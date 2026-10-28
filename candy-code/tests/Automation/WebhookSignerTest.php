<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Automation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SugarCraft\Code\Automation\WebhookSigner;

/**
 * C-AGT-5.e: the signer's headers, and the HMAC over a KNOWN VECTOR pinned
 * verbatim — computed once outside this file and pasted, so a rewrite of the
 * signed-payload template cannot re-derive the expectation and stay green.
 *
 * The vector pins the whole doctrine at once: the decimal timestamp and the
 * raw body joined by one dot, keyed HMAC-SHA256, hex-lowercase under a
 * `sha256=` prefix, in the two header names a receiver recipe will name.
 */
final class WebhookSignerTest extends TestCase
{
    private const BODY = '{"event":"turn.completed","timestamp":1761630000,"data":{"session_id":"ses_abc","duration_seconds":1.5,"tool_calls":2}}';

    private const SECRET = 'test-secret-key-0123';

    private const TIMESTAMP = 1761630000;

    /** hash_hmac('sha256', '1761630000.'.BODY, SECRET) — computed at authoring time. */
    private const KNOWN_SIGNATURE = 'sha256=ab76c69f2c65576e682e6e6fff1e0e954281a8c954d2d8db5440b274684f65e3';

    public function testTheKnownVectorIsReproducedByteForByte(): void
    {
        self::assertSame(
            self::KNOWN_SIGNATURE,
            WebhookSigner::signature(self::BODY, self::SECRET, self::TIMESTAMP),
        );
    }

    public function testAnEmptyBodyStillSignsToThePinnedVector(): void
    {
        // The second authoring-time vector: signature('', 'secret', 0).
        self::assertSame(
            'sha256=3445798a051818ef95def46c2eb62b43d377ce6e3c29b4d0aec3da0e59577f79',
            WebhookSigner::signature('', 'secret', 0),
        );
    }

    public function testTheHeadersCarrySignatureAndTimestampInTheirPinnedNames(): void
    {
        $headers = WebhookSigner::headers(self::BODY, self::SECRET, self::TIMESTAMP);

        self::assertSame(['X-Candy-Code-Signature', 'X-Candy-Code-Timestamp'], array_keys($headers));
        self::assertSame(self::KNOWN_SIGNATURE, $headers[WebhookSigner::SIGNATURE_HEADER]);
        self::assertSame('1761630000', $headers[WebhookSigner::TIMESTAMP_HEADER]);
    }

    public function testTheHeaderPairIsSelfConsistentWhenTheTimestampDefaults(): void
    {
        $before = time();
        $headers = WebhookSigner::headers(self::BODY, self::SECRET);
        $after = time();

        $seconds = (int) $headers[WebhookSigner::TIMESTAMP_HEADER];
        self::assertGreaterThanOrEqual($before, $seconds);
        self::assertLessThanOrEqual($after, $seconds);
        self::assertSame(
            WebhookSigner::signature(self::BODY, self::SECRET, $seconds),
            $headers[WebhookSigner::SIGNATURE_HEADER],
            'the value a receiver recomputes from the sent headers must equal the sent signature',
        );
    }

    public function testEverySignedInputChangesTheSignature(): void
    {
        $base = WebhookSigner::signature(self::BODY, self::SECRET, self::TIMESTAMP);

        self::assertNotSame($base, WebhookSigner::signature(self::BODY . ' ', self::SECRET, self::TIMESTAMP), 'one trailing byte is a different delivery');
        self::assertNotSame($base, WebhookSigner::signature(self::BODY, 'test-secret-key-0124', self::TIMESTAMP), 'a different key is a different signature');
        self::assertNotSame($base, WebhookSigner::signature(self::BODY, self::SECRET, self::TIMESTAMP + 1), 'the timestamp rides the HMAC — a captured pair ages out');
    }

    public function testTheSchemePrefixIsTheOnlyAlgorithmTheClassNames(): void
    {
        self::assertSame('X-Candy-Code-Signature', WebhookSigner::SIGNATURE_HEADER);
        self::assertSame('X-Candy-Code-Timestamp', WebhookSigner::TIMESTAMP_HEADER);
        self::assertSame('sha256', WebhookSigner::SCHEME);
        self::assertSame('%d.%s', WebhookSigner::SIGNED_PAYLOAD_TEMPLATE);
        self::assertStringStartsWith(WebhookSigner::SCHEME . '=', WebhookSigner::signature(self::BODY, self::SECRET, self::TIMESTAMP));
    }

    public function testTheSignerIsAStaticNamespaceAndNotAnObject(): void
    {
        $ctor = (new ReflectionClass(WebhookSigner::class))->getConstructor();

        self::assertNotNull($ctor);
        self::assertTrue($ctor->isPrivate(), 'the signer is a fixed scheme, there is nothing to configure into it');
    }
}
