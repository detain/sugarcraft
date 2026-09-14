<?php

declare(strict_types=1);

namespace SugarCraft\Crush\MCP;

/**
 * RFC 7636 Proof Key for Code Exchange (PKCE) primitives for the E701
 * authorization-code login.
 *
 * The verifier is high-entropy randomness only this process ever sees; the
 * challenge is its SHA-256 digest in the base64url alphabet, safe to put in
 * the authorization URL that leaves through the user's browser. The server
 * can verify possession of the verifier at exchange time without anyone
 * being able to replay a captured authorization code — the code alone is
 * worthless without the verifier that produced the challenge.
 *
 * S256 only, deliberately. The `plain` method sends the verifier itself as
 * the challenge and survives only as a spec-compatibility fallback for
 * servers that cannot hash; every endpoint this flow targets is required by
 * the MCP authorization spec to support S256, so offering `plain` here would
 * be a downgrade path, not a capability.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7636
 */
final class OAuthPkce
{
    /** Verifier entropy in bytes — RFC 7636 §4.1 allows 43..128 characters; 32 bytes base64url to exactly 43. */
    private const VERIFIER_BYTES = 32;

    /** The one challenge method this client ever sends. */
    public const METHOD = 'S256';

    /**
     * Generate a fresh code verifier (RFC 7636 §4.1): 32 random bytes in the
     * base64url unreserved alphabet, 43 characters, no padding.
     */
    public static function newVerifier(): string
    {
        return self::base64Url(random_bytes(self::VERIFIER_BYTES));
    }

    /**
     * The S256 challenge for a verifier (RFC 7636 §4.2): the base64url of the
     * raw SHA-256 digest. Same input, same output — a pure function, so the
     * authorize URL and the exchange can each derive it without either
     * trusting a stored copy.
     */
    public static function challengeFor(string $verifier): string
    {
        return self::base64Url(hash('sha256', $verifier, true));
    }

    /**
     * base64url per RFC 4648 §5: the URL- and filename-safe alphabet, `=`
     * padding stripped (RFC 7636 Appendix A).
     */
    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
