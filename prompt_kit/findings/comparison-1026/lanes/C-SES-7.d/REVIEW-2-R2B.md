# C-SES-7.d — REVIEW-2-R2B (independent fresh round-2; tip db117a763, master dc9eea92d)

VERDICT: FINDINGS — 1 MAJOR (step-local), 1 NIT, 1 ledger erratum. Round-1 CRITICAL GENUINELY CLEARED (verified, not taken on trust).

## MAJOR — §1.8 family rosters not moved with the Live-badged key
`resumeCostWarnUsd` is badged `ApplyMode::Live` + read through `UiSettings::float` (src/Chat.php resumeCostHold) yet appears in NEITHER roster (my own grep -c: 0 in both):
- `candy-code/src/Config/Settings/UiSettings.php` KEYS (~:44-66) — ApplySettingsTest.php:319 spreads KEYS as the `$handled` set for its per-key Live-arm loop (~:332); today it aborts naming foreign `autoReviewScope` first, and the moment that lane cures the SAME arm names OURS — misattribution at weld.
- `candy-code/tests/Chat/ApplySettingsTest.php` frozen `sessionKeys()` array (~:346, lead read :429 pre-shift) — my run at tip ALREADY shows `65 => 'resumeCostWarnUsd'` in Actual while Expected lacks it (testTheSessionTierRefusesWhatItCouldNeverApply, F#2 of 14T/166A/F2).
Cure: add `'resumeCostWarnUsd',` after `'doubleEscSeconds',` in each of the two files. Safe: UiSettingsTest::testEveryHeldKeyIsALiveInterfaceKey is one-way (KEYS ⇒ Interface+Live — ours is both). NOT a behavior bug: Chat::applySettings calls UiSettings::forget() unconditionally (:7873), so the value does land live; the defect is guard-roster law + latent mis-blame. FIX-1.md's "the 6 reds name nothing resume-cost" needs an erratum: ApplySettings x2 offend via our key (masked behind foreign first-offenders).

## Round-1 CRITICAL — cleared (disk-verified)
- Gate: src/Chat.php:20693 `if (!$this->backend instanceof Backend\EngineBackend) { return null; }` ahead of any estimate (pre-fix at 3a7b8c511 passed null price → unpriced-fire for every non-engine chat — read, confirmed).
- Keep-pin `testANonEngineChatNeverHolds` non-vacuous: neuter gate → EchoBackend + heavy history + plain store (cold) + null price → hold fires → exactly that pin (+ the 10 old casualties) reddens.
- Feature NOT disabled: `testAStaleResumeHoldsTheFirstPromptWithTheCostNotice` runs EngineBackend+EchoProvider model `echo` (no rate on file) → unpriced `≥$0.25` hold asserted; warm seed (-90s) sends straight out → TTL arm live. Real audience (engine-backed cold resume, custom/unknown model) intact.
- Gates I ran myself (cwd=candy-code, plain pipe): estimator+guard 21T/71A OK; 6-file casualty+guard cluster 97T/685A/F:1 — the lone F is the rostered foreign AutomaticCompactionModelSummaryTest::testClearingTheTranscriptUnarmsTheBreaker; all 11 casualties green. ColdResumeFinishedAgentTest isolated: 5T/F1, failure is the agent-composer row ("you → coder · now fix them") — nothing resume-cost; foreign, consistent with the A/B disclosure.
- Ctor TAIL true: `?bool $resumeCostGuardArmed = null,` is the LAST parameter before `) {` (src/Chat.php:1846); mutate spreads named args (`new self(...array_merge($constructorProps,$changes))`); sole production site Bootstrap.php:1369 uses named args; no positional call site exists. Choke (Chat.php:9230) re-derives only on fresh-id routes; hold/dispatchTurn carry explicit false and $changes wins the merge — sound.
- E646 clean (pure arithmetic, injectable clock, zero timers); no new proc_open/fork; Lang::t rows present; README/SETTINGS/LayeredSettings/InterfaceSettings rows rode in-step (review-1 verified the doc family green, fix commit touched none).

## NIT (step-local)
lang/en.php: 'chat.resume_cost.*' (3 rows, :2194-2196) sits BETWEEN 'chat.pane.*' and 'chat.permission.*' — the chat.* block is otherwise ascending (permission/picker/pin/provider follow), so resume_cost belongs after 'chat.provider.switched_model_saved' (:2234). No guard polices chat.* order (CliLangCatalogue sorts its own scope; ChatHostLangCatalogue polices usage) — convention only. Cure: move the 3 lines.

## Weld carries (unchanged, not chargeable here)
durations.tsv 2 rows (ResumeCostEstimateTest, ResumeCostGuardTest); suite-figure.json+README pair +21T (staleness pair standing red); plus the MAJOR's two roster lines — fixer lane before weld.
