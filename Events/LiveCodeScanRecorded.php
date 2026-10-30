<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Channel\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * 活码扫码上报（服务侧直接记账入口）
 *
 * 与 `LiveCodeScanned` 的分工：
 * - `LiveCodeScanned`：公众号扫码关注回调链路，携带 openid/scene/ticket 等微信上下文
 * - `LiveCodeScanRecorded`：服务侧（LockCodeService::recordScan）直接上报一次扫码，
 *   只带 message_id/user_id/channel/source/openid 等中性字段
 *
 * 两者最终都由 Marketing 侧监听器落 `reach_events`（event_type=live_code_scanned）触达账。
 * Channel 侧不直连 Marketing 模型，只发事件。
 */
class LiveCodeScanRecorded
{
    use Dispatchable;

    /**
     * @param  int  $tenantId  租户 ID
     * @param  mixed  $liveCodeId  活码主键
     * @param  array<string, mixed>  $scanData  扫码附加信息（message_id/user_id/channel/source/openid/occurred_at）
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly mixed $liveCodeId,
        public readonly array $scanData = [],
    ) {}
}
