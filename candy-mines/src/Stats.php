<?php

declare(strict_types=1);

namespace SugarCraft\Mines;

/**
 * Immutable stats tracker for minesweeper games.
 *
 * Tracks games played, wins, and best time per difficulty.
 */
final class Stats
{
    /** @var array<Difficulty, array{games:int, wins:int, best:int|null}> */
    private array $byDifficulty;

    public function __construct(
        public readonly int $easyGames = 0,
        public readonly int $easyWins = 0,
        public readonly ?int $easyBest = null,
        public readonly int $mediumGames = 0,
        public readonly int $mediumWins = 0,
        public readonly ?int $mediumBest = null,
        public readonly int $expertGames = 0,
        public readonly int $expertWins = 0,
        public readonly ?int $expertBest = null,
    ) {}

    public function withGame(Difficulty $d, bool $won, ?int $time): self
    {
        return match ($d) {
            Difficulty::EASY   => new self(
                easyGames: $this->easyGames + 1,
                easyWins: $this->easyWins + ($won ? 1 : 0),
                easyBest: $this->minTime($this->easyBest, $won ? $time : null),
                mediumGames: $this->mediumGames,
                mediumWins: $this->mediumWins,
                mediumBest: $this->mediumBest,
                expertGames: $this->expertGames,
                expertWins: $this->expertWins,
                expertBest: $this->expertBest,
            ),
            Difficulty::MEDIUM => new self(
                easyGames: $this->easyGames,
                easyWins: $this->easyWins,
                easyBest: $this->easyBest,
                mediumGames: $this->mediumGames + 1,
                mediumWins: $this->mediumWins + ($won ? 1 : 0),
                mediumBest: $this->minTime($this->mediumBest, $won ? $time : null),
                expertGames: $this->expertGames,
                expertWins: $this->expertWins,
                expertBest: $this->expertBest,
            ),
            Difficulty::EXPERT => new self(
                easyGames: $this->easyGames,
                easyWins: $this->easyWins,
                easyBest: $this->easyBest,
                mediumGames: $this->mediumGames,
                mediumWins: $this->mediumWins,
                mediumBest: $this->mediumBest,
                expertGames: $this->expertGames + 1,
                expertWins: $this->expertWins + ($won ? 1 : 0),
                expertBest: $this->minTime($this->expertBest, $won ? $time : null),
            ),
        };
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
            Difficulty::EASY   => $this->easyGames,
            Difficulty::MEDIUM => $this->mediumGames,
            Difficulty::EXPERT => $this->expertGames,
        };
    }

    public function wins(Difficulty $d): int
    {
        return match ($d) {
            Difficulty::EASY   => $this->easyWins,
            Difficulty::MEDIUM => $this->mediumWins,
            Difficulty::EXPERT => $this->expertWins,
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
            Difficulty::EASY   => $this->easyBest,
            Difficulty::MEDIUM => $this->mediumBest,
            Difficulty::EXPERT => $this->expertBest,
        };
    }
}
