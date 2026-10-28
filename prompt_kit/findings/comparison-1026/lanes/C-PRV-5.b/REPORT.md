# C-PRV-5.b — title and auxiliary backends run without the thinking budget

- Commit: 9868c7f8df28720fe488847d8f236266cb5f4724 on cc/C-PRV-5.b (base 33d015d4a, not rebased, not pushed). %ae/%ce both detain@interserver.net, zero Co-Authored trailers.
- Files: candy-code/src/Cli/Bootstrap.php (toollessBackend + titleBackend + summaryBackend regions only), candy-code/docs/SETTINGS.md (thinkingBudget hand prose, C-PRV-5.a :521 hunk preserved), candy-code/tests/Cli/ToollessBackendThinkingTest.php (new). Nothing else touched.

## What shipped
- `toollessBackend(..., bool $keepThinking = false)`: when !keepThinking, sets `$config['thinkingBudget'] = ''` on the config copy; `summaryBackend()` alone passes `keepThinking: true`; `titleBackend()` (and every other auxiliary consumer) default to the drop. AnthropicProvider untouched; the withModel carry becomes moot (no budget to carry).

## Deviation from the design's literal mechanism (disclosed)
- Design said "unsets thinkingBudget from the config copy". A literal `unset` is INCOMPLETE: all three thinking arms in ProviderFactory (:1184 anthropic, :1723 vertex, :1760 gemini) resolve `$config['thinkingBudget'] ?? self::userTierSetting('thinkingBudget')`, so a deleted key lets a root-tier `thinkingBudget` — the documented primary setting — ride the fallback straight into the titler's requests. `''` is a present non-null key (beats `??`) that BOTH parsers read as off (strict Anthropic parse returns null for null/''/[] at :1008; tolerant parse: wholeNumber('') fails the digit match → null). Proven by mutation: with literal unset, testTheTitlerDropsTheUserTierThinkingBudget goes red alone; with the arm disabled entirely, both titler tests go red; the summary polarity stays green under both mutations.

## Gates (plain pipe, cwd worktree candy-code, env -u LINES -u COLUMNS)
- ToollessBackendThinkingTest: Tests: 5, Assertions: 14 — OK.
- --filter 'ThinkingCeiling|AnthropicExtendedThinking': Tests: 57, Assertions: 141 — OK.
- --filter 'SettingsSchemaDocDrift|TrustKey|DocFigureProseDrift': Tests: 128, Assertions: 4685 — OK.
- five-guard minus DocFigure dup ('ReadmeSuiteFigureDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift'): Tests: 42, Failures: 2 — the expected ReadmeSuiteFigureDrift staleness pair only (pinned 25,537 vs live 26,237 at this base; closeout re-pin owns it).
- Neighbors 'BootstrapSpendAndSummaryTest|CacheReusingSummary': Tests: 60 — OK.
- Every run carries "PHPUnit Deprecations: 2" — pre-existing repo-wide AccessorDeprecationTest non-public data providers (reproduced identically on untouched TitleBackendAuxiliaryModelTest run); not introduced here.

## New test file → durations row (for the gate)
- candy-code/tests/Cli/ToollessBackendThinkingTest.php: 5 tests, ~4.4 s worst-class observed (4.4 s includes suite construction; file-alone wall ≈ 4 s on this box). suite-figure.json and durations.tsv never touched by this lane.

## Harness notes
- Fresh worktree had no vendor/; `php scripts/refresh-deps.php --mode=linked` (linked=56, committed-tree guard rc=0) — install-time wiring only, no composer update.
- Bash CWD does not persist across calls; all commands used absolute anchors.
