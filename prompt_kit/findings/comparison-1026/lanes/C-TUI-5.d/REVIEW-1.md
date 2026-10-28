# C-TUI-5.d REVIEW-1 — VERDICT: CLEAN (0C/0M/1minor/2nit)

Gate actuals re-derived at tip 963ddd0f6, ALL EXACT (plain pipe, cwd=candy-code): new files 28T/95A; SettingsWriterCensus|ReadPathCensus 24T/5436A; lang/glob family 95T/7380A; peers 317T/2683A; combined 147T/12911A (=28+24+95 / 95+5436+7380); php -l 7/7; one-type-per-file verified per-file (7×1); 2 deprecations ride baseline.

Core judgments:
1. shouldOffer 4 doors ALL pinned non-vacuously: selected→:243, configured→:244, TTY→:245, stamp→:256-259 (markDismissed-then-false leg).
2. Pure model holds: update() total, field Cmds discarded ([0]-slice), no FS/Cmd leak; masked Input is widget-side (Input::withPassword → TextInput EchoMode::Password renders str_repeat('*', length)); pin proves secret absent + chr(42) present; raw key rides only the Finish action (contract-correct).
3. ProviderKeyStore::store is a line-faithful extraction of loginStoreKey (Subcommands.php:1079-1136 compared: merge, control-chars, type_mismatch, corrupt, spread order, re-read-verify all identical); atomic/0600 shape lives in the pre-existing writeUserConfig door (no new chmod site, no TOCTOU introduced).
4. Stamp law: HomeDirectory::resolved() prefers HOME env ⇒ sandbox pin genuinely discriminates (passwd-fallback would miss the tmpRoot assertFileExists); MacAltNotice mkdir/put idiom byte-parallel; fail-soft toward OFFERING pinned both polarities.
5. Finish/Skip/Cancel = action objects only; wiring explicitly deferred to C-TUI-5.e per brief — dormant-by-design, not dead-code-accused.

Scope/census/§1.9: 10 files == brief exactly (5 src creates, en.php, 2 test creates, 2 census edits); no weld-only files; ReadPathCensus subdir key fixed + 2+1 rows match call-site counts; SettingsWriterCensus row 1; chr(42) done, zero glob-shape or **Refused:**-shape literals (GLOB gates green).

Salvage/contamination: DuplicatedTestHelperDrift red at lane tip = EXACTLY the inherited CacheTtlIdleFold|ModelMetadata NOW-pair; cure 240ba85c1 confirmed in master ancestry → merged tree GREEN, not held. P-AGENT.b (f1ec9a0d7) NOT an ancestor of lane base; master has zero tui.wizard/ProviderKeyStore rows → no duplication. Welder NOTE: master moved +7 en.php commits and +1 ReadPathCensus commit (e209a8544) since base → EOF/census context overlaps are re-anchor resolutions, not content clashes. removeTree helper pair follows entrenched convention (8+ existing files), helper arm green.

MINOR (step-local): FirstRunWizardTest.php:291 testThePipedSuiteShapeAnswersNoOffer is tautological — passes stream_isatty(STDOUT) AS the door input behind a guard that early-returns on its truth; only re-pins :245's door arithmetic; nothing in src can redden it that :245 misses. Fix: add `self::assertTrue(FirstRunWizard::shouldOffer(false, false, true));` (default stamp path, fresh sandbox home) — pins the default-path composition the wiring step will trust.

NITs: ProviderKeyStore.php:14 cites "plan_comparison §5" (steps are §4); wizard+keystore control-char regex duplicated (disclosed intentional parity).
