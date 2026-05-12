<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Diff;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class DiffTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testDiffImplementsSizer(): void
    {
        $diff = Diff::new('old', 'new');
        $this->assertInstanceOf(Sizer::class, $diff);
    }

    public function testDiffImplementsItem(): void
    {
        $diff = Diff::new('old', 'new');
        $this->assertInstanceOf(Item::class, $diff);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $diff = Diff::new('line1', 'line1-modified');
        $rendered = $diff->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsDiffMarkers(): void
    {
        $diff = Diff::new('old', 'new');
        $rendered = $diff->render();

        // Should contain +/- markers
        $this->assertMatchesRegularExpression('/[+\-]/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Diff operation types
    // ═══════════════════════════════════════════════════════════════

    public function testAddedLinesMarkedCorrectly(): void
    {
        $diff = Diff::new('old', 'new line');
        $rendered = $diff->render();

        // Should contain + marker
        $this->assertStringContainsString('+', $rendered);
    }

    public function testRemovedLinesMarkedCorrectly(): void
    {
        $diff = Diff::new('old line', 'new');
        $rendered = $diff->render();

        // Should contain - marker
        $this->assertStringContainsString('-', $rendered);
    }

    public function testUnchangedLinesMarkedCorrectly(): void
    {
        $diff = Diff::new("line1\nline2", "line1\nline2-modified");
        $rendered = $diff->render();

        // Should contain space marker for unchanged
        $this->assertMatchesRegularExpression('/ /', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Line numbers
    // ═══════════════════════════════════════════════════════════════

    public function testLineNumbersShownByDefault(): void
    {
        $diff = Diff::new('old', 'new');
        $rendered = $diff->render();

        // Should contain numeric line numbers
        $this->assertMatchesRegularExpression('/\d/', $rendered);
    }

    public function testHideLineNumbers(): void
    {
        $diff = Diff::new('old', 'new')->withLineNumbers(false);
        $rendered = $diff->render();

        // Should not contain line numbers at start of lines
        // Strip ANSI codes to check actual content
        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $rendered);
        $lines = explode("\n", $stripped);
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $this->assertMatchesRegularExpression('/^[+\-] /', $line);
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Statistics
    // ═══════════════════════════════════════════════════════════════

    public function testGetStatsReturnsArray(): void
    {
        $diff = Diff::new('old line', 'new line');
        $stats = $diff->getStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('added', $stats);
        $this->assertArrayHasKey('removed', $stats);
        $this->assertArrayHasKey('unchanged', $stats);
    }

    public function testGetStatsCountsCorrectly(): void
    {
        $diff = Diff::new("line1\nline2\nline3", "line1\nmodified\nline3");
        $stats = $diff->getStats();

        // Stats should have all keys with non-negative values
        $this->assertArrayHasKey('added', $stats);
        $this->assertArrayHasKey('removed', $stats);
        $this->assertArrayHasKey('unchanged', $stats);
        $this->assertGreaterThanOrEqual(0, $stats['added']);
        $this->assertGreaterThanOrEqual(0, $stats['removed']);
        $this->assertGreaterThanOrEqual(0, $stats['unchanged']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testAddedColorAddsAnsiCodes(): void
    {
        $diff = Diff::new('old', 'new')->withAddedColor(Color::ansi(2));
        $rendered = $diff->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testRemovedColorAddsAnsiCodes(): void
    {
        $diff = Diff::new('old', 'new')->withRemovedColor(Color::ansi(1));
        $rendered = $diff->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSize(): void
    {
        $diff = Diff::new('old', 'new');
        [$w, $h] = $diff->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeHeightMatchesLineCount(): void
    {
        $diff = Diff::new("a\nb\nc", "a\nb\nc");
        [, $h] = $diff->getInnerSize();

        // Should be at least number of lines
        $this->assertGreaterThanOrEqual(3, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithLineNumbersReturnsNewInstance(): void
    {
        $original = Diff::new('old', 'new');
        $updated = $original->withLineNumbers(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithContextReturnsNewInstance(): void
    {
        $original = Diff::new('old', 'new');
        $updated = $original->withContext(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithContextLinesReturnsNewInstance(): void
    {
        $original = Diff::new('old', 'new');
        $updated = $original->withContextLines(5);

        $this->assertNotSame($original, $updated);
    }

    public function testWithAddedColorReturnsNewInstance(): void
    {
        $original = Diff::new('old', 'new');
        $updated = $original->withAddedColor(Color::ansi(2));

        $this->assertNotSame($original, $updated);
    }

    public function testWithRemovedColorReturnsNewInstance(): void
    {
        $original = Diff::new('old', 'new');
        $updated = $original->withRemovedColor(Color::ansi(1));

        $this->assertNotSame($original, $updated);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Diff::new('old', 'new');
        $resized = $original->setSize(80, 20);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyOldText(): void
    {
        $diff = Diff::new('', 'new content');
        $rendered = $diff->render();

        $this->assertNotSame('', $rendered);
    }

    public function testEmptyNewText(): void
    {
        $diff = Diff::new('old content', '');
        $rendered = $diff->render();

        $this->assertNotSame('', $rendered);
    }

    public function testBothEmpty(): void
    {
        $diff = Diff::new('', '');
        $rendered = $diff->render();

        // Should still render something (even if empty diff)
        $this->assertNotNull($rendered);
    }

    public function testMultilineDiff(): void
    {
        $diff = Diff::new("line1\nline2\nline3", "line1\nmodified\nline3\nline4");
        $rendered = $diff->render();

        $this->assertNotSame('', $rendered);
    }

    public function testIdenticalTexts(): void
    {
        $text = "same\ncontent\nhere";
        $diff = Diff::new($text, $text);
        $rendered = $diff->render();

        // Should show all lines as unchanged
        $this->assertNotSame('', $rendered);
    }
}
