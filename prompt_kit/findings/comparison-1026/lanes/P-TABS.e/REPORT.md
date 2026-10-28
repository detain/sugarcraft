# P-TABS.e — DONE · commit 8ec8bff21f9da015410ab387f9d2814973ae8cfb (branch cc/P-TABS.e, base 5ed26cf92, never pushed)

App now hosts many Chats: SessionDeck armed on first update when tabFactory present; every foreground
Cmd wrapped TabScope::cmd(); TabScopedMsg/OpenTabMsg/CloseTabMsg/CycleTabMsg/QuitMsg arms in updateShell;
park/unpark with notice-drain appointment moved via new Chat::withNoticeDrain() (one drainer per shared
workspace sink); WindowSize replay on switch; attention working/waiting/unseen/failed raised instead of
modals from parked tabs; Ctrl+Tab pair + Ctrl+N intercepted in handleKey (legacy Chat cycle /
runRegistryCommand('new') intact when unarmed); two-press 3s busy-close (cancel-drive, flushTranscript);
quit asks once with busy background tabs. 10 files, +1183/−14.

NEW *Test.php (gate must add durations rows): tests/App/TabScopedRoutingTest.php (7T),
tests/App/BackgroundTabStreamingTest.php (4T), tests/App/TabCloseAndQuitTest.php (7T).

Gates (cwd candy-code, plain pipe, -u LINES -u COLUMNS): new 3 files OK 18T/97A — RED-BEFORE proven at
base via /tmp cp-harness (18F/17W, cmp-restored, no stash). tests/App/ OK 467T/21,260A.
KeyBindingDriftTest OK 222T/1,800A (deck-mode Ctrl+Tab/Ctrl+Shift+Tab/Ctrl+N observations in-step;
AppModelTest +2 deck arms in-step). Guards: ReadmeRoster|DocFigure|GlobDialect|DuplicatedTestHelperDrift|
TuiLang|LangParity|LangLaunchLocale 157T/8,905A/1F (ONLY red = DocFigure 'thirty-seven' — inherited,
reproduced in main tree without my diff). Census family 128T/41,318A/3F — all inherited (LspInstaller ×2,
ChatHostLangCatalogue), identical in main tree. ReadmeSuiteFigureDrift staleness pair red BY DESIGN:
welder re-pin owes live +25,537→+18T from this lane on top of pre-existing drift.

Deviations: (a) KeyBindingRegistry.php untouched — descriptions flow via Lang::t, en.php values carry them
(+3 tabs.* keys, en-only single-locale file); (b) Chat process statics (clickTracker/textSelection/
pressGesture) NOT reset on switch — no public resetter, outside [chat:withNoticeDrain] claim → seam
P-TABS.g; (c) close releases lock via flushTranscript + store pid-staleness (no public Chat unlock);
(d) palette/menu New-session funnels through consumeShellCmd NewSessionCmd to the deck when armed;
(e) busy-close cancel = bounded 4-step synchronous Esc-drive; (f) schedule-watch suppressed on parked tabs
alongside background-poll (shared-workspace supervisor would double-fire — const docblock); (g) Chat::cycleSessionTab
docblock claims Ctrl+Tab reachability — bypassed when deck armed, NOT edited (outside claim) → doc-truth seam;
(h) App::mutate is named-variadic — 10 bracket-call no-op clones found+fixed pre-commit, foreground-close
arm-carry fixed; (i) parking copies the Chat (withNoticeDrain(false)) — same state, moved appointment.

## FIX-1 corrections (round-1 review, amended in place)
MAJOR-1 docblock flipped to shared-queue truth; MAJOR-2 AgentMessageSentMsg exception implemented
in handleTabScoped + pinned by new TabScopedRoutingTest leg (red-before proven by fix-removal);
MAJOR-3 notice-drain absence pinned in BackgroundTabStreamingTest (paired raw fg-held id made
deterministic via test-local NoticeSink workspace; park-flag mutation reddens exactly :96);
NIT-1 pendingTabClose docblock reworded; NIT-2 '' sentinel one-line note. New-three 19T/106A,
tests/App 468T/21,269A, KeyBindingDrift 222/1,800 unchanged, guards 44T/20,260A. See FIX-1.md.
