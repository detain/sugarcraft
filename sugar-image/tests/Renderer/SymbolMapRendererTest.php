<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Renderer\SymbolMapRenderer;
use SugarCraft\Image\Symbol\CanvasMode;

/**
 * The symbol renderer's emission contract (plan_chafa S6.2).
 *
 * The byte digests below are the double-sourced regression floor for the
 * port-fidelity fixes this lane shipped: chafa's eval_symbol never
 * consumes the Hamming shortlist's inversion flag (the winner paints its
 * OWN coverage — chafa-symbol-renderer.c:340-395), and Fgbg runs the
 * fg-only discipline on the terminal's OSC 10/11 default pair (F-08
 * consumer wire). Re-introducing the inverted-paint flip, or scoring
 * truecolor pens under a fixed palette, moves these digests.
 */
final class SymbolMapRendererTest extends TestCase
{
    private const QB = __DIR__ . '/../fixtures/4x4_qb.png';

    private const GRADIENT = __DIR__ . '/../fixtures/gradient_64x64.png';

    private const NOISE = __DIR__ . '/../fixtures/500x400_noise.png';

    public function testTheTrueColorPensAndGlyphsAreByteFrozen(): void
    {
        $image = ImageSource::fromFile(self::QB);

        $this->assertSame('24829bdb20ffc08cbd7eb2b09adba5220f7e08ce', sha1((new SymbolMapRenderer())->render($image, 4)));
    }

    public function testAnsi16ScoresTheQuantizedPensNotTheTruecolorOnes(): void
    {
        // Discriminating digest: scoring BEFORE the palette snap
        // (pre-quantized means) picks the truecolor-optimal glyph and
        // moves this hash — the mutation proof runs exactly that swap.
        $image = ImageSource::fromFile(self::QB);
        $renderer = (new SymbolMapRenderer())->withCanvasMode(CanvasMode::Ansi16);

        $out = $renderer->render($image, 4);
        $this->assertSame('1bdb5b1d57b535a1b5efe1f2849acf89dba25ec2', sha1($out));
        $this->assertMatchesRegularExpression('/\e\[(?:3[0-7]|9[0-7])m/', $out);
        $this->assertStringNotContainsString('38;2;', $out);
    }

    public function testTheExhaustiveDialMovesThePickOnTheSameFixture(): void
    {
        // The slow path is a different (never worse) search — on
        // this fixture it lands different bytes, and both digests below
        // are stable across processes.
        $image = ImageSource::fromFile(self::QB);

        $fast = (new SymbolMapRenderer())->render($image, 4);
        $slow = (new SymbolMapRenderer())->withWorkFactor(1.0)->render($image, 4);

        $this->assertNotSame($fast, $slow);
        $this->assertSame('4e4ca0847ee3d4395be1e89d4740e7f11afd2931', sha1($slow));
    }

    public function testSymbolSelectorsReachTheCorpusAndTheCacheFingerprint(): void
    {
        // The `--symbols=` selector rides the renderer —
        // a braille-only corpus cannot pick the block glyphs the default
        // corpus prefers on this fixture, so the bytes move, and the
        // fingerprint (poster-cache key) records the selection.
        $image = ImageSource::fromFile(self::QB);
        $default = new SymbolMapRenderer();

        $braille = $default->withSymbolSelectors('braille');
        $ascii = $default->withSymbolSelectors('ascii');

        $this->assertSame('', $default->configFingerprint());
        $this->assertSame('symbols=braille', $braille->configFingerprint());
        $this->assertSame('braille', $braille->symbolSelector());

        $defaultBytes = $default->render($image, 4);
        $brailleBytes = $braille->render($image, 4);
        $asciiBytes = $ascii->render($image, 4);

        $this->assertNotSame($defaultBytes, $brailleBytes);
        $this->assertNotSame($defaultBytes, $asciiBytes);
        $this->assertNotSame($brailleBytes, $asciiBytes);

        // Braille-only output can only paint braille glyphs (U+2800–U+28FF).
        preg_match_all('/[\x{2800}-\x{28FF}]/u', $brailleBytes, $m);
        $this->assertGreaterThan(0, count($m[0]));
        preg_match_all('/[\x{2500}-\x{259F}]/u', $brailleBytes, $blocks);
        $this->assertSame([], $blocks[0], 'no block glyph may survive a braille-only selection');
    }

    public function testAnUnknownSymbolSelectorThrowsAtConfigurationTime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SymbolMapRenderer())->withSymbolSelectors('notaclass');
    }

    public function testAFullyTransparentCanvasIsBareWhitespace(): void
    {
        $img = imagecreatetruecolor(8, 8);
        imagealphablending($img, false);
        $hole = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefilledrectangle($img, 0, 0, 7, 7, $hole);
        imagesavealpha($img, true);

        $out = (new SymbolMapRenderer())->render(ImageSource::fromGd($img, 'image/png'), 1);

        $this->assertSame(' ', $out);
        $this->assertStringNotContainsString("\e", $out);
    }

    public function testFgbgEmitsPureGlyphsOnTheTerminalDefaults(): void
    {
        $image = ImageSource::fromFile(self::GRADIENT);
        $out = (new SymbolMapRenderer())->withCanvasMode(CanvasMode::Fgbg)->render($image, 4);

        $this->assertStringNotContainsString('38;', $out);
        $this->assertStringNotContainsString('48;', $out);
        $this->assertStringNotContainsString(chr(27), $out);
    }

    public function testFgbgHonoursTheCapabilityDefaultColourPairF08(): void
    {
        $image = ImageSource::fromFile(self::GRADIENT);

        $whiteOnBlack = (new SymbolMapRenderer(defaultFg: 'ffffff', defaultBg: '000000'))
            ->withCanvasMode(CanvasMode::Fgbg)->render($image, 4);
        $fallback = (new SymbolMapRenderer())
            ->withCanvasMode(CanvasMode::Fgbg)->render($image, 4);
        $blackOnWhite = (new SymbolMapRenderer(defaultFg: '000000', defaultBg: 'ffffff'))
            ->withCanvasMode(CanvasMode::Fgbg)->render($image, 4);

        // Un-probed terminals keep the historical white/black defaults...
        $this->assertSame($whiteOnBlack, $fallback);
        // ...but a probed inverted terminal must not be colour-blind.
        $this->assertNotSame($whiteOnBlack, $blackOnWhite);
    }

    public function testTheFlatShortcutAndTransparentVotingStayDisclosedShape(): void
    {
        // A uniform cell renders the solid block with the shared colour on
        // both pens (QBR isFlat precedent); the digest pins the pair.
        $img = imagecreatetruecolor(8, 8);
        imagefill($img, 0, 0, imagecolorallocate($img, 12, 200, 60));
        $out = (new SymbolMapRenderer())->render(ImageSource::fromGd($img, 'image/png'), 1);

        $this->assertStringContainsString("\u{2588}", $out);
        $this->assertStringContainsString('38;2;12;200;60', $out);
    }

    public function testTheSolidShortcutVetoesGlyphsOutsideTheSelection(): void
    {
        // Rev-w10-2 M2: chafa's find_best_solid_char
        // (chafa/chafa-canvas.c:335-374) guards the solid emission with
        // has_symbol(0x2588) and falls through a <=32-pixel candidate
        // ladder when the selection lacks it. The pre-fix shortcut was
        // unguarded: `--symbols=braille` still painted U+2588 into every
        // flat cell (449 of 450 cells in the reviewer's production proof).
        $swatch = self::solidSwatch(12, 200, 60);

        // Ladder hit: the legacy tier holds no U+2588, but its 7/8 fills
        // leave 7 pixels unpainted; the U+1FB4C/U+1FB5D tie (bgWeight 7)
        // resolves in corpus build order, as chafa's insertion-sorted
        // buffer does.
        $legacy = (new SymbolMapRenderer())->withSymbolSelectors('legacy')->render($swatch, 1);
        $this->assertStringNotContainsString("\u{2588}", $legacy);
        $this->assertStringContainsString("\u{1FB4C}", $legacy);

        // Ladder refusal: the best full-coverage braille dot (U+28FF)
        // leaves 48 pixels unpainted, over chafa's 32 gate, so flat cells
        // ride the ordinary symbol path and come out blank braille.
        $braille = (new SymbolMapRenderer())->withSymbolSelectors('braille')->render($swatch, 1);
        $this->assertStringNotContainsString("\u{2588}", $braille);
        $this->assertStringContainsString("\u{2800}", $braille);

        // Default corpus unchanged: has_symbol(0x2588) short-circuits to
        // the solid block, so every frozen digest keeps its bytes.
        $default = (new SymbolMapRenderer())->render($swatch, 1);
        $this->assertStringContainsString("\u{2588}", $default);
    }

    public function testFlatCellsActuallyMoveUnderTheSymbolsDialEndToEnd(): void
    {
        // Rev-w10-2 M2 production reachability: the photo-ish frame
        // (two flat swatch cells, a gradient and a checker cell) through
        // the same renderer dial candy-image's --symbols= threads. The
        // reviewer's complaint was that the dial was a no-op on flat
        // cells — here it must move them.
        $image = self::photoFrame();

        $default = (new SymbolMapRenderer())->render($image, 4);
        $braille = (new SymbolMapRenderer())->withSymbolSelectors('braille')->render($image, 4);

        $this->assertGreaterThan(0, substr_count($default, "\u{2588}"), 'the flat cells paint solid on the default corpus');
        $this->assertSame(0, substr_count($braille, "\u{2588}"), 'a selection without U+2588 may never paint it');
        $this->assertNotSame($default, $braille);
    }

    private static function solidSwatch(int $r, int $g, int $b): ImageSource
    {
        $img = imagecreatetruecolor(8, 8);
        imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $b));

        return ImageSource::fromGd($img, 'image/png');
    }

    /** 32x8 GD: cells 0-1 flat swatches, cell 2 horizontal gradient, cell 3 checker. */
    private static function photoFrame(): ImageSource
    {
        $img = imagecreatetruecolor(32, 8);
        imagefill($img, 0, 0, imagecolorallocate($img, 240, 30, 30));
        imagefilledrectangle($img, 8, 0, 15, 7, imagecolorallocate($img, 20, 60, 230));
        for ($x = 0; $x < 8; $x++) {
            $c = (int) round($x * 255 / 7);
            imagefilledrectangle($img, 16 + $x, 0, 16 + $x, 7, imagecolorallocate($img, $c, $c, 90));
        }
        $dark = imagecolorallocate($img, 10, 10, 10);
        $light = imagecolorallocate($img, 245, 245, 245);
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                imagesetpixel($img, 24 + $x, $y, ($x + $y) % 2 === 0 ? $dark : $light);
            }
        }

        return ImageSource::fromGd($img, 'image/png');
    }

    public function testTheRendererIsDeterministicAcrossRendersAndInstances(): void
    {
        $image = ImageSource::fromFile(self::GRADIENT);
        foreach ([CanvasMode::TrueColor, CanvasMode::Ansi256, CanvasMode::Ansi16, CanvasMode::Fgbg] as $mode) {
            $this->assertSame(
                (new SymbolMapRenderer())->withCanvasMode($mode)->render($image, 4),
                (new SymbolMapRenderer())->withCanvasMode($mode)->render($image, 4),
                $mode->name . ' must be deterministic'
            );
        }
    }

    /**
     * Discriminating pin: candidate SELECTION must score the
     * quantized pens, not the raw group means. Re-scoring raw (a mutation
     * that drops the quantizes() branch in scoredPens) flips glyph picks
     * on palettes-sensitivity-rich content and reddens BOTH digests here
     * — the earlier 4x4_qb width-4 pins alone were vacuous (verified:
     * raw scoring happens to keep those picks; see scan at evidence
     * TODO2.md). Noise fixture at width 4 and the qb fixture at width 2
     * both move under the mutation.
     */
    public function testScoringUsesTheQuantizedPensNotTheRawOnPaletteSensitiveContent(): void
    {
        $noise = sha1((new SymbolMapRenderer())->withCanvasMode(CanvasMode::Ansi16)->render(ImageSource::fromFile(self::NOISE), 4));
        $this->assertSame('198442deee384356bcfc2fc4d1fa2538b99074d7', $noise);

        $qb = sha1((new SymbolMapRenderer())->withCanvasMode(CanvasMode::Ansi16)->render(ImageSource::fromFile(self::QB), 2));
        $this->assertSame('91279786daf9ffc3ea1e2e07b8b433a274e2d152', $qb);
    }
}
