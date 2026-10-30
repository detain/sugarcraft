# C-CTX-4.a — DONE (commit c91e3f185 on cc/C-CTX-4.a, base ccf94cd63, never pushed)

Landed: TurnContextBlock::withCurrentDate(today, frozen) renders a
"Current date: YYYY-MM-DD" row ONLY while it differs from the frozen <env>
date (shape-validated input, fail-quiet otherwise); Runtime::turnContext()
derives both dates so the row is live while the PerSession prefix never
moves. EnvironmentBlock::capture() adds a static "Knowledge cutoff: …"
line from ModelMetadata::KNOWLEDGE_CUTOFFS (hand-kept substring table,
only published/confirmed generations: gpt-4o, o1-preview, o1-mini,
gemini-1.5 → October 2023); unknown family renders NOTHING; the line
freezes into every derived copy. Golden prompt bytes untouched (direct
ctor + claude model match neither mechanism).

Gate (filter Context|ModelMetadata|PromptPrefixByteStability|PromptSnapshot|
DocFigureProseDrift|ReadmeRosterDrift|SymbolCitationDrift|BaseSystemPromptTest):
OK — 2132 tests, 31186 assertions, 0 failures; 2 PHPUnit deprecations are
pre-existing tree-wide. Regression pair pins same-day-no-row vs rolled-one-row;
prefix-freeze + fail-closed mask proven.

Diffstat: 10 files, +478/−18 (5 src, 4 tests, 1 fixture, 1 doc).

Deviations:
- Beyond brief list (+2 files, §1.8 in-step): tests/Prompt/PromptSnapshotDriftTest.php
  + tests/fixtures/prompt-snapshots/turn-context.txt — my change reddened this
  guard (frozen 2026-08-26 env ⇒ ambient date row, unpinnable across days);
  closure masks the value to "Current date: <live>" (pinHostLines idiom,
  fail-closed exactly-one), fixture regenerated mechanically.
- ModelFamily enum rejected as cutoff key (SGLang-shaping vocabulary, unknown
  cutoffs) → substring table on ModelMetadata. gpt-4.1/llama-3 dropped
  low-confidence (llama-3 false-hits llama-3.1).
- Doc guards: no pinned sentence touched (verified pre-edit); "seven lines"
  docblock truth-flips rode EnvironmentBlock.

Welder carries:
- NEW test file candy-code/tests/Context/TurnContextDateTest.php needs a
  scripts/parallel-tests-durations.tsv row.
- suite-figure.json carry: +12 tests (7 new file + 4 EnvironmentBlockTest +
  1 PromptPrefixByteStabilityTest methods).
- Zero lang keys (prompt-wire English bytes). Author law verified (%ae/%ce
  detain 1/1, no Co-Authored-By).

## FIX ROUND 1 (2026-10-29, commit ce48359fe on cc/C-CTX-4.a — follow-up, no amend)

Findings dispositioned:
- MINOR gemini-1.5 cutoff — LEAD RULING (2026-10-29) taken as written: the row is
  REMOVED, not revalued (no November/May substitution). ModelMetadata.php:99-107 now
  carries a table comment naming both candidates (tech-report pre-training Nov 2023
  vs docs knowledge-cutoff May 2024) and the disagreement + staleness-law reason for
  the drop; the family takes the already-pinned unknown→zero-row path. Docblock
  review sentence truth-adjusted (OpenAI legs uncontested; gpt-4o/o1 rows STAY).
- Tests: EnvironmentBlockTest unknown-family leg gains 'gemini-1.5-pro'
  (:274-283, docblock names the drop); the frozen-copy pin (:291-300) re-keys
  'Gemini-1.5-Pro' → 'OpenAI/GPT-4O' (namespace + case tolerance still proven on a
  kept row; October 2023 byte pins unchanged). :266/:310 gpt-4o legs untouched.
- NIT-1 TurnContextBlock.php:287 — @param wording swapped to "different strings
  carry the row, equal ones clear it" (matches `$today !== $frozenDate`).
- NIT-2 Runtime.php turnContext() docblock — one sentence stating the live row is
  read in the host timezone (plain `new DateTimeImmutable()`, flips at host-local
  midnight). No behavior change.

Mutation proof (removal leg): restoring `'gemini-1.5' => 'October 2023'` reddens
EXACTLY testCaptureRendersNoCutoffLineForAnUnknownFamily (:283, 1F/90T). Restored
from cp backup, md5 ab7c4e4b14dbffd0da0b3658be629065 re-verified, grep row-count 0.
Gotcha self-caught: the first restore used the pre-EDIT backup and silently
un-did the fix; re-applied, then took a post-fix snapshot before round two.

Gate re-stamp (this tree, plain pipe, cwd=candy-code, env -u LINES -u COLUMNS):
- brief filter 'TurnContext|EnvironmentBlock|ModelMetadata|PromptPrefix|PromptSnapshot|Date':
  382T/4216A 0F exit-OK ×2 (one transient 4232A reading pre-mutation, unreproducible after;
  0F on every reading either way).
- reviewer filter 'Context|ModelMetadata|PromptPrefixByteStability|PromptSnapshot|DocFigure|ReadmeRoster|SymbolCitation|BaseSystemPrompt':
  2133T/31189A 0F ×2. Confirms the round-0 REPORT→reviewer drift (claim 2132T/31186A vs tip
  2133T/31188A: builder measured pre-final-commit, +1T/+2A real at tip); my fix adds +1A
  (the sixth unknown-model leg) — figure for the weld: 2133T/31189A.
- "PHPUnit Deprecations: 2" is config-global tree-wide, not lane-introduced.
- No new test FILE in round 1 → durations.tsv carry unchanged; suite-figure carry
  stands at +12T (no methods added or removed, only legs inside existing methods).

Author law: %ae/%ce both detain (grep -c 1/1), zero Co-Authored-By, explicit-path
staging, tree clean, never pushed. Diff: 4 files +22/−9, all inside step files.
