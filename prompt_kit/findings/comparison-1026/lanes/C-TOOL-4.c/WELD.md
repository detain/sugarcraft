# C-TOOL-4.c WELD — bwrap hardening + user-tier escape-hatch setting (W34 LATE)

Welder: cc-cmp-welder (SOLE master writer), 2026-10-31. Master before: `cf801710a`.

## TIMING ANOMALY — cited review landed AFTER the welder's opening forensic (recorded honestly)

The dispatch cited `lanes/C-TOOL-4.c/REVIEW-1.md` = **9,235 B**, r1 CLEAN C=0 M=0 MINOR=0 NIT=1,
"I stat'd it AND read its verdict line myself". At weld open (~07:45) I stat'd the exact path:
**absent** — the dir held only the builder's `REPORT.md` (5,391 B). I recorded a phantom-artifact
anomaly per the t706 precedent and proceeded on the merged-tree gates as verification.
**RESOLUTION (caught at commit staging, 07:52):** the file NOW EXISTS — `stat` reads **16,210 B,
mtime 2026-10-31 07:51:28** — the reviewer's write landed in the six minutes between my forensic and
my commit, i.e. the lead dispatched from in-channel knowledge (or a mid-stream size read) before the
flush. So the r1 review is GENUINE ON DISK; I read its verdict line myself: "**Verdict: CLEAN — 0
critical / 0 major / 0 step-local minor / 3 NIT (2 out-of-scope, 1 step-local perf) + §7 hand-offs +
2 lead-premise corrections**". Two citation corrections, by-ref per child-brief hygiene law: (a) the
size is **16,210 B, not 9,235 B** as dispatched; (b) the dispatch's NIT-1 gloss "by-ref static memo
untested" is NOT what the file says — its NIT-1 is a perf polish (`SandboxFactory::fromSetting`
falls back to the UN-MEMOIZED `Bootstrap::readUserConfig()`; threading `$userConfig` would save one
disk-parse per sandboxed command), and the by-ref `probeVerdicts()` memo is explicitly Q3-PASSED
(process-global, keyed, pinned by the noSpawn leg). NIT-2/NIT-3 are out-of-scope/nuance per the file.
Also recorded from the file: reviewer's `gen-settings-doc --check` was UNVERIFIED-BY-EXEC (its
permission map denies `php <script>`); the welder EXECUTED it — rc0 up-to-date on the merged tree.
Builder REPORT lists **11** files (brief's "10" was wrong) — reviewer confirmed against the design block.

## Merge

Lane base `9a0c569eb`, tip `1ac60022a`; master since base = C-EXT-6.c (3 picks + marks + run-state).
Cherry-picks, NO `-x` per CHERRY-PICK-TRAILER-LAW-vs-PLAN-PRECEDENT-t646:

- `fb0c461c3` → **`5333e353b`** (bwrap hardening — nested-userns flags, two-stage probe, --config re-bind, checkpoint 1/2) — patch-id **SAME**
- `1ac60022a` → **`56893049b`** (BubblewrapHardeningTest + PERMISSIONS.md bullets it pins) — patch-id **SAME**

ZERO conflicts. `candy-code/lang/en.php` auto-merged as the APPEND UNION it is: duplicate-key scan
of the merged blob (`grep -oE "^\s+'[a-z0-9._]+'" | sort | uniq -d`) returns **empty (DUPCOUNT=0)**;
the lane's two keys land in the sorted run — `settings.bashSandboxNestedUserns.label` :2110 and
`.help` :3908 — beside the C-EXT-6.c `hooks.http.*` family that landed since the base.
TreeWideGuardRosterTest WALKS_A_DIRECTORY_THE_TEST_MADE — all THREE sibling rows survive in the
landed blob: `:587` Agents/Live/AgentLiveRegistryTest.php · `:645` Cli/LoginSubcommandTest.php
(e78d5dc14) · `:801` Tools/BashSandboxTest.php. The lane edits none of these files' licence rows.

## Merged-tree gates (cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)

- Sandbox family (`tests/Tools/Sandbox tests/Tools/BashSandboxTest.php tests/Cli/DoctorSandboxProbeTest.php`): **OK 72T/393A** — r1-EXACT.
- New file alone `tests/Tools/Sandbox/BubblewrapHardeningTest.php`: **OK 16T/79A** — builder/r1-live-EXACT.
- `DuplicatedTestHelperDriftTest` BARE class: **14T/204A GREEN**. `TreeWideGuardRosterTest` BARE class: **17T/3871A GREEN** (e78d5dc14 cures hold on merged tree).
- Guard battery (reconstructed filter `Settings|Layered|DocFigureProseDrift|TrustKey|ReadmeSettingsTier|ReadmeRoster|ReadPathCensus|DuplicatedTestHelperDrift|TreeWideGuardRoster`): 1116T/26122A/**3F**. My filter is broader than the lane's 248T shorthand, and ALL three reds classified:
  1. `ApplySettingsTest::testEveryLiveKeyTheViewCanSaveHasAnApplyArm` — "promptCache.keepWarm is badged live but nothing applies it" = the ACCEPTED keepWarm 1F, shape unchanged; the message names keepWarm only, NOT `bashSandboxNestedUserns` (lane key needs no apply arm).
  2. `LayeredSettingsTest::testTheUserTierOnlyKeys...` — the array diff's ONLY extra derived entry is `76 => 'screenReader'`; the lane's `bashSandboxNestedUserns` leg passes (99T/234A/1F standalone). **THE STANDING MASTER-SIDE §7 RIDER** — introduced by the WELDED C-TUI-6.i and never followed into the pinned roster; r1 (and the builder) proved it red at lane base with the lane's work stashed. NOT this lane's defect; NOT cured here (welder does not fix code — the 2-word roster cure belongs to the key owner).
  3. **NEW, PROVEN FOREIGN**: `SettingsEditorTest::testSearchingTypesFiltersAcrossCategoriesAndEscClearsBeforeClosing` :189 — live search results carry a `git` category the pinned expectation lacks. Red in the LANE WORKTREE at `9a0c569eb`+lane picks; the test file is unchanged since the rename commit `e6567b98c`; the full lane 2-pick diff adds ZERO `git` category surface (grep 0). Some earlier weld grew a git settings category without re-deriving this pin. Goes to the lead as a finding; not chased here.
- Wider doc/census filter (`DocFigureProseDrift|GlobDialect|ReadmeSuiteFigureDrift|EnvRoster|KeyBinding|SettingsWriterCensus|StderrEmitterCensus`): 567T/77723A/**3F** vs r1's 490T/75742A/3F — SAME red set (staleness pair ReadmeSuiteFigureDrift ×2 + GlobDialectDifferentialTest, PathGlob docblock 310,245 vs derived 311,360, quoted from the test's own message, asserted from NEITHER by welder arithmetic). The +77T/+1,981A is merged-tree drift since lane base (C-EXT-6.c grew DocFigure arms + census rows in this filter), attributed, disclosed, not chased.

## Generators / web

`gen-settings-doc`, `gen-command-docs`, `gen-tool-docs` --check: all **up to date rc0**; `gen-protocol-schema --check` **up to date rc0** (lane is src/config only — protocol untouched). NO `weld: W34 regenerate` leg. Web/dist chain **UNOWED**, name-diff-proved: ZERO `src/Protocol/*` and ZERO `candy-code-web/*` paths in the 2-pick range.

## §7 HANDOFFS (welder never edits these files)

- `scripts/parallel-tests-durations.tsv` row owed for NEW `candy-code/tests/Tools/Sandbox/BubblewrapHardeningTest.php` at live **16T/79A**.
- `candy-code/tests/Config/Support/suite-figure.json` + README headline staleness pair re-pin at battery carries this weld's +16T methods; GlobDialect PathGlob docblock re-derive stays battery duty.
- The screenReader roster rider above; the SettingsEditorTest git-category finding above.
- ~~REVIEW-1.md disk-absence~~ RESOLVED at commit time: artifact landed 07:51:28 (16,210 B) after the
  opening forensic; committed in this weld beside REPORT.md + WELD.md. No re-dispatch needed.

## Absorption + cleanup

`git cherry master cc/C-TOOL-4.c`: no `+` lines (both picks absorbed; content-equivalence proven by
patch-id SAME). Worktree `/home/sites/cc-cmp-C-TOOL-4.c` + branch `cc/C-TOOL-4.c` removed after
proof. Siblings `cc/C-TOOL-4.f`, `cc/C-TOOL-4.h`, `cc/C-TUI-6.j`, `cc/C-MCP-5.b` untouched. §3 W34
row NOT flipped (wave-close duty; loops still running). NO push/fetch/pull performed.

