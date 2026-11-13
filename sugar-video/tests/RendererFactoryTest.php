<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\ColorProfile\ColorProfile;
use SugarCraft\Video\Render\AsciiRenderer;
use SugarCraft\Video\Render\FrameRenderer;
use SugarCraft\Video\Render\GraphicsRenderer;
use SugarCraft\Video\Render\HalfBlockRenderer;
use SugarCraft\Video\Render\LumaRamp;
use SugarCraft\Video\Render\Mode;
use SugarCraft\Video\Render\RendererFactory;
use SugarCraft\Video\Render\Support;
use SugarCraft\Video\Player;

/**
 * Unit tests for RendererFactory — creates FrameRenderer instances by Mode.
 *
 * @covers \SugarCraft\Video\Render\RendererFactory
 */
final class RendererFactoryTest extends TestCase
{
    // -------------------------------------------------------------------------
    // create() returns correct renderer types
    // -------------------------------------------------------------------------

    /**
     * @testdox create(Mode::Ascii) returns an AsciiRenderer instance
     */
    public function testCreateReturnsAsciiRendererForAsciiMode(): void
    {
        $renderer = RendererFactory::new(Mode::Ascii);

        $this->assertInstanceOf(AsciiRenderer::class, $renderer);
        $this->assertInstanceOf(FrameRenderer::class, $renderer);
    }

    /**
     * @testdox create(Mode::Ansi256) returns an AsciiRenderer instance
     */
    public function testCreateReturnsAnsi256RendererForAnsi256Mode(): void
    {
        $renderer = RendererFactory::new(Mode::Ansi256);

        $this->assertInstanceOf(AsciiRenderer::class, $renderer);
        $this->assertInstanceOf(FrameRenderer::class, $renderer);
    }

    /**
     * @testdox create(Mode::TrueColor) returns an AsciiRenderer instance
     */
    public function testCreateReturnsAsciiRendererForTrueColorMode(): void
    {
        $renderer = RendererFactory::new(Mode::TrueColor);

        $this->assertInstanceOf(AsciiRenderer::class, $renderer);
        $this->assertInstanceOf(FrameRenderer::class, $renderer);
    }

    /**
     * @testdox create(Mode::HalfBlock) returns a HalfBlockRenderer instance
     */
    public function testCreateReturnsHalfBlockRendererForHalfBlockMode(): void
    {
        $renderer = RendererFactory::new(Mode::HalfBlock);

        $this->assertInstanceOf(HalfBlockRenderer::class, $renderer);
        $this->assertInstanceOf(FrameRenderer::class, $renderer);
    }

    // -------------------------------------------------------------------------
    // Graphics modes return GraphicsRenderer
    // -------------------------------------------------------------------------

    /**
     * @testdox create(Mode::Sixel) returns a GraphicsRenderer instance
     */
    public function testCreateReturnsGraphicsRendererForSixelMode(): void
    {
        \SugarCraft\Video\Render\RendererFactory::setDiagnoseForTesting(new \SugarCraft\ColorProfile\Probe\ProbeReport([\SugarCraft\ColorProfile\Probe\Capability::Sixel->value => true, \SugarCraft\ColorProfile\Probe\Capability::KittyKeyboard->value => true, \SugarCraft\ColorProfile\Probe\Capability::ITerm2->value => true]));
        $renderer = RendererFactory::new(Mode::Sixel);

        $this->assertInstanceOf(GraphicsRenderer::class, $renderer);
        $this->assertInstanceOf(FrameRenderer::class, $renderer);
    }

    /**
     * @testdox create(Mode::Kitty) returns a GraphicsRenderer instance
     */
    public function testCreateReturnsGraphicsRendererForKittyMode(): void
    {
        \SugarCraft\Video\Render\RendererFactory::setDiagnoseForTesting(new \SugarCraft\ColorProfile\Probe\ProbeReport([\SugarCraft\ColorProfile\Probe\Capability::Sixel->value => true, \SugarCraft\ColorProfile\Probe\Capability::KittyKeyboard->value => true, \SugarCraft\ColorProfile\Probe\Capability::ITerm2->value => true]));
        $renderer = RendererFactory::new(Mode::Kitty);

        $this->assertInstanceOf(GraphicsRenderer::class, $renderer);
        $this->assertInstanceOf(FrameRenderer::class, $renderer);
    }

    /**
     * @testdox create(Mode::Iterm2) returns a GraphicsRenderer instance
     */
    public function testCreateReturnsGraphicsRendererForIterm2Mode(): void
    {
        \SugarCraft\Video\Render\RendererFactory::setDiagnoseForTesting(new \SugarCraft\ColorProfile\Probe\ProbeReport([\SugarCraft\ColorProfile\Probe\Capability::Sixel->value => true, \SugarCraft\ColorProfile\Probe\Capability::KittyKeyboard->value => true, \SugarCraft\ColorProfile\Probe\Capability::ITerm2->value => true]));
        $renderer = RendererFactory::new(Mode::Iterm2);

        $this->assertInstanceOf(GraphicsRenderer::class, $renderer);
        $this->assertInstanceOf(FrameRenderer::class, $renderer);
    }

    /**
     * @testdox create() accepts explicit cellPx args and still yields a GraphicsRenderer for graphics modes
     *
     * The trailing $cellPxW/$cellPxH args (used by graphics modes to recover the
     * cell footprint and size the sixel canvas) must not change the renderer type.
     *
     * @dataProvider graphicsModeProvider
     */
    public function testCreateWithExplicitCellPxReturnsGraphicsRenderer(Mode $mode): void
    {
        \SugarCraft\Video\Render\RendererFactory::setDiagnoseForTesting(new \SugarCraft\ColorProfile\Probe\ProbeReport([\SugarCraft\ColorProfile\Probe\Capability::Sixel->value => true, \SugarCraft\ColorProfile\Probe\Capability::KittyKeyboard->value => true, \SugarCraft\ColorProfile\Probe\Capability::ITerm2->value => true]));
        $renderer = RendererFactory::new($mode, 'standard', 12, 24);

        $this->assertInstanceOf(GraphicsRenderer::class, $renderer);
        $this->assertInstanceOf(FrameRenderer::class, $renderer);
    }

    /** @return list<array{Mode}> */
    public static function graphicsModeProvider(): array
    {
        return [
            'Sixel'  => [Mode::Sixel],
            'Kitty'  => [Mode::Kitty],
            'Iterm2' => [Mode::Iterm2],
        ];
    }

    /**
     * @testdox create() with explicit cellPx leaves the text modes as their normal renderers
     */
    public function testCreateWithExplicitCellPxTextModesUnchanged(): void
    {
        $this->assertInstanceOf(AsciiRenderer::class, RendererFactory::new(Mode::Ascii, 'standard', 12, 24));
        $this->assertInstanceOf(HalfBlockRenderer::class, RendererFactory::new(Mode::HalfBlock, 'standard', 12, 24));
    }

    // -------------------------------------------------------------------------
    // auto() selection
    // -------------------------------------------------------------------------

    /**
     * @testdox auto() returns an object that implements FrameRenderer
     */
    public function testAutoReturnsRenderer(): void
    {
        $renderer = RendererFactory::auto();

        $this->assertInstanceOf(FrameRenderer::class, $renderer);
    }

    /**
     * @testdox auto() returns a renderer without throwing (selects best available mode)
     */
    public function testAutoSelectsBestAvailableMode(): void
    {
        // auto() should never throw — it probes capabilities and falls back safely.
        $renderer = RendererFactory::auto();

        $this->assertInstanceOf(FrameRenderer::class, $renderer);
    }

    /**
     * @testdox auto($preferred) returns create($preferred) when a preferred mode is given
     */
    public function testAutoWithPreferredModeReturnsThatRenderer(): void
    {
        // When a preferred mode is given, it should be used directly.
        $preferred = RendererFactory::auto(Mode::Ascii);
        $direct    = RendererFactory::new(Mode::Ascii);

        $this->assertInstanceOf(AsciiRenderer::class, $preferred);
        $this->assertInstanceOf(AsciiRenderer::class, $direct);
    }

    // -------------------------------------------------------------------------
    // autoMode() — returns the Mode enum case directly (F3)
    // -------------------------------------------------------------------------

    /**
     * Regression for F3. RendererFactory::autoMode() must return a Mode enum
     * case (not null, not a renderer) so Reel::play() can resolve the
     * auto-detected Mode once and hand Player::open() the concrete case it
     * threads into the decoder and renderer — the auto-detected mode was
     * previously only used internally by auto() and never surfaced.
     *
     * @testdox autoMode() returns a Mode enum case
     */
    public function testAutoModeReturnsModeEnumCase(): void
    {
        $mode = RendererFactory::autoMode();

        $this->assertInstanceOf(Mode::class, $mode);
    }

    /**
     * Regression for F3. autoMode() must return one of the 7 known Mode cases
     * (never null, never throw) regardless of the capabilities reported at runtime.
     *
     * @testdox autoMode() returns a valid Mode that is one of the 7 cases
     */
    public function testAutoModeReturnsValidMode(): void
    {
        $mode = RendererFactory::autoMode();

        $validModes = Mode::cases();
        $this->assertContains($mode, $validModes,
            'autoMode() must return one of the known Mode::cases()');
    }

    /**
     * Regression for F3. autoMode() must not throw even when the terminal
     * reports no special capabilities — it must fall back to Ascii at minimum.
     *
     * @testdox autoMode() does not throw on a minimal-capability terminal
     */
    public function testAutoModeDoesNotThrowWithNoCapabilities(): void
    {
        // This test just verifies the method is callable without exception.
        // The real capability probing happens at runtime; we test the
        // fallback path doesn't crash.
        $mode = RendererFactory::autoMode();

        $this->assertInstanceOf(Mode::class, $mode);
    }

    // -------------------------------------------------------------------------
    // FrameRenderer contract — all created renderers implement the interface
    // -------------------------------------------------------------------------

    /**
     * @testdox every mode that create() can produce (without throwing) has render() and cellDimensions()
     */
    public function testAllImplementedModesHaveRequiredInterfaceMethods(): void
    {
        $implementedModes = [
            Mode::Ascii,
            Mode::Ansi256,
            Mode::TrueColor,
            Mode::HalfBlock,
            Mode::QuarterBlock,
        ];

        foreach ($implementedModes as $mode) {
            $renderer = RendererFactory::new($mode);

            $this->assertTrue(
                method_exists($renderer, 'render'),
                "{$mode->value} renderer missing render() method"
            );
            $this->assertTrue(
                method_exists($renderer, 'cellDimensions'),
                "{$mode->value} renderer missing cellDimensions() method"
            );

            // render() must accept (RgbFrame, Mode) and return string.
            $frame = new \SugarCraft\Video\Decode\RgbFrame("\x00\x00\x00", 1, 1);
            $result = $renderer->render($frame, $mode);
            $this->assertIsString($result, "render() should return string for {$mode->value}");

            // cellDimensions() must return array with w and h.
            $dims = $renderer->cellDimensions($mode);
            $this->assertIsArray($dims);
            $this->assertArrayHasKey('w', $dims);
            $this->assertArrayHasKey('h', $dims);

            // SV-L51: the renderer's answer IS Mode's answer — one packing source.
            $this->assertSame(
                ['w' => $mode->colsPerCell(), 'h' => $mode->rowsPerCell()],
                $dims,
                "{$mode->value} renderer still holds its own packing table"
            );
        }
    }

    /**
     * @testdox cellDimensions answers the mode it is passed, not the renderer's own mode (SV-L51)
     */
    public function testCellDimensionsFollowThePassedModeNotTheRenderer(): void
    {
        $ascii = RendererFactory::new(Mode::Ascii);
        $this->assertSame(['w' => 2, 'h' => 2], $ascii->cellDimensions(Mode::QuarterBlock));

        $half = RendererFactory::new(Mode::HalfBlock);
        $this->assertSame(['w' => 1, 'h' => 1], $half->cellDimensions(Mode::Ansi256));
    }

    /**
     * @testdox no renderer file keeps numeric packing literals — cell-packing arithmetic lives only in Mode (SV-L51)
     */
    public function testCellPackingArithmeticLivesOnlyInMode(): void
    {
        foreach (['AsciiRenderer', 'HalfBlockRenderer', 'QuarterBlockRenderer', 'GraphicsRenderer'] as $class) {
            $source = (string) file_get_contents(dirname(__DIR__) . '/src/Render/' . $class . '.php');

            $this->assertStringContainsString(
                '$mode->colsPerCell()',
                $source,
                "{$class}::cellDimensions no longer delegates to Mode"
            );
            $this->assertDoesNotMatchRegularExpression(
                "/'w' => \d/",
                $source,
                "{$class} still carries a hardcoded cell-packing table"
            );
        }
    }

    // -------------------------------------------------------------------------
    // F-06 — autoModeFrom(): symbol-safe tier selection (pure seam, no probing)
    // -------------------------------------------------------------------------

    /**
     * F-06: on a Unicode-capable seat the documented ladder stands —
     * TrueColor answers the HalfBlock rung.
     */
    public function testAutoModeFromKeepsHalfBlockOnUnicodeCapableTerminals(): void
    {
        $modern = \SugarCraft\Image\TermDb::matchChain(['TERM' => 'xterm-256color']);
        $this->assertSame(
            Mode::HalfBlock,
            RendererFactory::autoModeFrom(false, false, false, ColorProfile::TrueColor, $modern),
        );
    }

    /**
     * F-06: on an ASCII-only seat (TERM=linux console — chicle's
     * LINUX_CONSOLE_SYMS row) the TrueColor rung cannot draw ▀/▄, so it
     * steps down to the Ansi256 rung. Without the gate this stays HalfBlock
     * and this pin goes red.
     */
    public function testAutoModeFromDemotesHalfBlockOnAsciiOnlySeats(): void
    {
        $console = \SugarCraft\Image\TermDb::matchChain(['TERM' => 'linux']);
        $this->assertSame(
            Mode::Ansi256,
            RendererFactory::autoModeFrom(false, false, false, ColorProfile::TrueColor, $console),
            'TrueColor on the Linux console must not pick the block-glyph rung',
        );
    }

    /**
     * F-06: the Windows console family (BLOCK|BORDER safe) keeps the
     * half-block rung — the gate narrows by drawn class, not by OS.
     */
    public function testAutoModeFromWindowsConsoleKeepsHalfBlock(): void
    {
        $win = \SugarCraft\Image\TermDb::matchChain(['ComSpec' => 'C:\\Windows\\System32\\cmd.exe']);
        $this->assertSame(
            Mode::HalfBlock,
            RendererFactory::autoModeFrom(false, false, false, ColorProfile::TrueColor, $win),
        );
    }

    /**
     * F-06: the graphics rungs are not text-cell glyphs — a sixel-capable
     * Linux console (theoretically) still gets Sixel; the symbol gate
     * touches only the TrueColor rung. Precedence above the gate is intact.
     */
    public function testAutoModeFromSymbolGateAppliesOnlyToTextCellRung(): void
    {
        $console = \SugarCraft\Image\TermDb::matchChain(['TERM' => 'linux']);
        $this->assertSame(Mode::Sixel, RendererFactory::autoModeFrom(true, false, false, ColorProfile::TrueColor, $console));
        $this->assertSame(Mode::Kitty, RendererFactory::autoModeFrom(false, true, false, ColorProfile::TrueColor, $console));
        $this->assertSame(Mode::Iterm2, RendererFactory::autoModeFrom(false, false, true, ColorProfile::TrueColor, $console));
    }

    /**
     * F-06: rungs below TrueColor are ASCII-ramp modes already — the gate
     * never demotes them further.
     */
    public function testAutoModeFromLowerRungsPassThroughUntouched(): void
    {
        $console = \SugarCraft\Image\TermDb::matchChain(['TERM' => 'linux']);
        $this->assertSame(Mode::Ansi256, RendererFactory::autoModeFrom(false, false, false, ColorProfile::Ansi256, $console));
        $this->assertSame(Mode::Ascii, RendererFactory::autoModeFrom(false, false, false, ColorProfile::Ascii, $console));
        $this->assertSame(Mode::Ascii, RendererFactory::autoModeFrom(false, false, false, null, $console));
    }

    /**
     * F-06 (survey correction): the luma ramps ARE the safe tier — all three
     * named ramps are pure 7-bit ASCII, which is why LumaRamp itself needs
     * no TermDb consult (there is no Unicode glyph in the ladder to gate).
     * Pinning the purity keeps the correction honest if a fancy ramp is ever
     * added — that ramp would then need the symbol gate at selection time.
     */
    public function testLumaRampTiersArePureAsciiToday(): void
    {
        foreach ([LumaRamp::CHARS1, LumaRamp::CHARS2, LumaRamp::CHARS3] as $ramp) {
            for ($i = 0; $i < strlen($ramp); $i++) {
                $this->assertLessThan(128, ord($ramp[$i]), "non-ASCII byte in luma ramp at offset {$i}");
            }
        }
    }

    /**
     * @testdox SV-M35/035: supportShape() folds every verdict arm — protocol claim, ext-gd, block glyphs, the SV-H7 window — caller geometry errors first
     */
    public function testSupportShapeFoldsEveryVerdictArm(): void
    {
        $this->assertSame(Support::NeedsProtocol, RendererFactory::supportShape(Mode::Sixel, false, true, true));
        $this->assertSame(Support::Supported, RendererFactory::supportShape(Mode::Sixel, true, true, true));
        $this->assertSame(Support::NeedsGd, RendererFactory::supportShape(Mode::Iterm2, true, false, true));

        // The glyphs arm belongs to the block rungs; pure-SGR text never gates on them.
        $this->assertSame(Support::NeedsBlockGlyphs, RendererFactory::supportShape(Mode::HalfBlock, true, true, false));
        $this->assertSame(Support::NeedsBlockGlyphs, RendererFactory::supportShape(Mode::QuarterBlock, true, true, false));
        $this->assertSame(Support::Supported, RendererFactory::supportShape(Mode::TrueColor, true, true, false));
        $this->assertSame(Support::Supported, RendererFactory::supportShape(Mode::Ascii, false, false, false));

        // Geometry arms ride the single-sourced SV-H7 window (rows < 5 refuse),
        // and a caller's illegal grid is reported ahead of the seat's fortune.
        $this->assertSame(Support::GeometryTooSmall, RendererFactory::supportShape(Mode::TrueColor, true, true, true, Player::MIN_COLS, Player::MIN_ROWS - 1));
        $this->assertSame(Support::GeometryTooSmall, RendererFactory::supportShape(Mode::HalfBlock, true, true, false, Player::MIN_COLS - 1, 40));
        $this->assertSame(Support::GeometryTooLarge, RendererFactory::supportShape(Mode::HalfBlock, true, true, true, Player::MAX_COLS, Player::MAX_ROWS + 1));
        $this->assertSame(Support::Supported, RendererFactory::supportShape(Mode::HalfBlock, true, true, true, Player::MIN_COLS, Player::MIN_ROWS), 'the window floor itself renders');
        $this->assertSame(Support::GeometryTooSmall, RendererFactory::supportShape(Mode::Kitty, false, true, true, 1, 1));
    }

    /**
     * @testdox SV-M35/035: support()/canRender() read the scripted seams (H9-era WezTerm lesson as data; notice() speaks the branded diagnostic voice)
     */
    public function testSupportAndCanRenderReadTheScriptedDoors(): void
    {
        RendererFactory::setDiagnoseForTesting(new \SugarCraft\ColorProfile\Probe\ProbeReport([
            \SugarCraft\ColorProfile\Probe\Capability::Sixel->value => true,
        ]));
        RendererFactory::setGraphicsGateForTesting(static fn (): bool => true);
        $this->assertTrue(RendererFactory::canRender(Mode::Sixel, 80, 24));
        $this->assertSame(Support::Supported, RendererFactory::support(Mode::Sixel, 80, 24));

        RendererFactory::setGraphicsGateForTesting(static fn (): bool => false);
        $this->assertSame(Support::NeedsGd, RendererFactory::support(Mode::Sixel, 80, 24));
        $this->assertFalse(RendererFactory::canRender(Mode::Sixel, 80, 24));

        $this->assertSame(Support::NeedsProtocol, RendererFactory::support(Mode::Iterm2, 80, 24), 'a seat that only claimed Sixel gets the verdict, not an exception');
        $this->assertSame(Support::GeometryTooSmall, RendererFactory::support(Mode::Sixel, 80, 4), 'rows < 5 refuses even with every claim true');

        $this->assertStringStartsWith('sugar-video: ', RendererFactory::support(Mode::Iterm2)->notice(['mode' => 'iterm2', 'protocol' => 'OSC 1337']));
        RendererFactory::setGraphicsGateForTesting(null);
    }

    /**
     * @testdox SV-H9/009: a WezTerm seat is a TrueColor claim ONLY (sugar-colorprofile's TerminalProbe table maps TERM_PROGRAM=WezTerm to Capability::TrueColor, never ITerm2), so supportedModes() excludes the iterm2 rung the README once promised it — pinned through the scripted seam, no env poisoning
     */
    public function testWezTermTrueColorOnlyReportNeverClaimsTheIterm2Rung(): void
    {
        // Mirrors the foreign probe's WezTerm row as read from disk
        // (TerminalProbe's table — the colorprofile lane owns it; sugar-video
        // documents and obeys). The seam feeds the report the real probe
        // would hand this seat.
        RendererFactory::setDiagnoseForTesting(new \SugarCraft\ColorProfile\Probe\ProbeReport([
            \SugarCraft\ColorProfile\Probe\Capability::TrueColor->value => true,
        ]));

        $modes = RendererFactory::supportedModes();
        $this->assertNotContains(Mode::Iterm2, $modes, 'the OSC 1337 door is closed when no seat claimed ITerm2');
        $this->assertNotContains(Mode::Sixel, $modes);
        $this->assertNotContains(Mode::Kitty, $modes);
        $this->assertContains(Mode::TrueColor, $modes, 'the one claim WezTerm does make arrives as a text rung');
        $this->assertNotSame(Mode::Iterm2, RendererFactory::autoMode(), 'and the auto ladder never ascends to the rung either');
    }

    protected function tearDown(): void
    {
        \SugarCraft\Video\Render\RendererFactory::setDiagnoseForTesting(null);
    }
}