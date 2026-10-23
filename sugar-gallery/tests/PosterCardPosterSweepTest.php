<?php

declare(strict_types=1);

namespace SugarCraft\Gallery\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Gallery\PosterCard;

/**
 * The poster-bytes trust boundary (docs-review-1023 M5): a poster loaded
 * through a disk cache / network pipeline reaches withPoster() as opaque
 * bytes, and withPoster() is VERBATIM by design (documented caller law).
 * withSafePoster() is the coercing half — per-line AnsiGuard::sanitize(),
 * so hostile escape runs die while legitimate Mosaic art (block glyphs +
 * SGR colour) survives byte-identical and the row structure holds.
 */
final class PosterCardPosterSweepTest extends TestCase
{
    private function card(): PosterCard
    {
        return new PosterCard('1', 'T');
    }

    public function testCleanMosaicArtSurvivesByteIdentical(): void
    {
        $ansi = "\x1b[38;2;10;20;30m▒█▓\x1b[39m\n\x1b[0m█░▒\x1b[39m▓";

        self::assertSame($ansi, $this->card()->withSafePoster($ansi)->poster,
            'the sweep must be a no-op on exactly the art shape sugar-image emits');
    }

    public function testForgedEscapesDieWhileArtAndRowsStay(): void
    {
        $forged = "\x1b[31m▒\x1b]8;;http://evil.example\x07hit\x1b[2J█\n▒\x1b[10;1H\x0b░";
        $swept = $this->card()->withSafePoster($forged)->poster;

        self::assertStringNotContainsString("\x1b]", $swept, 'OSC sequence must not survive');
        self::assertStringNotContainsString("\x07", $swept, 'OSC terminator must not survive');
        self::assertStringNotContainsString("\x1b[2J", $swept, 'erase must not survive');
        self::assertStringNotContainsString("\x1b[10;1H", $swept, 'cursor move must not survive');
        self::assertStringNotContainsString("\x0b", $swept, 'vertical tab must not survive');
        self::assertStringNotContainsString('evil.example', $swept, 'the OSC payload text dies with its sequence');

        // What is left is the art: SGR kept, surrounding glyphs and text kept,
        // exactly two rows (calibrated against AnsiGuard's own run scanner).
        self::assertSame("\x1b[31m▒hit█\n▒░", $swept);
        self::assertSame(2, substr_count($swept, "\n") + 1, 'row count is a structural property the sweep cannot touch');
    }

    public function testWithPosterStaysVerbatimByContract(): void
    {
        // The documented law: withPoster() forwards bytes untouched — pinning
        // it here keeps a future "just sanitize everything" reflex from
        // silently redefining the API (the coercion is a CHOICE the caller
        // makes by picking withSafePoster()).
        $raw = "\x1b[2Jart\x07";
        self::assertSame($raw, $this->card()->withPoster($raw)->poster);
    }

    public function testLineEndingsNormaliseWithoutLosingRows(): void
    {
        self::assertSame("a\nb\nc\nd", $this->card()->withSafePoster("a\r\nb\rc\nd")->poster);
    }

    public function testUnterminatedEscapeSwallowsItsLineTailNotTheString(): void
    {
        // AnsiGuard's documented shape: a truncated sequence eats the rest of
        // its OWN line — the following rows of the poster are untouched.
        $swept = $this->card()->withSafePoster("\x1b[38;2;1\nsecond")->poster;
        self::assertSame("\nsecond", $swept);
    }

    public function testTheSweepCarriesEveryOtherField(): void
    {
        $card = new PosterCard('9', 'Keep', 'https://u', 0.5, 'old', "\e[1mS", 'img', 3);
        $swept = $card->withSafePoster("\x1b[0mnew");

        self::assertSame(['9', 'Keep', 'https://u', 0.5, "\e[1mS", 'img', 3],
            [$swept->id, $swept->title, $swept->posterUrl, $swept->progress, $swept->styledTitle, $swept->posterImage, $swept->imageId]);
        self::assertSame("\x1b[0mnew", $swept->poster);
    }
}
