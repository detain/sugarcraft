### C-SES-7.g — `/handoff --remote <url>` teleport
- item: C-SES-7 (sources: GAP-SES-14)
- kind: gap · effort S
- approach:
  - `/handoff --remote <url>` exports the current session (`Exporter::toJson`).
  - `RemoteSessionPush` connects through `Attach::connect()`, with the token from the state dir or env, the same way `attach` resolves it.
  - It calls `session.import`.
  - It prints `candy-code attach <new-id> --url <url>`.
  - `HandoffHostCommand` parses `--remote`; the hint `cmd.handoff.hint` is a Lang key, so `3600-handoff.php` is not edited.
- files.create: `candy-code/src/Session/Import/RemoteSessionPush.php`, `candy-code/tests/Server/HandoffRemotePushTest.php`
- files.modify: `candy-code/src/Host/Commands/HandoffHostCommand.php` (`--remote` parsing → push effect), `candy-code/lang/en.php` (`cmd.handoff.hint`, `cmd.handoff.remote.*`), `candy-code/docs/COMMANDS.md` (regen), `candy-code/docs/SERVER.md` (the teleport paragraph)
- tests: create `candy-code/tests/Server/HandoffRemotePushTest.php`: against a loopback serve, the session lands remotely and the printed attach line is correct; a refused token surfaces a clear error.
- docs/guards: the COMMANDS.md regen; `CommandDocsDriftTest`
- serialized: [lang:cmd.handoff.remote] [docs:COMMANDS.md] [docs:SERVER.md]
- deps: C-SES-7.e
- phase: normal
- ruling: none
- risks: Pushing to a non-loopback URL over plain `ws://` exposes the transcript in transit. Refuse non-loopback `ws://` until C-WEB-5 (TLS, LATE) exists; there is no dependency on it.

