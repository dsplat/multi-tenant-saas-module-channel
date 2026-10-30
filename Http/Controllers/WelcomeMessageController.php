<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Channel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Channel\Models\WelcomeMessage;

class WelcomeMessageController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $perPage = (int) $request->input('per_page', 20);

        $query = WelcomeMessage::where('tenant_id', $tenantId);

        if ($channel = $request->input('channel')) {
            $query->where('channel', $channel);
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $paginator = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
        ]);
    }

    public function show($id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        $message = WelcomeMessage::where('tenant_id', $tenantId)
            ->where('message_id', $id)
            ->first();

        if (! $message) {
            return response()->json(['success' => false, 'message' => '欢迎语不存在'], 404);
        }

        return response()->json(['success' => true, 'data' => $message]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:200',
            'channel' => 'required|string|max:50',
            'content' => 'required|string|max:2000',
            'materials' => 'nullable|array',
            'status' => 'nullable|string|in:active,inactive',
        ]);

        $tenantId = (int) TenantContext::getId();

        $message = WelcomeMessage::create([
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'channel' => $validated['channel'],
            'content' => $validated['content'],
            'materials' => $validated['materials'] ?? [],
            'status' => $validated['status'] ?? 'active',
        ]);

        return response()->json(['success' => true, 'data' => $message], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:200',
            'channel' => 'sometimes|string|max:50',
            'content' => 'sometimes|string|max:2000',
            'materials' => 'sometimes|array',
            'status' => 'sometimes|string|in:active,inactive',
        ]);

        $tenantId = (int) TenantContext::getId();

        $message = WelcomeMessage::where('tenant_id', $tenantId)
            ->where('message_id', $id)
            ->first();

        if (! $message) {
            return response()->json(['success' => false, 'message' => '欢迎语不存在'], 404);
        }

        $message->update($validated);

        return response()->json(['success' => true, 'data' => $message->fresh()]);
    }

    public function destroy($id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        $message = WelcomeMessage::where('tenant_id', $tenantId)
            ->where('message_id', $id)
            ->first();

        if (! $message) {
            return response()->json(['success' => false, 'message' => '欢迎语不存在'], 404);
        }

        $message->delete();

        return response()->json(['success' => true, 'message' => '欢迎语已删除']);
    }
}
