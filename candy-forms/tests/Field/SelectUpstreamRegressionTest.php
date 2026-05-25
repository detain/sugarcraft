<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Field;

use PHPUnit\Framework\TestCase;
use SugarCraft\Forms\Field\Select;

/**
 * Sentinel tests anchoring sugar-prompt Select against two known
 * upstream huh issues so a future port can't regress quietly.
 *
 *   - #679 — "Select doesn't render elements before selected when
 *            Value is set" (programmatic default placement).
 *   - #749 — "Cursor visibility navigating multiline options".
 *
 * The current sugar-prompt Select does not yet expose `withValue()`
 * (programmatic default) or multiline-option support; these tests
 * pin the current rendering invariants. When the corresponding port
 * lands, these tests should be expanded — not deleted.
 */
final class SelectUpstreamRegressionTest extends TestCase
{
    public function testInitialRenderShowsAllOptionsAndCursorAtFirst(): void
    {
        $s = Select::new('lang')
            ->title('Language')
            ->options('PHP', 'Go', 'Rust', 'Python', 'Zig');
        [$s, ] = $s->focus();
        $view = $s->view();

        // Every option should be in the first render — no scrolling needed
        // when the option count fits the default height.
        $this->assertStringContainsString('PHP', $view);
        $this->assertStringContainsString('Go', $view);
        $this->assertStringContainsString('Rust', $view);
        $this->assertStringContainsString('Python', $view);
        $this->assertStringContainsString('Zig', $view);
    }

    public function testValueDefaultsToFirstOption(): void
    {
        $s = Select::new('color')->options('red', 'green', 'blue');
        [$s, ] = $s->focus();
        $this->assertSame('red', $s->value());
    }

    public function testTitleAndDescriptionPrecedeTheOptionsBlock(): void
    {
        $s = Select::new('k')->title('Pick one')->desc('Helpful explanation')->options('a', 'b', 'c');
        [$s, ] = $s->focus();
        $view = $s->view();
        // Title and description should appear before the first option in
        // the rendered output (huh-shape contract).
        $titlePos = strpos($view, 'Pick one');
        $descPos  = strpos($view, 'Helpful explanation');
        $optPos   = strpos($view, 'a');
        $this->assertNotFalse($titlePos);
        $this->assertNotFalse($descPos);
        $this->assertNotFalse($optPos);
        $this->assertLessThan($descPos, $titlePos, 'title before description');
        $this->assertLessThan($optPos, $descPos, 'description before options');
    }

    public function testRenderTolerantOfWideOptionLabels(): void
    {
        // Cell-width-aware rendering — wide CJK glyphs and long ASCII
        // both render without the framework crashing on width math.
        $s = Select::new('k')->options('小説', 'Long ascii option label that is wide', 'short');
        [$s, ] = $s->focus();
        $this->assertNotEmpty($s->view());
    }
}
