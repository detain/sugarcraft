<?php

declare(strict_types=1);

namespace SugarCraft\Boxer\Tests;

use SugarCraft\Boxer\{Node, SugarBoxer};
use PHPUnit\Framework\TestCase;

/**
 * Flex / grow children: a layout can express "header=1 fixed, content=FILL,
 * status=1 fixed" — fixed children take their natural size, flex children share
 * the leftover. Without any flex child, the historical weight-by-min* split is
 * unchanged.
 */
final class FlexLayoutTest extends TestCase
{
    private SugarBoxer $boxer;

    protected function setUp(): void
    {
        $this->boxer = SugarBoxer::new();
    }

    /** @return list<string> rendered rows (rtrimmed) */
    private function rows(Node $layout, int $w, int $h): array
    {
        return array_map('rtrim', explode("\n", $this->boxer->render($layout, $w, $h)));
    }

    /** Count rows whose first non-space content matches /^c\d/ (content body lines). */
    private static function contentRows(array $rows): int
    {
        return count(array_filter($rows, static fn (string $r): bool => preg_match('/^c\d/', ltrim($r)) === 1));
    }

    private function headerContentStatus(bool $grow): Node
    {
        $content = $this->boxer->leaf("c1\nc2\nc3\nc4\nc5\nc6\nc7\nc8")->withBorder(false);
        $content = $grow ? $content->withGrow() : $content;

        // spacing=1 → no inter-panel separators to collide with the 1-row leaves.
        return $this->boxer->vertical(
            $this->boxer->leaf('HEAD')->withBorder(false)->withMinHeight(1),
            $content,
            $this->boxer->leaf('FOOT')->withBorder(false)->withMinHeight(1),
        )->withBorder(false)->withSpacing(1);
    }

    public function testFlexContentFillsWhileFixedKeepTheirLines(): void
    {
        $rows = $this->rows($this->headerContentStatus(grow: true), 12, 10);

        $this->assertStringContainsString('HEAD', $rows[0]);          // fixed header, top
        $this->assertStringContainsString('FOOT', $rows[9]);          // fixed status, bottom
        $this->assertGreaterThanOrEqual(5, self::contentRows($rows)); // content FILLS the middle
    }

    public function testWithoutFlexContentIsThirds(): void
    {
        // Same tree, no grow → historical weight-by-minHeight ≈ thirds: the
        // content panel is clipped to a fraction, NOT filled.
        $rows = $this->rows($this->headerContentStatus(grow: false), 12, 12);

        $this->assertLessThan(5, self::contentRows($rows));
    }

    public function testFlexContentGrowsWithTerminalHeight(): void
    {
        $short = self::contentRows($this->rows($this->headerContentStatus(grow: true), 12, 8));
        $tall  = self::contentRows($this->rows($this->headerContentStatus(grow: true), 12, 14));

        $this->assertGreaterThan($short, $tall); // taller terminal → more content rows
    }

    public function testTwoFlexChildrenSplitRemainderByWeight(): void
    {
        // weight 3 : 1 over a tall region → the first panel gets ~3x the rows.
        $layout = $this->boxer->vertical(
            $this->boxer->leaf("a1\na2\na3\na4\na5\na6\na7\na8\na9")->withBorder(false)->withFlex(3),
            $this->boxer->leaf("b1\nb2\nb3\nb4\nb5\nb6\nb7\nb8\nb9")->withBorder(false)->withFlex(1),
        )->withBorder(false)->withSpacing(1);

        $rows = array_map('rtrim', explode("\n", $this->boxer->render($layout, 12, 13)));
        $a = count(array_filter($rows, static fn (string $r): bool => str_starts_with(ltrim($r), 'a')));
        $b = count(array_filter($rows, static fn (string $r): bool => str_starts_with(ltrim($r), 'b')));

        // 12 content rows split 3:1 → ~9 and ~3.
        $this->assertGreaterThan($b, $a);
        $this->assertGreaterThanOrEqual(2, $b);
    }

    public function testHorizontalFlexContentTakesLeftoverWidth(): void
    {
        // Fixed 6-wide sidebar + growing content. The content panel must be wide
        // enough for its 10-char word — a weight-by-minWidth split would starve it.
        $layout = $this->boxer->horizontal(
            $this->boxer->leaf('S')->withBorder(false)->withMinWidth(6),
            $this->boxer->leaf('TENCHARABC')->withBorder(false)->withGrow(),
        )->withBorder(false)->withSpacing(1);

        $out = $this->boxer->render($layout, 20, 1);
        $this->assertStringContainsString('TENCHARABC', $out);
        $this->assertStringContainsString('S', $out);
    }

    public function testFlexBasisReservesBorderedFixedPanels(): void
    {
        // Chrome-shaped: bordered header/status (min 1 → 3 rows incl. border) and a
        // bordered, growing content panel that fills the rest — the content region
        // is far taller than a 1/3 split.
        $layout = $this->boxer->vertical(
            $this->boxer->leaf('H')->withMinHeight(1),
            $this->boxer->leaf("c1\nc2\nc3\nc4\nc5\nc6\nc7\nc8\nc9")->withGrow(),
            $this->boxer->leaf('S')->withMinHeight(1),
        );

        // Content lines sit inside the bordered panel ("│c1        │"), so match
        // the marker anywhere on the row rather than at the start.
        $rows  = $this->rows($layout, 20, 18);
        $shown = count(array_filter($rows, static fn (string $r): bool => preg_match('/c[1-9]/', $r) === 1));
        $this->assertGreaterThanOrEqual(6, $shown);
    }

    public function testFixedPanelsOverflowDegradeGracefully(): void
    {
        // Fixed bases exceed the viewport → flex gets 0 and trailing panels clip,
        // but rendering must not error and the first fixed panel still shows.
        $layout = $this->boxer->vertical(
            $this->boxer->leaf('TOP')->withBorder(false)->withMinHeight(3),
            $this->boxer->leaf('mid')->withBorder(false)->withGrow(),
            $this->boxer->leaf('BOT')->withBorder(false)->withMinHeight(3),
        )->withBorder(false);

        $out = $this->boxer->render($layout, 10, 2); // only 2 rows for 6+ requested
        $this->assertIsString($out);
        $this->assertStringContainsString('TOP', $out);
    }

    // ---- Node flex API ------------------------------------------------------

    public function testWithFlexSetsWeight(): void
    {
        $this->assertSame(3, Node::leaf('x')->withFlex(3)->flex);
        $this->assertSame(1, Node::leaf('x')->withGrow()->flex);
        $this->assertSame(0, Node::leaf('x')->flex); // default: not flexible
    }

    public function testWithFlexClampsNegativeToZero(): void
    {
        $this->assertSame(0, Node::leaf('x')->withFlex(-5)->flex);
    }

    public function testFlexSurvivesOtherBuilders(): void
    {
        $n = Node::leaf('x')->withGrow()->withMinHeight(2)->withPadding(1);
        $this->assertSame(1, $n->flex);
        $this->assertSame(2, $n->minHeight);
    }

    public function testBorderFalseSurvivesLaterBuilders(): void
    {
        // Regression: withBorder(false) must not be silently re-enabled by a
        // subsequent with*() call (the historical default-true footgun).
        $n = Node::leaf('x')->withBorder(false)->withMinHeight(1)->withGrow();
        $this->assertFalse($n->border);

        $n2 = Node::leaf('x')->withBorder(true)->withMinHeight(1);
        $this->assertTrue($n2->border);
    }

    // ---- Margin is part of a fixed child's flex footprint -------------------

    public function testHorizontalFixedMarginedChildReservesItsMargin(): void
    {
        // A fixed (non-flex) child with a right margin sits beside a flex child.
        // Its flex "base" must be minWidth + left+right margin (4 + 2 = 6), so the
        // flex sibling only gets the TRUE leftover. Under the old margin-omitting
        // totalWidth() the base was 4: the fixed child was under-reserved, its own
        // content ('SIDE') was truncated to 'SI', and the flex child stole 2 extra
        // columns (7 X's instead of 5).
        $layout = $this->boxer->horizontal(
            $this->boxer->leaf('SIDE')->withBorder(false)->withMinWidth(4)->withMargin(0, 2, 0, 0),
            $this->boxer->leaf(str_repeat('X', 20))->withBorder(false)->withGrow(),
        )->withBorder(false)->withSpacing(1);

        $out = $this->boxer->render($layout, 12, 1);

        // Fixed child now reserves its full footprint → its content is not clipped.
        $this->assertStringContainsString('SIDE', $out);
        // Flex sibling gets exactly the leftover after the TRUE footprint (6 + gap 1).
        $this->assertSame(5, substr_count($out, 'X'));
    }

    public function testVerticalFixedTopMarginedChildIsNotSqueezedOut(): void
    {
        // A fixed header with a 1-row top margin beside a growing body. Its flex
        // "base" must be minHeight + top+bottom margin (1 + 1 = 2). Under the old
        // margin-omitting totalHeight() the base was 1, the top margin consumed the
        // child's entire 1-row slot, and the header ('TOP') vanished while the body
        // absorbed the freed row (5 body lines instead of 4).
        $layout = $this->boxer->vertical(
            $this->boxer->leaf('TOP')->withBorder(false)->withMinHeight(1)->withMargin(1, 0, 0, 0),
            $this->boxer->leaf("b1\nb2\nb3\nb4\nb5\nb6")->withBorder(false)->withGrow(),
        )->withBorder(false)->withSpacing(1);

        $rows = array_map('rtrim', explode("\n", $this->boxer->render($layout, 10, 7)));

        // Header survives: blank top-margin row, then 'TOP' on row 1.
        $this->assertSame('', $rows[0]);
        $this->assertSame('TOP', $rows[1]);
        // Body fills exactly the leftover after the header's TRUE footprint → 4 lines.
        $body = count(array_filter($rows, static fn (string $r): bool => preg_match('/^b\d$/', $r) === 1));
        $this->assertSame(4, $body);
    }
}
