# Step 06 — Create candy-input

**Branch:** `ai/candy-input-new`
**Depends on:** step-00 ✅ (independent of others)
**Blocks:** step-21 (sugar-readline migrates onto candy-input)

## Goal

Create the `candy-input` foundation package — terminal escape sequence decoder for keyboard (legacy + Kitty progressive keyboard enhancement) and mouse (SGR 1006). Provides the `InputDriver` interface and `EscapeDecoder` implementation that unblocks real-world interactive use of `sugar-readline` (currently blocked: only handles symbolic key names, not raw bytes from a TTY).

Reference: `docs/repo_map_update.md` §327.7 (framework), strategic recs §463 ("Close the Input Driver Gap First").

## Files expected to be created

- `candy-input/composer.json`
- `candy-input/phpunit.xml`
- `candy-input/README.md`
- `candy-input/CALIBER_LEARNINGS.md`
- `candy-input/src/InputDriver.php` — interface.
- `candy-input/src/EscapeDecoder.php` — main decoder.
- `candy-input/src/Driver/StreamInputDriver.php` — reads from a PHP resource (STDIN or a fake stream for tests).
- `candy-input/src/Event/KeyEvent.php` — readonly (key string, modifiers bitmask, raw bytes).
- `candy-input/src/Event/MouseEvent.php` — readonly (x, y, button, action).
- `candy-input/src/Event/FocusEvent.php` — readonly (gained: bool).
- `candy-input/src/Event/PasteEvent.php` — readonly (content).
- `candy-input/src/Event/ResizeEvent.php` — readonly (cols, rows).
- `candy-input/src/KeyModifier.php` — enum / bitmask (Shift, Ctrl, Alt, Super, Hyper, Meta, CapsLock, NumLock).
- `candy-input/tests/EscapeDecoderTest.php`, `StreamInputDriverTest.php`.

## Files expected to be modified

- Root composer.json, MATCHUPS, PROJECT_NAMES, README, docs/index.html, docs/lib/candy-input.html, codecov.yml.

## Acceptance criteria

- [ ] `InputDriver::read(): ?Event` — returns next event or null on EOF/non-blocking-empty.
- [ ] `EscapeDecoder::decode(string $bytes): list<Event>` — decode a byte buffer into 0+ events; handles partial sequences (returns events + remaining unconsumed bytes via `remainder()`).
- [ ] Supports: plain ASCII keys, F1-F12, arrows, Home/End/PgUp/PgDn, Insert, Delete, Backspace, Tab, Enter, Esc.
- [ ] Supports Kitty keyboard protocol disambiguation flags (CSI `?u`); decodes the extended report format.
- [ ] Supports SGR mouse encoding (1006): `CSI < button ; x ; y M|m`.
- [ ] Supports focus events: `CSI I` / `CSI O`.
- [ ] Supports bracketed paste: `CSI 200 ~ ... CSI 201 ~`.
- [ ] Supports window resize via SIGWINCH plumbing (if not pure-decoder: provide a SignalListener helper).
- [ ] `EscapeDecoder::reset()` clears partial-sequence buffer.
- [ ] **No deps on other `sugarcraft/*` packages** — candy-input is leaf.
- [ ] ≥95 % coverage including pathological inputs.
- [ ] `git status` clean on master.

## Coder brief

1. **Spawn a Researcher** with: "Provide canonical byte sequences for: (a) Kitty keyboard protocol progressive enhancement (CSI ?u, the full progressive-enhancement spec), (b) SGR 1006 mouse encoding (button bit layout), (c) bracketed paste mode (start/end sequences, max-length safety), (d) focus reporting (DECSET 1004). Cite RFCs / Kitty docs / xterm ctlseqs."
2. Block on Researcher findings.
3. **Invoke `scaffold-library`** with slug `candy-input`, namespace `SugarCraft\Input\`, role "Terminal escape sequence decoder (keyboard + mouse + focus + paste)".
4. **Design**: EscapeDecoder is a state machine — it consumes bytes via `decode()` and either emits Events or buffers an incomplete sequence. Keep state separate from output (the decoder is reentrant per-instance).
5. **Implementation order**:
   1. Plain ASCII + control codes (Backspace, Tab, Enter, Esc, Ctrl+letter).
   2. Legacy escape sequences (`ESC [` CSI for arrows / Home / End / F-keys).
   3. SGR 1006 mouse.
   4. Bracketed paste (buffer until end marker; cap at 1 MiB to prevent OOM on hostile input).
   5. Focus events.
   6. Kitty keyboard protocol.
6. **StreamInputDriver** wraps a `resource`, reads non-blocking, feeds EscapeDecoder, yields Events.
7. **Fuzz-friendly**: every decoder path should handle random byte sequences without throwing (return as plain text or unknown-event, never crash).
8. Run phpunit + check-path-repos.

## Tester brief

- Per supported sequence: fixture table mapping bytes → expected event.
- Partial sequences: `decode("\x1b[")` → no events yet, `decode("A")` → Arrow Up; assert internal buffer cleared.
- Pathological: `"\x1b"` alone (lone Esc), `"\x1b\x1b"` (Alt+Esc), runaway CSI (`"\x1b[" + str_repeat("9", 1000)`), invalid UTF-8 mid-sequence.
- Bracketed paste: 1-byte paste, multi-line paste, paste with embedded escape sequences (should be inert), oversized paste truncated at 1 MiB.
- SGR mouse: all 4 actions (press, release, drag, scroll) × all 3 buttons × modifier flags.
- Kitty keyboard: shift+ctrl+letter, F-keys with all modifier combos, key release events (Kitty supports release tracking).

## Scribe brief

- README: pitch as "the missing input layer". Quickstart: open STDIN raw, feed bytes through EscapeDecoder, switch on Event type.
- CALIBER_LEARNINGS: "Bracketed paste cap at 1 MiB — hostile pipes can send infinite paste. Don't lift this without thinking."
- MATCHUPS: 🚀 (no upstream parallel — pioneering for PHP TUI). Reference Kitty keyboard protocol spec.
- PROJECT_NAMES Candy table.
- Docs/index.html + docs/lib/candy-input.html (lean into the "unblocks sugar-readline production use" angle).

## Ship brief

- **PR title**: `candy-input: terminal escape decoder (keys + mouse + focus + paste)`
- **PR body**:
  ```
  ## Summary
  - New foundation lib candy-input decodes terminal escape sequences.
  - Supports legacy keys, Kitty keyboard protocol, SGR 1006 mouse, focus events, bracketed paste.
  - Unblocks sugar-readline migration to real-TTY input (step-21).
  - EscapeDecoder is reentrant and handles partial sequences across reads.

  ## Test plan
  - [x] vendor/bin/phpunit in candy-input (≥95% coverage, including pathological-input cases)
  - [x] Fixture-driven byte→event tables for all supported sequences
  - [x] Bracketed-paste 1MiB cap verified
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_06.md, docs/repo_map_update.md §327.7, §463
  ```
- Commit subject: `candy-input: terminal input driver + escape decoder`.
