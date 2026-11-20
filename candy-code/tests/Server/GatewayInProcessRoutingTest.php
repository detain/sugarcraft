<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\RpcError;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Server\Workspace\Gateway;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;

/**
 * PL-5c-A: the routing seam the in-process door shares with the wire (Gateway::rootFor), checked
 * without a workspace host: which root a request names, and how a bad one is refused.
 */
final class GatewayInProcessRoutingTest extends TestCase
{
    private ProtocolFixture $fixture;

    private Gateway $gateway;

    private string $other;

    protected function setUp(): void
    {
        $this->fixture = ProtocolFixture::new();
        $this->other = $this->fixture->dir . '/other';
        \mkdir($this->other, 0o700, true);
        $this->other = (string) \realpath($this->other);
        $this->gateway = Gateway::new(
            $this->fixture->dispatcher,
            ServerConfig::new($this->fixture->dir)->withRoot($this->fixture->root),
            'test',
            Loop::get(),
            $this->fixture->dir . '/run',
        );
    }

    protected function tearDown(): void
    {
        $this->fixture->tearDown();
    }

    public function testThePrimaryRootAndAnUnboundSessionRouteToNoOtherRoot(): void
    {
        self::assertNull($this->gateway->rootFor((object) ['root' => $this->fixture->root]), 'the primary root is the server\'s own');
        self::assertNull($this->gateway->rootFor((object) []), 'no root and no session: the primary dispatcher');
        self::assertNull($this->gateway->rootFor((object) ['sessionId' => 'never-bound']), 'an unbound session goes to the primary dispatcher');
    }

    public function testAnExplicitRootRoutesToItsCanonicalPath(): void
    {
        self::assertSame($this->other, $this->gateway->rootFor((object) ['root' => $this->other . '/']));
    }

    public function testABoundSessionRoutesToItsRoot(): void
    {
        $bind = new \ReflectionMethod(Gateway::class, 'bindSession');
        $bind->invoke($this->gateway, 'bound-1', $this->other);

        self::assertSame($this->other, $this->gateway->rootFor((object) ['sessionId' => 'bound-1']));
    }

    public function testANonDirectoryRootIsRefusedRootNotFound(): void
    {
        try {
            $this->gateway->rootFor((object) ['root' => $this->other . '/missing']);
            self::fail('a missing root must be refused');
        } catch (RpcError $e) {
            self::assertSame('root_not_found', $e->refusalKind());
        }
    }

    public function testANonStringRootIsRefusedInvalidParams(): void
    {
        try {
            $this->gateway->rootFor((object) ['root' => 7]);
            self::fail('a non-string root must be refused');
        } catch (RpcError $e) {
            self::assertSame(ErrorCode::InvalidParams, $e->errorCode);
            self::assertSame('invalid_params', $e->refusalKind());
        }
    }
}
