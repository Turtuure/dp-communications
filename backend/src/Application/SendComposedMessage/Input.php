<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\SendComposedMessage;

use Daems\Domain\Tenant\TenantId;
use DaemsModule\Communications\Domain\Audience\AudienceFilter;
use DaemsModule\Communications\Domain\Mail\MailKind;
use DaemsModule\Communications\Domain\Meeting\MeetingType;

final class Input
{
    /**
     * @param array<string, mixed>         $payload             admin-supplied vars + optional `meeting_id` / `invoice_id`
     * @param array<string, string>        $titleByLocale       only used when payload has no `meeting_id` AND kind is MeetingInvitation
     * @param array<string, list<string>>  $agendaItemsByLocale  see above
     * @param list<string>                 $documentUrls        see above
     */
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly MailKind $kind,
        public readonly array $payload,
        public readonly AudienceFilter $audienceFilter,
        public readonly ?string $templateVariant = null,
        // Optional meeting-creation block — only consulted when kind is
        // MeetingInvitation and the payload does NOT carry an existing meeting_id.
        public readonly ?MeetingType $meetingType = null,
        public readonly ?\DateTimeImmutable $meetingStartsAt = null,
        public readonly ?string $meetingLocation = null,
        public readonly ?string $meetingRemoteUrl = null,
        public readonly array $titleByLocale = [],
        public readonly array $agendaItemsByLocale = [],
        public readonly array $documentUrls = [],
    ) {
    }
}
