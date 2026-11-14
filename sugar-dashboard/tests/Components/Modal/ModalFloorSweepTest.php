<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Modal;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dashboard\Components\Feedback\Alert;
use SugarCraft\Dashboard\Components\Modal\Modal;
use SugarCraft\Dashboard\Components\Modal\ProgressModal;

/**
 * Permanent floor-width sweep for the modal family (finding sugar-dashboard-060).
 *
 * A long title inside the floor-20 modal (plus a floor allocation smaller
 * than the modal) used to raise a ValueError from str_repeat() on the
 * negative dash padding; ProgressModal overflowed its bar when current
 * exceeded total, and Alert lost its border rows under width 4. The sweep
 * keeps every narrow-allocation path crash-free IN SUITE, forever.
 */
final class ModalFloorSweepTest extends TestCase
{
    private const LONG_TITLE = 'Extremely Long Modal Title That Overruns Floor Widths Easily';

    public function testModalWidthSweepNeverCrashes(): void
    {
        $renders = [];
        for ($w = 1; $w <= 40; $w++) {
            $modal = (new Modal('content', title: self::LONG_TITLE))
                ->setSize($w, 8);
            $renders[$w] = $modal->render();
        }

        foreach ($renders as $w => $rendered) {
            $this->assertNotSame('', $rendered, "modal at width {$w} must paint something");
        }

        // Healthy allocation: nothing may exceed the box it was given.
        foreach (explode("\n", $renders[40]) as $row) {
            $this->assertLessThanOrEqual(40, Width::string($row), 'no row may overrun the allocation');
        }
    }

    public function testWideGlyphTitleSweepNeverCrashes(): void
    {
        // CJK title: every glyph costs two cells, so the truncation budget
        // has to be counted in CELLS, not codepoints.
        for ($w = 1; $w <= 40; $w++) {
            $modal = (new Modal('content', title: '日本語タイトルです'))
                ->setSize($w, 8);
            $rendered = $modal->render();
            $this->assertNotSame('', $rendered, "CJK-titled modal at width {$w} must paint");
        }

        foreach (explode("\n", (new Modal('content', title: '日本語タイトルです'))
            ->setSize(30, 8)->render()) as $row) {
            $this->assertLessThanOrEqual(30, Width::string($row), 'CJK rows stay inside the allocation');
        }
    }

    public function testTitleBeyondBudgetIsTruncatedNotOverflowing(): void
    {
        $modal = (new Modal('content', title: self::LONG_TITLE))->setSize(24, 8);
        $top = explode("\n", $modal->render())[0];

        // The title row must not spill past the modal box; the long title
        // is cell-truncated to the interior budget.
        $this->assertLessThanOrEqual(24, Width::string($top), 'title row fits the 24-wide box');
    }

    public function testProgressModalBeyondTotalClampsBar(): void
    {
        $over = ProgressModal::new(7, 5)->render();
        $this->assertStringContainsString('[' . str_repeat('█', 30) . ']', $over, 'past-total fills clamp to the full bar');

        $negative = ProgressModal::new(-3, 5)->render();
        $this->assertStringContainsString('[' . str_repeat('░', 30) . ']', $negative, 'negative progress clamps to empty');
    }

    public function testAlertNarrowWidthsNeverCrash(): void
    {
        for ($w = 1; $w <= 6; $w++) {
            $alert = Alert::new('narrow')->withTitle('T')->setSize($w, 4);
            $rendered = $alert->render();
            $this->assertIsString($rendered, "alert at width {$w} renders");
        }
    }
}
