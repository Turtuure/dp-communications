<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Infrastructure\Adapter\Api\Controller;

use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use DaemsModule\Communications\Application\CreateNewsletterDraft\CreateNewsletterDraft;
use DaemsModule\Communications\Application\CreateNewsletterDraft\Input as CreateInput;
use DaemsModule\Communications\Application\DeleteNewsletterDraft\DeleteNewsletterDraft;
use DaemsModule\Communications\Application\DeleteNewsletterDraft\Input as DeleteInput;
use DaemsModule\Communications\Application\ListNewsletters\Input as ListInput;
use DaemsModule\Communications\Application\ListNewsletters\ListNewsletters;
use DaemsModule\Communications\Application\SendNewsletter\Input as SendInput;
use DaemsModule\Communications\Application\SendNewsletter\SendNewsletter;
use DaemsModule\Communications\Application\UpdateNewsletterDraft\Input as UpdateInput;
use DaemsModule\Communications\Application\UpdateNewsletterDraft\UpdateNewsletterDraft;
use DaemsModule\Communications\Domain\Audience\AudienceFilter;
use DaemsModule\Communications\Domain\Audience\JoinedWithinPeriod;
use DaemsModule\Communications\Domain\Mail\Exception\SmtpNotConfigured;
use DaemsModule\Communications\Domain\Mail\NewsletterId;
use DaemsModule\Communications\Domain\Template\Block\BlockSerializer;
use DaemsModule\Communications\Domain\Template\Block\NewsletterBlock;
use DaemsModule\Communications\Domain\Template\NewsletterDraft;

/**
 * Backstage newsletters HTTP controller — Wave E Task E4.
 *
 * Routes (registered in modules/communications/backend/routes.php):
 *   GET    /api/v1/backstage/communications/newsletters             → index
 *   POST   /api/v1/backstage/communications/newsletters             → create
 *   PATCH  /api/v1/backstage/communications/newsletters/{id}        → update
 *   DELETE /api/v1/backstage/communications/newsletters/{id}        → destroy
 *   POST   /api/v1/backstage/communications/newsletters/{id}/send   → send
 *
 * Error mapping (override Kernel's default 409 for \DomainException so the UI
 * surfaces a "fix your input" toast):
 *   - SmtpNotConfigured  → 422 + smtp_not_configured
 *   - \DomainException   → 422 + newsletter_error
 *   - ForbiddenException → 403 (Kernel)
 *
 * Mirrors the {@see ComposerController} / {@see TemplatesController} pattern.
 */
final class NewslettersController
{
    public function __construct(
        private readonly ListNewsletters $listUseCase,
        private readonly CreateNewsletterDraft $createUseCase,
        private readonly UpdateNewsletterDraft $updateUseCase,
        private readonly DeleteNewsletterDraft $deleteUseCase,
        private readonly SendNewsletter $sendUseCase,
    ) {
    }

    public function index(Request $request): Response
    {
        $acting   = $request->requireActingUser();
        $tenantId = $this->resolveTenantId($request, $acting->activeTenant);

        $output = $this->listUseCase->execute(new ListInput($tenantId), $acting);

        return Response::json([
            'data' => array_map(
                fn(NewsletterDraft $d): array => $this->serialize($d),
                $output->newsletters,
            ),
        ]);
    }

    public function create(Request $request): Response
    {
        $acting   = $request->requireActingUser();
        $tenantId = $this->resolveTenantId($request, $acting->activeTenant);

        $internalName = $this->trimmedString($request->string('internal_name'));
        if ($internalName === null) {
            throw new \InvalidArgumentException('internal_name is required.');
        }

        try {
            $output = $this->createUseCase->execute(
                new CreateInput($tenantId, $internalName),
                $acting,
            );
        } catch (\DomainException $e) {
            return Response::json(['error' => 'newsletter_error', 'message' => $e->getMessage()], 422);
        }

        return Response::json(['data' => ['id' => $output->newsletterId->value()]], 201);
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        $acting   = $request->requireActingUser();
        $tenantId = $this->resolveTenantId($request, $acting->activeTenant);
        $id       = $this->parseNewsletterId($params['id'] ?? '');

        $internalName    = $this->trimmedString($request->string('internal_name'));
        $subjectByLocale = $this->parseLocaleStringMap($request->arrayValue('subject_i18n'));
        $blocksByLocale  = $this->parseBlocksByLocale($request->arrayValue('blocks_i18n'));
        $audience        = $this->resolveAudienceFilter($request);

        try {
            $output = $this->updateUseCase->execute(
                new UpdateInput(
                    tenantId:        $tenantId,
                    newsletterId:    $id,
                    internalName:    $internalName,
                    subjectByLocale: $subjectByLocale,
                    blocksByLocale:  $blocksByLocale,
                    audience:        $audience,
                ),
                $acting,
            );
        } catch (\DomainException $e) {
            return Response::json(['error' => 'newsletter_error', 'message' => $e->getMessage()], 422);
        }

        return Response::json(['data' => ['id' => $output->newsletterId->value()]]);
    }

    /** @param array<string, string> $params */
    public function destroy(Request $request, array $params): Response
    {
        $acting   = $request->requireActingUser();
        $tenantId = $this->resolveTenantId($request, $acting->activeTenant);
        $id       = $this->parseNewsletterId($params['id'] ?? '');

        try {
            $output = $this->deleteUseCase->execute(
                new DeleteInput($tenantId, $id),
                $acting,
            );
        } catch (\DomainException $e) {
            return Response::json(['error' => 'newsletter_error', 'message' => $e->getMessage()], 422);
        }

        return Response::json(['data' => ['deleted' => $output->deleted]]);
    }

    /** @param array<string, string> $params */
    public function send(Request $request, array $params): Response
    {
        $acting   = $request->requireActingUser();
        $tenantId = $this->resolveTenantId($request, $acting->activeTenant);
        $id       = $this->parseNewsletterId($params['id'] ?? '');

        try {
            $output = $this->sendUseCase->execute(
                new SendInput($tenantId, $id),
                $acting,
            );
        } catch (SmtpNotConfigured $e) {
            return Response::json(['error' => 'smtp_not_configured', 'message' => $e->getMessage()], 422);
        } catch (\DomainException $e) {
            return Response::json(['error' => 'newsletter_error', 'message' => $e->getMessage()], 422);
        }

        return Response::json(['data' => ['enqueued_count' => $output->enqueuedCount]]);
    }

    // ----- helpers ----------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function serialize(NewsletterDraft $d): array
    {
        // Surface block counts per locale so the list page can show a glance
        // at coverage without round-tripping the full block payload.
        $blocksCount = [];
        foreach ($d->blocksByLocale as $loc => $blocks) {
            $blocksCount[$loc] = count($blocks);
        }

        return [
            'id'              => $d->id->value(),
            'tenant_id'       => $d->tenantId->value(),
            'internal_name'   => $d->internalName,
            'subject_i18n'    => $d->subjectByLocale,
            'blocks_i18n'     => $this->encodeBlocksByLocale($d->blocksByLocale),
            'blocks_count'    => $blocksCount,
            'audience'        => $this->encodeAudience($d->audience),
            'status'          => $d->status->value,
            'sent_at'         => $d->sentAt?->format(\DateTimeInterface::ATOM),
            'created_at'      => $d->createdAt->format(\DateTimeInterface::ATOM),
            'created_by'      => $d->createdBy->value(),
        ];
    }

    /**
     * @param array<string, list<NewsletterBlock>> $blocksByLocale
     * @return array<string, list<array<string, mixed>>>
     */
    private function encodeBlocksByLocale(array $blocksByLocale): array
    {
        $out = [];
        foreach ($blocksByLocale as $locale => $blocks) {
            $arr = [];
            foreach ($blocks as $block) {
                $arr[] = BlockSerializer::toArray($block);
            }
            $out[$locale] = $arr;
        }
        return $out;
    }

    /**
     * @return array{membershipTypes: list<string>, locales: list<string>, joinedWithin: ?string, applicationStatuses: list<string>}
     */
    private function encodeAudience(AudienceFilter $a): array
    {
        return [
            'membershipTypes'     => $a->membershipTypes,
            'locales'             => $a->locales,
            'joinedWithin'        => $a->joinedWithin?->value,
            'applicationStatuses' => $a->applicationStatuses,
        ];
    }

    private function parseNewsletterId(string $raw): NewsletterId
    {
        if ($raw === '') {
            throw new \InvalidArgumentException('id is required.');
        }
        return NewsletterId::fromString($raw);
    }

    private function resolveTenantId(Request $request, TenantId $fallback): TenantId
    {
        $tenant = $request->attribute('tenant');
        if ($tenant instanceof Tenant) {
            return $tenant->id;
        }
        return $fallback;
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
     * @return array<string, list<NewsletterBlock>>
     */
    private function parseBlocksByLocale(?array $raw): array
    {
        if ($raw === null) {
            return [];
        }
        $out = [];
        foreach ($raw as $locale => $list) {
            if (!is_string($locale) || !is_array($list)) {
                continue;
            }
            $blocks = [];
            foreach ($list as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                /** @var array<string, mixed> $entry */
                $blocks[] = BlockSerializer::fromArray($entry);
            }
            $out[$locale] = $blocks;
        }
        return $out;
    }

    private function resolveAudienceFilter(Request $request): AudienceFilter
    {
        $raw = $request->arrayValue('audience') ?? [];

        $membershipTypes     = $this->parseStringList($raw['membershipTypes'] ?? $raw['membership_types'] ?? null);
        $locales             = $this->parseStringList($raw['locales'] ?? null);
        $applicationStatuses = $this->parseStringList($raw['applicationStatuses'] ?? $raw['application_statuses'] ?? null);

        $joinedWithin = null;
        $joinedRaw    = $raw['joinedWithin'] ?? $raw['joined_within'] ?? null;
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
