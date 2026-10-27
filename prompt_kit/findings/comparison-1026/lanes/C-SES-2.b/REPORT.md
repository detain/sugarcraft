# C-SES-2.b — LANDED, gates green. Commit c20c36f1d on cc/C-SES-2.b (never pushed).

Files (13): src/App/SessionPickerSearchMsg.php (NEW), src/Chat.php [chat:buildSessionPicker region untouched — edits in sanitizeSessionRows + runSessionListAction Filter arm only], src/Chat/Handlers/HandlesSessionSurface.php, src/Tui/SessionPicker.php; tests: Chat/SessionCommandTest, Tui/SessionPickerFilterTest, Tui/SessionPickerSlashFilterTest, Tui/SessionPickerGoldenTest + goldens picker-{40,60,80,120}.txt; NEW tests/Chat/SessionPickerDebouncedSearchTest.php (5T) — **gate duty: add durations.tsv row + suite-figure re-pin; forbidden to this lane**.

Gates (cwd candy-code, env -u LINES -u COLUMNS, plain pipe):
- `--filter 'SessionPicker|SessionCommand|HandlesSessionSurface|Session'`: 1724T/15095A OK (deprecations:2 pre-existing family).
- five-guard: 133T/15349A, ONLY the allowed ReadmeSuiteFigureDrift staleness pair (live 25,661 vs pinned 25,537).
- SymbolCitation|DuplicatedDocBlock|TreeWideGuardRoster|StderrEmitterCensus: 125T/39085A; 2F = TreeWideGuardRoster SelfUpdateTest chr(42)-glob legs — PROVEN INHERITED: fails identically at base 50ec2e702 (stash-verify); both files byte-untouched here.

Deviation (minor, no behavior): sugar-core Cmd has no tick-cancel, so every edit arms its own 300 ms tick; the query-stamp rule in HandlesSessionSurface gives exact debounce semantics anyway — any edit within 300 ms makes the prior tick arrive stale and no-op, so only the last keystroke's tick ever performs a store read (one read per pause, never per keystroke).

Notes/disclosures:
- SessionRow::withBodySnippet was NOT forced-dormant: the store already populates it (SessionStore.php:578 listSessionsRanked attaches snippet per FTS hit); the gap was surface-only. LIKE-degraded/plain pages keep snippet null → rankedLayout admission never fires, old filtering byte-kept (pinned in Tui + SessionCommand tests).
- rankedLayout admits store-ranked (non-null snippet) rows after fuzzy hits in bm25 arrival order; haystack() unchanged (body is the store's leg).
- Picker emits SessionListAction::Filter on every query-changing edit (typed char/space + backspace-to-nonempty); 'edit' KEPT for Escape, empty-backspace (leaves filter), and the Enter slash-refusal — pinned flips: SlashFilterTest line-65 'edit'→Filter IN-STEP. SessionListAction.php's Filter docblock now understates the case ("opened") — file outside brief claims → closeout doc-row.
- renderRow: faint italic snippet second line (fit-plain-then-style, ≤ box width); spend column width≥76, all-or-none reservation, SpendLedger::boundUsd spelling ('≥$' when unpriced). rowZoneLines keys FIRST line only (Renderer recordSessionRowZones matches per overlay line). window() is now line-budgeted; byte-identical when no snippets (all-cost-1 slice proof in comment).
- Goldens MOVED all four via UPDATE_GOLDENS=1 (never hand-edited): picker-40/60 +snippet line; picker-80/120 +spend column shifts + snippet line. Fixture a1 '$0.0123', b2 '≥$0.0500' + snippet.
- E646-clean: the 0.3 s const is a UI debounce on a local store read, docblock says so; no LLM call bounded. No keybinding/README row changes needed (no doc guard pins 'typing never touches the store'; SEARCH_LIMIT + rankedLayout + section comments truth-flipped in-step).
- SessionCommandTest: reflection pin on the PRIVATE page read replaced by visible-rows through buildSessionPicker→filteredSessions (both FTS and latched-LIKE polarities kept).

## Fixes (round 2)
Commit c7b7c17e3 on cc/C-SES-2.b (author/committer Joe Huss <detain@interserver.net>, both greps separate, zero Co-Authored; tree clean, never pushed).
- STEP-LOCAL MAJOR ONLY (query-narrowed picker rows strand after clear). SessionPicker gains `lastFetchQuery` (?string: non-null = loaded page is the FTS match set) threaded via new()/mutate(); set by the debounced re-read (`withFetchedRows(..., $msg->query)`, HandlesSessionSurface.php:173), cleared by browse/widened reads (Chat Filter arm + load-more keep the null default); `withReloadedRows` mirrors the reload's own bodyQuery (reloadSessionPicker reads with the live query). Escape-from-filter now reports Filter when the page was narrowed (SessionPicker.php filterKey), and the empty-query Filter arm's already-fetched gate is AND-ed with `lastFetchQuery() === null` (Chat.php:15013) — both clear paths re-read the widened page synchronously, bypassing the keep-gate.
- Pins: SessionPickerDebouncedSearchTest +2 tests — narrow→tick-apply→Escape and narrow→backspace-to-empty both assert bare-list (non-matching) visible again + un-narrowed stamp. Mutations: M1 gate-neuter→2F, M2 escape-arm revert→1F, M3 tick-stamp revert→2F; cp+md5 clean restores.
- NOT taken (per instructions): SessionListAction.php:44 docblock MINOR (out-of-scope, closeout carry); rowZoneLines NIT.
- Gates (cwd candy-code, linked, plain pipe): Session family 1726T/15114A/0F green (builder 1724T/15095A +2T/+19A); five-guard 133T only the staleness pair (25,537 pinned vs 25,663 live — closeout re-pin); TreeWideGuardRoster unchanged at the 2 inherited reds; goldens moved by nothing (SessionPickerGoldenTest inside the green family). New test files: none (durations untouched; suite-figure untouched).

## Weld note (lane-4 closeout)
- Session-family gate on welded tree: 1732T/15150A, 1F = RemoteBackendTest::testAnExistingSessionOpensFromItsSnapshotByUniquePrefix
  ("the server has no session <pid-shaped prefix>"), reproduced INDEPENDENT of this lane: red at 30d4f91cb (pre-weld wave base) and at eaf42bbfe (pre-pick), green/broken state identical — FOREIGN-inherited (pre-existing red at wave base), disclosed, NOT cured here per weld law. All other 1731 tests green.
