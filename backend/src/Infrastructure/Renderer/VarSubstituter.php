<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Infrastructure\Renderer;

use DaemsModule\Communications\Domain\Mail\Exception\UnknownTemplateVarException;
use DaemsModule\Communications\Domain\Mail\MailKind;

/**
 * Replaces `{{var_name}}` placeholders in a template string with values from a
 * provided variables map.
 *
 * Rules:
 *   - Every placeholder name must be in the kind's whitelist
 *     (`MailKind::allowedVars()`) — unknown names throw
 *     `UnknownTemplateVarException`. This catches typos at render time.
 *   - Values are HTML-escaped via `htmlspecialchars()` with ENT_QUOTES |
 *     ENT_HTML5 to keep XSS-safe inside attributes AND text nodes.
 *   - EXCEPTION: variable names ending in `_html` (e.g. `intro_text_html`,
 *     `agenda_html`, `body_html`, `block_body`) are treated as already-rendered
 *     trusted HTML (produced by MarkdownRenderer or the newsletter block
 *     renderer) and are inserted verbatim.
 *   - Missing values (whitelisted but not provided) substitute as the empty
 *     string — templates can render with optional sections that gracefully
 *     collapse.
 *   - Scalar values (string, int, float, bool, null) are coerced to string;
 *     non-scalars are rejected to keep the contract obvious.
 */
final class VarSubstituter
{
    private const PLACEHOLDER_PATTERN = '/\{\{\s*([a-z_][a-z0-9_]*)\s*\}\}/i';

    /**
     * @param array<string,mixed> $vars
     */
    public function substitute(string $template, array $vars, MailKind $kind): string
    {
        $allowed = array_flip($kind->allowedVars());

        // Discover the placeholder names present in the template so we can
        // validate each against the whitelist (catches typos like {{firstName}}).
        if (preg_match_all(self::PLACEHOLDER_PATTERN, $template, $matches) === false) {
            return $template;
        }

        $names = array_unique($matches[1]);
        foreach ($names as $name) {
            if (!isset($allowed[$name])) {
                throw new UnknownTemplateVarException($name, $kind);
            }
        }

        $callback = static function (array $m) use ($vars): string {
            $name = $m[1];
            $value = $vars[$name] ?? '';

            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            if (is_int($value) || is_float($value)) {
                $value = (string) $value;
            }
            if (!is_string($value)) {
                throw new \InvalidArgumentException(
                    sprintf(
                        "Template var '%s' must be scalar or null, got %s",
                        $name,
                        get_debug_type($value),
                    ),
                );
            }

            // Pre-rendered HTML fields opt out of escaping.
            if (str_ends_with($name, '_html') || $name === 'block_body') {
                return $value;
            }

            return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        };

        $result = preg_replace_callback(self::PLACEHOLDER_PATTERN, $callback, $template);

        return $result ?? $template;
    }
}
