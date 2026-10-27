### C-TOOL-7.c — LSP auto-detect project-trust gate
- item: C-TOOL-7 (sources: EDT-2-P1)
- kind: fix · MINOR · effort S
- approach: Root cause `candy-code/src/LSP/LspLauncher.php:107-147` (`AUTO_TABLE`) and `fromConfig()` :172. With user-tier `lspAutoDetect` on, any opened repo's `Cargo.toml` starts rust-analyzer (which runs `build.rs` and proc-macros), and `package.json`+`tsconfig.json` starts the workspace tsserver, before first paint, with no trust check. `Bootstrap::lspClient()` (`candy-code/src/Cli/Bootstrap.php:8447-8476`) states "starting a server is code execution". Fix: `fromConfig(…, bool $projectTrusted = false)`; the AUTO arm runs only when trusted, and explicit `lsp` entries are unaffected. An untrusted root yields a provenance row in `problems()` ("lsp: auto-detect skipped — this project is not trusted for hooks"). `lspClient()` passes `self::projectHooksAreTrusted($root)`, the grant that already means "this repo's code may run here". Add a risk sentence to the `settings.lspAutoDetect.help` lang string and regen SETTINGS.md.
- files.create: none
- files.modify: `candy-code/src/LSP/LspLauncher.php` (fromConfig signature + auto arm + class docblock), `candy-code/src/Cli/Bootstrap.php` (lspClient() :8456-8460), `candy-code/lang/en.php` (`settings.lspAutoDetect.help`), `candy-code/docs/SETTINGS.md` (via `php tools/gen-settings-doc.php --write`)
- tests: modify `candy-code/tests/LSP/LspLauncherAutoDetectTest.php` (`testAnUntrustedProjectStartsNoFingerprintServer`, `testExplicitEntriesIgnoreTrust`; existing auto tests pass `projectTrusted: true`)
- docs/guards: `SettingsSchemaDocDriftTest` (help text regen)
- serialized: [bootstrap:lspClient] [lang:settings.lspAutoDetect.] [docs:SETTINGS.md]
- deps: none
- phase: normal
- ruling: none (if the owner prefers `trustedProjectSettings` over hooks trust, it is a one-word swap)
- risks: users who rely on auto-detect in untrusted clones lose it silently; the problems() row makes it loud.

