<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Channel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

class Channel extends Model
{
    use BelongsToTenant, HasGlobalId;
    use SerializesFriendlyDates;

    protected $table = 'channels';

    protected $primaryKey = 'channel_id';

    protected $fillable = [
        'tenant_id', 'type', 'name', 'app_id', 'app_secret',
        'agent_id', 'callback_token', 'encoding_aes_key',
        'status', 'metadata', 'last_connected_at',
    ];

    protected function casts(): array
    {
        return [
            'app_secret' => 'encrypted',
            'metadata' => 'array',
            'last_connected_at' => 'datetime',
        ];
    }

    public function liveCodes(): HasMany
    {
        return $this->hasMany(LiveCode::class, 'channel', 'channel_id');
    }

    public function isWechatWork(): bool
    {
        return $this->type === 'wechat_work';
    }

    public function isWechatOfficial(): bool
    {
        return $this->type === 'wechat_official';
    }

    public function isTelegram(): bool
    {
        return $this->type === 'telegram';
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected';
    }

    public function markConnected(): void
    {
        $this->update([
            'status' => 'connected',
            'last_connected_at' => now(),
        ]);
    }

    public function markDisconnected(): void
    {
        $this->update(['status' => 'disconnected']);
    }
}
