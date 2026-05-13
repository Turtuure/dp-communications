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

/**
 * One snapshot test per HTML template confirming each renders to table-based
 * email-safe HTML with expected placeholder substitutions and no JS / abs-pos.
 */
final class EmailHtmlSnapshotTest extends TestCase
{
    private EmailHtmlRenderer $renderer;
    private SupportedLocale $fi;

    protected function setUp(): void
    {
        $this->renderer = new EmailHtmlRenderer(
            new MailTemplateRegistry(),
            new VarSubstituter(),
            new MarkdownRenderer(),
            new Html2Text(),
        );
        $this->fi = SupportedLocale::fromString('fi_FI');
    }

    public function test_meeting_invitation_renders_to_table_based_html(): void
    {
        [$html, $text] = $this->renderer->render(
            MailKind::MeetingInvitation,
            [
                'first_name'           => 'Anna',
                'subject'              => 'Vuosikokous 2026',
                'intro_text_html'      => '<p>Tervetuloa!</p>',
                'meeting_title'        => 'Vuosikokous',
                'meeting_date'         => '15.6.2026 klo 18:00',
                'meeting_location'     => 'Kulttuuritalo',
                'meeting_remote_url'   => 'https://meet.example.com/x',
                'agenda_html'          => '<ol><li>Avaus</li><li>Päätös</li></ol>',
                'documents_list'       => 'toimintakertomus.pdf',
                'rsvp_url'             => 'https://daems.fi/rsvp/abc',
                'signature'            => 'Hallitus',
                'brand_primary_color'  => '#2e5c8a',
                'brand_logo_url'       => 'https://cdn/logo.png',
                'brand_footer_address' => 'Daem Society ry',
                'locale'               => 'fi_FI',
                'tenant_name'          => 'Daem Society',
            ],
            $this->fi,
            stringOverrides: [],
        );

        $this->assertCommonShape($html, $text);
        self::assertStringContainsString('Anna', $html);
        self::assertStringContainsString('Vuosikokous 2026', $html);
        self::assertStringContainsString('https://daems.fi/rsvp/abc', $html);
        self::assertStringContainsString('Anna', $text);
        self::assertStringContainsString('15.6.2026', $text);
    }

    public function test_payment_reminder_renders(): void
    {
        [$html, $text] = $this->renderer->render(
            MailKind::PaymentReminder,
            [
                'first_name'           => 'Pekka',
                'subject'              => 'Jäsenmaksu 2026',
                'intro_text_html'      => '<p>Muistutamme erääntyvästä maksusta.</p>',
                'invoice_year'         => '2026',
                'amount'               => '25.00',
                'currency'             => 'EUR',
                'due_date'             => '31.12.2026',
                'payment_link'         => 'https://daems.fi/pay/xyz',
                'signature'            => 'Hallitus',
                'brand_primary_color'  => '#2e5c8a',
                'brand_logo_url'       => 'https://cdn/logo.png',
                'brand_footer_address' => 'Daem Society ry',
                'locale'               => 'fi_FI',
                'tenant_name'          => 'Daem Society',
            ],
            $this->fi,
            stringOverrides: [],
        );

        $this->assertCommonShape($html, $text);
        self::assertStringContainsString('Pekka', $html);
        self::assertStringContainsString('25.00', $html);
        self::assertStringContainsString('https://daems.fi/pay/xyz', $html);
        self::assertStringContainsString('Pekka', $text);
        self::assertStringContainsString('25.00', $text);
    }

    public function test_membership_approved_renders(): void
    {
        [$html, $text] = $this->renderer->render(
            MailKind::MembershipApproved,
            [
                'first_name'           => 'Maija',
                'subject'              => 'Tervetuloa Daem Societyyn',
                'intro_text_html'      => '<p>Hakemuksesi on hyväksytty.</p>',
                'tenant_name'          => 'Daem Society',
                'login_link'           => 'https://daems.fi/login',
                'signature'            => 'Hallitus',
                'brand_primary_color'  => '#2e5c8a',
                'brand_logo_url'       => 'https://cdn/logo.png',
                'brand_footer_address' => 'Daem Society ry',
                'locale'               => 'fi_FI',
            ],
            $this->fi,
            stringOverrides: [],
        );

        $this->assertCommonShape($html, $text);
        self::assertStringContainsString('Maija', $html);
        self::assertStringContainsString('Daem Society', $html);
        self::assertStringContainsString('https://daems.fi/login', $html);
        self::assertStringContainsString('Maija', $text);
    }

    public function test_group_message_renders_body_html(): void
    {
        [$html, $text] = $this->renderer->render(
            MailKind::GroupMessage,
            [
                'first_name'           => 'Liisa',
                'subject'              => 'Hallituksen tiedote',
                'body_html'            => '<p>Tärkeä <strong>tiedote</strong> jäsenille.</p>',
                'signature'            => 'Hallitus',
                'tenant_name'          => 'Daem Society',
                'brand_primary_color'  => '#2e5c8a',
                'brand_logo_url'       => 'https://cdn/logo.png',
                'brand_footer_address' => 'Daem Society ry',
                'locale'               => 'fi_FI',
            ],
            $this->fi,
            stringOverrides: [],
        );

        $this->assertCommonShape($html, $text);
        self::assertStringContainsString('Liisa', $html);
        self::assertStringContainsString('<strong>tiedote</strong>', $html);
        self::assertStringContainsString('tiedote', $text);
    }

    public function test_lapse_warning_renders_via_variant(): void
    {
        [$html, $text] = $this->renderer->render(
            MailKind::PaymentReminder,
            [
                'first_name'           => 'Risto',
                'subject'              => 'Jäsenyys raukeamassa',
                'predicted_lapse_date' => '31.3.2027',
                'outstanding_total'    => '50.00',
                'currency'             => 'EUR',
                'payment_link'         => 'https://daems.fi/pay/lapse-001',
                'signature'            => 'Hallitus',
                'brand_primary_color'  => '#2e5c8a',
                'brand_logo_url'       => 'https://cdn/logo.png',
                'brand_footer_address' => 'Daem Society ry',
                'locale'               => 'fi_FI',
                'tenant_name'          => 'Daem Society',
            ],
            $this->fi,
            stringOverrides: [],
            templateVariant: 'lapse_warning',
        );

        $this->assertCommonShape($html, $text);
        self::assertStringContainsString('Risto', $html);
        self::assertStringContainsString('31.3.2027', $html);
        self::assertStringContainsString('§ 4', $html);
        self::assertStringContainsString('https://daems.fi/pay/lapse-001', $html);
        self::assertStringContainsString('Risto', $text);
    }

    public function test_newsletter_wrapper_renders_with_unsubscribe(): void
    {
        [$html, $text] = $this->renderer->render(
            MailKind::Newsletter,
            [
                'first_name'           => 'Eero',
                'subject'              => 'Daems-uutiskirje 5/2026',
                'block_body'           => '<table role="presentation"><tr><td>Sisältö</td></tr></table>',
                'unsubscribe_url'      => 'https://daems.fi/unsub/token-123',
                'tenant_name'          => 'Daem Society',
                'brand_primary_color'  => '#2e5c8a',
                'brand_logo_url'       => 'https://cdn/logo.png',
                'brand_footer_address' => 'Daem Society ry',
                'locale'               => 'fi_FI',
            ],
            $this->fi,
            stringOverrides: [],
        );

        $this->assertCommonShape($html, $text);
        self::assertStringContainsString('Eero', $html);
        self::assertStringContainsString('Sisältö', $html);
        self::assertStringContainsString('https://daems.fi/unsub/token-123', $html);
        self::assertStringContainsString('Eero', $text);
    }

    private function assertCommonShape(string $html, string $text): void
    {
        // Email-safe shape invariants — every template must hold these.
        self::assertStringContainsString('<table', $html);
        self::assertStringContainsString('role="presentation"', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('position:absolute', $html);
        self::assertStringNotContainsString('display:flex', $html);
        self::assertStringNotContainsString('display:grid', $html);

        self::assertNotSame('', trim($text), 'Plain-text fallback should be non-empty');
    }
}
