<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Shine\Renderer;
use SugarCraft\Shine\Theme;

/**
 * {@see Renderer::withPreservedNewLines()} keeps every run of blank lines
 * between blocks, exactly and in place (crush_libs shine #5: one blank line
 * was dropped from every run, and runs were applied to the first
 * separators of the output rather than where they stood).
 */
final class PreservedNewLinesTest extends TestCase
{
    private static function render(string $md): string
    {
        return (new Renderer(Theme::plain()))->withPreservedNewLines()->render($md);
    }

    /** @return iterable<string, array{string, string}> */
    public static function runs(): iterable
    {
        yield 'one blank line' => ["one\n\ntwo", "one\n\ntwo"];
        yield 'two blank lines' => ["one\n\n\ntwo", "one\n\n\ntwo"];
        yield 'three blank lines' => ["one\n\n\n\ntwo", "one\n\n\n\ntwo"];
        yield 'a run after a single break stays in place' => ["a\n\nb\n\n\nc", "a\n\nb\n\n\nc"];
        yield 'whitespace-only lines are blank' => ["a\n  \n\t\nb", "a\n\n\nb"];
        yield 'CRLF source' => ["a\r\n\r\n\r\nb", "a\n\n\nb"];
        yield 'blank lines inside a fence are the fence\'s' => ["```\nc\n\n\nd\n```\n\n\ne", "c\n \n \nd\n\n\ne"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('runs')]
    public function testBlankRunsSurviveExactly(string $md, string $expected): void
    {
        $this->assertSame($expected, self::render($md));
    }

    public function testRunsAroundAListAreKept(): void
    {
        $this->assertSame("para\n\n\n• x\n• y\n\n\nz", self::render("para\n\n\n- x\n- y\n\n\nz"));
    }

    public function testRunsInsideABlockquoteAreKept(): void
    {
        $this->assertSame("▎ q\n▎ \n▎ \n▎ r", self::render("> q\n>\n>\n> r"));
    }

    public function testOffByDefault(): void
    {
        $this->assertSame("one\n\ntwo", (new Renderer(Theme::plain()))->render("one\n\n\n\ntwo"));
    }

    public function testAReusedRendererDoesNotCarrySourceLinesOver(): void
    {
        $r = (new Renderer(Theme::plain()))->withPreservedNewLines();
        $r->render("a\n\n\n\nb");

        $this->assertSame("x\n\ny", $r->render("x\n\ny"));
    }
}
