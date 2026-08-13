<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools;

/**
 * Opt-in declaration that a {@see Tool} may run CONCURRENTLY with its
 * same-turn siblings (crush_code.md Phase 0 item 14).
 *
 * {@see \SugarCraft\Crush\Runtime::executeToolCalls()} fans a batch out over
 * one forked child per call, so "safe" here means two independent things, and
 * a tool must satisfy BOTH before it says true:
 *
 *  1. It does not mutate anything a sibling could observe — no file writes, no
 *     shell, no network side effects. Two concurrent calls must be unable to
 *     race each other, and (because a forked tool child outlives a cancelled
 *     turn — nothing can signal it once its parent is SIGKILLed) an orphaned
 *     call must be unable to leave the workspace in a state the user did not
 *     ask for. This is why `Bash`/`Edit` are absent and why any `mcp__*` tool,
 *     whose capability is server-defined and unknowable here, is absent too.
 *
 *  2. Any session-scoped state it DOES mutate survives the fork, either
 *     because there is none or because the tool also implements
 *     {@see CarriesSessionState} and hands that state back to the parent.
 *     Without this, "announce this file's CLAUDE.md once per session" silently
 *     becomes "once per tool call" as soon as the call is forked.
 *
 * NOT implementing this interface is the safe default: an unknown or
 * user-supplied tool is treated as a barrier and executed alone, in
 * provider order, exactly as it is today.
 *
 * ---
 *
 * Three things this rule does NOT cover, none of which the code can enforce:
 *
 * **The rule is about TOOLS, not HOOKS.** "Everything that can overlap is
 * non-mutating" is a statement about the tools in a group; a `PostToolUse`
 * hook is user-supplied code and free to mutate whatever it likes. Every
 * member of a group is forked before ANY of them reaches PostToolUse (hooks
 * run in the parent, in provider order, as each result is released), so a
 * mutating hook's effects that a later sibling used to observe are now
 * invisible to it. Concretely, for a hook that writes a marker file three
 * `Read`s report the contents of: sequential dispatch gives
 * `sees=nothing | sees=post-hook-ran-1 | sees=post-hook-ran-2`, concurrent
 * gives `sees=nothing` three times. What IS preserved is the count and the
 * order of hook invocations — only the point at which they interleave with
 * the tool bodies moved.
 *
 * **An orphaned tool child has no deadline.** {@see
 * \SugarCraft\Crush\Runtime}'s 90s group deadline is enforced by the
 * completion child that forked the group. SIGKILL that parent (an
 * Escape-Escape cancel, or {@see
 * \SugarCraft\Crush\Backend\EngineBackend::COMPLETE_TIMEOUT_SECONDS}) and the
 * deadline dies with it — nothing is left to enforce it, and the orphan's only
 * remaining bound is the tool's own behaviour: a 30s stream timeout for
 * `WebFetch`/`WebSearch`, an `sh -c grep` great-grandchild for `Grep`. A
 * custom ParallelSafe tool that loops forever would linger indefinitely. This
 * is the second, less obvious half of point 1: a tool joining a group is
 * promising not just that its effects are safe, but that it terminates on its
 * own.
 *
 * **An orphan also holds the parent's result socket open.** A forked tool
 * child inherits {@see
 * \SugarCraft\Crush\Backend\EngineBackend::completeAsync()}'s `$childSocket`
 * (PHP does not set CLOEXEC/close-on-fork on it), so the TUI parent does not
 * see EOF until the last orphan exits — measured at 3.21s for a group of
 * WebFetches. That delay is invisible on the happy path, where the result
 * frame arrives first; it matters for the fallback that detects a completion
 * child dying WITHOUT writing a result frame, which is keyed on that EOF.
 *
 * ---
 *
 * Deliberately per-INSTANCE rather than a name allowlist: whether a tool is
 * concurrency-safe can depend on how it was wired (which collaborators it was
 * given), and only the instance knows that. It is also why this does not reuse
 * {@see \SugarCraft\Crush\Permissions\PermissionGate}'s read-only list — that
 * one answers "does this need a permission prompt", a related but different
 * question with different consequences for being wrong.
 */
interface ParallelSafe
{
    public function isParallelSafe(): bool;
}
