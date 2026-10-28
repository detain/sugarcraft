# BRIEF — C-PRV-5.d: Gemini schema, documented catalog order, codex login wording

- area: prv-ctx
- src: design-prv-ctx.md
- item: C-PRV-5 (sources: PRV-3-P1, PRV-1-P2, PRV-4-P4)
- kind: fix · MINOR/NIT · effort S
- phase: normal
- ruling: none
- wave: None | late: False
- deps: none
- serialized (claims): [docs:README.md] [docs:SETTINGS.md] [hot:candy-code/src/Providers/Concerns/GeminiGenerateContentWire.php]

## Files to create
`candy-code/tests/Providers/GeminiResponseSchemaTest.php`

## Files to modify
`candy-code/src/Providers/Concerns/GeminiGenerateContentWire.php` (schema block), `candy-code/README.md` (L1595), `candy-code/docs/SETTINGS.md` (model-catalog prose)

## Declared regions
{"candy-code/src/Providers/Concerns/GeminiGenerateContentWire.php": ["block", "schema"], "candy-code/README.md": ["L1595"], "candy-code/docs/SETTINGS.md": ["model-catalog", "prose"]}

## Tests
create GeminiResponseSchemaTest: `responseJsonSchema` body pin, subset sanitiser, no schema on a tools-bearing step.

## Docs/guards
README provider table (`ReadmeRosterDriftTest` unaffected; wording only)

## Approach (verbatim)

- **PRV-3-P1:** `GeminiGenerateContentWire.php:280-293` sends the operator's raw JSON Schema as `responseSchema` with `responseMimeType: application/json`, on a first step that also declares tools (`Runtime.php:845-872`). Fix:
- send standard JSON Schema as `responseJsonSchema`;
- fall back to an OpenAPI-subset sanitiser (strip `$schema`, `additionalProperties`, `$defs`) where needed;
- on a step that carries function declarations, omit the mime type and schema for this wire.
- **PRV-1-P2:** the shipped merge order (`current-three > live > LiteLLM`, `ModelCatalog.php:18-26`) is documented truthfully in `docs/SETTINGS.md`. Switching to live-first is an optional owner call (Notes).
- **PRV-4-P4:** `README.md:1595` stops calling `codex login` a "PKCE fork". It is a stdio passthrough to `codex login` (`Subcommands.php:876, 889-925`).

## Risks
AUT-4's terminal-answer constraint (another area) decides whether the constrained answer comes later. This step only stops the 400.
