<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\SaveTemplateOverrides;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use DaemsModule\Communications\Domain\Mail\MailTemplateId;
use DaemsModule\Communications\Domain\Template\MailTemplate;
use DaemsModule\Communications\Domain\Template\MailTemplateRepositoryInterface;

/**
 * Wave D Task D8 — upsert admin-editable string overrides for one
 * (tenant, mail-kind, locale) triple.
 *
 * The whitelist of accepted keys is intentionally narrow:
 *   - subject     — overrides the localized subject header
 *   - intro_text  — top-of-email intro paragraph (Markdown allowed)
 *   - signature   — bottom-of-email closing line
 *   - footer      — small-print footer line (above the brand footer address)
 *
 * Any other key in `$overrides` triggers an InvalidArgumentException — we
 * don't silently drop unknown keys because the caller would have no way to
 * notice their input vanished. Empty-string values ARE accepted (= "set to
 * empty" rather than "fall back to default") so the admin can deliberately
 * blank a slot.
 *
 * Auth: tenant admin only (same as GetTemplateOverrides).
 */
final class SaveTemplateOverrides
{
    /** @var list<string> */
    public const ALLOWED_KEYS = ['subject', 'intro_text', 'signature', 'footer'];

    public function __construct(
        private readonly MailTemplateRepositoryInterface $repo,
    ) {
    }

    public function execute(Input $input, ActingUser $acting): Output
    {
        if (!$acting->isAdminIn($input->tenantId)) {
            throw new ForbiddenException();
        }

        // Validate keys: every key in $overrides MUST be in the whitelist.
        $extraKeys = array_diff(array_keys($input->overrides), self::ALLOWED_KEYS);
        if ($extraKeys !== []) {
            throw new \InvalidArgumentException(
                'Unknown template override key(s): ' . implode(', ', $extraKeys),
            );
        }

        // Re-read the existing row (if any) so we keep its synthetic id;
        // otherwise mint a fresh one. The SQL repo uses a composite PK
        // (tenant, kind, locale) under the hood so the synthetic id is
        // only used by domain code, but we keep it stable when round-tripping.
        $existing = $this->repo->findOverrides($input->tenantId, $input->kind, $input->locale);
        $id       = $existing !== null ? $existing->id : MailTemplateId::generate();

        $now = new \DateTimeImmutable();

        $template = new MailTemplate(
            id:              $id,
            tenantId:        $input->tenantId,
            kind:            $input->kind,
            locale:          $input->locale,
            stringOverrides: $input->overrides,
            updatedAt:       $now,
            updatedBy:       $acting->id,
        );

        $this->repo->saveOverrides($template);

        return new Output(success: true, updatedAt: $now);
    }
}
