# C-SEC-7.c — round-1 fix report

Branch `cc/C-SEC-7.c` @ /home/sites/cc-cmp-C-SEC-7.c. Review verdict file
`lanes/C-SEC-7.c/REVIEW-1.md` is ABSENT on disk (r1 reviewer ran; findings
arrived via orchestrator wake only — recorded as harness event). Wake also
misrecorded the chain: real base is `4dd8c20ce` and the skeleton is
`2bc6bb703` (the cited `166156d15` does not exist in this repo; completion
`873354b78` verified). Disk truth honored throughout; lane REPORT.md seam 1
names the same path I fixed — the wake's `src/Prompts/...` spelling is wrong,
the section lives at `src/Context/Sections/`.

## MAJOR — PlanModeSection body sentence false by construction — FIXED
- `candy-code/src/Context/Sections/PlanModeSection.php:53` (BODY): "The tool
  list is the same in every mode, so a tool being offered does not mean it may
  run." → truthful replacement naming BOTH directions of the new reality:
  plan mode drops always-refused tools, keeps the mode's own `Write`/`Bash`/
  `PlanExit` even against a `Deny` rule, and outside plan mode offered still
  ≠ permitted (gate decides each call).
- Same file, class docblock "WHERE IT SITS" (was ":23-26"): "The tool catalog
  is NOT narrowed in plan mode, for the same reason…" was the same lie in the
  docblock; flipped to state the narrowing, the accepted one-break-per-switch
  cache cost, and the PROMPT_ENGINEERING.md carve-out. DeepSeek-harness
  antecedent kept as a decision record, not a present-tense claim.
- Pin: `candy-code/tests/Context/Sections/PlanModeSectionTest.php:55` —
  old single needle replaced by THREE truthful needles (not-the-same,
  drops-always-refused, keep-trio). Regression discipline proven: with the
  new test against the pre-fix source the run is RED (1F, names the
  not-the-same assert); against the fixed source GREEN (4T/21A). The genuine
  behavior pin remains `PlanModeToolRosterTest` (builder's REPORT claim
  re-verified on disk; the earlier brief's PermissionsIntro attribution was
  wrong — PermissionsIntroDriftTest never saw this sentence, its intro
  window ends at the first `---`).

## 2 NITs (cosmetic wording in REPORT/docs) — NOT RECOVERABLE
REVIEW-1.md absent; the wake carried only "2 NITs, cosmetic wording". No
residue found in any evidence dir (/tmp/opencode/**, prompt_kit lanes).
Applied what disk shows as the adjacent cosmetic truth-cluster: the
docblock/REPORT path spellings above are the only stale wording grep can
find tree-wide (`same in every mode` had exactly 3 sites: BODY, test needle,
none in docs — PERMISSIONS.md:488 already flipped by the lane commit). If the
r2 reviewer still sees the two NITs, restate them verbatim.

## Gates (cwd=candy-code, env -u LINES -u COLUMNS, -c phpunit.xml, plain pipe)
- `--filter 'PlanMode|Permission|EngineBackend|PromptPrefixByteStability|PermissionsIntro|ForeignAgentPresetWiring|SymbolCitation|DocFigure'`:
  2724T / 2F — both are the standing foreign roster reds, byte-identical
  complaints to the lane report (PermissionsIntroDrift 'Schedule' row;
  ForeignAgentPresetWiringTest:472 reader census). Zero new reds.
- `--filter 'GlobDialect|DuplicatedTestHelperDrift|DuplicatedDocBlockLine|ChildWallClock|SwallowingCatch|ReadmeSuiteFigureDrift'`:
  59T / 3F = staleness pair (25,537 vs 28,288 live — closeout re-pin duty)
  + `SwallowingCatchSrcCensusTest` proven pre-existing AT TIP with my files
  reverted (SkillTool.php::execute row owed by 8ad2de199 C-EXT-2.c —
  documented foreign standing red in C-EXT-2.d/WELD.md, P-TABS.g,
  C-SES-7.e reports). Not this lane; no catch touched.
- php -l clean ×2 touched files. No timers (E646), no glob-shaped literals
  (GlobDialect green inside the 59T run), no {@see} citations added
  (SymbolCitationDrift green in the 2724T run).

## Mutation proof (per wake instruction)
- Neutered the plan-mode filter arm in `EngineBackend::turnTools()`
  (`if (false && …)`): `--filter PlanModeToolRosterTest` → 11T / 4F (red as
  required). Restored from cp backup, md5 `8789e8012f4900107b502ae9c7e5ab75`
  equal to pre-mutation, re-run GREEN 11T.

## DUR carry (welder duty — lane forbidden these files)
- `candy-code/tests/Backend/PlanModeToolRosterTest.php` — durations.tsv row
  still owed (carried from the original completion commit; this fix adds NO
  new test file and modifies no test count: 4T in PlanModeSectionTest
  unchanged, needles only).
- suite-figure.json / README pair re-pin stays a §7 closeout duty.
