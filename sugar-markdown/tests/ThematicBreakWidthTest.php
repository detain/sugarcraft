<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Markdown\Renderer;
use SugarCraft\Markdown\Theme;

/**
 * SM-S47 (sugar-markdown-064 / TUI-002): the thematic break used to draw
 * the theme's flat horizontalRuleLength cells regardless of the wrap width,
 * so the 40-cell default overshot a wrap-20 document (measured 42 visible
 * cells inside a blockquote once the prefix was added). Under wrapping the
 * rule now bows to the block stack's available width; the flat top-level
 * behaviour with no wrap width stays byte-compatible for the goldens.
 */
final class ThematicBreakWidthTest extends TestCase
{
    private const GLYPH = '─';

    public function testNoWrapKeepsTheFlatThemeLength(): void
    {
        $out = (new Renderer(Theme::ascii()))->render('---');

        $this->assertSame(40, self::longestRuleRun($out));
        $this->assertSame(40, substr_count($out, self::GLYPH));
    }

    public function testWrapActiveClampsTheRuleToTheWrapWidth(): void
    {
        $out = (new Renderer(Theme::ascii(), 20))->render('---');

        $this->assertSame(20, self::longestRuleRun($out));
        $this->assertSame(20, substr_count($out, self::GLYPH));
    }

    public function testNestedRuleStaysWithinTheAvailableWidth(): void
    {
        // BlockStack::availableWidth charges the blockquote one margin pair
        // at wrap 20, leaving 18 cells for the rule itself; before SM-S47
        // this line ran the flat 40-cell rule plus the marker prefix — a
        // 42-cell overshoot of the 20-column frame (measured in the
        // finding: wrap-30 rendered 42). The whole-run equality is what
        // makes this probe bite: the pre-fix tree merely wrapped the
        // oversized 40 into chunks, it never produced a single 18.
        $out = (new Renderer(Theme::ascii(), 20))->render("> ---");

        $this->assertSame(18, self::longestRuleRun($out));
        $this->assertSame(18, substr_count($out, self::GLYPH));
    }

    public function testCustomShorterRuleLengthIsNotStretched(): void
    {
        $base = Theme::ascii();
        // horizontalRuleLength is a constructor value, not a style slot:
        // build the theme directly to exercise the min() floor leg.
        $short = new Theme(
            heading1: $base->heading1, heading2: $base->heading2, heading3: $base->heading3,
            heading4: $base->heading4, heading5: $base->heading5, heading6: $base->heading6,
            paragraph: $base->paragraph, bold: $base->bold, italic: $base->italic,
            code: $base->code, codeBlock: $base->codeBlock, link: $base->link,
            blockquote: $base->blockquote, listMarker: $base->listMarker, rule: $base->rule,
            horizontalRuleGlyph: self::GLYPH, horizontalRuleLength: 8,
        );

        $this->assertSame(8, self::longestRuleRun((new Renderer($short, 20))->render('---')));
        $this->assertSame(8, self::longestRuleRun((new Renderer($short))->render('---')));
    }

    private static function longestRuleRun(string $out): int
    {
        if (!preg_match_all('/(' . self::GLYPH . ')+/u', $out, $m)) {
            return 0;
        }
        $longest = 0;
        foreach ($m[0] as $run) {
            $longest = max($longest, substr_count($run, self::GLYPH));
        }
        return $longest;
    }
}
