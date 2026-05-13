<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Renderer;

use DaemsModule\Communications\Domain\Mail\Exception\UnknownTemplateVarException;
use DaemsModule\Communications\Domain\Mail\MailKind;
use DaemsModule\Communications\Infrastructure\Renderer\VarSubstituter;
use PHPUnit\Framework\TestCase;

final class VarSubstituterTest extends TestCase
{
    private VarSubstituter $sub;

    protected function setUp(): void
    {
        $this->sub = new VarSubstituter();
    }

    public function test_substitutes_known_var_and_html_escapes(): void
    {
        $out = $this->sub->substitute(
            'Hello {{first_name}}!',
            ['first_name' => "Anna <O'Connor>"],
            MailKind::MeetingInvitation,
        );

        // ENT_QUOTES | ENT_HTML5 encodes single quotes as &apos; (HTML5 named entity).
        self::assertStringContainsString('Hello Anna &lt;O&apos;Connor&gt;!', $out);
    }

    public function test_html_named_vars_are_inserted_verbatim(): void
    {
        $out = $this->sub->substitute(
            '<div>{{intro_text_html}}</div>',
            ['intro_text_html' => '<p>Hei <strong>maailma</strong></p>'],
            MailKind::MeetingInvitation,
        );

        self::assertStringContainsString('<p>Hei <strong>maailma</strong></p>', $out);
    }

    public function test_unknown_var_throws(): void
    {
        $this->expectException(UnknownTemplateVarException::class);

        $this->sub->substitute(
            'Hello {{not_in_whitelist}}',
            [],
            MailKind::MeetingInvitation,
        );
    }

    public function test_missing_value_renders_empty_string(): void
    {
        $out = $this->sub->substitute(
            'A={{first_name}}|B={{subject}}',
            ['first_name' => 'Anna'],
            MailKind::MeetingInvitation,
        );

        self::assertSame('A=Anna|B=', $out);
    }

    public function test_supports_int_and_float_scalars(): void
    {
        $out = $this->sub->substitute(
            'Total: {{amount}} {{currency}}',
            ['amount' => 42, 'currency' => 'EUR'],
            MailKind::PaymentReminder,
        );

        self::assertStringContainsString('Total: 42 EUR', $out);
    }

    public function test_kind_specific_whitelist_enforced(): void
    {
        // `body_html` is allowed for GroupMessage, NOT for PaymentReminder.
        $this->expectException(UnknownTemplateVarException::class);

        $this->sub->substitute(
            '<div>{{body_html}}</div>',
            ['body_html' => '<p>x</p>'],
            MailKind::PaymentReminder,
        );
    }
}
