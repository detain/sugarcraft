<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks\BuiltIn;

use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Permissions\PermissionDecision;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\ToolCall;

/**
 * Adapts a {@see PermissionGate} onto the `PreToolUse` hook chain, which is
 * how crush_code.md Phase 1 item 2 resolves the two-permission-systems split:
 * the 6-mode gate (with its rules and its Auto-mode circuit breaker) had
 * exactly one consumer — the sub-agent path — while the main loop got only
 * {@see \SugarCraft\Crush\Hooks\HookManager}'s built-ins.
 *
 * An adapter rather than a second gate call site inside
 * {@see \SugarCraft\Crush\Runtime}, because the hook chain is ALREADY the one
 * place both live tool pipelines gate on: {@see \SugarCraft\Crush\Runtime::gate()}
 * for the engine/provider path and {@see \SugarCraft\Crush\Chat::gateToolCall()}
 * for Chat's own registered tools. Riding in as a hook therefore reaches both
 * with no new dispatch machinery, and — crucially — inherits the ASK plumbing
 * those two already implement (blocking prompt on Chat's side, fail-closed
 * denial on Runtime's), which is what makes {@see PermissionDecision::Ask}
 * mean something instead of being a decision nobody can act on.
 *
 * ORDERING against the built-in hooks (`ProtectFilesHook`, `ConfirmRemoveHook`,
 * `AuditHook`, `BashEscapeDenyHook`): the built-ins are registered FIRST and
 * this gate LAST — see {@see \SugarCraft\Crush\Cli\Bootstrap::hooks()} and
 * {@see \SugarCraft\Crush\Backend\EngineBackend::resolveHookManager()}. Both
 * orders are fail-closed as to the VERDICT, because
 * {@see \SugarCraft\Crush\Hooks\HookRegistry::executeHooks()} lets a DENY win
 * outright and never lets an ASK grant anything; the order is chosen for the
 * QUALITY of the message the user and the model end up seeing. The built-ins
 * are narrow, specific hazards ("this hook denies Bash paths outside the
 * workspace root: /etc") and this gate is broad policy ("permission mode
 * 'plan' does not allow Edit"), so letting the specific hazard short-circuit
 * first reports the actual reason rather than the generic one. The gate is
 * deliberately NOT a replacement for them: a mode as permissive as
 * BypassPermissions still has `rm -rf /` and `.env` writes refused, because
 * those checks live in the layer above it.
 *
 * That "both orders" claim used to be made about the ARGUMENTS as well, and it
 * was false for them: registered last, this gate only ever saw a call as the
 * hooks ahead of it had left it, so a hook rewriting `Bash{command:"ls"}` into
 * `Bash{command:"rm -rf /"}` was evaluated here against `ls`. `executeHooks()`
 * now RE-SCANS the whole chain against a rewrite, which is what actually makes
 * the ordering argument-independent; see its docblock.
 */
final readonly class PermissionGateHook implements HookInterface
{
    /**
     * Stable across instances so re-registering a gate REPLACES the previous
     * one rather than stacking two gates with independent Auto-mode strike
     * counters — {@see \SugarCraft\Crush\Hooks\HookRegistry} keys its hooks by
     * name, and {@see \SugarCraft\Crush\Backend\EngineBackend} may register
     * over a manager {@see \SugarCraft\Crush\Cli\Bootstrap} already populated.
     * {@see \SugarCraft\Crush\Hooks\HookRegistry::isReserved()} keeps a
     * user-supplied hook from claiming or disabling the same name.
     *
     * BE CLEAR ABOUT WHAT "ONE COUNTER" CURRENTLY BUYS, because it is less
     * than it sounds: {@see \SugarCraft\Crush\Backend\EngineBackend::completeAsync()}
     * runs the whole completion — tools, hooks, this gate — inside a
     * `pcntl_fork()`ed CHILD, and the child's memory dies with it. So on the
     * live TUI path every Auto-mode strike increment is discarded at the end
     * of the turn that made it, and the 3-strike/20-total circuit breaker
     * effectively restarts each turn. Sharing ONE instance is still the right
     * construction — it is what keeps the two tool paths on one MODE and one
     * rule set, and what makes the counters mean something on the synchronous
     * `complete()` path {@see \SugarCraft\Crush\Cli\NonInteractive} and every
     * embedder use — but "one counter for the session" is not true yet.
     * Making it true needs the gate's state to cross the fork boundary, which
     * is its own queued step.
     */
    public const NAME = 'permission-gate';

    /**
     * `readonly` describes THIS object, not the gate inside it: the hook holds
     * no state of its own and its binding to one gate never changes, which is
     * the guarantee that makes re-registering it per turn safe. The gate it
     * points at is emphatically mutable — its Auto-mode strike counters are
     * the whole reason a launch shares one instance — so do not read the
     * modifier as a promise about permission state.
     */
    public function __construct(
        private PermissionGate $gate,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function event(): HookEvent
    {
        return HookEvent::PreToolUse;
    }

    /**
     * Every tool, including MCP tools: the gate's own classifiers decide what
     * a name means (@see PermissionGate::isWriteTool()), so narrowing here
     * would silently exempt whatever the pattern failed to anticipate.
     */
    public function matcher(): string
    {
        return '.*';
    }

    /**
     * The gate this hook adapts, so a caller that installed one can read its
     * configured mode back without keeping a second reference.
     */
    public function gate(): PermissionGate
    {
        return $this->gate;
    }

    public function execute(HookContext $context): HookResult
    {
        $call = new ToolCall($context->toolName, $context->toolArgs);
        $mode = $this->gate->mode()->value;

        return match ($this->gate->evaluate($call)) {
            PermissionDecision::Allow => HookResult::allow(),
            PermissionDecision::Deny => HookResult::deny(
                "Permission mode '{$mode}' does not allow {$context->toolName}.",
            ),
            // Not collapsed into a deny: ASK is the whole reason the gate was
            // worth wiring into this chain at all. Whoever owns a UI settles
            // it; a caller with no UI fails closed, which is the existing
            // contract of HookResult::ask().
            PermissionDecision::Ask => HookResult::ask(
                "Allow {$context->toolName} to run? (permission mode: {$mode})",
            ),
        };
    }
}
