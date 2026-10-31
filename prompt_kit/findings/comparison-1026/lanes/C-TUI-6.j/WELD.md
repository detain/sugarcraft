# C-TUI-6.j — WELD.md (welder record; sole master writer)

Master before: `a4ed6a8f3` (weld: C-TOOL-6.c — run-state fold). Lane: `/home/sites/cc-cmp-C-TUI-6.j`, branch `cc/C-TUI-6.j`, base `9a0c569eb` — DEEP: predates the screenReader roster cure `00fedf519`, the C-TOOL-4.c/4.f/5.b/EXT-6.d/6.f/4.h/6.c welds, the W33 §3 flips (`89d5fe9c8`/`7e042da7b`) and the pairing cure `db1441a61`.

## Picks (explicit list read from the lane; NEVER a range; no -x per t646)
lane tip `c43c8e554f570c638771b1563bfde386d61938c0`; `git log --format='%h %s' 9a0c569eb..HEAD` at weld time:

| lane sha | landed sha | subject |
|---|---|---|
| c68ac29ae | 9fbbac04e | wiring: border shed ×7, glyph space-out, menuRow palette+permission, notifier SR→BELL, Chat BEL cue |
| b8b92b490 | 9e3a5079e | tests: ScreenReaderRenderTest (NEW) + ScreenReaderBellCueTest (NEW) + TurnEnd legs |
| afad9c717 | 631cb7d45 | NIT-1 fromConfig comment truth |
| 703a45998 | 46d3cb6b6 | BEHAVIOR CHANGE — overlay replaces clipped BODY band only; status bar + Agent View header survive |
| 12680b111 | 5dc8ceb85 | MAJOR-1 — four deletion-discriminating pins (legs i–iv) |
| c43c8e554 | c785f3ef8 | MINOR-1 — Agent View header splice pin (M3, cp+md5) |

Six cherry-picks, ZERO conflicts, ZERO hand-resolution. `git cat-file -t`=commit and `merge-base --is-ancestor HEAD`=OK for all six. Merged range `a4ed6a8f3..HEAD` name-only = EXACTLY the lane's 7 files (Chat.php, Renderer.php, Tui/Renderer.php, Tui/TerminalNotifier.php + 3 test files); zero `lang/en.php` (NIT-2 name-diff proof), zero `TreeWideGuardRosterTest.php`, zero docs/protocol/web/generated files.

## Content anchors verified on the MERGED blob (match r3's cites exactly)
`ScreenReaderMode::border` count 7 in Renderer.php; `glyph(` at Renderer.php:4427 (compaction) + :5849 (split-diff gutter); Tui/Renderer.php:1396 (review rule) + :2933 (dropdown walls); `menuRow(` palette :7100, permission $srOverhead :7683 + line :7684; agentHeader splice :2117; TerminalNotifier.php:79 `$notifier->mode === self::OFF && ScreenReaderMode::enabled()` → BELL; Chat.php sole `ScreenReaderMode::cue` :3060 (update side).

## Review loop (four rounds, honest)
- builder REPORT.md 8,056 B — two picks; TWIN-HAZARD incident (zombie re-poison of lane tree AFTER commits landed — lane history clean); deviations: zero `tui.sr.*` keys, SessionPicker not wired, overlay whole-frame swap (later superseded by ruling (c)).
- r1 REVIEW-1.md 11,262 B — `VERDICT: FINDINGS — C=0 M=1 MINOR=2 NIT=2`; MAJOR-1 = four unpinned legs (i)–(iv); MINOR-1 SessionPicker seam (LEAD RIDER); MINOR-2 overlay-drops-chrome needs ruling; NIT-1 docblock; NIT-2 tui.sr.* supersession; r1 executed NO mutations (cp denied — static trace).
- r2 REVIEW-2.md 14,987 B — `VERDICT: FINDINGS — C=0 M=0 MINOR=1 NIT=3` (final at tip `12680b111`; APPENDIX A: initial M=1 header struck at the four briefed blobs, superseded by the fifth pick which closed it); gates UNVERIFIED-BY-EXEC (vendor cp allowlist-denied); MINOR-1 = splice pin owed.
- r3 REVIEW-3.md 19,098 B — `VERDICT: FINDINGS — C=0 M=0 MINOR=1 NIT=2` + `WELD-ELIGIBLE: YES`; legs (i)–(iv) certified load-bearing via OFF-MODE counter-leg shapes; the RED-under-deletion sides are **UNCONFIRMED-BY-RUN at the reviewer level (mutation EXECUTION map-denied; r3's own label: static derivation; mutation NOT executed (map-denied))**. Builder/fixer-side EXECUTED proofs, cited from FIX-1.md: round-1 cp+md5 deletion runs recorded "ALL FIVE BY RUN, no UNVERIFIED-BY-RUN needed" — (c) 1F at :162, (i) 1F at :203, (ii) 1F at :216, (iii) 1F at :228, (iv) 1F at :247; round-2 M1→exactly 1F, M2→2F, M3 splice-deleted→EXACTLY 1F naming the new method, restore md5 2c13499e54b643815a30dd498b792a41.
- FIX-1.md 8,508 B — four fixer picks + gate figures as above.
- r3 MINOR-1 = dispatch-brief claim REFUTED: lane base predates `00fedf519` (LayeredSettingsTest screenReader roster cure) — welder MUST expect 0F on the MERGED tree; never cure in-lane. ✓ honored: no fold, merged run green (below).

## Merged-tree gates (welder's OWN runs, cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS plain pipe, anchored `::` except BARE for TWGR/DTH)
- ScreenReaderRenderTest:: 10T/57A · ScreenReaderBellCueTest:: 2T/4A · TurnEndNotificationTest:: 10T/22A · ScreenReaderModeTest:: 11T/54A — ALL EXACT vs r3.
- LayeredSettingsTest file-exact 33T/272A/0F GREEN; substring union `LayeredSettingsTest::` (= Config + Cli/Bootstrap classes, r3's 99T shape) **99T/409A/0F GREEN** — merged roster GREEN with master's `00fedf519` cure; the lane adds no user-tier KEY (zero lang keys by NIT-2/name-diff; zero LayeredSettings touches) and folds no cure.
- PAIRING-GREEN proof: `--filter 'BashSandboxTest|BubblewrapHardeningTest'` ONE process = OK 39T/190A GREEN (db1441a61 in force at merged tip).
- TreeWideGuardRosterTest BARE 17T/3905A GREEN (assertion drift +36A vs r3's 3869A — foreign welds added roster rows since; DISCLOSED, not chased); DuplicatedTestHelperDriftTest BARE 14T/204A EXACT; PaletteCaretHonoursHandoffTest:: 3T/18A EXACT.
- KeyHelpTest:: 63T/96506A, 5 failures + 1 risky = the ACCEPTED foreign set UNCHANGED-SHAPE (exact count pair incl assertions vs FIX-1 record).
- Generators: gen-command-docs / gen-tool-docs / gen-settings-doc `--check` all "up to date" rc0 → NO regen leg; protocol trio + web chain UNOWED (zero src/Protocol, zero candy-code-web, zero dist in range — name-diff).
- Accepted-PHPUnit shape: every run carries "PHPUnit Deprecations: 2" (the standing AccessorDeprecation shape — not lane-introduced).

## Mark count / DoD
- `^#### ✅` python-count: 303 → 304 after this mark (working tree == blob == 303 pre-mark).
- Own heading flipped at :3705 by TEXT: `#### ✅ 9fbbac04e 9e3a5079e 631cb7d45 46d3cb6b6 5dc8ceb85 c785f3ef8 C-TUI-6.j — …`.
- §3 W34 row NOT touched (wave-close duty); no foreign red cured (KeyHelp/keepWarm/… sets carried as-is).

## §7 HAND-OFFS (NEVER fix at weld)
1. durations.tsv rows for the 2 NEW files (ScreenReaderRenderTest.php live 10T/57A, ScreenReaderBellCueTest.php 2T/4A) + stale TurnEndNotificationTest.php row re-derive 8→10T (r3 §7 file list) — battery duty.
2. suite-figure.json/README pair: this weld's live share +14T (10+2 new-file + 2 TurnEnd methods; my live count — dispatch's ~11T estimate superseded by the file + run); pin-vs-live gap is era-wide (25,537 pinned vs ~29.1k live) — re-derive at battery, never from this mark.
3. COMPARISON.md SR cells (:3745-era family) + the dated "~15+~5 vs wired 2+2" menuRow-count §6 erratum rider + menuRow sole-position format note (r3).
4. reviewer scratch dir `/tmp/opencode/rev-6j-r3/` — LEAD-SIDE SWEEP still pending at weld; disclosed as dirt-not-fixed.
5. SessionPicker rider (r1 MINOR-1) stays lead-owned: deliberately OUT per ruling (b), lead §7 rider/erratum — named here, not fixed.

WARN NEXT WELDER: base-depth law re-proven — this lane's base predated two master-side cures; run LayeredSettingsTest + the pairing filter ANCHORED on YOUR merged tree (r3's 1F-at-lane-tip reading is base-local, not a stop signal), re-derive en.php unions and word-numbers merged, TWGR/DTH by BARE name, assertion drift with equal counts = disclose never chase.

Absorption proof + prune: `git cherry master cc/C-TUI-6.j` = six '-', NO '+' (patch-id exact — no content-proof needed); worktree `../cc-cmp-C-TUI-6.j` + branch `cc/C-TUI-6.j` removed after; survivors untouched: cc/C-TOOL-4.d, cc/C-TOOL-6.g, cc/C-TUI-6.c, cc/C-TUI-6.g, cc/C-WEB-5.c. No push/fetch/pull; commit law verified per-commit.
