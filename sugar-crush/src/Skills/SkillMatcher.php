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
 * Live since W3.S8: {@see \SugarCraft\Crush\Runtime::buildSystemPrompt()}
 * appends this listing for App::$availableSkills on every turn, and
 * Bootstrap populates that registry for the real CLI.
 */
final readonly class SkillMatcher
{
    /**
     * Build a formatted listing of all auto-invocable skills for system-prompt injection.
     *
     * Each skill renders as "- {name}: {description}" on its own line, preceded
     * by a header — collapsed to one line, fence-escaped and held to
     * {@see SkillPromptLine::LISTING_MAX_BYTES} by {@see SkillPromptLine::render()}. This gives the LLM full visibility of every available skill
     * at session start — it then decides relevance via the Skill tool rather
     * than via any PHP-side heuristic.
     *
     * @param SkillRegistry $registry The registry to query for auto-invocable skills
     * @param list<string> $excludeNames Skills whose FULL BODY the prompt already
     *                                   carries (the enabled-skills splice in
     *                                   Runtime::buildSystemPrompt) — rendering them
     *                                   here too would present one fact twice under
     *                                   two different contracts: metadata inviting a
     *                                   Skill-tool call, and standing instructions
     *                                   that need no call. Their bodies say
     *                                   everything the line would, louder.
     * @return string Formatted skill listing suitable for system-prompt injection
     */
    public function listForPrompt(SkillRegistry $registry, array $excludeNames = []): string
    {
        $autoInvocable = $this->getAutoInvocable($registry);

        if ($autoInvocable === []) {
            return '';
        }

        // Guarded by non-empty exclusions so every pre-P7.S3 caller — and the
        // default-config launch, where no skill is enabled — runs the exact
        // filter path it ran before: the parameter exists for the splice that
        // passes it, not for a cost on the common road.
        if ($excludeNames !== []) {
            $autoInvocable = array_values(array_filter(
                $autoInvocable,
                fn(Skill $s) => !\in_array($s->name, $excludeNames, true)
            ));
        }

        if ($autoInvocable === []) {
            return '';
        }

        // Through SkillPromptLine and never interpolated: name and description
        // are repository text from a cloned checkout's skills tree, read with no
        // trust gate, and this listing sits OUTSIDE every fence (audit 15d-02).
        $lines = array_map(
            fn(Skill $s) => SkillPromptLine::render($s, SkillPromptLine::LISTING_MAX_BYTES),
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
