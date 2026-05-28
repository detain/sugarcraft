# CandyAnsi — Caliber Learnings

> What the team learned while building candy-ansi. Accumulated across
> sessions; each entry is time-stamped, tagged, and attributed.

## 2026-05-28 — step-01: extracted from candy-vt

**candy-ansi** was extracted from `candy-vt/src/Parser/` in step-01 as the
shared ANSI state machine. The upstream is `charmbracelet/x/ansi` — a
Paul-Williams VT500 state machine that maps byte → action → next state.
`candy-vt` still carries its own copy of the parser classes (CsiHandlerImpl
specifically) until step-12, when the terminal-state coupling is resolved.
