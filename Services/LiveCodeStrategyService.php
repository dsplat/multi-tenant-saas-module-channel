<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Channel\Services;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Modules\Channel\Contracts\LiveCodeStrategyContract;
use MultiTenantSaas\Modules\Channel\Models\LiveCode;

class LiveCodeStrategyService implements LiveCodeStrategyContract
{
    public function __construct(
        protected IdGeneratorContract $idGenerator,
    ) {}

    /**
     * 分配活码 — 轮询策略
     *
     * @param  int  $tenantId  租户ID
     * @param  array  $data  包含 type, channel, target_urls (员工列表)
     * @return array 分配结果
     */
    public function assign($tenantId, array $data): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $type = $data['type'] ?? 'employee';
        $channel = $data['channel'] ?? 'wechat';
        $targetUrls = $data['target_urls'] ?? [];

        // 兼容单个 target_url：若未提供 target_urls 数组，则将单个 target_url 包装为数组
        if (empty($targetUrls) && ! empty($data['target_url'])) {
            $targetUrls = [$data['target_url']];
        }

        // 查找当前渠道下活跃的活码
        $existingCodes = LiveCode::where('tenant_id', $tenantId)
            ->byType($type)
            ->byChannel($channel)
            ->active()
            ->get();

        // 轮询：选择当前使用最少的目标
        $assignedTarget = $this->selectByRoundRobin($existingCodes, $targetUrls);

        $liveCode = LiveCode::create([
            'live_code_id' => $this->idGenerator->generate(),
            'tenant_id' => $tenantId,
            'type' => $type,
            'code' => $this->generateCode(),
            'channel' => $channel,
            'target_url' => $assignedTarget,
            'expire_at' => $data['expire_at'] ?? null,
            'status' => 'active',
            'metadata' => [
                'strategy' => 'round_robin',
                'assigned_at' => now()->toIso8601String(),
            ],
        ]);

        return $liveCode->toArray();
    }

    /**
     * 轮转 — 切换到下一个目标
     */
    public function rotate($liveCodeId): array
    {
        $liveCode = LiveCode::where('live_code_id', $liveCodeId)->firstOrFail();

        $metadata = $liveCode->metadata ?? [];
        $rotateCount = ($metadata['rotate_count'] ?? 0) + 1;

        $liveCode->update([
            'metadata' => array_merge($metadata, [
                'rotate_count' => $rotateCount,
                'last_rotated_at' => now()->toIso8601String(),
            ]),
        ]);

        return $liveCode->fresh()->toArray();
    }

    /**
     * 过期活码
     */
    public function expire($liveCodeId): bool
    {
        $liveCode = LiveCode::where('live_code_id', $liveCodeId)->firstOrFail();

        return $liveCode->update([
            'status' => 'expired',
            'expire_at' => now(),
        ]) > 0;
    }

    /**
     * 轮询选择目标
     */
    protected function selectByRoundRobin($existingCodes, array $targetUrls): ?string
    {
        if (empty($targetUrls)) {
            return null;
        }

        $index = $existingCodes->count() % count($targetUrls);

        return $targetUrls[$index];
    }

    /**
     * 生成唯一活码编码
     */
    protected function generateCode(): string
    {
        return strtoupper(substr(md5((string) random_int(0, PHP_INT_MAX)), 0, 8));
    }
}
