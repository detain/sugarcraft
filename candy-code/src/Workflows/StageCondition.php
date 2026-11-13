<?php

declare(strict_types=1);

namespace SugarCraft\Code\Workflows;

/**
 * The C2 fixed-op stage condition: parse (at load time) and evaluate (at
 * dispatch time) of a stage's `when:` clause and a loop's `until:` clause.
 *
 * The grammar is deliberately tiny and CLOSED — two combinators, four
 * comparison ops over prior stages' outputs, and two loop-only leaves:
 *
 *   when: {stage: analyze, contains: 'GO'}
 *   when: {stage: review, pointer: /verdict, equals: NEEDS_FIX}
 *   when: {stage: find, pointer: /findings, empty: false}
 *   when: {any: [{stage: a, equals: 'yes'}, {stage: b, matches: '/^PASS/'}]}
 *   when: {all: [{stage: a, contains: 'x'}, {any: [...leaves...]}]}
 *   until: {dry: 2}                    # loop-only (parse(..., allowLoopLeaves: true))
 *   until: {budgetBelow: 50000}        # loop-only
 *
 * Leaf grammar — a leaf carries exactly ONE op:
 *
 * - `stage:` (non-empty string) names the prior stage/result to judge.
 * - `equals` / `contains` / `matches` take a STRING (never coerced: a YAML
 *   `equals: true` is refused — quote it). `matches` is a delimited PCRE
 *   checked at load.
 * - `empty: true|false` takes a BOOL. It answers exact emptiness with no
 *   trimming (the no-coercion law): the empty string, or an empty array /
 *   object behind a pointer. Whitespace is content — `matches: '/^\s*$/'`
 *   is the spelling for "blank". A pointer leaf that is neither a string
 *   nor an array (number, bool, null) is not judgeable by `empty` and
 *   reads unsatisfied for BOTH `true` and `false`.
 * - `pointer:` (optional, non-empty string, RFC 6901 via {@see JsonPointer},
 *   leading `/` optional, `~` must start `~0`/`~1`) switches the leaf from
 *   the stage's TEXT to its TYPED row — the {@see
 *   WorkflowEngine::TYPED_RESULTS_CONTEXT_KEY} entry keyed by the same
 *   result name `{{name.<pointer>}}` interpolation reads, so only a
 *   schema'd stage has one. The resolved leaf compares by its string form:
 *   strings verbatim, bools `true`/`false`, numbers by PHP's string cast
 *   (exactly what interpolation splices), JSON null as `null`, arrays and
 *   objects as compact JSON (unescaped slashes and unicode).
 *
 * Loop-only leaves — refused unless parse() is called with
 * `allowLoopLeaves: true` (the `loop.until` path), so `when:` keeps
 * refusing them; they take no `stage`/`pointer` and may sit inside all/any:
 *
 * - `{dry: N}` (whole N >= 1): satisfied when the engine-maintained count
 *   {@see LOOP_DRY_ROUNDS_CONTEXT_KEY} is >= N.
 * - `{budgetBelow: N}` (whole N >= 1): satisfied when the engine-maintained
 *   {@see BUDGET_REMAINING_CONTEXT_KEY} is < N.
 *
 * An absent context key reads unsatisfied, like every other miss.
 *
 * Ops are fixed because the engine must never execute author-supplied code
 * to answer a routing question: no arithmetic, no user functions, no shell.
 * `matches` carries the same delimited PCRE the verdict scan carries, and
 * the same fail-closed law — a pattern that cannot compile refuses the
 * workflow at LOAD ({@see TaskBuilder::reject()} precedent), and anything
 * that still cannot evaluate at run time answers unsatisfied, never true.
 *
 * Evaluation reads ONLY the run's own recorded facts: the context strings
 * previous stages settled into (`<stage>.output`), the per-result map
 * under {@see WorkflowEngine::RESULTS_CONTEXT_KEY}, the typed map for
 * pointer leaves, and the two loop keys above. A condition naming a stage
 * that produced nothing — or a pointer that resolves nowhere — is
 * unsatisfied: the honest reading of "the output it would have judged does
 * not exist", and the reason a misspelled stage name skips its stage rather
 * than silently running it.
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

    /** The closed comparison roster — a stage leaf carries exactly one of these. */
    public const OPS = ['equals', 'contains', 'matches', 'empty'];

    /**
     * The loop-only leaf roster — accepted only by parse(..., allowLoopLeaves:
     * true). A loop leaf is the whole leaf: no stage, no pointer.
     */
    public const LOOP_OPS = ['dry', 'budgetBelow'];

    /**
     * Context key the ENGINE maintains during a `loop:` with `accumulate:`:
     * the number of consecutive completed rounds that added no new item
     * (int). `{dry: N}` reads it; absent means unsatisfied.
     */
    public const LOOP_DRY_ROUNDS_CONTEXT_KEY = '@loopDryRounds';

    /**
     * Context key the ENGINE maintains during a `loop:` when a token budget
     * is configured: tokens still spendable in the run (int|float).
     * `{budgetBelow: N}` reads it; absent (no budget) means unsatisfied, so
     * the loop runs to `max`.
     */
    public const BUDGET_REMAINING_CONTEXT_KEY = '@budgetRemaining';

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
     * Normalized leaves:
     * - text leaf:    {stage, op, value}            (value string; bool for empty)
     * - pointer leaf: {stage, pointer, op, value}
     * - loop leaf:    {op: dry|budgetBelow, value: int}
     *
     * @param array<string, mixed> $clause          the raw `when:`/`until:` map
     * @param bool                 $allowLoopLeaves true only for a loop's
     *        `until:` — admits the {@see LOOP_OPS} leaves; `when:` keeps the
     *        default and refuses them
     * @return array<string, mixed> the normalized node (see evaluate())
     *
     * @throws \InvalidArgumentException on any shape the grammar refuses.
     */
    public static function parse(array $clause, bool $allowLoopLeaves = false): array
    {
        return self::parseNode($clause, 0, $allowLoopLeaves);
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

        $op = $clause['op'] ?? null;
        $value = $clause['value'] ?? null;
        if (is_string($op) && in_array($op, self::LOOP_OPS, true)) {
            return self::evaluateLoopLeaf($op, $value, $context);
        }

        $stage = $clause['stage'] ?? null;
        if (!is_string($stage) || !is_string($op) || !in_array($op, self::OPS, true)) {
            return false;
        }
        if ($op === 'empty' ? !is_bool($value) : !is_string($value)) {
            return false;
        }

        if (array_key_exists('pointer', $clause)) {
            $pointer = $clause['pointer'];
            if (!is_string($pointer) || $pointer === '') {
                return false;
            }
            $leaf = self::typedLeaf($stage, $pointer, $context);
            if ($leaf['found'] === false) {
                return false;
            }
            if ($op === 'empty') {
                return match (true) {
                    is_string($leaf['value']) => ($leaf['value'] === '') === $value,
                    is_array($leaf['value']) => ($leaf['value'] === []) === $value,
                    // Numbers, bools and null are neither empty nor
                    // non-empty under the no-coercion law.
                    default => false,
                };
            }
            $text = self::leafText($leaf['value']);
            if ($text === null) {
                return false;
            }
        } else {
            $text = self::stageText($stage, $context);
            if ($text === null) {
                return false;
            }
            if ($op === 'empty') {
                return ($text === '') === $value;
            }
        }

        // The guards above leave a string $value and one of the three
        // string ops; past equals and contains only 'matches' can remain:
        // it answers through the pattern probe, which fails closed exactly
        // like the verdict scan (a PCRE error at match time reads
        // unsatisfied).
        return match ($op) {
            'equals' => $text === $value,
            'contains' => str_contains($text, $value),
            default => @preg_match($value, $text) === 1,
        };
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function parseNode(array $node, int $depth, bool $allowLoopLeaves): array
    {
        if ($node === []) {
            throw new \InvalidArgumentException('when needs either a combinator (all/any) or a stage condition, got an empty map');
        }

        $combinators = array_values(array_intersect(array_keys($node), self::COMBINATORS));
        if ($combinators !== []) {
            return self::parseCombinator($node, $combinators, $depth, $allowLoopLeaves);
        }

        return self::parseLeaf($node, $allowLoopLeaves);
    }

    /**
     * @param array<string, mixed> $node
     * @param list<string>         $combinators
     * @return array{combinator: string, conditions: list<array<string, mixed>>}
     */
    private static function parseCombinator(array $node, array $combinators, int $depth, bool $allowLoopLeaves): array
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
                self::plainKind($children),
            ));
        }

        $unknown = array_values(array_diff(array_keys($node), [$combinator]));
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                'when node carrying "%s" also holds "%s", which is not a condition key (expected one of: %s, all, any)',
                $combinator,
                $unknown[0],
                implode(', ', self::leafSpellings($allowLoopLeaves)),
            ));
        }

        $conditions = [];
        foreach ($children as $index => $child) {
            if (!is_array($child)) {
                throw new \InvalidArgumentException(sprintf(
                    'when "%s" entry #%d must be a condition map, got %s',
                    $combinator,
                    $index,
                    self::plainKind($child),
                ));
            }
            $conditions[] = self::parseNode($child, $depth + 1, $allowLoopLeaves);
        }

        return ['combinator' => $combinator, 'conditions' => $conditions];
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private static function parseLeaf(array $node, bool $allowLoopLeaves): array
    {
        $keys = array_keys($node);
        $loopOps = array_values(array_intersect($keys, self::LOOP_OPS));
        $ops = array_values(array_intersect($keys, self::OPS));

        if ($loopOps !== [] && !$allowLoopLeaves) {
            throw new \InvalidArgumentException(sprintf(
                'when leaf "%s" is loop-only; it is accepted in a loop\'s until, never in when',
                $loopOps[0],
            ));
        }

        $allOps = [...$ops, ...$loopOps];
        if ($allOps === []) {
            $offender = array_key_first($node);
            throw new \InvalidArgumentException(sprintf(
                'when leaf needs one of %s; "%s" is not a condition key',
                implode(', ', self::leafSpellings($allowLoopLeaves)),
                is_string($offender) ? $offender : (string) $offender,
            ));
        }
        if (count($allOps) > 1) {
            throw new \InvalidArgumentException(sprintf(
                'when leaf carries more than one op ("%s"); one of %s per condition is the grammar',
                implode('", "', $allOps),
                implode(', ', self::leafSpellings($allowLoopLeaves)),
            ));
        }

        if ($loopOps !== []) {
            return self::parseLoopLeaf($node, $loopOps[0]);
        }

        $op = $ops[0];
        $stage = $node['stage'] ?? null;
        if (!is_string($stage) || $stage === '') {
            throw new \InvalidArgumentException('when leaf needs a non-empty "stage" naming the prior stage to judge');
        }

        $unknown = array_values(array_diff($keys, ['stage', 'pointer', $op]));
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                'when leaf for stage "%s" also holds "%s", which is not a condition key (expected one of: stage, pointer, %s)',
                $stage,
                $unknown[0],
                $op,
            ));
        }

        $pointer = null;
        if (array_key_exists('pointer', $node)) {
            $pointer = $node['pointer'];
            if (!is_string($pointer) || $pointer === '') {
                throw new \InvalidArgumentException(sprintf(
                    'when "pointer" for stage "%s" must be a non-empty string (an RFC 6901 pointer such as /verdict), got %s',
                    $stage,
                    is_string($pointer) ? 'an empty string' : self::plainKind($pointer),
                ));
            }
            if (!JsonPointer::isWellFormed($pointer)) {
                throw new \InvalidArgumentException(sprintf(
                    'when "pointer" for stage "%s" is not a valid RFC 6901 pointer: "%s" ("~" must be written ~0, "/" inside a key ~1)',
                    $stage,
                    $pointer,
                ));
            }
        }

        $value = $node[$op];
        if ($op === 'empty') {
            if (!is_bool($value)) {
                throw new \InvalidArgumentException(sprintf(
                    'when "empty" value for stage "%s" must be true or false, got %s',
                    $stage,
                    self::plainKind($value),
                ));
            }
        } elseif (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf(
                'when "%s" value for stage "%s" must be a string, got %s',
                $op,
                $stage,
                self::plainKind($value),
            ));
        }

        if ($op === 'matches' && @preg_match($value, '') === false) {
            throw new \InvalidArgumentException(sprintf(
                'when "matches" pattern for stage "%s" is not a valid PCRE: %s',
                $stage,
                TaskBuilder::pcreErrorText(),
            ));
        }

        return $pointer === null
            ? ['stage' => $stage, 'op' => $op, 'value' => $value]
            : ['stage' => $stage, 'pointer' => $pointer, 'op' => $op, 'value' => $value];
    }

    /**
     * @param array<string, mixed> $node
     * @return array{op: string, value: int}
     */
    private static function parseLoopLeaf(array $node, string $op): array
    {
        $unknown = array_values(array_diff(array_keys($node), [$op]));
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                'loop leaf "%s" also holds "%s", which is not allowed; it is a whole condition on its own, e.g. {%s: 2}',
                $op,
                $unknown[0],
                $op,
            ));
        }

        $value = $node[$op];
        if (!is_int($value) || $value < 1) {
            throw new \InvalidArgumentException(sprintf(
                'loop leaf "%s" must be a whole number >= 1, got %s',
                $op,
                is_int($value) ? (string) $value : self::plainKind($value),
            ));
        }

        return ['op' => $op, 'value' => $value];
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function evaluateLoopLeaf(string $op, mixed $threshold, array $context): bool
    {
        if (!is_int($threshold) || $threshold < 1) {
            return false;
        }

        if ($op === 'dry') {
            $rounds = $context[self::LOOP_DRY_ROUNDS_CONTEXT_KEY] ?? null;

            return is_int($rounds) && $rounds >= $threshold;
        }

        $remaining = $context[self::BUDGET_REMAINING_CONTEXT_KEY] ?? null;

        return (is_int($remaining) || is_float($remaining)) && $remaining < $threshold;
    }

    /**
     * The leaf spellings an author may write at this call site — the loop
     * leaves are only advertised where they are accepted.
     *
     * @return list<string>
     */
    private static function leafSpellings(bool $allowLoopLeaves): array
    {
        return $allowLoopLeaves ? [...self::OPS, ...self::LOOP_OPS] : self::OPS;
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

    /**
     * The leaf a pointer leaf judges: resolved inside the stage's typed row
     * (keyed by result name, the map `{{name.<pointer>}}` reads). A stage
     * with no typed row — unschema'd, skipped, never run, misspelled —
     * reaches nothing, exactly like a pointer that misses.
     *
     * @param array<string, mixed> $context
     * @return array{found: bool, value: mixed}
     */
    private static function typedLeaf(string $stage, string $pointer, array $context): array
    {
        $typed = $context[WorkflowEngine::TYPED_RESULTS_CONTEXT_KEY] ?? null;
        $row = is_array($typed) ? ($typed[$stage] ?? null) : null;
        if (!is_string($row)) {
            return ['found' => false, 'value' => null];
        }

        return JsonPointer::resolveJson($row, $pointer);
    }

    /**
     * A resolved JSON leaf as the string the string ops compare: numbers by
     * the same cast interpolation splices (so an author copies what the
     * prompt showed), structures as compact JSON. Null only for a value no
     * JSON document can hold (an encode failure), which reads unsatisfied.
     */
    private static function leafText(mixed $leaf): ?string
    {
        return match (true) {
            is_string($leaf) => $leaf,
            is_bool($leaf) => $leaf ? 'true' : 'false',
            is_int($leaf), is_float($leaf) => (string) $leaf,
            default => ($encoded = json_encode($leaf, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false ? null : $encoded,
        };
    }

    /**
     * Plain-language phrase for a value's kind, so an authoring error reads
     * "got text" rather than leaking PHP's type names ("string", "array").
     */
    public static function plainKind(mixed $value): string
    {
        return match (true) {
            $value === null => 'nothing',
            is_bool($value) => 'true or false',
            is_int($value) => 'a whole number',
            is_float($value) => 'a decimal number',
            is_string($value) => 'text',
            is_array($value) => array_is_list($value) ? 'a list' : 'a map',
            default => 'a value of another kind',
        };
    }
}
