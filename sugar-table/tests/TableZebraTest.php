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
 * fg 0;0;0, bg 229;229;229), the odd stripe is empty. Rows opt in with an
 * explicit stripe index via Row::withZebra($index) and then ignore
 * positional parity; unflagged rows keep parity striping. All stripe codes
 * compose UNDER the row's own style and any higher tier.
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

    public function testRowZebraOptInSelectsExplicitStripeIndex(): void
    {
        // Row at even position opting into stripe index 1 (the EMPTY odd
        // stripe by default) must NOT carry the parity stripe; pre-fix the
        // flag was never read and the row would stripe anyway.
        $view = $this->table(
            Row::new(RowData::from(['a' => 'zero']))->withZebra(1),
            Row::new(RowData::from(['a' => 'one'])),
        )->View();

        $this->assertStringNotContainsString('48;2;229;229;229', $view);
    }

    public function testDisabledZebraIsSilent(): void
    {
        $view = Table::fromColumns([Column::new('a', 'A', 8)])
            ->withRows([Row::new(RowData::from(['a' => 'zero']))])
            ->withSelectable(false)
            ->View();

        $this->assertStringNotContainsString('48;2;229;229;229', $view);
    }
}
