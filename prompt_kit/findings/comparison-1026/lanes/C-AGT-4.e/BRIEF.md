### C-AGT-4.e — SendMessage reaches other sessions (`session:<id>`)
- item: C-AGT-4 (sources: GAP-AGT-N3, GAP-TL-23)
- kind: gap · effort M
- approach: Turns run in a `pcntl_fork()`ed child (`src/LSP/LspExchangeLock.php:11`), so delivery must cross processes. Use a disk mailbox:
  - a new `Agents\Live\SessionMailbox`: 0700 `~/.candy-code/session-mail/<sessionId>/`, 0600 JSON files, signed with the `AgentInbox` scheme;
  - `SendMessageTool` accepts `to: "session:<id>"` from the session's own agent only, and only for a session of the same root (checked against the session store row). Sub-agents get a refusal;
  - in `PermissionGate::isNoAskTool()` (:1572), a `session:` target is NOT no-ask, so it asks like any write;
  - drains: `SessionHost::tick()` (from C-AGT-1.b) on serve, and Chat's `pumpBackgroundSessions()` (:18386) in the TUI, on the slow watch tick from C-AGT-7.a. Each message is submitted as a followup, fenced as `<session-message from="…">`, carrying no authority.
- files.create: `candy-code/src/Agents/Live/SessionMailbox.php`
- files.modify: `candy-code/src/Tools/BuiltIn/SendMessageTool.php` (`execute`, schema, description), `candy-code/src/Permissions/PermissionGate.php` (`isNoAskTool`), `candy-code/src/Host/SessionHost.php` (`tick` drain), `candy-code/src/Chat.php` (`pumpBackgroundSessions` drain), `candy-code/docs/AGENTS.md` (hub-and-spoke section), `candy-code/README.md` (tools roster block, via `php tools/gen-tool-docs.php --write` if the gloss changes)
- tests: create `candy-code/tests/Agents/Live/SessionMailboxTest.php` (0700/0600, signature refusal, ordering), `candy-code/tests/Tools/SendMessageCrossSessionTest.php` (a session target asks; a sub-agent is refused; a foreign root is refused; a served host drains it as a queued turn)
- docs/guards: `candy-code/tests/Tools/Catalog/ToolDocDriftTest.php` if the `#[BuiltInTool]` gloss changes; `docs/AGENTS.md`; a `ReadPathCensusTest` row for the mailbox reads
- serialized: [chat:pumpBackgroundSessions] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Permissions/PermissionGate.php] [docs:AGENTS.md] [docs:README.md (tools block)]
- deps: C-AGT-1.b, C-AGT-7.a, C-AGT-3.b
- phase: normal
- ruling: none
- risks: background `/bg` daemons are not targets (no inbox), which the refusal says. P-SESSION-TABS makes several sessions per TUI, so the Chat drain must key on the active session id.

---

