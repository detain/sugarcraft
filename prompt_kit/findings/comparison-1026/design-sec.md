# design-sec — Security, permissions & trust (C-SEC-1 … C-SEC-8)

Design pass for `plan_comparison.md`. Every path below was checked against the live tree at HEAD `5e9195687`: each MODIFY path exists and each CREATE path does not. Line numbers are as of that HEAD. C-SEC-9 was not picked and is not designed here. None of these items is LATE, so every step is `phase: normal`.

## Summary

| step id | title | effort | serialized tags | deps |
|---|---|---|---|---|
| C-SEC-1.a | Bundle install never follows symlinks | S | [headless] [lang:cli.bundles.install.] | none |
| C-SEC-2.a | Web Edit card shows every edit and the raw arguments | S | [hot:PermissionCard.vue] [hot:candy-code-web/dist] | none |
| C-SEC-2.b | Server-computed diff on `permission.asked` | M | [protocol] [hot:EngineBackend.php] [hot:PermissionCard.vue] [hot:candy-code-web/dist] | C-SEC-2.a |
| C-SEC-3.a | One provenance-aware `!cmd` gate (refuse MCP and bundle shell forms) | M | [hot:TurnController.php] [lang:host.command_shell.] [docs:COMMANDS.md] [docs:MCP.md] [docs:PLUGINS.md] | none |
| C-SEC-3.b | Per-origin shell opt-in and the foreign-row decision | S | [hot:TurnController.php] [hot:LayeredSettings.php] [hot:PermissionSettings.php] [lang:settings.] [docs:SETTINGS.md] [docs:README.md] [docs:COMMANDS.md] | C-SEC-3.a |
| C-SEC-4.a | Served sessions honour `trustedProjectCommands` | S | [hot:SessionHost.php] [hot:Protocol/Methods/CommandMethods.php] (body only) | none |
| C-SEC-4.b | `context.trust` routable, failed writes surfaced, prompt discoverable | M | [protocol] [hot:TrustAsks.php] [hot:ContextPanel.vue] [hot:contextSources.ts] [hot:StatusBar.vue] [hot:candy-code-web/dist] [lang:server.trust.] | none |
| C-SEC-5.a | Trust-target census and one shared grant door | M | [bootstrap:new methods beside trustProjectMcp] [lang:server.trust.ask.] | none |
| C-SEC-5.b | Web trust targets and MCP pin re-approval | M | [protocol] [hot:TrustAsks.php] [hot:ContextSources.php] [hot:ContextPanel.vue] [hot:contextSources.ts] [hot:StatusBar.vue] [hot:candy-code-web/dist] [lang:server.trust.] [docs:PERMISSIONS.md] | C-SEC-4.b, C-SEC-5.a |
| C-SEC-5.c | TUI trust dialog component | S | [lang:tui.trust.] | C-SEC-5.a |
| C-SEC-5.d | TUI launch trust dialog wiring | M | [hot:App.php] [hot:KeyboardHandler.php] [hot:LaunchContext.php] [renderer] (conditional) [docs:PERMISSIONS.md] [lang:tui.trust.] | C-SEC-5.a, C-SEC-5.c |
| C-SEC-6.a | Saved-rule service and `/permissions saved` / `forget` | M | [lang:host.permissions.] [lang:cmd.permissions.hint] [docs:PERMISSIONS.md] [docs:COMMANDS.md] | none |
| C-SEC-6.b | Project-tier deny/ask-only rules | M | [bootstrap:permissionGate, permissionRules] [lang:appLaunch.notice.] [docs:PERMISSIONS.md] [docs:README.md] | none |
| C-SEC-6.c | Web saved-rule management and `remember: "user"` | M | [protocol] [hot:PermissionCard.vue] [hot:RulesPanel.vue] [hot:candy-code-web/dist] [docs:PERMISSIONS.md] | C-SEC-6.a |
| C-SEC-6.d | Web permission audit view | M | [protocol] [hot:RulesPanel.vue] [hot:candy-code-web/dist] | none |
| C-SEC-6.e | OTel safety events per verdict | S | [hot:OtlpEncoder.php] [docs:ENVIRONMENT.md] | C-SES-4 |
| C-SEC-7.a | `-p` starts in `default` mode (asks, denies unattended) | M | [bootstrap:permissionGate + DEFAULT_PERMISSION_MODE consts] [headless] [lang:appLaunch.notice.] [lang:cli.headless.] [docs:README.md] [docs:PERMISSIONS.md] | none |
| C-SEC-7.b | `--restricted` and `--safe-mode` | M | [headless] [bootstrap:filterToolSet, trustedRootsForThisProcess, bundles] [lang:cli.] [docs:README.md] [docs:PERMISSIONS.md] | none |
| C-SEC-7.c | Plan mode drops the tools the gate always refuses | M | [hot:EngineBackend.php] [docs:PERMISSIONS.md] [docs:PROMPT_ENGINEERING.md] | none |
| C-SEC-7.d | Model-judged review for writes, with a chosen reviewer model | M | [bootstrap:permissionGate] [hot:PermissionGate.php] [hot:LayeredSettings.php] [hot:PermissionSettings.php] [lang:settings.autoReview] [docs:SETTINGS.md] [docs:README.md] [docs:PERMISSIONS.md] | none |
| C-SEC-7.e | `autoReview` default | S | [bootstrap:permissionGate] [hot:PermissionSettings.php] [docs:SETTINGS.md] [docs:PERMISSIONS.md] | C-SEC-7.d |
| C-SEC-8.a | Fence MCP tool output as untrusted | S | [hot:McpToolBridge.php] [docs:MCP.md] | none |
| C-SEC-8.b | Opt-in secret redaction of tool output | M | [runtime] [hot:LayeredSettings.php] [hot:PermissionSettings.php] [lang:settings.redactToolOutput.] [docs:SETTINGS.md] [docs:README.md] | none |
| C-SEC-8.c | `Sanitize::stripInvisibleFormatting()` in sugar-core | S | none | none |
| C-SEC-8.d | Strip invisible Unicode at submit (TUI and serve) | S | [chat:submit] [hot:SessionHost.php] [lang:chat.notice.invisible_stripped] | C-SEC-8.c |
| C-SEC-8.e | `${keychain:<name>}` provider keys | M | [docs:ENVIRONMENT.md] [lang:cli.keychain.] | none |
| C-SEC-8.f | `candy-code login --keychain` | S | [headless] [docs:README.md] [lang:cli.login.keychain.] | C-SEC-8.e |

**Totals:** 27 steps · S 11 · M 16 · L 0 · 5 protocol lanes (2.b, 4.b, 5.b, 6.c, 6.d) · 3 rulings (3.b, 7.a, 7.e).

---

### C-SEC-1.a — Bundle install never follows symlinks
- item: C-SEC-1 (sources: PLG-1-P1, PLG-1-P3)
- kind: fix · CRITICAL · effort S
- approach:
  - Root cause: `Subcommands::removeTree()` (`candy-code/src/Cli/Subcommands.php:1920-1935`) recurses on `\is_dir($child)` at :1931, and `is_dir()` follows symlinks. A clone holding `x -> ../../..` therefore empties `$HOME` on every failure exit: :1670 (clone failed), :1683 (no manifest), :1694 (bad name), :1702 (already installed) and :1712-1717 (copy fallback).
  - Fix 1: test `is_link()` before `is_dir()` and `unlink()` the link itself, never descending. Apply the same guard to the top-level `$path`.
  - Fix 2: add `-c core.symlinks=false` to the clone argv at :1749, so git checks links out as plain files.
  - Fix 3 (PLG-1-P3): `copyBundleTree()` (:1877-1913) follows links when it recurses and when it copies (~:1899-1911). It now refuses the install and names the first link it meets, which also ends cyclic-link loops.
- files.create: none
- files.modify: `candy-code/src/Cli/Subcommands.php` (`removeTree`, `copyBundleTree`, `bundlesCloneDefault` argv), `candy-code/lang/en.php` (new key `cli.bundles.install.link_refused`)
- tests:
  - modify `candy-code/tests/Bundles/BundleInstallTest.php`:
    - Through the `$bundlesInstallSpawn` seam, build a scratch holding `link -> <outside sentinel dir>`, crossed with each of: no manifest, an invalid name, an existing name. Assert the sentinel survives and the scratch is gone.
    - A path source holding an outside link is refused, and the message names the link.
    - A cyclic link terminates.
    - Update the argv pin at :189-197 (`testTheDefaultCloneArgvPinsTheExtTransportOffBeforeTheCloneToken`): its substring `'git', '-c', 'protocol.ext.allow=never', 'clone'` breaks once a second `-c` is inserted. Re-pin both `-c` pairs ahead of `clone`.
- docs/guards: none. The refusal names the link, and no doc page describes the cleanup. Child lifetime: no new spawn.
- serialized: [headless] [lang:cli.bundles.install.]
- deps: none. C-EXT-4 (marketplace) and C-MCP-2 should name this step as a prerequisite.
- phase: normal
- ruling: none
- risks: a legitimate bundle that ships an in-tree relative symlink now installs as a plain file (clone) or is refused (path). The loaders already refuse links that escape the tree, so nothing that loads today is lost.

### C-SEC-2.a — Web Edit card shows every edit and the raw arguments
- item: C-SEC-2 (sources: WEB-5-P1, WEB-5-P4)
- kind: fix · CRITICAL · effort S
- approach:
  - Root cause 1: `diffPairsFor()` (`candy-code-web/src-web/lib/lineDiff.ts:38-41`) returns only the top-level `old_string`/`new_string` pair whenever it is present. `Edit::editList()` (`candy-code/src/Tools/BuiltIn/Edit.php:441-471`) always runs the top-level pair as edit 1 and `edits[]` as edits 2..N, so the `edits`-only branch (:42-52) never matches a real call.
  - Root cause 2: `PermissionCard.vue:113-120` renders the raw-arguments `<details>` as `v-else`, so it disappears whenever a preview exists.
  - Fix in `lineDiff.ts`:
    - Build the pair list as `[top-level pair, ...edits]`, in the same order as `editList`.
    - Return `null` (raw-dump fallback) on any malformed entry, including an empty `old_string` in `edits` (Edit refuses those).
    - Read `replace_all` as truthy, top-level and per entry, as `editList` does, and add "(replace all occurrences)" to that pair's label.
    - Mark the `<summary>` as snippet-relative (WEB-5-P4) without changing the `@@` grammar that `DiffView` parses.
  - Fix in `PermissionCard.vue`: always render the raw `arguments` `<details>`, beside the preview.
- files.create: `candy-code-web/src-web/components/__tests__/permissionCardDiff.spec.ts`
- files.modify: `candy-code-web/src-web/lib/lineDiff.ts` (`diffPairsFor`, `askDiffPreview` label), `candy-code-web/src-web/components/PermissionCard.vue` (template `ask-diff` / arguments block)
- tests:
  - modify `candy-code-web/src-web/lib/__tests__/lineDiff.spec.ts`:
    - Rewrite the batched case (:21-37) to the real `{old_string,new_string,edits:[…]}` shape. Assert that every pair's lines appear (including `exfiltrate()`) and that the counts sum.
    - A malformed extra entry gives null.
    - A `replace_all` pair carries the label suffix.
  - modify `candy-code-web/src-web/components/__tests__/permissionCardExtras.spec.ts:38`. It pins "no other `<details>` beside the diff", which is the defect, so flip it.
  - The new spec asserts that `arguments` details exist next to `ask-diff`.
- docs/guards: vitest (`--no-file-parallelism`). Rebuild web dist under the orchestrator's dist rule (see Notes). No PHP guard.
- serialized: [hot:candy-code-web/src-web/components/PermissionCard.vue] [hot:candy-code-web/dist]
- deps: none
- phase: normal
- ruling: none
- risks: the card grows taller for batched edits. The raw arguments sit in a collapsed `<details>`, so the card's default height is unchanged.

### C-SEC-2.b — Server-computed diff on `permission.asked`
- item: C-SEC-2 (sources: GAP-EDT-03, WEB-5-P4)
- kind: fix · CRITICAL · effort M
- approach:
  - The TUI already shows the real file diff. `Renderer.php:7258-7263` dispatches to `Edit/Write/ApplyPatch::previewDiff($root, $args)`. The web computes its own snippet diff instead, and shows ApplyPatch only as raw JSON.
  - New `AskDiffPreview::for(tool, args, root)`: the same three-way dispatch, plus the raw-patch fallback, confined to the session root (null outside it) and capped (the previews' own omitted-note beyond the cap).
  - `PendingAsk::describe()` (`src/Backend/PendingAsk.php:110-142`) adds `diff`. `fromFrame()` decodes it into a new trailing `?string $diff` ctor arg. `EngineBackend::completeAsyncBlocking()` (:5653) passes `$this->root` to `describe()`, as `ChildChannel` (:267) already does with its root.
  - `TranscriptProjector::askData()` (:472-487) and `SessionFeed::askSummary()` (:363-376) emit `diff`. `Definitions::PENDING_ASK` gains an optional `diff`.
  - `PermissionCard` prefers `ask.diff`, which has real file line numbers, over `askDiffPreview()`. The 2.a raw-arguments block stays.
- files.create: `candy-code/src/Permissions/AskDiffPreview.php`
- files.modify:
  - `candy-code/src/Backend/PendingAsk.php` (`describe`, `fromFrame`, ctor)
  - `candy-code/src/Backend/EngineBackend.php` (`completeAsyncBlocking` describe call)
  - `candy-code/src/Host/TranscriptProjector.php` (`askData`)
  - `candy-code/src/Protocol/SessionFeed.php` (`askSummary`)
  - `candy-code/src/Protocol/Schema/Definitions.php` (`PENDING_ASK`)
  - `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts` (regenerated)
  - `candy-code-web/src-web/components/PermissionCard.vue` (prefer `ask.diff`)
  - `candy-code-web/src-web/lib/lineDiff.ts` (a counts-from-unified-text helper for a server diff)
- tests:
  - create `candy-code/tests/Permissions/AskDiffPreviewTest.php`: all three tools, a path outside the root gives null, and the cap.
  - create `candy-code/tests/Host/PermissionAskDiffEventTest.php`: a served Edit ask with `edits[]` carries the full multi-hunk `diff` on `permission.asked` and in `permission.pending`.
  - create `candy-code/tests/Permissions/PendingAskDiffTest.php`: describe → fromFrame round-trip.
  - modify `candy-code-web/src-web/components/__tests__/permissionCardDiff.spec.ts` (from 2.a): a server `diff` wins.
- docs/guards: `scripts/gen-protocol-schema.php --check`, `npm run gen:protocol -- --check`, `ServerProtocolDocumentationDriftTest`, `ProtocolSchemaDriftTest`. Web dist rebuild.
- serialized: [protocol] [hot:candy-code/src/Backend/EngineBackend.php] [hot:candy-code/src/Host/TranscriptProjector.php] [hot:candy-code-web/src-web/components/PermissionCard.vue] [hot:candy-code-web/dist]
- deps: C-SEC-2.a
- phase: normal
- ruling: none
- risks:
  - `permission.requested` rows are recorded in the session event log, so the diff is persisted. Keep the cap small (≈32 KiB).
  - Confine the preview to the root, or a read-scope web viewer sees context lines of an out-of-root file the model asked to edit.

### C-SEC-3.a — One provenance-aware `!cmd` gate (refuse MCP and bundle shell forms)
- item: C-SEC-3 (sources: PLG-1-P2, MCP-2-P1, p1 Summary theme, p1-miss "unified third-party shell posture")
- kind: fix · MAJOR · effort M
- approach:
  - Root cause: `TurnController::refuseCommandShell()` (`candy-code/src/Host/TurnController.php:521-575`) gates only the `project` tier (:528) and the `mcp` tier (:545-554), and both legs check only `trustedProjectCommands`. After that, an `Ask` verdict proceeds unprompted (:567-575).
  - MCP leg: a remote server's `prompts/get` body runs a shell under a grant meant for the repo's own files. This is pinned as intended at `tests/Host/TurnControllerTest.php:186`.
  - Bundle leg: `ForeignCommandDiscovery::bundleDirs()` (:146-152) stamps bundle rows `$bundle->tier`, which is `user` for `~/.candy-code/bundles`, so git-installed bundle commands run `!cmd` unprompted.
  - Fix: a `CommandShellPosture` table maps `(tier, source)` to a `ShellProvenance` case:
    - operator (user tier): gate only;
    - repo (project tier): `trustedProjectCommands`, then the gate;
    - third-party (MCP prompt body, or any `SkillSource::Bundle` row whatever its tier): refused, with a Lang notice, before the gate is evaluated.
  - `refuseCommandShell()` and `customCommandMustFork()` read the table. A bundle body whose only shell forms are refused no longer forks; an MCP placeholder still forks, for its fetch.
  - Foreign rows keep today's behaviour in this step (the tier decides). C-SEC-3.b rules on them.
- files.create: `candy-code/src/Commands/CommandShellPosture.php`, `candy-code/src/Commands/ShellProvenance.php` (enum, one type per file)
- files.modify:
  - `candy-code/src/Host/TurnController.php` (`refuseCommandShell`, `customCommandMustFork`)
  - `candy-code/src/Commands/McpPromptCommands.php` (TRUST docblock :40-44)
  - `candy-code/lang/en.php` (`host.command_shell.mcp_refused`, `host.command_shell.bundle_refused`)
  - `candy-code/docs/COMMANDS.md` (tier table :23-28: add the bundle and MCP rows)
  - `candy-code/docs/MCP.md` (prompts section ~:875)
  - `candy-code/docs/PLUGINS.md` (bundle command shell forms join the declined surfaces)
- tests:
  - create `candy-code/tests/Commands/CommandShellPostureTest.php`: the full table, including a project-tier bundle row.
  - create `candy-code/tests/Host/TurnControllerBundleShellTest.php`: a bundle command's `!` form is refused and the gate is never evaluated.
  - modify `candy-code/tests/Host/TurnControllerTest.php:186`: refused even with trust = true.
  - modify `candy-code/tests/Commands/McpPromptCommandsTest.php`: a fetched body with `` !`echo pwned` `` never reaches the fork, and the refusal text is in the sent prompt.
- docs/guards: grep `candy-code/tests/Config/` for pins on the COMMANDS.md tier table, MCP.md :875-876 and the PLUGINS.md declined list (DocFigureProseDriftTest family). Move any that fire in this step. No drift-guarded roster changes.
- serialized: [hot:candy-code/src/Host/TurnController.php] [lang:host.command_shell.] [docs:COMMANDS.md] [docs:MCP.md] [docs:PLUGINS.md]
- deps: none. C-EXT-4 and C-MCP-2 should depend on this step.
- phase: normal
- ruling: none
- risks: users with Claude Code plugins whose commands rely on `!` lose those forms until C-SEC-3.b gives them a per-bundle opt-in. The refusal notice must name the path to the opt-in once it exists.

### C-SEC-3.b — Per-origin shell opt-in and the foreign-row decision
- item: C-SEC-3 (sources: PLG-1-P2 fix option 2, MCP-2-P1 fix option 2, p1-miss "foreign-user" tier)
- kind: fix · MAJOR · effort S
- approach:
  - Two user-tier-only list keys:
    - `trustedBundleCommands`: bundle names whose command `!` forms may run;
    - `mcpPromptShell`: MCP server names whose prompt bodies may run `!` forms.
  - Neither key is ever project-settable. The `CommandShellPosture` third-party arm consults them, then the gate.
  - The same step applies the owner's foreign-row ruling (`~/.claude/commands`, `~/.opencode/commands`). The code today lets the tier decide (`ForeignCommandDiscovery::claudeDirs` stamps them `user`), while `docs/COMMANDS.md:28` says foreign imports "never" run a shell. Either the table or the doc is wrong; the ruling picks which.
- files.create: none
- files.modify:
  - `candy-code/src/Commands/CommandShellPosture.php`
  - `candy-code/src/Host/TurnController.php` (pass the two lists)
  - `candy-code/src/Config/Settings/Definitions/PermissionSettings.php` (two `SettingDefinition`s, `ApplyMode::Frozen`, user-tier only)
  - `candy-code/src/Config/LayeredSettings.php` (generated roster blocks via `tools/gen-settings-doc.php --write`)
  - `candy-code/lang/en.php` (`settings.trustedBundleCommands.*`, `settings.mcpPromptShell.*`)
  - `candy-code/docs/SETTINGS.md` (generated)
  - `candy-code/README.md` (the user-tier-only key sentence at :1255 and its word number)
  - `candy-code/docs/COMMANDS.md` (foreign row per the ruling)
- tests: modify `candy-code/tests/Commands/CommandShellPostureTest.php` (opt-in arms and the foreign row per the ruling); modify `candy-code/tests/Host/TurnControllerBundleShellTest.php` (an opted-in bundle runs through the gate)
- docs/guards:
  - `tools/gen-settings-doc.php --check`;
  - `TrustKeyDocumentationDriftTest` (the README user-tier-only roster);
  - `DocFigureProseDriftTest` (the SETTINGS.md and README word numbers);
  - `SettingsWriter::trustKeys()` if the keys are classed as trust lists.
- serialized: [hot:candy-code/src/Host/TurnController.php] [hot:candy-code/src/Config/LayeredSettings.php] [hot:candy-code/src/Config/Settings/Definitions/PermissionSettings.php] [lang:settings.] [docs:SETTINGS.md] [docs:README.md] [docs:COMMANDS.md]
- deps: C-SEC-3.a
- phase: normal
- ruling: Should bundle and MCP-prompt `!cmd` be opt-in per origin (two new user-tier list keys)? And do user-tier foreign commands (`~/.claude|.opencode/commands`) run `!cmd` as the tier gate allows today, or never, as `docs/COMMANDS.md:28` states?
- risks: if the owner rules "never" for foreign rows, Claude Code users' personal `!` commands stop expanding. The refusal notice must say why.

### C-SEC-4.a — Served sessions honour `trustedProjectCommands`
- item: C-SEC-4 (sources: GAP-CMD-02, k2-sum live defect)
- kind: fix · MAJOR · effort S
- approach:
  - Root cause: two served callers of `CommandLoader::loadAll()` omit its second argument, `bool $projectCommandsTrusted` (`src/Commands/CommandLoader.php:605`):
    - `SessionHost::__construct()` (`candy-code/src/Host/SessionHost.php:240-244`);
    - `CommandMethods::files()` (`src/Protocol/Methods/CommandMethods.php:131`).
  - A trusted project's built-in override therefore runs in the TUI (`Chat.php:1798` passes it) but not over serve or the web.
  - Fix: pass `$workspace->projectCommandsTrusted` at both sites.
- files.create: none
- files.modify: `candy-code/src/Host/SessionHost.php` (ctor `customCommands`), `candy-code/src/Protocol/Methods/CommandMethods.php` (`files()`, one argument; no schema, event or gen-chain change)
- tests: create `candy-code/tests/Host/ServedProjectCommandTrustTest.php`. It mirrors the TUI precedence pin: a trusted project's override wins in a served host and in `command.list`, and an untrusted one is refused.
- docs/guards: none
- serialized: [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Protocol/Methods/CommandMethods.php] (body-only; see Notes)
- deps: none
- phase: normal
- ruling: none
- risks: none beyond the precedence pin. The served path now matches the TUI exactly.

### C-SEC-4.b — `context.trust` routable, failed writes surfaced, prompt discoverable
- item: C-SEC-4 (sources: WEB-3-P1, WEB-3-P2, WEB-3-P3, p2-miss "WEB-3 trust prompt discovery")
- kind: fix · MAJOR · effort M
- approach:
  - P1 root cause: the `context.trust` params (`MethodSchemas.php:413-418`) carry no `sessionId`. `Gateway::routeOf()` (`src/Server/Workspace/Gateway.php:289-298`) therefore sends the call to the primary process. The prompt was minted in a workspace-host child (`TrustAsks` is per-process static, :37-41), so every non-primary root gets `ask_not_found` and the prompt loops forever.
  - P1 fix: make `sessionId` required. The gateway then routes it like `permission.respond`, with no Gateway change. `TrustAsks::settle()` also verifies that the ask's root equals the session's workspace root.
  - P2 root cause: `TrustAsks::settle()` :124-131 drops `$report['error']` and settles `granted, persisted:false`.
  - P2 fix: when `remember === 'user'` and the write fails, throw `RpcError` `trust_write_failed` (carrying the report error) and leave the record `asked`.
  - P3 root cause: the prompt is minted only by `context.sources`, which runs only when the Context panel mounts.
  - P3 fix: `session.subscribe` runs the cheap MCP census (`Bootstrap::mcpServerInventory($root)['status']`) and calls `TrustAsks::discover()`. The web store holds a `pendingTrust` independent of `sources`; it currently drops events while `sources === null` (`contextSources.ts:86-97`). `StatusBar` shows a badge that opens the Context panel.
- files.create: `candy-code/tests/Server/GatewayTrustRoutingTest.php`, `candy-code-web/src-web/stores/__tests__/contextTrustRouting.spec.ts`, `candy-code-web/src-web/components/__tests__/statusBarTrustBadge.spec.ts`
- files.modify:
  - `candy-code/src/Protocol/Schema/MethodSchemas.php` (`context.trust` params and error description)
  - `candy-code/src/Protocol/Methods/ContextMethods.php` (`trust`)
  - `candy-code/src/Protocol/Methods/SessionMethods.php` (`subscribe` discovery)
  - `candy-code/src/Protocol/TrustAsks.php` (`settle` root check and write-failure arm)
  - `candy-code/lang/en.php` (`server.trust.write_failed`)
  - `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts` (regenerated)
  - `candy-code-web/src-web/stores/contextSources.ts` (`respondTrust` sends `sessionId`, `pendingTrust`, event filter)
  - `candy-code-web/src-web/components/panels/ContextPanel.vue` (render `trust_write_failed`)
  - `candy-code-web/src-web/components/StatusBar.vue` (badge)
- tests:
  - The new `GatewayTrustRoutingTest`: mint through `context.sources` on a non-primary root via the gateway, answer `context.trust`, and expect `granted`.
  - modify `candy-code/tests/Protocol/ContextTrustPromptTest.php`:
    - an unwritable user config keeps the record `asked` and admits a second answer;
    - subscribing on an untrusted root emits exactly one trust `permission.asked`.
  - modify `candy-code-web/src-web/stores/__tests__/contextSources.spec.ts`: the request carries `sessionId`.
- docs/guards: protocol gen checks; `ServerProtocolDocumentationDriftTest`; the SERVER.md error-kind roster (new `trust_write_failed`). Web dist rebuild.
- serialized: [protocol] [hot:candy-code/src/Protocol/TrustAsks.php] [hot:candy-code-web/src-web/components/panels/ContextPanel.vue] [hot:candy-code-web/src-web/stores/contextSources.ts] [hot:candy-code-web/src-web/components/StatusBar.vue] [hot:candy-code-web/dist] [lang:server.trust.]
- deps: none
- phase: normal
- ruling: none
- risks: `sessionId` becomes required on `context.trust`. That is a wire break for any older client, but the committed dist is rebuilt in the same lane.

### C-SEC-5.a — Trust-target census and one shared grant door
- item: C-SEC-5 (sources: GAP-PER-11, GAP-MCP-N1, k3-dorm#5)
- kind: dormant-wire · effort M
- approach:
  - The four trust lists exist (`PermissionSettings.php:134-152`, `Bootstrap.php:153/172/187`, `LayeredSettings::PROJECT_SETTINGS_TRUST_KEY`), and so do two writers:
    - `SettingsWriter::grantTrust($key, $root)` (`src/Config/Settings/SettingsWriter.php:376`), already wired by the settings editor through `App::grantProjectTrust()`;
    - `Bootstrap::trustProjectMcp()` (:7773), which also records MCP pins.
  - What is missing is a census of which targets a root needs. New `TrustTarget` enum: `mcp`, `mcp-pin`, `hooks`, `commands`, `settings`, with each case's config key. New `ProjectTrustCensus`: pure, with the predicates and "does the root declare it" probes injected as closures.
  - Three public Bootstrap methods, placed beside `trustProjectMcp`:
    - `pendingProjectTrust(?string $root)` wires the private predicates (`projectHooksAreTrusted`, `projectMcpIsTrusted`) and the public ones (`projectCommandShellIsTrusted`, `projectSettingsTrusted`);
    - `mcpPinRefusals(?string $root)` reads the memo client's `refusedServers()` (`src/MCP/McpClient.php:315`) through `McpDecision`, never launching a server;
    - `grantProjectTrust(TrustTarget, string $root, ?SettingsWriter)` sends `mcp`/`mcp-pin` to `trustProjectMcp` and every other target to `SettingsWriter::grantTrust`. It returns `{granted, persisted, error}`.
- files.create: `candy-code/src/Config/Trust/TrustTarget.php`, `candy-code/src/Config/Trust/ProjectTrustCensus.php`
- files.modify: `candy-code/src/Cli/Bootstrap.php` (new `pendingProjectTrust`, `mcpPinRefusals`, `grantProjectTrust` beside `trustProjectMcp`), `candy-code/src/Cli/McpDecision.php` (memo-only pin-refusal reader), `candy-code/lang/en.php` (`server.trust.ask.hooks|commands|settings|mcp_pin` reasons)
- tests: create `candy-code/tests/Config/Trust/ProjectTrustCensusTest.php` (each target declared or absent, trusted or not); create `candy-code/tests/Cli/BootstrapProjectTrustGrantTest.php` (each target lands in the right writer; an unwritable config reports `error`; the MCP path records pins)
- docs/guards:
  - none in this step; the user-visible docs land with 5.b and 5.d.
  - The writes go through the existing `SettingsWriter`/`writeUserConfig` doors, not Chat's `$onConfigChange`, so `ConfigWriteProducerDocumentationDriftTest`'s exact-two census holds.
- serialized: [bootstrap:new methods beside trustProjectMcp] [lang:server.trust.ask.]
- deps: none
- phase: normal
- ruling: none
- risks: the "declares commands" probe must mean "has a `!` form that could run" (`CommandSpec::hasShellSubstitution`), or every repo with a plain command file gets prompted.

### C-SEC-5.b — Web trust targets and MCP pin re-approval
- item: C-SEC-5 (sources: GAP-PER-11 web half, GAP-MCP-N1, k3-dorm#5)
- kind: dormant-wire · effort M
- approach:
  - `TrustAsks` (`src/Protocol/TrustAsks.php:33`) declares one grantable target (`TARGET_MCP`) and keys records by root. It becomes keyed by root+target.
  - `ContextMethods::sources()` mints one prompt per `Bootstrap::pendingProjectTrust($root)` entry. That includes `mcp-pin` when `mcpPinRefusals()` is non-empty on a trusted root.
  - `context.trust` settles through `Bootstrap::grantProjectTrust()`, passing the session workspace's `SettingsWriter` service.
  - Schema:
    - `Definitions::TRUST_PROMPT.target` widens to all five cases;
    - the `context.sources` answer replaces the single nullable `trust` with `trusts` (a list);
    - 4.b's subscribe-time discovery uses the widened census.
  - Web: `ContextPanel` renders a list (one Grant/Reject row per target), and the `StatusBar` badge counts them.
  - Each grant still applies next launch (the frozen-roster law), and the panel says so.
- files.create: `candy-code/tests/Protocol/ContextTrustTargetsTest.php`
- files.modify:
  - `candy-code/src/Protocol/TrustAsks.php` (keying, targets, `settle` writer closure)
  - `candy-code/src/Protocol/Methods/ContextMethods.php` (`sources`, `trust`)
  - `candy-code/src/Protocol/Methods/SessionMethods.php` (`subscribe` discovery)
  - `candy-code/src/Host/ContextSources.php` (`answer` shape)
  - `candy-code/src/Protocol/Schema/Definitions.php` (`TRUST_PROMPT`, `CONTEXT_SOURCES`)
  - `candy-code/src/Protocol/Schema/MethodSchemas.php` (`context.trust` description)
  - `candy-code/lang/en.php` (`server.trust.`)
  - `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts`
  - `candy-code-web/src-web/stores/contextSources.ts`
  - `candy-code-web/src-web/components/panels/ContextPanel.vue`
  - `candy-code-web/src-web/components/StatusBar.vue`
  - `candy-code/docs/PERMISSIONS.md` (trust section: the web prompts for all four lists)
- tests:
  - The new `ContextTrustTargetsTest`:
    - a root with untrusted hooks, MCP and settings mints three prompts;
    - each grant writes the right list;
    - a pin mismatch on a trusted root mints `mcp-pin`, and granting it re-records the pins.
  - modify `candy-code/tests/Protocol/ContextTrustPromptTest.php` (`trusts` shape) and `candy-code/tests/Protocol/ContextSourcesTest.php` (`trusts` shape).
  - modify `candy-code-web/src-web/components/panels/__tests__/contextPanel.spec.ts` (list rendering).
- docs/guards: protocol gen checks; `ServerProtocolDocumentationDriftTest`; `TrustKeyDocumentationDriftTest` (PERMISSIONS.md trust paragraph). Web dist rebuild.
- serialized: [protocol] [hot:candy-code/src/Protocol/TrustAsks.php] [hot:candy-code/src/Host/ContextSources.php] [hot:candy-code-web/src-web/components/panels/ContextPanel.vue] [hot:candy-code-web/src-web/stores/contextSources.ts] [hot:candy-code-web/src-web/components/StatusBar.vue] [hot:candy-code-web/dist] [lang:server.trust.] [docs:PERMISSIONS.md]
- deps: C-SEC-4.b (routing and the write-failure arm), C-SEC-5.a (census and grant door)
- phase: normal
- ruling: none
- risks:
  - `ContextSources.php` and `ContextPanel.vue` are also P-CTX-BREAKDOWN's and C-MCP-4's surfaces, so sequence those against this lane.
  - A `trustedProjectSettings` grant widens what the project file may set. The prompt reason must say that.

### C-SEC-5.c — TUI trust dialog component
- item: C-SEC-5 (sources: GAP-PER-11 TUI half)
- kind: dormant-wire · effort S
- approach:
  - A self-contained immutable `TrustDialog` model. It takes the `TrustTarget` list from 5.a and the root.
  - It shows one row per target, with what granting enables (hooks run programs, MCP starts servers, commands may run `!` forms, settings may change N keys) and the frozen-roster line "takes effect next launch".
  - Keys: `y` grant this, `a` grant all, `n` skip this, `Esc` dismiss. It returns choices as data and performs no writes.
  - `view(int $cols, int $rows, Theme)` returns the boxed string, so the step needs no Renderer edit.
- files.create: `candy-code/src/Tui/TrustDialog.php`, `candy-code/tests/Tui/TrustDialogTest.php`
- files.modify: `candy-code/lang/en.php` (`tui.trust.*`)
- tests: the new `TrustDialogTest` covers the key arms, the grant-all list, the box width at narrow cols (no over-wide lines, per the TUI render invariant), and the snapshot bytes.
- docs/guards: none. The dialog keys are local to the modal and do not join `KeyBindingRegistry`; if the lane registers them, `KeyBindingDriftTest` and its doc move in-step.
- serialized: [lang:tui.trust.]
- deps: C-SEC-5.a
- phase: normal
- ruling: none
- risks: none; the component is inert until 5.d wires it.

### C-SEC-5.d — TUI launch trust dialog wiring
- item: C-SEC-5 (sources: GAP-PER-11)
- kind: dormant-wire · effort M
- approach:
  - At an interactive launch only, never `-p` or serve, `LaunchContext` seeds the App with `TrustDialog::for(Bootstrap::pendingProjectTrust($root))` when the list is non-empty. The App is built at :2274.
  - `App` holds the dialog as an overlay field, the way it holds `settingsEditor` (`src/App/App.php:371`). `KeyboardHandler` routes keys to it while it is open.
  - Each grant runs as a Cmd through `Bootstrap::grantProjectTrust(target, root, $this->settingsWriter)`, and the result is answered as a notice. This mirrors the existing `App::grantProjectTrust()` (:3838-3874).
  - `App::view()` composites the dialog box over the frame that `TuiRenderer::renderView()` returns. If the frame compositor only exists inside `src/Tui/Renderer.php`, the lane also claims [renderer] for that one call.
  - The once-per-root "asked" latch reuses the launch-notice dedupe, so a skipped target does not reappear every launch. Keep the latch in process memory, plus a user-tier "skipped" list only if the owner later asks for one.
- files.create: `candy-code/tests/App/LaunchTrustDialogTest.php`
- files.modify:
  - `candy-code/src/App/App.php` (overlay field, `withTrustDialog`, key arm, grant Cmd, `view` composite)
  - `candy-code/src/Tui/KeyboardHandler.php` (modal routing)
  - `candy-code/src/Cli/LaunchContext.php` (App build ~:2274)
  - `candy-code/docs/PERMISSIONS.md` (launch dialog paragraph)
  - `candy-code/lang/en.php` (`tui.trust.granted|failed`)
  - `candy-code/src/Tui/Renderer.php` (only if compositing has to live there)
- tests:
  - The new `LaunchTrustDialogTest`:
    - an untrusted root with hooks shows the dialog;
    - `y` writes `trustedProjectHooks` through a fake `SettingsWriter`;
    - a write failure shows the failure notice;
    - a trusted root shows nothing;
    - `-p` never builds the dialog.
  - modify `candy-code/tests/Tui/KeyboardHandlerTest.php` (modal swallows keys).
- docs/guards: `TrustKeyDocumentationDriftTest` (PERMISSIONS.md trust prose); `MouseModalGuardTest` if the overlay must refuse clicks underneath.
- serialized: [hot:candy-code/src/App/App.php] [hot:candy-code/src/Tui/KeyboardHandler.php] [hot:candy-code/src/Cli/LaunchContext.php] [renderer] (conditional) [docs:PERMISSIONS.md] [lang:tui.trust.]
- deps: C-SEC-5.a, C-SEC-5.c
- phase: normal
- ruling: none
- risks: `App.php` and `KeyboardHandler.php` are likely P-SESSION-TABS / P-IMAGE-MENU / C-TUI surfaces too. Serialize the lane against them.

### C-SEC-6.a — Saved-rule service and `/permissions saved` / `forget`
- item: C-SEC-6 (sources: OC-new#3, GAP-PER-04)
- kind: gap · effort M
- approach:
  - Saved "always" grants (`p` → `permissionRules` in the user config) can only be added today. The one writer is `PermissionPrompt::persistRules()` (`src/Permissions/PermissionPrompt.php:262-305`).
  - Extract a `SavedRules` service: `list()` (user-tier `permissionRules`, numbered), `append(patterns)` (the moved body), and `remove(int|pattern)`, all through `SettingsWriter` (`SettingsTier::You`). `persistRules()` delegates to it.
  - The host command `PermissionsCommand` (`src/Host/Commands/PermissionsCommand.php:39-45`) gains `/permissions saved` and `/permissions forget <n>|<pattern>`. That makes it work in the TUI and over `command.exec`.
  - The live gate's rules are frozen per launch (`PermissionGate::rules()` :361). The reply says that `forget` applies from the next launch, as the settings editor does for permission keys.
- files.create: `candy-code/src/Permissions/SavedRules.php`
- files.modify:
  - `candy-code/src/Permissions/PermissionPrompt.php` (`persistRules` delegates)
  - `candy-code/src/Host/Commands/PermissionsCommand.php` (`run`, new `saved`/`forget` arms)
  - `candy-code/lang/en.php` (`host.permissions.saved*`, `host.permissions.forget*`, `cmd.permissions.hint` value)
  - `candy-code/docs/PERMISSIONS.md`
  - `candy-code/docs/COMMANDS.md` (regenerated by `tools/gen-command-docs.php` if the hint renders there)
- tests: create `candy-code/tests/Permissions/SavedRulesTest.php` (list, append-dedupe, remove-by-index and by-pattern, write failure reported); create `candy-code/tests/Host/Commands/PermissionsSavedRulesCommandTest.php`; modify any `PermissionPrompt` persist pin that names the old body
- docs/guards: `tools/gen-command-docs.php --check`; `PermissionsIntroDriftTest` if the intro enumerates subcommands
- serialized: [lang:host.permissions.] [lang:cmd.permissions.hint] [docs:PERMISSIONS.md] [docs:COMMANDS.md]. The `builtin-commands/1200-permissions.php` row is not edited; only its Lang value changes.
- deps: none
- phase: normal
- ruling: none
- risks: "applies next launch" for a revoked allow can surprise a user. The reply must say it, and point at `/permissions mode` for an immediate tightening.

### C-SEC-6.b — Project-tier deny/ask-only rules
- item: C-SEC-6 (sources: GAP-PER-04(b))
- kind: gap · effort M
- approach:
  - Today rules come only from the user tier: `Bootstrap::permissionConfigLayers()` (:6969) reads `settings.json` and `config.json`.
  - Under the existing `trustedProjectSettings` grant, read `permissionRules` from `<root>/.candy-code/settings.json`, keep only `deny`/`ask` entries, and prepend them. They can only narrow, and the self-grant floor holds.
  - A new `ProjectNarrowingRules` filter reports any dropped `allow` entries. `Bootstrap::permissionGate()` (:6769-6871) merges the result.
  - One launch notice names the file and the rules it added, for auditability. `/permissions` shows each rule's tier.
- files.create: `candy-code/src/Permissions/ProjectNarrowingRules.php`
- files.modify: `candy-code/src/Cli/Bootstrap.php` (`permissionGate`, `permissionRules` call site, launch notice), `candy-code/src/Host/Commands/PermissionsCommand.php` (`report`: tier column), `candy-code/lang/en.php` (`appLaunch.notice.project_narrowing_rules`), `candy-code/docs/PERMISSIONS.md`, `candy-code/README.md` (any "rules live in the user tier only" claim)
- tests: create `candy-code/tests/Permissions/ProjectNarrowingRulesTest.php` (allow dropped, deny/ask kept, ordering); create `candy-code/tests/Cli/BootstrapProjectNarrowingRulesTest.php` (untrusted root ignored; trusted root's deny wins over a user allow; notice emitted once)
- docs/guards: `PermissionsIntroDriftTest`; `TrustKeyDocumentationDriftTest` (README tier sentences); `DocFigureProseDriftTest` if PERMISSIONS.md counts tiers
- serialized: [bootstrap:permissionGate, permissionRules] [lang:appLaunch.notice.] [docs:PERMISSIONS.md] [docs:README.md]
- deps: none
- phase: normal
- ruling: none
- risks: `LayeredSettings` may already announce a project `permissionRules` key as "user-tier only, ignored". Reconcile that notice in the same step so the launch does not say both.

### C-SEC-6.c — Web saved-rule management and `remember: "user"`
- item: C-SEC-6 (sources: GAP-PER-04(a), OC-new#3)
- kind: gap · effort M
- approach:
  - `PermissionMethods::respond()` refuses `remember:"user"` with `remember_user_unavailable` (`src/Protocol/Methods/PermissionMethods.php:68-70`). The fix answers Always and calls `SavedRules::append()` with the ask's patterns (`SessionPermissionMemo::patternsFor`, as the TUI `p` does).
  - `permission.rules` rows (:213-230) gain `tier` and a `savedIndex` for user-tier rows.
  - A new `permission.forget` method {pattern, action} (Scope::Approve) removes one saved rule through `SavedRules`.
  - Web:
    - `PermissionCard` gains an "Always (save)" verdict;
    - `RulesPanel` gains a Saved section with Forget buttons and the "next launch" note;
    - the `approvals` store sends `remember:'user'`.
- files.create: `candy-code/tests/Protocol/PermissionSavedRulesMethodsTest.php`, `candy-code-web/src-web/components/panels/__tests__/rulesPanelSaved.spec.ts`
- files.modify:
  - `candy-code/src/Protocol/Methods/PermissionMethods.php` (`respond`, `rules`, new `forget`)
  - `candy-code/src/Protocol/Schema/MethodSchemas.php` (`permission.rules` result, `permission.forget`)
  - `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts`
  - `candy-code-web/src-web/components/PermissionCard.vue`
  - `candy-code-web/src-web/components/panels/RulesPanel.vue`
  - `candy-code-web/src-web/stores/approvals.ts`
  - `candy-code/docs/PERMISSIONS.md` ("`remember: user` is not offered over the wire" → offered)
- tests: the new PHP test covers remember-user persistence, forget, project remember still Forbidden, and the `remember_user_unavailable` removal. The new web spec covers Forget. Modify `candy-code/tests/Server/PermissionFirstWinsTest.php` if it pins the old refusal.
- docs/guards: protocol gen checks; `ServerProtocolDocumentationDriftTest`; `PermissionsIntroDriftTest`. Web dist rebuild.
- serialized: [protocol] [hot:candy-code-web/src-web/components/PermissionCard.vue] [hot:candy-code-web/src-web/components/panels/RulesPanel.vue] [hot:candy-code-web/dist] [docs:PERMISSIONS.md]
- deps: C-SEC-6.a
- phase: normal
- ruling: none
- risks: a web approver can now write the operator's user config. Scope::Approve already gates verdicts, and the rules written are allow-only, exactly the TUI `p` set.

### C-SEC-6.d — Web permission audit view
- item: C-SEC-6 (sources: GAP-PER-14)
- kind: gap · effort M
- approach:
  - `AuditHook` already writes a redacted, rotated log with three line shapes (`src/Hooks/BuiltIn/AuditHook.php` header: `=>`, `=! DENY <kind>`, `=! WITHHELD`). Nothing reads it.
  - New `AuditLogReader`: a bounded tail of `AuditHook::defaultLogFile()`, parsing each line into {time, session, tool, verdict, kind, reason}. It never loads the whole file.
  - New `permission.audit` {sessionId?, limit ≤ 200} at Scope::Approve, deliberately tighter than the gap's Read because the log spans every session and root of this user.
  - Rendered as "Recent decisions" in `RulesPanel`.
- files.create: `candy-code/src/Hooks/BuiltIn/AuditLogReader.php`, `candy-code/tests/Hooks/AuditLogReaderTest.php`, `candy-code/tests/Protocol/PermissionAuditMethodTest.php`, `candy-code-web/src-web/components/panels/__tests__/rulesPanelAudit.spec.ts`
- files.modify:
  - `candy-code/src/Protocol/Methods/PermissionMethods.php` (new `audit`)
  - `candy-code/src/Protocol/Schema/MethodSchemas.php`
  - `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts`
  - `candy-code-web/src-web/components/panels/RulesPanel.vue`
- tests: the reader covers the three shapes, a torn last line, rotation, and the cap. The method covers the session filter and the scope refusal. The web spec renders rows.
- docs/guards: protocol gen checks; `ServerProtocolDocumentationDriftTest`. Web dist rebuild.
- serialized: [protocol] [hot:candy-code-web/src-web/components/panels/RulesPanel.vue] [hot:candy-code-web/dist]
- deps: none. It shares `RulesPanel.vue` and the protocol chain with 6.c, so schedule them in different waves.
- phase: normal
- ruling: none
- risks: the default log lives in a per-user temp dir. A serve process under a different `HOME`/`TMPDIR` reads an empty log, so state that in the panel.

### C-SEC-6.e — OTel safety events per verdict
- item: C-SEC-6 (sources: CC-new#4)
- kind: gap · effort S
- approach:
  - Every refusal already reaches the encoder: `ToolFinished->result->denial` (`src/ToolResult.php:140`, a `DenialKind` of refused / unanswered / hook) crosses the fork today. Asks arrive as `PermissionAsked`, and rejections as `PermissionResolved`.
  - `OtlpEncoder` (`src/Observability/OtlpEncoder.php:158-171`, :311-327) emits a `candy_code.safety_event` span event for:
    - each denial (`decision=deny`, `kind`, tool, first reason token);
    - each ask (`decision=ask`, `source`, `mode`);
    - each rejection.
  - No Runtime or fork-channel change is needed.
- files.create: `candy-code/tests/Observability/OtlpSafetyEventTest.php`
- files.modify: `candy-code/src/Observability/OtlpEncoder.php`, `candy-code/docs/ENVIRONMENT.md` (OTel section: event name and attributes)
- tests: the new test covers deny, hook-deny, ask, reject and allow (no event). Modify `candy-code/tests/Observability/OtlpEncoderTest.php` if it counts events per span.
- docs/guards: `EnvRosterDriftTest` is untouched (no env var).
- serialized: [hot:candy-code/src/Observability/OtlpEncoder.php] [docs:ENVIRONMENT.md]
- deps: C-SES-4 (span timestamps, the off-TUI export path and serve/`-p` sink threading all change the encoder and sink first)
- phase: normal
- ruling: none
- risks: a denial reason can quote command text. Emit the `DenialKind` token and tool name only, never the reason body.

### C-SEC-7.a — `-p` starts in `default` mode (asks, denies unattended)
- item: C-SEC-7 (sources: CC-new#1)
- kind: gap · effort M
- approach:
  - `Bootstrap::DEFAULT_PERMISSION_MODE = BypassPermissions` (`src/Cli/Bootstrap.php:194`) is the fallback for both `-p` and the background daemon (:6827).
  - Split it into `HEADLESS_DEFAULT_PERMISSION_MODE = Default` for `-p` and `BACKGROUND_DEFAULT_PERMISSION_MODE = BypassPermissions` for locally spawned `/bg` daemons. `permissionGate()` gains a surface parameter.
  - `NonInteractive` (~:1830, where `backendFor(..., null, true)` lets the gate default) passes an explicit headless gate.
  - `HeadlessPermissionPrompt` already asks at a terminal and refuses with no terminal. Its unattended refusal names `--permission-mode` as the way out.
  - The rv-sec-1 bypass notice stays for an explicit bypass.
- files.create: `candy-code/tests/Cli/HeadlessDefaultPermissionModeTest.php`
- files.modify:
  - `candy-code/src/Cli/Bootstrap.php` (consts :189-197, `permissionGate` :6769-6871)
  - `candy-code/src/Cli/NonInteractive.php` (headless backend gate ~:1820-1830)
  - `candy-code/src/Cli/HeadlessPermissionPrompt.php` (unattended refusal hint)
  - `candy-code/lang/en.php` (`cli.headless.permission_hint`, `appLaunch.notice.`)
  - `candy-code/README.md` (mode table :1453, prose :1747-1751)
  - `candy-code/docs/PERMISSIONS.md`
- tests:
  - The new test: `-p` with no mode resolves to `default`; an unattended ask is denied with the hint; `--permission-mode bypass-permissions` still bypasses; a `/bg` daemon still bypasses.
  - Flip the existing pins of the old default. Grep candidates: `tests/Cli/BootstrapPermissionGateTest.php`, `HeadlessPermissionPromptUnattendedAskTest.php`, `LaunchFlagsTest.php`, `DoctorBypassNoticeProbeTest.php`, `BypassLaunchBannerTest.php`.
- docs/guards: `ReadmeRosterDriftTest`; `PermissionsIntroDriftTest`; `DocFigureProseDriftTest` (mode matrix)
- serialized: [bootstrap:permissionGate + DEFAULT_PERMISSION_MODE consts] [headless] [lang:appLaunch.notice.] [lang:cli.headless.] [docs:README.md] [docs:PERMISSIONS.md]
- deps: none
- phase: normal
- ruling: rv-sec-1 (`Bootstrap.php:6834`, "owner ruling: the default stands") kept the `-p` bypass default, and this pick reverses it. Confirm the flip for `-p` only, with locally spawned `/bg` daemons keeping bypass?
- risks: existing CI scripts that rely on `-p` editing files unprompted start failing closed. The hint line and README must make the one-flag fix obvious.

### C-SEC-7.b — `--restricted` and `--safe-mode`
- item: C-SEC-7 (sources: GAP-PER-N2)
- kind: gap · effort M
- approach:
  - `--restricted` drops the code-running tools from the launch tool set through `Bootstrap::filterToolSet()` (:8671): `ShellSubjectTools::NAMES`, plus `Task`, `Workflow`, `ShellStop` and `mcp_call`. File tools stay under the existing `WritePathScope` confinement.
  - `--safe-mode` makes the process ignore:
    - every `trusted*` list (`trustedRootsForThisProcess()` :6422 and the per-key predicates answer "untrusted");
    - bundles (`Bootstrap::bundles()` :2273 answers `[]`);
    - foreign skills, agents and commands.
  - Both flags print one launch notice and survive a session relaunch (`SessionRelaunch::KEPT_FLAGS`).
- files.create: `candy-code/tests/Cli/RestrictedSafeModeFlagsTest.php`
- files.modify:
  - `candy-code/src/Cli/ArgvParser.php`
  - `candy-code/src/Cli/ParsedArgs.php`
  - `candy-code/src/Cli/Subcommands.php` (completion roster ~:2545)
  - `candy-code/src/Cli/Help.php`
  - `candy-code/src/Support/SessionRelaunch.php` (`KEPT_FLAGS`)
  - `candy-code/src/Cli/Bootstrap.php` (`filterToolSet`, `trustedRootsForThisProcess`, `bundles`, the trust predicates)
  - `candy-code/lang/en.php` (`cli.help.`, `cli.completion.option.`, `appLaunch.notice.`)
  - `candy-code/README.md` (flags table)
  - `candy-code/docs/PERMISSIONS.md`
- tests:
  - The new test:
    - each flag parses, through both the `=` and the space form;
    - `--restricted` removes the code-running tools and keeps Read/Edit;
    - `--safe-mode` refuses a trusted project's hooks, MCP and commands, and loads no bundle.
  - modify `candy-code/tests/Cli/ArgvParserTest.php` and `candy-code/tests/Cli/HelpTest.php`.
- docs/guards: `ReadmeRosterDriftTest` (flag roster); the ArgvParser const-mirror test; the completion roster pin
- serialized: [headless] [bootstrap:filterToolSet, trustedRootsForThisProcess, bundles] [lang:cli.] [docs:README.md] [docs:PERMISSIONS.md]
- deps: none. It shares [headless], README and PERMISSIONS.md with 7.a, so schedule them in different waves.
- phase: normal
- ruling: none
- risks: foreign discovery has no single switch today. The lane threads one flag through `ForeignSkillDiscovery`/`ForeignCommandDiscovery` callers, so audit every caller.

### C-SEC-7.c — Plan mode drops the tools the gate always refuses
- item: C-SEC-7 (sources: GAP-TL-27, k2-sum TL-27 web ✅→🟡)
- kind: gap · effort M
- approach:
  - Plan mode refuses writes at the gate, but the model still sees every tool (`docs/PERMISSIONS.md:487-488`: "The tool list is the same in every mode").
  - The gap's proposal (drop `Runtime::WRITE_CAPABLE_TOOL_NAMES`) is wrong. Plan mode allows `Write` of a `.md` into `.candy-code/plans/` and read-only `Bash`, so both must stay.
  - Instead, `EngineBackend::turnTools()` (:3151) drops each tool whose declaration the gate refuses: `PermissionGate::refuses(ToolDeclaration)` (:564), the gate's own declaration verdict. The roster therefore cannot drift from the enforcement. Apply it only while the mode is Plan. `PlanExit` stays.
  - Accept one prompt-cache break per mode switch, and document it beside the cache law.
- files.create: `candy-code/tests/Backend/PlanModeToolRosterTest.php`
- files.modify: `candy-code/src/Backend/EngineBackend.php` (`turnTools`), `candy-code/docs/PERMISSIONS.md` (:487-488), `candy-code/docs/PROMPT_ENGINEERING.md` (cache-law carve-out; verify the page in-step)
- tests:
  - The new test: in Plan, `Edit`/`ApplyPatch` are absent while `Write`, `Bash`, `Read` and `PlanExit` are present; in Default, the roster is unchanged.
  - modify `candy-code/tests/Providers/PromptPrefixByteStabilityTest.php`: the prefix is stable within a mode, with the one mode-switch break carved out.
- docs/guards: `PermissionsIntroDriftTest` (the "same in every mode" sentence)
- serialized: [hot:candy-code/src/Backend/EngineBackend.php] [docs:PERMISSIONS.md] [docs:PROMPT_ENGINEERING.md]
- deps: none
- phase: normal
- ruling: none
- risks: a configured `Deny <Tool>` rule now also hides that tool in Plan mode. That is correct by construction, but the doc must say so.

### C-SEC-7.d — Model-judged review for writes, with a chosen reviewer model
- item: C-SEC-7 (sources: GAP-PER-07, CC-new#8)
- kind: gap · effort M
- approach:
  - `PermissionGate::autoReviewsUnflaggedShell()` (:998-1003) sends only shell calls to `ExecReviewer`. The reviewer is always built on the title backend (`Bootstrap.php:6849-6862`).
  - Generalise it to `autoReviewsUnflagged()`, driven by a new `autoReviewScope: shell|writes|all` key (default `shell`, byte-identical today). `writes` adds `Edit`, `Write`, `ApplyPatch` and `WebFetch`.
  - Add `autoReviewModel` (`provider/model`, empty means the title backend), built through the same factory as `titleBackend()` (:9397).
  - `ExecReviewer` gains an args renderer for non-shell tools (the path and a capped diff from `AskDiffPreview` if 2.b landed; otherwise the args).
  - Both keys are user-tier only, because each review is a paid model call.
- files.create: `candy-code/tests/Permissions/AutoReviewScopeTest.php`
- files.modify:
  - `candy-code/src/Permissions/PermissionGate.php` (`autoReviewsUnflaggedShell` → scoped)
  - `candy-code/src/Permissions/ExecReviewer.php` (non-shell prompt)
  - `candy-code/src/Config/Settings/Definitions/PermissionSettings.php` (two definitions)
  - `candy-code/src/Config/LayeredSettings.php` (generated rosters)
  - `candy-code/src/Cli/Bootstrap.php` (`permissionGate` reviewer build)
  - `candy-code/lang/en.php` (`settings.autoReviewScope.*`, `settings.autoReviewModel.*`, `cli.warn.auto_review_model_*`)
  - `candy-code/docs/SETTINGS.md` (generated)
  - `candy-code/README.md` (user-tier-only roster sentence :1255)
  - `candy-code/docs/PERMISSIONS.md` (Auto paragraph)
- tests: the new test covers scope `shell` (unchanged), `writes` (an Edit is reviewed), the model override building its own backend, and a bad model warning once. Modify `candy-code/tests/Permissions/UnflaggedShellReviewTest.php` and `candy-code/tests/Permissions/ExecReviewerTest.php`.
- docs/guards: `tools/gen-settings-doc.php --check`; `TrustKeyDocumentationDriftTest` (README user-tier-only roster and word number); `DocFigureProseDriftTest` (SETTINGS.md count)
- serialized: [bootstrap:permissionGate] [hot:candy-code/src/Permissions/PermissionGate.php] [hot:candy-code/src/Config/LayeredSettings.php] [hot:candy-code/src/Config/Settings/Definitions/PermissionSettings.php] [lang:settings.autoReview] [docs:SETTINGS.md] [docs:README.md] [docs:PERMISSIONS.md]
- deps: none. It reuses `AskDiffPreview` if C-SEC-2.b landed first; otherwise it renders args.
- phase: normal
- ruling: none
- risks: in Auto mode, `writes` multiplies paid review calls. The doc states the cost, and the default stays `shell`.

### C-SEC-7.e — `autoReview` default
- item: C-SEC-7 (sources: GAP-PER-07 owner-ruling note; candidates.md "Owner rulings: `autoReview` default")
- kind: gap · effort S
- approach:
  - `autoReview` is `false` by default (`PermissionSettings.php:61`). Per the ruling, either flip it to "on when a title backend or `autoReviewModel` resolves", or record the decision to keep it off.
  - When on, `permissionGate()` builds the reviewer exactly as an explicit `true` does, and its no-provider warning is silenced for the implicit default.
- files.create: none
- files.modify: `candy-code/src/Config/Settings/Definitions/PermissionSettings.php` (default), `candy-code/src/Cli/Bootstrap.php` (`permissionGate` implicit-default arm), `candy-code/docs/SETTINGS.md` (generated), `candy-code/docs/PERMISSIONS.md`
- tests: modify `candy-code/tests/Permissions/AutoReviewScopeTest.php` (implicit default with and without a title backend); modify `candy-code/tests/Cli/BootstrapPermissionGateTest.php`
- docs/guards: `tools/gen-settings-doc.php --check`
- serialized: [bootstrap:permissionGate] [hot:candy-code/src/Config/Settings/Definitions/PermissionSettings.php] [docs:SETTINGS.md] [docs:PERMISSIONS.md]
- deps: C-SEC-7.d
- phase: normal
- ruling: Turn `autoReview` on by default whenever a title backend (or `autoReviewModel`) exists? Each review is a paid model call.
- risks: if the owner keeps it off, this step reduces to a doc sentence. Fold it into 7.d's lane instead of scheduling it.

### C-SEC-8.a — Fence MCP tool output as untrusted
- item: C-SEC-8 (sources: GAP-PER-10)
- kind: gap · effort S
- approach:
  - `McpToolBridge::replyResult()` (`src/Tools/McpToolBridge.php:599-607`) is the one renderer for both a bridged MCP tool and `mcp_call` (`src/Tools/BuiltIn/McpCallTool.php`). It returns third-party server text raw.
  - New `McpOutputFence` mirrors `DelegatedOutputFence` (`src/Context/DelegatedOutputFence.php`): `PromptFence::escape()`, then quoted role labels, then a provenance header `[mcp tool output from <server>/<tool>: no user authority]`.
  - `replyResult()` gains server and tool parameters and fences before truncating, so the header survives the cap. `McpCallTool` passes its own names, which keeps the MCP-7 byte-parity promise.
- files.create: `candy-code/src/Context/McpOutputFence.php`, `candy-code/tests/Context/McpOutputFenceTest.php`
- files.modify: `candy-code/src/Tools/McpToolBridge.php` (`replyResult`, `call`), `candy-code/src/Tools/BuiltIn/McpCallTool.php` (`replyResult` caller), `candy-code/docs/MCP.md` (result framing sentence)
- tests: the new fence test covers the escape, role marking, header and idempotence. Modify `candy-code/tests/Tools/McpToolBridgeTest.php` and `candy-code/tests/Tools/McpToolBridgeResultCapTest.php` (byte expectations, header kept under the cap). Modify `candy-code/tests/Permissions/McpDispatchParityTest.php` (the bridge and `mcp_call` stay byte-identical).
- docs/guards: none roster-pinned; check the `docs/MCP.md` wording
- serialized: [hot:candy-code/src/Tools/McpToolBridge.php] [docs:MCP.md]
- deps: none
- phase: normal
- ruling: none
- risks: the header also shows in the TUI tool row. That is acceptable and matches sub-agent output. C-MCP items that touch `McpToolBridge` must sequence against this step.

### C-SEC-8.b — Opt-in secret redaction of tool output
- item: C-SEC-8 (sources: GAP-PER-09)
- kind: gap · effort M
- approach:
  - Tool results enter history and `sessions.db` raw. `SecretRedactor` (`src/Memory/SecretRedactor.php:79`) covers only memory, audit and `/share`.
  - `Runtime::resultMessage()` (`src/Runtime.php:3545-3570`) is documented as "the ONE place this class builds a ToolResultMessage".
  - When a new user-tier `redactToolOutput` (bool, default false) is on, it runs `SecretRedactor::redact()` there, after the UTF-8 and tag scrub. This never alters what the tool executed. `/export` stays raw.
  - The flag reaches `Runtime` through its existing settings plumbing (a `with*` on construction).
- files.create: `candy-code/tests/Runtime/ToolOutputRedactionTest.php`
- files.modify:
  - `candy-code/src/Runtime.php` (`resultMessage` and its flag)
  - `candy-code/src/Config/Settings/Definitions/PermissionSettings.php` (definition)
  - `candy-code/src/Config/LayeredSettings.php` (generated rosters)
  - `candy-code/lang/en.php` (`settings.redactToolOutput.*`)
  - `candy-code/docs/SETTINGS.md` (generated)
  - `candy-code/README.md` (user-tier-only roster)
- tests: the new test covers off (raw), on (a Bearer token becomes `[REDACTED]` in the ToolResultMessage), the image and usage fields kept, and idempotence. If the served event log stores `ToolFinished` rather than the folded message, add the same pin for `tool.finished`.
- docs/guards: `tools/gen-settings-doc.php --check`; `TrustKeyDocumentationDriftTest` (README roster); `DocFigureProseDriftTest`
- serialized: [runtime] [hot:candy-code/src/Config/LayeredSettings.php] [hot:candy-code/src/Config/Settings/Definitions/PermissionSettings.php] [lang:settings.redactToolOutput.] [docs:SETTINGS.md] [docs:README.md]
- deps: none
- phase: normal
- ruling: none
- risks:
  - The TUI and served `tool.finished` event payloads come from `ToolFinished`, not from the folded message, and may still persist the raw text. The lane must measure which one `sessions.db` and the EventLog store.
  - Redaction changes prompt bytes only for results that contain secrets.

### C-SEC-8.c — `Sanitize::stripInvisibleFormatting()` in sugar-core
- item: C-SEC-8 (sources: GAP-UI-N5)
- kind: gap · effort S
- approach:
  - `Sanitize::markInvisibleFormatting()` (`sugar-core/src/Util/Sanitize.php:359`) only marks. It has a two-tier rule: bidi, ZWSP, WJ and BOM are always marked, while ZWJ/ZWNJ/LRM/RLM/ALM are kept after a non-ASCII byte.
  - Add a sibling `stripInvisibleFormatting(string $s, ?int &$removed = null)`. It applies the same tiers, deletes instead of marking, and reuses `invisibleFormattingMaps()` so the two can never disagree.
- files.create: `sugar-core/tests/Util/SanitizeStripInvisibleFormattingTest.php`
- files.modify: `sugar-core/src/Util/Sanitize.php`
- tests: the new test covers the always tier removed, a ZWJ inside an emoji kept, `pay\u{200D}pal` stripped, the count, and empty input.
- docs/guards: the sugar-core suite. A `Mirrors` citation is not needed; this is a SugarCraft original.
- serialized: none
- deps: none
- phase: normal
- ruling: none
- risks: candy-code consumes it in 8.d. In Packagist mode the new method is not in vendor until sugar-core publishes, so 8.d's lane must run `refresh-deps.php --mode=linked`.

### C-SEC-8.d — Strip invisible Unicode at submit (TUI and serve)
- item: C-SEC-8 (sources: GAP-UI-N5)
- kind: gap · effort S
- approach:
  - Invisible formatting is only marked on display today. One rule applies at both submit chokes:
    - TUI: `Chat::submit()` (`src/Chat.php:9389`);
    - served: `SessionHost::submit()` (`src/Host/SessionHost.php:524`), which `session.send` reaches through `TurnMethods.php:198`.
  - Each choke runs `Sanitize::stripInvisibleFormatting()` on the prompt text, not on attachments, and posts one notice with the count when anything was removed.
  - No opt-out key: the joiner tier keeps legitimate scripts and emoji. This deliberately departs from the gap's "setting to keep them", to stay off the settings and README rosters, and can be a follow-up if the owner asks.
- files.create: `candy-code/tests/Host/SubmitInvisibleUnicodeTest.php`, `candy-code/tests/Chat/SubmitInvisibleUnicodeTest.php`
- files.modify: `candy-code/src/Chat.php` (`submit`), `candy-code/src/Host/SessionHost.php` (`submit`), `candy-code/lang/en.php` (`chat.notice.invisible_stripped`)
- tests: both new tests cover a bidi override removed with the notice, a plain prompt byte-identical, and a ZWJ emoji kept.
- docs/guards: none
- serialized: [chat:submit] [hot:candy-code/src/Host/SessionHost.php] [lang:chat.notice.invisible_stripped]
- deps: C-SEC-8.c
- phase: normal
- ruling: none
- risks: a pasted prompt the user meant to keep byte-exact (e.g. a bidi test case) is altered. The notice says so, and the user can attach the text as a file instead.

### C-SEC-8.e — `${keychain:<name>}` provider keys
- item: C-SEC-8 (sources: GAP-MOD-25)
- kind: gap · effort M
- approach:
  - Provider keys sit in plaintext in a 0600 config, and `ProviderFactory::resolveEnv()` (`src/Providers/ProviderFactory.php:450`) resolves only `${VAR}`.
  - Add `${keychain:<name>}`, resolved by a new `Keychain` that shells out to `secret-tool lookup service candy-code account <name>` on Linux or `security find-generic-password -s candy-code -a <name> -w` on macOS. It uses argv arrays only, through `ProcessContainment::spawnSpec`, and drains then reaps in-function. A short timeout is fine, since this is not an LLM call.
  - Resolution is memoised per process. A miss is a launch notice naming the entry, never a fatal.
- files.create: `candy-code/src/Support/Keychain.php`, `candy-code/tests/Support/KeychainTest.php`
- files.modify: `candy-code/src/Providers/ProviderFactory.php` (`resolveEnv`), `candy-code/docs/ENVIRONMENT.md` (interpolation section), `candy-code/lang/en.php` (`cli.keychain.*`)
- tests: the new test drives a spawn seam for hit, miss, missing binary and timeout, and checks that the argv never contains the secret. Modify `candy-code/tests/Providers/ProviderFactoryTest.php` (`${keychain:x}` resolves through the seam, `${VAR}` unchanged).
- docs/guards:
  - The child lifetime is short and synchronous. If `DescriptorInheritanceGuardTest` (`candy-code/tests/Support/DescriptorInheritanceGuardTest.php`) does not classify it `short`, add an `ACCOUNTED_FOR` row there. candy-code's sites are owned by that guard; `tools/check-child-lifetimes.php` excludes candy-code.
  - `EnvRosterDriftTest` is untouched.
- serialized: [docs:ENVIRONMENT.md] [lang:cli.keychain.]
- deps: none
- phase: normal
- ruling: none
- risks: Windows has no backend. `${keychain:}` there is a clear notice, not a silent empty key.

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
