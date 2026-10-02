<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

use SugarCraft\Crush\Support\ProcessContainment;

/**
 * Encapsulates a Claude Code CLI invocation.
 *
 * THE PROMPT TRAVELS ON STDIN, NEVER IN ARGV (audit 15a A12). `claude -p`
 * with no positional prompt reads it from stdin — measured against CLI
 * 2.1.287, `echo hi | claude -p --output-format stream-json --verbose` reaches
 * the request stage. The prompt is the whole flattened transcript, and Linux
 * caps ONE argv string at `MAX_ARG_STRLEN` (32 pages = 131072 bytes, NUL
 * included), so an argv prompt made `exec` fail with E2BIG the turn the history
 * crossed 128 KiB — and every later turn too, since history only grows. Stdin
 * has no such ceiling and keeps the transcript out of `ps` as a side benefit.
 */
final readonly class ClaudeCodeInvocation
{
    /**
     * The largest system prompt still passed inline as `--system-prompt`.
     *
     * Half of `MAX_ARG_STRLEN` (131072 bytes per argv string), not the limit
     * itself, because the per-string cap is not the only one: argv and the
     * environment share `ARG_MAX` too, and the containment wrapper adds its own
     * argv in front. Above this the text is spilled to a 0600 temp file and
     * passed as `--system-prompt-file` ({@see spillSystemPrompt()}); below it
     * no file is written, so the common case leaves nothing on disk to clean.
     */
    public const MAX_INLINE_SYSTEM_PROMPT_BYTES = 65536;

    /** Temp-file prefix for a spilled system prompt; see {@see spillSystemPrompt()}. */
    public const SYSTEM_PROMPT_FILE_PREFIX = 'sc_cc_sysprompt_';

    public function __construct(
        private string $claudePath = 'claude',
        private string $configDir = '~/.claude',
        private ?string $sessionId = null,
    ) {}

    public function claudePath(): string
    {
        return $this->claudePath;
    }

    public function configDir(): string
    {
        return $this->configDir;
    }

    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    /**
     * Build the base command arguments.
     *
     * NO `--output-format` HERE (audit 15a A12). This used to open with
     * `--output-format json`, and {@see printModeArgs()} then appended the
     * format the caller actually asked for, so every streaming spawn carried
     * the flag twice. The CLI happens to keep the last one, but the format is
     * a per-call decision that drives which other flags are legal
     * (`--verbose`, `--include-partial-messages`), so it has exactly one
     * owner: printModeArgs().
     *
     * @return array<string>
     */
    public function baseArgs(): array
    {
        $args = [];

        if ($this->sessionId !== null) {
            $args[] = '--resume';
            $args[] = $this->sessionId;
        }

        return $args;
    }

    /**
     * Build headless print mode arguments.
     *
     * The prompt is NOT among them: it is the stdin payload {@see execute()}
     * (and the provider's streaming spawn) write to the child, for the
     * `MAX_ARG_STRLEN` reason in the class docblock. A bare `-p` is what makes
     * the CLI read it from there.
     *
     * `stream-json` brings `--verbose` and `--include-partial-messages` with
     * it. The first is REQUIRED — measured, CLI 2.1.287 refuses
     * `-p --output-format stream-json` without it ("When using --print,
     * --output-format=stream-json requires --verbose") and exits 1, so the
     * streaming path failed every turn. The second is what turns the stream
     * into token deltas (`stream_event` lines); without it the CLI emits only
     * whole `assistant` messages, which is not streaming.
     *
     * `systemPromptFile` (a path from {@see spillSystemPrompt()}) wins over
     * `systemPrompt`; the two are never both sent.
     *
     * @param array<string, mixed> $options
     * @return array<string>
     */
    public function printModeArgs(array $options = []): array
    {
        $format = $options['format'] ?? 'json';

        $args = ['-p', '--output-format', $format];

        if ($format === 'stream-json') {
            $args[] = '--verbose';
            $args[] = '--include-partial-messages';
        }

        if ($options['bare'] ?? false) {
            $args[] = '--bare';
        }

        if ($options['continue'] ?? false) {
            $args[] = '--continue';
        }

        if (isset($options['allowedTools'])) {
            $args[] = '--allowedTools';
            $args[] = $options['allowedTools'];
        }

        if (isset($options['systemPromptFile'])) {
            $args[] = '--system-prompt-file';
            $args[] = $options['systemPromptFile'];
        } elseif (isset($options['systemPrompt'])) {
            $args[] = '--system-prompt';
            $args[] = $options['systemPrompt'];
        }

        if (isset($options['maxBudgetUsd'])) {
            $args[] = '--max-budget-usd';
            $args[] = (string) $options['maxBudgetUsd'];
        }

        if (isset($options['maxTurns'])) {
            $args[] = '--max-turns';
            $args[] = (string) $options['maxTurns'];
        }

        if (isset($options['permissionMode'])) {
            $args[] = '--permission-mode';
            $args[] = $options['permissionMode'];
        }

        return $args;
    }

    /**
     * Execute Claude Code and return the output.
     *
     * @param array<string> $args
     * @param callable(string): void|null $onChunk Called for each chunk in streaming mode
     * @param string $stdin Written to the child's stdin, then closed — the
     *                      prompt, see the class docblock.
     * @throws ProviderException when the child fails to start (null exitCode) or
     *                           exits non-zero (exitCode carries the shell code).
     *                           It IS-A \RuntimeException — see E664 below.
     */
    public function execute(array $args, ?callable $onChunk = null, string $stdin = ''): string
    {
        $cmd = array_merge([$this->claudePath], $this->baseArgs(), $args);

        // E672: once the containment wrapper fronts the spawn, a bogus
        // claudePath NO LONGER fails inside posix_spawn() — `setsid` itself
        // starts and the exec failure surfaces only as exit 127 attributed to
        // the RUN, laundering this site's typed spawn-failure contract into
        // generic exit text. The pre-check keeps fail-fast on the call that
        // guarded it before routing (twin of ProcessExecutor's worker check).
        if (ProcessContainment::detachedSpawnBinary() !== ''
            && !(str_contains($this->claudePath, '/')
                ? is_executable($this->claudePath)
                : ProcessContainment::locateOnPath($this->claudePath) !== '')
        ) {
            // E664: typed throw, exit code null = the child never spawned —
            // the SAME shape the !is_resource branch below carries.
            throw new ProviderException('Failed to start Claude Code process');
        }

        // E672/E674: choke-point spec + env; the three auth keys ride as
        // overrides. Empty-string values are dropped by proc_open() itself
        // (measured, recorded in ProcessContainment), so an unset key stays
        // unset rather than reaching the child as ''.
        $process = proc_open(
            ProcessContainment::spawnSpec($cmd),
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            ProcessContainment::env([
                'ANTHROPIC_API_KEY' => getenv('ANTHROPIC_API_KEY') ?: '',
                'ANTHROPIC_AUTH_TOKEN' => getenv('ANTHROPIC_AUTH_TOKEN') ?: '',
                'ANTHROPIC_BASE_URL' => getenv('ANTHROPIC_BASE_URL') ?: '',
            ])
        );

        if (!is_resource($process)) {
            // E664 (E27(a) family): this execute() is the SECOND Claude Code
            // subprocess site — the provider owns the first two, since retyped
            // in lane Q. Identical pattern: typed throw, exit code null = the
            // child never spawned.
            throw new ProviderException('Failed to start Claude Code process');
        }

        // ONE LOOP FEEDS STDIN AND DRAINS BOTH OUTPUT PIPES. Once the prompt
        // rides stdin it can be megabytes, and a blocking write of all of it
        // before reading anything deadlocks the moment the child fills its
        // 64 KiB stdout or stderr pipe while this side is still writing. So
        // every pipe is non-blocking and serviced as `stream_select()` says
        // it is ready — the same shape as ClaudeCodeProvider::completeStream().
        stream_set_blocking($pipes[0], false);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $pending = self::feedStdin($pipes[0], $stdin);
        $output = '';
        $errors = '';
        $open = [1 => $pipes[1], 2 => $pipes[2]];

        while ($open !== []) {
            $read = array_values($open);
            $write = $pending !== '' ? [$pipes[0]] : [];
            $except = [];

            // `@`: an EINTR (SIGCHLD, a suite's pcntl_alarm()) is a retry, and
            // its warning would red a passing run under failOnWarning.
            $ready = @stream_select($read, $write, $except, 1, 0);

            if ($ready === false) {
                foreach ($open as $fd => $pipe) {
                    if (feof($pipe)) {
                        unset($open[$fd]);
                    }
                }
                usleep(1000);

                continue;
            }

            if ($write !== []) {
                $pending = self::feedStdin($pipes[0], $pending);
            }

            foreach ($open as $fd => $pipe) {
                if (!in_array($pipe, $read, true)) {
                    continue;
                }

                $chunk = fread($pipe, 8192);
                if ($chunk === false || ($chunk === '' && feof($pipe))) {
                    unset($open[$fd]);

                    continue;
                }
                if ($chunk === '') {
                    continue;
                }

                if ($fd === 2) {
                    $errors .= $chunk;

                    continue;
                }

                $output .= $chunk;
                if ($onChunk !== null) {
                    $onChunk($chunk);
                }
            }
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $exitCode = proc_close($process);

        if ($exitCode !== 0 && $exitCode !== -1) {
            // E664: the shell exit code now rides as STRUCTURE on
            // ProviderException::$exitCode, never as the exception code, so
            // TransientFailure::statusCode() cannot misread a shell 429 or a
            // 128+signal death as an HTTP status. The message is byte-stable
            // and the class stays a \RuntimeException, so every existing
            // catch keeps its contract; the per-code retry verdict remains
            // with TransientFailure's allow-list (open half of E27(a)).
            $reason = self::exitReason($errors, self::resultError(self::lastLine($output)));

            throw new ProviderException("Claude Code exited with code $exitCode: $reason", exitCode: $exitCode);
        }

        return $output;
    }

    /**
     * Write the system prompt to a private temp file when it is too large to
     * ride argv, and return that file's path; null when it fits inline (or
     * there is none). The caller passes the path as the `systemPromptFile`
     * option and MUST unlink it once the child has exited.
     *
     * `tempnam()` creates the file 0600 under a name nothing else holds, so the
     * prompt is never readable by another user, not even for the instant
     * between create and write. A file left behind by a SIGKILLed parent is
     * therefore private, and carries the {@see SYSTEM_PROMPT_FILE_PREFIX} so it
     * can be recognised.
     *
     * @throws ProviderException when the file cannot be written: sending the
     *                           prompt inline instead would only move the
     *                           failure to an E2BIG at exec time.
     */
    public function spillSystemPrompt(?string $systemPrompt): ?string
    {
        if ($systemPrompt === null || strlen($systemPrompt) <= self::MAX_INLINE_SYSTEM_PROMPT_BYTES) {
            return null;
        }

        $path = @tempnam(sys_get_temp_dir(), self::SYSTEM_PROMPT_FILE_PREFIX);
        if ($path === false) {
            throw new ProviderException('Failed to create the Claude Code system prompt file');
        }

        if (@file_put_contents($path, $systemPrompt) !== strlen($systemPrompt)) {
            @unlink($path);

            throw new ProviderException('Failed to write the Claude Code system prompt file');
        }

        return $path;
    }

    /**
     * Push as much of $pending into the child's non-blocking stdin as the pipe
     * takes now, closing the pipe once nothing is left; returns what is still
     * pending ('' means written in full and closed).
     *
     * A failed write — the child exited, or closed its stdin without reading —
     * abandons the rest rather than retrying: nothing more can be delivered,
     * and the child's exit status is the diagnostic that says why. `@` because
     * that write raises an EPIPE notice, which would red a passing run.
     *
     * @param resource $pipe
     */
    public static function feedStdin($pipe, string $pending): string
    {
        if ($pending !== '') {
            $written = @fwrite($pipe, $pending);
            $pending = $written === false ? '' : substr($pending, $written);
        }

        if ($pending === '') {
            fclose($pipe);
        }

        return $pending;
    }

    /**
     * The error text a CLI `result` line carries, or null when the line is not
     * an error result.
     *
     * WHY THE EXIT MESSAGE NEEDS THIS. Measured against CLI 2.1.287 with an
     * unreachable `ANTHROPIC_BASE_URL`: the run exits 1 with an EMPTY stderr,
     * and the reason ("API Error: Connection refused …") is only in the final
     * `{"type":"result","is_error":true,"result":…}` line on stdout. A message
     * built from stderr alone read `Claude Code exited with code 1: `.
     */
    public static function resultError(string $line): ?string
    {
        $data = json_decode($line, true);

        return is_array($data) ? self::resultErrorOf($data) : null;
    }

    /**
     * {@see resultError()} for an already-decoded line.
     *
     * @param array<mixed> $data
     */
    public static function resultErrorOf(array $data): ?string
    {
        if (($data['type'] ?? null) !== 'result' || ($data['is_error'] ?? false) !== true) {
            return null;
        }

        $result = $data['result'] ?? null;

        return is_string($result) && $result !== '' ? $result : (string) ($data['subtype'] ?? 'error');
    }

    /**
     * The reason a non-zero exit is reported with: the child's stderr when it
     * said anything there, otherwise the error its `result` line carried.
     */
    public static function exitReason(string $stderr, ?string $resultError): string
    {
        return $stderr !== '' ? $stderr : ($resultError ?? '');
    }

    /** The last non-blank line of $output — where the CLI puts its `result`. */
    private static function lastLine(string $output): string
    {
        $lines = preg_split('/\R/', trim($output)) ?: [];

        return (string) end($lines);
    }
}
