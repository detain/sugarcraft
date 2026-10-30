# C-SES-7.d FIX-3 (round 3) — VERDICT: DONE. MAJOR cured, NIT taken, erratum appended. Tip 04741cb7d (cat-file: commit).

## MAJOR (§1.8 family rosters) — CURED
`resumeCostWarnUsd` (Live-badged, read via UiSettings::float) added to BOTH rosters,
each immediately after `'doubleEscSeconds',` — the exact schema slot (InterfaceSettings
defines it between doubleEscSeconds and paletteMru):
1. `candy-code/src/Config/Settings/UiSettings.php:51` — KEYS list.
2. `candy-code/tests/Chat/ApplySettingsTest.php:346` — frozen sessionKeys() expect.

Rationale (per REVIEW-2-R2B): at weld, once the foreign first-offender `autoReviewScope`
is cured by its own lane, ApplySettingsTest's arms would name OUR key — misattributing
foreign drift to this lane. Behavior unaffected either way: Chat::applySettings forgets
unconditionally (src/Chat.php:7873). UiSettingsTest's KEYS⇒Interface+Live pin is
one-way, so the addition is safe (verified green below).

## NIT (lang order) — TAKEN
`chat.resume_cost.*` (3 rows) moved out of the pane./permission. wedge to its
ascending slot AFTER `chat.request.cancelled` (values byte-identical; now at
en.php:2233-2235). Note: REVIEW-2 said "after chat.provider.switched_model_saved";
the strictly-ascending position is one row later (request < resume_cost < serviceTier),
which still satisfies "after switched_model_saved". No guard polices the order;
lang guards re-run below prove nothing orphaned.

## Ledger erratum — DONE
Appended to the lane's own FIX-1.md (reachable at worktree root; byte-identical to
the lead's copy at lanes/C-SES-7.d/FIX-1.md — diff verified). Its "6 reds name
nothing resume-cost" is FALSE: both ApplySettingsTest reds offended via our key,
masked behind foreign first-offenders. (Root ./FIX-1.md stays untracked evidence,
house convention: never staged.)

## Gate results (cwd=candy-code, env -u LINES -u COLUMNS -c phpunit.xml, plain pipe)
- UiSettingsTest alone: **21T/109A OK** (the guard just satisfied — KEYS⇒Interface+Live holds with the widened list).
- ResumeCostEstimateTest|ResumeCostGuardTest: **21T/71A OK** (lane's own guards, unchanged from FIX-1).
- Combined UiSettings|ResumeCost*: 42T/180A OK.
- ApplySettingsTest: 14T/166A/F2 — BOTH reds are now purely foreign: arm#1 names
  `autoReviewScope` (foreign first-offender); arm#2's diff differs ONLY by three
  foreign keys (`autoReviewScope`, `requireReadBeforeWrite`, `diffStyle`), our key
  MATCHES at its slot (Actual 65 vs expected 63 = +2 foreign shift). PROOF: a
  throwaway in-place add of exactly those 3 foreign keys turned
  testTheSessionTierRefusesWhatItCouldNeverApply **green (1T/9A OK)** with our line
  in place; file restored, final diff = 1 line.
- Lang guards CliLangCatalogue|LangParity|ChatHostLangCatalogue: 18T/3465A/F1 —
  the lone F is the STANDING RED unused-key rows (names 6 foreign keys, ZERO
  resume_cost rows: chat./host. entries nothing asks for). All three of our keys
  stay asked-for; move orphaned nothing.
- Round-1 CRITICAL region (EngineBackend gate, ctor tail, keep-pin): NOT re-touched.

## Weld carries (§7 duties, NOT chargeable to this lane — listed per brief)
- suite-figure.json + README pair: **+21T** carry (ResumeCostEstimateTest 13 + ResumeCostGuardTest 7 + 1) — staleness pair stands red until closeout re-pin.
- `scripts/parallel-tests-durations.tsv`: **2 rows** — ResumeCostEstimateTest, ResumeCostGuardTest.
- The 3 foreign sessionKeys roster lines (autoReviewScope/requireReadBeforeWrite/diffStyle) belong to their own lanes' cures, not ours.
- No new test files this round (2 roster lines + 1 lang move); no extra durations row minted.

## Commit
04741cb7dbc5b60bbfc1da0789819d6d80e0dbd0 on cc/C-SES-7.d (base db117a763, no rebase) —
3 files, +5/−4. Author+committer both detain@interserver.net (separate greps = 1/1),
zero Co-Authored-By, no caliber, explicit-path staging.
