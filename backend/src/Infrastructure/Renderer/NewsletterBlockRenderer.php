<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Infrastructure\Renderer;

use DaemsModule\Communications\Domain\Template\Block\ButtonBlock;
use DaemsModule\Communications\Domain\Template\Block\DividerBlock;
use DaemsModule\Communications\Domain\Template\Block\EventCardBlock;
use DaemsModule\Communications\Domain\Template\Block\HeadingBlock;
use DaemsModule\Communications\Domain\Template\Block\ImageBlock;
use DaemsModule\Communications\Domain\Template\Block\NewsletterBlock;
use DaemsModule\Communications\Domain\Template\Block\ParagraphBlock;
use DaemsModule\Communications\Domain\Template\Block\TwoColumnsBlock;

/**
 * Converts a list of {@see NewsletterBlock} values into an email-safe HTML
 * snippet suitable for embedding into `newsletter_wrapper.html` via the
 * `{{block_body}}` placeholder.
 *
 * Every block produces a `<table>` element with inline CSS only — the standard
 * pattern for email clients (Outlook desktop, Gmail-on-mobile, Apple Mail).
 *
 * `brandPrimaryColor` is injected per-render (constructed inline by the
 * SendNewsletter use case from `TenantCommunicationSettings::$brandPrimaryColor`).
 *
 * Wave E Task E2 (Milestone 0.8 / communications-v1).
 */
final class NewsletterBlockRenderer
{
    public function __construct(
        private readonly MarkdownRenderer $md,
        private readonly string $brandPrimaryColor,
    ) {
    }

    /** @param list<NewsletterBlock> $blocks */
    public function renderAll(array $blocks): string
    {
        $parts = [];
        foreach ($blocks as $b) {
            $parts[] = $this->renderOne($b);
        }
        return implode("\n", $parts);
    }

    public function renderOne(NewsletterBlock $b): string
    {
        return match (true) {
            $b instanceof HeadingBlock    => $this->heading($b),
            $b instanceof ParagraphBlock  => $this->paragraph($b),
            $b instanceof ImageBlock      => $this->image($b),
            $b instanceof ButtonBlock     => $this->button($b),
            $b instanceof DividerBlock    => $this->divider(),
            $b instanceof TwoColumnsBlock => $this->twoColumns($b),
            $b instanceof EventCardBlock  => $this->eventCard($b),
            default                       => throw new \RuntimeException('Unknown block: ' . $b::class),
        };
    }

    private function heading(HeadingBlock $b): string
    {
        $size    = match ($b->level) { 1 => '22px', 2 => '18px', default => '15px' };
        $weight  = $b->level <= 2 ? '700' : '600';
        $escaped = htmlspecialchars($b->text, ENT_QUOTES | ENT_HTML5);
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
            . '<tr><td style="padding:14px 0 8px 0;font-size:' . $size
            . ';font-weight:' . $weight
            . ';color:' . $this->brandPrimaryColor
            . ';font-family:Helvetica,Arial,sans-serif;">'
            . $escaped
            . '</td></tr></table>';
    }

    private function paragraph(ParagraphBlock $b): string
    {
        $html = $this->md->renderSafe($b->markdown);
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
            . '<tr><td style="padding:6px 0;font-size:14px;color:#333;line-height:1.55;font-family:Helvetica,Arial,sans-serif;">'
            . $html
            . '</td></tr></table>';
    }

    private function image(ImageBlock $b): string
    {
        $url = htmlspecialchars($b->url, ENT_QUOTES | ENT_HTML5);
        $alt = htmlspecialchars($b->alt, ENT_QUOTES | ENT_HTML5);
        $cap = $b->caption !== null
            ? '<div style="padding:4px 0;font-size:11px;color:#888;text-align:center;">'
                . htmlspecialchars($b->caption, ENT_QUOTES | ENT_HTML5)
                . '</div>'
            : '';
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
            . '<tr><td align="center" style="padding:10px 0;">'
            . '<img src="' . $url . '" alt="' . $alt . '" style="display:block;border:0;outline:none;max-width:100%;height:auto;">'
            . $cap
            . '</td></tr></table>';
    }

    private function button(ButtonBlock $b): string
    {
        $url = htmlspecialchars($b->url, ENT_QUOTES | ENT_HTML5);
        $txt = htmlspecialchars($b->text, ENT_QUOTES | ENT_HTML5);
        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:14px auto;">'
            . '<tr><td style="background:' . $this->brandPrimaryColor . ';border-radius:4px;">'
            . '<a href="' . $url . '" style="display:inline-block;padding:10px 22px;color:#ffffff;text-decoration:none;font-size:13px;font-weight:600;font-family:Helvetica,Arial,sans-serif;">'
            . $txt
            . '</a></td></tr></table>';
    }

    private function divider(): string
    {
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
            . '<tr><td style="padding:14px 0;">'
            . '<div style="border-top:1px solid #d8d8d8;height:1px;line-height:1px;font-size:1px;">&nbsp;</div>'
            . '</td></tr></table>';
    }

    private function twoColumns(TwoColumnsBlock $b): string
    {
        /** @var list<NewsletterBlock> $leftBlocks */
        $leftBlocks = [];
        foreach ($b->left as $child) {
            assert($child instanceof NewsletterBlock);
            $leftBlocks[] = $child;
        }
        /** @var list<NewsletterBlock> $rightBlocks */
        $rightBlocks = [];
        foreach ($b->right as $child) {
            assert($child instanceof NewsletterBlock);
            $rightBlocks[] = $child;
        }
        $left  = $this->renderAll($leftBlocks);
        $right = $this->renderAll($rightBlocks);
        // Outlook desktop renders as side-by-side; mobile (max-width:600) flips
        // to stacked via the .col CSS class declared in newsletter_wrapper.html.
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
            . '<tr><td class="col" width="50%" valign="top" style="padding:6px 10px 6px 0;">'
            . $left
            . '</td><td class="col" width="50%" valign="top" style="padding:6px 0 6px 10px;">'
            . $right
            . '</td></tr></table>';
    }

    private function eventCard(EventCardBlock $b): string
    {
        // 0.8 stub: title + whenLabel pre-resolved by the composer surface
        // before save, so the renderer does not need a cross-module call
        // against the events module at send time.
        $title = htmlspecialchars($b->title ?? '(Tapahtuma)', ENT_QUOTES | ENT_HTML5);
        $when  = htmlspecialchars($b->whenLabel ?? '', ENT_QUOTES | ENT_HTML5);
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f7fa;border-left:3px solid '
            . $this->brandPrimaryColor . ';margin:8px 0;">'
            . '<tr><td style="padding:10px 14px;font-size:13px;color:#333;">'
            . '<strong>' . $title . '</strong><br>' . $when
            . '</td></tr></table>';
    }
}
