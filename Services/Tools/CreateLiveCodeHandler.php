<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Channel\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Channel\Services\LiveCodeStrategyService;

class CreateLiveCodeHandler implements ToolHandlerContract
{
    public function __construct(private readonly LiveCodeStrategyService $service) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return $this->service->createLiveCode($arguments);
    }
}
