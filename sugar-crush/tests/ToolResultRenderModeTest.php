<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Mosaic\Detect;
use SugarCraft\Mosaic\Mosaic;

/**
 * W2.2 (plan_crush_media, crush_media §8.7 gap-1): the mosaic probe behind
 * {@see ToolResult::okWithImage()}/{@see ToolResult::withImage()} is mode-aware.
 *
 * The memo is keyed by the configured `ui.imageRenderMode` word, so a mode
 * switch re-derives instead of returning the stale probe of whichever mode was
 * asked for first, while `'auto'` keeps the pre-W2.2 detect-based behaviour
 * byte-identically (golden pin below). Detection is faked through candy-mosaic's
 * own env table (`KITTY_WINDOW_ID` answers `Detect::probe()` without any TTY —
 * the DA1/`setProbeStdin` path needs an interactive ctty a piped suite never
 * has), and every test restores the process statics in tearDown — a leaked
 * render mode or memo entry across the suite is the MAJOR the review brief
 * names (HomeSandbox-style snapshot/restore discipline).
 *
 * @see ToolResult
 * @see Bootstrap::applyMediaRenderMode()
 */
final class ToolResultRenderModeTest extends TestCase
{
    private const ENV_KEYS = ['SUGARCRUSH_MEDIA_RENDER_MODE', 'KITTY_WINDOW_ID'];

    /** @var array<string, string|false> */
    private array $envSnapshot = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::ENV_KEYS as $key) {
            $this->envSnapshot[$key] = getenv($key);
            putenv($key);
        }

        $this->resetToolResultStatics();
    }

    protected function tearDown(): void
    {
        foreach ($this->envSnapshot as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv("{$key}={$value}");
            }
        }

        $this->resetToolResultStatics();
        Detect::setProbeStdin(null);
        Detect::reset();

        parent::tearDown();
    }

    /**
     * ToolResult's memo/mode are private statics; reflection is the house
     * reset seam (Bootstrap static tests use the same shape), so ordering
     * against ToolResultImageTest in a merged run stays neutral.
     */
    private function resetToolResultStatics(): void
    {
        (new \ReflectionProperty(ToolResult::class, 'renderMode'))->setValue(null, 'auto');
        (new \ReflectionProperty(ToolResult::class, 'mosaics'))->setValue(null, []);
    }

    /**
     * Under tmux, Mosaic::auto() wraps its renderer and protocol() reports
     * 'tmux(<inner>)'; unwrap so assertions speak of the inner protocol.
     */
    private static function innerProtocol(string $protocol): string
    {
        return str_starts_with($protocol, 'tmux(') && str_ends_with($protocol, ')')
            ? substr($protocol, 5, -1)
            : $protocol;
    }

    // =========================================================================
    // Default 'auto' — pre-W2.2 behaviour, golden
    // =========================================================================

    public function testDefaultRenderModeIsAuto(): void
    {
        self::assertSame('auto', ToolResult::renderMode());
    }

    public function testAutoModeProbesTheTerminalUnchanged(): void
    {
        // Golden: with no setRenderMode call the answer is exactly what
        // Mosaic::auto() says right now (live re-probe), i.e. the memo
        // change is behaviour-preserving for the default mode — same value
        // okWithImage() stamped before W2.2 existed.
        $expected = self::innerProtocol(Mosaic::auto()->protocol());

        $viaMosaic = self::innerProtocol(ToolResult::mosaic()->protocol());
        $viaResult = self::innerProtocol(ToolResult::okWithImage('shot', 'ok', 'bytes')->imageProtocol);

        self::assertSame($expected, $viaMosaic);
        self::assertSame($expected, (string) $viaResult);
    }

    public function testAutoUnderFakedKittyEnvironmentDetectsKitty(): void
    {
        // The detect-based half stays detect-based: candy-mosaic's env table
        // answers definitively for kitty without any TTY (DA1 is skipped).
        putenv('KITTY_WINDOW_ID=1');
        Detect::reset();

        self::assertSame('kitty', self::innerProtocol(ToolResult::mosaic()->protocol()));
    }

    // =========================================================================
    // Forced modes — vocabulary flows through Mosaic::fromModeString()
    // =========================================================================

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function forcedModeProvider(): array
    {
        return [
            'halfblock' => ['halfblock', true],
            'quarterblock' => ['quarterblock', true],
            'ascii' => ['ascii', true],
            'kitty' => ['kitty', false],
            'iterm2' => ['iterm2', false],
            'sixel' => ['sixel', false],
        ];
    }

    /**
     * @dataProvider forcedModeProvider
     */
    public function testForcedModeSelectsThatRenderer(string $mode, bool $isInline): void
    {
        putenv('KITTY_WINDOW_ID=1'); // auto WOULD say kitty; the mode must win
        Detect::reset();
        ToolResult::setRenderMode($mode);

        $mosaic = ToolResult::mosaic();

        self::assertSame($mode, $mosaic->protocol());
        self::assertSame($isInline, $mosaic->isInline());
        self::assertSame($mode, ToolResult::okWithImage('shot', 'ok', 'bytes')->imageProtocol);
    }

    public function testForcedModeIgnoresFakedDetectionForImageResults(): void
    {
        // crush_media §8.7's DoD shape: a kitty-capable terminal forced to
        // halfblock paints inline anyway.
        putenv('KITTY_WINDOW_ID=1');
        Detect::reset();
        ToolResult::setRenderMode('halfblock');

        $result = ToolResult::ok('shot', 'ok')->withImage('bytes');

        self::assertSame('halfblock', $result->imageProtocol);
    }

    // =========================================================================
    // Memo semantics — per-mode, invalidation on switch, identity on repeat
    // =========================================================================

    public function testSameModeReturnsSameInstance(): void
    {
        ToolResult::setRenderMode('halfblock');

        self::assertSame(ToolResult::mosaic(), ToolResult::mosaic());
    }

    public function testModeChangeInvalidatesTheStaleAutoMemo(): void
    {
        // The exact regression W2.2 fixes: probe under auto (faked kitty),
        // THEN switch — the old single-slot memo handed the kitty instance
        // back forever.
        putenv('KITTY_WINDOW_ID=1');
        Detect::reset();
        $auto = ToolResult::mosaic();
        self::assertSame('kitty', self::innerProtocol($auto->protocol()));

        ToolResult::setRenderMode('halfblock');
        $forced = ToolResult::mosaic();

        self::assertNotSame($auto, $forced);
        self::assertSame('halfblock', $forced->protocol());

        // And back to auto the memo replays the ORIGINAL probe, not a re-derive.
        ToolResult::setRenderMode('auto');
        self::assertSame($auto, ToolResult::mosaic());
    }

    // =========================================================================
    // Coercion — normalization and unknown words
    // =========================================================================

    public function testSetRenderModeNormalizesCaseAndWhitespace(): void
    {
        ToolResult::setRenderMode('  HalfBlock ');

        self::assertSame('halfblock', ToolResult::renderMode());
        self::assertSame('halfblock', ToolResult::mosaic()->protocol());
    }

    public function testBlankModeNormalizesToAuto(): void
    {
        ToolResult::setRenderMode('   ');

        self::assertSame('auto', ToolResult::renderMode());
    }

    public function testUnknownModeFailsSoftToAutoWithoutThrowing(): void
    {
        // The plan's ruling: validator upstream, silent auto-fallback here.
        putenv('KITTY_WINDOW_ID=1');
        Detect::reset();
        ToolResult::setRenderMode('bitmap');

        self::assertSame('bitmap', ToolResult::renderMode());
        self::assertSame(
            self::innerProtocol(Mosaic::auto()->protocol()),
            self::innerProtocol(ToolResult::mosaic()->protocol()),
        );
    }

    // =========================================================================
    // Bootstrap wiring — one setter call, env > config > auto precedence
    // =========================================================================

    public function testApplyMediaRenderModeReadsTheConfiguredValue(): void
    {
        Bootstrap::applyMediaRenderMode(['ui.imageRenderMode' => 'ascii']);

        self::assertSame('ascii', ToolResult::renderMode());
    }

    public function testApplyMediaRenderModeEnvWinsOverConfig(): void
    {
        putenv('SUGARCRUSH_MEDIA_RENDER_MODE=chafa');

        Bootstrap::applyMediaRenderMode(['ui.imageRenderMode' => 'ascii']);

        // chafa shells out at RENDER time only; selecting it must not probe
        // the binary, so asserting the stored word (not a render) is honest.
        self::assertSame('chafa', ToolResult::renderMode());
    }

    public function testApplyMediaRenderModeTreatsEmptyEnvAsAbsence(): void
    {
        putenv('SUGARCRUSH_MEDIA_RENDER_MODE=');

        Bootstrap::applyMediaRenderMode(['ui.imageRenderMode' => 'sixel']);

        self::assertSame('sixel', ToolResult::renderMode());
    }

    public function testApplyMediaRenderModeDefaultsToAuto(): void
    {
        Bootstrap::applyMediaRenderMode([]);

        self::assertSame('auto', ToolResult::renderMode());
        Bootstrap::applyMediaRenderMode(['ui.imageRenderMode' => 404]); // junk typed value => auto
        self::assertSame('auto', ToolResult::renderMode());
    }

    public function testTheProductionLaunchWiresTheModeBeforeThreadingTheMosaic(): void
    {
        // Structural pin on Bootstrap::chat(): exactly one setter-call region
        // (review brief), and it runs BEFORE the `mosaic:` argument memoizes
        // the first probe — after that point the wrong mode would stick.
        $method = new \ReflectionMethod(Bootstrap::class, 'chat');
        $file = file($method->getFileName());
        self::assertIsArray($file);
        $slice = implode('', array_slice($file, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        // Line-anchored (no leading `//` possible) so commenting the call out
        // reddens this pin instead of still matching as a substring.
        self::assertSame(1, preg_match_all('/^ {8}self::applyMediaRenderMode\(\$userConfig\);$/m', $slice));
        $wiring = strpos($slice, 'self::applyMediaRenderMode($userConfig);');
        $threading = strpos($slice, 'mosaic: ToolResult::mosaic(),');
        self::assertNotFalse($threading);
        self::assertLessThan($threading, $wiring);
    }
}
