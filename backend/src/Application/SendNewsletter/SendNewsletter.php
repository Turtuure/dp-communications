<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\SendNewsletter;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Shared\Clock;
use DaemsModule\Communications\Domain\Audience\AudienceResolverInterface;
use DaemsModule\Communications\Domain\Mail\Exception\SmtpNotConfigured;
use DaemsModule\Communications\Domain\Mail\MailKind;
use DaemsModule\Communications\Domain\Mail\MailOutbox;
use DaemsModule\Communications\Domain\Mail\MailOutboxId;
use DaemsModule\Communications\Domain\Mail\MailOutboxRepositoryInterface;
use DaemsModule\Communications\Domain\Mail\MailOutboxStatus;
use DaemsModule\Communications\Domain\Mail\MailSuppressionRepositoryInterface;
use DaemsModule\Communications\Domain\Preference\CommunicationCategory;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettingsRepositoryInterface;
use DaemsModule\Communications\Domain\Template\NewsletterDraft;
use DaemsModule\Communications\Domain\Template\NewsletterDraftRepositoryInterface;
use DaemsModule\Communications\Domain\Template\NewsletterStatus;
use DaemsModule\Communications\Infrastructure\Renderer\EmailHtmlRenderer;
use DaemsModule\Communications\Infrastructure\Renderer\NewsletterBlockRenderer;
use DaemsModule\Communications\Infrastructure\Renderer\MarkdownRenderer;

/**
 * Sends a newsletter draft by enqueueing one {@see MailOutbox} row per resolved
 * recipient. Validates locale parity (every supported locale must carry a
 * subject + at least one block, OR a populated `en_GB` fallback) and audience
 * non-empty before any outbox row is written.
 *
 * Pipeline:
 *
 *   1. Authorise (Admin only — newsletters are marketing-category content).
 *   2. Load the draft, reject cross-tenant access and already-Sent drafts.
 *   3. Per-locale parity check (subject + ≥1 block, or fallback rule).
 *   4. SMTP must be configured for the tenant (early gate; the drain cron
 *      would still reject otherwise — surfacing a 422 here lets the UI say
 *      "go fix SMTP first" instead of "queued but invisible").
 *   5. Resolve audience with Marketing category (opt-in required).
 *   6. Reject empty audience.
 *   7. For each recipient: skip if suppressed, otherwise render the wrapper
 *      template via {@see EmailHtmlRenderer} with `{{block_body}}` filled by
 *      {@see NewsletterBlockRenderer} for the recipient's locale (with fallback
 *      to en_GB content when their locale is empty).
 *   8. Flip the draft to `Sent` + record sentAt.
 *
 * Wave G Task G3 will swap the placeholder `unsubscribe_url` for a real
 * HMAC-signed token — until then the URL is a stable placeholder.
 *
 * Wave E Task E3 (Milestone 0.8 / communications-v1).
 */
final class SendNewsletter
{
    private const FALLBACK_LOCALE = 'en_GB';

    public function __construct(
        private readonly NewsletterDraftRepositoryInterface $repo,
        private readonly AudienceResolverInterface $resolver,
        private readonly TenantCommunicationSettingsRepositoryInterface $settingsRepo,
        private readonly MailOutboxRepositoryInterface $outboxRepo,
        private readonly MailSuppressionRepositoryInterface $suppressionRepo,
        private readonly EmailHtmlRenderer $renderer,
        private readonly MarkdownRenderer $markdown,
        private readonly Clock $clock,
    ) {
    }

    public function execute(Input $input, ActingUser $acting): Output
    {
        // 1. Auth — admin only (marketing category).
        if (!$acting->isAdminIn($input->tenantId)) {
            throw new ForbiddenException();
        }

        // 2. Load draft.
        $draft = $this->repo->findById($input->newsletterId);
        if ($draft === null) {
            throw new \DomainException('Newsletter not found');
        }
        if (!$draft->tenantId->equals($input->tenantId)) {
            throw new ForbiddenException();
        }
        if ($draft->status === NewsletterStatus::Sent) {
            throw new \DomainException('Newsletter already sent');
        }

        // 3. Locale parity — each supported locale must have subject + ≥1 block,
        // OR en_GB fallback content must exist.
        $this->validateLocaleParity($draft);

        // 4. SMTP configured.
        $settings = $this->settingsRepo->findForTenant($input->tenantId);
        if (!$settings->isSmtpConfigured()) {
            throw new SmtpNotConfigured(
                'SMTP must be configured before sending. Visit /backstage/settings/communications.',
            );
        }

        // 5. Audience — marketing category (opt-in filtering happens inside the resolver).
        $recipients = $this->resolver->resolve(
            $input->tenantId,
            $draft->audience,
            CommunicationCategory::Marketing,
        );

        // 6. Empty audience.
        if ($recipients === []) {
            throw new \DomainException('Empty audience — no recipients match the newsletter filter.');
        }

        // 7. Render + persist per recipient.
        $brandPrimaryColor = $settings->brandPrimaryColor ?? '#2e5c8a';
        $blockRenderer     = new NewsletterBlockRenderer($this->markdown, $brandPrimaryColor);
        $now               = $this->clock->now();
        $enqueued          = 0;

        foreach ($recipients as $r) {
            if ($this->suppressionRepo->isSuppressed($input->tenantId, $r->email)) {
                continue;
            }

            $localeCode = $r->locale->value();
            $subject    = $this->resolveSubject($draft, $localeCode);
            $blocks     = $this->resolveBlocks($draft, $localeCode);
            $blockHtml  = $blockRenderer->renderAll($blocks);

            // Wave G G3 will replace with HMAC-signed URL keyed on recipient id.
            $unsubUrl = 'https://placeholder.local/unsubscribe?token=PLACEHOLDER';

            $vars = [
                'first_name'           => $r->firstName,
                'subject'              => $subject,
                'block_body'           => $blockHtml,
                'unsubscribe_url'      => $unsubUrl,
                'tenant_name'          => $settings->mailDisplayName ?? '',
                'brand_primary_color'  => $brandPrimaryColor,
                'brand_logo_url'       => $settings->brandLogoUrl ?? '',
                'brand_footer_address' => $settings->brandFooterAddress ?? '',
                'locale'               => $localeCode,
            ];

            [$html, $text] = $this->renderer->render(
                MailKind::Newsletter,
                $vars,
                $r->locale,
                [],
            );

            $this->outboxRepo->save(new MailOutbox(
                id:                  MailOutboxId::generate(),
                tenantId:            $input->tenantId,
                kind:                MailKind::Newsletter,
                category:            CommunicationCategory::Marketing,
                recipientEmail:      $r->email,
                recipientUserId:     $r->userId,
                locale:              $r->locale,
                subject:             $subject,
                bodyHtml:            $html,
                bodyText:            $text,
                payloadVars:         $vars,
                payloadMeetingId:    null,
                payloadInvoiceId:    null,
                payloadNewsletterId: $draft->id,
                status:              MailOutboxStatus::Queued,
                attemptCount:        0,
                lastError:           null,
                queuedAt:            $now,
                sentAt:              null,
                queuedBy:            $acting->id,
            ));
            $enqueued++;
        }

        // 8. Flip draft → Sent.
        $sentDraft = new NewsletterDraft(
            id:               $draft->id,
            tenantId:         $draft->tenantId,
            internalName:     $draft->internalName,
            subjectByLocale:  $draft->subjectByLocale,
            blocksByLocale:   $draft->blocksByLocale,
            audience:         $draft->audience,
            status:           NewsletterStatus::Sent,
            sentAt:           $now,
            createdAt:        $draft->createdAt,
            createdBy:        $draft->createdBy,
        );
        $this->repo->save($sentDraft);

        return new Output(enqueuedCount: $enqueued);
    }

    private function validateLocaleParity(NewsletterDraft $draft): void
    {
        $fallback        = self::FALLBACK_LOCALE;
        $fallbackSubject = trim($draft->subjectByLocale[$fallback] ?? '');
        $fallbackBlocks  = $draft->blocksByLocale[$fallback] ?? [];
        $fallbackOk      = $fallbackSubject !== '' && $fallbackBlocks !== [];

        foreach (SupportedLocale::supportedValues() as $locale) {
            $subject = trim($draft->subjectByLocale[$locale] ?? '');
            $blocks  = $draft->blocksByLocale[$locale] ?? [];
            if ($subject !== '' && $blocks !== []) {
                continue;
            }
            // Allow non-fallback locales to defer to en_GB when populated.
            if ($locale !== $fallback && $fallbackOk) {
                continue;
            }
            throw new \DomainException(
                "Newsletter requires subject + at least one block in {$locale} (or {$fallback} fallback).",
            );
        }
    }

    private function resolveSubject(NewsletterDraft $draft, string $localeCode): string
    {
        $subject = trim($draft->subjectByLocale[$localeCode] ?? '');
        if ($subject !== '') {
            return $subject;
        }
        $fallback = trim($draft->subjectByLocale[self::FALLBACK_LOCALE] ?? '');
        return $fallback !== '' ? $fallback : '(no subject)';
    }

    /**
     * @return list<\DaemsModule\Communications\Domain\Template\Block\NewsletterBlock>
     */
    private function resolveBlocks(NewsletterDraft $draft, string $localeCode): array
    {
        $blocks = $draft->blocksByLocale[$localeCode] ?? [];
        if ($blocks !== []) {
            return $blocks;
        }
        return $draft->blocksByLocale[self::FALLBACK_LOCALE] ?? [];
    }
}
