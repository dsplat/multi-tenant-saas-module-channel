<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Channel\Services\Write;

use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Access\WriteIntent;
use MultiTenantSaas\Authorization\AuthorizationRequest;
use MultiTenantSaas\Authorization\AuthorizedWriteContext;
use MultiTenantSaas\Authorization\WriteOutcome;
use MultiTenantSaas\Contracts\WriteAuthorizationAdapterContract;
use MultiTenantSaas\Modules\Ai\Services\Agent\WriteOperationJournal;
use MultiTenantSaas\Modules\Channel\Services\LiveCodeStrategyService;
use MultiTenantSaas\Modules\Channel\Services\Write\Concerns\GuardsTenantWrite;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;

/**
 * create_live_code 写适配器（L2 写试点，ADR v2.5 §8.7）
 *
 * 业务落点：{@see LiveCodeStrategyService::assign()}——活码创建的唯一已验证实现
 * （LiveCodeController::store 同口径）。工具参数与活码列不同名，映射如下：
 * - `name` → metadata.name（live_codes 无 name 列）；
 * - `channel` → live_codes.channel；
 * - `welcome_message` / `tags` → metadata（assign 透传 metadata）；
 * - 活码类型固定 `channel`（工具语义「扫码引流活码」，schema 未暴露 type）。
 *
 * 幂等：同 operation key 成功 replay 返回首次 live_code_id，不重复建码；
 * 同键异输入 conflict；占位未终态 uncertain。
 */
final class CreateLiveCodeWriteAdapter implements WriteAuthorizationAdapterContract
{
    use GuardsTenantWrite;

    /** 写适配器注册键（Tool.writeAdapter） */
    public const ADAPTER_KEY = 'channel.create_live_code';

    /** 写操作账本中的工具标识 */
    public const TOOL_SLUG = 'create_live_code';

    public const RESOURCE_TYPE = 'live_code';

    public const RESOURCE_NAME = 'live_codes';

    public const ALLOWED_ARGUMENTS = ['name', 'channel', 'welcome_message', 'tags', 'operation_key'];

    public const ALLOWED_FIELDS = ['name', 'channel', 'welcome_message', 'tags'];

    /** 工具语义对应的活码类型（渠道码） */
    private const LIVE_CODE_TYPE = 'channel';

    private const NAME_MAX_LENGTH = 200;

    private const CHANNEL_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,99}$/';

    private const WELCOME_MESSAGE_MAX_LENGTH = 2000;

    private const MAX_TAGS = 20;

    private const TAG_MAX_LENGTH = 50;

    public function __construct(
        private readonly LiveCodeStrategyService $liveCodes,
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

        $name = $this->normalizeName($arguments['name'] ?? null);
        $channel = $this->normalizeChannel($arguments['channel'] ?? null);
        $welcomeMessage = $this->normalizeWelcomeMessage($arguments['welcome_message'] ?? null);
        $tags = $this->normalizeTags($arguments['tags'] ?? null);

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
            operationKey: $this->operationKey($arguments['operation_key'] ?? null, 'lc'),
            payload: [
                'name' => $name,
                'channel' => $channel,
                'welcome_message' => $welcomeMessage,
                'tags' => $tags,
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
            $name = $this->normalizeName($context->payload['name'] ?? null);
            $channel = $this->normalizeChannel($context->payload['channel'] ?? null);
            $welcomeMessage = $this->normalizeWelcomeMessage($context->payload['welcome_message'] ?? null);
            $tags = $this->normalizeTags($context->payload['tags'] ?? null);
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
                    'operation' => 'create_live_code',
                    'name' => $name,
                    'channel' => $channel,
                    'welcome_message' => $welcomeMessage,
                    'tags' => $tags,
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
                $name,
                $channel,
                $welcomeMessage,
                $tags,
                (int) $claim['audit_id'],
            ),
            default => WriteOutcome::uncertain($context->operationKey, 'operation_state_unknown'),
        };
    }

    /**
     * @param  array<int, string>  $tags
     */
    private function apply(
        AuthorizedWriteContext $context,
        int $tenantId,
        string $name,
        string $channel,
        ?string $welcomeMessage,
        array $tags,
        int $auditId,
    ): WriteOutcome {
        try {
            return DB::transaction(function () use ($context, $tenantId, $name, $channel, $welcomeMessage, $tags, $auditId): WriteOutcome {
                if (! Tenant::where('tenant_id', $tenantId)->exists()) {
                    $this->journal->fail($auditId, 'channel_tenant_not_found');

                    return WriteOutcome::rejected($context->operationKey, 'channel_tenant_not_found');
                }

                $created = $this->liveCodes->assign($tenantId, [
                    'type' => self::LIVE_CODE_TYPE,
                    'channel' => $channel,
                    'metadata' => [
                        'name' => $name,
                        'welcome_message' => $welcomeMessage,
                        'tags' => $tags,
                        'source' => 'ai_tool',
                    ],
                ]);

                $result = $this->resultOf($created, $tenantId, $name);
                $this->journal->succeed($auditId, $result);

                return WriteOutcome::success($context->operationKey, $result);
            });
        } catch (\Throwable) {
            $this->failClaim($auditId, 'channel_write_failed');

            return WriteOutcome::rejected($context->operationKey, 'channel_write_failed');
        }
    }

    /**
     * @param  array<string, mixed>  $created
     * @return array<string, mixed>
     */
    private function resultOf(array $created, int $tenantId, string $name): array
    {
        return [
            'live_code_id' => (int) ($created['live_code_id'] ?? 0),
            'tenant_id' => (int) ($created['tenant_id'] ?? $tenantId),
            'type' => (string) ($created['type'] ?? self::LIVE_CODE_TYPE),
            'code' => (string) ($created['code'] ?? ''),
            'channel' => $created['channel'] ?? null,
            'status' => (string) ($created['status'] ?? ''),
            'name' => $name,
        ];
    }

    private function normalizeName(mixed $value): string
    {
        if (! is_string($value)) {
            throw new \DomainException('channel_write_name_invalid');
        }

        $name = trim($value);

        if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new \DomainException('channel_write_name_invalid');
        }

        return $name;
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

    private function normalizeWelcomeMessage(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new \DomainException('channel_write_welcome_message_invalid');
        }

        $message = trim($value);

        if ($message === '') {
            return null;
        }

        if (mb_strlen($message) > self::WELCOME_MESSAGE_MAX_LENGTH) {
            throw new \DomainException('channel_write_welcome_message_invalid');
        }

        return $message;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeTags(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value) || count($value) > self::MAX_TAGS) {
            throw new \DomainException('channel_write_tags_invalid');
        }

        $tags = [];

        foreach ($value as $tag) {
            if (! is_string($tag)) {
                throw new \DomainException('channel_write_tags_invalid');
            }

            $tag = trim($tag);

            if ($tag === '' || mb_strlen($tag) > self::TAG_MAX_LENGTH) {
                throw new \DomainException('channel_write_tags_invalid');
            }

            $tags[$tag] = true;
        }

        return array_keys($tags);
    }
}
