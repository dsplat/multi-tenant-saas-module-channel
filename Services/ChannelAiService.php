<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Channel\Services;

use MultiTenantSaas\Contracts\AiTextServiceContract;
use MultiTenantSaas\Modules\Ai\DTOs\AiResult;
use MultiTenantSaas\Modules\Ai\Services\AiOptional;

/**
 * 渠道模块 AI 增强服务
 *
 * AI 瘫痪时：框架 Channel 管道正常入库，Listener 降级为仅记录日志。
 */
class ChannelAiService
{
    public function __construct(
        private readonly AiOptional $aiOptional,
    ) {}

    /**
     * 智能客服自动回复
     *
     * @return AiResult output: ['reply'=>string, 'confidence'=>float, 'should_escalate'=>bool]
     */
    public function autoReply(int $tenantId, string $message, array $context = []): AiResult
    {
        $fallback = ['reply' => '', 'confidence' => 0.0, 'should_escalate' => true];

        return $this->aiOptional->invoke(
            category: 'channel.auto_reply',
            fallback: $fallback,
            aiCall: function () use ($message, $context) {
                $ai = app(AiTextServiceContract::class);
                $ctxStr = $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : '无';
                $prompt = "你是智能客服。回答用户问题，不确定时设 should_escalate=true 转人工。\n"
                    . "上下文：{$ctxStr}\n用户消息：{$message}\n\n"
                    . '以 JSON 返回：{"reply":"回答","confidence":0.8,"should_escalate":false}';
                $response = $ai->complete($prompt, ['temperature' => 0.4, 'response_format' => ['type' => 'json_object']]);

                return json_decode($response->content ?? '{}', true) ?: [];
            },
            options: ['timeout_ms' => 5000, 'confidence_threshold' => 0.6],
        );
    }

    /**
     * 欢迎语/菜单文案生成
     *
     * @return AiResult output: ['welcome'=>string, 'menu_items'=>array]
     */
    public function copyGen(int $tenantId, string $scenario, string $brandTone = ''): AiResult
    {
        $fallback = ['welcome' => '欢迎关注！', 'menu_items' => []];

        return $this->aiOptional->invoke(
            category: 'channel.copy_gen',
            fallback: $fallback,
            aiCall: function () use ($scenario, $brandTone) {
                $ai = app(AiTextServiceContract::class);
                $prompt = "为以下场景生成欢迎语和菜单文案：\n场景：{$scenario}\n"
                    . ($brandTone ? "品牌调性：{$brandTone}\n" : '')
                    . '以 JSON 返回：{"welcome":"欢迎语","menu_items":["菜单项1","菜单项2"]}';
                $response = $ai->complete($prompt, ['temperature' => 0.7, 'response_format' => ['type' => 'json_object']]);

                return json_decode($response->content ?? '{}', true) ?: [];
            },
            options: ['timeout_ms' => 8000],
        );
    }

    /**
     * 消息意图识别路由
     *
     * @return AiResult output: ['intent'=>string, 'route_to'=>string, 'confidence'=>float]
     */
    public function intentRoute(int $tenantId, string $message): AiResult
    {
        $fallback = ['intent' => 'unknown', 'route_to' => 'default', 'confidence' => 0.0];

        return $this->aiOptional->invoke(
            category: 'channel.intent_route',
            fallback: $fallback,
            aiCall: function () use ($message) {
                $ai = app(AiTextServiceContract::class);
                $prompt = "识别以下消息的意图并路由：\n消息：{$message}\n\n"
                    . "意图类别：consult(咨询)/complaint(投诉)/purchase(购买)/after_sale(售后)/other\n"
                    . '以 JSON 返回：{"intent":"consult","route_to":"客服组","confidence":0.9}';
                $response = $ai->complete($prompt, ['temperature' => 0.2, 'response_format' => ['type' => 'json_object']]);

                return json_decode($response->content ?? '{}', true) ?: [];
            },
            options: ['timeout_ms' => 4000, 'confidence_threshold' => 0.5],
        );
    }
}
