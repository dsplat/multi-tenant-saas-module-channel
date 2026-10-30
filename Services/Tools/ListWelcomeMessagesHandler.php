<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Channel\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Channel\Models\WelcomeMessage;

/**
 * 欢迎语列表（只读，L1）
 */
class ListWelcomeMessagesHandler implements ToolHandlerContract
{
    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $query = WelcomeMessage::where('tenant_id', $tenantId);

        $channel = (string) ($arguments['channel'] ?? '');
        if ($channel !== '') {
            $query->where('channel', $channel);
        }

        $status = (string) ($arguments['status'] ?? '');
        if ($status !== '') {
            $query->where('status', $status);
        }

        $paginator = $query->orderByDesc('created_at')
            ->paginate((int) ($arguments['per_page'] ?? 20));

        return [
            'data' => $paginator->items(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
        ];
    }
}
