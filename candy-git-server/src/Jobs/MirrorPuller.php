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
 * plaintext `http://` ships credentials over the wire), no leading dash
 * (an option like `--upload-pack=cmd` would be parsed as a flag), and the
 * fetch runs as an argv vector through {@see BoundedCommand} — no shell,
 * no quoting to forget, a hard deadline, and GIT_TERMINAL_PROMPT=0 so a
 * credentialed upstream can never park the tick on an interactive prompt.
 * Failure logging redacts any password embedded in the URL.
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
    /** Transports an operator may mirror over, after lowercasing. */
    public const ALLOWED_SCHEMES = ['https', 'ssh', 'git'];

    /** Wall-clock ceiling for one upstream fetch (git can be slow, not forever). */
    public const FETCH_TIMEOUT_SECONDS = 300.0;

    private Schedule $schedule;

    /** @var array<string, Repo> repo name => Repo */
    private array $repos = [];

    /** @var array<string, int> repo name => last pull attempt (unix time) */
    private array $lastPull = [];

    /**
     * Process-execution seam: fn(list<string> $argv): array{code:int, output:list<string>}.
     *
     * @var \Closure(list<string>): array{code: int, output: list<string>}
     */
    private \Closure $exec;

    private ?LoopInterface $loop = null;
    private ?TimerInterface $timer = null;

    /**
     * @param \Closure|null $exec Process-execution seam for tests; the
     *                            default runs argv vectors through
     *                            {@see BoundedCommand::run()}.
     * @throws \InvalidArgumentException if the configured schedule is invalid
     */
    public function __construct(Config $config, ?\Closure $exec = null)
    {
        $this->schedule = Schedule::parse($config->mirrorPullSchedule);
        $this->exec = $exec ?? static function (array $argv): array {
            return BoundedCommand::run($argv, self::FETCH_TIMEOUT_SECONDS, ['GIT_TERMINAL_PROMPT' => '0']);
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
     * even on failure — and on refusal — so a broken or unfit config
     * cannot hot-loop the job.
     */
    public function pull(Repo $repo, ?int $now = null): bool
    {
        if (!$repo->isMirror()) {
            return false;
        }

        $this->lastPull[$repo->name] = $now ?? \time();

        $url = (string) $repo->mirrorFrom;
        $refusal = self::upstreamRefusal($url);
        if ($refusal !== null) {
            // The refusal names the problem, never the URL: a hostile
            // config drop should not get its payload echoed into logs.
            \error_log("candy-git-server: mirror pull refused for {$repo->name}: {$refusal}");

            return false;
        }

        $result = ($this->exec)([
            'git', '-C', $repo->path(), 'fetch', '--prune', $url, '+refs/*:refs/*',
        ]);

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
    private static function upstreamRefusal(string $url): ?string
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
