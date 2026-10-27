# C-PRV-5.a — REVIEW-1 (step loop, round 1)

VERDICT: CLEAN

Lane: /home/sites/cc-cmp-C-PRV-5.a branch cc/C-PRV-5.a, single commit cf1b4b030, tree clean, on base c36cc1982-era.

## Gates re-run (plain pipe, cwd candy-code, env -u LINES -u COLUMNS)

- `--filter 'ThinkingCeiling'` → `OK — Tests: 20, Assertions: 51` (matches claim; 2 PHPUnit Deprecations, both FOREIGN: private static providers in tests/Config/AccessorDeprecationTest.php:168/:202, present in the main tree at base — not lane-introduced, gate not failing on them).
- provider siblings `--filter 'ThinkingCeiling|AnthropicExtendedThinking|VertexGeminiOutputBudget|GeminiProvider|EffortGate|ProviderFactory'` → `OK (247T/739A)` (builder's 204T was its narrower set; both green).
- `--filter 'SettingsSchemaDocDrift|DocFigureProseDrift|TrustKey'` → `OK (128 tests, 4673 assertions)` — EXACT match to claim.
- five-guard subset `--filter 'ReadmeSuiteFigureDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift'` → 42T, Failures: 2 — exactly `testReadmeHeadlineTestsFigureMatchesTheLiveEnumeration` + `testArtifactIsNotStaleAgainstTheLiveEnumeration` (25537 pinned vs 26141 live, +20 lane tests). Welder re-pin duty; staleness pair is the only red.

## Axes

1. **Scope.** Diff = 8 files, every one in the brief's files list or the lane's own evidence dir (prompt_kit REPORT.md). EffortBudget.php and all four hot src files are brief-declared. SETTINGS.md: single hunk in the hand-prose `thinkingBudget` bullet (~L517); no `<!-- settings:` markers anywhere near it; generated tables at :192/:1074 untouched — no collision with C-CTX-6.c's gen-settings-doc claim. No suite-figure.json, no durations.tsv, no dist. ThinkingCeilingTest is the only new `*Test.php` and is DUR-listed in the REPORT.
2. **Clamp order / correctness (traced).** Budget: `max(MIN, capToCeiling(b, c))` — with the table's ceilings (64000/65536) ≥ floor, the floor can never lift a clamped value past the ceiling; unknown id (c null) passes through untouched. Reply leg recomputed from the ONE resolved budget, then ceiling-capped; the `maxTokens <= budget` yield (`max(MIN, maxTokens-1)`) keeps the strict triangle at budget==ceiling (traced 64000→63999, and 50000→max 75000→64000, no yield needed). Budget drop to null flows into the temperature ternary and `formatAnthropicMessages` (resolved above the walk — signed-block replay gate reads the same value). Gemini: omission gated on family KNOWN (`$ceiling !== null && !supportsThinking`), `-1`/`0` pass through unclamped on thinking families and are dropped on pre-thinking ones — all pinned. Explicit `maxOutputTokens`/`max_tokens` above a known family's ceiling is silently clamped; this matches the brief's approach line ("Clamp max_tokens to the family output ceiling") — the launch refusal is prescribed for `thinkingBudget` only. The SETTINGS.md ":351 replaces that derivation outright, unclamped" sentence is sglang-scoped (anaphora on "that derivation") and stays TRUE — Sglang ids are outside both families → null ceiling → pass-through.
3. **Factory refusal.** Both resolvers gained `string $model` (in scope at all three call sites: 1166/1196-anthropic, 1713-vertex, 1752-gemini builder heads); throw is an `\InvalidArgumentException` raised during provider construction — same loud provider-prefixed sprintf shape as the floor refusal, nothing half-built. Garbage stays no-setting on the tolerant arm (pinned). Unknown ids refuse nothing on either parser (both polarities pinned in testTheFactoryRefusesNothingItDoesNotKnow).
4. **Non-vacuity.** Mutation runs are not possible from this read-only reviewer map (edit/cp denied), so per the contract's static clause: neutering the triangle-yield guard reddens `testABudgetAtTheCeilingYieldsOneTokenToKeepTheProtocolTriangle` (64000 ≠ 63999 pin); neutering the Anthropic omission branch reddens tests 1/2/7 (`assertArrayNotHasKey('thinking')`); neutering the Gemini omission reddens 8/9 (builder's M3, 2 reds); removing either factory throw reddens 15/16/17. All pins assert shapes the base tree contradicted — consistent with the builder's base-swap 13F.
5. **Disclosed corners (carries, report-only, confirmed no silent wrong-behavior).** vertex+claude model with budget in (64000,65536]: refused loudly at launch (per-model ceiling deviation, disclosed in REPORT); budget exactly 65536 on gemini: accepted, wire yields to 65535, triangle holds. gemini-2.5-flash 24576 real ceiling absent from the C-PRV-1.a family table: `/effort max` 32768 clamps to nothing locally — no local misbehavior at 24576, residual disclosed; table carry, correct to defer.
6. **Laws.** §1.9: no glob-shaped string literals and no `**Refused:**`-shaped literals in the test file (wildcard-bearing text only inside space-bearing prose; `*` hits are docblock tokens). Author/committer both detain@interserver.net (%ae/%ce separate greps), zero Co-Authored trailers, single commit. E646: no timeouts touched. Conventions: strict_types, final EffortBudget, one clamp spelled once (capToCeiling), comments say why, CTX-1/A21(b) comments truth-flipped in-step.

## Findings

None (0 critical / 0 major / 0 minor step-local).

## Notes for the welder (not findings)

- Re-pin README + suite-figure.json pair (staleness reds above); add the DUR row `candy-code/tests/Providers/ThinkingCeilingTest.php` (REPORT §DUR: ~0.14s, fast).
- The foreign AccessorDeprecationTest private-provider deprecations inflate any `--display-deprecations` count by 2 suite-wide; unrelated to this lane.
