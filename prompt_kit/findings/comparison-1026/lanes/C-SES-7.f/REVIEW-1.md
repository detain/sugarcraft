# C-SES-7.f — REVIEW-1 (round 1, fresh reviewer)

Commit 31691640f479c47fb76dd07496327d997b8bfaae (parent e91eb1d479db9fd1b8f770f610a9376e26f9f823 — cat-file verified; %ae/%ce both detain@interserver.net, zero Co-Authored trailers). Branch cc/C-SES-7.f, single commit, tree at HEAD clean of weld-only files (diff --stat: 9 files; no suite-figure.json, no durations.tsv, no generated.ts, no schema json — trio-untouched claim VERIFIED).

## VERDICT: FIXED — 0 CRITICAL / 0 MAJOR / 0 step-local MINOR / 4 NIT

### Gates re-derived (worktree candy-code, plain pipe, env -u LINES -u COLUMNS)
- New files: `--filter 'AttachRemoteCommands|AttachForeign'` → **8T/74A** OK — matches claim exactly.
- Band: `--filter 'AttachRemoteCommands|AttachForeign|RemoteBackend|AttachResume'` → 27T/207A, 1F = RemoteBackendTest::testAnExistingSessionOpensFromItsSnapshotByUniquePrefix. (Builder claimed 26T/201A — arithmetic/filter-regex drift, NIT-3; same 1F.)
- `SlashDispatch|CommandMethods|ConformanceTest` → **52T/652A** OK ✓.
- Guard family `ChatHostLangCatalogue|LangParity|ServerProtocolDoc|DocFigure|ReadmeSuiteFigure|TuiLang|CliLang` → 129T/8964A, **3F** = staleness pair (live 27954 vs pinned 25537; 27954−8 = 27946 == builder's base figure ✓) + ChatHostLangCatalogue 6 dead keys (chat.startup.personal, chat.attach.pdf_pages, chat.effort.launch_unshaped, chat.permission.not_persisted, chat.permission.persisted, chat.serviceTier.launch_unshaped) — our new key NOT among them ✓.
- **Inheritance proved by main-tree filter run** at untouched 6742a2ad3 (this reviewer's method; gitignored cache only): RemoteBackendTest prefix 1F + AttachPagingTest 2F (same "the server has no session" root) and ChatHostLangCatalogue 1F all reproduce there; `git log e91eb1d47..6742a2ad3 -- RemoteBackend.php RemoteSessionHost.php RemoteBackendTest.php` = EMPTY, so batch-14 cannot mask lane causation → all 4 claimed-inherited reds are genuinely pre-existing. ServerProtocolDocumentationDriftTest green in worktree → SERVER.md flip is outside the generated table region; the flip prose is code-true (runsIn derives from hostCommand at CommandMethods.php::runsIn; clear-transcript/open-title-editor are exactly the payload-free kinds wireResult replays).

### Key judgments
(a) **Fold-at-completion is an acceptable disclosed choice, not a REACHABLE-GAP.** The design's test promise is "appears without re-attaching" — met; the design's own risk line ("deltas must not interleave; key by turnId") makes completion-gating the safe reading of "folds that buffer". Pinned non-vacuously: mid-stream assertCount(2) fires specifically if the done-gate is removed; the interleave test runs a real own-turn with a queued foreign prompt and assertSames the final history to exactly 4 rows (own streamed pair must NOT fold, foreign pair must arrive whole) plus an idempotent double-pump assert. turnId keying is load-bearing end-to-end: neutralizing the foreign-buffer route or the adoption unsets red that test.
(b) **Region law satisfied.** Chat.php hunks: @@11697 inside dispatchCommand (:11670–) and @@19385 inside subscriptions (:19173–) — exactly the [chat:dispatchCommand,subscriptions] claims; zero ctor/mutate/use-block/key-dispatch edits (inline FQNs). Sibling un-welded lane cc/C-AGT-4.b (Chat.php hunks at 1800/9102/9124/11926/12037/12047/13402/13424 — applyCommandResult/ctor/handleAgentsCommand) is textually DISJOINT from ours at the shared base; our HandlesSessionSurface RemoteFeedPumpMsg arm does not edit applyCommandResult, only calls it. Cherry-order safe.
(c) Exec-reply-not-tick-delayed: disclosed; design fixes the rows' grammar, not their cadence. Promise→pump-Msg path verified real (Cmd::promise/AsyncCmd pattern matches existing Chat sites; busy-refusal rejection arm resolves a Msg too).
(d) Window reload mid-foreign-turn: persisted rows ride the open() snapshot (pre-existing pageOlder chain) — only transient streaming bytes are lost; matches the disclosed "effects/results arrive with the next snapshot".
(e) open() fail-open catch arm (`function (): self { return $this; }`) untested — happy-path fetch IS pinned (test 1 asserts cache content). NIT-1, step-local: needs a hostile-server fixture to test; disclosure honest.

### Vacuity walk
Shell writes are DENIED in this reviewer's permission map (the cp-backup command itself was refused) → **static mutation-reasoning**, disclosing per brief: M-a neuter `=== 'server'` → submitAndSettle's `assertInstanceOf(Closure/AsyncCmd)` + exec-path content asserts red (3 tests); M-b remove done-gate → mid-stream assertCount(2) red; M-c neuter pump arm → fold asserts red. Builder ran all as real mutations with cp+md5 restores and honestly disclosed M2 (own-skip guard) as non-discriminating because the adoption-unset + isset() legs double-protect — verified by reading both legs; redundant belt, not a fabrication. Tests use real loopback Server + real sockets + real Chat; no fixture fakes, no hardcoded success. §1.9 clean (no glob-shaped or **Refused:**-shaped literals; token is 64-hex).
Conventions: strict_types, final, one type per file, bare accessor serverCommands(), Lang::t consumer same-commit, comments state WHY. E646: FEED_PUMP_SECONDS is an idle poll cadence with null-on-empty factory (no timer on any LLM/request path) ✓. Dormant code: none added; nothing deleted.

### NITs (none blocking)
- NIT-1 step-local: open() fail-open catch arm untested (above).
- NIT-2 step-local: a foreign turn that starts but never completes (server crash mid-turn) leaves its feedForeign entry permanently — bounded in practice by server turn serialization, but an unbounded-in-principle slot; a cap or prune-on-resync is a candidate follow-up.
- NIT-3 step-local: builder REPORT band figure drift 26T/201A vs 27T/207A measured (same 1F).
- NIT-4 out-of-scope: the 6 ChatHostLangCatalogue dead keys + 3 session.list-visibility reds are standing reds owned by their lanes/closeout, not this pick.

### Weld carries
- durations.tsv: rows for `tests/Server/AttachRemoteCommandsTest.php` and `tests/Server/AttachForeignTurnTest.php` (2 new files, else zero shards).
- suite-figure.json + README pair re-pin: +8T from this pick on top of the pre-existing era drift (live enumeration at tip 27,954; pinned 25,537 pre-dates W23+ welds — the battery/§7 owes the pair, not this lane).
- No protocol regeneration owed by this pick (src/Protocol/** untouched).
