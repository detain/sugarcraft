# C-AGT-4.d — `@agent:<name>` mentions (TUI, serve, `-p`, web)

Verbatim design block (prompt_kit/findings/comparison-1026/design-agt.md:264-281):

### C-AGT-4.d — `@agent:<name>` mentions (TUI, serve, `-p`, web)
- item: C-AGT-4 (sources: GAP-AGT-20)
- kind: gap · effort M
- approach:
  - Reserve `@agent:<name>` in `ContextMentions` (`MENTION_PATTERN`/`KEYWORD_PATTERN` :73-76, `resolve()` :186). A known name becomes a framed directive attachment, "the user asks you to delegate this to agent `<name>` via the Task tool". An unknown name is a notice, never a guess.
  - The roster is a new `withAgentRoster(\Closure)`, threaded into `TurnController::userTurnMessage()` (:1033, one construction site for TUI and serve) from `Chat::userTurnMessage()` (:9925) and `SessionHost::admitPrompt`, and into `NonInteractive`'s two sites (:448, :1394).
  - TUI: `mentionPopupRows()` (:16399) offers roster names after `@agent:`.
  - Web: `Composer.vue`'s `@` popup offers them from the agents store (client-only).
- files.create: none
- files.modify: `candy-code/src/Attachments/ContextMentions.php` (patterns, `resolve`, `withAgentRoster`), `candy-code/src/Host/TurnController.php` (`userTurnMessage` param), `candy-code/src/Chat.php` (`userTurnMessage`, `mentionPopupRows`), `candy-code/src/Host/SessionHost.php` (`admitPrompt` roster pass), `candy-code/src/Cli/NonInteractive.php` (mention construction :448, :1394), `candy-code-web/src-web/components/Composer.vue`, `candy-code/README.md` (mentions section), `candy-code/docs/AGENTS.md`
- tests: modify `candy-code/tests/Attachments/ContextMentionsTest.php` (`@agent:reviewer` known → directive; unknown → notice; trailing punctuation), `candy-code/tests/Host/TurnControllerTest.php` (roster threaded), `candy-code/tests/Cli/NonInteractiveMentionsTest.php` (`-p` resolves it); modify `candy-code-web/src-web/components/__tests__/composerFilesImages.spec.ts` (agent completions)
- docs/guards: README mentions section; `docs/AGENTS.md`; rebuild `candy-code-web/dist/` (weld)
- serialized: [chat:userTurnMessage, mentionPopupRows] [headless] [hot:candy-code/src/Attachments/ContextMentions.php] [hot:candy-code/src/Host/TurnController.php] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code-web/src-web/components/Composer.vue] [docs:README.md] [docs:AGENTS.md]
- deps: C-AGT-3.a, C-AGT-3.b
- phase: normal
- ruling: none
- risks: `ContextMentions.php` is also edited by C-AGT-2.a, C-AGT-6.b and C-AGT-6.d, and `TurnController.php` by C-WEB-2 (skill hoist). Keep them in different waves.

## Orchestrator addendum (co-tenant law)
- Deps C-AGT-4.a/4.b/4.c welded (primary-agent core, /agent use + Alt+A, web agent select + SessionSummary.agent).
- C-AGT-4.e (fixer ACTIVE, own worktree) owns src/Tools/BuiltIn/SendMessageTool.php + src/Agents/Live/SessionMailbox.php + mail lang keys — DO NOT EDIT.
- Chat.php: mention-resolution hunk only, distinct region; lang keys `agent.mention.*` only.
- SessionHost/serve surface: my section only.
- Web touched → dist rebuild is WELD duty; do not commit dist.
