# C-TUI-7.a — Startup summary: personal files + gather off `view()` — LANDED

Tip: `e003ffa15659732e3d57963000c49eb8ff7fcd02` on `cc/C-TUI-7.a` (worktree `/home/sites/cc-cmp-C-TUI-7.a`, base `b38baf0f8`). Never pushed. 14 files, +834/−85, author+committer Joe Huss <detain@interserver.net> (separate greps 1/1), zero trailers, tree clean.

## What shipped

- **NEW `candy-code/src/App/StartupSummaryGatheredMsg.php`** — `final class ... implements Msg` carrying the expensive fact bag (one type per PSR-4 file).
- **`src/Support/StartupSummary.php`** (VIS-1-P2 root cause): `render()` now paints only cheap facts (version/provider/model/mode/cwd/tip) and, once a gathered bag has landed via `store()`, appends it from the memo. The old monolithic `gather()` was split into `cheapFacts()` (1 tryFact wrap) and `gatherExpensive()` (4 wraps + self-wrapped `branch()`), reached only through the new public `gatherCmd(Chat): \Closure` (`Cmd::promise` → resolves to `StartupSummaryGatheredMsg` — no timers, E646-clean). Class docblock truth-flipped from "VIEW-SIDE ONLY" to "VIEW-SIDE LIGHT". `clearCacheForTesting()` clears both stores.
- **VIS-1-P1 fix**: `instructionFiles()` now lists repo docs AND `loadPersonal()` docs (personal ones marked), deduplicated by `realpath() ?: path` (repo wins); `compose()` renders personal entries through `Lang::t('chat.startup.personal', …)` as a whole-key literal.
- **`src/Chat.php`**: `init()` always hands a batch (`Cmd::batch(wake?, [prompt?], gather)`); `applyCommandResult()` sets `$summaryRegather` in the ClearTranscript and SwitchSession arms and appends `gatherCmd($next)` to the returned cmd batch.
- **`src/Chat/Handlers/HandlesRunControl.php`**: new frozen arm stores the landed bag (`StartupSummary::store($this, $msg->facts)`).
- **`lang/en.php`**: `'chat.startup.personal' => '{name} personal'`.

## Keystone pins (regression-first evidence)

- **Base-red captured**: with pristine `b38baf0f8` `StartupSummary.php` swapped in, `tests/Chat/StartupSummaryGatherTest.php` = 1 failure (structural pin reds on base `render()`) + 4 errors (`gatherCmd`/`store` undefined). Restored md5-verified (`9122a591…`).
- **Mutation proof**: re-injecting `self::branch(` into `render()` reddens the structural pin naming "a blocking fact source is back on the view() hot path"; restore md5-clean.
- Deviation (disclosed): the brief's "GitRunner spy" is implemented as a structural source-slice pin (paint-path bodies forbid the 16 expensive-source tokens) plus a behavioral cheap/stable/branch-free render test, because `GitRunner` has no injection seam — the same house answer as the existing fail-soft pin's docblock reasoning.
- Behavioral tests: `gatherCmd` settles to `StartupSummaryGatheredMsg` with `facts['branch']==='alpha'` on a real temp repo (driven only via `GitRunner::run` — no proc_open literal); `update()` lands it through the handler and render paints `· alpha`; `/clear` re-gathers (beta appears, alpha gone); SwitchSession re-gathers under the new key with no leak of the old bag.
- Personal-file pins: compose marker + misshapen-provenance tolerance; `instructionFiles` lists repo+personal (40B/60B pair) and collapses one realpath to a single unmarked entry (HOME sandboxed in both `putenv`/`$_SERVER` spellings, restored).

## In-step guard flips

`tests/Chat/RouteTraitParityTest.php` roster + `StartupSummaryGatheredMsg` (mine) and **+ `TelemetryRetryTickMsg`** — that arm (`HandlesSessionSurface.php:319`) shipped into the base split without its roster row; the file is in my claim so the guard is green at my tip with a dated cure comment. Flips for `init()`-returns-non-null: ChatTest, SessionCommandTest, HostedRuntimeNoticeWakeTest (×3), RuntimeNoticeSinkDeliveryTest (null-vs-Closure discriminator retired; armed-after-fan-out pin), AppModelTest (batch 2→3), InitialPromptTest docblock.

## Gates (cwd worktree `candy-code`, `vendor/bin/phpunit -c phpunit.xml --filter`, plain pipe)

- `StartupSummary`: 23T/450A green. `RouteTraitParity`: 4T/44A green. `AppModelTest`: 61T. `HostedRuntimeNoticeWake`: 5T. `RuntimeNoticeSinkDelivery`: 25T/5693A. `InitCarries`: 1T. `SessionCommandTest`: 18T. `InitialPrompt`: 26T. `KittyKeyboardNegotiation`: 4T/1156A. `LangParity`: 7T/2839A. `ReadPathCensus`: 19T. `TuiLang`: 9T. `AgentStrip`: 20T. `SessionArtifactRetention`: 5T. All green.
- Five-guard: 133T with ONLY the 2 `ReadmeSuiteFigureDrift` staleness reds.
- `ContainedPathInventory`: 2 RED — **foreign base-inherited**: ± lines are `Memory/AgentMemory.php`, `Skills/SkillLoader.php`, `Host/Commands/ContextHostCommand.php`, `Host/TranscriptProjector.php`, `Tools/PathJail.php` — zero ± on my files, those src files byte-identical to base (`git diff --stat` empty), and the test file is not in my claim. My `realpath(` dedup is invisible to the instrument (`COMPARE_FUNCTIONS` = str_starts_with/strncmp/strncasecmp/substr_compare). Left for the weld/gate.

## Owed by gate/weld

1. **NEW test file**: `candy-code/tests/Chat/StartupSummaryGatherTest.php` (6 tests) → needs a `scripts/parallel-tests-durations.tsv` row (unlisted = zero shards) + `suite-figure.json`/README re-pin.
2. ReadmeSuiteFigureDrift pair was ALREADY red at base (live 26,382 vs pinned 25,537); my +8 methods grow an existing foreign drift — the re-pin is gate-owned; this lane never touched the pinned files.
3. `ContainedPathInventoryTest` roster needs the 5 foreign rows above (not this lane's file).
4. Pre-existing in every run: 2 PHPUnit deprecations from `tests/Config/AccessorDeprecationTest` private data providers (foreign, untouched).
