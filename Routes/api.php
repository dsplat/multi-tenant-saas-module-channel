<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Channel\Http\Controllers\ChannelController;
use MultiTenantSaas\Modules\Channel\Http\Controllers\LiveCodeController;
use MultiTenantSaas\Modules\Channel\Http\Controllers\WelcomeMessageController;

// 渠道模块后台组：前缀由 ChannelServiceProvider::loadModuleRoutes() 注入
// （config('channel.route_prefix')，框架默认 api/v1；下游设 CHANNEL_ROUTE_PREFIX=api/v1/biz
// 即保持既有 /api/v1/biz/* URL 零变更）+ 中间件链
// [api, auth:sanctum, VerifyOperatorTenant, tenant.ensure]。故此处路由路径不含前缀段。
//
// P0 授权门（ADR v2.5）：读 → channel.view；写/凭证/回调配置 → channel.manage。
// 权限名由 framework_core_data 的 2026_10_19_000001_add_missing_tenant_permissions 步骤入库。

// 渠道管理：读
Route::prefix('channels')->middleware('rbac.permission:channel.view')->group(function () {
    Route::get('/', [ChannelController::class, 'index']);
    Route::get('{id}', [ChannelController::class, 'show']);
    Route::get('{id}/callback-info', [ChannelController::class, 'getCallbackInfo']);
});

// 渠道管理：写
Route::prefix('channels')->middleware('rbac.permission:channel.manage')->group(function () {
    Route::post('/', [ChannelController::class, 'store']);
    Route::put('{id}', [ChannelController::class, 'update']);
    Route::delete('{id}', [ChannelController::class, 'destroy']);
    Route::post('{id}/test', [ChannelController::class, 'testConnection']);
    Route::post('{id}/generate-callback-credentials', [ChannelController::class, 'generateCallbackCredentials']);
});

// 活码管理：读（静态路径须在 {id} 前注册；GET show 已迁 Routes/optional.php：游客可看落地信息）
Route::middleware('rbac.permission:channel.view')->group(function () {
    Route::get('live-codes/ranking', [LiveCodeController::class, 'getRanking']);
    Route::get('live-codes', [LiveCodeController::class, 'index']);
    Route::get('live-codes/{id}/stats', [LiveCodeController::class, 'getStats']);
});

// 活码管理：写
Route::middleware('rbac.permission:channel.manage')->group(function () {
    Route::post('live-codes/{id}/wechat-qrcode', [LiveCodeController::class, 'attachWechatQrcode']);
    Route::post('live-codes', [LiveCodeController::class, 'store']);
    Route::match(['put', 'patch'], 'live-codes/{id}', [LiveCodeController::class, 'update']);
    Route::delete('live-codes/{id}', [LiveCodeController::class, 'destroy']);
});

// 欢迎语：读 / 写
Route::get('welcome-messages', [WelcomeMessageController::class, 'index'])
    ->middleware('rbac.permission:channel.view');
Route::get('welcome-messages/{id}', [WelcomeMessageController::class, 'show'])
    ->middleware('rbac.permission:channel.view');
Route::post('welcome-messages', [WelcomeMessageController::class, 'store'])
    ->middleware('rbac.permission:channel.manage');
Route::match(['put', 'patch'], 'welcome-messages/{id}', [WelcomeMessageController::class, 'update'])
    ->middleware('rbac.permission:channel.manage');
Route::delete('welcome-messages/{id}', [WelcomeMessageController::class, 'destroy'])
    ->middleware('rbac.permission:channel.manage');
