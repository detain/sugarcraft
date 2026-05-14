<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Form;

use SugarCraft\Dash\Components\Form\CommandPalette;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class CommandPaletteTest extends TestCase
{
    // Helper to strip ANSI codes for string comparison
    private function stripAnsi(string $output): string
    {
        return preg_replace('/\x1b\[[0-9;]*m/', '', $output);
    }

    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testCommandPaletteImplementsSizer(): void
    {
        $palette = CommandPalette::new([['label' => 'Command']]);
        $this->assertInstanceOf(Sizer::class, $palette);
    }

    public function testCommandPaletteImplementsItem(): void
    {
        $palette = CommandPalette::new([['label' => 'Command']]);
        $this->assertInstanceOf(Item::class, $palette);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $palette = CommandPalette::new([['label' => 'Command']]);
        $rendered = $palette->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsCommandsHeader(): void
    {
        $palette = CommandPalette::new([['label' => 'Command']]);
        $rendered = $palette->render();

        $this->assertStringContainsString('Commands', $rendered);
    }

    public function testRenderShowsPlaceholder(): void
    {
        $palette = CommandPalette::new([['label' => 'Command']]);
        $rendered = $palette->render();

        $this->assertStringContainsString('Type a command...', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Command rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderContainsCommandLabel(): void
    {
        $palette = CommandPalette::new([['label' => 'Save File']]);
        $rendered = $palette->render();

        $this->assertStringContainsString('Save File', $rendered);
    }

    public function testRenderMultipleCommands(): void
    {
        $palette = CommandPalette::new([
            ['label' => 'Save'],
            ['label' => 'Open'],
            ['label' => 'Close'],
        ]);
        $rendered = $palette->render();

        $this->assertStringContainsString('Save', $rendered);
        $this->assertStringContainsString('Open', $rendered);
        $this->assertStringContainsString('Close', $rendered);
    }

    public function testFirstCommandSelectedByDefault(): void
    {
        $palette = CommandPalette::new([
            ['label' => 'First'],
            ['label' => 'Second'],
        ]);
        $rendered = $palette->render();

        $this->assertStringContainsString('▶', $this->stripAnsi($rendered));
    }

    // ═══════════════════════════════════════════════════════════════
    // Icon support
    // ═══════════════════════════════════════════════════════════════

    public function testCommandWithIcon(): void
    {
        $palette = CommandPalette::new([
            ['label' => 'Save', 'icon' => '💾'],
        ]);
        $rendered = $palette->render();

        $this->assertStringContainsString('💾', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Shortcut support
    // ═══════════════════════════════════════════════════════════════

    public function testCommandWithShortcut(): void
    {
        $palette = CommandPalette::new([
            ['label' => 'Save', 'shortcut' => 'Ctrl+S'],
        ]);
        $rendered = $palette->render();

        $this->assertStringContainsString('Ctrl+S', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Query filtering
    // ═══════════════════════════════════════════════════════════════

    public function testQueryFiltersCommands(): void
    {
        $palette = CommandPalette::new([
            ['label' => 'Save File'],
            ['label' => 'Open File'],
            ['label' => 'Close'],
        ])->withQuery('save');
        $rendered = $palette->render();

        $this->assertStringContainsString('Save File', $rendered);
        $this->assertStringNotContainsString('Open File', $rendered);
        $this->assertStringNotContainsString('Close', $rendered);
    }

    public function testQueryIsCaseInsensitive(): void
    {
        $palette = CommandPalette::new([
            ['label' => 'Save File'],
        ])->withQuery('SAVE');
        $rendered = $palette->render();

        $this->assertStringContainsString('Save File', $rendered);
    }

    public function testEmptyQueryShowsAllCommands(): void
    {
        $palette = CommandPalette::new([
            ['label' => 'First'],
            ['label' => 'Second'],
        ]);
        $rendered = $palette->render();

        $this->assertStringContainsString('First', $rendered);
        $this->assertStringContainsString('Second', $rendered);
    }

    public function testNoMatchingCommandsShowsEmptyState(): void
    {
        $palette = CommandPalette::new([
            ['label' => 'Save'],
        ])->withQuery('xyz');
        $rendered = $palette->render();

        $this->assertStringContainsString('No commands found', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Selection behavior
    // ═══════════════════════════════════════════════════════════════

    public function testSelectedItemShowsIndicator(): void
    {
        $palette = CommandPalette::new([
            ['label' => 'First'],
            ['label' => 'Second'],
        ])->withSelectedIndex(1);
        $rendered = $palette->render();

        $this->assertStringContainsString('▶', $this->stripAnsi($rendered));
    }

    public function testSwitchingSelection(): void
    {
        $palette = CommandPalette::new([
            ['label' => 'First'],
            ['label' => 'Second'],
        ]);

        $palette2 = $palette->withSelectedIndex(1);
        $rendered = $palette2->render();

        // Second item should be selected with indicator
        $stripped = $this->stripAnsi($rendered);
        $this->assertMatchesRegularExpression('/▶\s+Second/', $stripped);
    }

    public function testQueryResetsSelection(): void
    {
        $original = CommandPalette::new([
            ['label' => 'Save'],
            ['label' => 'Open'],
        ])->withSelectedIndex(1);

        $updated = $original->withQuery('save');

        $this->assertNotSame($original->withSelectedIndex(0)->render(), $updated->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testSelectedColorAddsAnsiCodes(): void
    {
        $palette = CommandPalette::new([['label' => 'Command']])
            ->withSelectedColor(Color::ansi(9));
        $rendered = $palette->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testPaletteColorAddsAnsiCodes(): void
    {
        $palette = CommandPalette::new([['label' => 'Command']])
            ->withPaletteColor(Color::ansi(8));
        $rendered = $palette->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testShortcutColorAddsAnsiCodes(): void
    {
        $palette = CommandPalette::new([
            ['label' => 'Save', 'shortcut' => 'Ctrl+S'],
        ])->withShortcutColor(Color::ansi(8));
        $rendered = $palette->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithQueryReturnsNewInstance(): void
    {
        $original = CommandPalette::new([['label' => 'Command']]);
        $updated = $original->withQuery('test');

        $this->assertNotSame($original, $updated);
    }

    public function testWithSelectedIndexReturnsNewInstance(): void
    {
        $original = CommandPalette::new([['label' => 'Command']]);
        $updated = $original->withSelectedIndex(1);

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithQuery(): void
    {
        $original = CommandPalette::new([['label' => 'Command']]);
        $original->withQuery('test');

        $rendered = $original->render();
        $this->assertStringContainsString('Type a command...', $rendered);
    }

    public function testWithCommandsReturnsNewInstance(): void
    {
        $original = CommandPalette::new([['label' => 'Command']]);
        $updated = $original->withCommands([['label' => 'New']]);

        $this->assertNotSame($original, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // Custom styling
    // ═══════════════════════════════════════════════════════════════

    public function testCustomPlaceholder(): void
    {
        $palette = CommandPalette::new([['label' => 'Command']])
            ->withPlaceholder('Search commands...');
        $rendered = $palette->render();

        $this->assertStringContainsString('Search commands...', $rendered);
    }

    public function testCustomBorderChar(): void
    {
        $palette = CommandPalette::new([['label' => 'Command']])
            ->withBorderChar('=');
        $rendered = $palette->render();

        $this->assertStringContainsString('=', $rendered);
    }

    public function testCustomSelectedChar(): void
    {
        $palette = CommandPalette::new([['label' => 'Command']])
            ->withSelectedChar('*');
        $rendered = $palette->render();

        $this->assertStringContainsString('*', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = CommandPalette::new([['label' => 'Command']]);
        $resized = $original->setSize(40, 20);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsValidDimensions(): void
    {
        $palette = CommandPalette::new([
            ['label' => 'Command'],
        ]);

        [$w, $h] = $palette->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeIncludesAllCommands(): void
    {
        $palette = CommandPalette::new([
            ['label' => 'One'],
            ['label' => 'Two'],
            ['label' => 'Three'],
        ]);

        [, $h] = $palette->getInnerSize();

        $this->assertSame(7, $h); // header + search + top border + 3 commands + bottom border
    }

    public function testGetInnerSizeCalculatesMaxWidth(): void
    {
        $palette = CommandPalette::new([
            ['label' => 'Short'],
            ['label' => 'Much Longer Label'],
        ]);

        [$w, ] = $palette->getInnerSize();

        $this->assertGreaterThanOrEqual(25, $w);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyCommandsRendersWithoutError(): void
    {
        $palette = CommandPalette::new([]);
        $rendered = $palette->render();

        $this->assertNotSame('', $rendered);
    }

    public function testNegativeIndexClampedToZero(): void
    {
        $palette = CommandPalette::new([['label' => 'Command']])
            ->withSelectedIndex(-5);

        $this->assertNotSame('', $palette->render());
    }

    public function testOversizedIndexClampedToLast(): void
    {
        $palette = CommandPalette::new([
            ['label' => 'First'],
            ['label' => 'Second'],
        ])->withSelectedIndex(100);

        $this->assertNotSame('', $palette->render());
    }

    public function testUnicodeLabel(): void
    {
        $palette = CommandPalette::new([['label' => '保存文件']]);
        $rendered = $palette->render();

        $this->assertStringContainsString('保存文件', $rendered);
    }

    public function testUnicodeQueryMatching(): void
    {
        $palette = CommandPalette::new([
            ['label' => '保存文件'],
        ])->withQuery('保存');

        $this->assertNotSame('', $palette->render());
    }

    public function testGetFilteredCommandsReturnsAllWhenEmptyQuery(): void
    {
        $palette = CommandPalette::new([
            ['label' => 'First'],
            ['label' => 'Second'],
        ]);

        $filtered = $palette->getFilteredCommands();

        $this->assertCount(2, $filtered);
    }

    public function testGetFilteredCommandsFiltersCorrectly(): void
    {
        $palette = CommandPalette::new([
            ['label' => 'Save'],
            ['label' => 'Open'],
            ['label' => 'Settings'],
        ])->withQuery('s');

        $filtered = $palette->getFilteredCommands();

        $this->assertCount(2, $filtered);
    }
}
