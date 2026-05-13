<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\SendComposedMessage;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Tenant\UserTenantRole;
use DaemsModule\Communications\Application\CreateMeetingFromComposer\CreateMeetingFromComposer;
use DaemsModule\Communications\Application\CreateMeetingFromComposer\Input as CreateMeetingInput;
use DaemsModule\Communications\Domain\Audience\AudienceResolverInterface;
use DaemsModule\Communications\Domain\Mail\Exception\SmtpNotConfigured;
use DaemsModule\Communications\Domain\Mail\MailKind;
use DaemsModule\Communications\Domain\Mail\MailOutbox;
use DaemsModule\Communications\Domain\Mail\MailOutboxId;
use DaemsModule\Communications\Domain\Mail\MailOutboxRepositoryInterface;
use DaemsModule\Communications\Domain\Mail\MailOutboxStatus;
use DaemsModule\Communications\Domain\Mail\MailSuppressionRepositoryInterface;
use DaemsModule\Communications\Domain\Meeting\MeetingId;
use DaemsModule\Communications\Domain\Preference\CommunicationCategory;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettingsRepositoryInterface;
use DaemsModule\Communications\Domain\Template\MailTemplateRepositoryInterface;
use DaemsModule\Communications\Infrastructure\Renderer\EmailHtmlRenderer;

/**
 * Sends an admin-composed batch by rendering one outbox row per recipient and
 * persisting them as `Queued` for the {@see DrainMailOutbox} cron to pick up.
 *
 * Wave D Task D5.
 *
 * Pipeline:
 *   1. Authorise (admin or moderator; Marketing kind needs Admin).
 *   2. If kind=MeetingInvitation and no `meeting_id` in payload → create
 *      the Meeting via {@see CreateMeetingFromComposer} first.
 *   3. Reject when SMTP is not configured for the tenant (early gate; the
 *      drain cron would still reject, but a 422 here lets the UI surface
 *      "go fix SMTP first" instead of "queued but invisible to recipient").
 *   4. Resolve audience — opt-in + per-user preference filter happens in the
 *      resolver itself for Operational + Marketing categories.
 *   5. Reject when the resolved audience is empty (otherwise admins click
 *      "Lähetä" expecting outbox rows and get nothing).
 *   6. For each recipient: skip if email is on the tenant's suppression list,
 *      otherwise render per-locale and persist a Queued outbox row.
 *
 * Returns `enqueuedCount` (NOT audience size — suppressed addresses are
 * silently skipped).
 */
final class SendComposedMessage
{
    public function __construct(
        private readonly AudienceResolverInterface $resolver,
        private readonly MailTemplateRepositoryInterface $templateRepo,
        private readonly TenantCommunicationSettingsRepositoryInterface $settingsRepo,
        private readonly MailOutboxRepositoryInterface $outboxRepo,
        private readonly MailSuppressionRepositoryInterface $suppressionRepo,
        private readonly EmailHtmlRenderer $renderer,
        private readonly CreateMeetingFromComposer $createMeeting,
        private readonly Clock $clock,
    ) {
    }

    public function execute(Input $input, ActingUser $acting): Output
    {
        // 1. Authorisation.
        if (!$this->isAuthorized($acting, $input)) {
            throw new ForbiddenException();
        }
        // Marketing kind (newsletter) requires admin — moderators may operate
        // GroupMessage but never Newsletter, per § "Roles" in the plan.
        if ($input->kind->category() === CommunicationCategory::Marketing
            && !$acting->isAdminIn($input->tenantId)
        ) {
            throw new ForbiddenException();
        }

        // 2. Create meeting first if needed.
        $meetingId = $this->extractMeetingId($input);
        if ($input->kind === MailKind::MeetingInvitation && $meetingId === null) {
            if ($input->meetingType === null || $input->meetingStartsAt === null) {
                throw new \DomainException(
                    'meeting_invitation requires meeting_id, OR meeting_type + meeting_starts_at to create one inline.',
                );
            }
            $meetingId = $this->createMeeting->execute(
                new CreateMeetingInput(
                    tenantId:            $input->tenantId,
                    type:                $input->meetingType,
                    titleByLocale:       $input->titleByLocale,
                    startsAt:            $input->meetingStartsAt,
                    location:            $input->meetingLocation,
                    remoteUrl:           $input->meetingRemoteUrl,
                    agendaItemsByLocale: $input->agendaItemsByLocale,
                    documentUrls:        $input->documentUrls,
                ),
                $acting,
            )->meetingId;
        }

        // 3. SMTP must be configured.
        $settings = $this->settingsRepo->findForTenant($input->tenantId);
        if (!$settings->isSmtpConfigured()) {
            throw new SmtpNotConfigured(
                'SMTP must be configured before sending. Visit /backstage/settings/communications.',
            );
        }

        // 4. Resolve audience.
        $recipients = $this->resolver->resolve(
            $input->tenantId,
            $input->audienceFilter,
            $input->kind->category(),
        );

        // 5. Empty audience guard.
        if ($recipients === []) {
            throw new \DomainException('Empty audience — no recipients match the selected filter.');
        }

        // 6. Render + persist per recipient.
        $invoiceId = $this->extractInvoiceId($input);
        $now       = $this->clock->now();
        $enqueued  = 0;
        foreach ($recipients as $r) {
            if ($this->suppressionRepo->isSuppressed($input->tenantId, $r->email)) {
                continue;
            }

            $vars = array_merge($input->payload, $r->contextVars, [
                'first_name'   => $r->firstName,
                'locale'       => $r->locale->value(),
                'tenant_name'  => $input->payload['tenant_name']
                    ?? ($settings->mailDisplayName ?? ''),
            ]);
            $vars['brand_primary_color'] = $vars['brand_primary_color']
                ?? ($settings->brandPrimaryColor ?? '');
            $vars['brand_logo_url']      = $vars['brand_logo_url']
                ?? ($settings->brandLogoUrl ?? '');
            $vars['brand_footer_address'] = $vars['brand_footer_address']
                ?? ($settings->brandFooterAddress ?? '');

            $overrides = $this->templateRepo->findOverrides(
                $input->tenantId,
                $input->kind,
                $r->locale,
            );
            $stringOverrides = $overrides !== null ? $overrides->stringOverrides : [];

            [$html, $text] = $this->renderer->render(
                $input->kind,
                $vars,
                $r->locale,
                $stringOverrides,
                $input->templateVariant,
            );

            $subjectRaw = $vars['subject'] ?? $stringOverrides['subject'] ?? '';
            $subject    = is_scalar($subjectRaw) ? (string) $subjectRaw : '';
            if (trim($subject) === '') {
                // Fall back to a minimal placeholder rather than throwing —
                // MailOutbox would reject an empty subject and abort the
                // entire batch mid-loop. The renderer guarantees a meaningful
                // body even when subject is empty.
                $subject = '(no subject)';
            }

            $row = new MailOutbox(
                id:                  MailOutboxId::generate(),
                tenantId:            $input->tenantId,
                kind:                $input->kind,
                category:            $input->kind->category(),
                recipientEmail:      $r->email,
                recipientUserId:     $r->userId,
                locale:              $r->locale,
                subject:             $subject,
                bodyHtml:            $html,
                bodyText:            $text,
                payloadVars:         $vars,
                payloadMeetingId:    $meetingId,
                payloadInvoiceId:    $invoiceId,
                payloadNewsletterId: null,
                status:              MailOutboxStatus::Queued,
                attemptCount:        0,
                lastError:           null,
                queuedAt:            $now,
                sentAt:              null,
                queuedBy:            $acting->id,
            );
            $this->outboxRepo->save($row);
            $enqueued++;
        }

        return new Output(enqueuedCount: $enqueued, meetingId: $meetingId);
    }

    private function isAuthorized(ActingUser $acting, Input $input): bool
    {
        if ($acting->isPlatformAdmin()) {
            return true;
        }
        $role = $acting->roleIn($input->tenantId);
        return $role === UserTenantRole::Admin || $role === UserTenantRole::Moderator;
    }

    private function extractMeetingId(Input $input): ?MeetingId
    {
        $raw = $input->payload['meeting_id'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        return MeetingId::fromString($raw);
    }

    private function extractInvoiceId(Input $input): ?MemberFeeInvoiceId
    {
        $raw = $input->payload['invoice_id'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        return MemberFeeInvoiceId::fromString($raw);
    }
}
