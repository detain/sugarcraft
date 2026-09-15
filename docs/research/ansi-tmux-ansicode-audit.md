# ANSI conformance audit — SugarCraft vs tmux `tools/ansicode.txt`

> Research-only audit (docs track). Reference: tmux [`tools/ansicode.txt`](https://github.com/tmux/tmux/blob/master/tools/ansicode.txt)
> (779 lines, fetched 2026-09-15). Every **WRONG** finding below was confirmed by executed `php -r` probes against the
> checked-out sources; observed bytes are quoted inline. Companion detail table (every sequence family):
> `/home/sites/sc-briefs/ansicode-findings.md` (audit brief artifact).
> Scope: [candy-ansi](../../candy-ansi), [candy-vt](../../candy-vt), [candy-core](../../candy-core),
> [candy-input](../../candy-input), [candy-palette](../../candy-palette), [candy-freeze](../../candy-freeze),
> [sugar-spark](../../sugar-spark). No `src/` or `tests/` file was modified by this audit.

## Top defects (ranked by user-visible impact)

### Real bugs — wrong emitted or wrong consumed bytes

| # | Defect | Lib · evidence | Severity | Fix |
|---|--------|----------------|----------|-----|
| 1 | **DECAWM defaults OFF** (VT100 boots with autowrap ON, ansicode:460). 30 chars into a 20-col screen: chars 21–30 are silently **discarded** (observed: row 0 filled, cursor `0,19`, tail gone). Every full-width TUI repaint clips its right edge. | [candy-vt](../../candy-vt) `src/Mode/Mode.php:44` + `src/Handler/ScreenHandler.php:94` | Critical | `bool $autoWrap = true`; set on RIS/DECSTR |
| 2 | **No deferred (pending) wrap** — both engines advance the cursor eagerly when printing at the right margin: `ESC[1;20H`+`X` on 20 cols leaves cursor at `1,0` (xterm: `0,19` with wrap pending). CPR replies, overstrike repaints (`…\r`+`CR`+`B`) and status bars diverge from real terminals; in the vcr engine the wrap fires even with `?7l`. | [candy-vt](../../candy-vt) `src/Handler/ScreenHandler.php:154-162`, `src/Parser/CsiHandlerImpl.php:108-119` | Critical | add `wrapPending` to `Cursor`, consume on next printable, clear on cursor motion |
| 3 | **`ESC c` (RIS) and `CSI !p` (DECSTR) unimplemented** — probe `"ABCDEF\x1bc"` → screen still `ABCDEF`. An app reset leaves corrupted state (modes, palettes, regions) behind. | [candy-vt](../../candy-vt) `src/Handler/ScreenHandler.php:271-280`; grep: zero RIS code in lib | Critical | implement RIS + DECSTR (also fixes #1's persistence) |
| 4 | **candy-input poisons the input stream on any private CSI reply** — `decode("\x1b[?1;2c")` returns 0 events and *keeps buffering*; the next `decode("hello")` also returns 0 events (observed `remainder()` = 12 bytes; everything discarded at the 128-byte cap). DA1/DECRPM/kitty-flag/OSC-4 replies hang the keyboard. | [candy-input](../../candy-input) `src/EscapeDecoder.php:366-373,459,250-253` | Critical | parse and swallow `CSI ? … final` replies instead of gating on a CPR-shaped regex |
| 5 | **X10 mouse reports decode as keystrokes** — `"\x1b[M".chr(32).chr(35).chr(37)` → `Key(' ')`, `Key('#')`, `Key('%')` (observed). Mouse mode 1000 turns every click into printable-character spam. | [candy-input](../../candy-input) `src/EscapeDecoder.php:341-377,542-543,627` | Critical | implement `ESC [ M` 3-byte payload branch |
| 6 | **kitty keyboard decoder inverted** — real frames are `CSI code;mods u` (no `?`); they are parsed *only* behind a `?` gate: `"\x1b[97;5u"` → *no event* (observed), while `CSI ? u` *queries* are instead executed as SCO restore-cursor on the emulator (probe: kitty query reply teleports candy-vt cursor from `4,6` to `1,2` — also `HandlerAdapter.php:83-84` / `ScreenHandler.php:199` prefix-blindness). | [candy-input](../../candy-input) `src/EscapeDecoder.php:459-470`; [candy-vt](../../candy-vt) | Critical | decode non-`?` `u` finals as kitty events; gate cursor `s`/`u` on `$prefix===0` |
| 7 | **CPR on row 1 fabricated as a key** — `"\x1b[1;20R"` → `Key(F3, SHIFT|ALT)` (observed; row ≥10 correctly swallowed). Every `ESC[6n` reply that vim-style apps receive injects a phantom Alt+Shift+F3. | [candy-input](../../candy-input) `src/EscapeDecoder.php:547-575` (`'R'=>'F3'`) | Critical | require `R` to be non-keystroke when params are present |
| 8 | **candy-palette corrupts colours on degrade** — capture-group mis-index: `ESC[38;2;200;100;50m` → `ESC[38;5;162m` (true value 173); `ESC[38;2;0;205;0m` (green) → `ESC[34m` (**blue**, observed). Plus: every `38;5;n` round-trips through `fromAnsi256Index()` which has no idx<16 branch → `ESC[38;5;9m` (bright red) → `ESC[38;5;16m` (**black**, observed). | [candy-palette](../../candy-palette) `src/Palette.php:261-270`, `src/Color.php:205-215` | Critical | fix group indices; add 16-colour table branch |
| 9 | **`Ansi::strip()` / `Sanitize::untrusted()` blind to DCS/APC/PM/SOS and 8-bit C1** — `strip("A\x1bP0;1;0q\"1;1;2;2~abc\x1b\\B")` → `A0;1;0q"1;1;2;2~abcB`; `strip("a\x9b31mb")` byte-identical to input (observed hex `619B33316D62`). Consequences: `Width::of()` returns 21 instead of 2, `Width::truncate()` emits payload text, and `untrusted()` (documented "safe for terminal output") passes tmux DCS bodies through: `untrusted("A\x1bPtmux;echo\x1b\\B")` → `Atmux;echo\B` (observed). Companion bypass: a lone C1 byte makes the `/u` preg fail and the `??` fallback lets **all** C0 controls survive — `untrusted("a\x9b\x07b")` → `a\x07b` (hex `610762`). | [candy-core](../../candy-core) `src/Util/Ansi.php:642-693`, `src/Util/Sanitize.php:111`, `src/Util/Width.php:131,165,193,300` | Critical (security-adjacent) | rewrite strip over the candy-ansi state machine; pre-normalize/strip C1 in untrusted() |
| 10 | **`Ansi::kittyGraphicsBegin()` emits DCS `ESC P q`** — observed `1B5071613D742C663D313030…`; ansicode:277 defines `Pq` as **SIXEL**, so the header is byte-identical to a DECSIXEL start and chunks (`kittyGraphicsChunk()` returns a bare unframed `m=1,AAAA`; `kittyGraphicsEnd()` a lone ST) never live in a string context. Real protocol is APC `ESC _ G` — which `kittyGraphicsClear()` correctly uses (`1B5F47`). | [candy-core](../../candy-core) `src/Util/Ansi.php:743-810` | Critical | frame each chunk as `ESC _ G a=d,f=100;b=<m>;p=<id>;<b64> ESC \` |

### Also-real, one tier down (condensed)

- **IL/DL undelivered in the TEA emulator** (`ScreenHandler.php:198-212` switch omits `L`/`M`; probe `ESC[1L` → grid unchanged) — VT100 *baseline* (ansicode:363-364, 769-771). **Major** (candy-vt)
- **vcr path is a second, weaker engine**: `HandlerAdapter::escDispatch()` empty → ESC 7/8/D/E/M/H/c all no-ops; DECTCEM **inverted** (`?25l` → visible=1, observed) and prefix-blind; `cup()` clamps rows into DECSTBM even with DECOM off (`ESC[4;6r`+`ESC[1;1H`+`X` → row 3, observed); `38;2` truecolor **wipes bold+underline** (`ESC[1;4;38;2;255;0;0mA` → attrs=0, observed). **Critical** per-item (candy-vt `src/Parser/CsiHandlerImpl.php:108-119,146-167,240-252,307-319`, candy-ansi `HandlerAdapter.php:83-91`)
- **DECSC/`CSI s` share one cursor slot and save no attributes** (probe: `ESC7`@9,9 → `CSI s`@1,1 → `ESC 8` lands 1,1, not 9,9). **Major** (candy-vt `Cursor/Cursor.php:14-22`)
- **DECSTBM doesn't home the cursor**; **sub-region scrolls pollute scrollback** (region 2..4 + 5 LFs → scrollback=5, observed; SD also pushes); **`CSI 3J` dead branch**; **erases inside `?2026` sync are dropped** (`AB`+`2J` flush → row still `AB`, observed). **Major** (candy-vt `Handler/ScreenHandler.php:210-213,379-445`, `Handler/EraseHandler.php:81-83`)
- **SCS / DEC Special Graphics missing in every consumer** — `ESC(0jqx` prints literal `jqxk` (ansicode:222-249 baseline); `ESC # 8` DECALN **mis-executes as DECRC** (cursor teleport observed). **Major** (candy-vt, candy-freeze, sugar-spark)
- **SGR 58/59 + colon `38:2::` mis-consumed**: `ESC[58;5;9m` → blink+strikethrough with underline OFF (observed cell sgr) while candy-core *emits* 58 (`Color.php:523`); colon form → `foreground:null`. **Major** (candy-vt `Handler/SgrHandler.php:69,72,135-150`; root cause candy-ansi `Parser.php:280-308` colon-flattening)
- **candy-input misc decoders**: SGR wheel `ESC[<64;10;5M` → phantom left-click (observed); `ESC[Z` Backtab swallowed; focus `\x1b[I` only when chunk-final; `ESC O M`/keypad absent; OSC/DCS replies leak as Alt-key spam (all observed). **Major** (candy-input `EscapeDecoder.php:417-431,357-362,547,309-326`)
- **candy-freeze/sugar-spark re-copy bugs**: colon SGR → corrupt 18-hex `#ffffffffffffffff50a0` (observed); `38;5;m` → white; frame width from `Ansi::strip` ≠ drawn text (phantom cols); sugar-spark rewrites input bytes (`ESC[4;3m` reported as `ESC[4:3m` "underline curly"), emits ghost `ESC\` after DCS, loses truncated CSIs (`parse("\x1b[31")` → 0 segments), and an aborted `ESC O` **steals the next printable** (`ESCOX` "SS3 X", observed). **Major** (candy-freeze `AnsiParser.php:201-223`, sugar-spark `AnsiHandler.php:156-286`)

### Unimplemented extensions (not bugs — safely ignored, listed for completeness)

DECCKM/DECCOLM/DECSCNM/DECARM/LNM/SRM modes · IRM insert mode · DECOM re-anchor on toggle · DECSCA/DECPRO protected cells · DECRQM/DECRPM + **any reply channel in candy-vt** (CPR/DA/DSR/XTWINOPS/kitty `?u` go unanswered — `feed(): void`) · XTSETALT/ICON (OSC 1) + `CSI 21 t` emitters · OSC 4/10/11/12 **query-side consumption**, OSC 21/22/52-consume/1337 · Sixel/DECDMA (framing-only discard is safe) · DECSLPP/DECSHORP/DECREQTPARM/DECTTC/DECPRO/DECID/DECHTS/DECCAHT/DECSHTS (`u` is currently *mis-captured* as SCORC → bug) · CTC/vertical tabs · urxvt-1015/pixel-1016/modifyOtherKeys/kitty-flag negotiation in candy-input · **all terminal probing in candy-palette** (`checkEscapeQueries()` is comments-only; `TerminalProbe.php:241-283`) incl. sixel `infocmp` regex that never matches real output (`:227`) · `ESC c`/DECCOLM/SCS/DECALN emitters in candy-core.

### Deferred / out of scope

DECCRA/DECICE/DECFCRA, DECUDKR (not documented in ansicode.txt) · kitty/WezTerm/iTerm image *rendering* · a unified candy-vt single-engine refactor (the two-engine split is the root amplifier of items 1-2, 7) · running the 58-lib CI (docs-only track).

## Method

ansicode.txt walked requirement-by-requirement; each family probed through the library's public API (`Vt\Terminal\Terminal::feed`, `Vt\Terminal::feed`, `EscapeDecoder::decode`, `Palette::degrade`, `Ansi::*`, `Freeze\AnsiParser::parse`, `Spark\Inspector::parse`) with `php -r`; expected values taken from ansicode.txt line numbers (index in the companion findings file) and xterm control-sequence conventions where ansicode predates (DECSCUSR, 2026, 1049, SGR 38/58). Parser-layer results (candy-ansi) are strong: a conforming VT500 state machine (8-bit C1, CAN/SUB abort, DEL-inside-escape, C0-inside-ESC resume, string caps) — the defects are concentrated in **handlers, defaults, the emitter's hand-rolled strip, and the input decoder's reply handling**.

## Recommended fix order (dependency-wise)

1. candy-ansi: colon sub-param fidelity (unblocks candy-vt + candy-freeze + sugar-spark colour bugs) · param-overflow merge · strip/sanitize rewrite over the state machine (candy-core).
2. candy-vt TEA: DECAWM default+pending-wrap, RIS/DECSTR, IL/DL, SCS/DECALN, DECSC slot split, sync-erase by-reference, scrollback gating.
3. candy-vt vcr: route ESC/execute through the same handlers (or delete the duplicate engine).
4. candy-input: reply swallowing (private CSI, CPR `R`, X10 `M`, kitty un-gating, `Z`, focus prefix-match).
5. candy-palette: degrade regex/group fixes + real capability queries behind a `--probe` opt-in.
