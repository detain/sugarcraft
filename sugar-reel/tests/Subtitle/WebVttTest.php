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
}
