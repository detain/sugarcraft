<?php

declare(strict_types=1);

namespace SugarCraft\Ansi\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Ansi\Parser\CsiHandler;
use SugarCraft\Ansi\Parser\HandlerAdapter;
use SugarCraft\Ansi\Parser\OscHandler;
use SugarCraft\Ansi\Parser\Parser;

/**
 * DECSET/DECRST mode-list dispatch.
 *
 * Mouse reporting is configured by up to seven distinct modes (X10
 * select-item 1000, cell-motion 1002, any-event 1003, UTF-8 coordinates
 * 1005, SGR 1006, urxvt 1015, pixel 1016) that programs routinely enable
 * in ONE sequence — `CSI ? 1000 ; 1006 h`. An adapter that dispatches only
 * the first parameter hides every later mode from the consumer, which then
 * has to GUESS which encoding produced a mouse byte sequence. The adapter
 * must hand over every mode number it parsed.
 */
final class HandlerAdapterModeDispatchTest extends TestCase
{
    /** @var list<array{op:string, mode:int, prefix:int}> */
    private array $calls = [];

    private Parser $parser;

    protected function setUp(): void
    {
        $this->calls = [];
        $record = function (string $op): callable {
            return function (int $mode, int $prefix = 0) use ($op): void {
                $this->calls[] = ['op' => $op, 'mode' => $mode, 'prefix' => $prefix];
            };
        };
        $csi = $this->createMock(CsiHandler::class);
        $csi->method('decset')->willReturnCallback($record('set'));
        $csi->method('decrst')->willReturnCallback($record('reset'));
        $this->parser = new Parser(new HandlerAdapter($csi, $this->createMock(OscHandler::class)));
    }

    public function testMultiModeSetDispatchesEveryMode(): void
    {
        $this->parser->feed("\x1b[?1000;1006h");

        self::assertSame([
            ['op' => 'set', 'mode' => 1000, 'prefix' => 0x3F],
            ['op' => 'set', 'mode' => 1006, 'prefix' => 0x3F],
        ], $this->calls);
    }

    public function testMultiModeResetDispatchesEveryMode(): void
    {
        $this->parser->feed("\x1b[?1003;1015;1016l");

        self::assertSame([
            ['op' => 'reset', 'mode' => 1003, 'prefix' => 0x3F],
            ['op' => 'reset', 'mode' => 1015, 'prefix' => 0x3F],
            ['op' => 'reset', 'mode' => 1016, 'prefix' => 0x3F],
        ], $this->calls);
    }

    public function testSingleModeBehaviourUnchanged(): void
    {
        $this->parser->feed("\x1b[?25h");

        self::assertSame([['op' => 'set', 'mode' => 25, 'prefix' => 0x3F]], $this->calls);
    }

    public function testAnsiModeSetWithoutPrefixStillDispatches(): void
    {
        // `CSI 4 h` (insert mode) — prefix 0, single param.
        $this->parser->feed("\x1b[4h");

        self::assertSame([['op' => 'set', 'mode' => 4, 'prefix' => 0]], $this->calls);
    }

    public function testEmptyModeListMapsToDefaultZero(): void
    {
        // `CSI h` with no params keeps the historical decset(0) behaviour.
        $this->parser->feed("\x1b[h");

        self::assertSame([['op' => 'set', 'mode' => 0, 'prefix' => 0]], $this->calls);
    }

    public function testDefaultParamInsideModeListMapsToZero(): void
    {
        // `CSI ? ; 1006 h` — empty leading slot is a default (0), not a gap.
        $this->parser->feed("\x1b[?;1006h");

        self::assertSame([
            ['op' => 'set', 'mode' => 0, 'prefix' => 0x3F],
            ['op' => 'set', 'mode' => 1006, 'prefix' => 0x3F],
        ], $this->calls);
    }

    public function testModeCapOverflowDoesNotDesyncFlags(): void
    {
        // 33 mode numbers: the parser accepts 32 params; past the cap the
        // separator is dropped and digits accumulate into the last slot
        // (32 -> "32" then "33" folds in -> 3233). Dispatch must stay in
        // lockstep with the params the handler receives.
        $modes = range(1, 33);
        $this->parser->feed("\x1b[?" . implode(';', $modes) . 'h');

        $dispatched = array_map(static fn (array $c): int => $c['mode'], $this->calls);
        self::assertCount(32, $dispatched);
        self::assertSame(range(1, 31), array_slice($dispatched, 0, 31));
        self::assertSame(3233, $dispatched[31]);
    }

    public function testColonOverflowKeepsFlagListAlignedWithParams(): void
    {
        // Same overflow, but colon-separated throughout: past the 32-param
        // cap the separator is dropped exactly like ';' and digits fold into
        // the last slot, so the flag list must stay index-aligned (31
        // continued slots, last slot reports no continuation).
        $this->parser->feed("\x1b[?" . implode(':', range(1, 33)) . 'h');

        $flags = $this->parser->subparams();

        self::assertCount(32, $flags);
        self::assertSame(array_fill(0, 31, true), array_slice($flags, 0, 31));
        self::assertFalse($flags[31]);
        self::assertCount(32, $this->calls, 'one dispatch per retained param slot');
    }
}
