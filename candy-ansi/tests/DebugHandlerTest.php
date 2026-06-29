<?php

declare(strict_types=1);

namespace SugarCraft\Ansi\Tests;

use SugarCraft\Ansi\Parser\DebugHandler;
use SugarCraft\Ansi\Parser\Handler;
use PHPUnit\Framework\TestCase;

final class DebugHandlerTest extends TestCase
{
    private DebugHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new DebugHandler();
    }

    public function testPrintCharLogsPrintEntry(): void
    {
        $this->handler->printChar('A');

        $log = $this->handler->log;
        $this->assertCount(1, $log);
        $this->assertSame('print', $log[0]['type']);
        $this->assertSame('A', $log[0]['detail']);
    }

    public function testExecuteLogsExecuteEntry(): void
    {
        $this->handler->execute(0x0A); // LF

        $log = $this->handler->log;
        $this->assertCount(1, $log);
        $this->assertSame('execute', $log[0]['type']);
        $this->assertSame(0x0A, $log[0]['detail']);
    }

    public function testCsiDispatchLogsCsiEntryWithDetail(): void
    {
        $this->handler->csiDispatch(
            final: ord('m'),
            params: [31, 40],
            prefix: ord('?'),
            intermediate: 0,
        );

        $log = $this->handler->log;
        $this->assertCount(1, $log);
        $this->assertSame('csi', $log[0]['type']);
        $this->assertSame(ord('m'), $log[0]['detail']['final']);
        $this->assertSame([31, 40], $log[0]['detail']['params']);
        $this->assertSame(ord('?'), $log[0]['detail']['prefix']);
        $this->assertSame(0, $log[0]['detail']['intermediate']);
    }

    public function testEscDispatchLogsEscEntry(): void
    {
        $this->handler->escDispatch(final: ord('D'), intermediate: 0);

        $log = $this->handler->log;
        $this->assertCount(1, $log);
        $this->assertSame('esc', $log[0]['type']);
        $this->assertSame(ord('D'), $log[0]['detail']['final']);
        $this->assertSame(0, $log[0]['detail']['intermediate']);
    }

    public function testOscDispatchLogsOscEntry(): void
    {
        $this->handler->oscDispatch('2;Hello World');

        $log = $this->handler->log;
        $this->assertCount(1, $log);
        $this->assertSame('osc', $log[0]['type']);
        $this->assertSame('2;Hello World', $log[0]['detail']);
    }

    public function testDcsDispatchLogsDcsEntryWithAllDetailKeys(): void
    {
        $this->handler->dcsDispatch(
            final: ord('m'),
            params: [1, 2, 3],
            prefix: 0,
            intermediate: ord(' '),
            data: 'string',
        );

        $log = $this->handler->log;
        $this->assertCount(1, $log);
        $this->assertSame('dcs', $log[0]['type']);
        $detail = $log[0]['detail'];
        // Verify all expected keys are present
        $this->assertArrayHasKey('final', $detail);
        $this->assertArrayHasKey('params', $detail);
        $this->assertArrayHasKey('prefix', $detail);
        $this->assertArrayHasKey('intermediate', $detail);
        $this->assertArrayHasKey('data', $detail);
        $this->assertSame(ord('m'), $detail['final']);
        $this->assertSame([1, 2, 3], $detail['params']);
        $this->assertSame(0, $detail['prefix']);
        $this->assertSame(ord(' '), $detail['intermediate']);
        $this->assertSame('string', $detail['data']);
    }

    public function testSosPmApcDispatchLogsWithKindAsType(): void
    {
        $this->handler->sosPmApcDispatch('sos', 'test data');
        $this->assertSame('sos', $this->handler->log[0]['type']);
        $this->assertSame('test data', $this->handler->log[0]['detail']);

        $this->handler->sosPmApcDispatch('pm', 'pm data');
        $this->assertSame('pm', $this->handler->log[1]['type']);

        $this->handler->sosPmApcDispatch('apc', 'apc data');
        $this->assertSame('apc', $this->handler->log[2]['type']);
    }

    public function testFilterReturnsOnlyMatchingType(): void
    {
        $this->handler->printChar('A');
        $this->handler->execute(0x0A);
        $this->handler->csiDispatch(ord('m'), [31], 0, 0);
        $this->handler->printChar('B');

        $prints = $this->handler->filter('print');
        $this->assertCount(2, $prints);
        $this->assertSame('A', $prints[0]['detail']);
        $this->assertSame('B', $prints[1]['detail']);

        $csis = $this->handler->filter('csi');
        $this->assertCount(1, $csis);

        $executes = $this->handler->filter('execute');
        $this->assertCount(1, $executes);
    }

    public function testFilterReturnsEmptyArrayForUnknownType(): void
    {
        $this->handler->printChar('A');

        $unknown = $this->handler->filter('unknown');
        $this->assertEmpty($unknown);
    }

    public function testHandlerImplementsHandlerInterface(): void
    {
        $this->assertInstanceOf(Handler::class, $this->handler);
    }
}
