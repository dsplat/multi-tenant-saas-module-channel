<?php

namespace MultiTenantSaas\Modules\Channel\Contracts;

interface LiveCodeStrategyContract
{
    public function assign($tenantId, array $data): array;

    public function rotate($liveCodeId): array;

    public function expire($liveCodeId): bool;
}
