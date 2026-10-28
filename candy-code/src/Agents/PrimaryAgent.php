<?php

declare(strict_types=1);

namespace SugarCraft\Code\Agents;

use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Tools\Tool;

/**
 * Applies a preset to the MAIN chat's backend — undoably (C-AGT-4.a).
 *
 * The delegating side has run presets on sub-agent backends since roadmap 4.1;
 * this is the same bundle of narrowings aimed at the top-level loop: the
 * preset's tool grants (through {@see AgentManager::toolsForPrimary()}, so
 * the C-AGT-3 MCP dispatcher scoping rides identically), its model unless it
 * says `inherit`, its effort, its permission mode — and its body, which
 * reaches the system prompt as the fenced `<agent-instructions>` section
 * through {@see EngineBackend::withAgentInstructions()}.
 *
 * EVERY APPLICATION IS NARROW-ONLY, and the revert is the other half of that
 * promise: {@see apply()} records the launch slice it is replacing and
 * {@see revert()} restores exactly that slice, so a switch into an agent and
 * back out costs the session nothing it did not already have. The mode in
 * particular NEVER widens: the stricter of the session's mode and the
 * preset's wins while the agent is active — even against a bypass session,
 * where "stricter" bites — because apply is the operator choosing a narrower
 * hat, and the recorded launch gate is restored on revert, not recomputed.
 *
 * WHY A TUPLE RETURN RATHER THAN A MUTATED BACKEND: EngineBackend is
 * immutable-by-convention (`with*()` copies), and the caller needs BOTH the
 * narrowed backend (to keep running with) and the record (to undo with).
 * `apply()` hands back `[PrimaryAgent, EngineBackend]` so neither half can be
 * forgotten, and the record holds no reference to any backend — reverting
 * takes the currently-live one as an argument, which is the shape that stays
 * correct when other `with*()`s (permissions, spend caps) have layered onto it
 * in between.
 *
 * THE COST SAID PLAINLY: switching the primary agent mid-session changes the
 * system prompt, so the provider's prompt cache misses once per switch. That
 * is inherent to carrying instructions at all and is accepted, not engineered
 * around (no LLM request timeouts or cache gymnastics ride on it — E646).
 *
 * The `mode:` key on the preset (which presets are offered where —
 * {@see AgentMode}) is a PRESENTING decision the picker makes before an Agent
 * is ever materialised, so it is not carried here and not read by apply().
 */
final class PrimaryAgent
{
    /** modeSource() stamp a gate narrowed by this path carries. */
    public const GATE_SOURCE = 'agent-preset';

    /**
     * @param list<Tool> $launchTools the session's launch roster, exactly as
     *        {@see EngineBackend::tools()} held it at apply() time
     * @param string $launchModel the session's launch model
     * @param string|float|null $launchEffort the launch request's effort —
     *        the same union
     *        {@see EngineBackend::reasoningEffort()} carries
     * @param ?PermissionGate $launchGate the launch gate object, restored by
     *        identity on revert; null when the launch backend had no gate at
     *        all (a headless deny-everything shape), in which case apply()
     *        never narrows and revert() never invents one
     */
    private function __construct(
        public readonly Agent $agent,
        private readonly array $launchTools,
        private readonly string $launchModel,
        private readonly string|float|null $launchEffort,
        private readonly ?PermissionGate $launchGate,
    ) {}

    /**
     * Apply $agent's preset bundle to $backend and return
     * `[record, narrowedBackend]`.
     *
     * The narrowings, each stated with the condition under which it does
     * NOTHING, which is exactly the condition under which revert() restores
     * an unchanged value:
     *
     *  - tools: `toolsForPrimary()` returns null when the agent declares no
     *    grants and no `mcpServers` — the session roster is then untouched,
     *    the same null-law the sub-agent path keeps; a non-null list REPLACES
     *    it (it is already an intersection, never a widening — the method
     *    cannot re-add tools the session's own `disabledTools` removed).
     *  - model: only when the preset did not say `inherit`
     *    ({@see Agent::$inheritsModel}); `withModel()` keeps the
     *    RebindsModel-provider behaviour it has for every other caller.
     *  - effort: only when the preset declared one — null never overwrites
     *    the launch value, because null is "ask nothing", not "medium".
     *  - permission mode: only when a gate exists; the stricter of the
     *    session's mode and the preset's wins (stricterOf), and an unchanged
     *    result means no new gate object is built.
     *  - instructions: the preset body (the Agent's `prompt`) is carried to
     *    the prompt as-is; a body that is only whitespace asks for no section.
     *
     * @return array{0: self, 1: EngineBackend}
     */
    public static function apply(EngineBackend $backend, AgentManager $manager, Agent $agent): array
    {
        $record = new self(
            agent: $agent,
            launchTools: $backend->tools(),
            launchModel: $backend->model(),
            launchEffort: $backend->reasoningEffort(),
            launchGate: $backend->permissionGate(),
        );

        $narrowed = $backend;

        $tools = $manager->toolsForPrimary($agent);
        if ($tools !== null) {
            $narrowed = $narrowed->withTools($tools);
        }

        if (!$agent->inheritsModel) {
            $narrowed = $narrowed->withModel($agent->model);
        }

        if ($agent->effort !== null) {
            $narrowed = $narrowed->withReasoningEffort($agent->effort->value);
        }

        $gate = $narrowed->permissionGate();
        if ($gate !== null) {
            $narrowedMode = $gate->mode()->stricterOf($agent->permissionMode);
            if ($narrowedMode !== $gate->mode()) {
                $narrowed = $narrowed->withPermissionGate($gate->withMode($narrowedMode, self::GATE_SOURCE));
            }
        }

        $narrowed = $narrowed->withAgentInstructions(trim($agent->prompt) === '' ? null : $agent->prompt);

        return [$record, $narrowed];
    }

    /**
     * Undo {@see apply()} over the backend it produced: restore the recorded
     * launch tools, model, effort and gate, and drop the instructions section.
     *
     * Restores by VALUE, not by diff — a revert of a never-narrowed field
     * re-sets the same value and is a no-op in every observable sense — so no
     * branch here has to remember which narrowings fired, and the "narrow-only,
     * fully undoable" promise cannot depend on that bookkeeping staying exact.
     * The one conditional is the gate: a launch backend with no gate at all
     * stays gate-less (inventing `PermissionGate::default()` on revert would
     * be a widening dressed as an undo).
     */
    public function revert(EngineBackend $applied): EngineBackend
    {
        $restored = $applied
            ->withTools($this->launchTools)
            ->withModel($this->launchModel)
            ->withReasoningEffort($this->launchEffort)
            ->withAgentInstructions(null);

        if ($this->launchGate !== null) {
            $restored = $restored->withPermissionGate($this->launchGate);
        }

        return $restored;
    }
}
