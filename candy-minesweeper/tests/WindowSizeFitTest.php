<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Minesweeper\Difficulty;
use SugarCraft\Minesweeper\Game;
use SugarCraft\Minesweeper\Renderer;

/**
 * Terminal fit (candy-minesweeper-001, critical): the Model must consume
 * WindowSizeMsg (startup + SIGWINCH) instead of discarding it, the frame
 * budget must be derived from the real board shape, and a terminal below
 * that budget must show a safe plain notice — never a clipped board.
 */
final class WindowSizeFitTest extends TestCase
{
    private const BOARD_EDGE = ['╭', '╮', '╰', '╯'];

    private string $locale = 'en';

    protected function setUp(): void
    {
        $this->locale = T::locale();
        T::setLocale('en');
    }

    protected function tearDown(): void
    {
        T::setLocale($this->locale);
    }

    public function testWindowSizeMsgSeedsTheLiveFrame(): void
    {
        $game = Game::withDifficulty(Difficulty::Easy);
        self::assertSame(80, $game->frameCols);
        self::assertSame(24, $game->frameRows);

        // The startup dispatch repeats the seeded size — identity, no churn.
        [$same] = $game->update(new WindowSizeMsg(80, 24));
        self::assertSame($game, $same);

        [$grown] = $game->update(new WindowSizeMsg(100, 40));
        self::assertSame(100, $grown->frameCols);
        self::assertSame(40, $grown->frameRows);
    }

    public function testRequiredRowsCountsBoardPlusFooterBudget(): void
    {
        // Easy is 9 rows tall: 9 + border(2) + status(1) + help(4) + spare(1) = 17.
        $easy = Game::withDifficulty(Difficulty::Easy);
        self::assertSame(17, $easy->requiredRows());

        // A riding toast (the boot resume hint) adds one line.
        $toasted = Game::withDifficulty(Difficulty::Easy, notice: 'toast');
        self::assertSame(18, $toasted->requiredRows());

        // Expert (30×16) is the board the README claimed "fits 80×24 exactly" —
        // it does, but only until the resume hint exists: 16+8 = 24, +1 = 25.
        $expert = Game::withDifficulty(Difficulty::Expert);
        self::assertSame(24, $expert->requiredRows());
        self::assertSame(25, Game::withDifficulty(Difficulty::Expert, notice: 'resume')->requiredRows());
    }

    public function testShrinkBelowBudgetPaintsNoticeNotBoard(): void
    {
        $game = Game::withDifficulty(Difficulty::Expert)->update(new WindowSizeMsg(80, 40))[0];
        self::assertFalse($game->tooSmall());
        self::assertStringContainsString(self::BOARD_EDGE[0], Renderer::render($game));

        [$small] = $game->update(new WindowSizeMsg(80, 10));
        self::assertTrue($small->tooSmall());
        $frame = Renderer::render($small);
        foreach (self::BOARD_EDGE as $edge) {
            self::assertStringNotContainsString($edge, $frame, 'notice screen must not paint the border');
        }
        self::assertStringNotContainsString('mines:', $frame, 'notice screen must not paint the status line');
        self::assertStringContainsString('needs a terminal', $frame);
        self::assertStringContainsString('24', $frame, 'the notice names the row budget');
        self::assertStringContainsString('10', $frame, 'and the rows actually present');

        // Growing back restores the real frame with no other state moved.
        [$back] = $small->update(new WindowSizeMsg(80, 40));
        self::assertFalse($back->tooSmall());
        self::assertStringContainsString(self::BOARD_EDGE[0], Renderer::render($back));
    }

    public function testGameplayKeysAndClicksAreDeadWhileTooSmall(): void
    {
        $game = Game::withDifficulty(Difficulty::Easy, static fn(int $_max): int => 0)
            ->update(new WindowSizeMsg(80, 6))[0];
        self::assertTrue($game->tooSmall());

        foreach ([
            new KeyMsg(KeyType::Space),
            new KeyMsg(KeyType::Char, 'f'),
            new KeyMsg(KeyType::Char, 'c'),
            new KeyMsg(KeyType::Right),
            new KeyMsg(KeyType::Char, 'j'),
        ] as $key) {
            [$after] = $game->update($key);
            self::assertSame($game, $after, 'key while too-small must be a pure no-op');
        }

        [$clicks] = $game->update(new MouseClickMsg(4, 3, MouseButton::Left, MouseAction::Press));
        self::assertSame($game, $clicks);

        // Quit still routes — the notice screen is never a trap.
        [, $cmd] = $game->update(new KeyMsg(KeyType::Char, 'q'));
        self::assertEquals(Cmd::quit(), $cmd);
    }

    public function testRestartAndLoadStillRouteWhileTooSmall(): void
    {
        $game = Game::withDifficulty(Difficulty::Easy, static fn(int $_max): int => 0, notice: 'toast')
            ->update(new WindowSizeMsg(80, 6))[0];
        [$after] = $game->update(new KeyMsg(KeyType::Char, 'r'));
        self::assertNotSame($game, $after, 'r still restarts below the fit budget');
    }

    public function testNarrowTerminalWordWrapsTheFooterInsideItsColumns(): void
    {
        foreach ([Difficulty::Easy, Difficulty::Standard, Difficulty::Expert] as $preset) {
            $game = Game::withDifficulty($preset)->update(new WindowSizeMsg(40, 40))[0];
            self::assertFalse($game->tooSmall());
            $max = 0;
            foreach (explode("\n", Renderer::render($game)) as $line) {
                $clean = rtrim(preg_replace('/\x1b\[[0-9;]*m/', '', $line) ?? $line, ' ');
                $max = max($max, Width::of($clean));
            }
            self::assertLessThanOrEqual(40, $max, "{$preset->name} footer overflowed a 40-column terminal");
        }
    }

    public function testWideFrameFooterBytesMatchTheLegacyRender(): void
    {
        // The column budget only ever matters below 80; a wide terminal must
        // render byte-identically to the default frame (zero golden drift).
        $game = Game::withDifficulty(Difficulty::Standard, notice: 'saved');
        $legacy = Renderer::render($game);
        $wide = Renderer::render($game->update(new WindowSizeMsg(120, 40))[0]);
        self::assertSame($legacy, $wide);
    }

    public function testMouseMappingSurvivesAResizeCycle(): void
    {
        $game = Game::withDifficulty(Difficulty::Standard, static fn(int $_max): int => 0)
            ->update(new WindowSizeMsg(60, 30))[0];
        [$moved] = $game->update(new KeyMsg(KeyType::Char, 'j'));
        self::assertSame(1, $moved->cursorY);
        // Back to 80×24: interior geometry is frame-fixed, clicks still land.
        [$back] = $moved->update(new WindowSizeMsg(80, 24));
        [$flagged, $clicked] = $back->update(new MouseClickMsg(
            3 + Renderer::INTERIOR_COL_OFFSET,
            3 + Renderer::INTERIOR_ROW_OFFSET,
            MouseButton::Right,
            MouseAction::Press,
        ));
        self::assertNull($clicked);
        self::assertSame(1, $flagged->board->flagCount());
    }
}
