# Step 12.09 — Expect-style API expansion (DEFERRED)

**Source:** `leftover_updates.md` deferred section
**Branch:** `ai/expect-expansion`
**Deferred per user instruction.**

## Deliverable

Current `Expect` is a synchronous read / write / match loop. Once
`candy-skate` (or equivalent task-automation lib) needs scripted
dialogs, extend with:

- `expectRegex(string $pattern)`.
- `interact(): never` (Python pexpect's interact-mode handoff).
- `before` / `after` capture buffers.
- `expectExact` / `expectAny` variants.

## Files

**Modify:** `candy-pty/src/Expect.php`.

## Acceptance

- New methods covered by tests; documented in README.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
