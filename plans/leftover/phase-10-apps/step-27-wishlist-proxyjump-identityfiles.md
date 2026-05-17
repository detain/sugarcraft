# Step 10.27 — sugar-wishlist proxy_jump + identity_files + description rendering

**Source:** `leftover_updates_later.md` sugar-wishlist P1.1-3
**Branch:** `ai/wishlist-proxyjump-identityfiles`

## Deliverable

- `proxy_jump` field on `Endpoint` + `-J` flag in launcher.
- `identity_files` array with fallback (try each).
- `description` rendering in `Picker::draw()`.

## Files

**Modify:** `sugar-wishlist/src/Endpoint.php` — new fields.
`sugar-wishlist/src/Launcher.php` — `-J` and `-i` flag wiring.
`sugar-wishlist/src/Picker.php` — render description.

## Tests

- One per feature.

## Acceptance

- `cd sugar-wishlist && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
