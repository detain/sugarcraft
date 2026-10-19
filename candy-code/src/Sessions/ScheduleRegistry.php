<?php

declare(strict_types=1);

namespace SugarCraft\Code\Sessions;

use SugarCraft\Core\Util\AtomicJsonFile;
use SugarCraft\Code\Support\HomeDirectory;
use SugarCraft\Code\Support\PrivateDir;

/**
 * The roster of {@see Schedule} rows, the file behind them, and the clock.
 *
 * AUT-1 (plan_comparison.md §3): persisted rows live at
 * `~/.candy-code/schedules.json` — `{version: 1, schedules: [row, ...]}` —
 * written through {@see AtomicJsonFile} at 0600, and ephemeral rows registered
 * by `/loop` live only in this process's memory. Both kinds are evaluated by
 * the SAME pumps that poll background sessions (Chat's `pumpBackgroundSessions`
 * and the serve-side {@see \SugarCraft\Code\Host\BackgroundEvents}), so there
 * is no second loop, no timer per row, and no cron anywhere.
 *
 * WHY A LOCK PER ROW. Two hosts can share one home at once — a TUI and
 * `serve`, or two TUIs — and both pumps see the same due row at the same
 * instant. Before firing a PERSISTED row a host must claim
 * `<configHome>/.candy-code/schedules/<name>.lock` with a non-blocking
 * {@see \flock()} (the {@see \SugarCraft\Code\Session\SessionLock} idiom:
 * mode settled on the inode before any payload, dev/ino guard against a
 * planted file, auto-release at process death so a crashed host leaves no
 * stale lock). The loser skips the fire WITHOUT advancing — the winner's
 * persist lands and a reload picks the new `nextDue` up on the next tick.
 * Ephemeral rows need no lock: they exist in exactly one process by
 * construction.
 *
 * WHY THE ADVANCE COMES BEFORE THE SPAWN. A fire claims the row, moves
 * `nextDue` past every missed slot ({@see Schedule::nextDueAfter()} — the
 * overrun skip), persists, and only then spawns. If the spawn dies the row
 * still waits a full period rather than hot-looping on a broken prompt; the
 * bound on this path is due-COUNTS, never request timeouts (the E646 law), so
 * a slow or wedged daemon cannot make the clock fire twice — the row is
 * already armed for its next slot, and {@see self::$fired} refuses to stack a
 * second run of the same name on top of the first.
 *
 * Corrupt input never crashes a launch: a malformed FILE is recorded whole in
 * {@see loadErrors()} and the roster starts empty; a malformed ROW is skipped
 * by name and its siblings keep running. `/schedules` surfaces both loudly,
 * which is where a human will see them.
 */
final class ScheduleRegistry
{
    /** Wire format version of schedules.json; unknown majors refuse to load rather than half-trust. */
    public const VERSION = 1;

    /** Cadences are whole seconds (the plan's row format); the file records nothing else. */
    public const FILE_VERSION_KEY = 'version';

    private const FILE_SCHEDULES_KEY = 'schedules';

    /** Persisted rows keyed by name, in file order. The only mutable state that touches disk. */
    private array $rows = [];

    /** `/loop` rows keyed by name; never written, die with the process, never lock. */
    private array $ephemeral = [];

    /** schedule name => background session id of the run still in flight. */
    private array $fired = [];

    /** Names this registry refused to load, already formatted for a human row. */
    private array $loadErrors = [];

    /** mtime/size/ino stamp of the last read (or write), so stat() can stay rare. */
    private ?array $latch = null;

    private function __construct(
        public readonly ?string $path,
    ) {
    }

    /**
     * The default home-backed registry: `~/.candy-code/schedules.json`. With no
     * vouchsafe home there is nowhere durable to keep rows, so the registry
     * still exists — it just cannot persist (a persisted `add` refuses loudly,
     * `/loop` keeps working in memory).
     */
    public static function loadDefault(): self
    {
        $home = HomeDirectory::owned();
        $registry = new self($home === null ? null : rtrim($home, '/') . '/.candy-code/schedules.json');
        // Load NOW, not on first pump: `hasEnabled()` gates the pump's own
        // tick subscription, so a registry that only read its file from
        // inside a tick could never read it the first time.
        $registry->load();

        return $registry;
    }

    /** A registry over an explicit path (tests, --config homes). */
    public static function atPath(string $path): self
    {
        return new self($path);
    }

    /** A registry with NO backing file: the `/loop` half works, persistence refuses loudly. */
    public static function inMemory(): self
    {
        return new self(null);
    }

    /** @return list<Schedule> */
    public function all(): array
    {
        return [...array_values($this->rows), ...array_values($this->ephemeral)];
    }

    public function get(string $name): ?Schedule
    {
        return $this->rows[$name] ?? $this->ephemeral[$name] ?? null;
    }

    /** Whether any tick has work to watch for: one enabled row keeps the pump subscription alive. */
    public function hasEnabled(): bool
    {
        foreach ($this->all() as $row) {
            if ($row->enabled) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> human-formatted load refusals, oldest first */
    public function loadErrors(): array
    {
        return $this->loadErrors;
    }

    /**
     * @return list<Schedule> every enabled row whose nextDue has arrived
     */
    public function due(int $now): array
    {
        $due = [];
        foreach ($this->all() as $row) {
            if ($row->isDue($now)) {
                $due[] = $row;
            }
        }

        return $due;
    }

    /**
     * Insert or replace a row. Duplicate names refuse — a name is an identity
     * (and a lock-file stem), silently shadowing one row with another would
     * let the second writer steal the first's clock.
     *
     * @throws \InvalidArgumentException on a name clash or a persisted row in a
     *                                  process with nowhere to persist it
     */
    public function add(Schedule $schedule): void
    {
        // A name is an identity ACROSS kinds: an ephemeral row shadowing a
        // persisted one (or the reverse) would let two clocks answer to one
        // name, and the lock-file stem is shared too.
        if (isset($this->rows[$schedule->name]) || isset($this->ephemeral[$schedule->name])) {
            throw new \InvalidArgumentException("A schedule named '{$schedule->name}' already exists.");
        }
        if ($schedule->persisted && $this->path === null) {
            throw new \InvalidArgumentException(
                'No vouchsafe home directory: persisted schedules cannot be registered (a /loop row still works).',
            );
        }

        if ($schedule->persisted) {
            $this->rows[$schedule->name] = $schedule;
        } else {
            $this->ephemeral[$schedule->name] = $schedule;
        }
    }

    /** @return bool whether a row of that name existed */
    public function remove(string $name): bool
    {
        $existed = isset($this->rows[$name]) || isset($this->ephemeral[$name]);
        unset($this->rows[$name], $this->ephemeral[$name], $this->fired[$name]);

        return $existed;
    }

    public function setEnabled(string $name, bool $enabled): ?Schedule
    {
        $row = $this->get($name);
        if ($row === null) {
            return null;
        }
        $updated = $row->withEnabled($enabled);
        if ($row->persisted) {
            $this->rows[$name] = $updated;
        } else {
            $this->ephemeral[$name] = $updated;
        }

        return $updated;
    }

    /**
     * Replace one row's clock slot. Used by the pumps: the ephemeral fire path
     * advances in memory only, and the persisted path is advanced by
     * {@see fireDue()} itself.
     */
    public function noteNextDue(string $name, int $nextDue): void
    {
        $row = $this->get($name);
        if ($row === null) {
            return;
        }
        $updated = $row->withNextDue($nextDue);
        if ($row->persisted) {
            $this->rows[$name] = $updated;
        } else {
            $this->ephemeral[$name] = $updated;
        }
    }

    /** Write the PERSISTED rows (and only those) back to disk. */
    public function persist(): void
    {
        if ($this->path === null) {
            throw new \RuntimeException('This registry has no backing file to persist to.');
        }
        $payload = [
            self::FILE_VERSION_KEY => self::VERSION,
            self::FILE_SCHEDULES_KEY => array_values(array_map(
                static fn (Schedule $row): array => $row->toArray(),
                $this->rows,
            )),
        ];
        AtomicJsonFile::new($this->path)->withPermissions(0o600)->write($payload);
        $this->latch = $this->statLatch();
    }

    /**
     * Read the file if its stat stamp moved since the last touch. A host that
     * edited schedules.json by hand (or another host's persist) lands here
     * within one tick, without this process holding a watcher.
     *
     * @return bool whether the roster was reloaded
     */
    public function reloadIfChanged(): bool
    {
        if ($this->path === null) {
            return false;
        }
        $stat = $this->statLatch();
        if ($stat === $this->latch) {
            return false;
        }
        $this->load();

        return true;
    }

    /**
     * Evaluate every due PERSISTED row: claim the row's lock, skip when
     * another host holds it or a run of this name is still in flight, advance
     * the clock, spawn through $spawn, and remember the session so the pump
     * can {@see clearFired()} when it settles.
     *
     * @param \Closure(Schedule, int): (?string) $spawn receives the row and the
     *        tick's now-stamp, returns the spawned session id or null; throws
     *        to report a refused spawn
     * @return list<array{name: string, action: string, sessionId?: string, error?: string}>
     */
    public function fireDue(int $now, \Closure $spawn): array
    {
        $results = [];
        foreach ($this->due($now) as $row) {
            if (!$row->persisted) {
                continue; // the Chat pump owns the ephemeral half
            }
            $name = $row->name;
            if (isset($this->fired[$name])) {
                // The previous run is still going: skip this slot but move the
                // clock, so the row does not fire the instant it settles and a
                // wedged daemon cannot pile up a backlog of fires.
                $this->advance($row, $now);
                $results[] = ['name' => $name, 'action' => 'skipped-busy', 'sessionId' => $this->fired[$name]];

                continue;
            }
            $lock = $this->claimLock($name);
            if ($lock === null) {
                // Another host won the claim and is persisting the advance
                // right now; do not touch the clock, reload will catch up.
                $results[] = ['name' => $name, 'action' => 'skipped-locked'];

                continue;
            }
            try {
                $this->advance($row, $now);
                try {
                    $sessionId = $spawn($row, $now);
                } catch (\Throwable $e) {
                    // The advance STAYS: a refused launch announces once per
                    // period, never once per tick, and a transient handshake
                    // failure costs one slot rather than hot-looping.
                    $results[] = ['name' => $name, 'action' => 'error', 'error' => $e->getMessage()];

                    continue;
                }
                if ($sessionId !== null) {
                    $this->fired[$name] = $sessionId;
                }
                $results[] = ['name' => $name, 'action' => 'fired']
                    + ($sessionId === null ? [] : ['sessionId' => $sessionId]);
            } finally {
                $this->releaseLock($lock);
            }
        }

        return $results;
    }

    /**
     * Roll the clock forward past every missed slot and persist. Split from
     * the fire so the skipped-busy path advances too (that is the point: a
     * still-running row is exactly the row we must not fire twice).
     */
    private function advance(Schedule $row, int $now): void
    {
        $this->noteNextDue($row->name, $row->nextDueAfter($now));
        $this->persist();
    }

    /** Mark the row busy with the session that is running it. */
    public function noteFired(string $name, string $sessionId): void
    {
        $this->fired[$name] = $sessionId;
    }

    /** The daemon whose run still counts as this row's current fire, if any. */
    public function busySession(string $name): ?string
    {
        return $this->fired[$name] ?? null;
    }

    /** Called by the pump when the named row's session settles (or vanishes). */
    public function clearFired(string $name): void
    {
        unset($this->fired[$name]);
    }

    /** @return list<string> names with a run still in flight */
    public function firedNames(): array
    {
        return array_keys($this->fired);
    }

    /**
     * Read schedules.json. Never throws: every failure mode becomes a named
     * entry in {@see loadErrors()} with the roster (re)built from whatever is
     * trustworthy.
     */
    public function load(): void
    {
        $this->loadErrors = [];
        $this->rows = [];
        if ($this->path === null) {
            return;
        }
        $this->latch = $this->statLatch();
        $file = AtomicJsonFile::new($this->path);
        try {
            $document = $file->read();
        } catch (\Throwable $e) {
            $this->loadErrors[] = 'schedules.json could not be read: ' . $e->getMessage();

            return;
        }
        if ($document === []) {
            return; // absent file is simply an empty roster
        }
        $version = $document[self::FILE_VERSION_KEY] ?? null;
        if ($version !== self::VERSION) {
            $shown = is_scalar($version) ? var_export($version, true) : get_debug_type($version);
            $this->loadErrors[] = "schedules.json declares version {$shown}; this build writes version " . self::VERSION . '.';

            return;
        }
        $rows = $document[self::FILE_SCHEDULES_KEY] ?? [];
        if (!is_array($rows)) {
            $this->loadErrors[] = 'schedules.json has no "schedules" array.';

            return;
        }
        $now = time();
        foreach (array_values($rows) as $index => $raw) {
            if (!is_array($raw)) {
                $this->loadErrors[] = "schedule #{$index} is not an object.";

                continue;
            }
            try {
                $row = Schedule::fromArray($raw, $now);
            } catch (\InvalidArgumentException $e) {
                $this->loadErrors[] = "schedule #{$index}: " . $e->getMessage();

                continue;
            }
            if (isset($this->rows[$row->name])) {
                $this->loadErrors[] = "schedule '{$row->name}' appears twice; the later row was skipped.";

                continue;
            }
            $this->rows[$row->name] = $row;
        }
    }

    /**
     * Non-blocking claim of one row's lock, SessionLock-shaped: create,
     * tighten the mode BEFORE any payload, flock|NB, dev/ino race guard.
     *
     * @return array{handle: resource, path: string}|null null when the lock is
     *         held elsewhere (or the directory cannot be made private — a host
     *         that cannot prove its lock directory must not fire at all)
     */
    private function claimLock(string $name): ?array
    {
        if ($this->path === null) {
            return null;
        }
        $dir = dirname($this->path) . '/schedules';
        try {
            PrivateDir::ensure($dir, 'schedule lock directory');
        } catch (\Throwable $e) {
            return null;
        }
        $lockPath = $dir . '/' . $name . '.lock';
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $handle = @fopen($lockPath, 'c');
            if ($handle === false) {
                return null;
            }
            if (@chmod($lockPath, 0o600) === false) {
                fclose($handle);

                return null;
            }
            $wouldBlock = 0;
            if (!@flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
                fclose($handle);

                return null; // held by another host (or a torn open): this tick skips
            }
            $held = fstat($handle);
            clearstatcache(true, $lockPath);
            $atPath = @stat($lockPath);
            if ($held === false || $atPath === false
                || $held['ino'] !== $atPath['ino'] || $held['dev'] !== $atPath['dev']
            ) {
                // The path swapped under the handle (planted file, rotation):
                // release, re-open, try again rather than trust the inode.
                flock($handle, LOCK_UN);
                fclose($handle);

                continue;
            }
            // The holder's pid, informational only — the lock IS the flock.
            @fwrite($handle, (string) getmypid());

            return ['handle' => $handle, 'path' => $lockPath];
        }

        return null;
    }

    /** @param array{handle: resource, path: string} $lock */
    private function releaseLock(array $lock): void
    {
        @flock($lock['handle'], LOCK_UN);
        @fclose($lock['handle']);
    }

    /** @return array{0: int|false, 1: int|false, 2: int|false}|null mtime/size/ino stamp, null when stat itself fails */
    private function statLatch(): ?array
    {
        if ($this->path === null) {
            return null;
        }
        clearstatcache(true, $this->path);
        $stat = @stat($this->path);
        if ($stat === false) {
            return null;
        }

        return [$stat['mtime'], $stat['size'], $stat['ino']];
    }
}
