<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\ComposeAndPreviewMessage;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\UserTenantRole;
use DaemsModule\Communications\Domain\Audience\AudienceResolverInterface;
use DaemsModule\Communications\Domain\Audience\ResolvedRecipient;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettingsRepositoryInterface;
use DaemsModule\Communications\Domain\Template\MailTemplateRepositoryInterface;
use DaemsModule\Communications\Infrastructure\Renderer\EmailHtmlRenderer;

/**
 * Renders a single preview pair (HTML + plain text) for an admin-composed
 * message before it is queued to the outbox.
 *
 * Wave D Task D4.
 *
 * Authorisation:
 *   - admin or moderator in the target tenant, OR platform admin.
 *
 * Behaviour:
 *   1. Resolves the audience to learn (a) its size and (b) the first
 *      recipient's locale — that locale wins for the preview unless the caller
 *      explicitly picked a different one (handled via {@see Input::$locale}).
 *   2. Merges admin-input vars with tenant brand metadata from settings.
 *   3. Loads tenant string overrides for the chosen kind+locale and feeds
 *      them through {@see EmailHtmlRenderer}.
 *   4. Returns the rendered HTML/text + audience size + a short name sample
 *      so the UI can show "Sara, Mikko, Aino +5 muuta".
 *
 * Side effects: NONE. No DB writes, no outbox rows queued.
 */
final class ComposeAndPreviewMessage
{
    public function __construct(
        private readonly AudienceResolverInterface $resolver,
        private readonly MailTemplateRepositoryInterface $templateRepo,
        private readonly TenantCommunicationSettingsRepositoryInterface $settingsRepo,
        private readonly EmailHtmlRenderer $renderer,
    ) {
    }

    public function execute(Input $input, ActingUser $acting): Output
    {
        if (!$this->isAuthorized($acting, $input)) {
            throw new ForbiddenException();
        }

        // 1. Resolve audience (opt-in + suppression filter built in for SQL impl).
        $recipients = $this->resolver->resolve(
            $input->tenantId,
            $input->audienceFilter,
            $input->kind->category(),
        );
        $audienceCount = count($recipients);

        // 2. Pick the preview locale — first recipient wins; else fall back to
        // the locale the admin selected.
        $previewLocale = $recipients !== []
            ? $recipients[0]->locale
            : $input->locale;

        // 3. Build the vars map. Order:
        //   admin payload → brand/settings → per-sample-recipient overrides
        // The sample recipient is used so {{first_name}} renders against a
        // real value (or "Etunimi" placeholder when audience is empty).
        $settings = $this->settingsRepo->findForTenant($input->tenantId);

        $sampleRecipient = $recipients[0] ?? null;

        $vars = $input->payload;
        $vars['locale'] = $previewLocale->value();
        $vars['tenant_name'] = $vars['tenant_name'] ?? ($settings->mailDisplayName ?? '');
        $vars['brand_primary_color'] = $vars['brand_primary_color']
            ?? ($settings->brandPrimaryColor ?? '');
        $vars['brand_logo_url']      = $vars['brand_logo_url']
            ?? ($settings->brandLogoUrl ?? '');
        $vars['brand_footer_address'] = $vars['brand_footer_address']
            ?? ($settings->brandFooterAddress ?? '');
        $vars['first_name'] = $sampleRecipient !== null ? $sampleRecipient->firstName : 'Etunimi';

        // 4. Load admin-edited string overrides for this kind/locale (if any).
        $overrides = $this->templateRepo->findOverrides(
            $input->tenantId,
            $input->kind,
            $previewLocale,
        );
        $stringOverrides = $overrides !== null ? $overrides->stringOverrides : [];

        // 5. Render.
        [$html, $text] = $this->renderer->render(
            $input->kind,
            $vars,
            $previewLocale,
            $stringOverrides,
            $input->templateVariant,
        );

        // 6. Sample names — first 3 + "+N muuta" if more.
        $sampleNames = $this->buildSampleNames($recipients);

        return new Output(
            htmlPreview:           $html,
            textPreview:           $text,
            audienceCount:         $audienceCount,
            audienceSampleNames:   $sampleNames,
            previewLocale:         $previewLocale->value(),
            resolvedVars:          $vars,
        );
    }

    /**
     * Admin / moderator in the target tenant, or platform admin.
     */
    private function isAuthorized(ActingUser $acting, Input $input): bool
    {
        if ($acting->isPlatformAdmin()) {
            return true;
        }
        $role = $acting->roleIn($input->tenantId);
        return $role === UserTenantRole::Admin || $role === UserTenantRole::Moderator;
    }

    /**
     * @param list<ResolvedRecipient> $recipients
     * @return list<string>
     */
    private function buildSampleNames(array $recipients): array
    {
        $names = [];
        $take  = min(3, count($recipients));
        for ($i = 0; $i < $take; $i++) {
            $names[] = $recipients[$i]->firstName;
        }
        $extra = count($recipients) - $take;
        if ($extra > 0) {
            $names[] = sprintf('+%d muuta', $extra);
        }
        return $names;
    }
}
