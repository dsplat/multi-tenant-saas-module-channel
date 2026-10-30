<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Channel\Services\Write\Concerns;

use MultiTenantSaas\Authorization\AuthorizationRequest;
use MultiTenantSaas\Authorization\AuthorizedWriteContext;
use MultiTenantSaas\Authorization\CapabilityDefinition;
use MultiTenantSaas\Context\ExecutionIdentity;

/**
 * Channel 写适配器共用校验（身份 / 目标租户 / 幂等键 / 上下文复核）。
 *
 * 约定：
 * - 只服务本模块 `Services\Write` 下的适配器，方法全部 private；
 * - 复用方必须持有 `WriteOperationJournal $journal` 属性（failClaim 使用）。
 *
 * 身份口径与 course_update 试点一致：只有 operator（非匿名、数字主体、带租户）
 * 可以进入运营面写路径。
 */
trait GuardsTenantWrite
{
    /** ai_execution_audits.request_id 为 varchar(32)：operation key 必须可原样落库 */
    private const OPERATION_KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,31}$/';

    private function assertOperatorIdentity(ExecutionIdentity $identity): ExecutionIdentity
    {
        if ($identity->type !== ExecutionIdentity::TYPE_OPERATOR || $identity->isAnonymous()) {
            throw new \DomainException('channel_write_identity_invalid');
        }

        $subjectId = $identity->subjectId;

        if ($subjectId === null || $subjectId === '' || ! ctype_digit($subjectId) || (int) $subjectId <= 0) {
            throw new \DomainException('channel_write_identity_invalid');
        }

        if ($identity->tenantId === null || $identity->tenantId === '' || ! ctype_digit($identity->tenantId) || (int) $identity->tenantId <= 0) {
            throw new \DomainException('channel_write_tenant_missing');
        }

        return $identity;
    }

    private function targetTenantId(AuthorizationRequest $request): int
    {
        $tenantId = $request->target->kind === CapabilityDefinition::TARGET_TENANT
            ? $request->target->tenantId()
            : null;

        if ($tenantId === null || $tenantId <= 0) {
            throw new \DomainException('channel_write_target_invalid');
        }

        return $tenantId;
    }

    /**
     * 未提供 operation key 时由服务端生成；显式键用于跨重试去重。
     */
    private function operationKey(mixed $value, string $prefix): string
    {
        if ($value === null || $value === '') {
            return $prefix . '-' . bin2hex(random_bytes(12));
        }

        if (! is_string($value) || preg_match(self::OPERATION_KEY_PATTERN, $value) !== 1) {
            throw new \DomainException('channel_write_operation_key_invalid');
        }

        return $value;
    }

    /**
     * execute 前复核上下文（防伪造/篡改）：身份、资源类型、动作、字段、租户归属全部一致才放行。
     *
     * @param  array<int, string>  $allowedFields
     */
    private function contextTenantId(
        AuthorizedWriteContext $context,
        string $resourceType,
        string $action,
        array $allowedFields,
    ): ?int {
        if ($context->identity->type !== ExecutionIdentity::TYPE_OPERATOR
            || $context->resourceType !== $resourceType
            || $context->target->kind !== CapabilityDefinition::TARGET_TENANT) {
            return null;
        }

        if ($context->action !== $action) {
            return null;
        }

        if (array_diff(array_keys($context->payload), $allowedFields) !== []) {
            return null;
        }

        $tenantId = $context->target->tenantId();

        if ($tenantId === null || $tenantId <= 0) {
            return null;
        }

        if ($context->identity->tenantId !== (string) $tenantId) {
            return null;
        }

        if ((string) $tenantId !== (string) ($context->ownershipRules['tenant_id'] ?? '')) {
            return null;
        }

        return $tenantId;
    }

    private function failClaim(int $auditId, string $reason): void
    {
        try {
            $this->journal->fail($auditId, $reason);
        } catch (\Throwable) {
            // 账本回填失败不得掩盖业务结果：占位行保持 pending → 下次同键判 uncertain
        }
    }
}
