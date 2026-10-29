### C-EXT-3.d — `/agents new` drafts, `/agents accept` promotes
- item: C-EXT-3 (sources: GAP-AGT-07)
- kind: gap · effort M
- approach:
  - **Why the GAP's plan fails.** The GAP proposes a canned prompt that Writes `.candy-code/agents/<name>.md`. `ProtectFilesHook::WRITE_ONLY_PATTERNS` (`ProtectFilesHook.php:244`) denies that path outright, because a preset's `permissionMode:` is a self-grant. The agent therefore cannot write it.
  - **Design.**
    - Create a `NewAgentPrompt`, mirroring `NewRulePrompt`. It asks the agent to draft `.candy-code/agents-proposed/<name>.md` (an ordinary, gated write), validated against `FrontmatterKeyAudit::KNOWN[AGENT]`, never overwriting, and listing the existing presets.
    - `/agents accept <name>` is a host-side, user-initiated step. It validates the frontmatter, prints the policy keys (`permissionMode`, `tools`, `mcpServers`) and moves the file into `.candy-code/agents/`.
    - `/agents proposed` lists the drafts.
  - **Routing.** In the TUI, `Chat::handleAgentsCommand()` (:12930) sends `new` to `withInputBuf(prompt)->submit()`, the `/newrule` idiom. On serve, `AgentsHostCommand` returns a SubmitPrompt effect for `new` and handles `accept`/`proposed` itself.
- files.create: `candy-code/src/Host/Commands/NewAgentPrompt.php`
- files.modify: `candy-code/src/Host/Commands/AgentsHostCommand.php`, `candy-code/src/Chat.php` (`handleAgentsCommand`), `candy-code/builtin-commands/0800-agents.php` (argumentHint), `candy-code/lang/en.php` (`host.agents.new.*`, `cmd.agents.*`), `candy-code/docs/AGENTS_AUTHORING.md`, `candy-code/docs/COMMANDS.md` (gen)
- tests: create `candy-code/tests/Host/Commands/AgentsNewAcceptTest.php` (the prompt shape; accept validates, shows policy keys and moves; refuses an existing name). Modify `candy-code/tests/Chat/PaletteSupersetDispatchTest.php` only if the `/agents` bare form changes (it should not).
- docs/guards: gen-command-docs; AGENTS_AUTHORING.md.
- serialized: [chat:handleAgentsCommand] [commands:builtin-commands/0800-agents.php] [docs:AGENTS_AUTHORING.md] [docs:COMMANDS.md] [lang:host.agents.new.,cmd.agents.] [hot:candy-code/src/Host/Commands/AgentsHostCommand.php]
- deps: none
- phase: normal
- ruling: none
- risks: collides with C-AGT-4 (`/agent use`, Tab cycle) on AgentsHostCommand and `handleAgentsCommand`. Put the two in different waves.

