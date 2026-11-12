<?php

declare(strict_types=1);

namespace SugarCraft\Code\Agents;

use SugarCraft\Code\Permissions\PermissionMode;

/**
 * The fields a workflow stage takes from an agent preset (workflow-update
 * P1-6, `preset: <name>`), resolved through the SAME roster a `Task`
 * delegation resolves names against — {@see AgentManager::get()} on a session,
 * or the list {@see \SugarCraft\Code\Cli\Bootstrap::agentRoster()} builds
 * (built-ins < foreign < native project < native user presets) — so a stage
 * named `preset: reviewer` gets exactly the reviewer `Task` would run. No
 * second loader: this only reads the {@see Agent} the roster already built.
 *
 * Stage keys override these fields; that merge is the workflow engine's.
 */
final readonly class StagePreset
{
    /**
     * @param string        $name            the roster name
     * @param string        $systemPrompt    the preset body ({@see Agent::$prompt}); '' when it declares none
     * @param list<string>  $tools           the `tools:` grant, as declared (`Bash(git *)` patterns kept); [] = no narrowing
     * @param list<string>  $disallowedTools the `disallowedTools:` denylist, as declared
     * @param ?string       $model           the model the preset NAMES; null when it inherits the session's
     * @param ?Effort       $effort          declared reasoning effort; null when none
     * @param ?Isolation    $isolation       declared isolation; null when none
     * @param list<string>  $skills          declared skill names
     * @param ?int          $maxTurns        declared step cap; null when none
     * @param PermissionMode $permissionMode the preset's mode (already provenance-gated by {@see Agent::fromPreset()})
     * @param Agent         $agent           the roster Agent itself, for a caller that builds a SubAgent from it
     */
    public function __construct(
        public string $name,
        public string $systemPrompt,
        public array $tools,
        public array $disallowedTools,
        public ?string $model,
        public ?Effort $effort,
        public ?Isolation $isolation,
        public array $skills,
        public ?int $maxTurns,
        public PermissionMode $permissionMode,
        public Agent $agent,
    ) {}

    public static function fromAgent(Agent $agent): self
    {
        return new self(
            name: $agent->name,
            systemPrompt: $agent->prompt,
            tools: array_values(array_map('strval', $agent->tools)),
            disallowedTools: array_values(array_map('strval', $agent->disallowedTools)),
            model: $agent->inheritsModel || $agent->model === '' ? null : $agent->model,
            effort: $agent->effort,
            isolation: $agent->isolation,
            skills: array_values(array_map('strval', $agent->skillNames)),
            maxTurns: $agent->maxTurns,
            permissionMode: $agent->permissionMode,
            agent: $agent,
        );
    }

    /**
     * Resolve $name against a session's roster or a roster list.
     *
     * @param AgentManager|iterable<Agent> $roster
     *
     * @throws \InvalidArgumentException naming the unknown preset and the
     *         names that do exist — an unknown preset is a load error, never a
     *         silent fall-back to a plain stage.
     */
    public static function resolve(string $name, AgentManager|iterable $roster): self
    {
        $name = trim($name);
        $agents = $roster instanceof AgentManager ? $roster->all() : $roster;

        $known = [];
        foreach ($agents as $agent) {
            if (!$agent instanceof Agent) {
                continue;
            }
            if ($agent->name === $name) {
                return self::fromAgent($agent);
            }
            $known[] = $agent->name;
        }

        sort($known);

        throw new \InvalidArgumentException(sprintf(
            'unknown agent preset "%s" (known: %s)',
            $name,
            $known === [] ? 'none' : implode(', ', $known),
        ));
    }
}
