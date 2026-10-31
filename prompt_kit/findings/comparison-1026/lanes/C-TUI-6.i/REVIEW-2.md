# C-TUI-6.i REVIEW-2 (round-2 closure review, fresh session)

VERDICT: CLEAN — C=0 M=0 MINOR=0 NIT=0

LANE /home/sites/cc-cmp-C-TUI-6.i tip 0471efde5 (4 picks on 288b81f11). Evidence read:
lanes/C-TUI-6.i/REVIEW-1.md (6,657 B) and FIX-1.md (12,414 B) — both exist on disk; FIX-1
carries its withdrawn-phantom section preserved under an explicit WITHDRAWN stamp, consistent
with the lead's registry note. No file contradicts the brief. ALL measurements below are on
the LANE tree (pre-e78d5dc14 lineage), so the foreign LoginSubcommand/DuplicatedTestHelper
legs are expected to persist there.

## Q1 MAJOR-1 — CLOSED
Row at tests/TreeWideGuardRosterTest.php:580-588 is in WALKS_A_DIRECTORY_THE_TEST_MADE (the
LICENCE map, not DECLARED_TREE_WIDE_GUARDS — that map's count stays 4, verified). Key/value
shape matches the derivation exactly: slash-relative file key + flattened token `scandir($dir)`
(consumed at :2012 via array_diff against unresolvedByFile; :2110 removal-half loop re-checks
in_array($expression, $unresolved)). Alphabetical (AgentPreset→Live→Worktree). Guard BEFORE
(REVIEW-1 reproduced): 17T/3,846A 2F naming AgentLiveRegistryTest AND LoginSubcommandTest in
both arms. AFTER (my run): 17T/3,847A 2F — both reds name ONLY foreign
`Cli/LoginSubcommandTest.php => glob($this->dir.'/*')` (:1165, :2022). The +1A is the licensed
loop iterating my row; testEveryLicensedResidueRowStillMatchesALiveWalkSite is GREEN, proving
the row is live and consulted (not dead).

## Q2 REFUSALS — both CORRECT
ScreenReaderModeTest has zero `__DIR__`/`__FILE__` (root-anchored scandir at :266 never enters
the domain; ROOT_ANCHOR :959 gates it), so a row there is not in consultedResidue (:2107) and
testEveryLicensedResidueRowStillMatchesALiveWalkSite would red it as dead — rostering it would
have BROKEN the guard. ApplySettingsTest:473 same structure (no root name anywhere in the file;
predates the e6567b98c rename), clean in derivation output. Leaving both is right; a wrong
rostering would indeed have been worse than the refused MINOR.

## Q3 MAJOR-2 — CLOSED
README.md:1441 now carries the `screenReader` reason clause (vimMode-adjacent, truth-derived
from the decorator-shed docblock); count word "eighty-six" at :1386 is coherent — the guard's
live userTierOnlyKeys() derivation == 86 and TrustKeyDocumentationDriftTest::testTheReadmeNames
EveryKeyNoProjectMaySet is green. Docs batch at tip: 84T/41,678A 0F (r1: 84T/41,665A 1F).

## Q4 156f0a5c7 VACUITY — none found; KEEP endorsed
Per-pin mutation reasoning (my permission map forbids the mutation harness, as r1's): memo-test
leg1 reds if the memo clause is deleted (hand edit would re-read → true); leg2 reds if forget()
is inert (held false serves past the reload); leg3 reds if UiSettings::fingerprint drops the
session tier (held true wins the session write). Bounds-pin reds under BOX_FIRST/BOX_LAST ±1
(U+24FF/U+2500/U+257F/U+2580 straddle both edges — sampled fixtures cannot see ±1); junction
pin reds under embedded-flatten AND under junction-null (separate legs); menuRow 1/1 pair reds
under any position-based marker shortcut. FIX-1's M1–M6 table claims exactly one named red per
mutation; consistent with my reading. No survivor. KEEP on merits: design-risks demands the
memo ("cheap per frame"), and the builder's writeConfig() calls forget() itself, so pre-pick
the memo had ZERO coverage — this pick is the r1 §1.5 regression-test law satisfied.

## Q5 SCOPE + BY-DESIGN BOUNDARY — CLEAN
1c366fd1b touches only the guard test; 0471efde5 only README.md; 156f0a5c7 only
ScreenReaderModeTest.php (+80/−0). Full-range name-only scan: no src/Renderer.php, no
src/Tui/Renderer.php, no Chat.php; helpers unwired (6.j owns wiring — design-tui.md:992
"No renderer wiring in this step", lane copy read). Forbidden set (suite-figure.json,
durations.tsv, dist, protocol trio, SERVER.md, generated.ts, plan_comparison.md) absent from
the diff entirely. Session-tier fold re-confirmed on the lane tree:
ApplySettingsTest::testTheSessionTierRefusesWhatItCouldNeverApply 1T/9A green — derived==pinned
order-equal, `screenReader` sole delta after `vimMode`, no foreign roster widened.

## GATES (lane tree, cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe, exact-class filters, no full suite)
- TreeWideGuardRosterTest: 17T/3,847A 2F (foreign LoginSubcommand leg only).
- Docs batch (TrustKey|ReadmeRoster|EnvRoster|SettingsSchemaDoc|ReadmeSettingsTierClaim): 84T/41,678A 0F.
- ScreenReaderModeTest|AgentLiveRegistryTest|ApplySettingsTest: 42T/294A 1F = the accepted foreign
  keepWarm; fold arm separately 1T/9A OK.
- Porcelain 0 before and after. PtyRunToolTest never matched by any filter.
