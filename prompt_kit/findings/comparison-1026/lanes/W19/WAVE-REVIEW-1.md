# W19 wave-loop review 1 — merged WELD WAVE at master 6742a2ad3

VERDICT: CLEAN (0 CRITICAL / 0 MAJOR)

Reviewed tip 6742a2ad3 (re-checked at verdict time; unchanged). Wave = 10 steps (plan §4 W19 table line 155; brief's "11" off by one), all ✅ marks present with their master commits: C-PRV-4.a 204c33848 · C-SES-4.d a7a361466 · C-TOOL-2.b 5cdc6f440 · C-TUI-2.c 18822cc20 · C-WEB-4.d 074ffb281 · C-WEB-4.e 5a89b0b20+47d19563f(+docs 7a9f8de48, see NIT-1) · C-AGT-5.h 0ebfdf9fe · C-AGT-6.a 7e322e44d · C-CTX-2.f 96f4e4461 · C-CTX-3.b b4245ba44.

## Merged-tree gates (all at tip, plain pipe, env -u LINES -u COLUMNS)
- Generators rc0 ×4: gen-settings-doc --check, gen-command-docs --check, gen-tool-docs --check, scripts/gen-protocol-schema.php --check — all "up to date".
- Lane gate (CliProviderIsolation|SessionHostOtelFold|SearchBackends|HostedBackendShapes|DiffSplitRender|CommitHostCommand|InstructionVisibilityNotice|InstructionQuickAdd|SessionSendDocuments|ObservabilityWiring|WebSearch*/DiffGutter|AutoCommitter): OK 214T/2605A.
- Drift guards (EnvRoster|SettingsSchemaDoc|TrustKeyDoc|ReadmeRoster|CommandDocs|KeyBinding|ReadPathCensus|StderrEmitterCensus|ServerProtocolDoc|GlobDialect|DescriptorInheritanceGuard): OK 456T/70875A.
- DocFigureProseDriftTest: OK 92T/4114A. EngineBackendWither+LengthStopped carry: OK 50T. ClaudeCode family: OK 200T. Otel|SessionHost: OK 107T.
- tools/tests (PrCommentBotExample|ChildLifetimesTool|CheckPathRepos): OK 33T/157A.
- vitest serial --no-file-parallelism: turnTimer, statusBarTimer, composerDocuments, components = 4 files/50 tests PASS.
- durations.tsv: rows present for ALL 9 new candy-code test files (lines 59/61/67/78/196/200/206/554/660).
- Generated surfaces: protocol trio schema.json/SERVER.md/generated.ts in-pick+regen clean; dist rebuilt at welds (f2ecbff7c asset renames NcaNumyb→B5Pshpu5, then batch-6 041cf425a →index-DqOLwGWV.js/-DJ-w0CS9.css; bundle carries turnStartedAt + application/pdf; manifest↔index.html↔files in sync).
- Rulings honored: §6 line 4129 serve-OTel ALLOWED — EventSinkFactory registered Bootstrap.php:2135, SessionHost.php:2822 consumes lit endpoint ✓; §6 line 4125 R13 — pr-comment-bot example association-gated as ruled ✓.
- Cross-lane: quick-add (#-only, non-##) and /commit ('/commit' name-match via withHandler('handleCommitCommand')) dispatch disjoint in Chat.php; judged-set re-armed for commit; weld-cures all landed (bff8919ca census re-key, a2d5d0e3f ARCHITECTURE 59→60, d6b999ee8 en.php union, 9b1853cc1 settings numeral 134→135 rider carrying C-TUI-2.c's never-landed one).
- Laws/hygiene: authors+committers detain@interserver.net on all wave commits; no W19 worktrees/branches left; no weld-only files in lane commits; suite-figure staleness pair = expected standing, not flagged.

## Findings
- MINOR-1 plan_comparison.md:2329 — cite names 7a9f8de48, a lane hash NOT on master (docs-only delta landed as pick 6c42f30ca at weld fb3bc2a26; content is identical FIX-ROUND-4.md). Fix: append "(docs delta master pick 6c42f30ca)" to the ✅ header at next weld stamp.
- MINOR-2 plan_comparison.md:2273 vs brief — wave carries 10 steps, not 11; header is correct, no repo change (ledger note only).

## Foreign standing reds at tip (attributed, NOT W19)
- candy-code/tests/Chat/PaletteSupersetDispatchTest.php:183 RED: judged-set lacks actual 'instructions' (index 4) — registered by W20 C-CTX-3.a pick e80d84b40, which never touched this test. W19's 'commit' row is present and correct. Fix belongs to W20 loop: add 'instructions' at index 4 (no in-flight cc/* lane owns this file).
- candy-code/tests/Chat/ChatHostLangCatalogueTest.php:90 RED: 6 keys (chat.startup.personal, chat.attach.pdf_pages, chat.effort/serviceTier.launch_unshaped, chat.permission.persisted/not_persisted) all have live consumers outside the guard's scan roster (StartupSummary.php:240, FileMentions.php:340, LaunchContext.php:2199/:2304, SavedRules.php:150/:155) and come from W11 23fa8ab8e / W13-16 C-TUI-7.a 97113b210 / C-PRV-1.b a992413b0; batch-7 weld already recorded it foreign-standing. Zero W19 keys are offenders. Fix: extend roster in the owning loops.

NIT: main tree carries 50-path uncommitted foreign WIP (incl. InstructionDiffTracker.php); gates ran on the live merged tree per instructions.
