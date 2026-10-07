<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Channel\Listeners;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Models\OauthAccount;
use MultiTenantSaas\Modules\Channel\Events\LiveCodeScanned;
use MultiTenantSaas\Modules\Channel\Models\LiveCode;
use MultiTenantSaas\Modules\Channel\Services\ChannelCodeService;
use MultiTenantSaas\Modules\Channel\Services\ScanStatsService;
use MultiTenantSaas\Modules\Wechat\Events\WechatScanEventReceived;
use MultiTenantSaas\Scopes\TenantScope;
use Throwable;

/**
 * 微信扫码关注 → 活码归因（框架只发事件，业务落库全在这里）
 *
 * 事件来源：公众号消息回调 `WechatOfficialCallbackController` 解析
 * `subscribe`（未关注扫码，EventKey 带 qrscene_ 前缀）与 `SCAN`（已关注扫码）
 * 后分发，前缀已由框架剥离，`$event->scene` 恒为出码时烧入的原始 scene。
 *
 * scene → 活码的两种编码（出码侧见 ChannelCodeService::attachWechatQrcode）：
 * - `lc_{live_code_id}`：本服务默认写法，直接定位主键
 * - `{code}`：活码短码，兼容手工/第三方出码
 *
 * 落库三处（各自服务不同口径，缺一不可）：
 * 1. `scan_events`（经 ScanStatsService::recordScan）+ 活码 metadata 计数器 → 趋势与排名
 * 2. `LiveCodeScanned` 事件 → 广播归因上下文（见下），由各业务模块自行订阅落库：
 *    - Marketing 记 `ReachEvent(event_type=live_code_scanned)` 触达账
 *    - Distribution 完成分销首触归因（活码 metadata 带 distributor_id 时）
 *    - BehaviorEvent 行为埋点链路（RecordLiveCodeScanListener）
 * 3. 事件负载只携带 tenant / 活码 / 扫码人上下文，本监听器不再直连任何 scrm 模块。
 *
 * 微信 5 秒超时铁律：本 listener 在回调请求内同步执行，任何异常都不得抛回控制器
 * （抛出 → 回调 500 → 微信重试 3 次 → 仍失败则停用该公众号的消息推送）。
 */
class WechatScanEventListener
{
    /**
     * 扫码来源标识（scan_events.channel_source / LiveCodeScanned.scannerData.source）
     */
    public const CHANNEL_SOURCE = 'wechat_official';

    public function __construct(
        protected ScanStatsService $scanStats,
    ) {}

    public function handle(WechatScanEventReceived $event): void
    {
        try {
            $this->process($event);
        } catch (Throwable $e) {
            Log::error('[WechatScan] 扫码归因失败（已吞异常，回调仍回 success）', [
                'tenant_id' => $event->tenantId,
                'scene' => $event->scene,
                'event_type' => $event->eventType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function process(WechatScanEventReceived $event): void
    {
        // 回调线程已由框架 setTenantId，此处显式再设一次：listener 也可能被
        // 队列/命令重放触发，那时没有 HTTP 上下文，TenantScope 会 fail-closed
        TenantContext::setTenantId((string) $event->tenantId);

        $liveCode = $this->resolveLiveCode($event->tenantId, $event->scene);

        if ($liveCode === null) {
            // 不是活码的 scene（海报/活动等其他业务出码）不归本 listener 处理，
            // 记 debug 而非 warning：这是正常分流，不是故障
            Log::debug('[WechatScan] scene 未命中活码，跳过', [
                'tenant_id' => $event->tenantId,
                'scene' => $event->scene,
            ]);

            return;
        }

        $userId = $this->resolveUserId($event->tenantId, $event->openId);
        $codeId = (int) $liveCode->live_code_id;

        $extra = [
            'openid' => $event->openId,
            'scene' => $event->scene,
            'ticket' => $event->ticket,
            'wechat_event' => $event->eventType,
            'subscribed' => $event->isSubscribe(),
        ];

        $recorded = $this->scanStats->recordScan($codeId, $userId, array_merge([
            'channel_source' => self::CHANNEL_SOURCE,
        ], $extra));

        if (! $recorded) {
            // 活码已过期/停用：不落账（与 StoreCodeService 同口径），但要留痕
            Log::info('[WechatScan] 活码非活跃，扫码未记账', [
                'tenant_id' => $event->tenantId,
                'live_code_id' => $codeId,
                'status' => $liveCode->status,
            ]);

            return;
        }

        // 扫码关注 = 新增粉丝，记入 add_count，让既有「扫码→添加」转化率对公众号载体有意义
        if ($event->isSubscribe()) {
            $this->scanStats->recordAdd($codeId);
        }

        // 分销员 ID 随事件顶层下传：Distribution 侧监听 LiveCodeScanned 完成首触归因
        $distributorId = (int) (($liveCode->metadata ?? [])['distributor_id'] ?? 0);

        LiveCodeScanned::dispatch($codeId, $event->tenantId, array_merge([
            'user_id' => $userId,
            'source' => self::CHANNEL_SOURCE,
        ], $extra), $distributorId > 0 ? $distributorId : null);
    }

    /**
     * scene → 活码（主键编码优先，短码兜底）
     */
    protected function resolveLiveCode(int $tenantId, string $scene): ?LiveCode
    {
        if ($scene === '') {
            return null;
        }

        if (str_starts_with($scene, ChannelCodeService::SCENE_PREFIX)) {
            $id = substr($scene, strlen(ChannelCodeService::SCENE_PREFIX));

            if (ctype_digit($id)) {
                $liveCode = LiveCode::where('tenant_id', $tenantId)
                    ->where('live_code_id', (int) $id)
                    ->first();

                if ($liveCode !== null) {
                    return $liveCode;
                }
            }
        }

        return LiveCode::where('tenant_id', $tenantId)
            ->where('code', $scene)
            ->first();
    }

    /**
     * openid → user_id（扫码人是否已是本租户注册用户）
     *
     * 未注册返回 null：扫码关注 ≠ 注册，此时只有 openid 可用，落进
     * LiveCodeScanned.scannerData 保留归因线索，不臆造用户。
     */
    protected function resolveUserId(int $tenantId, string $openId): ?int
    {
        if ($openId === '') {
            return null;
        }

        // 绕过 TenantScope 按显式 tenant_id 查（同 WechatCredentialService::officialOpenidOfUser
        // 口径）：无 HTTP 上下文时全局 scope 会 fail-closed 查不到绑定
        $userId = OauthAccount::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('openid', $openId)
            ->where('provider', 'like', 'wechat:%')
            ->orderByDesc('created_at')
            ->value('user_id');

        return $userId !== null ? (int) $userId : null;
    }
}
