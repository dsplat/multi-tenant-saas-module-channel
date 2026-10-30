<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Channel\Services;

use MultiTenantSaas\Modules\Channel\Contracts\CustomerIdentityResolverContract;

/**
 * 客户身份解析契约的框架默认实现（恒不匹配）
 *
 * 框架层无 CRM 客户档案概念（身份铁律：框架只有 Operator / User，无 customer），
 * 故默认解析恒返回 null（外部身份 ≠ 已建档客户）；项目层可重新绑定自有实现
 * （如 scrm 的 `CustomerIdentityResolver`）顶掉本默认。
 */
class NullCustomerIdentityResolver implements CustomerIdentityResolverContract
{
    /**
     * @return array{user_id: int, name: ?string, tags: array<int, mixed>}|null
     */
    public function resolveByWechatId(int $tenantId, string $wechatId): ?array
    {
        return null;
    }
}
