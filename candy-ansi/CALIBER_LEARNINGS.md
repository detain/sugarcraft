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