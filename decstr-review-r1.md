# DECSTR (CSI ! p) soft-reset widening — review r1

- **Change under review**: branch `ai/w7-decstr-verdict` @ base `8ac6f596f`, workdir `/home/sites/sc7-decstr`
- **Files**: `candy-vt/src/Handler/ScreenHandler.php` (`softReset()` + doc-block), `candy-vt/tests/Handler/ResetTest.php` (DECSTR matrix)
- **Authority**: xterm-411 `/tmp/opencode/xterm-411/charproc.c`, `cursor.c`, `charsets`/`resetMargins` sites, `ctlseqs.txt` — every cited line opened and confirmed independently
- **Reviewer mode**: read-only. No file modified except this report. No composer run.
- **Result**: 0 Critical, 3 Major, 5 Minor, 4 Nit. The **implementation is semantically correct** (all three added resets verified against xterm, behaviour confirmed empirically); every Major is a verification/documentation-integrity gap, each with a small concrete fix.

---

## 1. Citation integrity — PASS (no fabricated or off-by-region cite)

Every `charproc.c:NNNN` reference in the two changed files was opened at the cited line.

| Cite (as written) | Actual content at those lines | Verdict |
|---|---|---|
| `charproc.c:6154-6156` "CASE_DECSTR → `VTReset(xw, False, False)`" | 6154 `case CASE_DECSTR:`, 6155 TRACE, 6156 `VTReset(xw, False, False);` | ✅ exact |
| `charproc.c:14358` "`ReallyReset()` with `full == False`" | 14358 `ReallyReset(XtermWidget xw, Bool full, Bool saved)` (definition); `VTReset` at 14566 forwards both args verbatim at 14568 | ✅ signature site |
| `charproc.c:14372-14375` scrollback gated on `saved` | 14372 `if (saved) {`, 14373 `screen->savedlines = 0;`, 14375 `}` | ✅ exact |
| `charproc.c:14377-14387` shared cursor block | 14377 `/* make cursor visible */`, 14378 `cursor_set = ON`, 14379 `InitCursorShape`, 14382 `cursor_blink_esc = 0`, 14387 `#endif` | ✅ exact |
| `charproc.c:14398` `resetMarginMode(xw)` | 14398 `resetMarginMode(xw);` — above the gate | ✅ exact |
| `charproc.c:14410` `resetCharsets(screen)` | 14410 `resetCharsets(screen);` — above the gate | ✅ exact |
| `charproc.c:14432` RIS-only `if (full) {` | 14432 `if (full) {                    /* RIS */` | ✅ exact |
| `charproc.c:14449` `TabReset` inside `if (full)` | 14449 `TabReset(xw->tabs);` — indented inside the 14432 block | ✅ exact |
| `charproc.c:14546` "only the `if (full)` branch calls `CursorSet(0,0)`" | 14546 `CursorSet(screen, 0, 0, xw->flags);` — inside `if (full)`; the `else` (14548 `} else {  /* DECSTR */`) contains no `CursorSet` | ✅ exact |
| `charproc.c:14559-14561` DECSTR overwrites save slot with home | 14559 `CursorSave(xw);` 14560-14561 `screen->sc[screen->whichBuf].row = screen->sc[screen->whichBuf].col = 0;` | ✅ exact |
| `charproc.c:10315-10317`, `:569-570`, `:4949-5005`, `:4968-4988`, `:4999` (pre-existing CURSOR SHAPE paragraph) | `InitCursorShape` macro; both resources default `False`; `CASE_DECSCUSR` span; writes `cursor_shape`; writes `cursor_blink_esc` | ✅ all confirmed |
| `ctlseqs.txt` "records DECSTR in a single line" | 1479 `CSI ! p   Soft terminal reset (DECSTR), VT220 and up.` | ✅ confirmed |
| `initCharset(…0/1, nrc_ASCII)` + `curgl = 0` + `curss = 0` | charproc.c:1271-1272 (`initCharset(screen, 0/1, nrc_ASCII)`), 1277 `curgl = 0`, 1279 `curss = 0` | ✅ confirmed — but see **m2** for the elided G2/G3 |
| `resetMarginMode` → full-screen rows | charproc.c:1381-1385 → `resetMargins` 1372-1378 → `#define reset_tb_margins(screen) set_tb_margins(screen, 0, screen->max_row)` (1368); `set_tb_margins` (1335-1349) touches no cursor state | ✅ confirms "resets to full-screen rows" |
| `resetCharsets` → G0/G1 ASCII, `curgl=0` | charproc.c:1271-1279; G2/G3 get `dft_upss`, which `1259-1261` forces to `nrc_ASCII` under UTF-8 | ✅ (see **m2** for prose precision) |

No citation in either file fails. **No Critical finding.**

---

## 2. MAJOR findings

### M1 — MAJOR — `candy-vt/src/Handler/ScreenHandler.php:1322` — new `singleShift` reset line has zero DECSTR coverage
`softReset()` now arms `$this->singleShift = null;`. Deleting that line leaves the **entire candy-vt suite green**: `grep -rn "singleShift" candy-vt/tests/` returns only `tests/Handler/DecalnResetPinTest.php` (lines 24, 58, 67, 81-113) — which pins the identical assignment for **DECALN only**. `ResetTest::testDecstrResetsCharsetsToAscii` (ResetTest.php:171) feeds `\x1b(0\x1b)0\x1b[!p`, never arming a shift, and `DIRTY` never arms one either. Note a *functional* probe cannot substitute here: because DECSTR also resets G2/G3 to ASCII, a surviving shift renders the same glyph — the state field is the only observable, exactly the argument `DecalnResetPinTest` makes in its own header ("pinned the only way that bites").
**Fix** — add to `ResetTest` (mirroring `testDecalnClearsTheArmedSingleShift`):
```php
public function testDecstrClearsTheArmedSingleShift(): void
{
    $h = $this->handler("\x8e\x1b*0");                       // SS2 armed onto G2 = DEC Special
    $this->assertSame(2, $h->singleShift, 'precondition: shift armed BEFORE DECSTR');
    (new Parser($h))->feed("\x1b[!p");
    $this->assertNull($h->singleShift, 'DECSTR must drop the armed SS2/SS3 shift');
}
```
(Verified: current code passes this; the state is `public ?int $singleShift` at ScreenHandler.php:140, so it is directly assertable.)

### M2 — MAJOR — `ScreenHandler.php:1351-1354` — the new DECSC-companion writes have no alt-screen coverage, despite a newly-written PRESERVES promise
The re-save writes `$this->generalSaved*`, which while on the alt screen **are the alt slot**, with the main-screen slot parked in `$parkedGeneral` (`parkGeneralCompanions()` 703-716, `unparkGeneralCompanions()` 718-726). This is exactly the xterm model — `CursorSave(xw)` writes `screen->sc[screen->whichBuf]` (cursor.c:419-423), leaving the other buffer's slot alone — and I confirmed the behaviour is correct by hand:

```
enter 1049h with dirty MAIN slot → DECSTR on ALT: altScreen=true top=0 bottom=2 origin=false
back on MAIN: content "MAIN" intact; DECRC → row=1 col=1 bg=red g0='0'   // parked slot untouched ✅
```

…but `grep -rn "isAltScreen" candy-vt/tests/` shows the only reset-side hit is `ResetTest.php:135` (RIS). Nothing in the suite would catch a future `softReset()` that clobbered `$parkedGeneral`, unparked it, or wrote the wrong slot. The diff re-wrote the doc-block that promises this (ScreenHandler.php:1236-1239 "…the alt screen…"; ResetTest.php:38 matrix row "alt screen | main screen | preserved") without pinning it.
**Fix** — add `testDecstrOnAltScreenLeavesParkedMainSlotAlone()`: dirty a DECSC slot on main (`\x1b[41m\x1b(0\x1b[2;2H\x1b7`), `\x1b[?1049h`, `\x1b[2;2r\x1b[?6h` on alt, `\x1b[!p`, assert `isAltScreen()` still true and `scrollRegionBottom === rows - 1`, then `\x1b[?1049l\x1b8` and assert row/col `1/1`, `sgr->background` still red, `charsets[0] === '0'`.

### M3 — MAJOR — `ScreenHandler.php:684` — stale doc-block invalidated by this diff (wrong in the direction that invites regression)
`saveCursor()`'s doc-block still reads: *"The position half rides on the Cursor value object (`savedRow`/`savedCol`), so **the slot survives DECSTR**/DECALN exactly as before"*. After this change it no longer survives DECSTR — `softReset()` overwrites it with home (1330-1335). This is precisely the "stale DIVERGENCE claim for an item now matching xterm" that review criterion 6 targets, and it is the one sentence in the file that would actively persuade a future reader to restore the old carry-through.
**Fix** — reword to: *"…rides on the Cursor value object (`savedRow`/`savedCol`): DECALN carries it through, DECSTR overwrites it with home — xterm's `CursorSave` + forced `sc[whichBuf].row/.col = 0` (`charproc.c:14559-14561`)."*

---

## 3. Minor findings

### m1 — MINOR — `ResetTest.php:43` / `ScreenHandler.php:1226` vs `:1280` / `:1357` — "four" vs "three" item count contradicts itself
`ResetTest.php:43` claims DECSTR "MATCHES xterm-411 on the **four** buffer geometry items", `ScreenHandler.php:1226` says "orthogonal to the **four** buffer-geometry items", while the enumerations in both places count three (margins, charsets, DECSC slot) and `:1280` says "Those **three** are now reset to match", `:1357` "the **three** resets above". The change actually introduces four *writes* (margins, charsets/GL/SS, saved position, saved companions), so neither number is defensible as written.
**Fix** — standardise on four and name them: "the scrolling region, the character-set designations (GL/SS), the DECSC saved position, and its rendition companions". Update all four sites.

### m2 — MINOR — `ResetTest.php:174-175` — prose overstates what `resetCharsets()` does at the cited line
Test comment: "xterm's DECSTR runs `resetCharsets(screen)` (charproc.c:14410) above the RIS-only gate, so **G0..G3 return to ASCII** and GL to G0." charproc.c:1271-1274 sends G0/G1 to `nrc_ASCII` but G2/G3 to `dft_upss` = `PreferredUPSS(screen)` (1243-1245), forced to ASCII only under UTF-8 (1259-1261); 1278 also sets `curgr = 2`, which candy-vt does not model at all. The **code** (all four `Charsets::ASCII`) is right for this UTF-8-only port; the citation-adjacent prose is imprecise in a repo that treats cite fidelity as a hard rule.
**Fix** — "…so G0/G1 return to ASCII and G2/G3 to the UPSS default — ASCII in this UTF-8-only port (`charproc.c:1271-1274`); xterm's `curgr = 2` has no candy-vt counterpart."

### m3 — MINOR — `ResetTest.php:209-220` — `generalSavedGl` companion unpinned (partially vacuous companions test)
The companions matrix covers SGR, charsets and DECOM, but its byte stream (`\x1b[?6h\x1b(0\x1b[41m\x1b7\x1b[!p\x1b8`) never moves GL off 0 — `\x1b(0` only designates G0. Deleting `$this->generalSavedGl = $this->gl;` (ScreenHandler.php:1352) keeps the test green. I confirmed the gap is real and the pin is cheap: with `\x1b)0\x0e` before `\x1b7`, `gl === 1` is what a stale slot would restore, vs `gl === 0` today.
**Fix** — prepend `"\x1b)0\x0e"` (G1 = DEC Special, SO → GL = G1) to the sequence and add `$this->assertSame(0, $h->gl, 'DECRC restores GL to G0');`.

### m4 — MINOR — `docs/research/ansi-tmux-ansicode-audit.md:33` — repo-level record still asserts the old semantics (outside the diff)
The ✅ FIXED probe note reads, in present tense: "DECSTR … margins left at `1..2` — DECSTR **deliberately keeps DECSTBM/charsets**/tab stops, the reset matrix owned by `candy-vt/tests/Handler/ResetTest.php`." Both clauses are now false, and it points at the very test file this change rewrites. No drift guard covers it (the `sugar-crush/tests/Config/*DriftTest.php` guards own sugar-crush docs only).
**Fix** — amend that cell to "keeps tab stops/scrollback (agrees with xterm); resets DECSTBM/charsets/DECSC — wave 7".

### m5 — MINOR — `ResetTest.php:199-220` — both DECRC-based pins pass vacuously if DECRC itself regresses
DECSTR already homes the visible cursor and already defaults pen/charsets/origin, so if `restoreCursor()` became a no-op, `testDecstrResetsSavedCursorToHome` and `testDecstrResetsSavedSlotCompanions` would still pass. They *do* catch the regression they were written for (a carried-through `savedRow`, or untouched companions — verified by reasoning against `Cursor::restore()`'s `savedRow ?? $this->row`), so this is hardening rather than a hole.
**Fix** — insert a cursor move between reset and restore, e.g. `\x1b[!p\x1b[3;4H\x1b8`, so landing on `0,0` proves DECRC actually fired.

---

## 4. Nits

- **n1 — NIT — `ScreenHandler.php:1272-1284`** — the new "RESOLVED DIVERGENCE" paragraph is indented 6 spaces before `*` where every other line in the block uses 5 (verified by `match(/^ */)`). `php-cs-fixer` with the repo config does **not** flag it (`Found 0 of 2 files that can be fixed`), so it will survive CI. Reflow for visual consistency.
- **n2 — NIT — `ScreenHandler.php:1232-1233`** — "exactly as xterm's `CursorSave` overwrites the whole `sc[]` struct" slightly overclaims: `CursorSave2` (cursor.c:398-416) also stores `wrap_flag` and `curgr`, neither of which candy-vt models (`restoreCursor()` forces `wrapPending = false` at :742). Pre-existing, unrelated to DECSTR. Consider "the state this port models".
- **n3 — NIT — `ScreenHandler.php:1354`** — `generalSavedOriginMode = false` hardcodes the value that `$this->mode->originMode` already holds two lines above (`withOriginMode(false)`). Both are xterm-correct (`bitclr(ORIGIN)` at 14400 precedes `CursorSave` at 14559), but reading the field keeps the save symmetrical with `saveCursor()` (:694) and immune to a future reorder.
- **n4 — NIT — repo hygiene, outside the two files** — `.probe/` (untracked, **not** ignored: `git check-ignore` fails; `.gitignore:39` only covers `.php-cs-fixer.cache`) contains `ScreenHandler.GOOD.php` (82 KB copy of the file under review) and `master.php` — the only other place in the tree containing `[!p`. A `git add -A` would commit it. Delete it or add `/.probe/` to `.gitignore` before the commit.

---

## 5. Semantic correctness — PASS (item-by-item against verified xterm)

| Added / retained reset | xterm evidence | candy-vt code | Call |
|---|---|---|---|
| Scrolling region → full screen | `resetMarginMode` 14398, above the 14432 gate → `set_tb_margins(screen, 0, max_row)` 1368 | `0` / `$this->buffer->rows - 1` (1313-1314) | ✅ correct |
| Charsets → ASCII, GL → G0, SS dropped | `resetCharsets` 14410 above gate → 1271-1279 | 1320-1322 | ✅ correct (see m2 for prose) |
| DECSC slot → home | 14559-14561 | `savedRow: 0, savedCol: 0` (1333-1334) | ✅ correct |
| DECSC companions re-saved from post-reset state | `CursorSave(xw)` at 14559 runs *after* `bitclr(ORIGIN)` 14400, `resetCharsets` 14410 and the soft-branch rendition clear 14549-14554, so `sc->flags/curgl/gsets` all hold defaults | 1351-1354 | ✅ **not over-reaching** — this is verbatim what xterm stores |
| `savedRow: 0` vs `null` | xterm sets `sc->saved = True` (cursor.c:402) | `0` = a real save; `restore()` uses `savedRow ?? $this->row`, so `null` would wrongly carry the current row | ✅ **0 is right** |
| Slot populated even with no prior DECSC | DECSTR unconditionally `CursorSave`s → a following DECRC always restores | `restoreCursor()`'s "no save on record" early-return stays honest for the no-DECSTR path | ✅ correct |
| Tab stops preserved | `TabReset` 14449 **inside** `if (full)` | untouched | ✅ agrees with xterm, not a divergence |
| Scrollback preserved | flush gated on `saved` 14372-14375; DECSTR passes `False` (6156) | untouched | ✅ agrees |
| Line rendition preserved | DECSTR clears no cells (no `ClearScreen` outside `if (full)` at 14504) | untouched | ✅ agrees |
| DECOM/DECAWM/DECTCEM/shape/sync | 14400 / 14549 / 14378 / 14379+14382 / 2026 path | pre-existing lines 1336-1341 | ✅ unchanged, correct |
| Visible cursor homed | xterm does **not** (`CursorSet` only at 14546, inside `if (full)`) | homes it | ✅ declared pre-existing divergence, explicitly disclosed at 1223-1227, out of scope |
| `resetMarginMode` also clears `LEFT_RIGHT` + LR margins (1383, 1377) | — | DECSLRM/LR margins "not modelled" (documented at :456) | ✅ no counterpart to reset |

**Alt-screen `parkedGeneral` sanity**: verified empirically correct (see M2) — DECSTR on alt touches only the active slot; the parked main slot survives intact and DECRC on main restores it. The finding is coverage, not behaviour.

## 6. PHP / consistency — PASS

- Margins `0` / `$this->buffer->rows - 1` (0-indexed) match DECSTBM's `- 1` conversion (:913-914), `hardReset()` (:1194-1195) and the constructor (:271-272). No reset helper exists in the class, so the two-line form matches the three existing sites.
- `$this->charsets = [Charsets::ASCII × 4]` is byte-identical to the property default (:134), `hardReset()` (:1196) and DECALN (:1444); `Charsets::ASCII === 'B'` (`src/Charset/Charsets.php:31`), matching the `['B','B','B','B']` assertions.
- `$this->singleShift = null` matches :1198 / :1446; `public ?int $singleShift` (140) is the correct sentinel.
- Re-save order is **safe**: `$this->sgr` (1315), `$this->charsets`/`gl` (1320-1321) and `withOriginMode(false)` (1337) all precede 1351-1354, so the slot captures the post-reset values. `Sgr` is `final readonly` (`src/Sgr/Sgr.php:14`) and PHP arrays are copy-on-write, so aliasing `$this->sgr`/`$this->charsets` into the slot cannot leak later mutation.
- `softReset()` keeps exactly **one** call site — `ScreenHandler.php:552` (`CSI ! p`). No other method touched; diff hunks (`@@1217`, `@@1258`, `@@1291`, `@@1311`) all lie inside the DECSTR region. `git diff --stat` = 2 files.
- No goldens/fixtures affected: a tree-wide search for `[!p` outside `candy-vt/tests/` hits only `.probe/master.php`; `docs/_data/candy-vt.*` and `docs/lib/candy-vt.html` contain no DECSTR text, so `gen-docs.php` need not re-run.

## 7. Test matrix — mostly complete, with the gaps in M1/M2/m3/m5

- **Reset side pinned**: margins ✅, SGR pen ✅, charsets + GL ✅, cursor home ✅, DECTCEM ✅, DECOM ✅, DECAWM ✅, wrap flag ✅, DECSC position ✅, DECSC companions (SGR/charsets/DECOM) ✅, sync flush ✅, cursor shape ✅ (`CursorShapeAgreementTest.php:179-221, 379`). Unpinned: `singleShift` (**M1**), `generalSavedGl` (**m3**).
- **Preserve side pinned**: screen contents ✅ (`DIRTY` string), tab stops ✅ both directions (`assertArrayHasKey(5)` + `assertArrayNotHasKey(8)` — catches "wrongly restored" and "wrongly wiped"), scrollback ✅ (`assertGreaterThan(0, count())`), line rendition ✅ (`assertSame(DoubleWidth)` + grapheme). Unpinned: alt screen (**M2**), extension modes / title / palette (**m4/m5** matrix rows 38-40).
- **No DECALN or RIS pin weakened or deleted.** All seven RIS tests and both DECALN tests are untouched by the diff (`@@` hunks never enter them). `testDecalnFillsScreenWithEAndHomesCursor` (ResetTest.php:283-284) still asserts `scrollRegionTop === 0`, `scrollRegionBottom === 2` and `charsets === ['B','B','B','B']`; `testDecalnClearsLineRendition` intact; `DecalnResetPinTest` passes.
- The assertion removed from `testDecstrKeepsScrollbackAndTabs` (`assertSame('0', $h->charsets[0], 'designations survive DECSTR')`) is correctly **inverted**, not dropped: `testDecstrResetsCharsetsToAscii` now asserts `['B','B','B','B']` plus a functional render of `l` as ASCII.
- The old single mixed test was properly split into four focused tests; the matrix header rows were updated in lock-step (`saved cursor → home (0,0)`, `DECSTBM margins → full screen`, `SCS G0-G3 / GL → ASCII / G0`).

## 8. Docs — one stale sentence (M3) plus count/precision slips (m1, m2)

`softReset()`'s RESETS/PRESERVES lists, the CURSOR SHAPE paragraph and the "RESOLVED DIVERGENCE" paragraph agree with the code, and the old `DIVERGENCE (our choice, not xterm's)` heading is gone. `ResetTest`'s matrix agrees with `softReset()` except for the three/four count (m1) and the G2/G3 overstatement (m2). The only doc that now *contradicts* shipped behaviour is `saveCursor()` (M3), with the audit-file record at m4.

## 9. Verification run (read-only)

| Gate | Command | Result |
|---|---|---|
| Syntax | `php -l` on both changed files | ✅ No syntax errors |
| Targeted tests | `vendor/bin/phpunit --filter 'ResetTest\|CursorShapeAgreementTest\|DecalnResetPinTest\|SyncOutputEraseTest'` | ✅ **OK (53 tests, 235 assertions)** |
| Full lib suite | `candy-vt$ vendor/bin/phpunit` | ✅ **OK (893 tests, 12 557 assertions)** — no consumer of DECSTR regressed (`SyncOutputEraseTest.php:84` included) |
| Style | `php-cs-fixer fix --dry-run --diff --using-cache=no` (root config, both files) | ✅ Found 0 of 2 fixable |
| Static analysis | `phpstan analyse` (`candy-vt/phpstan.neon`, level max + baseline) | ✅ 0 new errors — the 6 hits in `ScreenHandler.php` are all pre-existing lines (58, 207, 497, 723, 1630, 1719); **0** in the changed range 1302-1365; **0** in `ResetTest.php` |
| Behaviour probes | inline `php -r` (no files written) | ✅ DECSTR clears `singleShift` (2 → NULL) and G2 (`0` → `B`); DECRC restores `gl = 0`, default pen; DECSTR on alt keeps `altScreen = true`, resets `0..rows-1`, and the parked main slot survives for DECRC at `1,1` / red / `g0='0'` |

## 10. Recommendation

Ship the implementation as written — the xterm semantics are right and every citation holds. Before committing, close **M1** (one 6-line test), **M2** (one alt-screen test, expected values already derived above), and **M3** (one doc-block sentence); fold in **m1-m3** as prose edits in the same pass, and clear **n4** (`.probe/`) so it cannot be swept into the commit.

VERDICT: CHANGES REQUESTED
