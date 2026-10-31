# FIX-1 — C-TUI-3.g round-1 review fixes (lane cc/C-TUI-3.g)

Base picks: 56d082b04 + 28df4f6db (untouched, no amends). Findings authority: REVIEW-1.md (C=0 M=1 MINOR=6 NIT=1).

## M1 (MAJOR REACHABLE-GAP) — FIXED, commit ca769cef4
Arm widened in the lane's own hot file: `candy-code/src/Chat/Handlers/HandlesRunControl.php`
now matches BOTH `FocusLostMsg` (kept — class is real, other hosts may deliver it)
and `BlurMsg` (the shape sugar-core InputReader:518 actually constructs on CSI-?O)
before the FocusGained arm. Each shape keeps its own `if ($msg instanceof ...)`
arm because RouteTraitParityTest::topLevelArms harvests exactly one class name per
`if (` — a `||` compound would hide BlurMsg from the census. sugar-core decoder
NOT touched (outside declared set, shared surface).
- `candy-code/tests/Chat/RouteTraitParityTest.php`: 'BlurMsg' row added to
  FROZEN_ARM_ROSTER in-step. Load-bearing proven: arm present + row removed →
  1F (union drifted from roster); restored green.
- `candy-code/tests/Chat/AwayRecapTest.php`: NEW pin
  `testTheBlurShapeTheDecoderDeliversArmsTheRecap` drives the PRODUCTION chain:
  update(new BlurMsg()) → assertNull (loss only stamps); reflection read of
  AwayTracker::$lostAt asserts the blur actually armed a float stamp (this is
  the leg that pre-fix could not pass); the stamp is then stretched past the
  threshold through the tracker's own stored clock (same time seam beAway()
  seeds) and the FocusGainedMsg regain fires the single paid recap through the
  real fold (uiOnly assistant row, 1 ask).
- RED-BEFORE proof: with the new test on the pre-fix arm (BlurMsg arm body
  removed from a cp backup), the test fails exactly at :93 "the blur armed an
  absence stamp — Failed asserting that null is of type float" (Tests: 1, F: 1);
  cp-restore md5-verified (276f52b9), green after.
  An earlier draft of the pin (immediate regain) was itself caught red-by-design:
  the fresh stamp makes elapsed ~0 s, so it proved nothing about the arm —
  corrected to the stamped-threshold chain before committing.
- GREEN-AFTER: AwayRecapTest|RouteTraitParityTest = 18T/109A OK (2 standing
  PHPUnit deprecations); AwayTrackerTest|CommandRegistryTest = 26T/182A OK.
- Commit verified same-shell: git cat-file -t HEAD = commit; %ae/%ce grep -c
  detain = 1 each; zero Co-Authored trailers; 3 files +50/-0.

## m1 (MINOR step-local) — FIXED, commit 78b3b97b7
`testAtTheSpendCapTheAutoReturnIsSilent`: cap reached (same settlement shape as
the typed-cap pin: maxCostUsd 0.0005, spend 0.001 via SideQuestionAnsweredMsg),
beAway(), FocusGainedMsg → assertNull + title->asked empty. The `chat()` helper
gained a passthrough `?float $maxCostUsd` parameter. MUTATION PROOF: deleting
only the `|| $this->spendCapReached()` clause (null-leg kept) reddens exactly
this test (:308, suite 15T/1F); cp/md5-verified restore, green.

## m2 (MINOR step-local) — FIXED, commit 78b3b97b7
Chose the failure-voice pin remedy over routing the arm through
RecapHostCommand::run(): run() adds the command echo row the auto path must
never write, and the class's own effect is `BtwHostCommand::call(...)` — the
arm replicates it with the identical constants. NEW
`failingTitleBackend()` (completeAsync throws RuntimeException 'title model
exploded') + `testAFailedAutoRecapLandsTheRecapFailureVoice`: beAway →
FocusGained → resolve → SideQuestionAnsweredMsg answer contains 'The recap
failed' + the reason; settled row uiOnly assistant, carries the recap voice,
and asserts NOT 'side question' (btw default). MUTATION PROOF: swapping the
arm's failureKey to 'host.btw.failed' → exactly 1 red showing
'_The side question failed: title model exploded_'; md5-276f52b9 restore green.
This pins the previously-unpinned failureKey half of the replication.

## FINAL GATES (lane tip 78b3b97b7, cwd=candy-code -c phpunit.xml, plain pipe)
- AwayRecapTest|AwayTrackerTest|RouteTraitParityTest|CommandRegistryTest:
  OK 46T/303A (split 16+9+4+17; +3 tests total, all in-file — NO new test
  files, so no durations row owed; suite-figure re-pin is welder duty as before).
- Doc-guard septet 'CommandDocsDrift|ReadmeRosterDrift|SettingsSchemaDocDrift|
  LangParity|DocFigureProse|RouteTraitParity|TrustKeyDocumentationDrift':
  OK 150T/8442A — byte-identical to the reviewer's measurement.
- ApplySettingsTest: 14T/168A/1F — foreign keepWarm (message names
  promptCache.keepWarm; base-proven §Q7 rider m5).
- KeyHelpTest: 63T/96505A/5F+1 risky — foreign, base-identical (§Q7 rider m6).
- 2 PHPUnit deprecations throughout = standing tree-wide.
EXPECTED RED SET CONFIRMED: only the two foreign families. Nothing else moved;
riders m3/m4/n1 untouched as instructed.

## COMMIT LEDGER (branch cc/C-TUI-3.g; builder picks untouched)
- ca769cef4 M1: src arm-widen (FocusLostMsg + BlurMsg separate arms for the
  per-if census harvest) + roster row + production-shape pin. 3 files +50/-.
- 78b3b97b7 m1+m2: tests-only +70/-1 (one file).
Both: git cat-file -t = commit (same-shell), %ae/%ce separate greps = 1/1
detain@interserver.net, zero Co-Authored-By, explicit-path commit -o, tree
clean. sugar-core decoder NOT touched; forbidden files NOT touched
(diff name-list: 3 lane files only). vendor used as seeded; no composer/npm.
