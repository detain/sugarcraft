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
 * The listing is CURATED, not dumped: tier-grouped (built-in, project, user),
 * alphabetical by display name within a tier, and with byte-identical
 * (display name, description) lines collapsed to the shadow-winner — see
 * {@see self::orderedForListing()}. Lines show {@see Skill::displayName()},
 * the leaf of a nested key, which {@see \SugarCraft\Crush\Tools\BuiltIn\SkillTool}
 * resolves back to the exact key (uniquely) or refuses ambiguously with the
 * colliding keys listed.
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
     * Each skill renders as "- [{origin}] {name}: {description}" on its own
     * line, preceded by a header — collapsed to one line, fence-escaped and held to
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

        // Curated before rendered: tier-grouped, alphabetical, exact-duplicate
        // lines collapsed (see orderedForListing()). A non-empty list always
        // keeps at least one line, so no empty re-check is needed here.
        $curated = $this->orderedForListing($autoInvocable);

        // Through SkillPromptLine and never interpolated: name and description
        // are repository text from a cloned checkout's skills tree, read with no
        // trust gate (audit 15d-02). Each line also opens with its provenance
        // badge, so a repository's description cannot pass for one the operator
        // or the harness shipped; Runtime::systemPromptSections() wraps the whole
        // listing in the `available-skills` fence whose preamble explains it.
        $lines = array_map(
            fn(Skill $s) => SkillPromptLine::render($s, SkillPromptLine::LISTING_MAX_BYTES, withOrigin: true),
            $curated
        );

        return "\n\nAvailable skills (invoke via Skill tool):\n" . implode("\n", $lines);
    }

    /**
     * The listing order and the dedupe that keeps it honest — a presentation
     * decision only; the registry's own keys, shadowing and lookup are untouched.
     *
     * ORDER: grouped by {@see SkillOrigin::precedence()} — built-in, project,
     * user — which is the discovery read order and puts the harness's own
     * shipped skills first, then alphabetical by display name inside a tier,
     * then by full key so equal display names keep a deterministic relative
     * order (PHP 8 sorts are stable, but the comparator must not depend on it).
     *
     * DEDUPE: two skills whose (displayName, description) pair is byte-identical
     * render the SAME line, and the model cannot act twice on one fact — synced
     * import bundles reproduce this by design (the same skill imported under two
     * uuid keys). One line survives: the shadow winner per
     * {@see SkillOrigin::precedence()} semantics — the stronger tier's entry,
     * because that is the file a same-key collision would resolve to — and on a
     * within-tier tie, the first in registry order. The dropped entry stays fully
     * reachable: its exact key still resolves through {@see \SugarCraft\Crush\Tools\BuiltIn\SkillTool}.
     *
     * @param list<Skill> $skills
     * @return list<Skill>
     */
    private function orderedForListing(array $skills): array
    {
        $tier = [];
        foreach (SkillOrigin::precedence() as $rank => $origin) {
            $tier[$origin->value] = $rank;
        }

        /** @var array<string, array{rank: int, skill: Skill}> $keep pair key => surviving entry */
        $keep = [];
        foreach ($skills as $skill) {
            $key = $skill->displayName() . "\x00" . $skill->description;
            if (!isset($keep[$key])) {
                $keep[$key] = ['rank' => $tier[$skill->origin->value], 'skill' => $skill];
                continue;
            }
            // Stronger tier wins the single line; equal tier keeps the first.
            if ($tier[$skill->origin->value] > $keep[$key]['rank']) {
                $keep[$key] = ['rank' => $tier[$skill->origin->value], 'skill' => $skill];
            }
        }

        $entries = array_values($keep);
        usort($entries, static function (array $a, array $b): int {
            return [$a['rank'], $a['skill']->displayName(), $a['skill']->name]
                <=> [$b['rank'], $b['skill']->displayName(), $b['skill']->name];
        });

        return array_map(static fn(array $e): Skill => $e['skill'], $entries);
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
