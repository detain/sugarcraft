<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks\BuiltIn;

use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Permissions\ShellWords;

/**
 * Best-effort guard-rail against the common destructive shell commands.
 *
 * IMPORTANT: this is a HEURISTIC, not a security boundary. Regex cannot see
 * through shell indirection — `x=rf; rm -$x`, aliases, `$(echo rm) -rf`,
 * `bash -c`/`eval`, base64-decoded payloads all evade it. It exists to catch
 * the obvious footguns (a model literally emitting `rm -rf`), not to sandbox a
 * hostile command. For real containment, run the tool in a jail/VM.
 *
 * QUOTING IS NOT ONE OF THOSE EVASIONS ANY MORE. The patterns used to run
 * against the raw command only, and they want whitespace directly before the
 * flag, so `rm '-rf' x`, `rm "-rf" x` and `find . '-delete'` — byte-identical
 * argv to the unquoted spelling once bash removes the quotes — passed (audit
 * F-P1, measured through the full built-in chain under the shipped
 * `bypass-permissions` default). Each pattern now also runs against
 * {@see ShellWords::dequoted()}: the quote-removed words, one simple command
 * per line. When the line does not parse (an unterminated quote) the second
 * candidate is the raw text with every quote and backslash deleted instead —
 * cruder, but a deny list must not get NARROWER on input it cannot read. The
 * raw text is always checked too, so nothing denied before is allowed now.
 *
 * BYPASS IS ABOVE THIS GUARD-RAIL (owner ruling 2026-10-06). `bypass-permissions`
 * means allow-all: the human already holds the wheel, and every refusal this
 * hook adds is a heuristic prompt the operator explicitly waived. The
 * unswitchable floor for destructive Bash stays where it always was — the
 * step-0 `rm -rf /` breaker inside {@see \SugarCraft\Crush\Permissions\PermissionGate},
 * which no mode and no hook can talk open. Constructed without
 * $modeReader (bare embedders, tests) the hook denies exactly as before.
 */
final readonly class ConfirmRemoveHook implements HookInterface
{
    /** Lazy read of the session's permission mode; see {@see __construct()}. */
    private ?\Closure $modeReader;

    /**
     * @param (callable(): ?PermissionMode)|null $modeReader reads the mode
     *        of the gate registered on the SAME HookManager at execute time —
     *        lazy, not a captured gate, because a mode switch replaces the
     *        gate object mid-session ({@see \SugarCraft\Crush\Chat}'s
     *        permission-mode toggle re-registers {@see PermissionGateHook});
     *        null (or a chain with no gate) keeps the pre-ruling behaviour.
     */
    public function __construct(?\Closure $modeReader = null)
    {
        $this->modeReader = $modeReader;
    }
    /**
     * Destructive-command patterns, each matched against the raw Bash command
     * AND its quote-removed form (see the class docblock). Deliberately
     * conservative — see the class docblock on why this can only be a
     * heuristic.
     */
    private const DANGEROUS_PATTERNS = [
        // rm with short-form recursive/force flags (-r, -f, -rf, -rfv, ...).
        '/\brm\b[^\n]*\s-[a-z]*[rf]/i',
        // rm with GNU long-form flags: --recursive / --force, and the
        // unambiguous abbreviations GNU getopt accepts for them (`--rec`,
        // `--forc`) — the step-0 breaker in PermissionGate counts those, and
        // this hook is documented as refusing everything that breaker does.
        '/\brm\b[^\n]*\s--(?:r(?:e(?:c(?:u(?:r(?:s(?:i(?:ve?)?)?)?)?)?)?)?|f(?:o(?:r(?:ce?)?)?)?)(?!\w)/i',
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
        // Early exit for the one mode the operator declared above the
        // guard-rails; see the class docblock's ruling. A null reader means no
        // gate was wired into this manager, which is NOT a licence to allow.
        $read = $this->modeReader;
        if ($read !== null && $read() === PermissionMode::BypassPermissions) {
            return HookResult::allow();
        }

        $command = $context->toolArgs['command'] ?? '';
        if (!is_string($command)) {
            $command = '';
        }

        $parsed = ShellWords::parse($command);
        $candidates = [
            $command,
            $parsed->complete ? $parsed->dequoted() : str_replace(["'", '"', '\\'], '', $command),
        ];

        foreach (self::DANGEROUS_PATTERNS as $pattern) {
            if (preg_grep($pattern, $candidates) !== []) {
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
