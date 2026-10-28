# C-PRV-5.d — Gemini schema, documented catalog order, codex login wording

**Verdict: DONE.** Commit `5da428301a49f1d94510f75be0944538f46ae21f` on `cc/C-PRV-5.d`
(worktree `/home/sites/cc-cmp-C-PRV-5.d`, base master `b985fbe51`). Never pushed.

## Implementation

### PRV-3-P1 — `candy-code/src/Providers/Concerns/GeminiGenerateContentWire.php` (hot claim)
The GenerateContent wire previously forwarded the operator's raw JSON Schema as
`responseSchema` (the typed OpenAPI-subset field) with `responseMimeType: application/json`,
which 400s on standard-schema keys the subset type does not know (`$schema`, `$defs`,
`additionalProperties` — the subset spells its own ref/defs dollar-less). Replaced the block
per the design's three bullets:

- **(a) modern path:** on ids the 2.5-or-newer family covers, send the document whole as
  `responseJsonSchema` + `responseMimeType` (the field documented as "alternative to
  response_schema that accepts JSON Schema"; `responseSchema` is then omitted — the two are
  mutually exclusive, the mime type is required beside either).
- **(b) fallback path:** ids of the family that predates that field take the OpenAPI-subset
  sanitiser — a recursive strip of `$schema`, `additionalProperties`, `$defs` over the
  schema-bearing positions (`properties` walked by value so a *property named* `$schema`
  stays as data; `items` single-or-tuple; `anyOf`/`oneOf`/`allOf`/`prefixItems` element
  lists). If the sanitised document still names a `$ref`, the subset cannot express it, so
  the wire degrades to plain output (neither key) rather than a 400.
- **(c) tools door:** a step that carries function declarations (`$request->tools`) omits
  BOTH the mime type and the schema for this wire. Runtime sends the schema and the tools on
  the same request (Runtime.php:1469), and this wire deliberately never sends a `tools` key;
  the constrained-answer re-request is AUT-4's area (the plan's risk row) — this step only
  stops the 400.

The family question is asked of `ThinkingCapability::family()` / `supportsThinking()` — the
same publicly-documented 2.5 generation boundary — instead of copying that class's private
`/gemini-(?:1|2\.0)/` regex into a second home. Ids outside the gemini family (aliases,
gemma, served-through ids) keep the modern field. The block comment was rewritten to state
all of the above plus the epistemic status (no GCP credentials exist here; the claims rest on
the vendored `GenerationConfig` proto and Google's published reference pages, not a live
call). Three new private static helpers carry the logic, each with a why-docblock.

### PRV-1-P2 — `candy-code/docs/SETTINGS.md` (docs claim)
The shipped merge order was never described in SETTINGS.md at all (design block said
"documented truthfully" — it wasn't documented there yet). Added a prose section
"## The model catalog the palette lists" directly before "## Every key", outside every
generated `<!-- settings:* -->` region: the three tiers in the shipped precedence order
(what this session speaks, via `ModelChoice::paletteLabels()`, then the endpoint's own
answer, then the LiteLLM family names), sglang/custom contributing no third tier,
deduplication in that order with the leading tier winning and the `ModelCatalog::MAX_ROWS`
(64) cap, the fetch living update-side only (render path reads memos; a null memo so an
unreachable endpoint is asked once per process — "a shorter list, never a stalled frame"),
and that switching to live-first is an owner call §6.2 did not take. No code change — the
shipped order stays (plan §6: defaults as written).

### PRV-4-P4 — `candy-code/README.md` (docs claim)
The provider table's Codex row (README.md:**1626** — the design block cited L1595, a
line-number drift; the wording target is the same row) no longer calls `codex login` a "PKCE
fork". It is a stdio passthrough: `Subcommands.php` runs `loginPassthrough(['codex','login'],
…)` which hands the terminal to the vendor CLI and stores nothing. Every other PKCE mention
tree-wide is the MCP OAuth flow (correct) and was left untouched.

## Tests

- **New:** `candy-code/tests/Providers/GeminiResponseSchemaTest.php` — 6 tests: the standard
  document rides `responseJsonSchema` unsanitised on a modern family id; an id the family
  table does not know is not treated as ancient; a pre-2.5 family id takes the subset arm
  with the foreign keys stripped (exact expected document); the sanitiser walks sub-schema
  positions and never a property spelling; a subset document that still names a `$ref`
  degrades to plain output; a step carrying function declarations sends neither field, and
  the same request without tools is constrained again (`tools: []` still constrained).
- **In-step pin flip:** `tests/Providers/GeminiProviderTest.php`
  `testStructuredOutputRidesTheNative…Pair…` → `testStructuredOutputRidesTheJsonSchema…`:
  on `gemini-2.5-flash` it now pins `responseJsonSchema` + mime present and `responseSchema`
  ABSENT; the string-spelling case still decodes; unparseable text and absent schema still
  send neither key. Its :95 message reworded to name both fields.
- **Red-before-fix proof:** with the base wire restored from git, all 6 new tests FAIL
  (each names the missing `responseJsonSchema` key or the emitted-when-it-shouldnt pair);
  with the fix, green. Wire copy md5-verified identical on restore.

## Gates (cwd worktree candy-code, plain pipe, env -u LINES -u COLUMNS)

- `--filter 'GeminiResponseSchemaTest|GeminiProviderTest|VertexProviderTest'` →
  **OK. Tests: 184, Assertions: 554, Deprecations: 2** (both pre-existing foreign
  `tests/Config/AccessorDeprecationTest.php:168/:202` data-provider-visibility
  deprecations, reported at collection).
- Doc-drift guards `--filter 'ReadmeRosterDrift|EnvRosterDrift|TrustKeyDocumentationDrift|ConfigWriteProducerDocumentationDrift|DocFigureProseDrift|DuplicatedTestHelperDrift|GlobDialectDifferential'`
  → **OK. Tests: 192, Assertions: 42,851** (same 2 foreign deprecations).
- Secondary doc families `--filter 'SettingsSchemaDocDrift|ReadmeSettingsTierClaim|ClaimFamiliesDocumentationDrift|GlobFigureDrift|DocumentParagraphs|ChatConfigChangeDoorsDocumentationDrift|ThemePersistenceFraming'`
  → **OK. Tests: 126, Assertions: 7,480.**
- `ReadmeSuiteFigureDrift` → **expected-red staleness pair, NOT fixed (weld-owned
  figures).** Disclosed provenance: the pair is ALREADY red at base — with my new test file
  moved aside the live enumeration reads 26,379 vs the pinned 25,537 (+842 inherited drift
  from other waves' test files never re-pinned). My lane adds exactly +6 (→ 26,385) and one
  new test file.

## Guards moved in the same commit

GeminiProviderTest pin flip (in-file, in-step). No README/SETTINGS roster guard covers the
edited surfaces (verified above), so no other guard needed moving.

## Deviations / judgement calls

1. Family gate implemented via the public `ThinkingCapability` API rather than a new regex —
   keeps the single documented home of the 2.5 boundary (root-cause law; behaviour identical
   on every id in that class's family table).
2. The `$ref`-survives-sanitising → degrade-to-plain rule is an added third honest rule the
   design's bullet (b) implies but does not spell: the subset declares `ref` (dollar-less),
   so a standard `$ref` would otherwise ride as an unknown key — the exact 400 class this
   step fixes.
3. README row lives at L1626, not the design block's L1595 (line drift; same row).
4. SETTINGS.md "documented truthfully" → the file had no catalog prose at all; added it
   outside generated regions (design's region list named SETTINGS model-catalog prose).

## Seams for weld/closeout (outside this step's declared files — NOT edited)

- `src/Providers/GeminiProvider.php` (~:266-280) docblock sentence "the shared arm emits the
  native `responseSchema` + `responseMimeType` pair" and `src/Providers/VertexProvider.php:431`
  "CAN build a `responseSchema`" are now partial truths of the subset arm only; a one-line
  wording touch is warranted when someone next owns those files (no test pins the literals —
  grepped).
- New test file needs a `scripts/parallel-tests-durations.tsv` row + the suite-figure re-pin
  at the gate (the +842 base drift should ride the same re-pin).
- AUT-4's terminal-answer constraint: with the tools door, a tools-bearing step now gets
  plain (unconstrained) output — by design this step only stops the 400.
