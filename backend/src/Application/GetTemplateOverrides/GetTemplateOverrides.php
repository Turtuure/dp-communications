<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\GetTemplateOverrides;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use DaemsModule\Communications\Domain\Template\MailTemplateRepositoryInterface;

/**
 * Wave D Task D8 — read the admin-editable template overrides for one
 * (tenant, mail-kind, locale) triple. Returns an empty `stringOverrides` map
 * if no row exists yet (caller treats that as "use defaults").
 *
 * Auth: tenant admin in the same tenant. GSAs trivially admin-in-tenant via
 * `ActingUser::isAdminIn()`. Non-admins → ForbiddenException (caller maps 403).
 */
final class GetTemplateOverrides
{
    public function __construct(
        private readonly MailTemplateRepositoryInterface $repo,
    ) {
    }

    public function execute(Input $input, ActingUser $acting): Output
    {
        if (!$acting->isAdminIn($input->tenantId)) {
            throw new ForbiddenException();
        }

        $template = $this->repo->findOverrides($input->tenantId, $input->kind, $input->locale);
        if ($template === null) {
            return new Output(
                stringOverrides: [],
                updatedAt:       null,
                updatedByUserId: null,
            );
        }

        return new Output(
            stringOverrides: $template->stringOverrides,
            updatedAt:       $template->updatedAt,
            updatedByUserId: $template->updatedBy->value(),
        );
    }
}
