# C-TUI-5.e — First-run wizard wiring (App slot, web notice) — CLOSED

## Disk proof
73bb33e2a C-TUI-5.e: web dashboard no-provider notice
996ba8c0d C-TUI-5.e: first-run wizard wiring - App slot, launch offer, renderer band, registry rows, guards
--- status ---
--- shas ---
73bb33e2a4185ffed2aaad73e0677d1da2a4606f
996ba8c0daa7dbe3e056ae839c5fe2a53b1d9ec9

## Gates (cwd=candy-code, phpunit -c phpunit.xml, plain pipe; vitest --no-file-parallelism)
- Final union (FirstRunWizardWiring+model/KeyBindingDrift/KeyBindingRegistry/CommandKeybindingDispatch/ReadmeRosterDrift/DocFigureProseDrift/MessagePickerWiring/DiffReviewWiring/LaunchTrustDialog): Tests 446, Assertions 8057, OK (PHPUnit Deprecations 2 = baseline shape, no failures/skips)
- FirstRunWizardWiringTest: 13T/31A OK
- KeyBindingDriftTest: 244T/2021A OK incl 6 wizard.* observations
- ReadmeRosterDriftTest: 10T/78A OK
- KeyBindingRegistryTest: fixed in-step (contexts roster + counts 168->174 live/all, 17->18 groups, docblock chain note) then green
- CommandKeybindingDispatchTest: key-help fixture 200->240 rows in-step, green
- web: npx vitest run --no-file-parallelism src-web/views/__tests__/dashboardNoProvider.spec.ts -> 4/4 pass (node_modules cp -a from main tree, package.json md5-identical 7254b621...; worktree shipped empty despite brief)
- KeyHelpTest: 5 reds + 1 risky are PRE-EXISTING at pristine base 671105299 (proven by base checkout re-run; identical name-set at tip; my rows add zero). Footer-overflow 156-vs-184 standing pin NOT re-pinned (owner duty; disclosed).

## NEW test files (welder duties)
- candy-code/tests/App/FirstRunWizardWiringTest.php -> needs scripts/parallel-tests-durations.tsv row
- candy-code-web/src-web/views/__tests__/dashboardNoProvider.spec.ts -> dist/protocol-trio untouched (no server change); dist rebuild at weld per law

## Deviations / disclosures
- Cancel (Esc) ALSO writes the dismissal stamp; design line names only Skip. Adopted because 5.d's markDismissed contract is 'closing without a key' and both no-key exits qualify; an unstamped Esc re-offers forever. README wording matches: 'any way you leave without storing a key stamps the wizard away for good'.
- ProviderKeyStore refusal closes the band and shows the refusal verbatim in the footer status (typed key lands nowhere; candy-code login door re-runnable).
- hasConfigured reads config providers[].apiKey only -> env-var-key users (no config block) are still offered the wizard once; honest + cheap, avoids duplicating Subcommands' private env roster.
- lang keys.wizard.login-later shortened to 44 chars to satisfy the help-box 49-col ellipsis law (label 'Leave empty and run `candy-code login` later').
- App openFirstRunWizard() seeds FirstRunWizard::open() with default provider list (live path); tests inject explicit pill sets.
- switchProvider(string): array is a thin public wrapper over handleModelCommand([provider]) placed beside it — region disjoint from sibling C-TUI-5.a (handleDoctorCommand).

## Files
candy-code/README.md
candy-code/lang/en.php
candy-code/src/App/App.php
candy-code/src/Chat.php
candy-code/src/Commands/KeyBindingRegistry.php
candy-code/src/Tui/Renderer.php
candy-code/tests/App/CommandKeybindingDispatchTest.php
candy-code/tests/App/FirstRunWizardWiringTest.php
candy-code/tests/Commands/KeyBindingDriftTest.php
candy-code/tests/Commands/KeyBindingRegistryTest.php
candy-code-web/src-web/views/DashboardView.vue
candy-code-web/src-web/views/__tests__/dashboardNoProvider.spec.ts
