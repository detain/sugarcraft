# Step 07.14 — candy-mosaic Transparent HalfBlock bg + 256-color Sixel fallback

**Source:** `leftover_updates_later.md` candy-mosaic P1 #6 + #7
**Branch:** `ai/mosaic-transparent-sixel-fallback`

## Deliverable

- **P1 #6** — `HalfBlockRenderer` should preserve terminal background
  for fully-transparent pixels. Today it draws a solid color.
- **P1 #7** — 256-color Sixel fallback for terminals that don't
  support truecolor Sixel.

## Files

**Modify:**
- `candy-mosaic/src/Renderer/HalfBlockRenderer.php` — alpha=0 pixels
  emit no fg/bg, falling back to terminal default.
- `candy-mosaic/src/Renderer/SixelRenderer.php` — palette negotiator
  picks 256 colors when truecolor isn't available.

## Tests

- `candy-mosaic/tests/Renderer/HalfBlockTransparentTest.php`.
- `candy-mosaic/tests/Renderer/Sixel256FallbackTest.php`.

## Acceptance

- `cd candy-mosaic && vendor/bin/phpunit --filter "HalfBlockTransparent|Sixel256"` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
