<?php

/**
 * candy-tetris/src/Support/Scores.php
 *
 * The cross-session record. Before this file the README's own words
 * were "Nothing survives the exit" — the game-over banner printed a final
 * score with nothing to compare it against. This is a small immutable value
 * object over the same versioned-JSON envelope candy-minesweeper's
 * DifficultyStats uses, stored through sugar-core's AtomicJsonFile at
 * 0600: one file, one record, honest failures.
 *
 * Load discipline mirrors the minesweeper precedent exactly:
 *   - missing file (or no resolvable path) → load() answers null, the
 *     launcher treats that as "no record yet" and behavior is byte-identical
 *     to the game before records existed;
 *   - a malformed file THROWS naming the path — the launcher announces on
 *     stderr and starts fresh, and the old file is left untouched on disk.
 *     A corrupt record is never silently zeroed away by a save.
 */

declare(strict_types=1);

namespace SugarCraft\Tetris\Support;

use SugarCraft\Core\Util\AtomicJsonFile;

final class Scores
{
    /** Schema tag of the envelope; anything else is refused, not guessed. */
    private const VERSION = 1;

    public function __construct(
        /** Highest solo score ever settled (null = no solo game finished yet). */
        public readonly ?int $soloBest = null,
        /** Finished solo games (the denominator the best came out of). */
        public readonly int $soloGames = 0,
        /** Versus matches won by the local player. */
        public readonly int $versusWins = 0,
        /** Versus matches lost by the local player. */
        public readonly int $versusLosses = 0,
        /**
         * MA-24: has the player been through the first-run on-ramp. The
         * flag flips on the first deliberate start, never on a quit —
         * closing the game unseen must not count as having seen it.
         */
        public readonly bool $onboarded = false,
    ) {}

    /**
     * Read the record at $path. Null means "nothing to read": no path was
     * resolvable, or the file does not exist yet. Corruption — and any
     * non-regular occupant of the slot — throws; the caller decides how
     * loudly to be.
     */
    public static function load(?string $path): ?self
    {
        if ($path === null) {
            return null;
        }

        // lstat, never is_file(): a symlink is refused instead of followed,
        // and a socket or FIFO parked at the slot costs the record a loud
        // refusal (same door as corruption) instead of a reader that blocks
        // the launch waiting for a writer that never comes.
        $slot = @lstat($path);
        if ($slot === false) {
            return null;
        }
        if (($slot['mode'] & 0o170000) !== 0o100000) {
            throw new \RuntimeException('score store is not a regular file in ' . $path);
        }

        try {
            $envelope = AtomicJsonFile::new($path)->read();
        } catch (\JsonException) {
            throw new \RuntimeException('invalid persistence format in ' . $path);
        }

        if (
            !array_key_exists('version', $envelope)
            || !array_key_exists('data', $envelope)
            || $envelope['version'] !== self::VERSION
            || !is_array($envelope['data'])
        ) {
            throw new \RuntimeException('invalid persistence format in ' . $path);
        }

        $data = $envelope['data'];

        $soloBest = self::expectNullableInt($data, 'soloBest', $path);
        $soloGames = self::expectInt($data, 'soloGames', $path);
        $versusWins = self::expectInt($data, 'versusWins', $path);
        $versusLosses = self::expectInt($data, 'versusLosses', $path);

        if (!array_key_exists('onboarded', $data) || !is_bool($data['onboarded'])) {
            throw new \RuntimeException('invalid persistence format in ' . $path);
        }

        // Coherence: a record that counts negative games or reports fewer
        // finished games than its own counters is a broken file, and a
        // broken file is refused LOUDLY rather than trusted or zeroed.
        foreach ([$soloBest, $soloGames, $versusWins, $versusLosses] as $value) {
            if ($value !== null && $value < 0) {
                throw new \RuntimeException('invalid persistence counters in ' . $path);
            }
        }

        return new self($soloBest, $soloGames, $versusWins, $versusLosses, $data['onboarded']);
    }

    /** Write the record atomically at 0600 (mode settled before the publish rename). */
    public function save(string $path): void
    {
        AtomicJsonFile::new($path)->withPermissions(0600)->write([
            'version' => self::VERSION,
            'data' => [
                'soloBest' => $this->soloBest,
                'soloGames' => $this->soloGames,
                'versusWins' => $this->versusWins,
                'versusLosses' => $this->versusLosses,
                'onboarded' => $this->onboarded,
            ],
        ]);
    }

    /**
     * Settle one finished solo game: the best never moves backwards and
     * the games counter walks by exactly one.
     */
    public function withSolo(int $points): self
    {
        return new self(
            $this->soloBest === null ? $points : max($this->soloBest, $points),
            $this->soloGames + 1,
            $this->versusWins,
            $this->versusLosses,
            $this->onboarded,
        );
    }

    /** True when $points beats every solo game settled so far. */
    public function beatsSoloBest(int $points): bool
    {
        return $this->soloBest === null || $points > $this->soloBest;
    }

    /** Record one finished versus match from the local player's seat. */
    public function withVersusResult(bool $playerWon): self
    {
        return new self(
            $this->soloBest,
            $this->soloGames,
            $this->versusWins + ($playerWon ? 1 : 0),
            $this->versusLosses + ($playerWon ? 0 : 1),
            $this->onboarded,
        );
    }

    public function withOnboarded(): self
    {
        return new self(
            $this->soloBest,
            $this->soloGames,
            $this->versusWins,
            $this->versusLosses,
            true,
        );
    }

    /** @param array<string,mixed> $data */
    private static function expectInt(array $data, string $key, string $path): int
    {
        if (!array_key_exists($key, $data) || !is_int($data[$key])) {
            throw new \RuntimeException('invalid persistence format in ' . $path);
        }

        return $data[$key];
    }

    /** @param array<string,mixed> $data */
    private static function expectNullableInt(array $data, string $key, string $path): ?int
    {
        if (!array_key_exists($key, $data)) {
            throw new \RuntimeException('invalid persistence format in ' . $path);
        }
        if ($data[$key] === null) {
            return null;
        }
        if (!is_int($data[$key])) {
            throw new \RuntimeException('invalid persistence format in ' . $path);
        }

        return $data[$key];
    }
}
