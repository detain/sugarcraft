<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Detect;

/**
 * @covers \SugarCraft\Image\Detect::fromEnv
 */
final class DetectFromEnvTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $savedProcessEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        Detect::reset();
        $this->savedProcessEnv['TERM'] = getenv('TERM');
        $this->savedProcessEnv['TERM_PROGRAM'] = getenv('TERM_PROGRAM');
        $this->savedProcessEnv['TMUX'] = getenv('TMUX');
    }

    protected function tearDown(): void
    {
        foreach ($this->savedProcessEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv("{$key}={$value}");
            }
        }
        Detect::reset();
        parent::tearDown();
    }

    public function testXtermKittyTermIsKitty(): void
    {
        $cap = Detect::fromEnv(['TERM' => 'xterm-kitty']);

        $this->assertTrue($cap->kitty);
        $this->assertFalse($cap->iterm2);
        $this->assertFalse($cap->sixel);
    }

    public function testGhosttyTermIsKitty(): void
    {
        // AUD §3.9 drift: TERM=xterm-ghostty must classify without a probe.
        $cap = Detect::fromEnv(['TERM' => 'xterm-ghostty']);

        $this->assertTrue($cap->kitty);
    }

    public function testKittyWindowIdAloneIsKitty(): void
    {
        $cap = Detect::fromEnv(['KITTY_WINDOW_ID' => '7']);

        $this->assertTrue($cap->kitty);
    }

    public function testITerm2TermProgramIsITerm2(): void
    {
        $cap = Detect::fromEnv(['TERM_PROGRAM' => 'iTerm.app']);

        $this->assertTrue($cap->iterm2);
        $this->assertFalse($cap->kitty);
    }

    public function testEmptyEnvIsTextOnly(): void
    {
        $cap = Detect::fromEnv([]);

        $this->assertFalse($cap->kitty);
        $this->assertFalse($cap->iterm2);
        $this->assertFalse($cap->sixel);
        $this->assertTrue($cap->halfblock, 'the text tier is always available');
        $this->assertFalse($cap->inTmux);
    }

    public function testTmuxKeyMarksTheSessionAsInTmux(): void
    {
        $cap = Detect::fromEnv(['TERM' => 'xterm-kitty', 'TMUX' => '/tmp/tmux-1000/default,1,0']);

        $this->assertTrue($cap->inTmux);
        $this->assertTrue($cap->kitty);
    }

    public function testEmptyTmuxKeyStillMarksInTmux(): void
    {
        // probe() tests getenv('TMUX') !== false, so an empty value counts too.
        $cap = Detect::fromEnv(['TMUX' => '']);

        $this->assertTrue($cap->inTmux);
    }

    public function testExplicitEnvIgnoresTheProcessEnvironment(): void
    {
        putenv('TERM=xterm-kitty');

        $cap = Detect::fromEnv([]);

        $this->assertFalse($cap->kitty, 'an explicit map is the whole environment');
    }

    public function testNullEnvReadsTheProcessEnvironment(): void
    {
        putenv('TERM=xterm-kitty');
        putenv('TMUX');

        $cap = Detect::fromEnv(null);

        $this->assertTrue($cap->kitty);
        $this->assertFalse($cap->inTmux);
    }

    public function testFromEnvCarriesNoProbedFacts(): void
    {
        $cap = Detect::fromEnv(['TERM' => 'xterm-kitty']);

        $this->assertNull($cap->cellSize);
        $this->assertNull($cap->defaultFg);
        $this->assertNull($cap->defaultBg);
    }
}
