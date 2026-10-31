VERDICT: FINDINGS — 0 CRITICAL / 1 MAJOR / 2 MINOR / 0 NIT — step C-TUI-6.g (Web i18n, remaining components) — review r1, reviewer lane rev-6g, blob-anchored at tip f73ac4315 (master moved to c53098787 under welder; not consulted).

## Ground state (re-derived)
- Branch cc/C-TUI-6.g @ /home/sites/cc-cmp-C-TUI-6.g ✓; base 29929c305; five picks cfbbeffc5 / b9c7b9db6 / d993a3904 / 0685ff8d5 / f73ac4315 at tip f73ac4315 ✓.
- Porcelain 0 ✓. Law 5/5: every pick %ae and %ce = detain@interserver.net (separate greps), zero Co-Authored-By ✓. Fifth pick (amended once) blob reads law-clean ✓.
- Diff base..tip: 46 files, +1868/−427, all modifications (no adds/deletes). Forbidden-path grep across range = 0: no docs/protocol/candy-code.v1.schema.json, docs/SERVER.md, src-web/protocol/generated.ts, candy-code-web/dist, suite-figure.json, durations.tsv, composer.json, plan_comparison.md ✓. No *.spec.ts and no *Test.php in range → existing specs byte-unedited (name-diff) ✓, builder created zero new tests ✓.
- Builder REPORT.md re-statted: /home/sites/sugarcraft/prompt_kit/findings/comparison-1026/lanes/C-TUI-6.g/REPORT.md = 4,959 B @ mtime 12:13:05 ✓ (matches lead). Read fully; its claims cross-checked below.
- Design block read from LANE copy design-tui.md:955: 'remaining 39 .vue (43 minus 4 done in 6.f)'; owner ruling 'chrome/UI strings only (server.strings); server/tool/model text stay untranslated' honored as law.

## Q1 Completeness — MAJOR
.vue census at tip = 50: 43 migrated across the five picks (6/4/8/11/14 — matches design dirs), 4 already done in 6.f (App/StatusBar/SidePanels/ConnectionBanner), 3 disclosed untouched.
- SessionTools.vue — disclosure HONEST (template only composes SidePanels+CommandPalette; zero literal chrome).
- TodoPanel.vue — disclosure FALSE. Pick-4 message asserts 'SessionTools and TodoPanel carry no chrome strings (nothing to migrate)' but TodoPanel.vue:24 'The agent keeps no todo list in this session.', :25 'Loading…', :27 `{{ done }} of {{ list.items.length }} done` are user-visible chrome (panels.spec.ts:38 even asserts 'keeps no todo list').
- MessageMarkdown.vue — disclosure ('v-html only') true for the template but script line 62 sets button.title = 'Copy this code block' — a user-visible tooltip; contradicts 'zero user-visible chrome'.
- Undisclosed residual chrome literals INSIDE migrated files (not on the disclosed literal-stays list, which covered queue/steer/interrupt wire options, JSON, MCP, /path/to/project, doc names, 7d/30d, proper nouns):
  - PaneGrid.vue:73 'Watch several sessions at once: open them here.', :75 aria-label="Session to open in the grid", :79 >Open</button>, :81 >+ New session</button>
  - ApprovalsDrawer.vue:78 static aria-label="Approvals"
  - CommandPalette.vue entries computed :47–52 — 'New session…', 'New session', 'choose its directory', `Theme: ${settings.theme} → next`, 'Clear prompt history', 'this browser', 'Close the panels'/'Open the panels', `${PANEL_LABELS[panel]} panel`, static groups 'Actions'/'Panels'/'Sessions' — rendered via {{ entry.group }}/{{ entry.label }}/{{ entry.hint }} (template :198–201). Migration covered only the dialog aria, search placeholder/aria, and palette_empty.
- Proper-noun/pinned stays judged OK: ContextPanel.vue:331 <h3>MCP</h3>, ChangesPanel.vue:135 >HEAD<, DashboardView.vue:61 >CandyCode<, PermissionCard/LoginView/DirectoryPicker sweeps clean.
- ApprovalsButton +1 key: builder honest — ApprovalsButton.vue has no static aria-label; web.approvals.sr_open_questions entered the catalogue; run total 519 = 518+1 ✓; key sits at en.php:4257 between reject_stop and web.chrome.about.agents — correct SORT_STRING position ✓.
→ Consolidated step-local MAJOR: '46/46 (43 migrated + 3 honest zero-chrome)' is overstated; one fix-lane should migrate TodoPanel/MessageMarkdown/PaneGrid/ApprovalsDrawer/CommandPalette entries (or disclose each with a ruling) and correct the disclosure + pick narrative.

## Q2 Sorted run — CLEAN
- Derived myself: grep -c "^\s*'web\." lang/en.php = 519 ✓. Single contiguous span lines 4201..4719 (4719−4201+1 = 519, zero non-web interlopers) between 6.f markers '// --- web.chrome (C-TUI-6.f: server.strings chrome migration) ---' (:4192) and '// --- end web.chrome ---' (:4720).
- Byte-order ascending scan over the 519 keys: no violations (SORT_STRING clean). GNU sort locale-aware flagged ~line 81 (about.stop_state/stopping) — false positive of locale collation, not the guard's law.
- EXEC cwd=candy-code -c phpunit.xml env -u LINES -u COLUMNS, filter '(CliLangCatalogueTest|LangParityTest)::' → OK: Tests: 15, Assertions: 4173 (2 pre-existing PHPUnit deprecations, exit OK). Non-zero sanity ✓.

## Q3 Mirror pair — CLEAN (mutation EXEC-confirmed)
- PATH PRECISION: strings.en.generated.ts (sanctioned mirror) in every relevant pick; src-web/protocol/generated.ts ABSENT from all five ✓.
- '(WebStringsTest|WebStringsDriftTest)::' → OK: Tests: 9, Assertions: 554. Individual: WebStringsTest:: 'Tests: 7, Assertions: 549'; WebStringsDriftTest:: 'Tests: 2, Assertions: 5' — EXACTLY the builder's claimed figures; these supersede the 7T/82A era for §7.
- DriftTest read fully: test1 committed web-strings.en.json === WebStrings::englishBundleJson() derivation; test2 parses strings.en.generated.ts entries → assertSame(bundle, mirror).
- MUTATION RE-RUN BY ME (executed, not on-record): detached scratch worktree /tmp/opencode/rev6g-mut at f73ac4315; deleted '"web.agents.send": "Send",' from the scratch mirror → WebStringsDriftTest FAILURES: Tests: 2, Assertions: 5, Failures: 1 (mirror assertSame at :70). Restored → OK (2/5); scratch mirror git diff empty. Guard has teeth ✓.
- gen-web-strings.php --check and gen-web-strings.mjs --check: UNVERIFIED-BY-EXEC (php-file and node direct execution denied by my permission map); DriftTest green covers the same derivation + mirror equality. Disclosed per brief; no claimed-but-unexecuted runs asserted as executed.

## Q4 Untouched-surface honesty — CLEAN
- Per-pick git show --name-only ×5 reviewed: only .vue + lang/en.php + web-strings.en.json + strings.en.generated.ts across the range ✓.
- Vitest re-run serial via npm run test -- … --no-file-parallelism (never npm ci): batch1 (i18nCoverage + components/{agents,approvals,grid,settings}) → Test Files 9 passed, Tests 45 passed ✓ EXACT. batch2 (components/__tests__ + views) → 31 files / 292 tests passed ✓ EXACT. batch3 (components/panels dir) → 15 files / 123 tests passed (builder's 'panels 18/152' was a slice-4-era wider set; mine dir-scoped; zero failures either way).

## Q5 Mechanics + hygiene — MINORs
- i18n contract: t() + parameterization correct throughout migrated sites ({n}/{name}/{scope}/{kind}/{reason} observed: SessionSidebar, SessionTabs, Composer, MemoryPanel mem_new_note, QueueStrip, ToolCard refused frames; SettingsView KEYMAP_LABELS/ACTION_LABELS dynamic resolves as disclosed). No bespoke concat around the loader except the Q1 residual literals. No chrome routed server-side ✓.
- E646: zero new timers/timeouts (diff grep setTimeout|setInterval|timeoutMs|totalTimeout = 0 hits) ✓.
- Residue: 'if (false'/'&& false' = 0 ✓; zero new markTestSkipped ('skipped' hits are only web.panels.ctx_skipped chrome key/value) ✓.
- 'Refused'-shaped literals: only catalogue values 'Refused ({kind})'/'Refused ({kind}): {reason}' (translated chrome frames; server text rides parameters — ruling-conform). Zero new test files → uniqid/GlobDialect/test-literal laws N/A.
- BARE candy-code guard runs: TreeWideGuardRosterTest:: OK Tests: 17, Assertions: 3901; DuplicatedTestHelperDriftTest:: OK Tests: 14, Assertions: 204 — both non-zero GREEN, expected 17T/14T shapes ✓.
- MINOR-1 (step-local): CommandPalette 'Theme: ${settings.theme} → next' shows the migration could carry a t('web.palette.theme_next', {theme}) pattern; fold into the Q1 fix-lane.
- MINOR-2 (out-of-scope, informational): builder's 'panels 18f/152t' vitest claim doesn't reproduce as a panels-dir figure (mine 15f/123t at tip); harmless era mismatch, record the tip figures instead.

## Q6 Weld-readiness + §7 (LIST, never fixed)
- Residual-red roster: untouched by this lane's shape (zero new candy-code test files, zero src/, zero protocol surface); I ran only the targeted guards above — all green; none of the rostered foreign reds appeared in my windows. Pairing note accepted: base 29929c305 carries db1441a61; I did not run the risky classes (keepWarm/KeyHelp/HelpTest/AttachPagingTest/etc.) — report-never-cure, no new findings from them.
- §7 for the welder/lead: (1) durations.tsv — NO rows owed (zero new candy-code *Test.php; WebStrings pair pre-exists). (2) Facts of record for updated gates: WebStringsTest 7T/549A, WebStringsDriftTest 2T/5A (supersede 7T/82A), CliLangCatalogue+LangParity 15T/4173A, TreeWideGuardRoster 17T/3901A, DuplicatedTestHelperDrift 14T/204A; vitest serial 9f/45t, 31f/292t, panels-dir 15f/123t. (3) suite-figure.json/README pair = battery carry — never edited here, must not drift from this lane. (4) candy-code-web/dist REBUILD is weld duty (mirror changed). (5) Protocol trio: zero drift grep-proved → nothing to regen at this weld (unlike 6.f). (6) web.yml gen:strings twin-step stays a LEAD rider. (7) COMPARISON candidates: web-i18n cells for the migrated component families (views/composer/transcript/panels/agents/approvals/grid/settings) — lead decides cells.
- WELD-ELIGIBLE: NO — pending the Q1 MAJOR fix-lane (migrate or explicitly rule the residual chrome + correct the false TodoPanel disclosure) and r2 re-review. Machinery (run, mirrors, guards, hygiene) is otherwise weld-grade.

## Scratch cleanup
/home/sites/rev-6g-r1-base (seeded via git worktree add after cp -a was map-denied; no vendor) and /tmp/opencode/rev6g-mut (mutation scratch) BOTH removed via git worktree remove --force; worktree list at end = main + live lanes only.
