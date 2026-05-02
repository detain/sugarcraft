# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[pattern]** Before `gh pr create` / `gh pr merge` in this repo, prefix the command with `unset GITHUB_TOKEN &&` — the workflow used `unset GITHUB_TOKEN && gh pr create ...` and `unset GITHUB_TOKEN && gh pr merge ...` to ensure `gh` falls back to the user's stored auth token rather than a stale env var that may be present in the shell. Without this, `gh` can attach with the wrong identity or fail silently.
- **[gotcha]** `Input` field in sugar-prompt does NOT have `withDefault()` — that method only exists on certain field types. A test added `Input::new('a')->withDefault('A')` and failed with `Call to undefined method`. To set initial values on an `Input`, use `setValue(...)` (or whatever the field-specific setter is); don't assume every Field carries `withDefault`.
- **[pattern]** When adding a long-running child-process feature that needs captured output (e.g. `--show-output`), spawn `RealProcess` with `captureStdout: true` and use non-blocking pipes (`stream_set_blocking($pipe, false)`). Drain the pipes inside `exitCode()` BEFORE checking `proc_get_status`, otherwise the child can deadlock waiting for the parent to read stdout once the OS pipe buffer fills.
