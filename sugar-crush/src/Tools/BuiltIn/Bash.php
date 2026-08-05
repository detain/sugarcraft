<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Agents\PathJail as AgentPathJail;
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
    public function __construct(
        private ?string $root = null,
        private ?AgentPathJail $worktreeJail = null,
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
        ],
        'required' => ['command'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $command = $args['command'] ?? '';
        $output = [];
        $exitCode = 0;
        // Worktree jail takes precedence over root for isolated teammates;
        // jailPath('') returns the worktree root when path is empty.
        $cwd = $this->worktreeJail?->jailPath('') ?? $this->root ?? null;
        // Mirrors charmbracelet/bubbletea.*.Exec.
        // Use bash -c to interpret shell syntax; escapeshellarg prevents command injection.
        if ($cwd !== null) {
            $cmd = "bash -c " . escapeshellarg("cd " . escapeshellarg($cwd) . " && " . $command);
        } else {
            $cmd = "bash -c " . escapeshellarg($command);
        }
        exec($cmd, $output, $exitCode);
        return new ToolResult(
            toolCallId: $args['id'] ?? '',
            content: implode("\n", $output),
            isError: $exitCode !== 0,
        );
    }
}
