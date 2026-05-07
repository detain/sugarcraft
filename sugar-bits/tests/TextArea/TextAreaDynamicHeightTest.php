<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\TextArea;

use PHPUnit\Framework\TestCase;
use SugarCraft\Bits\TextArea\TextArea;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * Tests for {@see TextArea::withDynamic()} — render-as-tall-as-content
 * mode mirroring upstream Bubbles #910.
 */
final class TextAreaDynamicHeightTest extends TestCase
{
    private function focused(TextArea $t): TextArea
    {
        [$t, ] = $t->focus();
        return $t;
    }

    private function type(TextArea $t, string $text): TextArea
    {
        foreach (mb_str_split($text) as $ch) {
            if ($ch === "\n") {
                [$t, ] = $t->update(new KeyMsg(KeyType::Enter));
            } else {
                [$t, ] = $t->update(new KeyMsg(KeyType::Char, $ch));
            }
            assert($t instanceof TextArea);
        }
        return $t;
    }

    public function testEffectiveHeightDefaultsToFixedHeightWhenStatic(): void
    {
        $t = TextArea::new()->withHeight(5);
        $this->assertSame(5, $t->effectiveHeight());
    }

    public function testEffectiveHeightInDynamicModeReflectsContent(): void
    {
        $t = $this->focused(TextArea::new()->withDynamic());
        // Empty content -> 1 row (minimum).
        $this->assertSame(1, $t->effectiveHeight());

        $t = $this->type($t, "alpha\nbeta\ngamma");
        $this->assertSame(3, $t->effectiveHeight());
    }

    public function testDynamicModeCapsAtMaxHeight(): void
    {
        $t = $this->focused(
            TextArea::new()->withDynamic()->withMaxHeight(2),
        );
        $t = $this->type($t, "1\n2\n3\n4\n5");
        $this->assertSame(2, $t->effectiveHeight());
    }

    public function testDynamicModeMinimumHeightIsOne(): void
    {
        $t = TextArea::new()->withDynamic();
        $this->assertSame(1, $t->effectiveHeight());
    }

    public function testStaticModePadsWithEndOfBufferGlyph(): void
    {
        // Static height=4 with one line of content; expect 3 trailing ~ rows.
        $t = $this->focused(TextArea::new()->withHeight(4));
        $t = $this->type($t, 'hello');
        $view = $t->view();
        // Default end-of-buffer char = ~
        $this->assertSame(3, substr_count($view, "\n~"));
    }

    public function testDynamicModeDoesNotEmitEndOfBufferFiller(): void
    {
        $t = $this->focused(TextArea::new()->withDynamic());
        $t = $this->type($t, 'hello');
        $view = $t->view();
        $this->assertStringNotContainsString('~', $view);
        $rows = explode("\n", $view);
        $this->assertCount(1, $rows, 'single-line content should render as a single row');
    }

    public function testDynamicModeGrowsAsContentIsAdded(): void
    {
        $t = $this->focused(TextArea::new()->withDynamic());
        $t = $this->type($t, 'one');
        $this->assertSame(1, $t->effectiveHeight());
        [$t, ] = $t->update(new KeyMsg(KeyType::Enter));
        assert($t instanceof TextArea);
        $this->assertSame(2, $t->effectiveHeight());
        $t = $this->type($t, 'two');
        $this->assertSame(2, $t->effectiveHeight());
    }

    public function testToggleOffReturnsToFixedHeight(): void
    {
        $t = TextArea::new()->withHeight(5)->withDynamic();
        $this->assertSame(1, $t->effectiveHeight()); // dynamic, empty
        $t = $t->withDynamic(false);
        $this->assertSame(5, $t->effectiveHeight());
    }

    public function testDynamicShortAliasMatchesWithDynamic(): void
    {
        $t1 = TextArea::new()->withDynamic();
        $t2 = TextArea::new()->dynamic();
        $this->assertSame($t1->view(), $t2->view());
        $this->assertSame($t1->effectiveHeight(), $t2->effectiveHeight());
    }
}
