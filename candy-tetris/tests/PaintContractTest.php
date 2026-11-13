<?php

/**
 * candy-tetris/tests/PaintContractTest.php
 *
 * candy-tetris-027 (and its bug children -024/-041, contract point -055):
 * the overlay machinery lives ONCE. paintBanner, the no-TTY refusal, the
 * sub-floor notice and the cell map used to be character-identical copies
 * across the two renderers — the exact drift-bed the 20-column notice floor
 * ("fixed… nowhere, twice") grew in. The contract pinned here: one home
 * (Support\Paint), zero helper-body copies in either renderer, both frames
 * answering byte-identically off the shared machinery, the notice wrapped
 * to the ACTUAL width, and the refusal copy naming the TTY — never colour.
 */

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\ColorProfileMsg;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;
use SugarCraft\Tetris\Game;
use SugarCraft\Tetris\Renderer;
use SugarCraft\Tetris\Support\Paint;
use SugarCraft\Tetris\VsGame;
use SugarCraft\Tetris\VsRenderer;

final class PaintContractTest extends TestCase
{
    public function testTheOverlayMachineryHasExactlyOneHome(): void
    {
        // candy-tetris-027 acceptance: zero helper-body copies in either
        // renderer. A re-inlined copy in either file reddens this census.
        foreach ([
            __DIR__ . '/../src/Renderer.php',
            __DIR__ . '/../src/VsRenderer.php',
        ] as $file) {
            $src = (string) file_get_contents($file);
            foreach ([
                'function paintBanner',
                'function tooSmallNotice',
                'function wrapPlain',
                'function refusal',
                'function cellMap',
            ] as $ghost) {
                self::assertStringNotContainsString(
                    $ghost,
                    $src,
                    basename($file) . " still defines $ghost — the Paint home must not be forked (candy-tetris-027)",
                );
            }
        }
    }

    public function testBothFramesRefuseIdenticallyWithoutNamingColour(): void
    {
        $solo = Game::start()->update(new ColorProfileMsg(ColorProfile::NoTty))[0]->view();
        $versus = VsGame::start()->update(new ColorProfileMsg(ColorProfile::NoTty))[0]->view();

        self::assertSame($solo, $versus, 'one refusal line answers for both modes');
        self::assertStringNotContainsString("\x1b[", $solo, 'the refusal is zero-SGR');
        // candy-tetris-041: colour is optional, the TTY is not — the copy
        // must never DEMAND what the app does not need. (Naming colour in
        // the "plays without colour" sentence is the cure, not the bug.)
        self::assertStringContainsString('interactive terminal', $solo);
        self::assertStringContainsString('NO_COLOR', $solo);
        $lower = strtolower($solo);
        self::assertStringNotContainsString('colour-capable', $lower);
        self::assertStringNotContainsString('xterm', $lower);
        self::assertStringNotContainsString('term=', $lower);
    }

    public function testTheSubFloorNoticeIsWrappedToTheActualWidthNotAFloorOfTwenty(): void
    {
        // candy-tetris-024: below 20 columns the notice used to overflow
        // the very terminal it was apologising to.
        $surfaces = [
            'cli.too_small' => [Renderer::MIN_COLS, Renderer::MIN_ROWS],
            'cli.too_small_vs' => [VsRenderer::MIN_COLS, VsRenderer::MIN_ROWS],
        ];
        foreach ($surfaces as $key => [$minCols, $minRows]) {
            foreach ([14, 12, 7] as $cols) {
                $notice = Paint::tooSmallNotice($key, $minCols, $minRows, $cols, 10);
                foreach (explode("\n", rtrim($notice, "\n")) as $line) {
                    self::assertLessThanOrEqual(
                        $cols,
                        Width::of($line),
                        "the $key notice must wrap inside $cols columns",
                    );
                }
                self::assertStringContainsString("{$cols}x10", $notice, 'the actual size is named');
                self::assertStringContainsString($minCols . 'x' . $minRows, $notice, 'the needed size is named');
            }
        }
    }

    public function testBothFramesPaintTheSubFloorNoticeFromTheSameWrap(): void
    {
        $solo = Game::start()->mutate(['frameCols' => 30, 'frameRows' => 20])->view();
        // VsGame::mutate is private (the -06 precedent): reflection in,
        // the same private door the VS posture pins already use.
        $mutate = new \ReflectionMethod(VsGame::class, 'mutate');
        $mutate->setAccessible(true);
        $versus = $mutate->invoke(VsGame::start(), ['frameCols' => 30, 'frameRows' => 20])->view();

        self::assertSame(
            Paint::tooSmallNotice('cli.too_small', Renderer::MIN_COLS, Renderer::MIN_ROWS, 30, 20),
            $solo,
            'Renderer delegates its notice to Paint verbatim',
        );
        self::assertSame(
            Paint::tooSmallNotice('cli.too_small_vs', VsRenderer::MIN_COLS, VsRenderer::MIN_ROWS, 30, 20),
            $versus,
            'VsRenderer delegates its notice to Paint verbatim',
        );
        foreach (explode("\n", rtrim($solo, "\n")) as $line) {
            self::assertLessThanOrEqual(30, Width::of($line));
        }
    }

    public function testTheBannerStripeKeepsTheFrameShapeAndCentresItsText(): void
    {
        // candy-tetris-055, stated honestly: full-width stripe across the
        // band (borders covered), row count sacred, outside rows intact.
        // The band position is Board::VISIBLE_ROWS arithmetic, so the
        // fixture IS a full-height body: 22 rows, borders top and bottom.
        $bodyRows = ['╭' . str_repeat('─', 10) . '╮'];
        for ($i = 0; $i < \SugarCraft\Tetris\Board::VISIBLE_ROWS - 2; $i++) {
            $bodyRows[] = '│' . str_repeat('·', 10) . '│';
        }
        $bodyRows[] = '╰' . str_repeat('─', 10) . '╯';
        $body = implode("\n", $bodyRows);

        $out = Paint::banner($body, ['HI']);
        $rows = explode("\n", $out);

        self::assertCount(\SugarCraft\Tetris\Board::VISIBLE_ROWS, $rows, 'an overlay never changes the row count');
        $band = intdiv(\SugarCraft\Tetris\Board::VISIBLE_ROWS - 1, 2);
        $changed = [];
        foreach ($rows as $i => $row) {
            if ($row !== $bodyRows[$i]) {
                $changed[] = $i;
            }
        }
        self::assertSame([$band], $changed, 'exactly the centred band row is covered');
        self::assertStringContainsString('HI', $rows[$band]);
        self::assertStringNotContainsString('│', $rows[$band], 'the stripe covers BOTH border cells — the Paint docblock says so (candy-tetris-055)');
        self::assertSame('HI', trim($rows[$band]), 'the line is centred by leading pad, not fenced in');
    }

    public function testAWideBannerLineIsTruncatedToTheBodyWidthInsteadOfWideningTheFrame(): void
    {
        $bodyRows = ['┌' . str_repeat('─', 4) . '┐'];
        for ($i = 0; $i < \SugarCraft\Tetris\Board::VISIBLE_ROWS - 2; $i++) {
            $bodyRows[] = '│' . str_repeat('·', 4) . '│';
        }
        $bodyRows[] = '└' . str_repeat('─', 4) . '┘';

        $out = Paint::banner(implode("\n", $bodyRows), ['a line far wider than four cells']);

        foreach (explode("\n", $out) as $line) {
            self::assertLessThanOrEqual(6, Width::of($line), 'the frame never grows for a banner');
        }
        $band = intdiv(\SugarCraft\Tetris\Board::VISIBLE_ROWS - 1, 2);
        self::assertSame(6, Width::of(explode("\n", $out)[$band]), 'the over-wide line clips INTO the width');
    }

    public function testTheCellMapHomeServesBothBoardPainters(): void
    {
        // The map is pure geometry; the pin is the census above (no
        // private copies) plus this shape smoke on the shared home.
        $map = Paint::cellMap([[0, 1], [2, 3]]);
        self::assertSame(['0,1' => true, '2,3' => true], $map);
    }
}
