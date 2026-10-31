# C-TOOL-4.d — sandboxScope: jail hooks, lint, format and test runs — BUILDER REPORT

Lane: /home/sites/cc-cmp-C-TOOL-4.d branch cc/C-TOOL-4.d. Base: db1441a61 (= master at work time, after the BubblewrapHardening pairing cure ff; zero own picks at this writing). Brief: design-tool.md ### C-TOOL-4.d + lanes/C-TOOL-4.d/REVIEW-1.md census points 1-10 (ALL remaining — step never built).

STATUS (live, updated incrementally):
- Slice 1 (core seam: SandboxScope consts + sandboxScope setting full §1.8 cascade + forScope door + lang keys + README/SETTINGS/LayeredSettings regen + LayeredSettingsTest roster): WRITTEN, guards being verified, NOT yet committed.
- Slice 2 (ScriptHook jail legs PREFIX-ONLY + HookManager withScriptSandbox/loadEntries + Bootstrap hooks() block): WRITTEN (hook jail live in the block), gates pending.
- Slice 3 (LintRunner/TestRunner/FormatRunner withSandbox + wrap-at-run + 126-classification skip notes + Bootstrap widening to 4 jails): WRITTEN, gates pending.
- Slice 4 (ScopedRunnerJailTest 6 pins + docs/HOOKS.md + docs/PERMISSIONS.md): NOT STARTED.

Key discoveries this session:
- --filter '|A::|B::' regex form has EMPTY alternative → matches every test (ran whole suite twice by mistake; killed both). Correct: '(A|B)::'.
- Base bubblewrap absent on host; fake-binary trick Bubblewrap::new('/bin/true') drives argv/wrap; probe memo is class-level keyed binary+network-flag; base db1441a61 carries public static Bubblewrap::resetProbeVerdictsForTesting() — ScopedRunnerJailTest setUp MUST call it.
- README "never taken from a project file" count word is a GENERATED COUNT ANCHOR (tools/gen-settings-doc.php --write rewrote eighty-seven→eighty-eight for my key); base probe worktree proved base guard green; my derived WT count = 88 → word eighty-eight. Manual bump to eighty-nine was double-counting — reverted. The KEY NAMES in that paragraph are author-maintained: added the `sandboxScope` clause beside the bashSandbox family.
- 126 is house CLASSIFICATION for "command cannot start" (LintReport::COULD_NOT_RUN reasoning), used for jail-refusal skip reports; honest output = sandbox.runner.skipped Lang sentence ("Skipped: ... cannot start ... Nothing ran ..." — mirrors Bash's "Not run:" shape, DenialPrefixRoster-safe).
- ScriptHook carries (backend, refusal) pair because its spawn path has no captured-run probe door; runners probe themselves (memoised).

## Slices 2-4 LANDED (lane cc/C-TOOL-4.d, 4 picks off base d7b3654b5)

- cd746f4c9  core seam: SandboxScope.php (+SandboxScopeValidator.php, deviation via
             ThemeNameValidator precedent), SandboxFactory::forScope (bashSandbox-off
             => null, scope inert), ToolSettings definition (StringList [], user-tier,
             Security), lang/en.php 4 keys, README/SETTINGS/LayeredSettings regen
             (gen-settings-doc --check rc0; eighty-eight trust-count anchor is
             generator-owned), LayeredSettingsTest user-tier literal extended.
- c37c90f40  ScriptHook jail: ctor tail sandbox+sandboxRefusal pair, withSandbox
             (immutable rebuild), withTimeoutSeconds carries pair, executeStaged top
             refusal door -> HookResult::deny(sandbox.hook.not_run), spawnCommand
             PREFIX-ONLY at both existing @proc_open sites (count unchanged,
             DescriptorInheritanceGuard green, no new roster row); payload tempnam
             files ride argv(readOnlyBinds:) --ro-bind-try pairs; jail ends
             --chdir <root> -- so hook cwd IS the jail chdir. HookManager:
             withScriptSandbox mutates-in-place EXACTLY mirroring withPromptBackend
             (install-before-load), loadEntries ScriptHook arm captures the pair.
- 5a4f79165  runners: Lint/Test/Format withSandbox + probe-before-run door (memo via
             (new Bash(sandbox:..))->sandboxRefusal(); unprobed embedder pays one
             bounded per-command probe, E646-clean). Refusal => 126 house
             classification report + sandbox.runner.skipped note (never a pass, never
             unconfined; AutoTestHook stop rides couldNotRunReason for free).
             In-scope EXECUTED line = backend->wrap($line,$root); out-of-scope
             byte-identical (verified pins). TestReport command field keeps the raw
             command (historical convention, disclosed). Bootstrap hooks() region
             only: $jails map foreach SCOPES, one probe answers all four (identical
             fromSetting key), runner chains ->withSandbox($jails[...]).
             DocFigureProseDriftTest 4 seam windows 9000->9500 with dated comments
             (explicit C-TOOL-5.b 9000->12000 precedent).
- 5678b522e  NEW tests/Tools/Sandbox/ScopedRunnerJailTest.php 6T/76A + HOOKS.md
             'Sandboxed runs' section (incl cache-into-project/jail-tmp recipe +
             payload read-only-bind rationale) + PERMISSIONS.md sandboxScope bullet.

Mutations 5/5 discriminating (cp+md5 restored): M1 strip wrap -> pin1 1F; M2 neuter
hook refusal door -> pin5 1F; M3 requests() unknown->in_array -> pin2 1F; M4 drop
readOnlyBinds arg -> pin3 1F; M5 forScope off-inversion -> pin6 1F.

Gates: jail family combined filter (ScopedRunnerJail|ScriptHook|HookManager|LintReport|
TestRunner|FormatRunner|SandboxFactory|BubblewrapHardening|BashSandbox) 222T/956A OK —
PAIRING GREEN TOGETHER on cured base db1441a61+ (owner expectation confirmed, no new
finding). Guard batch (DocFigure|SettingsSchema|TrustKey|CliLangCatalogue|EnvRoster|
ReadmeRoster|ReadmeSettingsTierClaim|ConfigWriteProducer|HookEnvironmentDoc) green;
earlier 115T/42442A + 151T/46148A batches OK. Bare guards all OK: TreeWideGuardRoster,
DuplicatedTestHelperDrift, DescriptorInheritanceGuard (15T, no new row), Duplicated-
DocBlockLine. Hooks/runners batch 355T/1288A OK. gen-settings-doc --check rc0.
en.php dupscan: 4 added keys appear exactly once; whole-file key census 3175, ZERO
duplicates. All 4 commits %ae=%ce=detain@interserver.net (4/4), 0 Co-Authored trailers,
cat-file -t commit x4. Tree clean; zero web files touched (vitest n/a).

NEW *Test.php for §7: candy-code/tests/Tools/Sandbox/ScopedRunnerJailTest.php — LIVE
6 tests / 76 assertions. ReadmeSuiteFigureDrift staleness pair is the EXPECTED accepted
red (+6 live enum); suite-figure.json/durations.tsv untouched per law.

Master notes for weld: base moved twice mid-lane (db1441a61 pairing cure ff'd in before
first commit; d7b3654b5 C-TOOL-6.f Canvas was my pick base). Post-base master 570531559
= 4.h weld + C-TUI-6.f web-i18n chain — touches candy-code/lang/en.php (different
regions than mine; expect clean union, glance at sorted-run adjacency) and does not
touch my docs regions beyond PERMISSIONS/README non-overlap verified on file list.
