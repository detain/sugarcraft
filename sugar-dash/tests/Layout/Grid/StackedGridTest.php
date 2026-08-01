<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Layout\Grid;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Theme;
use SugarCraft\Dash\Layout\Grid\ItemOptions;
use SugarCraft\Dash\Layout\Grid\Options;
use SugarCraft\Dash\Layout\Grid\StackedGrid;
use SugarCraft\Dash\State\Persistence;

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

        $this->assertInstanceOf(\SugarCraft\Dash\Foundation\Sizer::class, $result);
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
        $grid = new StackedGrid();
        $persistence = $this->createMock(Persistence::class);

        $persistence->expects($this->once())
            ->method('save')
            ->with(
                '/path/to/state',
                $this->callback(function($data) {
                    return isset($data['collapsedAddresses'])
                        && $data['collapsedAddresses'] === ['address1', 'address2'];
                })
            );

        $grid->persistState($persistence, '/path/to/state', ['address1', 'address2']);
    }

    public function testPersistStateWithEmptyCollapsedAddresses(): void
    {
        $grid = new StackedGrid();
        $persistence = $this->createMock(Persistence::class);

        $persistence->expects($this->once())
            ->method('save')
            ->with(
                '/path/to/state',
                $this->callback(function($data) {
                    return $data['collapsedAddresses'] === [];
                })
            );

        $grid->persistState($persistence, '/path/to/state', []);
    }

    // ═══════════════════════════════════════════════════════════════
    // restoreState
    // ═══════════════════════════════════════════════════════════════

    public function testRestoreStateReturnsCollapsedAddresses(): void
    {
        $grid = new StackedGrid();
        $persistence = $this->createMock(Persistence::class);

        $persistence->expects($this->once())
            ->method('load')
            ->with('/path/to/state')
            ->willReturn(['collapsedAddresses' => ['addr1', 'addr2']]);

        $addresses = $grid->restoreState($persistence, '/path/to/state');

        $this->assertSame(['addr1', 'addr2'], $addresses);
    }

    public function testRestoreStateWithNullDataReturnsEmptyArray(): void
    {
        $grid = new StackedGrid();
        $persistence = $this->createMock(Persistence::class);

        $persistence->expects($this->once())
            ->method('load')
            ->with('/path/to/state')
            ->willReturn(null);

        $addresses = $grid->restoreState($persistence, '/path/to/state');

        $this->assertSame([], $addresses);
    }

    public function testRestoreStateWithMissingKeyReturnsEmptyArray(): void
    {
        $grid = new StackedGrid();
        $persistence = $this->createMock(Persistence::class);

        $persistence->expects($this->once())
            ->method('load')
            ->with('/path/to/state')
            ->willReturn(['other' => 'data']);

        $addresses = $grid->restoreState($persistence, '/path/to/state');

        $this->assertSame([], $addresses);
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
}
