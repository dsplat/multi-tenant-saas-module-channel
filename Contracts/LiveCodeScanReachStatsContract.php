<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Channel\Contracts;

/**
 * 活码扫码触达账**只读**契约（框架中立身份解析接缝）
 *
 * 活码「扫码→触达」账目的事实源是 Marketing 的 `reach_events`
 * （event_type=live_code_scanned）。Channel 侧统计（LockCodeService::getScanStats /
 * ChannelCodeService::getStats）需要读取该账目，但不能直接依赖 Marketing 私有模型，
 * 故经此只读契约反向暴露，由 Marketing 提供实现（ReachEvent）。
 *
 * 口径与既有 ReachEvent 查询完全一致：
 * `tenant_id` + `event_type='live_code_scanned'` + `metadata like '%"live_code_id":"{id}"%'`。
 * metadata.live_code_id 必须是**字符串**（同写入侧约定），布尔等其它类型匹配不到。
 */
interface LiveCodeScanReachStatsContract
{
    /**
     * 统计某活码的扫码触达总数
     *
     * @param  int  $tenantId  租户 ID
     * @param  int  $liveCodeId  活码主键
     * @param  string|null  $since  起始时间（含）；null 表示不限时间
     */
    public function countScans(int $tenantId, int $liveCodeId, ?string $since = null): int;

    /**
     * 统计某活码在指定日期（Y-m-d）的扫码触达数
     */
    public function countScansOnDate(int $tenantId, int $liveCodeId, string $date): int;
}
