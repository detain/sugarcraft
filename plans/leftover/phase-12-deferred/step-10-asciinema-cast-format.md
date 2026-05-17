# Step 12.10 — asciinema .cast format conversion (DEFERRED)

**Source:** `leftover_updates.md` P6.5-LO-05 + candy-vcr research §13 #L2
**Branch:** `ai/vcr-cast-format`
**Deferred per user instruction.**

## Deliverable

`candy-vcr cast import` / `candy-vcr cast export` to/from asciinema's
v2 JSON-lines format. Cross-tool replay parity is the main user
request — record in candy-vcr, replay in asciinema (or vice versa).

## Files

**Create:**
- `candy-vcr/src/Format/AsciinemaV2Format.php` — already exists as
  `AsciinemaFormat`; verify the conversion is bidirectional or extend.
- `candy-vcr/src/Cli/{CastImportCommand,CastExportCommand}.php`.

## Tests

- Round-trip a sample asciinema `.cast` file.

## Acceptance

- `candy-vcr cast import session.cast --output session.jsonl` works.
- Reverse direction also works.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
