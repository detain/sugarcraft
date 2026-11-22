<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Tabs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Mouse\Zones\Manager;
use SugarCraft\Style\Style;
use SugarCraft\Widgets\Tabs\ScrollWindow;
use SugarCraft\Widgets\Tabs\Tabs;

/**
 * P2-2-chrome: the additive chrome options — divider style, scroll-mark
 * glyphs and style, and the scroll-window mode. Each default must keep
 * every frame byte-identical; each option must keep the drawn frame, the
 * spans and the scroll window on the same cells.
 */
final class TabsChromeTest extends TestCase
{
    private const LABELS = ['Home', 'Profile', 'Settings'];

    private const LETTERS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];

    /** A bar with unstyled tabs, so frames compare as plain text. */
    private function plain(array $labels = self::LABELS, int $width = 0): Tabs
    {
        return Tabs::new($labels, $width)->withActiveStyle(Style::new());
    }

    private static function stripSgr(string $frame): string
    {
        return (string) preg_replace('/\e\[[0-9;]*m/', '', $frame);
    }

    /** The frame a Manager-marked copy of `$tabs` draws, its zone markup scanned away. */
    private static function scannedMarkedView(Tabs $tabs): string
    {
        $manager = Manager::newPrefix('z');
        return $manager->scan($tabs->withZoneManager($manager)->view());
    }

    /** @return list<array{int, int, int}> */
    private static function spanTriples(Tabs $tabs): array
    {
        return array_map(static fn($s): array => [$s->index, $s->start, $s->end], $tabs->spans());
    }

    // ── (a) divider style ────────────────────────────────────────────────────

    public function testDefaultDividerIsUnchanged(): void
    {
        $tabs = Tabs::new(self::LABELS)->withActive(1);

        $this->assertNull($tabs->dividerStyle);
        $this->assertSame(" Home  │ \e[1m Profile \e[0m │  Settings ", $tabs->view());
        $this->assertSame($tabs->view(), $tabs->withDividerStyle(null)->view());
    }

    public function testDefaultDividerFollowsTheInactiveStyle(): void
    {
        $tabs = $this->plain()->withInactiveStyle(Style::new()->italic());

        $this->assertSame(
            " Home \e[3m │ \e[0m\e[3m Profile \e[0m\e[3m │ \e[0m\e[3m Settings \e[0m",
            $tabs->view(),
        );
    }

    public function testStyledDividerIsDrawnInItsStyle(): void
    {
        $tabs = $this->plain()->withDividerStyle(Style::new()->bold());

        $this->assertSame(" Home \e[1m │ \e[0m Profile \e[1m │ \e[0m Settings ", $tabs->view());
    }

    public function testDividerStyleOverridesTheInactiveStyleForTheDividerOnly(): void
    {
        $tabs = $this->plain()
            ->withInactiveStyle(Style::new()->italic())
            ->withDividerStyle(Style::new()->bold());

        $this->assertSame(
            " Home \e[1m │ \e[0m\e[3m Profile \e[0m\e[1m │ \e[0m\e[3m Settings \e[0m",
            $tabs->view(),
        );
    }

    public function testNullDividerStyleRestoresTheDefaultDivider(): void
    {
        $default = Tabs::new(self::LABELS)->withActive(1);
        $reset = $default->withDividerStyle(Style::new()->bold())->withDividerStyle(null);

        $this->assertNull($reset->dividerStyle);
        $this->assertSame($default->view(), $reset->view());
    }

    public function testStyledDividerOnAMarkedBar(): void
    {
        $tabs = $this->plain()->withDividerStyle(Style::new()->bold());

        $this->assertSame($tabs->view(), self::scannedMarkedView($tabs));
    }

    public function testClippedDividerTailKeepsTheDividerStyle(): void
    {
        $tabs = Tabs::new(self::LABELS, 9)->withDividerStyle(Style::new()->underline());
        $expected = "\e[1m Home \e[0m\e[4m │\e[0m…";

        $this->assertSame($expected, $tabs->view());
        $this->assertSame($expected, self::scannedMarkedView($tabs));
    }

    public function testDividerStyleDoesNotMoveSpans(): void
    {
        $tabs = Tabs::new(self::LABELS, 14)->withActive(1);

        $this->assertSame(
            self::spanTriples($tabs),
            self::spanTriples($tabs->withDividerStyle(Style::new()->bold())),
        );
    }

    // ── (b) scroll marks ─────────────────────────────────────────────────────

    public function testDefaultMarksAreUnchanged(): void
    {
        $tabs = Tabs::new(self::LABELS, 14)->withActive(1);

        $this->assertSame('…', Tabs::SCROLL_MARK);
        $this->assertSame(Tabs::SCROLL_MARK, $tabs->leftMark);
        $this->assertSame(Tabs::SCROLL_MARK, $tabs->rightMark);
        $this->assertNull($tabs->markStyle);
        $this->assertTrue($tabs->separatorAfterLeft);
        $this->assertSame("… │ \e[1m Profile \e[0m…", $tabs->view());
        $this->assertSame("… │ \e[1m Settings \e[0m", Tabs::new(self::LABELS, 15)->withActive(2)->view());
        $this->assertSame([[1, 4, 13]], self::spanTriples($tabs));
    }

    public function testExplicitDefaultMarksAreByteIdentical(): void
    {
        for ($width = 1; $width <= 40; $width++) {
            for ($active = 0; $active < count(self::LABELS); $active++) {
                $default = Tabs::new(self::LABELS, $width)->withActive($active);
                $explicit = Tabs::new(self::LABELS, $width)
                    ->withScrollMarks(Tabs::SCROLL_MARK, Tabs::SCROLL_MARK)
                    ->withActive($active);

                $this->assertSame($default->view(), $explicit->view(), "width {$width}, active {$active}");
                $this->assertSame(self::spanTriples($default), self::spanTriples($explicit));
                $this->assertSame($default->scrollOffset, $explicit->scrollOffset);
            }
        }
    }

    public function testCustomMarkGlyphs(): void
    {
        $tabs = Tabs::new(self::LABELS, 14)->withScrollMarks('‹', '›')->withActive(1);

        $this->assertSame("‹ │ \e[1m Profile \e[0m›", $tabs->view());
        $this->assertSame([[1, 4, 13]], self::spanTriples($tabs));
    }

    public function testMarkStyleStylesBothMarks(): void
    {
        $view = Tabs::new(self::LABELS, 14)
            ->withScrollMarks('‹', '›', Style::new()->bold())
            ->withActive(1)
            ->view();

        $this->assertStringStartsWith("\e[1m‹\e[0m │ ", $view);
        $this->assertStringEndsWith("\e[1m›\e[0m", $view);
        $this->assertSame('‹ │  Profile ›', self::stripSgr($view));
    }

    public function testMarksAreUnstyledWithoutAMarkStyle(): void
    {
        $view = Tabs::new(self::LABELS, 14)->withScrollMarks('‹', '›')->withActive(1)->view();

        $this->assertStringStartsWith('‹ │ ', $view);
        $this->assertStringEndsWith("\e[0m›", $view);
    }

    public function testSeparatorAfterLeftOffJoinsTheFirstTabToTheMark(): void
    {
        $tabs = Tabs::new(self::LABELS, 14)->withScrollMarks('‹', '›', null, false)->withActive(1);

        $this->assertFalse($tabs->separatorAfterLeft);
        $this->assertSame("‹\e[1m Profile \e[0m │ ›", $tabs->view());
        $this->assertSame([[1, 1, 10]], self::spanTriples($tabs));
    }

    public function testSeparatorAfterLeftOffOnAMarkedBar(): void
    {
        $tabs = Tabs::new(self::LABELS, 14)->withScrollMarks('‹', '›', null, false)->withActive(1);

        $this->assertSame($tabs->view(), self::scannedMarkedView($tabs));
    }

    public function testWideMarksArePricedAtTheirWidth(): void
    {
        $tabs = Tabs::new(self::LABELS, 15)->withScrollMarks('<<', '>>')->withActive(2);

        $this->assertSame("<< │ \e[1m Settings \e[0m", $tabs->view());
        $this->assertSame([[2, 5, 15]], self::spanTriples($tabs));
    }

    public function testARightMarkWiderThanTheBarIsCutToTheBar(): void
    {
        $labels = ['Home', 'Profile', 'Settings', 'Inbox'];
        for ($width = 1; $width <= 8; $width++) {
            $tabs = Tabs::new($labels, $width)->withScrollMarks('[more]', '[more]');
            $where = "width {$width}";

            $this->assertLessThanOrEqual($width, Width::string(self::stripSgr($tabs->view())), $where);
            $this->assertLessThanOrEqual(
                $width,
                Width::string(self::stripSgr(self::scannedMarkedView($tabs))),
                $where,
            );
        }
        $this->assertSame('[more', Tabs::new($labels, 5)->withScrollMarks('[more]', '[more]')->view());
    }

    public function testChangingMarksKeepsTheActiveTabInView(): void
    {
        // At width 19 the default marks leave the window at offset 0 with
        // Profile drawn; two-cell marks squeeze it out unless the window
        // scrolls when the marks change.
        $tabs = Tabs::new(self::LABELS, 19)->withActive(1)->withScrollMarks('<<', '>>');

        $this->assertSame(1, $tabs->scrollOffset);
        $this->assertSame("<< │ \e[1m Profile \e[0m │ >>", $tabs->view());
    }

    public function testMarksAreSanitized(): void
    {
        $tabs = Tabs::new(self::LABELS)->withScrollMarks("\e‹", "›\e");

        $this->assertSame('‹', $tabs->leftMark);
        $this->assertSame('›', $tabs->rightMark);
    }

    /** @return array<string, array{string, string, ?Style, bool}> */
    public static function markConfigs(): array
    {
        return [
            'narrow'          => ['‹', '›', null, true],
            'narrow no sep'   => ['‹', '›', null, false],
            'wide styled'     => ['<<', '>>', Style::new()->bold(), true],
            'wide no sep'     => ['<<', '>>', null, false],
            'mixed widths'    => ['[ ', ' ]', null, false],
        ];
    }

    #[DataProvider('markConfigs')]
    public function testCustomMarksKeepFrameSpansAndWindowInAgreement(
        string $left,
        string $right,
        ?Style $style,
        bool $separator,
    ): void {
        $labels = ['Home', 'Profile', 'Settings', 'Ab', 'Wide label'];
        foreach ([ScrollWindow::Offset, ScrollWindow::FirstKeepingActive] as $mode) {
            for ($width = 8; $width <= 60; $width++) {
                for ($active = 0; $active < count($labels); $active++) {
                    $tabs = Tabs::new($labels, $width)
                        ->withScrollWindow($mode)
                        ->withScrollMarks($left, $right, $style, $separator)
                        ->withActive($active);
                    $where = "{$mode->name}, width {$width}, active {$active}";
                    $frame = self::stripSgr($tabs->view());

                    $this->assertLessThanOrEqual($width, Width::string($frame), $where);
                    $this->assertSame($frame, self::stripSgr(self::scannedMarkedView($tabs)), $where);
                    if ($width >= 16) {
                        $this->assertContains($active, array_column(self::spanTriples($tabs), 0), $where);
                    }
                    foreach ($tabs->spans() as $span) {
                        $cells = mb_substr($frame, $span->start, $span->end - $span->start);
                        $label = ' ' . $labels[$span->index] . ' ';
                        $this->assertStringStartsWith($cells, $label, $where);
                    }
                }
            }
        }
    }

    // ── (c) scroll window ────────────────────────────────────────────────────

    public function testOffsetIsTheDefaultMode(): void
    {
        $this->assertSame(ScrollWindow::Offset, Tabs::new(self::LETTERS, 16)->scrollWindow);
    }

    public function testOffsetModeIsUnchanged(): void
    {
        $default = Tabs::new(self::LETTERS, 16);
        $explicit = Tabs::new(self::LETTERS, 16)->withScrollWindow(ScrollWindow::Offset);
        foreach ([7, 2, 5, 0, 3] as $active) {
            $default = $default->withActive($active);
            $explicit = $explicit->withActive($active);
            $this->assertSame($default->view(), $explicit->view());
            $this->assertSame($default->scrollOffset, $explicit->scrollOffset);
        }
    }

    public function testOffsetModeLeftAlignsABackwardJump(): void
    {
        $tabs = Tabs::new(self::LETTERS, 16)->withActive(7)->withActive(2);

        $this->assertSame(2, $tabs->scrollOffset);
        $this->assertSame("… │ \e[1m C \e[0m │  D  │…", $tabs->view());
    }

    public function testFirstKeepingActiveShowsTheFirstRunHoldingTheActiveTab(): void
    {
        $tabs = Tabs::new(self::LETTERS, 16)
            ->withScrollWindow(ScrollWindow::FirstKeepingActive)
            ->withActive(7)
            ->withActive(2);

        $this->assertSame(0, $tabs->scrollOffset);
        $this->assertSame(" A  │  B  │ \e[1m C \e[0m…", $tabs->view());
    }

    public function testFirstKeepingActiveOffsetPerActiveTab(): void
    {
        $tabs = Tabs::new(self::LETTERS, 16)->withScrollWindow(ScrollWindow::FirstKeepingActive);
        $offsets = [];
        foreach (array_keys(self::LETTERS) as $active) {
            $at = $tabs->withActive($active);
            $offsets[] = $at->scrollOffset;
            $this->assertContains($active, array_column(self::spanTriples($at), 0));
        }

        $this->assertSame([0, 0, 0, 2, 3, 4, 5, 6], $offsets);
    }

    public function testFirstKeepingActiveDoesNotDependOnHistory(): void
    {
        $fresh = Tabs::new(self::LETTERS, 16)->withScrollWindow(ScrollWindow::FirstKeepingActive);
        $walked = $fresh;
        foreach ([7, 1, 6, 3, 0, 5, 2, 4] as $active) {
            $walked = $walked->withActive($active);
            $this->assertSame($fresh->withActive($active)->view(), $walked->view(), "active {$active}");
        }
    }

    public function testFirstKeepingActiveFollowsKeyboardNavigation(): void
    {
        $fresh = Tabs::new(self::LETTERS, 16)->withScrollWindow(ScrollWindow::FirstKeepingActive)->focus();
        $tabs = $fresh;
        for ($press = 1; $press <= 10; $press++) {
            [$tabs] = $tabs->update(new KeyMsg(KeyType::Tab));
            $this->assertSame($fresh->withActive($tabs->active)->view(), $tabs->view());
        }
        [$tabs] = $tabs->update(new KeyMsg(KeyType::Tab, shift: true));

        $this->assertSame($fresh->withActive($tabs->active)->view(), $tabs->view());
    }

    public function testFirstKeepingActiveIgnoresAPinnedOffset(): void
    {
        $tabs = Tabs::new(self::LETTERS, 16)
            ->withScrollWindow(ScrollWindow::FirstKeepingActive)
            ->withActive(1)
            ->withScrollOffset(5);

        $this->assertSame(0, $tabs->scrollOffset);
    }

    public function testFirstKeepingActiveRecomputesWhenTheWidthChanges(): void
    {
        $tabs = Tabs::new(self::LETTERS, 16)
            ->withScrollWindow(ScrollWindow::FirstKeepingActive)
            ->withActive(4)
            ->withWidth(60);

        $this->assertSame(0, $tabs->scrollOffset);
        $this->assertSame(7, $tabs->scrollEnd);
    }

    public function testFirstKeepingActiveOnAnUnboundedBarDrawsEveryTab(): void
    {
        $offset = Tabs::new(self::LETTERS)->withActive(5);
        $first = Tabs::new(self::LETTERS)->withScrollWindow(ScrollWindow::FirstKeepingActive)->withActive(5);

        $this->assertSame(0, $first->scrollOffset);
        $this->assertSame($offset->view(), $first->view());
    }

    public function testSwitchingBackToOffsetKeepsTheWindow(): void
    {
        $first = Tabs::new(self::LETTERS, 16)->withScrollWindow(ScrollWindow::FirstKeepingActive)->withActive(5);
        $offset = $first->withScrollWindow(ScrollWindow::Offset);

        $this->assertSame(ScrollWindow::Offset, $offset->scrollWindow);
        $this->assertSame($first->view(), $offset->view());
    }

    // ── (d) guards and immutability ──────────────────────────────────────────

    /** @return array<string, array{string, string}> */
    public static function widthlessMarks(): array
    {
        return [
            'empty left'       => ['', '›'],
            'empty right'      => ['‹', ''],
            'escape only'      => ["\e", '›'],
            'zero-width right' => ['‹', "\u{200B}"],
        ];
    }

    #[DataProvider('widthlessMarks')]
    public function testWidthlessMarkThrows(string $left, string $right): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tab scroll mark must not be empty');
        Tabs::new(self::LABELS)->withScrollMarks($left, $right);
    }

    public function testSettersAreImmutable(): void
    {
        $tabs = Tabs::new(self::LABELS, 14)->withActive(1);
        $before = $tabs->view();

        $styled = $tabs->withDividerStyle(Style::new()->bold());
        $marked = $tabs->withScrollMarks('‹', '›', Style::new()->bold(), false);
        $first = $tabs->withScrollWindow(ScrollWindow::FirstKeepingActive);

        $this->assertNotSame($tabs, $styled);
        $this->assertNotSame($tabs, $marked);
        $this->assertNotSame($tabs, $first);
        $this->assertNull($tabs->dividerStyle);
        $this->assertSame(Tabs::SCROLL_MARK, $tabs->leftMark);
        $this->assertNull($tabs->markStyle);
        $this->assertTrue($tabs->separatorAfterLeft);
        $this->assertSame(ScrollWindow::Offset, $tabs->scrollWindow);
        $this->assertSame($before, $tabs->view());
    }

    public function testOptionsSurviveOtherSetters(): void
    {
        $tabs = Tabs::new(self::LABELS, 14)
            ->withDividerStyle(Style::new()->bold())
            ->withScrollMarks('‹', '›', Style::new()->italic(), false)
            ->withScrollWindow(ScrollWindow::FirstKeepingActive)
            ->withLabels(['A', 'B', 'C', 'D'])
            ->withActive(2)
            ->focus();

        $this->assertNotNull($tabs->dividerStyle);
        $this->assertSame('‹', $tabs->leftMark);
        $this->assertSame('›', $tabs->rightMark);
        $this->assertNotNull($tabs->markStyle);
        $this->assertFalse($tabs->separatorAfterLeft);
        $this->assertSame(ScrollWindow::FirstKeepingActive, $tabs->scrollWindow);
    }
}
