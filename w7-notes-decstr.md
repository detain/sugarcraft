# W7 Track C — DECSTR verdict — NEW session notes (inherited workdir)

Branch `ai/w7-decstr-verdict` @ 8ac6f596f, dirty: ScreenHandler.php + ResetTest.php, untracked `.probe/`, `decstr-review-r1.md`.

## Inheritance posture
Predecessor died mid-fix-pass after review r1 (CHANGES REQUESTED: 0 Crit, 3 Maj, 5 min, 4 nit).
r1 Majors: M1 singleShift pin, M2 alt-slot coverage, M3 stale saveCursor docblock. m1 counts, m2 G2/G3 prose, m3 generalSavedGl pin, m4 audit doc (NOT in behavior PR), m5 vacuous-DECRC hardening. n1 indent, n2 CursorSave overclaim, n3 originMode field-read nit, n4 .probe/ cleanup.
Diff review shows predecessor ADDED tests addressing M1/M2/m3/m5 and rewrote saveCursor docblock (M3). STILL OPEN on disk: m2 prose in test comment, n1 indent, n4 .probe, and the `curss` cite error below.

## Citation verification (done MYSELF against /tmp/opencode/xterm-411 — all EXACT unless noted)
- charproc.c:6154 `case CASE_DECSTR:` / :6156 `VTReset(xw, False, False);` ✅
- charproc.c:14358-14359 `ReallyReset(XtermWidget xw, Bool full, Bool saved)` ✅
- charproc.c:14372-14375 `if (saved) { screen->savedlines = 0; ... }` → DECSTR passes saved=False → scrollback preserved ✅
- charproc.c:14398 `resetMarginMode(xw);` (above gate) ✅
- charproc.c:14410 `resetCharsets(screen);` (above gate) ✅
- charproc.c:14432 `if (full) {  /* RIS */` gate ✅
- charproc.c:14449 `TabReset(xw->tabs);` INSIDE if(full) → **DECSTR PRESERVES tab stops** ✅
- charproc.c:14546 `CursorSet(screen, 0, 0, ...)` inside full branch only; :14548 `} else { /* DECSTR */` ✅
- charproc.c:14559 `CursorSave(xw);` + :14560-14561 `sc[whichBuf].row/.col = 0` → DECSC slot overwritten with home ✅
- charproc.c:1271-1274 G0/G1→nrc_ASCII, G2/G3→dft_upss; :1259-1261 UTF-8 forces dft_upss=ASCII; :1277 curgl=0, :1278 curgr=2, **:1279 curss=0** — ⚠️ new test comment cites curss at :1278 → WRONG, must be :1279 (FIX)
- charproc.c:1368 `#define reset_tb_margins(screen) set_tb_margins(screen, 0, screen->max_row)` ✅
- cursor.c:419-423 `CursorSave` → `CursorSave2(xw, &screen->sc[screen->whichBuf])` ✅ (per-buffer slot)
- ctlseqs.txt:1479 `CSI ! p   Soft terminal reset (DECSTR), VT220 and up.` ✅
- ansicode.txt:601-602 — DECSTR listed only by NAME ("Soft Terminal Reset"), NO subset enumeration → wave-5's "tab stops → cleared+default" claim has NO anchor in ansicode.txt and CONTRADICTS xterm-411. Brief OPEN-path told us to rebuild tab stops; SOURCE SAYS PRESERVE. Decision: follow source (preserve), pin both directions, document deviation in handoff + PR body. (ctlseqs 3456 note = DECSCL hard-vs-soft manual disagreement, unrelated to tabs.)

## Suspect found (pre-fix): testDecstrOnMainScreenNeverTouchesParkedAltSlot
`parkedGeneral` is `[?Sgr, ?array, ?int, ?bool]` (companions only — position half lives on swapped Cursor objects; nothing is parked while on main). Test feeds `?1049l, !p, ?1049h, ESC 8` and asserts `parkedGeneral[0] === 5` — parked[0] will be an Sgr object → expected RED. Run suite to confirm.

## VERDICT: OPEN (implemented in proposal). Four items vs xterm-411:
| item | master (pre-fix) | xterm DECSTR | proposal |
|---|---|---|---|
| margins | preserved 1..2 | RESET full screen (14398) | reset ✅ |
| charsets+GL/SS | preserved | RESET ASCII/GL0/SS0 (14410,1271-1279) | reset ✅ |
| tab stops | preserved | PRESERVE (14449 inside full) | preserved (keep-pinned) ✅ |
| DECSC slot | preserved (savedRow carried) | OVERWRITE home + re-save defaults (14559-61) | reset ✅ incl. generalSaved* companions |
| scrollback | preserved | preserve (14372-75) | preserved ✅ |

## Status log
- [x] Read brief, r1 report, full git diff of both files
- [x] Verified every charproc.c/cursor.c/ctlseqs cite from source (see table above; ALL exact)
- [x] PREDECESSOR CLAIM DISPROVEN: their tree was RED — 896 tests, 2 FAILURES (both new alt tests:
      (1) `6;6H` on default 8x5 grid → row clamps 4≠5; (2) parkedGeneral[0] is ?Sgr not savedRow int,
      and premise wrong: nothing parks while on main (aux saves discarded at alt exit)). Claim "OK 897/12631" FALSE on disk.
- [x] Isolation: ScreenHandler resolves inside /home/sites/sc7-decstr (refl probe, both vcr-linked + vt)
- [x] Vendor modes recorded: all-3 published (refresh-deps --status). vcr before-run pub: 910/7984 S14
      (wave-6 linked figure 910/7376 S14 — assertion delta = vendor-mode sensitivity, test count exact).
      freeze before/after: 333/720 W3 — EXACT match to wave-6; freeze does not depend on candy-vt at all
      (vendor = ansi/core/input/pty).
- [x] Fixes applied: curss cite 1278→1279 (real error); m2 test prose (UPSS/curgr precision + cites);
      n1 RESOLVED-DIVERGENCE indent 6→5; n2 "whole sc[] struct" overclaim → wrap_flag/curgr disclaimer
      (verified cursor.c:407/409 myself); n3 generalSavedOriginMode reads $this->mode->originMode;
      charsets block comment gained charproc.c:1271-1274/1259-1261/1277-1279 cites.
      Test1 rebuilt: 8x8 grid, staged asserts (alt margins 0..7 while isAltScreen, DECRC-on-alt→home,
      exit→parked MAIN (5,5)+origin restored). Test2 rewritten: DECSTR-on-main must leave
      parkedGeneral/savedCursor/savedSgr/savedCharsets NULL (refl) + live-slot DECRC→home.
- [x] AFTER-FIX vt suite: OK (896 tests, 12574 assertions) GREEN
- [x] vcr linked (MY-EDIT VISIBLE via reflection): OK 910/7964 S14 GREEN. freeze linked: 333/720 W3 GREEN.
- [x] No composer.json edits (git status shows only the 2 files + .probe + r1 + notes + handoff-later)
- [x] MUTATION PROVES 7/7 GREEN (transcript /home/sites/sc7-decstr/mutation-proofs.txt):
      1-margins→RED testDecstrResetsModesAndMargins…; 2-charsets→RED …CharsetsToAscii;
      3-decsc-pos carry-through→RED …SavedCursorToHome; 4-companions-delete→RED …SavedSlotCompanions;
      5-singleShift-delete→RED …ClearsArmedSingleShift; 6-tabs-WRONGLY-REBUILD→RED …KeepsScrollbackAndTabStops
      (keep-direction proof); 7-parkedGeneral-clobber→RED …OnAltScreen… . All restored byte-identical,
      restore-check GREEN each, final full suite OK 896/12574.
- [x] phpstan level-max (lib config) DELTA-ZERO: before=149 after=149, normalized identical (ps-before/after.json)
- [x] php-cs-fixer --dry-run: 0 of 2 fixable
- [x] guards: check-path-repos --no-lib-path-repos rc=0; check-child-lifetimes rc=0
- [x] consumer suites AFTER (linked, MY-EDIT VISIBLE via reflection): vcr OK 910/7964 S14; freeze 333/720 W3 (freeze: no candy-vt dep)
- [ ] Review r2 → iterate clean
- [ ] Commit single (folds predecessor — documented in notes), push, PR NO-MERGE
- [ ] Handoff with MERGE_SHA_PLACEHOLDER

## Decisions
- VERDICT: OPEN. Four-item table above. Tab stops: brief said "rebuild defaults" — xterm-411 source
  says TabReset is RIS-only (charproc.c:14449 inside if(full)); ansicode.txt:601-602 names DECSTR only,
  enumerates nothing. FOLLOWED SOURCE: preserve + keep-pin both directions. Deviation from brief premise
  documented here + will be in PR body and handoff.
- Commit strategy: ONE new commit on 8ac6f596f folding predecessor's proposal + my fixes (predecessor had
  no commit to amend). Author Joe Huss from repo config.

