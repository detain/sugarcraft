# C-PRV-4.b — Codex isolation, mode → `-s`, `--output-schema`

STATUS: COMPLETE (4 commits, tree clean, branch cc/C-PRV-4.b in /home/sites/cc-cmp-C-PRV-4.b)

## Commits (all cat-file-verified; %ae AND %ce = detain@interserver.net; zero Co-Authored trailers)

- a455af32c — skeleton: CodexIsolationTest anchor file (step 0)
- 51a84224c — src trio: CodexProvider / CodexInvocation / ProviderFactory (3 files, +247/−22)
- 4f96ea5fe — tests: CodexIsolationTest real pins + CodexProviderTest options-pin flip +
  CliProviderIsolationTest non-honouring exemplar Codex→Echo (3 files, +327/−21)
- 2bed7ad84 — docs: README.md (providers-table codex row, :214 exception sentence codex
  clause, :1708 posture paragraph) + docs/SETTINGS.md codex isolate paragraph (2 files, +18/−3)

Tip: 2bed7ad84 (cat-file: commit). Diff vs base 0c1ad6c64: 8 files, +600/−48.

## What landed

1. `CodexProvider implements HonoursHostPermissionMode` (C-PRV-4.a interface, welded
   204c33848). Ctor `bool $isolate = true` + `?PermissionMode $hostMode = null`;
   `withHostPermissionMode()` clones via get_object_vars (claude-twin shape); `isolate()`
   accessor. EngineBackend's existing per-turn rebind (src/Backend/EngineBackend.php:974-1000)
   picks the provider up — NO EngineBackend edit (interface-based, as designed).
2. `options()` ALWAYS emits `sandbox` (pre-step null deferred to config.toml = the root
   defect: plan host mode over workspace-write config still edited). Plan/DontAsk →
   `read-only`; else configured ?? `workspace-write`; `danger-full-access` only from the
   operator's configured spelling, never widened by a host mode.
3. `isolate` (default true — §6.2 ruling names the Codex route) adds
   `--ignore-user-config` (recorded from `codex exec --help` at installed 0.154.0,
   2026-10-29: "Do not load $CODEX_HOME/config.toml; authentication still uses
   CODEX_HOME"). isolate:false restores inheritance — the escape is documented in both
   pages per the risk clause.
4. `--output-schema` channel wired: `CodexInvocation::spillOutputSchema()` tempnam-0600
   file (OUTPUT_SCHEMA_FILE_PREFIX='sc_codex_schema_'), execArgs emits
   `--output-schema <path>`; provider removes on every route out — complete() finally +
   stream pre-spawn-throw + drain finally (3 sites, claude twin's shape).
   `supportsJsonSchema()` STAYS FALSE: flip is gated on a recorded authenticated probe;
   this host has no codex login (`codex login status` → "Not logged in") — no fabricated
   capability. PROBED_CODEX_VERSION stays '0.154.0' (its pin test unchanged; help-probe
   date recorded in the docblock).
5. ProviderFactory: codex TYPE_SCHEMAS optional += 'isolate'; createCodex threads the
   SHARED `configuredIsolate()` normaliser ('sometimes' → InvalidArgumentException).

## Gates (targeted only; cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)

- CodexIsolationTest|CodexProviderTest → OK: Tests: 27, Assertions: 107 (rc=0; 2 pre-existing deprecations)
- CliProviderIsolation|ClaudeCodeProvider|ProviderFactory|Codex pair → OK: Tests: 256, Assertions: 705, rc=0, 0F
  (NOTE: ProviderFactoryTest's 2 fallback-pair reds observed at base during exploration are GREEN at tip)
- ReadmeRosterDrift|DocFigureProseDrift|GlobDialectDifferential|DuplicatedTestHelperDrift → OK: 129, 4764A
- + ReadmeSettingsTierClaim|EnvRosterDrift after docs edits → OK: 172, 44704A (rc=0)
- --filter 'Codex|codex' (login/surface family) → OK: 43, 164A (rc=0)
- broad --filter 'Provider' → Tests: 2842, Assertions: 11736, **1F: MediaSettingsTest::testTheProviderSchemaCarriesTheMediaOptionals** (openrouter mediaKinds)
- combined battery incl. StderrEmitterCensus/BootstrapLaunchFormat/WitherPreservesState → Tests: 512, 28854A, **1F: BootstrapLaunchFormatConstantsTest::testEveryDocPageQuotingAPromotedFormatIsOnTheReadersRoster**

## Inherited reds (BOTH proven pre-existing at base 0c1ad6c64, NOT caused by this lane, not in owned files)

1. `BootstrapLaunchFormatConstantsTest::testEveryDocPageQuotingAPromotedFormatIsOnTheReadersRoster` —
   re-ran with README+SETTINGS reverted to base content (cp-swap, restored byte-clean after):
   rc=1 with a BYTE-IDENTICAL diff shape: live sweep no longer matches
   `PROJECT_TIER_TOOL_REMOVAL_FORMAT` on its rostered pages (example block reflowed by an
   earlier lane; the roster still lists it). Root cause lives in whoever moved that example;
   fix = roster/PAGE_QUOTES adjudication in BootstrapLaunchFormatConstantsTest.php — outside
   this lane's declared files → reported to the welder.
2. `MediaSettingsTest::testTheProviderSchemaCarriesTheMediaOptionals` — ProviderFactory.php
   openrouter row carries mediaKinds/mediaBaseUrl/mediaApiKey at BASE (git show 0c1ad6c64
   :177 byte-identical to tip :179) while the test's exemption list is only
   ['sglang','custom'] and the test file itself is unmodified by this lane (empty diff vs
   base). Red at base by construction. Welder duty.

## New test files (durations.tsv carry for the gate)

- candy-code/tests/Providers/CodexIsolationTest.php — 11 tests (fake codex records child
  argv + schema-file {existed,perms=0o600,content}; -s/ignore-user-config/schema lifecycle)

## Laws

- E646: zero wall-clock timeouts added (test child carries the house 20s self-alarm only).
- No new proc_open sites (argv flags + tempnam only) → DescriptorInheritanceGuard untouched.
- GlobDialect: no glob-shaped literals (scandir-loop tearDown; PathGlob corpus figure unchanged — guard green in 129T/44704A runs).
- DuplicatedTestHelperDrift: helper names unique vs CodexProviderTest/CliProviderIsolationTest twins.
- Never touched: suite-figure.json, parallel-tests-durations.tsv, dist/, protocol trio, lang/en.php.
  No stash (cp-swap harness only), no caliber, branch cc/C-PRV-4.b confirmed before first edit and before each commit.
- Co-tenant discipline: README/SETTINGS edits are codex regions/paragraphs only (no other lane's rows touched).
