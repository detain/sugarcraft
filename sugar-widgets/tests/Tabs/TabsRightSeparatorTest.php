<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Tabs;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mouse\Zones\Manager;
use SugarCraft\Style\Style;
use SugarCraft\Widgets\Tabs\ScrollWindow;
use SugarCraft\Widgets\Tabs\Tabs;

/**
 * P2-2-chrome2: the divider before the right scroll mark. The default
 * frames are pinned here byte for byte, so the option that drops that
 * divider cannot move a frame that does not ask for it.
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
}
