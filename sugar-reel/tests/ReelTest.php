<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Reel\Reel;
use SugarCraft\Reel\Render\Mode;

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

    // -------------------------------------------------------------------------
    // mode() / withMode()
    // -------------------------------------------------------------------------

    /**
     * @testdox Reel::open()->mode() defaults to HalfBlock
     */
    public function testModeDefaultsToHalfBlock(): void
    {
        $this->assertSame(Mode::HalfBlock, Reel::open('/tmp/x.mp4')->mode());
    }

    /**
     * @testdox withMode() sets the mode and is immutable
     */
    public function testWithModeSetsModeImmutably(): void
    {
        $original = Reel::open('/tmp/x.mp4');
        $modified = $original->withMode(Mode::Ascii);

        $this->assertNotSame($original, $modified);
        $this->assertSame(Mode::HalfBlock, $original->mode());
        $this->assertSame(Mode::Ascii, $modified->mode());
        // Other fields are preserved across the with*() call.
        $this->assertSame('/tmp/x.mp4', $modified->path());
    }

    // -------------------------------------------------------------------------
    // cols() / rows() / withSize()
    // -------------------------------------------------------------------------

    /**
     * @testdox cols()/rows() default to 80x24
     */
    public function testSizeDefaults(): void
    {
        $reel = Reel::new();
        $this->assertSame(80, $reel->cols());
        $this->assertSame(24, $reel->rows());
    }

    /**
     * @testdox withSize() sets cols/rows and is immutable
     */
    public function testWithSizeSetsDimensionsImmutably(): void
    {
        $original = Reel::new();
        $modified = $original->withSize(120, 40);

        $this->assertNotSame($original, $modified);
        $this->assertSame(80, $original->cols());
        $this->assertSame(24, $original->rows());
        $this->assertSame(120, $modified->cols());
        $this->assertSame(40, $modified->rows());
    }

    /**
     * @testdox builders chain and each returns a distinct Reel
     */
    public function testBuildersChain(): void
    {
        $reel = Reel::open('/tmp/clip.mp4')
            ->withMode(Mode::TrueColor)
            ->withSize(100, 30)
            ->withFps(25.0);

        $this->assertSame('/tmp/clip.mp4', $reel->path());
        $this->assertSame(Mode::TrueColor, $reel->mode());
        $this->assertSame(100, $reel->cols());
        $this->assertSame(30, $reel->rows());
        $this->assertSame(25.0, $reel->fps());
    }
}
