<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Minesweeper\Difficulty;
use SugarCraft\Minesweeper\Game;
use SugarCraft\Minesweeper\Lang;
use SugarCraft\Minesweeper\Stats;

/**
 * msw-08 (findings 010/095): with persistence enabled the stats were
 * invisible — reading your best time meant `cat`ing the JSON, a NEW BEST
 * badge never said WHICH record fell, and no screen named the preset or
 * hinted that custom shapes exist. These pins own the three surfaces: the
 * status line's preset/best segments (gated on stats being kept), the
 * banner's beaten-record suffix, and the plain no-stats status staying
 * byte-identical so every golden that predates this row still proves.
 */
final class StatsSurfaceTest extends TestCase
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

    /** Open every safe cell (rand→0 EASY flood-resolves to a win). */
    private function winDrive(Game $g): Game
    {
        [$g, ] = $g->update(self::key(KeyType::Space));
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

    public function testPresetStatusWithStatsNamesThePresetAndDashesAnEmptyRecord(): void
    {
        $view = Game::withDifficulty(Difficulty::STANDARD, static fn(int $max): int => 0, stats: new Stats())->view();

        $this->assertStringContainsString(Lang::t('ui.preset_standard'), $view, 'stats keep score — the status must say for which preset');
        $this->assertStringContainsString('best: --', $view, 'no record yet, the segment shows a placeholder');
        $this->assertStringNotContainsString('mines: 12', $view, 'the preset name replaces the redundant mine count');
    }

    public function testPresetStatusShowsTheCurrentBestTime(): void
    {
        $stats = new Stats(standardGames: 5, standardWins: 2, standardBest: 42);
        $view = Game::withDifficulty(Difficulty::STANDARD, static fn(int $max): int => 0, stats: $stats)->view();

        $this->assertStringContainsString('best: 0:42', $view, 'the stored tally must surface without catting the JSON');
    }

    public function testCustomBoardStatusSpellsItsRawShape(): void
    {
        // 12×8 with 10 mines matches no preset — the head segment is the
        // raw W×H×M so the board at least proves custom sizes exist.
        $game = Game::withCustom(12, 8, 10, static fn(int $max): int => 0, stats: new Stats());

        $view = $game->view();
        $this->assertStringContainsString('12×8×10  ·  ', $view, 'custom head names its own shape (width×height×mines per the naming law)');
        $this->assertStringContainsString('best: --', $view, 'custom boards tally nothing, the placeholder stays');
    }

    public function testNoStatsStatusStaysThePlainLegacyLine(): void
    {
        $view = Game::withDifficulty(Difficulty::STANDARD, static fn(int $max): int => 0)->view();

        $this->assertStringContainsString('mines: 12', $view);
        $this->assertStringNotContainsString('best:', $view, 'nothing is being tallied, nothing is advertised');
    }

    public function testBannerNamesTheRecordItBeat(): void
    {
        $g = $this->winDrive(Game::withDifficulty(
            Difficulty::EASY,
            static fn(int $max): int => 0,
            stats: new Stats(easyGames: 1, easyWins: 0, easyBest: 3600),
        ));

        $this->assertTrue($g->newBest, 'sanity: a sub-hour win beat the stored hour');
        $this->assertSame(3600, $g->beatenRecord, 'the fallen record survives the tally merge long enough to be quoted');
        $view = $g->view();
        $this->assertStringContainsString(
            Lang::t('ui.beat_best', ['prev' => '60:00']),
            $view,
            'the banner must name the number it beat, not just wave a badge',
        );
    }

    public function testFirstRecordWearsTheBareBadge(): void
    {
        $g = $this->winDrive(Game::withDifficulty(
            Difficulty::EASY,
            static fn(int $max): int => 0,
            stats: new Stats(),
        ));

        $this->assertTrue($g->newBest);
        $this->assertNull($g->beatenRecord, 'there was no record to beat');
        $this->assertStringContainsString(Lang::t('ui.new_best'), $g->view());
        $this->assertStringNotContainsString(
            Lang::t('ui.beat_best', ['prev' => '0:00']),
            $g->view(),
            'a first win quotes no fallen time (the arrow check would be silly — the help footer has arrows)',
        );
    }

    public function testRestartClearsTheBeatenRecord(): void
    {
        $g = $this->winDrive(Game::withDifficulty(
            Difficulty::EASY,
            static fn(int $max): int => 0,
            stats: new Stats(easyGames: 1, easyWins: 0, easyBest: 3600),
        ));
        $this->assertNotNull($g->beatenRecord, 'sanity: the win named a record');

        [$restarted, ] = $g->update(self::key(KeyType::Char, 'r'));

        $this->assertFalse($restarted->newBest, 'msw-03 sticky-badge carry');
        $this->assertNull($restarted->beatenRecord, 'a fresh board has quoted nobody');
    }

    public function testStatusSegmentsFitTheFortyColumnBudget(): void
    {
        $stats = new Stats(standardGames: 5, standardWins: 2, standardBest: 3599);
        $game = Game::withDifficulty(Difficulty::STANDARD, static fn(int $max): int => 0, stats: $stats)
            ->update(new WindowSizeMsg(40, 40))[0];
        foreach (explode("\n", $game->view()) as $line) {
            $clean = rtrim(preg_replace('/\x1b\[[0-9;]*m/', '', $line) ?? $line, ' ');
            $this->assertLessThanOrEqual(40, mb_strlen($clean), "narrow stats status overflows: {$clean}");
        }
    }
}
