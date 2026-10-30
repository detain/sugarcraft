<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\BuiltIn;

use SugarCraft\Code\Skills\Skill;
use SugarCraft\Code\Skills\SkillOrigin;
use SugarCraft\Code\Skills\SkillPromptLine;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Code\Tools\Catalog\BuiltInTool;
use SugarCraft\Code\Tools\Catalog\ToolBuildContext;
use SugarCraft\Code\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Code\Tools\Concerns\TruncatesOutput;
use SugarCraft\Code\Tools\ParallelSafe;
use SugarCraft\Code\Tools\TakesToolCallId;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolResult;

/**
 * Keyword search over the skill roster — the other half of the listing budget.
 *
 * `SkillMatcher::listForPrompt()` names the model-invocable skills in the
 * system prompt up to an aggregate byte budget, and collapses everything past
 * it into one counted line (ISS-022). Until this tool existed, that collapse
 * line pointed at `Skill`, which by its own description "does not itself
 * list, search, or run skills" — the dropped skills had no reachable door
 * from the message that announced them. This is the door: a lexical rank
 * over the same roster the listing draws from, so a skill the budget could
 * not name is one keyword query away, and a model unsure which of ~100
 * skills fits the task can ask instead of guessing.
 *
 * SEARCHED FIELDS, in descending weight: the display name (whole-token then
 * substring), the registry key, the description, the path globs' basenames,
 * and a bounded head of the body — the same bytes the listing and the
 * progressive disclosure trade on, never the whole file (bodies reach the
 * prompt only through `Skill`, and search must not quietly load them all).
 * Only skills {@see SkillRegistry::isAutoInvocable()} offers to the model are
 * candidates: a user-only, disabled or model-declining skill stays as
 * unfindable here as it is unlistable in the prompt, and the coverage line
 * ("searched N of M skills") reports exactly that split so a narrow N is a
 * visible fact, not a silent filter.
 *
 * Every repository-supplied value crosses {@see SkillPromptLine::field()}
 * before it reaches the result: names and descriptions arrive from a cloned
 * checkout with no trust gate (audit 15d-02), and a tool result feeds the
 * next request. The tier badge is enum text and rides outside the field
 * calls, so a project skill cannot badge itself as built-in through its own
 * frontmatter.
 *
 * PERMISSION CLASS: Read. It reports configuration the user already opted
 * into and changes nothing; it answers from the already-loaded registry and
 * touches no disk, which is also what makes it {@see ParallelSafe}.
 */
#[BuiltInTool(
    name: self::NAME,
    permission: ToolPermissionClass::Read,
    position: 42,
    gloss: 'find skills by keyword when the system-prompt listing collapsed or the right one is uncertain',
)]
final readonly class SkillsSearch implements Tool, BuildsFromCatalog, ParallelSafe, TakesToolCallId
{
    use TruncatesOutput;

    public const NAME = 'skills_search';

    /** Result rows returned when the caller names no `limit`. */
    public const DEFAULT_LIMIT = 5;

    /** The widest page a caller may ask for; a larger `limit` is clamped here, never honored. */
    public const MAX_LIMIT = 20;

    /**
     * How many body bytes per skill the search may read into its haystack.
     * Bodies are already in memory for enabled skills; the bound keeps a
     * multi-kilobyte SKILL.md from turning one query into a full-corpus scan.
     */
    public const BODY_HEAD_BYTES = 4096;

    /** Opens the result: the query, field-escaped, quoted. */
    public const HEADER_FORMAT = 'Skills matching "%s":';

    /** One ranked hit: rank, provenance badge, display name (+ exact key when they differ), description. */
    public const ROW_FORMAT = '- %d. [%s] %s: %s';

    /** Suffix naming the registry key after a display name that is not the whole key. */
    public const KEY_SUFFIX_FORMAT = ' (%s)';

    /** Closes the result: what fraction of the roster the rank actually walked. */
    public const COVERAGE_FORMAT = 'searched %d of %d skills; %d matched.';

    /** Standing pointer line — search finds, it does not load. */
    public const POINTER_LINE = 'Load a skill with the Skill tool by its name or exact key.';

    public function __construct(private SkillRegistry $registry) {}

    public static function fromCatalog(ToolBuildContext $context): Tool
    {
        return new self($context->skills);
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Search the skill roster by keyword and return the best matches ranked, for when the '
            . 'system-prompt listing collapsed past its byte budget or you are not sure which skill exists. '
            . 'Pass "query" with the keywords; the name, description, path globs and the start of each body '
            . 'are searched, and a coverage line reports how many of the registered skills were searched. '
            . 'Pass "limit" to widen or narrow the page (default 5, at most 20). '
            . 'Each result carries its provenance tier badge and, when the name is nested, its exact key. '
            . 'This finds skills, it never loads or runs one: pass a result to the Skill tool to use it. '
            . 'Skills the model may not invoke are not searched and never appear here.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Keywords to rank skills by — one or more words; the whole roster is searched per query.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Optional: at most this many results (1-' . self::MAX_LIMIT . ', default ' . self::DEFAULT_LIMIT . ').',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function isParallelSafe(): bool
    {
        return true;
    }

    public function execute(array $args): ToolResult
    {
        $id = is_string($args['id'] ?? null) ? $args['id'] : '';

        $query = $args['query'] ?? null;
        if (!is_string($query)) {
            return new ToolResult(
                toolCallId: $id,
                content: 'Error: skills_search "query" must be a string of keywords.',
                isError: true,
            );
        }
        $tokens = self::tokens($query);
        if ($tokens === []) {
            return new ToolResult(
                toolCallId: $id,
                content: 'Error: skills_search "query" holds no word characters to search for.',
                isError: true,
            );
        }

        $limit = $args['limit'] ?? null;
        if ($limit !== null && !is_int($limit)) {
            return new ToolResult(
                toolCallId: $id,
                content: 'Error: skills_search "limit" must be an integer.',
                isError: true,
            );
        }
        $limit = $limit === null ? self::DEFAULT_LIMIT : max(1, min(self::MAX_LIMIT, $limit));

        // The roster the listing draws from, walked the same way: `all()`
        // holds every ENABLED skill (a `disabledSkills` entry is filtered
        // inside the registry itself), and isAutoInvocable() re-checks
        // `disable-model-invocation: true` and the no-ask door (the identical
        // double-check SkillTool performs). M counts enabled skills, N only
        // what the model may reach — a registry of 100 with 3 model-invocable
        // must say "searched 3 of 100". A disabled skill is in no count: it
        // is absent from the universe this search walks, exactly as it is
        // absent from the prompt listing.
        $total = count($this->registry->all());
        $hits = [];
        $searched = 0;
        foreach ($this->registry->all() as $key => $skill) {
            $key = (string) $key;
            if (!$this->registry->isAutoInvocable($key)) {
                continue;
            }
            $searched++;
            $score = self::score($skill, $tokens);
            if ($score > 0) {
                $hits[] = [$score, $key, $skill];
            }
        }

        // Rank: score first, then the tier reading order the listing keeps
        // (built-in, project, user), then display name, then key — a total
        // order, so equal scores never inherit registration order.
        usort($hits, static function (array $a, array $b): int {
            $byScore = $b[0] <=> $a[0];
            if ($byScore !== 0) {
                return $byScore;
            }

            return [self::tierRank($a[2]->origin), $a[2]->displayName(), $a[1]]
                <=> [self::tierRank($b[2]->origin), $b[2]->displayName(), $b[1]];
        });

        $lines = [sprintf(self::HEADER_FORMAT, SkillPromptLine::field($query))];
        $shown = 0;
        foreach (\array_slice($hits, 0, $limit) as [$score, $key, $skill]) {
            $shown++;
            $lines[] = self::row($shown, $key, $skill);
        }
        $lines[] = sprintf(self::COVERAGE_FORMAT, $searched, $total, count($hits));
        $lines[] = self::POINTER_LINE;

        return new ToolResult(
            toolCallId: $id,
            content: $this->truncateOutput(implode("\n", $lines), self::DEFAULT_MAX_OUTPUT_BYTES),
        );
    }

    /**
     * The query as lowercase word tokens: every non-letter/digit run splits.
     *
     * @return list<string>
     */
    private static function tokens(string $query): array
    {
        $parts = preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower(trim($query), 'UTF-8')) ?: [];

        return array_values(array_filter($parts, static fn(string $t): bool => $t !== ''));
    }

    /**
     * Position inside {@see SkillOrigin::precedence()} — the listing's tier
     * reading order, so a tie above ranks built-in before project before user.
     * Every case is in the list, so the lookup always returns an int.
     */
    private static function tierRank(SkillOrigin $origin): int
    {
        $rank = array_search($origin, SkillOrigin::precedence(), true);

        return false === $rank ? count(SkillOrigin::precedence()) : $rank;
    }

    /**
     * One candidate's relevance to ALL the tokens summed.
     *
     * Weights: a display-name token match beats a display-name substring,
     * which beats a registry-key substring, which beats description hits,
     * path-basename hits and body-head hits in that order. Substring leg on
     * purpose — "pdf" must find `pdf-export` without either word existing.
     */
    private static function score(Skill $skill, array $tokens): int
    {
        $display = mb_strtolower($skill->displayName(), 'UTF-8');
        $key = mb_strtolower($skill->name, 'UTF-8');
        $description = mb_strtolower($skill->description, 'UTF-8');
        $paths = implode(' ', array_map(
            static fn(string $p): string => mb_strtolower(basename($p), 'UTF-8'),
            $skill->paths,
        ));
        $body = mb_strtolower(
            mb_strcut($skill->content, 0, self::BODY_HEAD_BYTES, 'UTF-8'),
            'UTF-8',
        );

        $score = 0;
        foreach ($tokens as $token) {
            if ($display === $token) {
                $score += 16;
            } elseif (str_contains($display, $token)) {
                $score += 8;
            }
            if (str_contains($key, $token)) {
                $score += 6;
            }
            if (str_contains($description, $token)) {
                $score += 4;
            }
            if ($paths !== '' && str_contains($paths, $token)) {
                $score += 3;
            }
            if (str_contains($body, $token)) {
                $score += 1;
            }
        }

        return $score;
    }

    /**
     * One result line: badge outside the field calls (enum text), every
     * repository value through {@see SkillPromptLine::field()}.
     */
    private static function row(int $rank, string $key, Skill $skill): string
    {
        $name = SkillPromptLine::field($skill->displayName());
        if ($skill->displayName() !== $skill->name) {
            $name .= sprintf(self::KEY_SUFFIX_FORMAT, SkillPromptLine::field($skill->name));
        }

        return sprintf(
            self::ROW_FORMAT,
            $rank,
            $skill->origin->badge($skill->source),
            $name,
            SkillPromptLine::field($skill->description),
        );
    }
}
