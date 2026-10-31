<?php

declare(strict_types=1);

namespace SugarCraft\Code\Server\Push;

/**
 * One Web Push subscription as the browser hands it out
 * (`PushSubscription.toJSON()` under RFC 8030): an endpoint URL, the user
 * agent's P-256 ECDH public key, and the 16-octet authentication secret
 * (RFC 8291 §3.1–§3.2). Both keys ride base64url without padding, exactly
 * as the Web Push JavaScript surface serializes them.
 *
 * The value object is strict about shape and silent about policy: which
 * endpoints may be dialed is {@see WebPushSender}'s allowlist question,
 * because the endpoint is user-agent-supplied data and this class has no
 * business deciding what network it names. Construction still refuses the
 * shapes that could never decrypt anything — a non-https endpoint, a key
 * that is not an uncompressed P-256 point, an auth secret that is not 16
 * octets — so the cipher and the sender never re-litigate them.
 */
final class PushSubscription
{
    /** X9.62 uncompressed P-256 point: 0x04 || x (32) || y (32). */
    public const PUBLIC_KEY_BYTES = 65;

    /** RFC 8291 §3.2: the authentication secret is 16 octets. */
    public const AUTH_SECRET_BYTES = 16;

    private function __construct(
        public readonly string $endpoint,
        public readonly string $p256dh,
        public readonly string $auth,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when any half is not the shape RFC 8030/RFC 8291 define
     */
    public static function new(string $endpoint, string $p256dh, string $auth): self
    {
        $origin = self::endpointRefusal($endpoint);
        if ($origin !== null) {
            throw new \InvalidArgumentException($origin);
        }
        $point = self::decodeBase64Url($p256dh);
        if ($point === null || strlen($point) !== self::PUBLIC_KEY_BYTES || $point[0] !== "\x04") {
            throw new \InvalidArgumentException(
                'the p256dh key must be a base64url-encoded uncompressed P-256 point ('
                . self::PUBLIC_KEY_BYTES . ' octets starting with 0x04)',
            );
        }
        $secret = self::decodeBase64Url($auth);
        if ($secret === null || strlen($secret) !== self::AUTH_SECRET_BYTES) {
            throw new \InvalidArgumentException(
                'the auth secret must be base64url-encoded ' . self::AUTH_SECRET_BYTES . ' octets',
            );
        }

        return new self($endpoint, $p256dh, $auth);
    }

    /** @return null|string a refusal sentence, or null when the endpoint is structurally dialable */
    public static function endpointRefusal(string $endpoint): ?string
    {
        $parts = parse_url($endpoint);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return 'the push endpoint is not a URL';
        }
        if ($parts['scheme'] !== 'https') {
            return 'the push endpoint must be an https URL';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'the push endpoint must not carry credentials';
        }
        if (isset($parts['port']) && $parts['port'] !== 443) {
            return 'the push endpoint must use the default https port';
        }
        $path = $parts['path'] ?? '';
        if ($path !== '' && !preg_match('#^/[A-Za-z0-9\-._~%!$&\'()*+,;=:@\/]*$#', $path)) {
            return 'the push endpoint path carries characters a push service never issues';
        }
        // The query rides requestTarget() into the HTTP request line verbatim,
        // exactly like the path, so it answers to the same pchar law — widened
        // only by '?' itself, the query/pchar boundary octet (RFC 3986 §3.4
        // permits '/' and '?' in a query; parse_url has already defanged any
        // raw control octet to '_', which is pchar-legal and inert).
        $query = $parts['query'] ?? '';
        if ($query !== '' && !preg_match('#^[A-Za-z0-9\-._~%!$&\'()*+,;=:@\/?]*$#', $query)) {
            return 'the push endpoint query carries characters a push service never issues';
        }

        return null;
    }

    /** @return array{endpoint: string, p256dh: string, auth: string} the wire shape stores persist */
    public function toArray(): array
    {
        return ['endpoint' => $this->endpoint, 'p256dh' => $this->p256dh, 'auth' => $this->auth];
    }

    /** The push resource URL's host, lowercased, without any port (443 is forced). */
    public function host(): string
    {
        return strtolower((string) parse_url($this->endpoint, PHP_URL_HOST));
    }

    /**
     * The RFC 6454 origin of the endpoint — the VAPID `aud` claim value per
     * RFC 8292 §2. The port is never included: {@see endpointRefusal} admits
     * only 443, which is https' default and therefore not part of an origin.
     */
    public function origin(): string
    {
        return 'https://' . $this->host();
    }

    /** @return null|string the path+query to carry, defaulting to '/' like RFC 8291 §5's example; both legs were shape-gated by {@see endpointRefusal} at construction */
    public function requestTarget(): ?string
    {
        $path = (string) (parse_url($this->endpoint, PHP_URL_PATH) ?? '');
        $query = parse_url($this->endpoint, PHP_URL_QUERY);
        $target = $path === '' ? '/' : $path;
        if (is_string($query) && $query !== '') {
            $target .= '?' . $query;
        }

        return $target;
    }

    /** @return string the 65-byte uncompressed peer point */
    public function publicKeyBytes(): string
    {
        $point = self::decodeBase64Url($this->p256dh);
        if ($point === null || strlen($point) !== self::PUBLIC_KEY_BYTES) {
            // Unreachable: new() verified the shape and the fields are readonly.
            throw new \LogicException('a subscription never changes shape after construction');
        }

        return $point;
    }

    /** @return string the 16-octet authentication secret */
    public function authSecretBytes(): string
    {
        $secret = self::decodeBase64Url($this->auth);
        if ($secret === null || strlen($secret) !== self::AUTH_SECRET_BYTES) {
            throw new \LogicException('a subscription never changes shape after construction');
        }

        return $secret;
    }

    /**
     * Strict base64url (§5 alphabet, no padding, no whitespace); null when
     * the input is not that. Padding is refused rather than normalized: the
     * Web Push surface never emits it, and accepting it here would let a
     * silently-mangled key reach the cipher.
     */
    private static function decodeBase64Url(string $value): ?string
    {
        if ($value === '' || preg_match('#^[A-Za-z0-9_\-]+$#', $value) !== 1) {
            return null;
        }
        $padded = $value . str_repeat('=', (4 - strlen($value) % 4) % 4);
        $bytes = base64_decode(strtr($padded, '-_', '+/'), true);

        return $bytes === false ? null : $bytes;
    }
}
