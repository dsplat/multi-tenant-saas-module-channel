<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Channel\Http\Controllers\LiveCodeController;

// ========== 活码详情（扫码落地页：游客可看，服务端白名单裁剪） ==========
// 前缀由 ChannelServiceProvider::loadModuleRoutes() 注入（config('channel.route_prefix')），
// 中间件链 [api, throttle:api, tenant.identify, OptionalSanctumAuth]。
// 游客/学员仅回 live_code_id/type/code/target_url/metadata/is_expired；
// Operator 全量 + 扫码统计（stats）。渠道归属/到期原值/状态不外泄。
Route::get('live-codes/{live_code}', [LiveCodeController::class, 'show']);
