<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Channel\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Channel\Models\LiveCode;

/**
 * 扫码统计服务
 *
 * 提供活码扫码统计、转化率、排名和趋势分析。
 */
class ScanStatsService
{
    /**
     * 记录扫码事件
     *
     * @param  array<string, mixed>  $params
     */
    public function recordScan($codeId, $userId = null, array $params = []): bool
    {
        $liveCode = LiveCode::where('live_code_id', $codeId)->first();

        if ($liveCode === null || ! $liveCode->isActive()) {
            return false;
        }

        // 写入事件表（提供历史趋势真数据）
        DB::table('scan_events')->insert([
            'tenant_id' => $liveCode->tenant_id,
            'live_code_id' => $codeId,
            'user_id' => $userId,
            'channel_source' => $params['channel_source'] ?? 'unknown',
            'region' => $params['region'] ?? null,
            'scanned_at' => now(),
            'metadata' => ! empty(array_diff_key($params, array_flip(['channel_source', 'region'])))
                ? json_encode(array_diff_key($params, array_flip(['channel_source', 'region'])))
                : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 兼容：同步更新 metadata 计数器
        $metadata = $liveCode->metadata ?? [];
        $metadata['scan_count'] = ($metadata['scan_count'] ?? 0) + 1;
        $metadata['last_scanned_at'] = now()->toIso8601String();

        $channel = $params['channel_source'] ?? 'unknown';
        $channelStats = $metadata['channel_stats'] ?? [];
        $channelStats[$channel] = ($channelStats[$channel] ?? 0) + 1;
        $metadata['channel_stats'] = $channelStats;

        $region = $params['region'] ?? null;
        if ($region !== null) {
            $regionStats = $metadata['region_stats'] ?? [];
            $regionStats[$region] = ($regionStats[$region] ?? 0) + 1;
            $metadata['region_stats'] = $regionStats;
        }

        $liveCode->update(['metadata' => $metadata]);

        Log::info('ScanStatsService: 记录扫码', [
            'code_id' => $codeId,
            'user_id' => $userId,
            'channel' => $channel,
        ]);

        return true;
    }

    /**
     * 获取扫码统计
     *
     * @param  array<string, mixed>  $dateRange
     * @return array<string, mixed>
     */
    public function getScanStats($codeId, array $dateRange = []): array
    {
        $liveCode = LiveCode::where('live_code_id', $codeId)->firstOrFail();

        $startDate = $this->parseDate($dateRange['start'] ?? null, now()->subDays(30));
        $endDate = $this->parseDate($dateRange['end'] ?? null, now(), true);

        $metadata = $liveCode->metadata ?? [];

        return [
            'live_code_id' => $codeId,
            'code' => $liveCode->code,
            'type' => $liveCode->type,
            'channel' => $liveCode->channel,
            'status' => $liveCode->status,
            'date_range' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
            ],
            'summary' => [
                'total_scans' => $metadata['scan_count'] ?? 0,
                'total_adds' => $metadata['add_count'] ?? 0,
            ],
            'channel_stats' => $metadata['channel_stats'] ?? [],
            'region_stats' => $metadata['region_stats'] ?? [],
            'daily_trend' => $this->getDailyScanTrend($codeId, $startDate, $endDate),
        ];
    }

    /**
     * 获取转化率
     *
     * @param  array<string, mixed>  $dateRange
     * @return array<string, mixed>
     */
    public function getConversionRate($codeId, array $dateRange = []): array
    {
        $liveCode = LiveCode::where('live_code_id', $codeId)->firstOrFail();

        $metadata = $liveCode->metadata ?? [];
        $scanCount = $metadata['scan_count'] ?? 0;
        $addCount = $metadata['add_count'] ?? 0;

        // 扫码→添加好友转化率
        $addFriendRate = $scanCount > 0 ? round($addCount / $scanCount * 100, 2) : 0;

        // 模拟其他转化数据
        $joinGroupCount = (int) ($metadata['join_group_count'] ?? 0);
        $registerCount = (int) ($metadata['register_count'] ?? 0);

        return [
            'live_code_id' => $codeId,
            'code' => $liveCode->code,
            'conversion' => [
                'scan_to_add' => [
                    'total' => $scanCount,
                    'converted' => $addCount,
                    'rate' => $addFriendRate,
                ],
                'scan_to_group' => [
                    'total' => $scanCount,
                    'converted' => $joinGroupCount,
                    'rate' => $scanCount > 0 ? round($joinGroupCount / $scanCount * 100, 2) : 0,
                ],
                'scan_to_register' => [
                    'total' => $scanCount,
                    'converted' => $registerCount,
                    'rate' => $scanCount > 0 ? round($registerCount / $scanCount * 100, 2) : 0,
                ],
            ],
        ];
    }

    /**
     * 记录添加事件
     */
    public function recordAdd($codeId): bool
    {
        $liveCode = LiveCode::where('live_code_id', $codeId)->first();

        if ($liveCode === null) {
            return false;
        }

        $metadata = $liveCode->metadata ?? [];
        $metadata['add_count'] = ($metadata['add_count'] ?? 0) + 1;
        $metadata['last_added_at'] = now()->toIso8601String();

        $liveCode->update(['metadata' => $metadata]);

        return true;
    }

    /**
     * 获取排名
     *
     * @param  array<string, mixed>  $dateRange
     * @return array<int, array<string, mixed>>
     */
    public function getRanking($tenantId, array $dateRange = [], string $sortBy = 'scans', $limit = 10): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $liveCodes = LiveCode::where('tenant_id', $tenantId)
            ->active()
            ->get();

        $ranking = [];
        foreach ($liveCodes as $liveCode) {
            $metadata = $liveCode->metadata ?? [];
            $scanCount = $metadata['scan_count'] ?? 0;
            $addCount = $metadata['add_count'] ?? 0;

            $ranking[] = [
                'live_code_id' => $liveCode->live_code_id,
                'code' => $liveCode->code,
                'type' => $liveCode->type,
                'channel' => $liveCode->channel,
                'scan_count' => $scanCount,
                'add_count' => $addCount,
                'conversion_rate' => $scanCount > 0
                    ? round($addCount / $scanCount * 100, 2)
                    : 0,
            ];
        }

        $sortField = match ($sortBy) {
            'adds' => 'add_count',
            'conversion_rate' => 'conversion_rate',
            default => 'scan_count',
        };

        usort($ranking, fn ($a, $b) => $b[$sortField] <=> $a[$sortField]);

        return array_slice($ranking, 0, $limit);
    }

    /**
     * 获取活码统计数据（别名）
     *
     * @param  array<string, mixed>  $dateRange
     * @return array<string, mixed>
     */
    public function getLiveCodeStats($codeId, array $dateRange = []): array
    {
        return $this->getScanStats($codeId, $dateRange);
    }

    /**
     * 获取每日扫码趋势（真实聚合自 scan_events 表）
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getDailyScanTrend($codeId, Carbon $startDate, Carbon $endDate): array
    {
        $rows = DB::table('scan_events')
            ->where('live_code_id', $codeId)
            ->whereBetween('scanned_at', [$startDate, $endDate])
            ->selectRaw('date(scanned_at) as day, COUNT(*) as scans')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $trend = [];
        $current = $startDate->copy();
        while ($current->lte($endDate)) {
            $day = $current->toDateString();
            $row = $rows->get($day);
            $trend[] = [
                'date' => $day,
                'scans' => $row ? (int) $row->scans : 0,
                'adds' => 0, // 扫码→添加 需要 Customer.created_at 关联，暂如实为 0
            ];
            $current->addDay();
        }

        return $trend;
    }

    /**
     * 解析日期
     */
    protected function parseDate(?string $date, Carbon $default, bool $endOfDay = false): Carbon
    {
        if ($date === null) {
            return $endOfDay ? $default->copy()->endOfDay() : $default;
        }

        try {
            $parsed = Carbon::parse($date);

            return $endOfDay ? $parsed->endOfDay() : $parsed;
        } catch (\Throwable $e) {
            return $endOfDay ? $default->copy()->endOfDay() : $default;
        }
    }
}
