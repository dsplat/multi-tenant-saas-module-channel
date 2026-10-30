<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Channel\Services\Tools;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;
use MultiTenantSaas\Services\Channel\ChannelManager;

/**
 * 发送消息工具 Handler
 *
 * 经框架 ChannelManager 解析租户渠道驱动，向指定会话发送消息。
 * 参数：channel(渠道类型)、to(会话 external_conv_id)、message(内容)、message_type(类型)
 */
class SendMessageHandler implements ToolHandlerContract
{
    public function __construct(private readonly ChannelManager $channelManager) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $channelType = $arguments['channel'] ?? '';
        $to = $arguments['to'] ?? '';
        $content = $arguments['message'] ?? '';
        $messageType = $arguments['message_type'] ?? 'text';

        if ($channelType === '' || $to === '' || $content === '') {
            return ['success' => false, 'error' => '缺少必要参数: channel/to/message'];
        }

        try {
            $driver = $this->channelManager->resolve($channelType, $tenantId);

            // 按 external_conv_id 查找活跃会话
            $conversation = Conversation::where('tenant_id', $tenantId)
                ->where('channel', $channelType)
                ->where('status', 'active')
                ->where('metadata->external_conv_id', $to)
                ->first();

            if ($conversation === null) {
                return ['success' => false, 'error' => "未找到渠道 [{$channelType}] 对应会话: {$to}"];
            }

            $message = match ($messageType) {
                'text' => ['msgtype' => 'text', 'text' => ['content' => $content]],
                'markdown' => ['msgtype' => 'markdown', 'markdown' => ['content' => $content]],
                default => ['msgtype' => 'text', 'text' => ['content' => $content]],
            };

            $sent = $driver->sendMessage($conversation, $message);

            return [
                'success' => $sent,
                'channel' => $channelType,
                'to' => $to,
                'message' => $sent ? '消息已发送' : '发送失败',
            ];
        } catch (\Throwable $e) {
            Log::error('SendMessageHandler: 发送失败', [
                'tenant_id' => $tenantId,
                'channel' => $channelType,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
