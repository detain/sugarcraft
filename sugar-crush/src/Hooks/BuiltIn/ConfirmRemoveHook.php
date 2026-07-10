<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks\BuiltIn;

use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookResult;

/**
 * Best-effort guard-rail against the common destructive shell commands.
 *
 * IMPORTANT: this is a HEURISTIC, not a security boundary. Regex cannot see
 * through shell indirection — `x=rf; rm -$x`, aliases, `$(echo rm) -rf`,
 * base64-decoded payloads, or quoting tricks all evade it. It exists to catch
 * the obvious footguns (a model literally emitting `rm -rf`), not to sandbox a
 * hostile command. For real containment, run the tool in a jail/VM.
 */
final readonly class ConfirmRemoveHook implements HookInterface
{
    /**
     * Destructive-command patterns, each matched against the raw Bash command.
     * Deliberately conservative — see the class docblock on why this can only
     * be a heuristic.
     */
    private const DANGEROUS_PATTERNS = [
        // rm with short-form recursive/force flags (-r, -f, -rf, -rfv, ...).
        '/\brm\b[^\n]*\s-[a-z]*[rf]/i',
        // rm with GNU long-form flags: --recursive / --force.
        '/\brm\b[^\n]*\s--(recursive|force)\b/i',
        // find ... -delete wipes every matched entry.
        '/\bfind\b[^\n]*\s-delete\b/i',
        // shred overwrites files irrecoverably.
        '/\bshred\b/i',
        // dd with an output file/device overwrites it wholesale.
        '/\bdd\b[^\n]*\bof=/i',
    ];

    public function name(): string
    {
        return 'confirm-rm';
    }

    public function event(): HookEvent
    {
        return HookEvent::PreToolUse;
    }

    public function matcher(): string
    {
        return '^Bash$';
    }

    public function execute(HookContext $context): HookResult
    {
        $command = $context->toolArgs['command'] ?? '';

        foreach (self::DANGEROUS_PATTERNS as $pattern) {
            if (preg_match($pattern, $command)) {
                return HookResult::deny(
                    'This hook prevents recursive/force rm and other destructive '
                    . 'commands (find -delete, shred, dd of=). It is a best-effort '
                    . 'heuristic, not a security boundary. Use interactive rm instead.'
                );
            }
        }

        return HookResult::allow();
    }
}
