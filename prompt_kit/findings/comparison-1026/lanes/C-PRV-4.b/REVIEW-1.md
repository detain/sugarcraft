# C-PRV-4.b r1 review — Codex isolation (reviewer: cc-cmp step reviewer, 2026-10-29)

VERDICT: CLEAN

Scope verified: branch cc/C-PRV-4.b, tip 2bed7ad84 on base 0c1ad6c64 (ancestry OK), 4 commits, tree clean, 8 files +610/-32 — exactly the brief's create/modify set plus `tests/Providers/CliProviderIsolationTest.php`, whose 1-hunk flip (codex exemplar → EchoProvider) is a §1.8-forced in-step guard move (C-PRV-4.a's stand-alone pin MUST fall the same commit as codex entering the contract). No undeclared files.

## Focus-item adjudication

1. **-s can never regress to danger-full-access.** `effectiveSandbox()` (CodexProvider.php:444-451) emits read-only only for plan/dont-ask, else `sandbox ?? workspace-write`; `danger-full-access` rides ONLY the verbatim configured dial. Mutation reasoning: neuter the always-`-s` arm (restore `if ($this->sandbox !== null)`) → reddens testTheDefaultProviderStillSendsAnExplicitSandbox (options-shape leg + child-argv leg) AND the exact-array flip in CodexProviderTest:186-190 (≥3 reds). Any widening (e.g. bypass→danger) → testBypassPermissionsNeverWidensAndDangerOnlyRidesWhenConfigured reddens both legs. Narrowing arm removal → plan+dont-ask pins red on both the reflection shape and the fake-child argv. argv position pinned by the exact-array testExecArgsPlaceTheNewFlagsInTheirDeclaredPositions.
2. **isolate default-true, honest opt-out.** Default true in ctor (CodexProvider.php:104) + factory `configuredIsolate` (shared claude normaliser; typo throws). Pin pair `assertContains`/`assertNotContains('--ignore-user-config')` on spawned argv covers both polarities; factory normalisation pinned (true/false/'false'/'sometimes'→throw). Doc opt-out in README:1706 + SETTINGS.md:635-647.
3. **Spill 0600 + teardown routes.** tempnam (0600) → child records `fileperms & 0777` and asserts 0o600 (fake child, inside-run observation — not a parent-side echo). Routes: complete() finally (CodexProvider.php:225-233), completeStream !is_resource pre-throw (:270-274), generator finally AFTER terminateAndClose (:377-382) — the stream route covers abandon/throw via generator destruction. Two of three pinned live (success + stream); the !is_resource route is inspection-only (forcing a post-spawn proc_open failure is disproportionate) — NIT below. Schema-free run: no flag, no file (pinned).
4. **HonoursHostPermissionMode production reach.** Interface in base (C-PRV-4.a); EngineBackend::honouringHostGate() calls withHostPermissionMode at EngineBackend.php:1692/1742/5212 (turn heads) and the instanceof leg (:979) now honors CodexProvider. Sole `new CodexProvider` site is ProviderFactory::createCodex:1475. Clone via `new self(...get_object_vars)` carries isolate/sandbox/hostMode across withModel/withHostPermissionMode (pinned in testTheHostModeRebindCarriesEveryOtherDial).
5. **Doc-line disjointness.** Branch hunks README :211+:1700-1706, SETTINGS :632+; C-SEC-8.f (f491d8187) touches README :2253 only and is NOT an ancestor of either master tip 103a385aa or base 0c1ad6c64 — parked; weld safe either order (hunks >500 lines apart, generated regions untouched; new SETTINGS prose lives outside all `<!-- settings:* -->` markers).
6. **E646.** No wall-clock cap added anywhere in src; drain loop keeps the "BOTH PIPES DRAINED … no wall-clock cap" discipline; only test-fake carries the house 20s self-alarm. No new proc_open sites → DescriptorInheritanceGuard untouched, correctly. spawnSpec/env parity preserved.
7. **supportsJsonSchema() stays FALSE** with wired-unadvertised channel + probe-record doctrine (class docblock + README/SETTINGS both truth-state it). No fabricated success path; encode/write failures throw.

## Gates (my runs, worktree, linked vendor, plain pipe -u LINES -u COLUMNS)

- php -l ×5 changed PHP: clean.
- `--filter 'Codex|ProviderFactory|ReadmeRosterDrift|EnvRosterDrift|SettingsWriter|ConfigWriteProducer'`: Tests: 321, Assertions: 45750, Failures: 1 (see MINOR-1) — all lane-owned + doc guards green inside.
- `--filter 'CodexIsolationTest|CodexProviderTest'`: OK — Tests: 27, Assertions: 107 (2 pre-existing PHPUnit deprecations) — builder figure EXACT.

## Findings

- **MINOR (out-of-step, NOT caused by this lane — for the run-state roster/welder, not a fixer task):** `SettingsWriterCensusTest::testTheWriterIsSavedThroughOnlyByItsKnownProducers` red — derived extra producer `Cli/Bootstrap.php::stampBundleRecommendations->write`. Proven inherited: Bootstrap.php and SettingsWriterCensusTest.php are both byte-identical base↔tip (lane diff empty for them; grep of the lane's src hunks finds zero new settings-producer calls), and the same red shape stands at master 103a385aa (method present ×2, roster line ×0). It is a C-EXT-3.e-era carry the disclosed-reds list missed; fix = roster row in SettingsWriterCensusTest by its owning/weld lane. The lane's two disclosed inherited reds (MediaSettingsTest, BootstrapLaunchFormatConstantsTest) carry sound construction-proofs and sit outside my filter (not re-run; attribution accepted).
- **NIT:** README codex exception sentence and SETTINGS.md "isolate: false restores the config inheritance" overstate one dimension: `-s` rides unconditionally, so isolate:false restores config.toml hooks/profiles/MCP but NOT its sandbox default. One-clause fix: "(the explicit `-s` pin stays regardless)". Code is exactly as the brief specifies; the same sections already state "-s always rides".
- **NIT:** the !is_resource spill-teardown route is inspection-only (unpinnable without forcing proc_open failure); acceptable, mirrors the claude twin.

## Ledger hygiene

New test file CodexIsolationTest.php listed in lane REPORT for the durations.tsv carry (gate/weld duty; §1.5 honored — suite-figure.json/durations.tsv/dist untouched). Generated protocol trio absent from diff ✓.

**Exit condition met: 0 critical / 0 major / 0 step-local minor → weld-eligible.**
