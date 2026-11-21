<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\Candy;

use CandyCode\Bundles\MsTeams\Candy\ServeError;
use CandyCode\Bundles\MsTeams\Tests\Fixture\FakeRefusal;
use PHPUnit\Framework\TestCase;

/**
 * The in-process refusal mapping: a host refusal keeps the wire's kind and data so the router
 * reads it as it reads a wire refusal (the media retry keys on the kind), and a failure that
 * is not a refusal is the `internal` kind.
 */
final class ServeErrorTest extends TestCase
{
    public function testAHostRefusalKeepsItsKindDataAndMethodAttribution(): void
    {
        $error = ServeError::fromHost('session.send', new FakeRefusal('document_mime_mismatch', 'invalid params'));

        self::assertSame('document_mime_mismatch', $error->kind);
        self::assertSame(['kind' => 'document_mime_mismatch'], $error->data);
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
    }

    public function testALibraryRefusalKeepsItsKindAndRetryHint(): void
    {
        $refusal = \SugarCraft\McpClient\Protocol\RpcError::of(
            \SugarCraft\McpClient\Protocol\ErrorCode::Busy,
            'session is busy',
            null,
            ['retryAfterMs' => 500],
        );
        $error = ServeError::fromHost('session.send', $refusal);

        self::assertSame('busy', $error->kind);
        self::assertSame(['kind' => 'busy', 'retryAfterMs' => 500, 'retryable' => true], $error->data);
    }

    public function testALibraryNotFoundRefusalKeepsItsKind(): void
    {
        $refusal = \SugarCraft\McpClient\Protocol\RpcError::notFound('gone', 'session_not_found');
        $error = ServeError::fromHost('session.get', $refusal);

        self::assertSame('session_not_found', $error->kind);
        self::assertSame(['kind' => 'session_not_found'], $error->data);
    }
}
