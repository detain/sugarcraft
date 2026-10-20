<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Mosaic;
use SugarCraft\Image\Renderer\HalfBlockRenderer;
use SugarCraft\Image\Renderer\SymbolMapRenderer;
use SugarCraft\Image\Renderer\WorkFactorConfigurable;
use SugarCraft\Image\Symbol\CanvasMode;

/**
 * A-08: chafa's work-effort dial on the symbol renderer (plan_chafa S6.2).
 *
 * The dial is deterministic by construction (candidate-window arithmetic,
 * never wall clock), so the op-counter observer seam pins exactly the
 * quantities chafa's --work knob implies: the (int)(wf*10+0.5) ladder,
 * the 1..8 clamp, the exhaustive slow path at step >= 8, and the monotone
 * error floor that makes "more work never picks a worse glyph" true.
 */
final class WorkFactorTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../fixtures/gradient_64x64.png';

    public static function ladderProvider(): array
    {
        // CLI --work 1..9 maps to config (w-1)/8 (chafa.c:570); the canvas
        // then floors config*10+0.5 (chafa-canvas.c:551).
        return [
            ['work' => 0.000, 'step' => 0, 'limit' => 1, 'exhaustive' => false],
            ['work' => 0.125, 'step' => 1, 'limit' => 1, 'exhaustive' => false],
            ['work' => 0.250, 'step' => 3, 'limit' => 3, 'exhaustive' => false],
            ['work' => 0.375, 'step' => 4, 'limit' => 4, 'exhaustive' => false],
            ['work' => 0.500, 'step' => 5, 'limit' => 5, 'exhaustive' => false],
            ['work' => 0.625, 'step' => 6, 'limit' => 6, 'exhaustive' => false],
            ['work' => 0.750, 'step' => 8, 'limit' => 8, 'exhaustive' => true],
            ['work' => 0.875, 'step' => 9, 'limit' => 8, 'exhaustive' => true],
            ['work' => 1.000, 'step' => 10, 'limit' => 8, 'exhaustive' => true],
        ];
    }

    /** @dataProvider ladderProvider */
    public function testTheLadderMatchesChafaConfigScale(float $work, int $step, int $limit, bool $exhaustive): void
    {
        $this->assertSame($step, SymbolMapRenderer::workFactorToInt($work));
        $this->assertSame($limit, SymbolMapRenderer::candidateLimit($work));
        $this->assertSame($exhaustive, SymbolMapRenderer::isExhaustiveWorkFactor($work));
    }

    public function testTheDialClampsInsteadOfRefusing(): void
    {
        $base = new SymbolMapRenderer();

        $this->assertSame(0.0, $base->withWorkFactor(-1.0)->workFactor());
        $this->assertSame(1.0, $base->withWorkFactor(2.0)->workFactor());
        $this->assertSame(0.5, $base->workFactor(), 'immutable: the original keeps its dial');
    }

    public function testTheDialIsClampedIntoTheScanToo(): void
    {
        // chafa's canvas floors the ALREADY clamped config float.
        $this->assertSame(SymbolMapRenderer::workFactorToInt(1.0), SymbolMapRenderer::workFactorToInt(5.0));
        $this->assertSame(SymbolMapRenderer::workFactorToInt(0.0), SymbolMapRenderer::workFactorToInt(-5.0));
    }

    public function testTheExhaustivePathScoresTheWholeCorpus(): void
    {
        $image = ImageSource::fromFile(self::FIXTURE);

        $evaluations = [];
        $renderer = (new SymbolMapRenderer())
            ->withWorkFactor(1.0)
            ->withCandidateObserver(static function (bool $exhaustive, int $evaluated, string $winner, int $error) use (&$evaluations): void {
                $evaluations[] = [$exhaustive, $evaluated, $winner, $error];
            });

        $renderer->render($image, 1);

        $this->assertCount(1, $evaluations, 'one observer call per cell');
        [$exhaustive, $evaluated] = $evaluations[0];
        $this->assertTrue($exhaustive);
        $this->assertGreaterThan(100, $evaluated, 'slow path walks the corpus, not a window');
    }

    public function testTheFastWindowHonoursTheDialPerCell(): void
    {
        $image = ImageSource::fromFile(self::FIXTURE);

        $collect = static function (float $work) use ($image): array {
            $seen = [];
            $renderer = (new SymbolMapRenderer())
                ->withWorkFactor($work)
                ->withCandidateObserver(static function (bool $exhaustive, int $evaluated, string $winner, int $error) use (&$seen): void {
                    $seen[] = [$exhaustive, $evaluated, $error];
                });
            $renderer->render($image, 1);

            return $seen[0];
        };

        [$exh0, $eval0] = $collect(0.0);
        [$exh5, $eval5] = $collect(0.5);
        $this->assertFalse($exh0);
        $this->assertFalse($exh5);
        $this->assertSame(1, $eval0, 'work 0 spends exactly one candidate');
        $this->assertLessThanOrEqual(5, $eval5);
        $this->assertGreaterThanOrEqual($eval0, $eval5);
    }

    public function testMoreWorkNeverIncreasesTheBestCellError(): void
    {
        // D-03 quality floor: the candidate windows are prefix-nested
        // (chafa's insertion-sorted top-8 buffer truncated at k), so the
        // best error per cell must be non-increasing as the dial opens.
        $image = ImageSource::fromFile(self::FIXTURE);

        $errors = static function (float $work) use ($image): array {
            $out = [];
            $renderer = (new SymbolMapRenderer())
                ->withWorkFactor($work)
                ->withCandidateObserver(static function (bool $exhaustive, int $evaluated, string $winner, int $error) use (&$out): void {
                    $out[] = $error;
                });
            $renderer->render($image, 4);

            return $out;
        };

        $at0 = $errors(0.0);
        $at5 = $errors(0.5);
        $at9 = $errors(1.0);

        $this->assertNotEmpty($at0);
        foreach ($at0 as $i => $e0) {
            $this->assertGreaterThanOrEqual($at9[$i], $e0);
            $this->assertGreaterThanOrEqual($at5[$i], $e0);
            $this->assertGreaterThanOrEqual($at9[$i], $at5[$i]);
        }
    }

    public function testTheDialDoesRealWorkOnTheFixture(): void
    {
        // If tightening the window never changed a single glyph, the dial
        // would be decorative. On the gradient it must bite.
        $image = ImageSource::fromFile(self::FIXTURE);

        $tight = (new SymbolMapRenderer())->withWorkFactor(0.0)->render($image, 8);
        $wide = (new SymbolMapRenderer())->withWorkFactor(1.0)->render($image, 8);

        $this->assertNotSame($tight, $wide);
    }

    public function testTheDefaultWindowIsChafaFiveAndFingerprintsEmpty(): void
    {
        $renderer = new SymbolMapRenderer();
        $this->assertSame(0.5, $renderer->workFactor());
        $this->assertSame('', $renderer->configFingerprint());

        $open = $renderer->withWorkFactor(0.75)->withCanvasMode(CanvasMode::Ansi16);
        $this->assertSame('canvas=ansi16|work=0.75', $open->configFingerprint());
        $this->assertSame('canvas=ansi16', $renderer->withCanvasMode(CanvasMode::Ansi16)->configFingerprint());
        $this->assertSame('work=0.75', $renderer->withWorkFactor(0.75)->configFingerprint());
    }

    public function testTheDialIsAnOptionalCapabilityNotAnInterfaceWidening(): void
    {
        $this->assertInstanceOf(WorkFactorConfigurable::class, new SymbolMapRenderer());
        // Backends without the concept keep compiling untouched: Mosaic
        // hands the same instance back rather than faking a dial.
        $half = Mosaic::halfBlock();
        $this->assertSame($half, $half->withWorkFactor(1.0));
        $this->assertSame($half, $half->withCanvasMode(CanvasMode::Ansi16));
    }

    public function testMosaicDelegatesBothDialsOntoTheSymbolBackend(): void
    {
        $mosaic = Mosaic::symbols(canvas: CanvasMode::Ansi256, workFactor: 0.25);

        $prop = new \ReflectionProperty(Mosaic::class, 'renderer');
        $renderer = $prop->getValue($mosaic);

        $this->assertInstanceOf(SymbolMapRenderer::class, $renderer);
        $this->assertSame(CanvasMode::Ansi256, $renderer->canvasMode());
        $this->assertSame(0.25, $renderer->workFactor());
        $this->assertSame('symbols', $mosaic->protocol());

        $retuned = $mosaic->withWorkFactor(0.0)->withCanvasMode(CanvasMode::Ansi16);
        $this->assertNotSame($mosaic, $retuned);
        $inner = $prop->getValue($retuned);
        $this->assertSame(0.0, $inner->workFactor());
        $this->assertSame(CanvasMode::Ansi16, $inner->canvasMode());
    }

    public function testTheWorkFactorRidergesAreByteStableAcrossRuns(): void
    {
        $image = ImageSource::fromFile(self::FIXTURE);
        foreach ([0.0, 0.5, 1.0] as $work) {
            $renderer = (new SymbolMapRenderer())->withWorkFactor($work);
            $this->assertSame($renderer->render($image, 2), $renderer->render($image, 2));
        }
    }

    public function testTheHalfBlockRendererStillExistsForExplicitSelection(): void
    {
        // The ladder change (D-03) must not disturb the old floor's bytes.
        $mosaic = Mosaic::fromModeString('halfblock');
        $this->assertNotNull($mosaic);
        $this->assertInstanceOf(HalfBlockRenderer::class, (new \ReflectionProperty(Mosaic::class, 'renderer'))->getValue($mosaic));
    }
}
