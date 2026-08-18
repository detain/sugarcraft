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
    /**
     * The three facts a first-time caller cannot infer and pays a wasted turn
     * for: nothing survives between calls (each {@see execute()} builds a
     * fresh `bash -c`, so a `cd`, an export or a shell function is gone by the
     * next one), the result is bounded (see {@see $maxOutputBytes}), and stderr
     * is NOT unconditionally part of the answer.
     *
     * That last clause is stated per branch because
     * {@see CapturesProcessOutput::mergeCapturedOutput()} decides per branch,
     * and the three branches disagree: stderr is appended when the command
     * FAILED, stands in for the whole answer when stdout was empty, and is
     * replaced by a marker when a SUCCEEDING command wrote to both. An earlier
     * draft of this description said "stdout and stderr are merged"
     * unconditionally, which reads a green `phpunit` or compiler run as
     * warning-free when the warnings went to stderr and were dropped — a
     * false claim is worse than the terse sentence it replaced.
     *
     * The byte figure is READ OFF $maxOutputBytes rather than written out,
     * because a caller that raised or disabled the cap would otherwise be
     * advertising a number that is not its own.
     */
    public function description(): string
    {
        $bound = $this->maxOutputBytes > 0
            ? sprintf(
                'The result is clipped at %s bytes, with a marker naming what was dropped.',
                number_format($this->maxOutputBytes),
            )
            : 'There is no size cap on this instance.';

        return 'Execute a bash command. Each call is a fresh `bash -c`, so nothing carries '
            . 'over from the previous one — not the working directory (a `cd` here has no '
            . 'effect on the next call), not environment variables, not shell functions. '
            . 'Output is captured, not written to the terminal. You get stdout; stderr is '
            . 'appended after it only when the command exits non-zero, and is returned on '
            . 'its own when the command wrote nothing to stdout. A command that SUCCEEDS '
            . 'while writing to stderr has that stderr replaced by a one-line marker, not '
            . 'included — so append 2>&1 yourself when the warnings are what you are after. '
            . $bound . ' '
            . 'Prefer Read/Grep/Glob for reading and searching files; reach for this for '
            . 'build, test and git work, and for anything those tools cannot do.';
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
