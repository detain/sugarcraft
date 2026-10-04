<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Workspace;

use SugarCraft\Crush\Support\ProcessContainment;
use SugarCraft\Crush\Tools\Concerns\CapturesProcessOutput;

/**
 * One bounded `git` invocation at a time, for the code that drives git on
 * the app's own behalf — workspace checkpoints (item 3.A-1), auto-commit
 * (3.G) next — and for the reads it makes for the model: the environment
 * block's git fields and the `@diff` mention ({@see capture()}).
 *
 * WHY IT RIDES {@see CapturesProcessOutput} INSTEAD OF SPAWNING ITS OWN
 * CHILD. That trait is the package's one bounded spawn path: `setsid -w`
 * detach (git's credential prompt cannot reach the user's terminal), the
 * non-interactive environment, pipes drained to completion and a wall-clock
 * deadline that ends in the reaper's 15→9 ladder. A second spawn site would
 * have to re-prove all of that, and it would be one more row for
 * `DescriptorInheritanceGuardTest` and `tools/check-child-lifetimes.php` to
 * account for. Through the trait every git child is already accounted for.
 *
 * WHAT THE USER'S ENVIRONMENT IS NOT ALLOWED TO CHANGE. Every call runs with
 * the inherited `GIT_DIR`/`GIT_WORK_TREE`/`GIT_INDEX_FILE` family unset — a
 * session launched from inside a git hook, or with `GIT_DIR` exported, would
 * otherwise snapshot some other repository — then with this runner's own
 * index file and identity set. The `-c` options switch off what the user's
 * config could add to a private plumbing call: a pager, colour, quoted
 * paths, commit signing (a passphrase prompt nobody can answer), hooks
 * (`reference-transaction` runs on every `update-ref`), fsmonitor and a
 * detached background gc. `--no-optional-locks` keeps the read-only calls
 * from taking the user's index lock under a running `git` of their own.
 *
 * Immutable: every `with*()` returns a copy.
 */
final class GitRunner
{
    use CapturesProcessOutput;

    /** The bound on any single call when the caller sets none. */
    public const DEFAULT_TIMEOUT_SECONDS = 10.0;

    /**
     * Bytes of each stream retained per call. Listings (`ls-files -z`) are
     * the large outputs; past this the caller is told the output was cut
     * rather than handed a silently partial list.
     */
    public const MAX_OUTPUT_BYTES = 16 * 1024 * 1024;

    /** The identity commit objects are written under. */
    public const IDENTITY_NAME = 'sugar-crush';
    public const IDENTITY_EMAIL = 'checkpoint@sugar-crush.invalid';

    /**
     * Inherited variables that would point git somewhere other than where
     * this runner says.
     */
    private const SCRUBBED_GIT_ENV = [
        'GIT_DIR',
        'GIT_WORK_TREE',
        'GIT_INDEX_FILE',
        'GIT_OBJECT_DIRECTORY',
        'GIT_ALTERNATE_OBJECT_DIRECTORIES',
        'GIT_COMMON_DIR',
        'GIT_NAMESPACE',
        'GIT_PREFIX',
        'GIT_CEILING_DIRECTORIES',
        'GIT_CONFIG_PARAMETERS',
        'GIT_CONFIG_COUNT',
    ];

    private const GLOBAL_OPTIONS = [
        '--no-pager',
        '--no-optional-locks',
        '-c', 'core.quotepath=false',
        '-c', 'color.ui=false',
        '-c', 'commit.gpgSign=false',
        '-c', 'core.hooksPath=/dev/null',
        '-c', 'core.fsmonitor=false',
        '-c', 'gc.autoDetach=false',
        '-c', 'maintenance.auto=false',
    ];

    private const BUDGET_SPENT = 'checkpoint time budget spent';

    /**
     * The lock rule as an environment variable too, for anything git runs on
     * this runner's behalf that does not inherit `--no-optional-locks`.
     */
    private const ENV = ['GIT_OPTIONAL_LOCKS' => '0'];

    private function __construct(
        private readonly string $cwd,
        private readonly ?string $gitDir = null,
        private readonly ?string $workTree = null,
        private readonly ?string $indexFile = null,
        private readonly float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        private readonly ?float $deadline = null,
        private readonly bool $inheritGitEnv = false,
    ) {}

    /** A runner whose calls start in $cwd and let git discover the repository. */
    public static function new(string $cwd): self
    {
        return new self($cwd);
    }

    /** Whether a `git` executable is on PATH at all — a stat walk, no spawn. */
    public static function available(): bool
    {
        return ProcessContainment::locateOnPath('git') !== '';
    }

    /** Pin the repository (`--git-dir`) and, optionally, its work tree. */
    public function withGitDir(string $gitDir, ?string $workTree = null): self
    {
        return $this->mutate(['gitDir' => $gitDir, 'workTree' => $workTree]);
    }

    /** Run against $indexFile (`GIT_INDEX_FILE`) instead of the repository's own index. */
    public function withIndexFile(?string $indexFile): self
    {
        return $this->mutate(['indexFile' => $indexFile]);
    }

    /**
     * Keep the inherited `GIT_*` location and config variables instead of
     * scrubbing them. For a READ of the user's repository as the user would
     * see it — the environment block, `@diff` — where an exported
     * `GIT_CEILING_DIRECTORIES` (or `GIT_DIR`) is the user's own statement of
     * where their repository is. A checkpoint pins its repository with
     * {@see withGitDir()} and must not be redirected, so it never sets this.
     */
    public function withInheritedGitEnv(bool $inherit = true): self
    {
        return $this->mutate(['inheritGitEnv' => $inherit]);
    }

    /** Bound each call at $seconds. */
    public function withTimeout(float $seconds): self
    {
        return $this->mutate(['timeoutSeconds' => max(0.001, $seconds)]);
    }

    /**
     * Share one wall-clock budget across every call: a call starting after
     * $epochSeconds fails as timed out without spawning, and one starting
     * before it is bounded by whichever of the two limits comes first.
     */
    public function withDeadline(?float $epochSeconds): self
    {
        return $this->mutate(['deadline' => $epochSeconds]);
    }

    public function cwd(): string
    {
        return $this->cwd;
    }

    public function gitDir(): ?string
    {
        return $this->gitDir;
    }

    public function workTree(): ?string
    {
        return $this->workTree;
    }

    /** The shared wall-clock deadline, or null when only the per-call bound applies. */
    public function deadline(): ?float
    {
        return $this->deadline;
    }

    /**
     * Run `git <args>`.
     *
     * @return array{ok: bool, stdout: string, stderr: string, exitCode: int, timedOut: bool}
     */
    public function run(string ...$args): array
    {
        return $this->invoke(null, $args);
    }

    /**
     * Run `git <args>` with $input on its stdin — `update-index --stdin`,
     * `update-ref --stdin`. The input goes through a private temp file and a
     * shell redirect, because the bounded spawn closes the child's stdin pipe
     * at once by design.
     *
     * @return array{ok: bool, stdout: string, stderr: string, exitCode: int, timedOut: bool}
     */
    public function runWithInput(string $input, string ...$args): array
    {
        $file = @tempnam(sys_get_temp_dir(), 'sc-git-');
        if ($file === false) {
            return self::failure('could not create a temp file for git input');
        }

        try {
            @chmod($file, 0o600);
            if (@file_put_contents($file, $input) !== \strlen($input)) {
                return self::failure('could not write git input to ' . $file);
            }

            return $this->invoke($file, $args);
        } finally {
            @unlink($file);
        }
    }

    /**
     * Run `git <args>` retaining at most $maxBytes of each stream (null: no
     * bound) and hand back the capture's OWN account of the cut — the bytes
     * each stream dropped and whether the cut landed mid-line — for a caller
     * that announces truncation itself rather than failing on it.
     *
     * The read path for code that reads the repository on the MODEL's behalf:
     * {@see \SugarCraft\Crush\Context\EnvironmentBlock}'s per-request git
     * fields (it used to carry a private copy of this spawn, with a shorter
     * option list) and the `@diff` mention (roadmap 5.8). Same scrubbed
     * environment, `-c` pins and bounded spawn as {@see run()}; only the
     * result shape differs.
     *
     * @return array{stdout: string, stderr: string, exitCode: int, truncatedBytes: int, stdoutDropped: int, stderrDropped: int, stdoutMidLine: bool, stderrMidLine: bool, timedOut: bool}
     */
    public function capture(?int $maxBytes, string ...$args): array
    {
        $timeout = $this->callTimeout();
        if ($timeout === null) {
            return [
                'stdout' => '',
                'stderr' => self::BUDGET_SPENT,
                'exitCode' => 124,
                'truncatedBytes' => 0,
                'stdoutDropped' => 0,
                'stderrDropped' => 0,
                'stdoutMidLine' => false,
                'stderrMidLine' => false,
                'timedOut' => true,
            ];
        }

        return $this->runCaptured($this->commandLine(null, $args), $this->cwd, $maxBytes, $timeout, self::ENV);
    }

    /**
     * The `sh` command line {@see run()} and {@see capture()} spawn, for a
     * caller with no bounded spawn left to use — {@see \SugarCraft\Crush\Context\EnvironmentBlock}'s
     * branch read on a build with `proc_open` disabled hands it to
     * `shell_exec` — so even that fallback reads the repository under the
     * same scrubbed environment and options. It does not change directory:
     * such a caller passes `-C <dir>` in $args.
     */
    public function shellCommand(string ...$args): string
    {
        return $this->commandLine(null, $args);
    }

    /** This call's bound in seconds, or null when the shared deadline is already spent. */
    private function callTimeout(): ?float
    {
        $timeout = $this->timeoutSeconds;
        if ($this->deadline !== null) {
            $remaining = $this->deadline - microtime(true);
            if ($remaining <= 0.0) {
                return null;
            }
            $timeout = min($timeout, $remaining);
        }

        return $timeout;
    }

    /** @param list<string> $args */
    private function commandLine(?string $stdinFile, array $args): string
    {
        $command = $this->inheritGitEnv ? '' : 'unset ' . implode(' ', self::SCRUBBED_GIT_ENV) . ' 2>/dev/null; ';
        $assign = [
            'GIT_AUTHOR_NAME' => self::IDENTITY_NAME,
            'GIT_AUTHOR_EMAIL' => self::IDENTITY_EMAIL,
            'GIT_COMMITTER_NAME' => self::IDENTITY_NAME,
            'GIT_COMMITTER_EMAIL' => self::IDENTITY_EMAIL,
            ...self::ENV,
        ];
        if ($this->indexFile !== null) {
            $assign['GIT_INDEX_FILE'] = $this->indexFile;
        }
        foreach ($assign as $name => $value) {
            $command .= $name . '=' . escapeshellarg($value) . ' ';
        }

        $words = self::GLOBAL_OPTIONS;
        if ($this->gitDir !== null) {
            $words[] = '--git-dir=' . $this->gitDir;
        }
        if ($this->workTree !== null) {
            $words[] = '--work-tree=' . $this->workTree;
        }
        $command .= 'exec git';
        foreach ([...$words, ...$args] as $word) {
            $command .= ' ' . escapeshellarg($word);
        }
        if ($stdinFile !== null) {
            $command .= ' < ' . escapeshellarg($stdinFile);
        }

        return $command;
    }

    /**
     * @param list<string> $args
     * @return array{ok: bool, stdout: string, stderr: string, exitCode: int, timedOut: bool}
     */
    private function invoke(?string $stdinFile, array $args): array
    {
        $timeout = $this->callTimeout();
        if ($timeout === null) {
            return ['ok' => false, 'stdout' => '', 'stderr' => self::BUDGET_SPENT, 'exitCode' => 124, 'timedOut' => true];
        }

        $captured = $this->runCaptured($this->commandLine($stdinFile, $args), $this->cwd, self::MAX_OUTPUT_BYTES, $timeout, self::ENV);
        $ok = !$captured['timedOut'] && $captured['exitCode'] === 0 && $captured['truncatedBytes'] === 0;

        return [
            'ok' => $ok,
            'stdout' => $captured['stdout'],
            'stderr' => $captured['truncatedBytes'] > 0
                ? trim($captured['stderr'] . "\n(git output exceeded " . self::MAX_OUTPUT_BYTES . ' bytes)')
                : $captured['stderr'],
            'exitCode' => $captured['exitCode'],
            'timedOut' => $captured['timedOut'],
        ];
    }

    /**
     * @return array{ok: bool, stdout: string, stderr: string, exitCode: int, timedOut: bool}
     */
    private static function failure(string $reason): array
    {
        return ['ok' => false, 'stdout' => '', 'stderr' => $reason, 'exitCode' => 1, 'timedOut' => false];
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function mutate(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
