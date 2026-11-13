<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Tests\Candy;

use CandyCode\Bundles\Discord\Candy\ServeError;
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
        $error = ServeError::fromRpc('session.create', [
            'code' => -32000,
            'message' => 'connect timed out',
            'data' => ['kind' => 'timeout'],
        ]);

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
}
