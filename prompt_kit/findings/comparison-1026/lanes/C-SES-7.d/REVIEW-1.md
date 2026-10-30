# C-SES-7.d — REVIEW-1 (round 1, fresh generation)

VERDICT: FINDINGS — 1 CRITICAL, 1 MINOR(step-local), 2 NIT.

Provenance: prior generation's uncommitted work was destroyed by lead worktree
churn; this chain (9195b8bcc + 719b80e34 + 3a7b8c511, tip 3a7b8c511) was rebuilt
by the resumed builder. Tree clean at review; the 2 test files landed as
3a7b8c511 mid-review. No prior verdict file existed. Verified vs BRIEF content,
not prior claims.

## Stat line
    9195b8bcc C-SES-7.d: ResumeCostEstimate — pure function (skeleton)   src/Host/ResumeCostEstimate.php +119
    719b80e34 C-SES-7.d: wire resume-cost guard — Chat arm/hold/consume   README.md 6, SETTINGS.md 30, lang/en.php 5, Chat.php 132, LayeredSettings.php 2, InterfaceSettings.php 15
    3a7b8c511 C-SES-7.d: guard tests                                      tests/Host/ResumeCostEstimateTest.php 258, tests/Chat/ResumeCostGuardTest.php 258
    9 files, +808/-17 vs master 07e49e872

## (a) Scope
BRIEF set all present. Two extra files are in-step guard law, accepted, disclosed:
`src/Config/LayeredSettings.php` (+2 rows: LAYERED_KEYS + PROJECT_TIER, required by
`withLayered()`/`withProjectSettable()`; TrustKeyDocumentationDriftTest polices) and
`README.md` (settings:layered roster + "one hundred and thirty-eight→thirty-nine"
word-number, the BRIEF's "verify in-step" clause). No W24 co-tenant regions touched
(lang cli.session.import, README Subcommands block untouched; Chat hunks all C-SES-7.d).

## CRITICAL — branch tip is red vs base on 11 pre-existing tests
Chat::resumeCostHold() (src/Chat.php ~:20678) passes `$costPer1k = null` for any
NON-EngineBackend chat; ResumeCostEstimate::of() treats null price as "unpriced →
fire on any cold resume, threshold check skipped" (ResumeCostEstimate.php:96-99),
and plain SessionStore yields lastActivity=null → cold (fail-toward documented
default). Net: EVERY test Chat constructed as (non-empty history, real
currentSessionId, no sessionMint) — the derived-armed shape — now holds its first
Enter. Base run vs branch run, same filter (18 session-shaped tests/Chat files):
    base   (master 07e49e872): Tests: 243, Assertions: 1610, Failures: 1   [that 1 = AutomaticCompactionModelSummaryTest::testClearingTheTranscriptUnarmsTheBreaker, foreign base-red, verified, not charged]
    branch (tip 3a7b8c511):    Tests: 243, Assertions: 1555, Errors: 1, Failures: 11
Branch-caused casualties (10F+1E): ReadOnlySessionTest x4, RewindDraftHistoryTest x4,
RewindCommandTest::testARewoundContextReminderIsStillDeduplicated,
SessionChangeResetsTest::testCtrlTabIntoALargeSessionIsNotRefusedForTheThrashOfTheSessionLeft,
AutomaticCompactionModelSummaryTest::testTheDispatchedParkedTurnSavesACheckpoint (E,
"resolve(): Argument #1 ($cmd) must be of type Closure, null given" = held, not sent).
Cure (recommended): make the guard inert where no billable model exists — gate
resumeCostHold() on `$this->backend instanceof Backend\EngineBackend` BEFORE the
estimate (the `≥` unpriced branch then serves its real audience: engine-backed
unpriced models, production `--resume` with a custom/unknown model). Then re-pin
ResumeCostGuardTest (stale-hold cases use an EngineBackend double; keep one pin
that EchoBackend never holds). Alternative (rejected): naming resumeCostGuardArmed:false
across ~12 fixture sites dilutes every existing resume-shaped pin.

## Gate results at tip (targeted, cwd=candy-code, plain pipe env -u LINES -u COLUMNS)
- ResumeCostEstimateTest|ResumeCostGuardTest: OK — Tests: 20, Assertions: 65 (hermetic injected clocks; T0 fixed epoch).
- SettingsSchemaDocDriftTest|LayeredSettingsTest|ReadmeSettingsTierClaimTest|DocFigureProseDriftTest|ConfigWriteProducerDocumentationDriftTest|TrustKeyDocumentationDriftTest: OK — 243T/5356A (SETTINGS.md regen incl "forty-one live" flip rides; reader symbol Chat::resumeCostHold resolves — SettingsSchemaTest 46T/2552A OK).
- ReadmeRosterDriftTest|LangCatalogue family: 1F = ChatHostLangCatalogueTest::testEveryCatalogueKeyIsAskedForByChatOrHost — VERIFIED-AT-BASE (same 6 keys, none is chat.resume_cost), foreign, not charged.
- GlobFigureDriftTest|EnvRosterDriftTest|KeyBindingDriftTest: OK — 324T/44673A.
- MutateCarryCensusTest: OK — 4T/5A (carry-row parity policed).
- Chat cluster: CRITICAL above. php -l clean x6. "PHPUnit Deprecations: 2" = standing at base, not charged.

## Pins / law / vacuity (good parts, verified)
End-to-end: Bootstrap::chat() :1369 (`--continue`/`--resume`, non-empty history +
real id + null mint) → derived arm; switchToSession names armed=true; session-identity
choke re-derives on fresh-id routes (`/new` test pins), named-flag wins; consume in
dispatchTurn covers queued-release funnel; guard sits AFTER dispatchCommand so
/compact stays reachable (pinned); hold appends Message::notice (uiOnly — the
"never reaches the model wire" claim is true by construction, Message.php:331).
Every arm/hold/consume/re-derive/sub-string pin is load-bearing (mutation-reasoned;
dropped carry-row escapes guard tests but is policed by MutateCarryCensusTest).
E646: pure arithmetic, zero timers, no wall-clock anything. Trust/SSRF: no new I/O.

## MINOR (step-local)
Chat.php ctor docblock for `$resumeCostGuardArmed` sits between `$sessionMint` and
`$lastActivityAt` — parameter inserted mid-list AFTER `$sessionMint`: safe only
because every construction site uses named args (verified grep src/ + tests pattern);
append new params at list tail next time to keep positional-shape safety structural.

## NIT
1. Unpriced `≈`-rendering leg (Lang 'chat.resume_cost.priced') is never exercised
   end-to-end through Chat (arithmetic pins costUsd only) — a priced-notice pin
   belongs with the CRITICAL cure's EngineBackend-double re-pin.
2. `/branch` inherits an armed guard via re-derivation (non-empty history, null
   mint) — defensible (prefix-cache genuinely unknown) but undocumented in the
   field docblock; add one sentence.

## Weld-carry (do-not-charge, must land at weld)
- scripts/parallel-tests-durations.tsv: 2 rows (ResumeCostEstimateTest, ResumeCostGuardTest) — lane-forbidden file.
- suite-figure.json + README pair: +20T live-enum drift (staleness pair is the expected standing red).
- Do NOT merge until the CRITICAL is cured and the 18-file Chat cluster matches base (1 foreign red only).
