<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\MsTeams;

/**
 * Bot Framework token validation — the ENTIRE §8.1 trust door, terminated
 * inside this bundle: the candy-code router that mounts `/channels/` never
 * speaks Bot Framework, so an unverified activity would otherwise reach
 * policy code with a typed string on its forehead.
 *
 * The acceptance law, claim by claim:
 *  - compact JWS, exactly three base64url segments, `alg` RS256 and nothing
 *    else (a `none` or HS256 header is REFUSED before any crypto — the
 *    classic confusion attack wants exactly this door);
 *  - `iss` is one of the Bot Framework issuers;
 *  - `aud` equals THIS bundle's configured appId — a token minted for some
 *    other bot is not ours, full stop;
 *  - `nbf`/`exp` with a 60-second leeway;
 *  - the `kid` resolves to a Microsoft signing key, and openssl_verify
 *    passes over the raw header.payload bytes.
 *
 * The key roster arrives through an injected provider closure so the whole
 * matrix tests offline against fixture RSA keys: no network in any test,
 * ever. Production wires the closure to the published JWKS (refreshing at
 * most every five minutes when an unknown kid shows up — a kid we cannot
 * resolve is a refusal, never a fetch storm).
 */
final class Jwt
{
    public const ISSUERS = ['https://api.botframework.com', 'https://botframework.com'];
    public const CLOCK_LEEWAY_SECONDS = 60;
    public const ROSTER_REFRESH_SECONDS = 300;

    /** @var \Closure(): array<string,string> kid => PEM */
    private readonly \Closure $roster;

    /** @var array<string,string>|null */
    private ?array $keys = null;

    private float $lastFetchAt = 0.0;

    /** @param \Closure(): array<string,string> $roster kid => PEM public keys */
    public function __construct(
        private readonly string $appId,
        \Closure $roster,
    ) {
        $this->roster = $roster;
    }

    /**
     * Verify and return the claims. Throws {@see JwtException} naming the
     * failed claim (or crypto step) on ANY refusal — callers never see a
     * half-believed array.
     *
     * @return array<string,mixed>
     */
    public function validate(string $compact, ?int $now = null): array
    {
        if ($this->appId === '') {
            throw new JwtException('no configured application id to audit against', 'no_aud');
        }
        $now ??= \time();

        $segments = \explode('.', $compact);
        if (\count($segments) !== 3 || $segments[0] === '' || $segments[1] === '') {
            throw new JwtException('token is not a compact JWS', 'malformed');
        }
        [$headerB64, $claimsB64, $signatureB64] = $segments;

        $header = self::decodeSegment($headerB64, 'header');
        if (($header['alg'] ?? '') !== 'RS256') {
            throw new JwtException('alg ' . \json_encode($header['alg'] ?? null) . ' is not RS256', 'alg');
        }
        $kid = (string) ($header['kid'] ?? '');
        if ($kid === '') {
            throw new JwtException('no kid in the token header', 'kid');
        }

        $claims = self::decodeSegment($claimsB64, 'claims');

        // Signature FIRST, claims second: a token not authenticated by
        // Microsoft's keys must never be allowed to probe which audiences
        // or windows this bot accepts.
        $pem = $this->keyFor($kid);
        $signature = self::b64urlDecode($signatureB64);
        if ($signature === '') {
            throw new JwtException('empty signature segment', 'sig');
        }
        $verified = @\openssl_verify(
            $headerB64 . '.' . $claimsB64,
            $signature,
            $pem,
            \OPENSSL_ALGO_SHA256,
        );
        if ($verified !== 1) {
            throw new JwtException('signature does not verify under the roster key', 'sig');
        }

        if (!\in_array((string) ($claims['iss'] ?? ''), self::ISSUERS, true)) {
            throw new JwtException('issuer ' . \json_encode($claims['iss'] ?? null) . ' is not a Bot Framework issuer', 'iss');
        }
        if ((string) ($claims['aud'] ?? '') !== $this->appId) {
            throw new JwtException('audience is not this application', 'aud');
        }
        if (isset($claims['nbf']) && (int) $claims['nbf'] > $now + self::CLOCK_LEEWAY_SECONDS) {
            throw new JwtException('token is not yet valid', 'nbf');
        }
        if (isset($claims['exp']) && (int) $claims['exp'] < $now - self::CLOCK_LEEWAY_SECONDS) {
            throw new JwtException('token has expired', 'exp');
        }

        return $claims;
    }

    /** @return array<string,mixed> */
    private static function decodeSegment(string $encoded, string $which): array
    {
        $raw = self::b64urlDecode($encoded);
        $decoded = \json_decode($raw, true);
        if (!\is_array($decoded)) {
            throw new JwtException("token {$which} is not a JSON object", 'malformed');
        }

        return $decoded;
    }

    /** kid → PEM, through the cache + refresh throttle; unknown kid refuses. */
    private function keyFor(string $kid): string
    {
        $stale = $this->keys === null
            || (\microtime(true) - $this->lastFetchAt) > self::ROSTER_REFRESH_SECONDS;
        if ($this->keys === null || !isset($this->keys[$kid])) {
            if (!$stale && $this->keys !== null) {
                throw new JwtException('unknown token kid ' . $kid, 'kid');
            }
            $fetched = ($this->roster)();
            if (!\is_array($fetched) || $fetched === []) {
                throw new JwtException('signing-key roster unavailable', 'roster');
            }
            $this->keys = $fetched;
            $this->lastFetchAt = \microtime(true);
            if (!isset($this->keys[$kid])) {
                throw new JwtException('unknown token kid ' . $kid, 'kid');
            }
        }

        return $this->keys[$kid];
    }

    private static function b64urlDecode(string $encoded): string
    {
        $padded = $encoded . \str_repeat('=', (4 - \strlen($encoded) % 4) % 4);

        return \base64_decode(\strtr($padded, '-_', '+/'), true) ?: '';
    }
}
