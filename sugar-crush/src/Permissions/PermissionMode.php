<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Permissions;

/**
 * Permission mode for hook execution, controlling when edits are
 * accepted, plans are shown, or permissions are bypassed.
 */
enum PermissionMode: string
{
    case Default = 'default';
    case AcceptEdits = 'accept-edits';
    case Plan = 'plan';
    case Auto = 'auto';
    case DontAsk = 'dont-ask';
    case BypassPermissions = 'bypass-permissions';

    /**
     * One sentence on what this mode actually does, for a surface that has to
     * SHOW the policy back to the person running under it — `/permissions`,
     * via {@see \SugarCraft\Crush\Chat}.
     *
     * WHY THIS LIVES ON THE ENUM. The alternative is a lookup table in
     * whichever screen is painting, and that table is the thing that goes
     * stale: `/keys` documented bindings nobody drove until
     * `Commands\KeyBindingDriftTest` closed it, and the two command surfaces
     * drifted for the same reason before `Commands\CommandRegistry` gave them
     * one list. A mode's own description cannot be added to a mode that does
     * not exist, and cannot be forgotten for one that does.
     *
     * The `match` is DELIBERATELY DEFAULT-LESS. A seventh case added to this
     * enum makes this throw `\UnhandledMatchError` the first time anything
     * asks — which `Permissions\PermissionModeDescriptionTest` turns into a
     * red suite, rather than a screen that silently describes six modes out of
     * seven, or (worse, with a `default` arm) describes the new one wrongly.
     *
     * Each sentence is written against the evaluator that enforces it —
     * {@see PermissionGate::evaluateDefault()} and its five siblings — not
     * against the class doc-block, which was measured overstating `Plan` as
     * "all writes Deny" when a non-redirecting `Bash` has always been allowed
     * there.
     */
    public function description(): string
    {
        return match ($this) {
            self::Default => 'Reads run silently. Writes, shell and networking ask first.',
            // MEASURED, not assumed. A first draft of this said "writes scoped
            // to the working directory run without asking", which reads as the
            // Write and Edit TOOLS — and PermissionGate::evaluateAcceptEdits()
            // asks about both. The grant is
            // PermissionGate::isScopedWriteTool()'s: a `Bash` call whose command
            // is one of the six filesystem primitives, on contained relative
            // paths. The anchor in Permissions\PermissionModeDescriptionTest is
            // what caught the difference.
            self::AcceptEdits => 'Shell filesystem commands (mkdir, touch, mv, cp, rm, rmdir) on paths '
                . 'below the working directory run without asking. Everything else — the Write and Edit '
                . 'tools included — asks.',
            self::Plan => 'Reads run, and shell commands run for exploration — but a shell command '
                . 'that redirects output, and every other write, is denied.',
            self::Auto => 'Everything runs unless the safety classifier objects. Blocked commands '
                . 'trip a circuit breaker that escalates to asking.',
            self::DontAsk => 'Read-only tools run. Everything else is denied outright rather than '
                . 'asked about.',
            self::BypassPermissions => 'The mode gates nothing. Only explicit deny rules and the '
                . 'unswitchable `rm -rf /` breaker still refuse.',
        };
    }
}
