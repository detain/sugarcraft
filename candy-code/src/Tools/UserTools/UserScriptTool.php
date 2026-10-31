<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\UserTools;

use SugarCraft\Code\Agents\PathJail as AgentPathJail;
use SugarCraft\Code\Support\ProcessContainment;
use SugarCraft\Code\Tools\AcceptsHeartbeat;
use SugarCraft\Code\Tools\AcceptsWorktreeJail;
use SugarCraft\Code\Tools\BuiltIn\Bash;
use SugarCraft\Code\Tools\Concerns\CapturesProcessOutput;
use SugarCraft\Code\Tools\Sandbox\SandboxBackend;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolResult;

/**
 * One user-declared script tool (C-TOOL-6.e): a `~/.candy-code/tools/*.json`
 * file, made dispatchable under the wire name `user__<name>`.
 *
 * WHAT IT IS NOT. It is not a catalog tool — there is no `#[BuiltInTool]`
 * here, because the roster is the PERSON's, not the build's: it arrives
 * through {@see \SugarCraft\Code\Cli\Bootstrap::userScriptTools()} exactly
 * the way {@see \SugarCraft\Code\Tools\McpToolBridge} arrives through
 * `mcpTools()`, and it passes through the same `allowedTools`/
 * `disabledTools` filter as everything else in the launch roster.
 *
 * THE COMMAND, AND WHY IT CANNOT BE SPOOKED. argv is a fixed spine: the
 * program comes from the FILE (element 0 never carries a placeholder — the
 * validator refuses it at load), and model-supplied values only fill
 * `{{prop}}` holes. Then EVERY element goes through `escapeshellarg()`
 * BEFORE the join, so a value of `; rm -rf /` is one quoted argv word of
 * the chosen program, never a second command — the pinned test is exactly
 * that string. The line is then wrapped for `bash -c` through
 * {@see ProcessContainment::cdGuard()} and, when the `bashSandbox` setting
 * has a backend, through the SAME {@see SandboxBackend::wrap()} Bash uses,
 * with the project root as the writable root — a user tool is a script, not
 * a way around the jail. A sandbox that cannot start refuses the call
 * rather than running it unconfined, in Bash's words.
 *
 * THE PERMISSION TWIN: `user__*` is a WRITE-class name for every evaluator —
 * {@see \SugarCraft\Code\Runtime::USER_TOOL_PREFIX} folds it into
 * `isWriteCapableTool()`, {@see \SugarCraft\Code\Permissions\PermissionGate::isWriteTool()}
 * claims it before its final `mcp__` literal, and under `auto` it lands in
 * the same never-classified Ask the `mcp__` branch keeps (neither a strike
 * nor a safe call — the breaker stays where it was). `Allow user__fmt`
 * grants ONE tool; the prefix is the class, not a wildcard grant.
 *
 * THE TIMEOUT is the file's own `timeout` (1..600, default 60) — a
 * per-PROCESS run bound passed to {@see CapturesProcessOutput::runCaptured()},
 * which kills the child's whole session group when it bites. It is not a
 * blanket request timeout and it never wraps an LLM call, so the standing
 * E646 ban is untouched; while the child runs, the heartbeat rides
 * `runCaptured()`'s per-slice wait hook exactly as Bash's does, so a slow
 * tool keeps its turn alive by being seen to work.
 *
 * The call's arguments also reach the child VERBATIM as JSON in
 * `CANDY_CODE_TOOL_INPUT`, so a script that prefers reading stdin-style
 * structured input to shelling out placeholders has one.
 */
final readonly class UserScriptTool implements Tool, AcceptsHeartbeat, AcceptsWorktreeJail
{
    use CapturesProcessOutput;

    /** The wire-name prefix — the class every evaluator recognises. */
    public const NAME_PREFIX = 'user__';

    /** Bytes of the child's stderr kept in a failure row (the tail — end matters first). */
    public const STDERR_TAIL_BYTES = 2048;

    public function __construct(
        private UserToolSpec $spec,
        private ?string $root = null,
        private ?SandboxBackend $sandbox = null,
        private ?AgentPathJail $worktreeJail = null,
    ) {}

    public function name(): string
    {
        return self::NAME_PREFIX . $this->spec->name;
    }

    public function description(): string
    {
        return $this->spec->description;
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return $this->spec->inputSchema;
    }

    public function withWorktreeJail(AgentPathJail $jail): static
    {
        return new self(
            spec: $this->spec,
            root: $this->root,
            sandbox: $this->sandbox,
            worktreeJail: $jail,
        );
    }

    /**
     * @param array<string, mixed> $args
     */
    public function execute(array $args): ToolResult
    {
        return $this->executeWithHeartbeat($args, static function (): void {});
    }

    /**
     * @param array<string, mixed> $args
     * @param \Closure(): void $heartbeat
     */
    public function executeWithHeartbeat(array $args, \Closure $heartbeat): ToolResult
    {
        $rendered = $this->renderArgv($args, $failure);
        if ($rendered === null) {
            return $this->error($failure ?? 'the call was refused before the command was built');
        }

        $cwd = $this->worktreeJail?->root() ?? $this->root ?? (string) getcwd();

        $command = "bash -c " . escapeshellarg(ProcessContainment::cdGuard($cwd) . $rendered);

        if ($this->sandbox !== null) {
            $probeRefusal = $this->sandbox->probe(function (string $probe): array {
                $result = $this->runCaptured($probe, null, 4096, 10.0);

                return ['exitCode' => $result['exitCode'], 'stderr' => $result['stderr']];
            });
            if ($probeRefusal !== null) {
                return $this->error(sprintf(
                    'Not run: the bashSandbox setting is "%s" but the %s sandbox cannot start on this host (%s). '
                        . 'Fix the sandbox, turn it off, or ask the person who declared %s to review both.',
                    $this->sandbox->name(),
                    $this->sandbox->name(),
                    $probeRefusal,
                    $this->name(),
                ));
            }

            $command = $this->sandbox->wrap($command, $cwd, false);
        }

        $result = $this->runCaptured(
            $command,
            null,
            // The Bash cap, by reference: Bash owns the TruncatesOutput trait
            // and this tool is meant to cost exactly what a Bash call costs.
            Bash::DEFAULT_MAX_OUTPUT_BYTES,
            (float) $this->spec->timeoutSeconds,
            ['CANDY_CODE_TOOL_INPUT' => json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
            static function () use ($heartbeat): void {
                $heartbeat();
            },
        );

        if ($result['timedOut']) {
            return $this->error(sprintf(
                '%s timed out after %d seconds and its process group was killed. stderr: %s',
                $this->name(),
                $this->spec->timeoutSeconds,
                self::tail($result['stderr']),
            ));
        }

        if ($result['exitCode'] !== 0) {
            return $this->error(sprintf(
                '%s exited with code %d. stderr: %s',
                $this->name(),
                $result['exitCode'],
                self::tail($result['stderr']),
            ));
        }

        $stdout = $result['stdout'];
        if ($result['truncatedBytes'] > 0) {
            $stdout .= sprintf("\n[output truncated: %d bytes over the capture bound]", $result['truncatedBytes']);
        }

        return new ToolResult(toolCallId: '', content: $stdout === '' ? '(no output)' : $stdout);
    }

    /**
     * The argv spine with the call's scalars substituted, as one built shell
     * line — or null with $failure set. NOTHING spawns until this returns;
     * every refusal a bad call can earn is a refusal BEFORE the boundary.
     *
     * Scalar values only: an array or object has no one-token shell reading,
     * and inventing one (join? JSON?) would make the file author's quoting
     * questions depend on a coercion nobody declared.
     *
     * @param array<string, mixed> $args
     * @param string|null $failure out-parameter: why null means no
     */
    private function renderArgv(array $args, ?string &$failure): ?string
    {
        foreach ($args as $key => $value) {
            if (!in_array($key, $this->spec->declaredProperties(), true)) {
                $failure = sprintf('`%s` is not a property declared by %s', (string) $key, $this->name());

                return null;
            }

            if (!is_scalar($value) && $value !== null) {
                $failure = sprintf(
                    '`%s` must be a string or number; array and object argument values are not supported',
                    (string) $key,
                );

                return null;
            }
        }

        foreach ($this->spec->requiredProperties() as $required) {
            if (!array_key_exists($required, $args)) {
                $failure = sprintf('`%s` is required by %s', $required, $this->name());

                return null;
            }
        }

        $words = [];
        foreach ($this->spec->argv as $element) {
            $placeholders = UserToolSpec::placeholdersIn($element);

            if ($placeholders === []) {
                $words[] = escapeshellarg($element);

                continue;
            }

            // The drop rule, exactly as declared: an element that IS only a
            // placeholder, whose property the call left out (it was not
            // required), disappears — that is how an optional flag is spelled.
            if (count($placeholders) === 1
                && $element === '{{' . $placeholders[0] . '}}'
                && !in_array($placeholders[0], $this->spec->requiredProperties(), true)
                && !array_key_exists($placeholders[0], $args)
            ) {
                continue;
            }

            $substituted = $this->substitute($element, $args, $failure);
            if ($substituted === null) {
                return null;
            }

            $words[] = escapeshellarg($substituted);
        }

        return implode(' ', $words);
    }

    /**
     * Replace every `{{prop}}` in one element. An absent optional in the
     * MIDDLE of an element is not droppable (the element still has other
     * text), and leaving the placeholder raw would ship `{{prop}}` to a
     * program as data — so it is a named failure instead.
     *
     * @param array<string, mixed> $args
     */
    private function substitute(string $element, array $args, ?string &$failure): ?string
    {
        $out = (string) preg_replace_callback(
            UserToolSpec::PLACEHOLDER_PATTERN,
            function (array $hit) use ($args, &$failure): string {
                $prop = $hit[1];
                if (!array_key_exists($prop, $args)) {
                    $failure = sprintf('`%s` was not supplied for %s', $prop, $this->name());

                    return $hit[0];
                }

                return self::stringify($args[$prop]);
            },
            $element,
        );

        if ($failure !== null) {
            return null;
        }

        if (str_contains($out, '{{')) {
            $failure = sprintf('a malformed placeholder reached the call for %s', $this->name());

            return null;
        }

        return $out;
    }

    private static function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '';
        }

        return (string) $value;
    }

    private static function tail(string $stderr): string
    {
        $trimmed = rtrim($stderr);

        return strlen($trimmed) > self::STDERR_TAIL_BYTES
            ? '…' . substr($trimmed, -self::STDERR_TAIL_BYTES)
            : ($trimmed === '' ? '(no stderr)' : $trimmed);
    }

    /**
     * Model-visible refusal; the catalogue reserves Lang for person-facing
     * rows, and tool results stay English in the code (LOCALES.md,
     * candy-code row).
     */
    private function error(string $reason): ToolResult
    {
        return new ToolResult(toolCallId: '', content: $reason, isError: true);
    }
}
