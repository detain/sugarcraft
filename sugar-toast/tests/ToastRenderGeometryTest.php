<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Toast\{Position, Toast, ToastType};

/**
 * Frame-geometry pins for the round-2026-09-30 render audit (findings C2,
 * M3, M4, M5). Every expectation here is exact-row/zero-based — the audit
 * showed contains-string pins were keeping four placement defects alive
 * under a green suite.
 *
 * The composite model under test: background fills a viewport-wide frame;
 * each alert box is its own (possibly narrower) buffer blitted at its
 * position, so the canvas survives beyond maxWidth, right of narrow boxes,
 * and bottom stacks never grow the frame past the viewport.
 */
final class ToastRenderGeometryTest extends TestCase
{
    /** 24-row reference canvas so every 9-position landing is distinct. */
    private const BG24 = "line\n";

    /**
     * The audit's 9-position table: (x, y) of the box's ╭ corner for a
     * 50-wide × 3-row box on an 80×24 viewport. Pre-fix, xOffset was always
     * 0 (every box glued to the left edge).
     *
     * @return array<string, array{Position, int, int}>
     */
    public static function positionLandings(): array
    {
        return [
            'TopLeft' => [Position::TopLeft, 0, 0],
            'TopCenter' => [Position::TopCenter, 15, 0],
            'TopRight' => [Position::TopRight, 30, 0],
            'MiddleLeft' => [Position::MiddleLeft, 0, 10],
            'MiddleCenter' => [Position::MiddleCenter, 15, 10],
            'MiddleRight' => [Position::MiddleRight, 30, 10],
            'BottomLeft' => [Position::BottomLeft, 0, 21],
            'BottomCenter' => [Position::BottomCenter, 15, 21],
            'BottomRight' => [Position::BottomRight, 30, 21],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('positionLandings')]
    public function testEachPositionLandsTheBoxOnItsOwnCell(Position $position, int $x, int $y): void
    {
        $rows = $this->rows($position);

        $tops = [];
        foreach ($rows as $i => $row) {
            $col = \strpos($row, '╭');
            if ($col !== false) {
                $tops[$i] = $col;
            }
        }

        $this->assertSame([$y => $x], $tops, "{$position->name}: exactly one box top, at ({$x},{$y})");
        // The box must be whole inside the frame: base row carries ╰.
        $this->assertStringContainsString('╰', $rows[$y + 2]);
    }

    public function testAllNineLandingsAreDistinct(): void
    {
        $seen = [];
        foreach (self::positionLandings() as $name => [$position, , ]) {
            foreach ($this->rows($position) as $i => $row) {
                $col = \strpos($row, '╭');
                if ($col !== false) {
                    $seen["{$i}:{$col}"][] = $name;
                }
            }
        }

        $this->assertCount(9, $seen, 'the 9 positions must occupy 9 distinct anchor cells');
        foreach ($seen as $anchor => $names) {
            $this->assertCount(1, $names, "anchor {$anchor} claimed by multiple positions: " . \implode(',', $names));
        }
    }

    public function testBackgroundBeyondMaxWidthSurvives(): void
    {
        // Audit C2: maxWidth 50 used to CLIP THE FRAME itself, so a 60-char
        // background row lost cells 50-59 and the emitted rows were narrower
        // than the viewport. Now only the box is capped; the frame is wide.
        $bg = \str_repeat(\str_repeat('0123456789', 6) . "\n", 12);
        $rows = $this->clean(Toast::new(50)->alert(ToastType::Info, 'msg')->View($bg, 80, 12));

        // Row 0 is the box top border (cols 0-49); the band right of the box
        // keeps the background's second '0123456789' run.
        $border = '╭' . \str_repeat('─', 48) . '╮';
        $this->assertStringStartsWith($border, $rows[0]);
        $this->assertSame('0123456789', \substr($rows[0], \strlen($border), 10));

        // A background row below the 3-row box is byte-exact.
        $this->assertSame(\str_repeat('0123456789', 6), $rows[3]);
    }

    public function testCanvasRightOfNarrowBoxSurvives(): void
    {
        // Audit M5: the alert buffer used to be viewport-wide, so its blank
        // cells right of a narrow box were blitted OVER the background.
        $bg = \str_repeat(\str_repeat('B', 30) . "\n", 12);
        $rows = $this->clean(
            Toast::new(50)->withMinWidth(10)->alert(ToastType::Info, 'Hi')->View($bg, 40, 12)
        );

        // Box occupies cells 0-9 (minWidth 10); background 'B' survives from
        // cell 10 through its 30th cell, then frame padding.
        $this->assertSame('│', \substr($rows[1], 0, 3), 'box left border (cells 0-9: │ ℹ space H i 4sp │)');
        $this->assertSame('│', \substr($rows[1], 13, 3), 'box right border closes cell 9');
        $this->assertSame(\str_repeat('B', 20), \substr($rows[1], 16, 20), 'canvas cells 10-29 keep their background');
        $this->assertSame(\str_repeat('B', 30), $rows[4], 'rows below the box keep their background');
    }

    public function testBottomToastStaysInsideTheViewport(): void
    {
        // Audit M3: the sizing pass passed (cumulative − height) where the
        // placement pass wanted plain cumulative, inflating the frame so a
        // single bottom toast emitted BELOW the caller's content.
        $rows = $this->clean(
            Toast::new(50)->withPosition(Position::BottomCenter)
                ->alert(ToastType::Info, 'one')->View(self::bg(12), 80, 12)
        );

        $this->assertCount(12, $rows, 'a 3-row bottom toast must not grow a 12-row frame');
        $this->assertSame('line', $rows[8], 'first row under the box is still background');
        $this->assertStringContainsString('╭', $rows[9]);
        $this->assertStringStartsWith('line', $rows[9]);
        $this->assertStringEndsWith('╮', $rows[9]);
        $this->assertStringEndsWith('╯', $rows[11], 'box base sits flush on the last row');
    }

    public function testBottomStacksUpwardInsideTheFrame(): void
    {
        $toast = Toast::new(50)->withPosition(Position::BottomCenter);
        foreach (['x1', 'x2', 'x3'] as $message) {
            $toast = $toast->alert(ToastType::Info, $message);
        }
        $rows = $this->clean($toast->View(self::bg(24), 80, 24));

        $this->assertCount(24, $rows, 'three stacked bottom toasts must stay inside the 24-row viewport');
        $tops = [];
        $bases = [];
        foreach ($rows as $i => $row) {
            if (\str_contains($row, '╭')) {
                $tops[] = $i;
            }
            if (\str_contains($row, '╰')) {
                $bases[] = $i;
            }
        }
        // Queue order stacks UPWARD from the base row: first alert lowest.
        $this->assertSame([15, 18, 21], $tops, 'box tops stack upward, 3 rows apart');
        $this->assertSame(23, \max($bases), 'the lowest box base is flush with the viewport floor');
        $this->assertSame('line', $rows[12], 'canvas above the stack is untouched background');
    }

    public function testBoxIsCappedByViewportNotJustMaxWidth(): void
    {
        // maxWidth 100 on a 40-wide viewport: the box shrinks to the frame
        // instead of overflowing it (resolveWidth's capWidth parameter).
        $rows = $this->clean(
            Toast::new(100)->alert(ToastType::Info, 'msg')->View(self::bg(12), 40, 12)
        );

        $this->assertSame('╭' . \str_repeat('─', 38) . '╮', $rows[0]);
    }

    /**
     * @return list<string> stripped, padded box-top scan rows of one alert
     */
    private function rows(Position $position): array
    {
        return $this->clean(
            Toast::new(50)->withPosition($position)->alert(ToastType::Info, 'm')->View(self::bg(24), 80, 24)
        );
    }

    /**
     * @return list<string>
     */
    private function clean(string $view): array
    {
        $rows = \explode("\n", $view);
        if ($rows !== [] && \end($rows) === '') {
            unset($rows[\count($rows) - 1]);
            $rows = \array_values($rows);
        }

        return \array_map(static fn (string $row): string => \rtrim(Ansi::strip($row), ' '), $rows);
    }

    private static function bg(int $rows): string
    {
        return \str_repeat(self::BG24, $rows);
    }
}
