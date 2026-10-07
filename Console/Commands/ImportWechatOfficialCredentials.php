<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use MultiTenantSaas\Modules\Channel\Models\Channel;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * 公众号凭证导入：channels(wechat_official) → tenant_settings(oauth)
 *
 * 背景（框架 docs/wechat-carrier-model.md 第八章）：微信上移框架做基础设施后，
 * 公众号凭证的唯一写入点是框架 console（tenant_settings group=oauth），channels
 * 表的 wechat_official 类型将在 P4 废除。本命令做过渡期的一次性存量导入：把
 * 项目层 channels 里已填的公众号 app_id/app_secret 搬进框架凭证槽，使登录 /
 * 消息 / 菜单链路立即读到同一份凭证。
 *
 * 映射：
 *   channels.app_id     → oauth.wechat_client_id
 *   channels.app_secret → oauth.wechat_client_secret（加密存储）
 *
 * 安全策略：
 * - 幂等：目标槽已等于该 app_id → 跳过；
 * - 不覆盖：目标槽已有不同的 wechat_client_id → 跳过并告警（除非 --force）；
 * - 一租户多公众号渠道：优先 status=connected，其次最近连接/创建的一条。
 *
 * 用法：
 *   php artisan channel:import-wechat-official              # 全部租户
 *   php artisan channel:import-wechat-official --tenant=123 # 仅指定租户
 *   php artisan channel:import-wechat-official --dry-run    # 仅预览，不写库
 *   php artisan channel:import-wechat-official --force      # 覆盖已存在的不同凭证
 */
class ImportWechatOfficialCredentials extends Command
{
    protected $signature = 'channel:import-wechat-official
                          {--tenant= : 仅处理指定租户（tenant_id）}
                          {--dry-run : 仅列出待导入凭证，不实际写库}
                          {--force : 目标槽已有不同凭证时强制覆盖}';

    protected $description = '将 channels(wechat_official) 公众号凭证导入框架 tenant_settings(oauth)';

    private const GROUP = 'oauth';

    private const KEY_OFFICIAL_APPID = 'wechat_client_id';

    private const KEY_OFFICIAL_SECRET = 'wechat_client_secret';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $query = Channel::withoutGlobalScope(TenantScope::class)->where('type', 'wechat_official');

        $tenantOption = $this->option('tenant');
        if ($tenantOption !== null) {
            $query->where('tenant_id', (int) $tenantOption);
        }

        $grouped = $query->get()->groupBy('tenant_id');

        if ($grouped->isEmpty()) {
            $this->info('没有 wechat_official 渠道，无需导入。');

            return self::SUCCESS;
        }

        $imported = 0;
        $skipped = 0;

        foreach ($grouped as $tenantId => $channels) {
            $tenantId = (int) $tenantId;
            $channel = $this->pickPrimary($channels);

            $appId = (string) ($channel->app_id ?? '');
            $appSecret = (string) $channel->app_secret;

            if ($appId === '' || $appSecret === '') {
                $this->warn("  [租户 {$tenantId}] 渠道 {$channel->channel_id} 凭证不完整，跳过。");
                $skipped++;

                continue;
            }

            $existing = (string) TenantSetting::get($tenantId, self::GROUP, self::KEY_OFFICIAL_APPID, '');

            if ($existing === $appId) {
                $this->line("  [租户 {$tenantId}] 已导入（wechat_client_id={$appId}），跳过。");
                $skipped++;

                continue;
            }

            if ($existing !== '' && ! $force) {
                $this->warn("  [租户 {$tenantId}] oauth 槽已有不同凭证（{$existing}），与渠道 {$appId} 冲突，跳过（加 --force 覆盖）。");
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line("  [租户 {$tenantId}] 待导入：{$appId} → oauth.wechat_client_id" . ($existing !== '' ? '（覆盖 ' . $existing . '）' : ''));
                $imported++;

                continue;
            }

            TenantSetting::set($tenantId, self::GROUP, self::KEY_OFFICIAL_APPID, $appId);
            TenantSetting::set(
                $tenantId,
                self::GROUP,
                self::KEY_OFFICIAL_SECRET,
                $appSecret,
                true,
                '公众号凭证（channel:import-wechat-official 从 channels 导入）'
            );

            $this->line("  [租户 {$tenantId}] 已导入公众号凭证 {$appId} → oauth.wechat_client_id");
            $imported++;
        }

        $verb = $dryRun ? '待导入' : '已导入';
        $this->info("{$verb} {$imported} 个租户，跳过 {$skipped} 个。");

        return self::SUCCESS;
    }

    /**
     * 一租户多条公众号渠道时选主：connected 优先，其次最近连接/创建
     *
     * @param  Collection<int, Channel>  $channels
     */
    private function pickPrimary($channels): Channel
    {
        return $channels
            ->sortByDesc(fn (Channel $c) => [
                $c->status === 'connected' ? 1 : 0,
                $c->last_connected_at?->timestamp ?? $c->created_at?->timestamp ?? 0,
            ])
            ->first();
    }
}
