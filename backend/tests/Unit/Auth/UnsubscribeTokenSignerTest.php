<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Auth;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DaemsModule\Communications\Domain\Preference\CommunicationCategory;
use DaemsModule\Communications\Infrastructure\Auth\UnsubscribeTokenSigner;
use PHPUnit\Framework\TestCase;

final class UnsubscribeTokenSignerTest extends TestCase
{
    private string $key;
    private UserId $user;
    private TenantId $tenant;

    protected function setUp(): void
    {
        $this->key    = base64_encode(sodium_crypto_secretbox_keygen());
        $this->user   = UserId::fromString('01958000-0000-7000-8000-0000000000aa');
        $this->tenant = TenantId::fromString('01958000-0000-7000-8000-0000000000bb');
    }

    public function test_sign_verify_roundtrip(): void
    {
        $signer = new UnsubscribeTokenSigner($this->key);
        $token  = $signer->sign($this->user, $this->tenant, CommunicationCategory::Marketing);

        $payload = $signer->verify($token);

        self::assertIsArray($payload);
        self::assertSame($this->user->value(), $payload['u']);
        self::assertSame($this->tenant->value(), $payload['t']);
        self::assertSame('marketing', $payload['c']);
        self::assertGreaterThan(time(), $payload['e']);
    }

    public function test_expired_token_rejected(): void
    {
        $signer = new UnsubscribeTokenSigner($this->key);
        // Negative TTL → already-expired token.
        $token = $signer->sign($this->user, $this->tenant, CommunicationCategory::Marketing, -10);

        self::assertNull($signer->verify($token));
    }

    public function test_tampered_signature_rejected(): void
    {
        $signer = new UnsubscribeTokenSigner($this->key);
        $token  = $signer->sign($this->user, $this->tenant, CommunicationCategory::Marketing);

        // Flip the last char (signature region after the '|' separator). Padding
        // is stripped from the rtrim, so just append an 'X' to corrupt the sig.
        $tampered = substr($token, 0, -1) . (substr($token, -1) === 'X' ? 'Y' : 'X');
        self::assertNull($signer->verify($tampered));

        // Garbage that doesn't even contain the '|' separator after b64-decode.
        self::assertNull($signer->verify('not-a-real-token'));
    }

    public function test_different_key_rejects_token(): void
    {
        $signerA = new UnsubscribeTokenSigner($this->key);
        $token   = $signerA->sign($this->user, $this->tenant, CommunicationCategory::Marketing);

        $signerB = new UnsubscribeTokenSigner(base64_encode(sodium_crypto_secretbox_keygen()));
        self::assertNull($signerB->verify($token));
    }

    public function test_invalid_base64_key_throws(): void
    {
        $signer = new UnsubscribeTokenSigner('!!! not base64 !!!');

        $this->expectException(\RuntimeException::class);
        $signer->sign($this->user, $this->tenant, CommunicationCategory::Marketing);
    }
}
