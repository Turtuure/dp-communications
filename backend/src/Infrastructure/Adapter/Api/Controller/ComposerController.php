<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Infrastructure\Adapter\Api\Controller;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use DaemsModule\Communications\Application\ComposeAndPreviewMessage\ComposeAndPreviewMessage;
use DaemsModule\Communications\Application\ComposeAndPreviewMessage\Input as PreviewInput;
use DaemsModule\Communications\Application\CreateMeetingFromComposer\CreateMeetingFromComposer;
use DaemsModule\Communications\Application\CreateMeetingFromComposer\Input as MeetingInput;
use DaemsModule\Communications\Application\SendComposedMessage\Input as SendInput;
use DaemsModule\Communications\Application\SendComposedMessage\SendComposedMessage;
use DaemsModule\Communications\Domain\Audience\AudienceFilter;
use DaemsModule\Communications\Domain\Audience\JoinedWithinPeriod;
use DaemsModule\Communications\Domain\Mail\Exception\SmtpNotConfigured;
use DaemsModule\Communications\Domain\Mail\MailKind;
use DaemsModule\Communications\Domain\Meeting\MeetingType;

/**
 * Backstage composer HTTP controller — Wave D Task D6.
 *
 * Routes (registered in modules/communications/backend/routes.php):
 *   POST /api/v1/backstage/communications/preview         → preview
 *   POST /api/v1/backstage/communications/send            → send
 *   POST /api/v1/backstage/meetings/from-composer         → createMeeting
 *
 * Error mapping:
 *   - SmtpNotConfigured  (RuntimeException, NOT auto-mapped) → 422 + smtp_not_configured
 *   - \DomainException (e.g. "Empty audience")               → 422 + composer_error
 *     (we override the Kernel's 409 default for these so the UI surfaces a
 *     "fix your input" toast rather than a "conflict" one — both signal the
 *     same intent but 422 is the conventional code for unprocessable form
 *     payloads.)
 *   - ForbiddenException etc. → handled by Kernel as usual.
 */
final class ComposerController
{
    public function __construct(
        private readonly ComposeAndPreviewMessage $previewUseCase,
        private readonly SendComposedMessage $sendUseCase,
        private readonly CreateMeetingFromComposer $createMeetingUseCase,
    ) {
    }

    public function preview(Request $request): Response
    {
        $acting   = $request->requireActingUser();
        $tenantId = $this->resolveTenantId($request, $acting->activeTenant);

        $kind   = $this->resolveKind($request);
        $locale = $this->resolveLocale($request);
        $filter = $this->resolveAudienceFilter($request);

        $payload = $request->arrayValue('payload') ?? [];

        $templateVariant = $this->trimmedString($request->string('template_variant'));

        try {
            $output = $this->previewUseCase->execute(
                new PreviewInput(
                    tenantId:        $tenantId,
                    kind:            $kind,
                    payload:         $payload,
                    audienceFilter:  $filter,
                    locale:          $locale,
                    templateVariant: $templateVariant,
                ),
                $acting,
            );
        } catch (\DomainException $e) {
            return Response::json(['error' => 'composer_error', 'message' => $e->getMessage()], 422);
        }

        return Response::json(['data' => [
            'html_preview'           => $output->htmlPreview,
            'text_preview'           => $output->textPreview,
            'audience_count'         => $output->audienceCount,
            'audience_sample_names'  => $output->audienceSampleNames,
            'preview_locale'         => $output->previewLocale,
            'resolved_vars'          => $output->resolvedVars,
        ]]);
    }

    public function send(Request $request): Response
    {
        $acting   = $request->requireActingUser();
        $tenantId = $this->resolveTenantId($request, $acting->activeTenant);

        $kind   = $this->resolveKind($request);
        $filter = $this->resolveAudienceFilter($request);

        $payload = $request->arrayValue('payload') ?? [];

        $templateVariant = $this->trimmedString($request->string('template_variant'));

        $meetingType = $this->parseMeetingType($request->string('meeting_type'));
        $startsAt    = $this->parseDateTime($request->string('meeting_starts_at'));
        $location    = $this->trimmedString($request->string('meeting_location'));
        $remoteUrl   = $this->trimmedString($request->string('meeting_remote_url'));

        $titleByLocale       = $this->parseLocaleStringMap($request->arrayValue('title_by_locale'));
        $agendaItemsByLocale = $this->parseLocaleListMap($request->arrayValue('agenda_items_by_locale'));
        $documentUrls        = $this->parseStringList($request->arrayValue('document_urls'));

        try {
            $output = $this->sendUseCase->execute(
                new SendInput(
                    tenantId:            $tenantId,
                    kind:                $kind,
                    payload:             $payload,
                    audienceFilter:      $filter,
                    templateVariant:     $templateVariant,
                    meetingType:         $meetingType,
                    meetingStartsAt:     $startsAt,
                    meetingLocation:     $location,
                    meetingRemoteUrl:    $remoteUrl,
                    titleByLocale:       $titleByLocale,
                    agendaItemsByLocale: $agendaItemsByLocale,
                    documentUrls:        $documentUrls,
                ),
                $acting,
            );
        } catch (SmtpNotConfigured $e) {
            return Response::json(['error' => 'smtp_not_configured', 'message' => $e->getMessage()], 422);
        } catch (\DomainException $e) {
            return Response::json(['error' => 'composer_error', 'message' => $e->getMessage()], 422);
        }

        return Response::json(['data' => [
            'enqueued_count' => $output->enqueuedCount,
            'meeting_id'     => $output->meetingId?->value(),
        ]]);
    }

    public function createMeeting(Request $request): Response
    {
        $acting   = $request->requireActingUser();
        $tenantId = $this->resolveTenantId($request, $acting->activeTenant);

        $type     = $this->parseMeetingType($request->string('meeting_type'));
        $startsAt = $this->parseDateTime($request->string('meeting_starts_at'));
        if ($type === null || $startsAt === null) {
            throw new \InvalidArgumentException('meeting_type and meeting_starts_at are required.');
        }
        $location  = $this->trimmedString($request->string('meeting_location'));
        $remoteUrl = $this->trimmedString($request->string('meeting_remote_url'));

        $titleByLocale       = $this->parseLocaleStringMap($request->arrayValue('title_by_locale'));
        $agendaItemsByLocale = $this->parseLocaleListMap($request->arrayValue('agenda_items_by_locale'));
        $documentUrls        = $this->parseStringList($request->arrayValue('document_urls'));

        if ($titleByLocale === []) {
            throw new \InvalidArgumentException('title_by_locale must contain at least one locale entry.');
        }

        $output = $this->createMeetingUseCase->execute(
            new MeetingInput(
                tenantId:            $tenantId,
                type:                $type,
                titleByLocale:       $titleByLocale,
                startsAt:            $startsAt,
                location:            $location,
                remoteUrl:           $remoteUrl,
                agendaItemsByLocale: $agendaItemsByLocale,
                documentUrls:        $documentUrls,
            ),
            $acting,
        );

        return Response::json(['data' => ['meeting_id' => $output->meetingId->value()]]);
    }

    // ----- helpers -----------------------------------------------------------

    private function resolveTenantId(Request $request, TenantId $fallback): TenantId
    {
        $tenant = $request->attribute('tenant');
        if ($tenant instanceof Tenant) {
            return $tenant->id;
        }
        return $fallback;
    }

    private function resolveKind(Request $request): MailKind
    {
        $raw  = $request->string('kind');
        if ($raw === null || $raw === '') {
            throw new \InvalidArgumentException('kind is required.');
        }
        $kind = MailKind::tryFrom($raw);
        if ($kind === null) {
            throw new \InvalidArgumentException("Unknown mail kind: {$raw}");
        }
        return $kind;
    }

    private function resolveLocale(Request $request): SupportedLocale
    {
        $raw = $request->string('locale');
        if ($raw === null || $raw === '') {
            return SupportedLocale::contentFallback();
        }
        return SupportedLocale::fromString($raw);
    }

    private function resolveAudienceFilter(Request $request): AudienceFilter
    {
        $raw = $request->arrayValue('audience_filter') ?? [];

        $membershipTypes     = $this->parseStringList($raw['membership_types'] ?? null);
        $locales             = $this->parseStringList($raw['locales'] ?? null);
        $applicationStatuses = $this->parseStringList($raw['application_statuses'] ?? null);

        $joinedWithin = null;
        $joinedRaw    = $raw['joined_within'] ?? null;
        if (is_string($joinedRaw) && $joinedRaw !== '') {
            $joinedWithin = JoinedWithinPeriod::tryFrom($joinedRaw);
        }

        return new AudienceFilter(
            membershipTypes:     $membershipTypes,
            locales:             $locales,
            joinedWithin:        $joinedWithin,
            applicationStatuses: $applicationStatuses,
        );
    }

    private function parseMeetingType(?string $raw): ?MeetingType
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        return MeetingType::tryFrom($raw)
            ?? throw new \InvalidArgumentException("Unknown meeting type: {$raw}");
    }

    private function parseDateTime(?string $raw): ?\DateTimeImmutable
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            throw new \InvalidArgumentException('Invalid datetime: ' . $raw);
        }
    }

    /**
     * @param array<array-key, mixed>|null $raw
     * @return array<string, string>
     */
    private function parseLocaleStringMap(?array $raw): array
    {
        if ($raw === null) {
            return [];
        }
        $out = [];
        foreach ($raw as $locale => $value) {
            if (!is_string($locale) || !is_string($value)) {
                continue;
            }
            $out[$locale] = $value;
        }
        return $out;
    }

    /**
     * @param array<array-key, mixed>|null $raw
     * @return array<string, list<string>>
     */
    private function parseLocaleListMap(?array $raw): array
    {
        if ($raw === null) {
            return [];
        }
        $out = [];
        foreach ($raw as $locale => $list) {
            if (!is_string($locale) || !is_array($list)) {
                continue;
            }
            $out[$locale] = $this->parseStringList($list);
        }
        return $out;
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private function parseStringList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $v) {
            if (is_string($v) && $v !== '') {
                $out[] = $v;
            }
        }
        return $out;
    }

    private function trimmedString(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $t = trim($v);
        return $t === '' ? null : $t;
    }
}
