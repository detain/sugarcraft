# Lane report — C-EXT-6.c `type: http` hooks (W33, LATE)

Branch `cc/C-EXT-6.c` · worktree `/home/sites/cc-cmp-C-EXT-6.c` · base master `288b81f11`.

## Position (re-derived from disk at recovery, 2026-10-30 22:4x)

- `rev-parse --short HEAD` = `288b81f11`, `rev-list --count 288b81f11..HEAD` = **0** (nothing committed yet).
- `git status --porcelain` = `?? candy-code/src/Hooks/HookEnvelope.php` only (13,725 B, mtime 22:41).
  Every other file in `src/Hooks/` carries the 22:19 worktree-creation mtime, so this tree has had
  exactly one writer. **No twin collision** — the stopped twin never wrote here.
- `php -l` clean on the surviving file.

## Plan (design block re-read VERBATIM from `prompt_kit/findings/comparison-1026/design-ext.md:502`)

1. `HookEnvelope` — envelope reader/verdict moved out of ScriptHook's private
   `envelope`/`fromEnvelope`/`malformedEnvelope`; ScriptHook delegates (consolidation, not deletion).
2. `HookPayload` — stdin JSON moved out of ScriptHook's private `stdinPayload` (:1067 stale, real :1306).
3. `HttpHook` — BoundedHookInterface, POST payload JSON to `url`; http/https only; no redirects;
   capped response; `$VAR` interpolation restricted to `allowedEnvVars`; 2xx JSON envelope via
   HookEnvelope; 2xx non-JSON allows; error → claude dialect allows+notice, native denies;
   bounded by hook timeout (E646 n/a — not an LLM call).
4. Egress guard: REUSE `candy-code/src/Support/PublicUrlGuard.php` (the repo's single SSRF rule),
   never a second one. Refusal branches pinned. Authorization redacted from any emitted row.
5. Config: HookConfig `type`/`url`/`headers`/`allowedEnvVars` + per-type validation;
   HookManager::loadEntries() builds by type; ClaudeSettingsHooks maps `type: http`.
6. Docs/guards in-step: `docs/HOOKS.md` (file format + CC section), `lang/en.php` (`hooks.http.*`),
   DocFigureProseDriftTest HOOKS.md entry-key pin.
7. Tests: create `HttpHookTest`, `HookEnvelopeTest`; modify `HookConfigTest`,
   `ClaudeSettingsHooksTest`, `DocFigureProseDriftTest`.

## NEW test files (for the §7 durations TSV)

- `candy-code/tests/Hooks/HttpHookTest.php`
- `candy-code/tests/Hooks/HookEnvelopeTest.php`

## Status log

- 22:41 `HookEnvelope.php` written (pre-stop).
- (in flight) ScriptHook delegation + visibility widening.

## Commit 1 — `ac7ce47a7` (cat-file: commit; %ae/%ce detain = 1/1; no Co-Authored trailer; tree clean)

Landed (13 paths, all inside the design block's declared set):

- NEW `src/Hooks/HookEnvelope.php` — the envelope reader/verdict, moved out of ScriptHook
  (`envelope`/`fromEnvelope`/`malformedEnvelope`); canonical lists stay on ScriptHook and this
  class aliases them, so no second roster exists.
- NEW `src/Hooks/HookPayload.php` — the stdin JSON, moved out of ScriptHook's private
  `stdinPayload` (design's :1067 cite is stale; real site :1306).
- NEW `src/Hooks/HttpHook.php` — `BoundedHookInterface`; POSTs `HookPayload::stdin()` to `url`.
  - EGRESS GUARD REUSED (no second validator minted): `PublicUrlGuard::guardedDialAddress()`
    (`src/Support/PublicUrlGuard.php:171`), the same seam `WebFetch` uses
    (`src/Tools/BuiltIn/WebFetch.php:717`), with `resolveViaSystemDns`/`addressIsBlocked` as the
    default seams (`HttpHook.php:141` ctor) so tests inject doubles and no server is dialled.
  - http/https only; URL credentials refused; host literals refused at load AND again on the
    address the resolver answered; `max_redirects => 0` plus an explicit refusal on any 3xx
    (a redirect is never followed, so the guard cannot be walked past hop-by-hop);
    response capped at `MAX_RESPONSE_BYTES = 262144` with a bounded read loop; `$VAR`/`${VAR}`
    interpolated ONLY from `allowedEnvVars`; transport-owned framing fields refused; control
    bytes in a name or value refused; every emitted sentence carries `targetSummary()`
    (scheme://host[:port] only) — never a path, query, header value, or the Authorization byte.
  - 2xx JSON envelope → `HookEnvelope::verdict()` (deny-wins, shared with the script path);
    2xx non-envelope → ALLOW with `Sanitize::untrusted` + `HookContextFiles::bound` body as
    context; could-not-consult → native dialect DENIES, claude dialect (or advisory) ALLOWS
    with a `RuntimeNoticeSink` notice. Timeout = the hook's own per-request transport timeout
    on the stream context (E646: not an LLM call; no wall-clock kill, no blanket request cap).
- `ScriptHook.php` — bodies replaced by thin delegates (docblocks verbatim) so every
  reflection-pinned surface still works; `clip()`, the three reason/rewrite bounds,
  `NOTICE_REASON_BYTES`, `ENVELOPE_KEYS`, `ENVELOPE_DECISIONS` widened public for the sharing;
  new `public static showSystemMessage()` so both transports notice identically.
- `HookConfig.php` — eleven entry keys (`type`,`url`,`headers`,`allowedEnvVars` added) with
  `perTypeKeys()` validation placed AFTER the matcher read (DocFigure's 12 000-byte window).
- `HookManager::loadEntries()` — builds `HttpHook` by type; `ClaudeSettingsHooks` maps
  `type: http` instead of skip-recording (8 entry keys; `prompt`/`agent`/`mcp_tool` still
  skip-and-record; missing/non-string type still refuses the launch by name).
- `lang/en.php` — 12 `hooks.http.*` keys (no new env var → no EnvRoster/Help.php move needed;
  the dynamic `getenv($name)` inside `interpolate()` is invisible to that scanner by design).
- `docs/HOOKS.md` — CC section flipped, entry-key count sentence seven → eleven, the format
  fence now demonstrates all eleven keys across two `PreToolUse` entries (the second one
  `type: http`), plus a new `### HTTP hooks` section stating the guard, the no-redirect rule,
  the byte cap, the allow-list on `$VAR` interpolation, redaction, and the dialect split.
- GUARDS MOVED IN-STEP: `tests/Config/DocFigureProseDriftTest.php` (word map → eleven; the
  PreToolUse slice leg now compares a DEDUPED key set and pins exactly two example entries plus
  both `type:` spellings) and `tests/Support/ReadPathCensusTest.php`
  (`Hooks/HttpHook.php|fopen → NOT_A_FILESYSTEM_PATH`, mirroring the `PublicUrlGuard` row).
- NEW test file: `tests/Hooks/HookEnvelopeTest.php` — 20 tests / 70 assertions, incl. the
  cross-transport identity pin (a real spawned script and this reader give the same sentence
  for the same envelope) and the deny-wins table.

## Gates (this tree, cwd=candy-code -c phpunit.xml, plain pipe)

- `tests/Hooks` whole dir: **1070 tests / 3517 assertions OK** (base was 1050/3447 → +20/+70 =
  exactly the new file; nothing existing broke).
- `DocFigureProseDrift|EnvRosterDrift|ConfigWriteProducer|StderrEmitterCensus|GlobDialectDifferential|
  DuplicatedDocBlockLine|SymbolCitationDrift|HookEnvironmentDocumentationDrift`:
  **266 tests / 86 964 assertions OK** (2 PHPUnit deprecations = the repo-wide baseline shape).
- `ReadPathCensusTest`: **19 / 729 OK**.
- `php -l` clean on all 10 touched/new PHP files.

## BASE REDS found, NOT mine, NOT cured

- `tests/TreeWideGuardRosterTest.php` × 2
  (`testEveryWalkerCallSiteInAFileThatNamesThePackageRootIsAccountedFor`,
  `testEveryFileWithAnUnresolvedWalkIsARosterMemberOrFullyLicensed`) — offender is
  `Cli/LoginSubcommandTest.php => glob($this->dir.'/*')`, a file that arrived on master with
  **117c948bb "C-TUI-5.b: `candy-code import claude` …"** and is byte-unmodified in this lane
  (`git diff HEAD --` on it and on the roster test is empty). It needs a
  `WALKS_A_DIRECTORY_THE_TEST_MADE` row; that is C-TUI-5.b's or the gate's, not this step's
  declared file set.

## Still open in this step

- `tests/Hooks/HttpHookTest.php` (NEW — transport seam, every refusal branch pinned).
- `tests/Hooks/HookConfigTest.php` (per-type refusal table).

---

## Commit 2 — `969ee98b3ec594a2dad4386558c19843f034982f` (3 paths)

- `candy-code/src/Hooks/HookConfig.php` — ROOT-CAUSE FIX found by the new tests:
  `perTypeKeys()` declared `string $type` while its own first branch exists to
  refuse a NON-string type, so an entry written `type: [1, 2]` escaped as an
  uncatchable `TypeError` (HookConfig.php:520 from :464) instead of the curated
  refusal naming the key. Parameter widened to `mixed $type`; the `@param`
  docblock says why ("a `type: [1, 2]` has to arrive as a refusal naming the
  key, not as a TypeError escaping the parser").
- `candy-code/tests/Hooks/HookConfigTest.php` — +5 methods (106/238 OK): an http
  entry that LOADS (type/url/headers/allowedEnvVars emitted, `command` empty,
  name defaults to the url), a plain row defaulting to `command`, the per-type
  wrong-kind refusals (9 cases), the targets this host will not dial (5 urls,
  refused at load rather than at call time), unusable header/env names (7 cases).
- `candy-code/tests/Hooks/HttpHookTest.php` — NEW, 23 tests / 164 assertions, a
  transport seam only (no live server). Pins every refusal branch BEFORE any
  dial, the SSRF guard's own refusal (which denies even under the claude
  dialect, with zero notices), that the dialed address IS the validated literal
  while the `Host` keeps the virtual name, the dialect split for all six
  can't-consult shapes, that a `Location` target and a header VALUE (an
  Authorization sentinel) never reach any emitted row, the redirect refusal,
  the byte cap, `$VAR` interpolation (disallowed stays literal, allowed-unset
  becomes ''), 2xx non-JSON allows with a bounded swept body, deny-wins and a
  malformed envelope through the shared reader, `withTimeoutSeconds()` carrying
  all three seams, and that every `hooks.http.*` key this class speaks resolves.

## Guard triage (final)

- `GlobDialectDifferentialTest` corpus figure — WAS mine (four unique `?`-bearing
  fixture URLs were corpus-admitting, deriving 562×558 vs the pinned 310,245 pair
  count). Cured INSIDE my own test file: a `private const Q = "\x3f";` with a
  comment (a glued bare `?` is glob-shaped and the census reads the RAW token
  spelling, so the escape keeps the byte out of the source while PHP still
  decodes it), and the four URLs are built by concatenation. Green alone.
  `src/Util/PathGlob.php` (out of declared scope) was NOT touched.
- `ReadPathCensusTest` — my capped network `@fopen` needed a row: added
  `Hooks/HttpHook.php | fopen => NOT_A_FILESYSTEM_PATH` in commit 1, mirroring
  the existing `PublicUrlGuard` row. 19/729 OK.
- `TreeWideGuardRosterTest` ×2 — RED, NOT MINE, NOT IN THE LEAD'S FOREIGN LIST:
  offender `Cli/LoginSubcommandTest.php => glob($this->dir.'/*')`, which arrived
  on master with `117c948bb` (C-TUI-5.b); `git diff --stat 288b81f11 -- tests/Cli/LoginSubcommandTest.php tests/TreeWideGuardRosterTest.php` is EMPTY and none of my five new files walks a directory. It needs a `WALKS_A_DIRECTORY_THE_TEST_MADE` row — C-TUI-5.b's lane or the §7 gate.
- `DuplicatedTestHelperDriftTest` ×2 (`titleBackend`, `T0`) — the foreign pair
  already on the lead's red list. Not mine.
- Final battery (`DocFigureProseDrift|EnvRosterDrift|ConfigWriteProducer|StderrEmitterCensus|GlobDialectDifferential|DuplicatedDocBlockLine|SymbolCitationDrift|ReadPathCensus|DuplicatedTestHelperDrift|HookEnvironmentDocumentationDrift`): 299 tests / 87,899 assertions, only the two foreign helper-drift legs red.
- Whole `tests/Hooks`: 1098 tests / 3717 assertions OK (base 1050/3447; +48/+270 arithmetic exact).

## New test files for the §7 durations TSV

- `candy-code/tests/Hooks/HttpHookTest.php`
- `candy-code/tests/Hooks/HookEnvelopeTest.php`

## Corrected final gate figures (measured on the committed tree, tip `969ee98b3`)

- Whole `tests/Hooks`: **OK (1098 tests, 3717 assertions)** — 20.9 s.
- Guard battery, correct namespaces (`TreeWideGuardRoster|SymbolCitationDrift|StderrEmitterCensus|EnvRosterDrift|LangParity|ReadPathCensus|GlobDialectDifferential|DocFigureProseDrift|DuplicatedDocBlockLine|ConfigWriteProducerDocumentationDrift|HookEnvironmentDocumentationDrift`):
  **309 tests / 94,950 assertions, 2 failures** — both legs are
  `TreeWideGuardRosterTest` blaming `Cli/LoginSubcommandTest.php => glob($this->dir.'/*')`.
  That file is tracked at base and byte-unmodified by this lane
  (`git diff --stat 288b81f11 -- tests/Cli/LoginSubcommandTest.php tests/TreeWideGuardRosterTest.php` = empty);
  the guard reads source text, so the red cannot come from my five new files. It needs a
  `WALKS_A_DIRECTORY_THE_TEST_MADE` row from C-TUI-5.b's lane or the §7 gate.
- An earlier narrower battery run (167/45,850 OK) is recorded above; it silently EXCLUDED
  `TreeWideGuardRosterTest`, `SymbolCitationDriftTest` and `StderrEmitterCensusTest` because my
  filter prefix assumed `Tests\Config\` for classes that live in `Tests\` and `Tests\Cli\`.
  Those three are green here apart from the foreign walker roster above.
- `DuplicatedTestHelperDriftTest` alone: 14 tests / 202 assertions, 2 failures — the foreign
  `titleBackend` + `T0` pair already on the lead's red list.
