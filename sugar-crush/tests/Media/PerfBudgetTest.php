<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Media;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Media\GenerationParams;
use SugarCraft\Crush\Media\Infotext;
use SugarCraft\Crush\Media\MediaRequest;
use SugarCraft\Crush\Media\PreviewPaint;
use SugarCraft\Crush\Media\Png;
use SugarCraft\Crush\Media\Sd\Response;
use SugarCraft\Crush\Media\Sd\SdTransportResult;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tui\MediaForm\MediaForm;
use SugarCraft\Crush\Tui\MediaForm\MediaFormState;
use SugarCraft\Crush\Tui\MediaForm\Section;
use SugarCraft\Forms\Field;
use SugarCraft\Mosaic\Mosaic;

/**
 * The W7.5 perf lane's keeper (plan_crush_media.md §W7.5: measure, don't
 * guess). Every hot path the lane touched gets TWO kinds of pin:
 *
 * - STRUCTURAL / COUNT / ALLOCATION pins (the deterministic kind the plan
 *   blesses for CI): where a memo is looked up relative to the work it skips,
 *   how many times a painted frame may ask a section whether it is visible,
 *   how much of a 64 MiB container a scan is allowed to hold.
 * - BEHAVIOUR pins proving the optimised shape still parses/splits/anchors
 *   EXACTLY like the byte-walk and 41-label loop it replaced (the 600k-string
 *   differential fuzz of the lane lives here as its distilled matrix).
 *
 * Wall-clock appears ONLY as generous ceilings (>= 20x this box's measured
 * median per E646-adjacent campaign law: budgets never kill or time out,
 * they just assert the whole batch finished under a headroom that a
 * regression an order of magnitude smaller than this would still cross).
 *
 * Baselines (devbox 2026-10-11, /tmp/opencode/plan-crush-media/w7/perf/):
 * parse 36 µs/op, emit 79 µs/op, Response::generation(8) 74 µs/op,
 * Png::readText(4 MiB) 1.2 ms/op (was 12.7 ms), small-file readText ~14 µs,
 * MediaForm view 60x24 1.0 ms/op, PreviewPaint memo hit 1.35 µs/op
 * (was 3.98 µs).
 */
final class PerfBudgetTest extends TestCase
{
    /** A 1x1 PNG — same shape PreviewPaintTest uses; needs no GD to carry. */
    private const TINY_PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** ~512 bytes of multibyte prompt text, the W7.5 bench fixture. */
    private const PROMPT = '猫の肖像画、金色の瞳 ☕ 花畑 ñoño 🌸✨ 極上の光、夕暮れの空 猫の肖像画、金色の瞳 ☕ 花畑 ñoño 🌸✨ 極上の光、夕暮れの空 猫の肖像画、金色の瞳 ☕ 花畑 ñoño 🌸✨ 極上の光';

    // ─────────────────────────── behaviour: the split rewrite ───────────────────────────

    /**
     * The strcspn-segment scanner must tokenise EXACTLY like the per-byte
     * walk it replaced (lane fuzz: 600,000 random strings over the full
     * alphabet incl. quotes/backslashes/brackets — zero differences). These
     * are its distilled shapes, each pinned through the public parse().
     */
    public function testTheSegmentedSplitMatchesTheByteWalk(): void
    {
        $cases = [
            // depth-0 ', ' splits; square-bracket groups do not (A1111 model names)
            'Steps: 20, Model: HAT [ESRGAN, 4x], Seed: 7' =>
                ['prompt' => '', 'Steps' => '20', 'Model' => 'HAT [ESRGAN, 4x]', 'Seed' => '7'],
            // quoted values ride through their first ': ' split; braces are NOT depth chars upstream either
            'Steps: 20, Params: {"a": 1, "b": 2}, CFG scale: 7' =>
                ['prompt' => '', 'Steps' => '20', 'Params' => '{"a": 1', '"b"' => '2}', 'CFG scale' => '7'],
            // an escaped quote inside a quoted value keeps the quote inert (jump-across-escape law)
            'Steps: 20, Note: "escaped \"quote\" inside", Seed: 3' =>
                ['prompt' => '', 'Steps' => '20', 'Note' => 'escaped "quote" inside', 'Seed' => '3'],
            // trailing comma before ', Key:' still yields an empty value
            'Steps: 20, Extra: , Seed: 4' =>
                ['prompt' => '', 'Steps' => '20', 'Extra' => '', 'Seed' => '4'],
            // angle groups protect their commas (<lora:name,weight>)
            'Steps: 20, Lora: <lora:x,0.8>, Seed: 9' =>
                ['prompt' => '', 'Steps' => '20', 'Lora' => '<lora:x,0.8>', 'Seed' => '9'],
        ];

        foreach ($cases as $line => $expected) {
            $parsed = Infotext::parse((string) $line);
            unset($parsed['negative prompt']);
            $this->assertSame($expected, $parsed, "pair-line shape: {$line}");
        }
    }

    /**
     * One compiled alternation replaced the 41-label loop; PCRE
     * backtracking must keep the prefix-overlap verdicts the loop gave:
     * 'Seed:' fails its colon on 'Seed resize from: …' and the longer label
     * matches at the same anchor. Mid-word and no-boundary starts stay prompt.
     */
    public function testTheAnchorAlternationKeepsPrefixOverlappingLabels(): void
    {
        $overlap = Infotext::parse('Seed resize from: 0.5, Steps: 20');
        $this->assertSame('', $overlap['prompt'], 'the line anchors as a parameter line');
        $this->assertSame('0.5', $overlap['Seed resize from']);
        $this->assertSame('20', $overlap['Steps']);

        $nearMiss = Infotext::parse('Seeds are nice');
        $this->assertSame('Seeds are nice', $nearMiss['prompt'], 'no anchor boundary, no pair');

        $midWord = Infotext::parse('xSteps: 1');
        $this->assertSame('xSteps: 1', $midWord['prompt'], 'a starter mid-word is not a pair');

        $embedded = Infotext::parse('a prompt, Steps: 12, more words');
        $this->assertSame('', $embedded['prompt'], 'a ", Steps:" pair boundary anchors mid-line');
        $this->assertSame('12', $embedded['Steps']);
    }

    public function testTheAnchorGateIsOneCompiledAlternationNotAStarterLoop(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Media/Infotext.php');

        $this->assertSame(
            0,
            substr_count($source, 'foreach (InfotextFields::STARTERS as $starter)'),
            'the 41-label scan per line must not come back (W7.5: parse was 158 µs/op on a 4-line text)',
        );
        $this->assertStringContainsString("static \$pattern = null;\n\n        return \$pattern ??=", $source);
    }

    // ─────────────────────────── structure: PreviewPaint memo ───────────────────────────

    public function testThePreviewMemoIsCheckedBeforeTheFrameIsDecoded(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Media/PreviewPaint.php');

        $keyAt = strpos($source, "hash('xxh3', \$frameB64)");
        $lookupAt = strpos($source, 'isset(self::$memo[$key])');
        $decodeAt = strpos($source, 'base64_decode($frameB64');

        $this->assertNotFalse($keyAt);
        $this->assertNotFalse($lookupAt);
        $this->assertNotFalse($decodeAt);
        $this->assertLessThan($lookupAt, $keyAt, 'the b64-keyed memo hash is the first thing paint() does');
        $this->assertLessThan($decodeAt, $lookupAt, 'a memo hit must skip base64_decode entirely');
    }

    public function testRepeatingTheNewestFrameServesTheMemoWithinBudget(): void
    {
        if (!\extension_loaded('gd')) {
            $this->markTestSkipped('candy-mosaic decodes images through ext-gd');
        }
        PreviewPaint::resetForTesting();
        $mosaic = Mosaic::fromModeString('halfblock');
        $this->assertNotNull($mosaic);

        $perOp = self::perfMicrosecondsPerOp(
            static fn () => PreviewPaint::paint(self::TINY_PNG_B64, $mosaic, 100, 8),
            3000,
        );

        // measured 1.35 µs/op; ceiling 40 µs (≈30x)
        $this->assertLessThan(40.0, $perOp, "memo-hit paint costs {$perOp} µs/op");
    }

    // ─────────────────────────── allocation: Png lazy scan ───────────────────────────

    public function testThePngScanRecordsCarryFramingNotPayloads(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Media/Png.php');
        $scanAt = strpos($source, 'private static function scan(');
        $dataOfAt = strpos($source, 'private static function dataOf(');
        $this->assertNotFalse($scanAt);
        $this->assertNotFalse($dataOfAt);

        $scanBody = substr($source, $scanAt, $dataOfAt - $scanAt);
        $this->assertStringNotContainsString(
            "'data' =>",
            $scanBody,
            'scan() must not retain chunk payloads — the whole-file second allocation is the W7.5 defect',
        );
        $this->assertSame(2, substr_count($source, "self::dataOf(\$pngBytes, \$chunk)"), 'readText and writeText decode lazily');
    }

    public function testReadingTextFromALargeContainerDoesNotHoldACopyOfIt(): void
    {
        $big = self::perfBigPngBytes(fillerCount: 4); // 4 x 1 MiB real chunks ≈ 4.2 MiB
        $this->assertGreaterThan(4 * 1024 * 1024, strlen($big));
        $this->assertNotNull(Png::readText($big), 'sanity: the fixture is parseable');

        gc_collect_cycles();
        $baseline = memory_get_usage();
        memory_reset_peak_usage();
        $text = Png::readText($big);
        $peakDelta = memory_get_peak_usage() - $baseline;

        $this->assertNotNull($text);
        // Pre-fix the scan held every payload: >= 4 MiB. Post-fix the peak
        // transient is one chunk copy (~1 MiB) plus framing records.
        $this->assertLessThan(3 * 1024 * 1024, $peakDelta, sprintf('readText peaked %.1f MiB over baseline', $peakDelta / 1048576));
        unset($big);
        gc_collect_cycles();
    }

    // ─────────────────────────── counts: MediaForm frame passes ───────────────────────────

    public function testViewAndZonesEvaluateEachSectionsVisibilityOnce(): void
    {
        $a = new PerfCountingSection('a', 'Alpha');
        $b = new PerfCountingSection('b', 'Beta');
        $form = MediaForm::fromSections([$a, $b]);
        $theme = Theme::default();

        $a->visibleCalls = 0;
        $b->visibleCalls = 0;
        $form->view($theme, 60, 24);
        $this->assertSame(1, $a->visibleCalls, 'one visible() per painted frame (was 2: tabLabels + focusedSection each re-filtered)');
        $this->assertSame(1, $b->visibleCalls);

        $a->visibleCalls = 0;
        $b->visibleCalls = 0;
        $form->zones($theme, 200, 50);
        $this->assertSame(1, $a->visibleCalls, 'the zone pass shares its filtered list too');
        $this->assertSame(1, $b->visibleCalls);

        $a->visibleCalls = 0;
        $b->visibleCalls = 0;
        $form->view($theme, 60, 24);
        $form->zones($theme, 60, 24);
        // 2 sections x (one view pass + one zones pass) — pre-W7.5 the same
        // frame asked each section 4+ times and totalled 8+.
        $this->assertSame(4, $a->visibleCalls + $b->visibleCalls, 'a full frame filters visibility exactly twice');
    }

    // ─────────────────────── wall-clock ceilings: bench-gated only ───────────────────────

    /**
     * The five hot-path µs/op ceilings measure a cost, and a cost number on
     * a loaded CI runner is noise the suite must not trade green for
     * (plan :916: no wall-clock assertions in CI; R21-m1). They therefore
     * run only when SUGARCRUSH_PERF_BENCH=1 arms them, mirroring the
     * env-conditional shape of the live sglang smoke; the default shape
     * keeps every fixture and one correctness smoke per leg, so the
     * assertion figure is identical either way.
     */
    public function testTheMediaHotPathsStayInsideTheirGenerousBudgets(): void
    {
        $bench = getenv('SUGARCRUSH_PERF_BENCH') === '1';
        $infotext = self::perfFullInfotext();
        $params = GenerationParams::new()
            ->withSteps(28)
            ->withSamplerName('DPM++ 2M Karras')
            ->withSeed(123456789)
            ->withWidth(512)
            ->withHeight(512);

        // parse: measured 36 µs/op → ceiling 900 µs (25x)
        if ($bench) {
            $perOp = self::perfMicrosecondsPerOp(static fn () => Infotext::parse($infotext), 400);
            $this->assertLessThan(900.0, $perOp, "Infotext::parse costs {$perOp} µs/op");
        } else {
            $this->assertSame('28', Infotext::parse($infotext)['Steps'] ?? null);
        }

        // emit: measured 79 µs/op → ceiling 2400 µs (30x)
        if ($bench) {
            $perOp = self::perfMicrosecondsPerOp(static fn () => Infotext::emit($params, [], self::PROMPT), 150);
            $this->assertLessThan(2400.0, $perOp, "Infotext::emit costs {$perOp} µs/op");
        } else {
            $this->assertStringContainsString('Steps: 28', Infotext::emit($params, [], self::PROMPT));
        }

        // Response::generation over an 8-image grid: measured 74 µs/op → ceiling 2000 µs
        $result = SdTransportResult::new(200, json_encode([
            'images' => array_fill(0, 8, self::TINY_PNG_B64),
            'parameters' => ['prompt' => 'a cat', 'steps' => 20, 'seed' => -1],
            'infotexts' => array_fill(0, 8, $infotext),
            'all_seeds' => [1, 2, 3, 4, 5, 6, 7, 8],
        ], JSON_THROW_ON_ERROR), 'application/json');
        if ($bench) {
            $perOp = self::perfMicrosecondsPerOp(static fn () => Response::generation($result, 'generation'), 150);
            $this->assertLessThan(2000.0, $perOp, "Response::generation(8) costs {$perOp} µs/op");
        } else {
            $this->assertCount(8, Response::generation($result, 'generation')->artifacts());
        }

        // Png::readText on a small stamped file: measured ~14 µs/op → ceiling 600 µs
        $small = Png::writeText(self::perfBigPngBytes(fillerCount: 0), 'parameters', $infotext);
        if ($bench) {
            $perOp = self::perfMicrosecondsPerOp(static fn () => Png::readText($small), 400);
            $this->assertLessThan(600.0, $perOp, "Png::readText(small) costs {$perOp} µs/op");
        } else {
            $this->assertStringContainsString('Steps: 28', (string) Png::readText($small));
        }

        // MediaForm full-registry view at 60x24: measured 1.0 ms/op → ceiling 30 ms (30x)
        $form = MediaForm::open(MediaRequest::new()->fromArray([
            'prompt' => 'a lighthouse at dawn', 'steps' => 25, 'cfg_scale' => 7, 'seed' => 42,
        ]));
        $theme = Theme::default();
        if ($bench) {
            $perOp = self::perfMicrosecondsPerOp(static fn () => $form->view($theme, 60, 24), 20);
            $this->assertLessThan(30000.0, $perOp, "MediaForm::view costs {$perOp} µs/op");
        } else {
            $this->assertIsString($form->view($theme, 60, 24));
        }
    }

    // ─────────────────────────── helpers ───────────────────────────

    /**
     * Median-free timing: warm-up, then the BEST of 3 rounds (the most
     * CI-load-tolerant statistic short of a timer — and with 20-30x
     * headroom even the best-of-3 only reddens on an order-of-magnitude
     * regression). E646: no time limits, no kills — a slow box passes, it
     * does not hang.
     */
    private static function perfMicrosecondsPerOp(callable $fn, int $ops, int $rounds = 3): float
    {
        $fn();
        $best = INF;
        for ($r = 0; $r < $rounds; $r++) {
            $t0 = hrtime(true);
            for ($i = 0; $i < $ops; $i++) {
                $fn();
            }
            $best = min($best, (hrtime(true) - $t0) / 1e3 / $ops);
        }

        return $best;
    }

    private static function perfFullInfotext(): string
    {
        return self::PROMPT . "\n"
            . "Negative prompt: blurry, lowres, bad anatomy, watermark\n"
            . 'Steps: 28, Sampler: DPM++ 2M Karras, Schedule type: Simple, CFG scale: 7, Seed: 123456789, '
            . 'Face restoration: 1, Size: 512x512, Model hash: 8feea0a5b1, Model: realisticVisionV50, '
            . 'Denoising strength: 0.45, Hires upscale: 2, Hires steps: 10, Hires upscaler: 4x-UltraSharp, '
            . 'CLIP skip: 2';
    }

    /**
     * A minimal valid container: signature + IHDR + N one-megabyte dUMY
     * chunks (each with a spec-correct CRC) + a stamped tEXt + IEND. Built
     * without GD so the allocation pin costs milliseconds.
     */
    private static function perfBigPngBytes(int $fillerCount): string
    {
        $bytes = "\x89PNG\r\n\x1a\n"; // Png::SIGNATURE is private; the eight spec bytes verbatim
        $bytes .= self::perfChunk('IHDR', pack('N', 8) . pack('N', 8) . chr(8) . chr(2) . chr(0) . chr(0) . chr(0));
        $filler = str_repeat('x', 1024 * 1024);
        for ($i = 0; $i < $fillerCount; $i++) {
            $bytes .= self::perfChunk('dUMY', $filler);
        }
        $bytes .= self::perfChunk('tEXt', "parameters\x00prompt only");
        $bytes .= self::perfChunk('IEND', '');

        return $bytes;
    }

    private static function perfChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data) & 0xFFFFFFFF);
    }
}

/**
 * A Section that counts how often the form asks it anything structural —
 * the counter instrument for the one-visible-pass pin. Public props on
 * purpose (test-local telemetry).
 */
final class PerfCountingSection implements Section
{
    public int $visibleCalls = 0;

    public function __construct(private readonly string $sectionKey, private readonly string $tabLabel)
    {
    }

    public function key(): string
    {
        return $this->sectionKey;
    }

    public function label(): string
    {
        return $this->tabLabel;
    }

    public function visible(MediaFormState $state): bool
    {
        $this->visibleCalls++;

        return true;
    }

    /** @return list<string> */
    public function rows(): array
    {
        return ['first row', 'second row'];
    }

    public function fieldAt(int $row): ?Field
    {
        return null;
    }

    public function withField(Field $field): Section
    {
        return $this;
    }

    public function valueAt(int $row): string
    {
        return 'value';
    }

    public function collect(MediaRequest $draft): MediaRequest
    {
        return $draft;
    }
}
