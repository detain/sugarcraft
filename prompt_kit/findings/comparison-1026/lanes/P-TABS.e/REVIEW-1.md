# P-TABS.e (W14) — REVIEW-1 (round 1, read-only)

Lane: cc/P-TABS.e @ 8ec8bff21 (base 5ed26cf92), worktree /home/sites/cc-cmp-P-TABS.e.
Verdict: **FINDINGS — 3 MAJOR, 0 CRITICAL, 2 NIT.** All three MAJORs are small, surgical cures; the shipped behavior core is genuine and matches the brief.

## 1. Phantom/scope — PASS

One commit; porcelain clean; author/committer detain@interserver.net (separate %ae/%ce greps); 10 files, +1183/−14, exactly the declared set:
`candy-code/{README.md, docs/ARCHITECTURE.md, lang/en.php, src/App/App.php, src/Chat.php, tests/App/AppModelTest.php, tests/App/TabScopedRoutingTest.php, tests/App/BackgroundTabStreamingTest.php, tests/App/TabCloseAndQuitTest.php, tests/Commands/KeyBindingDriftTest.php}`.
No suite-figure.json, no durations.tsv, no dist/, no protocol trio. `create:` claim honest: the Tabs types (SessionDeck, TabScope, TabScopedMsg, OpenTabMsg, CloseTabMsg, CycleTabMsg) all pre-exist at base (P-TABS.b 88ac5e485), one type per file under src/App/Tabs/; the lane adds NO new src class, so one-type-per-file is untouched. LaunchContext.php:2375-2376 (P-TABS.d) already wires `withTabFactory(static fn (?string $sessionId) => Bootstrap::tabChat($root, $sessionId))` — the lane consumes it as designed.

Mechanisms blob-verified in `git show` content (not just prose): `armDeck()` + `updateShell` split; TabScopedMsg/OpenTabMsg/CloseTabMsg/CycleTabMsg/QuitMsg arms in the shell match; `TabScope::cmd` wrap via `scopeForegroundCmd()` on the `delegateToChat` and `runRegistryCommand` return paths; Ctrl+Tab / Ctrl+Shift+Tab / Ctrl+N deck intercept in `handleKey` gated on `deck !== null` with count>1 for cycle; park = `withNoticeDrain(false)`, unpark = `withNoticeDrain(true)->update(WindowSizeMsg(cols,rows))` replay; two-press close `TAB_CLOSE_ARM_SECONDS = 3.0`; quit-ask-once `tabQuitWarned`; parked subscription merge with `FOREGROUND_ONLY_TAB_SUBSCRIPTIONS = ['code.background-poll','code.schedule-watch','code.status-line']`. Base had no QuitMsg arm (default → delegateToChat), and `handleQuit` with deck null falls back to `delegateToChat($msg)` — the pre-deck route is byte-behavior-identical unarmed. Legacy single-session paths (NewSessionCmd → runRegistryCommand('new') when deck null; Ctrl+N fall-through) intact.

## 2. Design conformance (BRIEF + W14 block) — core PASS

- Tab-scoped Cmd routing: foreground Cmds deliberately unscoped (per design), parked-tab pumps prefixed via `TabScope::subscriptions` with the fg-only suppression list; ids match Chat consts (:624/:641/:697). The ACTIVE tab's subscriptions are the raw unfiltered `Chat::subscriptions()` output, so its `code.schedule-watch` wake-up survives — duty "check suppression does not silently kill ACTIVE-tab scheduled wake-ups" PASS.
- R-MENU-2 note: §6.1 contains no tab ruling (R-MENU-2 is the P-MENU.e MenuBar indicator; the review prompt's governance cite is a mislink). The lane BRIEF itself records "ruling: none", so conformance was judged against the BRIEF + W14 block.
- Disclosed deviations judged honest and acceptable: (a) KeyBindingRegistry untouched — the three drifted descriptions flow through `Lang::t('keys.chat.session-cycle'/'…prev'/'keys.shell.new-session')` at KeyBindingRegistry.php:965/966/1034, so the en.php flips ARE the registry descriptions; the +43 in-step KeyBindingDrift legs add real deck-mode behavior (3-tab cycle wrap tab-3→tab-1, prev, Ctrl+N grows deck and parks prior fg) while keeping the legacy single-chat legs. No drift hole. (b) Chat statics not reset on switch — declared P-TABS.g seam; acceptable: no public reset surface exists in this claim set. (c) lock release rides pid-staleness — disclosed. (f) design's fg-only list named idle-compact, but that id is a single App-level subscription ('idle-compact', App.php:5222), not per-tab droppable; the lane substituted schedule-watch with a shared-supervisor justification. (g) Chat::cycleSessionTab docblock truth-seam disclosed outside the claim.

## 3. Findings

### MAJOR-1 — src/Chat.php (withNoticeDrain docblock, ~:9270): false queueing claim in NEW code
The new docblock states: "A parked tab's own notices stay QUEUED (the sink is an inbox, not a stream) and surface the next time that tab holds the appointment." False on disk: every deck tab shares ONE WorkspaceContext (Bootstrap::tabChat → buildChat launch:false → sharedWorkspace memo, single instance per root), and WorkspaceContext::notices falls back to `RuntimeNoticeSink::current()` (:136) — the process-global sink, one queue, no session key. The currently-assigned foreground tab drains ALL pending rows, including a parked tab's; nothing defers to "the next time that tab holds the appointment." ARCHITECTURE.md in the same commit tells the truth ("exactly one tab drains the shared workspace's runtime notices at a time"); the docblock contradicts both the code and its own sibling doc. Downstream P-TABS.f/.g would build on a phantom attribution guarantee.
**Cure (one sentence):** replace with truth — notices are workspace-global; rows produced while a tab is parked surface in whichever tab currently holds the drain appointment, not necessarily the producer.

### MAJOR-2 — src/App/App.php handleTabScoped(): the claimed AgentMessageSentMsg exception is not implemented
BRIEF :33 and the method's own docblock both promise the drop-list has an exception — "except AgentMessageSentMsg, which is keyed by run" (the Agent View is global; dropping would lose a row the foreground would have recorded). The parked branch special-cases only OpenMediaFormMsg/OpenSettingsMsg/OpenAgentViewMsg; everything else is routed into `$model->update($inner)`. But Chat consumes AgentMessageSentMsg nowhere (grep `instanceof AgentMessageSentMsg` over src/Chat.php and src/Chat/**: zero consumers — Chat only PRODUCES it at :9372/:9547/:9576 deferred `.then` Cmds), and the sole consumer tree-wide is App's global match arm → `recordAgentMessage()`. Reachable path: a turn's `.then` resolves after the user switched tabs, the scoping wrapper re-emits it under the parked key → handleTabScoped parks it into a Chat that ignores it → the Agent View global row is silently lost. That is a regression against base (where every AgentMessageSentMsg reached the arm) and the exact failure the docblock says it prevents.
**Cure:** in handleTabScoped's parked branch, before the generic route: `if ($inner instanceof \SugarCraft\Code\App\AgentMessageSentMsg) { return [$this->recordAgentMessage($inner), null]; }` plus a TabScopedRoutingTest leg (parked-key AgentMessageSentMsg → deck row recorded, model identity preserved).

### MAJOR-3 — the one-drainer park mechanism is unpinned (vacuity)
No test in the deck family asserts that a parked tab lacks `code.runtime-notice-poll`. `Chat::subscriptions()` gates RUNTIME_NOTICE_SUBSCRIPTION (:703) on `drainsRuntimeNotices`, and the fg-only suppression list deliberately does NOT contain that id — so the absence of the parked poller rides entirely on the `withNoticeDrain(false)` call. Mutating that call to `withNoticeDrain(true)` leaves all 18 new tests green (the existing `runtime-notice-poll` pins live in non-deck worlds: IdleSubscriptionsTest:74, RuntimeNoticeSinkDeliveryTest — all unaffected). The behavior consequence is real: two tabs polling drains → the parked tab races the foreground for the shared queue and misattributes notice rows into an invisible transcript — precisely the design defect this step legislates. Per review duty 3 (mutation-stable pins ⇒ step-local MAJOR).
**Cure (2 lines):** in BackgroundTabStreamingTest::testParkedTabKeepsItsEventPumpAndDropsTheForegroundOnlyPolls add `self::assertFalse($subscriptions->has('tab:tab-1:code.runtime-notice-poll'));` and a paired positive that the raw (unprefixed, fg-held) `code.runtime-notice-poll` is present in the same list.

### NITs
- NIT-1: App.php handleCloseTab docblock says the armed window survives because "anything else lets it expire", but the code ALSO proactively clears `pendingTabClose` on switchTab and on other-target closes (the latter is pinned). Reword to match the pin.
- NIT-2 (forward note, P-TABS.f territory): armDeck keys unminted sessions by `''` sentinel; two fresh tabs can briefly share the key until mint/rekey. Not reachable as a defect today; record for the f/g waves.

## 4. Gates reproduced (plain pipe, env -u LINES -u COLUMNS, cwd /home/sites/cc-cmp-P-TABS.e/candy-code, vendor/bin/phpunit -c phpunit.xml; no PtyRunToolTest anywhere)

- new three files `--filter 'TabScopedRouting|BackgroundTabStreaming|TabCloseAndQuit'` → **Tests: 18, Assertions: 97** — exact vs builder. (The "PHPUnit Deprecations: 2" are the foreign AccessorDeprecationTest non-public-provider pair — file untouched by the lane.)
- `tests/App` directory → **OK (467 tests, 21260 assertions)** — exact.
- `--filter 'KeyBindingDrift'` → **222/1,800** — exact.
- guard family `ReadmeRosterDrift|DocFigureProseDrift|GlobDialectDifferential|DuplicatedTestHelperDrift|TuiLang|LangParity|LangLaunchLocale` → **157/8,905, 1F** — exact; the lone red is the standing thirty-seven word-map (test + AGENTS_AUTHORING page both outside the lane's file set → structurally inherited; DuplicatedTestHelperDrift green = the three new files' helpers pass; GlobDialect green = no glob-shaped literal drift; DenialPrefixRoster shape n/a — no Refused-shaped literals in the new tests).
- wider census family `Census|Roster|StderrEmitter|ReadPath|ChildLifetimes|BootstrapLaunchFormat` → 893/88,026/5F. All five reds name ONLY foreign files: TreeWideGuardRoster walker pair (LSP/LspInstallerTest scandir — standing list), SwallowingCatchSrcCensus (src/Tools/BuiltIn/SkillTool.php::execute), PermissionsIntroDrift (docs/PERMISSIONS.md roster missing 'Schedule' — foreign schedule-era drift), ProjectTierRefusalInventory (8 unclassified dot-paths in Bundles/Marketplace*, Format/FormatAutoTable, LSP/LspInstaller, Memory/AgentMemory*, Support/MacAltNotice, Tools/BuiltIn/ScheduleTool). None is a lane file; the lane's derivation contributions add none → inherited at base (newly named, report-only for the welder's battery).
- ReadmeSuiteFigureDrift → 2F staleness pair standing (§7); the +18T/this-lane figure rides the welder re-pin.
- E646 scan: zero timeout/alarm tokens in the src diff; the 3.0 s close-arm window and the notice-poll cadence are UI/subscription windows, no LLM request timeout minted.
- Builder's red-before claim (18F/17W at base) not re-run (write-ops cp/rm denied in this reviewer's map); discrimination instead re-derived by inspection per §5.

## 5. Non-vacuity (re-reasoned; worktree untouched)

- Neuter `scopeForegroundCmd` (return the raw Cmd): TabScopedRoutingTest's parked-prefix assertions ('tab:tab-1:code.tool-event-poll' present / raw absent) and BackgroundTabStreamingTest's pump assertions fail on the spot. Certain.
- Remove the two-press (close busy tab on first press): TabCloseAndQuitTest's arm-press-1 asserts (count 2 + pendingTabClose['key'] + tabs.close_arm status) fail. Certain.
- Neuter the park flag (`withNoticeDrain(false)` → true): NO pin reddens → MAJOR-3 above.
- The pinned behaviors I audited line-by-line are real state observations (deck counts, model identities via !==/=== on parked Chat objects, history carry, attention enum transitions idle→waiting→unseen→idle, arm staleness via reflection-injected older timestamps) — not string-scans.

## 6. Welder notes

- NEW test files needing durations.tsv rows (expect 3): candy-code/tests/App/TabScopedRoutingTest.php, candy-code/tests/App/BackgroundTabStreamingTest.php, candy-code/tests/App/TabCloseAndQuitTest.php.
- Chat.php claim = single region (withNoticeDrain +er + docblock) → ≤3-disjoint-regions law OK. Master has moved (EXT-2.e f63217396 also touches Chat.php) — cherry-pick conflict watch is welder-side per the review brief.
- MAJOR-1/2/3 fixes are additive and localized (one docblock sentence, ~4 code lines + 1 test leg, 2 test lines); a fixer round should re-run: new-three filter (expect 19T once MAJOR-2's leg lands), tests/App, guards trio.
- Foreign standing reds to NOT attribute to this lane: the three newly-named census families in §4 plus the pre-listed set (thirty-seven, walker pair, ChatHostLangCatalogue, BinCandyCodeAutoloadGuard, KeyHelpTest, DreamPassTest, AccessorDeprecation deprecations, web vue-tsc 19, ReadmeSuiteFigure pair).

VERDICT: FINDINGS
