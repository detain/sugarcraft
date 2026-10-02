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
 *     race each other, and (because a forked tool child CAN outlive a
 *     cancelled turn — the teardown's tree kill is Linux-/proc-only, and even
 *     there a member's in-flight side effect may already have landed) an
 *     orphaned call must be unable to leave the workspace in a state the user
 *     did not ask for. This is why `Bash`/`Edit` are absent and why any `mcp__*` tool,
 *     whose capability is server-defined and unknowable here, is absent too.
 *
 *  2. Any session-scoped state it DOES mutate survives the fork, either
 *     because there is none or because the tool also implements
 *     {@see CarriesSessionState} and hands that state back to the parent.
 *     Without this, "announce this file's CLAUDE.md once per session" silently
 *     becomes "once per tool call" as soon as the call is forked.
 *
 * ONE DELIBERATE EXCEPTION to rule 1: {@see BuiltIn\TaskTool}, whose body is
 * a delegated agentic run that may edit files. Concurrent delegation is the
 * whole reason to delegate, so the caller that asks for parallel sub-agents
 * owns keeping them off each other's files; the orphan hazard below is closed
 * inside the tool instead (it abandons its run once its parent is gone), and
 * {@see ExemptFromParallelDeadline} keeps the group deadline off it.
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
 * **A ParallelSafe tool must still terminate on its own.** {@see
 * \SugarCraft\Crush\Runtime}'s 90s group deadline is enforced by the
 * completion child that forked the group. When that child is torn down (an
 * Escape-Escape cancel, or {@see
 * \SugarCraft\Crush\Backend\EngineBackend::COMPLETE_TIMEOUT_SECONDS}) the
 * teardown does not leave the group orphaned: it is
 * {@see \SugarCraft\Crush\Support\ProcessContainment::killTree()}, which
 * freezes the completion child, walks /proc for every descendant — each
 * forked group member and every `setsid` command one of them started — and
 * kills them all (audit 15a B2 / F-E2). The same walk is the parallel
 * deadline's own kill. Two cases still leave a member to its own devices, so
 * joining a group remains a promise that the tool's body ends by itself:
 * where /proc or ext-posix's getpgrp is missing, killTree() degrades to a
 * SIGKILL of the root alone and the members live on with no deadline — their
 * only bound is the tool's own behaviour (a 30s stream timeout for
 * `WebFetch`/`WebSearch`); and an {@see ExemptFromParallelDeadline} member
 * (`Task`) is skipped by the deadline sweep by design, so only a teardown
 * stops it.
 *
 * **The result socket no longer waits on a member.** A forked group member
 * still inherits the completion child's end of {@see
 * \SugarCraft\Crush\Backend\EngineBackend::completeAsync()}'s frame socket
 * — close-on-exec is an exec rule and a fork keeps every descriptor — but no
 * command anything in the turn EXECs does: both ends are marked `FD_CLOEXEC`
 * ({@see \SugarCraft\Crush\Support\ProcessContainment::closeOnExec()}), the
 * child closes the parent's end first, and the TUI parent notices a turn
 * child that died without a result frame by polling its pid, not by waiting
 * for EOF (audit 15a B3). A lingering member therefore no longer delays that
 * fallback; it is the teardown's tree kill above that ends it.
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
