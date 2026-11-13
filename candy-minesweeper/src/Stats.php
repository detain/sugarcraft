<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper;

use SugarCraft\Core\Concerns\Mutable;

/**
 * Immutable stats tracker for minesweeper games.
 *
 * Tracks games played, wins, and best time per difficulty.
 */
final class Stats
{
    use Mutable;

    public function __construct(
        public readonly int $easyGames = 0,
        public readonly int $easyWins = 0,
        public readonly ?int $easyBest = null,
        public readonly int $standardGames = 0,
        public readonly int $standardWins = 0,
        public readonly ?int $standardBest = null,
        public readonly int $mediumGames = 0,
        public readonly int $mediumWins = 0,
        public readonly ?int $mediumBest = null,
        public readonly int $expertGames = 0,
        public readonly int $expertWins = 0,
        public readonly ?int $expertBest = null,
    ) {}

    /**
     * Fold one finished game into the tally for its preset bucket.
     *
     * The three per-difficulty fields (games/wins/best) are addressed by a
     * name prefix, so all four presets share a single mutate() path —
     * the previous per-case duplication had twelve untouched-field copies to
     * drift. Fields for the other three presets ride get_object_vars carry.
     */
    public function withGame(Difficulty $d, bool $won, ?int $time): self
    {
        $p = match ($d) {
            Difficulty::Easy     => 'easy',
            Difficulty::Standard => 'standard',
            Difficulty::Medium   => 'medium',
            Difficulty::Expert   => 'expert',
        };

        return $this->mutate([
            $p . 'Games' => $this->gamesPlayed($d) + 1,
            $p . 'Wins'  => $this->wins($d) + ($won ? 1 : 0),
            $p . 'Best'  => $this->minTime($this->bestTime($d), $won ? $time : null),
        ]);
    }

    private function minTime(?int $existing, ?int $new): ?int
    {
        if ($new === null) {
            return $existing;
        }
        if ($existing === null) {
            return $new;
        }
        return min($existing, $new);
    }

    public function gamesPlayed(Difficulty $d): int
    {
        return match ($d) {
            Difficulty::Easy     => $this->easyGames,
            Difficulty::Standard => $this->standardGames,
            Difficulty::Medium   => $this->mediumGames,
            Difficulty::Expert   => $this->expertGames,
        };
    }

    public function wins(Difficulty $d): int
    {
        return match ($d) {
            Difficulty::Easy     => $this->easyWins,
            Difficulty::Standard => $this->standardWins,
            Difficulty::Medium   => $this->mediumWins,
            Difficulty::Expert   => $this->expertWins,
        };
    }

    public function winRate(Difficulty $d): float
    {
        $games = $this->gamesPlayed($d);
        if ($games === 0) {
            return 0.0;
        }
        return $this->wins($d) / $games * 100;
    }

    public function bestTime(Difficulty $d): ?int
    {
        return match ($d) {
            Difficulty::Easy     => $this->easyBest,
            Difficulty::Standard => $this->standardBest,
            Difficulty::Medium   => $this->mediumBest,
            Difficulty::Expert   => $this->expertBest,
        };
    }
}
