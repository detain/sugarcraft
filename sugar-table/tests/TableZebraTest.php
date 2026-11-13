<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Table\{Column, Row, RowData, Table};

/**
 * Zebra striping pins (audit M5 — Row::withZebra() was stored, never read;
 * the stripe REPLACED the row style instead of composing underneath it).
 *
 * Post-fix behavior: the default even stripe is '30;47' (ANSI16 canon:
 * fg 0;0;0, bg 229;229;229), the odd stripe is empty. Rows opt in with
 * Row::withZebra() (ST-28 bool form — pins the ODD stripe, ignoring
 * positional parity; false restores parity); unflagged rows keep parity
 * striping. All stripe codes compose UNDER the row's own style and any
 * higher tier.
 */
final class TableZebraTest extends TestCase
{
    private const EVEN_STRIKE = "\x1b[0;38;2;0;0;0;48;2;229;229;229";

    private function table(Row ...$rows): Table
    {
        return Table::fromColumns([Column::new('a', 'A', 8)])
            ->withRows(array_values($rows))
            ->withSelectable(false)
            ->withZebra(true);
    }

    public function testParityStripeLandsOnEvenRows(): void
    {
        $view = $this->table(
            Row::new(RowData::from(['a' => 'zero'])),
            Row::new(RowData::from(['a' => 'one'])),
        )->View();

        $this->assertStringContainsString(self::EVEN_STRIKE, $view);
        // Exactly one striped row (parity), the odd row stays bare.
        $this->assertSame(1, \substr_count($view, '48;2;229;229;229'));
    }

    public function testRowStyleComposesUnderTheStripe(): void
    {
        // Pre-fix the stripe REPLACED the row style: bold vanished.
        $view = $this->table(
            Row::new(RowData::from(['a' => 'zero']))->withStyle('1'),
        )->View();

        $this->assertStringContainsString(self::EVEN_STRIKE . ';1m', $view);
    }

    public function testRowZebraOptInPinsTheOddStripe(): void
    {
        // Row at even position opting in pins the ODD stripe (empty by
        // default) and ignores positional parity; pre-M5-fix the flag was
        // never read and the row would stripe anyway; pre-ST-28 the same
        // outcome required the int selector 1.
        $view = $this->table(
            Row::new(RowData::from(['a' => 'zero']))->withZebra(),
            Row::new(RowData::from(['a' => 'one'])),
        )->View();

        $this->assertStringNotContainsString('48;2;229;229;229', $view);
    }

    public function testRowZebraOptOutRestoresParity(): void
    {
        // withZebra(false) is a value-level no-op against an unflagged row:
        // the even position stripes exactly as parity dictates.
        $view = $this->table(
            Row::new(RowData::from(['a' => 'zero']))->withZebra(false),
        )->View();

        $this->assertStringContainsString(self::EVEN_STRIKE, $view);
    }

    public function testDisabledZebraIsSilent(): void
    {
        $view = Table::fromColumns([Column::new('a', 'A', 8)])
            ->withRows([Row::new(RowData::from(['a' => 'zero']))])
            ->withSelectable(false)
            ->View();

        $this->assertStringNotContainsString('48;2;229;229;229', $view);
    }

    /** Data rows r0..r5, windowed or paged per the call. */
    private function sixRows(): array
    {
        return \array_map(
            static fn (int $i): Row => Row::new(RowData::from(['a' => "r{$i}"])),
            \range(0, 5)
        );
    }

    /** @return array<string,string> row label => its rendered line (ANSI kept) */
    private function linesByLabel(string $view): array
    {
        $out = [];
        foreach (\explode("\n", $view) as $line) {
            foreach (['r0', 'r1', 'r2', 'r3', 'r4', 'r5'] as $label) {
                if (\str_contains($line, $label)) {
                    $out[$label] = $line;
                }
            }
        }
        return $out;
    }

    /**
     * Audit ST-18: the stripe must belong to the DATA row, not the frame
     * slot. Pre-fix, scrolling one step inverted every visible row's parity
     * (candy-sql's browse pane rode the flicker): r1 gained a stripe by
     * moving into slot 0 and r2 lost one.
     */
    public function testScrollStepKeepsStripesWithTheirDataRows(): void
    {
        // Selected row 2 makes the one-step scroll cursor-legal (audit ST-15
        // keeps the highlight on screen), so the window actually moves.
        $t = $this->table(...$this->sixRows())->withViewportHeight(3)->withSelectedIndex(2);
        $striped = '48;2;229;229;229';

        $at0 = $this->linesByLabel($t->View());
        $at1 = $this->linesByLabel($t->withScrollY(1)->View());
        $this->assertSame(['r0', 'r1', 'r2'], \array_keys($at0), 'window at home must show the first three rows');
        $this->assertSame(['r1', 'r2', 'r3'], \array_keys($at1), 'the scroll step must move the window');

        // r1 (abs 1, odd) sits in slot 1 scrolled to 0, slot 0 scrolled to
        // 1; it must be unstriped in BOTH. r2 (abs 2, even) sits in slot 2,
        // then slot 1; it must carry the stripe in BOTH.
        $this->assertStringNotContainsString($striped, $at0['r1']);
        $this->assertStringNotContainsString($striped, $at1['r1'], 'stripe flipped onto an odd row by scrolling');
        $this->assertStringContainsString($striped, $at0['r2']);
        $this->assertStringContainsString($striped, $at1['r2'], 'stripe fell off an even row by scrolling');
    }

    /** Page turns ride the same absolute domain as scrolling. */
    public function testPageTurnKeepsStripesWithTheirDataRows(): void
    {
        $t = $this->table(...$this->sixRows())->withPageSize(3);
        $striped = '48;2;229;229;229';

        $page1 = $this->linesByLabel($t->withPage(1)->View());

        // r3 is page slot 0 but data index 3 (odd): no stripe, ever.
        $this->assertStringNotContainsString($striped, $page1['r3'], 'slot parity striped an odd data row after a page turn');
        // r4 is slot 1 (odd under slot parity) but data index 4 (even).
        $this->assertStringContainsString($striped, $page1['r4'], 'even data row lost its stripe after a page turn');
    }
}
