---
name: record-vhs-demo
description: Creates a VHS .tape file at <slug>/.vhs/<demo>.tape driving php examples/<demo>.php with TokyoNight theme and the standard FontSize/Width/Height/Type/Enter/Sleep sequence. Also adds the lib to the hand-maintained matrix in .github/workflows/vhs.yml when missing. Use when user says 'add VHS demo', 'record gif', 'new tape file', 'add a tape for <slug>'. Do NOT use for editing an existing tape's visual style, rendering GIFs locally (CI does that via vhs.yml), or for non-visual libs (candy-pty, FFI/syscall wrappers) — those are exempt from the .vhs/ + matrix requirement.
paths:
  - */.vhs/*.tape
  - .github/workflows/vhs.yml
---
# Record VHS demo

## Critical

- Tape path is **always** under the lib's VHS directory. The `Output` directive inside is **always** relative to the lib root: `Output <dir>/<demo>.gif` where `<dir>` is the lib's tape directory. Never write absolute paths inside the tape.
- Theme is **always** `Set Theme "TokyoNight"`. Don't pick a different theme without explicit user request — every existing tape uses it and the website tile grid assumes the same palette.
- The CI matrix in `.github/workflows/` for VHS rendering is **hand-maintained** (a bash array in the workflow file). A tape that exists but whose lib isn't in that array will silently never render. Verify the lib is present before claiming the work is done.
- Do **not** run `vhs` locally to commit a rendered output. CI renders and commits via the workflow's commit job. Tape files only — no rendered output in the same PR.
- The matching example script (PHP entrypoint or bin command) must already exist. If it doesn't, stop and ask the user — don't fabricate an example script just to make the tape runnable.
- Skip this skill entirely for non-visual libs: `candy-pty`, FFI/syscall wrappers, transport-only libs. They're explicitly listed (or omitted) from the workflow matrix and don't carry tape directories.

## Instructions

1. **Confirm the lib is visual and the example exists.**

   ```
   Glob "<slug>/examples/*.php"
   ```

   If a specific demo name wasn't given, list candidates and ask the user. If none exist, stop — the tape can't drive a missing script.

   Check the lib already has a VHS directory or other tapes:

   ```
   Glob "<slug>/.vhs/*.tape"
   ```

   If yes, **read one of them first** and mirror its `FontSize`/`Width`/`Height` choices unless the new demo genuinely needs different dimensions.

2. **Pick dimensions from the established convention.** Use the closest match to neighbouring tapes. Defaults observed across the repo:

   - `Set FontSize 14` for most libs; `Set FontSize 16` only for sugar-bits-style component showcases.
   - `Set Width 700` is the canonical width. Use `600` only for narrow component demos (single spinner, single status line).
   - `Set Height` scales to output: short status (`180`–`220`), medium TUI (`320`), full-screen game/board (`440`–`460`).
   - `Set TypingSpeed 60ms` whenever `Type` is used to invoke or interact (omit only for pure-display tapes that just `Type` the launch line).

3. **Write the tape using this exact skeleton:**

   ```
   # VHS tape — <one-line description of what the demo shows>.
   Output <relative path to rendered output>

   Set FontSize 14
   Set Width 700
   Set Height 320
   Set TypingSpeed 60ms
   Set Theme "TokyoNight"

   Type "php <example entrypoint>"
   Enter
   Sleep 2s
   ```

   For interactive demos, append scripted input below the `Enter` using bare-name VHS keys and per-step `Sleep`:

   ```
   Up    Sleep 200ms
   Down  Sleep 400ms
   Space Sleep 600ms
   Type "f" Sleep 600ms
   Type "q"
   ```

   For binary-driven demos, swap the `Type` line for `Type "./bin/<name> <args>"`. Do **not** add `cd` — VHS already runs in the lib's working dir when invoked from the repo root with a path-prefixed tape, and CI runs each tape from inside the matrix lib's working dir.

4. **Verify the lib is in the CI matrix.** Read the VHS workflow file under `.github/workflows/` and locate its bash array of libs. If the lib isn't there:

   - Insert the slug alphabetically into the existing grouping (don't reformat the array — preserve the multi-line layout).
   - The workflow's path filters already cover the lib's tape directory, so adding the tape file alone will trigger renders, but the matrix line must exist or the render job skips the lib silently.

5. **Add the README hero image only if the lib has none.** If the lib's `README.md` doesn't already have a hero image line just below the `<!-- BADGES:END -->` block, add one (canonical placement: directly under the badges). If a hero image already exists, leave it — don't replace.

6. **Final verification gate.** Before reporting done:

   - The new tape file exists under the lib's tape directory.
   - `Grep -n "<slug>"` against the VHS workflow file shows it inside the bash array block.
   - The tape contains exactly one `Output` line and exactly one `Set Theme "TokyoNight"` line.
   - **Do NOT** run `vhs` locally and **do NOT** stage any rendered output. Tape + workflow edit only.

## Examples

**User:** "Add a VHS demo for sugar-table showing the basic scrolling table."

**Actions taken:**

1. `Glob "sugar-table/examples/*.php"` → confirms a basic example exists.
2. `Glob "sugar-table/.vhs/*.tape"` → empty, so this is the first tape; pick canonical defaults (FontSize 14, Width 700, Height 320).
3. Write the new tape:

   ```
   # VHS tape — sugar-table basic scrolling table demo.
   Output .vhs/basic.gif

   Set FontSize 14
   Set Width 700
   Set Height 320
   Set TypingSpeed 60ms
   Set Theme "TokyoNight"

   Type "php examples/basic.php"
   Enter
   Sleep 500ms
   Down Sleep 200ms
   Down Sleep 200ms
   Down Sleep 400ms
   Type "q"
   ```

4. `Grep -n "sugar-table"` against the VHS workflow → not in the bash array. Edit the array to insert `sugar-table` next to its alphabetical neighbours (`sugar-stickers sugar-table`).
5. `Read sugar-table/README.md` head → no hero image. Add a hero image line directly under the badges block.

**Result:** Three changes in one PR — the new tape, the workflow matrix entry, the README hero image. No rendered output committed; CI's commit job will push it on the next master merge.

## Common Issues

- **Rendered output never appears on master after PR merge.** The lib isn't in the bash array in the VHS workflow. The render matrix iterates only that array — tapes for missing libs are skipped silently. Fix: add the slug to the array and re-trigger with `gh workflow run` against the workflow.
- **`Error: file does not exist: ...` in render logs.** The `Output` directive uses an absolute or wrong-prefix path. It must be relative to the lib root.
- **`PHP Fatal error: Uncaught Error: ...: No such file`.** The tape references an example script that doesn't exist. Don't `touch` an empty file to silence it — go back and confirm the user wants you to also write the example, or pick the actual existing demo name.
- **Render finishes but output is blank/empty.** Final `Sleep` is shorter than the example's startup time. Bump the post-`Enter` `Sleep` to at least `1500ms` for ReactPHP-driven TUIs; 500ms is only safe for static `Type`+`echo` style scripts.
- **`composer validate --strict` complaint after editing the workflow.** Unrelated — that's the `"sugarcraft/*": "@dev"` warning documented in `AGENTS.md`. Drop `--strict`. Tape work doesn't touch composer.
- **Multiple tapes in one lib produce a single overwriting render.** Each tape needs a unique `Output` line matching the tape's basename — CI loops over each tape in the directory, and a duplicated `Output` clobbers the prior render.
