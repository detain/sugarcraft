# §7 Closeout gate — plan_comparison.md

Started 2026-11-01. Base: master == origin == 86695f57d0f6775e146bd9042e33b9f347ef6c1b.
Loop per §1.6: steps 1–4 → fix → re-run from 1 until green; then 5–7.

## Log
- Gate session opened; evidence dir created.

## Fix round 1 (from battery-1 + vitest-1)
- VITEST: 4 failed (2 files) == exactly the banked VITEST-28/4 idempotencyKey family. CURED @ 7c19eb68154c9ec18edba26088862023e1fee4e4 (shellsInput.spec x2 + processesSection.spec x2, six sites: strip stamped key, shape-lock business params, pin 32-hex). Both files 21/21 green serial; vue-tsc rc0.
- Riders: W40/WAVE-REVIEW-1.md records RESTRICTED_TOOL_NAMES 'Script' as LEAD rider OPEN (not a finding); adjudicating against design blocks below. No W40/WELD.md exists (WAVE-REVIEW-1.md is the wave record).

## Fix round 2 — @347d583f0
TrustKeyDocumentationDrift::testTheReadmeNamesEveryKeyNoProjectMaySet cured: README's reasoned
'never taken from a project file' enumeration lacked the five voice.* keys (they sat only in the
tier-summary list above). Added truthful clauses (host+credential routing of takes, model/key
spend, forced-language composer corruption, per-take bill bound). Guard green isolated (100
assertions). skills.pathNudges verified NOT in userTierOnlyKeys (94 live) — clause removed.

## Fix round 2 committed (master 5d7eb2fab, 11 ahead of origin 86695f57d)
- 449ea5302 ChatTest slash-menu /re 8-row re-derive (wrap + ambiguous)
- 4f14ce37b PaletteClick WIDE_UNCLICKABLE_ROWS += /shells row; PaletteSuperset SLASH_ONLY_SURVIVORS += recap
- a922ad16e ShellContrast: Tui reviewDiffRow delegates to canonical Renderer::styleDiffLine (made public), drop unused Color import
- b179f4690 LspLauncherAutoDetect full-manual-shape += 'sandbox'=>true
- 279bd598c DoctorChecks PDO -> SqliteConnect::openDsn; census allowlist re-derive (Subcommands stale row deleted)
- 5d7eb2fab ProcessUniqueTempName six sites -> pid+more-entropy uniqid shape (AudioRecorder take path + 5 fixture names)
All verified green on targeted filters (isolate runs). Continuing TRIAGE grind.

## Fix round 2 committed + lattice (e8ae7a3de)
Master chain now 12 ahead of origin 86695f57d: 7c19eb681 vitest-idempotencyKey · ec86bc080 README voice keys · c8bdf19a6 SelfUpdate 14 verbs · 4755ca05e MemoryPromptWiring client builders · e921cf63e --worktree OPTIONS+12 lang · 449ea5302 ChatTest /re 8-row · 4f14ce37b PaletteClick+/shells, Superset+recap · a922ad16e ShellContrast styleDiffLine delegation · b179f4690 LspLauncher sandbox in manual shape · 279bd598c DoctorChecks PDO→SqliteConnect census · 5d7eb2fab ProcessUniqueTempName six sites pid+entropy · e8ae7a3de ProjectTierRefusal lattice 95/64/35/29 + Bootstrap enumeration (all cat-file verified, %ae/%ce detain 1/1, 0 Co-Authored).
ProjectTierRefusalInventoryTest: 15/15 green isolated (Assertions:1057, PHPUnit Deprecations:2 = standing ambient pair, cross-check battery-2).
Next: ContainedPathInventoryTest 2F, ReadmeJsonErrorContractDriftTest x4, then remaining TRIAGE list.

## Fix round 3 (through 89477c9ea, master 16 ahead of origin)
- f6755b134 JSON error-contract: README schema union/enumerating/exit-1/kebab-four + NonInteractive src type index now FIVE outsiders (import-refused, lsp-install rows) six-of-eleven; ReadmeJsonErrorContractDriftTest 19T/519A green.
- 89477c9ea SwallowingCatchCensus: ClaudeSettingsImportTest::testHostileDocumentsRefuse + VapidKeysTest rawSignature forgery loop rewritten capture-then-assert (fail-in-try was caught: ExpectationFailedException extends RuntimeException via AssertionFailedError); filter SwallowingCatchCensus+both classes 36T green.

## Fix round 4 (through 653d5bb4f, master 19 ahead)
- 160b056d0 RefusalStderrSurface re-measure 676/9 + NonInteractive prose nine-line.
- 653d5bb4f worktree-jail roster + NotebookEdit/ScriptTool; MenuBarLabel + doctor/recap rows.
Both verified red-before (diffs captured from battery-1/isolate1 + direct runs) and green-after; 17T/265A filter. Master tip 653d5bb4f (cat-file ok).
In flight: BootstrapLaunchNoticeCapCensus (userScriptTools fan-out cure) + FrameCapFamily x2 (1_048_576 literal vs ::MAX_FRAME_BYTES; 1048576 vs 67108864 equality leg).

## Fix rounds 7–10 (loop pass-1 grind COMPLETE — ranges @751@..@927@)

All remaining TRIAGE-queue reds cured and committed (each %ae/%ce detain separate greps, 0 Co-Authored, explicit-path; full chain 41 commits ahead of base 86695f57d):
- 3de78fd2e §7(4) RIDER LANDED: RESTRICTED_TOOL_NAMES += 'Script' (Bootstrap const + docblock rationale; RestrictedSafeModeFlagsTest mirror renamed to 'FiveCodeShapers'; README :2035 + docs/PERMISSIONS.md drop lists). C-TOOL-6.d r1 review recommendation honored: the bridge runs operator-unread PHP — exactly what --restricted exists to drop.
- 9e4fb5c5a §7(1) REAL defect cure (was mis-banked as foreign): AttachPagingTest + RemoteBackendTest prefix leg root-caused to C-SES-2.c repurposing session.list `query` into body-only FTS while RemoteSessionHost::open still resolved attach targets through it — attach-by-id silently broken whenever the FTS index is healthy. Cure at the protocol boundary: new spelled `search` param (pre-FTS LIKE shape), SessionMethods/SessionHub/RemoteSessionHost/MethodSchemas wired, protocol schema + generated.ts regenerated same commit, pin SessionListBodySearchTest::testTheSearchArmResolvesNamePreviewAndIdWhereTheBodyQueryIsBlind.
- 250143f1e LaunchContextDelegates['tools'] — deferred-to-§7 drift (C-TOOL-4.g egress-armed door predates base): pinned allowance mirroring the reason-speaker pattern (one ordered egressProxy()+forward shape, creep reddens; mutation-proved).
- af61e710b OneSidedHomeSandbox backlog — six program-era files MIGRATED to HomeSandboxTrait (guard law: migrate, don't extend the deferral list); useHomeSandbox(dir,false) keeps refusal-subject semantics (HookLog unrealizable home, AudioRecorder HOME=/).
- ccfa28a90 SettingsEditorTest:189 — 'trusted' search now lands category git (git.lspAutoDetect help sentence names 'trusted'; matcher reads help prose by its own law); canonicalizing list += Git + truth comment.
- d446f4b68 ChildStderrCaptureTest — nineteen exec('rm -rf …') tearDown sweeps across 18 program-era files inherited fd2; guard-sanctioned ' 2>&1' merge (fd1 unparsed; never /dev/null).
- ef3ece989 KeyHelpTest six-leg re-pin (63T/96865A green, zero risky): footer census 181→210 lines ([[100,30,185],[100,215,0]]), /help hint listing at registry-derived fit width + NEW 100x30 /skills-clip pin (src spill behavior KEPT — wrap experiment reverted), scroll-through roster += chat.agent-cycle/session-cycle/session-cycle-prev, clipped-fixture walks keyHelpMaxOffset range, scrolled-frame sweep steps derived offsets (100×Down blew the 60s budget under load).
- 1236307f6 --help documents CANDY_CODE_SERVICE_TIER (C-PRV-3.a env never got its screen entry); HelpTest+EnvRoster 207T green. HelpTest was the LAST queued red.
- Batch verification: 'StderrEmitterCensus|AttachPaging|KeepWarm|LaunchContextDelegates' 186T/0F; every banked foreign candidate is now either cured program-owned or green.

## §7(2) re-pin state
- live enumeration (guard register) = 30,171; hand-bump pair LANDED d8ed6fc19 (json tests + README headline 25,537→30,171, assertions equal 609,443 both sides — pair legs green, refreshed after green serial).
- FULL candy-code serial now running to GATE/serial1.xml (background, plain pipe, env -u LINES -u COLUMNS). Next: refresh-suite-figure.php on the green log + README assertions twin (one commit) → durations wholesale regen from the same junit → battery-2 K=24 --against-json conservation.

## Fix rounds 11–12 + §7(3)/§7(5) passes (gate, 2026-11-01)
- ProviderConnectTimeout x2 env-proxy cure c5f3f7cd9 ('proxy'=>false in the two origin-dial pins;
  this box exports proxy vars — connectPreflight's proxy bail correctly skips the probe there).
- GlobDialect docblock 1efca51cc: src/Util/PathGlob.php figure re-derived live
  312,477 = 561 x 557 (test asserts derived==docblock; never a literal).
- ReadmeJsonErrorContractDrift x4 f6755b134 (import-refused + lsp-install join README union/exit
  table/enumerating/outsider legs; NonInteractive.php index three->five).
- SwallowingCatch pair 89477c9ea (capture-then-assert; fail-in-try was RuntimeException-swallowed).
- StdinConstantReader + SrcCatch rosters fff428089; FixtureLifetime 0f55a9a93; RefusalStderrSurface
  160b056d0 (nine-line refusal block); WorktreeJail+MenuBarLabels 653d5bb4f; FrameCap rename
  17b903693; EnumSpelling 2fef82b65; notice-cap aggregate bfec0ada2; ColdResume a46bc0b90;
  ForeignAgentPreset roster 02f9216d3; keepWarm badge 9c260c6b2; CompactionHides src fix
  9db066db5 (real defect: messageWithContent dropped thinkingBlocks+recallTitles); reaper pair
  f19859183 (+ shrink-test clearstatcache law); PtyRelay convention 276ec9047.
- RESTRICTED_TOOL_NAMES += 'Script' rider LANDED 3de78fd2e (C-TOOL-6.d r1 recommendation).
- AttachPaging/RemoteBackend REAL protocol cure 9e4fb5c5a: session.list body-only FTS (C-SES-2.c)
  had silently broken attach-by-id/prefix; new spelled 'search' param through
  SessionHub::list->withSearch, RemoteSessionHost::open dials it; schema+generated.ts regenerated;
  SessionListBodySearchTest leg pins the arm.
- LaunchContextDelegates 250143f1e (egress-armed tools door allowance, ordered-regex pin).
- OneSidedHomeSandbox:435 af61e710b (six program-era files migrated to HomeSandboxTrait;
  backlog list shrinks per the guard's own law).
- SettingsEditor ccfa28a90 (git.lspAutoDetect help text matches 'trusted' — category list grew).
- ChildStderrCapture d446f4b68 (19 rm -rf sweeps got ' 2>&1'; guard-sanctioned merge).
- KeyHelpTest ef3ece989 six-leg live re-pin (210 content lines; 185 overflow at 100x30; derived
  offset sweeps replace the hundred-Down loops; /help hint listing renders at derived width +
  100-col clip pinned). HelpTest env entry CANDY_CODE_SERVICE_TIER 1236307f6.
- §7(2) hand-bump d8ed6fc19: tests figure 25,537 -> 30,171 (guard register = --list-tests ' - '
  lines); full serial relaunched via nohup (shell-tool bg jobs die to SIGHUP ~10min).
- §7(3): gen-command-docs/gen-tool-docs/gen-settings-doc/gen-protocol-schema --check all up to
  date; npm run gen:protocol byte-clean; npm run build -> dist porcelain EMPTY (tracked, clean);
  vitest-2 --no-file-parallelism 921/921 green (104 files); check-path-repos --no-lib-path-repos,
  check-child-lifetimes (61 libs, 33 sites, 9 findings), check-one-type-per-file rc0.
- §7(5): COMPARISON.md refresh 9c7f880db (all @b47@-planned cells; 64->75 built-ins verified live
  = 75 specs / 67 slash / 8 palette-only / 6 aliases; scoreboard regenerated from empty patch —
  web GIT 7->8 yellow 3->2 red, Total yellow 156->158 red 63->61; apply_comparison --check
  356 rows, 0 changed).
- §7(6) derivation: GATE/step-ledger.tsv — 329 marks, ALL rows resolved to master ancestors
  (10 lane-tip marks mapped to their weld commits 041cf425a/c0a462bc9/b138e5c73/d665c01e5/
  bb8a83ce0/978ff2889/2123c6582/5f9f6a488); per-wave counts W1-27 x10, W28 6, W29 3, W30 3,
  W31 2, W32 1, W33 8, W34 6, W35 7, W36 5, W37 3, W38 2, W39 4, W40 4, W41 3, W42 2.
- Serial pass at this entry: 46% no reds (14101/30171). Next on OK: refresh-suite-figure +
  README twin, durations regen, battery-2 conservation, drift sweep, CLOSEOUT, hygiene, push.
