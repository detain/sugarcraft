<?php

declare(strict_types=1);

namespace SugarCraft\Serve\Jobs;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use SugarCraft\Serve\Config;
use SugarCraft\Serve\Repo;

/**
 * Mirror-pull background job (plan item 7.5).
 *
 * Honors the `jobs.mirror_pull` schedule that {@see Config} has carried
 * as a dead string since the initial port. Repos are mirrors when they
 * carry an upstream pull URL ({@see Repo::isMirror()} /
 * `withMirrorFrom()`); each due mirror is refreshed with
 * `git -C <path> fetch --prune <url> '+refs/*:refs/*'` — the
 * full-mirror refspec so deleted upstream refs prune locally, matching
 * soft-serve's `git remote update --prune` semantics for
 * `--mirror` clones.
 *
 * Dual-mode, like the rest of the lib: {@see attach()} registers a
 * periodic timer on a ReactPHP loop (the `serveAsync()` path);
 * {@see runOnce()} is the synchronous entry point a blocking host can
 * call from its own housekeeping (or a cron job).
 *
 * Mirrors charmbracelet/soft-serve's mirror job.
 */
final class MirrorPuller
{
    private Schedule $schedule;

    /** @var array<string, Repo> repo name => Repo */
    private array $repos = [];

    /** @var array<string, int> repo name => last pull attempt (unix time) */
    private array $lastPull = [];

    /** Exec seam: fn(string $cmd): array{int, list<string>} => [exit code, output lines]. */
    private \Closure $exec;

    private ?LoopInterface $loop = null;
    private ?TimerInterface $timer = null;

    /**
     * @param \Closure|null $exec Process-execution seam for tests; the
     *                            default shells out via \exec().
     * @throws \InvalidArgumentException if the configured schedule is invalid
     */
    public function __construct(Config $config, ?\Closure $exec = null)
    {
        $this->schedule = Schedule::parse($config->mirrorPullSchedule);
        $this->exec = $exec ?? static function (string $cmd): array {
            $out = [];
            $rc = 0;
            \exec($cmd, $out, $rc);

            return [$rc, $out];
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

    /**
     * Pull every due mirror now. Synchronous — callable from a blocking
     * host's housekeeping or standalone (e.g. cron).
     *
     * @return int number of mirrors pulled successfully
     */
    public function runOnce(?int $now = null): int
    {
        $now ??= \time();
        $pulled = 0;
        foreach ($this->dueMirrors($now) as $repo) {
            if ($this->pull($repo, $now)) {
                $pulled++;
            }
        }

        return $pulled;
    }

    /**
     * Fetch a single mirror from its upstream. The attempt is recorded
     * even on failure so a broken upstream cannot hot-loop the job.
     */
    public function pull(Repo $repo, ?int $now = null): bool
    {
        if (!$repo->isMirror()) {
            return false;
        }

        $this->lastPull[$repo->name] = $now ?? \time();

        $path = \escapeshellarg($repo->path());
        $url = \escapeshellarg((string) $repo->mirrorFrom);
        $refspec = \escapeshellarg('+refs/*:refs/*');
        [$rc, $out] = ($this->exec)("git -C {$path} fetch --prune {$url} {$refspec} 2>&1");

        if ($rc !== 0) {
            \error_log("candy-serve: mirror pull failed for {$repo->name}: " . \implode(' ', $out));

            return false;
        }

        return true;
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
