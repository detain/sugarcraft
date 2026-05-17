---
name: vhs-tape-record
description: Authors a `<slug>/.vhs/*.tape` VHS demo file and wires it into `.github/workflows/vhs.yml` so CI re-renders the GIF. Sets `Set Theme "TokyoNight"`, 800x480 dims, `Type "php examples/<demo>.php"` boilerplate. Use when user says 'add a demo', 'record VHS', 'new tape', 'regenerate GIF', or creates/edits a file under `<slug>/.vhs/`. Capabilities: scaffolds the .tape file, adds the slug to the workflow `all=(...)` array, registers required non-default PHP extensions (ext-ssh2/ext-gd/ext-ffi) in `extensions:`. Do NOT use for non-visual primitive libs (`candy-pty`, FFI bindings, codecs) — they're matrix-exempt; do NOT use for changes to the rendered `.gif` itself (CI overwrites it).
paths:
  - '**/.vhs/*.tape'
  - .github/workflows/vhs.yml
---
# vhs-tape-record

## Critical

- **`.github/workflows/vhs.yml` is HAND-MAINTAINED.** Adding a `.tape` file is NOT enough — if the lib's slug is missing from the `all=(...)` array (and from `extensions:` when it needs non-default PHP extensions), CI silently skips the lib and the `.gif` never re-renders. Verify both edits before claiming completion.
- **Matrix-exempt libs** — `candy-pty`, raw FFI bindings, codecs, and other non-visual primitives MUST NOT be added to the matrix. If the lib has no TUI output, refuse and tell the user the lib is matrix-exempt (call this out in the PR body too).
- **Author the `examples/<demo>.php` file FIRST.** A `.tape` that runs `Type "php examples/<demo>.php"` against a missing script renders an empty GIF. Confirm `<slug>/examples/<demo>.php` exists and exits cleanly under ~3 seconds before recording.
- **Never edit the rendered `.gif` directly** — `.github/workflows/vhs.yml` overwrites it on every push touching `<slug>/.vhs/`.

## Instructions

1. **Confirm the lib qualifies.** Visual/TUI libs only — components, prompts, charts, dashboards, spinners. If the lib is `candy-pty`, an FFI binding, a parser, or a codec, STOP. Tell the user: "`<slug>` is matrix-exempt — non-visual primitives don't get VHS demos." Verify by reading `<slug>/README.md` and checking for screen-output APIs. Verify before proceeding.

2. **Author the example script.** Create `<slug>/examples/<demo>.php` if it does not already exist. Top of file: `<?php declare(strict_types=1); require __DIR__ . '/../vendor/autoload.php';`. The script must self-terminate in under ~3 seconds (no `while (true)` loops without a break). Run it once locally to confirm it exits cleanly: `cd <slug> && php examples/<demo>.php`. Verify the script exits with status 0 before proceeding.

3. **Create the `.tape` file** at `<slug>/.vhs/<demo>.tape`. Use this canonical skeleton (mirrors existing tapes under `sugar-bits/.vhs/`, `candy-shine/.vhs/`, etc.):

   ```
   Output <demo>.gif

   Set Theme "TokyoNight"
   Set FontSize 14
   Set Width 800
   Set Height 480
   Set Padding 20

   Type "php examples/<demo>.php"
   Enter
   Sleep 2s
   ```

   - `Output` is relative to the `.tape` file — emit a bare filename, never an absolute path.
   - Keep the theme `TokyoNight` unless the demo is *about* theming (then use the theme being demoed).
   - For interactive demos, replace `Sleep 2s` with a scripted sequence: `Sleep 500ms` · `Type "j"` · `Sleep 300ms` · `Enter` · `Sleep 2s`.
   - Widen to 1000–1200 ONLY if the demo wraps at 800; never go below 800x480.

   Verify the file parses by running `vhs <slug>/.vhs/<demo>.tape` locally if `vhs` is on PATH. If not, move on — CI will catch syntax errors.

4. **Add the slug to `.github/workflows/vhs.yml`** in the `all=(...)` bash array. The array is alphabetical within prefix groups (Candy first, then Honey, then Sugar, then super-candy). Read the file, locate `all=(`, insert `<slug>` in the correct group. Verify the slug is present before proceeding.

5. **Register non-default PHP extensions** if the example requires any. Open `.github/workflows/vhs.yml`, find the step that calls `shivammathur/setup-php` (or the matrix's `extensions:` key). The default set typically includes `mbstring`, `intl`, `tokenizer`. Add any of these the lib needs:
   - `ssh2` — `ext-ssh2` (used by `candy-wish`, `sugar-skate`)
   - `gd` — `ext-gd` (image rendering)
   - `ffi` — `ext-ffi` (FFI bindings — but recall non-visual FFI libs are exempt)
   - `shmop` — `ext-shmop` (shared memory; gate with `os: ubuntu-latest` if Windows-incompatible)
   - `sockets` — `ext-sockets` (networking demos)

   Match the existing format in the file (comma-separated string, e.g. `extensions: mbstring, intl, ssh2`). Verify the extension is listed before proceeding.

6. **Reference the rendered GIF from the lib's `README.md`** under a `## Demo` heading using the canonical URL pattern:

   ```markdown
   ## Demo

   ![<demo>](https://raw.githubusercontent.com/detain/sugarcraft/master/<slug>/.vhs/<demo>.gif)
   ```

   Skip this step if the README already has a demo block and you're only adding a secondary tape. Verify the README still renders (preview the markdown).

7. **Commit the new files.** Stage `<slug>/.vhs/<demo>.tape`, `<slug>/examples/<demo>.php`, `.github/workflows/vhs.yml`, and (if touched) `<slug>/README.md`. Do NOT stage a `.gif` — CI generates it on push. Confirm pre-commit hook handles the Caliber refresh (per CLAUDE.md). The first push triggers `.github/workflows/vhs.yml`; the resulting `.gif` lands in a follow-up commit by the CI bot.

## Examples

**User says:** "Add a VHS demo for the new `Sparkline` mode in sugar-charts."

**Actions taken:**
1. Confirm `sugar-charts` is visual — yes, it's a chart lib.
2. Read `sugar-charts/examples/` to see the existing example layout, then create `sugar-charts/examples/sparkline.php` rendering a 60-sample sparkline that prints and exits.
3. Run `cd sugar-charts && php examples/sparkline.php` — confirm clean exit.
4. Create `sugar-charts/.vhs/sparkline.tape`:
   ```
   Output sparkline.gif

   Set Theme "TokyoNight"
   Set FontSize 14
   Set Width 800
   Set Height 480
   Set Padding 20

   Type "php examples/sparkline.php"
   Enter
   Sleep 2s
   ```
5. Edit `.github/workflows/vhs.yml` — `sugar-charts` is already in `all=(...)`, so no array edit needed. No extra extensions needed.
6. Append the GIF reference to `sugar-charts/README.md` under the existing `## Demo` heading.
7. Stage + commit + push. CI rebuilds the GIF on the next workflow run.

**Result:** A new `sparkline.gif` appears at `sugar-charts/.vhs/sparkline.gif` after the `vhs.yml` workflow completes, embedded in the README.

---

**User says:** "Record a tape for candy-wish showing an interactive SSH session."

**Actions taken:**
1. Confirm `candy-wish` is visual — yes, TUI SSH wish-server demo.
2. Create `candy-wish/examples/ssh-session.php` (or reuse an existing example). The demo must self-terminate (e.g. `exit` after 2 seconds in-script).
3. Create `candy-wish/.vhs/ssh-session.tape` with scripted keystrokes:
   ```
   Output ssh-session.gif

   Set Theme "TokyoNight"
   Set FontSize 14
   Set Width 1000
   Set Height 600
   Set Padding 20

   Type "php examples/ssh-session.php"
   Enter
   Sleep 1s
   Type "hello"
   Sleep 500ms
   Enter
   Sleep 2s
   ```
4. Edit `.github/workflows/vhs.yml`:
   - Confirm `candy-wish` is in `all=(...)` — add it if missing.
   - Add `ssh2` to the `extensions:` list since `candy-wish` requires `ext-ssh2`.
5. Add the GIF reference to `candy-wish/README.md`.
6. Commit + push.

**Result:** Workflow renders `candy-wish/.vhs/ssh-session.gif` with the keystrokes baked in.

## Common Issues

- **"Workflow ran but no GIF appeared."** The lib is missing from the `all=(...)` array in `.github/workflows/vhs.yml`. Re-read the file, confirm the slug literal is in the array, push a fix commit.

- **"GIF rendered but is empty / blank screen."** The `examples/<demo>.php` script is missing, throws an uncaught exception, or doesn't produce visible output before `Sleep 2s` elapses. Run the script locally: `cd <slug> && php examples/<demo>.php` — fix any errors, increase `Sleep` if the script needs more time, or add an explicit final render call.

- **"Workflow fails with `extension not found` or `Class \"SSH2\" not found`."** The required PHP extension is missing from `.github/workflows/vhs.yml`'s `extensions:` key. Add the bare extension name (e.g. `ssh2`, not `ext-ssh2`) to the comma-separated list.

- **"VHS errors `unknown command "Set Themes"`."** Tape grammar is case-sensitive and singular: `Set Theme`, not `Set Themes`; `Type`, not `type`; `Enter`, not `enter`. Re-check the tape against the canonical skeleton in step 3.

- **"GIF renders but text is cut off on the right."** Width too small. Bump `Set Width 800` to `1000` or `1200`. Keep height proportional (`Set Height` ~ 60% of width).

- **"`composer validate --strict` fails after my edits."** Unrelated — `--strict` flags every `"sugarcraft/*": "@dev"` path-repo. Drop `--strict` (per CLAUDE.md Gotchas).

- **"Tape runs forever / GIF is 10MB."** The example script has an infinite loop or hangs on stdin. Either add a hard timeout inside the script (`pcntl_alarm(3)`), or have the script auto-exit after N frames. Don't try to fix this with a shorter `Sleep` — the tape will still record the hung process up to the sleep duration.

- **"User asked for a VHS demo on `candy-pty` / an FFI binding."** Refuse politely: "`candy-pty` is matrix-exempt — it's a non-visual primitive (PTY allocator). VHS demos are reserved for libs with TUI output. Add a `## Why no demo` note to its README if needed."