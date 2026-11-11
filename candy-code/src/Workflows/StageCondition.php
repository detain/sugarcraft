<?php

declare(strict_types=1);

namespace SugarCraft\Code\Workflows;

/**
 * The C2 fixed-op stage condition: parse (at load time) and evaluate (at
 * dispatch time) of a stage's `when:` clause.
 *
 * The grammar is deliberately tiny and CLOSED — two combinators and three
 * comparison ops over the text of prior stages' outputs:
 *
 *   when: {stage: analyze, contains: 'GO'}
 *   when: {any: [{stage: a, equals: 'yes'}, {stage: b, matches: '/^PASS/'}]}
 *   when: {all: [{stage: a, contains: 'x'}, {any: [...leaves...]}]}
 *
 * Ops are fixed because the engine must never execute author-supplied code
 * to answer a routing question: no arithmetic, no user functions, no shell.
 * `matches` carries the same delimited PCRE the verdict scan carries, and
 * the same fail-closed law — a pattern that cannot compile refuses the
 * workflow at LOAD ({@see TaskBuilder::reject()} precedent), and anything
 * that still cannot evaluate at run time answers unsatisfied, never true.
 *
 * Evaluation reads ONLY the run's own recorded facts: the context strings
 * previous stages settled into (`<stage>.output`) and the per-result map
 * under {@see WorkflowEngine::RESULTS_CONTEXT_KEY}. A condition naming a
 * stage that produced nothing is unsatisfied — the honest reading of "the
 * output it would have judged does not exist", and the reason a misspelled
 * stage name skips its stage rather than silently running it.
 *
 * Refusals are raw English like every other workflow diagnostic ({@see
 * WorkflowLinter} header law): a workflow author reads them in the
 * transcript regardless of UI locale. The registry wraps them into
 * WorkflowLoadException with the file/stage context; the PHP builder lets
 * the InvalidArgumentException ride like TaskBuilder::reject() does.
 */
final class StageCondition
{
    /** The closed combinator roster — a node carries exactly one of these. */
    public const COMBINATORS = ['all', 'any'];

    /** The closed comparison roster — a leaf carries exactly one of these. */
    public const OPS = ['equals', 'contains', 'matches'];

    /**
     * Maximum nesting: a combinator may hold leaves and ONE more combinator
     * layer of leaves — never an unbounded tree to read off a YAML file.
     */
    public const MAX_DEPTH = 2;

    /**
     * Normalize and validate an author clause, refusing anything the
     * grammar does not carry. The returned shape is what the engine
     * evaluates; never re-feed an author clause into evaluate() directly.
     *
     * @param array<string, mixed> $clause the raw `when:` map
     * @return array<string, mixed> the normalized node (see evaluate())
     *
     * @throws \InvalidArgumentException on any shape the grammar refuses.
     */
    public static function parse(array $clause): array
    {
        return self::parseNode($clause, 0);
    }

    /**
     * Answer a parsed clause against a run context. Fail-closed by
     * construction: an unknown node, op or value type at run time reads
     * unsatisfied, never true, never thrown — the load-time parse is what
     * guarantees shapes, and a hand-built clause that bypassed it must not
     * turn an engine bug into a crash.
     *
     * @param array<string, mixed> $clause a parse() normalized node
     * @param array<string, mixed> $context the run's accumulated context
     */
    public static function evaluate(array $clause, array $context): bool
    {
        if (isset($clause['combinator'])) {
            $combinator = $clause['combinator'];
            $conditions = $clause['conditions'] ?? null;
            if (!is_string($combinator) || !in_array($combinator, self::COMBINATORS, true)
                || !is_array($conditions) || $conditions === []
            ) {
                return false;
            }

            foreach ($conditions as $child) {
                $satisfied = is_array($child) && self::evaluate($child, $context);
                if ($combinator === 'any' && $satisfied) {
                    return true;
                }
                if ($combinator === 'all' && !$satisfied) {
                    return false;
                }
            }

            return $combinator === 'all';
        }

        $stage = $clause['stage'] ?? null;
        $op = $clause['op'] ?? null;
        $value = $clause['value'] ?? null;
        if (!is_string($stage) || !is_string($op) || !is_string($value)
            || !in_array($op, self::OPS, true)
        ) {
            return false;
        }

        $text = self::stageText($stage, $context);
        if ($text === null) {
            return false;
        }

        // The OPS guard above admits exactly three spellings, so past the
        // equals and contains arms only 'matches' can remain: it answers
        // through the pattern probe, which fails closed exactly like the
        // verdict scan (a PCRE error at match time reads unsatisfied).
        return match ($op) {
            'equals' => $text === $value,
            'contains' => str_contains($text, $value),
            default => @preg_match($value, $text) === 1,
        };
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function parseNode(array $node, int $depth): array
    {
        if ($node === []) {
            throw new \InvalidArgumentException('when needs either a combinator (all/any) or a stage condition, got an empty map');
        }

        $combinators = array_values(array_intersect(array_keys($node), self::COMBINATORS));
        if ($combinators !== []) {
            return self::parseCombinator($node, $combinators, $depth);
        }

        return self::parseLeaf($node);
    }

    /**
     * @param array<string, mixed> $node
     * @param list<string>         $combinators
     * @return array{combinator: string, conditions: list<array<string, mixed>>}
     */
    private static function parseCombinator(array $node, array $combinators, int $depth): array
    {
        if (count($combinators) > 1) {
            throw new \InvalidArgumentException('when carries both "all" and "any"; one combinator per node is the grammar');
        }
        if ($depth >= self::MAX_DEPTH) {
            throw new \InvalidArgumentException(sprintf(
                'when nests combinators %d deep; the grammar stops at %d layers',
                $depth + 1,
                self::MAX_DEPTH,
            ));
        }

        $combinator = $combinators[0];
        $children = $node[$combinator];
        if (!is_array($children) || !array_is_list($children) || $children === []) {
            throw new \InvalidArgumentException(sprintf(
                'when "%s" must be a non-empty list of conditions, got %s',
                $combinator,
                get_debug_type($children),
            ));
        }

        $unknown = array_values(array_diff(array_keys($node), [$combinator]));
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                'when node carrying "%s" has unknown key "%s"; supported spellings: %s, all, any',
                $combinator,
                $unknown[0],
                implode(', ', self::OPS),
            ));
        }

        $conditions = [];
        foreach ($children as $index => $child) {
            if (!is_array($child)) {
                throw new \InvalidArgumentException(sprintf(
                    'when "%s" entry #%d must be a condition map, got %s',
                    $combinator,
                    $index,
                    get_debug_type($child),
                ));
            }
            $conditions[] = self::parseNode($child, $depth + 1);
        }

        return ['combinator' => $combinator, 'conditions' => $conditions];
    }

    /**
     * @param array<string, mixed> $node
     * @return array{stage: string, op: string, value: string}
     */
    private static function parseLeaf(array $node): array
    {
        $ops = array_values(array_intersect(array_keys($node), self::OPS));
        if ($ops === []) {
            $offender = array_key_first($node);
            throw new \InvalidArgumentException(sprintf(
                'when leaf needs one of %s; got unknown key "%s" where a condition belongs',
                implode(', ', self::OPS),
                is_string($offender) ? $offender : (string) $offender,
            ));
        }
        if (count($ops) > 1) {
            throw new \InvalidArgumentException(sprintf(
                'when leaf carries more than one op ("%s"); one of equals, contains, matches per condition is the grammar',
                implode('", "', $ops),
            ));
        }

        $stage = $node['stage'] ?? null;
        if (!is_string($stage) || $stage === '') {
            throw new \InvalidArgumentException('when leaf needs a non-empty "stage" naming the prior stage to judge');
        }

        $unknown = array_values(array_diff(array_keys($node), ['stage', $ops[0]]));
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                'when leaf for stage "%s" has unknown key "%s"; supported spellings: stage, %s',
                $stage,
                $unknown[0],
                $ops[0],
            ));
        }

        $value = $node[$ops[0]];
        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf(
                'when "%s" value for stage "%s" must be a string, got %s',
                $ops[0],
                $stage,
                get_debug_type($value),
            ));
        }

        if ($ops[0] === 'matches' && @preg_match($value, '') === false) {
            throw new \InvalidArgumentException(sprintf(
                'when "matches" pattern for stage "%s" is not a valid PCRE: %s',
                $stage,
                TaskBuilder::pcreErrorText(),
            ));
        }

        return ['stage' => $stage, 'op' => $ops[0], 'value' => $value];
    }

    /**
     * The text a leaf judges: the stage's recorded output string in the
     * run context, then its per-result entry, or null when the stage
     * produced nothing the grammar can see (never ran, skipped, or was
     * never named that) — null answers every leaf unsatisfied.
     *
     * @param array<string, mixed> $context
     */
    private static function stageText(string $stage, array $context): ?string
    {
        $direct = $context[$stage . '.output'] ?? null;
        if (is_string($direct)) {
            return $direct;
        }

        $results = $context[WorkflowEngine::RESULTS_CONTEXT_KEY] ?? null;
        if (is_array($results)) {
            $entry = $results[$stage] ?? null;
            if (is_string($entry)) {
                return $entry;
            }
        }

        return null;
    }
}
