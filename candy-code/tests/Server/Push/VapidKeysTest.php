<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server\Push;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Server\Push\VapidKeys;
use SugarCraft\Code\Server\StateDir;

/**
 * RFC 8292 VAPID key handling: one P-256 pair minted once into the serve
 * state dir at 0600, reloaded thereafter, and the ES256 assertion it signs.
 *
 * The signature pin that matters is the RFC 7515 one: the JWT signature is
 * the raw 64-byte R||S value, not OpenSSL's DER — a naive pass-through would
 * interop-fail every push service, so the segment length and a verify leg
 * through a rebuilt DER both have to hold.
 */
final class VapidKeysTest extends TestCase
{
    /** The spec example's clock (RFC 8292 §3 uses a fixed instant for its token). */
    private const CLOCK = 1730000000;

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/serve-' . bin2hex(random_bytes(4)) . '/pushkeys';
        mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testThePairIsMintedOnceAndReloadedAfterwards(): void
    {
        $first = VapidKeys::new($this->dir);
        $public = $first->publicKeyBase64Url();

        self::assertFileExists($this->dir . '/' . StateDir::VAPID_KEY_FILE);

        $second = VapidKeys::new($this->dir);
        self::assertSame($public, $second->publicKeyBase64Url(), 'the key must be made once, not per launch');
        self::assertSame(65, strlen($second->publicKeyUncompressed()));
        self::assertSame("\x04", $second->publicKeyUncompressed()[0]);
    }

    public function testTheKeyFileIsOwnerReadWriteOnly(): void
    {
        VapidKeys::new($this->dir)->publicKeyBase64Url();

        self::assertSame(0o600, fileperms($this->dir . '/' . StateDir::VAPID_KEY_FILE) & 0o777,
            'the private half must never be readable by anyone but the owner');
    }

    public function testTheTokenHeaderIsTheCompactEs256Sequence(): void
    {
        $segments = explode('.', VapidKeys::new($this->dir)->tokenFor('https://updates.push.services.mozilla.com'));

        self::assertCount(3, $segments);
        self::assertSame('eyJ0eXAiOiJKV1QiLCJhbGciOiJFUzI1NiJ9', $segments[0],
            'RFC 8292 §2.4 pins the header bytes {"typ":"JWT","alg":"ES256"}');
    }

    public function testTheClaimsCarryTheOriginAudienceAndABoundedExpiry(): void
    {
        $token = VapidKeys::new($this->dir, fn(): int => self::CLOCK)->tokenFor('https://api.push.apple.com');
        $claims = json_decode($this->decodeSegment(explode('.', $token)[1]), true);

        self::assertSame('https://api.push.apple.com', $claims['aud']);
        self::assertSame(self::CLOCK + VapidKeys::JWT_LIFETIME_SECONDS, $claims['exp']);
        self::assertArrayNotHasKey('sub', $claims);
        self::assertLessThanOrEqual(VapidKeys::JWT_MAX_LIFETIME_SECONDS, $claims['exp'] - self::CLOCK,
            'RFC 8292: exp MUST NOT be more than 24 hours from now');
    }

    public function testTheSignatureIsSixtyFourRawBytesAndVerifiesUnderThePublicHalf(): void
    {
        $keys = VapidKeys::new($this->dir);
        [$header, $claims, $signature] = explode('.', $keys->tokenFor('https://fcm.googleapis.com'));
        $raw = $this->decodeSegment($signature);

        self::assertSame(64, strlen($raw), 'RFC 7515: ES256 signs with raw R||S, not DER');

        $point = $keys->publicKeyUncompressed();
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
        $publicPem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . '-----END PUBLIC KEY-----';
        $public = openssl_pkey_get_public($publicPem);
        self::assertNotFalse($public);

        self::assertSame(1, openssl_verify($header . '.' . $claims, $this->derFromRawSignature($raw), $public, 'sha256'),
            'the raw signature must verify as the DER pair it encodes');
    }

    public function testTheAuthorizationHeaderCarriesTheBearerTokenAndPublicPoint(): void
    {
        $keys = VapidKeys::new($this->dir);
        $auth = $keys->authorizationFor('https://push.services.mozilla.com');

        self::assertMatchesRegularExpression(
            '#^vapid t=[A-Za-z0-9_-]+\.eyJ[A-Za-z0-9_-]+\.([A-Za-z0-9_-]{86}), k=([A-Za-z0-9_-]{87})$#',
            $auth,
            'RFC 8292 §2.2 scheme syntax; 64 raw signature bytes are 86 base64url characters'
        );

        $point = $this->decodeSegment(explode(', k=', $auth)[1]);
        self::assertSame(65, strlen($point));
        self::assertSame($keys->publicKeyUncompressed(), $point, 'k is the public half that verifies t');
    }

    public function testOnlyTheHttpsOriginMayBeAudience(): void
    {
        $keys = VapidKeys::new($this->dir);

        foreach ([
            'http://fcm.googleapis.com',
            'https://fcm.googleapis.com/wpush/v2/x',
            'https://fcm.googleapis.com:8443',
            'fcm.googleapis.com',
        ] as $notOrigin) {
            try {
                $keys->tokenFor($notOrigin);
                self::fail("audience `$notOrigin` must be refused before signing");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testTheOptionalSubjectRidesTheClaimsAndThePairStaysImmutable(): void
    {
        $plain = VapidKeys::new($this->dir, fn(): int => self::CLOCK);
        $withMail = $plain->withSubject('mailto:admin@example.com');

        $claimsPlain = json_decode($this->decodeSegment(explode('.', $plain->tokenFor('https://fcm.googleapis.com'))[1]), true);
        $claimsMail = json_decode($this->decodeSegment(explode('.', $withMail->tokenFor('https://fcm.googleapis.com'))[1]), true);

        self::assertArrayNotHasKey('sub', $claimsPlain);
        self::assertSame('mailto:admin@example.com', $claimsMail['sub']);

        $withUrl = $plain->withSubject('https://example.com/contact');
        $claimsUrl = json_decode($this->decodeSegment(explode('.', $withUrl->tokenFor('https://fcm.googleapis.com'))[1]), true);
        self::assertSame('https://example.com/contact', $claimsUrl['sub']);
    }

    public function testANonContactSubjectIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VapidKeys::new($this->dir)->withSubject('ftp://admin@example.com');
    }

    public function testACorruptKeyFileIsReplacedByAFreshMintNotAFatal(): void
    {
        $before = VapidKeys::new($this->dir)->publicKeyBase64Url();

        $path = $this->dir . '/' . StateDir::VAPID_KEY_FILE;
        file_put_contents($path, 'this is not a PEM at all');

        $after = VapidKeys::new($this->dir)->publicKeyBase64Url();
        self::assertNotSame($before, $after);
        self::assertSame(0o600, fileperms($path) & 0o777, 'the replacement was written through the private writer');
    }

    public function testAStoredKeyOnTheWrongCurveIsReplacedNotUsed(): void
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($resource);
        openssl_pkey_export($resource, $rsaPem);

        $path = $this->dir . '/' . StateDir::VAPID_KEY_FILE;
        file_put_contents($path, $rsaPem);
        chmod($path, 0o600);

        $keys = VapidKeys::new($this->dir);
        self::assertSame(65, strlen($keys->publicKeyUncompressed()), 'a re-minted P-256 pair answers instead');
        self::assertStringContainsString('BEGIN PRIVATE KEY', (string) file_get_contents($path));
    }

    public function testAStoredEcKeyOnAnotherCurveIsReplacedNotUsed(): void
    {
        // Same key type, wrong curve: only the bit-width door tells these apart.
        $resource = openssl_pkey_new([
            'curve_name' => 'secp384r1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        self::assertNotFalse($resource);
        openssl_pkey_export($resource, $p384Pem);

        $path = $this->dir . '/' . StateDir::VAPID_KEY_FILE;
        file_put_contents($path, $p384Pem);
        chmod($path, 0o600);

        $keys = VapidKeys::new($this->dir);
        self::assertSame(65, strlen($keys->publicKeyUncompressed()), 'a re-minted P-256 pair answers instead');
        self::assertNotSame(48, strlen($keys->publicKeyUncompressed()) - 1);
    }

    public function testTheDerParserLeftPadsMinimalIntegersIntoTheFixedWidthPair(): void
    {
        // A 31-byte minimal r (high bit clear, no sign octet) is the case the
        // str_pad leg exists for; a real signed JWT only hits it by luck.
        $r31 = chr(0x7f) . random_bytes(30);
        $s32 = chr(0xb5) . random_bytes(31);
        $der = "\x30" . chr(68)
            . "\x02" . chr(31) . $r31
            . "\x02" . chr(33) . "\x00" . $s32;

        $method = new \ReflectionMethod(VapidKeys::class, 'rawSignature');
        $raw = $method->invoke(null, $der);

        self::assertSame(64, strlen($raw));
        self::assertSame("\x00" . $r31 . $s32, $raw, 'raw form left-pads each coordinate to 32 octets');
    }

    public function testTheDerParserRefusesNonCanonicalOrTruncatedSequences(): void
    {
        $method = new \ReflectionMethod(VapidKeys::class, 'rawSignature');

        $doublePad = "\x30" . chr(4 + 32 + 2 + 33)
            . "\x02" . chr(34) . "\x00\x00" . str_repeat("\x11", 32)
            . "\x02" . chr(33) . "\x00" . str_repeat("\x22", 32);
        $trailing = "\x30" . chr(4 + 32 + 2 + 33)
            . "\x02" . chr(33) . "\x00" . str_repeat("\x11", 32)
            . "\x02" . chr(33) . "\x00" . str_repeat("\x22", 32) . "\x00";
        $shortSeq = "\x30" . chr(65)
            . "\x02" . chr(33) . "\x00" . str_repeat("\x11", 32)
            . "\x02" . chr(33) . "\x00" . str_repeat("\x22", 32);

        foreach (['double zero padding' => $doublePad, 'trailing bytes' => $trailing, 'sequence length mismatch' => $shortSeq] as $shape => $der) {
            try {
                $method->invoke(null, $der);
                self::fail("rawSignature accepted the $shape forgery");
            } catch (\RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function testPrivateKeyMaterialNeverReachesTheWireStrings(): void
    {
        $keys = VapidKeys::new($this->dir);
        $wire = $keys->authorizationFor('https://fcm.googleapis.com');
        $pem = (string) file_get_contents($this->dir . '/' . StateDir::VAPID_KEY_FILE);
        $secretRun = substr(trim(explode("\n", $pem)[1]), 8, 24);

        self::assertStringNotContainsString($secretRun, $wire);
        self::assertStringNotContainsString('PRIVATE KEY', $wire);
    }

    private function decodeSegment(string $encoded): string
    {
        $raw = base64_decode(strtr($encoded, '-_', '+/'), true);

        if ($raw === false) {
            throw new LogicException('segment is not base64url');
        }

        return $raw;
    }

    /** Re-encode raw R||S as the DER SEQUENCE OpenSSL's verifier consumes. */
    private function derFromRawSignature(string $raw): string
    {
        $integers = '';
        foreach ([substr($raw, 0, 32), substr($raw, 32, 32)] as $value) {
            while (strlen($value) > 1 && $value[0] === "\x00") {
                $value = substr($value, 1);
            }

            $padded = $value[0] >= "\x80" ? "\x00" . $value : $value;
            $integers .= "\x02" . chr(strlen($padded)) . $padded;
        }

        return "\x30" . chr(strlen($integers)) . $integers;
    }
}
