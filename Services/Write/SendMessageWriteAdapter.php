<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Channel\Services\Write;

use MultiTenantSaas\Access\WriteIntent;
use MultiTenantSaas\Authorization\AuthorizationRequest;
use MultiTenantSaas\Authorization\AuthorizedWriteContext;
use MultiTenantSaas\Authorization\WriteOutcome;
use MultiTenantSaas\Contracts\WriteAuthorizationAdapterContract;
use MultiTenantSaas\Modules\Ai\Services\Agent\WriteOperationJournal;
use MultiTenantSaas\Modules\Channel\Services\Write\Concerns\GuardsTenantWrite;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;
use MultiTenantSaas\Scopes\TenantScope;
use MultiTenantSaas\Services\Channel\ChannelManager;

/**
 * send_message 写适配器（L2 写试点，ADR v2.5 §8.7）
 *
 * 顺序（外部副作用必须发生在授权之后）：
 * 1. prepare：规范化 channel/to/message/message_type 与幂等键，不产生任何外呼；
 * 2. execute：先占位写操作账本（operation key 执行权），再按 (tenant_id, channel,
 *    metadata.external_conv_id) 重验会话归属，然后解析驱动并发送。
 *
 * 外部结果处理（重点）：
 * - 驱动抛异常/超时 → **不置 failed、不自动重试**：占位行保持 pending，本次与后续同键
 *   一律返回 uncertain（重发可能造成重复消息；failed 会把「可能已发出」误判成可安全重试）；
 * - 驱动明确返回 false（服务器拒绝，确认未送达）→ 账本置 failed，同键允许重试；
 * - 发送成功但账本回填失败 → 返回 uncertain（不置 failed，避免二次外发）。
 *
 * 同键成功 replay 直接回放首次结果，驱动零调用。
 */
final class SendMessageWriteAdapter implements WriteAuthorizationAdapterContract
{
    use GuardsTenantWrite;

    /** 写适配器注册键（Tool.writeAdapter） */
    public const ADAPTER_KEY = 'channel.send_message';

    /** 写操作账本中的工具标识 */
    public const TOOL_SLUG = 'send_message';

    public const RESOURCE_TYPE = 'conversation';

    public const RESOURCE_NAME = 'conversations';

    public const ALLOWED_ARGUMENTS = ['channel', 'to', 'message', 'message_type', 'operation_key'];

    public const ALLOWED_FIELDS = ['channel', 'to', 'message', 'message_type'];

    public const MESSAGE_TYPES = ['text', 'markdown'];

    private const DEFAULT_MESSAGE_TYPE = 'text';

    /** 渠道类型标识（如 wechat-work / wechat-kf） */
    private const CHANNEL_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,99}$/';

    /** 企微文本消息上限 2048 字节；按字符放宽到 4000 由驱动侧再裁决 */
    private const MAX_MESSAGE_LENGTH = 4000;

    public function __construct(
        private readonly ChannelManager $channelManager,
        private readonly WriteOperationJournal $journal,
    ) {}

    public function prepare(AuthorizationRequest $request, array $arguments): AuthorizedWriteContext
    {
        $identity = $this->assertOperatorIdentity($request->identity);
        $tenantId = $this->targetTenantId($request);

        if ($identity->tenantId !== (string) $tenantId) {
            throw new \DomainException('channel_write_tenant_mismatch');
        }

        if (array_diff(array_keys($arguments), self::ALLOWED_ARGUMENTS) !== []) {
            throw new \DomainException('channel_write_arguments_unsupported');
        }

        $channel = $this->normalizeChannel($arguments['channel'] ?? null);
        $to = $this->normalizeRecipient($arguments['to'] ?? null);
        $message = $this->normalizeMessage($arguments['message'] ?? null);
        $messageType = $this->normalizeMessageType($arguments['message_type'] ?? null);

        return new AuthorizedWriteContext(
            identity: $identity,
            capabilityKey: $request->capabilityKey,
            target: $request->target,
            resourceId: null,
            resourceType: self::RESOURCE_TYPE,
            resourceName: self::RESOURCE_NAME,
            action: WriteIntent::MODE_CREATE,
            allowedFields: self::ALLOWED_FIELDS,
            ownershipRules: ['tenant_id' => (string) $tenantId],
            expectedVersion: null,
            operationKey: $this->operationKey($arguments['operation_key'] ?? null, 'sm'),
            payload: [
                'channel' => $channel,
                'to' => $to,
                'message' => $message,
                'message_type' => $messageType,
            ],
        );
    }

    public function execute(AuthorizedWriteContext $context): WriteOutcome
    {
        $tenantId = $this->contextTenantId(
            $context,
            self::RESOURCE_TYPE,
            WriteIntent::MODE_CREATE,
            self::ALLOWED_FIELDS,
        );

        if ($tenantId === null) {
            return WriteOutcome::rejected($context->operationKey, 'channel_write_context_invalid');
        }

        try {
            $channel = $this->normalizeChannel($context->payload['channel'] ?? null);
            $to = $this->normalizeRecipient($context->payload['to'] ?? null);
            $message = $this->normalizeMessage($context->payload['message'] ?? null);
            $messageType = $this->normalizeMessageType($context->payload['message_type'] ?? null);
        } catch (\DomainException) {
            return WriteOutcome::rejected($context->operationKey, 'channel_write_context_invalid');
        }

        try {
            $claim = $this->journal->begin(
                $tenantId,
                $context->operationKey,
                self::TOOL_SLUG,
                [
                    'tenant_id' => $tenantId,
                    'actor_id' => (string) $context->identity->subjectId,
                    'operation' => 'send_message',
                    'channel' => $channel,
                    'to' => $to,
                    'message_type' => $messageType,
                    'message_sha256' => 'sha256:' . hash('sha256', $message),
                ],
            );
        } catch (\Throwable) {
            return WriteOutcome::rejected($context->operationKey, 'operation_store_unavailable');
        }

        return match ($claim['decision']) {
            WriteOperationJournal::DECISION_REPLAY => WriteOutcome::success($context->operationKey, $claim['result']),
            WriteOperationJournal::DECISION_UNCERTAIN => WriteOutcome::uncertain($context->operationKey, 'operation_in_progress'),
            WriteOperationJournal::DECISION_CONFLICT => WriteOutcome::conflict($context->operationKey, 'operation_key_input_mismatch'),
            WriteOperationJournal::DECISION_ALLOW => $this->apply(
                $context,
                $tenantId,
                $channel,
                $to,
                $message,
                $messageType,
                (int) $claim['audit_id'],
            ),
            default => WriteOutcome::uncertain($context->operationKey, 'operation_state_unknown'),
        };
    }

    private function apply(
        AuthorizedWriteContext $context,
        int $tenantId,
        string $channel,
        string $to,
        string $message,
        string $messageType,
        int $auditId,
    ): WriteOutcome {
        // 会话归属重验：显式按授权租户查询，不依赖环境 TenantContext
        $conversation = Conversation::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('channel', $channel)
            ->where('status', 'active')
            ->where('metadata->external_conv_id', $to)
            ->first();

        if ($conversation === null) {
            $this->failClaim($auditId, 'conversation_not_found_or_foreign');

            return WriteOutcome::rejected($context->operationKey, 'conversation_not_found_or_foreign');
        }

        // 驱动解析失败 = 确认未外呼，可安全标记失败并允许同键重试
        try {
            $driver = $this->channelManager->resolve($channel, $tenantId);
        } catch (\Throwable) {
            $this->failClaim($auditId, 'channel_unavailable');

            return WriteOutcome::rejected($context->operationKey, 'channel_unavailable');
        }

        $payload = $this->buildDriverMessage($messageType, $message);

        try {
            $sent = $driver->sendMessage($conversation, $payload);
        } catch (\Throwable) {
            // 超时/异常：外部是否已送达不可知 —— 占位保持 pending，禁止盲目重发
            return WriteOutcome::uncertain($context->operationKey, 'channel_send_uncertain');
        }

        if (! $sent) {
            $this->failClaim($auditId, 'channel_send_failed');

            return WriteOutcome::rejected($context->operationKey, 'channel_send_failed');
        }

        $result = [
            'success' => true,
            'channel' => $channel,
            'to' => $to,
            'message_type' => $messageType,
            'message' => '消息已发送',
        ];

        try {
            $this->journal->succeed($auditId, $result);
        } catch (\Throwable) {
            // 已外发但账本未记录：保持 pending → 同键后续判 uncertain，不触发二次发送
            return WriteOutcome::uncertain($context->operationKey, 'channel_result_unrecorded');
        }

        return WriteOutcome::success($context->operationKey, $result);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDriverMessage(string $messageType, string $message): array
    {
        return match ($messageType) {
            'markdown' => ['msgtype' => 'markdown', 'markdown' => ['content' => $message]],
            default => ['msgtype' => 'text', 'text' => ['content' => $message]],
        };
    }

    private function normalizeChannel(mixed $value): string
    {
        if (! is_string($value)) {
            throw new \DomainException('channel_write_channel_invalid');
        }

        $channel = trim($value);

        if (preg_match(self::CHANNEL_PATTERN, $channel) !== 1) {
            throw new \DomainException('channel_write_channel_invalid');
        }

        return $channel;
    }

    private function normalizeRecipient(mixed $value): string
    {
        if (! is_string($value)) {
            throw new \DomainException('channel_write_recipient_invalid');
        }

        $to = trim($value);

        if ($to === '' || mb_strlen($to) > 255 || preg_match('/[\x00-\x1F\x7F]/', $to) === 1) {
            throw new \DomainException('channel_write_recipient_invalid');
        }

        return $to;
    }

    private function normalizeMessage(mixed $value): string
    {
        if (! is_string($value)) {
            throw new \DomainException('channel_write_message_invalid');
        }

        if (trim($value) === '' || mb_strlen($value) > self::MAX_MESSAGE_LENGTH) {
            throw new \DomainException('channel_write_message_invalid');
        }

        return $value;
    }

    private function normalizeMessageType(mixed $value): string
    {
        if ($value === null || $value === '') {
            return self::DEFAULT_MESSAGE_TYPE;
        }

        if (! is_string($value) || ! in_array($value, self::MESSAGE_TYPES, true)) {
            throw new \DomainException('channel_write_message_type_invalid');
        }

        return $value;
    }
}
