<?php

declare(strict_types=1);

/**
 * Constraint-based dashboard — demonstrates the Layout constraint API.
 *
 * Uses ratatui-inspired constraint-based layout (Length, Min, Percentage,
 * Fill) to partition a terminal region into a 3-row / 3-column grid.
 *
 *   php examples/constraint-dashboard.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Layout as LegacyLayout;
use SugarCraft\Sprinkles\Layout\Constraint;
use SugarCraft\Sprinkles\Layout\Layout;
use SugarCraft\Sprinkles\Layout\Rect;
use SugarCraft\Sprinkles\Position;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Sprinkles\Table\Table;

// ── Build content for each pane ─────────────────────────────────────────────

$headerStyle = Style::new()
    ->bold()
    ->foreground(Color::hex('#ff5fd2'))
    ->padding(0, 2);

$header = $headerStyle->render('  SugarCraft Dashboard  ');

$status = Style::new()
    ->foreground(Color::ansi(10))
    ->border(Border::rounded())
    ->borderForeground(Color::ansi(8))
    ->padding(1, 1)
    ->render(' All systems nominal ');

$sidebarContent = Table::new()
    ->headers('Metric', 'Value')
    ->row('Tests',     '1,023')
    ->row('Assertions','5,775')
    ->row('Libraries', '42')
    ->row('PHP',       '8.1+')
    ->border(Border::rounded())
    ->styleFunc(static fn(int $row): Style
        => $row === Table::HEADER_ROW
            ? Style::new()->bold()->foreground(Color::ansi(13))
            : Style::new())
    ->render();

$mainContent = Style::new()
    ->foreground(Color::ansi(14))
    ->padding(1, 2)
    ->render(<<<TEXT
    Welcome to SugarCraft!

    This dashboard uses the constraint-based
    Layout API to partition the terminal:

      • Layout::vertical([...])  — row splits
      • Layout::horizontal([...]) — column splits

    Constraints: Length, Min, Percentage,
    Ratio, Max, Fill.
    TEXT);

$footer = Style::new()
    ->faint()
    ->foreground(Color::ansi(8))
    ->padding(0, 1)
    ->render(' ratatui-inspired constraint solver  sugarcraft.github.io ');

// ── Constraint-based layout ──────────────────────────────────────────────────

$area = Rect::fromSize(80, 24);

// Split vertically: header (fixed 3), body (flexible ≥15), footer (fixed 1)
$rows = Layout::vertical([
    Constraint::length(3),   // header
    Constraint::min(15),     // body — at least 15 rows, grows with slack
    Constraint::length(1),   // footer
])->split($area);

// Split the body row horizontally: sidebar (fixed 22), main (percentage 60), extra (fill)
$cols = Layout::horizontal([
    Constraint::length(22),    // sidebar
    Constraint::percentage(60), // main — 60% of remaining width
    Constraint::fill(1),       // extra column absorbs the rest
])->split($rows[1]);

// ── Render each region into its Rect ────────────────────────────────────────

$height = $rows[1]->height;

$sidebarRendered = Style::new()
    ->width($cols[0]->width)
    ->height($height)
    ->render($sidebarContent);

$mainRendered = Style::new()
    ->width($cols[1]->width)
    ->height($height)
    ->render($mainContent);

// Extra pane: tiny filler
$extraContent = Style::new()
    ->faint()
    ->foreground(Color::ansi(8))
    ->render("  extra\n  space");
$extraRendered = Style::new()
    ->width($cols[2]->width)
    ->height($height)
    ->render($extraContent);

// Assemble body row with joinHorizontal
$bodyRow = LegacyLayout::joinHorizontal(Position::TOP, $sidebarRendered, $mainRendered, $extraRendered);

// Assemble full layout
$headerRendered = Style::new()->width($area->width)->render($header);
$footerRendered = Style::new()->width($area->width)->render($footer);

$dashboard = LegacyLayout::joinVertical(Position::LEFT, $headerRendered, $bodyRow, $footerRendered);

echo $dashboard . "\n";
