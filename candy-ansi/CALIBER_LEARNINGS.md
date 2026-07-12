# CandyAnsi — Caliber Learnings

> What the team learned while building candy-ansi. Accumulated across
> sessions; each entry is time-stamped, tagged, and attributed.

## 2026-05-28 — step-01: extracted from candy-vt

**candy-ansi** was extracted from `candy-vt/src/Parser/` in step-01 as the
shared ANSI state machine. The upstream is `charmbracelet/x/ansi` — a
Paul-Williams VT500 state machine that maps byte → action → next state.
`candy-vt` still carries its own copy of the parser classes (CsiHandlerImpl
specifically) until step-12, when the terminal-state coupling is resolved.

## 2026-05-30 — step-20 ansi-consumers: Parser state machine — don't clear buffers in start() before dispatch

**Bug:** `start()` cleared `$this->stringBuffer` before `dispatch` was called for
DCS/OSC/SOS/PM sequences, causing the lead-through byte that opened the
sequence to be lost before the handler could receive it.

**Fix:** Removed `$this->stringBuffer = ''` from `start()` in `Parser.php`.
Buffer clearing (or any stateful reset) must happen **after** dispatch, not
before. State machine actions in `start()` should only set up transitional
state, not discard data that arrived earlier in the same logical sequence.

**Impact:** sugar-spark Inspector now passes all 147 tests; also fixed
candy-hermit and candy-freeze consumers.

## 2026-07-10 — W1 defork-prep: grow CsiHandler, route CR/LF, fix OSC-8, configurable buffer cap

Prepared candy-ansi to become the single parser candy-vt implements (instead of
forking its own copy). Four changes, all inside candy-ansi:

- **`CsiHandler` interface grown.** Added `cr()`, `lf()`, `hvp(int,int)`,
  `gridCols(): int`, and the emulator CSI finals `su`/`sd` (SU `S` / SD `T`),
  `il`/`dl` (IL `L` / DL `M`), `ich`/`dch` (ICH `@` / DCH `P`), `rep` (REP `b`),
  and `scosc`/`scorc` (SCO save/restore `s`/`u`). Signatures match candy-vt's
  `CsiHandlerImpl` (count methods take `int $count = 1`) so the later candy-vt
  reimplementation is a drop-in. **BC-critical:** growing an interface breaks
  every implementer — the only in-repo implementer of `SugarCraft\Ansi\Parser\CsiHandler`
  is `CsiHandlerImpl` (all dependents — candy-pty, sugar-spark, candy-freeze —
  implement the `Handler` interface, NOT `CsiHandler`), so only `CsiHandlerImpl`
  needed new (no-op / return-0) method bodies.

- **HandlerAdapter routing.** `execute()` routes C0 `0x0A`→`lf()` and
  `0x0D`→`cr()` (were `null`). `csiDispatch()` splits `f` (HVP) onto `hvp()`
  distinct from `H` (CUP), and adds the new finals above. Only candy-ansi's own
  tests consume `HandlerAdapter`, so no dependent goldens shift.

- **OSC-8 gap fixed.** `oscDispatch()` never called `OscHandler::hyperlink()` and
  `OscHandlerImpl::hyperlink()` was a no-op — hyperlinks were silently dropped.
  Now `oscDispatch()` parses `8;params;uri` (params is a `:`-separated key=value
  list, only `id=` is defined; uri may be empty = close link) and calls
  `hyperlink($uri, $id)`. `OscHandlerImpl` now records the active hyperlink,
  exposed via `hyperlinkUri()` / `hyperlinkId()`.

- **String-buffer cap is now a ctor param.** `Parser::__construct(..., int $maxStringBuffer = 65536)`
  defaults to 64 KiB (unchanged behaviour) but lets emulator front-ends raise it
  (candy-vt historically used 1 MiB) for large DCS/OSC/SOS/PM/APC passthrough
  payloads. `put()` now checks `$this->maxStringBuffer` instead of the const.

**Gotcha:** CSI final bytes are `0x40`–`0x7E`, so `@` (0x40), `s`, `u`, `b` are
all valid finals the state machine dispatches — no Transitions-table change
needed to reach the new handler methods.

## 2026-07-12 — deadcode: deleted inert `CsiHandlerImpl` stub (step-12 never landed)

The de-fork (W1, PRs #1262/#1263) put the REAL grid-mutating CSI impl in
`candy-vt` (`SugarCraft\Vt\Parser\CsiHandlerImpl`), wired through candy-ansi's
`HandlerAdapter`. candy-ansi's own `SugarCraft\Ansi\Parser\CsiHandlerImpl` was
only ever the "step-12 pending" placeholder: **all 30 methods no-op**,
`gridRows()`/`gridCols()` return `0`, and its test was 30 `assertTrue(true)`
smoke assertions. Grep confirmed **zero real consumers** — the only references
were the class's own file + its own test, and every dependent (candy-pty,
sugar-spark, candy-freeze) implements the `Handler` interface, while candy-vt
supplies its own `CsiHandler` implementation. So both files were deleted.

- **The `CsiHandler` interface stays** — it is the contract `HandlerAdapter`
  type-hints (`__construct(private CsiHandler $csi, ...)`) and that
  `HandlerAdapterTest` exercises via `createMock(CsiHandler::class)`. candy-ansi
  intentionally ships the interface + adapter with **no concrete in-repo
  implementer**; emulator front-ends (candy-vt) provide the real one. This
  supersedes the W1 note above that called `CsiHandlerImpl` "the only in-repo
  implementer".
- **`OscHandlerImpl` was NOT deleted** — unlike the CSI stub it is *not* inert:
  #1262 gave `hyperlink()` real storage behaviour (records the active OSC-8
  link, exposed via `hyperlinkUri()`/`hyperlinkId()`), and `OscHandlerImplTest`
  asserts that behaviour for real. Precision matters here: the two `*Impl`
  files diverged in #1262 — CSI stayed inert, OSC gained behaviour.