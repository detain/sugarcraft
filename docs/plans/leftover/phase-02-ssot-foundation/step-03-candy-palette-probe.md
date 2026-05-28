# Step 02.03 — candy-palette::Probe consolidates env-var detection

**Source:** `leftover_updates_later.md` SSOT-08 + candy-palette research H1-H5 + M1-M3
**Branch:** `ai/palette-probe`
**Bundle hint:** standalone (foundational; later phase-09 candy-log step depends on it)

## Deliverable

Today multiple libs probe environment variables independently
(`NO_COLOR` / `FORCE_COLOR` / `COLORTERM` / `WT_SESSION` / etc.).
Consolidate every probe into `candy-palette/src/Probe.php` so that
`candy-log`, `candy-mosaic`, `candy-freeze`, `candy-vt` (truecolor
accept), and future consumers all call a single API.

## Files

**Create:**
- `candy-palette/src/Probe.php` — static class with:
  - `Probe::colorProfile(): ColorProfile` — returns the negotiated
    profile after walking every env var in precedence order.
  - `Probe::isNoColor(): bool` — `NO_COLOR` set ⇒ true.
  - `Probe::isForceColor(): bool` — `CLICOLOR_FORCE=1` set ⇒ true.
  - `Probe::reducedMotion(): bool` — for honey-bounce; reads
    `REDUCE_MOTION` / `PREFERS_REDUCED_MOTION`.
- `candy-palette/src/ColorProfile.php` — enum: `NoTTY`, `Ascii`,
  `Ansi`, `Ansi256`, `TrueColor`.

**Implement precedence order (research H1–H5, M1–M3, Phase-2):**

1. `CLICOLOR_FORCE=1` → TrueColor (overrides everything below).
2. `NO_COLOR` set (any value) → NoColor.
3. `CLICOLOR=0` → NoColor.
4. `TERM=dumb` → NoTTY / Ascii.
5. `COLORTERM=24bit|truecolor|yes` → TrueColor.
6. `WT_SESSION` set → TrueColor (Windows Terminal).
7. `GOOGLE_CLOUD_SHELL=true` → TrueColor.
8. `TMUX` / `STY` set + base TERM checks tmux/screen first.
9. `TERM=xterm-kitty|xterm-ghostty|*-256color` → Ansi256.
10. `TERM=xterm*|screen*|tmux*` → Ansi.
11. Default → Ansi.
12. Optional Phase 2: parse `infocmp` for `Tc` / `RGB` capabilities
    if available; upgrade Ansi → TrueColor.

**Tests:**
- `candy-palette/tests/ProbeTest.php` — parameterized:
  - Each precedence rule has at least one test case.
  - Fixture sets env vars, runs `Probe::colorProfile()`, asserts
    expected `ColorProfile` enum value.
- `candy-palette/tests/ProbeInfocmpTest.php` — markTestSkipped when
  `infocmp` binary missing; otherwise asserts Tc/RGB detection.

## Acceptance

- `cd candy-palette && vendor/bin/phpunit --filter Probe` green.
- `Probe::colorProfile()` covers every research bullet from H1-H5 +
  M1-M3 + Phase 2.
- Existing candy-palette tests still pass.

## Notes

- candy-log adoption is step 09.11 — that's a separate phase. For
  now this lib lands the surface; downstream picks it up later.
- Document the precedence rule order in the class docblock — it's
  load-bearing knowledge.
- ColorProfile enum names follow the research doc's terminology
  exactly.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
