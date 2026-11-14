<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Select;

use SugarCraft\Dashboard\Components\Select\ComboBox;
use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class ComboBoxTest extends TestCase
{
    // Helper to strip ANSI codes for string comparison
    private function stripAnsi(string $output): string
    {
        return preg_replace('/\x1b\[[0-9;]*m/', '', $output);
    }

    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testComboBoxImplementsSizer(): void
    {
        $combobox = ComboBox::new();
        $this->assertInstanceOf(Sizer::class, $combobox);
    }

    public function testComboBoxImplementsItem(): void
    {
        $combobox = ComboBox::new();
        $this->assertInstanceOf(Item::class, $combobox);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $combobox = ComboBox::new();
        $rendered = $combobox->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderShowsPlaceholder(): void
    {
        $combobox = ComboBox::new('Type to search...');
        $rendered = $combobox->render();

        $this->assertStringContainsString('Type to search...', $this->stripAnsi($rendered));
    }

    public function testRenderShowsSearchIcon(): void
    {
        $combobox = ComboBox::new();
        $rendered = $combobox->render();

        $this->assertStringContainsString('🔍', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Options rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderEmptyOptionsNoResults(): void
    {
        $combobox = ComboBox::new('Search...', []);
        $rendered = $combobox->render();

        // Should only show input, no results
        $this->assertStringContainsString('Search...', $rendered);
    }

    public function testRenderShowsAllOptionsWhenNoQuery(): void
    {
        $combobox = ComboBox::new('Search...', [
            ['label' => 'Apple'],
            ['label' => 'Banana'],
            ['label' => 'Cherry'],
        ]);
        $rendered = $combobox->render();

        $this->assertStringContainsString('Apple', $rendered);
        $this->assertStringContainsString('Banana', $rendered);
        $this->assertStringContainsString('Cherry', $rendered);
    }

    public function testQueryFiltersOptions(): void
    {
        $combobox = ComboBox::new('Search...', [
            ['label' => 'Apple'],
            ['label' => 'Banana'],
            ['label' => 'Cherry'],
        ])->withQuery('ap');

        $rendered = $combobox->render();

        $this->assertStringContainsString('Apple', $rendered);
        $this->assertStringNotContainsString('Banana', $rendered);
        $this->assertStringNotContainsString('Cherry', $rendered);
    }

    public function testQueryIsCaseInsensitive(): void
    {
        $combobox = ComboBox::new('Search...', [
            ['label' => 'Apple'],
            ['label' => 'Banana'],
        ])->withQuery('APP');

        $rendered = $combobox->render();

        $this->assertStringContainsString('Apple', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Selection behavior
    // ═══════════════════════════════════════════════════════════════

    public function testSelectedItemShowsIndicator(): void
    {
        $combobox = ComboBox::new('Search...', [
            ['label' => 'Apple'],
            ['label' => 'Banana'],
        ])->withQuery('a')->withSelectedIndex(1);

        $rendered = $combobox->render();

        $this->assertStringContainsString('▶', $this->stripAnsi($rendered));
    }

    public function testSwitchingSelection(): void
    {
        $combobox = ComboBox::new('Search...', [
            ['label' => 'Apple'],
            ['label' => 'Banana'],
        ])->withQuery('a');

        $combobox2 = $combobox->withSelectedIndex(1);
        $rendered = $combobox2->render();

        $this->assertStringContainsString('▶', $this->stripAnsi($rendered));
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testInputColorAddsAnsiCodes(): void
    {
        $combobox = ComboBox::new()->withInputColor(Color::ansi(9));
        $rendered = $combobox->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testMatchColorAddsAnsiCodes(): void
    {
        $combobox = ComboBox::new('Search...', [
            ['label' => 'Apple'],
        ])->withQuery('ap')->withMatchColor(Color::ansi(9));

        $rendered = $combobox->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testSelectedColorAddsAnsiCodes(): void
    {
        $combobox = ComboBox::new('Search...', [
            ['label' => 'Apple'],
        ])->withQuery('ap')->withSelectedColor(Color::ansi(9));

        $rendered = $combobox->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithQueryReturnsNewInstance(): void
    {
        $original = ComboBox::new('Search...', [['label' => 'Item']]);
        $updated = $original->withQuery('ite');

        $this->assertNotSame($original, $updated);
    }

    public function testWithOptionsReturnsNewInstance(): void
    {
        $original = ComboBox::new('Search...', []);
        $updated = $original->withOptions([['label' => 'New Item']]);

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithQuery(): void
    {
        $original = ComboBox::new('Search...', [
            ['label' => 'Apple'],
            ['label' => 'Banana'],
        ]);
        $original->withQuery('app');

        $rendered = $original->render();
        // Original still shows all options since no query was set
        $this->assertStringContainsString('Apple', $rendered);
        $this->assertStringContainsString('Banana', $rendered);
    }

    public function testWithQueryResetsSelection(): void
    {
        $original = ComboBox::new('Search...', [
            ['label' => 'Apple'],
            ['label' => 'Banana'],
        ])->withSelectedIndex(1);

        // Query change should reset selection to 0
        $updated = $original->withQuery('a');
        $this->assertNotSame('', $updated->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = ComboBox::new();
        $resized = $original->setSize(20, 5);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $combobox = ComboBox::new('Search...', [
            ['label' => 'Item'],
        ]);
        [$w, $h] = $combobox->innerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(2, $h); // 1 input + 1 result
    }

    public function testGetInnerSizeWithFilteredResults(): void
    {
        $combobox = ComboBox::new('Search...', [
            ['label' => 'Apple'],
            ['label' => 'Banana'],
            ['label' => 'Cherry'],
        ])->withQuery('a');

        [$w, $h] = $combobox->innerSize();

        $this->assertSame(2, $h); // 1 input + 1 filtered result (Apple)
    }

    public function testGetInnerSizeNoQueryShowsAllOptions(): void
    {
        $combobox = ComboBox::new('Search...', [
            ['label' => 'Apple'],
            ['label' => 'Banana'],
        ]);

        [$w, $h] = $combobox->innerSize();

        $this->assertSame(3, $h); // 1 input + 2 results
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testNoMatchShowsNoResults(): void
    {
        $combobox = ComboBox::new('Search...', [
            ['label' => 'Apple'],
            ['label' => 'Banana'],
        ])->withQuery('xyz');

        $rendered = $combobox->render();

        // Should show input (query xyz) but no results
        $stripped = $this->stripAnsi($rendered);
        $this->assertStringContainsString('xyz', $stripped);
        $this->assertStringNotContainsString('Apple', $stripped);
        $this->assertStringNotContainsString('Banana', $stripped);
    }

    public function testUnicodeQuery(): void
    {
        $combobox = ComboBox::new('搜索...', [
            ['label' => '苹果'],
            ['label' => '香蕉'],
        ])->withQuery('苹');

        $rendered = $combobox->render();

        $this->assertStringContainsString('苹果', $rendered);
    }

    public function testUnicodeOptions(): void
    {
        $combobox = ComboBox::new('Search...', [
            ['label' => '日本語'],
            ['label' => '中文'],
        ]);

        $rendered = $combobox->render();

        $this->assertStringContainsString('日本語', $rendered);
        $this->assertStringContainsString('中文', $rendered);
    }

    public function testWithOptionsClampsSelectedIndex(): void
    {
        $original = ComboBox::new('Search...', [
            ['label' => 'Apple'],
            ['label' => 'Banana'],
        ])->withSelectedIndex(1);

        $updated = $original->withOptions([['label' => 'Only']]);
        $this->assertNotSame('', $updated->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Allocation windowing (finding sugar-dashboard-051)
    // ═══════════════════════════════════════════════════════════════

    /** @return list<array{label: string}> */
    private function manyOptions(int $count): array
    {
        $options = [];
        for ($i = 0; $i < $count; $i++) {
            $options[] = ['label' => sprintf('Res %03d', $i)];
        }
        return $options;
    }

    public function testUnallocatedComboboxPaintsEveryResult(): void
    {
        $rendered = ComboBox::new('Search...', $this->manyOptions(50))->render();

        $this->assertCount(51, explode("\n", $rendered)); // input + 50
    }

    public function testResultsAreWindowedToHeightMinusTheInputRow(): void
    {
        $rendered = ComboBox::new('Search...', $this->manyOptions(50))
            ->withSelectedIndex(40)
            ->setSize(0, 6)
            ->render();

        $rows = explode("\n", $rendered);
        $this->assertCount(6, $rows);
        $this->assertStringContainsString('🔍', $rows[0]);
        $this->assertStringContainsString('Res 040', $rendered);
        $this->assertStringNotContainsString('Res 000', $rendered);
    }

    public function testHeightOfOneLeavesOnlyTheInputRow(): void
    {
        $rendered = ComboBox::new('Search...', $this->manyOptions(50))
            ->setSize(0, 1)
            ->render();

        $this->assertCount(1, explode("\n", $rendered));
        $this->assertStringContainsString('Search...', $rendered);
    }

    public function testInnerSizeReportsTheWindowedHeight(): void
    {
        [, $natural] = ComboBox::new('Search...', $this->manyOptions(50))->innerSize();
        $this->assertSame(51, $natural);

        [, $windowed] = ComboBox::new('Search...', $this->manyOptions(50))->setSize(0, 6)->innerSize();
        $this->assertSame(6, $windowed);
    }

    public function testWithersCarryTheAllocation(): void
    {
        $sized = ComboBox::new('Search...', $this->manyOptions(50))
            ->withSelectedIndex(40)
            ->setSize(0, 6);

        foreach (
            [
                fn(ComboBox $c) => $c->withPlaceholder('Find...'),
                fn(ComboBox $c) => $c->withInputColor(null),
                fn(ComboBox $c) => $c->withMatchColor(null),
                fn(ComboBox $c) => $c->withSelectedColor(null),
            ] as $wither
        ) {
            $this->assertCount(6, explode("\n", $wither($sized)->render()));
        }
    }

    public function testAllocatedComboboxEllipsisesLongContentInsteadOfOverflowing(): void
    {
        // The no-compress cliff (finding 046): render used to paint natural
        // content width regardless of setSize, so the results box grew past
        // whatever the layout handed it.
        $combobox = ComboBox::new('Search for a fruit please', [
            ['label' => 'Extremely Long Option Name That Will Not Fit'],
            ['label' => 'Nope, This One Is Even Longer Than The First'],
        ])->setSize(12, 5);

        foreach (explode("\n", $combobox->render()) as $line) {
            $plain = $this->stripAnsi($line);
            $this->assertLessThanOrEqual(12, mb_strlen($plain), 'line escapes the 12-cell allocation: ' . $plain);
        }

        $this->assertStringContainsString('…', $this->stripAnsi($combobox->render()));
    }

    public function testUnallocatedComboboxKeepsItsNaturalWidth(): void
    {
        // No setSize, no clipping - innerSize is the contract and Boxer asks.
        $long = 'Extremely Long Option Name That Will Not Fit';
        $combobox = ComboBox::new('Find...', [['label' => $long]]);

        $rendered = $combobox->render();

        $this->assertStringContainsString($long, $this->stripAnsi($rendered));
        $this->assertStringNotContainsString('…', $this->stripAnsi($rendered));
    }

    public function testAllocatedComboboxQueryLineStaysInsideBudget(): void
    {
        $combobox = ComboBox::new('Find...', [
            ['label' => 'Ant'],
        ])->withQuery('a really long typed query nobody can see anyway')->setSize(10, 3);

        $input = explode("\n", $combobox->render())[0];

        $this->assertLessThanOrEqual(10, mb_strlen($this->stripAnsi($input)));
    }
}
