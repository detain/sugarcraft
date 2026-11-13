<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Model;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\ScriptUi\Lang;
use SugarCraft\ScriptUi\Model\ChooseModel;
use SugarCraft\ScriptUi\Model\FilterModel;
use SugarCraft\ScriptUi\Model\PagerModel;
use SugarCraft\ScriptUi\Runtime\Geometry;

/**
 * scriptui-16 (finding 010) — the frame must fit the screen in BOTH
 * dimensions. A ~64-cell keybinding hint with no clamp to the measured
 * columns wrapped over several physical rows and pushed list+status out of
 * the rows budget (20×6 PTY repro), and the frame was never clipped to
 * rows at all (vertical overrun at rows ≤ 2). Law pinned here, with the
 * terminal census pinned through {@see Geometry::override()}:
 *
 *  - a hint (or pager footer) line the terminal is too narrow to hold
 *    unwrapped is DROPPED (gum's posture: hide, never wrap);
 *  - the composed list frame is clipped to the rows known at launch;
 *  - wide-enough terminals keep every discoverability line;
 *  - with nothing knowable (piped/cron) the historical frame — hints and
 *    all — survives byte-for-byte (-57 discoverability contract).
 */
final class FrameClipTest extends TestCase
{
    protected function tearDown(): void
    {
        Geometry::override(null);
    }

    /**
     * @return list<string>
     */
    private function assertFits(string $frame, int $cols, int $rows, string $label): array
    {
        $lines = explode("\n", $frame);
        $this->assertLessThanOrEqual($rows, count($lines), "$label: frame exceeds $rows rows");
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(
                $cols,
                Width::string($line),
                "$label: wrapped tail (line wider than $cols cols): [$line]"
            );
        }
        return $lines;
    }

    public function testChooseFrameFitsTwentyBySix(): void
    {
        Geometry::override(['cols' => 20, 'rows' => 6]);
        $model = ChooseModel::fromOptions(
            array_map(static fn(int $i): string => "item-$i", range(1, 10)),
            height: 10, limit: 5
        );
        $lines = $this->assertFits($model->view(), 20, 6, 'choose 20x6');
        // The hint is 60 cells — it must be gone, not wrapped.
        $this->assertNotContains(Lang::t('hints.choose.multi'), $lines, 'too-narrow hint must yield');
    }

    public function testChooseHintYieldsEvenWhenRowsHaveRoom(): void
    {
        Geometry::override(['cols' => 20, 'rows' => 20]);
        $model = ChooseModel::fromOptions(['one', 'two'], limit: 5);
        $view  = $model->view();
        $this->assertStringNotContainsString(Lang::t('hints.choose.multi'), $view, 'hint row count must not hide the width rule');
    }

    public function testFilterFrameFitsTwentyBySixAndTenByThree(): void
    {
        foreach ([[20, 6], [10, 3]] as [$cols, $rows]) {
            Geometry::override(['cols' => $cols, 'rows' => $rows]);
            $model = FilterModel::fromOptions(
                array_map(static fn(int $i): string => "entry-number-$i", range(1, 12)),
                height: 10, limit: 5
            );
            $lines = $this->assertFits($model->view(), $cols, $rows, "filter {$cols}x{$rows}");
            $this->assertNotContains(Lang::t('hints.filter'), $lines, "filter hint must yield at $cols cols");
        }
    }

    public function testFuzzyFrameFitsTwentyBySix(): void
    {
        Geometry::override(['cols' => 20, 'rows' => 6]);
        $model = FilterModel::fromOptions(
            array_map(static fn(int $i): string => "file-$i", range(1, 30)),
            height: 10, fuzzy: true, value: 'file'
        );
        $this->assertFits($model->view(), 20, 6, 'fuzzy 20x6');
    }

    public function testPagerFooterYieldsToNarrowColumns(): void
    {
        Geometry::override(['cols' => 20, 'rows' => 6]);
        $model = PagerModel::fromContent(str_repeat("page line\n", 40));
        $lines = $this->assertFits($model->view(), 20, 6, 'pager 20x6');
        $this->assertNotContains(Lang::t('hints.pager'), $lines, '74-cell footer must not wrap a 20-col screen');
        $this->assertNotContains(Lang::t('hints.pager.more'), $lines);
    }

    public function testWideTerminalKeepsEveryHintLine(): void
    {
        Geometry::override(['cols' => 120, 'rows' => 50]);
        $choose = ChooseModel::fromOptions(['a', 'b'], limit: 5);
        $this->assertStringContainsString(Lang::t('hints.choose.multi'), $choose->view());
        $filter = FilterModel::fromOptions(['a', 'b']);
        $this->assertStringContainsString(Lang::t('hints.filter'), $filter->view());
        $pager = PagerModel::fromContent("one\ntwo\n");
        $this->assertStringContainsString(Lang::t('hints.pager'), $pager->view());
        $this->assertStringContainsString(Lang::t('hints.pager.more'), $pager->view());
    }

    public function testUnknownGeometryKeepsHistoricalFrame(): void
    {
        // Empty override answers "nothing knowable" regardless of the
        // runner's ambient env: hints stay, rows stay unclipped.
        Geometry::override([]);
        $model = ChooseModel::fromOptions(array_map(strval(...), range(1, 40)), height: 40, limit: 5);
        $view  = $model->view();
        $this->assertStringContainsString(Lang::t('hints.choose.multi'), $view, 'piped frames keep the discoverability line');
        $this->assertGreaterThan(6, count(explode("\n", $view)), 'no rows bound without a knowable terminal');
    }

    public function testStatusLineCannotOutweighTheFrame(): void
    {
        Geometry::override(['cols' => 20, 'rows' => 6]);
        // A nine-hex-digit limit makes the '[0/N selected]' line itself
        // longer than the screen — status rides the same belt as the rows.
        $model = ChooseModel::fromOptions(['x'], height: 5, limit: PHP_INT_MAX);
        $lines = $this->assertFits($model->view(), 20, 6, 'choose status width');
        $status = array_values(array_filter(
            $lines,
            static fn(string $line): bool => str_starts_with($line, '[')
        ));
        $this->assertNotEmpty($status, 'the status line must still paint');
        // The raw '[0/9223372036854775807 selected]' row is 30 cells — it
        // must sit exactly at the budget, never past it (pre-belt status).
        $this->assertSame(20, Width::string($status[0]));
    }
}
