<?php

declare(strict_types=1);

/**
 * Pinned-key Ed25519 verification, offline. A release publisher signs the
 * sha256 sidecar body with the private key; the app pins only the public key
 * (base64) in ReleaseSource::withPublicKey(). This example plays both sides with
 * a throwaway keypair so the verdicts are reproducible.
 *
 * Requires ext-sodium. Without it every verdict is false (fail-closed), so the
 * example says so and stops rather than printing misleading results.
 *
 *   php examples/verify-signature.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Releases\Signature;

if (!\extension_loaded('sodium')) {
    echo "ext-sodium is not loaded: every signature is refused (fail-closed).\n";
    exit(0);
}

// Publisher side: sign the sidecar body, the exact bytes the app will check.
$keyPair = \sodium_crypto_sign_keypair();
$publicKey = \base64_encode(\sodium_crypto_sign_publickey($keyPair));
$secretKey = \sodium_crypto_sign_secretkey($keyPair);

$sidecar = \hash('sha256', "phar bytes\n") . "  widget.phar\n";
$signature = \base64_encode(\sodium_crypto_sign_detached($sidecar, $secretKey)) . "\n";

// App side: the pinned public key judges the detached signature.
echo 'genuine sidecar:  ' . var_export(Signature::verifyDetached($sidecar, $signature, $publicKey), true) . "\n";
echo 'tampered sidecar: ' . var_export(
    Signature::verifyDetached(\str_replace('widget.phar', 'evil.phar', $sidecar), $signature, $publicKey),
    true,
) . "\n";

// Another publisher's key does not verify this release.
$otherKey = \base64_encode(\sodium_crypto_sign_publickey(\sodium_crypto_sign_keypair()));
echo 'wrong pinned key: ' . var_export(Signature::verifyDetached($sidecar, $signature, $otherKey), true) . "\n";

// Malformed input refuses instead of throwing.
echo 'empty payload:    ' . var_export(Signature::verifyDetached('', $signature, $publicKey), true) . "\n";
echo 'not base64:       ' . var_export(Signature::verifyDetached($sidecar, '!!!', $publicKey), true) . "\n";
