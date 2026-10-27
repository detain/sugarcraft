### C-SEC-4.a — Served sessions honour `trustedProjectCommands`
- item: C-SEC-4 (sources: GAP-CMD-02, k2-sum live defect)
- kind: fix · MAJOR · effort S
- approach:
  - Root cause: two served callers of `CommandLoader::loadAll()` omit its second argument, `bool $projectCommandsTrusted` (`src/Commands/CommandLoader.php:605`):
    - `SessionHost::__construct()` (`candy-code/src/Host/SessionHost.php:240-244`);
    - `CommandMethods::files()` (`src/Protocol/Methods/CommandMethods.php:131`).
  - A trusted project's built-in override therefore runs in the TUI (`Chat.php:1798` passes it) but not over serve or the web.
  - Fix: pass `$workspace->projectCommandsTrusted` at both sites.
- files.create: none
- files.modify: `candy-code/src/Host/SessionHost.php` (ctor `customCommands`), `candy-code/src/Protocol/Methods/CommandMethods.php` (`files()`, one argument; no schema, event or gen-chain change)
- tests: create `candy-code/tests/Host/ServedProjectCommandTrustTest.php`. It mirrors the TUI precedence pin: a trusted project's override wins in a served host and in `command.list`, and an untrusted one is refused.
- docs/guards: none
- serialized: [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Protocol/Methods/CommandMethods.php] (body-only; see Notes)
- deps: none
- phase: normal
- ruling: none
- risks: none beyond the precedence pin. The served path now matches the TUI exactly.

