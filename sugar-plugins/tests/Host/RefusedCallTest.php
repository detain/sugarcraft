<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests\Host;

use PHPUnit\Framework\TestCase;
use SugarCraft\McpClient\Protocol\Refusal;
use SugarCraft\McpClient\Protocol\RpcError;
use SugarCraft\Plugins\Host\RefusedCall;

/**
 * The plugin-side refusal contract extends the protocol's {@see Refusal}, so a bundle that
 * checks either interface accepts a refusal from the in-process host and from the library
 * error alike.
 */
final class RefusedCallTest extends TestCase
{
    public function testRefusedCallExtendsTheProtocolRefusal(): void
    {
        self::assertTrue(is_a(RefusedCall::class, Refusal::class, true));
    }

    public function testALibraryRefusalIsAProtocolRefusalButNotARefusedCall(): void
    {
        $error = RpcError::notFound('gone', 'session_not_found');

        self::assertInstanceOf(Refusal::class, $error);
        self::assertNotInstanceOf(RefusedCall::class, $error);
    }

    public function testAnImplementationOfRefusedCallIsAProtocolRefusal(): void
    {
        $refusal = new class extends \RuntimeException implements RefusedCall {
            public function refusalKind(): string
            {
                return 'busy';
            }

            public function refusalData(): array
            {
                return ['kind' => 'busy'];
            }
        };

        self::assertInstanceOf(Refusal::class, $refusal);
    }
}
