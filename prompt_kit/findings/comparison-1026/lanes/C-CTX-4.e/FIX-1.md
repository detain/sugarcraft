# C-CTX-4.e — Round-1 review fixes (FIX-1)

Verdict: MAJOR fixed — every declared doc row landed or corrected, all three
lane-tripped guard reds cured in-step, DoD test file shipped with 5/5
mutation proof. Branch tip after this commit carries the fixes; two foreign
standing reds disclosed with base-proof (not this lane's roster).

## MAJOR — declared doc rows: verified, two were absent/stale, now landed

Per-row verdict (guard coverage was asserted-not-verified at round 0; all
guards now actually run green at tip — gate `OutputStyle|Rules|RuleLoader|
RulePathNudge|DocFigure|ReadmeRosterDrift|CommandDocsDrift|
CommandsTableTakesColumnDrift|RegistryTranslation|MemoryDocumentationDrift|
SessionHostCommands|KeyBindingDrift`: **922 tests / 9,306 assertions / 0
failures**, /tmp/opencode/ctx4e-fix/gate-final.txt):

- README.md command roster row (`commands:roster` markers): **LANDED** at the
  skeleton commit 39073913e (regenerated; ReadmeRosterDrift green).
- docs/COMMANDS.md surface-table row: **LANDED** at 39073913e (CommandDocsDrift
  + CommandsTableTakesColumnDrift green).
- docs/ARCHITECTURE.md :155 "dispatch arms for 62": **STALE — FIXED 62→63**
  (DocFigureProseDriftTest::testArchitecturePageCountersAreLiveOrDigitless was
  red at tip: live CommandRegistry::slashCommands() == 63).
- docs/SETTINGS.md "/rules lists every pack the loader found — both user
  directories … walks eight directories across three tiers …":
  **STALE-BY-LANE — FIXED**: pack-scoped wording ("every rule pack", "both user
  pack directories"), eight→ten with `user styles`/`project styles` listed, and
  the styles-orthogonality law appended (not packs, outside the /rules list,
  one-of-one select, reserved `off`, disabledRules naming a style inert, rides
  next turn, bare `/output-style` lists). No test pinned the old phrase.
- docs/MEMORY.md: **ABSENT — LANDED**: new `### Rule packs, rulebooks, and
  output styles` subsection (after the UTF-8-scrub paragraph, before
  `### @import`): three families × two native tiers, same `<user-rules>`
  fence, /rules any-number toggle vs /output-style exactly-one, frontmatter
  disabled stays out however selected, both session-only and effective from the
  next turn onward. MemoryDocumentationDrift green.
- tests/Context/OutputStyleTest.php (brief files.create + DoD): **ABSENT —
  LANDED**: 16 tests / 83 assertions covering one-of-one slot + blank-name
  refusal, unselected/selected/cleared splice delta as the assertion,
  switch-moves-only-the-style-fence (pack fence byte-identical), no-RulesState
  negative control, frontmatter-wins, disabled-set orthogonality at
  effectiveRule, walks-vs-load listing law, project-walk tier, registration
  parity row (handler/CommandArguments::Text/hostCommand/'[name|off]'), full
  host-command conversation (list/select/unknown exit-1 notice/arity/off/no-op
  off), self-disabled truthful row, and served parity — the two-directory
  same-name match rides user fence + project fence together, cleared by one
  off.

## Guard probes flipped in-step (the guards demand the decision, not abstention)

DocFigureProseDriftTest::testRuleLoaderAggregateArithmeticSurvivesItsConstants:
both 7-probes → **9** with truthful messages (five native walks including the
two styles walks, four INT-2 imports). The derived legs (MAX_FILES=64, product
576, +1 = 577, splice ceiling) pass live; the header prose in
src/Context/RuleLoader.php already read "9 x 64 = 576" from the build commit.

## Mutation proof (script /tmp/opencode/ctx4e-fix/mutate.py, cp+md5 restores)

M1 unselected-style-rides → 5 reds (the splice centre); M2 stateless-guard
removal → exactly 1 red (negative control); M3 matches-note neuter → 1;
M4 self-disabled honesty → 1; M5 off-noop → 1. All restored md5-clean.

## MINOR adjudications

- ContextPanel churn vs MCP-2.c @mcp hunks: **N/A** — `git diff 4dd8c20ce..HEAD
  -- candy-code-web builtin-commands | wc -l` = 0; zero web churn from this
  branch, nothing to re-gate.
- SettingsWriterCensusTest persistFast red: **FOREIGN STANDING — disclosed,
  not fixed.** Call sites blame to 0da9b5071 (C-PRV-3.b /fast) and 0273a6178;
  both ancestors of lane base 4dd8c20ce; lane diff touches no Settings file;
  the roster in the test never gained those rows → red at base by construction.
- ChatHostLangCatalogueTest::testEveryCatalogueKeyIsAskedForByChatOrHost (6
  unused chat.* entries): **FOREIGN STANDING — disclosed, not fixed.** Base
  proof: test file byte-identical base→tip; all six keys present in
  base en.php; the requesting emitters (StartupSummary.php:240,
  FileMentions.php, SavedRules.php) byte-unchanged by this range; the lane
  en.php delta is only the 19 used host.output_style.* rows. Belongs to the
  34ac69dfc/97113b210 roster lineage — welder carry: roster those emitters
  into sources() or de-key the rows, deliberately, in the owning batch.

## Merge-base / staleness for the welder

Branch base is 4dd8c20ce (weld-18b close) — merge-base with origin/master IS
4dd8c20ce, so the branch already carries batches 18a/18b; the spawn-cited
93d2f1996 is phantom (cat-file fails), lane chain is 39073913e + 6d7072876.
No rebase owed for 18b; 18c-d drift, if any, is the welder's pick-time call.

## Welder carries

1. **suite-figure.json / durations.tsv NOT touched here** (forbidden). New test
   file `candy-code/tests/Context/OutputStyleTest.php` (16T) needs:
   `scripts/parallel-tests-durations.tsv` row + `tests/Config/Support/
   suite-figure.json` + README pair re-pin via the standard cadence
   (hand-bump → green K=24 `scripts/parallel-tests.sh 24 --out <unique>`
   --against-json → merge-junit → refresh-suite-figure → README same run).
2. Runtime::standingSlot memo keys (projectRoot+disabled()+loader identity, NOT
   activeStyle()) — mid-session /output-style switch can serve a pre-switch
   slab until the next refresh; docs now state "rides from the next turn
   onward"; the memo-key fix belongs to the [runtime] owner, route separately.
3. The two foreign standing reds above, if origin still carries them at weld.

Author law verified on this commit: %ae + %ce greps separately → detain both,
no Co-Authored-By trailer.
