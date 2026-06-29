<?php

declare(strict_types=1);

namespace SugarCraft\Ansi\Tests;

use SugarCraft\Ansi\Parser\DebugHandler;
use SugarCraft\Ansi\Parser\Parser;
use PHPUnit\Framework\TestCase;

final class ParserOverflowTest extends TestCase
{
    /**
     * Step 3: Parameter values are clamped to 65535 to prevent integer overflow.
     * No float promotion — the value must be a proper int.
     */
    public function testParamValueClampedTo65535(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        // Huge parameter that would overflow without clamping
        $parser->feed("\x1b[99999999999999999999m");

        $csis = $handler->filter('csi');
        $this->assertNotEmpty($csis);
        $param = $csis[0]['detail']['params'][0];
        $this->assertSame(65535, $param);
        $this->assertTrue(is_int($param), 'Clamped param must be int, not float');
    }

    public function testParamClampIsIntegerNotFloat(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        $parser->feed("\x1b[99999999999999999999m");

        $csis = $handler->filter('csi');
        $param = $csis[0]['detail']['params'][0];
        // Ensure no float promotion occurred
        $this->assertSame(65535.0, (float) $param);
        $this->assertSame(65535, (int) $param);
    }

    /**
     * Step 4: Parameter count is capped at 32 (MaxParamsSize).
     * Further params after the cap are ignored.
     */
    public function testParamCountCappedAt32(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        // Feed 50 params (far exceeding the 32 limit)
        $parser->feed("\x1b[" . str_repeat('1;', 49) . "1m");

        $csis = $handler->filter('csi');
        $this->assertNotEmpty($csis);
        $this->assertCount(32, $csis[0]['detail']['params']);
    }

    /**
     * Normal SGR sequences with ~5 params are unaffected by the cap.
     */
    public function testNormalSgrWith5ParamsUnaffected(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        // SGR with 5 params: foreground color 38;2;r;g;b
        $parser->feed("\x1b[38;2;255;128;0m");

        $csis = $handler->filter('csi');
        $this->assertNotEmpty($csis);
        $this->assertSame([38, 2, 255, 128, 0], $csis[0]['detail']['params']);
    }

    /**
     * A single huge param is clamped to 65535 without affecting param count.
     */
    public function testClampDoesNotAffectParamCount(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        $parser->feed("\x1b[9999999999999;2m");

        $csis = $handler->filter('csi');
        $this->assertNotEmpty($csis);
        // First param clamped, second param preserved
        $this->assertSame(65535, $csis[0]['detail']['params'][0]);
        $this->assertSame(2, $csis[0]['detail']['params'][1]);
        $this->assertCount(2, $csis[0]['detail']['params']);
    }

    /**
     * The 32nd param can still accumulate digits (clamped by step 3).
     */
    public function test32ndParamCanStillGrowAndBeClamped(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        // 31 separators = 32 param slots, last one gets a huge value
        $parser->feed("\x1b[" . str_repeat('1;', 31) . "99999999999999m");

        $csis = $handler->filter('csi');
        $this->assertNotEmpty($csis);
        $params = $csis[0]['detail']['params'];
        $this->assertCount(32, $params);
        // The last param should be clamped to 65535
        $this->assertSame(65535, $params[31]);
    }
}
