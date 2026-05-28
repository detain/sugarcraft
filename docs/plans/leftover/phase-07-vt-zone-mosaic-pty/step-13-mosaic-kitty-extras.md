# Step 07.13 — candy-mosaic Kitty virtual-image (a=p) + Kitty f=1 zlib compression

**Source:** `leftover_updates_later.md` candy-mosaic P1 #4 + #5
**Branch:** `ai/mosaic-kitty-extras`

## Deliverable

Two Kitty protocol extensions:

- **a=p** virtual-image placement — `KittyOptions` flag is present but
  unwired. Wire it: emit `a=p` instead of `a=T` for placements.
- **f=1** zlib compression — compress image payloads before
  transmission. Reduces bandwidth dramatically for SSH-mediated
  renders.

## Files

**Modify:**
- `candy-mosaic/src/Renderer/KittyRenderer.php` — honor
  `KittyOptions::$useVirtual` and `KittyOptions::$compress`.
- `candy-mosaic/src/KittyOptions.php` — both flags become non-experimental;
  doc updates.

## Tests

- `candy-mosaic/tests/Renderer/KittyVirtualImageTest.php` — assert
  `a=p` chunk emitted when virtual mode is on.
- `candy-mosaic/tests/Renderer/KittyZlibTest.php` — assert payload is
  zlib-compressed when `compress=true`.

## Acceptance

- `cd candy-mosaic && vendor/bin/phpunit --filter "Kitty"` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
