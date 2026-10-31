<?php

declare(strict_types=1);

namespace SugarCraft\Code\Server\Push;

/**
 * The RFC 8291 `aes128gcm` payload encoder — implemented in-tree against
 * the specification's own arithmetic, with ext-openssl primitives only
 * (`openssl_pkey_derive`, `hash_hkdf`, `openssl_encrypt`), no new
 * dependency. Every constant below is a spec line, not a preference:
 *
 *  - The header is RFC 8188 §2.1 verbatim: `salt (16) || rs (4, network
 *    order) || idlen (1) || keyid (idlen)`. Push's keyid is the sender's
 *    ephemeral public point in X9.62 form (RFC 8291 §4), so the header is
 *    always 86 octets.
 *  - Key derivation is RFC 8291 §3.4 end to end. `hash_hkdf` runs each
 *    documented HKDF extract-then-expand pair — first combining the ECDH
 *    secret with the subscription's auth secret via
 *    `key_info = "WebPush: info" || 0x00 || ua_public || as_public`, then
 *    deriving CEK and NONCE under the header salt with the two documented
 *    info strings. The single record means the §2.3 XOR with the sequence
 *    number is by construction a no-op (seq 0), which §3.4's own note
 *    spells out.
 *  - The record is RFC 8188 §2: the padding delimiter 0x02 (last record,
 *    RFC 8291 §4 pins exactly one), zero-length AAD, and the 16-octet
 *    GCM tag appended.
 *  - rs is 4096 (RFC 8291 §5's header) and plaintext is capped at 3993 —
 *    §4's maximum that survives a push service's 4096-octet body limit
 *    once the 86-octet header, the mandatory delimiter and the tag are
 *    paid for. A message that would not fit is refused here rather than
 *    truncated or split, because §4 forbids multi-record push.
 *
 * RFC 8291 §3.1 demands a FRESH ECDH pair per message and RFC 8188 §2.1 a
 * fresh salt, so both are generated inside every {@see encrypt()} call in
 * production ({@see new()}); {@see withEntropy()} exists so the RFC's own
 * worked example can be pinned byte-exact — no production path uses it.
 */
final class PushPayloadCipher
{
    /** RFC 8291 §4/§5: the single record size written into the header. */
    public const RECORD_SIZE = 4096;

    /**
     * RFC 8291 §4: a push service need not accept a body over 4096 octets;
     * 4096 − 86 header − 16 tag − 1 delimiter = 3993 octets of plaintext.
     */
    public const MAX_PLAINTEXT_BYTES = 4096 - 86 - 16 - 1;

    /** RFC 8291 §3.4 info strings — the §8188 note: no NUL terminator is added. */
    private const KEY_INFO_PREFIX = 'WebPush: info' . "\x00";

    private const CEK_INFO = 'Content-Encoding: aes128gcm' . "\x00";

    private const NONCE_INFO = 'Content-Encoding: nonce' . "\x00";

    /** RFC 8188 §2: X9.62 id-prefix of a P-256 SPKI for a 65-octet uncompressed point. */
    private const SPKI_P256_PREFIX_HEX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    private function __construct(
        private readonly \Closure $saltFactory,
        private readonly \Closure $localKeyFactory,
    ) {
    }

    /** The production cipher: random salt and a fresh ECDH pair per message. */
    public static function new(): self
    {
        return new self(
            static fn (): string => random_bytes(16),
            static function (): string {
                $key = openssl_pkey_new([
                    'curve_name' => 'prime256v1',
                    'private_key_type' => OPENSSL_KEYTYPE_EC,
                ]);
                if ($key === false) {
                    throw new \RuntimeException('OpenSSL could not create the ephemeral push ECDH pair');
                }
                openssl_pkey_export($key, $pem);

                return $pem;
            },
        );
    }

    /**
     * A cipher whose randomness is supplied: the two factories answer the
     * 16-octet salt and a local P-256 private-key PEM for each message.
     * This exists so {@see self::encrypt()} can be pinned against the RFC
     * 8291 Appendix A worked example; production calls {@see new()}.
     *
     * @param callable(): string $saltFactory
     * @param callable(): string $localKeyPemFactory
     */
    public function withEntropy(callable $saltFactory, callable $localKeyPemFactory): self
    {
        return new self(
            \Closure::fromCallable($saltFactory),
            \Closure::fromCallable($localKeyPemFactory),
        );
    }

    /**
     * The encrypted `application/octet-stream` body — header and single
     * record, ready to POST — for one subscription's message.
     *
     * @throws \InvalidArgumentException when the plaintext would not fit RFC 8291 §4
     * @throws \RuntimeException when the subscription's key or OpenSSL itself refuses
     */
    public function encrypt(string $plaintext, PushSubscription $subscription): string
    {
        if (strlen($plaintext) > self::MAX_PLAINTEXT_BYTES) {
            throw new \InvalidArgumentException(sprintf(
                'a push message carries at most %d octets of plaintext (RFC 8291 §4), got %d',
                self::MAX_PLAINTEXT_BYTES,
                strlen($plaintext),
            ));
        }

        $salt = ($this->saltFactory)();
        if (strlen($salt) !== 16) {
            throw new \LogicException('the salt factory did not answer with 16 octets');
        }
        $localPem = ($this->localKeyFactory)();
        $local = openssl_pkey_get_private($localPem);
        if ($local === false) {
            throw new \RuntimeException('the local push ECDH key could not be read as a private key');
        }

        $uaPublic = self::peerKey($subscription->publicKeyBytes());
        $shared = openssl_pkey_derive($uaPublic, $local);
        if ($shared === false) {
            throw new \RuntimeException('the ECDH secret against the subscription key could not be derived');
        }
        $details = openssl_pkey_get_details($local);
        if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC) {
            throw new \RuntimeException('the local push key is not an EC pair');
        }
        /** @var array{x: string, y: string} $ec */
        $ec = $details['ec'];
        $asPublic = "\x04" . self::pad32($ec['x']) . self::pad32($ec['y']);
        $uaPublicBytes = $subscription->publicKeyBytes();

        // RFC 8291 §3.4, one HKDF (extract+expand) per step, L values as written.
        $ikm = hash_hkdf(
            'sha256',
            $shared,
            32,
            self::KEY_INFO_PREFIX . $uaPublicBytes . $asPublic,
            $subscription->authSecretBytes(),
        );
        $cek = hash_hkdf('sha256', $ikm, 16, self::CEK_INFO, $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, self::NONCE_INFO, $salt);

        $record = openssl_encrypt(
            $plaintext . "\x02",
            'aes-128-gcm',
            $cek,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            16,
        );
        if ($record === false) {
            throw new \RuntimeException('aes-128-gcm refused to encrypt the push record');
        }

        return $salt . pack('N', self::RECORD_SIZE) . chr(strlen($asPublic)) . $asPublic . $record . $tag;
    }

    /** @return resource the subscription's raw point as an EC public key */
    private static function peerKey(string $uncompressedPoint)
    {
        $der = hex2bin(self::SPKI_P256_PREFIX_HEX) . $uncompressedPoint;
        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . '-----END PUBLIC KEY-----';
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new \RuntimeException('the subscription public key is not a usable P-256 point');
        }

        return $key;
    }

    private static function pad32(string $bytes): string
    {
        if (strlen($bytes) > 32) {
            throw new \RuntimeException('an EC coordinate came back wider than P-256');
        }

        return str_pad($bytes, 32, "\x00", STR_PAD_LEFT);
    }
}
