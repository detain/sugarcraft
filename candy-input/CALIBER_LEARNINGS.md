# CandyInput — Caliber Learnings

## Accumulated patterns and gotchas

> Fill in as lessons are learned during implementation and testing.

## Key learnings

- **Bracketed paste cap at 1 MiB** — hostile pipes can send infinite paste. Don't lift this without thinking.
- **Partial sequence buffering** — EscapeDecoder must handle `decode("\x1b[")` returning zero events, then `decode("A")` returning ArrowUp. The buffer must be cleared after a complete sequence.
- **EscapeDecoder buffers partial sequences via remainder()** — consumer calls again with remainder prepended when no event returned. Source: step-06 ai/candy-input-new
- **Fuzz-friendly** — every decoder path handles random byte sequences without throwing; unknown sequences emit a plain KeyEvent with the raw bytes rather than crashing.
