<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Parser;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Parser\DebugHandler;
use SugarCraft\Vt\Parser\Parser;

/**
 * CSI sub-parameter parsing: ':' is a sub-parameter separator per VT500.
 *
 * Mirrors charmbracelet/x/ansi/parser subparameter support.
 * Both ':' and ';' delimit params identically — SGR handlers consume
 * numeric values sequentially regardless of which separator was used.
 */
final class SubparamTest extends TestCase
{
    private function parse(string $bytes): DebugHandler
    {
        $h = new DebugHandler();
        $p = new Parser($h);
        $p->feed($bytes);
        return $h;
    }

    private static function csi(int $final, array $params = [], int $prefix = 0, int $intermediate = 0): array
    {
        return ['type' => 'csi', 'detail' => [
            'final' => $final,
            'params' => $params,
            'prefix' => $prefix,
            'intermediate' => $intermediate,
        ]];
    }

    public function testColonSubparamTruecolor(): void
    {
        // \x1b[38:2:255:100:50m  — colon form of 38;2;255;100;50
        $h = $this->parse("\x1b[38:2:255:100:50m");
        $this->assertSame([
            self::csi(ord('m'), [38, 2, 255, 100, 50]),
        ], $h->log);
    }

    public function testSemicolonFormStillWorks(): void
    {
        // \x1b[38;2;255;100;50m  — semicolon form (existing behavior)
        $h = $this->parse("\x1b[38;2;255;100;50m");
        $this->assertSame([
            self::csi(ord('m'), [38, 2, 255, 100, 50]),
        ], $h->log);
    }

    public function testColonAndSemicolonProduceSameParams(): void
    {
        $colon = $this->parse("\x1b[38:2:255:100:50m");
        $semi = $this->parse("\x1b[38;2;255;100;50m");
        $this->assertSame($semi->log, $colon->log);
    }

    public function testBackgroundTruecolorWithColons(): void
    {
        // \x1b[48:2:0:128:255m  — colon form of 48;2;0;128;255
        $h = $this->parse("\x1b[48:2:0:128:255m");
        $this->assertSame([
            self::csi(ord('m'), [48, 2, 0, 128, 255]),
        ], $h->log);
    }

    public function testIndex256ColorWithColons(): void
    {
        // \x1b[38:5:196m  — colon form of 38;5;196
        $h = $this->parse("\x1b[38:5:196m");
        $this->assertSame([
            self::csi(ord('m'), [38, 5, 196]),
        ], $h->log);
    }

    public function testMixedColonSemicolonInParams(): void
    {
        // Mix of colon and semicolon separators (edge case)
        $h = $this->parse("\x1b[38:2;255:100:50m");
        $this->assertSame([
            self::csi(ord('m'), [38, 2, 255, 100, 50]),
        ], $h->log);
    }

    public function testLeadingColonCreatesDefaultSlot(): void
    {
        // \x1b[38::50m  — each ':' creates a new param slot (same as ';').
        // Slot 0: 38, Slot 1: -1 (default after first ':'), Slot 2: 50 (from '5','0')
        // Extended-color handler reads kind=params[1]=(-1→0=2), R=params[2]=(-1→0),
        // G=params[3]=(-1→0), B=params[4]=50 — RGB(0,0,50) = same as semicolon form.
        $h = $this->parse("\x1b[38::50m");
        $this->assertSame([
            self::csi(ord('m'), [38, -1, 50]),
        ], $h->log);
    }

    public function testColonInCsiWithPrivatePrefix(): void
    {
        // \x1b[?38:5:196m  — private prefix + colon subparams
        $h = $this->parse("\x1b[?38:5:196m");
        $this->assertSame([
            self::csi(ord('m'), [38, 5, 196], ord('?')),
        ], $h->log);
    }

    public function testDcsWithColonSubparams(): void
    {
        // DCS with colon subparams — e.g. \x1bP1:2:3q ...
        $h = $this->parse("\x1bP1:2:3q\x1b\\");
        $this->assertSame([
            ['type' => 'dcs', 'detail' => [
                'final' => ord('q'),
                'params' => [1, 2, 3],
                'prefix' => 0,
                'intermediate' => 0,
                'data' => '',
            ]],
            ['type' => 'esc', 'detail' => ['final' => ord('\\'), 'intermediate' => 0]],
        ], $h->log);
    }
}
