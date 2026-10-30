<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 渠道模块建表（Channel / 框架侧标准件）
 *
 * 物理归位自 scrm `database/migrations/2025_01_03_000004_channel_module.php`，逐列照抄，
 * 迁移名沿用 scrm 既有 basename（存量库 migrations 表已有同名记录自动跳过）。
 *
 * 表：
 * - channels            渠道账号（企微自建 / 公众号 / Telegram）
 * - live_codes          活码（渠道码 / 门店码 / 员工码，软删）
 * - lock_code_bindings  锁客绑定（客户 ↔ 员工 ↔ 活码，软删）
 * - welcome_messages    各渠道欢迎语（软删）
 * - scan_events         扫码事件明细（活码趋势 / 排名的真数据源）
 *
 * 幂等：先 Schema::hasTable 再 Schema::create —— 框架全新安装建表；scrm 存量库该表
 * 已存在则**跳过**（不重复建、不报错），hasTable 守卫是「生产表已存在不重建」的第二道保险。
 *
 * **未迁入**：scrm 同迁移里的 `channel_accounts`（无模型、零消费者，死表）。
 * **不建外键**：框架模块表以 tenant_id / user_id 裸列关联，不建 FK（可移植 + 支持跨包裁剪安装）。
 *
 * 注：`scan_events` 保留 scrm 的 AUTO_INCREMENT 主键 —— ScanStatsService 走原生
 * `DB::table('scan_events')->insert()` 不提供主键，取值依赖自增；它是高频追加型事件明细表，
 * 非业务实体（无模型、无全局 ID 语义），故不适用 IdGenerator 全局 ID 方案。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('channels')) {
            Schema::create('channels', function (Blueprint $table) {
                $table->unsignedBigInteger('channel_id')->primary()->comment('IdGenerator 全局ID');
                $table->unsignedBigInteger('tenant_id');
                $table->string('type', 50)->comment('wechat_official|wechat_work|telegram');
                $table->string('name', 100);
                $table->string('app_id', 100)->nullable();
                $table->text('app_secret')->nullable();
                $table->string('agent_id', 100)->nullable();
                $table->string('callback_token', 255)->nullable();
                $table->string('encoding_aes_key', 255)->nullable();
                $table->string('status', 20)->default('disconnected')->comment('connected|disconnected|error');
                $table->json('metadata')->nullable();
                $table->timestamp('last_connected_at')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'type']);
                $table->index(['tenant_id', 'status']);
                $table->index('tenant_id');
            });
        }

        if (! Schema::hasTable('live_codes')) {
            Schema::create('live_codes', function (Blueprint $table) {
                $table->unsignedBigInteger('live_code_id')->primary()->comment('IdGenerator 全局ID');
                $table->unsignedBigInteger('tenant_id');
                $table->string('type', 50);
                $table->string('code', 200)->unique();
                $table->string('channel', 100)->nullable();
                $table->string('target_url', 500)->nullable();
                $table->timestamp('expire_at')->nullable();
                $table->string('status', 20)->default('active');
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index('tenant_id');
                $table->index('type');
                $table->index('channel');
                $table->index('status');
                $table->index('expire_at');
                $table->index(['tenant_id', 'type', 'status']);
            });
        }

        if (! Schema::hasTable('lock_code_bindings')) {
            Schema::create('lock_code_bindings', function (Blueprint $table) {
                $table->unsignedBigInteger('binding_id')->primary()->comment('IdGenerator 全局ID');
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('lock_code_id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('staff_id');
                $table->timestamp('locked_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'lock_code_id']);
                $table->index(['tenant_id', 'user_id']);
                $table->index(['tenant_id', 'staff_id']);
                $table->index('user_id');
            });
        }

        if (! Schema::hasTable('welcome_messages')) {
            Schema::create('welcome_messages', function (Blueprint $table) {
                $table->unsignedBigInteger('message_id')->primary()->comment('IdGenerator 全局ID');
                $table->unsignedBigInteger('tenant_id');
                $table->string('name', 200);
                $table->string('channel', 50);
                $table->text('content');
                $table->json('materials')->nullable();
                $table->string('status', 20)->default('active');
                $table->timestamps();
                $table->softDeletes();

                $table->index('tenant_id');
            });
        }

        if (! Schema::hasTable('scan_events')) {
            Schema::create('scan_events', function (Blueprint $table) {
                $table->bigIncrements('scan_event_id');
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('live_code_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('channel_source', 50)->default('unknown');
                $table->string('region', 100)->nullable();
                $table->timestamp('scanned_at')->useCurrent();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'live_code_id']);
                $table->index(['live_code_id', 'scanned_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_events');
        Schema::dropIfExists('welcome_messages');
        Schema::dropIfExists('lock_code_bindings');
        Schema::dropIfExists('live_codes');
        Schema::dropIfExists('channels');
    }
};
