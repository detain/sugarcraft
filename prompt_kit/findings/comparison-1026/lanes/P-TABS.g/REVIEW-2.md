# P-TABS.g — REVIEW-2 (fix-round re-review + weld audit) — VERDICT: CLEAN (0 CRITICAL / 0 MAJOR / 0 MINOR; 3 NIT report-only)

Target: branch cc/P-TABS.g tip d8732079268397e9120cf06e91262bca6cf9a49c (r1's REVIEW-1.md at a1504e05e is committed history; this round re-reviews the FIX-1 amendment and the weld — written as REVIEW-2.md rather than overwriting REVIEW-1.md).

## 0. Brief-SHA discrepancy (harness note, not a repo finding)
The dispatch brief cited tip 28b98075b — object does not exist (cat-file fatal). On-disk chain is honest: a1504e05e (build, r1-approved) → FIX-1 amend → d87320792 (6638d6415..tip = exactly 1 commit, porcelain 0, %ae and %ce each detain@interserver.net verified separately, zero trailers, 15 files +1112/−43). Not a twin: single branch, FIX-1.md documents the amend. The lead's phantom hash was a transcription; WELD.md cites the correct full SHA.

## 1. Phantom-first — all true
WINDOW_STRIP_MIN_ROWS=12 at src/Tui/Renderer.php:81; strip gate at :711 (`deck !== null && !isDocked(Pane::Sessions) && rows >= 12`); Pane::Sessions case :29, label via Lang::t('tui.pane.label.sessions') at :268 (en.php:2662); OSC 2 path = App::withDeckEffects :5719 CoreCmd::setWindowTitle, only-on-change; windowList() list-vs-map fix at :5637 (`$tabRows[$row->id]` — SessionTabs::tabs() returns a 0-based list; naive $tabs[$key] string-index would read null ⇒ attention never lands — the fix is necessary at source, not cosmetic).

## 2. Declared-set discipline — PASS
15 files: 12 of the BRIEF's declared 14 (declared-not-touched MenuBar.php/SessionsPane.php correct — P-TABS.a already ships WindowStrip/SessionsPane; scope reduction, not violation) + 3 licensed in-step guard ripples (KeyBindingRegistryTest count, KeyboardHandlerTest 12/4560+histogram, PaneReverseCycleTest ring) from the sessions.toggle row and the 12-pane ring — REPORT deviations 2/11, endorsed. No suite-figure.json, no durations.tsv, no dist in the lane commit (welder duty honored).

## 3. Semantics — all pins present
Row-floor pin (11 rows: null ZONE_NEW + no PLUS row), dock stand-down pin, early-modal stand-down pin (testTheStripStandsDownWhileAModalOwnsTheFrame, TrustDialog at 120x30, real zone+absence asserts — FIX-1's cure verified on disk, 8th test). Attention: announce only Waiting/Unseen, only non-focused, booked once per state, ledger pruned to live keys (array_intersect_key); chips foreground-only, background entries attention-only; title buckets state-based (foreground Working = running). Click ladder longest-prefix + modal guard + win:a:/winx:a: deliberate no-ops. bandTop/zoneOrigin/dropTarget all carry the strip row.

## 4. Deviation ledger — all accepted (r1 adjudications hold; re-checked §6.1)
Plan §6.1/P-CTX.e ruling text is Context-pane-only ("Auto-dock the Context pane once on terminals ≥120 cols", plan:893/:895/:4119); Sessions auto-dock = deck-count≥2 trigger through the family autoDockKeepsChatReadable (≥80) veto. NOT inconsistent with §6.1 — different surface, design's own trigger. rekey title lag (deviation 9): bounded, key-independent buckets, self-heals at next choke — accept. App.php AgentStrip::items() (now :799) unscoped seam while src/Renderer.php:1838 uses itemsFor — disclosed, P-AGENT.b carry.

## 5. Non-vacuity — harness blocked (disclosed), mutation-reasoned from assertion text
All three disk-mutation mechanisms were denied this round: shell cp permission.rejected, edit/write tools cannot reach worktree paths (workspace-root bound). Reasoning grounded in real assertions read at source:
- M1 strip-gate neuter → 11-row render would register ZONE_NEW + paint PLUS_PLAIN → testTheStripStandsDownBelowTheRowFloor asserts both absences → RED.
- M2 dock leg removed → testTheDockedSessionsPaneReplacesTheStrip RED.
- M3 dedupe leg removed → testTheSameStateIsNeverAnnouncedTwice (`assertNull($cmd, '...not even the title')`) RED; book-nothing leg: testNotificationsOffAnnounceNothingButBookNothingEither RED.
- M4 revert id-keying to naive $tabs[$key] → attention reads null→Idle → fixture `assertSame(WindowAttention::Waiting, ...)` + ['tab-2'=>'waiting'] booking RED.
- M5 OSC2 emit removed → testOpeningASecondTabMintsThePlainTitleOnce deckTitle/titleSequence asserts RED; emit-always → its no-restatement leg RED.
FIX-1 additionally ran M1' (hoist strip above modals → exactly the new pin, 1F/8, md5-restored) while it had disk rights.

## 6. Gates reproduced (reviewer runs, pre-weld worktree, cwd candy-code, plain pipe, -u LINES -u COLUMNS, no PtyRunToolTest)
- Per-file: WindowStripShellTest **OK (8 tests, 182 assertions)**; TabAttentionTest **OK (6 tests, 26 assertions)**; WindowTitleTest **OK (4 tests, 17 assertions)** — FIX-1's 8/6/4 claim CONFIRMED; REPORT.md's 7/7/3 superseded (matches r1 MINOR-2).
- Superset 'WindowStrip|TabAttention|WindowTitle|PaneTest|PaneReverseCycle': **Tests: 197, Assertions: 3214** green (+2 pre-existing deprecations).
- Guard family (+DuplicatedTestHelperDrift|KeyBindingDrift): **Tests: 525, Assertions: 73369** green — three new files distinct-helper-law clean.
- tests/App tests/Commands: **OK (1374 tests, 29635 assertions)** — exact.
- Post-weld master (WELD.md, welder's runs): 228T filter green; 1374T with 1 FOREIGN-INHERITED red MenuBarLabelDerivationTest::testEverySlashOnlyRowReadsAsThisTable (C-AGT-6.d 'pr' row — diff-empty attribution proof accepted; report-only, same standing-red family as MenuBarTest).

## 7. Hazards
Zero proc_open/fork/timer additions (diff grep 0 — E646 clean, strip/OSC2 render-side). Glob-shaped: none (GlobDialect green). Denial-shaped literals: none in new tests (FIX-1 ran DenialPrefixRoster green). One type per file: 3 files × 1 final class. strict_types all.

## 8. Welder duty list — ALL ALREADY DISCHARGED (audit of 8d5e95c78)
tsv rows minted at 50164befb with the authoritative per-file sums 8/6/4 (independently confirmed); §4 full-hash ✅ on d489f425f; §3 W16 row untouched (1/10 of wave — next lead); git-cherry '−' absorption proof; worktree+branch pruned after proof; README Alt+S row + strip prose in-step; doc-truth "eight component renderers" flip present.

## 9. NITs (report-only, closeout carry)
1. lang/en.php:3487 comment names the deck choke "windowList/afterDeckChange" — no afterDeckChange exists (withDeckEffects); one-word comment truth fix, rides next lang-area touch.
2. Lead dispatch carried a phantom tip SHA (28b98075b); record the correct tip d87320792 in run-state.
3. REPORT.md durations sentence (7/7/3) stays historically wrong; WELD.md/this review are authoritative — no rewrite (history preserved).

## 10. Out-of-scope carries
§6.3-h en-only sweep for tui.pane.label.sessions + tui.window.title.*/attention.* + keys.sessions.toggle (LangParity tolerates en-only today); P-AGENT.b: win:a:/winx:a: chip handlers + App.php:799 unscoped AgentStrip::items(); suite-figure staleness pair → wave closeout re-pin.

**VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 MINOR. Lane fully absorbed (master d489f425f+50164befb+8d5e95c78 == branch-tip content for all 15 files); ship-ready; nothing owed by this lane.**
