<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Skills;

/**
 * Level-1 metadata listing for system-prompt injection.
 *
 * Folds every auto-invocable skill's name + description into a formatted string
 * (~100 tokens/skill) that gets injected into the system prompt at session start.
 * The LLM decides relevance — no PHP-side keyword matching at this stage.
 *
 * This is the LEVEL-1 component only (metadata listing). The actual Skill tool
 * that loads skill body on-demand (Level-2) is a separate concern (W1.C1b).
 *
 * NOTE: This service is wired into AppBuilder in Wave 3 (W3.S8). It is NOT yet
 * reachable from bin/sugarcrush until that step lands.
 */
final readonly class SkillMatcher
{
    /**
     * Build a formatted listing of all auto-invocable skills for system-prompt injection.
     *
     * Each skill renders as "- {name}: {description}" on its own line, preceded
     * by a header. This gives the LLM full visibility of every available skill
     * at session start — it then decides relevance via the Skill tool rather
     * than via any PHP-side heuristic.
     *
     * @param SkillRegistry $registry The registry to query for auto-invocable skills
     * @return string Formatted skill listing suitable for system-prompt injection
     */
    public function listForPrompt(SkillRegistry $registry): string
    {
        $autoInvocable = $this->getAutoInvocable($registry);

        if ($autoInvocable === []) {
            return '';
        }

        $lines = array_map(
            fn(Skill $s) => "- {$s->name}: {$s->description}",
            $autoInvocable
        );

        return "\n\nAvailable skills (invoke via Skill tool):\n" . implode("\n", $lines);
    }

    /**
     * Get all auto-invocable skills from the registry.
     *
     * @param SkillRegistry $registry
     * @return array<Skill>
     */
    private function getAutoInvocable(SkillRegistry $registry): array
    {
        return array_values(array_filter(
            $registry->all(),
            fn(Skill $s) => $registry->isAutoInvocable($s->name)
        ));
    }
}
