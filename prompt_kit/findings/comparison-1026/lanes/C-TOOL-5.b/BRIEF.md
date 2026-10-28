# BRIEF C-TOOL-5.b — Default formatter table (`formatAuto`)

area: tool  item: C-TOOL-5 (sources: GAP-EDT-04)  kind: gap · effort M  wave:   serialized: [bootstrap:hooks] [lang:settings.formatAuto.] [docs:HOOKS.md] [docs:SETTINGS.md] [docs:README.md]
deps: C-TOOL-1.c (PostEditFormatHook + HOOKS.md section), C-TOOL-7.c (trust-gate pattern)

## approach
`FormatRunner` (`candy-code/src/Format/FormatRunner.php`) runs only the explicit `formatCommands` map, and `Bootstrap::hooks()` (:5826, :5843-5848) registers the hook only when a command exists. Add `FormatAutoTable`: extension → candidate formatters in preference order (gofmt -w, rustfmt, ruff format / black, a project-local `node_modules/.bin/prettier --write`, shfmt -w, terraform fmt, …). It is gated on binary presence through an injectable `?callable $binaryPresent` seam (the `LspLauncher::fromConfig` shape). Explicit `formatCommands` entries win per extension. New user-tier bool `formatAuto` (default false). Formatters read project config (prettier plugins execute JS), so the AUTO arm also requires project hooks trust, the same gate as C-TOOL-7.c. The hook registers when commands exist OR formatAuto is on.

## files.create
`candy-code/src/Format/FormatAutoTable.php`, `candy-code/tests/Format/FormatAutoTableTest.php`

## files.modify
`candy-code/src/Format/FormatRunner.php` (auto fallback per extension), `candy-code/src/Cli/Bootstrap.php` (hooks() format block), `candy-code/src/Config/Settings/Definitions/HooksMcpSettings.php` (`formatAuto` beside `formatCommands` :52), `candy-code/lang/en.php`, `candy-code/docs/HOOKS.md` ("Post-edit format": the auto table + trust), `candy-code/docs/SETTINGS.md` + `candy-code/README.md` (regen)

## tests
create the file above (preference order, absent binaries skipped, explicit entries win, untrusted roots get no auto arm); modify `candy-code/tests/Format/FormatRunnerTest.php`, `candy-code/tests/Hooks/BuiltIn/PostEditFormatHookTest.php` (registration when only formatAuto is set)

## docs/guards
`SettingsSchemaDocDriftTest`, `TrustKeyDocumentationDriftTest`

## risks
a formatter's style can fight the project's own config; only project-local prettier is used, never a global one, for that reason.

## ruling
none
