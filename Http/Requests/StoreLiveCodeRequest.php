<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Channel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLiveCodeRequest extends FormRequest
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
            'type' => 'required|string|in:qrcode,short_link,channel,store,employee',
            'channel' => 'nullable|string|max:100',
            'target_url' => 'nullable|string|max:500',
            'target_urls' => 'nullable|array',
            'target_urls.*' => 'string|max:500',
            'expire_at' => 'nullable|date',
            'metadata' => 'nullable|array',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => '活码类型不能为空',
            'type.in' => '活码类型只能是 qrcode、short_link、channel、store 或 employee',
            'expire_at.date' => '过期时间格式不正确',
        ];
    }
}
