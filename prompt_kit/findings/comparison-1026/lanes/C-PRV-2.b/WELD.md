# C-PRV-2.b — WELD (2026-10-28, welder W16)

## Pick
- Pre-pick live tip: `a074a8805c53e09ae954d28cc25b0b6325931f98` (weld: C-SEC-6.a).
- Phantom gate PASSED before the pick: `git cat-file -t be3d3af187065f080fde411cb0c54112ed61e36e` → commit; branch `cc/C-PRV-2.b` points at it; `master..cc/C-PRV-2.b` = ONE commit; worktree `/home/sites/cc-cmp-C-PRV-2.b` porcelain EMPTY; `%ae`/`%ce` separate greps = detain@interserver.net; zero trailers.
- `git cherry-pick be3d3af18` → **`cf36a8458c6bb299af289c23bafe4a3ef7f04423`** — CLEAN, zero conflicts. Patch-id **eff967934fb30480413feaf58bfaa6f06e737143 IDENTICAL** lane↔master. Lane set: 6 files +858/−15 (`src/Providers/Concerns/OpenAiResponsesWire.php`, `src/Providers/Concerns/AnthropicMessagesWire.php`, `src/Providers/OpenAIProvider.php`, `docs/ADDING_A_PROVIDER.md`, NEW `tests/Providers/OpenAiResponsesReasoningTest.php` 519L/12 methods, `tests/Providers/OpenAiResponsesWireTest.php`).
- §1.5/§2 law honored in the pick: zero `suite-figure.json`, zero `durations.tsv`, zero `dist/` touches.

## Regen riders: NONE owed
Zero `candy-code/src/Protocol/**` paths and zero `candy-code-web/**` paths in the commit stat → no `gen-protocol-schema`, no doc generators, no web regenerate; no `weld: … regenerate` commit (the docs edits in the pick are prose in `ADDING_A_PROVIDER.md`, not a generated region).

## Gates on the merged tree (plain pipe, env -u LINES -u COLUMNS, cwd candy-code, -c phpunit.xml)
- `--filter 'OpenAiResponsesReasoning'` → **`Tests: 12, Assertions: 32` / "OK, but there were issues!"** (the 2 PHPUnit Deprecations are the base-present `tests/Config/AccessorDeprecationTest.php:168/:202` provider-visibility residue that fires during ANY `--filter` provider expansion — foreign era-style, documented by REVIEW-3). EXACT to REVIEW-2/REVIEW-3/fixer claims.
- `--filter 'OpenAiResponses|Providers'` → **`Tests: 2438, Assertions: 9024`** OK — EXACT to both cure reviews (r1 baseline 2436 +2T).
- `--filter Drift` → **`Tests: 593, Assertions: 66669, Failures: 7`**; the 7 decompose EXACTLY to the prescribed foreign-standing set: `ReadmeSuiteFigureDriftTest` ×2 (staleness pair), `PermissionsIntroDriftTest::testThePatternToolRosterIsTheWholeCatalog` ×1, `ReadmeJsonErrorContractDriftTest` ×4. Zero self-inflicted → **not red-for-this-lane**; carried to closeout below.
- No regen/drift-guard flips introduced by the weld commit (marks + evidence + run-state only).

## PHANTOM-REVIEW event (recorded, no action taken)
The lead's inflight note for this lane described the r2 round as `FINDINGS 0C/0M/1MINOR/2NIT` with the MINOR being "replay must be gated on shapesEffortFor(model)". That note was **phantom-to-disk**: the tip under review (`be3d3af18`, amended) already carried the gate — the replay `foreach` is wrapped in `if ($this->shapesEffortFor($request->model))` at `OpenAiResponsesWire.php:204`, the same predicate the request body's reasoning/`include` door uses at `:120`. The lane's own REVIEW-2.md on disk returns **CLEAN 0C/0M/0MINOR** for that tip and verifies the cure, so no re-fix round was dispatched: the weld proceeded on the disk record (fixer's FIX-1.md + red-before.log at `tests/Providers/OpenAiResponsesReasoningTest.php:331` are the genuine cure evidence: base-src/tip-test fails exactly the mid-session-switch negative leg).

## MINOR-2 trait-const adjudication (microprobe)
REVIEW-1's second MINOR claimed the "direct `Trait::CONST` access is a PHP fatal" docblocks (OpenAIProvider.php:344-350, OpenAiResponsesWire.php:405-415) are false for a PUBLIC const. The fixer rejected it; REVIEW-2 could not live-verify (direct `php` refused by the reviewer permission map) and sustained the rejection on the quoted error only. **REVIEW-3 is the authoritative adjudication**: the r3 reviewer ran its own microprobes on the identical PHP 8.3.6 runtime THROUGH the gated `vendor/bin/phpunit --no-configuration` (files now archived here: `M2AdjudicationProbeTest.php`, `M2ClassThroughProbeTest.php`, `review3-m2probe*.php`, `traitconst.php`; fixer set: `probe*.php`, `mini*.php`, `statement.php`, `two.php`, `seg.php`). Verbatim: a PUBLIC trait constant accessed as `ProbePublic::K` — top-level AND cross-trait from inside a method — dies with `Error: Cannot access trait constant ProbePublic::K directly`, while the using-class form passes `OK (1 test, 1 assertion)`. The docblocks are literally true for the repo's PHP floor; the 8.2/8.3 trait-const RFCs added declarations/visibility and by-name fetch, not direct trait-name access. **Docblocks stay as written; r1 MINOR-2 premise refuted.**

## Durations (scripts commit `f0cf899f9d6a61ad1657468c946c20f9b811e356`)
ONE new row: `candy-code/tests/Providers/OpenAiResponsesReasoningTest.php 0.020 12` — time/count from the file's junit per-file sum (`--filter OpenAiResponsesReasoningTest --log-junit /tmp/opencode/cc-cmp/prv2b-weld.xml` → `tests 12 / assertions 32 / time 0.020197`). Descending-invariant insert at line 967 inside the 0.020 tie band (after `Tui/MediaForm/ImageSectionIngressTest.php`, before `Chat/PrCommandTest.php 0.019`). Verified: tsv **1532→1533**, set-equality `new-old` = exactly that one row, `old-new` empty, ascending-violations **4 == 4** (pre-existing tail noise untouched). `OpenAiResponsesWireTest.php` is MODIFIED → already rostered (row 647), no new row owed. `suite-figure.json` NEVER touched (§1.5).

## Run-state
`lanes/run-state.json` in this commit: `C-PRV-2.b` removed from `inflight` (all other inflight keys preserved verbatim: C-EXT-4.b, C-MCP-3.b, C-MCP-4.b, C-AGT-5.c), appended to `welded`, `welded_count` 156→157 with `len(welded)==welded_count` re-verified; `weld_notes["C-PRV-2.b"]` added.

## Absorption proof + cleanup
`git cherry master cc/C-PRV-2.b` → **`- be3d3af187065f080fde411cb0c54112ed61e36e`** (absorbed; patch-id identity above is the content proof, so no conflict-resolution `+` to re-verify). Then `git worktree remove ../cc-cmp-C-PRV-2.b` + `git branch -D cc/C-PRV-2.b`.

## Carries (closeout / other owners)
- **+12T** suite-figure/README re-pin delta from this lane (10T r1 + 2T cure pair) — rides the §7 closeout battery, NOT the weld (lane-forbidden file).
- Foreign standing reds confirmed at weld tip: `PermissionsIntroDriftTest` Schedule-roster row (C-AGT-5.a era), `ReadmeJsonErrorContractDriftTest` ×4, and the 2 accessor-deprecation provider warnings.
- NIT-3 (out-of-step docs): `CompleteResponse::$thinkingBlocks` docblock still Anthropic-family-worded while the carrier is fleet-tagged → closeout docs collection.
- W15/W17 note: C-PRV-2.c (W17) builds on this wire file's image arm + `input_file` parts; the reasoning gate at `:204` is now load-bearing for that step.
