<?php

declare(strict_types=1);

namespace SugarCraft\Animation\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Animation\Decoder;
use SugarCraft\Animation\Lang;

/**
 * E-13: hostile GIF corpus replay for the hand-rolled Decoder.php byte walk.
 *
 * Chafa runs a curated `tests/data/bad/` battery in CI (real historical
 * crashers replayed across every format/size/speed); sugar-animation's
 * decoder — the same parser class E-01 punched its clamp into — had only
 * prose-driven security cases. This is the corpus that was missing: every
 * member is a pack()-built hostile GIF committed under tests/fixtures/bad/,
 * byte-locked to its in-code builder, and replayed through the full public
 * Decoder::decode().
 *
 * The replay law every case obeys (asserted by the shared replay() helper,
 * so no test needs a generic catch-all):
 *  - a hostile file must DECODE (a list of Frames, possibly empty) or throw
 *    the decoder's TYPED refusal (\RuntimeException carrying a lang/en.php
 *    `decoder.*` message) — anything else (TypeError, ValueError, raw E_*
 *    diagnostic, fatal) fails the test;
 *  - diagnostics are converted to failures by an error handler installed for
 *    the decode window only; the decoder's own deliberate `@` suppressions
 *    still pass (they are the documented GD-mercy contract, pinned here as
 *    NON-events by honouring the PHP 8 error_reporting mask);
 *  - every fixture loads within a measured memory budget: the live-allocation
 *    delta (memory_get_usage(true) sampled before/after) AND the peak climb
 *    (memory_get_peak_usage(true)) must stay under 64 MB — peak-delta credits
 *    freed arena from earlier decodes, so it is a per-decode low bar, not a
 *    high-water proof; the live delta catches anything retained.
 *
 * The giant-descriptor cases are deliberately outcome-pinned (clip-decoded,
 * red survives), NOT merely "decode-or-refuse", because that is what makes
 * them load-bearing proof for E-01: disabling the parseHeader() clip hands
 * GD the declared 65535² frame, GD rejects the payload, the frame list
 * collapses, and these tests go red. A bare decode-or-refuse assertion
 * would have stayed green over the un-clamped decoder (the W1 docblock
 * records that pre-fix the file "decoded" to zero frames just fine).
 */
final class DecoderHostileCorpusTest extends TestCase
{
    /** Per-fixture memory budget in MB (live delta AND peak climb). */
    private const MEM_BUDGET_MB = 64;

    /** Per-decode wall budget in ms — nothing in this corpus may grind. */
    private const WALL_BUDGET_MS = 2000;

    /** Relative paths (under tests/) of every committed hostile fixture. */
    private const CORPUS = [
        'fixtures/bad/giant-descriptor-4x4.gif',
        'fixtures/bad/off-canvas-giant-mixed.gif',
        'fixtures/bad/lsd-zero-screen.gif',
        'fixtures/bad/gct-past-eof.gif',
        'fixtures/bad/zero-delay-storm-64.gif',
        'fixtures/bad/frame-flood-300.gif',
        'fixtures/bad/gct-flag-mismatch.gif',
        'fixtures/bad/gct-size-desync.gif',
        'fixtures/bad/multi-frame-clip-interaction.gif',
        'fixtures/bad/bogus-disposal-4567.gif',
        'fixtures/bad/netscape-loop-ext.gif',
        'fixtures/bad/lzw-overlong.gif',
    ];

    /** 4-entry GCT: black, red, green, blue (the house palette). */
    private const GCT = "\x00\x00\x00\xff\x00\x00\x00\xff\x00\x00\x00\xff";

    /** @var list<string> */
    private array $temp = [];

    protected function tearDown(): void
    {
        foreach ($this->temp as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
        $this->temp = [];
    }

    public function testGiantDescriptorInsideTinyScreenDecodesClipped(): void
    {
        [$frames] = $this->replay($this->fixturePath('giant-descriptor-4x4.gif'));

        // E-01's clipped outcome: the 65535² declaration enters the walk as
        // the screen-sized remainder and the red fill survives the re-emit.
        // This is the mutation discriminator for the parseHeader clamp.
        self::assertNotNull($frames, 'a clipped giant descriptor must decode, not be dropped or refused');
        self::assertCount(1, $frames);
        self::assertSame([255, 0, 0], $frames[0]->cells[0][0] ?? null, 'the red fill must survive the clip');
    }

    public function testFullyOffCanvasFrameIsDroppedAndLaterFramesStayInSync(): void
    {
        [$frames] = $this->replay($this->fixturePath('off-canvas-giant-mixed.gif'));

        // Declared order: giant-at-(2,2) (clips to 2x2, red), a fully
        // off-canvas green at (8,8) (dropped, sub-blocks still consumed),
        // and a blue top row (kept). The second surviving frame must show
        // BOTH earlier paints composited — proof the drop did not desync
        // the walk nor corrupt the canvas.
        self::assertNotNull($frames, 'the clipped giant and trailing frames must decode');
        self::assertCount(2, $frames, 'the fully off-canvas frame must not join the list');
        self::assertSame([0, 0, 255], $frames[1]->cells[0][0] ?? null, 'blue top row must paint');
        self::assertSame([255, 0, 0], $frames[1]->cells[1][1] ?? null, 'clipped red must still hold its cell');
    }

    public function testLsdZeroScreenIsRefusedWithTheTypedGate(): void
    {
        // 0x0 screen: the door throw in decode() fires before any canvas
        // allocation (imagecreatetruecolor(0,0) would be a raw ValueError).
        [, $refusal] = $this->replay($this->fixturePath('lsd-zero-screen.gif'));

        self::assertNotNull($refusal, 'a zero-extent logical screen must be refused, never decoded');
        self::assertSame(Lang::t('decoder.screen_too_small'), $refusal->getMessage());
    }

    public function testDeclaredGctReachingPastEofIsRefusedWithTheTypedGate(): void
    {
        [, $refusal] = $this->replay($this->fixturePath('gct-past-eof.gif'));

        self::assertNotNull($refusal, 'a color table spanning past EOF cannot frame any walk');
        self::assertSame(Lang::t('decoder.truncated'), $refusal->getMessage());
    }

    public function testZeroDelayStormKeepsEveryFrameSlotPositive(): void
    {
        [$frames] = $this->replay($this->fixturePath('zero-delay-storm-64.gif'));

        // E-04 interplay: delay-0 GCEs must not mint zero-length slots —
        // the walk keeps the last positive (spec default 10cs) and the
        // Player floors at MIN_TICK_CS on top. Either way no frame may
        // carry a 0 delay into a schedule.
        self::assertNotNull($frames, 'a zero-delay storm must decode, not be refused');
        self::assertCount(64, $frames);
        foreach ($frames as $i => $frame) {
            self::assertGreaterThanOrEqual(1, $frame->delay, "frame {$i} carries a zero slot");
        }
    }

    public function testFrameCountFloodIsCappedByMaxFrames(): void
    {
        [$frames] = $this->replay($this->fixturePath('frame-flood-300.gif'));

        // 300 tiny frames on a 2x2 screen: the MAX_FRAMES slice must bound
        // the composite loop, and the walk must reach it without stalling.
        self::assertNotNull($frames, 'a frame flood must decode to the capped list, not be refused');
        self::assertCount(Decoder::MAX_FRAMES, $frames, 'the cap must bite at exactly MAX_FRAMES');
        self::assertLessThanOrEqual(300, count($frames));
    }

    public function testGctFlagMismatchWalkStaysContained(): void
    {
        // GCT flag OFF but size-exp bits SET: the parser trusts the flag
        // (zero table bytes consumed), so the table bytes become walker
        // padding and the frame still parses — GD, fed a palette-less
        // payload, degrades to its own default instead of refusing. The
        // measured law (E-13): one frame survives, walk finishes clean,
        // no diagnostic, no hang.
        [$frames] = $this->replay($this->fixturePath('gct-flag-mismatch.gif'));

        self::assertNotNull($frames, 'an inconsistent GCT flag must not be refused as corrupt');
        self::assertCount(1, $frames, 'GD-degraded palette-less frames still occupy one slot, never poisoning the walk');
    }

    public function testGctSizeDesyncWalkStaysContained(): void
    {
        // Declared 8-entry GCT but only 6 bytes supplied: the walk starts
        // past the declared end — inside frame bytes. Every stray byte must
        // either skip sub-blocks or single-step; the file decodes empty and
        // never emits a raw diagnostic.
        [$frames] = $this->replay($this->fixturePath('gct-size-desync.gif'));

        self::assertIsArray($frames);
        self::assertSame([], $frames, 'a desynchronised walk must end empty, not half-parse garbage');
    }

    public function testMultiFrameClipInteractionPaintsAndKeepsTiming(): void
    {
        [$frames] = $this->replay($this->fixturePath('multi-frame-clip-interaction.gif'));

        // Three clipped declarations (100x100, 1x1, 65535x2) on a 4x4
        // screen: all three enter the list, the GCE timing of the middle
        // frame survives, and the composited canvas distinguishes each
        // frame's paint region.
        self::assertNotNull($frames, 'the clip-interaction trio must decode whole');
        self::assertCount(3, $frames, 'a clipped frame that GD can paint may not silently vanish');
        self::assertSame(5, $frames[1]->delay, 'per-frame GCE timing must ride the hostile walk');
        self::assertNotNull($frames[2]->cells[0][0] ?? null, 'cell (0,0) must carry the green/red blend');
        self::assertNotNull($frames[2]->cells[1][0] ?? null, 'cell (1,0) must carry the red edge');
        self::assertNotSame($frames[2]->cells[0][0], $frames[2]->cells[1][0], 'distinct paint regions must not flatten to one cell value');
    }

    public function testBogusDisposalHighBitsDoNotFatalTheComposite(): void
    {
        // libnsgif quirk parity: disposal values 4..7 are reserved. The
        // decoder must carry them through (only 2/3 act on the canvas) and
        // every frame must still paint.
        [$frames] = $this->replay($this->fixturePath('bogus-disposal-4567.gif'));

        self::assertNotNull($frames);
        self::assertCount(4, $frames);
        self::assertSame([4, 5, 6, 7], array_map(static fn ($f): int => $f->disposal, $frames));
    }

    public function testNetscapeLoopExtensionIsSkippedAndTrailingGarbageIsInert(): void
    {
        // A well-formed NETSCAPE2.0 loop block ahead of a real frame (E-02
        // future-proofing: its presence must not break the walk), plus a
        // bogus length-framed application extension AFTER the frame with no
        // terminator — the skip leg must degrade to end-of-data.
        [$frames] = $this->replay($this->fixturePath('netscape-loop-ext.gif'));

        self::assertNotNull($frames, 'a NETSCAPE extension must be skipped, never refused');
        self::assertCount(1, $frames);
        self::assertSame([255, 0, 0], $frames[0]->cells[0][0] ?? null, 'the post-loop frame must paint');
    }

    public function testOverlongLzwStreamIsContained(): void
    {
        // 1x1 frame whose LZW sub-blocks carry 1024 pixel-codes: GD stops
        // consuming when the declared rect is full; the decoder must neither
        // loop past the terminator nor size any buffer from the stream.
        [$frames] = $this->replay($this->fixturePath('lzw-overlong.gif'));

        self::assertNotNull($frames);
        self::assertCount(1, $frames, 'the 1x1 frame must decode despite the 1024-code stream');
        self::assertSame([255, 0, 0], $frames[0]->cells[0][0] ?? null);
    }

    /**
     * Every committed corpus member replays through the public decoder with
     * a typed outcome and stays inside the memory/wall budgets — this is the
     * growing-corpus invariant the named tests above refine per fixture.
     */
    public function testEveryCorpusMemberReplaysAsTypedOutcomeWithinBudget(): void
    {
        $refusals = [
            Lang::t('decoder.not_gif'),
            Lang::t('decoder.truncated'),
            Lang::t('decoder.screen_too_small'),
            Lang::t('decoder.grid_too_small'),
            Lang::t('decoder.screen_too_large', ['max' => (string) Decoder::MAX_TOTAL_PIXELS]),
            Lang::t('decoder.grid_too_large', ['max' => (string) Decoder::MAX_CELLS]),
        ];

        $outcomes = [];
        foreach (self::CORPUS as $relative) {
            [$frames, $refusal] = $this->replay('tests/' . $relative);
            if ($refusal !== null) {
                self::assertContains(
                    $refusal->getMessage(),
                    $refusals,
                    "corpus member {$relative} threw an off-contract message",
                );
                $outcomes[$relative] = 'refused';
                continue;
            }
            self::assertIsArray($frames, "corpus member {$relative} produced neither frames nor a typed refusal");
            $outcomes[$relative] = 'decoded:' . count($frames);
        }

        // The named pins above would catch a flip; this multiset is the
        // one-line human-readable corpus truth for the lane report.
        self::assertSame([
            'fixtures/bad/giant-descriptor-4x4.gif' => 'decoded:1',
            'fixtures/bad/off-canvas-giant-mixed.gif' => 'decoded:2',
            'fixtures/bad/lsd-zero-screen.gif' => 'refused',
            'fixtures/bad/gct-past-eof.gif' => 'refused',
            'fixtures/bad/zero-delay-storm-64.gif' => 'decoded:64',
            'fixtures/bad/frame-flood-300.gif' => 'decoded:256',
            'fixtures/bad/gct-flag-mismatch.gif' => 'decoded:1',
            'fixtures/bad/gct-size-desync.gif' => 'decoded:0',
            'fixtures/bad/multi-frame-clip-interaction.gif' => 'decoded:3',
            'fixtures/bad/bogus-disposal-4567.gif' => 'decoded:4',
            'fixtures/bad/netscape-loop-ext.gif' => 'decoded:1',
            'fixtures/bad/lzw-overlong.gif' => 'decoded:1',
        ], $outcomes);
    }

    /**
     * The committed bytes ARE the builders below, byte-exact — provenance
     * lock so the fixtures/bad/ files stay re-derivable and a fixture edit
     * without a builder edit cannot ride the corpus (and vice versa).
     */
    public function testEveryCommittedFixtureMatchesItsBuilderByteExact(): void
    {
        $dir = dirname(__DIR__);
        $present = [];
        foreach (self::CORPUS as $relative) {
            $path = $dir . '/tests/' . $relative;
            self::assertFileExists($path, "corpus roster names a missing fixture: {$relative}");
            $bytes = $this->fixtureBytes(basename($relative));
            self::assertSame(
                $bytes,
                (string) file_get_contents($path),
                "committed fixture {$relative} drifted from fixtureBytes() — regenerate it, do not hand-edit bytes",
            );
            $present[] = basename($relative);
        }

        $onDisk = array_values(array_filter(
            (array) scandir($dir . '/tests/fixtures/bad'),
            static fn (string $entry): bool => str_ends_with($entry, '.gif'),
        ));
        sort($present);
        sort($onDisk);
        self::assertSame($present, $onDisk, 'the corpus roster and tests/fixtures/bad/ must agree exactly');
    }

    /**
     * Truncated-header shapes below the walk's first real block: 0 and
     * partial bytes must either refuse typed (pre-13-byte gate, cut
     * descriptor) or degrade to the documented graceful empty list (bare
     * header, mid-GCE cut — the shape DecoderTest pins for the walk).
     *
     * @return array<string, array{0: string, 1: ?string, 2: ?list<int>}>
     */
    public static function truncatedHeaderProvider(): array
    {
        // Static provider: shapes spelled as literals (byte-identical to
        // head(4,4) above by inspection, and deliberately independent —
        // these are corruption inputs, not the committed corpus).
        $bare13 = 'GIF89a' . pack('v', 4) . pack('v', 4) . "\x01\x00\x00";
        $head25 = 'GIF89a' . pack('v', 4) . pack('v', 4) . "\x81\x00\x00" . self::GCT;

        return [
            'empty-file' => ['', Lang::t('decoder.not_gif'), null],
            'six-byte-signature' => ['GIF89a', Lang::t('decoder.not_gif'), null],
            'twelve-byte-partial-header' => [substr($head25, 0, 12), Lang::t('decoder.not_gif'), null],
            'thirteen-byte-bare-header' => [$bare13, null, []],
            'cut-mid-gce' => [$head25 . "\x21\xF9\x04\x00", null, []],
            'cut-mid-descriptor' => [$head25 . "\x21\xF9\x04\x00\x0A\x00\x00\x00\x2C\x01\x00\x02\x00", Lang::t('decoder.truncated'), null],
        ];
    }

    /**
     * @param ?list<int> $expectedFrames
     * @dataProvider truncatedHeaderProvider
     */
    public function testTruncatedHeadersRefuseTypedOrDegradeToEmptyList(string $bytes, ?string $expectedRefusal, ?array $expectedFrames): void
    {
        $path = sys_get_temp_dir() . '/flip-e13-cut-' . uniqid('', true) . '.gif';
        file_put_contents($path, $bytes);
        $this->temp[] = $path;

        [$frames, $refusal] = $this->replay($path);

        if ($expectedRefusal !== null) {
            self::assertNotNull($refusal, 'this truncation shape must be refused, not silently decoded');
            self::assertSame($expectedRefusal, $refusal->getMessage());

            return;
        }
        self::assertNotNull($frames, 'this truncation shape must decode gracefully, not be refused');
        self::assertSame($expectedFrames, $frames);
    }

    /**
     * Fuzz-lite truncation ladder: sample prefixes of a valid multi-frame
     * GIF (bounded ladder incl. byte-1 and len-1). No prefix may fatal,
     * warn, or leak an untyped error; the walk must refuse typed or degrade
     * to a partial decode.
     */
    public function testTruncationLadderOfValidMultiFrameGifNeverFatals(): void
    {
        $full = $this->fixtureBytes('ladder-source.gif');
        $len = strlen($full);
        $offsets = $this->distinctAscending([1, 6, 13, 25, (int) ($len / 2), $len - 4, $len - 2, $len - 1], $len);

        $decodedAny = false;
        $refusedAny = false;
        foreach ($offsets as $offset) {
            $path = sys_get_temp_dir() . '/flip-e13-ladder-' . $offset . '-' . uniqid('', true) . '.gif';
            file_put_contents($path, substr($full, 0, $offset));
            $this->temp[] = $path;

            [$frames, $refusal] = $this->replay($path);
            if ($refusal !== null) {
                $refusedAny = true;
                continue;
            }
            self::assertIsArray($frames);
            $decodedAny = $decodedAny || $frames !== [];
        }

        self::assertSame([1, 6, 13, 25], array_slice($offsets, 0, 4), 'the ladder must start at byte 1 and cross the 13-byte header gate');
        self::assertSame($len - 1, $offsets[count($offsets) - 1], 'the ladder must reach len-1');
        self::assertCount(8, $offsets);

        // The shortest prefix is below the 13-byte gate: the NOT-GIF refusal
        // there is load-bearing (a file with no LSD has nothing to trust).
        [, $firstRefusal] = $this->replayAtPrefix($full, 1);
        self::assertNotNull($firstRefusal, 'a one-byte file must be refused, never decoded');
        self::assertSame(Lang::t('decoder.not_gif'), $firstRefusal->getMessage());

        self::assertTrue($refusedAny, 'the low ladder rungs must exercise the typed refusals');
        self::assertTrue($decodedAny, 'the high ladder rungs must exercise graceful partial decodes');
    }

    /**
     * @param list<int> $offsets
     * @return list<int>
     */
    private function distinctAscending(array $offsets, int $ceiling): array
    {
        $clean = [];
        foreach ($offsets as $offset) {
            if ($offset >= 1 && $offset <= $ceiling) {
                $clean[$offset] = true;
            }
        }
        $keys = array_keys($clean);
        sort($keys);

        return $keys;
    }

    /**
     * @return array{0: ?list<\SugarCraft\Animation\Frame>, 1: ?\RuntimeException}
     */
    private function replayAtPrefix(string $bytes, int $offset): array
    {
        $path = sys_get_temp_dir() . '/flip-e13-prefix-' . $offset . '-' . uniqid('', true) . '.gif';
        file_put_contents($path, substr($bytes, 0, $offset));
        $this->temp[] = $path;

        return $this->replay($path);
    }

    /**
     * Shared replay law: decode inside a diagnostic-honest window, budget
     * the allocation and the wall clock, and classify the outcome as
     * frames-array XOR typed refusal — everything else fails right here.
     *
     * @return array{0: ?list<\SugarCraft\Animation\Frame>, 1: ?\RuntimeException}
     */
    private function replay(string $pathOrRelative): array
    {
        $path = str_starts_with($pathOrRelative, 'tests/')
            ? dirname(__DIR__) . '/' . $pathOrRelative
            : $pathOrRelative;
        self::assertFileExists($path);

        $memBefore = memory_get_usage(true);
        $peakBefore = memory_get_peak_usage(true);
        $t0 = hrtime(true);

        $frames = null;
        $refusal = null;
        $handler = static function (int $severity, string $message): bool {
            if (0 === (error_reporting() & $severity)) {
                return false; // The decoder's documented @-suppression: not a leak.
            }

            throw new \ErrorException($message, 0, $severity);
        };
        set_error_handler($handler);
        try {
            $frames = Decoder::decode($path, 2, 2);
        } catch (\RuntimeException $e) {
            $refusal = $e;
        } catch (\Throwable $e) {
            self::fail('hostile input leaked an untyped error: ' . get_class($e) . ': ' . $e->getMessage());
        } finally {
            restore_error_handler();
            $wallMs = (int) ((hrtime(true) - $t0) / 1_000_000);
        }

        $usageMb = (memory_get_usage(true) - $memBefore) / 1_048_576;
        $peakMb = (memory_get_peak_usage(true) - $peakBefore) / 1_048_576;
        self::assertLessThan(
            (float) self::MEM_BUDGET_MB,
            $usageMb,
            "decode of {$pathOrRelative} retained " . round($usageMb, 1) . ' MB',
        );
        self::assertLessThan(
            (float) self::MEM_BUDGET_MB,
            $peakMb,
            "decode of {$pathOrRelative} climbed the process peak by " . round($peakMb, 1) . ' MB',
        );
        self::assertLessThan(
            self::WALL_BUDGET_MS,
            $wallMs,
            "decode of {$pathOrRelative} took {$wallMs} ms — the walk must stay bounded",
        );

        return [$frames, $refusal];
    }

    private function fixturePath(string $name): string
    {
        return 'tests/fixtures/bad/' . $name;
    }

    /**
     * Byte-exact source of every corpus member (and the ladder's valid
     * sample). Builder idiom per DecoderHugeDescriptorTest::framesGif —
     * spec-valid LZW at minimum code size 3 with a clear code before every
     * literal, so the streams are honest data rather than noise.
     */
    private function fixtureBytes(string $name): string
    {
        return match ($name) {
            'giant-descriptor-4x4.gif' => $this->head(4, 4)
                . $this->gce(10, 0) . $this->desc(0, 0, 65535, 65535) . $this->lzw(16, 1)
                . "\x3B",

            'off-canvas-giant-mixed.gif' => $this->head(4, 4)
                . $this->gce(10, 0) . $this->desc(2, 2, 65535, 65535) . $this->lzw(4, 1)
                . $this->gce(10, 0) . $this->desc(8, 8, 4, 4) . $this->lzw(16, 2)
                . $this->gce(10, 0) . $this->desc(0, 0, 4, 1) . $this->lzw(4, 3)
                . "\x3B",

            'lsd-zero-screen.gif' => $this->head(0, 0)
                . $this->gce(10, 0) . $this->desc(0, 0, 1, 1) . $this->lzw(1, 1)
                . "\x3B",

            'gct-past-eof.gif' => substr(
                $this->head(4, 4, 0x84) . $this->gce(10, 0) . "\x00",
                0,
                25, // header + 12 GCT bytes; declared table is 96.
            ),

            'zero-delay-storm-64.gif' => $this->head(4, 4)
                . str_repeat($this->gce(0, 0) . $this->desc(1, 1, 1, 1) . $this->lzw(1, 1), 64)
                . "\x3B",

            'frame-flood-300.gif' => $this->head(2, 2)
                . str_repeat($this->gce(1, 0) . $this->desc(0, 0, 1, 1) . $this->lzw(1, 1), 300)
                . "\x3B",

            'gct-flag-mismatch.gif' => 'GIF89a' . pack('v', 4) . pack('v', 4) . "\x03"
                . $this->gce(10, 0) . $this->desc(0, 0, 4, 4) . $this->lzw(16, 1)
                . "\x3B",

            'gct-size-desync.gif' => $this->head(4, 4, 0x82)
                . $this->gce(10, 0) . $this->desc(1, 1, 1, 1) . $this->lzw(1, 1)
                . "\x3B",

            'multi-frame-clip-interaction.gif' => $this->head(4, 4)
                . $this->gce(10, 0) . $this->desc(1, 1, 100, 100) . $this->lzw(9, 1)
                . $this->gce(5, 0) . $this->desc(0, 0, 1, 1) . $this->lzw(1, 2)
                . $this->gce(10, 0) . $this->desc(3, 3, 65535, 2) . $this->lzw(1, 3)
                . "\x3B",

            'bogus-disposal-4567.gif' => $this->head(2, 2)
                . $this->gce(10, 4) . $this->desc(0, 0, 1, 1) . $this->lzw(1, 1)
                . $this->gce(10, 5) . $this->desc(0, 0, 1, 1) . $this->lzw(1, 2)
                . $this->gce(10, 6) . $this->desc(0, 0, 1, 1) . $this->lzw(1, 3)
                . $this->gce(10, 7) . $this->desc(0, 0, 1, 1) . $this->lzw(1, 1)
                . "\x3B",

            'netscape-loop-ext.gif' => $this->head(4, 4)
                . "\x21\xFF\x0B" . 'NETSCAPE2.0' . "\x03\x01" . pack('v', 3) . "\x00"
                . $this->gce(10, 0) . $this->desc(0, 0, 4, 4) . $this->lzw(16, 1)
                . "\x21\xFF\x05" . 'junk!' . "\x3B", // no terminator: framed past its length, then EOF-side trailer

            'lzw-overlong.gif' => $this->head(4, 4)
                . $this->gce(10, 0) . $this->desc(0, 0, 1, 1) . $this->lzw(1024, 1)
                . "\x3B",

            'ladder-source.gif' => $this->head(4, 4)
                . $this->gce(5, 0) . $this->desc(0, 0, 2, 2) . $this->lzw(4, 1)
                . $this->gce(10, 0) . $this->desc(2, 0, 2, 2) . $this->lzw(4, 2)
                . $this->gce(15, 0) . $this->desc(0, 2, 4, 2) . $this->lzw(8, 3)
                . "\x3B",

            default => throw new \LogicException('no builder for fixture: ' . $name),
        };
    }

    /**
     * Signature + Logical Screen Descriptor + Global Color Table.
     * Packed byte 0x81 = GCT present, 4 entries (exp 1).
     */
    private function head(int $w, int $h, int $packed = 0x81, string $gct = self::GCT): string
    {
        return 'GIF89a' . pack('v', $w) . pack('v', $h) . chr($packed) . "\x00\x00" . $gct;
    }

    /** Graphic Control Extension: fixed 8 bytes, disposal 0..7 raw, transparent flag off. */
    private function gce(int $delayCs, int $disposal): string
    {
        return "\x21\xF9\x04" . chr(($disposal & 0x07) << 2) . pack('v', $delayCs) . "\x00\x00";
    }

    /** Image Descriptor: 10 bytes, packed 0x00 (no LCT, no interlace). */
    private function desc(int $left, int $top, int $w, int $h): string
    {
        return "\x2C" . pack('v', $left) . pack('v', $top) . pack('v', $w) . pack('v', $h) . "\x00";
    }

    /**
     * Spec-valid LZW image data block at minimum code size 3 (clear=8,
     * end=9, 4-bit codes): a clear code before every literal keeps the
     * table width fixed, so the stream is decodable by any conforming
     * reader while never needing a real compressor.
     */
    private function lzw(int $pixels, int $colorIndex): string
    {
        $codes = [];
        for ($i = 0; $i < $pixels; $i++) {
            $codes[] = 8;
            $codes[] = $colorIndex;
        }
        $codes[] = 9;

        $acc = 0;
        $n = 0;
        $packed = '';
        foreach ($codes as $code) {
            $acc |= $code << $n;
            $n += 4;
            while ($n >= 8) {
                $packed .= chr($acc & 0xFF);
                $acc >>= 8;
                $n -= 8;
            }
        }
        if ($n > 0) {
            $packed .= chr($acc & 0xFF);
        }

        $blocks = '';
        foreach (str_split($packed, 255) as $chunk) {
            $blocks .= chr(strlen($chunk)) . $chunk;
        }

        return "\x03" . $blocks . "\x00";
    }
}
