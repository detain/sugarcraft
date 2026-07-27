<?php

declare(strict_types=1);

namespace SugarCraft\Ansi\Tests;

use SugarCraft\Ansi\Parser\CsiHandler;
use SugarCraft\Ansi\Parser\DebugHandler;
use SugarCraft\Ansi\Parser\Handler;
use SugarCraft\Ansi\Parser\HandlerAdapter;
use SugarCraft\Ansi\Parser\OscHandler;
use SugarCraft\Ansi\Parser\Parser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the HandlerAdapter's printChar forwarding logic:
 * C0 controls (< 0x20) are dropped, printable ASCII and valid UTF-8
 * lead bytes are forwarded to CsiHandler::printable().
 */
final class HandlerAdapterPrintCharTest extends TestCase
{
    private DebugHandler $csi;
    private DebugHandler $osc;
    private HandlerAdapter $adapter;
    private Parser $parser;

    protected function setUp(): void
    {
        $this->csi = new DebugHandler();
        $this->osc = new DebugHandler();
        // We can't use DebugHandler for CsiHandler directly since it's an interface.
        // Instead, wrap in HandlerAdapter and use a concrete CsiHandler impl.
        $csiImpl = new class ($this->csi) implements CsiHandler {
            public function __construct(private DebugHandler $log) {}
            public function printable(string $byte): void { $this->log->printChar($byte); }
            public function cuu(int $count): void { $this->log->csiDispatch(ord('A'), [$count], 0, 0); }
            public function cud(int $count): void { $this->log->csiDispatch(ord('B'), [$count], 0, 0); }
            public function cuf(int $count): void { $this->log->csiDispatch(ord('C'), [$count], 0, 0); }
            public function cub(int $count): void { $this->log->csiDispatch(ord('D'), [$count], 0, 0); }
            public function cup(int $row, int $col): void { $this->log->csiDispatch(ord('H'), [$row, $col], 0, 0); }
            public function hvp(int $row, int $col): void { $this->log->csiDispatch(ord('f'), [$row, $col], 0, 0); }
            public function sgr(array $params): void { $this->log->csiDispatch(ord('m'), $params, 0, 0); }
            public function ed(int $mode): void { $this->log->csiDispatch(ord('J'), [$mode], 0, 0); }
            public function el(int $mode): void { $this->log->csiDispatch(ord('K'), [$mode], 0, 0); }
            public function decset(int $mode, int $prefix): void { $this->log->csiDispatch(ord('h'), [$mode], $prefix, 0); }
            public function decrst(int $mode, int $prefix): void { $this->log->csiDispatch(ord('l'), [$mode], $prefix, 0); }
            public function decstbm(int $top, int $bottom): void { $this->log->csiDispatch(ord('r'), [$top, $bottom], 0, 0); }
            public function tbc(int $mode): void { $this->log->csiDispatch(ord('g'), [$mode], 0, 0); }
            public function cht(int $count = 1): void { $this->log->csiDispatch(ord('I'), [$count], 0, 0); }
            public function cbt(int $count = 1): void { $this->log->csiDispatch(ord('Z'), [$count], 0, 0); }
            public function cr(): void {}
            public function lf(): void {}
            public function su(int $count = 1): void { $this->log->csiDispatch(ord('S'), [$count], 0, 0); }
            public function sd(int $count = 1): void { $this->log->csiDispatch(ord('T'), [$count], 0, 0); }
            public function il(int $count = 1): void { $this->log->csiDispatch(ord('L'), [$count], 0, 0); }
            public function dl(int $count = 1): void { $this->log->csiDispatch(ord('M'), [$count], 0, 0); }
            public function ich(int $count = 1): void { $this->log->csiDispatch(ord('@'), [$count], 0, 0); }
            public function dch(int $count = 1): void { $this->log->csiDispatch(ord('P'), [$count], 0, 0); }
            public function rep(int $count = 1): void { $this->log->csiDispatch(ord('b'), [$count], 0, 0); }
            public function scosc(): void { $this->log->csiDispatch(ord('s'), [], 0, 0); }
            public function scorc(): void { $this->log->csiDispatch(ord('u'), [], 0, 0); }
            public function gridRows(): int { return 24; }
            public function gridCols(): int { return 80; }
        };
        $oscImpl = new class ($this->osc) implements OscHandler {
            public function __construct(private DebugHandler $log) {}
            public function title(string $title): void { $this->log->oscDispatch("2;$title"); }
            public function hyperlink(string $uri, string $id): void { $this->log->oscDispatch("8;id=$id;$uri"); }
        };
        $this->adapter = new HandlerAdapter($csiImpl, $oscImpl);
        $this->parser = new Parser($this->adapter);
    }

    public function testPrintableAsciiForwardedToPrintable(): void
    {
        $this->parser->feed("ABC");

        $prints = $this->csi->filter('print');
        $this->assertCount(3, $prints);
        $this->assertSame('A', $prints[0]['detail']);
        $this->assertSame('B', $prints[1]['detail']);
        $this->assertSame('C', $prints[2]['detail']);
    }

    public function testC0ControlBytesDroppedByPrintChar(): void
    {
        $this->parser->feed("\x00\x01\x02");

        $prints = $this->csi->filter('print');
        $this->assertCount(0, $prints, 'C0 controls should be dropped');
    }

    public function testSpaceCharacterForwarded(): void
    {
        $this->parser->feed(" ");
        $prints = $this->csi->filter('print');
        $this->assertCount(1, $prints);
        $this->assertSame(' ', $prints[0]['detail']);
    }

    public function testDeleteCharacterNotForwarded(): void
    {
        $this->parser->feed("\x7f"); // DEL
        $prints = $this->csi->filter('print');
        $this->assertCount(0, $prints, 'DEL is not printable');
    }

    public function testHighByteNotForwarded(): void
    {
        $this->parser->feed("\x80\x81"); // High bytes not valid UTF-8 starts
        $prints = $this->csi->filter('print');
        $this->assertCount(0, $prints, 'High bytes without UTF-8 lead are not printable');
    }

    public function testAllPrintableAsciiRange(): void
    {
        $printable = '';
        for ($i = 0x20; $i <= 0x7E; $i++) {
            $printable .= chr($i);
        }
        $this->parser->feed($printable);

        $prints = $this->csi->filter('print');
        $this->assertCount(0x7E - 0x20 + 1, $prints, 'All printable ASCII should be forwarded');
    }
}
