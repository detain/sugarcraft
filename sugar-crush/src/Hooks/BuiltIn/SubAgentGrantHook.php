<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks\BuiltIn;

use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\ToolCall;

/**
 * Enforces a delegated sub-agent's OWN tool declaration on every call it makes
 * through the engine path — the argument half of `tools: ['Bash(git *)']` and
 * every argument-scoped `disallowedTools` entry (step 4.2).
 *
 * WHY A HOOK. {@see \SugarCraft\Crush\Tools\BuiltIn\TaskTool} runs a sub-agent
 * through the calling engine's own tool loop, narrowed to the preset's grant by
 * tool NAME ({@see AgentManager::grantedToolsFor()}) — a tool schema cannot say
 * "git commands only", so `Bash(git *)` puts the whole `Bash` tool on the wire.
 * The per-call half lived in {@see AgentManager::evaluateToolCalls()}, reachable
 * only from `executeSubAgent()`, which nothing in `src/` calls. So the built-in
 * `reviewer`, declared `['Read', 'Grep', 'Bash(git *)']`, ran ANY Bash command
 * the session gate allowed, and `disallowedTools: ['Bash(git push*)']` bit
 * nothing. The PreToolUse chain is the one place the engine path already gates
 * every call — rewrites re-scanned, the root handed in — so the check rides it
 * rather than adding a second dispatch-time call site.
 *
 * THE MATCHER IS {@see AgentManager::grantRefusalFor()}, not a second one: the
 * fail-closed {@see \SugarCraft\Crush\Permissions\PermissionRule} dialect, where
 * a denial is a UNION over a shell chain's segments and a grant an
 * INTERSECTION, so `Bash(git *)` admits `git status` and refuses
 * `git log && rm x`. A malformed declaration is a DENY naming it, never an
 * allow.
 *
 * A REFUSAL IS A DENIED CALL, NOT A FAILED RUN. The model gets the reason as the
 * call's result and may carry on inside its grant, the same as any other hook
 * denial — the stop-the-run shape belongs to the dormant `executeSubAgent()`
 * path, which has no transcript to continue.
 *
 * NOT AN OPT-OUT. {@see \SugarCraft\Crush\Backend\EngineBackend::resolveHookManager()}
 * registers it on the sub-agent's per-turn chain even under `withoutHooks()`,
 * as it does the permission gate: a preset's declaration is the agent's own
 * statement about itself and a hooks switch is not a way to widen it. It is the
 * second gate, never a substitute for the first — the session's
 * {@see PermissionGateHook} still judges every call this one admits.
 */
final readonly class SubAgentGrantHook implements HookInterface
{
    /**
     * Stable, so re-registering on the next turn REPLACES the hook instead of
     * stacking two, and distinct from every user-hook name the built-ins use.
     */
    public const NAME = 'subagent-grant';

    public function __construct(
        private AgentManager $manager,
        private SubAgent $subAgent,
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
     * Every tool, MCP bridges included: the declaration decides what a name
     * means, so narrowing the matcher here would exempt whatever it missed.
     */
    public function matcher(): string
    {
        return '.*';
    }

    /** The run this hook polices — for a caller that reads the chain back. */
    public function subAgent(): SubAgent
    {
        return $this->subAgent;
    }

    public function execute(HookContext $context): HookResult
    {
        $call = new ToolCall($context->toolName, $context->toolArgs);
        $root = $context->projectRoot === '' ? null : $context->projectRoot;

        try {
            $why = $this->manager->grantRefusalFor($call, $this->subAgent, $root);
        } catch (\RuntimeException $malformed) {
            return HookResult::deny(sprintf(
                'Tool call "%s" refused: the declaration of agent "%s" cannot be applied (%s).',
                $context->toolName,
                $this->subAgent->agent->name,
                $malformed->getMessage(),
            ));
        }

        if ($why === null) {
            return HookResult::allow();
        }

        return HookResult::deny(sprintf('Tool call "%s" %s.', $context->toolName, $why));
    }
}
