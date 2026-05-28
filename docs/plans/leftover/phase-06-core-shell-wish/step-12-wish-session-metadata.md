# Step 06.12 — candy-wish Session SSH-protocol metadata

**Source:** `leftover_updates_later.md` candy-wish §4.5 P2
**Branch:** `ai/wish-session-metadata`

## Deliverable

Expand `Session` with SSH-protocol metadata: `sessionId`, `authMethod`,
`keyFingerprint`, `clientVersion`, `serverVersion`. Today middleware
has limited visibility into protocol-level details.

## Files

**Modify:**
- `candy-wish/src/Session.php` — add the new readonly properties.
- `candy-wish/src/Transport/InProcessTransport.php` — populates them
  from the protocol handshake.

**Tests:**
- `candy-wish/tests/SessionMetadataTest.php` — fixture session;
  assert metadata is populated post-handshake.

## Acceptance

- `cd candy-wish && vendor/bin/phpunit --filter SessionMetadata` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
