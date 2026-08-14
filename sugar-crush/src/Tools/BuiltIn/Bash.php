<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Agents\PathJail as AgentPathJail;
use SugarCraft\Crush\Tools\Concerns\CapturesProcessOutput;
use SugarCraft\Crush\Tools\Concerns\TruncatesOutput;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Runs a shell command.
 *
 * SECURITY — intentional PathJail asymmetry: unlike {@see Edit}, {@see Read},
 * {@see Glob} and {@see Grep}, Bash is deliberately NOT path-jailed. `$root`
 * only sets the working directory (a `cd` prefix); it does NOT confine the
 * command. Arbitrary shell can `cat /etc/passwd`, `cd /`, follow symlinks, or
 * reach anything the PHP process can — jailing free-form shell by rewriting the
 * command string is not sound, so we don't pretend to.
 *
 * When a worktree PathJail is injected, the `cd` prefix targets the worktree
 * root so git/file operations run within that isolated tree. The command
 * itself is still unconstrained.
 *
 * Callers that need containment have two layers: run the process itself in a
 * real jail/container, and/or opt into
 * {@see \SugarCraft\Crush\Hooks\BuiltIn\BashEscapeDenyHook}, a heuristic
 * PreToolUse hook that denies commands referencing paths outside `$root`.
 */
final readonly class Bash implements Tool
{
    use CapturesProcessOutput;
    use TruncatesOutput;

    /**
     * $maxOutputBytes bounds what a command can push into the context window.
     * Shell output is the least predictable of any tool's — `find /`, a `cat`
     * of a build artefact or a verbose test run all produce megabytes with no
     * warning — so the bound applies during capture as well as after it (see
     * {@see CapturesProcessOutput::runCaptured()}). Zero or negative disables
     * the cap for a caller that has arranged its own containment.
     */
    public function __construct(
        private ?string $root = null,
        private ?AgentPathJail $worktreeJail = null,
        private int $maxOutputBytes = self::DEFAULT_MAX_OUTPUT_BYTES,
    ) {}

    public function name(): string
    {
        return 'Bash';
    }
    public function description(): string
    {
        return 'Execute a bash command';
    }
    public function inputSchema(): array
    {
        return [
        'type' => 'object',
        'properties' => [
            'command' => ['type' => 'string', 'description' => 'The bash command to execute'],
            'description' => [
                'type' => 'string',
                'description' => 'Clear, concise 5-10 word description in active voice of what this command does (e.g. "List files in current directory", not "runs ls").',
            ],
        ],
        'required' => ['command', 'description'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $command = $args['command'] ?? '';
        $output = [];
        $exitCode = 0;
        // Worktree jail takes precedence over root for isolated teammates.
        // root() says "the directory this jail is bound to" outright, where
        // the previous jailPath('') got the same string by relying on an
        // edge case of a join helper that checks no containment at all.
        $cwd = $this->worktreeJail?->root() ?? $this->root ?? null;
        // Mirrors charmbracelet/bubbletea.*.Exec.
        // Use bash -c to interpret shell syntax; escapeshellarg prevents command injection.
        if ($cwd !== null) {
            $cmd = "bash -c " . escapeshellarg("cd " . escapeshellarg($cwd) . " && " . $command);
        } else {
            $cmd = "bash -c " . escapeshellarg($command);
        }
        // runCaptured(), not exec(): exec() leaves the child's stderr
        // attached to the real terminal, so a command that writes there
        // paints outside the TUI frame -- and the model never sees the
        // reason a command failed.
        $run = $this->runCaptured($cmd, null, $this->maxOutputBytes > 0 ? $this->maxOutputBytes : null);

        // The merge can concatenate stdout AND stderr, so each being within
        // the bound is not the same as the result being within it — the final
        // string gets the authoritative clip. It is handed the merge's own
        // account of what survived rather than the capture's raw totals: only
        // the merge knows whether the stderr the capture clipped is even part
        // of this answer, and whether the failure explanation still has to be
        // fitted in alongside a large stdout.
        return new ToolResult(
            toolCallId: $args['id'] ?? '',
            content: $this->truncateMerged(
                $this->mergeCapturedOutput($run),
                $this->maxOutputBytes,
            ),
            isError: $run['exitCode'] !== 0,
        );
    }
}
