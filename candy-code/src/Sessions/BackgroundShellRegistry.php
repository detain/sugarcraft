<?php

declare(strict_types=1);

namespace SugarCraft\Code\Sessions;

use SugarCraft\Code\Support\HomeDirectory;
use SugarCraft\Code\Support\PrivateDir;
use SugarCraft\Code\Support\ProcessContainment;

/**
 * The launch's table of background shells — the state behind Bash's
 * `run_in_background`, `ShellOutput` and `ShellStop` (SHL-1).
 *
 * WHY A TABLE AT ALL: `Bash`'s synchronous path reaps its child inside the
 * call, so the model has no way to keep a process running between turns and
 * the human's `!cmd` channel is not its own. A background shell therefore
 * has to be owned by SOMETHING that outlives one tool call and is visible to
 * every later one. That owner is this registry — one per launch and root,
 * exactly like the {@see \SugarCraft\Code\Tools\ReadLedger} it is modelled
 * on, handed to Bash/ShellOutput/ShellStop through the one
 * {@see \SugarCraft\Code\Tools\Catalog\ToolBuildContext} the tools are built
 * from, so the three tools cannot disagree about which shells exist.
 *
 * WHY NOT A `BackgroundSupervisor` SESSION (the rejected alternative, kept
 * here so it stays rejected): a supervisor session is a full double-forked
 * daemon with a UNIX-socket handshake, a runner script, and a stalled/
 * settled status machine. A shell needs none of that: the containment spawn
 * already puts it in its own session and process group, its output already
 * fits on disk, and its answer is an exit code. Running shells as sessions
 * would pay the handshake and the daemon machinery for a `sleep 60` and
 * would inherit a lifecycle the shell did not ask for.
 *
 * THE GROUP IS THE UNIT OF OWNERSHIP. Every child is spawned through
 * {@see ProcessContainment::spawnSpec()}, so under `setsid -w` the direct
 * child pid IS the process-group id (and session id) by construction, and
 * every later signal goes to the GROUP — a shell that backgrounded children
 * takes them down with it. The pid is recorded AT SPAWN while the leader is
 * certainly alive (ISS-014, the same law `runCaptured()` follows):
 * re-deriving it after the leader exits would signal a recycled pid.
 *
 * THE GROUP OUTLIVES THE LEADER'S PROCESS TABLE ENTRY (MAJOR-2). When the
 * leader is fully reaped, {@see ProcessContainment::terminatePid()} can no
 * longer confirm the group through posix_getpgid() — both the group branch
 * and its per-pid fallback answer ESRCH, and the signal lands nowhere while
 * TERM-proof members keep running. Where {@see groupMemberAlive()} has just
 * proved live processes carry the recorded pgid, the ladder and the teardown
 * sweep signal the group DIRECTLY instead ({@see signalShellGroup()}) — that
 * is what makes the teardown guarantee true for corpse leaders. RESIDUAL
 * RISK, ACCEPTED AND WRITTEN DOWN: pid/pgid reuse. If this launch's group
 * has fully died and an unrelated process later takes the recorded pid as
 * its own group leader, the member census cannot distinguish the lineage,
 * and a late sweep would signal that stranger's group. The window is bounded
 * by teardown, every signal is gated on members carrying the recorded pgid,
 * and the exposure is the same one every pid-caching killer accepts.
 *
 * OUTPUT LANDS IN FILES, NOT PIPES. A background shell has no reader on the
 * parent side between calls, and keeping a pipe open across a later fork
 * hands the write end into every tool child — the fd-inheritance family the
 * descriptor guards police. So stdout/stderr are `proc_open()` file sinks
 * under a 0700 directory (`~/.candy-code/shells/<session>/`), settled 0600
 * before any payload — the `MediaStore` doctrine — and a shell's third file,
 * `<id>.exit`, is written LAST by the spawn wrapper itself. The exit file's
 * EXISTENCE-and-contents are the durable "settled" marker: no reader is
 * needed to learn that a shell finished, only to learn its code.
 *
 * LIFETIMES ARE BOUNDED. A shell may not outlive the max-lifetime wall
 * unnoticed: {@see status()}, {@see read()} and {@see stop()} enforce it
 * lazily (a still-live shell past the wall gets the TERM→grace→KILL ladder
 * and is reported expired). This is a RESOURCE bound on the launch's process
 * table — the same class of rule as `timeout` on the synchronous path, a
 * bound on the SHELL, never on a model request — so it is E646-clean.
 * {@see teardown()} is the hard guarantee: every live shell is killed and
 * every handle released.
 *
 * THE PAYLOAD CROSSES THE FORK AS PLAIN ARRAYS. Under the forked tool-child
 * path ({@see \SugarCraft\Code\Tools\CarriesSessionState}) a child's rows
 * ride `exportSessionState()` to the parent; `proc_open()` resources stay
 * UNSERIALISED in the local `$handles` map, and a child's inherited copies
 * are dropped on merge ({@see forgetHandles()}) so only the process that
 * owns a child keeps a handle to it. {@see merge()} is order-independent: a
 * row reporting an exit code beats one that does not, and among equally
 * informed rows the later record wins, so applying the same export twice is
 * a no-op.
 */
final class BackgroundShellRegistry
{
    /** The key the tools' {@see \SugarCraft\Code\Tools\CarriesSessionState} export carries the rows under. */
    public const SESSION_STATE_KEY = 'backgroundShells';

    /** Environment arm naming the exit-code file, handed to the spawn wrapper. */
    public const EXIT_CODE_FILE_ENV = 'SHELL_EXIT_CODE_FILE';

    /** Permission bits every shell file carries for its whole life (the MediaStore doctrine). */
    public const FILE_MODE = 0o600;

    /** Ceiling on shells tracked at once; a launch at the cap must stop one before starting another. */
    public const MAX_SHELLS = 64;

    /**
     * Wall a background shell may live for. DECISION (SHL-1): thirty
     * minutes — long enough to outlive every build or test suite a turn
     * would plausibly detach, short enough that a forgotten `while true`
     * cannot accumulate across a day-long session (the row cap and the
     * per-shell files bound the debris; this bounds the processes). The
     * model is TOLD the number in Bash's schema, so a deliberate long-runner
     * is restarted by asking, and `ShellStop` is the polite exit ramp.
     */
    public const MAX_LIFETIME_SECONDS = 1800;

    /** Grace between the TERM and the KILL rung of the stop ladder, and the confirmation budget after it. */
    public const TERM_GRACE_SECONDS = 2.0;

    /** Default answer size for one {@see read()}; the tool clamps a model-supplied figure to {@see MAX_READ_BYTES}. */
    public const DEFAULT_READ_BYTES = 65536;

    /** Hard ceiling on bytes one {@see read()} hands back, whatever the caller asked for. */
    public const MAX_READ_BYTES = 262144;

    /** Poll cadence while the stop ladder waits for the group to go quiet. */
    private const STOP_POLL_SECONDS = 0.01;

    /**
     * @var array<string, array<string, mixed>> rows keyed by shell id, insertion-ordered
     */
    private array $rows = [];

    /**
     * Live `proc_open()` handles for shells THIS process spawned directly.
     * Deliberately outside the exported payload: a resource cannot cross the
     * fork boundary, and the parent owns the child regardless.
     *
     * @var array<string, resource>
     */
    private array $handles = [];

    /**
     * @param (callable(float): void)|null $sleeper poll-sleep seam for the stop ladder (tests inject a no-op)
     */
    private function __construct(
        private readonly string $directory,
        private readonly ?\Closure $sleeper = null,
    ) {}

    /**
     * Open the shell table for one session. `$root` relocates the tree
     * wholesale (a test seam); the default roots at
     * {@see HomeDirectory::owned()} — an unestablishable home throws rather
     * than parking a shell log under a world-writable stand-in. The
     * directory itself is created on first {@see start()}, so building the
     * registry touches no disk.
     *
     * @param (callable(float): void)|null $sleeper
     *
     * @throws \RuntimeException when the home cannot be established.
     */
    public static function forSession(string $sessionId, ?string $root = null, ?callable $sleeper = null): self
    {
        $root ??= self::defaultRoot();
        $safe = self::sanitizeSessionId($sessionId);

        return new self(rtrim($root, '/\\') . '/' . $safe, $sleeper === null ? null : \Closure::fromCallable($sleeper));
    }

    /** The HOME-rooted tree every default session shares one level above. */
    public static function defaultRoot(): string
    {
        // The house doctrine is `HomeDirectory::owned() . '/.candy-code/…'`
        // (agents, teams, commands, workflows, servers all live there); a
        // bare `$HOME/shells` would litter the home directory itself.
        return HomeDirectory::owned() . '/.candy-code/shells';
    }

    /** Absolute path of this table's session directory (created lazily by {@see start()}). */
    public function directoryPath(): string
    {
        return $this->directory;
    }

    /**
     * Spawn `$command` detached and record it. The command string is the
     * CALLER's job (Bash builds the same `bash -c` + cd-guard + sandbox
     * stack the synchronous path runs); this method adds only the exit-code
     * trailer, the file sinks, and the containment spawn.
     *
     * @param array<string, string> $envOverrides handed to {@see ProcessContainment::scrubbedEnv()} and applied after the scrub
     *
     * @return array{ok: bool, error?: string, shell?: array<string, mixed>}
     */
    public function start(string $command, string $label, array $envOverrides = []): array
    {
        // The cap counts TABLE SIZE, not liveness: a row this process cannot
        // observe (a merged export, a zombie awaiting the launch's reaper) is
        // still a shell the launch has taken responsibility for, and a cap
        // that ignored those could be walked past by an accumulation of rows
        // nobody can currently see.
        if (count($this->rows) >= self::MAX_SHELLS) {
            return ['ok' => false, 'error' => sprintf(
                'this launch already tracks %d background shells, which is the cap; stop one with ShellStop first',
                self::MAX_SHELLS,
            )];
        }

        $id = $this->mintId();
        $paths = $this->pathFor($id);
        $failure = $this->sinkFiles($paths);
        if ($failure !== null) {
            return ['ok' => false, 'error' => $failure];
        }

        // The command runs inside `( ... )` so an `exit N` in the user's own
        // text ends the SUBSHELL, never the wrapper — a bare append would let
        // `exit 3` skip the trailer entirely (measured). The trailer then
        // stamps `$?` into the exit file — whose path rides the env, so no
        // figure is interpolated into the quoted string — and re-exits with
        // the command's own code. Built from single-quoted segments because
        // PHP interpolates `$SHELL_STATUS` inside a double-quoted string
        // exactly as eagerly as `$foo` — only quoting keeps the child's
        // variables verbatim. A wrapper killed before the trailer leaves the
        // exit file empty: the honest `exitCode: null` of a signalled shell.
        $trailer = chr(10) . 'SHELL_STATUS=$?; printf "%s" "$SHELL_STATUS" > "$' . self::EXIT_CODE_FILE_ENV . '"; exit "$SHELL_STATUS"';
        $wrapped = 'bash -c ' . escapeshellarg('(' . $command . ')' . $trailer);
        $spawnSpec = ProcessContainment::spawnSpec($wrapped);
        $env = ProcessContainment::scrubbedEnv($envOverrides + [self::EXIT_CODE_FILE_ENV => $paths['exit']]);
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $paths['stdout'], 'w'],
            2 => ['file', $paths['stderr'], 'w'],
        ];

        error_clear_last();
        $process = @proc_open($spawnSpec, $descriptors, $pipes, $this->directory, $env);
        if (!\is_resource($process)) {
            $cause = error_get_last()['message'] ?? 'proc_open() failed';

            return ['ok' => false, 'error' => 'the background command was not started: ' . $cause];
        }

        // pid recorded NOW, while the leader is certainly alive (ISS-014):
        // under the containment wrapper this pid is also the pgid by
        // construction, which is why `pgid` simply copies it.
        $status = proc_get_status($process);
        $pid = (int) $status['pid'];
        $this->handles[$id] = $process;
        $this->rows[$id] = [
            'id' => $id,
            'label' => $label,
            'pid' => $pid,
            'pgid' => $pid,
            'stdoutPath' => $paths['stdout'],
            'stderrPath' => $paths['stderr'],
            'exitPath' => $paths['exit'],
            'spawnedAt' => \microtime(true),
            'exitCode' => null,
            'stopped' => false,
            'expired' => false,
        ];

        return ['ok' => true, 'shell' => $this->status($id)];
    }

    /**
     * One row with its LIVE state resolved, plus `ageSeconds` and the
     * lifetime verdict. Enforces the max-lifetime wall on a live shell
     * before answering.
     *
     * @return array<string, mixed>|null null when the id is not in the table
     */
    public function status(string $shellId): ?array
    {
        $row = $this->rows[$shellId] ?? null;
        if ($row === null) {
            return null;
        }
        $row = $this->enforceLifetime($shellId, $row);
        $state = $this->observe($shellId, $row);
        $row['state'] = $state['state'];
        $row['exitCode'] = $state['exitCode'];
        $row['ageSeconds'] = \max(0.0, \microtime(true) - (float) $row['spawnedAt']);
        $this->rows[$shellId] = $row;

        return $row;
    }

    /**
     * The output of one shell from `$cursor` forward — a byte offset over
     * stdout THEN stderr, in that order, so a caller threads ONE number
     * through repeated reads. At most $maxBytes (clamped to
     * {@see MAX_READ_BYTES}, default {@see DEFAULT_READ_BYTES}) come back.
     * Only a DEFAULT fresh read is tail-wins: when its backlog exceeds the
     * budget the OLDEST bytes are skipped and `skippedBytes` says how many.
     * An explicit window or a threaded cursor paginates without skipping —
     * pagination never destroys unread output. The returned `cursor` is the
     * exact offset just past the last byte handed back; it never rewinds and
     * never lies about position.
     *
     * @return array{ok: bool, error?: string, stdout?: string, stderr?: string, cursor?: int, skippedBytes?: int, bytesAvailable?: int, state?: string, exitCode?: ?int, expired?: bool}
     */
    public function read(string $shellId, int $cursor = 0, ?int $maxBytes = null): array
    {
        $row = $this->status($shellId);
        if ($row === null) {
            return ['ok' => false, 'error' => 'no background shell ' . $shellId . ' is registered by this launch'];
        }
        $limit = $maxBytes === null ? self::DEFAULT_READ_BYTES : \max(0, \min(self::MAX_READ_BYTES, $maxBytes));
        $cursor = \max(0, $cursor);

        $stdoutSize = (int) (@filesize((string) $row['stdoutPath']) ?: 0);
        $stderrSize = (int) (@filesize((string) $row['stderrPath']) ?: 0);
        // filesize() can only fail on a vanished sink; a vanished sink reads
        // as zero bytes, which is the honest "nothing there" answer.
        $available = $stdoutSize + $stderrSize;
        $skipped = 0;
        if ($cursor > $available) {
            // The files got SMALLER under a monotone cursor (a shell that
            // truncated its own log): restart at the only offset that reads.
            $cursor = $available;
        }
        $remaining = $available - $cursor;
        if ($maxBytes === null && $cursor === 0 && $remaining > $limit) {
            // Tail-wins applies to a DEFAULT fresh read: nobody chose a
            // window, and the newest output is the useful answer, so the
            // oldest bytes are skipped and `skippedBytes` says how many.
            // Once the caller threads a cursor — or asks for an explicit
            // window — pagination owes every byte at or above the cursor;
            // dropping some to honour the budget would silently destroy
            // unread output. A huge backlog is simply walked with more steps.
            $skipped = $remaining - $limit;
            $cursor += $skipped;
        }

        // The cursor is one monotone offset over the CONCATENATION
        // stdout-then-stderr: each file's slice starts at the cursor measured
        // from that stream's own beginning, so the per-file offsets are the
        // cursor clamped into stdout and the cursor-minus-stdout-size clamped
        // into stderr (never negative, never past a stream's end).
        $stdoutOffset = \min($cursor, $stdoutSize);
        $stderrOffset = \max(0, \min($cursor - $stdoutSize, $stderrSize));
        $stdout = $this->sliceFrom((string) $row['stdoutPath'], $stdoutOffset, $limit);
        $stderrBudget = $limit - \strlen($stdout['bytes']);
        $stderr = $this->sliceFrom((string) $row['stderrPath'], $stderrOffset, $stderrBudget);
        // The answered cursor is the global offset just past the last byte
        // handed back: the entry cursor plus what was RETURNED, per file. A
        // window that only fits part of stdout leaves stderr pending — the
        // next step from the advanced cursor collects it. Advancing past
        // unreturned bytes would silently strand a stream.
        $cursor += \strlen($stdout['bytes']) + \strlen($stderr['bytes']);

        return [
            'ok' => true,
            'stdout' => $stdout['bytes'],
            'stderr' => $stderr['bytes'],
            'cursor' => $cursor,
            'skippedBytes' => $skipped,
            'bytesAvailable' => $available,
            'state' => (string) $row['state'],
            'exitCode' => $row['exitCode'],
            'expired' => (bool) $row['expired'],
        ];
    }

    /**
     * Stop one shell: TERM to the group, a bounded grace, KILL to the same
     * the same group, then a bounded confirmation. Unknown ids answer
     * `found` false; an already-settled shell is a no-op success — the
     * ladder never signals a pid it has no claim on.
     *
     * @return array{found: bool, wasRunning: bool, confirmed: bool, exitCode: ?int, expired: bool}
     */
    public function stop(string $shellId): array
    {
        $row = $this->rows[$shellId] ?? null;
        if ($row === null) {
            return ['found' => false, 'wasRunning' => false, 'confirmed' => false, 'exitCode' => null, 'expired' => false];
        }
        $wasRunning = $this->observe($shellId, $row)['state'] === 'running';
        $confirmed = true;
        if ($wasRunning) {
            $confirmed = $this->runKillLadder($shellId, (int) $row['pid']);
            $row['stopped'] = true;
            $this->rows[$shellId] = $row;
        }
        $settled = $this->status($shellId) ?? $row;

        return [
            'found' => true,
            'wasRunning' => $wasRunning,
            'confirmed' => $confirmed,
            'exitCode' => $settled['exitCode'],
            'expired' => (bool) $settled['expired'],
        ];
    }

    /**
     * Kill every live shell (TERM→grace→KILL each) and release every
     * handle. The launch's teardown guarantee: no background child of this
     * registry survives it. Output files stay on disk for a post-mortem read.
     */
    public function teardown(): void
    {
        foreach (array_keys($this->rows) as $id) {
            $this->stop((string) $id);
        }
        foreach ($this->handles as $process) {
            if (\is_resource($process)) {
                @proc_terminate($process);
            }
        }
        // Final group sweep: a row can look settled (leader reaped, even an
        // exit file written by a `cmd & exit 0` shape) while members of its
        // group outlive it — TERM-proof or merely orphaned. The teardown
        // guarantee is about the GROUP: no background process of this
        // registry survives the launch, so every still-living group gets a
        // KILL after the polite pass, unconditionally. The kill goes through
        // signalShellGroup() so a CORPSE leader — fully reaped, invisible to
        // posix_getpgid() — cannot route it into ESRCH and strand the
        // members the census just proved alive (MAJOR-2).
        foreach ($this->rows as $row) {
            $pid = (int) ($row['pid'] ?? 0);
            if ($pid > 0 && self::groupMemberAlive($pid)) {
                self::signalShellGroup($pid, 9);
            }
        }
        // Drop the map; each resource closes with its last reference. NOT
        // proc_close(): reaping belongs to the launch's wait machinery, and a
        // blocking close here would couple teardown to a child's schedule.
        $this->handles = [];
    }

    /** @return list<string> the ids in the table, insertion order */
    public function ids(): array
    {
        return array_map('strval', array_keys($this->rows));
    }

    /**
     * The plain-array payload for the fork boundary: scalars only, by
     * construction (no handles — those live in the local `$handles` map).
     *
     * @return array<string, array<string, mixed>>
     */
    public function toArray(): array
    {
        return $this->rows;
    }

    /**
     * Fold an export from a forked child (or the same export again —
     * idempotent by the laws below). A row that reports an exit code beats
     * one that does not; among equally informed rows the later `spawnedAt`
     * wins. Malformed rows are skipped, never fatal.
     *
     * @param mixed $payload
     */
    public function merge(mixed $payload): void
    {
        if (!\is_array($payload)) {
            return;
        }
        foreach ($payload as $id => $row) {
            if (!\is_string($id) || !\is_array($row)) {
                continue;
            }
            $clean = $this->parseRow($id, $row);
            if ($clean === null) {
                continue;
            }
            $current = $this->rows[$id] ?? null;
            if ($current !== null && !$this->rowWins($clean, $current)) {
                continue;
            }
            $this->rows[$id] = $clean;
        }
        // Two children each at the cap could merge past it; trim the oldest
        // SETTLED rows, and stop rather than evicting a live shell.
        while (\count($this->rows) > self::MAX_SHELLS && $this->evictOldestSettled()) {
        }
    }

    /**
     * Drop the inherited handle copies for $ids — called on the PARENT after
     * merging a forked child's rows, so the parent's fd table keeps one
     * handle per child it actually spawned. The child closed its own copies
     * when it exited; this is the parent letting go of the copies IT
     * inherited at fork and never owned a use for.
     *
     * @param list<string> $ids
     */
    public function forgetHandles(array $ids): void
    {
        foreach ($ids as $id) {
            unset($this->handles[$id]);
        }
    }

    /** The ids whose spawn this process itself performed (handle present). */
    public function localIds(): array
    {
        return array_map('strval', array_keys($this->handles));
    }

    /**
     * Resolve a row's live state without claiming more than this process can
     * see, in strict order of authority:
     *
     *  1. a settled `exitCode` already on the row — final, never re-observed;
     *  2. the exit FILE — the durable marker the wrapper writes; its contents
     *     are the code;
     *  3. a live local handle — `proc_get_status()` PEELS without reaping;
     *     `running === false` settles the row;
     *  4. no handle and no exit file: `kill(pid, 0)` answers, but a ZOMBIE
     *     answers signal 0 too, so an exited-but-unreaped child reads as
     *     "running" until the launch reaps it — the honest limit of
     *     file-based bookkeeping, written down rather than papered over.
     *
     * THE EXIT FILE IS THE WRAPPER'S TRUTH, NOT THE GROUP'S (documented
     * boundary, deliberate): route 2 settles the row WITHOUT a member check,
     * so a `nohup X & exit 0` shape answers `finished` while X keeps running
     * inside the group — stop() on such a row is a no-op by design and cannot
     * reach X. The launch-wide sweep in teardown() is the backstop that kills
     * the orphaned group; the member census lives on the handle route (3),
     * where the wrapper's own death is exactly the moment being judged.
     *
     * @param array<string, mixed> $row
     * @return array{state: string, exitCode: ?int}
     */
    private function observe(string $id, array $row): array
    {
        if (isset($row['exitCode']) && \is_int($row['exitCode'])) {
            return ['state' => 'finished', 'exitCode' => (int) $row['exitCode']];
        }
        $code = $this->readExitFile($row);
        if ($code !== null) {
            $this->settle($id, $code);
            $row['exitCode'] = $code;

            return ['state' => 'finished', 'exitCode' => $code];
        }
        $handle = $this->handles[$id] ?? null;
        if (\is_resource($handle)) {
            $status = @proc_get_status($handle);
            if (\is_array($status)) {
                if ($status['running'] === false) {
                    // Exited under our hands; the wrapper still wrote the
                    // exit file first (it precedes the exit), but read it
                    // directly — the code is what the answer needs. A DEAD
                    // LEADER IS NOT THE SHELL: the unit of life is the
                    // process group, and a member that survived the leader
                    // keeps the shell running (MAJOR-1: leader-only death
                    // let TERM-proof members outlive every later signal).
                    $code = $this->readExitFile($row);
                    if ($code === null && self::groupMemberAlive((int) $row['pid'])) {
                        return ['state' => 'running', 'exitCode' => null];
                    }

                    return ['state' => 'finished', 'exitCode' => $code];
                }

                return ['state' => 'running', 'exitCode' => null];
            }
            // 'unknown' — someone else reaped our child; fall through to the
            // env-level answers rather than claim a state we cannot see.
            unset($this->handles[$id]);
        }
        if (\function_exists('posix_kill')) {
            $leader = @posix_kill((int) $row['pid'], 0);

            return ['state' => ($leader || self::groupMemberAlive((int) $row['pid'])) ? 'running' : 'unknown', 'exitCode' => null];
        }

        return ['state' => 'unknown', 'exitCode' => null];
    }

    /**
     * TERM the group, grace, KILL the group, grace. Confirmation is GROUP
     * death, never leader death: the recorded pid leads a process group, and
     * a member that traps TERM outlives its leader — a ladder that accepted
     * "the wrapper exited" would report a shell as stopped while a trapped
     * survivor kept running, unreachable by anything afterwards (the group
     * id points at a dead leader). The ladder is given the leader PID, never
     * the recorded pgid: terminatePid() takes a pid and consults
     * posix_getpgid() itself, and because spawnSpec makes the child's pid its
     * group id, passing the leader pid IS the group kill. (Feeding it the
     * pgid as if it were a pid looks identical and signals nobody.) A fully
     * reaped leader blinds that consult — the rungs therefore go through
     * {@see signalShellGroup()}, which falls back to the direct group signal
     * the moment the member census proves the group is live (MAJOR-2).
     */
    private function runKillLadder(string $id, int $pid): bool
    {
        self::signalShellGroup($pid, 15);
        if ($this->awaitGroupDeath($id, $pid, self::TERM_GRACE_SECONDS)) {
            return true;
        }
        self::signalShellGroup($pid, 9);

        return $this->awaitGroupDeath($id, $pid, self::TERM_GRACE_SECONDS);
    }

    /**
     * Signal the shell's process group, surviving a corpse leader.
     *
     * The documented route (terminatePid) group-kills only while
     * posix_getpgid() still answers $pid === $pid; once the leader has been
     * fully reaped both its pgid lookup and its per-pid signal answer ESRCH,
     * and TERM-proof members outlive every rung the launch fires. When that
     * route delivers nothing, {@see groupMemberAlive()} is the proof that
     * live processes still carry $pid as their pgid — so the group gets the
     * signal directly. A group with no proven members is never signalled:
     * the recorded pid may already belong to a stranger, and pgid -X could
     * alias pid X on a kill.
     */
    private static function signalShellGroup(int $pid, int $signal): void
    {
        if (ProcessContainment::terminatePid($pid, $signal)) {
            return;
        }
        if ($pid > 0 && \function_exists('posix_kill') && self::groupMemberAlive($pid)) {
            @posix_kill(-$pid, $signal);
        }
    }

    /**
     * Poll until NO live (non-zombie) member of the shell's process group
     * remains. A zombie leader holds the group id in `kill(-pgid, 0)`
     * answers, so the Linux path reads /proc and discounts state Z; the
     * fallback (no /proc) probes the group with signal 0. The exit file is
     * DELIBERATELY not consulted here: it proves the wrapper finished, not
     * that the group died (a `nohup X & exit 0` writes one with X alive —
     * the boundary documented on observe()), and accepting it would stop the
     * ladder early and strand exactly the members the confirmation exists
     * to catch.
     */
    private function awaitGroupDeath(string $id, int $pid, float $budgetSeconds): bool
    {
        $deadline = \microtime(true) + $budgetSeconds;
        while (true) {
            if (!isset($this->rows[$id]) || !$this::groupMemberAlive($pid)) {
                return true;
            }
            if (\microtime(true) >= $deadline) {
                return !self::groupMemberAlive($pid);
            }
            $this->sleep(self::STOP_POLL_SECONDS);
        }
    }

    /**
     * True while at least one NON-ZOMBIE process carries $pgid as its
     * process group. /proc scan on Linux (a zombie must not keep a dead
     * shell "alive"); signal-0 group probe elsewhere.
     */
    private static function groupMemberAlive(int $pgid): bool
    {
        if (\is_dir('/proc')) {
            foreach ((array) @\scandir('/proc') as $entry) {
                if ($entry === '' || $entry[0] === '.' || !\ctype_digit($entry)) {
                    continue;
                }
                $stat = @\file_get_contents('/proc/' . $entry . '/stat');
                if (!\is_string($stat)) {
                    continue; // vanished mid-scan
                }
                // comm may contain spaces: parse AFTER the last ')'.
                $close = \strrpos($stat, ')');
                if ($close === false) {
                    continue;
                }
                $fields = \explode(' ', \trim(\substr($stat, $close + 1)));
                // fields after comm: state ppid pgid ...
                if (($fields[0] ?? '') !== 'Z' && (int) ($fields[2] ?? -1) === $pgid) {
                    return true;
                }
            }

            return false;
        }

        return \function_exists('posix_kill') && @posix_kill(-$pgid, 0);
    }

    /**
     * Enforce the wall on a live shell: past {@see MAX_LIFETIME_SECONDS} the
     * ladder runs and the row is stamped expired. A settled row is returned
     * untouched. NOTE the recursion guard: enforceLifetime may ONLY be
     * called from a path that has already observed the row through a route
     * that does NOT itself re-enforce — observe() never calls this, and
     * liveCount() counts without status().
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function enforceLifetime(string $id, array $row): array
    {
        if ($row['expired'] === true) {
            return $row;
        }
        if ($this->observe($id, $row)['state'] !== 'running') {
            return $row;
        }
        if (\microtime(true) - (float) $row['spawnedAt'] <= self::MAX_LIFETIME_SECONDS) {
            return $row;
        }
        $this->runKillLadder($id, (int) $row['pid']);
        $row['expired'] = true;
        $this->rows[$id] = $row;

        return $row;
    }

    /**
     * Read up to $limit bytes of $path starting at $cursor.
     *
     * @return array{bytes: string, cursor: int}
     */
    private function sliceFrom(string $path, int $cursor, int $limit): array
    {
        $size = (int) (@filesize($path) ?: 0);
        if ($limit <= 0 || $cursor >= $size) {
            return ['bytes' => '', 'cursor' => $cursor];
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false || @fseek($handle, $cursor) !== 0) {
            if (\is_resource($handle ?? null)) {
                fclose($handle);
            }

            return ['bytes' => '', 'cursor' => $cursor];
        }
        $bytes = (string) @fread($handle, $limit);
        $read = ftell($handle);
        fclose($handle);

        return ['bytes' => $bytes, 'cursor' => \is_int($read) ? $read : $cursor + \strlen($bytes)];
    }

    /**
     * Create the 0600 sinks BEFORE proc_open runs (proc_open's file mode
     * would create them under the process umask instead). `fopen 'xb'`
     * refuses to follow a planted link at the target name; an existing file
     * from a crashed predecessor is re-settled, not followed.
     *
     * @param array{stdout: string, stderr: string, exit: string} $paths
     */
    private function sinkFiles(array $paths): ?string
    {
        PrivateDir::ensure($this->directory, 'background shell registry');
        foreach ([$paths['stdout'], $paths['stderr'], $paths['exit']] as $path) {
            $handle = @fopen($path, 'xb');
            if ($handle === false) {
                if (is_file($path)) {
                    @chmod($path, self::FILE_MODE);
                    continue;
                }

                return 'cannot create the output file ' . $path;
            }
            @chmod($path, self::FILE_MODE);
            fclose($handle);
        }

        return null;
    }

    /** @return array{stdout: string, stderr: string, exit: string} */
    private function pathFor(string $id): array
    {
        return [
            'stdout' => $this->directory . '/' . $id . '.out',
            'stderr' => $this->directory . '/' . $id . '.err',
            'exit' => $this->directory . '/' . $id . '.exit',
        ];
    }

    /** Collision-checked id mint; 4 random hex bytes against a 64-row table cannot practically collide, and a hit simply mints again. */
    private function mintId(): string
    {
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $id = 'sh-' . bin2hex(random_bytes(4));
            if (!isset($this->rows[$id])) {
                return $id;
            }
        }

        throw new \RuntimeException('BackgroundShellRegistry: cannot mint a free shell id');
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $current
     */
    private function rowWins(array $candidate, array $current): bool
    {
        $candidateInformed = $candidate['exitCode'] !== null || $candidate['stopped'] === true || $candidate['expired'] === true;
        $currentInformed = $current['exitCode'] !== null || $current['stopped'] === true || $current['expired'] === true;
        if ($candidateInformed !== $currentInformed) {
            return $candidateInformed;
        }

        return (float) $candidate['spawnedAt'] >= (float) $current['spawnedAt'];
    }

    /**
     * Validate a merged row at the boundary; a malformed row is dropped, and
     * nothing downstream re-checks what this accepted. The PATHS must be the
     * ones this tree derives for the id — an export naming files elsewhere
     * describes a shell this launch can neither read nor kill truthfully.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function parseRow(string $id, array $row): ?array
    {
        if (preg_match('/^sh-[0-9a-f]{8}$/', $id) !== 1) {
            return null;
        }
        foreach (['pid', 'pgid'] as $field) {
            if (!isset($row[$field]) || !\is_int($row[$field]) || $row[$field] <= 0) {
                return null;
            }
        }
        $paths = $this->pathFor($id);
        foreach (['stdoutPath' => $paths['stdout'], 'stderrPath' => $paths['stderr'], 'exitPath' => $paths['exit']] as $field => $expected) {
            if (($row[$field] ?? null) !== $expected) {
                return null;
            }
        }
        if (!isset($row['spawnedAt']) || !(\is_float($row['spawnedAt']) || \is_int($row['spawnedAt']))) {
            return null;
        }
        $exitCode = $row['exitCode'] ?? null;
        if ($exitCode !== null && !\is_int($exitCode)) {
            return null;
        }

        return [
            'label' => \is_string($row['label'] ?? null) ? $row['label'] : '',
            'pid' => (int) $row['pid'],
            'pgid' => (int) $row['pgid'],
            'stdoutPath' => $paths['stdout'],
            'stderrPath' => $paths['stderr'],
            'exitPath' => $paths['exit'],
            'spawnedAt' => (float) $row['spawnedAt'],
            'exitCode' => $exitCode,
            'stopped' => ($row['stopped'] ?? false) === true,
            'expired' => ($row['expired'] ?? false) === true,
        ];
    }

    /** Evict the oldest settled row; false when every row is live (the cap goes advisory, never lethal). */
    private function evictOldestSettled(): bool
    {
        foreach ($this->rows as $id => $row) {
            if ($row['exitCode'] !== null || $row['stopped'] === true || $row['expired'] === true) {
                unset($this->rows[$id]);

                return true;
            }
        }

        return false;
    }

    private function settle(string $id, int $code): void
    {
        if (isset($this->rows[$id])) {
            $this->rows[$id]['exitCode'] = $code;
        }
    }

    /** The code the wrapper wrote, or null while the file is absent or empty. */
    private function readExitFile(array $row): ?int
    {
        $raw = @file_get_contents((string) $row['exitPath']);
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        if (preg_match('/^-?\d+$/', trim($raw)) !== 1) {
            return null;
        }

        return (int) trim($raw);
    }

    private function sleep(float $seconds): void
    {
        $sleeper = $this->sleeper;
        if ($sleeper !== null) {
            $sleeper($seconds);

            return;
        }
        \usleep((int) ($seconds * 1_000_000));
    }

    private static function sanitizeSessionId(string $id): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '-', $id) ?? '';
        $clean = trim($clean, '.-');

        return $clean === '' ? 'session' : substr($clean, 0, 64);
    }
}
