<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\MarkSuppressedRecipientsInPending;

/**
 * No input parameters — the use case scans all `queued` rows across all
 * tenants. Each row carries its own `TenantId`, and suppression is checked
 * against that tenant's list. Reserved for future filter expansion.
 */
final class Input
{
}
