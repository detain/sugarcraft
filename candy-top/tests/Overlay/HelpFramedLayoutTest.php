<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Overlay;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Top\Input\KeyTable;
use SugarCraft\Top\Lang;
use SugarCraft\Top\Overlay\HelpMenu;
use SugarCraft\Top\View\Surface;

/**
 * The framed help layout: every section is its own box with the heading
 * embedded in the top border (no free-standing bold heading rows), boxes
 * are atomic across column and page breaks — an over-tall section continues
 * as a fresh framed segment — and the wider two-column budget wraps fewer
 * descriptions than the old three-column grid ever did.
 */
final class HelpFramedLayoutTest extends OverlayTestCase
{
    /** (a) every heading rides a top border, embedded exactly once, nowhere free. */
    public function testEverySectionHeadingRidesATopBorder(): void
    {
        $surface = Surface::new(168, 80);
        HelpMenu::new()->paint($surface, self::ctx(168, 80));
        $lines = $surface->plainLines();
        foreach (array_keys(KeyTable::SECTIONS) as $heading) {
            $title = Lang::t($heading);
            $embedded = array_values(array_filter(
                $lines,
                static fn (string $line): bool => str_contains($line, '┐' . $title . '┌'),
            ));
            $this->assertCount(1, $embedded, $heading . ' is embedded in exactly one top border on the single fitting page');
        }
        // The old layout's tell — a row that is ONLY the heading (bold, at
        // the column start, no box glyphs around it) — must be gone.
        foreach ($lines as $line) {
            foreach (array_keys(KeyTable::SECTIONS) as $heading) {
                $title = trim(Lang::t($heading));
                $this->assertNotSame(
                    $title,
                    trim(str_replace(['│', '┐', '┌'], ' ', $line)),
                    $heading . ' must never paint as a free-standing heading row',
                );
            }
        }
    }

    /** (b) on a page that fits, the frame count is one outer box plus one box per section. */
    public function testFittingPageCarriesOneFramePerSection(): void
    {
        $this->assertSame(1, HelpMenu::geometry(168, 80)[3], 'the whole plan fits one page at 168x80');
        $surface = Surface::new(168, 80);
        HelpMenu::new()->paint($surface, self::ctx(168, 80));
        $plain = implode("\n", $surface->plainLines());
        $sections = count(KeyTable::SECTIONS);
        $this->assertSame(1 + $sections, substr_count($plain, '╭'), 'outer box plus one top border per section');
        $this->assertSame(1 + $sections, substr_count($plain, '╰'), 'every frame closes (page counter rides the outer bottom)');
    }

    /**
     * (c) boxes are atomic: on every painted page of the 120x40 plan (which
     * splits Panels & views across two segments), each planned box's top
     * border, side glyphs and bottom border close inside ONE column window —
     * never with a top border in one column and its rows in another.
     */
    public function testFramesCloseWithinTheirColumnOnEveryPage(): void
    {
        [$x, $y, $height] = HelpMenu::geometry(120, 40);
        $width = HelpMenu::widthFor(120);
        $columnWidth = HelpMenu::columnWidth($width, HelpMenu::columnsFor($width));
        $plan = HelpMenu::plan($width, $height - 3);
        $this->assertGreaterThan(1, count($plan), '120x40 must paginate so the sweep sees real page turns');

        $menu = HelpMenu::new();
        foreach ($plan as $page => $columns) {
            $surface = Surface::new(120, 40);
            $menu->paint($surface, self::ctx(120, 40));
            $lines = $surface->plainLines();
            $boxes = 0;
            foreach ($columns as $j => $planned) {
                $left = $x + $j * ($columnWidth + HelpMenu::GUTTER); // 0-based column of the box's left border
                foreach ($planned as $box) {
                    ++$boxes;
                    $top = $y + 7 + $box['row']; // 0-based: grid starts one row under the header
                    $bottom = $top + count($box['lines']) + 1;
                    $this->assertSame('╭', mb_substr($lines[$top], $left, 1), "page $page box {$box['heading']} opens at its column");
                    $this->assertSame('╮', mb_substr($lines[$top], $left + $columnWidth - 1, 1), "page $page box {$box['heading']} opens its right corner");
                    $this->assertSame('╰', mb_substr($lines[$bottom], $left, 1), "page $page box {$box['heading']} closes at its column");
                    $this->assertSame('╯', mb_substr($lines[$bottom], $left + $columnWidth - 1, 1), "page $page box {$box['heading']} closes its right corner");
                    for ($row = $top + 1; $row < $bottom; ++$row) {
                        $this->assertSame('│', mb_substr($lines[$row], $left, 1), "page $page box {$box['heading']} side (row $row)");
                        $this->assertSame('│', mb_substr($lines[$row], $left + $columnWidth - 1, 1), "page $page box {$box['heading']} far side (row $row)");
                    }
                }
            }
            $this->assertSame(1 + $boxes, substr_count(implode("\n", $lines), '╭'), "page $page paints exactly the planned frames plus the outer box");
            $menu = $menu->update(new KeyMsg(KeyType::Down), self::ctx(120, 40))->overlay;
        }
    }

    /** (c2) the planner never emits a box taller than the column it flows into. */
    public function testNoBoxEverExceedsItsColumn(): void
    {
        foreach ([78, 98, 118, 166] as $width) {
            for ($per = 3; $per <= 40; ++$per) {
                foreach (HelpMenu::plan($width, $per) as $page) {
                    foreach ($page as $boxes) {
                        foreach ($boxes as $box) {
                            $this->assertLessThanOrEqual(
                                $per,
                                count($box['lines']) + 2,
                                "width $width per $per: {$box['heading']} segment {$box['segment']} overflows its column",
                            );
                        }
                    }
                }
            }
        }
    }

    /**
     * (d) the widened cap answers the ask on the planner itself: at a 168+
     * column terminal (166 box) NO description wraps; at 120 columns the
     * 118-cell box still beats the old three-column 117-cap grid (167
     * content lines). If help.* text drifts, these counts move and fail
     * here with the numbers named.
     */
    public function testFramedDescriptionsWrapLessThanTheOldGrid(): void
    {
        $roster = count(KeyTable::rows());
        $content = static function (int $width): int {
            $lines = 0;
            foreach (HelpMenu::plan($width, 1000) as $page) {
                foreach ($page as $boxes) {
                    foreach ($boxes as $box) {
                        $lines += count($box['lines']);
                    }
                }
            }

            return $lines;
        };

        $this->assertSame(166, HelpMenu::widthFor(170), 'the 166 ceiling: a 170-column terminal saturates it');
        $this->assertSame($roster, $content(HelpMenu::widthFor(170)), 'zero wrap continuations in the 59-cell description column at the 166 cap');

        $at120 = $content(HelpMenu::widthFor(120));
        $this->assertSame(99, $at120, 'the 120-column box holds the roster in 99 content lines (39 continuations)');
        $this->assertLessThan(167, $at120, 'strictly fewer lines than the old three-column 117-cap grid (167)');
    }

    /** The header row keeps labelling the two halves of every box row. */
    public function testGlobalHeaderRowSurvivesAboveTheFrames(): void
    {
        $surface = Surface::new(120, 40);
        HelpMenu::new()->paint($surface, self::ctx(120, 40));
        [, $y] = HelpMenu::geometry(120, 40);
        $header = $surface->plainLines()[$y + 6]; // 0-based row y+7: the header between the border and the first grid row
        $this->assertStringContainsString(Lang::t('menu.help.key'), $header);
        $this->assertStringContainsString(Lang::t('menu.help.description'), $header);
        $this->assertLessThan(mb_strpos($header, Lang::t('menu.help.description')), mb_strpos($header, '┐'), 'the frames start below the header row');
    }

    /**
     * (e) maximality — the precise invariant, stated exactly as strong as it
     * is: the greedy turns the page only when neither column takes the box,
     * so on every non-last page the FIRST box of the next page fails the
     * column-fit predicate (used height, plus the one-row spacer whenever
     * the column already stacks a box, plus box height beats the budget)
     * for BOTH columns. Later boxes of that next page are NOT covered — a
     * smaller one could have fitted a residual hole, and the pass keeps
     * reading order instead of reaching past the box in front of it. Swept
     * over every real box width, the four live per-column budgets, and the
     * degenerate 1-row column where boxes overflow the head of each page.
     */
    public function testEveryNonLastPageEndsUnfillable(): void
    {
        $boxHeight = static fn (array $box): int => count($box['lines']) + 2;
        foreach ([78, 118, 148, 166] as $width) {
            foreach ([1, 3, 15, 31, 41, 60] as $perColumn) {
                $plan = HelpMenu::plan($width, $perColumn);
                for ($page = 0; $page < count($plan) - 1; ++$page) {
                    $used = array_map(
                        static function (array $boxes) use ($boxHeight): int {
                            return $boxes === [] ? 0 : $boxes[count($boxes) - 1]['row'] + $boxHeight($boxes[count($boxes) - 1]);
                        },
                        $plan[$page],
                    );
                    $this->assertNotSame([], $plan[$page + 1][0], "width $width per $perColumn page $page: a page turn always starts a fresh left column");
                    $next = $boxHeight($plan[$page + 1][0][0]);
                    foreach ($used as $c => $height) {
                        $this->assertGreaterThan(
                            $perColumn,
                            $height + ($height > 0 ? 1 : 0) + $next,
                            "width $width per $perColumn: page $page column $c could still take the box that opens page " . ($page + 1),
                        );
                    }
                }
            }
        }
    }

    /**
     * (f) the measured shape of the wrapped 120x40 grid. At width 118 the
     * descriptions wrap and Panels & views costs two 17-row segments, so
     * page 1 is General + both Panels segments + Navigation (with the
     * continuation riding under General at row 8 — the spacer's row 7 —
     * proof the greedy revisits the shorter stack), page 2 is Process list,
     * Search & filter and Net & io, page 3 is Process actions, Overlays,
     * Mouse and Info. Every page boundary is maximal by test (e).
     */
    public function testMeasuredPageMembershipAt120x40(): void
    {
        [$x, $y, $height] = HelpMenu::geometry(120, 40);
        $plan = HelpMenu::plan(HelpMenu::widthFor(120), $height - 3);
        $this->assertCount(3, $plan, 'the greedy still spends exactly three pages at 120x40');

        $this->assertSame([
            [Lang::t('help.section.general') . '@0', Lang::t('help.section.panels') . '@8'],
            [Lang::t('help.section.panels') . '@0', Lang::t('help.section.navigation') . '@18'],
        ], self::columnShape($plan[0]));
        $this->assertSame([
            [Lang::t('help.section.process_list') . '@0'],
            [Lang::t('help.section.filter') . '@0', Lang::t('help.section.net') . '@8'],
        ], self::columnShape($plan[1]));
        $this->assertSame([
            [Lang::t('help.section.actions') . '@0'],
            [Lang::t('help.section.overlays') . '@0', Lang::t('help.section.mouse') . '@5', Lang::t('help.section.info') . '@12'],
        ], self::columnShape($plan[2]));
    }

    /**
     * (g) the zero-wrap width at a 40-row terminal spends its whole page-1
     * budget — six sections — and the SHAPE is the greedy's own: the 22-row
     * Panels box rides the right column while General's stack continues
     * three deep under it. Honest scope: the base flush next-fit also held
     * six sections on this page (General + Panels left, all four small
     * boxes right), so the six is a property of the 166-wide zero-wrap
     * budget, NOT of the packing rule — first-fit drops Panels into the
     * hole under General just as the base did, and only the column split
     * pinned below tells that from the greedy's placement. The count win
     * over next-fit lives where the budget is short: measured and pinned
     * in test (h).
     */
    public function testGreedyFillsPageOneOnWideTerminals(): void
    {
        [$x, $y, $height, $pages] = HelpMenu::geometry(170, 40);
        $this->assertSame(2, $pages, 'two pages at the zero-wrap width');
        $plan = HelpMenu::plan(HelpMenu::widthFor(170), $height - 3);
        $first = [];
        foreach ($plan[0] as $boxes) {
            foreach ($boxes as $box) {
                $first[$box['heading']] = true;
            }
        }
        foreach (['help.section.general', 'help.section.panels', 'help.section.navigation', 'help.section.process_list', 'help.section.filter'] as $heading) {
            $this->assertArrayHasKey(Lang::t($heading), $first, Lang::t($heading) . ' belongs to page 1 of the wide grid');
        }
        $this->assertCount(6, $first, 'six sections share page 1 at the zero-wrap width');
        $this->assertSame([
            [
                Lang::t('help.section.general') . '@0',
                Lang::t('help.section.navigation') . '@7',
                Lang::t('help.section.process_list') . '@15',
                Lang::t('help.section.filter') . '@22',
            ],
            [Lang::t('help.section.panels') . '@0', Lang::t('help.section.net') . '@23'],
        ], self::columnShape($plan[0]), 'Panels rides the right column; the small boxes fill the hole under General');
    }

    /**
     * (h) the measured win over the old rule, in the short-terminal band of
     * the owner's screenshot: a 166-wide (zero-wrap) box on a 32-row
     * terminal answers a 23-row column, where the 22-row Panels box no
     * longer stacks under General. The base column-advancing next-fit
     * leaves page 1 with 2 distinct sections (General stranded above a
     * 17-row hole, Panels alone on the right) and spends 3 pages; the
     * greedy revisits the short stack — page 1 holds 4 distinct sections
     * (General, Navigation, Process list down the left, Panels right) in 2
     * pages. Both numbers come from the probe table in this campaign's
     * evidence report; the page count is pinned against the disclosed base
     * constant 3.
     */
    public function testGreedyRescuesTheShortZeroWrapBand(): void
    {
        [$x, $y, $height, $pages] = HelpMenu::geometry(170, 32);
        $this->assertSame(23, $height - 3, 'a 32-row terminal answers a 23-row column');
        $this->assertSame(2, $pages, 'two pages where the base next-fit spent three');
        $plan = HelpMenu::plan(HelpMenu::widthFor(170), $height - 3);
        $this->assertCount(2, $plan, 'the plan itself turns exactly two pages');

        $first = [];
        foreach ($plan[0] as $boxes) {
            foreach ($boxes as $box) {
                $first[$box['heading']] = true;
            }
        }
        foreach (['help.section.general', 'help.section.panels', 'help.section.navigation', 'help.section.process_list'] as $heading) {
            $this->assertArrayHasKey(Lang::t($heading), $first, Lang::t($heading) . ' belongs to page 1 of the short zero-wrap grid');
        }
        $this->assertCount(4, $first, 'four distinct sections share page 1 where the next-fit held two');
        $this->assertSame([
            [
                Lang::t('help.section.general') . '@0',
                Lang::t('help.section.navigation') . '@7',
                Lang::t('help.section.process_list') . '@15',
            ],
            [Lang::t('help.section.panels') . '@0'],
        ], self::columnShape($plan[0]));

        $second = [];
        foreach ($plan[1] as $boxes) {
            foreach ($boxes as $box) {
                $second[$box['heading']] = true;
            }
        }
        $this->assertCount(6, $second, 'the other six roster sections share page 2 of the two-page grid');
    }

    /** The plan's page as `heading@row` per column — the shape (f), (g) and (h) assert against. */
    private static function columnShape(array $page): array
    {
        return array_map(
            static fn (array $boxes): array => array_map(
                static fn (array $box): string => $box['heading'] . '@' . $box['row'],
                $boxes,
            ),
            $page,
        );
    }
}
