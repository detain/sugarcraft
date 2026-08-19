<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context;

use DateTimeImmutable;

/**
 * Renders the execution environment for injection into the system prompt.
 *
 * WHAT IS A SNAPSHOT AND WHAT IS NOT — the distinction this docblock used to get
 * backwards. {@see capture()} freezes exactly three things: the working
 * directory, the model name and the timestamp. Everything else
 * {@see render()} emits is read AT RENDER TIME: `PHP_OS_FAMILY`, `php_uname()`,
 * `PHP_VERSION` (constants, so stable anyway) and — the one that matters — the
 * git snapshot, which shells out to `git branch`/`status`/`log` on every
 * `render()` call. `buildSystemPrompt()` runs once per step of the agentic loop,
 * so those three subprocesses run per step, and the block's git section reflects
 * the repository as the agent has already changed it rather than as it was at
 * session start.
 *
 * That is deliberate — a model reading a stale `git status` after its own edits
 * is worse than the subprocess cost — and it is pinned by
 * `tests/Providers/PromptStabilityTest::testEnvironmentBlockGitSnapshotIsLivePolledNotFrozenAtCapture()`.
 * It also has a consequence for anything reasoning about prompt caching: this
 * block sits ahead of the others, so the first write of a session voids the
 * cacheable prefix for everything after it (see {@see MemoryBlock}, which builds
 * an argument on exactly that).
 *
 * The full line set is enumerated on {@see render()}, which is the method that
 * emits it — one list, in one place, so the two cannot drift apart again.
 */
final readonly class EnvironmentBlock
{
    public function __construct(
        private string $cwd,
        private string $modelName,
        private ?DateTimeImmutable $now = null,
    ) {}

    /** Returns the captured working directory. */
    public function cwd(): string
    {
        return $this->cwd;
    }

    /** Returns the captured model name. */
    public function modelName(): string
    {
        return $this->modelName;
    }

    /** Returns the captured timestamp, or null if none was provided. */
    public function now(): ?DateTimeImmutable
    {
        return $this->now;
    }

    /**
     * Factory that captures the current working directory and model name with a
     * fresh timestamp.
     *
     * Those three values, and only those three, are frozen here. The git section
     * {@see render()} appends is polled on every render — see the class docblock
     * for why that is deliberate and what it costs.
     *
     * @see W1.B3b for the production wiring that calls this once per Chat session.
     */
    public static function capture(string $cwd, string $modelName): self
    {
        return new self($cwd, $modelName, new DateTimeImmutable());
    }

    /**
     * Renders the environment block as an XML-flavoured string for embedding in prompts.
     *
     * Seven lines, in this order: cwd, git-repository flag, platform, OS version,
     * PHP version, model name, current date. When the cwd is a git repository, a
     * git section (branch, --porcelain status, recent log) is appended — polled
     * here, on every call, not frozen at capture time.
     *
     * There is deliberately no "additional working directories" line, although
     * crush_code.md Phase 5 item 10a asks for one. See the inline note at the
     * OS-version line below for the full reason; the short version is that this
     * application has no multi-root concept for such a line to describe.
     */
    public function render(): string
    {
        $lines = [
            'Working directory: ' . $this->cwd,
            'Is directory a git repo: ' . ($this->isGitRepo() ? 'Yes' : 'No'),
            'Platform: ' . strtolower(PHP_OS_FAMILY),
            // Distinct from `Platform:` above, which is PHP_OS_FAMILY - a
            // four-value bucket ("Linux"/"Darwin"/"Windows"/"BSD") that answers
            // "which family of syscalls" and nothing about the release. This
            // line adds the version, which is what a model needs to know
            // whether a flag exists: `sed -i ''` vs `sed -i`, GNU vs BSD
            // coreutils, a kernel new enough for a given /proc entry.
            //
            // The value is self-labelling on purpose. php_uname('r') alone would
            // read as "OS version: 23.5.0" on macOS, which is the DARWIN
            // version, not the macOS product version anyone means by "macOS
            // 14.5" - a number next to a label that does not own it. Prefixing
            // php_uname('s') makes the pair name its own domain ("Darwin
            // 23.5.0", "Linux 6.8.0-137-generic", "Windows NT 10.0") and
            // matches the reference pattern item 10a asks to match.
            //
            // Unguarded by function_exists() as a considered choice, not an
            // oversight: gitStatusSnapshot() below already calls shell_exec()
            // three times in this same render path, and shell_exec is far more
            // commonly disabled than php_uname, so a guard here would protect
            // the wrong end of the same method while adding a branch no test can
            // reach.
            'OS version: ' . php_uname('s') . ' ' . php_uname('r'),
            'PHP version: ' . PHP_VERSION,
            'Model: ' . $this->modelName,
            'Current date: ' . ($this->now ?? new DateTimeImmutable())->format('Y-m-d'),
        ];

        if ($this->isGitRepo()) {
            $lines[] = '';
            $lines[] = $this->gitStatusSnapshot();
        }

        return "<env>\n" . implode("\n", $lines) . "\n</env>";
    }

    /**
     * Checks whether the captured working directory is a git repository.
     *
     * We check for the presence of a .git directory — the cheapest possible
     * check, and it runs on every render alongside the git section it gates, so
     * cheap is the requirement.
     */
    private function isGitRepo(): bool
    {
        return is_dir($this->cwd . '/.git');
    }

    /**
     * Reads the current git state of the captured working directory.
     *
     * Called from {@see render()}, so it re-runs on every render — three
     * subprocesses per call, and `buildSystemPrompt()` renders once per step of
     * the agentic loop. Live rather than frozen on purpose: a model that has just
     * edited files must not be shown the status those files had at session start.
     * Pinned by
     * `tests/Providers/PromptStabilityTest::testEnvironmentBlockGitSnapshotIsLivePolledNotFrozenAtCapture()`.
     *
     * Each field (branch, status, log) is captured separately so
     * failures in one field don't poison the others; empty strings indicate failure.
     */
    private function gitStatusSnapshot(): string
    {
        $branch = trim((string) shell_exec('git -C ' . escapeshellarg($this->cwd) . ' branch --show-current 2>/dev/null'));
        $status = trim((string) shell_exec('git -C ' . escapeshellarg($this->cwd) . ' status --porcelain 2>/dev/null'));
        $log = trim((string) shell_exec('git -C ' . escapeshellarg($this->cwd) . ' log --oneline -5 2>/dev/null'));

        return "Current branch: {$branch}\n\nStatus:\n{$status}\n\nRecent commits:\n{$log}";
    }
}
