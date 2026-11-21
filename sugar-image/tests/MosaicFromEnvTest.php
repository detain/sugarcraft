<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Detect;
use SugarCraft\Image\Mosaic;
use SugarCraft\Image\TmuxPassthroughDecorator;
use SugarCraft\Image\Tests\Support\KittyTerminal;

/**
 * @covers \SugarCraft\Image\Mosaic::fromEnv
 * @covers \SugarCraft\Image\Mosaic::fromModeString
 * @covers \SugarCraft\Image\Mosaic::auto
 * @covers \SugarCraft\Image\Mosaic::resetAutoMemo
 * @covers \SugarCraft\Image\Detect::reset
 */
final class MosaicFromEnvTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $savedProcessEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        Detect::reset();
        Mosaic::resetAutoMemo();
        foreach (['TERM', 'TERM_PROGRAM', 'TMUX'] as $key) {
            $this->savedProcessEnv[$key] = getenv($key);
            putenv($key);
        }
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
        Mosaic::resetAutoMemo();
        Detect::reset();
        parent::tearDown();
    }

    public function testKittyEnvWithoutAReplyBuildsNoKittyMosaic(): void
    {
        // Regression (P1-13b): a pure env entry point must not emit Kitty APC bytes
        // to a terminal that never answered `a=q`, so the env claim is not Kitty.
        $protocol = Mosaic::fromEnv(['TERM' => 'xterm-kitty'])->protocol();

        $this->assertNotSame('kitty', $protocol);
        $this->assertSame('symbols', $protocol);
    }

    public function testITerm2EnvBuildsAnITerm2Mosaic(): void
    {
        $this->assertSame('iterm2', Mosaic::fromEnv(['TERM_PROGRAM' => 'iTerm.app'])->protocol());
    }

    public function testTextOnlyEnvFallsToTheSymbolsRung(): void
    {
        $this->assertSame('symbols', Mosaic::fromEnv([])->protocol());
    }

    public function testFromEnvWrapsInsideTmuxButNeverProbes(): void
    {
        $mosaic = Mosaic::fromEnv(['TERM_PROGRAM' => 'iTerm.app', 'TMUX' => '/tmp/tmux-1000/default,1,0']);

        $this->assertInstanceOf(TmuxPassthroughDecorator::class, $mosaic->renderer());
        $this->assertSame('tmux(iterm2)', $mosaic->protocol(), 'the decorator names the wrapped backend');
    }

    public function testFromEnvNullReadsTheProcessEnvironment(): void
    {
        putenv('TERM_PROGRAM=iTerm.app');

        $this->assertSame('iterm2', Mosaic::fromEnv(null)->protocol());
    }

    public function testFromModeStringAutoWithoutProbeUsesTheEnvironment(): void
    {
        putenv('TERM_PROGRAM=iTerm.app');

        $mosaic = Mosaic::fromModeString('auto', false);

        $this->assertNotNull($mosaic);
        $this->assertSame('iterm2', $mosaic->protocol());
    }

    public function testFromModeStringAutoWithoutProbeNeverBuildsKittyFromTerm(): void
    {
        putenv('TERM=xterm-kitty');

        $mosaic = Mosaic::fromModeString('auto', false);

        $this->assertNotNull($mosaic);
        $this->assertNotSame('kitty', $mosaic->protocol());
    }

    public function testFromModeStringAutoDefaultsToTheProbedPath(): void
    {
        // probe defaults to true: the same memoised auto() answer as auto().
        $this->assertSame(Mosaic::auto(), Mosaic::fromModeString('auto'));
    }

    public function testFromModeStringProbeFlagLeavesFixedModesUntouched(): void
    {
        $this->assertSame('sixel', Mosaic::fromModeString('sixel', false)?->protocol());
        $this->assertSame('halfblock', Mosaic::fromModeString('halfblock', false)?->protocol());
    }

    public function testFromModeStringUnknownModeIsNull(): void
    {
        $this->assertNull(Mosaic::fromModeString('no-such-mode', false));
    }

    public function testDetectResetDropsTheAutoMemo(): void
    {
        // Regression (candy-code ToolResultRenderModeTest): a probe reset after
        // an environment change must not hand back the pre-change auto answer.
        putenv('TERM=xterm-kitty');
        KittyTerminal::answer();
        $first = Mosaic::auto()->protocol();
        KittyTerminal::clear();
        $this->assertSame('kitty', $first);

        putenv('TERM=dumb');
        Detect::reset();

        $this->assertNotSame('kitty', Mosaic::auto()->protocol());
    }

    public function testAutoIsMemoisedUntilReset(): void
    {
        $first = Mosaic::auto();

        $this->assertSame($first, Mosaic::auto(), 'a second auto() must reuse the first probe');

        Mosaic::resetAutoMemo();

        $this->assertNotSame($first, Mosaic::auto(), 'reset must force a fresh probe');
    }
}
