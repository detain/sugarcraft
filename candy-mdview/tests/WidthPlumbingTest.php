<?php

declare(strict_types=1);

namespace SugarCraft\MdView\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\MdView\MdViewModel;
use SugarCraft\MdView\RenderCommand;
use SugarCraft\Markdown\Renderer;
use SugarCraft\Markdown\Theme;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * Width plumbing pins: the pager must measure with the canonical helper.
 *
 * The step file names a `WidthHelper` (CJK/emoji-aware widths via
 * mb_strwidth); that class was DELETED once {@see Width} became the canonical single
 * source. This
 * suite is the contract pin that the replacement actually holds
 * end-to-end through the viewer's live render path — wide glyphs cost 2 cells
 * in the Core measurer, the sugar-markdown word-wrap never lets a physical row
 * exceed the column budget, and the pager model's view respects its
 * width. Re-adding an mdview-local helper would be the drift this pins out.
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

        $model = MdViewModel::fromContent($rendered, 24, 6);

        foreach (explode("\n", $model->view()) as $row) {
            self::assertLessThanOrEqual(24, Width::of($row), 'pager row overflows its width: ' . var_export($row, true));
        }
    }

    public function testNoMdViewLocalWidthHelperIsResurrected(): void
    {
        // candy-mdview once had a local WidthHelper; Core\Util\Width replaced it.
        // A class_alias or re-implementation under the MdView namespace would
        // silently fork the width tables again; guard its absence.
        self::assertFalse(
            class_exists(\SugarCraft\MdView\WidthHelper::class),
            'candy-mdview must consume SugarCraft\Core\Util\Width, never a local copy'
        );
    }


    /**
     * The canonical sugar-forms Viewport is the only viewport this app may
     * touch. The deprecated sugar-widgets facade was removed with the
     * dependency declaration fix and must not creep back into shipped code.
     */
    public function testNoShippedCodeImportsTheDeprecatedWidgetsViewport(): void
    {
        $root = dirname(__DIR__);
        $files = array_merge(
            glob($root . '/src/*.php') ?: [],
            [$root . '/bin/candy-mdview'],
        );
        $this->assertGreaterThan(0, count($files));
        foreach ($files as $file) {
            $this->assertStringNotContainsString(
                'SugarCraft\Widgets',
                (string) file_get_contents($file),
                $file . ' must use the canonical SugarCraft\Forms Viewport',
            );
        }
    }

    public function testUnsetWidthResolvesToNullWhileZeroStaysNoWrap(): void
    {
        // The three width states (finding 090) must survive parsing:
        // omitted = null (auto), explicit 0 = no-wrap, explicit N = pinned.
        // The old single-state default (0) collapsed unset and no-wrap into
        // one meaning, which is exactly what made auto impossible.
        $definition = (new RenderCommand())->getDefinition();
        $resolve    = new \ReflectionMethod(RenderCommand::class, 'resolveWidth');
        $empty      = \SugarCraft\MdView\Config::empty();

        self::assertNull($resolve->invoke(null, new ArrayInput([], $definition), $empty), 'omitted --width must read as auto');
        self::assertSame(0, $resolve->invoke(null, new ArrayInput(['--width' => '0'], $definition), $empty), 'explicit 0 must stay 0');
        self::assertSame(42, $resolve->invoke(null, new ArrayInput(['--width' => '42'], $definition), $empty));
    }

    public function testWindowSizeReflowRendersAtTheLiveColumnCount(): void
    {
        // Finding 014: the README promised resize reflow since day one;
        // this is the closure seam that makes the promise real.
        $calls  = [];
        $reflow = function (int $cols) use (&$calls): string {
            $calls[] = $cols;

            return "W{$cols}\nsecond";
        };
        $model = MdViewModel::fromContent("seed\nlines\nmore", 80, 10)->withReflow($reflow);

        [$grown, $cmd] = $model->update(new WindowSizeMsg(50, 10));

        self::assertSame([50], $calls, 'the reflow closure must run once with the new columns');
        self::assertSame(['W50', 'second'], $grown->viewport->lines, 'the document text must be the reflowed render');
        self::assertSame(50, $grown->viewport->width);
        self::assertNull($cmd, 'a resize alone arms no command');

        // A height-only change cannot move the wrap column — no re-render.
        $model->update(new WindowSizeMsg(80, 6));
        self::assertSame([50], $calls, 'rows-only resizes must not pay the re-render cost');

        // The geometry identity short-circuit still wins (startup duplicate).
        [$same] = $model->update(new WindowSizeMsg(80, 10));
        self::assertSame($model, $same);
    }

    public function testPinnedWidthResizeMovesTheClipWindowOnly(): void
    {
        // With --width given, $reflowAt is never armed, and the user's
        // chosen column count must survive every resize untouched.
        $model = MdViewModel::fromContent("a\nb\nc", 80, 10);

        [$grown] = $model->update(new WindowSizeMsg(40, 12));

        self::assertSame(['a', 'b', 'c'], $grown->viewport->lines, 'pinned text must not be re-rendered on resize');
        self::assertSame(40, $grown->viewport->width);
        self::assertSame(12, $grown->viewport->height);
    }
}
