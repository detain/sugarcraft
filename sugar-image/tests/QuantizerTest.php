<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Quantizer;

/**
 * B-01: PNN quantizer pins — the chafa -w ladder mapping, the fail-loud
 * edges, determinism (the palette is a pure function of the sample SET),
 * the histogram degenerate paths, and golden palettes that catch any drift
 * in the merge math. Goldens are hand-pinned digests (§4.11: this lib has
 * no golden-update environment), captured from the chafa-aligned port.
 */
final class QuantizerTest extends TestCase
{
    /**
     * B-01: bits-per-channel rows verbatim from chafa's quality_params
     * (chafa-palette.c:63-76) for rungs -w1..-w9.
     */
    public function testQualityLadderMapsRungsToHistogramBits(): void
    {
        $ladder = [];
        for ($w = Quantizer::MIN_QUALITY; $w <= Quantizer::MAX_QUALITY; $w++) {
            $ladder[] = Quantizer::bitsForQuality($w);
        }

        $this->assertSame([3, 3, 4, 4, 4, 5, 5, 5, 5], $ladder);
    }

    public function testQualityOutsideTheLadderIsRefused(): void
    {
        foreach ([0, 10, -1, 100] as $rung) {
            try {
                Quantizer::bitsForQuality($rung);
                $this->fail("rung {$rung} should be refused");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('quality must be 1-9', $e->getMessage());
            }
        }
    }

    public function testHistogramDepthOutsideTheAssertedRangeIsRefused(): void
    {
        // chafa asserts 3..5 (chafa-palette.c:631-632); anything else is a
        // wiring bug, so it must throw rather than silently mis-bucket.
        foreach ([2, 6, 0] as $bits) {
            try {
                Quantizer::build([[10, 20, 30]], 2, $bits);
                $this->fail("bits {$bits} should be refused");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('bitsPerCh must be 3-5', $e->getMessage());
            }
        }
    }

    public function testEmptySamplesAnswerTheMedianCutParityRegister(): void
    {
        // median-cut returns [[0,0,0]] for zero samples; the PNN path keeps
        // the caller's "#0;2;P adjacent" register well-formed the same way.
        $this->assertSame([[0, 0, 0]], Quantizer::build([]));
    }

    public function testBuildIsDeterministicAcrossIdenticalCalls(): void
    {
        $samples = self::lcgSamples(1024);
        $a = Quantizer::build($samples, 32, 4);
        $b = Quantizer::build($samples, 32, 4);

        $this->assertSame($a, $b);
    }

    public function testPaletteIsInvariantToSampleArrivalOrder(): void
    {
        // The histogram accumulates integer channel sums per bin, so a bin's
        // mean cannot depend on arrival order; a strided permutation is the
        // churn the sampler itself would produce from a different phase.
        $samples = self::lcgSamples(500);
        $base    = Quantizer::build($samples, 16, 4);

        $stride  = 7; // coprime-ish walk over 500 — not a rotation
        $mixed   = [];
        $n       = count($samples);
        for ($i = 0, $p = 0; $i < $n; $i++) {
            $mixed[] = $samples[$p];
            $p = ($p + $stride) % $n;
        }

        $this->assertSame($base, Quantizer::build($mixed, 16, 4));
    }

    public function testTwoBlobHistogramMergesToExactClusterMeans(): void
    {
        // Two tight blobs, one bin each, budget 2 → extbins = 0 → the export
        // is both bin means verbatim in ascending bin-index order. (32,32,32)
        // bins below (220,220,220) at every rung, so the order is pinned too.
        $samples = [];
        for ($i = 0; $i < 100; $i++) {
            $samples[] = [32, 32, 32];
            $samples[] = [220, 220, 220];
        }

        $this->assertSame([[32, 32, 32], [220, 220, 220]], Quantizer::build($samples, 2, 4));
    }

    public function testSingleOccupiedBinUnderBudgetExportsFewerColors(): void
    {
        // chafa-palette.c:808 "we may produce fewer colors than requested":
        // 200 identical samples cannot fill a 64-colour budget.
        $samples = array_fill(0, 200, [9, 200, 42]);

        $this->assertSame([[9, 200, 42]], Quantizer::build($samples, 64, 4));
    }

    public function testGoldenPalettesAcrossTheHistogramRungs(): void
    {
        // sha1 of json_encode of the palette, LCG-seeded 1024 samples —
        // any change to the merge error, the rintf-half-even fold, the
        // quanfn ladder or the export rounding moves these.
        $samples = self::lcgSamples(1024);

        $this->assertSame('44522aaf0cf1cd4a4f303c33722210e40b964ca3', sha1((string) json_encode(Quantizer::build($samples, 32, 3))));
        $this->assertSame('dcef3e1e62d5da7a6b3ea10fa2ef6ed0b93cc5cf', sha1((string) json_encode(Quantizer::build($samples, 32, 4))));
        $this->assertSame('177d06d700d55ca1622bd1ba4ff46fd64536559b', sha1((string) json_encode(Quantizer::build($samples, 64, 5))));
    }

    public function testPaletteEntriesStayInChannelRange(): void
    {
        // Export is (int)(mean + 0.5) over means bounded by the input; a
        // 256.0 or negative answer would poison the sixel palette introducer.
        foreach ([3, 4, 5] as $bits) {
            foreach (Quantizer::build(self::lcgSamples(1024), 48, $bits) as [$r, $g, $b]) {
                $this->assertGreaterThanOrEqual(0, $r);
                $this->assertLessThanOrEqual(255, $r);
                $this->assertGreaterThanOrEqual(0, $g);
                $this->assertLessThanOrEqual(255, $g);
                $this->assertGreaterThanOrEqual(0, $b);
                $this->assertLessThanOrEqual(255, $b);
            }
        }
    }

    /**
     * A tiny integer LCG — portable, version-stable sample churn (mt_rand
     * streams are a PHP-major-version liability for hand-pinned goldens).
     *
     * @return list<array{int,int,int}>
     */
    private static function lcgSamples(int $n): array
    {
        $samples = [];
        $s       = 12345;
        for ($i = 0; $i < $n; $i++) {
            $s = ($s * 1103515245 + 12345) % 2147483648;
            $samples[] = [($s >> 16) & 0xFF, ($s >> 8) & 0xFF, $s & 0xFF];
        }

        return $samples;
    }
}
