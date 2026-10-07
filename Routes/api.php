<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Channel\Http\Controllers\ChannelController;
use MultiTenantSaas\Modules\Channel\Http\Controllers\LiveCodeController;
use MultiTenantSaas\Modules\Channel\Http\Controllers\WelcomeMessageController;

// 渠道模块后台组：前缀由 ChannelServiceProvider::loadModuleRoutes() 注入
// （config('channel.route_prefix')，框架默认 api/v1；下游设 CHANNEL_ROUTE_PREFIX=api/v1/biz
// 即保持既有 /api/v1/biz/* URL 零变更）+ 中间件链
// [api, auth:sanctum, VerifyOperatorTenant, tenant.ensure]。故此处路由路径不含前缀段。

// 渠道管理
Route::prefix('channels')->group(function () {
    Route::get('/', [ChannelController::class, 'index']);
    Route::post('/', [ChannelController::class, 'store']);
    Route::get('{id}', [ChannelController::class, 'show']);
    Route::put('{id}', [ChannelController::class, 'update']);
    Route::delete('{id}', [ChannelController::class, 'destroy']);
    Route::post('{id}/test', [ChannelController::class, 'testConnection']);
    Route::get('{id}/callback-info', [ChannelController::class, 'getCallbackInfo']);
    Route::post('{id}/generate-callback-credentials', [ChannelController::class, 'generateCallbackCredentials']);
});
// 活码管理（静态路径须在 {id} 前注册；GET show 已迁 Routes/optional.php：游客可看落地信息）
Route::get('live-codes/ranking', [LiveCodeController::class, 'getRanking']);
Route::post('live-codes/{id}/wechat-qrcode', [LiveCodeController::class, 'attachWechatQrcode']);
Route::get('live-codes', [LiveCodeController::class, 'index']);
Route::post('live-codes', [LiveCodeController::class, 'store']);
Route::match(['put', 'patch'], 'live-codes/{id}', [LiveCodeController::class, 'update']);
Route::delete('live-codes/{id}', [LiveCodeController::class, 'destroy']);
Route::get('live-codes/{id}/stats', [LiveCodeController::class, 'getStats']);
// 欢迎语
Route::apiResource('welcome-messages', WelcomeMessageController::class);
