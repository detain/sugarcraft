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
        $grid->addItem($this->strItem('test'), new ItemOptions());

        $rendered = $grid->render();

        $this->assertSame('Loading...', $rendered);
    }

    public function testSetSizeZeroHeightReturnsLoadingMessage(): void
    {
        $grid = new StackedGrid();
        $grid->addItem($this->strItem('test'), new ItemOptions());
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

        $grid->addItem($plainItem, new ItemOptions());

        // Should not throw, should return themed grid
        $themed = $grid->withTheme($theme);

        $this->assertNotNull($themed);
        $this->assertNotSame($grid, $themed);
    }

    public function testWithThemePreservesGridDimensions(): void
    {
        $grid = new StackedGrid();
        $grid->addItem($this->strItem('item'), new ItemOptions());
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
        $grid->addItem($this->strItem('Column 0 Item'), new ItemOptions(column: 0));
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

        $grid->addItem($this->strItem('test'), new ItemOptions());
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

}
