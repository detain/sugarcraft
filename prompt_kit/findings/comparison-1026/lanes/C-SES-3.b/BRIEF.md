### C-SES-3.b — `/share list` across sessions; revoke hardening
- item: C-SES-3 (+ C-SES-9's SES-3-P3) (sources: SES-3-P1, SES-3-P3, p2-miss: cross-session `/share list`)
- kind: fix · MAJOR · effort S
- approach:
  - **`/share list`.** New subcommand: render `ShareStore::liveAll()` as token prefix, session name (or "deleted session"), expiry and format. `/share revoke` with no token now lists every live share, not just `liveForSession(currentSessionId)` (`src/Commands/ShareCommand.php:228`).
  - **SES-3-P3.**
    - `ShareHostCommand` wraps `ob_start()…execute()` in `try/finally { ob_get_clean() }`.
    - `ShareCommand::revoke()` catches `RuntimeException`/`PDOException` from `shareStoreFor()` (`:258-269`) and `ShareStore::revoke()`, the same way `serve()` already does.
  - **Hint.** `cmd.share.hint` gains `list`. The hint is a Lang key, so `0400-share.php` is not edited.
- files.create: none
- files.modify: `candy-code/src/Commands/ShareCommand.php` (execute(), new list(), revoke()), `candy-code/src/Host/Commands/ShareHostCommand.php` (run()), `candy-code/lang/en.php` (`cmd.share.hint`, `cmd.share.list.*`), `candy-code/docs/COMMANDS.md` (regen via `candy-code/tools/gen-command-docs.php`)
- tests: create `candy-code/tests/Host/ShareHostCommandBufferTest.php`: a read-only database gives a failure result and `ob_get_level()` is unchanged. Modify `candy-code/tests/Commands/ShareCommandTest.php`: the list includes other sessions' and deleted sessions' shares; bare revoke lists everything.
- docs/guards: `docs/COMMANDS.md` regen; `CommandDocsDriftTest`
- serialized: [lang:cmd.share.] [docs:COMMANDS.md]
- deps: C-SES-3.a
- phase: normal
- ruling: none
- risks: none notable

