<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Parser;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Parser\DebugHandler;
use SugarCraft\Vt\Parser\Parser;

/**
 * DoS regression tests for Parser parameter and string accumulator caps.
 *
 * Verifies that steps candy-vt-1 and candy-vt-2 correctly bound:
 * - MAX_PARAMS = 32 (CSI/DCS parameter count)
 * - MAX_PARAM_VALUE = 65535 (individual param value clamp)
 * - MAX_STRING_BYTES = 1_048_576 (OSC/DCS payload size)
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
}
