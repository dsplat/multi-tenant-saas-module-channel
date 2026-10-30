<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Channel\Listeners;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Events\MessageReceived;
use MultiTenantSaas\Modules\Channel\Contracts\CustomerIdentityResolverContract;
use MultiTenantSaas\Modules\Channel\Services\ChannelAiService;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;
use MultiTenantSaas\Services\Channel\ChannelManager;

/**
 * 渠道消息接收监听器
 *
 * 消费框架 MessageReceived 事件（Channel 全链路入库后触发），执行 SCRM 业务：
 * 1. 客户关联：按 external_from 经 CustomerIdentityResolverContract 解析客户档案，关联会话
 * 2. AI 自动回复：调用 ChannelAiService 生成回复，经框架 ChannelManager 发送
 *
 * 仅处理渠道来源消息（channel != 'web'），web 端消息不走此逻辑。
 * 客户档案解析经契约反向暴露，本监听器不直连 scrm Customer 私有模型。
 */
class MessageReceivedListener
{
    public function __construct(
        private readonly ChannelAiService $aiService,
        private readonly ChannelManager $channelManager,
        private readonly CustomerIdentityResolverContract $customers,
    ) {}

    public function handle(MessageReceived $event): void
    {
        $message = $event->message;
        $channel = $event->channel;

        // 仅处理渠道来源（企微 app/kf 等），跳过 web 端消息
        if ($channel === 'web' || ! str_starts_with($channel, 'enterprise_wechat')) {
            return;
        }

        $tenantId = (int) $message->tenant_id;
        $externalFrom = $message->metadata['external_from'] ?? '';

        if ($externalFrom === '') {
            return;
        }

        // 1. 客户关联
        $customer = $this->associateCustomer($tenantId, $externalFrom, $message->conversation_id);

        // 2. AI 自动回复（仅文本消息）
        if ($message->type === 'text' && $message->content !== '') {
            $this->autoReply($tenantId, $message, $channel, $customer);
        }
    }

    /**
     * 按平台身份匹配客户档案，关联到会话 metadata。
     *
     * @return array{user_id: int, name: ?string, tags: array<int, mixed>}|null
     */
    private function associateCustomer(int $tenantId, string $externalFrom, string $conversationId): ?array
    {
        $customer = $this->customers->resolveByWechatId($tenantId, $externalFrom);

        if ($customer === null) {
            Log::debug('MessageReceivedListener: 未匹配到客户', [
                'tenant_id' => $tenantId,
                'external_from' => $externalFrom,
            ]);

            return null;
        }

        // 将客户 user_id 写入会话 metadata（幂等）
        $conversation = Conversation::where('tenant_id', $tenantId)
            ->where('conversation_id', $conversationId)
            ->first();

        if ($conversation !== null) {
            $metadata = $conversation->metadata ?? [];
            if (($metadata['customer_user_id'] ?? null) !== $customer['user_id']) {
                $metadata['customer_user_id'] = $customer['user_id'];
                $conversation->forceFill(['metadata' => $metadata])->save();
            }
        }

        return $customer;
    }

    /**
     * AI 自动回复 + 经框架渠道发送。
     *
     * @param  array{user_id: int, name: ?string, tags: array<int, mixed>}|null  $customer
     */
    private function autoReply(int $tenantId, $message, string $channel, ?array $customer): void
    {
        $context = $customer !== null
            ? ['customer_name' => $customer['name'], 'customer_tags' => $customer['tags'] ?? []]
            : [];

        $result = $this->aiService->autoReply($tenantId, $message->content, $context);

        if (! $result->success) {
            return;
        }

        $reply = $result->output['reply'] ?? '';
        $shouldEscalate = $result->output['should_escalate'] ?? true;

        // AI 不确定时不自动回复，标记转人工
        if ($reply === '' || $shouldEscalate) {
            if ($shouldEscalate) {
                Log::info('MessageReceivedListener: AI 建议转人工', [
                    'tenant_id' => $tenantId,
                    'conversation_id' => $message->conversation_id,
                ]);
            }

            return;
        }

        // 经框架 ChannelManager 发送回复
        try {
            $conversation = Conversation::where('tenant_id', $tenantId)
                ->where('conversation_id', $message->conversation_id)
                ->first();

            if ($conversation === null) {
                return;
            }

            $driver = $this->channelManager->resolve($channel, $tenantId);
            $driver->sendMessage($conversation, [
                'msgtype' => 'text',
                'text' => ['content' => $reply],
            ]);

            Log::info('MessageReceivedListener: AI 自动回复已发送', [
                'tenant_id' => $tenantId,
                'conversation_id' => $message->conversation_id,
                'reply_length' => mb_strlen($reply),
            ]);
        } catch (\Throwable $e) {
            Log::error('MessageReceivedListener: 自动回复发送失败', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
