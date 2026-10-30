<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Channel\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Channel\Services\LiveCodeStrategyService;

class CreateLiveCodeHandler implements ToolHandlerContract
{
    public function __construct(private readonly LiveCodeStrategyService $service) {}

    /**
     * 旧 handler 保留为直接调用入口（写路径已由 CreateLiveCodeWriteAdapter 接管）。
     *
     * 契约修正：LiveCodeStrategyService 没有 createLiveCode()，活码创建的唯一实现是
     * assign($tenantId, $data)（与 LiveCodeController::store 同口径）。工具参数
     * name / welcome_message / tags 中没有同名列，按写适配器的映射落入 metadata。
     */
    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $metadata = array_filter([
            'name' => $arguments['name'] ?? null,
            'welcome_message' => $arguments['welcome_message'] ?? null,
            'tags' => $arguments['tags'] ?? [],
            'source' => 'ai_tool',
        ], static fn ($value): bool => $value !== null);

        return $this->service->assign($tenantId, [
            'type' => 'channel',
            'channel' => $arguments['channel'] ?? null,
            'metadata' => $metadata,
        ]);
    }
}
