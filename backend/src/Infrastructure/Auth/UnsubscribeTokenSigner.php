<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Infrastructure\Auth;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DaemsModule\Communications\Domain\Preference\CommunicationCategory;

/**
 * HMAC-signed one-click unsubscribe token (Wave G Task G3).
 *
 * Used to authorise a public /unsubscribe?t=... page WITHOUT an authenticated
 * session — the HMAC over (user, tenant, category, expiry) is the
 * authorisation. Keyed off APP_ENCRYPTION_KEY (shared with DsnEncryptor) but
 * passed through `sodium_crypto_generichash` with a per-purpose salt so the
 * unsubscribe HMAC key cannot be reused to forge anything else if it leaks.
 *
 * Token format: base64url( payloadJson . '|' . hmacSha256 )
 * Default TTL: 30 days (matches Newsletter email retention window).
 */
final class UnsubscribeTokenSigner
{
    private const DOMAIN_SEPARATOR = 'unsubscribe';

    public function __construct(private readonly string $base64Key)
    {
    }

    public function sign(
        UserId $user,
        TenantId $tenant,
        CommunicationCategory $cat,
        int $ttlSeconds = 2592000,
    ): string {
        $payload = (string) json_encode([
            'u' => $user->value(),
            't' => $tenant->value(),
            'c' => $cat->value,
            'e' => time() + $ttlSeconds,
        ]);
        $derivedKey = $this->deriveKey();
        $sig        = hash_hmac('sha256', $payload, $derivedKey);
        return rtrim(strtr(base64_encode($payload . '|' . $sig), '+/', '-_'), '=');
    }

    /**
     * Verify a token and return its payload, or null on any failure.
     *
     * @return array{u: string, t: string, c: string, e: int}|null
     */
    public function verify(string $token): ?array
    {
        $padding = strlen($token) % 4;
        if ($padding > 0) {
            $token .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        if ($decoded === false || !str_contains($decoded, '|')) {
            return null;
        }
        [$payload, $sig] = explode('|', $decoded, 2);

        $derivedKey = $this->deriveKey();
        $expected   = hash_hmac('sha256', $payload, $derivedKey);
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        $data = json_decode($payload, true);
        if (
            !is_array($data)
            || !isset($data['u'], $data['t'], $data['c'], $data['e'])
            || !is_string($data['u'])
            || !is_string($data['t'])
            || !is_string($data['c'])
            || !is_int($data['e'])
        ) {
            return null;
        }
        if ($data['e'] < time()) {
            return null;
        }
        return ['u' => $data['u'], 't' => $data['t'], 'c' => $data['c'], 'e' => $data['e']];
    }

    private function deriveKey(): string
    {
        $rawKey = base64_decode($this->base64Key, true);
        if ($rawKey === false || $rawKey === '') {
            throw new \RuntimeException('Invalid APP_ENCRYPTION_KEY for UnsubscribeTokenSigner');
        }
        return sodium_crypto_generichash(self::DOMAIN_SEPARATOR . $rawKey, '', 32);
    }
}
