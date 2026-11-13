<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Tree;

use PHPUnit\Framework\TestCase;
use SugarCraft\Widgets\Tree\Node;
use SugarCraft\Widgets\Tree\Tree;

/**
 * Pins for the 2026-11 review findings on Tree: C3/M20 (labels and the
 * five chrome knobs took raw control bytes / forged zone sentinels) and
 * M15 (the public readonly knobs had no immutable setter at all).
 */
final class TreeReviewPinsTest extends TestCase
{
    public function testForgedSentinelInLabelCannotReachTheFrame(): void
    {
        $tree = Tree::new(new Node("root\u{E000}zone\u{E001}"))
            ->withStyle(collapsedGlyph: '- ');
        $view = $tree->view();
        self::assertStringNotContainsString("\u{E000}", $view);
        self::assertStringNotContainsString("\u{E001}", $view);
        self::assertStringContainsString('rootzone', $view);
    }

    public function testWithStyleOverridesChromeAndSanitisesIt(): void
    {
        $tree = Tree::new(new Node('a'));
        $styled = $tree->withStyle(
            cursorPrefix: ">\u{1B}[0m ",
            leafGlyph: "L\u{E000}",
        );
        $focused = $styled->focus();

        // controlChars folds the ESC payload; the sentinel is swept.
        self::assertStringNotContainsString("\u{1B}", $styled->cursorPrefix);
        self::assertStringNotContainsString("\u{E000}", $styled->leafGlyph);
        self::assertStringContainsString('L', $styled->leafGlyph);
        // Untouched knobs carry over; the original instance is unchanged.
        self::assertSame($tree->expandedGlyph, $styled->expandedGlyph);
        self::assertSame('> ', $tree->cursorPrefix);
        self::assertStringContainsString($styled->cursorPrefix, $focused->view());
    }
}
