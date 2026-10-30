# C-SES-7.d — REVIEW-3 (independent fresh round-3; tip 04741cb7d cat-file-verified; master read at lane-diff base)

VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR. Roster cure complete and correct (both §1.8 family rosters, right slot, every doc row moved); both ApplySettingsTest reds verified on disk as purely foreign; round-1 CRITICAL and round-2 MAJOR regions intact at the new tip; roster pins proven non-vacuous by mutation-reasoning. READY TO WELD.

## 1. Roster cure — complete
r3 (04741cb7d, 3 files +5/−4: UiSettings.php, ApplySettingsTest.php, lang/en.php — Chat.php untouched):
- `src/Config/Settings/UiSettings.php:51` `'resumeCostWarnUsd',` after `'doubleEscSeconds',` — KEYS is the RIGHT roster: the key is Live-badged and read via `UiSettings::float` (Chat.php:20711), and arm#1's `$handled` spread (ApplySettingsTest:319) is exactly `UiSettings::KEYS` + CompactorConfig + odds; UiSettingsTest::testEveryHeldKeyIsALiveInterfaceKey (:80) is one-way KEYS⇒(Interface+Live) — an addition cannot redden it (verified green).
- `tests/Chat/ApplySettingsTest.php:346` frozen `sessionKeys()` — the pin for the schema-derived list (`SettingsWriter::sessionKeys()` walks `SettingsSchema::all()`, src :460; NOT KEYS), so the frozen array must carry every Live/NextTurn layered key in schema order. Slot correct.
- No OTHER family roster is owed: `LayeredSettings::LAYERED_KEYS` (:627) + `PROJECT_TIER_KEYS` (:925) rode the wire commit; README layered roster + word-numbers (thirty-eight→thirty-nine), SETTINGS.md reader table (:306), full-schema row (:1325), apply-live row + "forty→forty-one live", session-only list, env-split list (:1801, our row `—` env — no EnvRoster duty). Greps: resumeCostWarnUsd lands in ALL rosters/docs; nothing missing.
- Guards I ran: `TrustKeyDocumentationDriftTest|SettingsSchemaDocDriftTest|ReadmeSettingsTierClaimTest|ConfigWriteProducerDocumentationDriftTest|LayeredSettingsTest|SettingsSchemaTest` → 197T/3773A OK. `DocFigureProseDriftTest|EnvRosterDriftTest|KeyBindingDriftTest|ReadmeRosterDriftTest` → 364T/46207A OK. `UiSettingsTest` 21T/109A OK. `MutateCarryCensusTest` 4T OK.

## 2. ApplySettingsTest reds — purely foreign (my own read, junit /tmp/opencode/ses7d-r3-apply.xml)
14T/166A/F2. arm#1 message: `autoReviewScope is badged live but nothing applies it` — names NO resume-cost key. arm#2 diff: Expected−Actual vs Actual−Expected — Actual-only keys are exactly `autoReviewScope`(29), `requireReadBeforeWrite`(50), `diffStyle`(68); `resumeCostWarnUsd` appears on BOTH sides (`-63`/`+65`, position shifted by the two earlier foreign inserts, value-and-order-slot identical). Fixer's claim VERIFIED, not taken. The foreign trio belongs to their lanes' roster cures.

## 3. Round-1/2 regions intact
Gate `if (!$this->backend instanceof Backend\EngineBackend) { return null; }` first statement of `resumeCostHold()` (src/Chat.php ~:20693); ctor tail param `?bool $resumeCostGuardArmed = null,` LAST before `) {` (:1846); sole production site Bootstrap.php:1369 all-named args; mutate carry row (:9192) + session-identity-choke re-derive (:9230); switchToSession arms true (:2503); submit guard (:10131) placed AFTER dispatchCommand (commands reach `/compact` while held — pinned); dispatchTurn consumes (`'resumeCostGuardArmed' => false`). r3 touched none of these (file list above). E646: pure arithmetic, injectable clock, zero timers; no fork/proc_open; one type/file; final + strict_types.

## 4. Vacuity (mutation-reasoned; no src edited)
- Drop UiSettings.php:51 → arm#1's reverse leg names `resumeCostWarnUsd` (latent behind the foreign first-offender today — precisely the weld misattribution §1.8 prevents; behavior unaffected: applySettings forgets unconditionally, Chat.php:7873). UiSettingsTest one-way arm shrinks silently (expected, direction read).
- Drop ApplySettingsTest:346 entry → arm#2 immediate: schema-derived sessionKeys() still carries it → lone `+65 => 'resumeCostWarnUsd'` Actual-only line → red names ours.
- Neuter the Chat gate → `testANonEngineChatNeverHolds` reddens: heavy-history EchoBackend + plain store (cold) + unpriced (threshold skipped) + default 0.25 → hold returns → `assertNotNull($cmd)` fails and the draft stays in the box; R1's empirical 10-casualty base/branch run is the same shape proven real.

## 5. Lang NIT cure — landed position is the STRICTLY correct one
en.php:2233–2235 after `chat.request.cancelled`: `provider < request < resume_cost < serviceTier` (`'q'<'s'` in req|res). The reviewer's prescribed slot (after `switched_model_saved`) would have been one row BEFORE `request` — out of ascending order. Values byte-identical (read of diff + grep). Lang guards: 18T/3465A/F1, the lone F = standing-red unused-key rows naming 6 foreign keys, grep of resume_cost hits in that failure = 0.

## 6. Rescan
Casualty+guard run: 102T/710A/F2 — AutomaticCompaction…UnarmsTheBreaker (rostered standing red) + ColdResumeFinishedAgentTest composer-row copy failure (`you → coder · now fix them`, file untouched by the lane, same failure R2B isolated as foreign). Lane guards: 21T/71A OK (13 estimator + 8 guard methods = the +21T). Scope note (not a finding, zero collision at tip): the block's `[chat:submit,resume/switch arms]` claim does not spell ctor/mutate/dispatchTurn, which the field addition provably requires (§2 names ctor as the field mechanism); r1/r2 reviewers accepted; W24's only other Chat claimant is C-CTX-5.b's ahead-of-need block — weld cherry order will settle text locality.

## Weld carries (§7)
- New test FILES this round: NONE (r3 = 2 roster lines + 1 lang move). The lane's 2 durations.tsv rows (ResumeCostEstimateTest, ResumeCostGuardTest) stand from round 1 — verify the gate adds them at weld.
- suite-figure.json + README pair: +21T carry (staleness pair standing red until closeout re-pin — not chargeable).
- Foreign trio (autoReviewScope, requireReadBeforeWrite, diffStyle) must land in ApplySettingsTest's frozen array + arm#1 via their own lanes before that pair goes green at weld — do NOT blame this lane if arm#1/arm#2 still red in the welded tree.
- Settings regen chain: lane's SETTINGS.md matches the generators (SettingsSchemaDocDriftTest green); weld's gen-settings-doc --write should be a no-op for our rows.
