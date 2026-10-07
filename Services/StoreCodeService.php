<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Channel\Services;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Modules\Channel\Models\LiveCode;

/**
 * 门店活码服务
 *
 * 基于 LBS 的门店活码分配，支持最近门店、均衡分配和加权分配策略。
 */
class StoreCodeService extends LiveCodeStrategyService
{
    public const STRATEGY_NEAREST = 'nearest';

    public const STRATEGY_BALANCED = 'balanced';

    public const STRATEGY_WEIGHTED = 'weighted';

    public const VALID_STRATEGIES = [
        self::STRATEGY_NEAREST,
        self::STRATEGY_BALANCED,
        self::STRATEGY_WEIGHTED,
    ];

    public function __construct(
        protected IdGeneratorContract $idGenerator,
        protected ScanStatsService $scanStatsService,
    ) {
        parent::__construct($idGenerator);
    }

    /**
     * 创建门店活码
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function createStoreCode($tenantId, array $params): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $stores = $params['stores'] ?? [];
        $strategy = $params['strategy'] ?? self::STRATEGY_NEAREST;
        $channel = $params['channel'] ?? 'wechat';

        if (! in_array($strategy, self::VALID_STRATEGIES, true)) {
            throw new \InvalidArgumentException("不支持的分配策略: {$strategy}");
        }

        if (empty($stores)) {
            throw new \InvalidArgumentException('门店列表不能为空');
        }

        // 构建门店元数据
        $storeMetadata = [];
        foreach ($stores as $index => $store) {
            $storeMetadata[] = [
                'store_id' => $store['store_id'] ?? $index,
                'name' => $store['name'] ?? "门店{$index}",
                'address' => $store['address'] ?? '',
                'latitude' => $store['latitude'] ?? 0,
                'longitude' => $store['longitude'] ?? 0,
                'weight' => $store['weight'] ?? 1,
                'target_url' => $store['target_url'] ?? null,
                'scan_count' => 0,
            ];
        }

        $liveCode = LiveCode::create([
            'live_code_id' => $this->idGenerator->generate(),
            'tenant_id' => $tenantId,
            'type' => 'store',
            'code' => $this->generateCode(),
            'channel' => $channel,
            'target_url' => $storeMetadata[0]['target_url'] ?? null,
            'status' => 'active',
            'metadata' => [
                'strategy' => $strategy,
                'stores' => $storeMetadata,
                'current_index' => 0,
                'created_at' => now()->toIso8601String(),
            ],
        ]);

        Log::info('StoreCodeService: 创建门店活码', [
            'tenant_id' => $tenantId,
            'live_code_id' => $liveCode->live_code_id,
            'strategy' => $strategy,
            'store_count' => count($storeMetadata),
        ]);

        return $liveCode->toArray();
    }

    /**
     * 根据 LBS 解析最近门店
     *
     * @param  array<string, float>  $geoLocation  包含 latitude 和 longitude
     * @return array<string, mixed>|null
     */
    public function resolveStore($tenantId, $codeId, array $geoLocation): ?array
    {
        TenantContext::setTenantId((string) $tenantId);

        $liveCode = LiveCode::where('live_code_id', $codeId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        if (! $liveCode->isActive()) {
            return null;
        }

        $metadata = $liveCode->metadata ?? [];
        $stores = $metadata['stores'] ?? [];
        $strategy = $metadata['strategy'] ?? self::STRATEGY_NEAREST;

        if (empty($stores)) {
            return null;
        }

        $selectedStore = match ($strategy) {
            self::STRATEGY_NEAREST => $this->resolveByNearest($stores, $geoLocation),
            self::STRATEGY_BALANCED => $this->resolveByBalanced($stores, $metadata),
            self::STRATEGY_WEIGHTED => $this->resolveByWeighted($stores, $metadata),
            default => $stores[0],
        };

        if ($selectedStore === null) {
            return null;
        }

        // 更新扫码计数
        $this->updateStoreScanCount($tenantId, $codeId, $selectedStore['store_id']);

        // 记录扫码事件（第二参 userId 无用户上下文置 null，门店/策略信息走第三参 params）
        $this->scanStatsService->recordScan($codeId, null, [
            'store_id' => $selectedStore['store_id'],
            'strategy' => $strategy,
            'latitude' => $geoLocation['latitude'] ?? null,
            'longitude' => $geoLocation['longitude'] ?? null,
        ]);

        return [
            'store' => $selectedStore,
            'strategy' => $strategy,
            'target_url' => $selectedStore['target_url'] ?? null,
        ];
    }

    /**
     * 获取活码扫码统计
     *
     * @return array<string, mixed>
     */
    public function getStoreCodeStats($codeId): array
    {
        $liveCode = LiveCode::where('live_code_id', $codeId)->firstOrFail();

        $metadata = $liveCode->metadata ?? [];
        $stores = $metadata['stores'] ?? [];

        // 各门店扫码统计
        $storeStats = [];
        foreach ($stores as $store) {
            $storeStats[] = [
                'store_id' => $store['store_id'],
                'name' => $store['name'],
                'scan_count' => $store['scan_count'] ?? 0,
            ];
        }

        // 总扫码数
        $totalScans = array_sum(array_column($storeStats, 'scan_count'));

        return [
            'live_code_id' => $codeId,
            'code' => $liveCode->code,
            'type' => $liveCode->type,
            'strategy' => $metadata['strategy'] ?? 'nearest',
            'summary' => [
                'total_scans' => $totalScans,
                'store_count' => count($stores),
            ],
            'stores' => $storeStats,
        ];
    }

    /**
     * 最近门店策略
     *
     * @param  array<int, array<string, mixed>>  $stores
     * @param  array<string, float>  $geoLocation
     * @return array<string, mixed>|null
     */
    protected function resolveByNearest(array $stores, array $geoLocation): ?array
    {
        $userLat = $geoLocation['latitude'] ?? 0;
        $userLng = $geoLocation['longitude'] ?? 0;

        if ($userLat === 0 && $userLng === 0) {
            return $stores[0] ?? null;
        }

        $nearest = null;
        $minDistance = PHP_FLOAT_MAX;

        foreach ($stores as $store) {
            $storeLat = $store['latitude'] ?? 0;
            $storeLng = $store['longitude'] ?? 0;

            if ($storeLat === 0 && $storeLng === 0) {
                continue;
            }

            $distance = $this->calculateDistance($userLat, $userLng, $storeLat, $storeLng);

            if ($distance < $minDistance) {
                $minDistance = $distance;
                $nearest = $store;
            }
        }

        return $nearest ?? $stores[0] ?? null;
    }

    /**
     * 均衡分配策略（轮询）
     *
     * @param  array<int, array<string, mixed>>  $stores
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    protected function resolveByBalanced(array $stores, array $metadata): array
    {
        $currentIndex = $metadata['current_index'] ?? 0;
        $index = $currentIndex % count($stores);

        return $stores[$index];
    }

    /**
     * 加权分配策略
     *
     * @param  array<int, array<string, mixed>>  $stores
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    protected function resolveByWeighted(array $stores, array $metadata): array
    {
        // 计算总权重
        $totalWeight = 0;
        foreach ($stores as $store) {
            $totalWeight += ($store['weight'] ?? 1);
        }

        if ($totalWeight <= 0) {
            return $stores[0];
        }

        // 按权重随机选择
        $random = random_int(1, $totalWeight);
        $cumulative = 0;

        foreach ($stores as $store) {
            $cumulative += ($store['weight'] ?? 1);
            if ($random <= $cumulative) {
                return $store;
            }
        }

        return $stores[0];
    }

    /**
     * 计算两点间距离（Haversine 公式，单位：公里）
     */
    protected function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371; // 地球半径（公里）

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * 更新门店扫码计数
     */
    protected function updateStoreScanCount($tenantId, $codeId, mixed $storeId): void
    {
        $liveCode = LiveCode::where('live_code_id', $codeId)->first();

        if ($liveCode === null) {
            return;
        }

        $metadata = $liveCode->metadata ?? [];
        $stores = $metadata['stores'] ?? [];
        $currentIndex = $metadata['current_index'] ?? 0;

        foreach ($stores as $index => $store) {
            if (($store['store_id'] ?? null) === $storeId) {
                $stores[$index]['scan_count'] = ($store['scan_count'] ?? 0) + 1;
                break;
            }
        }

        // 更新轮询索引
        $metadata['stores'] = $stores;
        $metadata['current_index'] = ($currentIndex + 1) % max(1, count($stores));

        $liveCode->update(['metadata' => $metadata]);
    }
}
