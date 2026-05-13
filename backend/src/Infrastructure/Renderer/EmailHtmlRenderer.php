<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Infrastructure\Renderer;

use Daems\Domain\Locale\SupportedLocale;
use DaemsModule\Communications\Domain\Mail\MailKind;

/**
 * Renders an outbox row's payload to an `[html, text]` MIME tuple.
 *
 * Pipeline:
 *
 *   1. Pick template name from `$templateVariant` (e.g. `lapse_warning`) or
 *      fall back to the kind's default (`MailKind::defaultTemplateName()`).
 *   2. Merge developer-default strings from `lang/{locale}.php` with the
 *      admin's `$stringOverrides` (admin wins).
 *   3. Pre-render Markdown fields (`intro_text`, `body`, `signature`) into
 *      `*_html` companions that the templates consume.
 *   4. Copy string overrides (`subject`, `signature`, `footer`) into the
 *      variables map so templates can reference them.
 *   5. Load the HTML template, apply var substitution, derive plain-text
 *      fallback.
 */
final class EmailHtmlRenderer
{
    public function __construct(
        private readonly MailTemplateRegistry $registry,
        private readonly VarSubstituter $varSub,
        private readonly MarkdownRenderer $md,
        private readonly Html2Text $h2t,
    ) {
    }

    /**
     * @param array<string,mixed>  $vars            Context vars: brand_*, locale, plus kind-specific fields
     * @param array<string,string> $stringOverrides Admin-edited overrides for subject/intro/signature/footer
     * @return array{0:string,1:string}             [html, plainText]
     */
    public function render(
        MailKind $kind,
        array $vars,
        SupportedLocale $locale,
        array $stringOverrides,
        ?string $templateVariant = null,
    ): array {
        $templateName = $templateVariant ?? $kind->defaultTemplateName();

        // 1. Strings: defaults → admin overrides.
        $defaults = $this->registry->defaultStrings($kind, $locale);
        $strings  = array_replace($defaults, $stringOverrides);

        // 2. Pre-render Markdown body-fields into *_html companions.
        foreach (['intro_text', 'body', 'signature'] as $mdField) {
            if (isset($strings[$mdField]) && $strings[$mdField] !== '') {
                $vars[$mdField . '_html'] = $this->md->renderSafe($strings[$mdField]);
            }
        }

        // 3. Pass-through admin string overrides into vars for placeholder use.
        foreach (['subject', 'signature', 'footer'] as $passThrough) {
            if (isset($strings[$passThrough])) {
                $vars[$passThrough] = $strings[$passThrough];
            }
        }

        // 4. Load + substitute.
        $template = $this->registry->loadHtmlSource($templateName);
        $html     = $this->varSub->substitute($template, $vars, $kind);

        // 5. Plain-text fallback.
        $text = $this->h2t->convert($html);

        return [$html, $text];
    }
}
