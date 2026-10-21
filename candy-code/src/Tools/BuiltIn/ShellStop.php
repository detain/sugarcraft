<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\BuiltIn;

use SugarCraft\Code\Background\BackgroundShellRegistry;
use SugarCraft\Code\Tools\CarriesSessionState;
use SugarCraft\Code\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Code\Tools\Catalog\BuiltInTool;
use SugarCraft\Code\Tools\Catalog\ToolBuildContext;
use SugarCraft\Code\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Code\Tools\TakesToolCallId;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolResult;

/**
 * Stops one background shell started by Bash's `run_in_background` (SHL-1).
 *
 * THE LADDER: SIGTERM to the recorded process GROUP (the containment spawn
 * made the shell a group leader, so everything it started rides the same
 * signal), a bounded grace, then SIGKILL to the same group. The id's pid was
 * recorded at spawn while the leader was alive (ISS-014), so the signal can
 * never chase a recycled pid. `confirmed` follows the same law as the
 * synchronous timeout path: true only when the shell's death was OBSERVED —
 * the exit file landed or the leader stopped answering — and an unconfirmed
 * stop is narrated as unconfirmed, never as a kill.
 *
 * THE GROUP PROMISE IS A `setsid` PROMISE (SHL-1). On a host without one the
 * recorded pid never led a group, the ladder degrades to signalling the
 * wrapper alone, and processes the command started may keep running. Rows
 * carry that fact from spawn as `grouped`, and the confirmed answer says so
 * out loud — `confirmed` on the degraded path means the wrapper is dead, and
 * nothing more.
 *
 * WRITE permission class, and this is the roster argument the review asks
 * for: ShellStop is a PROCESS MUTATION. It belongs on
 * {@see \SugarCraft\Code\Runtime::WRITE_CAPABLE_TOOL_NAMES} because a call
 * that tears down a process tree changes what later tool calls in the same
 * batch can depend on, exactly like the writes the roster exists to detect.
 *
 * ShellStop is deliberately NOT on {@see \SugarCraft\Code\Permissions\ShellSubjectTools}:
 * that roster is the list of tools whose SUBJECT IS A SHELL COMMAND LINE —
 * text the permission matchers parse with `Bash(rm *)`-style rules, segment
 * splitting, and the cross-binding proxy. ShellStop's only argument is an
 * opaque registry id (`sh-xxxxxxxx`); there is no command text to read, so a
 * rule about command lines has nothing to bind to, and pretending otherwise
 * (naming the shell's original command here) would leak a second,
 * un-parseable copy of it past the matcher. Background shells therefore stay
 * permissioned where they are CREATED — the Bash call that spawns one,
 * `run_in_background` and all, is judged exactly like a synchronous Bash
 * call — and ShellStop is gated as an ordinary Write tool. Restrictive
 * Bash rules cannot name a background shell they never saw; that is the
 * honest shape, and `disabledTools: ["Bash"]` remains the blunt instrument.
 */
#[BuiltInTool(name: 'ShellStop', permission: ToolPermissionClass::Write, position: 38, gloss: 'stop a background shell started with Bash run_in_background')]
final readonly class ShellStop implements Tool, BuildsFromCatalog, TakesToolCallId, CarriesSessionState
{
    public const NAME = 'ShellStop';

    private function __construct(
        private ?BackgroundShellRegistry $registry = null,
    ) {}

    public static function new(): self
    {
        return new self();
    }

    public static function fromCatalog(ToolBuildContext $context): self
    {
        return new self($context->backgroundShells);
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Stop a background shell started with Bash(run_in_background: true), addressed by the shell_id Bash returned. Sends SIGTERM to the shell and everything in its process group, waits briefly, then SIGKILL. Returns the shell\'s final state and exit code when the death was confirmed.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'shell_id' => [
                    'type' => 'string',
                    'description' => 'The id Bash returned when it started the shell (sh-xxxxxxxx).',
                ],
            ],
            'required' => ['shell_id'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $id = $args['shell_id'] ?? null;
        if (!\is_string($id) || preg_match('/^sh-[0-9a-f]{8}$/', $id) !== 1) {
            return $this->refuse('shell_id must be an id of the form sh-xxxxxxxx exactly as Bash returned it');
        }
        if ($this->registry === null) {
            return $this->refuse('no background shell registry is attached to this launch');
        }

        $answer = $this->registry->stop($id);
        if ($answer['found'] !== true) {
            return $this->refuse('no background shell ' . $id . ' is registered by this launch');
        }

        return new ToolResult(
            toolCallId: (string) ($args['id'] ?? ''),
            content: $this->paint($id, $answer),
            // A stop that could not confirm the death is a failure worth the
            // model's attention: it must not retry blindly nor assume the
            // processes are gone.
            isError: $answer['wasRunning'] === true && $answer['confirmed'] !== true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function exportSessionState(): array
    {
        return [BackgroundShellRegistry::SESSION_STATE_KEY => $this->registry?->toArray() ?? []];
    }

    /**
     * @param array<string, mixed> $state
     */
    public function mergeSessionState(array $state): void
    {
        $this->registry?->merge($state[BackgroundShellRegistry::SESSION_STATE_KEY] ?? null);
    }

    /**
     * @param array{wasRunning: bool, confirmed: bool, grouped: bool, exitCode: ?int, expired: bool} $answer
     */
    private function paint(string $id, array $answer): string
    {
        if ($answer['wasRunning'] !== true) {
            return sprintf(
                'shell %s had already settled%s; nothing was signalled.',
                $id,
                $answer['exitCode'] !== null ? ' (exit ' . $answer['exitCode'] . ')' : ' (exit code unknown)',
            );
        }
        if ($answer['confirmed'] !== true) {
            return sprintf(
                'shell %s was signalled but its death was not confirmed — anything in its group may still be running.',
                $id,
            );
        }

        return sprintf(
            'shell %s %s%s%s.',
            $id,
            $answer['expired'] === true ? 'had been expired at the lifetime wall and is now' : 'is',
            $answer['exitCode'] !== null ? ' stopped (exit ' . $answer['exitCode'] . ')' : ' stopped (exit code unknown)',
            $answer['grouped'] === true ? '' : ' — this host offered no setsid at spawn, so only the shell wrapper was signalled; processes it started may still be running',
        );
    }

    private function refuse(string $reason): ToolResult
    {
        return new ToolResult(toolCallId: '', content: $reason, isError: true);
    }
}
