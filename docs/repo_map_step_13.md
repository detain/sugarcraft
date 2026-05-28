# Step 13 — candy-mosaic + candy-palette TerminalProbe consolidation

**Branch:** `ai/probe-consolidation`
**Depends on:** step-00 ✅ (no new shared package; lifts the probe into existing candy-palette)
**Blocks:** step-29 (sugar-glow + candy-wish consume TerminalProbe)

## Goal

Extract terminal capability probing into a shared `TerminalProbe` in `candy-palette` (which already does 12-step detection per §422 of the repo map). Add `Mosaic::auto()` graceful fallback + `Mosaic::diagnose()` structured probe report to candy-mosaic, both consuming the new shared `TerminalProbe`. Eliminates the user-facing panics on Windows/SSH/old terminals (issues #69/#68/#72/#64 on ratatui-image cited).

Reference: `docs/repo_map_update.md` §345.6 (TerminalProbe / Mosaic::auto()), §387.9 (probing reinvention), §327.5 (frameworks).

## Files expected to be created

- `candy-palette/src/Probe/TerminalProbe.php` — moves existing 12-step detection here; public interface.
- `candy-palette/src/Probe/ProbeReport.php` — readonly value object (capabilities map, source per capability, detected_at).
- `candy-palette/src/Probe/Capability.php` — enum (TrueColor, 256Color, BasicColor, NoColor, Sixel, Kitty, ITerm2, Hyperlinks, BracketedPaste, FocusEvents, KittyKeyboard).

## Files expected to be modified

- `candy-palette/src/` — refactor the existing detection logic to use TerminalProbe internally; existing public API stays.
- `candy-mosaic/composer.json` — add `sugarcraft/candy-palette: @dev` if not already there (via `path-repo-closure`).
- `candy-mosaic/src/Mosaic.php` — add `Mosaic::auto(): self` and `Mosaic::diagnose(): ProbeReport` factories.
- `candy-mosaic/tests/MosaicTest.php` — add auto() + diagnose() test cases.

## Acceptance criteria

- [ ] `TerminalProbe::run(): ProbeReport` — runs the full probe pipeline (env vars, terminfo, escape-query if interactive, fallbacks).
- [ ] `ProbeReport::has(Capability): bool`, `ProbeReport::source(Capability): string` (e.g., "env:COLORTERM", "terminfo:Tc", "escape-query:OSC4").
- [ ] `Mosaic::auto()` returns a Mosaic instance using the best renderer the probe finds (Kitty > Sixel > ITerm2 > HalfBlock > QuarterBlock > BasicAscii). NEVER throws — falls back to BasicAscii on every error.
- [ ] `Mosaic::diagnose()` returns the ProbeReport — useful for users debugging "why is my terminal not rendering Sixel".
- [ ] Existing candy-palette / candy-mosaic tests still pass.
- [ ] ≥95 % coverage on TerminalProbe + ProbeReport + auto().
- [ ] No more "unhelpful error on Windows/SSH" — replaced by a falling-back Mosaic + actionable diagnose() output.
- [ ] `git status` clean on master.

## Coder brief

1. **Delegate to an Explore subagent**: "Locate the 12-step terminal-capability detection code in candy-palette. List the 12 steps and the file path for each. Identify whether terminfo (infocmp) parsing is included or skipped (Phase 2 reference in repo_map §422)."
2. **Refactor candy-palette**: move detection code into `Probe/TerminalProbe.php`. Keep candy-palette's existing public color-profile API as a thin facade that internally calls TerminalProbe.run() then maps Capabilities to its profile concept.
3. **Build ProbeReport**: each capability gets a `source` string ("env:VAR", "terminfo:cap", "escape:OSCn", "fallback"). Useful for diagnose().
4. **Add Mosaic::auto()**:
   - Try probe; on any exception fall through.
   - Pick best supported renderer: `Kitty > Sixel > ITerm2 > HalfBlock > QuarterBlock > BasicAscii`.
   - Construct + return Mosaic with that renderer. `BasicAscii` is the always-available floor.
5. **Add Mosaic::diagnose()** — calls TerminalProbe::run(), returns the ProbeReport.
6. **Path-repo closure**: candy-mosaic now requires candy-palette; if it didn't before, run `path-repo-closure`.
7. Run phpunit in candy-palette + candy-mosaic + check-path-repos.

## Tester brief

- TerminalProbe::run on fixture envs (mock getenv via dependency injection or a test seam): COLORTERM=truecolor → has(TrueColor); TERM=xterm-256color → has(256Color); TERM=dumb → only NoColor.
- ProbeReport::source: each detected capability has a non-empty source string.
- Mosaic::auto in fixture-Sixel-supported env returns Sixel renderer; in NO_COLOR env returns BasicAscii.
- Mosaic::auto on simulated probe failure (force exception in probe internals) → returns BasicAscii without throwing.
- Mosaic::diagnose returns a ProbeReport with each detected Capability.

## Scribe brief

- `candy-palette/README.md`: add section "Terminal capability probing" with TerminalProbe code example.
- `candy-mosaic/README.md`: add `## Auto-detection` section with `Mosaic::auto()` + `diagnose()` examples; highlight the "never throws" guarantee.
- CALIBER_LEARNINGS in both: "TerminalProbe is the single source of truth for capability detection. Don't re-roll env-var parsing in consumers — use TerminalProbe."
- MATCHUPS: both libs get note "shared TerminalProbe via candy-palette".

## Ship brief

- **PR title**: `candy-mosaic + candy-palette: shared TerminalProbe + Mosaic::auto() never throws`
- **PR body**:
  ```
  ## Summary
  - candy-palette adds TerminalProbe / ProbeReport / Capability extracting its 12-step detection into a public API.
  - candy-mosaic gains Mosaic::auto() (graceful fallback to BasicAscii on any probe failure) and Mosaic::diagnose() (structured ProbeReport).
  - Addresses ratatui-image issues #69, #68, #72, #64 pattern: no more unhelpful errors on Windows/SSH/old terminals.
  - sugar-glow + candy-wish migrate to TerminalProbe in step-29.

  ## Test plan
  - [x] vendor/bin/phpunit in candy-palette + candy-mosaic (≥95% coverage on probe code)
  - [x] Mosaic::auto on simulated probe failure — confirms no exception, falls to BasicAscii
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_13.md, docs/repo_map_update.md §327.5, §345.6, §387.9
  ```
- Commit subject: `candy-palette: extract TerminalProbe; candy-mosaic: auto()+diagnose()`.
