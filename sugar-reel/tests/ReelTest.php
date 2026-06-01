<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Reel\Reel;

final class ReelTest extends TestCase
{
    public function testOpenRecordsThePath(): void
    {
        $this->assertSame('/tmp/x.mp4', Reel::open('/tmp/x.mp4')->path());
    }

    public function testNewConstructsWithEmptyPath(): void
    {
        $this->assertSame('', Reel::new()->path());
    }

    // -------------------------------------------------------------------------
    // fps() and withFps()
    // -------------------------------------------------------------------------

    /**
     * @testdox Reel::new()->fps() returns null (default is null)
     */
    public function testFpsDefaultsToNull(): void
    {
        $this->assertNull(Reel::new()->fps());
    }

    /**
     * @testdox Reel::new()->withFps(60.0)->fps() returns 60.0
     */
    public function testWithFpsSetsFps(): void
    {
        $reel = Reel::new()->withFps(60.0);
        $this->assertSame(60.0, $reel->fps());
    }

    /**
     * @testdox Reel::new()->withFps(30.0)->withFps(24.0)->fps() returns 24.0 (override works)
     */
    public function testWithFpsOverrideIsImmutable(): void
    {
        $reel = Reel::new()->withFps(30.0)->withFps(24.0);
        $this->assertSame(24.0, $reel->fps());
    }

    /**
     * @testdox Reel::new()->withFps() returns a new Reel instance (immutable)
     */
    public function testWithFpsReturnsNewInstance(): void
    {
        $original = Reel::new();
        $modified = $original->withFps(60.0);

        $this->assertNotSame($original, $modified);
        $this->assertNull($original->fps());
        $this->assertSame(60.0, $modified->fps());
    }
}
