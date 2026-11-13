<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Minesweeper\Difficulty;
use SugarCraft\Minesweeper\Game;
use SugarCraft\Minesweeper\Lang;
use SugarCraft\Minesweeper\Stats;

/**
 * M06 (lib-reviews-1103): the win/lose banner used to discard the final
 * time and the stats overlay that would celebrate a new record never
 * existed — the score line stayed live under a board nobody could score
 * on any more. These pins own the banner suffix (frozen time) and the
 * NEW BEST badge gate (preset games only, strict beat against the
 * PREVIOUS tally, never on a loss).
 *
 * The deterministic rand→0 EASY fixture flood-resolves to a win (the
 * golden mines-win-overlay proves the same drive); a loss is driven by
 * detonating the first planted mine after the safe opener.
 */
final class BannerFinishTest extends TestCase
{
    private static function key(KeyType $type, string $rune = ''): KeyMsg
    {
        return new KeyMsg($type, $rune);
    }

    /** Drive cursor moves through update() so afterAction() stamps. */
    private static function driveTo(Game $g, int $x, int $y): Game
    {
        while ($g->cursorX !== $x) {
            [$g, ] = $g->update($g->cursorX < $x ? self::key(KeyType::Right) : self::key(KeyType::Left));
        }
        while ($g->cursorY !== $y) {
            [$g, ] = $g->update($g->cursorY < $y ? self::key(KeyType::Down) : self::key(KeyType::Up));
        }
        return $g;
    }

    private function winDrive(Game $g): Game
    {
        [$g, ] = $g->update(self::key(KeyType::Space)); // opener places mines safely
        $b = $g->board;
        for ($y = 0; $y < $b->height; $y++) {
            for ($x = 0; $x < $b->width; $x++) {
                $c = $b->cell($x, $y);
                if ($c !== null && !$c->mine && !$c->revealed) {
                    $g = self::driveTo($g, $x, $y);
                    [$g, ] = $g->update(self::key(KeyType::Space));
                    if ($g->board->isWon()) {
                        break 2;
                    }
                }
            }
        }
        $this->assertTrue($g->board->isWon(), 'sanity: drive reached the win');
        return $g;
    }

    public function testWinBannerCarriesFrozenTimeAndFirstRecordBadge(): void
    {
        $g = self::winDrive(Game::withDifficulty(
            Difficulty::EASY,
            static fn(int $max): int => 0,
            stats: new Stats(),
        ));
        $this->assertNotNull($g->elapsedSeconds, 'the transition frame froze the clock');
        $this->assertTrue($g->newBest, 'no previous tally → the win IS the record');
        $view = $g->view();
        $this->assertStringContainsString(Lang::t('ui.new_best'), $view);
        $this->assertMatchesRegularExpression('/' . preg_quote(Lang::t('ui.cleared'), '/') . ' · \d+:\d\d/', $view, 'banner carries the frozen final time');
    }

    public function testTallylessWinNeverWearsTheBadge(): void
    {
        // msw-03: with stats disabled there is no record to beat — a badge
        // celebrating a time nobody keeps is a lie, so the gate refuses.
        $g = self::winDrive(Game::withDifficulty(Difficulty::EASY, static fn(int $max): int => 0));
        $this->assertFalse($g->newBest, 'no tally when the transition ran, no record');
        $this->assertStringNotContainsString(Lang::t('ui.new_best'), $g->view());
    }

    public function testRestartClearsTheBadgeAndASlowerWinDoesNotRelightIt(): void
    {
        // msw-03: newBest used to be one-way sticky — stamped true on the
        // record win, never cleared. A second (slower) win after r therefore
        // kept advertising a record it did not set.
        $g = self::winDrive(Game::withDifficulty(
            Difficulty::EASY,
            static fn(int $max): int => 0,
            stats: new Stats(),
        ));
        $this->assertTrue($g->newBest, 'sanity: first win sets the badge');
        [$restarted, ] = $g->update(self::key(KeyType::Char, 'r'));
        $this->assertFalse($restarted->newBest, 'r clears the badge with the board');
        $g = self::winDrive($restarted);
        $this->assertFalse($g->newBest, '0:00 ties the tally it just set — a tie is no beat');
        $this->assertStringNotContainsString(Lang::t('ui.new_best'), $g->view());
    }

    public function testStoredBestDeniesTheBadge(): void
    {
        // easyBest 0: the driven win lands at 0 s, and 0 < 0 is no beat —
        // a tie never re-wears the badge.
        $g = self::winDrive(Game::withDifficulty(
            Difficulty::EASY,
            static fn(int $max): int => 0,
            stats: new Stats(easyBest: 0),
        ));
        $this->assertFalse($g->newBest);
        $this->assertStringNotContainsString(Lang::t('ui.new_best'), $g->view());
    }

    public function testLossCarriesTimeNeverTheBadge(): void
    {
        $g = Game::withDifficulty(Difficulty::EASY, static fn(int $max): int => 0);
        [$g, ] = $g->update(self::key(KeyType::Space));
        $b = $g->board;
        for ($y = 0; $y < $b->height; $y++) {
            for ($x = 0; $x < $b->width; $x++) {
                $c = $b->cell($x, $y);
                if ($c !== null && $c->mine && !$c->revealed) {
                    $g = self::driveTo($g, $x, $y);
                    [$g, ] = $g->update(self::key(KeyType::Space));
                    break 2;
                }
            }
        }
        $this->assertTrue($g->board->exploded, 'sanity: the drive detonated');
        $this->assertFalse($g->newBest, 'a loss is never a record');
        $view = $g->view();
        $this->assertStringContainsString(Lang::t('ui.boom'), $view);
        $this->assertStringNotContainsString(Lang::t('ui.new_best'), $view);
        $this->assertMatchesRegularExpression('/' . preg_quote(Lang::t('ui.boom'), '/') . ' · \d+:\d\d/', $view);
    }

    public function testCustomBoardWinShowsTimeWithoutPresetBadge(): void
    {
        // 3×3 with a single mine: opener at (0,0), mine planted elsewhere;
        // the rest flood-reveals to a win. Custom boards tally nothing,
        // so they get the time but never the badge.
        $g = Game::start(3, 3, 1, static fn(int $max): int => 0);
        [$g, ] = $g->update(self::key(KeyType::Space));
        $b = $g->board;
        foreach ([0, 1, 2] as $y) {
            foreach ([0, 1, 2] as $x) {
                $c = $b->cell($x, $y);
                if ($c !== null && !$c->mine && !$c->revealed) {
                    $g = self::driveTo($g, $x, $y);
                    [$g, ] = $g->update(self::key(KeyType::Space));
                }
            }
        }
        $this->assertTrue($g->board->isWon(), 'sanity: custom board won');
        $this->assertNull($g->difficulty(), 'sanity: 3x3/1 matches no preset');
        $this->assertFalse($g->newBest, 'no bucket, no record');
        $view = $g->view();
        $this->assertStringContainsString(Lang::t('ui.cleared'), $view);
        $this->assertStringNotContainsString(Lang::t('ui.new_best'), $view);
        $this->assertMatchesRegularExpression('/' . preg_quote(Lang::t('ui.cleared'), '/') . ' · \d+:\d\d/', $view, 'the frozen time still rides a custom win');
    }
}
