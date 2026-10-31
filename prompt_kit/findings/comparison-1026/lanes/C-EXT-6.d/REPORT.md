# C-EXT-6.d — `type: prompt` hooks (LATE) — lane report

Branch `cc/C-EXT-6.d`, base master `56893049b`. Author law verified per commit
(`%ae`/`%ce` grep counts 1/1 detain, zero Co-Authored trailers).

## Slice 1 LANDED — commit `86fb200fb` (cat-file'd same shell: commit)

`C-EXT-6.d: type: prompt hooks — PromptHook core, HookConfig prompt/model keys,
manager backend seam` — 8 files, +1167/−31.

- NEW `src/Hooks/PromptHook.php` — final class implements HookInterface only
  (deliberately NOT BoundedHookInterface: E646 — the entry `timeout` is
  validated but never applied to the LLM call; the call carries only the
  provider connect timeout, as `ExecReviewer`'s does; `HookRegistry` charges
  a non-bounded hook no deadline). One tool-less `complete()` on the title
  backend: fixed `SYSTEM_PROMPT` + the hook's `prompt` with `$ARGUMENTS`
  replaced by the fenced payload (`PromptFence::escape`, marker breakage to
  the hyphen-spelled `UNTRUSTED-HOOK-INPUT-BEGIN/END`, head clip at
  `MAX_INPUT_CHARS=8000`). Reply contract `{"ok":bool,"reason":string}` —
  deliberately NOT the `HookEnvelope` shape (the model answers the hook
  author's question, it does not speak the script envelope). `ok:false`
  denies with `clip(Sanitize::untrusted(reason), MAX_DENY_REASON_BYTES)`;
  empty-after-sweep reason gets its own Lang sentence. `ok:true` with a
  reason shows it via `ScriptHook::showSystemMessage`. Unparseable reply /
  missing backend / failed call: claude dialect (or advisory) allows with a
  `RuntimeNoticeSink` notice, native denies. `model` honoured through the
  `EngineBackend::withModel`/`servedModel` gate — silently stays on the title
  backend when the provider cannot serve the name.
- `src/Hooks/HookConfig.php` — `ENTRY_KEYS` eleven→thirteen (`prompt`,`model`),
  `TYPE_PROMPT` const, command-required arm exempts prompt rows, unnamed
  prompt rows named after the flattened head-80 of their ask (ask itself
  rides byte-raw), `perTypeKeys()` widened to the 5-tuple with the per-type
  stranger algebra extended to the third kind (all existing pinned refusal
  fragments kept byte-identical); unknown-kind sentence now names all three.
- `src/Hooks/HookManager.php` — `private ?Backend $promptBackend`,
  `withPromptBackend()` (mutating, install-before-`loadEntries` law —
  docblock justifies the non-`with*`-clone deviation: launch-owned chain
  object), dispatch is a three-arm match; prompt arm receives the backend.
- `lang/en.php` — `hooks.prompt.*` one sorted run of 7 keys under a dated
  marker (`deny_unanswered`, `notice_unanswered`, `reason_call_failed`,
  `reason_empty_verdict`, `reason_no_backend`, `reason_no_prompt`,
  `reason_unparseable`), `{hook}`/`{reason}` params only.
- In-step test moves: `HookConfigTest` +9 provider legs + prompt-row load +
  name-flattening pins; `ClaudeSettingsHooksTest` full-row pin + @return
  shapes now carry `prompt`/`model` (they describe `HookConfig::parse`
  output, which moved — same-commit law; the CSH mapping itself is slice 2).

Gates for slice 1 (all `cwd=candy-code -c phpunit.xml`, plain pipe):
- Hooks family union (`HookConfig|HookManager|HookRegistry|HttpHook|ScriptHook|
  PromptHook|HookEnvelope|…|McpCallHookParity)Test`): **776 TESTS / 2353 ASSERTIONS, 0F**
  (after the two in-step fixes: row-shape pin, raw-prompt assertion).
- `PromptHookTest` alone: **21 TESTS / 107 ASSERTIONS** (NEW file — §7 durations
  candidate; live count listed, row NOT minted).
- `StderrEmitterCensusTest|ReadPathCensusTest|DuplicatedTestHelperDriftTest`:
  green — zero rows move (PromptHook opens no files, writes no stderr).
- `GlobDialectDifferentialTest` 1F persists at 560×556 pairs WITH my new test
  file hidden → contribution zero; the accepted §7 foreign rider, numbers not
  asserted, not cured.
- 2 "PHPUnit Deprecations" on every filter run trace to
  `tests/Config/AccessorDeprecationTest` private data providers — baseline,
  shown identically by untouched siblings (`HookEnvelopeTest` 20/70 +2).

Budget verdict (design asked to confirm): the prompt call rides
`Bootstrap::titleBackend()` — a fresh tool-less `EngineBackend` built per
launch with `hooksDisabled: true`, never the chat backend; nothing settles
its usage into the session spend, exactly the `ExecReviewer` precedent →
**NOT counted against /budget**. Disclosed here and in the HOOKS.md section
(slice 2).

## Slice 2 — in progress

CSH type mapping + ENTRY_KEYS + docblock truth-flip, `Bootstrap::hooks()`
`withPromptBackend(self::titleBackend())` install, HOOKS.md (thirteen-keys
prose + third YAML example entry + `### Prompt hooks` section + CC-dialect
eight→ten keys), guard flips (DocFigure `eleven→thirteen` word-map +
3-entry example, CSH keys test nine/ten, BootstrapHookFileTest skip example
prompt→agent), `tests/Cli/BootstrapPromptHookWiringTest.php` (NEW).

## Slice 2 — LANDED (commit e43c6a684, amended once for the GlobDialect brace fix)

Files: src/Hooks/ClaudeSettingsHooks.php (type:prompt RUNS; ENTRY_KEYS 10;
copy superset incl prompt/model; agent+mcp_tool stay skip-and-record; docblocks
truth-flipped), src/Cli/Bootstrap.php (hooks() installs
`$hooks->withPromptBackend(self::titleBackend())` before the files load; comment
reworded so it carries no early `loadEntries(` token that tripped the
DocFigure positional board-notice arm), docs/HOOKS.md (thirteen-key roster,
third example entry type: prompt, ten-key CC-dialect sentence, new
'### Prompt hooks' section incl. the E646 timeout-never-applied law and the
/budget-not-counted verdict), guards moved in-step: DocFigureProseDriftTest
(twelve/thirteen words, 3-entry example, 'type: prompt' leg),
ClaudeSettingsHooksTest (nine/ten words + 2 new prompt-mapping legs),
BootstrapHookFileTest + ClaudeSettingsHooksSkipRecordTest (skip-example
prompt→agent), NEW tests/Cli/BootstrapPromptHookWiringTest.php 3T/11A.

Gates (all cwd=candy-code -c phpunit.xml, plain pipe):
- filter 'Hooks': 1369 tests, 7185 assertions, 0F/0E (baseline deprecations 2).
- DocFigureProseDriftTest: 92T/4209A green.
- 'ClaudeSettingsHooks|BootstrapHookFile|BootstrapPromptHookWiring': 101T/311A green.
- TreeWideGuardRosterTest(bare)|DuplicatedTestHelperDrift: 31T/4079A green.
- StderrEmitterCensus|ReadPathCensus: 114T/23866A green — zero rows moved.
- ToolDoc/SettingsSchema/CommandDocs/EnvRoster doc guards: 67T/41345A green (unmoved).
- LangParity|CliLangCatalogue|DuplicatedTestHelperDrift: 29T/3858A green.
- GlobDialectDifferentialTest: 13T/364A, the SAME single accepted foreign rider
  (:336); my literals are corpus-neutral (JSON built via json_encode; the one
  brace literal caught pre-amend and fixed).

MUTATION PROOF: deleting the Bootstrap setter line reddens exactly
testALaunchFeedsTheTitleBackendIntoThePromptHookItLoads ("null is an instance
of EngineBackend"); restore md5-identical (e9a082e8…).

New *Test.php files for the §7 gate (LIVE, do not re-derive from blobs):
- tests/Hooks/PromptHookTest.php: 21 tests / 107 assertions
- tests/Cli/BootstrapPromptHookWiringTest.php: 3 tests / 11 assertions

Refused/not-touched: suite-figure.json, durations.tsv (gate duty), protocol
trio/SERVER.md/generated.ts (no protocol surface added — doc guards prove it),
ToolSettings/LayeredSettings/sandbox lang keys (C-TOOL-4.c surface),
LayeredSettingsTest screenReader staleness (never fired — no settings keys
added here). Seam left unfixed (pre-existing, reported): HookConfig.php:146/:187
stale pre-http @return shapes — C-EXT-6.c era, not widened.
