<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper\Stats;

use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Core\Util\AtomicJsonFile;
use SugarCraft\Minesweeper\Difficulty;
use SugarCraft\Minesweeper\Stats;

/**
 * Atomic persistence wrapper for minesweeper difficulty statistics.
 *
 * Delegates the durable-state read/write to sugar-core's
 * {@see AtomicJsonFile} SSOT: writes go to a same-dir temp file under an
 * exclusive lock, then rename over the target. The rename is atomic on
 * POSIX — the target file is never in a partially-written state even if a
 * crash occurs mid-write. This document store keeps its own `{version,data}`
 * envelope on top of that raw-array primitive.
 */
final class DifficultyStats
{
    use Mutable;

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
        $store = AtomicJsonFile::new($path);
        // A missing file is "no stats yet" (null), not the empty state — keep
        // the null contract rather than AtomicJsonFile's read()->[] convention.
        if (!$store->exists()) {
            return null;
        }

        // read() delivers the is-array guard for free: a malformed file throws
        // \JsonException, a valid-but-non-array top level throws \RuntimeException.
        /** @var array<string, mixed> $decoded */
        $decoded = $store->read();

        if (!isset($decoded['version']) || !isset($decoded['data'])) {
            throw new \RuntimeException("Invalid persistence format in: {$path}");
        }

        if ($decoded['version'] !== 1) {
            throw new \RuntimeException("Unsupported persistence version: {$decoded['version']}");
        }

        $data = $decoded['data'];
        // Per-field reads index $data — a non-array data section (string,
        // number, null slipped past isset via explicit null value... isset
        // already refuses null, but a scalar does not) would have every
        // expectInt* hit a warning-then-default path, silently replacing
        // the real tally with zeros that the next save then CLOBBERS to
        // disk. Refuse loudly instead of overwriting the user's history.
        if (!is_array($data)) {
            throw new \RuntimeException("Invalid persistence format in: {$path}");
        }

        $stats = new Stats(
            easyGames:   self::expectInt($data['easyGames']   ?? null, $path),
            easyWins:    self::expectInt($data['easyWins']    ?? null, $path),
            easyBest:    self::expectNullableInt($data['easyBest']    ?? null, $path),
            standardGames: self::expectInt($data['standardGames'] ?? null, $path),
            standardWins:  self::expectInt($data['standardWins']  ?? null, $path),
            standardBest:  self::expectNullableInt($data['standardBest'] ?? null, $path),
            mediumGames: self::expectInt($data['mediumGames'] ?? null, $path),
            mediumWins:  self::expectInt($data['mediumWins']  ?? null, $path),
            mediumBest:  self::expectNullableInt($data['mediumBest']  ?? null, $path),
            expertGames: self::expectInt($data['expertGames'] ?? null, $path),
            expertWins:  self::expectInt($data['expertWins']  ?? null, $path),
            expertBest:  self::expectNullableInt($data['expertBest']  ?? null, $path),
        );

        // Counters must describe a countable past: negative games/wins or a
        // negative best are impossible states, and wins-above-games is the
        // classic tally forgery. The stats file is user-editable, so the
        // reader enforces coherence rather than printing a "win rate
        // 900.0%" leaderboard.
        foreach (Difficulty::cases() as $d) {
            $games = $stats->gamesPlayed($d);
            $wins  = $stats->wins($d);
            $best  = $stats->bestTime($d);
            if ($games < 0 || $wins < 0 || ($best !== null && $best < 0) || $wins > $games) {
                throw new \RuntimeException("Invalid persistence counters in: {$path}");
            }
        }

        return new self($stats);
    }

    /**
     * Validate a required integer counter.
     *
     * Absent is a REFUSAL, not a default: save() always writes the full
     * twelve-key set, so a missing counter means the file was truncated or
     * hand-edited — exactly the clobber vector the scalar-data guard above
     * exists for. Zero-defaulting a half-deleted file would silently
     * overwrite the real tally on the next save.
     *
     * @throws \RuntimeException if the field is absent or not an int
     */
    private static function expectInt(mixed $v, string $path): int
    {
        if (!is_int($v)) {
            throw new \RuntimeException("Invalid persistence format in: {$path}");
        }
        return $v;
    }

    /**
     * Validate and coerce a nullable integer field.
     *
     * @throws \RuntimeException if the value is present but not an int or null
     */
    private static function expectNullableInt(mixed $v, string $path): ?int
    {
        if ($v === null) {
            return null;
        }
        if (!is_int($v)) {
            throw new \RuntimeException("Invalid persistence format in: {$path}");
        }
        return $v;
    }

    /**
     * Save difficulty stats atomically to a persistence file.
     *
     * Delegates the tmp+flock+rename dance to {@see AtomicJsonFile::write()},
     * so the file is never in a partial-write state and no temp artifact is
     * left behind. The `{version,data}` envelope is preserved on disk and
     * pretty-printed — still valid JSON that {@see load()} round-trips.
     *
     * @param string $path Absolute path to the target file.
     * @throws \RuntimeException If write or rename fails.
     */
    public function save(string $path): void
    {
        $s = $this->stats;
        // 0600 like the savegame slot: this is private per-user history;
        // the mode is applied to the temp file before the publish rename.
        AtomicJsonFile::new($path)
            ->withPermissions(0600)
            ->write([
                'version' => 1,
                'data' => [
                    'easyGames' => $s->easyGames,
                    'easyWins' => $s->easyWins,
                    'easyBest' => $s->easyBest,
                    'standardGames' => $s->standardGames,
                    'standardWins' => $s->standardWins,
                    'standardBest' => $s->standardBest,
                    'mediumGames' => $s->mediumGames,
                    'mediumWins' => $s->mediumWins,
                    'mediumBest' => $s->mediumBest,
                    'expertGames' => $s->expertGames,
                    'expertWins' => $s->expertWins,
                    'expertBest' => $s->expertBest,
                ],
            ]);
    }

    /**
     * Record a game result and return a new DifficultyStats with the update applied.
     */
    public function withGame(Difficulty $d, bool $won, ?int $time): self
    {
        return $this->mutate(['stats' => $this->stats->withGame($d, $won, $time)]);
    }

    /**
     * Underlying Stats snapshot (bare accessor — house convention, no `get`).
     */
    public function stats(): Stats
    {
        return $this->stats;
    }
}
