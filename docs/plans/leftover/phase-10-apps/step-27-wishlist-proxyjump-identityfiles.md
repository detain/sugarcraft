# Step 10.27 — candy-ssh-launcher proxy_jump + identity_files + description rendering

**Source:** `leftover_updates_later.md` candy-ssh-launcher P1.1-3
**Branch:** `ai/wishlist-proxyjump-identityfiles`

## Deliverable

- `proxy_jump` field on `Endpoint` + `-J` flag in launcher.
- `identity_files` array with fallback (try each).
- `description` rendering in `Picker::draw()`.

## Files

**Modify:** `candy-ssh-launcher/src/Endpoint.php` — new fields.
`candy-ssh-launcher/src/Launcher.php` — `-J` and `-i` flag wiring.
`candy-ssh-launcher/src/Picker.php` — render description.

## Tests

- One per feature.

## Acceptance

- `cd candy-ssh-launcher && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
