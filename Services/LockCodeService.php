<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Channel\Services;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Modules\Channel\Contracts\LiveCodeScanReachStatsContract;
use MultiTenantSaas\Modules\Channel\Events\LiveCodeScanRecorded;
use MultiTenantSaas\Modules\Channel\Models\LiveCode;
use MultiTenantSaas\Modules\Channel\Models\LockCodeBinding;

class LockCodeService
{
    public function __construct(
        protected IdGeneratorContract $idGenerator,
        protected LiveCodeScanReachStatsContract $reachStats,
    ) {}

    public function bind($tenantId, $codeId, $userId): bool
    {
        TenantContext::setTenantId((string) $tenantId);

        $liveCode = LiveCode::where('live_code_id', $codeId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        // 防止重复绑定同一客户到同一活码
        $existing = LockCodeBinding::where('tenant_id', $tenantId)
            ->where('lock_code_id', $codeId)
            ->where('user_id', $userId)
            ->exists();

        if ($existing) {
            return false;
        }

        LockCodeBinding::create([
            'binding_id' => $this->idGenerator->generate(),
            'tenant_id' => $tenantId,
            'lock_code_id' => $codeId,
            'user_id' => $userId,
            'staff_id' => $liveCode->metadata['staff_id'] ?? 0,
            'locked_at' => now(),
            'metadata' => [
                'code_type' => $liveCode->type,
                'code' => $liveCode->code,
            ],
        ]);

        return true;
    }

    public function getScanStats($tenantId, $codeId, ?string $period = null): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $liveCode = LiveCode::where('live_code_id', $codeId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        // 触达账读 Marketing 私有模型会耦合跨模块：改走只读契约（Marketing 实现）
        $totalScans = $this->reachStats->countScans(
            (int) $tenantId,
            (int) $codeId,
            $period !== null ? $this->resolvePeriodStart($period) : null,
        );

        $todayScans = $this->reachStats->countScansOnDate(
            (int) $tenantId,
            (int) $codeId,
            now()->toDateString(),
        );

        $bindCount = LockCodeBinding::where('tenant_id', $tenantId)
            ->where('lock_code_id', $codeId)
            ->count();

        return [
            'live_code_id' => $liveCode->live_code_id,
            'type' => $liveCode->type,
            'code' => $liveCode->code,
            'status' => $liveCode->status,
            'total_scans' => $totalScans,
            'today_scans' => $todayScans,
            'bind_count' => $bindCount,
            'period' => $period ?? 'all',
            'expire_at' => $liveCode->expire_at?->toIso8601String(),
        ];
    }

    public function recordScan($tenantId, $codeId, array $scanData): void
    {
        TenantContext::setTenantId((string) $tenantId);

        // 触达账由 Marketing 侧监听器落库（本服务不再直连 ReachEvent 模型）
        LiveCodeScanRecorded::dispatch((int) $tenantId, $codeId, $scanData);
    }

    protected function resolvePeriodStart(string $period): string
    {
        return match ($period) {
            'today' => now()->startOfDay()->toDateTimeString(),
            'yesterday' => now()->subDay()->startOfDay()->toDateTimeString(),
            'week' => now()->subWeek()->startOfDay()->toDateTimeString(),
            'month' => now()->subMonth()->startOfDay()->toDateTimeString(),
            'quarter' => now()->subQuarter()->startOfDay()->toDateTimeString(),
            'year' => now()->subYear()->startOfDay()->toDateTimeString(),
            default => now()->subDay()->toDateTimeString(),
        };
    }
}
