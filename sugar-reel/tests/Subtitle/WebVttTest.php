<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests\Subtitle;

use PHPUnit\Framework\TestCase;
use SugarCraft\Reel\Subtitle\Cue;
use SugarCraft\Reel\Subtitle\WebVtt;

/**
 * @covers \SugarCraft\Reel\Subtitle\WebVtt
 * @covers \SugarCraft\Reel\Subtitle\Cue
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

    public function testStripsInlineTagsAndKeepsLineBreaks(): void
    {
        $vtt = WebVtt::parse($this->sample());

        self::assertSame("Second line here\nwrapped", $vtt->cues()[1]->text, 'tags stripped, wrap preserved');
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
}
