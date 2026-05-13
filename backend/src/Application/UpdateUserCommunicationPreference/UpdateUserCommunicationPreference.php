<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\UpdateUserCommunicationPreference;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use DaemsModule\Communications\Domain\Preference\UserCommunicationPreferenceRepositoryInterface;

final class UpdateUserCommunicationPreference
{
    public function __construct(
        private readonly UserCommunicationPreferenceRepositoryInterface $repo,
    ) {
    }

    public function execute(Input $input, ActingUser $acting): Output
    {
        // Transactional preferences are immutable — even admins cannot disable them.
        // The DB has a CHECK constraint as defence in depth, but the use case
        // refuses the request up-front so the UI can show a clean message.
        if ($input->category->isImmutable()) {
            throw new ForbiddenException(
                sprintf(
                    'Cannot mutate communication category "%s": it is required for service operation.',
                    $input->category->value,
                ),
            );
        }

        // Self-edit always allowed; otherwise caller must be admin in target tenant.
        $isSelf = $acting->id->equals($input->userId);
        if (!$isSelf && !$acting->isAdminIn($input->tenantId)) {
            throw new ForbiddenException();
        }

        $this->repo->setFor(
            $input->userId,
            $input->tenantId,
            $input->category,
            $input->optedIn,
        );

        return new Output(success: true);
    }
}
