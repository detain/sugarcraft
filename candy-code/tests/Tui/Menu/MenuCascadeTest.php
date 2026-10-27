<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui\Menu;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tui\Menu\MenuCascade;
use SugarCraft\Code\Tui\Menu\MenuCursor;
use SugarCraft\Code\Tui\Menu\MenuNode;
use SugarCraft\Code\Tui\Menu\MenuPanel;
use SugarCraft\Code\Tui\Menu\MenuTree;
use SugarCraft\Code\Theme;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Core\Util\Width;
use SugarCraft\Mouse\Mark;
use SugarCraft\Style\Style;

/**
 * The cascade's geometry law, pinned four ways: composited goldens for the
 * root, an entered child, the right-edge flip, and a windowed tall panel;
 * the marked twin rebuilt cell-for-cell off the painted one; the width law
 * swept across every screen width; and the colour tokens lifted straight
 * from `MenuBar::dropdownLines()`.
 *
 * Regenerate goldens with `UPDATE_GOLDENS=1` after an intended change.
 */
final class MenuCascadeTest extends TestCase
{
    private const SENTINEL_OPEN = Sanitize::ZONE_SENTINEL_OPEN;
    private const SENTINEL_CLOSE = Sanitize::ZONE_SENTINEL_CLOSE;

    /**
     * Menu 1 Media: ▶ Play (explicit zone), ✓ ↻ Loop (checked), Shuffle,
     * a separator, an offline (disabled) row, Playlist ▸ (six entries).
     * Menu 2 View: Panes ▸ (Chat, Dock ▸ (Left, Right)), Theme.
     */
    private function mediaTree(): MenuTree
    {
        return MenuTree::new([
            MenuNode::submenu('sub:media', 'Media', [
                MenuNode::command('cmd:play', 'Play', '▶', zone: 'cmd:play'),
                MenuNode::toggle('toggle:loop', 'Loop', true, '↻'),
                MenuNode::toggle('toggle:shuffle', 'Shuffle'),
                MenuNode::separator(),
                MenuNode::command('cmd:offline', 'Karaoke (offline)', enabled: false),
                MenuNode::submenu('sub:playlist', 'Playlist', [
                    MenuNode::command('cmd:pl-1', 'One'),
                    MenuNode::command('cmd:pl-2', 'Two'),
                    MenuNode::command('cmd:pl-3', 'Three'),
                    MenuNode::command('cmd:pl-4', 'Four'),
                    MenuNode::command('cmd:pl-5', 'Five'),
                    MenuNode::command('cmd:pl-6', 'Six'),
                ]),
            ]),
            MenuNode::submenu('sub:view', 'View', [
                MenuNode::submenu('sub:panes', 'Panes', [
                    MenuNode::command('cmd:chat', 'Chat'),
                    MenuNode::submenu('sub:dock', 'Dock', [
                        MenuNode::command('cmd:left', 'Left'),
                        MenuNode::command('cmd:right', 'Right'),
                    ]),
                ]),
                MenuNode::command('cmd:theme', 'Theme'),
            ]),
        ]);
    }

    /** @return list<MenuNode> */
    private function sixteenLongRows(): array
    {
        $rows = [];

        for ($index = 1; $index <= 16; $index++) {
            $rows[] = MenuNode::command(
                'cmd=row-' . $index,
                'Item ' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            );
        }

        return $rows;
    }

    public function testClosedCursorLaysOutNothing(): void
    {
        $theme = Theme::default();
        $tree = $this->mediaTree();

        self::assertSame([], MenuCascade::layout($tree, MenuCursor::closed(), $theme, 1, 1, 80, 24));
    }

    public function testRootPanelGoldenAtEightyByTwentyFour(): void
    {
        $tree = $this->mediaTree();
        $cursor = MenuCursor::open(1, $tree);

        $panels = MenuCascade::layout($tree, $cursor, Theme::default(), 1, 1, 80, 24);
        self::assertCount(1, $panels);

        $frame = $this->compose($panels, 80, 24);
        $this->assertMenuGolden('root-80x24', $frame);

        $stripped = array_map($this->unmark(...), array_map($this->plain(...), $panels[0]->lines));
        self::assertStringContainsString('├', $stripped[4], 'the separator draws ├─┤');
        self::assertStringContainsString('✓ ↻ Loop', $stripped[2], 'checked toggle carries the check column');
        self::assertStringContainsString('  Shuffle', $stripped[3], 'unchecked toggle keeps a blank column');
        self::assertMatchesRegularExpression('/Playlist +▸/', $stripped[6], 'submenu rows right-align the marker');
    }

    public function testEnteredChildGoldenAtEightyByTwentyFour(): void
    {
        $tree = $this->mediaTree();
        $cursor = $this->openPlaylist($tree);
        self::assertSame([5, 0], $cursor->rows);

        $panels = MenuCascade::layout($tree, $cursor, Theme::default(), 1, 1, 80, 24);
        self::assertCount(2, $panels);

        // The child sits on its parent's highlighted row, flush against the
        // parent's right border.
        self::assertSame($panels[0]->col + $panels[0]->width() - 1, $panels[1]->col);
        self::assertSame($panels[0]->row + 1 + 5, $panels[1]->row);
        self::assertSame([5], $panels[1]->path);

        $this->assertMenuGolden('root-child-80x24', $this->compose($panels, 80, 24));
    }

    public function testFlipLeftGoldenAtTheRightEdge(): void
    {
        $tree = $this->mediaTree();
        $cursor = $this->openPlaylist($tree);

        // Title near the right edge: the root box shifts left to fit, and
        // the child would cross cols on the right, so it flips to the
        // parent's left border.
        $panels = MenuCascade::layout($tree, $cursor, Theme::default(), 62, 1, 80, 24);
        self::assertCount(2, $panels);

        self::assertSame(60, $panels[0]->col, 'root shifted left to fit the screen');
        self::assertSame($panels[0]->col - $panels[1]->width() + 1, $panels[1]->col, 'child right edge on the parent left border');

        $this->assertMenuGolden('flip-left-80x24', $this->compose($panels, 80, 24));
    }

    public function testWindowedPanelGoldenAtFortyByTwelve(): void
    {
        $tree = MenuTree::new([MenuNode::submenu('sub:long', 'Long', $this->sixteenLongRows())]);
        $cursor = MenuCursor::open(1, $tree);

        for ($step = 0; $step < 8; $step++) {
            $cursor = $cursor->key('down', $tree)->cursor;
        }

        self::assertSame([8], $cursor->rows);

        $panels = MenuCascade::layout($tree, $cursor, Theme::default(), 28, 1, 40, 12);
        self::assertCount(1, $panels);

        $panel = $panels[0];
        // Shifted up to the top of the band, then windowed: two faint `…`
        // rows bracket a slice of 8 centred on the highlighted row 8.
        self::assertSame(0, $panel->row);
        self::assertSame(12, count($panel->lines));
        self::assertSame(4, $panel->firstRowIndex);

        $stripped = array_map($this->unmark(...), array_map($this->plain(...), $panel->lines));
        self::assertStringContainsString('…', $stripped[1], 'top ellipsis row');
        self::assertStringContainsString('…', $stripped[10], 'bottom ellipsis row');
        self::assertStringContainsString('Item 05', $stripped[2], 'first displayed row');
        self::assertStringContainsString('Item 12', $stripped[9], 'last displayed row');
        $joined = implode('|', $stripped);
        self::assertStringNotContainsString('Item 04', $joined, 'the window drops rows above the slice');
        self::assertStringNotContainsString('Item 13', $joined, 'and rows below it');
        self::assertStringContainsString('Item 09', $stripped[6], 'the highlighted row sits inside the slice');

        $this->assertMenuGolden('windowed-40x12', $this->compose($panels, 40, 12));
    }

    public function testHoverPreviewsTheChildAtTheEnteredGeometry(): void
    {
        $tree = $this->mediaTree();
        $hovered = MenuCursor::open(1, $tree)->hover([5], $tree);
        self::assertSame([5], $hovered->rows, 'hover does not enter');
        self::assertTrue($hovered->expanded);

        $theme = Theme::default();
        $preview = MenuCascade::layout($tree, $hovered, $theme, 1, 1, 80, 24);
        $entered = MenuCascade::layout($tree, $this->openPlaylist($tree), $theme, 1, 1, 80, 24);

        self::assertCount(2, $preview);
        self::assertSame($entered[1]->col, $preview[1]->col);
        self::assertSame($entered[1]->row, $preview[1]->row);

        // A preview opens at its first row; an entered child keeps its own.
        $plainPreview = $this->plain($preview[1]->lines[1]);
        $plainEntered = $this->plain($entered[1]->lines[1]);
        self::assertStringContainsString('One', $plainPreview);
        self::assertStringContainsString('One', $plainEntered);
    }

    public function testMarkedTwinCoversExactlyThePaintedCells(): void
    {
        $tree = $this->mediaTree();
        $cursor = $this->openPlaylist($tree);
        $theme = Theme::default();

        $painted = MenuCascade::layout($tree, $cursor, $theme, 1, 1, 80, 24, false);
        $marked = MenuCascade::layout($tree, $cursor, $theme, 1, 1, 80, 24, true);

        self::assertCount(2, $marked);
        self::assertSame($this->compose($painted, 80, 24), $this->compose($marked, 80, 24), 'both twins paint the same frame');

        // The explicit zone rides the row that carries it…
        self::assertSame(
            Mark::zone('cmd:play', $painted[0]->lines[1]),
            $marked[0]->lines[1],
        );
        // …while derived zones number rows 1-based from the ROOT, and the
        // menu index never appears.
        self::assertSame(Mark::zone('menuitem:2', $painted[0]->lines[2]), $marked[0]->lines[2]);
        self::assertSame(Mark::zone('menuitem:6', $painted[0]->lines[6]), $marked[0]->lines[6]);
        self::assertSame(Mark::zone('menuitem:6.1', $painted[1]->lines[1]), $marked[1]->lines[1]);
        self::assertSame(Mark::zone('menuitem:6.6', $painted[1]->lines[6]), $marked[1]->lines[6]);

        // Separators and disabled rows are drawn, never clickable.
        self::assertStringNotContainsString(self::SENTINEL_OPEN, $marked[0]->lines[4]);
        self::assertStringNotContainsString(self::SENTINEL_OPEN, $marked[0]->lines[5]);
        // Borders never carry a zone.
        self::assertStringNotContainsString(self::SENTINEL_OPEN, $marked[0]->lines[0]);
    }

    public function testEveryZoneIdCarriesItsDottedDropdownPath(): void
    {
        $tree = $this->mediaTree();
        $cursor = MenuCursor::open(2, $tree)
            ->key('enter', $tree)->cursor
            ->key('down', $tree)->cursor
            ->key('right', $tree)->cursor;
        self::assertSame([0, 1, 0], $cursor->rows);

        $panels = MenuCascade::layout($tree, $cursor, Theme::default(), 1, 1, 80, 24, true);
        self::assertCount(3, $panels);

        // root row 1 (Panes) = menuitem:1 → dock row 2 = menuitem:1.2 →
        // dock child row 1 (Left) = menuitem:1.2.1
        self::assertStringContainsString(self::SENTINEL_OPEN . 'menuitem:1' . self::SENTINEL_CLOSE, $panels[0]->lines[1]);
        self::assertStringContainsString(self::SENTINEL_OPEN . 'menuitem:1.2.1' . self::SENTINEL_CLOSE, $panels[2]->lines[1]);
    }

    public function testWidthLawHoldsAtEveryScreenWidthOnTheDeepestPath(): void
    {
        $tree = $this->mediaTree();
        $cursor = MenuCursor::open(2, $tree)
            ->key('enter', $tree)->cursor
            ->key('down', $tree)->cursor
            ->key('right', $tree)->cursor;

        foreach ([20, 28, 40, 60, 80, 120, 160, 200] as $cols) {
            foreach ([false, true] as $marked) {
                $panels = MenuCascade::layout($tree, $cursor, Theme::default(), 1, 1, $cols, 24, $marked);
                self::assertGreaterThan(1, count($panels), 'deepest path renders at ' . $cols);

                foreach ($panels as $panel) {
                    self::assertGreaterThanOrEqual(1, $panel->col);
                    self::assertLessThanOrEqual($cols, $panel->col + $panel->width() - 1);

                    foreach ($panel->lines as $line) {
                        self::assertLessThanOrEqual(
                            $cols,
                            Width::string($this->unmark($this->plain($line))),
                            'panel line exceeds cols at width ' . $cols . ': ' . $this->plain($line),
                        );
                    }
                }
            }
        }
    }

    public function testRowsRideTheSameTokensMenuBarDropdownLinesUses(): void
    {
        $theme = Theme::default();
        $prefix = static fn (Style $style): string => explode('x', $style->render('x'))[0];

        $border = $prefix(Style::new()->foreground($theme->border));
        $foreground = $prefix(Style::new()->foreground($theme->shellForeground));
        $primary = $prefix(Style::new()->foreground($theme->shellPrimary)->bold());
        $muted = $prefix(Style::new()->foreground($theme->shellMuted));

        $tree = $this->mediaTree();
        $panels = MenuCascade::layout($tree, MenuCursor::open(1, $tree), $theme, 1, 1, 80, 24);
        $root = $panels[0];

        self::assertStringContainsString($border, $root->lines[0]);
        self::assertStringContainsString($primary, $root->lines[1], 'the highlighted row is shellPrimary + bold');
        self::assertStringContainsString($foreground, $root->lines[2]);
        self::assertStringContainsString($muted, $root->lines[5], 'a disabled row is shellMuted');
    }

    /** Walk the cursor from a closed bar down to an ENTERED Playlist child. */
    private function openPlaylist(MenuTree $tree): MenuCursor
    {
        $cursor = MenuCursor::open(1, $tree);

        // [0] Play -> [1] Loop -> [2] Shuffle -> [5] Playlist (skips the
        // separator and the disabled offline row).
        for ($step = 0; $step < 3; $step++) {
            $cursor = $cursor->key('down', $tree)->cursor;
        }

        self::assertSame([5], $cursor->rows);

        return $cursor->key('enter', $tree)->cursor;
    }

    /** @param list<MenuPanel> $panels */
    private function compose(array $panels, int $cols, int $rows): string
    {
        $grid = array_fill(0, $rows, array_fill(0, $cols, ' '));

        foreach ($panels as $panel) {
            foreach ($panel->lines as $offset => $line) {
                $y = $panel->row + $offset;

                if ($y >= $rows) {
                    continue;
                }

                $characters = mb_str_split($this->unmark($this->plain($line)));
                $x = $panel->col - 1;

                foreach ($characters as $character) {
                    if ($x >= 0 && $x < $cols) {
                        $grid[$y][$x] = $character;
                    }

                    $x += Width::string($character);
                }
            }
        }

        return implode("\n", array_map(
            static fn (array $rowLine): string => rtrim(implode('', $rowLine)),
            $grid,
        ));
    }

    private function plain(string $line): string
    {
        return Ansi::strip($line);
    }

    /** Strip Mark::zone sentinel+id wrappers, leaving the painted cells. */
    private function unmark(string $line): string
    {
        while (true) {
            $open = strpos($line, self::SENTINEL_OPEN);

            if ($open === false) {
                return $line;
            }

            $close = strpos($line, self::SENTINEL_CLOSE, $open);

            if ($close === false) {
                return $line;
            }

            $line = substr($line, 0, $open) . substr($line, $close + strlen(self::SENTINEL_CLOSE));
        }
    }

    private function assertMenuGolden(string $name, string $actual): void
    {
        $path = __DIR__ . '/golden/' . $name . '.txt';

        if (getenv('UPDATE_GOLDENS') === '1') {
            file_put_contents($path, $actual . "\n");
        }

        $this->assertFileExists($path, 'no golden at ' . $path . '; run with UPDATE_GOLDENS=1 to create it');
        $this->assertSame(file_get_contents($path), $actual . "\n", 'golden drift at ' . $name);
    }
}
