<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Tabs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Style;
use SugarCraft\Widgets\Tabs\Tabs;

/**
 * P2-2-pad: the additive label-padding option. The default (1 and 1) must
 * keep every frame byte-identical, and any other padding must keep the
 * drawn text, the spans, tabAt(), clickAt() and the scroll window on the
 * same cells.
 */
final class TabsLabelPaddingTest extends TestCase
{
    private const LABELS = ['Home', 'Profile', 'Settings'];

    /** A bar with unstyled tabs, so frames compare as plain text. */
    private function plain(array $labels = self::LABELS, int $width = 0): Tabs
    {
        return Tabs::new($labels, $width)->withActiveStyle(Style::new());
    }

    // ── default bytes pinned ─────────────────────────────────────────────────

    public function testDefaultFrameBytesArePinned(): void
    {
        $tabs = Tabs::new(self::LABELS)->withActive(1);

        $this->assertSame(" Home  │ \e[1m Profile \e[0m │  Settings ", $tabs->view());
        $this->assertSame(" Home  │ \e[1;4m Profile \e[0m │  Settings ", $tabs->focus()->view());
        $this->assertSame("… │ \e[1m Profile \e[0m…", Tabs::new(self::LABELS, 14)->withActive(1)->view());
    }

    public function testDefaultSpansArePinned(): void
    {
        $spans = Tabs::new(self::LABELS)->withActive(1)->spans();

        $this->assertSame(
            [[0, 0, 6], [1, 9, 18], [2, 21, 31]],
            array_map(static fn($s): array => [$s->index, $s->start, $s->end], $spans),
        );
    }

    public function testDefaultPaddingIsOneAndOne(): void
    {
        $tabs = Tabs::new(self::LABELS);

        $this->assertSame(1, $tabs->paddingLeft);
        $this->assertSame(1, $tabs->paddingRight);
        $this->assertSame($tabs->view(), $tabs->withLabelPadding(1, 1)->view());
    }

    // ── guard ────────────────────────────────────────────────────────────────

    /** @return array<string, array{int, int}> */
    public static function negativePadding(): array
    {
        return [
            'negative left'  => [-1, 0],
            'negative right' => [0, -1],
            'both negative'  => [-2, -3],
        ];
    }

    #[DataProvider('negativePadding')]
    public function testNegativePaddingThrows(int $left, int $right): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tab label padding must be >= 0');
        Tabs::new(self::LABELS)->withLabelPadding($left, $right);
    }

    public function testWithLabelPaddingIsImmutable(): void
    {
        $tabs = Tabs::new(self::LABELS);
        $bare = $tabs->withLabelPadding(0, 0);

        $this->assertNotSame($tabs, $bare);
        $this->assertSame(1, $tabs->paddingLeft);
        $this->assertSame(0, $bare->paddingLeft);
        $this->assertSame(0, $bare->paddingRight);
    }

    public function testPaddingSurvivesLaterSetters(): void
    {
        $tabs = $this->plain()->withLabelPadding(0, 0)->withActive(2)->withLabels(['A', 'B', 'C'])->focus();

        $this->assertSame(0, $tabs->paddingLeft);
        $this->assertSame(0, $tabs->paddingRight);
    }

    // ── zero padding ─────────────────────────────────────────────────────────

    public function testZeroPaddingDrawsBareLabels(): void
    {
        $tabs = Tabs::new(self::LABELS)->withActive(1)->withLabelPadding(0, 0);

        $this->assertSame("Home │ \e[1mProfile\e[0m │ Settings", $tabs->view());
        $this->assertSame('Home │ Profile │ Settings', $this->plain()->withLabelPadding(0, 0)->view());
    }

    public function testZeroPaddingSpansMatchTheBareCells(): void
    {
        $spans = $this->plain()->withLabelPadding(0, 0)->spans();

        $this->assertSame(
            [[0, 0, 4], [1, 7, 14], [2, 17, 25]],
            array_map(static fn($s): array => [$s->index, $s->start, $s->end], $spans),
        );
    }

    public function testZeroPaddingTabAtMatchesTheBareCells(): void
    {
        $tabs = $this->plain()->withLabelPadding(0, 0);
        $frame = 'Home │ Profile │ Settings';
        $owner = [];
        foreach (self::LABELS as $i => $label) {
            $start = mb_strpos($frame, $label);
            for ($c = $start; $c < $start + mb_strlen($label); $c++) {
                $owner[$c] = $i;
            }
        }

        for ($col = -1; $col <= mb_strlen($frame) + 1; $col++) {
            $this->assertSame($owner[$col] ?? null, $tabs->tabAt($col), "column {$col}");
        }
    }

    public function testZeroPaddingClickOnEachTabActivatesThatTab(): void
    {
        $tabs = $this->plain()->withLabelPadding(0, 0);

        foreach ($tabs->spans() as $span) {
            foreach ([$span->start, $span->end - 1] as $col) {
                [$next, $cmd] = $tabs->clickAt($col);
                $this->assertSame($span->index, $next->active(), "column {$col}");
                $this->assertNull($cmd);
            }
        }
    }

    public function testZeroPaddingClickOnADividerChangesNothing(): void
    {
        $tabs = $this->plain()->withLabelPadding(0, 0)->withActive(1);

        foreach ([4, 5, 6, 14, 15, 16] as $col) {
            [$next] = $tabs->clickAt($col);
            $this->assertSame(1, $next->active(), "column {$col}");
        }
    }

    public function testZeroPaddingClosableCloseAffordanceStaysOnItsCells(): void
    {
        $tabs = $this->plain(['Home', 'Inbox'])->withLabelPadding(0, 0)->withClosable();

        $this->assertSame('Home×  │ Inbox× ', $tabs->view());
        $spans = $tabs->spans();
        $this->assertSame(4, $spans[0]->closeStart);
        $this->assertSame(9, $spans[1]->start);
        $this->assertSame(14, $spans[1]->closeStart);

        [$next, $cmd] = $tabs->clickAt(14);
        $this->assertSame(['Home'], $next->labels());
        $this->assertNotNull($cmd);
    }

    // ── any padding: draw, spans and hit-test agree ──────────────────────────

    /** @return array<string, array{int, int}> */
    public static function paddings(): array
    {
        return [
            'default'    => [1, 1],
            'none'       => [0, 0],
            'left only'  => [2, 0],
            'right only' => [0, 3],
        ];
    }

    #[DataProvider('paddings')]
    public function testSpansCoverExactlyThePaddedLabelText(int $left, int $right): void
    {
        $tabs = $this->plain()->withLabelPadding($left, $right);
        $frame = $tabs->view();

        foreach ($tabs->spans() as $span) {
            $expected = str_repeat(' ', $left) . self::LABELS[$span->index] . str_repeat(' ', $right);
            $this->assertSame($expected, mb_substr($frame, $span->start, $span->width()));
            $this->assertSame($span->index, $tabs->tabAt($span->start));
            $this->assertSame($span->index, $tabs->tabAt($span->end - 1));
        }
    }

    #[DataProvider('paddings')]
    public function testScrollWindowKeepsTheActiveTabWholeAtAnyPadding(int $left, int $right): void
    {
        $labels = ['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon'];
        $width = 22;
        $tabs = $this->plain($labels, $width)->withLabelPadding($left, $right)->focus();
        $tabKey = new KeyMsg(KeyType::Tab);

        foreach (array_keys($labels) as $i) {
            $this->assertSame($i, $tabs->active());
            $this->assertLessThanOrEqual($width, Width::string($tabs->view()));
            $active = array_values(array_filter($tabs->spans(), static fn($s): bool => $s->index === $i));
            $this->assertCount(1, $active, "tab {$i} drawn");
            $this->assertSame($left + mb_strlen($labels[$i]) + $right, $active[0]->width(), "tab {$i} whole");
            [$clicked] = $tabs->clickAt($active[0]->start);
            $this->assertSame($i, $clicked->active());
            [$tabs] = $tabs->update($tabKey);
        }
    }

    public function testZeroPaddingFitsMoreTabsInTheWindow(): void
    {
        $labels = ['Alpha', 'Beta', 'Gamma', 'Delta'];

        $this->assertSame(1, $this->plain($labels, 22)->scrollEnd);
        $this->assertSame(2, $this->plain($labels, 22)->withLabelPadding(0, 0)->scrollEnd);
    }
}
