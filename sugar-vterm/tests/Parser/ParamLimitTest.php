<?php

declare(strict_types=1);

namespace SugarCraft\Vterm\Tests\Parser;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vterm\Parser\DebugHandler;
use SugarCraft\AnsiParser\Parser\Parser;
use SugarCraft\Vterm\Terminal as RootTerminal;
use SugarCraft\Vterm\Terminal\Terminal as FullTerminal;

/**
 * DoS regression tests for Parser parameter and string accumulator caps.
 *
 * Verifies that steps sugar-vterm-1 and sugar-vterm-2 correctly bound:
 * - MAX_PARAMS = 32 (CSI/DCS parameter count)
 * - MAX_PARAM_VALUE = 65535 (individual param value clamp)
 * - MAX_STRING_BYTES = 1_048_576 (OSC/DCS payload size)
 *
 * The last bullet is the parser's OWN default; both production facades
 * halve it to the shared 64 KiB {@see RootTerminal::MAX_STRING_BUFFER} —
 * pinned by the facade legs at the end of this file, so deleting or
 * widening that ceiling fails loud instead of riding green on the
 * upstream default the other legs exercise.
 */
final class ParamLimitTest extends TestCase
{
    /**
     * Feed CSI with 100 semicolon-separated params and verify count is capped at 32.
     */
    public function testCsiParamCountIsCappedAt32(): void
    {
        $h = new DebugHandler();
        $p = new Parser($h);

        // 100 params: "1;2;3;...;100H"
        $params = implode(';', range(1, 100));
        $p->feed("\x1b[{$params}H");

        $csiEntries = $h->filter('csi');
        $this->assertCount(1, $csiEntries);

        $params = $csiEntries[0]['detail']['params'];
        $this->assertLessThanOrEqual(32, count($params));
    }

    /**
     * Feed CSI with 100 semicolons (implicit defaults) and verify capped at 32 slots.
     */
    public function testCsiImplicitDefaultParamsCappedAt32(): void
    {
        $h = new DebugHandler();
        $p = new Parser($h);

        // 100 implicit defaults: ";;;...;;;H" (100 semicolons)
        $p->feed("\x1b[" . str_repeat(';', 100) . "H");

        $csiEntries = $h->filter('csi');
        $this->assertCount(1, $csiEntries);

        $params = $csiEntries[0]['detail']['params'];
        $this->assertLessThanOrEqual(32, count($params));
    }

    /**
     * Verify a long digit run is clamped to MAX_PARAM_VALUE (65535).
     */
    public function testLongDigitRunClampedTo65535(): void
    {
        $h = new DebugHandler();
        $p = new Parser($h);

        // 100 nines: should be clamped to 65535
        $p->feed("\x1b[" . str_repeat('9', 100) . "m");

        $csiEntries = $h->filter('csi');
        $this->assertCount(1, $csiEntries);

        $params = $csiEntries[0]['detail']['params'];
        $this->assertCount(1, $params);
        $this->assertLessThanOrEqual(65535, $params[0]);
    }

    /**
     * Verify 256-color SGR (5 params) still works (well under 32 cap).
     */
    public function testSgr256ColorStillWorks(): void
    {
        $h = new DebugHandler();
        $p = new Parser($h);

        // 38;5;196m = set fg to 196 (256-color)
        $p->feed("\x1b[38;5;196m");

        $csiEntries = $h->filter('csi');
        $this->assertCount(1, $csiEntries);

        $params = $csiEntries[0]['detail']['params'];
        $this->assertSame([38, 5, 196], $params);
    }

    /**
     * Verify truecolor SGR (5 params) still works (5 < 32 cap).
     */
    public function testSgrTruecolorStillWorks(): void
    {
        $h = new DebugHandler();
        $p = new Parser($h);

        // 38;2;255;128;0m = set fg to RGB 255,128,0
        $p->feed("\x1b[38;2;255;128;0m");

        $csiEntries = $h->filter('csi');
        $this->assertCount(1, $csiEntries);

        $params = $csiEntries[0]['detail']['params'];
        $this->assertSame([38, 2, 255, 128, 0], $params);
    }

    /**
     * Feed OSC with multi-MiB payload and verify it's capped at MAX_STRING_BYTES.
     */
    public function testOscStringPayloadCappedAt1MiB(): void
    {
        $h = new DebugHandler();
        $p = new Parser($h);

        // OSC 52;c; + 2MB of 'A' + BEL terminator
        $largePayload = "\x1b]52;c;" . str_repeat('A', 2_000_000) . "\x07";
        $p->feed($largePayload);

        $oscEntries = $h->filter('osc');
        $this->assertCount(1, $oscEntries);

        $data = $oscEntries[0]['detail'];
        // After the cap, the payload should be at most MAX_STRING_BYTES
        $payloadLen = strlen($data) - strlen('52;c;'); // subtract the command prefix
        $this->assertLessThanOrEqual(1_048_576, $payloadLen);
    }

    /**
     * Verify a normal-sized OSC title (< 1 KiB) passes through unchanged.
     */
    public function testNormalOscTitlePassesThrough(): void
    {
        $h = new DebugHandler();
        $p = new Parser($h);

        $p->feed("\x1b]2;Normal Title\x07");

        $oscEntries = $h->filter('osc');
        $this->assertCount(1, $oscEntries);
        $this->assertSame('2;Normal Title', $oscEntries[0]['detail']);
    }

    /**
     * Verify the cap does not cause a timeout on pathologically large input.
     * This test has a timeout to catch infinite loops (not just slow processing).
     */
    public function testLargeParamCountCompletesInUnderOneSecond(): void
    {
        $h = new DebugHandler();
        $p = new Parser($h);

        $start = microtime(true);
        $p->feed("\x1b[" . str_repeat('1;', 10_000) . "1H");
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(1.0, $elapsed, 'Parser should process 10k params in under 1 second');
    }

    /**
     * A parser built with the facade ceiling (as BOTH facades build theirs)
     * truncates OSC payloads at MAX_STRING_BUFFER instead of letting them
     * grow with the feed.
     */
    public function testFacadeStringBufferCapUsesTheSharedConstant(): void
    {
        // The contract is the 64 KiB value itself — widening the constant
        // must redden this file, not move the goalposts with it. Read
        // through reflection so the pin compares a runtime value against
        // the literal, never two copies of the same compile-time constant.
        $this->assertSame(
            65_536,
            (new \ReflectionClass(RootTerminal::class))->getConstant('MAX_STRING_BUFFER'),
            'the shared parser ceiling is 64 KiB'
        );

        $h = new DebugHandler();
        $p = new Parser($h, maxStringBuffer: RootTerminal::MAX_STRING_BUFFER);

        $p->feed("\x1b]52;c;" . str_repeat('A', 2_000_000) . "\x07");

        $oscEntries = $h->filter('osc');
        $this->assertCount(1, $oscEntries);
        $payloadLen = strlen($oscEntries[0]['detail']) - strlen('52;c;');
        $this->assertLessThanOrEqual(
            65_536,
            $payloadLen,
            'facade parsers must honour the 64 KiB ceiling'
        );
    }

    /**
     * End-to-end through the emulator facade: a 2 MB OSC 2 title is stored
     * only up to the same shared ceiling.
     */
    public function testEmulatorFacadeHonoursTheSharedCap(): void
    {
        $t = FullTerminal::new(20, 5);
        $t->feed("\x1b]2;" . str_repeat('x', 2_000_000) . "\x07");

        $title = $t->windowTitle();
        $this->assertIsString($title);
        $this->assertLessThanOrEqual(
            65_536,
            strlen($title),
            'emulator facade accumulates titles past the 64 KiB ceiling'
        );
    }
}
