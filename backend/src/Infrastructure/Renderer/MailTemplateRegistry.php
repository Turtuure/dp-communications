<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Infrastructure\Renderer;

use Daems\Domain\Locale\SupportedLocale;
use DaemsModule\Communications\Domain\Mail\MailKind;

/**
 * Locates HTML template sources on disk and reads developer-default strings
 * (subject / intro / signature / footer) for a given mail kind + locale.
 *
 * Templates are routed by string name so we can load alternate variants
 * (e.g. `lapse_warning` shares the `payment_reminder` MailKind but uses
 * `templates/lapse_warning.html`). Use `MailKind::defaultTemplateName()` as
 * the default and pass an explicit variant where needed.
 */
final class MailTemplateRegistry
{
    private const TEMPLATE_NAME_PATTERN = '/^[a-z_][a-z0-9_]*$/';
    private const LANG_DIR = __DIR__ . '/../../../../../../daems-platform/lang';

    /**
     * Load the raw HTML source for a template by base-name (no extension).
     *
     * @throws \RuntimeException when the template file is missing
     * @throws \InvalidArgumentException when the name contains unsafe characters
     */
    public function loadHtmlSource(string $templateName): string
    {
        if (preg_match(self::TEMPLATE_NAME_PATTERN, $templateName) !== 1) {
            throw new \InvalidArgumentException(
                "Invalid template name '{$templateName}' — expected snake_case identifier",
            );
        }

        $file = __DIR__ . "/templates/{$templateName}.html";
        if (!is_file($file)) {
            throw new \RuntimeException("Template not found: {$templateName}.html");
        }

        $source = file_get_contents($file);
        if ($source === false) {
            throw new \RuntimeException("Failed to read template: {$templateName}.html");
        }

        return $source;
    }

    /**
     * Read developer-default strings (subject/intro/signature/footer) for a
     * given mail kind from the platform `lang/{locale}.php` file.
     *
     * Returns an empty map when no defaults are defined (admin-supplied
     * overrides will still drive rendering downstream). If the requested
     * locale has no defaults but `en_GB` does, the en_GB fallback applies.
     *
     * @return array<string,string>
     */
    public function defaultStrings(MailKind $kind, SupportedLocale $locale): array
    {
        $prefix = "communications.template.defaults.{$kind->value}.";
        $defaults = $this->readPrefix($locale->value(), $prefix);

        if ($defaults === [] && $locale->value() !== SupportedLocale::CONTENT_FALLBACK) {
            return $this->readPrefix(SupportedLocale::CONTENT_FALLBACK, $prefix);
        }

        return $defaults;
    }

    /**
     * @return array<string,string>
     */
    private function readPrefix(string $localeValue, string $prefix): array
    {
        $langFile = self::LANG_DIR . "/{$localeValue}.php";
        if (!is_file($langFile)) {
            return [];
        }

        /** @var mixed $strings */
        $strings = require $langFile;
        if (!is_array($strings)) {
            return [];
        }

        $out = [];
        $prefixLen = strlen($prefix);
        foreach ($strings as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            if (str_starts_with($key, $prefix)) {
                $out[substr($key, $prefixLen)] = $value;
            }
        }

        return $out;
    }
}
