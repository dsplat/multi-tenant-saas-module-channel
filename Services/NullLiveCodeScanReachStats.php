<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Channel\Services;

use MultiTenantSaas\Modules\Channel\Contracts\LiveCodeScanReachStatsContract;

/**
 * 活码扫码触达账只读契约的框架默认实现（恒计 0）
 *
 * 触达账的事实源在下游（scrm Marketing 的 reach_events），框架层无该账目，
 * 故默认恒返回 0；项目层可重新绑定自有实现（如 scrm 的 `LiveCodeScanReachStats`）
 * 顶掉本默认。
 */
class NullLiveCodeScanReachStats implements LiveCodeScanReachStatsContract
{
    public function countScans(int $tenantId, int $liveCodeId, ?string $since = null): int
    {
        return 0;
    }

    public function countScansOnDate(int $tenantId, int $liveCodeId, string $date): int
    {
        return 0;
    }
}
