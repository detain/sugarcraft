# C-TUI-1.a — Vim text objects, `c` and counts in ComposerVim — LANDED

Tip: `cf5f0f925` on `cc/C-TUI-1.a` (worktree /home/sites/cc-cmp-C-TUI-1.a, base b38baf0f8). One cohesive commit, 7 files, author/committer Joe Huss <detain@interserver.net> (verified %ae and %ce separately), zero trailers, never pushed.

## What shipped (brief, exactly)
- `src/Tui/ComposerVim.php`: +readonly `pendingScope` ('i'/'a') and `count`; static `claims()` → instance `claimsKey()` (digits 1-9 always claimed; `0` and the lib motions claimed ONLY while a count is armed — the brief's named risk: a bare `0` stays the line-start motion so `d0` survives); `isArmed()`; `resolve()` grammar extended: digit-accumulate (1-9, then 0-9), `i`/`a` arm the scope after an operator, text-object targets resolve through the lib's `VimKeyHandler::handleTextObject()` on the caret's line into the existing `applySpan()` (lib consumed UNCHANGED); `c`/`cc`/`C`/`cw` = the `d`/`dd`/`D` kill then `enterInsert()` (shared `openLine()` i-feed precedent, new private `enterInsert()`); `cw` ≡ `ce` when bare, motion-span with a count; count multiplies dd/yy/cc, dj/dk, x, p/P and the delegated motions; `start()` + cases `c`, `C`, `1`-`9`. Whole armed grammar cancels+re-routes on any key with no reading (Tab/Esc guarantee unchanged for counts too). "Deliberately NOT implemented" docblock truth-flipped: only named registers remain unimplemented.
- `src/Chat/Handlers/HandlesKeys.php`: arm (b) `pending !== null` → `isArmed()`; claim arm `pending === null` → `!isArmed()` and `ComposerVim::claims()` → `claimsKey()`; both doc comments truth-flipped in-step.
- `lang/en.php` `settings.vimMode.help`: key list now names cc/C/text objects/counts. README Keys did NOT move (a mode, not bindings). No other file in the repo pins this value (grepped).

## Tests
- NEW `tests/Tui/ComposerVimTextObjectTest.php` (9): `ci"`, `da(`, `diw`, `yi{`, empty-object `ci(` inserts between `()`, jump-forward quote, outside-every-pair beep cancels everything, non-target beep, Esc mid-object.
- NEW `tests/Tui/ComposerVimCountTest.php` (18): `3dd`, `2yy`+`p`, `d2w`, `3x`, bare `0` vs `0`-after-digit (`d20w`), `3w` pure motion, `d2j`, clamped-count beep, `2p`, forgotten-count→Tab false-reroute, Esc keeps register, `C`, `cc`, bare `cw`≡`ce` vs `c2w` motion-span, decimal accumulation, `start('1')` never touches the buffer.
- MODIFIED `tests/Tui/ComposerVimTest.php`: claim-roster pin rewritten to `claimsKey()` with both count polarities + `isArmed()` legs.
- MODIFIED `tests/Chat/ComposerVimModeTest.php` (+3): digits claimed-in-NORMAL/typed-in-Insert/sidelined-behind-menu; `cw` end-to-end lands Insert; forgotten count does not hold Tab.

## Gates (targeted, plain pipe, cwd worktree candy-code)
- Red-before-fix: new files at base → `Tests: 27, Errors: 15, Failures: 11`.
- After: `--filter 'ComposerVim|LangParity'` → `OK. Tests: 82, Assertions: 3292` (2 PHPUnit deprecations = pre-existing AccessorDeprecationTest private providers, present in the red-baseline run; not this lane's files).
- Guards: `--filter 'ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift|LangParity|DuplicatedDocBlockLine'` → `Tests: 144`, failures = ONLY the suite-figure staleness pair (live 26,411 vs pinned 25,537 — an ~844 gap already open at base b38baf0f8 from concurrent lanes + this lane's +30; re-pin is the gate's duty, forbidden file untouched).

## Notes / seams
- §6.1 R4 honored: no VisualLine, `V` still aliases `v` — that paragraph untouched.
- ComposerVimTest/ModeTest `st()` still reads `pending !== null` (harmless); new pins use `isArmed()`.
- Docblocks elsewhere naming "pending operator" generally (Chat.php property, SETTINGS.md:303 consumer row) remain true; not in files-modify, untouched.
- Whole-line conveniences (D/Y/o/O) spend a count without multiplying (recorded in the class docblock honestly).
