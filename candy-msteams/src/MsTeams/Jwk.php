<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\MsTeams;

/**
 * Convert a Bot Framework signing key between wire forms.
 *
 * Microsoft publishes the roster as JWKS (`https://login.botframework.com/
 * v1/.well-known/keys`, RSA/RS256); PHP's openssl_verify wants PEM. The
 * conversion is DER from scratch on purpose: the components (modulus n,
 * exponent e) are base64url integers, and hand-rolling the two SEQUENCEs
 * keeps the bundle dependency-free (a marketplace bundle cannot pull a
 * crypto library through the host vendor).
 *
 * Parses only what the roster contains: kty RSA. Anything else refuses.
 */
final class Jwk
{
    /** @return string PEM public key */
    public static function toPem(array $jwk): string
    {
        if ((string) ($jwk['kty'] ?? '') !== 'RSA') {
            throw new JwtException('unsupported signing key type: ' . (string) ($jwk['kty'] ?? '?'));
        }
        $n = self::b64int((string) ($jwk['n'] ?? ''));
        $e = self::b64int((string) ($jwk['e'] ?? ''));
        if ($n === '' || $e === '') {
            throw new JwtException('RSA signing key without modulus/exponent');
        }

        $algorithm = self::der(self::ASN_SEQUENCE, self::oid('1.2.840.113549.1.1.1') . self::der(self::ASN_NULL, ''));
        $bitString = self::der(self::ASN_BITSTRING, "\x00" . self::der(self::ASN_SEQUENCE, self::integer($n) . self::integer($e)));

        return self::toBase64Pem(self::der(self::ASN_SEQUENCE, $algorithm . $bitString), 'PUBLIC KEY');
    }

    /**
     * Build a JWKS-shaped array from a locally generated key (test fixture
     * material: an openssl key becomes the roster entry the validator will
     * be told to trust).
     *
     * @param \OpenSSLAsymmetricKey|string $publicKey
     *
     * @return array<string,mixed>
     */
    public static function fromPublicKey($publicKey, string $kid): array
    {
        $details = \openssl_pkey_get_details($publicKey);
        if ($details === false || ($details['type'] ?? -1) !== OPENSSL_KEYTYPE_RSA) {
            throw new JwtException('fixture key is not RSA');
        }

        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $kid,
            'n' => self::b64urlEncode($details['rsa']['n']),
            'e' => self::b64urlEncode($details['rsa']['e']),
        ];
    }

    /** The DER tag for a SEQUENCE. */
    private const ASN_SEQUENCE = "\x30";
    private const ASN_NULL = "\x05";
    private const ASN_OID = "\x06";
    private const ASN_BITSTRING = "\x03";
    private const ASN_INTEGER = "\x02";

    private static function der(string $tag, string $body): string
    {
        $length = strlen($body);
        if ($length < 0x80) {
            return $tag . chr($length) . $body;
        }
        if ($length <= 0xff) {
            return $tag . "\x81" . chr($length) . $body;
        }

        return $tag . "\x82" . pack('n', $length) . $body;
    }

    private static function oid(string $dotted): string
    {
        $parts = \explode('.', $dotted);
        $bytes = chr((int) $parts[0] * 40 + (int) $parts[1]);
        foreach (\array_slice($parts, 2) as $part) {
            $value = (int) $part;
            if ($value < 0x80) {
                $bytes .= chr($value);
                continue;
            }
            $chunk = '';
            while ($value > 0) {
                $chunk = chr(($value & 0x7f) | ($chunk === '' ? 0 : 0x80)) . $chunk;
                $value >>= 7;
            }
            $bytes .= $chunk;
        }

        return self::der(self::ASN_OID, $bytes);
    }

    private static function integer(string $unsignedBytes): string
    {
        $bytes = \ltrim($unsignedBytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        if ((\ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }

        return self::der(self::ASN_INTEGER, $bytes);
    }

    private static function b64int(string $encoded): string
    {
        $padded = $encoded . \str_repeat('=', (4 - \strlen($encoded) % 4) % 4);

        return \base64_decode(\strtr($padded, '-_', '+/'), true) ?: '';
    }

    private static function b64urlEncode(string $bytes): string
    {
        return \rtrim(\strtr(\base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function toBase64Pem(string $der, string $label): string
    {
        return "-----BEGIN {$label}-----\n" . \chunk_split(\base64_encode($der), 64, "\n") . "-----END {$label}-----\n";
    }
}
