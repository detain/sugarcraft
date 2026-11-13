<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Layout\Grid;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Foundation\Theme;
use SugarCraft\Dashboard\Layout\Grid\ItemOptions;
use SugarCraft\Dashboard\Layout\Grid\Options;
use SugarCraft\Dashboard\Layout\Grid\StackedGrid;
use SugarCraft\Dashboard\State\Persistence;

/**
 * Tests for StackedGrid persistence and theming.
 */
final class StackedGridTest extends TestCase
{
    private function strItem(string $s): Item
    {
        return new class($s) implements Item {
            public function __construct(private readonly string $s) {}
            public function render(): string { return $this->s; }
        };
    }

    // ═══════════════════════════════════════════════════════════════
    // setSize
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsSizerInterface(): void
    {
        $grid = new StackedGrid();
        $result = $grid->setSize(100, 50);

        $this->assertInstanceOf(\SugarCraft\Dashboard\Foundation\Sizer::class, $result);
    }

    public function testSetSizeReturnsSameInstance(): void
    {
        $grid = new StackedGrid();
        $resized = $grid->setSize(100, 50);

        // setSize returns $this (mutates in place)
        $this->assertSame($grid, $resized);
    }

    public function testSetSizeZeroWidthReturnsLoadingMessage(): void
    {
        $grid = new StackedGrid();
        $grid = $grid->withItem($this->strItem('test'), new ItemOptions());

        $rendered = $grid->render();

        $this->assertSame('Loading...', $rendered);
    }

    public function testSetSizeZeroHeightReturnsLoadingMessage(): void
    {
        $grid = new StackedGrid();
        $grid = $grid->withItem($this->strItem('test'), new ItemOptions());
        $grid->setSize(100, 0);

        $rendered = $grid->render();

        $this->assertSame('Loading...', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Empty grid rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderEmptyGridReturnsEmpty(): void
    {
        $grid = new StackedGrid();
        $grid->setSize(100, 50);

        $rendered = $grid->render();

        $this->assertSame('', $rendered);
    }

    public function testRenderGridWithNoColumnsReturnsEmpty(): void
    {
        $grid = new StackedGrid();
        $grid->setSize(100, 50);

        // No items added

        $this->assertSame('', $grid->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // persistState
    // ═══════════════════════════════════════════════════════════════

    public function testPersistStateSavesCollapsedAddresses(): void
    {
        // Persistence is final — the real save/load round-trip IS the pin.
        $dir = self::stateSandbox();
        try {
            $path = $dir . '/grid-state.json';
            $persistence = new Persistence();

            (new StackedGrid())->persistState($persistence, $path, ['address1', 'address2']);

            $this->assertSame(
                ['collapsedAddresses' => ['address1', 'address2']],
                $persistence->load($path),
            );
        } finally {
            self::removeStateSandbox($dir);
        }
    }

    public function testPersistStateWithEmptyCollapsedAddresses(): void
    {
        $dir = self::stateSandbox();
        try {
            $path = $dir . '/grid-state.json';
            $persistence = new Persistence();

            (new StackedGrid())->persistState($persistence, $path, []);

            $this->assertSame(['collapsedAddresses' => []], $persistence->load($path));
        } finally {
            self::removeStateSandbox($dir);
        }
    }

    public function testRestoreStateReturnsCollapsedAddresses(): void
    {
        $dir = self::stateSandbox();
        try {
            $path = $dir . '/grid-state.json';
            $persistence = new Persistence();
            $persistence->save($path, ['collapsedAddresses' => ['addr1', 'addr2']]);

            $addresses = (new StackedGrid())->restoreState($persistence, $path);

            $this->assertSame(['addr1', 'addr2'], $addresses);
        } finally {
            self::removeStateSandbox($dir);
        }
    }

    public function testRestoreStateWithNullDataReturnsEmptyArray(): void
    {
        $dir = self::stateSandbox();
        try {
            $persistence = new Persistence();

            $addresses = (new StackedGrid())->restoreState($persistence, $dir . '/nonexistent.json');

            $this->assertSame([], $addresses);
        } finally {
            self::removeStateSandbox($dir);
        }
    }

    public function testRestoreStateWithMissingKeyReturnsEmptyArray(): void
    {
        $dir = self::stateSandbox();
        try {
            $path = $dir . '/grid-state.json';
            $persistence = new Persistence();
            $persistence->save($path, ['other' => 'data']);

            $addresses = (new StackedGrid())->restoreState($persistence, $path);

            $this->assertSame([], $addresses);
        } finally {
            self::removeStateSandbox($dir);
        }
    }


    // ═══════════════════════════════════════════════════════════════
    // withTheme
    // ═══════════════════════════════════════════════════════════════

    public function testWithThemeReturnsNewInstance(): void
    {
        $grid = new StackedGrid();
        $theme = Theme::dark();

        $themed = $grid->withTheme($theme);

        $this->assertNotSame($grid, $themed);
    }

    public function testWithThemeWorksWithRegularItems(): void
    {
        $grid = new StackedGrid();
        $theme = Theme::dark();
        $plainItem = $this->strItem('plain');

        $grid = $grid->withItem($plainItem, new ItemOptions());

        // Should not throw, should return themed grid
        $themed = $grid->withTheme($theme);

        $this->assertNotNull($themed);
        $this->assertNotSame($grid, $themed);
    }

    public function testWithThemePreservesGridDimensions(): void
    {
        $grid = new StackedGrid();
        $grid = $grid->withItem($this->strItem('item'), new ItemOptions());
        $grid->setSize(100, 20);
        $theme = Theme::dark();

        $themed = $grid->withTheme($theme);

        // The themed version should still be usable
        $this->assertIsString($themed->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Single column rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderSingleColumnGridRendersCorrectly(): void
    {
        $grid = new StackedGrid();
        $grid = $grid->withItem($this->strItem('Column 0 Item'), new ItemOptions(column: 0));
        $grid->setSize(100, 20);

        $rendered = $grid->render();

        $this->assertStringContainsString('Column 0 Item', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Options
    // ═══════════════════════════════════════════════════════════════

    public function testConstructorWithCustomOptions(): void
    {
        $options = new Options(fitScreen: false);
        $grid = new StackedGrid($options);

        $grid = $grid->withItem($this->strItem('test'), new ItemOptions());
        $grid->setSize(100, 20);

        // Should render without error
        $rendered = $grid->render();
        $this->assertIsString($rendered);
    }

    private static function stateSandbox(): string
    {
        $dir = sys_get_temp_dir() . '/dash-state-' . bin2hex(random_bytes(4));
        if (!mkdir($dir, 0700)) {
            throw new \RuntimeException('could not create state sandbox');
        }

        return $dir;
    }

    private static function removeStateSandbox(string $dir): void
    {
        foreach ((array) scandir($dir) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($dir . '/' . $entry);
            }
        }
        @rmdir($dir);
    }


    public function testFalseFitScreenCannotOverFlowFrame(): void
    {
        // Finding M-33: with fitScreen=false every column used to receive the
        // FULL grid width, so the joined frame overflowed by colCount x width.
        // Review m4: the previous arm sized the grid at 30 columns — but
        // Breakpoint::narrow() collapses anything under 90 into a SINGLE
        // column before the clamp arm is ever reached, so it stayed green
        // under the unclamped bug too (self-disclosed as non-discriminating).
        // 120 keeps the grid on the multi-column path, where clamped columns
        // measure share x colCount = width while unclamped ones measure
        // width x colCount after joinHorizontal (which pads and joins, it
        // does NOT crop).
        $grid = new StackedGrid(new Options(fitScreen: false));
        $grid = $grid->withItem($this->strItem(str_repeat('A', 90)), new ItemOptions(column: 0));
        $grid = $grid->withItem($this->strItem(str_repeat('B', 90)), new ItemOptions(column: 1));
        $grid->setSize(120, 3);

        $lines = explode("\n", $grid->render());
        foreach ($lines as $line) {
            $this->assertSame(
                120,
                \SugarCraft\Core\Util\Width::string($line),
                'fitScreen=false must clamp every column to its even share',
            );
        }

        // Geometry, not just budget: clamped, column 0 keeps its first 60
        // cells and column 1 lands in cells 61..120. Unclamped, the 'A'
        // column runs to 90 + padding and the frame carries 180+ cells —
        // the exact breach the ≤120 width arms above also catch.
        $this->assertSame(60, substr_count($lines[0], 'A'));
        $this->assertSame(60, substr_count($lines[0], 'B'));
    }

    public function testFalseFitScreenKeepsShortColumnsNatural(): void
    {
        // Finding m-17: fitScreen=false used to be inert — the arm padded
        // every column to the even share exactly like fitScreen=true, so a
        // 'Short' column still shipped 100 cells of blank after it and the
        // joined frame always filled the width. False must now stop the
        // frame where the content stops: 5 + 11 natural cells, not 200.
        // 200 keeps the grid on the multi-column path (90 would collapse).
        $grid = new StackedGrid(new Options(fitScreen: false));
        $grid = $grid->withItem($this->strItem('Short'), new ItemOptions(column: 0));
        $grid = $grid->withItem($this->strItem('LongContent'), new ItemOptions(column: 1));
        $grid->setSize(200, 3);

        $lines = explode("\n", $grid->render());
        // Plain non-expanding items occupy their natural one line each;
        // the grid pads only within a column's stack, not to full height.
        $this->assertNotEmpty($lines);
        foreach ($lines as $line) {
            $this->assertSame(
                16,
                \SugarCraft\Core\Util\Width::string($line),
                'fitScreen=false must join natural column widths, not fill the frame',
            );
        }
        $this->assertSame('Short', substr($lines[0], 0, 5));
        $this->assertSame('LongContent', substr($lines[0], 5, 11));
    }

    public function testTrueFitScreenStillDividesTheFrameEvenly(): void
    {
        // Byte-freeze of the default arm beside the m-17 flip: identical
        // content under fitScreen=true must still pad both columns to the
        // even share and fill the frame (100 + 100 = 200), so the natural
        // widths asserted above can only ever come from the flag, never
        // from a regression in the default path.
        $grid = new StackedGrid(new Options(fitScreen: true));
        $grid = $grid->withItem($this->strItem('Short'), new ItemOptions(column: 0));
        $grid = $grid->withItem($this->strItem('LongContent'), new ItemOptions(column: 1));
        $grid->setSize(200, 3);

        $lines = explode("\n", $grid->render());
        foreach ($lines as $line) {
            $this->assertSame(200, \SugarCraft\Core\Util\Width::string($line));
        }
        $this->assertSame('Short', substr($lines[0], 0, 5));
        $this->assertSame('LongContent', substr($lines[0], 100, 11));
    }

    public function testFalseFitScreenSizedColumnTakesFullShare(): void
    {
        // A column whose child is sized by its recipient (here a nested
        // grid) has no natural width to measure — it renders to whatever
        // box it is handed — so under fitScreen=false it keeps the full
        // even share (100) while the plain column beside it stays natural
        // (11): joined 111, filling neither 200 nor collapsing to 1.
        $inner = new StackedGrid();
        $inner = $inner->withItem($this->strItem('Inner'), new ItemOptions(column: 0));

        $grid = new StackedGrid(new Options(fitScreen: false));
        $grid = $grid->withItem($inner, new ItemOptions(column: 0));
        $grid = $grid->withItem($this->strItem('LongContent'), new ItemOptions(column: 1));
        $grid->setSize(200, 3);

        $lines = explode("\n", $grid->render());
        $this->assertStringContainsString('Inner', $lines[0]);
        $this->assertStringContainsString('LongContent', $lines[0]);
        foreach ($lines as $line) {
            $this->assertSame(
                111,
                \SugarCraft\Core\Util\Width::string($line),
                'a Sizer/nested-grid column must take the full share even under fitScreen=false',
            );
        }
    }

}
