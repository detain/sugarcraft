<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\Detect;

final class DetectTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Snapshot and clear relevant env vars so each test starts clean.
        $keys = ['KITTY_WINDOW_ID', 'TERM_PROGRAM', 'LC_TERMINAL', 'TERM', 'XTERM_VERSION'];
        foreach ($keys as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
        }
        Detect::reset();
    }

    protected function tearDown(): void
    {
        // Restore env vars to pre-test state.
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv("$key=$value");
            }
        }
        Detect::reset();
        parent::tearDown();
    }

    public function testKittyViaKittyWindowId(): void
    {
        putenv('KITTY_WINDOW_ID=12345');
        $cap = Detect::probe();
        $this->assertTrue($cap->kitty);
        $this->assertFalse($cap->iterm2);
        $this->assertFalse($cap->sixel);
        $this->assertTrue($cap->halfblock);
    }

    public function testKittyViaTermProgramGhostty(): void
    {
        putenv('TERM_PROGRAM=ghostty');
        $cap = Detect::probe();
        $this->assertTrue($cap->kitty);
    }

    public function testKittyViaTermProgramWezTerm(): void
    {
        putenv('TERM_PROGRAM=WezTerm');
        $cap = Detect::probe();
        $this->assertTrue($cap->kitty);
    }

    public function testKittyViaXtermKittyTerm(): void
    {
        putenv('TERM=xterm-kitty');
        $cap = Detect::probe();
        $this->assertTrue($cap->kitty);
    }

    public function testIterm2ViaTermProgramItermApp(): void
    {
        putenv('TERM_PROGRAM=iTerm.app');
        $cap = Detect::probe();
        $this->assertFalse($cap->kitty);
        $this->assertTrue($cap->iterm2);
    }

    public function testIterm2ViaTermProgramWezTerm(): void
    {
        // WezTerm supports both Kitty and iTerm2 protocols; per our precedence
        // Kitty wins, so TERM_PROGRAM=WezTerm returns kitty capability.
        putenv('TERM_PROGRAM=WezTerm');
        $cap = Detect::probe();
        $this->assertTrue($cap->kitty);
        $this->assertFalse($cap->iterm2);
    }

    public function testIterm2ViaTermProgramMintty(): void
    {
        putenv('TERM_PROGRAM=mintty');
        $cap = Detect::probe();
        $this->assertTrue($cap->iterm2);
    }

    public function testIterm2ViaLcTerminal(): void
    {
        putenv('LC_TERMINAL=iTerm2');
        $cap = Detect::probe();
        $this->assertTrue($cap->iterm2);
    }

    public function testSixelViaXtermVersionAndMlterm(): void
    {
        putenv('TERM=mlterm');
        putenv('XTERM_VERSION=Mlterm');
        $cap = Detect::probe();
        $this->assertTrue($cap->sixel);
        $this->assertFalse($cap->kitty);
        $this->assertFalse($cap->iterm2);
    }

    public function testSixelViaXtermVersionAndFoot(): void
    {
        putenv('TERM=foot');
        putenv('XTERM_VERSION=Foot');
        $cap = Detect::probe();
        $this->assertTrue($cap->sixel);
    }

    public function testSixelViaXtermVersionAndXterm256color(): void
    {
        putenv('TERM=xterm-256color');
        putenv('XTERM_VERSION=XTerm');
        $cap = Detect::probe();
        $this->assertTrue($cap->sixel);
    }

    public function testSixelRequiresXtermVersion(): void
    {
        putenv('TERM=mlterm');
        $cap = Detect::probe();
        // No sixel without XTERM_VERSION.
        $this->assertFalse($cap->sixel);
    }

    public function testHalfBlockAlwaysAvailable(): void
    {
        $cap = Detect::probe();
        $this->assertTrue($cap->halfblock);
        // No other protocol detected.
        $this->assertFalse($cap->kitty);
        $this->assertFalse($cap->iterm2);
        $this->assertFalse($cap->sixel);
    }

    public function testCachedReturnsSameInstance(): void
    {
        putenv('TERM_PROGRAM=iTerm.app');
        $first  = Detect::cached();
        $second = Detect::cached();
        $this->assertSame($first, $second);
    }

    public function testCachedReturnsCapabilityInstance(): void
    {
        $cap = Detect::cached();
        $this->assertInstanceOf(\SugarCraft\Mosaic\Capability::class, $cap);
    }
}
