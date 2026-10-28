### C-AGT-7.b — Headless honesty: `session_id`, `--json-schema`
- item: C-AGT-7 (sources: AUT-2-P1, AUT-4-P1, AUT-4-P2)
- kind: fix · MINOR · effort M
- approach:
  - AUT-2-P1: the envelope carries `$opened['id']` (`src/Cli/NonInteractive.php:597-612`) and is printed (:636-641) before the save (:653-664). For `json`/`stream-json`, persist first inside the same try and render after, with `session_id` only on a successful save. Text output keeps answer-first.
  - AUT-4-P1: `--json-schema` without `-p`/`run` parses clean and is ignored (`ArgvParser.php:640-688`; bin never reads it). Make it a usage error (exit 2). The lane checks whether `--output-format` merits the same.
  - AUT-4-P2: the schema rides only the first step (`Runtime::jsonSchemaFor()` :845-872). `NonInteractive` sets `json_schema_applied: false` when the settled answer followed a tool result (computed from the run's history, so no Runtime edit), plus one stderr notice. The README discloses the first-step boundary.
- files.create: none
- files.modify: `candy-code/src/Cli/NonInteractive.php` (render/persist order, envelope key), `candy-code/src/Cli/ArgvParser.php` (usage error), `candy-code/lang/en.php` (`cli.argv.json_schema.requires_prompt`, `cli.noninteractive.json_schema_unapplied`), `candy-code/README.md` (`--json-schema` line :1731, the "Six keys are **conditional**" paragraph :1947 → seven)
- tests: modify `candy-code/tests/Cli/NonInteractiveSessionPersistenceTest.php` (a forced save failure → no `session_id`), `candy-code/tests/Cli/LaunchFlagsTest.php` (`--json-schema` without a prompt → exit 2), `candy-code/tests/Cli/JsonSchemaEnvelopeTest.php` (a tool-using turn → `json_schema_applied:false` + notice; a toolless turn has no key)
- docs/guards: README envelope paragraph. `candy-code/tests/DenialPrefixRosterTest.php` anchors on "are **conditional**" (count-word-free), so it stays green. `candy-code/tests/Config/ReadmeJsonErrorContractDriftTest.php` if it pins the key set.
- serialized: [headless] [lang: `cli.*`] [docs:README.md]
- deps: none
- phase: normal
- ruling: none. The opt-in v2 "re-request the final step under the schema" is left out: it is an extra billed call.
- risks: [headless] collides with C-SES-1's ledger write at the same persist block (SES-1-P1). Same owner or different waves.

