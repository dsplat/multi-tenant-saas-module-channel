<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Channel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 锁客绑定关系模型
 *
 * 记录客户与员工的锁客绑定关系，支持首次扫码绑定和后续自动识别。
 */
class LockCodeBinding extends Model
{
    use BelongsToTenant, HasGlobalId, SoftDeletes;
    use SerializesFriendlyDates;

    protected $primaryKey = 'binding_id';

    protected $fillable = [
        'tenant_id',
        'lock_code_id',
        'user_id',
        'staff_id',
        'locked_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'locked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function liveCode(): BelongsTo
    {
        return $this->belongsTo(LiveCode::class, 'lock_code_id', 'live_code_id');
    }

    /**
     * 按活码查询
     */
    public function scopeByLockCode($query, $lockCodeId)
    {
        return $query->where('lock_code_id', $lockCodeId);
    }

    /**
     * 按员工查询
     */
    public function scopeByStaff($query, $staffId)
    {
        return $query->where('staff_id', $staffId);
    }

    /**
     * 按客户查询
     */
    public function scopeByCustomer($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}
