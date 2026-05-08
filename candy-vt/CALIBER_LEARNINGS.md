# candy-vt Caliber Learnings

Accumulated patterns and gotchas for this library.

---

## Parser table

The VT500 state machine transition table is hand-maintained as a
precomputed `int[state][class]` array. Each entry is packed as
`(next_state << 8) | action`. Keep `State::cases()` and `Action::cases()`
in sync with the table dimensions (8 states × 16 classes = 128 entries).

## Wide-character handling

CJK and emoji graphemes occupy 2 cells. The second cell is marked with
`Cell::continuation()`. Width queries delegate to `SugarCraft\Core\Util\Width`
to stay consistent with the rest of the stack.

## Stream writes

Never `ftruncate; rewind;` between writes — slice deltas with
`ftell` / `fseek` / `stream_get_contents`.

## Fixture encoding

Fixtures must be LF-only (`\n`). Add `*.ansi binary` to `.gitattributes`
to prevent CRLF normalization on checkout.
