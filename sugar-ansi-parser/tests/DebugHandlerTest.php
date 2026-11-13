<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Tests;

use SugarCraft\AnsiParser\Parser\DebugHandler;
use SugarCraft\AnsiParser\Parser\Handler;
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

    public function testDefaultShapeStaysUnbounded(): void
    {
        for ($i = 0; $i < 5_000; $i++) {
            $this->handler->printChar('r');
        }

        $this->assertCount(5_000, $this->handler->log);
        $this->assertSame(0, $this->handler->dropped);
    }

    public function testMaxLogRowsRingCeilingAndBatchEviction(): void
    {
        // cap 40 scales the vterm grace to the cap, so the ring tolerates
        // a transient of 2*cap rows and then splices down to cap in ONE batch.
        $handler = new DebugHandler(maxLogRows: 40);
        for ($i = 0; $i < 80; $i++) {
            $handler->printChar("r$i");
        }
        $this->assertCount(80, $handler->log);
        $this->assertSame(0, $handler->dropped);

        $handler->printChar('r80'); // 81st row: first batch eviction
        $this->assertCount(40, $handler->log);
        $this->assertSame(41, $handler->dropped);
        $details = array_column($handler->log, 'detail');
        $this->assertSame('r41', $details[0]);
        $this->assertSame('r80', end($details));

        for ($i = 0; $i < 40; $i++) {
            $handler->printChar("s$i");
        }
        $this->assertCount(80, $handler->log); // transient again, no evict yet
        $this->assertSame(41, $handler->dropped);

        $handler->printChar('tail'); // second batch eviction
        $this->assertCount(40, $handler->log);
        $this->assertSame(82, $handler->dropped);
        $details = array_column($handler->log, 'detail');
        $this->assertSame('s1', $details[0]);
        $this->assertSame('tail', end($details));
        $this->assertSame(122, $handler->dropped + count($handler->log)); // every feed accounted
    }

    public function testEvictedHeadIsOldestFirst(): void
    {
        $handler = new DebugHandler(maxLogRows: 3); // ceiling 6
        for ($i = 0; $i < 11; $i++) {
            $handler->printChar("r$i");
        }

        $this->assertCount(3, $handler->log);
        $this->assertSame(8, $handler->dropped); // splice at r6 (4) + at r10 (4)
        $this->assertSame(
            ['r8', 'r9', 'r10'],
            array_map(static fn ($e) => $e['detail'], $handler->log),
        );
    }

    public function testAllSevenEventTypesFlowThroughTheRing(): void
    {
        $handler = new DebugHandler(maxLogRows: 10); // ceiling 20
        for ($i = 0; $i < 30; $i++) {
            $handler->printChar('p');
            $handler->execute(0x0A);
            $handler->csiDispatch(ord('m'), [31], 0, 0);
            $handler->escDispatch(ord('D'), 0);
            $handler->oscDispatch("2;t$i");
            $handler->dcsDispatch(ord('q'), [1], 0, 0, 'd');
            $handler->sosPmApcDispatch('sos', 's');
        }

        $this->assertLessThanOrEqual(20, count($handler->log));
        $this->assertGreaterThan(0, $handler->dropped);
        $this->assertSame(210, $handler->dropped + count($handler->log));
        foreach (['print', 'execute', 'csi', 'esc', 'osc', 'dcs', 'sos'] as $type) {
            $this->assertNotEmpty($handler->filter($type), "type $type vanished");
        }
    }

    public function testResetLogClearsLogAndDropCounter(): void
    {
        $handler = new DebugHandler(maxLogRows: 3);
        for ($i = 0; $i < 11; $i++) {
            $handler->printChar("r$i");
        }
        $this->assertSame(8, $handler->dropped);

        $handler->resetLog();
        $this->assertSame([], $handler->log);
        $this->assertSame(0, $handler->dropped);

        $handler->printChar('after');
        $this->assertCount(1, $handler->log);
        $this->assertSame('after', $handler->log[0]['detail']);
    }
}
