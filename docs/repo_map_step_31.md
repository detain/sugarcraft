# Step 31 — candy-pty adopts candy-input + candy-ansi

**Branch:** `ai/candy-pty-shared`
**Depends on:** step-06 (candy-input), step-01 (candy-ansi), step-30 (audit confirms scope)
**Blocks:** —

## Goal

Migrate `candy-pty` (PTY handling, FFI-driven) onto candy-input for escape decoding of bytes read off the PTY and onto candy-ansi for any ANSI parsing it does on the PTY stream. Reduces duplication with the libs already migrated and makes future Kitty/Mouse improvements at candy-input level benefit candy-pty automatically.

Reference: step-30 audit (will name the exact files needing change).

## Files expected to be modified

- `candy-pty/composer.json` — add `sugarcraft/candy-input` + `sugarcraft/candy-ansi` via `path-repo-closure`.
- `candy-pty/src/` — pty-read loop: bytes → candy-input decoder → events; pty-output ANSI: → candy-ansi parser if any inspection is needed.

## Acceptance criteria

- [ ] candy-pty's existing test suite passes (FFI tests gated via `requirePtySyscalls()`).
- [ ] PTY input bytes are decoded through `\SugarCraft\Input\EscapeDecoder`.
- [ ] Any ANSI parsing of PTY output uses `\SugarCraft\Ansi\Parser\Parser` (not a private byte-loop).
- [ ] No regression on PTY echo/cursor tests.
- [ ] ≥95 % coverage maintained.
- [ ] `git status` clean on master.

## Coder brief

1. Read the step-30 audit entry for candy-pty to know the exact target files.
2. **path-repo closure**: candy-input + candy-ansi.
3. **Migrate input path**: any byte-buffering + arrow-key/Ctrl-key decoding now goes through `EscapeDecoder`.
4. **Migrate output parsing path**: any place candy-pty looks at PTY output for cursor-position / SGR / OSC events should use the Parser + a Handler.
5. **FFI gotchas**: the `timeout`-doesn't-work pattern (memory: `feedback_phpunit_kill_pattern.md`) — use the backgrounded `pkill` watchdog if any PTY test hangs.
6. Run phpunit + check-path-repos. Use `requirePtySyscalls()` to gate where needed.

## Tester brief

- Existing PTY tests green.
- New test: PTY emits `"\x1b[31mred\x1b[0m"` → consumer reads via candy-input + candy-ansi → asserts SGR transitions seen.

## Scribe brief

- `candy-pty/README.md`: `## Shared foundations` mentioning the two adoptions.
- `candy-pty/CALIBER_LEARNINGS.md`: input/output decoding flows now standardised; don't roll your own.

## Ship brief

- **PR title**: `candy-pty: adopt candy-input + candy-ansi`
- **PR body**:
  ```
  ## Summary
  - candy-pty's PTY-read loop decodes input through candy-input.
  - PTY output ANSI parsing through candy-ansi (when needed).
  - FFI tests still gated via requirePtySyscalls().

  ## Test plan
  - [x] vendor/bin/phpunit in candy-pty (≥95% — modulo FFI gating)
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_31.md, docs/repo_map_update_followups.md
  ```
- Commit subject: `candy-pty: adopt candy-input + candy-ansi`.
