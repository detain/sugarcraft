# C-EXT-6.d — type: prompt hooks (W36 LATE) — REVIEW-1

STEP=C-EXT-6.d LANE=/home/sites/cc-cmp-C-EXT-6.d
VERDICT: CLEAN
C=0 M=0 MINOR=1 NIT=3

## 0. Git-state re-verification (own reads)
- Branch `cc/C-EXT-6.d`, base `56893049b`, tip `e43c6a684`; two picks: `86fb200fb` (PromptHook core + HookConfig 13 ENTRY_KEYS/TYPE_PROMPT/5-tuple perTypeKeys + HookManager promptBackend seam) and `e43c6a684` (Claude-dialect mapping, Bootstrap::hooks() titleBackend pre-load, lang 7 keys, HOOKS.md, guards, skip-example flip).
- `git status --porcelain` = 0. `%ae`/`%ce` detain greps = 2/2 each across the two picks; zero Co-Authored-By trailers.
- `git diff --name-only 56893049b..HEAD` = 14 files, +1575/−82: candy-code/{docs/HOOKS.md, lang/en.php, src/Cli/Bootstrap.php, src/Hooks/{ClaudeSettingsHooks,HookConfig,HookManager,PromptHook}.php, tests/Cli/{BootstrapHookFileTest,BootstrapPromptHookWiringTest,ClaudeSettingsHooksSkipRecordTest}.php, tests/Config/DocFigureProseDriftTest.php, tests/Hooks/{ClaudeSettingsHooksTest,HookConfigTest,PromptHookTest}.php}.
- Forbidden surfaces: NONE in diff (suite-figure.json, parallel-tests-durations.tsv, candy-code-web/dist, protocol trio + SERVER.md + generated.ts, plan_comparison.md all absent).

## 1. SECURITY / execution semantics — PASS
- Fence: `src/Hooks/PromptHook.php:199-203` — payload through `PromptFence::escape` + marker-break sweep (`untrusted()` at `:303-320` rewrites forged `UNTRUSTED_HOOK_INPUT_BEGIN/END` to hyphen spellings), head clip `MAX_INPUT_CHARS=8000` (`:111`). System prompt declares the markers DATA, not instructions.
- Deny reason swept: `Sanitize::untrusted` + `ScriptHook::clip` at `PromptHook.php:234-243`.
- Trust gates MATCH the http arm: project-tier hook files load only under `projectHooksAreTrusted` (`src/Cli/Bootstrap.php:6937`, gate ~`:6822`); user-tier always. `TYPE_PROMPT` rides the same `HookConfig` perTypeKeys 5-tuple refusals (`HookConfig.php:105-113` region); prompt rows can never bypass the file-load gate the http rows face.
- Escalation impossible by construction: `HookRegistry::executeHooks` (`:512+`) scans ALL hooks every pass; DENY/ASK are blocking; an ALLOW from a prompt hook never skips BuiltIn `PermissionGateHook`/`ConfirmRemoveHook`/`ProtectFilesHook` — prompt-allow cannot widen permissions.
- E646: `PromptHook` deliberately NOT `BoundedHookInterface` per design; only provider connect timeout; pinned structurally in `PromptHookTest` (body-slice contains `$backend->complete(`, not-contains `timeout`, boundaries `assertIsInt`).
- /budget adjudication: `titleBackend()` = `toollessBackend()` constructs a fresh `EngineBackend(hooksDisabled: true)` per launch; the chat loop never settles its usage, so prompt-hook spend is NOT counted against /budget. The design block sanctions exactly this ("Whether that spend counts against /budget follows the ExecReviewer precedent; confirm in-lane"); builder confirmed and disclosed in HOOKS.md 'Prompt hooks' ("The spend is not the chat's") + `Bootstrap.php:6351-6359` comment. RULING: sanctioned scope, NOT a MAJOR accounting hole.
- NIT-1: `PromptHook.php:57` says "the precedent's docblock states the same" — `ExecReviewer.php` contains no budget/spend sentence (grep 0 hits). Structurally true, rhetorically over-attributed.

## 2. Wiring load-bearingness — PASS (one disclosure)
- `tests/Cli/BootstrapPromptHookWiringTest.php` test1 drives the REAL `Bootstrap::hooks()` (CANDY_CODE_PROVIDER=anthropic + dummy key) and asserts the loaded `PromptHook->backend instanceof EngineBackend`; deleting the setter line in `Bootstrap.php` reddens exactly that leg (tests 2/3 independent — assertSame identity pin + install-before-load order pin). DISCLOSURE: my own mutation run was blocked by this reviewer's permission map (`php -r`, chained `cp`/`sed -i` denied) → STATIC-DERIVED, UNVERIFIED-BY-RUN; the assertion is unambiguous and not vacuous (no fabricated success path).
- `tests/Hooks/PromptHookTest.php` 21 methods enumerated; per-leg discrimination for dialect mapping (claude unparseable→allow+notice; native unparseable/missing-backend→deny) and refusal arms; hostile-SGR leg asserts `chr(27)` NOT contained — renderable if the sanitizer broke, so a real discriminator; fence-forgery leg counts exactly 1 BEGIN / 1 END / 1 case-insensitive END + hyphen spelling present.
- Live counts: PromptHookTest + WiringTest alone = 24T/118A, matching builder's 21T/107A + 3T/11A exactly. Not the vacuous-pin class.

## 3. §1.8 in-step guards — PASS
- `HookConfig` ENTRY_KEYS 12→13 and `ClaudeSettingsHooks` 10 verified by own awk counts; word-numbers moved SAME commit: `THIRTEEN` docblock, HOOKS.md "Those thirteen", CC-dialect "ten keys" (diff hunks read).
- Skip-and-record semantics verified in `ClaudeSettingsHooks.php`: command/http/prompt RUN; agent/mcp_tool skip-and-record BY TYPE NAME; missing/non-string type still refuses launch — docs match code exactly, and the now-RUNNABLE prompt example was flipped to `agent` in `BootstrapHookFileTest` + `ClaudeSettingsHooksSkipRecordTest` in-step (outside the design's declared test list but mandated by the same-commit guard law — noted, sanctioned).
- Runs (cwd=candy-code, `-c phpunit.xml`, `env -u LINES -u COLUMNS`, anchored filters, PtyRunToolTest::testExpectMiss excluded by class non-selection):
  - `(HookConfigTest|ClaudeSettingsHooksTest|ClaudeSettingsHooksSkipRecordTest|BootstrapHookFileTest|PromptHookTest|BootstrapPromptHookWiringTest)::` → 237T/699A OK (2 baseline deprecations only).
  - `DocFigureProseDriftTest::` → 92T/4209A green (roster arms re-derived).
  - `TreeWideGuardRosterTest|DuplicatedTestHelperDriftTest` (bare) → 31T/4079A green — the two-new-sibling-files DTH trap (e78d5dc14 shape) CLEARED.
  - `StderrEmitterCensusTest|ReadPathCensusTest|LangParityTest|CliLangCatalogueTest::` → 129T/27520A green — zero-movement claim VERIFIED, no missing row.
  - `GlobDialectDifferentialTest::` → 13T/364A with EXACTLY the accepted foreign 1F at `:336` quoting 310,245 vs derived 311,360 (560×556) — unchanged shape, lane added zero corpus drift (new-file `.*` literals do not move the derived number).

## 4. Lang — PASS
- 7 `hooks.prompt.*` keys in `lang/en.php`, each exactly once (uniq -c all 1), inside ONE sorted run at `:3898-3904` under the dated marker; params only `{hook}`/`{reason}`. `CliLangCatalogueTest::|LangParityTest::` anchored run green (placement law holds).

## 5. Seams adjudicated
- MINOR-1 (step-local): `src/Hooks/HookConfig.php:160` (`loadFromFile`) and `:201` (`parse`) `@return` shapes still the 8-key pre-http row. Pre-existing rot at base (url/type already absent) but THIS step widened the lie by adding prompt/model to the real shape while flipping the CSH + HookManager shapes in the same commit — one-line fix in a fixer or at weld. Builder reported it as `:146/:187`: NIT-2, cites drifted — HEAD truth `:160`/`:201`.
- NIT-3: `HookManager::withPromptBackend` mutates + returns `$this` (`HookManager.php:77-95`). The design block itself names it a SETTER installed pre-load; deviation is docblock-justified, behaviorally pinned (assertSame identity, WiringTest:167-171) and order-pinned (test3). Design-sanctioned; house with*-clone law deviation noted for the ledger, NOT a finding-grade break.

## 6. Scope + weld-readiness
- File set = design declared set + the two mandated guard-flip test files. All builder claims disk-verified: REPORT.md 7,544 B (stat'd).
- §7 MINT DUTY (my own live counts, do not trust prose): new test files `tests/Hooks/PromptHookTest.php` 21T/107A and `tests/Cli/BootstrapPromptHookWiringTest.php` 3T/11A → durations.tsv rows + suite-figure re-pin owed at weld/battery, per standing law.
- Residual red set observed in my gate windows matched the accepted-foreign list only (GlobDialect 1F shape byte-unchanged); no NEW red family or shape change introduced.

VERDICT: CLEAN — proceed to weld. Fix-list for a free micro-commit if the welder wants it: MINOR-1 @return rows; NIT-1 attribution sentence.
