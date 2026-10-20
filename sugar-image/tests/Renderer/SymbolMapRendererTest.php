<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Renderer\SymbolMapRenderer;
use SugarCraft\Image\Symbol\CanvasMode;

/**
 * A-04/D-03: the symbol renderer's emission contract (plan_chafa S6.2).
 *
 * The byte digests below are the double-sourced regression floor for the
 * port-fidelity fixes this lane shipped: chafa's eval_symbol never
 * consumes the Hamming shortlist's inversion flag (the winner paints its
 * OWN coverage — chafa-symbol-renderer.c:340-395), and Fgbg runs the
 * fg-only discipline on the terminal's OSC 10/11 default pair (F-08
 * consumer wire). Re-introducing the inverted-paint flip, or scoring
 * truecolor pens under a fixed palette (PC-14), moves these digests.
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
        // PC-14 discriminating digest: scoring BEFORE the palette snap
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
        // D-03: the slow path is a different (never worse) search — on
        // this fixture it lands different bytes, and both digests below
        // are stable across processes.
        $image = ImageSource::fromFile(self::QB);

        $fast = (new SymbolMapRenderer())->render($image, 4);
        $slow = (new SymbolMapRenderer())->withWorkFactor(1.0)->render($image, 4);

        $this->assertNotSame($fast, $slow);
        $this->assertSame('4e4ca0847ee3d4395be1e89d4740e7f11afd2931', sha1($slow));
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
     * A-07/PC-14 discriminating pin: candidate SELECTION must score the
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
