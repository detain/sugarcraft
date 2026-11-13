<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests\Subtitle;

use PHPUnit\Framework\TestCase;
use SugarCraft\Video\Subtitle\Cue;
use SugarCraft\Video\Subtitle\WebVtt;

/**
 * @covers \SugarCraft\Video\Subtitle\WebVtt
 * @covers \SugarCraft\Video\Subtitle\Cue
 */
final class WebVttTest extends TestCase
{
    private function sample(): string
    {
        return "WEBVTT - Some Title\n\n"
            . "1\n"
            . "00:00:01.000 --> 00:00:04.000 align:start position:10%\n"
            . "Hello, world.\n\n"
            . "00:00:05.500 --> 00:00:08.000\n"
            . "Second <i>line</i> here\nwrapped\n\n"
            . "00:00:10.000 --> 00:00:12.000\n"
            . "Tom &amp; Jerry";
    }

    public function testParsesCuesSkippingTheHeader(): void
    {
        $vtt = WebVtt::parse($this->sample());

        self::assertCount(3, $vtt->cues());
        $first = $vtt->cues()[0];
        self::assertInstanceOf(Cue::class, $first);
        self::assertSame(1.0, $first->start);
        self::assertSame(4.0, $first->end);
        self::assertSame('Hello, world.', $first->text);
    }

    public function testStripsInlineTagsAndCollapsesLineBreaks(): void
    {
        // The old pin kept the LF — "collapse to single-line text" is what the
        // docblock promised and what Player's one-row-per-pane invariant
        // requires; a two-line subtitle must never shift the frame rows.
        $vtt = WebVtt::parse($this->sample());

        self::assertSame('Second line here wrapped', $vtt->cues()[1]->text, 'tags stripped, wrap collapsed');
    }

    public function testDecodesEntities(): void
    {
        $vtt = WebVtt::parse($this->sample());

        self::assertSame('Tom & Jerry', $vtt->cues()[2]->text);
    }

    public function testCueAtIsHalfOpenAndFindsTheActiveCaption(): void
    {
        $vtt = WebVtt::parse($this->sample());

        self::assertNull($vtt->cueAt(0.5), 'before the first cue');
        self::assertSame('Hello, world.', $vtt->cueAt(1.0), 'inclusive start');
        self::assertSame('Hello, world.', $vtt->cueAt(3.999));
        self::assertNull($vtt->cueAt(4.0), 'exclusive end');
        self::assertNull($vtt->cueAt(4.5), 'in a gap');
        self::assertSame('Tom & Jerry', $vtt->cueAt(11.0));
        self::assertNull($vtt->cueAt(99.0), 'after the last cue');
    }

    public function testShortTimestampWithoutHours(): void
    {
        $vtt = WebVtt::parse("WEBVTT\n\n01:30.000 --> 02:00.000\nMinutes only");

        self::assertSame(90.0, $vtt->cues()[0]->start);
        self::assertSame(120.0, $vtt->cues()[0]->end);
    }

    public function testSkipsNoteStyleAndRegionBlocks(): void
    {
        $vtt = WebVtt::parse(
            "WEBVTT\n\n"
            . "NOTE this is a comment with --> inside it\n\n"
            . "STYLE\n::cue { color: yellow }\n\n"
            . "REGION\nid:foo\n\n"
            . "00:00:01.000 --> 00:00:02.000\nReal cue"
        );

        self::assertCount(1, $vtt->cues());
        self::assertSame('Real cue', $vtt->cues()[0]->text);
    }

    public function testCueIdentifierStartingWithABlockKeywordStillParses(): void
    {
        // Identifiers that merely begin with REGION/STYLE/WEBVTT are real cues,
        // not block keywords — they have a timing line, so they must parse.
        $vtt = WebVtt::parse(
            "WEBVTT\n\n"
            . "REGION 5\n00:00:01.000 --> 00:00:02.000\nOne\n\n"
            . "STYLE note\n00:00:03.000 --> 00:00:04.000\nTwo\n\n"
            . "WEBVTT-ish\n00:00:05.000 --> 00:00:06.000\nThree"
        );

        self::assertCount(3, $vtt->cues());
        self::assertSame('One', $vtt->cues()[0]->text);
        self::assertSame('Two', $vtt->cues()[1]->text);
        self::assertSame('Three', $vtt->cues()[2]->text);
    }

    public function testToleratesSrtStyleInput(): void
    {
        // Numeric index line + comma millisecond separator.
        $srt = "1\n00:00:01,000 --> 00:00:03,000\nSubtitle one\n\n2\n00:00:04,000 --> 00:00:05,000\nSubtitle two";
        $vtt = WebVtt::parse($srt);

        self::assertCount(2, $vtt->cues());
        self::assertSame(1.0, $vtt->cues()[0]->start);
        self::assertSame('Subtitle one', $vtt->cues()[0]->text);
    }

    public function testCrlfAndBomAreNormalised(): void
    {
        $vtt = WebVtt::parse("\xEF\xBB\xBFWEBVTT\r\n\r\n00:00:01.000 --> 00:00:02.000\r\nWindows line");

        self::assertCount(1, $vtt->cues());
        self::assertSame('Windows line', $vtt->cues()[0]->text);
    }

    public function testEmptyAndMalformedInput(): void
    {
        self::assertTrue(WebVtt::parse('')->isEmpty());
        self::assertTrue(WebVtt::parse('WEBVTT')->isEmpty(), 'header only');
        self::assertTrue(WebVtt::parse("WEBVTT\n\nnot a timing line\njust text")->isEmpty());
        self::assertTrue(WebVtt::parse("WEBVTT\n\n00:00:01.000 --> garbage\ntext")->isEmpty(), 'bad end timestamp');
        self::assertTrue(WebVtt::parse("WEBVTT\n\naa:bb:cc --> 00:00:02.000\ntext")->isEmpty(), 'non-numeric timestamp');
        self::assertTrue(WebVtt::parse("WEBVTT\n\n1:2:3:4 --> 00:00:02.000\ntext")->isEmpty(), 'too many timestamp parts');
        self::assertTrue(WebVtt::parse("WEBVTT\n\n00:00:01.000 --> 00:00:02.000\n")->isEmpty(), 'empty text dropped');
    }

    /**
     * DoS guard: a track with more than MAX_CUES cues is truncated at the cap.
     *
     * Load-bearing: remove the `count($cues) >= MAX_CUES` break in parse() and
     * the cue count becomes unbounded (MAX_CUES + overflow) — this test fails.
     */
    public function testCueCountIsCappedAtMaxCues(): void
    {
        $cap = WebVtt::MAX_CUES;
        $overflow = 25;

        // Build a VTT with cap + overflow well-formed cues.
        $sb = "WEBVTT\n\n";
        for ($i = 0; $i < $cap + $overflow; $i++) {
            $start = sprintf('%02d:%02d:%02d.000', intdiv($i, 3600), intdiv($i % 3600, 60), $i % 60);
            $end   = sprintf('%02d:%02d:%02d.000', intdiv($i + 1, 3600), intdiv(($i + 1) % 3600, 60), ($i + 1) % 60);
            $sb .= "{$start} --> {$end}\nLine {$i}\n\n";
        }

        $vtt = WebVtt::parse($sb);

        self::assertCount($cap, $vtt->cues(), 'cue count must be capped at MAX_CUES');
    }

    /**
     * @testdox a track at exactly MAX_CUES is kept in full (cap is inclusive)
     */
    public function testTrackAtExactlyMaxCuesIsKept(): void
    {
        $cap = WebVtt::MAX_CUES;

        $sb = "WEBVTT\n\n";
        for ($i = 0; $i < $cap; $i++) {
            $start = sprintf('%02d:%02d:%02d.000', intdiv($i, 3600), intdiv($i % 3600, 60), $i % 60);
            $end   = sprintf('%02d:%02d:%02d.000', intdiv($i + 1, 3600), intdiv(($i + 1) % 3600, 60), ($i + 1) % 60);
            $sb .= "{$start} --> {$end}\nLine {$i}\n\n";
        }

        self::assertCount($cap, WebVtt::parse($sb)->cues());
    }

    public function testCueContains(): void
    {
        $cue = new Cue(5.0, 10.0, 'x');

        self::assertFalse($cue->contains(4.9));
        self::assertTrue($cue->contains(5.0));
        self::assertTrue($cue->contains(9.99));
        self::assertFalse($cue->contains(10.0));
    }

    /**
     * Binary-search tie-breaking: when $seconds falls inside multiple overlapping
     * cues, cueAt() returns the cue with the LATEST start time (first matching
     * from the end during the walk-back phase).
     *
     * Set up three overlapping cues:
     *   A: 0.0 – 10.0  "Background"
     *   B: 2.0 –  8.0  "Middle"
     *   C: 4.0 –  6.0  "Foreground"  (latest start = wins when overlapping)
     *
     * At t=5.0 all three contain the time; the binary search lands on C (index 2)
     * first, then walk-back confirms it contains t=5.0 and returns "Foreground".
     */
    public function testCueAtOverlappingCuesReturnsLatestStart(): void
    {
        $vtt = WebVtt::parse(
            "WEBVTT\n\n"
            . "00:00:00.000 --> 00:00:10.000\n"
            . "Background\n\n"
            . "00:00:02.000 --> 00:00:08.000\n"
            . "Middle\n\n"
            . "00:00:04.000 --> 00:00:06.000\n"
            . "Foreground"
        );

        // Before first cue: null
        self::assertNull($vtt->cueAt(-0.1));

        // Inside A: t=0.5 (A: [0.0, 10.0))
        self::assertSame('Background', $vtt->cueAt(0.5));

        // Inside A (A: [0.0, 10.0) contains 1.0 since 0.0 <= 1.0 < 10.0)
        self::assertSame('Background', $vtt->cueAt(1.0));

        // Inside A and B (before C starts) — B wins (later start)
        self::assertSame('Middle', $vtt->cueAt(3.0));

        // Inside all three — C wins (latest start)
        self::assertSame('Foreground', $vtt->cueAt(5.0));

        // Inside B and C (after C ends) — B wins (latest remaining start)
        self::assertSame('Middle', $vtt->cueAt(7.0));

        // Inside A only (after B and C end) — A wins
        self::assertSame('Background', $vtt->cueAt(9.0));

        // After last cue: null
        self::assertNull($vtt->cueAt(99.0));
    }

    /**
     * Binary search must handle a gap between cues correctly: cueAt() lands
     * on the cue just after the gap during the binary search, then walks back
     * past cues that don't contain $seconds until it finds one that does or
     * exhausts the list (returning null).
     */
    public function testCueAtBinarySearchWalkBackAcrossGap(): void
    {
        $vtt = WebVtt::parse(
            "WEBVTT\n\n"
            . "00:00:00.000 --> 00:00:01.000\n"
            . "First\n\n"
            // gap: 1.0 – 4.999
            . "00:00:05.000 --> 00:00:06.000\n"
            . "Second\n\n"
            // gap: 6.0 – 8.999
            . "00:00:09.000 --> 00:00:10.000\n"
            . "Third"
        );

        self::assertSame('First', $vtt->cueAt(0.5),  'inside first cue');
        self::assertNull($vtt->cueAt(1.5),  'in first gap');
        self::assertSame('Second', $vtt->cueAt(5.5), 'inside second cue');
        self::assertNull($vtt->cueAt(7.5),  'in second gap');
        self::assertSame('Third', $vtt->cueAt(9.5), 'inside third cue');
    }

    /** Build a single-cue track and hand back its caption text. */
    private function captionOf(string $body): string
    {
        $vtt = WebVtt::parse("WEBVTT\n\n00:00:00.000 --> 00:00:01.000\n" . $body);
        $cues = $vtt->cues();

        return $cues === [] ? '' : $cues[0]->text;
    }

    /**
     * C1 (security+qa convergence): the old cleanText stripped `<…>` spans
     * BEFORE entity decoding, so an entity-encoded tag survived as live
     * markup. A double-encoded span must settle through the fixpoint loop —
     * nothing angle-bracketed reaches the cue no matter how many &amp; coats
     * hide it.
     */
    public function testDoubleEncodedTagsCannotSurviveTheDecodeFixpoint(): void
    {
        // The visible residue after strip+decode is the inert word, never markup.
        self::assertStringNotContainsString('<', $this->captionOf('&amp;lt;script&amp;gt;'));
        self::assertStringNotContainsString('>', $this->captionOf('&amp;lt;script&amp;gt;'));
        self::assertStringNotContainsString('<', $this->captionOf('&lt;em&gt;note&lt;/em&gt;'));
        self::assertSame('note', $this->captionOf('&lt;em&gt;note&lt;/em&gt;'));
    }

    /**
     * C1: `&#10;`/`&#27;`/CR/TAB decoded to LF/ESC/CR/TAB used to sail into
     * the composed ANSI frame. No control byte may survive: ESC must never
     * appear, newlines and tabs are collapsed (the row invariant), and the
     * OSC-8/SGR spellings must not be executable.
     */
    public function testDecodedControlBytesNeverReachCueText(): void
    {
        $caption = $this->captionOf("a&#10;b&#13;c&#9;d\x1b[31mred\x1b]8;;http://evil\x07tail\x07end");

        self::assertStringNotContainsString("\x1b", $caption, 'no ESC introducer survives');
        self::assertStringNotContainsString("\n", $caption, 'no LF survives');
        self::assertStringNotContainsString("\r", $caption, 'no CR survives');
        self::assertStringNotContainsString("\t", $caption, 'no TAB survives');
        self::assertStringNotContainsString("\x07", $caption, 'no BEL survives');
        self::assertMatchesRegularExpression('/^[\x20-\x7e\x{80}-\x{10FFFF}]*$/u', $caption, 'printable UTF-8 only');

        // Raw in-file control bytes (not entity spellings) get the same sweep.
        self::assertStringNotContainsString("\x1b", $this->captionOf("live\x9bCSI and raw\x00nul\x7fdel"));
    }

    /**
     * C1 (8th-lib zone-sentinel family): a forged sugar-mouse U+E000/U+E001
     * pair in a caption would register as an attacker-authored click target
     * in any host that zones around the player frame.
     */
    public function testForgedZoneSentinelsAreSwept(): void
    {
        $caption = $this->captionOf("click\u{E000}mouse:evil\u{E001}here");

        self::assertStringNotContainsString("\xEE\x80\x80", $caption, 'OPEN sentinel gone');
        self::assertStringNotContainsString("\xEE\x80\x81", $caption, 'CLOSE sentinel gone');
        // Entity spellings of the same codepoints, likewise.
        $viaEntity = $this->captionOf('&#xE000;forged&#xE001;');
        self::assertStringNotContainsString("\xEE\x80\x80", $viaEntity);
    }

    /**
     * Invalid UTF-8 in a caption is repaired to U+FFFD, never dropped and
     * never left as raw bytes (the measurement said non-UTF-8 passed through
     * the old path untouched).
     */
    public function testInvalidUtf8IsRepairedNotPassedThrough(): void
    {
        $caption = $this->captionOf("ok\xFF\xFE tail");

        self::assertTrue(mb_check_encoding($caption, 'UTF-8'), 'caption is valid UTF-8');
        self::assertStringNotContainsString("\xFF", $caption);
        self::assertStringContainsString("\u{FFFD}", $caption, 'a visible stand-in marks the repair');
    }

    /** A legitimate caption keeps its text — the sweep is not a black hole. */
    public function testBenignCaptionsAreUnharmed(): void
    {
        self::assertSame('Tom & Jerry — 你好 🎬', $this->captionOf('Tom &amp; Jerry — 你好 🎬'));
        self::assertSame('3 < 5? fine.', $this->captionOf('3 &lt; 5? fine.'), 'a lone decoded lt is inert text, no tag survives');
    }

    /**
     * The egress re-assert is idempotent: Player calls sanitizeForTerminal()
     * on text the parser already swept, and legitimate glyphs must not be
     * mangled a second time (defense-in-depth must not become defense-in-the-
     * way). PUA beyond the zone pair (image markers U+E002+) passes by design.
     */
    public function testSanitizeForTerminalIsIdempotentAndKeepsOtherPua(): void
    {
        $once = WebVtt::sanitizeForTerminal("a\u{E000}b\u{E002}c&#10;plain");
        $twice = WebVtt::sanitizeForTerminal($once);

        self::assertSame($once, $twice, 'idempotent');
        self::assertStringNotContainsString("\xEE\x80\x80", $once, 'sentinels swept');
        self::assertStringContainsString("\xEE\x80\x82", $once, 'U+E002 image marker survives by design');
    }
    // ── SV-VTT ceilings and the bounded cueAt ────────────────────────────────

    /** 078: parse() is public API and caps its own input — a block storm that
     * MAX_CUES would only notice mid-materialisation is refused up front. */
    public function testATrackAboveTheByteCeilingIsRefusedTyped(): void
    {
        $payload = "WEBVTT\n\n" . str_repeat('x', WebVtt::MAX_VTT_BYTES + 1);

        try {
            WebVtt::parse($payload);
            self::fail('the oversized track must be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('ceiling', $e->getMessage());
        }

        // Just-under still parses: the guard refuses payloads it names, not the
        // legitimate files the 4 MiB read cap already admitted.
        $ok = "WEBVTT\n\n00:00.000 --> 00:01.000\n"
            . str_repeat('y', WebVtt::MAX_VTT_BYTES - 64);
        self::assertFalse(WebVtt::parse($ok)->isEmpty(), 'within-ceiling track parses');
    }

    /** 081: one giant block lands CLAMPED with a visible seam, not verbatim. */
    public function testACueTextIsClampedAtCollectTimeWithAnEllipsis(): void
    {
        // é is two UTF-8 bytes: 3000 of them are 6000 bytes over the 4 KiB gate.
        $huge = str_repeat('é', 3000);
        $vtt = WebVtt::parse("WEBVTT\n\n00:00.000 --> 00:01.000\n{$huge}");
        $text = $vtt->cues()[0]->text;

        self::assertLessThanOrEqual(WebVtt::MAX_CUE_TEXT_BYTES, strlen($text), 'within the ceiling');
        self::assertGreaterThanOrEqual(WebVtt::MAX_CUE_TEXT_BYTES - 4, strlen($text),
            'mb_strcut lands within one codepoint of the gate — it is not a mass-trim');
        self::assertStringEndsWith('…', $text, 'the truncation is visible');
        self::assertTrue(mb_check_encoding($text, 'UTF-8'), 'the byte cut never splits a codepoint');

        // At-ceiling text passes untouched (clamp is >, not >=).
        $exact = str_repeat('a', WebVtt::MAX_CUE_TEXT_BYTES);
        $kept = WebVtt::parse("WEBVTT\n\n00:00.000 --> 00:01.000\n{$exact}")->cues()[0]->text;
        self::assertSame($exact, $kept, 'a legal cue is never touched');
    }

    /** 084: a long caption-free gap costs logarithmic bracketing, not a
     * contains() charge per silent cue — and tie-break semantics are untouched. */
    public function testCueAtBracketsInsteadOfWalkingGaps(): void
    {
        $lines = ['WEBVTT', ''];
        // 2000 cues packed into [0, 20) — none contains t = 50.
        for ($i = 0; $i < 2000; $i++) {
            $start = $i * 0.01;
            $lines[] = sprintf('00:%02d.%03d --> 00:%02d.%03d',
                (int) $start, (int) round(($start - (int) $start) * 1000),
                (int) ($start + 0.005), (int) round((($start + 0.005) - (int) ($start + 0.005)) * 1000));
            $lines[] = "dense-{$i}";
            $lines[] = '';
        }
        $vtt = WebVtt::parse(implode("\n", $lines));

        self::assertNull($vtt->cueAt(50.0), 'a gap past a dense history answers without the walk');
        self::assertSame('dense-0', $vtt->cueAt(0.001), 'a cue inside the history still answers');
        self::assertNull($vtt->cueAt(30.0), 'still null deeper into the gap');

        // Overlap tie-break (latest start wins) preserved.
        $over = WebVtt::parse("WEBVTT\n\n"
            . "00:00.000 --> 00:10.000\nA\n\n"
            . "00:02.000 --> 00:04.000\nB\n\n"
            . "00:03.000 --> 00:09.000\nC\n");
        self::assertSame('A', $over->cueAt(1.0));
        self::assertSame('B', $over->cueAt(2.5));
        self::assertSame('C', $over->cueAt(3.5));
        self::assertSame('A', $over->cueAt(9.5), 'C ended at 9; A still holds');

        // Construction assert: the prefix-max array backing the bracket is
        // exactly the running maximum of ends, non-decreasing.
        $reflected = (new \ReflectionProperty(WebVtt::class, 'maxEnds'))->getValue($over);
        self::assertSame([10.0, 10.0, 10.0], $reflected, 'prefix maxima, one slot per cue');
    }

    /** The removed linear walk must stay removed (anti-drift). */
    public function testTheGapWalkIsGoneFromTheSource(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Subtitle/WebVtt.php');

        self::assertStringNotContainsString('while ($result !== null && $result >= 0)', $src,
            'the unbounded walk-back arm must not return');
        self::assertStringContainsString('$this->maxEnds[$mid] >= $seconds', $src,
            'the bracket keeps consulting the prefix array');
    }
}
