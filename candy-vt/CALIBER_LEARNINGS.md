# candy-vt Caliber Learnings

Accumulated patterns and gotchas for this library.

---

## Parser table

The VT500 state machine transition table is generated in PHP at first
use as a 4096-byte string (15 states × 256 codes), packed one byte per
entry as `(action << 4) | nextState`. State and Action enums hold the
canonical numeric values; their order is load-bearing because the
transition table is keyed by `state.value << 8 | byte`. The build
function in `Transitions::build()` is a direct port of upstream
`charmbracelet/x/ansi/parser.GenerateTransitionTable` — keep them in
sync if upstream changes the table.

## ESC dispatch is separate from CSI dispatch

Two-byte ESC sequences (e.g. ESC D = IND, ESC 7 = DECSC) emit
`Handler::escDispatch($final, $intermediate)`, not `csiDispatch` or
`execute`. Downstream handlers translate ESC + 0x40-0x5F to the
equivalent C1 control if they want; the parser stays neutral.

## ESC \ (ST) emits a trailing escDispatch

When OSC/DCS/SOS/PM/APC are terminated by `ESC \` (ST in 7-bit form),
the parser dispatches the string sequence on the ESC, transitions to
Escape state, then dispatches the trailing `\` as `escDispatch(0x5C)`.
Most consumers ignore it. BEL and 8-bit ST (0x9C) terminators don't
have this trailing event.

## UTF-8 multi-byte handling

Lead bytes (0xC2-0xF4) transition to a synthetic `State::Utf8` and the
parser accumulates continuation bytes (0x80-0xBF) internally. The full
rune arrives at the handler as a single `printChar(string $rune)` call
— callers don't need to reassemble bytes themselves. An ASCII byte
arriving mid-rune drops the partial sequence and processes the byte
fresh from Ground.

## CSI default params surface as -1

`\x1b[;H` produces `params = [-1, -1]` (two slots, both default).
`\x1b[1;H` produces `[1, -1]`. Sentinel `-1` means "missing/default";
handlers downstream should resolve to spec-defined defaults
(`?: 1` for cursor moves, `?: 0` for SGR, etc.).

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
