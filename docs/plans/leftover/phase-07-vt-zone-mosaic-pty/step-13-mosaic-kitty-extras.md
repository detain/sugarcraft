# Step 07.13 — sugar-image Kitty virtual-image (a=p) + Kitty f=1 zlib compression

**Source:** `leftover_updates_later.md` sugar-image P1 #4 + #5
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

- `sugar-image/src/Renderer/KittyRenderer.php` — honor
  `KittyOptions::$useVirtual` and `KittyOptions::$compress`.
- `sugar-image/src/KittyOptions.php` — both flags become non-experimental;
  doc updates.

## Tests

- `sugar-image/tests/Renderer/KittyVirtualImageTest.php` — assert
  `a=p` chunk emitted when virtual mode is on.
- `sugar-image/tests/Renderer/KittyZlibTest.php` — assert payload is
  zlib-compressed when `compress=true`.

## Acceptance

- `cd sugar-image && vendor/bin/phpunit --filter "Kitty"` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
