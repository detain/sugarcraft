# W18 wave-loop review 1 — tip reviewed: 86f80fef8 (also current at verdict time)

VERDICT: FINDINGS — 0 CRITICAL / 3 MAJOR (1 W18 debt, 2 foreign-inherited surfaced by merged-tree re-derivation) / 1 MINOR / 2 NIT.

## MAJOR
1. W18 DEBT — candy-code/tests/Support/ReadPathCensusTest.php:1310 red at tip. C-AGT-4.b (master 5e30f5e94) added the wave's third Chat.php file_get_contents (:13724, primary-agent mode-key read-back from the launch-trusted preset file) with no READ_PATHS verdict; census says Chat.php|file_get_contents expected 2, actual 3. Fix: add the verdict row for the new sink (contained trusted-home preset read — classify like the other launch-trusted reads) AND the same run's Bundles/* leg (below #2) — both are in the one red diff. File unclaimed by in-flight lanes.
2. FOREIGN (W21 C-EXT-4.d, 0273a6178) — ReadPathCensusTest: Bundles/BundleRecommender.php|scandir, BundleSource.php|file_get_contents, Marketplace.php|file_get_contents carry no verdict; SettingsWriterCensusTest:145 red on unclassified `Cli/Bootstrap.php::stampBundleRecommendations->write`. W18 welds edited both roster files (C-TUI-5.d riders), so the wave loop catches it — route the cure to W21's fixer (roster rows in the two tests only; no src claim collision).
3. FOREIGN (W19 C-TOOL-2.b, 5cdc6f440) — candy-code/tests/Tools/ToolCapSettingsTest.php red x2 at tip: ToolLimits::KEYS carries WEB_SEARCH_BACKEND_KEY but the test's $expected default roster never gained the line (assertSame KEYS vs keys(expected) 22 vs 21) and the else-branch risk pin expects Tuning while the src definition is Egress (:146). Fix: add `ToolLimits::WEB_SEARCH_BACKEND_KEY => '<default>'` to $expected in KEYS order and classify it beside WEB_SEARCH_ENDPOINT_KEY's Egress treatment (mirror WebSearchEndpointSettingTest). Red at 5cdc6f440 itself (roster verified absent in that commit's copy) — W18's ca69cb333 touched the same file but its own lines are coherent. Unclaimed file.

## MINOR
4. Weld hygiene — branch cc/C-AGT-4.b + worktree /home/sites/cc-cmp-C-AGT-4.b (at lane c4fb3580f, `git cherry` = '-', absorbed as 5e30f5e94) still exist after the weld; the worktree's untracked prompt_kit/findings/comparison-1026/lanes/C-AGT-4.b/REPORT.md is the ONLY copy (master's tree holds just BRIEF/REVIEW-1/WELD). Fix order: copy REPORT.md into the main tree and commit with the wave-close evidence, THEN `git worktree remove` + `git branch -d cc/C-AGT-4.b`. Other 9 W18 lanes pruned clean.

## NIT
5. COMPARISON.md cells now stale post-W18 (MOD-N2 :154 "no fast mode or service tier", MOD-25 :152 "no OS keychain", summary rows :64/:936) — plan assigns flips to closeout via apply_comparison.py --check; closeout must include these four cells.
6. C-TOOL-5.a planned modify of EditTest/WriteTest/ApplyPatchTest did not land; coverage rides the two new files instead (reviewed clean) — plan-text erratum only.

## Gates run (merged tree, cwd=candy-code, plain pipe)
- Generators --check rc0 x4: gen-settings-doc, gen-command-docs, gen-tool-docs, scripts/gen-protocol-schema ("up to date" all).
- Drift sextet KeyBinding|CommandDocs|SettingsSchemaDoc|EnvRoster|TrustKey|ReadmeRoster: 309T OK.
- Guard batch Descriptor|LangParity|SettingsWriter|ReadPath|StderrEmitter: 141T/2F (= MAJOR-1/2).
- PrimaryAgent+ServiceTier+Keychain+ProviderFactory: 175T OK. ReadBeforeWrite/Bom/DiffReview/CheckpointDiff/MessagePicker: 81T/2F (= MAJOR-3). Recap|Btw|FirstRunWizard|ProviderKeyStore|AgentsCommand|SessionHostCommands|KeyBindingRegistry: 110T OK. tools ChildLifetimesToolTest: 9T OK.
- Keychain spawn discipline read: bounded TIMEOUT deadline + stream_select, MAX_STDOUT cap, stdin-close, TERM/KILL ProcessReaper ladder, DescriptorInheritanceGuard row Keychain.php::spawn present (:624). E646-clean (local helper call, not an LLM timeout).
- Claims vs landing: all 10 steps' ✅ marks are master ancestors (963ddd0f6/2e32c90bf are lane hashes; master carries aa0e46158+cure / ff32fbd87+riders — matches plan prose). CommandEffectKind::SetPrimaryAgent + Chat apply arm :12126 + SessionHost :1901 + Alt+A chain (HandlesKeys:980, Registry:971) landed; lang dup-scan clean, all 8 W18 key namespaces present; /agents use verb row COMMANDS.md:372.
- durations.tsv: 11/11 new phpunit test files carry rows. No wave commit touched suite-figure.json. Authors %ae/%ce detain 20/20 wave commits, zero Co-Authored trailers.
- dist: HEAD manifest↔assets↔index.html consistent; last src-web and last dist touches are the same commit (718aa187f) — W18's web riders current. vitest not re-run (main tree npm-untouchable; welds ran 645/645 + batch-10/13 re-serials on supersets of W18 src-web).
