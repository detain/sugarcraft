<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Tabs;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Mouse\Zones\Manager;
use SugarCraft\Style\Style;
use SugarCraft\Widgets\Tabs\ScrollWindow;
use SugarCraft\Widgets\Tabs\Tabs;

/**
 * P2-2-chrome2: the divider before the right scroll mark. The default
 * frames are pinned here byte for byte, so the option that drops that
 * divider cannot move a frame that does not ask for it; with the option
 * off the draw, spans(), the marked clip and the scroll window all leave
 * that divider out, and a clipped line keeps no empty SGR pairs before
 * the mark.
 */
final class TabsRightSeparatorTest extends TestCase
{
    private const LABELS = ['Home', 'Profile', 'Settings'];

    private const SWEEP_LABELS = ['Home', 'Profile', 'Settings', 'Ab', 'Wide label'];

    /**
     * sha256 of {@see sweep()} over {@see defaultConfigs()}, recorded on the
     * frames before the right-mark separator option existed.
     */
    private const DEFAULT_SWEEP_SHA256 = '7a83d9050a39d0dd2ce4aaebc9501c096e3e6a2a2867991059a4ad099835f01f';

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

    private static function stripSgr(string $frame): string
    {
        return (string) preg_replace('/\e\[[0-9;]*m/', '', $frame);
    }

    /** The bar with its current marks, style and left separator, the right separator set to `$on`. */
    private static function withRightSeparator(Tabs $tabs, bool $on): Tabs
    {
        return $tabs->withScrollMarks(
            $tabs->leftMark,
            $tabs->rightMark,
            $tabs->markStyle,
            $tabs->separatorAfterLeft,
            $on,
        );
    }

    /** Whether `$frame` holds an SGR open (or several) followed at once by a reset. */
    private static function hasEmptySgrPair(string $frame): bool
    {
        return preg_match('/(?:\e\[(?!0?m)[0-9;]*m)+\e\[0?m/', $frame) === 1;
    }

    /**
     * Configurations that never touch the right-mark separator, each a
     * closure over a fresh bar.
     *
     * @return array<string, \Closure(Tabs): Tabs>
     */
    private static function defaultConfigs(): array
    {
        return [
            'plain' => static fn(Tabs $t): Tabs => $t,
            'styled' => static fn(Tabs $t): Tabs => $t
                ->withInactiveStyle(Style::new()->italic())
                ->withDividerStyle(Style::new()->underline())
                ->withScrollMarks('‹', '›', Style::new()->bold()),
            'wide marks no left sep' => static fn(Tabs $t): Tabs => $t
                ->withScrollMarks('<<', '>>', null, false),
            'badged closable' => static fn(Tabs $t): Tabs => $t
                ->withBadge(0, '3')
                ->withBadgeStyle(Style::new()->underline())
                ->withClosable(),
            'first keeping active' => static fn(Tabs $t): Tabs => $t
                ->withScrollWindow(ScrollWindow::FirstKeepingActive)
                ->withScrollMarks('‹', '›', Style::new()->bold()),
        ];
    }

    /**
     * Every frame, marked frame, span list and window of each configuration
     * over widths 0 to 40 and every active tab, as one string.
     *
     * @param array<string, \Closure(Tabs): Tabs> $configs
     * @param \Closure(Tabs): Tabs $extra applied after each configuration
     */
    private static function sweep(array $configs, \Closure $extra): string
    {
        $out = '';
        foreach ($configs as $name => $configure) {
            for ($width = 0; $width <= 40; $width++) {
                for ($active = 0; $active < count(self::SWEEP_LABELS); $active++) {
                    $tabs = $extra($configure(Tabs::new(self::SWEEP_LABELS, $width)))->withActive($active);
                    $out .= "{$name}|{$width}|{$active}|"
                        . $tabs->view() . '|'
                        . self::scannedMarkedView($tabs) . '|'
                        . json_encode(self::spanTriples($tabs)) . '|'
                        . $tabs->scrollOffset . '-' . $tabs->scrollEnd . "\n";
                }
            }
        }
        return $out;
    }

    // ── (c) the default path, pinned before the option ───────────────────────

    public function testDefaultClippedFramesArePinned(): void
    {
        $styled = static fn(int $width): Tabs => Tabs::new(self::LABELS, $width)
            ->withInactiveStyle(Style::new()->italic())
            ->withScrollMarks('‹', '›', Style::new()->bold());

        $this->assertSame("… │ \e[1m Profile \e[0m…", Tabs::new(self::LABELS, 14)->withActive(1)->view());
        $this->assertSame("\e[1m Home \e[0m │…", Tabs::new(self::LABELS, 9)->view());
        $this->assertSame("\e[1m Hom\e[0m…", Tabs::new(self::LABELS, 5)->view());
        // The default clip keeps the cut parts' empty SGR pairs.
        $this->assertSame(
            "\e[1m Hom\e[0m\e[3m\e[0m\e[1m\e[0m\e[1m›\e[0m",
            $styled(5)->view(),
        );
        $this->assertSame(
            "\e[1m‹\e[0m\e[3m │ \e[0m\e[1m P\e[0m\e[3m\e[0m\e[1m\e[0m\e[1m›\e[0m",
            $styled(7)->withActive(1)->view(),
        );
    }

    public function testDefaultSweepIsPinned(): void
    {
        $sweep = self::sweep(self::defaultConfigs(), static fn(Tabs $t): Tabs => $t);

        $this->assertSame(self::DEFAULT_SWEEP_SHA256, hash('sha256', $sweep));
    }

    public function testExplicitSeparatorBeforeRightOnIsTheDefaultSweep(): void
    {
        $sweep = self::sweep(
            self::defaultConfigs(),
            static fn(Tabs $t): Tabs => self::withRightSeparator($t, true),
        );

        $this->assertSame(self::DEFAULT_SWEEP_SHA256, hash('sha256', $sweep));
    }

    public function testSeparatorBeforeRightDefaultsOn(): void
    {
        $this->assertTrue(Tabs::new(self::LABELS)->separatorBeforeRight);
        $this->assertTrue(Tabs::new(self::LABELS)->withScrollMarks('‹', '›')->separatorBeforeRight);
    }

    // ── (a) the right mark joined to the last visible tab ────────────────────

    public function testSeparatorBeforeRightOffJoinsTheRightMarkToTheLastTab(): void
    {
        $joined = Tabs::new(self::LABELS, 19)->withScrollMarks('‹', '›', null, true, false);

        $this->assertFalse($joined->separatorBeforeRight);
        $this->assertSame("\e[1m Home \e[0m │  Profile ›", $joined->view());
        $this->assertSame([[0, 0, 6], [1, 9, 18]], self::spanTriples($joined));
    }

    public function testSeparatorBeforeRightOffAfterALeftMark(): void
    {
        $joined = Tabs::new(self::LABELS, 14)->withScrollMarks('‹', '›', null, true, false)->withActive(1);

        $this->assertSame("‹ │ \e[1m Profile \e[0m›", $joined->view());
        $this->assertSame([[1, 4, 13]], self::spanTriples($joined));
    }

    public function testBothSeparatorsOff(): void
    {
        $joined = Tabs::new(self::LABELS, 14)->withScrollMarks('‹', '›', null, false, false)->withActive(1);

        $this->assertSame("‹\e[1m Profile \e[0m›", $joined->view());
        $this->assertSame([[1, 1, 10]], self::spanTriples($joined));
    }

    public function testSeparatorBeforeRightOffKeepsTheDividersBetweenTabs(): void
    {
        $tabs = Tabs::new(self::LABELS)->withActive(1);
        $joined = $tabs->withScrollMarks('‹', '›', null, true, false);

        $this->assertSame($tabs->view(), $joined->view());
        $this->assertSame(self::spanTriples($tabs), self::spanTriples($joined));
    }

    public function testSeparatorBeforeRightOffOnAMarkedBar(): void
    {
        $joined = Tabs::new(self::LABELS, 19)
            ->withInactiveStyle(Style::new()->italic())
            ->withScrollMarks('‹', '›', Style::new()->bold(), true, false);

        $this->assertSame($joined->view(), self::scannedMarkedView($joined));
        $this->assertStringEndsWith("\e[3m Profile \e[0m\e[1m›\e[0m", $joined->view());
    }

    public function testSeparatorBeforeRightOffLeavesTheWindowAsPriced(): void
    {
        // The window prices no divider before the right mark under either
        // setting: the default clips it away, the joined bar never draws it.
        foreach (self::defaultConfigs() as $name => $configure) {
            for ($width = 1; $width <= 40; $width++) {
                for ($active = 0; $active < count(self::SWEEP_LABELS); $active++) {
                    $default = $configure(Tabs::new(self::SWEEP_LABELS, $width))->withActive($active);
                    $joined = self::withRightSeparator($configure(Tabs::new(self::SWEEP_LABELS, $width)), false)
                        ->withActive($active);
                    $where = "{$name}, width {$width}, active {$active}";

                    $this->assertSame($default->scrollOffset, $joined->scrollOffset, $where);
                    $this->assertSame($default->scrollEnd, $joined->scrollEnd, $where);
                }
            }
        }
    }

    public function testSeparatorBeforeRightOffKeepsFrameSpansAndMarkupInAgreement(): void
    {
        $count = count(self::SWEEP_LABELS);
        foreach (self::defaultConfigs() as $name => $configure) {
            for ($width = 1; $width <= 40; $width++) {
                for ($active = 0; $active < $count; $active++) {
                    $tabs = self::withRightSeparator($configure(Tabs::new(self::SWEEP_LABELS, $width)), false)
                        ->withActive($active);
                    $where = "{$name}, width {$width}, active {$active}";
                    $view = $tabs->view();
                    $frame = self::stripSgr($view);
                    $mark = $tabs->rightMark;

                    $this->assertLessThanOrEqual($width, Width::string($frame), $where);
                    $this->assertSame($view, self::scannedMarkedView($tabs), $where);
                    if ($tabs->scrollEnd >= $count - 1 || Width::string($mark) >= $width) {
                        continue;
                    }
                    // The right mark is drawn: the last span ends on the cell
                    // before it, with no divider cells between.
                    $this->assertStringEndsWith($mark, $frame, $where);
                    $this->assertStringEndsNotWith('│' . $mark, $frame, $where);
                    $this->assertStringEndsNotWith('│ ' . $mark, $frame, $where);
                    $spans = $tabs->spans();
                    if ($spans !== []) {
                        $last = $spans[count($spans) - 1];
                        $this->assertSame(Width::string($frame) - Width::string($mark), $last->end, $where);
                    }
                }
            }
        }
    }

    // ── (b) a clipped line keeps no empty pairs before the right mark ────────

    public function testClippedJoinedLineDropsTheEmptySgrPairs(): void
    {
        $styled = static fn(int $width): Tabs => Tabs::new(self::LABELS, $width)
            ->withInactiveStyle(Style::new()->italic())
            ->withScrollMarks('‹', '›', Style::new()->bold(), true, false);

        $this->assertSame("\e[1m Hom\e[0m\e[1m›\e[0m", $styled(5)->view());
        $this->assertSame(
            "\e[1m‹\e[0m\e[3m │ \e[0m\e[1m P\e[0m\e[1m›\e[0m",
            $styled(7)->withActive(1)->view(),
        );
        $this->assertSame(
            "\e[1m Hom\e[0m…",
            Tabs::new(self::LABELS, 5)->withScrollMarks('…', '…', null, true, false)->view(),
        );
    }

    public function testClippedJoinedLineDropsAClippedBadgePair(): void
    {
        $tabs = Tabs::new(self::LABELS, 5)
            ->withBadge(0, '3')
            ->withBadgeStyle(Style::new()->underline())
            ->withClosable()
            ->withScrollMarks('‹', '›', Style::new()->bold(), true, false);

        $this->assertSame("\e[1m Hom\e[0m\e[1m›\e[0m", $tabs->view());
        $this->assertSame($tabs->view(), self::scannedMarkedView($tabs));
    }

    public function testJoinedFramesHoldNoEmptySgrPairs(): void
    {
        foreach (self::defaultConfigs() as $name => $configure) {
            for ($width = 1; $width <= 40; $width++) {
                for ($active = 0; $active < count(self::SWEEP_LABELS); $active++) {
                    $tabs = self::withRightSeparator($configure(Tabs::new(self::SWEEP_LABELS, $width)), false)
                        ->withActive($active);
                    $where = "{$name}, width {$width}, active {$active}";

                    $this->assertFalse(self::hasEmptySgrPair($tabs->view()), $where);
                    $this->assertFalse(self::hasEmptySgrPair(self::scannedMarkedView($tabs)), $where);
                }
            }
        }
    }

    public function testTheDefaultClipStillKeepsItsEmptySgrPairs(): void
    {
        $tabs = Tabs::new(self::LABELS, 5)
            ->withInactiveStyle(Style::new()->italic())
            ->withScrollMarks('‹', '›', Style::new()->bold(), true, true);

        $this->assertTrue(self::hasEmptySgrPair($tabs->view()));
    }

    // ── (d) guards, immutability and behavior ────────────────────────────────

    public function testWidthlessMarksStillThrowWithTheRightSeparatorOff(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tab scroll mark must not be empty');
        Tabs::new(self::LABELS)->withScrollMarks('‹', '', null, true, false);
    }

    public function testRightSeparatorSetterIsImmutable(): void
    {
        $tabs = Tabs::new(self::LABELS, 19);
        $before = $tabs->view();
        $joined = $tabs->withScrollMarks('‹', '›', null, true, false);

        $this->assertNotSame($tabs, $joined);
        $this->assertTrue($tabs->separatorBeforeRight);
        $this->assertSame($before, $tabs->view());
    }

    public function testRightSeparatorSurvivesOtherSetters(): void
    {
        $tabs = Tabs::new(self::LABELS, 19)
            ->withScrollMarks('‹', '›', null, true, false)
            ->withDividerStyle(Style::new()->bold())
            ->withScrollWindow(ScrollWindow::FirstKeepingActive)
            ->withLabels(['A', 'B', 'C', 'D'])
            ->withWidth(12)
            ->withActive(2)
            ->focus();

        $this->assertFalse($tabs->separatorBeforeRight);
        $this->assertTrue($tabs->separatorAfterLeft);
    }

    public function testKeyboardNavigationIsUnchanged(): void
    {
        $default = Tabs::new(self::SWEEP_LABELS, 20)->focus();
        $joined = $default->withScrollMarks(Tabs::SCROLL_MARK, Tabs::SCROLL_MARK, null, true, false);
        $keys = [
            new KeyMsg(KeyType::Tab),
            new KeyMsg(KeyType::Tab),
            new KeyMsg(KeyType::Tab),
            new KeyMsg(KeyType::Tab, shift: true),
            new KeyMsg(KeyType::Tab),
            new KeyMsg(KeyType::Tab),
            new KeyMsg(KeyType::Tab),
        ];
        foreach ($keys as $press => $key) {
            [$default] = $default->update($key);
            [$joined] = $joined->update($key);

            $this->assertSame($default->active, $joined->active, "press {$press}");
            $this->assertSame($default->scrollOffset, $joined->scrollOffset, "press {$press}");
            $this->assertSame($default->focused, $joined->focused, "press {$press}");
        }
        [$blurred] = $joined->blur()->update(new KeyMsg(KeyType::Tab));
        $this->assertSame($joined->active, $blurred->active);
    }
}
