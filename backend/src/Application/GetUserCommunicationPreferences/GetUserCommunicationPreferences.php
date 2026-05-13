<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\GetUserCommunicationPreferences;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use DaemsModule\Communications\Domain\Preference\UserCommunicationPreferenceRepositoryInterface;

final class GetUserCommunicationPreferences
{
    public function __construct(
        private readonly UserCommunicationPreferenceRepositoryInterface $repo,
    ) {
    }

    public function execute(Input $input, ActingUser $acting): Output
    {
        // Authorisation: the user themselves (reading own prefs) OR a tenant admin.
        $isSelf = $acting->id->equals($input->userId);
        if (!$isSelf && !$acting->isAdminIn($input->tenantId)) {
            throw new ForbiddenException();
        }

        return new Output(
            preferences: $this->repo->findFor($input->userId, $input->tenantId),
        );
    }
}
