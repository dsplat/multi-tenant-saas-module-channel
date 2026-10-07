<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Channel\Services;

use MultiTenantSaas\Modules\Channel\Models\Channel;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;

/**
 * 渠道凭证 ↔ 框架 ChannelManager 同步（tenant_settings group=channel 双写）
 *
 * 框架 ChannelManager 是纯只读消费者：驱动凭证从 tenant_settings
 * （group=channel，key=驱动类型，value=JSON 加密存储）读取。本服务在渠道
 * 保存/删除时负责写入/清理，保证「渠道配置页填写的内容」与「消息回调验签 /
 * 测试连接 resolve」读到的是同一份凭证。
 *
 * 映射（type=wechat_work → 框架驱动 wechat-work）：
 *   corp_id ← app_id、corp_secret ← app_secret、agent_id、
 *   token ← callback_token、encoding_aes_key、enabled（凭据齐全才 true）
 *
 * 注意：
 * - 代开发（suite）授权租户由 ChannelController::assertWechatWorkCredentialsAllowed
 *   前置拦截（禁写自建 app_secret），不会走到本服务写入自建凭证行；
 * - enabled=false（凭证不完整）时驱动行仍存在，webhook GET 验证会因
 *   token/aes_key 缺失失败——与「企微后台保存验证要求凭据先就绪」的顺序一致。
 */
class ChannelCredentialSyncService
{
    /** 框架驱动类型（与框架 EnterpriseWechatAppDriver::TYPE 一致） */
    public const DRIVER_WECHAT_WORK = 'wechat-work';

    /**
     * 将渠道凭证同步到框架 tenant_settings（仅企微自建应用有驱动契约；
     * 公众号/Telegram 为 scrm 业务枚举，无框架驱动，跳过）。
     *
     * 按当前填写进度写入（向导分步暂存）：应用凭证未填完也保留驱动行，
     * 便于企微后台「设置 API 接收」GET 验证时驱动可解析（验签/解密仅依赖
     * token/encoding_aes_key，不依赖 corp_secret）；enabled 随凭据齐全度。
     * 驱动行清理只发生在渠道删除/类型变更（remove）。
     */
    public function sync(int $tenantId, Channel $channel): void
    {
        if ($channel->type !== 'wechat_work') {
            return;
        }

        $payload = [
            'corp_id' => (string) ($channel->app_id ?? ''),
            'corp_secret' => (string) $channel->app_secret,
            'agent_id' => (string) ($channel->agent_id ?? ''),
            'token' => (string) ($channel->callback_token ?? ''),
            'encoding_aes_key' => (string) ($channel->encoding_aes_key ?? ''),
            'enabled' => $this->isComplete($channel),
        ];

        TenantSetting::set(
            $tenantId,
            'channel',
            self::DRIVER_WECHAT_WORK,
            $payload,
            true,
            '企业微信自建应用渠道凭证（渠道配置页自动同步）'
        );
    }

    /** 渠道删除/类型变更时清理对应驱动凭证行 */
    public function remove(int $tenantId, string $type): void
    {
        if ($type !== 'wechat_work') {
            return;
        }

        TenantSetting::remove($tenantId, 'channel', self::DRIVER_WECHAT_WORK);
    }

    /**
     * 消息回调所需的全部凭据是否就绪（corp_id/corp_secret/agent_id 供 API 调用，
     * token/encoding_aes_key 供企微后台 GET 验证与消息解密）。
     */
    public function isComplete(Channel $channel): bool
    {
        return $channel->type === 'wechat_work'
            && ! empty($channel->app_id)
            && ! empty($channel->app_secret)
            && ! empty($channel->callback_token)
            && ! empty($channel->encoding_aes_key);
    }
}
