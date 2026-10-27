VERDICT: FINDINGS — 3 MAJOR / 5 MINOR / 3 NIT (whole-wave unless marked)

MAJOR-1 [P-CTX.a] /context 'By category' block unreachable in production. src/Chat/Handlers/HandlesSlashMenu.php:913 routes bare '/context' → Cmd::chatContext → Chat.php:6490 ContextCommand::report(..., format:'full') → format:'tree'. No caller passes ContextBreakdown::withCategories(). Fix or declare.
MAJOR-2 [P-TABS.b] TabScope/SessionDeck unwired. src/App/Tabs/TabScope.php + SessionDeck.php have zero production callers (src/App/App.php:1469,1619,1670 use Chat->tabs()->open/close/active). Dead public surface. Wire or declare.
MAJOR-3 [P-TABS.d] Bootstrap::tabChat() has zero production callers (Bootstrap.php:4155 vs :4173). Dead factory. Wire or declare.
MINOR-1 [C-WEB-1.a vs C-WEB-1.c] Web session.updated omits model/thinkingBudget (SessionHost.php:2066-2068, ServerContext.php:687-694). Fix or declare.
MINOR-2 [C-SES-2.b] Debounce timer vacuous. SessionPickerSearchMsg.php:17-20 + Chat.php:14942-14948: 300ms no-op; SessionPicker::withPendingSearch (Tui/SessionPicker.php:517) never read, withPendingSearch(null) :529 never called, no Cmd::tick(0.3), no SessionPickerSearchTickMsg mint. Feature inert. Fix or declare.
MINOR-3 [P-TABS.a] WindowList/WindowViewports/WindowRef/WindowAttention have zero production callers; only WindowStrip consumed (Renderer.php:2211→MenuChrome.php:186). Dead types. Fix or declare.
MINOR-4 [P-TABS.b] SessionTab/SessionTabs attention API (src/Tui/SessionTab.php:262,310; SessionTabs.php:682) has zero production callers. Fix or declare.
MINOR-5 [C-SEC-4.a] CommandMethods::files() passes $projectCommandsTrusted at src/Protocol/Methods/CommandMethods.php:133 but SessionHost.php:243 calls loadAll with NO flag → untrusted serve leaks project command files (CommandMethodsListTrustTest red; m2 2F+30E environmental but this one real). Fix before weld/close.
NIT-1 [C-WEB-1.c] src/Backend/Mcp/ProxyServer.php:383 forget() call unrelated to @-mention; add clarifying comment.
NIT-2 [P-TABS.a] WindowStrip.php:143 $windowCounts never deleted on closeSession.
NIT-3 [P-TABS.b] App.php:2455 WindowRef::session('s:'.$key) — SessionDeck/TabScope use bare $key elsewhere; prefix inconsistency.
--- lead note: persisted verbatim from reviewer reply (writes denied in sandbox); gates they could not run were run per-lane at welds. Adjudication (wire-vs-carry) by lead against plan §1.6 + later-step DoDs; CommandMethods trust leak (M5) treated as live tree red — disk-verifying now.
