<?php

declare(strict_types=1);

namespace SugarCraft\Code\Automation;

/**
 * The signature pair every outbound delivery carries:
 *
 * - `X-Candy-Code-Signature: sha256=<hex>` — an HMAC-SHA256 over the
 *   canonical signed payload {@see SIGNED_PAYLOAD_TEMPLATE} rendered with
 *   the unix timestamp and the exact request body, keyed by the endpoint's
 *   configured secret;
 * - `X-Candy-Code-Timestamp: <unix seconds>` — the same integer the
 *   signature was computed over, so a receiver can reject a captured
 *   delivery once it is too old to replay.
 *
 * WHY THE TIMESTAMP RIDES THE HMAC INSTEAD OF SITTING BESIDE IT: a signature
 * over the body alone is permanent — one byte-for-byte copy of a logged
 * request (a proxy access log, an error dump) is a valid delivery forever.
 * Binding the timestamp into the signed message makes every signature expire,
 * which is the same reasoning GitHub documents for its
 * `X-Hub-Signature-256` companion header; the receiver-side comparison is a
 * `hash_equals` over the re-rendered template, so the check is constant-time
 * by construction and this class never hand-rolls one.
 *
 * The algorithm is fixed to `sha256`, the only scheme the receiver recipe in
 * the (later) docs will describe. Nothing here is configurable: a signer
 * with a switchable scheme is a signer someone can downgrade.
 */
final class WebhookSigner
{
    /** Header carrying the signature. Sent verbatim; PSR-7 canonicalises case. */
    public const SIGNATURE_HEADER = 'X-Candy-Code-Signature';

    /** Header carrying the unix seconds the signature was computed over. */
    public const TIMESTAMP_HEADER = 'X-Candy-Code-Timestamp';

    /** The only algorithm this scheme names. A receiver recomputes with it. */
    public const SCHEME = 'sha256';

    /**
     * The exact message the HMAC covers: the decimal timestamp, one dot, the
     * raw body. Dotted so a boundary cannot be forged by shifting bytes
     * between the two parts.
     */
    public const SIGNED_PAYLOAD_TEMPLATE = '%d.%s';

    private function __construct()
    {
    }

    /**
     * The full header value (`sha256=<lowercase hex>`) for one body at one
     * instant. Deterministic — the test suite pins it against a known vector.
     */
    public static function signature(string $body, string $secret, int $timestamp): string
    {
        return self::SCHEME . '=' . \hash_hmac(
            self::SCHEME,
            \sprintf(self::SIGNED_PAYLOAD_TEMPLATE, $timestamp, $body),
            $secret,
        );
    }

    /**
     * The two headers to merge into one delivery. `$timestamp` defaults to
     * `time()`; every production call path may pass it explicitly so a test
     * pins the pair without waiting on a wall-clock boundary.
     *
     * @return array<string,string>
     */
    public static function headers(string $body, string $secret, ?int $timestamp = null): array
    {
        $seconds = $timestamp ?? \time();

        return [
            self::SIGNATURE_HEADER => self::signature($body, $secret, $seconds),
            self::TIMESTAMP_HEADER => (string) $seconds,
        ];
    }
}
