<?php

declare(strict_types=1);

/**
 * 渠道模块配置
 *
 * 物理归位自 scrm 项目层 Channel 模块。模块本身无独立运行参数，此处只提供
 * **路由前缀插口**（与 Membership/Miniapp 同约定），让下游沿用既有路由命名空间。
 */

return [

    /*
    |--------------------------------------------------------------------------
    | 路由前缀（配置插口）
    |--------------------------------------------------------------------------
    | api 与 optional 两组路由的公共前缀，由 ChannelServiceProvider::loadModuleRoutes() 读取：
    | - 框架默认 'api/v1'：/api/v1/channels、/api/v1/live-codes、/api/v1/welcome-messages …
    | - 下游（如 scrm）设 CHANNEL_ROUTE_PREFIX=api/v1/biz → /api/v1/biz/* URL 零变更。
    */
    'route_prefix' => env('CHANNEL_ROUTE_PREFIX', 'api/v1'),
];
