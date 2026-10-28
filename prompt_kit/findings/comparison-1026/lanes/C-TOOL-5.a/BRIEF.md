### C-TOOL-5.a — Opt-in read-before-write + UTF-8 BOM preservation
- item: C-TOOL-5 (sources: GAP-EDT-02, GAP-EDT-10)
- kind: gap · effort M
- approach:
  - (1) New user-tier bool `requireReadBeforeWrite` (default false), read through `ToolLimits` like the other tool bounds. When it is on, these refuse an EXISTING file that has no `ReadLedger::has()` entry (`candy-code/src/Tools/ReadLedger.php:185`), with "Read it first": `Edit`, `Write` (overwrite), and `ApplyPatch` (update/delete/move source). New files are unaffected. Descriptions mention the rule only when it is on.
  - (2) BOM preservation: `IndentShift::inheritBom()` beside `inheritEndings()` (`candy-code/src/Tools/Edit/IndentShift.php`). It strips a UTF-8 BOM before matching (`EditMatcher`) and re-prepends it on write in Edit, in Write's overwrite of a BOM file (unless the new content brings its own), and in `ApplyPatch`'s `normalizeEndings` callers.
  Both legs are kept in one lane because they touch the same three tool files.
- files.create: `candy-code/tests/Tools/BuiltIn/ReadBeforeWriteTest.php`, `candy-code/tests/Tools/Edit/BomPreservationTest.php`
- files.modify: `candy-code/src/Tools/BuiltIn/Edit.php`, `candy-code/src/Tools/BuiltIn/Write.php`, `candy-code/src/Tools/BuiltIn/ApplyPatch.php`, `candy-code/src/Tools/Edit/IndentShift.php`, `candy-code/src/Tools/Edit/EditMatcher.php`, `candy-code/src/Tools/ToolLimits.php` (key), `candy-code/src/Config/Settings/Definitions/ToolSettings.php` (definition), `candy-code/lang/en.php`, `candy-code/docs/SETTINGS.md` + `candy-code/README.md` (via `php tools/gen-settings-doc.php --write`)
- tests: create the two above (refusal on unread existing files only; new files pass; the setting off is byte-identical to today; BOM kept across Edit, Write and ApplyPatch, and a BOM-less file never gains one); modify `candy-code/tests/Tools/BuiltIn/EditTest.php`, `candy-code/tests/Tools/BuiltIn/WriteTest.php`, `candy-code/tests/Tools/BuiltIn/ApplyPatchTest.php` (only where a description pin moves)
- docs/guards: `SettingsSchemaDocDriftTest`, `TrustKeyDocumentationDriftTest` (README user-tier enumeration)
- serialized: [lang:settings.requireReadBeforeWrite.] [docs:SETTINGS.md] [docs:README.md]
- deps: none
- phase: normal
- ruling: none
- risks: forked tool children must see the parent's ledger entries; they do (Read exports it via `CarriesSessionState`); pin it with a forked-path test.

