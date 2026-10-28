# C-AGT-5.a — REPORT — model-callable `Schedule` tool

COMMIT: d9784ce96b619e39ef3a18cb9af63f7772985074 on `cc/C-AGT-5.a` (base cc14518d5, not pushed). 16 files, +1195/−40. Author/committer Joe Huss <detain@interserver.net> (verified %ae and %ce separately), zero trailers.

## Shipped
- `src/Tools/BuiltIn/ScheduleTool.php` — `#[BuiltInTool(name:'Schedule', Ask, position 41)]`, implements Tool+BuildsFromCatalog+DelegatesToEngine+TakesToolCallId; `create|list|delete`; registry = `ScheduleRegistry::loadDefault($context->root)` (no Bootstrap threading); session mode via `permissionGate()?->mode()`, Default when unbound; list reuses `SchedulesCommand::listing()` (one paint); delete refuses untagged rows unless `include_operator_rows:true` (Ask-approval displays the arg = the asking).
- `src/Background/ScheduleFactory.php` — shared creation policy for 5.b: `settleMode` (unknown spelling refused AT CREATE naming allowed spellings; bypass-class refused by name via `ServerConfig::answersAsksWithoutAHuman`; result = stricterOf(session, requested) — never bypass rank), `MODEL_ROW_CAP=8` per root counting only `createdBy:'model'` && root-match rows, reload-before-count (cross-host cap), null-root create REFUSED (dead row = false success), arms `nextDue=now+every`, add+persist through the registry.
- `Schedule.php` — 10th field `createdBy` (+`CREATED_BY_MODEL` const; ctor permissive, `fromArray` strict: only `model` or absence, forged spellings refused by name; `toArray` emits `createdBy`; "nine fields" docblocks truth-flipped).
- `Runtime.php` — `'Schedule'` in `WRITE_CAPABLE_TOOL_NAMES` (after ShellStop) + docblock paragraph (Ask-class roster member adjudicated).
- Docs: SCHEDULES.md createdBy row + "The Schedule tool" section + add-verb truth-flip; README/ARCHITECTURE/PERMISSIONS + AGENTS_AUTHORING/SETTINGS regenerated via `php tools/gen-tool-docs.php --write` in-step; `--check` rc0.
- Tests NEW: `tests/Background/ScheduleFactoryTest.php` (14T), `tests/Tools/BuiltIn/ScheduleToolTest.php` (12T, fromCatalog+HomeSandbox house seam; attribute pin Ask/41).
- Guards in-step: ToolCatalogTest tail slice −2→−3 (Schedule rides after the MCP pair; disclosed flip), RuntimeTest drift verdict → gate⊆roster + roster-only set adjudicated-exactly-['Schedule'], ScheduleTest toArray key list, ForeignAgentPresetWiringTest +2 census rows (both new files needle-match the row's OWN mode/args key, never an Agent's).

## Gates (cwd candy-code, env -u LINES -u COLUMNS plain pipe)
- `--filter 'ScheduleFactoryTest|ScheduleToolTest|testTheWriteToolRosterDoesNotDrift...'` → OK 27T/110A.
- `--filter 'ToolCatalog|BuiltInToolCorpus|ForeignAgentPresetWiring|ShellSubjectTools|ToolDocDrift|Schedule'` → 248T/1108A, 1F = `DreamPassTest` (matches 'Schedule' in name; PRE-EXISTING — reproduces identically on main tree fa14b82c6, no overlap with this diff).
- `--filter 'DuplicatedTestHelperDrift|DenialPrefixRoster|GlobDialect|ReadPathCensus'` green; `LangParityTest` OK 7T/2839A; `BinCandyCodeWiringTest` OK 1174T (provider +2T from the two new src files — suite-figure NOT touched, re-pin owed at gate); `ReadmeRosterDriftTest` OK.
- TreeWideGuardRosterTest: 2F at this base naming ONLY foreign files (`Cli/JsonSchemaEnvelopeTest`, `Context/MemoryRecallBlockTest`) — already cured upstream by 743681f84 (in main tree, green there); my own glob()-literals were removed (deterministic fixed-path tearDowns) so this lane adds zero offenders.

## Deviations
1. Brief's `lang: tool.schedule.*` NOT used: lang/en.php header law — tool descriptions/results are model-read text and stay English in code (every sibling tool, ShellStop); list output already routes through `host.schedules.*` via the reused command listing. en.php untouched.
2. `ShellSubjectToolsCompletenessTest` / `BuiltInToolCorpusTest` needed no edit (site-6 is containment on the RESOLVED constant; corpus figures are live-derived) — gates prove it.
3. Brief's "record in corpus secondary-declaration map" is stale vs the current derived corpus (one-type-per-file arm already satisfied).
4. Null-root create REFUSES rather than persisting an unbound row (design assumed a root always exists; a never-firing row would be a fabricated success — §1.11 root-cause law).
5. ToolCatalogTest tail-pin flipped −2→−3 with adjudication comment (position 41 makes the MCP pair non-tail; the pin keeps its anti-sneak meaning).

## New test files (gate owes durations.tsv rows)
- candy-code/tests/Background/ScheduleFactoryTest.php
- candy-code/tests/Tools/BuiltIn/ScheduleToolTest.php
(Suite-figure untouched per §1.5; report-only note: +2 BinCandyCodeWiring provider rows, +26 new tests.)
