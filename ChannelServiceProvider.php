<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Channel;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Events\MessageReceived;
use MultiTenantSaas\Modules\Channel\Console\Commands\ImportWechatOfficialCredentials;
use MultiTenantSaas\Modules\Channel\Contracts\CustomerIdentityResolverContract;
use MultiTenantSaas\Modules\Channel\Contracts\LiveCodeScanReachStatsContract;
use MultiTenantSaas\Modules\Channel\Contracts\LiveCodeStrategyContract;
use MultiTenantSaas\Modules\Channel\Listeners\MessageReceivedListener;
use MultiTenantSaas\Modules\Channel\Listeners\WechatScanEventListener;
use MultiTenantSaas\Modules\Channel\Services\LiveCodeStrategyService;
use MultiTenantSaas\Modules\Channel\Services\NullCustomerIdentityResolver;
use MultiTenantSaas\Modules\Channel\Services\NullLiveCodeScanReachStats;
use MultiTenantSaas\Modules\Channel\Services\Tools\CreateLiveCodeHandler;
use MultiTenantSaas\Modules\Channel\Services\Tools\ListWelcomeMessagesHandler;
use MultiTenantSaas\Modules\Channel\Services\Tools\SendMessageHandler;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Wechat\Events\WechatScanEventReceived;
use MultiTenantSaas\Support\OptionalModule;

/**
 * 渠道模块（框架侧标准件，物理归位自 scrm 项目层 Channel 模块）
 *
 * 渠道接入 / 活码（渠道码·门店码·员工码）/ 欢迎语 / 扫码归因。形态照抄 Membership/Miniapp：
 * 继承框架模块基类 ModuleServiceProvider，配置（Config/channel.php）由基类 mergeModuleConfig
 * 自动合并，迁移由基类 loadModuleMigrations 加载。
 *
 * 本模块的三处**自有接线**（scrm 侧原先分别在 channel ServiceProvider 与
 * app/Providers/EventServiceProvider 注册，归位后由本 Provider 自注册，使模块自洽）：
 * - **路由前缀插口**：覆写 loadModuleRoutes()，api/optional 两组前缀都取
 *   config('channel.route_prefix')（默认 api/v1）；下游设 CHANNEL_ROUTE_PREFIX=api/v1/biz
 *   即保持既有 /api/v1/biz/* URL 零变更。
 * - **事件监听器自注册**：bootModule() 内 Event::listen（见 registerListeners()）。
 * - **命令自注册**：registerModuleCommands() 钩子（基类 boot() 调用）。
 *
 * 两组中间件口径**照搬 scrm 现状**（非框架基类默认）：
 * - api 组：['api','auth:sanctum',VerifyOperatorTenant,'tenant.ensure']
 * - optional 组：['api','throttle:api','tenant.identify',OptionalSanctumAuth]
 * 中间件 FQCN 用**字符串常量**引用（同基类 MW_* 写法），避免把「Infrastructure 已装」隐式埋进路由。
 */
class ChannelServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'channel';

    /**
     * Infrastructure 提供的路由中间件（FQCN 字符串常量，理由同基类：core 刻意不假设
     * module-infrastructure 已装，故不用 `use X::class` 把它隐式埋进路由定义）。
     */
    private const MW_VERIFY_OPERATOR_TENANT = 'MultiTenantSaas\Modules\Infrastructure\Http\Middleware\VerifyOperatorTenant';

    private const MW_OPTIONAL_SANCTUM_AUTH = 'MultiTenantSaas\Modules\Infrastructure\Http\Middleware\OptionalSanctumAuth';

    protected function bootModule(): void
    {
        $this->registerListeners();
        $this->registerTools();
    }

    /**
     * 契约默认实现绑定（项目层可重新绑定自有实现顶掉）。
     *
     * 两个跨模块契约（客户身份解析 / 活码扫码触达账只读）的**事实源在下游**
     * （scrm Customer / Marketing），框架层无对应物；若不提供默认实现，框架独立安装时
     * 注入这两个契约的 LiveCodeController 会 BindingResolutionException 无法解析
     * （`route:list` / 中间件收集都会触发控制器实例化）。
     *
     * 故此处绑「空实现」（恒 null / 恒 0），项目层绑自有实现即顶掉（见
     * docs/module-pool-architecture.md「覆盖默认实现」插口）。
     *
     * **用 `bound()` 守卫**：下游 scrm 在 AppServiceProvider::register 已绑自有实现，
     * 而模块 Provider 随 ModuleBootstrapper 在 boot 阶段才 register —— 无条件覆盖会把
     * 项目实现顶掉。守卫后「先绑者胜」（项目先绑则模块让路），保证归位不改下游行为。
     */
    protected function registerModuleBindings(): void
    {
        if (! $this->app->bound(CustomerIdentityResolverContract::class)) {
            $this->app->singleton(CustomerIdentityResolverContract::class, NullCustomerIdentityResolver::class);
        }

        if (! $this->app->bound(LiveCodeScanReachStatsContract::class)) {
            $this->app->singleton(LiveCodeScanReachStatsContract::class, NullLiveCodeScanReachStats::class);
        }

        if (! $this->app->bound(LiveCodeStrategyContract::class)) {
            $this->app->bind(LiveCodeStrategyContract::class, LiveCodeStrategyService::class);
        }
    }

    protected function registerModuleCommands(): void
    {
        $this->commands([ImportWechatOfficialCredentials::class]);
    }

    /**
     * 覆写基类路由加载：两组前缀均取 config('channel.route_prefix','api/v1')。
     *
     * 之所以不复用基类（其 api 组为 ['api','auth:sanctum','throttle:api','tenant.identify',
     * VerifyOperatorTenant, module.enabled]）：本模块下游（scrm）的既有契约是
     * api + auth:sanctum + VerifyOperatorTenant + tenant.ensure，且不带 module.enabled 门控，
     * 归位时须**逐字保持**以免改变生产路由行为。optional 组同 scrm 现状。
     */
    protected function loadModuleRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        // 与基类同口径：路由别名/中间件由 module-infrastructure 提供，缺它时尽早抛可捕获异常。
        OptionalModule::ensure(self::MW_VERIFY_OPERATOR_TENANT, '渠道模块路由加载', 'Infrastructure');

        $moduleDir = $this->getModulePath();
        $prefix = (string) config('channel.route_prefix', 'api/v1');

        // 后台管理组（渠道/活码/欢迎语）：api + Sanctum + Operator 归属校验 + 租户保证
        $apiRoute = $moduleDir . '/Routes/api.php';
        if (file_exists($apiRoute)) {
            Route::middleware(['api', 'auth:sanctum', self::MW_VERIFY_OPERATOR_TENANT, 'tenant.ensure'])
                ->prefix($prefix)
                ->group($apiRoute);
        }

        // 可选认证组（活码落地页：游客可读 + 登录后个性化）：api + 限流 + 租户识别 + 可选登录态
        $optionalRoute = $moduleDir . '/Routes/optional.php';
        if (file_exists($optionalRoute)) {
            Route::middleware(['api', 'throttle:api', 'tenant.identify', self::MW_OPTIONAL_SANCTUM_AUTH])
                ->prefix($prefix)
                ->group($optionalRoute);
        }
    }

    /**
     * 注册框架事件监听器（模块自洽；scrm 侧同名注册须在 Stage 2 移除，否则双重触发）
     *
     * 1. MessageReceived：框架渠道管道入库后触发 → 客户关联 + AI 自动回复
     * 2. WechatScanEventReceived：公众号扫码关注回调解析后触发 → 归因回活码
     *
     * Wechat 是独立拆包：未安装时事件类不存在，class_exists 守卫后降级为「扫码关注不归因」，
     * 不影响模块其余能力，也不会在应用启动时 class-not-found。
     */
    private function registerListeners(): void
    {
        Event::listen(MessageReceived::class, MessageReceivedListener::class);

        if (class_exists(WechatScanEventReceived::class)) {
            Event::listen(WechatScanEventReceived::class, WechatScanEventListener::class);
        }
    }

    /**
     * 注册渠道 AI 工具（Console 系统小秘书代配置用）。
     *
     * Ai 模块未启用时静默跳过——工具注册表由 Ai 提供，缺失则不注册。
     */
    private function registerTools(): void
    {
        if (! $this->app->bound(ToolRegistryContract::class)) {
            return;
        }

        $registry = $this->app->make(ToolRegistryContract::class);

        $registry->register(
            slug: 'send_message',
            name: '发送消息',
            description: '通过渠道发送消息',
            handlerClass: SendMessageHandler::class,
            schema: [
                'type' => 'object',
                'properties' => [
                    'channel' => ['type' => 'string', 'description' => '渠道类型'],
                    'to' => ['type' => 'string', 'description' => '接收者 ID'],
                    'message' => ['type' => 'string', 'description' => '消息内容'],
                    'message_type' => ['type' => 'string', 'description' => '消息类型: text/image/link', 'default' => 'text'],
                ],
                'required' => ['channel', 'to', 'message'],
            ],
            category: 'channel',
            risk: 'L2',
        );

        $registry->register(
            slug: 'create_live_code',
            name: '创建活码',
            description: '创建活码（扫码引流）',
            handlerClass: CreateLiveCodeHandler::class,
            schema: [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => '活码名称'],
                    'channel' => ['type' => 'string', 'description' => '绑定渠道'],
                    'welcome_message' => ['type' => 'string', 'description' => '欢迎语'],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '自动标签'],
                ],
                'required' => ['name', 'channel'],
            ],
            category: 'channel',
            risk: 'L2',
        );

        $registry->register(
            slug: 'list_welcome_messages',
            name: '欢迎语列表',
            description: '获取各渠道欢迎语配置列表（企微/公众号/Telegram/短信）',
            handlerClass: ListWelcomeMessagesHandler::class,
            schema: [
                'type' => 'object',
                'properties' => [
                    'channel' => ['type' => 'string', 'description' => '渠道: wechat_work/wechat_official/telegram/sms'],
                    'status' => ['type' => 'string', 'description' => '状态: active/inactive'],
                    'per_page' => ['type' => 'integer', 'description' => '每页数量', 'default' => 20],
                ],
            ],
            category: 'channel',
            risk: 'L1',
        );
    }
}
