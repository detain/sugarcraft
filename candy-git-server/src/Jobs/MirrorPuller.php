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

    /** @var array<string, int> repo name => last pull attempt (unix time) */
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
            fn (Repo $r) => $this->schedule->isDue($this->lastPull[$r->name] ?? null, $now),
        ));
    }

    /** When the last pull of a repo was attempted (null = never). */
    public function lastPullAt(string $repoName): ?int
    {
        return $this->lastPull[$repoName] ?? null;
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
     * Fetch a single mirror from its upstream. The attempt is recorded
     * even on failure — and on refusal — so a broken or unfit config
     * cannot hot-loop the job.
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

        $this->lastPull[$repo->name] = $now ?? \time();

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
    }

    /**
     * Why an operator-supplied upstream URL is unfit to fetch, or null.
     */
    private static function upstreamRefusal(string $url, bool $allowGit = false): ?string
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
