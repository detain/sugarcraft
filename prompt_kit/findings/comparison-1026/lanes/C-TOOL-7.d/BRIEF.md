# BRIEF — C-TOOL-7.d: JSON-output escaping of control/format chars

- area: tool
- src: design-tool.md
- item: C-TOOL-7 (sources: CC-new#12 JSON-output escaping)
- kind: fix · NIT · effort S
- phase: normal
- ruling: none
- wave: None | late: False
- deps: none
- serialized (claims): [headless]

## Files to create
`candy-code/tests/Cli/EncodeDocumentEscapingTest.php`

## Files to modify
`candy-code/src/Cli/NonInteractive.php` (encodeDocument + docblock), `candy-code/src/Cli/Subcommands.php` (only if the two direct sites are contract documents)

## Declared regions
{"candy-code/src/Cli/NonInteractive.php": ["docblock", "encodeDocument"], "candy-code/src/Cli/Subcommands.php": ["contract", "documents", "direct", "sites"]}

## Tests
create `candy-code/tests/Cli/EncodeDocumentEscapingTest.php` (no raw DEL/C1/Cf bytes in output; `json_decode` round-trip identity; plain ASCII and CJK unchanged); modify `candy-code/tests/Cli/StreamJsonEmitterTest.php` (a bidi-override payload arrives escaped)

## Docs/guards
none (`ReadmeJsonErrorContractDriftTest` unaffected: same document shape)

## Approach (verbatim)

`NonInteractive::encodeDocument()` (`candy-code/src/Cli/NonInteractive.php:1341-1354`) is the single encoder for `-p --output-format json`, `stream-json` (`StreamJsonEmitter.php:175`) and the subcommand JSON documents. It encodes with `JSON_UNESCAPED_UNICODE`, so DEL, C1 (U+0080–U+009F) and Unicode Cf format characters (bidi overrides U+202A–U+202E, U+2066–U+2069, zero-width U+200B–U+200F, U+FEFF, U+00AD, U+061C…) reach a consumer's terminal raw. Fix: a post-pass that rewrites those code points as `\uXXXX`. That is safe on the whole encoded string because json_encode output only carries them inside strings, and decoding is byte-identical. The two direct `json_encode` sites in `Subcommands.php` (:1516, :2419) are checked and routed through the encoder if they emit contract documents.

## Risks
consumers that grep raw output for an emoji or CJK text are unaffected; only Cf/C1 change.
