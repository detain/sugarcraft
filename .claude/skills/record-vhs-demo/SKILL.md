---
name: record-vhs-demo
description: Creates a VHS .tape file at <slug>/.vhs/<demo>.tape driving a php examples/<demo>.php (or ./bin/<binary>) invocation with TokyoNight theme and the standard FontSize/Width/Height/Type/Enter/Sleep sequence. Also adds the lib to the hand-maintained matrix in .github/workflows/vhs.yml when missing — vhs.yml is glob-triggered on path but glob-skipped on rendering, so libs absent from the matrix render nothing. Use when the user says 'add VHS demo', 'record gif', 'new tape file', 'add a tape for <slug>'. Do NOT use for editing an existing tape's visual style, rendering GIFs locally (CI does that), or troubleshooting a failed render — those are separate flows.
paths:
  - */.vhs/*.tape
  - .github/workflows/vhs.yml
---
# record-vhs-demo

Create a VHS tape file that drives a runnable example so CI re-renders the GIF on every push.

## Critical

- The tape file lives at `<lib-dir>/.vhs/<demo-name>.tape` — dot-prefixed dir, never a top-level recordings folder.
- The CI workflow `.github/workflows/vhs.yml` has a **hand-maintained `all=(...)` matrix at lines ~51-64**. A lib that is not in that array will never render, even if a tape file exists. Always check before scaffolding a tape for a new lib.
- Do **not** commit the rendered GIF — CI renders and commits it on push via the `commit` job in `.github/workflows/vhs.yml`. The local repo only owns the tape source.
- The demo command must be a real, working invocation. `Type "php examples/<demo>.php"` requires the example file to already exist and be self-contained (no extra setup, no external input fixtures).
- Theme is **always** `"TokyoNight"`. Do not invent themes; the existing 90+ tapes are uniform on this.

## Instructions

1. **Verify the example exists.** Read the lib's example PHP file. If it doesn't exist, stop — scaffold the example first (separate task) before writing a tape that calls it. Verify it runs to completion in a bounded time (look for an exit, a timer, or a quit-on-key handler) so a fixed `Sleep` will cover its lifetime.

2. **Pick dimensions from the demo's content.** Match a sibling tape with similar shape rather than guessing. Verified patterns from existing tapes:
   - **Compact text/spinner** (`sugar-bits/.vhs/spinners.tape`): `FontSize 16` · `Width 600` · `Height 180`
   - **Single-line input** (`candy-shell/.vhs/input.tape`): `FontSize 14` · `Width 700` · `Height 200`
   - **Standard interactive demo** (`sugar-prompt/.vhs/form.tape`): `FontSize 16` · `Width 800` · `Height 480`
   - **Tall chart/grid** (`sugar-charts/.vhs/bar.tape`): `FontSize 14` · `Width 700` · `Height 600`
   - **Wide animation** (`honey-bounce/.vhs/spring.tape`): `FontSize 14` · `Width 800` · `Height 380`

   When in doubt: `FontSize 14`, `Width 800`, `Height 480`. Verify the chosen dims match the example's actual output before continuing.

3. **Write the tape file** at the lib's `.vhs/` dir using this exact ordering (Output first, then Set lines, then Type/Enter/Sleep). For a non-interactive demo:

   ```
   Output .vhs/start.gif
   Set FontSize 14
   Set Width 800
   Set Height 480
   Set TypingSpeed 60ms
   Set Theme "TokyoNight"
   Type "php examples/start.php"
   Enter
   Sleep 4s
   ```

   Notes:
   - `Output` path is relative to the lib dir (the workflow's `working-directory`), so it is always `.vhs/<demo>.gif` — never absolute.
   - `Set TypingSpeed 60ms` (or `80ms`) is optional; include when the demo has a `Type` step the viewer should see being typed.
   - The trailing `Sleep <N>s` must cover the demo's full runtime. `Sleep 2s` for instant chart renders; `Sleep 5s`-`12s` for animations or timers; `Sleep 22s` for long looping showcases.

   For a demo that uses a binary under `<lib>/bin/` (e.g. `candy-shell`):

   ```
   Type "./bin/candy-shell run --flag value"
   ```

   For an interactive demo with key sequences, follow `sugar-prompt/.vhs/form.tape` — interleave `Type "..."`, `Tab`, `Down`, `Up`, `Enter`, and `Sleep <ms>ms` between actions. Use `400ms`-`800ms` between key actions, `1500ms` after page transitions.

   Verify: file written, syntax matches a sibling tape line-for-line on the `Set` block.

4. **Check the workflow matrix.** Read `.github/workflows/vhs.yml` lines 51-64. If the slug is not in the `all=(...)` array, add it. Group it next to its sibling category (candy-* together, sugar-* together, honey-* together) to match the existing layout. Wrap to a new line when the current line exceeds ~60 characters — match the existing wrap rhythm.

   Verify with:

   ```sh
   grep -n 'sugar-marbles' .github/workflows/vhs.yml
   ```

   Must return a hit inside the `all=(...)` block.

5. **Do NOT render locally.** CI renders on push. Local rendering would require installing `vhs` + `ttyd` + `ffmpeg` and would produce a non-deterministic GIF that wastes a CI re-render cycle when committed. If the user explicitly asks to preview locally:

   ```sh
   vhs sugar-marbles/.vhs/marbles.tape
   ```

   Do not commit the resulting GIF.

6. **Verify before completing.** Read back the new tape file and the workflow change. Confirm:
   - `Output .vhs/<demo>.gif` is the first non-comment line.
   - `Set Theme "TokyoNight"` is present.
   - `Type "..."` references a path that exists.
   - The lib is in `.github/workflows/vhs.yml`'s `all=(...)` array.
   - No GIF has been added to the working tree.

7. **Commit pattern.** When the user is ready to commit, the message follows the existing convention: `<slug>: add <demo> VHS tape`. The companion GIF will land in a follow-up auto-commit by the `commit` job (`vhs: regenerate demo GIFs`, authored by Joe Huss).

## Examples

### User says: "add a VHS demo for candy-mold's start example"

Actions:
1. Read `candy-mold/examples/start.php` — confirm it exists and exits in ~3 seconds.
2. Read `candy-core/.vhs/timer.tape` as the closest sibling (compact, non-interactive, fixed runtime).
3. Write `candy-mold/.vhs/start.tape`:

   ```
   Output .vhs/start.gif
   Set FontSize 14
   Set Width 800
   Set Height 480
   Set TypingSpeed 60ms
   Set Theme "TokyoNight"
   Type "php examples/start.php"
   Enter
   Sleep 4s
   ```

4. Grep `.github/workflows/vhs.yml` for `candy-mold` — already present at line 56. No matrix change.
5. Report: tape written, workflow already lists candy-mold, GIF will land on next push.

### User says: "record gif for new lib sugar-newthing"

Actions:
1. Verify `sugar-newthing/examples/demo.php` exists (else stop).
2. Write `sugar-newthing/.vhs/demo.tape` per the standard template.
3. Grep `.github/workflows/vhs.yml` for `sugar-newthing` — **not found**.
4. Edit `.github/workflows/vhs.yml`'s `all=(...)` array, inserting `sugar-newthing` next to its sugar-* siblings.
5. Verify with grep, report both files changed.

## Common Issues

- **Workflow triggers but no GIF appears.** The push triggered `.github/workflows/vhs.yml` because `*/.vhs/*.tape` matched, but the `render` job's matrix is built from the hand-maintained `all=(...)` array in the `changed` job. If the lib isn't listed, the matrix is empty and nothing renders. Fix: add the slug to `all=(...)` lines 51-64.

- **`Output` path produces no GIF.** The workflow's `working-directory` for the render step is the lib dir. An absolute path or a parent path writes the file outside the upload-artifact glob (`<lib>/.vhs/*.gif`). Fix: use `Output .vhs/<demo>.gif` exactly.

- **GIF is truncated mid-animation.** Final `Sleep` is shorter than the example's runtime. The recording stops when the tape ends, not when the process exits. Fix: re-run the example manually, time it, then bump `Sleep <N>s` to runtime + 0.5-1s of trailing buffer.

- **Hidden `.vhs/` dir not uploaded as artifact.** Already handled — `.github/workflows/vhs.yml` sets `include-hidden-files: true` on `actions/upload-artifact@v5` (line 197). If you fork this skill into a new workflow, preserve that flag.

- **`Set Theme "<name>"` errors on render.** Theme name is case-sensitive and must be one VHS recognises. Use `"TokyoNight"` (verified across all 90+ existing tapes) — do not substitute.

- **`Type "..."` runs the wrong binary.** Working dir is the lib dir, so `php examples/<demo>.php` and `./bin/<binary>` both resolve relative to the lib root. `composer install` ran in the previous workflow step, so `vendor/autoload.php` is available. Do not `cd` inside the tape.

- **Commit job pushes nothing.** The `commit` job's `git diff --cached --quiet` check skips when no GIFs changed. Expected when only docs/non-render-relevant files changed in the push. Confirm by checking the workflow run's `commit` job logs for `no GIF changes — skipping commit`.
