<?php

declare(strict_types=1);

namespace SugarCraft\Code\Hooks\BuiltIn;

use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\Hooks\HookInterface;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\ShellSubjectTools;
use SugarCraft\Code\Permissions\ShellWords;

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
 * cruder, but a match list must not get NARROWER on input it cannot read. The
 * raw text is always checked too, so nothing asked about before runs unasked now.
 *
 * BYPASS IS ABOVE THIS GUARD-RAIL (owner ruling 2026-10-06). `bypass-permissions`
 * means allow-all: the human already holds the wheel, and every prompt this
 * hook adds is a heuristic the operator explicitly waived. The
 * unswitchable floor for destructive Bash stays where it always was — the
 * step-0 `rm -rf /` breaker inside {@see \SugarCraft\Code\Permissions\PermissionGate},
 * which no mode and no hook can talk open. Constructed without $modeReader
 * (bare embedders, tests) there is no mode to stand the hook down, so a match
 * asks.
 *
 * A MATCH IS AN ASK, NOT A DENY. The hook has no verdict on a destructive
 * command: an operator may mean it (cleaning a build directory). It returns
 * {@see HookResult::ask()} with the explicit question, so the operator decides
 * and a headless run without an answerer still refuses (fail closed).
 */
final readonly class ConfirmRemoveHook implements HookInterface
{
    /** Lazy read of the session's permission mode; see {@see __construct()}. */
    private ?\Closure $modeReader;

    /**
     * @param (callable(): ?PermissionMode)|null $modeReader reads the mode
     *        of the gate registered on the SAME HookManager at execute time —
     *        lazy, not a captured gate, because a mode switch replaces the
     *        gate object mid-session ({@see \SugarCraft\Code\Chat}'s
     *        permission-mode toggle re-registers {@see PermissionGateHook});
     *        null (or a chain with no gate) never stands the hook down, so a
     *        match asks ({@see execute()}).
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
        // this hook is documented as asking about everything that breaker refuses.
        '/\brm\b[^\n]*\s--(?:r(?:e(?:c(?:u(?:r(?:s(?:i(?:ve?)?)?)?)?)?)?)?|f(?:o(?:r(?:ce?)?)?)?)(?!\w)/i',
        // find ... -delete wipes every matched entry.
        '/\bfind\b[^\n]*\s-delete\b/i',
        // shred overwrites files irrecoverably.
        '/\bshred\b/i',
        // dd with an output file/device overwrites it wholesale.
        '/\bdd\b[^\n]*\bof=/i',
    ];

    /**
     * The question put to the operator when a destructive pattern matches.
     * It is the permission prompt's body ({@see HookResult::ask()}), so it
     * says what matched and what approval means, in terms the human can act on.
     */
    private const CONFIRM_MESSAGE = 'This command matches the recursive/force rm, find -delete, '
        . 'shred or dd of= guard-rail. Approve it only if you mean to run it. The guard-rail '
        . 'is a best-effort heuristic, not a security boundary.';

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
        // ISS-002: the roster IS the matcher. `PtyRun` runs the same `/bin/sh -c`
        // command and TYPES more shell text at the live prompt it spawns, so an
        // `^Bash$` matcher let the interactive driver step around this
        // guard-rail wholesale. ShellSubjectToolsCompletenessTest pins the
        // construction against the roster.
        return '^(?:' . implode('|', ShellSubjectTools::NAMES) . ')$';
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

        // Every text the call puts through the shell: `command`, and for PtyRun
        // each decoded step (ISS-002). A `rm -rf build` typed at a spawned
        // prompt is the same footgun as one spawned directly.
        foreach (ShellSubjectTools::judgedShellTexts($context->toolName, $context->toolArgs) as $command) {
            $parsed = ShellWords::parse($command);
            $candidates = [
                $command,
                $parsed->complete ? $parsed->dequoted() : str_replace(["'", '"', '\\'], '', $command),
            ];

            foreach (self::DANGEROUS_PATTERNS as $pattern) {
                if (preg_grep($pattern, $candidates) !== []) {
                    // ASK, not DENY: the operator decides. A headless model cannot
                    // run `rm` interactively, so a refusal here left it no way to
                    // finish the task. An unanswered ASK still never runs (see
                    // HookResult::permitsExecution()), and every surface that cannot
                    // put the question fails closed on it.
                    return HookResult::ask(self::CONFIRM_MESSAGE);
                }
            }
        }

        return HookResult::allow();
    }
}
