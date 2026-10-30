# C-SEC-8.d round 1 — REVIEW-1

VERDICT: CLEAN — 0C/0M/2m/2n

Lane f1d679d3d atop master aa5ddf638 (loose-ref read; zero base drift, ONE commit, name-only = 8 files:
docs/HOOKS.md, lang/en.php, src/Chat.php, src/Host/SessionHost.php, 2 new tests, DocFigureProseDriftTest, REPORT.md.
No suite-figure/durations/dist/protocol files — P5 clean. cherry pick: conflict-free expected (branch IS master tip).)

## Priority checks (all PASS)
- (a) SPLIT verified: `submit(): array` @9876 keeps old signature/return shape (`array{0:Chat,1:?\Closure}`);
  `submitDraft(string $text)` is PRIVATE and called ONLY from the wrapper's two arms (:9901 fast path, :9921).
  Grep of ALL `->submit(` sites at tip (20 hits incl. 16 in Chat.php, Goal kickoff :2806, release loop :11365):
  every one hits the wrapper. Zero bypass. Fast path byte-identical ($invisibleRemoved===0 ⇒ submitDraft($text),
  no mutate — inputBuf untouched, exactly the old frame).
- (b) HOOKS.md:346 -> `submitDraft()` + DocFigure arm comment flipped IN-STEP; DocFigureProseDriftTest 92T/4152A
  GREEN at lane (derives the method name live). Swept docs/README for stale funnel-path cites: COMMANDS/
  ARCHITECTURE/SKILLS/SETTINGS mentions name the SEND SURFACE (still `Chat::submit()`) — coherent, no edit owed.
- (c) All-invisible edge coherent at both chokes: TUI consumes draft + notice as whole answer (no user row,
  inFlight false, pinned); serve persists notice (append/save/announce = the idle-advisory pattern beside it),
  then TurnTicket::refused with emptyPromptNotice + idempotency key. No half-state: no turn/queue/inFlight touched.
  Coherence NIT: post-refusal usability not re-pinned (see MINOR-2).
- (d) Queue-carries-stripped pinned (testTheMidTurnQueueCarriesTheStrippedBytesToo: queuedPrompts == ['and also this'],
  exactly one strip notice beside the queue notice). Deeper: Tab queueDraft enqueues RAW bytes, but the sole
  consumer releaseQueuedPrompts() re-enters via ->submit() — strip happens at actual send. Model never sees
  invisible bytes on any route. (NIT-1: wrapper comment could name that deferral.)
- P1 Sanitize used AS-IS: sugar-core untouched; master blob `stripInvisibleFormatting(string $s, ?int &$removed=null)`
  confirmed via git show master:sugar-core/...; tiers match (always 200B/202A-202E/2060/2066-2069/FEFF; joiners
  061C/200C/200D/200E/200F position-gated). php -r refused by permission map; joiner semantics read from landed
  pins: family ZWJ kept byte-identical BOTH chokes, Persian ZWNJ kept, `pay\u{200D}pal`→'paypal' count 1,
  RLO+ZWS→count 2 — all assertSame on the sent row, not vacuous.
- P2 attachments untouched at serve choke pinned (data byte-dirty, prompt clean, notice counts text only);
  TUI submit has no attachment surface (file path never crosses the choke). Command classify sees stripped text
  (safe direction: `/exi\u{200B}t` can no longer dodge the classifier). One pass, no double-strip (release
  re-strip is idempotent, notice only fires when removed>0).
- P3 exactly one notice per stripping send, assertCount(1) at both chokes; en.php key sorted
  (chat.model.usage < chat.notice.invisible_stripped < chat.notify.*, :2252).
- P4 save+announceTranscriptRow mirrors the idle-advisory shape exactly.
- P5 zero sprintf/error_log: StderrEmitterCensus green inside the 132T/23551A batch.

## Gates (lane tree, cwd=candy-code, plain pipe, env -u LINES -u COLUMNS)
- 2 new files: OK 12T/39A (matches builder exactly).
- DocFigureProseDrift: 92T/4152A green.
- DuplicatedDocBlockLine|ServedRuntimeNoticeDrain|GlobDialect|SwallowingCatch|LangParity batch: 43T/9173A 1F
  — sole F = SwallowingCatchSrcCensus (base-proven foreign, see below).
- StderrEmitterCensus|BootstrapLaunchFormatConstants|ReadPathCensus: 132T/23551A 2F — BOTH base-proven identical
  at aa5ddf638 main tree (same names, same counts).
- tests/Host full: OK 624T/4684A. InFlightInputQueue+GoalCommand+SessionSendDocuments+SendImages: 58T, sole F
  = LangCatalogue (base-identical; the 7 unused keys are byte-same foreign askers at lane — lane key NOT flagged).
- DuplicatedTestHelperDrift: 1F, SAME 4 roster rows at base and lane (OPEN/CLOSE/STUB_CANDIDATE/HEAVY_CHARS) —
  lane names in ZERO rows (its withDraft twin is byte-identical per guard law).

## Base-red confirmation (main tree at aa5ddf638, zero candy-code dirt, lane files absent)
ApplySettings ×2, ColdResumeFinishedAgent, PaletteSupersetDispatch, RouteTraitParity, ChatHostLangCatalogue —
6 of the builder's 7 re-proven RED at base ("CompactionBreaker" = AutomaticCompactionModelSummaryTest breaker
methods, shorthand, builder named no file — NOT re-proven individually). NEW foreign-inherited reds surfaced by
broader gates, base-proven, join the standing roster: SwallowingCatchSrcCensusTest (SkillTool.php::execute bare-
empty catch unrostered) + BootstrapLaunchFormatConstantsTest (PROJECT_TIER doc-page roster) + ReadPathCensus
(latter already known as W25-account per §4 weld note).

## Minors / nits (step-local)
- MINOR-1 tests/Host/SubmitInvisibleUnicodeTest.php:141 — `assertNotSame(TurnTicket::STARTED, ...)` passes for a
  QUEUED regression; TurnTicket::REFUSED exists (:41): use assertSame(REFUSED).
- MINOR-2 same test — post-refusal usability unpinned: add one follow-up `$host->submit('back to normal')`
  asserting STARTED (P4's "session stays usable — pinned?" is otherwise satisfied by code-reading only).
- NIT-1 Chat.php:9895 comment "every route the draft can take — dispatch, queue, steer" — Tab-queue defers the
  strip to release-submit; true in effect, could say so.
- NIT-2 Chat.php:9904 cites "plan C-SEC-8.d" in shipped src comment — matches local precedent (ISS-064,
  roadmap 1.C-3, audit 15b-28 in same files); no change asked.

## Weld carries
- Durations rows (re-measure at weld or accept w/ disclosure): Chat/SubmitInvisibleUnicodeTest 7T ~2.1s /
  Host/SubmitInvisibleUnicodeTest 5T ~1.9s (measured together: 12T/39A 4.03s).
- §7 figure carry: +12T (live enum); ReadmeSuiteFigureDrift staleness pair widens, closeout re-pin, NOT weld.
- Pick: branch at master tip — conflict-free expected; lang/en.php + Chat.php hot-file check vs any master
  move at weld (Chat.php region claims W28: handleDiffCommand/READ_ONLY lanes — disjoint from submit region).
