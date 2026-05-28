# Step 08.14 — candy-freeze VS Code/chroma JSON theme + font embed + line highlight

**Source:** `leftover_updates_later.md` candy-freeze §4.1.3 + §4.2.1 + §4.3.2
**Branch:** `ai/freeze-themes-font-linehighlight`

## Deliverable

- VS Code / chroma JSON theme compatibility (load `*.json` theme
  files; map their token names to candy-freeze styles).
- Font embedding in SVG via base64 TTF.
- Line highlighting (`--highlight 5-10` adds a background-color
  range).

## Files

**Create:**
- `candy-freeze/src/Theme/VsCodeThemeLoader.php`.
- `candy-freeze/src/Theme/ChromaThemeLoader.php`.

**Modify:**
- `candy-freeze/src/Renderer/SvgRenderer.php` — `--font path/to/font.ttf`
  embeds. `--highlight start-end` adds.

## Tests

- One test per feature.

## Acceptance

- `cd candy-freeze && vendor/bin/phpunit --filter "VsCode|Chroma|FontEmbed|LineHighlight"` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
