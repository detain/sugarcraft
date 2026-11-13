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
 *
 * Save discipline (candy-tetris-035): a loaded record remembers the disk
 * state it was read from, and save() re-reads the file before publishing —
 * the session's own deltas are merged onto whatever the disk holds NOW, not
 * onto the boot-time snapshot. Two terminals settling the same store each
 * keep their games; the best is the max of both views. A record built in
 * memory counts as booted from the EMPTY record (that is exactly how the
 * launcher first-run builds it), and save() answers the record as written,
 * re-anchored, so the next save deltas from the published state and never
 * re-bills a counter an earlier save already paid.
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
        /**
         * candy-tetris-035: the disk state this record was last reconciled
         * with. save() writes (disk-as-found + [this − baseline]) so a
         * settlement made in another terminal between our read and our
         * write is kept, not clobbered. Null means "built in memory" — the
         * boot-empty contract: the launcher's first-run record is exactly
         * `new Scores()`, so its deltas are its own counters.
         */
        private readonly ?self $baseline = null,
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

        return self::anchored(new self($soloBest, $soloGames, $versusWins, $versusLosses, $data['onboarded']));
    }

    /**
     * Write the record atomically at 0600 (mode settled before the publish
     * rename), merged with whatever the file holds NOW (candy-tetris-035):
     * the disk is re-read, this session's deltas since its boot anchor are
     * applied on top, and the best becomes the max of both views. A file
     * that became unreadable mid-run falls back to the legacy straight
     * write — the launcher already refused to boot from it, and losing the
     * current session's own settlement would be the louder failure.
     *
     * Returns the record as written, re-anchored to that disk state: the
     * caller's next settlement counts as a delta only from HERE, so a
     * second save from the same session never double-bills the first.
     */
    public function save(string $path): self
    {
        try {
            $disk = self::load($path);
        } catch (\RuntimeException) {
            $disk = null;
        }

        $merged = $this->mergeOnto($disk);
        AtomicJsonFile::new($path)->withPermissions(0600)->write([
            'version' => self::VERSION,
            'data' => [
                'soloBest' => $merged->soloBest,
                'soloGames' => $merged->soloGames,
                'versusWins' => $merged->versusWins,
                'versusLosses' => $merged->versusLosses,
                'onboarded' => $merged->onboarded,
            ],
        ]);

        return self::anchored($merged);
    }

    /**
     * (disk-as-found) + (this − boot anchor). Counters only ever walked up
     * in this session, so every delta is non-negative; a vanished or
     * unreadable file answers with the session view alone, which is then
     * the whole publishable truth.
     */
    private function mergeOnto(?self $disk): self
    {
        if ($disk === null) {
            return $this;
        }
        $base = $this->baseline ?? new self();

        return new self(
            self::bestOf($this->soloBest, $disk->soloBest),
            $disk->soloGames + ($this->soloGames - $base->soloGames),
            $disk->versusWins + ($this->versusWins - $base->versusWins),
            $disk->versusLosses + ($this->versusLosses - $base->versusLosses),
            $this->onboarded || $disk->onboarded,
        );
    }

    /** The higher of two nullable bests (null = no game ever finished). */
    private static function bestOf(?int $left, ?int $right): ?int
    {
        if ($left === null) {
            return $right;
        }
        if ($right === null) {
            return $left;
        }

        return max($left, $right);
    }

    /** Copy of $record whose baseline is the record itself (fresh anchor). */
    private static function anchored(self $record): self
    {
        return new self(
            $record->soloBest,
            $record->soloGames,
            $record->versusWins,
            $record->versusLosses,
            $record->onboarded,
            $record,
        );
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
            $this->baseline,
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
            $this->baseline,
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
            $this->baseline,
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
