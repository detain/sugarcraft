<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Renderer\AsciiRenderer;
use SugarCraft\Image\Renderer\HalfBlockRenderer;
use SugarCraft\Image\Renderer\QuarterBlockRenderer;
use SugarCraft\Image\Support\OutputOptimizer;

/**
 * A-13 reset-scope pins.
 *
 * The pre-A-13 emitters closed every row (and, in the block renderers, every
 * cell) with `ESC[0m`. That is a full SGR clobber: it erases bold, underline,
 * blink and inverse of whatever frame the image is riding in — a TUI painting
 * a blinking cursor line around a mosaic loses the blink. Chafa solved this
 * by ending rows with scoped resets (reset_fg / reset_attributes against the
 * pen the printer entered with, chafa-canvas-printer.c). These pins hold the
 * door shut: a clobber reset in any emitter output, or a missing restore at a
 * row end, reddens here even when every byte-level golden stays green.
 */
final class ResetScopeTest extends TestCase
{
    /** @return array<string, array{0: object, 1: string, 2: int, 3: int}> */
    public static function renderedFixtures(): array
    {
        return [
            'halfblock' => [new HalfBlockRenderer(), '4x2.png', 4, 2],
            'quarterblock' => [new QuarterBlockRenderer(), '4x4_qb.png', 4, 4],
            'ascii-truecolor' => [new AsciiRenderer(), '4x2.png', 4, 2],
            'ascii-ansi256' => [new AsciiRenderer(\SugarCraft\Image\Renderer\AsciiColorMode::Ansi256), '4x2.png', 4, 2],
            'ascii-mono' => [new AsciiRenderer(\SugarCraft\Image\Renderer\AsciiColorMode::Mono), '4x2.png', 4, 2],
        ];
    }

    /**
     * @param object $renderer
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('renderedFixtures')]
    public function testNoClobberResetInAnyEmitterOutput(object $renderer, string $fixture, int $w, int $h): void
    {
        $out = $renderer->render(ImageSource::fromFile(__DIR__ . '/fixtures/' . $fixture), $w, $h);

        self::assertStringNotContainsString("\x1b[0m", $out, 'A-13: ESC[0m must never leave an optimizer-fed emitter');
        self::assertStringNotContainsString("\x1b[m", $out, 'the empty-parameter spelling is the same clobber');
    }

    public function testColouredRowsEndByRestoringTheDefaultPen(): void
    {
        $out = (new HalfBlockRenderer())->render(ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png'), 4, 2);

        foreach (explode("\n", $out) as $i => $row) {
            if (!str_contains($row, "\x1b[")) {
                continue;
            }
            self::assertMatchesRegularExpression('/(\x1b\[39;49m|\x1b\[39m|\x1b\[49m)$/', $row, "row $i must close with a scoped 39/49 restore");
        }
    }

    public function testCallerAttributeSurvivesAWholeRenderedImage(): void
    {
        // Simulated caller frame: blink armed, mosaic streamed through, blink
        // must still be in force after every row the emitter produced — the
        // emitter may only ever switch off what IT switched on.
        $out = (new AsciiRenderer())->render(ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png'), 4, 2);

        self::assertStringNotContainsString("\x1b[0m", $out);
        self::assertStringNotContainsString("\x1b[25m", $out, 'the optimizer never switches off an attribute it did not arm');
    }

    public function testPenStateReturnsToEntryAfterFinishRow(): void
    {
        $pen = new OutputOptimizer('38;2;7;7;7');
        $pen->cell('a', OutputOptimizer::trueColor(1, 2, 3), OutputOptimizer::indexed256(4));
        $pen->attribute('bright', true);
        $pen->finishRow();

        self::assertSame('38;2;7;7;7', $pen->foreground());
        self::assertNull($pen->background());
        $second = $pen->finishRow();
        self::assertSame('', $second, 'a row that changes nothing emits nothing back to the caller');
    }
}
