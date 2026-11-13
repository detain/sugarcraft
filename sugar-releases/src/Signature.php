<?php

declare(strict_types=1);

namespace SugarCraft\Releases;

/**
 * Pure Ed25519 detached-signature check for release assets.
 */
final class Signature
{
    private function __construct()
    {
    }

    /**
     * Is $signatureBody the base64 of a signature by $publicKeyBase64 over exactly
     * $payload? Every malformed shape — empty payload, non-strict base64,
     * wrong-length raw halves, a missing sodium extension — answers FALSE
     * (refuse), never an exception and never a trust. Surrounding whitespace is
     * tolerated in both base64 slots because release-asset bodies carry trailing
     * newlines.
     */
    public static function verifyDetached(string $payload, string $signatureBody, string $publicKeyBase64): bool
    {
        // Guard FIRST: the SODIUM_CRYPTO_SIGN_* constants only exist when the
        // extension does, and an extension-less PHP must refuse, not fatal.
        if ($payload === '' || !\function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }
        $signature = \base64_decode(\trim($signatureBody), true);
        $publicKey = \base64_decode(\trim($publicKeyBase64), true);
        if (
            $signature === false
            || $publicKey === false
            || \strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || \strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
        ) {
            return false;
        }

        try {
            return \sodium_crypto_sign_verify_detached($signature, $payload, $publicKey);
        } catch (\Throwable) {
            return false;
        }
    }
}
