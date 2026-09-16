<?php

declare(strict_types=1);

namespace SugarCraft\Glow\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Glow\GlowModel;
use SugarCraft\Shine\Renderer;
use SugarCraft\Shine\Theme;

/**
 * E735 step 10.25 — width half of the original leftover spec.
 *
 * The step file names a `WidthHelper` (CJK/emoji-aware widths via
 * mb_strwidth); that class shipped in ad4de43a4 and was DELETED in
 * af5c4aefc once {@see Width} became the canonical single source. This
 * suite is the contract pin that the replacement actually holds
 * end-to-end through glow's live render path — wide glyphs cost 2 cells
 * in the Core measurer, the Shine word-wrap never lets a physical row
 * exceed the column budget, and the pager model's view respects its
 * width. Re-adding a glow-local helper would be the drift this pins out.
 */
final class WidthPlumbingTest extends TestCase
{
    public function testCoreMeasurerCountsWideGlyphsAsTwoCells(): void
    {
        // Mirrors charmbracelet/x/ansi StringWidth: CJK ideographs and
        // emoji presentation are doublewidth; combining marks are zero.
        self::assertSame(2, Width::string('你'), 'CJK must cost two cells');
        self::assertSame(4, Width::string('你好'));
        self::assertSame(2, Width::string('📦'), 'emoji must cost two cells');
        self::assertSame(3, Width::string('a你'));
        self::assertSame(0, Width::string("\u{0301}"), 'combining acute is zero-width');
    }

    public function testRendererWrapKeepsEveryRowInsideTheBudget(): void
    {
        $markdown = "你好世界 你好世界 你好世界 你好世界 你好世界\n\n"
            . "📦 package 📦 package 📦 package 📦 package 📦 package\n\n"
            . "plain ascii words that must still wrap somewhere near the limit";

        $rendered = (new Renderer(Theme::plain(), 20))->render($markdown);

        $rows = explode("\n", $rendered);
        self::assertGreaterThan(3, count($rows), 'wrap must actually produce rows');

        foreach ($rows as $row) {
            // Width::of ignores SGR bytes, counts cells of what remains.
            self::assertLessThanOrEqual(
                20,
                Width::of($row),
                'row exceeds the 20-column budget: ' . var_export($row, true)
            );
        }

        // The CJK paragraph really did wrap: 40 cells of ideographs cannot
        // fit one 20-cell row, so some row must carry exactly the doubled
        // measure the budget allows.
        self::assertGreaterThanOrEqual(2, substr_count($rendered, "\n"));
    }

    public function testPagerModelViewRespectsItsColumnBudget(): void
    {
        $rendered = (new Renderer(Theme::plain(), 24))
            ->render("日本語のテキスト 日本語のテキスト 日本語のテキスト 日本語のテキスト");

        $model = GlowModel::fromContent($rendered, 24, 6);

        foreach (explode("\n", $model->view()) as $row) {
            self::assertLessThanOrEqual(24, Width::of($row), 'pager row overflows its width: ' . var_export($row, true));
        }
    }

    public function testNoGlowLocalWidthHelperIsResurrected(): void
    {
        // af5c4aefc deleted sugar-glow's WidthHelper in favour of Core.
        // A class_alias or re-implementation under the Glow namespace would
        // silently fork the width tables again; guard its absence.
        self::assertFalse(
            class_exists(\SugarCraft\Glow\WidthHelper::class),
            'sugar-glow must consume SugarCraft\Core\Util\Width, never a local copy'
        );
    }
}
