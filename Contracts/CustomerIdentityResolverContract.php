<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Channel\Contracts;

/**
 * 客户身份解析契约（框架中立身份解析接缝）
 *
 * 渠道侧（Channel 模块）按外部平台身份（如公众号 openid / 企微 external_userid）
 * 反查本租户的客户档案时，不能直接依赖 scrm Customer 私有模型，故经此契约反向暴露。
 * 由 scrm Customer 模块提供实现（按 wechat_id 匹配 Customer）。
 *
 * 返回值刻意用数组而非模型：框架侧消费方只需要 user_id / name / tags 三项，
 * 不暴露 CRM 档案的完整字段面，避免隐式耦合。
 */
interface CustomerIdentityResolverContract
{
    /**
     * 按平台外部身份（wechat_id）解析客户档案
     *
     * 未匹配到返回 null（外部身份 ≠ 已建档客户）。
     *
     * @return array{user_id: int, name: ?string, tags: array<int, mixed>}|null
     */
    public function resolveByWechatId(int $tenantId, string $wechatId): ?array;
}
