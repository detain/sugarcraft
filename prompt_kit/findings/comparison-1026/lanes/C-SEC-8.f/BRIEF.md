### C-SEC-8.f — `candy-code login --keychain`
- item: C-SEC-8 (sources: GAP-MOD-25)
- kind: gap · effort S
- approach:
  - `Subcommands::loginStoreKey()` (:937) writes the key into the user config.
  - With `--keychain`, it instead stores the key through `Keychain::store()` (`secret-tool store` / `security add-generic-password -U`, secret on stdin, never argv) and writes `"apiKey": "${keychain:<provider>}"` into the provider block.
- files.create: `candy-code/tests/Cli/LoginKeychainTest.php`
- files.modify:
  - `candy-code/src/Cli/Subcommands.php` (`loginStoreKey`, completion roster)
  - `candy-code/src/Cli/ParsedArgs.php` (`SUBCOMMAND_FLAGS`)
  - `candy-code/src/Cli/ArgvParser.php` (if the flag is parsed there)
  - `candy-code/src/Support/Keychain.php` (`store`)
  - `candy-code/README.md` (login section)
  - `candy-code/lang/en.php` (`cli.login.keychain.*`)
- tests: the new test covers the secret passed on stdin, never in argv; the config getting the reference, not the key; and a store failure leaving the config untouched. Modify `candy-code/tests/Cli/LoginSubcommandTest.php`.
- docs/guards: `ReadmeRosterDriftTest`; the completion roster pin
- serialized: [headless] [docs:README.md] [lang:cli.login.keychain.]
- deps: C-SEC-8.e
- phase: normal
- ruling: none
- risks: PRV-4-P1 (`login` replaces the whole provider block) is a separate C-PRV fix. If that lands first, this step must patch only `apiKey`.

---

## Notes for the orchestrator

- **Wave 1 (the owner's ask).** C-SEC-1.a and C-SEC-2.a are small and disjoint (headless set vs web-only).
  - C-SEC-3.a and C-SEC-4.a can join them. They are also disjoint, except that 3.a and 8.a both write `docs/MCP.md`, so keep 8.a out of the wave that runs 3.a.
  - C-SEC-2.b is the natural protocol lane for wave 2.
- **Protocol lanes from this area: 2.b, 4.b, 5.b, 6.c, 6.d (five).** Ordering constraints:
  - 4.b before 5.b, since 5.b widens what 4.b makes routable;
  - 6.a before 6.c;
  - 6.c and 6.d both edit `RulesPanel.vue`;
  - 2.b and 6.c both edit `PermissionCard.vue`.
- **C-SEC-4.a touches `src/Protocol/Methods/CommandMethods.php`,** but only by adding one argument inside `files()`, with no schema, event or gen-chain change. Either count it as that wave's [protocol] lane, or co-schedule it with a protocol lane that does not touch `CommandMethods.php`.
- **`Bootstrap::permissionGate` is claimed by four steps: 6.b, 7.a, 7.d and 7.e.** They need four different waves; 7.e can fold into 7.d if the ruling keeps the default off. 7.b (`filterToolSet`/trust predicates/`bundles`) and 5.a (new methods beside `trustProjectMcp`) are method-disjoint region claims.
- **Shared hot files across this area:**

  | File | Steps |
  |---|---|
  | `candy-code/docs/PERMISSIONS.md` | 5.b, 5.d, 6.a, 6.b, 6.c, 7.a, 7.b, 7.c, 7.d, 7.e (one owner per wave) |
  | `candy-code/README.md` | 3.b, 6.b, 7.a, 7.b, 7.d, 8.b, 8.f |
  | `candy-code/src/Config/LayeredSettings.php` (generated roster blocks) + `docs/SETTINGS.md` + `PermissionSettings.php` | 3.b, 7.d, 7.e, 8.b (regenerate once per wave, in the owning lane or at weld) |
  | `candy-code/src/Host/SessionHost.php` | 4.a, 8.d (plus other areas) |
  | `candy-code/src/Host/TurnController.php` | 3.a, 3.b |
  | `candy-code/src/Host/Commands/PermissionsCommand.php` | 6.a (`saved`/`forget` arms), 6.b (`report` tier column) |
  | `candy-code/src/Protocol/TrustAsks.php`, `ContextPanel.vue`, `contextSources.ts`, `StatusBar.vue` | 4.b, 5.b |
  | `candy-code/src/Backend/EngineBackend.php` | 2.b, 7.c |
  | `candy-code/src/Cli/LaunchContext.php` | 5.d (P-SESSION-TABS likely touches it too) |
  | `candy-code/src/App/App.php`, `src/Tui/KeyboardHandler.php` | 5.d (and likely P-SESSION-TABS, P-IMAGE-MENU and C-TUI-*) |
  | `candy-code/src/Host/ContextSources.php` | 5.b (and P-CTX-BREAKDOWN, C-MCP-4) |
  | `candy-code/src/Tools/McpToolBridge.php` | 8.a (and any C-MCP step touching MCP results) |

- **`candy-code-web/dist/` is a collision point.**
  - It is committed, and `.github/workflows/web.yml` enforces dist parity. Every web step (2.a, 2.b, 4.b, 5.b, 6.c, 6.d) rebuilds it, and the hashed asset names collide between any two web lanes in one wave.
  - Recommendation: web lanes commit source only, and the weld runs one `npm ci && npm run build` per wave. Otherwise treat dist as one-owner-per-wave.
- **Rulings** (for §5 of the plan):
  - 3.b: the per-origin shell opt-in, and the foreign-row truth;
  - 7.a: reverse rv-sec-1 for `-p` only;
  - 7.e: the `autoReview` default.
- **Cross-area dependencies:**
  - C-EXT-4 and C-MCP-2 depend on C-SEC-1.a and C-SEC-3.a.
  - C-SEC-6.e depends on C-SES-4.
  - C-SEC-8.f should land after C-PRV's PRV-4-P1 fix, or patch only `apiKey`.
  - 7.d optionally reuses 2.b's `AskDiffPreview`.
- **Could not verify (left to the lanes):**
  - Whether user-tier foreign commands really run `!cmd` today. By reading the code they do: `ForeignCommandDiscovery::claudeDirs` stamps `user`, and the gate reads only the tier. `docs/COMMANDS.md:28` says "never". 3.a's tests should measure it before 3.b rules on it.
  - Whether `sessions.db` and the EventLog persist tool output from the folded `ToolResultMessage` or from `ToolFinished` (8.b).
  - Whether App-level frame compositing exists outside `src/Tui/Renderer.php` (5.d, which may need [renderer]).
  - The exact docs page holding the prompt-cache law (7.c names `docs/PROMPT_ENGINEERING.md`; `docs/CONTEXT.md` and `ARCHITECTURE.md` also mention the cache).
  - Whether `LayeredSettings` already emits a "user-tier only" notice for a project `permissionRules` key (6.b).
- **Weld-only:** no lane edits `candy-code/tests/Config/Support/suite-figure.json` or `scripts/parallel-tests-durations.tsv`. Every new `*Test.php` above must be added to the durations TSV at weld:
  - 1.a: none;
  - 2.b: 3;
  - 3.a: 2;
  - 4.a: 1;
  - 4.b: 1;
  - 5.a: 2;
  - 5.b: 1;
  - 5.c: 1;
  - 5.d: 1;
  - 6.a: 2;
  - 6.b: 2;
  - 6.c: 1;
  - 6.d: 2;
  - 6.e: 1;
  - 7.a: 1;
  - 7.b: 1;
  - 7.c: 1;
  - 7.d: 1;
  - 8.a: 1;
  - 8.b: 1;
  - 8.d: 2;
  - 8.e: 1;
  - 8.f: 1.
