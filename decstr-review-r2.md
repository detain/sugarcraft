# DECSTR (CSI ! p) soft-reset widening — review r2 (strict, read-only)

- **Change under review**: branch `ai/w7-decstr-verdict` @ base `8ac6f596f`, workdir `/home/sites/sc7-decstr`, **uncommitted working-tree state**.
- **Files**: `candy-vt/src/Handler/ScreenHandler.php` (`softReset()` body + `softReset()`/`saveCursor()` doc-blocks), `candy-vt/tests/Handler/ResetTest.php` (DECSTR matrix + header).
- **Reviewer mode**: read-only. Nothing modified except this report. Verified the FINAL applied state (read the files at their live line numbers, not just the diff), so a predecessor's half-finished intent folded by a successor is judged as one artifact.
- **Authority independently reproduced**: every cited line opened in `/tmp/opencode/xterm-411/{charproc.c,cursor.c,ctlseqs.txt}` and `/home/sites/sc-briefs/ansicode.txt`.
- **Result**: **0 Critical, 0 Major, 1 Minor, 2 Nit.** The implementation is semantically correct against xterm-411, every citation reproduces exactly, the reset/preserve matrix is pinned in both directions, and all three r1 Majors (M1/M2/M3) plus m1/m2/m3/m5 and n1/n2/n3 landed and are sound. The one Minor is a single ambiguous doc-clause with a one-line fix.

---

## 1. Citation integrity — PASS (no fabricated or off-by-N cite)

Every DECSTR-relevant `charproc.c:` / `cursor.c:` / `ctlseqs.txt:` reference in the two changed files was opened at the cited line by me and matches. The brief asked me to re-derive these; results:

| Cite (as written in shipped files) | Actual at those lines | Verdict |
|---|---|---|
| `charproc.c:6154-6156` | 6154 `case CASE_DECSTR:`, 6156 `VTReset(xw, False, False);` | ✅ exact |
| `charproc.c:14358` | `ReallyReset(XtermWidget xw, Bool full, Bool saved)` | ✅ signature site |
| `charproc.c:14372-14375` | 14372 `if (saved) {`, 14373 `screen->savedlines = 0;`, 14375 `}` → DECSTR passes `saved=False` → scrollback kept | ✅ exact |
| `charproc.c:14377-14387` | 14377 `/* make cursor visible */`, 14378 `cursor_set = ON`, 14379 `InitCursorShape`, 14382 `cursor_blink_esc = 0`, 14387 `#endif` | ✅ exact |
| `charproc.c:14398` | `resetMarginMode(xw);` — above the gate | ✅ exact |
| `charproc.c:14410` | `resetCharsets(screen);` — above the gate | ✅ exact |
| `charproc.c:14432` | `if (full) {   /* RIS */` | ✅ exact (the gate) |
| `charproc.c:14449` | `TabReset(xw->tabs);` — indented **inside** the 14432 block | ✅ exact |
| `charproc.c:14546` | `CursorSet(screen, 0, 0, xw->flags);` — inside the full branch only; 14548 `} else {   /* DECSTR */` has no CursorSet | ✅ exact |
| `charproc.c:14559-14561` | 14559 `CursorSave(xw);`, 14560-14561 `sc[screen->whichBuf].row = ... .col = 0;` | ✅ exact |
| `charproc.c:1271-1272` | `initCharset(screen, 0/1, nrc_ASCII)` | ✅ exact |
| `charproc.c:1273-1274` | `initCharset(screen, 2/3, dft_upss)` | ✅ exact |
| `charproc.c:1259-1261` | `if (wide_chars && (utf8_mode||utf8_nrc_mode)) dft_upss = nrc_ASCII;` | ✅ exact |
| `charproc.c:1277-1279` | 1277 `curgl = 0`, 1278 `curgr = 2`, 1279 `curss = 0` | ✅ exact |
| `ResetTest.php:176` `charproc.c:1277` | curgl=0 (GL→G0) | ✅ exact |
| `ResetTest.php:235` `charproc.c:1279` | `curss = 0` | ✅ exact — **predecessor's 1278 error is corrected** in the final tree; a whole-file grep for `1278` now returns zero hits in either changed file |
| `cursor.c:419-423` | `CursorSave` → `CursorSave2(xw, &screen->sc[screen->whichBuf])` (per-buffer slot) | ✅ exact |
| `cursor.c:398-416` (n2 wrap_flag/curgr claim) | 402 `sc->saved = True`, **407 `sc->curgr`**, **408 `sc->wrap_flag`** | ✅ confirms both unmodelled members exist → the "no candy-vt counterpart" disclaimer is accurate |
| `charproc.c:14400` | `bitclr(&xw->flags, ORIGIN);` — precedes CursorSave, so `sc->flags` stores origin-off | ✅ (supports companion ordering) |
| `ctlseqs.txt:1479` | `CSI ! p   Soft terminal reset (DECSTR), VT220 and up.` | ✅ exact |

Cursor-shape paragraph (adjacent, unchanged by this diff but relied on): `charproc.c:10315-10317` (`InitCursorShape` macro recomputes from resources), `:569-570` (`cursorUnderLine`/`cursorBar` `Bres(...False)`), `:4949/4968-4988` (`CASE_DECSCUSR` writes `screen->cursor_shape`), `:4999` (writes `screen->cursor_blink_esc`) — **all reproduced**. ✅

Note on the brief's list: `charproc.c:1368` (`#define reset_tb_margins(screen) set_tb_margins(screen, 0, screen->max_row)`) is accurate at that line, but it is **not cited in either shipped file** (it was a reference inside the r1 report only), so it is not a diff citation to police.

**No citation in the change fails. No Critical/Major.**

## 2. KEY JUDGMENT — tab stops: PRESERVE is source-correct (brief was wrong)

I opened the exact lines the brief named. `charproc.c:14432` is `if (full) {  /* RIS */` and `charproc.c:14449` is `TabReset(xw->tabs);`, indented **inside** that block. `CASE_DECSTR` (6154) calls `VTReset(xw, False, False)` (6156), so `full == False` and the `if (full)` body — including `TabReset` — is never reached by DECSTR. **xterm-411 does not touch tab stops on a soft reset.** `ansicode.txt:601-602` lists DECSTR by name only ("Soft Terminal Reset") and enumerates nothing. The task brief's "cleared + defaults restored" premise has no source anchor and contradicts xterm-411. **Ruling: the implementation's decision to PRESERVE (overriding the brief) is the correct, source-backed behavior, and it is pinned both directions** (`assertArrayHasKey(5)` catches a wrong wipe, `assertArrayNotHasKey(8)` catches a wrong rebuild — ResetTest.php:198-199). Endorse fully.

## 3. Semantic correctness vs xterm-411 — PASS (item by item)

| Item | xterm evidence (self-verified) | shipped candy-vt | Call |
|---|---|---|---|
| Scrolling region → full | `resetMarginMode` 14398 above gate → `set_tb_margins(...,0,max_row)` | `scrollRegionTop=0`, `=buffer->rows-1` (1318-1319) | ✅ |
| Charsets → ASCII, GL → G0, SS dropped | `resetCharsets` 14410 → 1271-1279 | `charsets=[ASCII×4]`, `gl=0`, `singleShift=null` (1333-1335) | ✅ (G2/G3 nuance honestly described) |
| DECSC slot → home (real save) | `CursorSave` then `sc[].row/.col=0` 14559-14561 | `new Cursor(..., savedRow:0, savedCol:0)` (1344-1349) | ✅ `0` not `null` → `restore()`'s `savedRow ?? row` yields a genuine home save |
| Companions re-saved from post-reset state | `CursorSave` runs after origin-clear (14400), charset reset (14410), soft rendition clear (14549-14554) | reads live `sgr`/`charsets`/`gl`/`mode->originMode` at 1369-1372, all set earlier in the method | ✅ correct target + ordering |
| Alt/parked independence | `sc[whichBuf]` per-buffer (cursor.c:419-423) | writes only `generalSaved*`; never `parkedGeneral`/`saved*` aux | ✅ (see §4) |
| Tab stops preserved | `TabReset` inside `if (full)` 14449 | untouched | ✅ agrees with xterm |
| Scrollback preserved | flush gated on `saved` 14372-14375, DECSTR passes False | untouched | ✅ agrees |
| Line rendition preserved | DECSTR clears no cells (no `ClearScreen` outside full) | untouched | ✅ agrees |
| Visible cursor homed | xterm does **NOT** (only `CursorSet(0,0)` at 14546, inside full) | homes it | ✅ **DECLARED divergence — see next** |

**Visible-cursor divergence is disclosed honestly**: ScreenHandler.php:1225-1231 states "The one place we still differ from xterm is the VISIBLE cursor: xterm's DECSTR leaves it in place (only the `if (full)` branch calls `CursorSet(0,0)`, `charproc.c:14546`), while candy-vt homes it — a pre-existing choice." Confirmed at 14546/14548. Honest and prominent. (See N2 for a minor label tension in the RESETS bullet.)

## 4. The four `generalSaved*` writes — PASS

- **Correct target**: `generalSavedSgr/Charsets/Gl/OriginMode` (1369-1372) — the live GENERAL slot only.
- **Correct ordering**: placed after `sgr` (1321), `charsets`/`gl` (1333-1334) and the `mode` rebuild with `withOriginMode(false)` (1351-1356), so the slot captures post-reset defaults — verbatim what xterm's `CursorSave` at 14559 stores after 14400/14410/14549-14554.
- **alt/parked interaction clean**: `parkedGeneral` is written only by `parkGeneralCompanions()` (708) and `generalSaved*` nulled there; `saved*` aux fields only by `enterAltScreen()` (1616-1621)/cleared by `leaveAltScreen()` (1656-1659). `softReset()` writes none of them — confirmed by reading the whole method body (1306-1384). Matches xterm's `sc[whichBuf]` model.

## 5. Test matrix — both directions pinned; r1 gaps closed — PASS

Reset side: margins ✅, SGR pen ✅, charsets+GL ✅, singleShift ✅ (**M1 closed**, `testDecstrClearsArmedSingleShift`), cursor home ✅, DECTCEM/DECOM/DECAWM/wrap ✅, DECSC position ✅, companions incl. `generalSavedGl` via LS2 ✅ (**m3 closed**).
Preserve side: screen contents ✅, scrollback ✅, **tab stops both directions** ✅, line rendition ✅, **alt-screen active-slot + parked-main survival** ✅ and **main-screen no-clobber of aux stores** ✅ (**M2 closed**, two new tests).

- **Alt test (`testDecstrOnAltScreenReSavesActiveSlotAndKeepsMainParked`, ResetTest.php:245-270)**: non-vacuous and correct. Uses only public observables (no reflection). On an 8×8 grid (avoids the 5-row clamp for `CSI 6;6H` → saves (5,5)); asserts DECSTR keeps alt active, resets alt margins to 0..7, DECRC-on-alt → home (not the alt (3,3) save), and after alt-exit DECRC → main origin-ON + (5,5) — i.e. it would RED if `softReset()` clobbered `parkedGeneral` or wrote the wrong slot. Mutation 7 (parkedGeneral clobber) confirms sensitivity.
- **Main test (`testDecstrOnMainScreenLeavesAltScreenStoresUntouched`, 272-290)**: reflection null-checks on `parkedGeneral`/`savedCursor`/`savedSgr`/`savedCharsets` are justified (no public observable while on main) and premise-correct (these are alt-only fields). Ends with a live-slot DECRC→home assertion so it is not purely white-box. Not over-coupled to behavior — only to field existence, which is the point of the guard.
- **LS2 pin non-vacuous (criterion 5)**: `restoreCursor()` reads `$this->gl = $this->generalSavedGl ?? 0` (line 750). If only the `generalSavedGl` re-save (1371) were deleted, `generalSavedGl` retains the dirty save's `gl=2` (LS2 armed by `\x1bn` → `$this->gl = 2` at **ScreenHandler.php:657**, which I verified), so DECRC would restore 2 and `assertSame(0, $h->gl)` REDs. **Confirmed non-vacuous.**
- **No RIS/DECALN pin weakened**: `git diff` for ResetTest touches none of `testRisRestoresPowerOnModes`/`testRisClearsScreenButKeepsScrollbackRing`/`testRisResetsMarginsAndTabStops`/`testRisResetsCharsetsAndGl`/`testRisClearsSavedCursor` nor any DECALN test (diff-vs-keyword grep returns only header/DECSTR-region comment lines). `DecalnResetPinTest` + `CursorShapeAgreementTest` + `SyncOutputEraseTest`: **OK (35 tests, 162 assertions)**.
- **Mutation transcript independence**: each mutation ran a single-test `--filter`, so the transcript proves each pin is *sensitive* to its own behavior but does not by construction prove one-to-one mutual independence. By code reasoning the requested direction holds (a charsets mutation leaves `testDecstrResetsModesAndMargins…` green — it asserts no charset), and margins/charsets being asserted by >1 test (e.g. margins in both the primary and alt tests) is harmless redundant defense, not a gap. **Informational only — not a finding against the change.**

## 6. Stale-doc sweep (beyond `softReset()`) — CLEAN

Repo-wide (excluding vendor/.probe/r1/notes) for narrower-subset claims about margins/charsets/saved-cursor surviving DECSTR: **zero offenders** outside the two changed files.
- `docs/research/ansi-tmux-ansicode-audit.md:33` — **EXPECTED stale, supervisor-owned post-merge — not counted** (per instructions).
- `candy-vt/README.md:485-492` and `candy-vt/CALIBER_LEARNINGS.md:383-387` discuss only DECSCUSR *cursor shape* (which DECSTR correctly RESETS) — consistent, not stale.
- Root `CALIBER_LEARNINGS.md:37` says "DECSTR soft reset PRESERVES [per-cell line renditions]" — correct (line rendition is preserved).
- `ScreenHandler.php:67-68` (phantom-wrap "Cleared by ... RIS/DECSTR") — correct.
- `docs/repo_map/*.md` ("DECSTR not implemented / TODO") describe the pre-port gap analysis, not our reset matrix — out of scope for this semantic.
- `saveCursor()` doc-block (**M3**) rewritten (682-689): DECALN preserves the slot, DECSTR overwrites with home + re-saves companions, citing 14559-14561 — the one actively-misleading sentence from r1 is gone.

## 7. Verification (executed)

| Gate | Command | Result |
|---|---|---|
| Syntax | `php -l src/Handler/ScreenHandler.php` | `No syntax errors detected in src/Handler/ScreenHandler.php` |
| Syntax | `php -l tests/Handler/ResetTest.php` | `No syntax errors detected in tests/Handler/ResetTest.php` |
| Focused | `vendor/bin/phpunit --filter ResetTest` | `OK (21 tests, 90 assertions)` |
| Neighbors | `vendor/bin/phpunit --filter 'DecalnResetPinTest\|CursorShapeAgreementTest\|SyncOutputEraseTest'` | `OK (35 tests, 162 assertions)` |
| Full lib | `vendor/bin/phpunit` (candy-vt) | `OK (896 tests, 12574 assertions)` — exact match to the transcript's final-sanity line |
| Surface | `git diff --stat` / hunk headers | 2 files only; all hunks within `saveCursor()` doc-block + `softReset()` doc/body |

## 8. Count-prose consistency (r1 m1) — RESOLVED

"four" always denotes the four buffer-geometry ITEMS {margins, charsets, DECSC slot, tab stops}; "three" always denotes the three that flipped to reset {margins, charsets, DECSC}. Sites agree: ScreenHandler.php:1229-1231 ("four … all four now matching xterm"), :1287 ("Those three are now reset"), :1330 ("all four GL slots" = G0-G3, orthogonal), :1375 ("the three resets above"), ResetTest.php:43 ("MATCHES xterm-411 on the four buffer geometry items"). ScreenHandler.php:684 "remaining three halves" is the pre-existing DECSC *save-halves* framing (SGR/charset+GL/origin → 4 fields), not a DECSTR count — no contradiction. **m1 fixed.**

---

## 9. Findings

### Minor

**m1 (r2) — MINOR — `candy-vt/src/Handler/ScreenHandler.php:1374-1375`** — the code comment reads: "Tab stops and the scrollback are DELIBERATELY untouched — and **unlike the three resets above this AGREES with xterm rather than diverging**". As literally parsed, "unlike the three resets above" characterizes those three as *not* agreeing with xterm, which contradicts the same block's own line 1231 ("all four now matching xterm") — after the widening, the three resets *also* match xterm. The recoverable intent is "unlike the narrower-subset behaviour those three items used to have," but the shipped wording admits the contradicting reading. The immediately following clause (1376-1381, "its TabReset sits inside `if (full)` … so DECSTR never rebuilds the stops") states the true facts, so no reader is actually misled about *behavior* — hence Minor, not Major. **Fix**: change the connective — e.g. "…and, like the three resets above, this AGREES with xterm rather than diverging:" or drop the comparison ("…and this AGREES with xterm: its `TabReset(xw->tabs)` sits INSIDE `if (full)`…"). Given this repo treats intra-doc-block claims as load-bearing (r1 M3 was a doc-block contradiction), recommend folding this one-word reword into the commit; it is not, on its own, a correctness or coverage blocker.

### Nit

**n1 (r2) — NIT — `candy-vt/tests/Handler/ResetTest.php:260`** — assertion message "…to the ACTIVE 8-row screen, not the main geometry" overstates what the `assertSame(7, $h->scrollRegionBottom)` proves: in that test both the main and alt buffers are 8×8, so a bug that read the main buffer's `rows` instead of the active one would still yield `7`. The value is correct; only the comment implies a discrimination the fixtures don't exercise. **Fix** (optional): either make the two buffers differ in row count, or soften the message to "…reset to the active screen's full height."

**n2 (r2) — NIT — `candy-vt/src/Handler/ScreenHandler.php:1233`** — the RESETS list header says "RESETS (**matching xterm-411's DECSTR**): SGR pen, cursor home + visible, …". Homing the *visible* cursor is the one declared divergence (xterm homes only in `if (full)`, line 14546). The divergence is disclosed in full 8 lines above (1225-1231), so this is honest overall — just a local label tension. **Fix** (optional): tag the bullet, e.g. "cursor home + visible (visible-on matches xterm; *home* is our divergence — see Design choice)".

## 10. r1 disposition (each prior item re-checked on the FINAL tree)

- **M1** singleShift DECSTR coverage → **CLOSED** (`testDecstrClearsArmedSingleShift`; mutation 5 RED; setter justification "only the 8-bit C1 arms it" verified against lines 383-384).
- **M2** alt-slot / parked coverage → **CLOSED** (two alt tests; mutation 7 RED).
- **M3** stale `saveCursor()` doc-block → **CLOSED** (rewritten, cites 14559-14561).
- **m1** counts → **CLOSED** (§8).
- **m2** G2/G3/UPSS prose → **CLOSED** (precise + reproducible cites 1271-1274/1259-1261).
- **m3** `generalSavedGl` pin → **CLOSED** + verified non-vacuous (§5).
- **m5** vacuous-DECRC hardening → **CLOSED** (live `\x1b[3;4H` inserted; fails for both no-op DECRC and old carry-through).
- **n1** indent → **CLOSED** (RESOLVED-DIVERGENCE paragraph aligned).
- **n2** `CursorSave` "whole sc[]" overclaim → **CLOSED** (qualified to "the state this port models"; wrap_flag/curgr disclaimer confirmed at cursor.c:407/408).
- **n3** originMode hardcode → **CLOSED** (`= $this->mode->originMode`, line 1372).
- **m4** / **n4** (audit doc, `.probe/`) → **DEFERRED to post-merge / commit hygiene** (per brief; `.probe/` still untracked — must not be swept into `git add -A`; audit:33 owned by supervisor). Not counted as r2 findings.

## 11. Recommendation

Ship the implementation. All three r1 Majors and every actionable Minor are closed and sound; the tab-stop PRESERVE decision is confirmed correct against xterm-411 (brief premise overridden on source authority); every shipped citation reproduces at its cited line; the matrix pins reset and preserve items in both directions; full suite green (896/12574). Fold the one-clause Minor reword (m1-r2) and the two Nits into the commit if convenient, and honor the deferred hygiene (delete/ignore `.probe/`, hand `docs/research/ansi-tmux-ansicode-audit.md:33` to the supervisor) before the single commit lands.

VERDICT: APPROVE
