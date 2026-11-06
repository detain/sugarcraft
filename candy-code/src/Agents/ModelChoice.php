<?php

declare(strict_types=1);

namespace SugarCraft\Code\Agents;

use SugarCraft\Code\Backend\EngineBackend;

/**
 * The model ladder: what a bounded run actually dials when someone asked for
 * a model by name. One copy on purpose (S2, wf-g2g3) — {@see TaskTool}
 * consults it for a delegation's `model` parameter and the agent's preset,
 * and {@see EngineExecutor} consults it for a workflow stage's `model:`, so
 * the same id answers the same way in both doors: same session equality,
 * same tier-alias walk, same "which provider serves what" verdicts.
 *
 * The two consumers phrase the OUTCOME differently, and always will:
 * a delegation that cannot honour the ask annotates the note and runs on the
 * session's model (a user watching the row decides whether that is fine);
 * a workflow stage REFUSES and is never started, because a plan author who
 * pinned a model asked for that model's answer, not for a cheaper stand-in
 * billed under its name. Only the phrasing differs — the feasibility
 * reasoning is this one method.
 */
final class ModelChoice
{
    /**
     * Claude Code's model TIER names (roadmap 4.1-1). A preset copied from
     * `.claude/agents` routinely says `model: sonnet`; no engine provider
     * serves an id by that name, so it resolves only where the session's own
     * model is of that tier, and runs on the session's model everywhere else.
     */
    public const ALIASES = ['sonnet', 'opus', 'haiku'];

    private function __construct()
    {
    }

    /**
     * Decide the run's model from the ask, the agent's preset, and the engine
     * it would dial on. Answers `[pin, null]` — nothing to do, or a model the
     * engine can serve — or `[null, parts]` when the ask cannot be honoured,
     * with the raw `'asked'` id, the `'origin'` that asked, and the `'why'`
     * the engine cannot serve it. WHAT HAPPENS TO A FALLBACK is the caller's
     * law (annotated continuation vs refusal), which is why the note sentence
     * is NOT composed here.
     *
     * @return array{0: ?string, 1: ?array{asked: string, origin: string, why: string}}
     */
    public static function decide(EngineBackend $engine, Agent $agent, string $requested): array
    {
        $model = trim($requested);
        $origin = 'this Task call';
        if ($model === '' || $model === 'inherit') {
            if ($agent->inheritsModel || trim($agent->model) === '' || $agent->model === 'inherit') {
                return [null, null];
            }
            $model = trim($agent->model);
            $origin = sprintf('agent "%s"', $agent->name);
        }

        $session = $engine->model();
        if ($model === $session) {
            return [null, null];
        }

        $provider = $engine->provider()->name();

        $refuse = static fn (string $why): array => [null, [
            'asked' => $model,
            'origin' => $origin,
            'why' => $why,
        ]];

        if (preg_match('/[\s[:cntrl:]]/', $model) === 1) {
            return $refuse('which is not a model id');
        }

        if (\in_array(strtolower($model), self::ALIASES, true)) {
            if (str_contains(strtolower($session), strtolower($model))) {
                return [null, null];
            }

            return $refuse(sprintf('a Claude Code tier name no %s model answers to', $provider));
        }

        $served = $engine->servedModel();
        if ($served !== null) {
            return $served === $model
                ? [$model, null]
                : $refuse(sprintf('which this %s server does not serve', $provider));
        }
        if ($engine->servesOneModel()) {
            return $refuse(sprintf('which this %s server does not serve: it runs one model', $provider));
        }

        return [$model, null];
    }
}
