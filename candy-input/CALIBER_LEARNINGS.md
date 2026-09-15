# CandyInput — Caliber Learnings

## Accumulated patterns and gotchas

> Fill in as lessons are learned during implementation and testing.

## Key learnings

- **Bracketed paste cap at 1 MiB** — hostile pipes can send infinite paste. Don't lift this without thinking.
- **Partial sequence buffering** — EscapeDecoder must handle `decode("\x1b[")` returning zero events, then `decode("A")` returning ArrowUp. The buffer must be cleared after a complete sequence.
- **EscapeDecoder buffers partial sequences via remainder()** — consumer calls again with remainder prepended when no event returned. Source: step-06 ai/candy-input-new
- **Fuzz-friendly** — every decoder path handles random byte sequences without throwing; recognized terminal replies drain as `TerminalReplyEvent`, genuinely unknown complete sequences are consumed and tallied by `droppedUnknownCount()` — neither crashes nor stalls the buffer.
- **Terminal replies share stdin with keystrokes** — a DA/CPR/XTWINOPS/kitty-flags/DECRPM/OSC/DCS answer must be structurally consumed the moment its final byte arrives. Returning the tail as "incomplete" (re-buffering) or swallowing the suffix (returning `''`) are both poisoning: later keys vanish behind the reply until the 128-byte cap discards everything. `drainedReplyCount()` vs `droppedUnknownCount()` make the two silent paths observable.
- **Kitty `;mods` is 1 + the bitmask** — a bare press is `;1u`, not `;0u`; de-base before interpreting bits, or every framed keypress carries phantom Shift. The legacy release flag (`0x20`) and spec `:event-type=3` are separate encodings.
- **Bounded string drain must not resume key decoding mid-OSC/DCS** — after the 1 KiB cap, keep swallowing to the terminator (one `truncated` reply, no key spam), and make the 64 KiB abandon boundary byte-exact so single-chunk and byte-at-a-time reads decode identically.
