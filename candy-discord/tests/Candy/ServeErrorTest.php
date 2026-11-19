<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Tests\Candy;

use CandyCode\Bundles\Discord\Candy\ServeError;
use CandyCode\Bundles\Discord\Tests\Fixture\FakeRefusal;
use PHPUnit\Framework\TestCase;

/**
 * The 015 split: getMessage() is the log surface — prefixed, method-
 * bearing; userReason() is the chat surface — the kind's sentence, never
 * a snake_case RPC name or the doubled `channel-bridge:` attribution.
 */
final class ServeErrorTest extends TestCase
{
    public function testAKnownKindRendersItsOwnSentenceNotTheWireText(): void
    {
        $error = new ServeError('timeout', 'channel-bridge: session.create: connect timed out', ['kind' => 'timeout']);

        self::assertStringContainsString('channel-bridge: session.create', $error->getMessage(), 'the log line keeps its attribution');
        self::assertSame('candy-code took too long to answer', $error->userReason());
        self::assertStringNotContainsString('session.create', $error->userReason(), 'no snake_case RPC name reaches chat');
    }

    public function testEveryNamedKindHasItsLineAndUnknownKindsShareTheGenericOne(): void
    {
        $known = [
            'not_connected' => 'not connected to candy-code',
            'dropped' => 'the connection to candy-code was lost',
            'unreachable' => 'candy-code could not be reached',
            'handshake_failed' => 'the connection to candy-code was refused',
            'bad_url' => 'the candy-code endpoint is misconfigured',
        ];
        foreach ($known as $kind => $copy) {
            self::assertSame($copy, (new ServeError($kind, 'log only'))->userReason(), $kind);
        }

        self::assertSame('candy-code refused the request', (new ServeError('rpc_-32601', 'log only'))->userReason());
        self::assertSame('candy-code refused the request', (new ServeError('image_mime_mismatch', 'log only'))->userReason());
    }

    public function testAnInProcessNotInitializedRefusalReadsAsNotConnected(): void
    {
        $error = ServeError::fromHost('session.create', new FakeRefusal('not_initialized', 'send server.hello first'));

        self::assertSame('not_initialized', $error->kind);
        self::assertSame('not connected to candy-code', $error->userReason(), 'the chat line says not connected, not a generic failure');
    }

    public function testAHostRefusalKeepsItsKindDataAndMethodAttribution(): void
    {
        $error = ServeError::fromHost('session.send', new FakeRefusal('image_mime_mismatch', 'invalid params'));

        self::assertSame('image_mime_mismatch', $error->kind);
        self::assertSame(['kind' => 'image_mime_mismatch'], $error->data);
        self::assertSame('channel-bridge: session.send: invalid params', $error->getMessage());
    }

    public function testAServeErrorPassesThroughFromHostUnchanged(): void
    {
        $original = new ServeError('timeout', 'channel-bridge: session.get: connect timed out');

        self::assertSame($original, ServeError::fromHost('session.get', $original));
    }

    public function testANonRefusalFailureIsTheInternalKindWithItsCause(): void
    {
        $cause = new \RuntimeException('dispatcher exploded');
        $error = ServeError::fromHost('command.exec', $cause);

        self::assertSame('internal', $error->kind);
        self::assertSame([], $error->data);
        self::assertSame('channel-bridge: command.exec: dispatcher exploded', $error->getMessage());
        self::assertSame($cause, $error->getPrevious());
        self::assertSame('candy-code refused the request', $error->userReason());
    }
}
