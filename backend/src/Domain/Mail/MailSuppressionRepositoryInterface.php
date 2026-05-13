<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Mail;

use Daems\Domain\Tenant\TenantId;

interface MailSuppressionRepositoryInterface
{
    public function isSuppressed(TenantId $tenantId, string $email): bool;

    public function add(MailSuppression $suppression): void;

    public function remove(TenantId $tenantId, string $email): void;

    /**
     * @return list<MailSuppression>
     */
    public function listForTenant(TenantId $tenantId): array;
}
