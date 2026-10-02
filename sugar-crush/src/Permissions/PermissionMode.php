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
     * "all writes Deny" when a non-redirecting `Bash` was allowed there (it no
     * longer is — see the `Plan` arm).
     *
     * THREE OF THESE SIX SHIPPED FALSE, and they were found by re-measuring
     * every clause rather than by re-reading them — `default` on networking,
     * `accept-edits` on reads, `plan` on writes. The common shape is that each
     * had ONE clause nobody had driven through the gate, and in all three the
     * un-driven clause was the wrong one; the sentences read fine, which is the
     * whole problem with checking a policy screen by reading it. So the
     * anti-drift test no longer picks representative rows: it declares a TOTAL
     * matrix of every probe tool against every mode, asserts the matrix covers
     * `cases()` × probes with no gaps, and drives every cell. A future clause
     * about a tool can still be wrong, but it can no longer be wrong about a
     * tool the suite never asked about.
     */
    public function description(): string
    {
        return match ($this) {
            // MEASURED, and the first version of this sentence was WRONG about
            // exactly one of its three claims. It said "Writes, shell and
            // networking ask first"; `WebFetch` is in
            // PermissionGate::isReadOnlyTool(), so under `default` an outbound
            // fetch runs SILENTLY. The anchor table of the day carried two rows
            // for `default` — a read and a write — and the un-anchored claim was
            // the false one. That is why the table below is now TOTAL over a
            // probe set rather than a hand-picked subset: a claim cannot be the
            // one nobody measured when every tool is measured for every mode.
            self::Default => 'Reads run silently, and WebFetch is classed a read — it fetches without '
                . 'asking. Everything else asks first: writes, shell, and WebSearch.',
            // MEASURED, not assumed. A first draft of this said "writes scoped
            // to the working directory run without asking", which reads as the
            // Write and Edit TOOLS — and PermissionGate::evaluateAcceptEdits()
            // asks about both. The grant is
            // PermissionGate::isScopedWriteTool()'s: a `Bash` call whose command
            // is one of the six filesystem primitives, on contained relative
            // paths. The anchor in Permissions\PermissionModeDescriptionTest is
            // what caught the difference.
            //
            // The SECOND thing measured wrong here was the closing clause.
            // "Everything else … asks" literally covers reads, and
            // evaluateAcceptEdits() allows them — its first branch is the same
            // isReadOnlyTool() check `default` uses. The reads clause is now
            // stated rather than left to be inferred from a word that excluded
            // it.
            self::AcceptEdits => 'Reads run. Shell filesystem commands (mkdir, touch, mv, cp, rm, rmdir) '
                . 'on paths below the working directory also run without asking, and the same command on a '
                . 'path outside it asks. Everything else — the Write and Edit tools included — asks.',
            // MEASURED, twice. The first sentence here said "every other write
            // is denied", which was false for a Bash write: evaluatePlan() then
            // allowed every Bash call a three-regex redirect check missed, so
            // `rm ./a` and `curl https://x.example` both ran. The second
            // sentence told the truth about that — "any shell command that does
            // not redirect output runs … this is not a dry run" — and the truth
            // was the defect (audit F-P2): `sed -i`, `git push --force` and
            // `echo x>f` ran unprompted in the mode a user picks to change
            // nothing. The POLICY was fixed rather than the sentence this time:
            // Plan now runs a shell command only when every command in it is on
            // PermissionGate's read-only allow-list, and denies the rest. The
            // `rm` and `curl` clauses stay because they are the two a user
            // would most reasonably wonder about.
            self::Plan => 'Reads run, and so does a shell command made only of known read-only commands '
                . '(`git log`, `grep`, `ls` …) with no output redirection or substitution. Any other shell '
                . 'command is denied — a destructive `rm` and an outbound `curl` included — as is every write '
                . 'through Write, Edit or an MCP tool.',
            self::Auto => 'Everything runs unless the safety classifier objects. Blocked commands '
                . 'trip a circuit breaker that escalates to asking.',
            self::DontAsk => 'Read-only tools run. Everything else is denied outright rather than '
                . 'asked about.',
            self::BypassPermissions => 'The mode gates nothing. Only explicit deny rules and the '
                . 'unswitchable `rm -rf /` breaker still refuse.',
        };
    }
}
