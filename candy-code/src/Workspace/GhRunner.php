<?php

declare(strict_types=1);

namespace SugarCraft\Code\Workspace;

use SugarCraft\Code\Support\ProcessContainment;
use SugarCraft\Code\Tools\Concerns\CapturesProcessOutput;

/**
 * One bounded `gh` invocation at a time, for the reads the app makes of the
 * user's own GitHub host — today the `@pr:<n>` mention
 * ({@see \SugarCraft\Code\Attachments\ContextMentions}'s pr leg), which shows
 * the agent a pull request as filed rather than as the working tree claims.
 *
 * WHY THE USER'S `gh` AND NOT AN API CLIENT. Plan §6.1 R13 ruled that
 * shelling out to the gh the user installed and authenticated is the shape
 * this product takes: their host, their protocol version, their credentials,
 * zero new HTTP surface and zero token handling of ours. The write side of
 * gh (`pr create`) is deliberately NOT reached from here — it happens inside
 * a turn through the Bash tool, where the session's permission mode gates it.
 *
 * WHY IT RIDES {@see CapturesProcessOutput} INSTEAD OF SPAWNING ITS OWN
 * CHILD. That trait is the package's one bounded spawn path: `setsid -w`
 * detach, pipes drained to completion and a wall-clock deadline ending in
 * the reaper's 15→9 ladder — the same shape {@see GitRunner} documents. A
 * second spawn site would have to re-prove all of it and earn its own
 * `DescriptorInheritanceGuardTest` row. Through the trait every child is
 * already accounted for, and `gh` is a network CLI: a bounded PER-CALL
 * ceiling is the legal shape under E646, mirroring the sibling precedent —
 * never a blanket timeout on anything that waits on the model.
 *
 * WHAT THE ENVIRONMENT GRANTS. The spawn runs on the scrubbed environment
 * (credential-shaped names stripped — audit F-E1), EXCEPT the `GH_*` family
 * and `GITHUB_TOKEN`: `gh` cannot reach the user's host without them, so
 * those names are declared here and re-injected as overrides, which merge
 * last and win — {@see \SugarCraft\Code\Support\ProcessContainment}'s mcp
 * precedent that the declaration IS the grant. Nothing else is added, and
 * model-written text never reaches these argv words.
 *
 * Immutable: every `with*()` returns a copy. Read-only by construction —
 * this class builds no mutating command lines.
 */
final class GhRunner
{
    use CapturesProcessOutput;

    /** The bound on any single call when the caller sets none. */
    public const DEFAULT_TIMEOUT_SECONDS = 10.0;

    private function __construct(
        private readonly string $cwd,
        private readonly float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {}

    /** A runner whose calls start in $cwd and let `gh` find the repository. */
    public static function new(string $cwd): self
    {
        return new self($cwd);
    }

    /** Whether a `gh` executable is on PATH at all — a stat walk, no spawn. */
    public static function available(): bool
    {
        return ProcessContainment::locateOnPath('gh') !== '';
    }

    /** Bound each call at $seconds. */
    public function withTimeout(float $seconds): self
    {
        return $this->mutate(['timeoutSeconds' => max(0.001, $seconds)]);
    }

    public function cwd(): string
    {
        return $this->cwd;
    }

    /**
     * Run `gh <args>` retaining at most $maxBytes of each stream (null: no
     * bound) and hand back the capture's OWN account of the cut — the bytes
     * each stream dropped and whether the cut landed mid-line — for a caller
     * that announces truncation itself rather than failing on it.
     *
     * @return array{stdout: string, stderr: string, exitCode: int, truncatedBytes: int, stdoutDropped: int, stderrDropped: int, stdoutMidLine: bool, stderrMidLine: bool, timedOut: bool}
     */
    public function capture(?int $maxBytes, string ...$args): array
    {
        return $this->runCaptured($this->commandLine($args), $this->cwd, $maxBytes, $this->timeoutSeconds, self::grant());
    }

    /**
     * The `sh` command line {@see capture()} spawns: `exec gh` and every
     * argument word quoted with `escapeshellarg` — the same argv discipline
     * {@see GitRunner}'s command line keeps, so no word can ever split or
     * expand, whatever it came from.
     */
    public function shellCommand(string ...$args): string
    {
        return $this->commandLine($args);
    }

    /**
     * The declared grant: the `GH_*` family plus `GITHUB_TOKEN`, read from
     * the live environment at call time. Passed as runCaptured overrides,
     * they arrive on top of the scrub; every other credential-shaped name
     * stays stripped.
     *
     * @return array<string, string>
     */
    private static function grant(): array
    {
        $grant = [];
        foreach (getenv() as $name => $value) {
            if (str_starts_with((string) $name, 'GH_') || $name === 'GITHUB_TOKEN') {
                $grant[(string) $name] = (string) $value;
            }
        }

        return $grant;
    }

    /** @param list<string> $args */
    private function commandLine(array $args): string
    {
        $command = 'exec gh';
        foreach ($args as $word) {
            $command .= ' ' . escapeshellarg($word);
        }

        return $command;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function mutate(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
