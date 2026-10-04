<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Lint;

use SugarCraft\Crush\Support\ToolOutputSpill;
use SugarCraft\Crush\Tools\Concerns\CapturesProcessOutput;
use SugarCraft\Crush\Tools\PathJail;

/**
 * Lints one file with the command configured for its extension (step 3.E).
 *
 * THE MAP. `php` is linted by `php -l` out of the box — the one linter every
 * install of this package is guaranteed to have, since it is the interpreter
 * running it. The user-tier `lintCommands` setting ({@see SETTINGS_KEY}) adds
 * or replaces entries, keyed by file extension:
 *
 *     {"lintCommands": {"php": "vendor/bin/phpstan analyse --no-progress --error-format=raw", "py": "flake8 --select=E9,F63,F7,F82", "js": false}}
 *
 * A command gets the file as its last argument, or wherever it writes
 * `{file}` ({@see FILE_PLACEHOLDER}); the path is shell-quoted either way.
 * `false`, `null` or `""` switches an extension off, the default `php` one
 * included. Anything unusable — a list, a non-string command — is ignored
 * rather than refused, the {@see \SugarCraft\Crush\Config\StatusLineCommand}
 * precedent for a tolerant settings read: a lint map typed wrongly costs the
 * lint and nothing else.
 *
 * USER TIER ONLY, because it is command execution: a project file naming a
 * lint command would run arbitrary shell on the first edit after a clone,
 * with no tool call and no gate in the path — the argument
 * {@see \SugarCraft\Crush\Config\LayeredSettings::PROJECT_TIER_KEYS} makes
 * for `statusLine`.
 *
 * WHY IT RIDES {@see CapturesProcessOutput} INSTEAD OF SPAWNING ITS OWN
 * CHILD — {@see \SugarCraft\Crush\Workspace\GitRunner}'s reason: that trait is
 * the package's one bounded spawn path (`setsid -w` detach, non-interactive
 * and credential-scrubbed environment, both pipes drained, a wall-clock
 * deadline ending in the 15→9 ladder), so every lint child is already
 * accounted for by `DescriptorInheritanceGuardTest` and
 * `tools/check-child-lifetimes.php` without a row of its own.
 *
 * Immutable: every `with*()` returns a copy.
 */
final class LintRunner
{
    use CapturesProcessOutput;

    /** The settings key the extension → command map is read from. */
    public const SETTINGS_KEY = 'lintCommands';

    /** Where a command wants the file path, when not as its last argument. */
    public const FILE_PLACEHOLDER = '{file}';

    /**
     * One lint's wall clock when nobody says otherwise. Generous, because a
     * whole-project analyser on one file is still a whole-project analyser;
     * `php -l` takes milliseconds.
     */
    public const DEFAULT_TIMEOUT_SECONDS = 30.0;

    /** Bytes of each stream kept from one lint run. */
    public const MAX_CAPTURE_BYTES = 65536;

    /** Files larger than this are linted but not quoted in the excerpt. */
    public const MAX_SOURCE_BYTES = 2 * 1024 * 1024;

    /**
     * @param array<string, string> $commands extension (lower case, no dot) => command
     */
    private function __construct(
        private readonly array $commands,
        private readonly float $timeoutSeconds,
    ) {}

    /** A runner with only the built-in `php -l` entry. */
    public static function new(): self
    {
        return new self(self::defaultCommands(), self::DEFAULT_TIMEOUT_SECONDS);
    }

    /**
     * The entries every runner starts from: `php` → `php -l`, run by the
     * interpreter executing this code, with the ini forced so the parse error
     * arrives once, on stderr, whatever `display_errors`/`log_errors` the
     * user's php.ini sets.
     *
     * @return array<string, string>
     */
    public static function defaultCommands(): array
    {
        return ['php' => escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d log_errors=1 -d error_log= -l'];
    }

    /**
     * The same runner with $settings — the raw `lintCommands` value — laid
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

    /** The same runner with each lint bounded at $seconds (floored at 10 ms). */
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

    /** The command that lints $path, or null when its extension has none. */
    public function commandFor(string $path): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $extension === '' ? null : ($this->commands[$extension] ?? null);
    }

    /**
     * Lint $path, or null when there is nothing to lint: no command for its
     * extension, a path outside $root, or no regular file there (an edit that
     * failed to create it).
     *
     * THE PATH IS THE MODEL'S, SO IT IS JAILED EXACTLY AS THE EDIT WAS:
     * {@see PathJail::resolve()} against $root, the call `Edit` makes. A
     * `PostToolUse` chain also runs after an edit the tool itself REFUSED, and
     * a linter quotes the lines it flags — so an unjailed lint of
     * `/elsewhere/config.php` would put lines of a file the edit was not
     * allowed to touch into the model's context. Whatever the edit could not
     * reach, the lint does not read.
     *
     * The linter runs IN $root and is handed the path relative to it, so its
     * messages name the file the way the model named it.
     */
    public function lint(string $path, string $root): ?LintReport
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

        // PathJail's one answer outside the root is a saved tool-output file
        // (its read-only spill exception). That is not an edit target — Edit
        // refuses one by name — so it is not linted either, and every path
        // past this line is under $rootReal.
        if (ToolOutputSpill::readablePath($absolute) !== null) {
            return null;
        }

        $display = substr($absolute, strlen($rootReal) + 1);

        $line = str_contains($command, self::FILE_PLACEHOLDER)
            ? str_replace(self::FILE_PLACEHOLDER, escapeshellarg($display), $command)
            : $command . ' ' . escapeshellarg($display);

        $run = $this->runCaptured(
            $line,
            $rootReal,
            self::MAX_CAPTURE_BYTES,
            $this->timeoutSeconds,
        );

        $source = filesize($absolute) <= self::MAX_SOURCE_BYTES ? (string) @file_get_contents($absolute) : '';

        return new LintReport(
            file: $display,
            command: $line,
            exitCode: $run['exitCode'],
            output: trim($run['stderr'] . "\n" . $run['stdout']),
            timedOut: $run['timedOut'],
            timeoutSeconds: $this->timeoutSeconds,
            source: $source,
        );
    }
}
