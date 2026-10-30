<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Channel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Channel\Models\Channel;
use MultiTenantSaas\Modules\Channel\Services\ChannelCallbackService;
use MultiTenantSaas\Modules\Channel\Services\ChannelCredentialSyncService;
use MultiTenantSaas\Modules\WechatWork\Services\WechatWorkSuiteService;
use MultiTenantSaas\Services\Channel\ChannelManager;

class ChannelController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        $query = Channel::where('tenant_id', $tenantId);

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $channels = $query->orderByDesc('created_at')
            ->paginate($request->input('per_page', 20));

        return response()->json(['success' => true, 'data' => $channels]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        $validated = $request->validate([
            'type' => 'required|in:wechat_official,wechat_work,telegram',
            'name' => 'required|string|max:100',
            'app_id' => 'nullable|string|max:100',
            'app_secret' => 'nullable|string',
            'agent_id' => 'nullable|string|max:100',
            'callback_token' => 'nullable|string|max:255',
            'encoding_aes_key' => 'nullable|string|max:255',
        ]);

        // 9.4-4 双体系互斥：代开发授权租户禁写自建应用凭证（与框架 9.2 口径一致）
        $this->assertWechatWorkCredentialsAllowed($tenantId, $validated['type'], $validated);

        $channel = Channel::create(array_merge($validated, [
            'tenant_id' => $tenantId,
            'status' => 'disconnected',
        ]));

        // 凭证双写：channels 表 → 框架 tenant_settings（group=channel），消息回调/测试连接读取同源
        app(ChannelCredentialSyncService::class)->sync($tenantId, $channel);

        Log::info('Channel created', [
            'tenant_id' => $tenantId,
            'channel_id' => $channel->channel_id,
            'type' => $channel->type,
        ]);

        return response()->json(['success' => true, 'data' => $channel], 201);
    }

    public function show($id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        $channel = Channel::where('tenant_id', $tenantId)
            ->where('channel_id', $id)
            ->firstOrFail();

        return response()->json(['success' => true, 'data' => $channel]);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        $channel = Channel::where('tenant_id', $tenantId)
            ->where('channel_id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'app_id' => 'nullable|string|max:100',
            'app_secret' => 'nullable|string',
            'agent_id' => 'nullable|string|max:100',
            'callback_token' => 'nullable|string|max:255',
            'encoding_aes_key' => 'nullable|string|max:255',
            // 扩展配置（metadata.session_archive.private_key 为企微会话存档 RSA 私钥 PEM）
            'metadata' => 'nullable|array',
        ]);

        // metadata 合并更新（保留未传的既有键）
        if (isset($validated['metadata'])) {
            $validated['metadata'] = array_merge($channel->metadata ?? [], $validated['metadata']);
        }

        // 9.4-4 双体系互斥：代开发授权租户禁写自建应用凭证
        $this->assertWechatWorkCredentialsAllowed($tenantId, $channel->type, $validated);

        $channel->update($validated);

        // 凭证双写：渠道编辑后同步框架 tenant_settings（含 Token/AESKey 变更，供企微 GET 验证）
        app(ChannelCredentialSyncService::class)->sync($tenantId, $channel->fresh());

        Log::info('Channel updated', [
            'tenant_id' => $tenantId,
            'channel_id' => $id,
        ]);

        return response()->json(['success' => true, 'data' => $channel->fresh()]);
    }

    public function destroy($id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        $channel = Channel::where('tenant_id', $tenantId)
            ->where('channel_id', $id)
            ->firstOrFail();

        $type = $channel->type;

        $channel->delete();

        // 渠道删除时清理框架凭证行，防止残留孤儿凭证影响 hasDriver/路由行为
        app(ChannelCredentialSyncService::class)->remove($tenantId, $type);

        Log::info('Channel deleted', [
            'tenant_id' => $tenantId,
            'channel_id' => $id,
        ]);

        return response()->json(['success' => true, 'message' => '渠道已删除']);
    }

    /**
     * 企微渠道凭证双体系互斥（9.4-4）
     *
     * 租户已完成代开发授权时禁写自建应用凭证（app_secret）：双体系只能二选一，
     * 自建凭证会与企业 token（permanent_code 充当 secret）打架，且自建出口 IP
     * 白名单约束与代开发代理体系互斥。WechatWork 模块为可选拆包，未安装时
     * class_exists 守卫跳过。
     *
     * @param  array<string, mixed>  $validated
     */
    private function assertWechatWorkCredentialsAllowed(int $tenantId, string $type, array $validated): void
    {
        if ($type !== 'wechat_work' || empty($validated['app_secret'] ?? '')) {
            return;
        }

        if (! class_exists(WechatWorkSuiteService::class) || ! Schema::hasTable('wechat_work_authorizations')) {
            return;
        }

        $authorization = app(WechatWorkSuiteService::class)->authorization($tenantId);

        if ($authorization !== null && $authorization->isAuthorized()) {
            abort(422, '当前租户已使用平台代开发应用授权，无需配置自建应用凭证；如需切换请先解除授权');
        }
    }

    public function testConnection($id, ChannelManager $channelManager): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        $channel = Channel::where('tenant_id', $tenantId)
            ->where('channel_id', $id)
            ->firstOrFail();

        // 映射 SCRM channels 表类型 → 框架 ChannelManager 驱动类型（kebab-case）
        $driverType = match ($channel->type) {
            'wechat_work' => 'wechat-work',
            default => null,
        };

        if ($driverType === null || ! $channelManager->hasDriver($driverType)) {
            return response()->json([
                'success' => false,
                'data' => ['connected' => false, 'message' => '不支持的渠道类型: ' . $channel->type],
            ]);
        }

        try {
            // 尝试解析驱动（读取 tenant_settings 凭证），成功即表示配置有效
            $channelManager->resolve($driverType, $tenantId);
            $channel->markConnected();

            return response()->json([
                'success' => true,
                'data' => ['connected' => true, 'message' => '渠道凭证配置有效'],
            ]);
        } catch (\Throwable $e) {
            $channel->markDisconnected();

            return response()->json([
                'success' => false,
                'data' => ['connected' => false, 'message' => $e->getMessage()],
            ]);
        }
    }

    /**
     * 回调信息（URL 恒定平台统一回调域，单一事实源见 ChannelCallbackService）
     */
    public function getCallbackInfo(Request $request, $id, ChannelCallbackService $callbacks): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        $channel = Channel::where('tenant_id', $tenantId)
            ->where('channel_id', $id)
            ->firstOrFail();

        $info = $callbacks->resolve($tenantId, $channel, $request->getHost());

        // 代开发授权态（租户消息走平台服务商链路 /suite/cz，渠道自建回调引导应让位）
        $suiteActive = false;
        if (class_exists(WechatWorkSuiteService::class) && Schema::hasTable('wechat_work_authorizations')) {
            $authorization = app(WechatWorkSuiteService::class)->authorization($tenantId);
            $suiteActive = $authorization !== null && $authorization->isAuthorized();
        }

        return response()->json([
            'success' => true,
            'data' => $info + [
                'suite_active' => $suiteActive,
                'note' => '接收消息服务器 URL 保存时，企微只校验「URL 可达 + msg_signature 验签通过 + echostr 解密并原样回显」，不校验域名备案主体（官方《回调配置》path/90930）；若保存报 403/验证失败，通常是本系统验签拒绝（凭证未录入、解不开 echostr），而非域名主体问题。仍建议用租户自有域名：前往「应用 → 接收消息 → 设置 API 接收」粘贴 URL/Token/EncodingAESKey 并保存（企微发起 GET 验证）。注意：另有「可信域名 / 网页授权域名」（OAuth、JS-SDK 用）才要求备案主体与企业主体一致，与本回调 URL 是两回事，勿混。',
            ],
        ]);
    }

    /**
     * 生成/重置消息回调凭证（Token + EncodingAESKey），落库并同步框架 tenant_settings。
     *
     * 两种取法二选一：
     *  1. 本端点生成后，复制三件套（URL/Token/AESKey）去企微后台「设置 API 接收」粘贴；
     *  2. 用户在企微后台点「随机获取」后，把 Token/AESKey 回填渠道表单保存（本端点无需调用）。
     */
    public function generateCallbackCredentials($id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        $channel = Channel::where('tenant_id', $tenantId)
            ->where('channel_id', $id)
            ->firstOrFail();

        if ($channel->type !== 'wechat_work') {
            abort(422, '仅企业微信自建应用渠道需要消息回调凭证');
        }

        $token = $this->randomAlphaNumeric(16);
        $encodingAesKey = $this->randomAlphaNumeric(43);

        $channel->update([
            'callback_token' => $token,
            'encoding_aes_key' => $encodingAesKey,
        ]);

        app(ChannelCredentialSyncService::class)->sync($tenantId, $channel->fresh());

        return response()->json([
            'success' => true,
            'data' => [
                'callback_token' => $token,
                'encoding_aes_key' => $encodingAesKey,
            ],
        ]);
    }

    /** 生成指定长度的字母数字随机串（Token 3-32 位、EncodingAESKey 43 位，企微字符集 A-Za-z0-9） */
    private function randomAlphaNumeric(int $length): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $out = '';
        $max = strlen($alphabet) - 1;

        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }
}
