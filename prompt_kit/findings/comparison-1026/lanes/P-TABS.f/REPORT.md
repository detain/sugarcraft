# P-TABS.f — CLOSED (commit c991c6d10cc1028da8b81af1828109c2b44e885c on cc/P-TABS.f)

SHIPPED: Chat goes deck-aware. `tabHosted` flag (ctor tail, mutate carry, identity-preserving
`withTabHosted`); `presentSession(id,name)` → hosted: Cmd::send(OpenTabMsg) touching nothing
else, standalone: the historic in-place `switchToSession`. All four in-place routes (picker
Enter-resume, picker fork, Agent-View open-as-session, palette New session) route through it;
hosted they stand mid-turn (refusal gates now `inFlight && !tabHosted`). New palette-only
Close tab row (`builtin-commands/0105-close-tab.php`, `PaletteAction::CloseTab`, explicit arms
in runRootPaletteAction/runPaletteAction/runSelectedPaletteActionWhileInFlight): hosted →
CloseTabMsg, standalone → named `chat.tabs.close_no_deck` notice (never silent). App:
`withChat` sets the flag from `tabFactory !== null`; `armDeck` seeds it on the launch chat.

FILES: src/Chat.php, src/App/App.php, src/Palette/PaletteAction.php, src/Tui/Menu/ShellMenu.php
(row placed in the Session menu — unplaced rows would land the tree in an App▸More submenu the
ShellMenuTest pins absent), builtin-commands/0105-close-tab.php (new), lang/en.php (3 keys:
cmd.close-tab.{label,description} + chat.tabs.close_no_deck), docs/COMMANDS.md (regen + prose
seven→eight), tests/Config/DocFigureProseDriftTest.php (palette-only roster arm flip
in-step; + `thirty-seven` word-map line curing an INHERITED BASE-RED from the foreign
C-AGT-5.a tool-growth that never extended the map), tests/Chat/PaletteSupersetDispatchTest.php
('new' exception reason truth-flip), tests/Chat/DeckHostedSessionRoutesTest.php (NEW, 14T/77A).

GATES: new file 14/14 green; red-before proven on base src (13E; the 1 pass is the pure-legacy
idle-fork byte-pin); combined filter (Deck/Superset/CommandDocsDrift/ShellMenu/MouseModalGuard/
DocFigure/KeyBinding/Lang/TuiLang/ReadmeRoster/GlobDialect/DupHelper/SessionPicker/AgentView/
Palette/CommandRegistry/tabs/Switch/Trust/Env/SuiteSkip) 787T green; tests/App 468T green;
gen-command-docs --check rc0. INHERITED BASE-RED disclosed: ReadmeJsonErrorContractDriftTest
4F on src/MCP/McpServerRequests.php envelope shape — byte-identical to base, reproduced with
ALL lane files reverted; foreign MCP-era, not mine to cure (out of ownership).

DEVIATIONS: (1) brief base db1e63739 does not exist — cut from real tip 2cd5294e9 'weld:
P-TABS.e'; worktree was missing, created + refresh-deps linked rc0. (2) armDeck seeds the
flag on the launch chat (withChat alone bypasses it → tab-1 desync). (3) handlePaletteNewSession
keeps its bespoke createSession block for standalone (a fresh session has no transcript to
switchToSession-load); hosted returns early via presentSession(null,null) like Ctrl+N.
(4) MouseModalGuardTest unmodified — its pins passed unchanged; hosted cases live in the new
file instead. (5) chat.tabs.* = one key (plan prefix honored minimally).

WELDER: NEW durations row — candy-code/tests/Chat/DeckHostedSessionRoutesTest.php.
