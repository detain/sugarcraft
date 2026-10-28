# REVIEW-1 — C-PRV-5.d (Gemini schema, catalog order, codex wording)

**VERDICT: CLEAN** — 0 CRITICAL / 0 MAJOR / 0 step-local MINOR / 2 NIT.

Commit `5da428301` (single) on `cc/C-PRV-5.d` off base `b985fbe51`; diff `--stat` = exactly
5 files, +451/−21, matching the claim: `candy-code/README.md` (1 line),
`candy-code/docs/SETTINGS.md` (+32), `src/Providers/Concerns/GeminiGenerateContentWire.php`,
`tests/Providers/GeminiProviderTest.php` (in-step pin flip, expected per step law),
new `tests/Providers/GeminiResponseSchemaTest.php` (6T). No weld-only files
(suite-figure/durations/dist) touched. Author `%ae` and `%ce` grepped separately:
both `detain@interserver.net`; zero Co-Authored/Signed-off trailers.

## 1. Behavior (substance) — PASS

- Wire block at `GeminiGenerateContentWire.php:348-380` (in `geminiBody()`): modern arm
  sends `responseJsonSchema`+mime; pre-2.5 gemini arm sends sanitised `responseSchema`+mime;
  `$ref`-survives → neither key; `($request->tools ?? []) === []` door → neither key.
- Proto ground truth (vendored, read-only verified):
  `vendor/google/cloud-ai-platform/src/V1/GenerationConfig.php:105,141` —
  `response_schema = 16`, `response_json_schema = 28`, oneof-presenced; :111 "If set,
  `response_schema` must be omitted, but `response_mime_type` is [required]"; :101 mime
  required beside schema. Subset `Schema.php:175-187` — `additional_properties` is a
  single Value, dollar-less `#/defs/` refs. Every doc claim checks out.
- Family gate: `geminiFamilyPredatesJsonSchemaField()` (wire :388) =
  `ThinkingCapability::family($model) === FAMILY_GEMINI && !supportsThinking($model)` —
  public API of the single 2.5-boundary home (`src/Providers/ThinkingCapability.php:35-96`),
  no regex duplication; same polarity shape as the thinking arm already in the method
  (wire :282-287). Unknown ids (gemma, aliases, glm) → modern; claude-first ordering cannot
  mis-route (claude never reaches this block).
- Sanitiser edges traced by hand: property *named* `$schema` stays (walk by value);
  `items` list-vs-single via `array_is_list` with `$items !== []` guard; `prefixItems`/
  `anyOf/oneOf/allOf` walked; empty schema objects no-op; whole-tree `$ref` scan after
  stripping is conservative-direction (worst case: plain output). Boolean-subschema values
  (`properties: {a: true}`) skip the walk safely (is_array door).
- Production reachability: `--json-schema` (ArgvParser.php:652-668) → NonInteractive.php:287
  → `withJsonSchema` (:429) → Runtime ctor :817 → `jsonSchemaFor()` gate :846-873 →
  `CompleteRequest(jsonSchema:/tools:)` at Runtime.php:1469-1497 → `geminiBody()` on BOTH
  providers, unary AND stream: `GeminiProvider.php:308,393`; `VertexProvider.php:473,728`
  (factory-registered types, ProviderFactory.php:844). Real user path, not test-only.
- Tools door matches Runtime's own behavior (tools + schema on the same request;
  `supportsFunctionCalling()` false on this wire), per design bullet (c); AUT-4
  terminal-answer re-request disclosed as the other area's seam.

## 2. Non-vacuity — PASS (reviewer-run)

Base-wire swap probe (only mutation made; restored byte-exact, `git diff HEAD` on the
path = 0 lines, tree porcelain clean): `git show b985fbe51:...Wire.php` into the worktree,
ran `--filter GeminiResponseSchemaTest` → **FAILURES! Tests: 6, Failures: 6** — builder's
red-before-fix claim reproduced exactly.
Mutation-reason: strip-one-key-only → the whole-document `assertSame` pins die; send
mime-without-schema on degrade → the `assertArrayNotHasKey('responseMimeType')` pin dies;
gate-inverted → both family tests die; drop tools-door → the tools test's first three
asserts die; treat `items` always-as-list → the walk test's `row.items` pin dies.

## 3. Docs truth — PASS

- README.md:1626 Codex row: no "PKCE fork"; matches `Subcommands.php:889-890`
  `loginPassthrough(['codex','login'],…)` + no-pipes stdio docblock :896-901. All
  remaining PKCE mentions tree-wide are MCP OAuth (McpLoginFork:45, McpAuthCommand:21,
  Subcommands:2522) — correctly untouched.
- SETTINGS.md new "model catalog" section sits outside generated `<!-- settings:* -->`
  regions; every claim verified vs code: merge order + dedup + leading-tier-wins
  (`ModelCatalog.php:135-149`), MAX_ROWS=64 (:36), null-memoised once-per-process fetch
  (:76-81), update-side-only prime/render-memo read (docblock :11-18), sglang/custom
  no-third-tier (:40-52), paletteLabels "each once" (ModelChoice.php:73-83).
  Order stated AS SHIPPED; live-first explicitly "possible alternative … not taken" —
  matches plan §6.2 (merge order = default as written, no owner call). Does NOT claim
  live-first.
- Gates re-run by reviewer (cwd worktree candy-code, `env -u LINES -u COLUMNS`, plain pipe):
  provider family `GeminiResponseSchemaTest|GeminiProviderTest|VertexProviderTest` →
  **OK. Tests: 184, Assertions: 554** (exact); doc-guard battery
  `ReadmeRosterDrift|EnvRosterDrift|TrustKeyDocumentationDrift|ConfigWriteProducer…|DocFigure…|DuplicatedTestHelper…|GlobDialect…` →
  **OK. Tests: 192, Assertions: 42851** (exact); secondary `SettingsSchemaDocDrift|…` →
  **OK. Tests: 126, Assertions: 7480** (exact). Each run carries the 2 known-foreign
  AccessorDeprecationTest PHPUnit deprecations — not attributed. ReadmeSuiteFigureDrift
  staleness pair: base-inherited, weld-owned, correctly not fixed; builder disclosed
  provenance (+842 base drift, +6 from this lane).

## 4. Laws/conventions — PASS

declare(strict_types=1), final test class, one type per file, why-comments throughout,
no new user-facing strings (no Lang needed), no proc_open/fork sites (http closure seam),
E646 untouched (no timeouts). GeminiProviderTest method rename lives in the same commit
(in-step flip, behavior-driven: the old pin asserted the buggy `responseSchema` on
gemini-2.5-flash). New test file's durations.tsv row + suite-figure re-pin correctly
REPORTED as weld duty.

## Nits (non-blocking, no action owed)

- NIT-1 (wire :412-460): sanitiser walks only the enumerated sub-schema positions; a
  stripped key nested under an un-walked position (`not`, `patternProperties`,
  `dependentSchemas`) survives — but such a document also carries a parent key the subset
  `Schema` message has no spelling for at all, so it was never sendable and stays outside
  design bullet (b)'s three-key scope. Fallback remains plain output on those requests'
  400-class only if Google ever widens; note for the AUT-4 lane, not this one.
- NIT-2 (REPORT §Deviation 3): README row cited at design-L1595, actually L1626 — drift
  correctly disclosed; same row.

## Commands run

git branch/log/diff --stat; git show base file; full diff read; grep reachability trace
(Runtime/NonInteractive/ArgvParser/factory/providers); proto greps; ThinkingCapability +
ModelCatalog + ModelChoice + Subcommands reads; 3 phpunit filter runs; base-wire swap →
6F; restore + `git diff HEAD -- path | wc -l` = 0.
