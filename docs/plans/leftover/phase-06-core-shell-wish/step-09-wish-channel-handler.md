# Step 06.09 — candy-wish ChannelHandler

**Source:** `leftover_updates_later.md` candy-wish §4.2 P1
**Branch:** `ai/wish-channel-handler`

## Deliverable

Add a `ChannelHandler` layer that owns SSH channel-level messages:
pty-req, window-change, shell, exec, signal, env, break. Today these
are scattered through transport code.

## Files

**Create:**
- `candy-wish/src/Channel/ChannelHandler.php` — interface.
- `candy-wish/src/Channel/DefaultChannelHandler.php` — wires the
  common case (pty + shell).
- `candy-wish/src/Channel/Msg/{PtyReq,WindowChange,Shell,Exec,Signal,Env,Break}Msg.php`.

**Modify:**
- `candy-wish/src/Transport/InProcessTransport.php` — dispatches
  channel messages to the handler instead of inline logic.

**Tests:**
- `candy-wish/tests/Channel/DefaultChannelHandlerTest.php` — feed each
  Msg type, assert correct downstream effect.

## Acceptance

- `cd candy-wish && vendor/bin/phpunit --filter Channel` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
