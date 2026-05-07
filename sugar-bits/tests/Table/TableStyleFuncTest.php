<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\Table;

use PHPUnit\Framework\TestCase;
use SugarCraft\Bits\Table\Table;
use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Style;

/**
 * Tests for {@see Table::styleFunc()} — per-cell styling closure
 * mirroring upstream charmbracelet/bubbles #246.
 */
final class TableStyleFuncTest extends TestCase
{
    private function newTable(): Table
    {
        return Table::new(
            ['Name', 'Status'],
            [
                ['Alice', 'OK'],
                ['Bob',   'FAIL'],
                ['Carol', 'OK'],
            ],
            0,
            10,
        );
    }

    public function testStyleFuncIsAppliedPerCell(): void
    {
        $t = $this->newTable()->styleFunc(static fn(int $row, int $col): Style
            => Style::new()->bold());
        $view = $t->view();
        // Bold SGR (1) should appear at least once per data cell + per header cell.
        // Two columns × four rows (1 header + 3 body) = 8 bold-on / 8 reset pairs.
        $this->assertSame(8, substr_count($view, "\x1b[1m"));
    }

    public function testStyleFuncSeesHeaderRowSentinel(): void
    {
        $sawHeader = false;
        $t = $this->newTable()->styleFunc(function (int $row, int $col) use (&$sawHeader): Style {
            if ($row === Table::HEADER_ROW) {
                $sawHeader = true;
            }
            return Style::new();
        });
        $t->view();
        $this->assertTrue($sawHeader, 'styleFunc should be called with HEADER_ROW for the header');
    }

    public function testStyleFuncReceivesCorrectRowIndices(): void
    {
        $rows = [];
        $t = $this->newTable()->styleFunc(function (int $row, int $col) use (&$rows): Style {
            $rows[] = $row;
            return Style::new();
        });
        $t->view();
        sort($rows);
        $unique = array_values(array_unique($rows));
        $this->assertSame([Table::HEADER_ROW, 0, 1, 2], $unique);
    }

    public function testStyleFuncReceivesEveryColumnIndex(): void
    {
        $cols = [];
        $t = $this->newTable()->styleFunc(function (int $row, int $col) use (&$cols): Style {
            $cols[] = $col;
            return Style::new();
        });
        $t->view();
        $this->assertSame([0, 1], array_values(array_unique($cols)));
    }

    public function testStripedRowsViaStyleFunc(): void
    {
        // Even data rows green, odd rows red (a common striped-rows pattern).
        $t = $this->newTable()->styleFunc(static function (int $row, int $col): Style {
            if ($row === Table::HEADER_ROW) {
                return Style::new()->bold();
            }
            return $row % 2 === 0
                ? Style::new()->foreground(Color::ansi(2))   // even = green
                : Style::new()->foreground(Color::ansi(1));  // odd  = red
        });
        $view = $t->view();
        // Both green (ANSI index 2) and red (ANSI index 1) SGR sequences
        // must appear. Style emits TrueColor by default, so check the
        // RGB-equivalent prefixes plus the ANSI fallbacks.
        $this->assertMatchesRegularExpression(
            '/\x1b\[(38;2;0;205;0|38;5;2|32)m/',
            $view,
            'green SGR should be emitted for even rows',
        );
        $this->assertMatchesRegularExpression(
            '/\x1b\[(38;2;205;0;0|38;5;1|31)m/',
            $view,
            'red SGR should be emitted for odd rows',
        );
    }

    public function testGetStyleFuncRoundTripsTheCallback(): void
    {
        $cb = static fn(int $row, int $col): Style => Style::new();
        $t = $this->newTable()->styleFunc($cb);
        $this->assertSame($cb, $t->getStyleFunc());
    }

    public function testStyleFuncNullClearsTheOverride(): void
    {
        $t = $this->newTable()->styleFunc(static fn(int $row, int $col) => Style::new()->bold());
        $this->assertNotNull($t->getStyleFunc());
        $t = $t->styleFunc(null);
        $this->assertNull($t->getStyleFunc());
        // No bold SGR should appear in the rendered output.
        $this->assertStringNotContainsString("\x1b[1m", $t->view());
    }

    public function testStyleFuncAndStylesComposeWithoutClobbering(): void
    {
        // styleFunc styles individual cells; Styles wraps the whole row.
        // Both should be present in the final output.
        $t = $this->newTable()
            ->withStyles(new \SugarCraft\Bits\Table\Styles(
                cell: Style::new()->italic(),
            ))
            ->styleFunc(static fn(int $row, int $col): Style => Style::new()->underline());
        $view = $t->view();
        // Italic (SGR 3) from row-level Styles + Underline (SGR 4) from styleFunc.
        $this->assertStringContainsString("\x1b[3m", $view);
        $this->assertStringContainsString("\x1b[4m", $view);
    }
}
