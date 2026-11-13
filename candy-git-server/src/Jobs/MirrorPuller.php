<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Jobs;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use SugarCraft\GitServer\Config;
use SugarCraft\GitServer\Repo;
use SugarCraft\GitServer\Support\BoundedCommand;

/**
 * Mirror-pull background job.
 *
 * Honors the `jobs.mirror_pull` schedule that {@see Config} parses;
 * repos are mirrors when they carry an upstream pull URL
 * ({@see Repo::isMirror()} / `withMirrorFrom()`); each due mirror is
 * refreshed with `git -C <path> fetch --prune <url> '+refs/*:refs/*'` —
 * the full-mirror refspec so deleted upstream refs prune locally,
 * matching soft-serve's `git remote update --prune` semantics for
 * `--mirror` clones.
 *
 * The upstream URL is operator-supplied, so it passes a safety envelope
 * before any child is spawned: an explicit scheme from
 * {@see self::ALLOWED_SCHEMES} only (git's `ext::` transport is remote
 * code execution dressed as a URL; `file://` reads the server's own disk;
 * plaintext `http://` ships credentials over the wire; and `git://` —
 * finding 070 — authenticates neither host nor content, so it is refused
 * by default and admitted only under an explicit
 * git.mirror_allow_git_protocol opt-in, loudly logged on every pull),
 * no leading dash (an option like `--upload-pack=cmd` would be parsed as
 * a flag), and the fetch runs as an argv vector through
 * {@see BoundedCommand} — no shell, no quoting to forget, a hard
 * deadline, and GIT_TERMINAL_PROMPT=0 so a credentialed upstream can
 * never park the tick on an interactive prompt. Failure logging redacts
 * any password embedded in the URL.
 *
 * Dual-mode, like the rest of the lib: {@see attach()} registers a
 * periodic timer on a ReactPHP loop (the `serveAsync()` path);
 * {@see runOnce()} is the synchronous entry point a blocking host can
 * call from its own housekeeping (or a cron job).
 *
 * TICK ENVELOPE (finding 005): a batch of due mirrors used to pull with
 * no wall bound at all — N mirrors × FETCH_TIMEOUT_SECONDS of blocked
 * host, and on the {@see attach()} path that block froze the SHARED
 * event loop for its whole duration: the pre-fix worst case is a batch
 * of hung upstreams freezing every other client of the async server for
 * N×300 s. A tick now carries a wall budget — half the schedule interval,
 * capped at {@see TICK_BUDGET_MAX_SECONDS}, strictly below one interval by
 * construction — every fetch gets the REMAINING budget as its deadline,
 * mirrors the budget ran out on stay unstamped (and therefore due) for
 * the next tick, and the tick refuses to overlap: a second entry while
 * one runs logs the skip and returns zero rather than interleaving
 * pulls.
 *
 * Mirrors charmbracelet/soft-serve's mirror job.
 *
 * CADENCE AND RACE (S25, finding 044): last-attempt stamps persist in
 * the repo's metadata plane, so a fresh cron process inherits the
 * schedule instead of treating every mirror as due; and each fetch runs
 * under a per-repo non-blocking flock, so racing ticks serialize on one
 * pull rather than racing '+refs/*:refs/*' ref-updates whose refusals
 * are silent on the wire.
 */
final class MirrorPuller
{
    /**
     * Transports an operator may mirror over BY DEFAULT, after lowercasing
     * (finding 070). git:// is NOT here: it authenticates neither the host
     * nor the payload, so a mirror admitted over it is whatever the wire
     * said it was. It joins the accepted set only under the explicit
     * opt-in git.mirror_allow_git_protocol.
     */
    public const ALLOWED_SCHEMES = ['https', 'ssh'];

    /** The unauthenticated daemon transport, usable only via opt-in. */
    public const GIT_SCHEME = 'git';

    /** Wall-clock ceiling for one upstream fetch (git can be slow, not forever). */
    public const FETCH_TIMEOUT_SECONDS = 300.0;

    /**
     * Ceiling on ONE tick's wall budget: the batch of due mirrors may
     * block its host at most this long (finding 005 — pre-fix, N hung
     * upstreams meant N×FETCH_TIMEOUT_SECONDS of frozen event loop). The
     * effective budget is min(this, half the schedule interval) so a tick
     * always finishes — and defers — before the next one is due.
     */
    public const TICK_BUDGET_MAX_SECONDS = 60.0;

    /** True while a runOnce() batch is executing (overlap guard). */
    private bool $inTick = false;

    /** git.mirror_allow_git_protocol as read at construction (finding 070). */
    private readonly bool $allowGit;

    private Schedule $schedule;

    /** @var array<string, Repo> repo name => Repo */
    private array $repos = [];

    /** @var array<string, int> repo name => last pull attempt (unix time);
     *     in-process cache of the truth — the durable stamp lives in each
     *     repo's metadata plane (S25, finding 044) */
    private array $lastPull = [];

    /**
     * Process-execution seam: fn(list<string> $argv, float $timeoutSeconds):
     * array{code:int, output:list<string>}. The timeout arrives PER FETCH —
     * the tick's remaining budget — so the runner cannot outlive the tick
     * that invoked it.
     *
     * @var \Closure(list<string>, float): array{code: int, output: list<string>}
     */
    private \Closure $exec;

    private ?LoopInterface $loop = null;
    private ?TimerInterface $timer = null;

    /**
     * @param \Closure|null $exec Process-execution seam for tests; the
     *                            default runs argv vectors through
     *                            {@see BoundedCommand::run()} with the
     *                            per-fetch timeout the tick hands it.
     * @throws \InvalidArgumentException if the configured schedule is invalid
     */
    public function __construct(Config $config, ?\Closure $exec = null)
    {
        $this->allowGit = $config->mirrorAllowGitProtocol;
        $this->schedule = Schedule::parse($config->mirrorPullSchedule);
        $this->exec = $exec ?? static function (array $argv, float $timeoutSeconds = self::FETCH_TIMEOUT_SECONDS): array {
            return BoundedCommand::run($argv, $timeoutSeconds, ['GIT_TERMINAL_PROMPT' => '0']);
        };
    }

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function registerRepo(Repo $repo): void
    {
        $this->repos[$repo->name] = $repo;
    }

    /** @param iterable<Repo> $repos */
    public function registerRepos(iterable $repos): void
    {
        foreach ($repos as $repo) {
            $this->repos[$repo->name] = $repo;
        }
    }

    // -------------------------------------------------------------------------
    // Selection
    // -------------------------------------------------------------------------

    public function schedule(): Schedule
    {
        return $this->schedule;
    }

    /** @return list<Repo> registered repos that mirror an upstream */
    public function mirrors(): array
    {
        return \array_values(\array_filter($this->repos, static fn (Repo $r) => $r->isMirror()));
    }

    /** @return list<Repo> mirrors whose schedule interval has elapsed */
    public function dueMirrors(?int $now = null): array
    {
        $now ??= \time();

        return \array_values(\array_filter(
            $this->mirrors(),
            fn (Repo $r) => $this->schedule->isDue($this->effectiveLastPull($r), $now),
        ));
    }

    /** When the last pull of a repo was attempted (null = never). */
    public function lastPullAt(string $repoName): ?int
    {
        $repo = $this->repos[$repoName] ?? null;

        return $repo === null ? ($this->lastPull[$repoName] ?? null) : $this->effectiveLastPull($repo);
    }

    /**
     * The cadence memory finding 044 lacked: this process's stamp if it
     * has one, else the metadata plane's persisted stamp (a fresh cron
     * process re-reads disk instead of re-pulling everything), and the
     * LATER of the two when both exist.
     */
    public function effectiveLastPull(Repo $repo): ?int
    {
        $mem = $this->lastPull[$repo->name] ?? null;
        $disk = \is_dir($repo->gitDir()) ? $repo->mirrorLastAttempt() : null;
        if ($mem === null) {
            return $disk;
        }
        if ($disk === null) {
            return $mem;
        }

        return \max($mem, $disk);
    }

    // -------------------------------------------------------------------------
    // Pulling
    // -------------------------------------------------------------------------

    /** Wall-clock budget one runOnce() batch may spend (S05, finding 005). */
    public function tickBudgetSeconds(): float
    {
        // min(cap, half the interval): a tick defers and yields long
        // before the next one is due, whatever the operator's cadence.
        return \max(1.0, \min(self::TICK_BUDGET_MAX_SECONDS, $this->schedule->intervalSeconds / 2.0));
    }

    /**
     * Pull every due mirror now. Synchronous — callable from a blocking
     * host's housekeeping or standalone (e.g. cron).
     *
     * S05 (finding 005): the batch carries a wall budget
     * ({@see tickBudgetSeconds()}, or $budgetSeconds when the host knows
     * better); the moment it is spent, the remaining mirrors are DEFERRED
     * — not failed: they were never stamped, so they are due again on the
     * next tick. Each fetch receives its own share: the runner's timeout
     * is the budget still left, capped at FETCH_TIMEOUT_SECONDS. Overlap
     * is refused: a host that re-enters mid-batch (a synchronous seam
     * calling runOnce, a housekeeping pass beside an attached timer) gets
     * a logged skip and zero, never two interleaved pull batches.
     *
     * S25 (finding 044): last-attempt stamps live in the repo's metadata
     * plane, not just this process — the README-recommended cron shape
     * (a FRESH php process per tick) used to re-pull every mirror on
     * every tick because in-memory `null` reads as due. The overlap guard
     * is likewise cross-process now: a per-repo non-blocking flock, so
     * two cron ticks racing the same mirror serialize on ONE pull
     * instead of racing `+refs/*:refs/*` ref-updates whose refusals
     * were silent. A mirror whose lock is held is skipped WITHOUT a
     * stamp (the winner's batch is the attempt; the loser stays
     * honestly scheduled by the winner's stamp).
     *
     * @return int number of mirrors pulled successfully
     */
    public function runOnce(?int $now = null, ?float $budgetSeconds = null): int
    {
        if ($this->inTick) {
            \error_log('candy-git-server: mirror pull skipped — a tick is already running');

            return 0;
        }

        $now ??= \time();
        $budget = $budgetSeconds ?? $this->tickBudgetSeconds();
        $deadlineNs = \hrtime(true) + (int) ($budget * 1e9);
        $this->inTick = true;
        try {
            $pulled = 0;
            foreach ($this->dueMirrors($now) as $repo) {
                $leftNs = $deadlineNs - \hrtime(true);
                if ($leftNs <= 0) {
                    // Deferred mirrors stay unstamped (due next tick) —
                    // the budget exhaustion is not their failure.
                    \error_log('candy-git-server: mirror pull tick budget exhausted; remaining mirrors defer to the next tick');

                    break;
                }
                $timeout = \min(
                    self::FETCH_TIMEOUT_SECONDS,
                    \max(0.1, $leftNs / 1e9)
                );
                if ($this->pull($repo, $now, $timeout)) {
                    $pulled++;
                }
            }

            return $pulled;
        } finally {
            $this->inTick = false;
        }
    }

    /**
     * Pull a single mirror from its upstream. The attempt is recorded
     * even on failure — and on refusal — so a broken or unfit config
     * cannot hot-loop the job. The stamp is BOTH in-memory (this
     * process's cadence) and persisted to the metadata plane (finding
     * 044), and the whole fetch runs under the repo's non-blocking
     * mirror lock: a concurrent cron tick holding it means the pull is
     * already happening, and this call skips with a log line.
     *
     * @param float|null $timeoutSeconds per-fetch wall ceiling; defaults
     *                                   to the full FETCH_TIMEOUT_SECONDS
     *                                   (a standalone pull, not a tick)
     */
    public function pull(Repo $repo, ?int $now = null, ?float $timeoutSeconds = null): bool
    {
        if (!$repo->isMirror()) {
            return false;
        }

        $lockHandle = $this->acquireMirrorLock($repo);
        if ($lockHandle === null) {
            \error_log("candy-git-server: mirror pull skipped for {$repo->name} — a concurrent pull holds its lock");

            return false;
        }

        try {
            $stampAt = $now ?? \time();
            $this->lastPull[$repo->name] = $stampAt;
            $this->stampAttempt($repo, $stampAt);

            $url = (string) $repo->mirrorFrom;
            $refusal = self::upstreamRefusal($url, $this->allowGit);
            if ($refusal !== null) {
                // The refusal names the problem, never the URL: a hostile
                // config drop should not get its payload echoed into logs.
                \error_log("candy-git-server: mirror pull refused for {$repo->name}: {$refusal}");

                return false;
            }
            if ($this->allowGit && \stripos($url, self::GIT_SCHEME . '://') === 0) {
                // Admitted under opt-in: say so on every pull, so an operator
                // who flipped the key for a trusted LAN mirror still sees the
                // unauthenticated transport in the log when something smells
                // wrong later.
                \error_log("candy-git-server: mirroring {$repo->name} over git:// under git.mirror_allow_git_protocol — unauthenticated transport");
            }

            $result = ($this->exec)([
                'git', '-C', $repo->path(), 'fetch', '--prune', $url, '+refs/*:refs/*',
            ], $timeoutSeconds ?? self::FETCH_TIMEOUT_SECONDS);

            if ($result['code'] !== 0) {
                \error_log("candy-git-server: mirror pull failed for {$repo->name}: "
                    . self::redactCredentials(\implode(' ', $result['output']), $url));

                return false;
            }

            return true;
        } finally {
            $this->releaseMirrorLock($lockHandle);
        }
    }

    /**
     * Claim the per-repo mirror lock without blocking (finding 044).
     * Returns the handle to keep open until the batch ends, or null when
     * the lock is held elsewhere. A repo whose git directory does not
     * exist (library-caller VOs over plain temp paths) runs UNLOCKED:
     * there is no lockable inode and no cron racing them either — the
     * pull itself will fail honestly downstream.
     *
     * @return resource|null
     */
    private function acquireMirrorLock(Repo $repo)
    {
        $gitDir = $repo->gitDir();
        if (!\is_dir($gitDir)) {
            return 'unlocked';
        }
        $lock = @\fopen($gitDir . '/' . Repo::MIRROR_LOCK_FILE, 'c');
        if ($lock === false) {
            // Lock state unavailable — refusing the pull would hand an
            // attacker a DoS by simply making the file unreadable; the
            // documented posture is fail-open-with-log for scheduling
            // hardening (not a security gate).
            \error_log("candy-git-server: mirror lock for {$repo->name} could not be opened; running unlocked");

            return 'unlocked';
        }
        @\chmod($gitDir . '/' . Repo::MIRROR_LOCK_FILE, 0600);
        if (!\flock($lock, \LOCK_EX | \LOCK_NB)) {
            @\fclose($lock);

            return null;
        }

        return $lock;
    }

    /** @param resource|string|null $handle */
    private function releaseMirrorLock($handle): void
    {
        if (\is_resource($handle)) {
            @\flock($handle, \LOCK_UN);
            @\fclose($handle);
        }
    }

    /**
     * Persist the attempt stamp through the metadata plane's lock. A
     * failure to stamp is LOUD but does not abort the pull — the fetch
     * already happened (or is happening); losing the cadence memory is
     * the pre-044 status quo, not a new fault.
     */
    private function stampAttempt(Repo $repo, int $when): void
    {
        if (!\is_dir($repo->gitDir())) {
            return;
        }
        try {
            $repo->stampMirrorAttempt($when);
        } catch (\RuntimeException $e) {
            \error_log("candy-git-server: mirror stamp for {$repo->name} could not be persisted: " . $e->getMessage());
        }
    }

    /**
     * Why an operator-supplied upstream URL is unfit to fetch, or null.
     * Public (S25, finding 012): the CLI consults the SAME admission at
     * the door, so `repo mirror` refuses ext::/scheme-garbage before it
     * can ever sit in the plane waiting for a tick to complain.
     */
    public static function upstreamRefusal(string $url, bool $allowGit = false): ?string
    {
        if ($url === '') {
            return 'empty upstream URL';
        }
        if ($url[0] === '-') {
            return 'upstream URL starts with a dash (git would parse it as an option)';
        }
        if (\stripos($url, 'ext::') === 0) {
            return 'the git ext:: transport executes commands and is refused';
        }

        $scheme = \parse_url($url, \PHP_URL_SCHEME);
        if (\is_string($scheme) && \strtolower($scheme) === self::GIT_SCHEME) {
            if (!$allowGit) {
                // Named refusal, not roster fall-through: the operator who
                // set a git:// upstream must learn WHICH key to flip.
                return 'the git:// transport authenticates neither host nor content — a mirror over it is '
                    . 'trusting the wire; set git.mirror_allow_git_protocol: true to accept that for this upstream';
            }

            return null; // opted in: admitted with the warning already logged
        }
        if (!\is_string($scheme) || !\in_array(\strtolower($scheme), self::ALLOWED_SCHEMES, true)) {
            return 'upstream scheme must be one of ' . \implode(', ', self::ALLOWED_SCHEMES)
                . ' (scp-style "host:path" needs the explicit ssh:// form)';
        }

        return null;
    }

    /**
     * Mask `scheme://user:password@` credentials in a message — first the
     * configured URL itself, then anywhere else the text picked it up
     * (git quotes the remote in its own failure lines).
     */
    private static function redactCredentials(string $text, string $url): string
    {
        $pattern = '#(://[^/@\s:]*:)[^@\s/]*@#';
        $masked = \preg_replace($pattern, '$1***@', $url);
        if (\is_string($masked) && $masked !== $url) {
            $text = \str_replace($url, $masked, $text);
        }

        return \preg_replace($pattern, '$1***@', $text) ?? $text;
    }

    // -------------------------------------------------------------------------
    // Event-loop integration
    // -------------------------------------------------------------------------

    /**
     * Schedule the job on a ReactPHP loop: {@see runOnce()} fires every
     * schedule interval (the async-daemon counterpart of calling
     * runOnce() from blocking housekeeping).
     */
    public function attach(?LoopInterface $loop = null): void
    {
        if ($this->timer !== null) {
            throw new \RuntimeException('MirrorPuller is already attached; call detach() first.');
        }

        $this->loop = $loop ?? Loop::get();
        $this->timer = $this->loop->addPeriodicTimer(
            (float) $this->schedule->intervalSeconds,
            fn () => $this->runOnce(),
        );
    }

    /** Cancel the loop timer. Idempotent. */
    public function detach(): void
    {
        if ($this->timer !== null) {
            $this->loop?->cancelTimer($this->timer);
        }
        $this->timer = null;
        $this->loop = null;
    }

    public function isAttached(): bool
    {
        return $this->timer !== null;
    }
}
