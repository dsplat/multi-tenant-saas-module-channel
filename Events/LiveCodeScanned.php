<?php

namespace MultiTenantSaas\Modules\Channel\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LiveCodeScanned
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $scannerData  扫码上下文（user_id / source / openid / scene …）
     * @param  int|null  $distributorId  活码 metadata 携带的分销员 ID（无则 null）
     *
     * $distributorId 刻意放在**顶层**而非塞进 $scannerData：后者会经
     * RecordLiveCodeScanListener 整体落进 BehaviorEvent.metadata，追加键即改变
     * 行为埋点的落库内容。顶层字段只供 Distribution 归因监听器消费。
     */
    public function __construct(
        public readonly mixed $liveCodeId,
        public readonly mixed $tenantId,
        public readonly array $scannerData,
        public readonly ?int $distributorId = null,
    ) {}
}
