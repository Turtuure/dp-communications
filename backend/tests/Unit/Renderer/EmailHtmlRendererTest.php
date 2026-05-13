<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Renderer;

use Daems\Domain\Locale\SupportedLocale;
use DaemsModule\Communications\Domain\Mail\MailKind;
use DaemsModule\Communications\Infrastructure\Renderer\EmailHtmlRenderer;
use DaemsModule\Communications\Infrastructure\Renderer\Html2Text;
use DaemsModule\Communications\Infrastructure\Renderer\MailTemplateRegistry;
use DaemsModule\Communications\Infrastructure\Renderer\MarkdownRenderer;
use DaemsModule\Communications\Infrastructure\Renderer\VarSubstituter;
use PHPUnit\Framework\TestCase;

final class EmailHtmlRendererTest extends TestCase
{
    private EmailHtmlRenderer $renderer;
    private SupportedLocale $fi;
    private SupportedLocale $en;

    protected function setUp(): void
    {
        $this->renderer = new EmailHtmlRenderer(
            new MailTemplateRegistry(),
            new VarSubstituter(),
            new MarkdownRenderer(),
            new Html2Text(),
        );
        $this->fi = SupportedLocale::fromString('fi_FI');
        $this->en = SupportedLocale::fromString('en_GB');
    }

    public function test_full_pipeline_produces_html_and_text(): void
    {
        [$html, $text] = $this->renderer->render(
            MailKind::MeetingInvitation,
            [
                'first_name'           => 'Anna',
                'subject'              => 'Vuosikokous 2026',
                'meeting_title'        => 'Vuosikokous',
                'meeting_date'         => '15.6.2026',
                'meeting_location'     => 'Kulttuuritalo',
                'meeting_remote_url'   => 'https://meet/x',
                'agenda_html'          => '<ol><li>Avaus</li></ol>',
                'documents_list'       => '',
                'rsvp_url'             => 'https://daems.fi/rsvp/x',
                'signature'            => 'Hallitus',
                'brand_primary_color'  => '#2e5c8a',
                'brand_logo_url'       => 'https://cdn/x.png',
                'brand_footer_address' => 'Daem Society',
                'locale'               => 'fi_FI',
                'tenant_name'          => 'Daem Society',
            ],
            $this->fi,
            stringOverrides: ['intro_text' => 'Hyvä jäsen, **tervetuloa**.'],
        );

        self::assertStringContainsString('<table', $html);
        self::assertStringContainsString('Anna', $html);

        // Markdown rendered into intro_text_html and injected verbatim.
        self::assertStringContainsString('<strong>tervetuloa</strong>', $html);

        // Plain-text fallback has the recipient name.
        self::assertStringContainsString('Anna', $text);

        // Markdown bold survives the strip-tags path as plain word.
        self::assertStringContainsString('tervetuloa', $text);
    }

    public function test_string_overrides_win_over_defaults_for_subject(): void
    {
        [$html, $_] = $this->renderer->render(
            MailKind::MembershipApproved,
            [
                'first_name'           => 'Ada',
                'tenant_name'          => 'Daem Society',
                'login_link'           => 'https://daems.fi/login',
                'signature'            => 'Hallitus',
                'brand_primary_color'  => '#2e5c8a',
                'brand_logo_url'       => 'https://cdn/x.png',
                'brand_footer_address' => 'Daem Society ry',
                'locale'               => 'en_GB',
            ],
            $this->en,
            stringOverrides: ['subject' => 'Welcome aboard!'],
        );

        self::assertStringContainsString('Welcome aboard!', $html);
    }

    public function test_xss_in_var_value_is_escaped(): void
    {
        [$html, $_] = $this->renderer->render(
            MailKind::MembershipApproved,
            [
                'first_name'           => '<script>alert(1)</script>',
                'subject'              => 'Hi',
                'tenant_name'          => 'Daem Society',
                'login_link'           => 'https://daems.fi/login',
                'signature'            => 'Hallitus',
                'brand_primary_color'  => '#2e5c8a',
                'brand_logo_url'       => 'https://cdn/x.png',
                'brand_footer_address' => 'Daem Society',
                'locale'               => 'fi_FI',
            ],
            $this->fi,
            stringOverrides: [],
        );

        // Tag character should be escaped, not present as a real script tag.
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_template_variant_loads_alternate_file(): void
    {
        [$html, $_] = $this->renderer->render(
            MailKind::PaymentReminder,
            [
                'first_name'           => 'Anna',
                'subject'              => 'Lapse warning',
                'predicted_lapse_date' => '31.3.2027',
                'outstanding_total'    => '50.00',
                'currency'             => 'EUR',
                'payment_link'         => 'https://daems.fi/pay/x',
                'signature'            => 'Hallitus',
                'brand_primary_color'  => '#2e5c8a',
                'brand_logo_url'       => 'https://cdn/x.png',
                'brand_footer_address' => 'Daem Society',
                'locale'               => 'fi_FI',
                'tenant_name'          => 'Daem Society',
            ],
            $this->fi,
            stringOverrides: [],
            templateVariant: 'lapse_warning',
        );

        // § 4 is only in lapse_warning.html.
        self::assertStringContainsString('§ 4', $html);
        self::assertStringContainsString('31.3.2027', $html);
    }
}
