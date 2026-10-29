# C-PRV-4.a — CLOSED

Commit: `958c5b939` on `cc/C-PRV-4.a` (base b9a3b0fff, worktree /home/sites/cc-cmp-C-PRV-4.a, never pushed). 10 files, +615/−10. Author/committer detain verified separately (1/1), zero Co-Authored.

## Shipped (all BRIEF items)
1. NEW `src/Providers/HonoursHostPermissionMode.php` — one-method interface `withHostPermissionMode(?PermissionMode): static`, ShapesServiceTier seam family (C-PRV-3.a).
2. EngineBackend per-turn rebind: `withHostPermissionMode()` public wither + private `honouringHostGate()` reading the LIVE PermissionGate at the three turn entries (complete(), completeTranscript(), runCompleteInChild()); summariser child untouched (not a gated turn). Rostered in `EngineBackendWitherPreservesStateTest::withers()` IN-STEP (owned fields = [], uniform-copy semantics so the sweep's new-instance contract holds).
3. ClaudeCodeProvider implements the interface; mode→child spelling map narrowing-only: Default→manual, AcceptEdits→acceptEdits, Plan→plan, Auto→auto, DontAsk→dontAsk, BypassPermissions→bypassPermissions (only when the host gate IS bypass); absent/unbound mode sends NO flag. options() carries `permissionMode`; withModel/clone carry isolate+hostMode via get_object_vars.
4. Block key `isolate` (default TRUE, moot when bare:true — trio never rides a bare argv): `--setting-sources project,local --strict-mcp-config --disable-slash-commands` verified word-for-word in `claude --help`; TYPE_SCHEMAS['claude-code'] optional + `configuredIsolate()` normaliser (loud refusal on garbage, same law as bare).
5. `PROBED_CLAUDE_VERSION = '2.1.312'` const on ClaudeCodeInvocation (house doctrine, mirrors CodexInvocation::PROBED_CODEX_VERSION) + `ISOLATION_SETTING_SOURCES`.
6. Honest residuals documented + ONE launch notice (`provider.claude_code.isolation_notice`, en.php tail block): CLAUDE.md auto-discovery and auto-memory ride no switch outside --bare (2.1.312 has --safe-mode but it disables far more than this step's scope — not adopted, disclosed).
7. Docs moved same commit: README (table row, :214 exception sentence, :1694 full prose), docs/SETTINGS.md isolate paragraph (outside generated regions; `gen-settings-doc --check` = up to date). OAuth hazard: credentials live in keychain/OAuth store not setting sources; live login could NOT be probed here → doc-notice path taken (README+SETTINGS state it).

## Gates (targeted only, plain pipe, cwd candy-code)
- CliProviderIsolationTest: 28 tests / 63 assertions OK (NEW FILE — welder mints durations row; suite-figure untouched by law).
- Providers family (ClaudeCode|ProviderFactory|Codex): 373T/1189A OK.
- EngineBackend family: 294T/1979A OK (after rostering the wither — caught by the sweep, cured in-step).
- Census batch (StderrEmitterCensus|DuplicatedTestHelperDrift|GlobDialect|TreeWideGuardRoster|BootstrapLaunchFormat*|LaunchNoticeCap): 162T, only 1 FAIL = FOREIGN-INHERITED (below).
- Doc guards (ReadmeRoster|SettingsSchemaDocDrift|DocFigure|EnvRoster|TrustKey|ReadmeSettingsTierClaim): 182T/44557A OK.
- php -l all 8 touched php files: clean. Mutation M1 (`'isolate' => false` in options()): exactly 2 targeted reds, cp+md5 restore clean.
- 2 PHPUnit deprecations in every suite output = `tests/Config/AccessorDeprecationTest` non-public data providers (file untouched, foreign).

## Foreign base reds (NOT mine; verified)
1. `ChatHostLangCatalogueTest::testEveryCatalogueKeyIsAskedForByChatOrHost` — 6 dead chat.* keys (startup.personal, attach.pdf_pages, effort.launch_unshaped, permission.not_persisted/persisted, serviceTier.launch_unshaped); all 6 exist at base en.php, askers live outside the test's sources() whitelist (LaunchContext/SavedRules/StartupSummary/FileMentions). My en.php diff adds only the provider key.
2. `BootstrapLaunchFormatConstantsTest::testEveryDocPageQuotingAPromotedFormatIsOnTheReadersRoster` — PROJECT_TIER_TOOL_REMOVAL_FORMAT pattern matches NO page (base blobs false under reflection-driven shapePatternFor probe; base↔current quoted-example bytes identical, test file identical to base).

## Seams for welder/gate
- durations.tsv row + suite-figure re-pin for CliProviderIsolationTest (+28T).
- The two base reds above need the owning lanes (whitelist roster + page-quote bound re-derivation).
- FallbackProvider (factory wrapper) does not implement HonoursHostPermissionMode — a fallback-wrapped claude-code route stands down at the seam (same known shape as C-PRV-3.a service-tier leg); widening is a later step if wanted.

## FIX ROUND 1 (REVIEW-1: 0C/0M/3m — all three landed, one commit)

- **m1** `src/Backend/EngineBackend.php` — `honouringHostGate()` docblock truth. The old text claimed Shift+Tab "mutates the very PermissionGate object this field points at"; false — `PermissionGate::withMode` (:346) returns a NEW gate, and `Chat::swapPermissionGate` (:18419) rebinds the backend via `withPermissionGate` on every switch, so the FIELD points at the current gate. The docblock now says the rebind mechanism; the per-turn-read conclusion is unchanged. docblock-only, zero behavior change.
- **m2** NEW pin `CliProviderIsolationTest::testTheSummariserNeverCarriesTheHostMode` — the summariser exclusion (structural+deliberate, REVIEW-1 judgment (d)) now pinned: structural leg counts `->honouringHostGate()` call sites in the EngineBackend source == 3 (the three turn entries) and asserts neither `honouringHostGate` nor `withHostPermissionMode` appears inside the `summaryReply()`/`newRuntime()` reflection slices; observable leg asserts the provider FIELD — the object newRuntime hands the summary Runtime — carries no `permissionMode` key while the backend stands on a live Plan gate. Mutation: routing newRuntime through the seam → count 4≠3 reds the pin (cp+md5 restore clean).
- **m3** `README.md:1694` — mode-mirror sentence qualified with the fallback exception: "…rides down as --permission-mode — narrowed only, never widened past what the host granted (unless the launch falls back, `fallbackModels`: the `claude-code` child then sits behind the wrapper and the mirror stands down)". Static+live verified: no guard re-derives that paragraph — zero tests name any phrase of it; the only `<!-- settings:` region pair lives at :1247/:1289, outside :1694; ReadmeSettingsTierClaimTest (the neighbouring tier-claim guard, the only guard the reviewer flagged as word-adjacent, serviceTier) pins OTHER sentences only and runs green (6T), as does the whole doc-guard family.
- **Gates**: CliProviderIsolation 29T/71A OK (+1T/+7A exact); `Backend\EngineBackend` band 272T/1894A green; Providers namespace 2418T/8641A green; doc-guard family (DocFigure|ReadmeRoster|TrustKey|EnvRoster|ConfigWriteProducer) 166T/44330A green; php -l clean. The loose regex `--filter Providers` (2546T wide match) shows 2E/2F PROVEN FOREIGN at parent by file-copy revert of all three fix files (same red set: SystemPromptWiring InstructionDiffTracker TypeError x2, NonInteractiveProviderFailureTest:432, MediaSettingsTest:254 mediaKinds-on-openrouter) — neither my diff nor the round.
- **Weld carry updated**: durations row + suite-figure re-pin for CliProviderIsolationTest now +29T (was +28T); foreign base-reds and seams unchanged. REVIEW-1.md lands as the canonical record with this commit.
