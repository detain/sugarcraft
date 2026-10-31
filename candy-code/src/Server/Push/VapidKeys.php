<?php

declare(strict_types=1);

namespace SugarCraft\Code\Server\Push;

use SugarCraft\Code\Server\StateDir;
use SugarCraft\Code\Support\PrivateRetainedDir;

/**
 * The server's VAPID signing identity (RFC 8292): one P-256 key pair,
 * created on first use in `<state dir>/vapid.key` (0600 inside the
 * PrivateRetainedDir-verified 0700 state directory — the same discipline as
 * the owner token, because this private key signs the identity every push
 * service accepts) and reused for every later message so the identity is
 * "consistent across multiple messages" as §2 requires.
 *
 * From the pair this class answers only public questions:
 *
 *  - `tokenFor($audience)` — the ES256 JWT. The `k` half of the credential
 *    and the JWT both carry just the PUBLIC key; the signing input is
 *    `base64url(header) . '.' . base64url(claims)` and the signature is the
 *    raw `R || S` (64 octets) form RFC 7515 §4.1.6 requires — OpenSSL
 *    emits DER, so {@see rawSignature()} parses and re-pads it. A DER
 *    signature left in place verifies nowhere.
 *  - `authorizationFor($origin)` — the whole `Authorization` field value,
 *    `vapid t=<jwt>, k=<base64url uncompressed point>` per RFC 8292 §3.
 *
 * The claims are the two §2 mandates plus the optional contact: `aud` is
 * the origin of the push resource URL, `exp` is {@see JWT_LIFETIME_SECONDS}
 * out and can never exceed §2's 24-hour ceiling (the clock is injectable
 * for pins; the bound is asserted, not assumed). The ECDH key the push
 * encrypts with is a FRESH ephemeral pair per message inside
 * {@see PushPayloadCipher} — §3.2 forbids reusing the signing key for the
 * key exchange, and this class never exports a raw key to it anyway.
 *
 * A file that is not a P-256 private key (rotation damage, a hand-edited
 * state dir) is replaced by a fresh pair on the next read, exactly like the
 * owner token: a corrupted identity is not salvageable, and every pushed
 * message with the old key would have been refused regardless.
 */
final class VapidKeys
{
    /** RFC 8292 §2: an `exp` MUST NOT be more than 24 hours from the request. */
    public const JWT_MAX_LIFETIME_SECONDS = 24 * 3600;

    /** Tokens are minted per delivery, so one hour of validity is generous. */
    public const JWT_LIFETIME_SECONDS = 3600;

    private const LABEL = 'server state';

    /** @var string|null the loaded (or just minted) PEM, cached for the instance */
    private ?string $pem = null;

    /** @var (callable(): int)|null */
    private $clock;

    private function __construct(
        private readonly string $dir,
        private readonly ?string $subject = null,
        ?callable $clock = null,
    ) {
        $this->clock = $clock;
    }

    /**
     * @param string $dir the serve state directory (its owner, mode and shape
     *                    are re-verified by {@see PrivateRetainedDir} on every use)
     * @param (callable(): int)|null $clock the epoch clock behind `exp`
     */
    public static function new(string $dir, ?callable $clock = null): self
    {
        return new self($dir, null, $clock);
    }

    /**
     * A clone identifying the server as $contact in the JWT's `sub` claim —
     * a `mailto:` or `https:` URI per RFC 8292 §2.1. RFC 8292bis retired
     * the claim; with no argument this class sends the two mandatory ones.
     */
    public function withSubject(?string $contact): self
    {
        if ($contact !== null && preg_match('#^(mailto:[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+|https://[A-Za-z0-9.\-]+(/[^\s]*)?)$#', $contact) !== 1) {
            throw new \InvalidArgumentException(
                'the VAPID subject must be a mailto: address or an https:// URI (RFC 8292 §2.1)',
            );
        }

        return new self($this->dir, $contact, $this->clock);
    }

    /** @return string the 65-octet X9.62 uncompressed public point */
    public function publicKeyUncompressed(): string
    {
        $details = openssl_pkey_get_details($this->privateKey());
        if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_EC) {
            throw new \RuntimeException('the stored VAPID key did not read back as an EC private key');
        }
        /** @var array{x: string, y: string} $ec */
        $ec = $details['ec'];

        return "\x04" . self::pad32($ec['x']) . self::pad32($ec['y']);
    }

    public function publicKeyBase64Url(): string
    {
        return self::base64Url($this->publicKeyUncompressed());
    }

    /**
     * The signed JWT for one push-service origin.
     *
     * @throws \InvalidArgumentException when $audience is not a bare https origin
     * @throws \RuntimeException when the state directory is unsafe or unwritable
     */
    public function tokenFor(string $audience): string
    {
        if (preg_match('#^https://[A-Za-z0-9.\-]+(:443)?$#', $audience) !== 1) {
            throw new \InvalidArgumentException(
                'the VAPID audience must be the https origin of the push URL (RFC 8292 §2), got: ' . $audience,
            );
        }

        $now = ($this->clock ?? static fn (): int => time())();
        $exp = $now + self::JWT_LIFETIME_SECONDS;
        if ($exp - $now > self::JWT_MAX_LIFETIME_SECONDS || $exp <= $now) {
            // Constant-checked today; the arm stays loud for whoever widens the lifetime.
            throw new \LogicException('the minted VAPID lifetime must stay inside RFC 8292 §2');
        }

        $claims = ['aud' => $audience, 'exp' => $exp];
        if ($this->subject !== null) {
            $claims['sub'] = $this->subject;
        }

        $signingInput = self::base64Url((string) json_encode(
            ['typ' => 'JWT', 'alg' => 'ES256'],
            JSON_UNESCAPED_SLASHES,
        )) . '.' . self::base64Url((string) json_encode($claims, JSON_UNESCAPED_SLASHES));

        $raw = $this->sign($signingInput);

        return $signingInput . '.' . self::base64Url($raw);
    }

    /**
     * The complete `Authorization` field value RFC 8292 §3 defines. The
     * private key never appears anywhere in it; neither does the audience
     * beyond the JWT's own `aud` claim.
     */
    public function authorizationFor(string $audience): string
    {
        return 'vapid t=' . $this->tokenFor($audience) . ', k=' . $this->publicKeyBase64Url();
    }

    /** @return resource the loaded-or-minted private key handle */
    private function privateKey()
    {
        if ($this->pem !== null) {
            $key = openssl_pkey_get_private($this->pem);
        } else {
            $path = $this->dir() . '/' . StateDir::VAPID_KEY_FILE;
            $stored = @\lstat($path) !== false && \is_file($path) && !\is_link($path)
                ? (string) @\file_get_contents($path)
                : '';
            $key = openssl_pkey_get_private($stored);
            if ($key !== false && !$this->isP256($key)) {
                $key = false;
            }
            if ($key === false) {
                $key = $this->mint();
            }
            $privatePem = null;
            if (openssl_pkey_export($key, $privatePem) !== true || !is_string($privatePem)) {
                throw new \RuntimeException('the VAPID key pair could not be exported for caching');
            }
            $this->pem = $privatePem;
        }
        if ($key === false) {
            throw new \RuntimeException('the stored VAPID key could not be read as a P-256 private key');
        }

        return $key;
    }

    /** @return resource a freshly created pair, stored 0600 before it is returned */
    private function mint()
    {
        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        if ($key === false) {
            throw new \RuntimeException('OpenSSL could not create the VAPID P-256 key pair');
        }
        openssl_pkey_export($key, $pem);
        PrivateRetainedDir::write($this->dir(), StateDir::VAPID_KEY_FILE, $pem . "\n", self::LABEL);

        return $key;
    }

    private function isP256($key): bool
    {
        $details = openssl_pkey_get_details($key);

        return $details !== false
            && $details['type'] === OPENSSL_KEYTYPE_EC
            && (int) ($details['bits'] ?? 0) === 256
            // Bit width alone would adopt a 256-bit curve that is not P-256
            // (secp256k1, brainpoolP256r1); only prime256v1 signs ES256.
            && ($details['ec']['curve_name'] ?? '') === 'prime256v1';
    }

    private function dir(): string
    {
        return PrivateRetainedDir::verified($this->dir, self::LABEL);
    }

    /** @return string the 64-octet raw R||S signature RFC 7515 §4.1.6 requires */
    private function sign(string $input): string
    {
        $der = '';
        if (!openssl_sign($input, $der, $this->privateKey(), OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('the VAPID JWT could not be signed');
        }

        return self::rawSignature($der);
    }

    /**
     * ECDSA-to-JWS: the DER `SEQUENCE { INTEGER r, INTEGER s }` becomes the
     * fixed-width `R || S` pair (each left-padded to 32 octets). Malformed
     * or over-wide output is a refusal, never a truncation — a quietly
     * shortened r or s would forge an unverifiable token, not a valid one.
     */
    private static function rawSignature(string $der): string
    {
        $offset = 0;
        $expect = static function (int $byte) use ($der, &$offset): void {
            if ($offset >= strlen($der) || ord($der[$offset]) !== $byte) {
                throw new \RuntimeException('the ECDSA signature did not parse as the DER SEQUENCE OpenSSL documents');
            }
            ++$offset;
        };
        $length = static function () use ($der, &$offset): int {
            if ($offset >= strlen($der)) {
                throw new \RuntimeException('the ECDSA signature ended inside its own length');
            }
            $first = ord($der[$offset++]);
            if ($first < 0x80) {
                return $first;
            }
            if ($first !== 0x81 || $offset >= strlen($der)) {
                throw new \RuntimeException('the ECDSA signature used a long-form length ES256 never produces');
            }

            return ord($der[$offset++]);
        };
        $integer = static function () use ($der, &$offset, $expect, $length): string {
            $expect(0x02);
            $span = $length();
            if ($span < 1 || $offset + $span > strlen($der)) {
                throw new \RuntimeException('an ECDSA INTEGER ran past the end of the DER signature');
            }
            $value = substr($der, $offset, $span);
            $offset += $span;
            $trimmed = ltrim($value, "\x00");
            // Only canonical minimal encodings are DER: a non-zero high bit
            // needs the 0x00 sign byte, and padding beyond one octet is not.
            if ($trimmed === '' ? $value !== "\x00" : (strlen($value) - strlen($trimmed) > 1)) {
                throw new \RuntimeException('the ECDSA signature carried non-canonical INTEGER padding');
            }
            if (strlen($trimmed) > 32) {
                throw new \RuntimeException('the ECDSA signature is wider than P-256 can produce');
            }

            return str_pad($trimmed, 32, "\x00", STR_PAD_LEFT);
        };

        $expect(0x30);
        $total = $length();
        if ($offset + $total !== strlen($der)) {
            throw new \RuntimeException('the ECDSA signature did not fill its DER SEQUENCE');
        }
        $r = $integer();
        $s = $integer();
        if ($offset !== strlen($der)) {
            throw new \RuntimeException('the ECDSA DER signature had trailing bytes');
        }

        return $r . $s;
    }

    private static function pad32(string $bytes): string
    {
        if (strlen($bytes) > 32) {
            throw new \RuntimeException('an EC coordinate came back wider than P-256');
        }

        return str_pad($bytes, 32, "\x00", STR_PAD_LEFT);
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
