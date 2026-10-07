<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Channel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

class WelcomeMessage extends Model
{
    use BelongsToTenant, HasGlobalId, SoftDeletes;
    use SerializesFriendlyDates;

    protected $primaryKey = 'message_id';

    protected $fillable = [
        'tenant_id', 'name', 'channel', 'content', 'materials', 'status',
    ];

    protected function casts(): array
    {
        return [
            'materials' => 'array',
        ];
    }
}
