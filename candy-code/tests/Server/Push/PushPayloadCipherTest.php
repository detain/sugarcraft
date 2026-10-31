<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server\Push;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Server\Push\PushPayloadCipher;
use SugarCraft\Code\Server\Push\PushSubscription;

/**
 * RFC 8291 §5 test vectors for the aes128gcm payload encoding.
 *
 * Every string below is copied verbatim from the RFC (base64url as printed
 * there). The gate the design block names is the byte-exact full-body pin:
 * encrypting the vector plaintext under the vector UA keys, with the vector
 * local keypair and salt forced, must produce the vector HTTP body with zero
 * slop. A suite that only round-trips its own implementation would survive
 * any derivation mistake, so the reference numbers — not our own outputs —
 * are what each structural leg compares against.
 */
final class PushPayloadCipherTest extends TestCase
{
    private const PLAINTEXT = 'When I grow up, I want to be a watermelon';

    private const SALT = 'DGv6ra1nlYgDCS1FRnbzlw';

    /** The server's local (ephemeral) P-256 keypair from the RFC example. */
    private const LOCAL_PRIVATE_D = 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw';

    private const LOCAL_PUBLIC = 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8';

    /** The user agent keys, as the subscription would carry them. */
    private const UA_PUBLIC = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';

    private const AUTH_SECRET = 'BTBZMqHH6r4Tts7J_aSIgg';

    /** Intermediate key material the RFC publishes for this exact message. */
    private const CEK = 'oIhVW04MRdy2XN9CiKLxTg';

    private const NONCE = '4h_95klXJ5E_qnoN';

    /** The complete HTTP body (144 bytes) the RFC's encryption produces. */
    private const BODY = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';

    public function testTheRfcVectorEncryptsByteExact(): void
    {
        $body = $this->pushVectorCipher()->encrypt(self::PLAINTEXT, $this->pushVectorSubscription());

        self::assertSame(
            $this->decode64Url(self::BODY),
            $body,
            'the encryption must reproduce RFC 8291 section 5 byte for byte'
        );
        self::assertSame(144, strlen($body));
    }

    public function testTheHeaderCarriesTheVectorSaltRecordSizeAndLocalPoint(): void
    {
        $body = $this->pushVectorCipher()->encrypt(self::PLAINTEXT, $this->pushVectorSubscription());

        self::assertSame($this->decode64Url(self::SALT), substr($body, 0, 16));
        self::assertSame(4096, unpack('N', substr($body, 16, 4))[1], 'record size is network byte order');
        self::assertSame(65, ord($body[20]), 'keyid length is the uncompressed P-256 point');
        self::assertSame($this->decode64Url(self::LOCAL_PUBLIC), substr($body, 21, 65));
        self::assertSame("\x04", substr($body, 21, 1), 'the point is uncompressed');
    }

    public function testTheRecordDecryptsWithTheVectorsOwnKeysToPayloadPlusLastDelimiter(): void
    {
        $body = $this->pushVectorCipher()->encrypt(self::PLAINTEXT, $this->pushVectorSubscription());
        $record = substr($body, 86);

        self::assertSame(strlen($record), strlen(self::PLAINTEXT) + 1 + 16, 'one record: plaintext, one byte of payload, tag');

        $plain = openssl_decrypt(
            substr($record, 0, strlen($record) - 16),
            'aes-128-gcm',
            $this->decode64Url(self::CEK),
            OPENSSL_RAW_DATA,
            $this->decode64Url(self::NONCE),
            substr($record, -16)
        );

        self::assertSame(self::PLAINTEXT . "\x02", $plain, 'the last record carries delimiter 0x02 and nothing follows');
    }

    public function testProductionMintsAFreshSaltAndEphemeralKeyPerMessage(): void
    {
        $cipher = PushPayloadCipher::new();
        $subscription = $this->pushVectorSubscription();

        $first = $cipher->encrypt(self::PLAINTEXT, $subscription);
        $second = $cipher->encrypt(self::PLAINTEXT, $subscription);

        self::assertNotSame($first, $second, 'two encryptions of one payload must not reuse the nonce');
        self::assertNotSame(substr($first, 0, 16), substr($second, 0, 16));
        self::assertNotSame(substr($first, 21, 65), substr($second, 21, 65));
        self::assertSame("\x04", substr($first, 21, 1));
        self::assertSame(65, ord($first[20]));
    }

    public function testTheCeilingIsOneFullRecordAndTheEdgeIsPinned(): void
    {
        $atCap = $this->pushVectorCipher()->encrypt(str_repeat('x', PushPayloadCipher::MAX_PLAINTEXT_BYTES), $this->pushVectorSubscription());

        self::assertSame(PushPayloadCipher::RECORD_SIZE, strlen($atCap), 'the largest payload must fit exactly one record');

        $this->expectException(InvalidArgumentException::class);
        $this->pushVectorCipher()->encrypt(str_repeat('x', PushPayloadCipher::MAX_PLAINTEXT_BYTES + 1), $this->pushVectorSubscription());
    }

    public function testAnEmptyPayloadStillFramesACompleteRecord(): void
    {
        $body = $this->pushVectorCipher()->encrypt('', $this->pushVectorSubscription());

        self::assertSame(86 + 1 + 16, strlen($body));

        $record = substr($body, 86);
        $plain = openssl_decrypt(
            substr($record, 0, strlen($record) - 16),
            'aes-128-gcm',
            $this->decode64Url(self::CEK),
            OPENSSL_RAW_DATA,
            $this->decode64Url(self::NONCE),
            substr($record, -16)
        );
        self::assertSame("\x02", $plain);
    }

    public function testASaltOfTheWrongLengthRefusesToEncrypt(): void
    {
        $pem = $this->sec1Pem(self::LOCAL_PRIVATE_D, self::LOCAL_PUBLIC);
        $cipher = PushPayloadCipher::new()->withEntropy(
            static fn(): string => random_bytes(15),
            static fn(): string => $pem
        );

        $this->expectException(LogicException::class);
        $cipher->encrypt('x', $this->pushVectorSubscription());
    }

    public function testATruncatedPublicKeyCannotDescribeASubscriptionAtAll(): void
    {
        // The shape door is the value object's, upstream of the cipher: 64
        // bytes instead of the 65-byte uncompressed point never reaches ECDH.
        $truncated = rtrim(strtr(base64_encode("\x04" . random_bytes(63)), '+/', '-_'), '=');

        $this->expectException(InvalidArgumentException::class);
        PushSubscription::new('https://updates.push.services.mozilla.com/wpush/v2/abc', $truncated, self::AUTH_SECRET);
    }

    public function testAPaddedOrUrlForeignSecretIsRefusedAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PushSubscription::new('https://updates.push.services.mozilla.com/wpush/v2/abc', self::UA_PUBLIC, self::AUTH_SECRET . '=');
    }

    /** RFC 8291 §5 example subscription (the endpoint is the real autopush host the RFC uses). */
    private function pushVectorSubscription(): PushSubscription
    {
        return PushSubscription::new(
            'https://updates.push.services.mozilla.com/wpush/v2/GwABAAC9X6_wa8jUisWIsEP3wz59JrMgGw8wEqKK6PBru3mn',
            self::UA_PUBLIC,
            self::AUTH_SECRET
        );
    }

    /** The cipher with the RFC's local keypair and salt forced. */
    private function pushVectorCipher(): PushPayloadCipher
    {
        $salt = $this->decode64Url(self::SALT);
        $pem = $this->sec1Pem(self::LOCAL_PRIVATE_D, self::LOCAL_PUBLIC);

        return PushPayloadCipher::new()->withEntropy(
            static fn(): string => $salt,
            static fn(): string => $pem
        );
    }

    /**
     * SEC1 ECPrivateKey DER (RFC 5915) for P-256, wrapped in PEM, from the
     * RFC's raw private scalar and public point. The framing below is the
     * fixed structure of a prime256v1 key: version 1, the 32-byte scalar, the
     * curve OID [0], and the public point [1] — nothing about it is secret.
     */
    private function sec1Pem(string $privateD64Url, string $publicPoint64Url): string
    {
        $d = $this->decode64Url($privateD64Url);
        $point = $this->decode64Url($publicPoint64Url);

        if (strlen($d) !== 32 || strlen($point) !== 65 || $point[0] !== "\x04") {
            throw new LogicException('the vector key material must be a 32-byte scalar and a 65-byte uncompressed point');
        }

        $der = hex2bin('30770201010420') . $d
            . hex2bin('a00a06082a8648ce3d030107a144034200') . $point;

        return "-----BEGIN EC PRIVATE KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . '-----END EC PRIVATE KEY-----' . "\n";
    }

    private function decode64Url(string $encoded): string
    {
        $raw = base64_decode(strtr($encoded, '-_', '+/'), true);

        if ($raw === false || $encoded === '') {
            throw new LogicException('test vector is not base64url');
        }

        return $raw;
    }
}
