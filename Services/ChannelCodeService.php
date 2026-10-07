<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Channel\Services;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Exceptions\ServiceUnavailableException;
use MultiTenantSaas\Modules\Channel\Contracts\LiveCodeScanReachStatsContract;
use MultiTenantSaas\Modules\Channel\Models\LiveCode;
use MultiTenantSaas\Modules\Wechat\Services\WechatQrcodeService;

/**
 * 活码（渠道码 / 门店码）创建与统计
 *
 * 两种载体的分工（不是二选一，可叠加）：
 * - 自有短码（默认）：live_codes.code + target_url，扫码打开落地页时由前端上报，
 *   只能统计「落地页访问」，拿不到扫码人身份
 * - 微信带参二维码（carrier=wechat_qrcode）：向公众号换取官方码，用户扫码关注时
 *   微信推送 subscribe/SCAN 事件带 openid + scene，由 WechatScanEventListener
 *   归因回本活码 —— 这是唯一能拿到「谁扫的、是否新关注」的数据源
 */
class ChannelCodeService
{
    /**
     * 载体标识：微信带参二维码
     */
    public const CARRIER_WECHAT_QRCODE = 'wechat_qrcode';

    /**
     * live_codes.metadata 中承载微信码信息的键（不改表结构，JSON 承载）
     */
    public const META_WECHAT_QRCODE = 'wechat_qrcode';

    /**
     * scene 编码前缀：lc_{live_code_id}
     *
     * 直接编码主键而非活码短码，扫码事件回来时 O(1) 定位；短码形式
     * （8 位大写字母数字）由 WechatScanEventListener 一并兼容解析。
     */
    public const SCENE_PREFIX = 'lc_';

    /**
     * 框架二维码台账的业务归属类型（wechat_qrcodes.biz_type）
     */
    public const QRCODE_BIZ_TYPE = 'live_code';

    public function __construct(
        protected IdGeneratorContract $idGenerator,
        protected LiveCodeScanReachStatsContract $reachStats,
    ) {}

    /**
     * 创建渠道活码
     *
     * @param  array<string, mixed>  $data  channel / target_url / expire_at / metadata /
     *                                      carrier（=wechat_qrcode 时额外出微信带参二维码）
     * @return array<string, mixed>
     */
    public function createChannelCode($tenantId, array $data): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $liveCode = LiveCode::create([
            'live_code_id' => $this->idGenerator->generate(),
            'tenant_id' => $tenantId,
            'type' => 'channel',
            'code' => $this->generateCode(),
            'channel' => $data['channel'] ?? 'wechat',
            'target_url' => $data['target_url'] ?? null,
            'expire_at' => $data['expire_at'] ?? null,
            'status' => 'active',
            'metadata' => array_merge($data['metadata'] ?? [], [
                'created_via' => 'channel_code_service',
                'created_at' => now()->toIso8601String(),
            ]),
        ]);

        if (($data['carrier'] ?? '') === self::CARRIER_WECHAT_QRCODE) {
            try {
                $this->attachWechatQrcode($tenantId, $liveCode->live_code_id, $data);
            } catch (\Throwable $e) {
                // 出码失败不留半截活码：残留的 active 记录会进列表/排名/统计，
                // 且它没有可用的微信码 —— 运营只会看到「建了个废码」
                $liveCode->delete();

                throw $e;
            }

            return $liveCode->fresh()->toArray();
        }

        return $liveCode->toArray();
    }

    /**
     * 为既有活码换取微信带参二维码（幂等）
     *
     * 幂等由框架侧保证：永久码同 scene + 同业务归属已存在时直接复用存量，
     * 不重复消耗公众号 10 万配额；确需换一张新码传 force=true。
     *
     * @param  array<string, mixed>  $options  scene / permanent / expire_seconds / force
     * @return array<string, mixed> 写入 metadata.wechat_qrcode 的内容
     *
     * @throws ServiceUnavailableException 框架 Wechat 模块未就绪，或微信侧拒绝出码
     */
    public function attachWechatQrcode($tenantId, $liveCodeId, array $options = []): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $liveCode = LiveCode::where('live_code_id', $liveCodeId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $scene = trim((string) ($options['scene'] ?? ''));
        $scene = $scene !== '' ? $scene : self::SCENE_PREFIX . $liveCode->live_code_id;

        $result = $this->wechatQrcodeService()->create((int) $tenantId, [
            'scene' => $scene,
            // 默认永久码：活码是长期投放物料，临时码最长 30 天会到期失效，
            // 且过期后同一活码要重新出码（既吃配额又让已印刷的物料作废）
            'permanent' => array_key_exists('permanent', $options) ? (bool) $options['permanent'] : true,
            'expire_seconds' => (int) ($options['expire_seconds'] ?? 0),
            'biz_type' => self::QRCODE_BIZ_TYPE,
            'biz_id' => (string) $liveCode->live_code_id,
            'metadata' => [
                'live_code_id' => (string) $liveCode->live_code_id,
                'code' => (string) $liveCode->code,
                'channel' => (string) $liveCode->channel,
            ],
            'force' => (bool) ($options['force'] ?? false),
        ]);

        $payload = [
            'scene' => (string) $result['scene'],
            'ticket' => (string) $result['ticket'],
            'qrcode_image_url' => (string) $result['url'],
            'expire_seconds' => (int) $result['expire_seconds'],
            'permanent' => (bool) $result['permanent'],
            'reused' => (bool) $result['reused'],
            'qrcode_id' => (int) $result['qrcode_id'],
            'generated_at' => now()->toIso8601String(),
        ];

        $metadata = $liveCode->metadata ?? [];
        $metadata[self::META_WECHAT_QRCODE] = $payload;
        $metadata['carrier'] = self::CARRIER_WECHAT_QRCODE;

        $liveCode->update(['metadata' => $metadata]);

        return $payload;
    }

    public function createStoreCode($tenantId, array $data): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $liveCode = LiveCode::create([
            'live_code_id' => $this->idGenerator->generate(),
            'tenant_id' => $tenantId,
            'type' => 'store',
            'code' => $this->generateCode(),
            'channel' => $data['channel'] ?? 'wechat',
            'target_url' => $data['target_url'] ?? null,
            'expire_at' => $data['expire_at'] ?? null,
            'status' => 'active',
            'metadata' => array_merge($data['metadata'] ?? [], [
                'store_id' => $data['store_id'] ?? null,
                'store_name' => $data['store_name'] ?? null,
                'created_via' => 'channel_code_service',
                'created_at' => now()->toIso8601String(),
            ]),
        ]);

        return $liveCode->toArray();
    }

    public function getStats($tenantId, $codeId): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $liveCode = LiveCode::where('live_code_id', $codeId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        // 触达账读 Marketing 私有模型会耦合跨模块：改走只读契约（Marketing 实现）
        $scanCount = $this->reachStats->countScans((int) $tenantId, (int) $codeId);
        $todayScanCount = $this->reachStats->countScansOnDate((int) $tenantId, (int) $codeId, now()->toDateString());

        return [
            'live_code_id' => $liveCode->live_code_id,
            'type' => $liveCode->type,
            'code' => $liveCode->code,
            'status' => $liveCode->status,
            'total_scans' => $scanCount,
            'today_scans' => $todayScanCount,
            'expire_at' => $liveCode->expire_at?->toIso8601String(),
            'created_at' => $liveCode->created_at->toIso8601String(),
        ];
    }

    public function expire($tenantId, $codeId): bool
    {
        TenantContext::setTenantId((string) $tenantId);

        $liveCode = LiveCode::where('live_code_id', $codeId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        return $liveCode->update([
            'status' => 'expired',
            'expire_at' => now(),
        ]) > 0;
    }

    protected function generateCode(): string
    {
        return strtoupper(substr(md5((string) random_int(0, PHP_INT_MAX)), 0, 8));
    }

    /**
     * 框架带参二维码服务（延迟解析 + 缺包守卫）
     *
     * 不做构造器注入：Wechat 是独立拆包，下游若只发了后台没同步框架包
     * （deploy.md 记过的静默失败模式），构造器注入会让**所有**活码创建
     * 直接 class-not-found 500；延迟解析把爆炸半径收敛到显式要求
     * carrier=wechat_qrcode 的那一次调用，且给可读原因。
     */
    protected function wechatQrcodeService(): object
    {
        if (! class_exists(WechatQrcodeService::class)) {
            throw new ServiceUnavailableException(
                '微信带参二维码能力不可用：请升级框架包 dsplat/multi-tenant-saas-module-wechat'
            );
        }

        return app(WechatQrcodeService::class);
    }
}
