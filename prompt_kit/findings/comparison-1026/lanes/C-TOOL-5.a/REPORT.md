# C-TOOL-5.a — LANDED (verdict-first)

**Commit:** `bdf851899` on `cc/C-TOOL-5.a`, parent `878047472` (verified). Author+committer `Joe Huss <detain@interserver.net>` (`%ae`/`%ce` grep -c detain = 1 each, separate greps), zero trailers, no Co-Authored-By. Never pushed. 14 files, +733/−41: `candy-code/{README.md,docs/SETTINGS.md,lang/en.php}` + `src/Config/LayeredSettings.php` (LAYERED_KEYS = generator-owned line, from `tools/gen-settings-doc.php --write`) + `src/Config/Settings/Definitions/ToolSettings.php` + `src/Tools/BuiltIn/{Edit,Write,ApplyPatch}.php` + `src/Tools/Edit/{IndentShift,EditMatcher}.php` + `src/Tools/ToolLimits.php` + `tests/Tools/ToolCapSettingsTest.php` + NEW `tests/Tools/BuiltIn/ReadBeforeWriteTest.php` (223L) + NEW `tests/Tools/Edit/BomPreservationTest.php` (181L). Forbidden files (suite-figure.json, durations.tsv, dist) never staged.

## Twin-writer disclosure (read this before resuming anyone)
This is the 3rd builder incarnation; incarnations #2 (and a still-live concurrent writer of the same step) landed the work on disk. I salvaged the pre-existing 3-file diff (Edit/EditMatcher/IndentShift — complete and good), wrote the remaining src edits, and a concurrent twin process wrote the same final designs for Write/ApplyPatch/ToolLimits/ToolSettings + docs generation + guard flip + both new test files (verified mtimes during my session; converged design, coherent final tree — no conflicts). **The twin made the commit at 15:2x; I verified it: content == gate-tested tree byte-for-byte (worktree clean against it), author law compliant.** Anyone holding a resumable sibling session for C-TOOL-5.a must be STOOD DOWN — the work is committed on `cc/C-TOOL-5.a`.

## Implementation (per BRIEF + plan §1/§2/§6)
- **Leg 1 (GAP-EDT-02):** user-tier Bool `requireReadBeforeWrite` (default false, RiskClass::Narrowing, layered, NextTurn, NOT project-settable) through `ToolLimits` (`REQUIRE_READ_BEFORE_WRITE_KEY`, `BOOL_KEYS`, new `honouredBool()` + `bool()` accessor, `applyTo()` rebind arm for Edit/Write/ApplyPatch). ON + a live ReadLedger: Edit, Write-overwrite, and every ApplyPatch existing-file source (Update/Move/Delete) refuse a file with no `has()` entry — "has not been read in this session; Read it first"; new-file creates/Add untouched; off is byte-identical today. Descriptions claim the rule only on enforcing instances (flag && ledger doors). Forked-child lift pinned via `CarriesSessionState` export/merge round-trip.
- **Leg 2 (GAP-EDT-10):** `IndentShift::BOM/hasBom/stripBom/inheritBom`; `EditMatcher::match()` strips the mark from content/old/new; Edit, Write-overwrite and ApplyPatch (plan + previewDiff) compute on the stripped body and re-prepend the file's own mark exactly once at write (own-mark never doubled; plain files never gain one).

## Gates (targeted, cwd candy-code, env -u LINES -u COLUMNS, plain pipe, -c phpunit.xml)
- ReadBeforeWriteTest + BomPreservationTest: **OK 20T/91A**
- EditTest + WriteTest + ApplyPatchTest + ToolCapSettingsTest: **OK 88T/430A** (zero regressions)
- SettingsSchemaDocDrift + TrustKeyDocumentationDrift + ReadmeSettingsTierClaim + ReadmeRosterDrift: **OK 47T/845A**
- DocFigureProseDriftTest: **OK 92T/4047A**
- Five-guard family filter (DuplicatedTestHelperDrift|GlobDialect|DuplicatedDocBlockLine|TreeWideGuardRoster|SymbolCitationDrift): 58T, **1F = BASE-INHERITED, NOT this step** — `Context/CacheTtlIdleFoldTest.php vs Providers/ModelMetadataTest.php` identical-helper pair; proven independent (red persists with this step's new files hidden; neither file touched by this commit). Seam → gate/welder.

## Deviations (disclosed)
1. `honouredBool()` added instead of widening `honoured()`'s return type: external callers `ScriptHook.php:206`, `Agents/AgentPoolConfig.php:124`, `Agents/EngineExecutor.php:100` consume `int|float|string|null`.
2. Write BOM inherit rides the `$previous !== ''` condition (same skip as the ISS-062 endings inherit): an oversized previous read as empty lends neither endings nor mark — symmetric, disclosed.
3. Flag is turn-time only (`withRequireReadBeforeWrite`); `fromCatalog` launches off — matches NextTurn apply mode.

## Welder owes
- durations.tsv rows: `tests/Tools/BuiltIn/ReadBeforeWriteTest.php`, `tests/Tools/Edit/BomPreservationTest.php`.
- suite-figure re-pin: +2 new test files, +20T from them (exact live-enum at merge).
- The base-inherited DuplicatedTestHelperDrift red above.
