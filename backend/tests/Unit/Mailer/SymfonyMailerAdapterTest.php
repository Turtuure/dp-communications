<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Mailer;

use DaemsModule\Communications\Infrastructure\Crypto\DsnEncryptor;
use DaemsModule\Communications\Infrastructure\Mailer\SymfonyMailerAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SymfonyMailerAdapterTest extends TestCase
{
    private function makeAdapter(): SymfonyMailerAdapter
    {
        return new SymfonyMailerAdapter(
            new DsnEncryptor(base64_encode(sodium_crypto_secretbox_keygen())),
        );
    }

    /**
     * Real-world SMTP transport-error messages collected from major providers
     * (Gmail, Outlook 365, Mailgun, Postmark) plus generic 4xx/5xx samples.
     *
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function smtpMessageProvider(): array
    {
        return [
            'gmail-5.1.1-no-such-user' => [
                'Expected response code "250" but got code "550", with message "550 5.1.1 The email account that you tried to reach does not exist."',
                '5.1.1',
            ],
            'outlook-5.7.1-relay-denied' => [
                'Expected response code "354" but got code "550", with message "550 5.7.1 Unable to relay for foo@example.com"',
                '5.7.1',
            ],
            'mailgun-4.2.2-mailbox-full' => [
                'Mailgun returned "4.2.2 mailbox full" for the recipient',
                '4.2.2',
            ],
            'postmark-5.7.0-rejected' => [
                '550 5.7.0 Address rejected by Postmark suppression list',
                '5.7.0',
            ],
            'generic-4.7.1-greylisted' => [
                'Expected response code "250" but got code "451", with message "451 4.7.1 Greylisting in effect, please come back later"',
                '4.7.1',
            ],
            'basic-451-soft-no-extended' => [
                'Expected response code "250" but got code "451" greylisting active',
                '451',
            ],
            'basic-550-hard-no-extended' => [
                'Expected response code "250" but got code "550" mailbox unavailable',
                '550',
            ],
            'sendgrid-5.4.1-bounce' => [
                '550 5.4.1 Recipient address rejected: Access denied. AS(201806281) [BL0PR02MB4416.namprd02.prod.outlook.com]',
                '5.4.1',
            ],
            'mailgun-4.4.2-timeout' => [
                'Connection lost during DATA: 4.4.2 timeout while writing',
                '4.4.2',
            ],
            'amazon-ses-5.7.13-policy' => [
                'Error code "554" 5.7.13 Email rejected by SES sending policy',
                '5.7.13',
            ],
            'no-recognizable-code' => [
                'Connection timed out after 30 seconds',
                null,
            ],
            'dns-failure-no-code' => [
                'Unable to resolve smtp.example.com: name or service not known',
                null,
            ],
            'malicious-3xx-code-ignored' => [
                'Expected response code "250" but got code "354" mid-stream',
                null,
            ],
        ];
    }

    #[DataProvider('smtpMessageProvider')]
    public function test_parse_smtp_code(string $message, ?string $expected): void
    {
        $adapter = $this->makeAdapter();

        self::assertSame($expected, $adapter->parseSmtpCode($message));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function hardBounceProvider(): array
    {
        return [
            'enhanced-5.1.1-hard' => ['5.1.1', true],
            'enhanced-5.7.0-hard' => ['5.7.0', true],
            'basic-550-hard'      => ['550', true],
            'basic-554-hard'      => ['554', true],
            'enhanced-4.2.2-soft' => ['4.2.2', false],
            'enhanced-4.7.1-soft' => ['4.7.1', false],
            'basic-451-soft'      => ['451', false],
            'basic-421-soft'      => ['421', false],
        ];
    }

    #[DataProvider('hardBounceProvider')]
    public function test_is_hard_bounce(string $code, bool $expected): void
    {
        $adapter = $this->makeAdapter();

        self::assertSame($expected, $adapter->isHardBounce($code));
    }
}
