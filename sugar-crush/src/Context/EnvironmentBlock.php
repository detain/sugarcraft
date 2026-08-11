<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context;

use DateTimeImmutable;

/**
 * Captures the execution environment at session start for injection into the system prompt.
 *
 * This class captures a one-shot snapshot of the environment (cwd, git status, platform,
 * PHP version, model name) that is embedded once per Chat session — not re-polled per turn.
 * Reusing the same snapshot across turns matches Claude Code's documented behavior and
 * avoids the cost and instability of repeated shell_exec calls mid-session.
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
     * Factory that captures the current working directory and model name with a fresh timestamp.
     *
     * Use this at Chat session start — the returned instance is a snapshot, not live state.
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
     * Output includes cwd, git-repository flag, platform, PHP version, model name, and
     * current date. When the cwd is a git repository, a one-shot git status snapshot
     * (branch, --porcelain status, recent log) is appended.
     */
    public function render(): string
    {
        $lines = [
            'Working directory: ' . $this->cwd,
            'Is directory a git repo: ' . ($this->isGitRepo() ? 'Yes' : 'No'),
            'Platform: ' . strtolower(PHP_OS_FAMILY),
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
     * We check for the presence of a .git directory — this is the cheapest possible
     * check and sufficient for the one-shot snapshot use case.
     */
    private function isGitRepo(): bool
    {
        return is_dir($this->cwd . '/.git');
    }

    /**
     * Captures a one-shot git status snapshot for the captured working directory.
     *
     * One-shot at session start — never re-run mid-session (matches Claude Code's
     * documented behavior). Each field (branch, status, log) is captured separately so
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
