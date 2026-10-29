<?php

declare(strict_types=1);

namespace SugarCraft\Code\Providers;

use SugarCraft\Code\Support\ProcessContainment;

/**
 * Encapsulates a Codex CLI (`codex exec`) invocation.
 *
 * PRV-4 mirrors the claude-code transport template: the prompt rides STDIN
 * (the `-` operand — measured against codex-cli 0.154.0, which prints
 * "Reading additional input from stdin..." and reaches the request stage),
 * never argv, for the same `MAX_ARG_STRLEN` reason spelled out in
 * {@see ClaudeCodeInvocation}'s class docblock, and it keeps the transcript
 * out of `ps`. Auth is deliberately NOT this class's business: `codex login`
 * writes the subscription token under ~/.codex upstream, and `OPENAI_API_KEY`
 * rides the inherited environment — zero new secret handling on our side.
 *
 * PROBED VERSION: codex-cli 0.154.0 (2026-10-19). Every event shape parsed
 * by {@see CodexProvider} was taken from a live unauthenticated `codex exec
 * --json` run (framing: thread.started / turn.started / item.completed /
 * turn.completed / turn.failed / bare error) plus the binary's serde schema
 * for the payload fields (agent_message text, TokenUsage buckets). This is
 * the house defense doctrine: pin what was measured, name what was not.
 * C-PRV-4.b (2026-10-29) extended the record on the SAME installed release:
 * `codex exec --help` was read live for the isolation and schema flags —
 * `--ignore-user-config` ("Do not load $CODEX_HOME/config.toml… authentication
 * still uses CODEX_HOME"), `--output-schema <FILE>`, and the three accepted
 * `-s` values. What was NOT observed is constrained decoding through an
 * authenticated run, which is why {@see CodexProvider::supportsJsonSchema()}
 * still answers false with the channel wired.
 */
final readonly class CodexInvocation
{
    /** The codex-cli release every flag and event shape here was verified against. */
    public const PROBED_CODEX_VERSION = '0.154.0';

    /** Temp-file prefix for a spilled output schema; see {@see spillOutputSchema()}. */
    public const OUTPUT_SCHEMA_FILE_PREFIX = 'sc_codex_schema_';

    public function __construct(
        private string $codexPath = 'codex',
        private ?string $sessionId = null,
    ) {}

    public function codexPath(): string
    {
        return $this->codexPath;
    }

    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    /**
     * Positional shape between `exec` and the event flags.
     *
     * `codex exec resume <SESSION_ID> [PROMPT]` is the measured subcommand
     * (0.154.0 --help: "Resume a previous session by id"); an idless session
     * starts a fresh thread, which needs no words here.
     *
     * @return array<string>
     */
    public function baseArgs(): array
    {
        if ($this->sessionId === null) {
            return [];
        }

        return ['resume', $this->sessionId];
    }

    /**
     * Build the headless JSON-event flags, ending in the stdin prompt operand.
     *
     * The trailing `-` is what makes the CLI read the prompt from stdin for a
     * fresh run AND for `exec resume` (both help texts state it).
     *
     * The sandbox posture changed under C-PRV-4.b: this method used to say
     * NOTHING about `-s` unless the operator configured one, which left the
     * child running under whatever authority its own ~/.codex/config.toml
     * granted — a plan-mode turn could still edit files. {@see
     * CodexProvider::options()} now ALWAYS carries a `sandbox` key (the
     * configured value, else workspace-write, narrowed by the host permission
     * mode down to read-only), and `ignoreUserConfig` rides when `isolate` is
     * on so a foreign config.toml contributes neither a wider sandbox nor MCP
     * servers to the child. `danger-full-access` is still only ever emitted
     * when an operator configured exactly that; nothing here widens anything.
     * The three values 0.154.0 accepts are read-only, workspace-write and
     * danger-full-access; we pass through verbatim and validate nothing,
     * because codex itself refuses the rest.
     *
     * `outputSchemaFile` (a path from {@see spillOutputSchema()}) becomes
     * `--output-schema <path>`; the caller MUST unlink the file once the
     * child has exited.
     *
     * @param array<string, mixed> $options
     * @return array<string>
     */
    public function execArgs(array $options = []): array
    {
        $args = ['exec', ...$this->baseArgs(), '--json'];

        // C-PRV-4.b (§6.2 ruling): isolation on by default for the codex
        // route. Verified in `codex exec --help` at PROBED_CODEX_VERSION: the
        // flag drops $CODEX_HOME/config.toml — foreign MCP servers, profiles,
        // and any sandbox default it carries — while authentication keeps
        // using CODEX_HOME, so subscription auth survives untouched.
        if (!empty($options['ignoreUserConfig'])) {
            $args[] = '--ignore-user-config';
        }

        if (isset($options['model'])) {
            $args[] = '-m';
            $args[] = (string) $options['model'];
        }

        if (isset($options['sandbox'])) {
            $args[] = '-s';
            $args[] = (string) $options['sandbox'];
        }

        // C-PRV-1: `-c <key=value>` is verified in `codex exec --help`
        // (0.154.0) as an exec-level config override. The value is the
        // TOML spelling the CLI parses; the provider has already filtered
        // it to a member of its level table.
        if (isset($options['reasoningEffort'])) {
            $args[] = '-c';
            $args[] = 'model_reasoning_effort=' . (string) $options['reasoningEffort'];
        }

        if (isset($options['outputSchemaFile'])) {
            $args[] = '--output-schema';
            $args[] = (string) $options['outputSchemaFile'];
        }

        $args[] = '-';

        return $args;
    }

    /**
     * Write the request's JSON schema to a private temp file and return its
     * path for the `outputSchemaFile` option; the caller MUST unlink the file
     * once the child has exited.
     *
     * A string schema rides verbatim (the raw JSON document as the caller
     * holds it); an array is encoded. `tempnam()` creates the file 0600 under
     * a name nothing else holds — the same spill doctrine as
     * {@see ClaudeCodeInvocation::spillSystemPrompt()}, so a SIGKILLed parent
     * leaves only private residue carrying {@see OUTPUT_SCHEMA_FILE_PREFIX}.
     *
     * @param string|array<mixed> $schema
     * @throws ProviderException when the schema cannot be encoded or the file
     *                           cannot be written: silently dropping
     *                           `--output-schema` and asking the child to
     *                           freestyle would be a fabricated success path.
     */
    public function spillOutputSchema(string|array $schema): string
    {
        $json = is_string($schema)
            ? $schema
            : json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($json)) {
            throw new ProviderException('Failed to encode the Codex output schema');
        }

        $path = @tempnam(sys_get_temp_dir(), self::OUTPUT_SCHEMA_FILE_PREFIX);
        if ($path === false) {
            throw new ProviderException('Failed to create the Codex output schema file');
        }

        if (@file_put_contents($path, $json) !== strlen($json)) {
            @unlink($path);

            throw new ProviderException('Failed to write the Codex output schema file');
        }

        return $path;
    }

    /**
     * Execute codex and return its full stdout.
     *
     * @param array<string> $args
     * @param callable(string): void|null $onChunk Called for each chunk in streaming mode
     * @param string $stdin Written to the child's stdin, then closed — the
     *                      prompt, see the class docblock.
     * @throws ProviderException when the child fails to start (null exitCode) or
     *                           exits non-zero (exitCode carries the shell code).
     */
    public function execute(array $args, ?callable $onChunk = null, string $stdin = ''): string
    {
        $cmd = array_merge([$this->codexPath], $args);

        // E672 doctrine, twin of ClaudeCodeInvocation::execute(): once the
        // containment wrapper fronts the spawn, a bogus codexPath exec-fails
        // INSIDE the wrapper and would surface only as exit 127 attributed to
        // the run. Pre-check keeps the typed spawn-failure contract.
        if (ProcessContainment::detachedSpawnBinary() !== ''
            && !(str_contains($this->codexPath, '/')
                ? is_executable($this->codexPath)
                : ProcessContainment::locateOnPath($this->codexPath) !== '')
        ) {
            throw new ProviderException('Failed to start Codex process');
        }

        // E672/E674 choke-point spec + env. NO overrides: `codex` needs no key
        // this class owns — OPENAI_API_KEY rides the inherited environment and
        // the subscription token lives in ~/.codex, exactly as the operator's
        // own `codex` invocation would read them.
        $process = proc_open(
            ProcessContainment::spawnSpec($cmd),
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            ProcessContainment::env()
        );

        if (!is_resource($process)) {
            throw new ProviderException('Failed to start Codex process');
        }

        // ONE LOOP FEEDS STDIN AND DRAINS BOTH OUTPUT PIPES — the deadlock
        // closure is spelled out in ClaudeCodeInvocation::execute(); the
        // reasoning is byte-for-byte the same here (megabyte prompts, 64 KiB
        // pipes, non-blocking writes).
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
            $reason = self::exitReason($errors, self::turnFailureError(self::lastLine($output)));

            throw new ProviderException("Codex exited with code $exitCode: $reason", exitCode: $exitCode);
        }

        return $output;
    }

    /**
     * Push as much of $pending into the child's non-blocking stdin as the pipe
     * takes now, closing the pipe once nothing is left.
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
     * The message a `turn.failed` line carries, or null for any other line.
     *
     * This is the codex sibling of {@see ClaudeCodeInvocation::resultError()}:
     * measured (0.154.0, unauthenticated run) the CLI exits non-zero with the
     * real reason ONLY in `{"type":"turn.failed","error":{"message":…}}` on
     * stdout — a bare `{"type":"error",…}` line also rides stdout for the same
     * failure, and stderr carries log chatter. Feed the LAST stdout line here.
     */
    public static function turnFailureError(string $line): ?string
    {
        $data = json_decode($line, true);
        if (!is_array($data)) {
            return null;
        }

        if (($data['type'] ?? null) !== 'turn.failed') {
            return null;
        }

        $error = $data['error'] ?? null;

        return is_array($error) && is_string($error['message'] ?? null) && $error['message'] !== ''
            ? $error['message']
            : 'turn failed';
    }

    /**
     * The reason a non-zero exit is reported with: the child's stderr when it
     * said anything there, otherwise the failure its `turn.failed` line carried.
     */
    public static function exitReason(string $stderr, ?string $turnFailure): string
    {
        return $stderr !== '' ? $stderr : ($turnFailure ?? '');
    }

    /** The last non-blank line of $output — where the CLI puts its terminal event. */
    private static function lastLine(string $output): string
    {
        $lines = preg_split('/\R/', trim($output)) ?: [];

        return (string) end($lines);
    }
}
