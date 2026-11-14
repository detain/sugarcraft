# Accessibility — candy-tetris

Facts on this page are pinned by `tests/KeysVocabReferenceTest.php`: the key
vocabulary table below IS the shipped binding map, character for character,
and the README's two Controls tables are checked against the same source.
Add a token to the game without teaching it here, and the suite goes red.

## Screen readers and non-terminal output

The game refuses to play without a live terminal. A launch with no TTY —
stdin or stdout a pipe or redirect, or `TERM=dumb` — is rejected before
anything paints: one plain line on stderr, zero escape bytes, exit code 1.
A screen reader pointed at a redirected session therefore gets a clean
sentence, never an escape-sequence soup.

The plain-text surfaces that exist on purpose:

- `--help` (also `-h`): flags, the shipped key card derived from the live
  bindings, and the terminal floor read from the renderer's own constants.
- The refusal line and the per-launch record line on stderr, which name
  what happened in sentences.
- With `NO_COLOR` or `CLICOLOR=0` the whole frame renders with zero SGR:
  shape carries every fact colour used to carry, and empty cells render as
  `·` so the grid stays readable as text-ish structure.
  Caveat kept honest: `CLICOLOR_FORCE=1` / `FORCE_COLOR` vouch for the
  terminal and skip the no-TTY refusal, so under either one a redirected
  stdout receives real escape bytes.

Play itself requires a terminal at the documented posture: the solo frame
is 40 columns wide with an honest 40x24 floor (the sidebar reflows by
budget; below the floor a resize notice paints and play freezes until the
terminal is wide *enough rows* again — columns alone do not restore it).
The duel is one posture only: 54x33, exact.

## Key vocabulary

These are the remappable action ids and the tokens the shipped default
binds them to (the same spelling `keys.json` takes — see "Remapping keys"
in the README). Chord tokens (`ctrl+…`) actuate only under the modifier.

| Action id    | Shipped tokens     |
|--------------|--------------------|
| `quit`       | `q`, `ctrl+c`      |
| `pause`      | `p`                |
| `restart`    | `r`                |
| `hold`       | `c`                |
| `move-left`  | `left`             |
| `move-right` | `right`            |
| `soft-drop`  | `down`             |
| `rotate-cw`  | `up`, `x`          |
| `rotate-ccw` | `z`                |
| `rotate-180` | `a`                |
| `hard-drop`  | `space`            |
| `begin`      | `space`, `enter`   |
| `help`       | `h`                |

The in-game teaching surfaces all derive from this one map: the live `h`
key card (which freezes the world while it reads), the versus strip, the
intro card's key line, the one-row budgeted pointer, and `--help`. A remap
moves every one of them with it, so the documentation you are reading
describes the defaults — press `h` to see *your* table.

## Motion and flashing

- **Earned moments flash rows into the sidebar card.** A `T-SPIN`,
  `TETRIS`, `PERFECT CLEAR`, `combo xN` or `B2B armed` row appears (and
  disappears) as the state arms; the versus strip carries the same story
  compactly. The vocabulary is plain text and the rows are additive — the
  score card itself never blinks out — but players sensitive to transient
  motion should know the badges come and go.
- **Gravity ramps to near-instant lock.** The fall starts at 48 frames per
  row (about 0.8 s) and shortens with level; from level 29 the interval is
  one 60 Hz frame per row (about 17 ms) — at the top of the ramp a piece
  crosses the well almost instantly. `p` pauses (gravity and the lock
  timer both stop), and the `h` card freezes play while open: both are
  legitimate ways to take all the reading time a board needs.
- **Resize freezes the board below the floor** rather than reflowing
  under the player: the world is still while the notice is up.

Single, double and triple clears stay silent — only named moments paint.
There is no audio at all.
