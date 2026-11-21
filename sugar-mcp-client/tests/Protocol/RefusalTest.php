<?php

declare(strict_types=1);

namespace SugarCraft\McpClient\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\McpClient\Protocol\ErrorCode;
use SugarCraft\McpClient\Protocol\Refusal;
use SugarCraft\McpClient\Protocol\RpcError;

/**
 * The refusal face of a wire error: a bundle branches on {@see Refusal}, so the library
 * error must answer the kind and the data the wire carries, retry hint included.
 */
final class RefusalTest extends TestCase
{
    public function testTheLibraryRpcErrorIsARefusal(): void
    {
        self::assertInstanceOf(Refusal::class, RpcError::notFound('gone', 'session_not_found'));
    }

    public function testARefusalCarriesItsKind(): void
    {
        $error = RpcError::notFound('gone', 'session_not_found');

        self::assertSame('session_not_found', $error->refusalKind());
        self::assertSame(['kind' => 'session_not_found'], $error->refusalData());
    }

    public function testABusyRefusalKeepsItsRetryHintAndRetryableFlag(): void
    {
        $error = RpcError::of(ErrorCode::Busy, 'session is busy', null, ['retryAfterMs' => 500]);

        self::assertSame('busy', $error->refusalKind());
        self::assertSame(['kind' => 'busy', 'retryable' => true, 'retryAfterMs' => 500], $error->refusalData());
    }

    public function testRefusalDataIsTheWireDataObject(): void
    {
        $error = RpcError::invalidParams('bad mime');

        self::assertSame($error->toArray()['data'], $error->refusalData());
    }
}
