# P-TABS.e (W14) — REVIEW-2 (cure-review, round 2, read-only)

Lane: cc/P-TABS.e @ 3c173eb10 (amend of 8ec8bff21; base 5ed26cf92 = HEAD^ verified by rev-parse).
Verdict: **CLEAN — 0 CRITICAL, 0 MAJOR, 0 step-local MINOR.** All five r1 findings genuinely cured in the single amended commit; gates reproduce exactly.

## 1. Blob-verify (phantom-amend family) — PASS

- `git show 3c173eb10` exists; log master-era range 5ed26cf92..HEAD = exactly ONE commit; porcelain EMPTY; %ae and %ce greps separately = detain@interserver.net both.
- File set = exactly the 10 declared lane files (+1,241/−14 vs r1's +1,183/−14 = the cures). No suite-figure.json, no durations.tsv, no dist/, no protocol trio, no foreign paths.
- `git diff --diff-filter=A` vs base: exactly 3 new files — tests/App/TabScopedRoutingTest.php, BackgroundTabStreamingTest.php, TabCloseAndQuitTest.php. Welder durations duty unchanged (3 rows).
- Commit-blob greps (git show :path, not worktree reads): App.php:5575 `return [$this->recordAgentMessage($inner), null];` lives in handleTabScoped's parked branch, AFTER the three open-modal specials, BEFORE the generic `$model->update($inner)` route; BackgroundTabStreamingTest:96 `assertFalse(... 'tab:tab-1:code.runtime-notice-poll')` + :97 `assertTrue(... 'code.runtime-notice-poll')` pair present; TabScopedRoutingTest:184 ledger assert present.
- `git diff master..HEAD` initially looked enormous — the worktree's LOCAL master ref is stale (other welded lanes live on origin only); branch parent is 5ed26cf92 exactly. Not a lane issue; welder-side ref hygiene only.

## 2. MAJOR-1 (Chat.php withNoticeDrain docblock) — CURED, truth re-verified against code

New docblock (Chat.php :9261-9286) states: "The queue is workspace-global, not per-tab (every deck Chat shares one sink — C-TOOL-3.c): a row produced while its tab is parked surfaces in whichever tab holds the drain appointment at that moment, not necessarily the tab that produced it." The false per-tab-queueing sentences are gone (grep "stay QUEUED" / "surface the next time" = 0 hits tree-wide). ARCHITECTURE.md same commit: "exactly one tab drains the shared workspace's runtime notices at a time" — and I re-derived the one-owner invariant against the live code: armDeck seeds active=launch chat (armed by Bootstrap::chat()); handleOpenTab parks prior fg `withNoticeDrain(false)` (:5637) before arming target `(true)` (:5643); switchTab same shape (:5507/:5517); factory-built chats default flag false (:1487); mutate carries it (:8941); close routes through switchTab for the fg. No deck path ever leaves two true, none leaves zero while a deck is armed. Docblock, ARCHITECTURE, and behavior now agree. The field-docblock addendum (:1479-1485, "one post-launch writer … keeps the one-owner law by never appointing two at once") is likewise true.

## 3. MAJOR-2 (AgentMessageSentMsg parked route) — CURED, semantics + ordering pinned

- Base-ledger parity: the foreground arm at App.php:2917 is `$msg instanceof AgentMessageSentMsg => [$this->recordAgentMessage($msg), null]`; the parked arm is `[$this->recordAgentMessage($inner), null]` — same method, same unwrapped message object, same null Cmd. Exact parity.
- Ordering (before the generic park route) is structurally pinned: the generic branch RETURNS, so moving the arm below it makes it dead code for every input → `assertCount(1, $after->agentMessages)` at :184 reddens. Control-flow certain. (Live cp-mutation denied by this reviewer's permission map — same posture as r1 §4; FIX-1's red-before quote names exactly :184 "actual size 0", consistent.)
- New pin testRunKeyedAgentMessageFromAParkedTabReachesTheGlobalLedger asserts the full shape: ledger count 1 + agentId 'run-7' + foreground Chat identity === + parked deck model identity === (proving the row is NOT also fed to the parked Chat). Non-vacuity: without the arm the parked Chat silently swallows the msg (Chat has zero consumers — r1 verified), so the ledger leg is the only recorder.

## 4. MAJOR-3 (one-drainer vacuity) — CURED, discrimination re-derived

Gate re-read: Chat.php:18631 declares RUNTIME_NOTICE_SUBSCRIPTION iff `drainsRuntimeNotices && (inFlight || sink->hasPending())`, and FOREGROUND_ONLY_TAB_SUBSCRIPTIONS (content-pinned at BackgroundTabStreamingTest:103-105) does NOT contain the notice-poll id. Therefore mutating either park site withNoticeDrain(false)→(true) makes the parked busy tab (inFlight=true) declare `tab:tab-1:code.runtime-notice-poll` → the :96 assertFalse reddens. Builder's red-at-:96 claim verified line-exact. The paired positive :97 is non-vacuous by construction: the fresh fg tab is given a test-local NoticeSink with one recorded row (inbox->arm + record), so the raw id is observably declared without leaning on the process-global sink's ambient state; removing the :97 leg reddens nothing by design — it exists to keep the absence leg honest (both legs ride the same merged subscriptions object). Neuter check answered: the pair's discriminative power lives in :96 under the flag mutation, confirmed.

## 5. NITs — CURED

- NIT-1: pendingTabClose property docblock (App.php:600-607) now reads "the confirming press closes, switching tabs or arming a different target clears it early, and anything else lets it expire" — matches code (switchTab's mutate clears at :5529/:5543; different-target re-arm at :5685; expiry via TAB_CLOSE_ARM_SECONDS at :5682).
- NIT-2: ''-sentinel forward note landed at App.php:5640 above handleOpenTab's open() — two id-less fresh tabs briefly share '' until first-persist rekey; P-TABS.f record, no behavior change.

## 6. Gates reproduced (plain pipe, env -u LINES -u COLUMNS, cwd worktree candy-code, no PtyRunToolTest)

- `--filter 'TabScopedRouting|BackgroundTabStreaming|TabCloseAndQuit'` → **Tests: 19, Assertions: 106** OK — exact vs FIX-1 (was 18/97).
- `tests/App` directory → **OK (468 tests, 21269 assertions)** — exact.
- `--filter 'KeyBindingDrift'` → **222/1,800** — exact.
- `--filter 'LangParity|GlobDialect|DuplicatedTestHelperDrift|SymbolCitation'` → **44/20,260** — exact.
(The recurring "PHPUnit Deprecations: 2" = the standing foreign AccessorDeprecationTest pair, REVIEW-1 §4.)

## 7. Duty 6 — locale + carries

candy-code/lang/ contains ONLY en.php — the tabs.* additions (close_arm/name_new/quit_arm at :2393-2395) and the three keys.* description flips are en-only by tree shape; §6.3-h fleet-locale parity remains a welder/closeout carry as briefed. KeyBindingDriftTest's +43 legs reproduce green (222/1800), so the en flips ARE the registry descriptions (r1 §2a finding stands).

## 8. Welder notes (updated)

- Durations rows owed for exactly 3 new test files (unchanged).
- ReadmeSuiteFigureDrift staleness pair stands for the welder re-pin (lane adds +19T net across the three new files).
- Master has moved (EXT-2.e touches Chat.php) — the Chat hunk set is two localized regions (ctor docblock :1476-1488, +withNoticeDrain at :9261-9288); cherry-pick conflict watch unchanged.
- Nothing in this amend re-opens any r1 §2 conformance finding; r1's PARTIALs/carry list (Chat statics reset = P-TABS.g, lock-release pid-staleness, fg-only idle-compact substitution) ride unchanged.

VERDICT: CLEAN
