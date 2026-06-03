<?php

declare(strict_types=1);

namespace SugarCraft\Kit\Tests;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Kit\Frame;
use SugarCraft\Sprinkles\Style;
use PHPUnit\Framework\TestCase;

/**
 * @see Frame
 */
final class FrameTest extends TestCase
{
    /**
     * The frame must fill the terminal EXACTLY: every line is `$cols` display
     * cells and the whole frame is `$rows` lines. This is the load-bearing
     * frame-diff invariant.
     *
     * @dataProvider sizes
     */
    public function testFillsTerminalExactly(int $cols, int $rows): void
    {
        $out = Frame::new()
            ->withTitle('SugarSQL')
            ->withStatus('q:quit')
            ->render("line one\nline two", $cols, $rows);

        $lines = explode("\n", $out);
        self::assertCount($rows, $lines, "frame must be exactly $rows lines");
        foreach ($lines as $i => $line) {
            self::assertSame($cols, Width::string($line), "line $i must be exactly $cols cells");
        }
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function sizes(): iterable
    {
        yield '80x24'  => [80, 24];
        yield '100x48' => [100, 48];
        yield '120x40' => [120, 40];
    }

    /**
     * Line count stays constant (= $rows) regardless of how much body content
     * is supplied — empty, a few lines, or far more than fits.
     *
     * @dataProvider bodies
     */
    public function testConstantLineCount(string $body): void
    {
        $out = Frame::new()->render($body, 80, 24);
        self::assertCount(24, explode("\n", $out));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bodies(): iterable
    {
        yield 'empty'     => [''];
        yield 'one line'  => ['just one'];
        yield 'few lines' => ["a\nb\nc"];
        yield 'over-tall' => [implode("\n", array_fill(0, 100, 'row'))];
    }

    /** A body taller than the content area is hard-truncated, never overflows. */
    public function testOverTallBodyHardTruncates(): void
    {
        $body = implode("\n", array_map(static fn (int $i): string => "row$i", range(0, 99)));
        $out = Frame::new()->render($body, 80, 24);
        $lines = explode("\n", $out);

        self::assertCount(24, $lines);
        // 24 rows - 6 overhead = 18 content rows; rows 0..17 fit, row 18+ dropped.
        self::assertStringContainsString('row17', $out);
        self::assertStringNotContainsString('row18', $out);
        self::assertStringNotContainsString('row99', $out);
    }

    /** A body line wider than the terminal is truncated with an ellipsis. */
    public function testOverWideLineTruncatesWithEllipsis(): void
    {
        $out = Frame::new()->render(str_repeat('x', 500), 80, 24);

        self::assertStringContainsString('…', $out);
        foreach (explode("\n", $out) as $line) {
            self::assertSame(80, Width::string($line));
        }
    }

    /** A styled (SGR-laden) body line still pads to exactly the terminal width. */
    public function testStyledLinePadsByDisplayWidth(): void
    {
        $styled = Style::new()->bold()->foreground(Color::hex('#ff0000'))->render('hello');
        $out = Frame::new()->render($styled, 80, 24);

        self::assertStringContainsString('hello', $out);
        foreach (explode("\n", $out) as $line) {
            self::assertSame(80, Width::string($line));
        }
    }

    /** Wide CJK glyphs (2 cells each) in the centred title still fill exactly. */
    public function testWideCjkTitlePadsByDisplayWidth(): void
    {
        $out = Frame::new()->withTitle('数据库管理器')->render('body', 80, 24);

        self::assertStringContainsString('数据库管理器', $out);
        foreach (explode("\n", $out) as $line) {
            self::assertSame(80, Width::string($line));
        }
    }

    /** The diff renderer owns the screen — Frame must never emit a clear. */
    public function testEmitsNoScreenClear(): void
    {
        $out = Frame::new()->withTitle('t')->withStatus('s')->render("a\nb", 80, 24);
        self::assertStringNotContainsString("\x1b[2J", $out);
    }

    public function testDrawsDoubleLineBox(): void
    {
        $out = Frame::new()->render('body', 40, 10);
        foreach (['╔', '╗', '╚', '╝', '╠', '╣', '║', '═'] as $glyph) {
            self::assertStringContainsString($glyph, $out, "box should contain $glyph");
        }
    }

    public function testTitleAndStatusAppear(): void
    {
        $out = Frame::new()->withTitle('MY-TITLE')->withStatus('MY-STATUS')->render('body', 80, 24);
        self::assertStringContainsString('MY-TITLE', $out);
        self::assertStringContainsString('MY-STATUS', $out);
    }

    public function testWithersAreImmutable(): void
    {
        $base = Frame::new();
        self::assertNotSame($base, $base->withTitle('TITLEZ'));
        self::assertNotSame($base, $base->withStatus('STATUSZ'));
        self::assertNotSame($base, $base->withBorderStyle(Style::new()->bold()));

        // The base instance is unchanged: its render carries neither title nor status.
        $bare = $base->render('---', 80, 24);
        self::assertStringNotContainsString('TITLEZ', $bare);
        self::assertStringNotContainsString('STATUSZ', $bare);
    }

    public function testDefaultBorderIsSlate(): void
    {
        $out = Frame::new()->render('body', 40, 10);
        // rgb(100,116,139) truecolor SGR on the box-drawing chars.
        self::assertStringContainsString('38;2;100;116;139', $out);
    }

    public function testBorderStyleIsOverridable(): void
    {
        $out = Frame::new()
            ->withBorderStyle(Style::new()->foreground(Color::hex('#ff0000')))
            ->render('body', 40, 10);
        self::assertStringContainsString('38;2;255;0;0', $out);
        self::assertStringNotContainsString('38;2;100;116;139', $out);
    }
}
