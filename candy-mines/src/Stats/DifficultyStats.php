<?php

declare(strict_types=1);

namespace SugarCraft\Mines\Stats;

use SugarCraft\Mines\Difficulty;
use SugarCraft\Mines\Stats;

/**
 * Atomic persistence wrapper for minesweeper difficulty statistics.
 *
 * Uses the Homestead atomic-save pattern: write to a temp file then
 * rename over the target. The rename is atomic on POSIX — the target
 * file is never in a partially-written state even if a crash occurs
 * mid-write.
 */
final class DifficultyStats
{
    public function __construct(
        private readonly Stats $stats,
    ) {}

    /**
     * Build a DifficultyStats from an existing Stats object.
     */
    public static function fromStats(Stats $stats): self
    {
        return new self($stats);
    }

    /**
     * Load difficulty stats from a persistence file.
     *
     * @param string $path Absolute path to the JSON persistence file.
     * @return self|null Null if the file does not exist.
     * @throws \RuntimeException If the file exists but is not valid.
     */
    public static function load(string $path): ?self
    {
        if (!file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Failed to read persistence file: {$path}");
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($decoded['version']) || !isset($decoded['data'])) {
            throw new \RuntimeException("Invalid persistence format in: {$path}");
        }

        if ($decoded['version'] !== 1) {
            throw new \RuntimeException("Unsupported persistence version: {$decoded['version']}");
        }

        $data = $decoded['data'];

        $stats = new Stats(
            easyGames: $data['easyGames'] ?? 0,
            easyWins: $data['easyWins'] ?? 0,
            easyBest: $data['easyBest'] ?? null,
            mediumGames: $data['mediumGames'] ?? 0,
            mediumWins: $data['mediumWins'] ?? 0,
            mediumBest: $data['mediumBest'] ?? null,
            expertGames: $data['expertGames'] ?? 0,
            expertWins: $data['expertWins'] ?? 0,
            expertBest: $data['expertBest'] ?? null,
        );

        return new self($stats);
    }

    /**
     * Save difficulty stats atomically to a persistence file.
     *
     * Uses tmp+rename so the file is never in a partial-write state.
     *
     * @param string $path Absolute path to the target file.
     * @throws \RuntimeException If write or rename fails.
     */
    public function save(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException("Cannot create persistence directory: {$dir}");
            }
        }

        $s = $this->stats;
        $payload = json_encode([
            'version' => 1,
            'data' => [
                'easyGames' => $s->easyGames,
                'easyWins' => $s->easyWins,
                'easyBest' => $s->easyBest,
                'mediumGames' => $s->mediumGames,
                'mediumWins' => $s->mediumWins,
                'mediumBest' => $s->mediumBest,
                'expertGames' => $s->expertGames,
                'expertWins' => $s->expertWins,
                'expertBest' => $s->expertBest,
            ],
        ], JSON_THROW_ON_ERROR);

        $tmp = $dir . '/.tmp_' . basename($path) . '.' . bin2hex(random_bytes(8));

        try {
            if (file_put_contents($tmp, $payload, LOCK_EX) === false) {
                throw new \RuntimeException("Failed to write temp file: {$tmp}");
            }

            if (!rename($tmp, $path)) {
                throw new \RuntimeException("Failed to rename temp file to: {$path}");
            }
        } catch (\Throwable $e) {
            if (file_exists($tmp)) {
                unlink($tmp);
            }
            throw $e;
        }
    }

    /**
     * Record a game result and return a new DifficultyStats with the update applied.
     */
    public function withGame(Difficulty $d, bool $won, ?int $time): self
    {
        return new self($this->stats->withGame($d, $won, $time));
    }

    /**
     * Get the underlying Stats object.
     */
    public function getStats(): Stats
    {
        return $this->stats;
    }
}
