# Step 12.05 — Sidecar binary fallback (DEFERRED)

**Source:** `leftover_updates.md` deferred section
**Branch:** `ai/pty-sidecar-binary`
**Deferred per user instruction.**

## Deliverable

Small static binary (Go using `creack/pty` or Rust using `portable-pty`)
that talks to PHP over framed stdio messages. Same `PtySystem`
interface as the FFI backend. Avoids ConPTY threading entirely;
downside is Composer post-install download per OS/arch.

## Files

- `candy-pty/src/Sidecar/SidecarPtySystem.php`.
- `candy-pty/bin/sidecar/<os>-<arch>/sugar-pty-sidecar` (binary
  artifacts).
- Composer post-install script that downloads the right binary.
- `SUGARCRAFT_PTY_BACKEND=sidecar` (from step 01.08) wires this.

## Acceptance

- `SUGARCRAFT_PTY_BACKEND=sidecar vendor/bin/phpunit` green for the
  full integration suite.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
