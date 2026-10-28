# P-TABS.e: App hosts many Chats: scoped routing, park/unpark, background streaming (GAP-SES-12)

- wave ? · area picks · kind gap · effort M · effort ? · phase normal
- item: P-SESSION-TABS (sources: GAP-SES-12)
- deps: P-TABS.b, P-TABS.d, C-TOOL-3.c (session-owned background shells, so tabs never share one registry)
- serialized: [chat:withNoticeDrain (new wither)] [hot:candy-code/src/App/App.php] [hot:candy-code/src/Commands/KeyBindingRegistry.php] [docs:README.md] [docs:ARCHITECTURE.md]
- ruling: none
- risks: 
- Process statics on Chat (`clickTracker`, `textSelection`, `pressGesture`, `src/Chat.php:6137-6249`) are foreground-only. Reset them on switch.
- Each busy tab is one forked child, so many concurrent tabs multiply provider spend. Show the count in the title (P-TABS.g).

## Files
- create: none
- modify: `candy-code/src/App/App.php` (deck field, `update()` arm, `delegateToChat()`, `runRegistryCommand()`, `subscriptions()`, `handleKey()` Ctrl+Tab/Ctrl+N, new `switchTab()`/`openTab()`/`closeTab()`), `candy-code/src/Chat.php` (new wither `withNoticeDrain()` beside `drainsRuntimeNotices`), `candy-code/src/Commands/KeyBindingRegistry.php` (`chat.session-cycle` and `shell.new-session` descriptions), `candy-code/docs/ARCHITECTURE.md` (§ App/Chat hosting, L262), `candy-code/README.md` (L275 key table, L1023 tab section)
- tests: 
- create `candy-code/tests/App/BackgroundTabStreamingTest.php`:
- a turn starts in tab A on a deferred backend promise;
- Ctrl+Tab to B is not refused;
- A's promise resolves, and A's history gets the reply while B is untouched;
- A is marked Unseen;
- A's pump subscription stays declared;
- create `candy-code/tests/App/TabScopedRoutingTest.php` (foreground unwrap; parked routing; shell Msgs from a parked tab dropped; App Cmds never scoped);
- create `candy-code/tests/App/TabCloseAndQuitTest.php`;
- modify `candy-code/tests/Commands/KeyBindingDriftTest.php` (deck-mode observations for Ctrl+Tab and Ctrl+N) and `candy-code/tests/App/AppModelTest.php`.
- docs/guards: `README.md` keys and tabs prose; `docs/ARCHITECTURE.md`; KeyBindingDriftTest moved in-step

## Approach

- `App` gains a `SessionDeck`. Every Cmd a Chat returns, from `delegateToChat` (:4298), `runRegistryCommand` and `feedChat`, is wrapped by `TabScope::cmd($fgKey)`. App-level Cmds stay unscoped, so shell Msgs (SettingsSaved…) can never be misrouted.
- A new `TabScopedMsg` arm in `update()` (:2714) does the routing:
- **foreground:** unwrap and re-enter `update()`;
- **parked:** `$parked->update($inner)`, re-wrap its Cmd, and refresh that tab's attention (inFlight → Working; pendingPermission → Waiting; reply settled while parked → Unseen; error → Failed).
- Shell-claimed inner Msgs from a parked tab are dropped (OpenMediaForm/OpenSettings/OpenAgentView), except AgentMessageSentMsg, which is keyed by run. A background tab can never pop a modal; it raises attention instead.
- `subscriptions()` (:4664) merges each parked Chat's `TabScope::subscriptions()`. The foreground-only ids are the background poll, statusLine and idle-compact.
- **Switch:** park the foreground Chat (its turn keeps running), unpark or factory-build the target, replay `WindowSizeMsg($cols,$rows)` (`handleWindowSize`, :4265), and move the notice drain to the new foreground via a new `Chat::withNoticeDrain(bool)` (the field exists, `src/Chat.php:1440`).
- The App intercepts Ctrl+Tab/Ctrl+Shift+Tab and Ctrl+N (shell.new-session) when the deck is armed. After each delegate it rekeys the deck when `currentSessionId()` moved in place (`/branch`, mint).
- **Close:** closing a busy tab needs a second press within 3 s (`cancel and close`); then it cancels, flushes and releases the lock.
- **Quit** with busy background tabs asks once.
- No new fork site: each tab's turn uses the existing TurnRunner/EngineBackend fork path. TurnRunner is already keyed per token and session (`src/Host/TurnRunner.php:643-734`).
