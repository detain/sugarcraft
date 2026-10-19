<?php

declare(strict_types=1);

namespace SugarCraft\Code\Format;

use SugarCraft\Code\Support\ToolOutputSpill;
use SugarCraft\Code\Tools\Concerns\CapturesProcessOutput;
use SugarCraft\Code\Tools\PathJail;

/**
 * Formats one file with the command configured for its extension (EDT-1).
 *
 * THE MAP, the shape {@see \SugarCraft\Code\Lint\LintRunner} teaches, with one
 * difference that is the whole point of a separate class: a linter only reads,
 * and this runs. `formatCommands` ({@see SETTINGS_KEY}) is therefore not just
 * user-tier — every run is wrapped in the mutate guard below, and the map
 * starts EMPTY: `php -l` is guaranteed on every install and so is a default,
 * a formatter is neither, and no formatter is named for the user behind their
 * back.
 *
 *     {"formatCommands": {"php": "vendor/bin/php-cs-fixer fix --path-mode=intersection --quiet {file}",
 *                         "ts": "npx prettier --write {file}", "md": false}}
 *
 * A command gets the file as its last argument, or wherever it writes
 * `{file}`; the path is shell-quoted either way, and the placeholder is
 * collapsed out of any quotes the settings author wrapped it in — the exact
 * ISS-011 argument lives on
 * {@see \SugarCraft\Code\Lint\LintRunner::unquotePlaceholder()}'s kin below.
 * `false`, `null` or `""` switches an extension off. Anything unusable is
 * ignored rather than refused, the tolerant settings read `lintCommands`
 * established.
 *
 * THE MUTATE GUARD. The command rewrites a file that holds work the model
 * just did, so the runner hashes the bytes before the run and re-reads after,
 * and any of these outcomes restores the pre-run bytes and says so in the
 * report: the file is gone, the file is empty, the rewrite is larger than
 * {@see MAX_SOURCE_BYTES} (a formatter looping on its own output grows the
 * file), or the run FAILED yet still changed the bytes (a killed or non-zero
 * exit leaves a partial rewrite — the file is only trustworthy when the
 * formatter exited 0). A successful run that changes bytes keeps them: that
 * is the feature. This is the "formatter that empties/corrupts the file" wall
 * the lint has no analogue for.
 *
 * USER TIER ONLY, for `lintCommands`' reason squared: its values are shell
 * AND they overwrite files — a project file naming a formatter would let a
 * clone rewrite the operator's sources with no tool call and no gate.
 *
 * BOUNDED, and every child accounted for, by riding
 * {@see CapturesProcessOutput} — {@see \SugarCraft\Code\Lint\LintRunner}'s
 * reason, unchanged: the one bounded spawn path in the package.
 *
 * Immutable: every `with*()` returns a copy.
 */
final class FormatRunner
{
    use CapturesProcessOutput;

    /** The settings key the extension => command map is read from. */
    public const SETTINGS_KEY = 'formatCommands';

    /** Where a command wants the file path, when not as its last argument. */
    public const FILE_PLACEHOLDER = '{file}';

    /**
     * One format run's wall clock when nobody says otherwise — `LintRunner`'s
     * reasoning: a whole-project formatter handed one file is still a
     * whole-project formatter.
     */
    public const DEFAULT_TIMEOUT_SECONDS = 30.0;

    /** Bytes of each stream kept from one format run. */
    public const MAX_CAPTURE_BYTES = 65536;

    /**
     * Files larger than this are not formatted at all (their bytes are the
     * restore baseline the guard holds in memory), and a formatter that
     * GROWS a file past this is treated as corrupting it.
     */
    public const MAX_SOURCE_BYTES = 2 * 1024 * 1024;

    /** The digest the before/after comparison runs on: cheap, collision-tolerant here. */
    private const ALGO = 'xxh128';

    /**
     * @param array<string, string> $commands extension (lower case, no dot) => command
     */
    private function __construct(
        private readonly array $commands,
        private readonly float $timeoutSeconds,
    ) {}

    /** A runner with no commands at all — the feature is off until configured. */
    public static function new(): self
    {
        return new self([], self::DEFAULT_TIMEOUT_SECONDS);
    }

    /**
     * The same runner with $settings — the raw `formatCommands` value — laid
     * over its map. See the class docblock for the shape and the tolerance.
     */
    public function withCommands(mixed $settings): self
    {
        if (!is_array($settings) || ($settings !== [] && array_is_list($settings))) {
            return $this;
        }

        $commands = $this->commands;
        foreach ($settings as $extension => $command) {
            $key = strtolower(ltrim(trim((string) $extension), '.'));
            if ($key === '') {
                continue;
            }

            if ($command === null || $command === false || (is_string($command) && trim($command) === '')) {
                unset($commands[$key]);
                continue;
            }

            if (is_string($command)) {
                $commands[$key] = trim($command);
            }
        }

        return new self($commands, $this->timeoutSeconds);
    }

    /** The same runner with each format run bounded at $seconds (floored at 10 ms). */
    public function withTimeout(float $seconds): self
    {
        return new self($this->commands, is_finite($seconds) ? max(0.01, $seconds) : self::DEFAULT_TIMEOUT_SECONDS);
    }

    /** @return array<string, string> extension => command */
    public function commands(): array
    {
        return $this->commands;
    }

    public function timeoutSeconds(): float
    {
        return $this->timeoutSeconds;
    }

    /** The command that formats $path, or null when its extension has none. */
    public function commandFor(string $path): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $extension === '' ? null : ($this->commands[$extension] ?? null);
    }

    /**
     * Collapse a placeholder the settings author wrapped in quotes back to
     * bare `{file}` — {@see \SugarCraft\Code\Lint\LintRunner}'s ISS-011
     * argument, applied unchanged: the wrapper re-opens `$(…)` expansion in a
     * model-influenced file name, and this class hands that path to a command
     * that WRITES.
     */
    private static function unquotePlaceholder(string $command): string
    {
        $pattern = '/(["\'])' . \preg_quote(self::FILE_PLACEHOLDER, '/') . '\1/';
        while (\preg_match($pattern, $command) === 1) {
            $command = (string) \preg_replace($pattern, self::FILE_PLACEHOLDER, $command);
        }

        return $command;
    }

    /**
     * Format $path, or null when there is nothing to format: no command for
     * its extension, a path outside $root, no regular file there, or a file
     * too large to hold a restore baseline for.
     *
     * JAILED EXACTLY AS THE EDIT WAS, harder: like the lint, the path goes
     * through {@see PathJail::resolve()} and a spilled tool-output file is
     * skipped — and unlike the lint, this command is handed the file for
     * WRITING, so what the edit could not reach, the format does not touch.
     */
    public function format(string $path, string $root): ?FormatReport
    {
        $command = $this->commandFor($path);
        if ($command === null || $root === '') {
            return null;
        }

        $absolute = PathJail::resolve($root, $path);
        $rootReal = realpath($root);
        if ($absolute === null || $rootReal === false || !is_file($absolute)) {
            return null;
        }

        if (ToolOutputSpill::readablePath($absolute) !== null) {
            return null;
        }

        // The restore baseline. Past the size wall the guard cannot hold the
        // old bytes, and a formatter the guard cannot un-run is a formatter
        // that does not run.
        clearstatcache(true, $absolute);
        $before = @file_get_contents($absolute);
        if ($before === false || strlen($before) > self::MAX_SOURCE_BYTES) {
            return null;
        }
        $hashBefore = hash(self::ALGO, $before);

        $display = substr($absolute, strlen($rootReal) + 1);
        $line = str_contains($command, self::FILE_PLACEHOLDER)
            ? str_replace(self::FILE_PLACEHOLDER, escapeshellarg($display), self::unquotePlaceholder($command))
            : $command . ' ' . escapeshellarg($display);

        $run = $this->runCaptured(
            $line,
            $rootReal,
            self::MAX_CAPTURE_BYTES,
            $this->timeoutSeconds,
        );

        return $this->guard($absolute, $display, $line, $before, $hashBefore, $run);
    }

    /**
     * The mutate guard: hash what is there now, keep it only if the run
     * earned it, and say what happened.
     *
     * @param array{exitCode: int, stdout: string, stderr: string, timedOut: bool} $run
     */
    private function guard(string $absolute, string $display, string $line, string $before, string $hashBefore, array $run): FormatReport
    {
        $output = trim($run['stderr'] . "\n" . $run['stdout']);
        clearstatcache(true, $absolute);

        if (!is_file($absolute)) {
            $restored = $this->restore($absolute, $before);

            return new FormatReport($display, $absolute, $line, $run['exitCode'], $output, $run['timedOut'], $this->timeoutSeconds, false, $restored ? 'the command removed the file' : 'the command removed the file, and the restore failed');
        }

        $after = @file_get_contents($absolute);
        if ($after === false) {
            // Unreadable after the run — same distrust as a vanished file.
            $restored = $this->restore($absolute, $before);

            return new FormatReport($display, $absolute, $line, $run['exitCode'], $output, $run['timedOut'], $this->timeoutSeconds, false, $restored ? 'the file could not be read back after the run' : 'the file could not be read back after the run, and the restore failed');
        }

        $changed = hash(self::ALGO, $after) !== $hashBefore;
        if (!$changed) {
            return new FormatReport($display, $absolute, $line, $run['exitCode'], $output, $run['timedOut'], $this->timeoutSeconds, false, null);
        }

        $reason = null;
        if ($after === '') {
            $reason = 'the command emptied the file';
        } elseif (strlen($after) > self::MAX_SOURCE_BYTES) {
            $reason = 'the rewrite grew the file past the format size wall';
        } elseif ($run['timedOut']) {
            $reason = sprintf('the run ran past its %.3f s wall mid-rewrite', $this->timeoutSeconds);
        } elseif ($run['exitCode'] !== 0) {
            $reason = sprintf('the command exited %d after already rewriting the file', $run['exitCode']);
        }

        if ($reason === null) {
            // A clean, whole run that changed bytes: the feature working.
            return new FormatReport($display, $absolute, $line, $run['exitCode'], $output, false, $this->timeoutSeconds, true, null);
        }

        $restored = $this->restore($absolute, $before);

        return new FormatReport(
            $display,
            $absolute,
            $line,
            $run['exitCode'],
            $output,
            $run['timedOut'],
            $this->timeoutSeconds,
            false,
            $restored ? $reason : $reason . ', and the restore failed',
        );
    }

    /** Put the pre-run bytes back; false when even that failed. */
    private function restore(string $absolute, string $bytes): bool
    {
        clearstatcache(true, $absolute);

        return @file_put_contents($absolute, $bytes) !== false;
    }
}
