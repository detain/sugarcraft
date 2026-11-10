<?php

declare(strict_types=1);

namespace ChannelBridge\Teams;

/**
 * Bot Framework incoming-activity JWT validation (§8.2, the same posture
 * as OpenClaw's Teams bearer lane): every activity POST carries a bearer
 * JWT signed by Microsoft. Validation gates the webhook BEFORE anything
 * is spliced as a prompt: a channel that accepts unsigned activities is a
 * prompt-injection door with a public URL in front of it.
 *
 * Checks, in order: well-formed three-part compact JWT → header alg RS256
 * (or the documented emulator exemption) → kid known → signature verifies
 * against the Microsoft public keys (kid-cached) → iss in the accepted set
 * → aud equals OUR appId (not just any bot's) → nbf/exp clock window.
 *
 * The key-set fetch is an injected callable: production wires
 * {@see self::fetchKeys()} (the OpenID discovery endpoint); the self-check
 * injects a locally-generated RSA JWK set so the FAIL PATHS — expired,
 * foreign audience, unknown kid, bad signature, malformed header — are
 * exercised offline, honestly and deterministically.
 *
 * Pure per call given the key source: same token + same kid set, same verdict.
 */
final class JwtValidator
{
    /** The tenants/domains a Bot Framework token is signed by. */
    public const ACCEPTED_ISSUERS = [
        'https://api.botframework.com',
        'https://sts.windows.net/d6d49420-f39b-4df7-a1dc-d59a935871db/',
        'https://login.microsoftonline.com/d6d49420-f39b-4df7-a1dc-d59a935871db/v2.0',
        'https://botframework.azure.us',
        'https://login.microsoftonline.com/us_gov/organizations/v2.0',
    ];

    public const KEYS_URL = 'https://login.microsoftonline.com/common/discovery/vkeys';

    /** @var array<string, array{pem: string, expires: int}> */
    private array $kidCache = [];

    /** @var \Closure(string): array<string,string> */
    private readonly \Closure $keySource;

    /** @param ?\Closure(string): array<string,string> $keySource kid → PEM map provider */
    public function __construct(
        private readonly string $appId,
        ?\Closure $keySource = null,
        private readonly bool $allowUnsignedEmulator = false,
    ) {
        $this->keySource = $keySource ?? self::fetchKeys(...);
    }

    /**
     * @return array<string,mixed>|null the decoded payload, or null when the
     *         token must not be trusted (the webhook answers 401; nothing
     *         else is said — a reason is logged bridge-side, not returned)
     */
    public function validate(string $jwt): ?array
    {
        $parts = \explode('.', $jwt);
        if (\count($parts) !== 3) {
            return null;
        }
        [$headerB64, $payloadB64, $signatureB64] = $parts;
        $header = self::decodeSegment($headerB64);
        $payload = self::decodeSegment($payloadB64);
        if ($header === null || $payload === null) {
            return null;
        }
        $alg = (string) ($header['alg'] ?? '');
        $kid = isset($header['kid']) ? (string) $header['kid'] : '';
        $signature = self::base64UrlDecode($signatureB64);
        if ($signature === null || $signature === '') {
            return null;
        }

        // The emulator sends unsigned tokens; that door is opt-in per the
        // config and NEVER implicit (§8.2).
        if ($alg === 'none') {
            return $this->allowUnsignedEmulator ? (self::claimsOk($payload, $this->appId) ? $payload : null) : null;
        }
        if ($alg !== 'RS256' || $kid === '') {
            return null;
        }

        $pem = $this->pemFor($kid);
        if ($pem === null) {
            return null;
        }
        $signed = $headerB64 . '.' . $payloadB64;
        if (\openssl_verify($signed, $signature, $pem, \OPENSSL_ALGO_SHA256) !== 1) {
            return null;
        }

        return self::claimsOk($payload, $this->appId) ? $payload : null;
    }

    /** @param array<string,mixed> $payload */
    private static function claimsOk(array $payload, string $appId): bool
    {
        $iss = (string) ($payload['iss'] ?? '');
        if (!\in_array($iss, self::ACCEPTED_ISSUERS, true)) {
            return false;
        }
        $aud = (string) ($payload['aud'] ?? '');
        if ($appId !== '' && $aud !== $appId) {
            return false;
        }
        $now = \time();
        if (isset($payload['exp']) && (int) $payload['exp'] + self::clockLeeway() < $now) {
            return false;
        }
        if (isset($payload['nbf']) && (int) $payload['nbf'] - self::clockLeeway() > $now) {
            return false;
        }

        return true;
    }

    private static function clockLeeway(): int
    {
        return 300;
    }

    /** @return array<string,mixed>|null */
    private static function decodeSegment(string $segment): ?array
    {
        $raw = self::base64UrlDecode($segment);
        if ($raw === null) {
            return null;
        }
        try {
            $decoded = \json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $pad = \strlen($value) % 4;
        if ($pad === 1) {
            return null;
        }
        $decoded = \base64_decode(\strtr($value, '-_', '+/') . \str_repeat('=', (4 - $pad) % 4), true);

        return $decoded === false ? null : $decoded;
    }

    private function pemFor(string $kid): ?string
    {
        $cached = $this->kidCache[$kid] ?? null;
        if ($cached !== null && $cached['expires'] > \time()) {
            return $cached['pem'];
        }
        $keys = ($this->keySource)($kid);
        $pem = $keys[$kid] ?? null;
        if ($pem === null) {
            return null;
        }
        $this->kidCache[$kid] = ['pem' => $pem, 'expires' => \time() + 3600];

        return $pem;
    }

    /**
     * The production key source: fetch the JWKS and turn every key into a
     * PEM, keyed by kid. Microsoft rotates these; the cache holds an hour.
     *
     * @return array<string,string> kid → PEM
     */
    public static function fetchKeys(string $wantedKid): array
    {
        $context = \stream_context_create(['http' => ['timeout' => 10, 'header' => "User-Agent: candy-code-channel-bridge\r\n"]]);
        $raw = @\file_get_contents(self::KEYS_URL, false, $context);
        if ($raw === false) {
            return [];
        }
        try {
            $set = \json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        $pemByKid = [];
        foreach ((array) ($set['keys'] ?? []) as $jwk) {
            if (!\is_array($jwk) || ($jwk['kty'] ?? '') !== 'RSA') {
                continue;
            }
            $kid = (string) ($jwk['kid'] ?? '');
            $pem = self::rsaJwkToPem($jwk);
            if ($kid !== '' && $pem !== null) {
                $pemByKid[$kid] = $pem;
            }
        }

        return $pemByKid;
    }

    /** @param array<string,mixed> $jwk */
    public static function rsaJwkToPem(array $jwk): ?string
    {
        $n = self::base64UrlDecode((string) ($jwk['n'] ?? ''));
        $e = self::base64UrlDecode((string) ($jwk['e'] ?? ''));
        if ($n === null || $e === null) {
            return null;
        }

        $der = self::asn1Sequence(self::asn1Integer($n) . self::asn1Integer($e));
        $spki = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00" . self::asn1BitString($der);
        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . \chunk_split(\base64_encode(self::asn1Sequence($spki)), 64, "\n")
            . '-----END PUBLIC KEY-----';

        return \openssl_pkey_get_public($pem) === false ? null : $pem;
    }

    private static function asn1Length(int $length): string
    {
        if ($length < 0x80) {
            return \chr($length);
        }
        $bytes = '';
        while ($length > 0) {
            $bytes = \chr($length & 0xFF) . $bytes;
            $length >>= 8;
        }

        return \chr(0x80 | \strlen($bytes)) . $bytes;
    }

    private static function asn1Sequence(string $contents): string
    {
        return "\x30" . self::asn1Length(\strlen($contents)) . $contents;
    }

    private static function asn1Integer(string $unsigned): string
    {
        $unsigned = \ltrim($unsigned, "\x00");
        if ($unsigned === '' || \ord($unsigned[0]) > 0x7F) {
            $unsigned = "\x00" . $unsigned;
        }

        return "\x02" . self::asn1Length(\strlen($unsigned)) . $unsigned;
    }

    private static function asn1BitString(string $contents): string
    {
        $payload = "\x00" . $contents;

        return "\x03" . self::asn1Length(\strlen($payload)) . $payload;
    }
}
