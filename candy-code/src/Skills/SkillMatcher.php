<?php

declare(strict_types=1);

namespace SugarCraft\Code\Skills;

/**
 * Level-1 metadata listing for system-prompt injection.
 *
 * Folds every auto-invocable skill's name + description into a formatted string
 * (~100 tokens/skill) that gets injected into the system prompt at session start.
 * The LLM decides relevance — no PHP-side keyword matching at this stage.
 *
 * The listing is CURATED, not dumped: tier-grouped (built-in, project, user),
 * newest source file first within a tier (ISS-022 — ties and the pathless
 * manifest skills fall back to alphabetical by display name, then by full
 * key), and with byte-identical
 * (display name, description) lines collapsed to the shadow-winner — see
 * {@see self::orderedForListing()}. Lines show {@see Skill::displayName()},
 * the leaf of a nested key, which {@see \SugarCraft\Code\Tools\BuiltIn\SkillTool}
 * resolves back to the exact key (uniquely) or refuses ambiguously with the
 * colliding keys listed.
 *
 * THE LISTING CARRIES AN AGGREGATE BUDGET (ISS-022). Each line is clipped
 * per line by {@see SkillPromptLine}; this class is the one place that
 * counts the SUM: skills past `skills.listingMaxBytes` (default
 * {@see DEFAULT_LISTING_MAX_BYTES}) are dropped from the tail and replaced
 * by one "N more skills" line, and the drop is announced once per process
 * through {@see \SugarCraft\Code\Diagnostics\RuntimeNoticeSink} — the same
 * channel that carries every other "this session got smaller" warning.
 * Before this, a skill-heavy checkout paid ~40 KB (~10k tokens) of
 * `<available-skills>` on every request with nothing bounding it.
 *
 * This is the LEVEL-1 component only (metadata listing). The actual Skill tool
 * that loads skill body on-demand (Level-2) is a separate concern (W1.C1b).
 *
 * Live since W3.S8: {@see \SugarCraft\Code\Runtime::buildSystemPrompt()}
 * appends this listing for App::$availableSkills on every turn, and
 * Bootstrap populates that registry for the real CLI.
 */
final readonly class SkillMatcher
{
    /** The settings key naming the listing's aggregate byte budget. */
    public const SETTING_LISTING_MAX_BYTES = 'skills.listingMaxBytes';

    /**
     * Default aggregate budget for the whole listing, header excluded.
     *
     * The issue's measurement: 98 skills cost 40,178 B (~10k tokens) at the
     * repo root, riding every request. 8 KiB keeps ~80 typical lines and is
     * a deliberate return to "the listing is a roster, not an essay": a
     * skill the budget cannot name is one `Skill` call away, which is what
     * the collapse line says in as many bytes.
     */
    public const DEFAULT_LISTING_MAX_BYTES = 8192;

    /** The tail line naming what the budget dropped. */
    private const MORE_LINE_FORMAT = '- %d more skills; call Skill to list them';

    /**
     * The listing's aggregate byte budget: `skills.listingMaxBytes`, else
     * {@see DEFAULT_LISTING_MAX_BYTES}. Mirrors
     * {@see \SugarCraft\Code\Context\ProjectMemoryWriter::maxContentBytes()}'s
     * shape — a nonsense or absent value costs the default, never a fatal,
     * and no clamp widens a lowered budget.
     */
    public static function listingMaxBytes(?array $config = null): int
    {
        if ($config === null) {
            try {
                $config = \SugarCraft\Code\Cli\Bootstrap::readUserConfig();
            } catch (\Throwable) {
                return self::DEFAULT_LISTING_MAX_BYTES;
            }
        }

        $value = $config[self::SETTING_LISTING_MAX_BYTES] ?? null;
        if (\is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 1e15) {
            $value = (int) $value;
        }

        return \is_int($value) && $value >= 1 ? $value : self::DEFAULT_LISTING_MAX_BYTES;
    }

    /**
     * Build a formatted listing of all auto-invocable skills for system-prompt injection.
     *
     * Each skill renders as "- [{origin}] {name}: {description}" on its own
     * line, preceded by a header — collapsed to one line, fence-escaped and held to
     * {@see SkillPromptLine::LISTING_MAX_BYTES} by {@see SkillPromptLine::render()}. This gives the LLM visibility of the available skills
     * at session start — bounded in AGGREGATE by $maxTotalBytes (ISS-022),
     * with the overflow collapsed into one line naming the count rather than
     * silently dropped — and it then decides relevance via the Skill tool
     * rather than via any PHP-side heuristic.
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
     * @param ?int $maxTotalBytes aggregate budget over the joined lines; null
     *                            reads `skills.listingMaxBytes` once per call
     * @return string Formatted skill listing suitable for system-prompt injection
     */
    public function listForPrompt(SkillRegistry $registry, array $excludeNames = [], ?int $maxTotalBytes = null): string
    {
        $maxTotalBytes ??= self::listingMaxBytes();
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

        // Curated before rendered: tier-grouped, recency-then-alphabetical,
        // exact-duplicate lines collapsed (see orderedForListing()). A
        // non-empty list always keeps at least one line, so no empty
        // re-check is needed here.
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

        // Spend the aggregate budget (ISS-022) as the longest PREFIX that
        // still leaves room for the collapse line while a tail remains. The
        // first line is kept unconditionally: a budget so small it cannot
        // prove the tail fits still yields a roster of one, never a silent
        // zero — the per-line clip already bounds that line at
        // LISTING_MAX_BYTES, so breaking even that floor takes a budget
        // under ~1 KiB, which only an explicit setting asks for.
        $reserve = strlen(sprintf(self::MORE_LINE_FORMAT, 1_000_000_000));
        $kept = [];
        $spent = 0;
        $omitted = 0;
        $total = count($lines);
        foreach ($lines as $index => $line) {
            $cost = strlen($line) + ($kept === [] ? 0 : 1);
            $tail = $total - $index - 1;
            $fits = $kept === []
                || $spent + $cost + ($tail > 0 ? 1 + $reserve : 0) <= $maxTotalBytes;
            if (!$fits) {
                $omitted = $tail + 1;
                break;
            }
            $kept[] = $line;
            $spent += $cost;
        }

        if ($omitted > 0) {
            // The prompt says what was dropped; the operator is told once per
            // process on the notice channel — the same "this session got
            // smaller" route the fallback provider and the audit hook use —
            // because a listing the user did not know was cut is the issue's
            // failure mode, not just a shorter string. A function-local
            // static, not a property: this class is readonly.
            static $announced = false;
            if (!$announced) {
                $announced = true;
                \SugarCraft\Code\Diagnostics\RuntimeNoticeSink::warn(sprintf(
                    'The system-prompt skill listing hit its %d-byte budget (`%s`); %d skill(s) are not named in it — the Skill tool still lists and loads them, and the setting raises the cap.',
                    $maxTotalBytes,
                    self::SETTING_LISTING_MAX_BYTES,
                    $omitted,
                ));
            }

            $kept[] = sprintf(self::MORE_LINE_FORMAT, $omitted);
        }

        return "\n\nAvailable skills (invoke via Skill tool):\n" . implode("\n", $kept);
    }

    /**
     * The listing order and the dedupe that keeps it honest — a presentation
     * decision only; the registry's own keys, shadowing and lookup are untouched.
     *
     * ORDER: grouped by {@see SkillOrigin::precedence()} — built-in, project,
     * user — which is the discovery read order and puts the harness's own
     * shipped skills first, then NEWEST source file first inside a tier
     * (ISS-022: with an aggregate budget the ORDER decides who survives, and
     * the note most recently touched is the one most likely to matter —
     * memory's index made the same argument for modification time), then
     * alphabetical by display name, then by full key so equal everything
     * keeps a deterministic relative order (PHP 8 sorts are stable, but the
     * comparator must not depend on it). Skills with no readable file
     * (manifest registrations, vanished paths) carry the floor timestamp and
     * sort last inside their tier.
     *
     * DEDUPE: two skills whose (displayName, description) pair is byte-identical
     * render the SAME line, and the model cannot act twice on one fact — synced
     * import bundles reproduce this by design (the same skill imported under two
     * uuid keys). One line survives: the shadow winner per
     * {@see SkillOrigin::precedence()} semantics — the stronger tier's entry,
     * because that is the file a same-key collision would resolve to — and on a
     * within-tier tie, the first in registry order. The dropped entry stays fully
     * reachable: its exact key still resolves through {@see \SugarCraft\Code\Tools\BuiltIn\SkillTool}.
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

        /** @var array<string, array{rank: int, mtime: int, skill: Skill}> $keep pair key => surviving entry */
        $keep = [];
        foreach ($skills as $skill) {
            $key = $skill->displayName() . "\x00" . $skill->description;
            if (!isset($keep[$key])) {
                $keep[$key] = [
                    'rank' => $tier[$skill->origin->value],
                    'mtime' => self::recencyOf($skill),
                    'skill' => $skill,
                ];
                continue;
            }
            // Stronger tier wins the single line; equal tier keeps the first.
            if ($tier[$skill->origin->value] > $keep[$key]['rank']) {
                $keep[$key] = [
                    'rank' => $tier[$skill->origin->value],
                    'mtime' => self::recencyOf($skill),
                    'skill' => $skill,
                ];
            }
        }

        $entries = array_values($keep);
        usort($entries, static function (array $a, array $b): int {
            return $a['rank'] <=> $b['rank']
                ?: $b['mtime'] <=> $a['mtime']
                ?: [$a['skill']->displayName(), $a['skill']->name]
                    <=> [$b['skill']->displayName(), $b['skill']->name];
        });

        return array_map(static fn(array $e): Skill => $e['skill'], $entries);
    }

    /**
     * The listing's recency key: the mtime of the file the skill was read
     * from, or the timestamp floor for a skill with no path (a manifest
     * registration) or a vanished one. Read at ORDER time only; the number
     * itself never enters the prompt, so a filesystem timestamp cannot put
     * bytes into the cached prefix that vary between machines with equal
     * rosters — it only decides which of two lines the budget keeps.
     */
    private static function recencyOf(Skill $skill): int
    {
        if ($skill->sourcePath === '') {
            return \PHP_INT_MIN;
        }

        return @filemtime($skill->sourcePath) ?: \PHP_INT_MIN;
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
