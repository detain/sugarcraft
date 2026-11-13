<?php

declare(strict_types=1);

namespace SugarCraft\McpClient\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\McpClient\McpServer;
use SugarCraft\McpClient\StdioMcpServer;

/**
 * The SHAPE of the published contract is itself API. This pin makes the
 * widened interface observable — method set, shared parameter names, and
 * the documented positional order of the concrete opt-ins — so a drift of
 * either surface (a dropped shared leg, a silently reordered trailing
 * parameter) goes red at the contract door instead of at a downstream
 * consumer that rebuilt its own twin type out of need.
 */
final class McpServerContractTest extends TestCase
{
    public function testTheContractCarriesTheSharedTransportShape(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(McpServer::class))->getMethods(),
        );

        self::assertSame(
            ['start', 'stop', 'listTools', 'callTool', 'request', 'notify'],
            $methods,
            'McpServer must expose lifecycle, tool surface, and both raw RPC legs',
        );
    }

    public function testTheRawLegsDeclareTheSharedParameterPairInOrder(): void
    {
        foreach (['request', 'notify'] as $leg) {
            $params = array_map(
                static fn (\ReflectionParameter $p): string => $p->getName(),
                (new \ReflectionMethod(McpServer::class, $leg))->getParameters(),
            );

            self::assertSame(
                ['method', 'params', 'deadline'],
                $params,
                "the {$leg}() contract is (method, params, deadline) — anything past it is concrete-class API",
            );
        }
    }

    public function testCallToolOnTheContractIsTheSharedPairOnly(): void
    {
        $params = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(McpServer::class, 'callTool'))->getParameters(),
        );

        self::assertSame(['name', 'arguments'], $params, 'the three opt-ins live on the concrete class, not the contract');
    }

    public function testTheStdioTransportSatisfiesTheContractWithItsDocumentedOrder(): void
    {
        self::assertTrue(
            (new \ReflectionClass(StdioMcpServer::class))->implementsInterface(McpServer::class),
            'the shipped transport must grow with every contract leg',
        );

        // The interface docblock states this order as concrete-class law
        // (onWait: third, timeoutSeconds: fourth); positional callers past
        // the shared pair — inside and outside this repo — bind against it.
        $params = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(StdioMcpServer::class, 'callTool'))->getParameters(),
        );

        self::assertSame(
            ['name', 'arguments', 'onWait', 'timeoutSeconds', 'onServerMessage'],
            $params,
        );
    }
}
