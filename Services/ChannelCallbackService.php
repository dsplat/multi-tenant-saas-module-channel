<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Channel\Services;

use MultiTenantSaas\Modules\Channel\Models\Channel;
use MultiTenantSaas\Modules\Domain\Services\DomainService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;

/**
 * 渠道回调 URL 解析（回调域名与租户自定义域名联动）
 *
 * 自建应用模式下，企微后台「设置 API 接收」保存时校验 URL 域名备案主体，
 * 报错原文：「域名主体校验未通过，需配置备案主体与当前企业主体相同或有关联
 * 关系的域名」（租户生产实测，2026-09-08）。平台统一回调域备案主体为平台
 * 公司，无法通过租户企微主体校验——回调 URL 必须落在租户自有备案域名上，
 * 保证域名主体与企微认证主体的「一体性」（与 OAuth resolveRedirectUrl 域名
 * 分支同规则）。
 *
 * 域名选择优先级：
 *   1. 完全自定义域名 approved（tenants.domain 非空 且 domain_status=approved）
 *      → https://{domain}，可通过企微主体校验
 *   2. 平台统一回调域（config('auth.oauth.callback_domain')，如 auth.neihang.com）
 *      → 未启用自定义域名时的临时展示/兜底，前端须硬提示主体校验风险
 *   3. 兜底当前请求域（fallbackHost，平台未配置统一回调域时）
 */
class ChannelCallbackService
{
    /**
     * 解析渠道回调信息（框架统一 webhook 路由 /api/v1/{type}/webhook/{slug}）
     *
     * @return array{
     *     callback_url: string,
     *     driver_type: string,
     *     custom_domain: ?string,
     *     custom_domain_active: bool,
     *     callback_host: string,
     * }
     */
    public function resolve(int $tenantId, Channel $channel, string $fallbackHost): array
    {
        // SCRM 渠道类型 → 框架 ChannelManager 驱动类型（kebab-case，与 suite 回调 wechat-work 命名统一）
        $driverType = match ($channel->type) {
            'wechat_work' => 'wechat-work',
            default => $channel->type,
        };

        $tenant = Tenant::query()->where('tenant_id', $tenantId)->first();

        // 自定义域名启用状态（domain 非空 + approved 即「已启用完全自定义域名」）
        $domain = ! empty($tenant?->domain) ? $tenant->domain : null;
        $domainStatus = TenantSetting::get(
            $tenantId,
            DomainService::GROUP_DOMAIN,
            'domain_status',
            DomainService::STATUS_PENDING
        );
        $customDomainActive = $domain !== null && $domainStatus === DomainService::STATUS_APPROVED;

        // 回调域名：完全自定义域名优先（企微主体校验要求）→ 平台统一回调域 → 请求域兜底
        $host = $customDomainActive
            ? $domain
            : (string) config('auth.oauth.callback_domain', '');
        if ($host === '') {
            $host = $fallbackHost;
        }

        // webhook 租户定位参数：slug 优先（租户无 slug 时回退 tenant_id）
        $tenantSlug = ! empty($tenant?->slug) ? $tenant->slug : (string) $tenantId;

        return [
            'callback_url' => "https://{$host}/api/v1/{$driverType}/webhook/{$tenantSlug}",
            'driver_type' => $driverType,
            'custom_domain' => $domain,
            'custom_domain_active' => $customDomainActive,
            'callback_host' => $host,
        ];
    }
}
