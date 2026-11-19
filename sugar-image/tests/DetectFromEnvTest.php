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

    public function testXtermKittyTermIsNotKittyWithoutAReply(): void
    {
        // The env claim is a hypothesis: fromEnv never asks, so Kitty stays unconfirmed (P1-13b).
        $cap = Detect::fromEnv(['TERM' => 'xterm-kitty']);

        $this->assertFalse($cap->kitty);
        $this->assertFalse($cap->iterm2);
        $this->assertFalse($cap->sixel);
    }

    public function testGhosttyTermIsNotKittyWithoutAReply(): void
    {
        $cap = Detect::fromEnv(['TERM' => 'xterm-ghostty']);

        $this->assertFalse($cap->kitty);
    }

    public function testKittyWindowIdAloneIsNotKittyWithoutAReply(): void
    {
        $cap = Detect::fromEnv(['KITTY_WINDOW_ID' => '7']);

        $this->assertFalse($cap->kitty);
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
        $this->assertFalse($cap->kitty, 'a tmux pane with a Kitty env claim is not Kitty without the reply');
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
        putenv('TERM=xterm-256color');
        putenv('TERM_PROGRAM=iTerm.app');
        putenv('TMUX');

        $cap = Detect::fromEnv(null);

        $this->assertTrue($cap->iterm2, 'null reads the process environment');
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
