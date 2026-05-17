# Step 07.12 — candy-mosaic Renderer::delete() API + WezTerm detection cleanup

**Source:** `leftover_updates_later.md` candy-mosaic P0 #2
**Branch:** `ai/mosaic-delete-wezterm`

## Deliverable

Two related cleanups:

1. **`Renderer::delete()`** API — every renderer gains a `delete(string $imageId): string`
   that emits the protocol-specific "remove this image" sequence.
   Today only Iterm2Renderer has delete-ish bits; standardize across
   all renderers.

2. **WezTerm detection** — note: step 01.13 already fixed this. If
   that step already shipped, this step's WezTerm portion is a no-op
   verification. If not yet shipped, do it here.

## Files

**Modify:**
- `candy-mosaic/src/Renderer/Renderer.php` (interface) — add
  `delete(string $imageId): string`.
- `candy-mosaic/src/Renderer/{Kitty,Iterm2,Sixel,HalfBlock,Quarter}Renderer.php`
  — implement.
- `candy-mosaic/src/Detect.php` — if WezTerm still classifies as both
  Kitty and iTerm2 candidate, fix to Kitty only.

## Tests

- `candy-mosaic/tests/Renderer/DeleteApiTest.php` — for each renderer,
  call delete and assert the emitted sequence.

## Acceptance

- `cd candy-mosaic && vendor/bin/phpunit --filter "Delete|WezTerm"` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
