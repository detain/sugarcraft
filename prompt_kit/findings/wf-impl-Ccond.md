# wf-impl track Ccond — P1-5 condition grammar half

Commit: `62bdc9093` (candy-code: condition pointer leaves, empty op, loop-only dry/budgetBelow leaves + JsonPointer).

## 1. What was implemented (P1-5 items 1 + the `until: {dry}` / `{budgetBelow}` leaves of item 3)

### `JsonPointer` (new, `candy-code/src/Workflows/JsonPointer.php`)

RFC 6901 resolver with the EXACT semantics of `WorkflowEngine::jsonPointerValue()` (the
`{{name.<pointer>}}` helper), so the engine can switch to it:

- split on `/` BEFORE unescaping; each token unescapes `~1`→`/` then `~0`→`~`;
- a leading `/` is optional (`/a/0` ≡ `a/0`); `""` = whole document; `/` = the member named `""`;
- dots have no meaning (`a.b` is one key); array indices are member names (`0` ok; `01`, `-`, `1.0` miss).
- Unlike the engine helper it returns ANY leaf (arrays, JSON null) and separates found/missing.

Public API:

```php
JsonPointer::resolve(mixed $document, string $pointer): array{found: bool, value: mixed}
JsonPointer::resolveJson(string $json, string $pointer): array{found: bool, value: mixed} // invalid JSON → not found
JsonPointer::isWellFormed(string $pointer): bool   // false when a `~` is not `~0`/`~1` (load-time check)
JsonPointer::tokens(string $pointer): list<string> // unescaped reference tokens
```

Engine switch recipe: `jsonPointerValue($row, $p)` ≡
`$r = JsonPointer::resolveJson($row, $p); return $r['found'] && is_scalar($r['value']) ? $r['value'] : null;`
(the engine also requires the row to decode to an array; typed rows always do).

### Condition leaf `pointer:` (optional)

`when: {stage: review, pointer: /verdict, equals: NEEDS_FIX}`

- Value: non-empty string, RFC 6901, leading `/` optional, every `~` must be `~0`/`~1`.
- With `pointer:` the leaf reads the stage's TYPED row: `$context[WorkflowEngine::TYPED_RESULTS_CONTEXT_KEY][<stage>]`
  — the same map, keyed by the same RESULT NAME, that `{{name.<pointer>}}` reads (task `name:` if set,
  else the stage name for a sequential stage; `<stage>_<n>` for unnamed parallel agents, etc.). Only
  schema'd stages (`outputSchema`) have typed rows. It never falls back to the text row.
- The resolved leaf compares by its string form: strings verbatim; bool → `true`/`false`; int/float by
  PHP's `(string)` cast (identical to what interpolation splices: `0.5`, `7`, `1.0` → `1`); JSON null →
  `null`; arrays/objects → compact JSON (`JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`; note `{}`
  decodes to `[]` and re-encodes as `[]`).
- Missing stage / no typed row / undecodable row / pointer miss → **unsatisfied** (fail-closed).
- Errors (raw English, InvalidArgumentException):
  - `when "pointer" for stage "S" must be a non-empty string (an RFC 6901 pointer such as /verdict), got <type|an empty string>`
  - `when "pointer" for stage "S" is not a valid RFC 6901 pointer: "<p>" ("~" must be written ~0, "/" inside a key ~1)`
  - unknown-key message now reads `supported spellings: stage, pointer, <op>`.

### New op `empty: true|false`

`until: {stage: find, pointer: /fresh, empty: true}` · `when: {stage: tests, empty: false}`

- Value must be a bool: `when "empty" value for stage "S" must be true or false, got <type>`.
- Exact emptiness, NO trim (no-coercion law): text `''` only — whitespace is content (spell blank as
  `matches: '/^\s*$/'`). Behind a pointer: string `''` or empty array/object `[]`/`{}`.
- A pointed leaf that is a number, bool or null is neither empty nor non-empty → unsatisfied for BOTH
  `true` and `false`. A stage that never ran is unsatisfied for both, too.
- A leaf still carries exactly one op; `StageCondition::OPS` is now `['equals','contains','matches','empty']`.
  Other string ops still refuse non-string values (`equals: true` must be quoted).

### Loop-only leaves (`StageCondition::LOOP_OPS = ['dry', 'budgetBelow']`)

Accepted ONLY by `StageCondition::parse($clause, allowLoopLeaves: true)`; the default (`false`) keeps
`when:` refusing them. They may sit inside `all`/`any` (MAX_DEPTH still 2). A loop leaf is the whole
leaf: no `stage`, no `pointer`.

- `{dry: N}` (int N ≥ 1): satisfied when `$context[StageCondition::LOOP_DRY_ROUNDS_CONTEXT_KEY]`
  (`'@loopDryRounds'`, int — consecutive completed rounds that added nothing new) is `>= N`.
- `{budgetBelow: N}` (int N ≥ 1): satisfied when `$context[StageCondition::BUDGET_REMAINING_CONTEXT_KEY]`
  (`'@budgetRemaining'`, int|float — tokens still spendable) is `< N` (strict).
- Absent / non-numeric key → unsatisfied (so with no budget configured the loop runs to `max`).
- Errors:
  - `when leaf "dry" is loop-only; it is accepted in a loop's until, never in when`
  - `loop leaf "dry" must be a whole number >= 1, got <n|type>`
  - `loop leaf "dry" has unknown key "stage"; it is a whole condition on its own, e.g. {dry: 2}`
  - more-than-one-op message lists `equals, contains, matches, empty, dry, budgetBelow` under the flag.

### Normalized shapes (what `evaluate()` consumes)

- text leaf `{stage, op, value}` (unchanged; value bool for `empty`)
- pointer leaf `{stage, pointer, op, value}`
- loop leaf `{op: 'dry'|'budgetBelow', value: int}`
- combinator `{combinator, conditions}` (unchanged)

Existing `when:` shapes normalize byte-identically.

### Tests

- `candy-code/tests/Workflows/JsonPointerTest.php` (new, 26)
- `candy-code/tests/Workflows/StageConditionPointerTest.php` (new, 18)
- `candy-code/tests/Workflows/StageConditionLoopLeafTest.php` (new, 12)
- `candy-code/tests/Workflows/StageConditionTest.php` (roster test updated for `empty` + new constants)

## 2. Not done (by brief scope)

- Engine side: populating `@loopDryRounds` / `@budgetRemaining`, `accumulate:`, `{{previous}}`/`{{seen}}`/
  `{{fresh}}`, loop on parallel/verification/map, `when:` on map steps — engine agent.
- Switching `WorkflowEngine::jsonPointerValue()` to `JsonPointer` — not my file.
- The brief said `normalize()`; the existing method is `parse()`, so the flag was added there.

## 3. Cross-track requests

- **WorkflowRegistry** (`:1781`, loop `until:` parse) and **WorkflowBuilder** (`:338`, `loop()`): call
  `StageCondition::parse($until, allowLoopLeaves: true)` so `{dry}`/`{budgetBelow}` load in `until:`.
  `when:` call sites (`Registry:1718`, `Builder:289`) keep the default.
- **WorkflowEngine**: maintain `StageCondition::LOOP_DRY_ROUNDS_CONTEXT_KEY` (int, reset per loop) and
  `StageCondition::BUDGET_REMAINING_CONTEXT_KEY` (only when a budget is configured) in the context passed
  to `StageCondition::evaluate($loop['until'], $context)`; optionally replace `jsonPointerValue()` with
  `JsonPointer::resolveJson()` (recipe above).
- **WorkflowLinter** mirrors worth adding: a `pointer:` leaf naming a stage/task with no `outputSchema`
  (always unsatisfied); `{dry: N}` in an `until:` of a loop without `accumulate:` (never satisfied);
  `{budgetBelow}` with no `config.maxTokens`/budget (never satisfied → runs to max); `dry: N` ≥ `loop.max`.

## 4. Commits

- `62bdc9093`
