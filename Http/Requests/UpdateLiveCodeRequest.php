<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Channel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLiveCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'target_url' => 'nullable|string|max:500',
            'expire_at' => 'nullable|date',
            'status' => 'nullable|string|in:active,expired,disabled',
            'metadata' => 'nullable|array',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => '状态只能是 active、expired 或 disabled',
        ];
    }
}
