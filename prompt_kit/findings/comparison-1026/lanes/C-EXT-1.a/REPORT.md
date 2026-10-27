# C-EXT-1.a — Claude Code hook entries: accept `statusMessage`/`async`, read CC's exit dialect

Verdict: **DONE**. Commit `5d195cd08332d955c64267aa24f5c3fa262e2b37` on `cc/C-EXT-1.a`
(worktree `/home/sites/cc-cmp-C-EXT-1.a`, base `ff10efc43`). Author AND committer
`Joe Huss <detain@interserver.net>` (verified `%ae` and `%ce` separately), zero
Co-Authored trailers, never pushed, no `caliber` run. 6 files, +933/-13.

## What landed

**`src/Hooks/ClaudeSettingsHooks.php`** — `ENTRY_KEYS` is now
`['type','command','timeout','statusMessage','async']`; the refusal message spells the
same five in the same order. `statusMessage` is mapped onto the native `description` slot
(whether it is *text* stays `HookConfig`'s verdict, not this parser's — the
"validation is not re-implemented" law holds). `async` is validated here as a boolean by
name, because a guessed value would decide whether a guard may block; that mirrors
`HookConfig`'s no-coercion law for `disabled`/`unattendedAsk`. After the reuse step, each
row is stamped `'dialect' => ScriptHook::DIALECT_CLAUDE` and `'advisory' => bool` — the two
keys a native `hooks.yaml` can never produce, since `HookConfig::ENTRY_KEYS` refuses them
(now pinned both directions).

**`src/Hooks/ScriptHook.php`** — `DIALECT_NATIVE`/`DIALECT_CLAUDE` consts, `dialect`/`advisory`
ctor params after `$clock` (every existing positional `new ScriptHook(...)` site stays valid),
read by `fromConfig()` with no guessing, carried by `withTimeoutSeconds()` (the `HookRegistry`
clamp builds a copy, so dropping them there would lose the dialect on the row that runs),
accessors `dialect()`/`isAdvisory()`. `claudeDialectVerdict()` is consulted **in front of**
the untouched `return match ($exitCode)` block: under the claude dialect only `0` and `2`
mean anything and every other code is a non-blocking complaint; an `advisory` row downgrades
every non-allow including `2`. A downgrade returns `HookResult::allow()` with an **empty**
message and **empty** `additionalContext` — the stderr goes to `RuntimeNoticeSink::record()`
for the human, never into the model's context. `recordDialectNotice()` is the small helper
C-EXT-6.a reuses. A timeout stays a deny: it is not an exit code, and killing a guard that
wedged is not the same verdict as a guard that complained.

**`lang/en.php`** — three keys under the assigned `hooks.notice.` prefix:
`hooks.notice.advisory`, `hooks.notice.claude_dialect` (`{hook}`/`{code}`/`{reason}`),
`hooks.notice.said_nothing`.

**`docs/HOOKS.md`** — only my two regions (page is shared with C-TOOL-1.c this wave): the
`### What is read from a Claude settings file` bullet list gained the five-key entry shape
and the dialect stamp, and `## The exit-code contract` gained
`### Claude Code's two codes`. Deliberately written with **no** `| \`N\` | **allow** |`
table row, no "clipped at N KiB" phrase, no `| N bytes |` cell and no
"those N are the only keys" sentence, so no pinned DocFigure shape was widened — the new
`NOTICE_REASON_BYTES` is a src-side cap with no docs byte figure.

## Tests

New **`candy-code/tests/Hooks/ScriptHookClaudeDialectTest.php`** (13 tests / 88 assertions)
— real `sh` script hooks through the real spawn path: claude `1`/`5`/`7`/`127` proceed with
exactly one notice naming the hook, the code and the stderr; `2` still blocks; an advisory
row downgrades `2`; `0` still reads its JSON envelope; a timeout still denies with no notice;
the native negative pair (every non-zero denies, zero notices recorded); exit `3` still asks
natively while claude `4` proceeds; `fromConfig` defaults and refuses to guess either flag;
the timeout clamp carries the dialect; and the docs page spells the dialect the code runs.

Moved in-step (`§1.8`) in `tests/Hooks/ClaudeSettingsHooksTest.php`: the nested==flat
equality pin is restated as equality **after stripping the two stamp keys**, with the stamp
itself pinned as the whole of the difference (class docblock amended honestly — the stamp
names the file, not an opinion about the hook); the row-shape pin gained the two keys and a
truer name; the refused-key pin moved off `async:true` (now accepted) onto `"nope":1`; plus
seven new tests (statusMessage→description, async→advisory, non-bool `async` refuses,
non-text `statusMessage` refuses **through the native validator**, a native file cannot ask
for the stamp, docs↔const key-list agreement, refusal↔const order agreement).

Results (plain pipe, `cwd=candy-code`, targeted only — never the full suite):

- `--filter 'DocFigureProseDriftTest'` → **OK, 91 tests / 3889 assertions** (2 pre-existing deprecations)
- `--filter 'ClaudeSettingsHooksTest'` → **OK, 37 tests / 79 assertions** (was 30/—; +7)
- `--filter 'ScriptHookClaudeDialectTest'` → **OK, 13 tests / 88 assertions**
- hooks family `ClaudeSettingsHooksTest|ScriptHook|HookRegistry|HookManager|HookDispatcher|HookConfigTest|HookResultTest|AuditHookRefusalNotice|HookGateE2E|HookInputEncoding|RuntimePostToolUseBlock|ChatPostToolUseBlock|PreCompactHook|TurnHookAsync` → **OK, 519 tests / 1664 assertions**
- guard family `DocFigureProseDrift|LangParity|ReadmeRosterDrift|StderrEmitterCensus|DuplicatedTestHelperDrift|GlobDialectDifferential|TreeWideGuardRoster|SymbolCitationDrift|DuplicatedDocBlockLine|BootstrapLaunchFormatConstants|ReadmeSuiteFigureDrift|EnvRosterDrift|TrustKeyDocumentationDrift|ConfigWriteProducerDocumentationDrift|KeyBindingDrift|SuiteSkipRoster` → **590 tests / 85493 assertions, Failures: 2** — both are the `ReadmeSuiteFigureDriftTest` staleness pair (live 25557 vs pinned 25537 = my +20 tests). That is the gate's re-pin, not a defect; `suite-figure.json`/`durations.tsv` were not touched.
- `--filter 'HookEnvironmentDocumentationDriftTest|GlobFigureDriftTest|PermissionsIntroDriftTest|ChatTest|RuntimeTest'` (the other files naming `HOOKS.md`) → **OK, 475 tests / 4079 assertions**

**Regression proof (§1.5)**: with `src/Hooks/ScriptHook.php`,
`src/Hooks/ClaudeSettingsHooks.php` and `lang/en.php` reverted to base (cp-backup first,
md5-verified after restore), `--filter 'ScriptHookClaudeDialectTest|ClaudeSettingsHooksTest'`
ran **Tests: 50, Errors: 15, Failures: 3** — 18 red before the fix, all green after.

## For the gate

- **NEW test file needing a `scripts/parallel-tests-durations.tsv` row:**
  `candy-code/tests/Hooks/ScriptHookClaudeDialectTest.php`.
- **Suite-figure re-pin owed:** live enumeration moved 25537 → **25557** (+13 new file,
  +7 in `ClaudeSettingsHooksTest`). Assertions from the green run: dialect file 88 A,
  parser file 79 A.
- No `proc_open`/spawn change, so §1.10 is untouched; no new stderr emitter, so
  `StderrEmitterCensusTest` stayed green unmodified.

## Deviations & seams

1. The stamp is applied **after** `HookConfig::parse()` (as the design block specifies),
   which is what forces the equality pin's restatement. It is pinned as an exact
   two-key difference, so the "same validator" claim stays testable rather than dropped.
2. `async` is refused by *this* parser instead of being delegated, because there is no
   native slot to delegate to — it is the third member of the no-coercion family.
3. A downgrade allows with an empty message and empty context on purpose; the reason is
   clipped at `NOTICE_REASON_BYTES = 400` inside the notice only.
4. **Serve-side visibility**: the notice lands on `RuntimeNoticeSink`, so on `serve` it is
   shown only once C-CTX-2's hosted notice drain lands (declared in the step's Risk).
5. **Seam (reported, not edited)**: `src/Hooks/HookConfig.php:29`'s class docblock still
   says "Those six are the ONLY keys an entry may carry" while `count(ENTRY_KEYS)` is 7 — a
   stale internal comment. `DocFigureProseDriftTest`'s count-word pin reads
   `docs/HOOKS.md` prose (which says seven), not this docblock, so it is unpinned. File is
   outside this step's declared set.
6. `DocFigureProseDriftTest`'s HOOKS.md entry-key pin was checked and **not moved**: it
   derives from `HookConfig::ENTRY_KEYS` plus the native `## The file format` YAML fence and
   does not cover the Claude-settings bullets, per the brief's "move it if it covers them".
   The CC key-list claim is therefore pinned inside `ClaudeSettingsHooksTest`.
7. Unknown `type` values and every other foreign key still refuse the launch — C-EXT-1.b
   turns those into skip-and-record.
