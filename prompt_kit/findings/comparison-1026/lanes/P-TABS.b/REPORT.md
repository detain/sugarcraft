# P-TABS.b — TabScope envelope + SessionDeck wiring dormant SessionTabs detach/summary

VERDICT: DONE. Commit `fe389906e9f18e6b9217f719b6b69ae66ebedd17` on `cc/P-TABS.b` (base `30d4f91cb`), 12 files, +1388/−1. Author/committer Joe Huss <detain@interserver.net> (separate %ae/%ce greps = 1/1), zero Co-Authored trailers. Never pushed.

## What landed
- **src/App/Tabs/TabScope.php** — `cmd()` lazily wraps a tab's Cmd, mapping every produced Msg through `msg()`: BatchMsg/SequenceMsg keep shape with cmd-wrapped leaves, TickRequest keeps seconds + wraps produce, AsyncCmd gets `then(scope, reject→TabScopedMsg(ExceptionMsg))` so a tab rejection NEVER reaches Program's raw reject path, the 10 Program-consumed sentinels pass through unwrapped, everything else becomes TabScopedMsg. `subscriptions()` drops foreground-only ids BY ORIGINAL ID (background-supervisor poll / statusLine / idle-compact), then re-keys `tab:<key>:<id>` and scopes producers — the re-key law is enforced by Program::reconcileSubscriptions' id-based diff.
- **src/App/Tabs/TabScopedMsg.php** — `{tabKey, inner}` envelope implementing `SugarCraft\Code\App\Msg` (accessors for P-TABS.e's routing arm).
- **src/App/Tabs/SessionDeck.php** — immutable deck over SessionTabs keyed by deck-stable tab keys (never session ids — `/branch` moves ids in place). `forLaunch/open/close/park/unpark/cycle/attention/summary/rekey`, `parked<key,Model>` + `sessionOf`. This is now the sole production driver of the dormant `SessionTabs::detachTab/reattachTab/updateTabSummary` and the new `updateTabAttention`. Attention vocabulary: string consts IDLE/WORKING/WAITING/UNSEEN/FAILED (+ ATTENTION_STATES).
- **OpenTabMsg/CloseTabMsg/CycleTabMsg** — App Msg envelopes for P-TABS.e/f.
- **src/Tui/SessionTab.php** — tail ctor param `attention='idle'` + `withAttention()` via mutate() + accessor. **src/Tui/SessionTabs.php** — `updateTabAttention()` mirroring `updateTabSummary`.

## Tests
- NEW `candy-code/tests/App/Tabs/TabScopeTest.php` — 14 tests (nested batch/sequence/tick wrapping, deep batch→tick→leaf, AsyncCmd resolve + reject-scoping with reject-leg-never-fires pin, per-sentinel assertSame identity for all 10, **dynamic Program-source sweep**: every `$msg instanceof X` in sugar-core Program.php must be ∈ PASS_THROUGH∪STRUCTURAL — a future sentinel added to Program reddens this test instead of being silently swallowed; subscriptions re-key + foreground-drop-before-rekey + null).
- NEW `candy-code/tests/App/Tabs/SessionDeckTest.php` — 12 tests (forLaunch seed, park keeps focus, unpark reattach+activate, close-last/unknown refused, parked-model return, cycle ring incl. cross-check vs handleKey, attention/summary land on ledger rows + unknown-id inert, rekey session-only, parked survives cycle/rekey).
- MOD `tests/Tui/SessionTabsTest.php` +2 (updateTabAttention + unknown-id no-op). MOD `tests/Host/SessionTabsExtractionParityTest.php` +2 (SessionTab ctor-roster pin ending `attention`; SessionDeck sole-driver census over all of src/).
- `tests/Tui/SessionTabTest.php` needed NO change: its R26 with*-delegates-mutate pin iterates by reflection, auto-covering `withAttention`.

## Gate results (cwd candy-code in worktree, env -u LINES -u COLUMNS, plain pipe)
- `--filter 'TabScope|SessionDeck|SessionTab'`: **OK — Tests 142, Assertions 1190** (2 pre-existing PHPUnit deprecations from AccessorDeprecationTest provider visibility, foreign, caught in-window only via SessionTab rows).
- five-guard `--filter 'ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift'`: Tests 133, Assertions 15391, **Failures 2 = ONLY the allowed staleness pair** (pinned 25,537 vs live 25,730).
- `--filter 'DuplicatedDocBlockLine|BackendSignatureNullability|AssertionSwallowingCatch|SymbolCitationDrift'`: **OK — Tests 56, Assertions 20763**.
- php -l clean on every new/modified file.

## NEW *Test.php for gate durations TSV
- `candy-code/tests/App/Tabs/TabScopeTest.php`
- `candy-code/tests/App/Tabs/SessionDeckTest.php`

## Deviations / adjudications
- **WindowSizeMsg classified PASS_THROUGH** — plan text listed it neither way. Rationale (in docblock): Program consumes it for recorder + renderer-reset before falling through to the model; a Cmd minting a resize is unknown today; if that ever happens the fix is unwrapping it in the model-routing arm, not dropping the pass-through entry.
- Attention is string consts on SessionDeck, not a dedicated enum file — closed file set + one-type-per-PSR-4-file law.

## Seams (none blocking)
- P-TABS.e (W14) gains the App::update TabScopedMsg routing arm + subscriptions merge; P-TABS.f (W15) the switch UI. App.php/Bootstrap.php/LaunchContext.php untouched per W4 concurrency with P-TABS.d; the brief's file set required no App-side code. `Chat::withNoticeDrain` + background-supervisor dial ride P-TABS.e.
- suite-figure re-pin (25,537→25,730+): GATE duty per plan; lane never touched suite-figure.json/durations.tsv.
