<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Channel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Channel\Http\Requests\StoreLiveCodeRequest;
use MultiTenantSaas\Modules\Channel\Http\Requests\UpdateLiveCodeRequest;
use MultiTenantSaas\Modules\Channel\Models\LiveCode;
use MultiTenantSaas\Modules\Channel\Services\ChannelCodeService;
use MultiTenantSaas\Modules\Channel\Services\LiveCodeStrategyService;
use MultiTenantSaas\Modules\Channel\Services\ScanStatsService;
use MultiTenantSaas\Modules\Operator\Models\Operator;

class LiveCodeController extends BaseController
{
    public function __construct(
        protected LiveCodeStrategyService $liveCodeService,
        protected ScanStatsService $scanStatsService,
        protected ChannelCodeService $channelCodeService,
    ) {}

    /**
     * 活码列表 - 分页、类型筛选
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        $query = LiveCode::where('tenant_id', $tenantId);

        // 类型筛选
        if ($request->filled('type')) {
            $query->byType($request->input('type'));
        }

        // 渠道筛选
        if ($request->filled('channel')) {
            $query->byChannel($request->input('channel'));
        }

        // 状态筛选
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // 搜索
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('target_url', 'like', "%{$search}%");
            });
        }

        $liveCodes = $query->orderByDesc('created_at')
            ->paginate($request->input('per_page', 20));

        return response()->json(['success' => true, 'data' => $liveCodes]);
    }

    /**
     * 创建活码 - 支持渠道码/门店码/员工码
     */
    public function store(StoreLiveCodeRequest $request): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        $result = $this->liveCodeService->assign($tenantId, $request->validated());

        Log::info('LiveCodeController: 创建活码', [
            'tenant_id' => $tenantId,
            'type' => $request->input('type'),
            'live_code_id' => $result['live_code_id'] ?? null,
        ]);

        return response()->json(['success' => true, 'data' => $result], 201);
    }

    /**
     * 活码详情 - 含配置、统计（optional 路由组：游客/学员白名单裁剪，Operator 全量 + stats）
     */
    public function show(Request $request, $id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        $liveCode = LiveCode::where('live_code_id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        // 游客/学员：服务端白名单裁剪（渠道归属/到期原值/状态/扫码统计不外泄）
        if (! $request->user() instanceof Operator) {
            return response()->json([
                'success' => true,
                'data' => $this->toPublicLiveCode($liveCode),
            ]);
        }

        // 获取统计数据
        $stats = $this->scanStatsService->getLiveCodeStats($id);

        return response()->json([
            'success' => true,
            'data' => array_merge($liveCode->toArray(), [
                'stats' => $stats['summary'] ?? [],
            ]),
        ]);
    }

    /**
     * 游客/学员公开视图：只回落地必需字段；
     * 剔除 channel（渠道归属）/tenant_id/expire_at/status 原值与 stats（扫描统计），
     * is_expired 由服务端按 expire_at 计算，可替代原始到期时间。
     */
    private function toPublicLiveCode(LiveCode $liveCode): array
    {
        return [
            'live_code_id' => $liveCode->live_code_id,
            'type' => $liveCode->type,
            'code' => $liveCode->code,
            'target_url' => $liveCode->target_url,
            'metadata' => $liveCode->metadata,
            'is_expired' => $liveCode->isExpired(),
        ];
    }

    /**
     * 更新活码
     */
    public function update(UpdateLiveCodeRequest $request, $id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        $liveCode = LiveCode::where('live_code_id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $liveCode->update($request->validated());

        Log::info('LiveCodeController: 更新活码', [
            'tenant_id' => $tenantId,
            'live_code_id' => $id,
        ]);

        return response()->json(['success' => true, 'data' => $liveCode->fresh()]);
    }

    /**
     * 删除活码
     */
    public function destroy($id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        $liveCode = LiveCode::where('live_code_id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $liveCode->delete();

        Log::info('LiveCodeController: 删除活码', [
            'tenant_id' => $tenantId,
            'live_code_id' => $id,
        ]);

        return response()->json(['success' => true, 'message' => '活码已删除']);
    }

    /**
     * 扫码统计
     */
    public function getStats(Request $request, $id): JsonResponse
    {
        $dateRange = $request->only(['start', 'end']);

        $stats = $this->scanStatsService->getLiveCodeStats($id, $dateRange);

        return response()->json(['success' => true, 'data' => $stats]);
    }

    /**
     * 为活码换取微信带参二维码（幂等：永久码同 scene 复用存量，不重复吃配额）
     *
     * 与自有短码的区别：短码只能统计落地页访问，带参二维码能拿到微信推送的
     * 扫码关注事件（openid + scene），由 WechatScanEventListener 归因回本活码。
     *
     * 前置：公众号 self 凭证已配且为**已认证服务号**（订阅号/未认证号微信回 48001）。
     * 失败一律 422 + 可读原因（包含「框架包未升级」），不静默降级成短码。
     */
    public function attachWechatQrcode(Request $request, $id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        $options = $request->validate([
            'scene' => ['nullable', 'string', 'max:64'],
            'permanent' => ['sometimes', 'boolean'],
            'expire_seconds' => ['nullable', 'integer', 'min:60', 'max:2592000'],
            'force' => ['sometimes', 'boolean'],
        ], [
            'expire_seconds.min' => '临时二维码有效期不得少于 60 秒',
            'expire_seconds.max' => '临时二维码有效期不得超过 30 天（2592000 秒）',
        ]);

        try {
            $qrcode = $this->channelCodeService->attachWechatQrcode($tenantId, $id, $options);
        } catch (DomainException $e) {
            // ServiceUnavailableException 继承 DomainException，此处一并收口
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        Log::info('LiveCodeController: 生成微信带参二维码', [
            'tenant_id' => $tenantId,
            'live_code_id' => $id,
            'scene' => $qrcode['scene'],
            'permanent' => $qrcode['permanent'],
            'reused' => $qrcode['reused'],
        ]);

        return response()->json([
            'success' => true,
            'message' => $qrcode['reused'] ? '已复用存量永久二维码（未消耗新配额）' : '微信带参二维码已生成',
            'data' => $qrcode,
        ]);
    }

    /**
     * 活码排名
     */
    public function getRanking(Request $request): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        $sortBy = $request->input('sort_by', 'scans');
        $limit = (int) $request->input('limit', 10);
        $dateRange = $request->only(['start', 'end']);

        $ranking = $this->scanStatsService->getRanking($tenantId, $dateRange, $sortBy, $limit);

        return response()->json(['success' => true, 'data' => $ranking]);
    }
}
