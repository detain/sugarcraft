# design-tool.md — Tools, shell & editing (C-TOOL-1 … C-TOOL-7)

Design pass per `BRIEF-design.md`. All MODIFY paths verified on the live tree (HEAD `5e9195687`); all CREATE paths verified absent. Line numbers are as of that HEAD.

## Summary

| step id | title | effort | serialized tags | deps |
|---|---|---|---|---|
| C-TOOL-1.a | Background-shell two-part cursor | M | [hot:BackgroundShellRegistry.php] | none |
| C-TOOL-1.b | rg `--no-config` jail fix | S | none | none |
| C-TOOL-1.c | Format hook skips failed edits, never blesses stale | S | [runtime:settle] [docs:HOOKS.md] | none |
| C-TOOL-7.a | Background-shell registry nits (forgetHandles, test names, per-launch dir, sink-only cap) | M | [bootstrap:backgroundShells] [hot:BackgroundShellRegistry.php] | C-TOOL-1.a |
| C-TOOL-7.b | Ls bounded heap | S | none | none |
| C-TOOL-7.c | LSP auto-detect project-trust gate | S | [bootstrap:lspClient] [lang:settings.lspAutoDetect.] [docs:SETTINGS.md] | none |
| C-TOOL-7.d | JSON-output escaping of control/format chars | S | [headless] | none |
| C-TOOL-7.e | Bounded resample of all-malformed tool-call replies | S | [runtime:runBatch,runStreaming] | none |
| C-TOOL-2.a | WebFetch HTML→Markdown | M | none | none |
| C-TOOL-2.b | Pluggable search backends (SearXNG/Brave/Tavily/Exa) | M | [lang:settings.webSearchBackend.] [docs:SETTINGS.md,README.md,ENVIRONMENT.md] | none |
| C-TOOL-2.c | Anthropic server-side `web_search` backend | M | [hot:AnthropicMessagesWire.php] | C-TOOL-2.b |
| C-TOOL-2.d | Read PDFs: tool_result `document` block + pdftotext text | M | [runtime:settle,encodeResult,decodeResult] [hot:EngineBackend.php] [hot:AnthropicMessagesWire.php] | none |
| C-TOOL-2.e | User PDF attachments on Bedrock/Gemini/OpenAI-chat | M | [hot:AttachmentEncoding.php] [hot:OpenAIProvider.php] | none |
| C-TOOL-2.f | MCP images/PDF resources into the tool-result channel | S | [docs:MCP.md] | C-TOOL-2.d |
| C-TOOL-2.g | OpenAI Responses image/document tool results | S | none | C-PRV-2, C-TOOL-2.d |
| C-TOOL-3.a | Tool-tail slot store + PtyRun live progress | S | none | none |
| C-TOOL-3.b | TUI paints the live shell tail | M | [chat:applyBackendToolEvent,pumpLiveToolEvents,replaceToolRunningPlaceholder] [renderer:renderPendingToolCall] [lang:tui.tool.tail] | C-TOOL-3.a |
| C-TOOL-3.c | Session-owned background shells (serve/tabs isolation) | M | [hot:EngineBackend.php] [hot:BackgroundShellRegistry.php] | C-TOOL-1.a, C-TOOL-7.a |
| C-TOOL-3.d | `/shells` list/output/stop | M | [chat:handleShellsCommand(new),isReadOnlySafeCommand] [commands] [bootstrap:workspace] [lang:cmd.shells.,host.shells.] [docs:README.md,COMMANDS.md,ARCHITECTURE.md] | C-TOOL-3.c |
| C-TOOL-3.e | Web Processes section + `shells.*` methods | M | [protocol] | C-TOOL-3.c |
| C-TOOL-5.a | Opt-in read-before-write + UTF-8 BOM preservation | M | [lang:settings.requireReadBeforeWrite.] [docs:SETTINGS.md,README.md] | none |
| C-TOOL-5.b | Default formatter table (`formatAuto`) | M | [bootstrap:hooks] [lang:settings.formatAuto.] [docs:HOOKS.md,SETTINGS.md,README.md] | C-TOOL-1.c, C-TOOL-7.c |
| C-TOOL-5.c | LSP installer core (pinned, checksummed) | M | none | C-TOOL-7.c |
| C-TOOL-5.d | `candy-code lsp install/list` subcommand | S | [headless] [lang:cli.lsp.] [docs:README.md,ARCHITECTURE.md,MCP.md] | C-TOOL-5.c |
| C-TOOL-4.a | `sandbox` row in `candy-code doctor` (phase: normal) | S | [headless] [lang:cli.doctor.sandbox_] [docs:TROUBLESHOOTING.md] [hot:Bash.php] [hot:ReadPathCensusTest.php] | none |
| C-TOOL-4.b | SandboxBackend seam + macOS Seatbelt backend (LATE) | M | [headless] [workflows] [lang:settings.bashSandbox.,cli.doctor.sandbox_] [docs:PERMISSIONS.md,SETTINGS.md,README.md] [hot:Bash.php] [hot:PtyRun.php] [hot:ReadPathCensusTest.php] | C-TOOL-4.a |
| C-TOOL-4.c | bwrap hardening, CC-new#11 (LATE) | S | [lang:settings.bashSandboxNestedUserns.] [docs:PERMISSIONS.md,SETTINGS.md,README.md] [hot:LayeredSettings.php] | C-TOOL-4.b |
| C-TOOL-4.d | `sandboxScope`: jail hooks/lint/format/test runs (LATE) | M | [bootstrap:hooks] [lang:settings.sandboxScope.,sandbox.] [docs:HOOKS.md,PERMISSIONS.md,SETTINGS.md,README.md] [hot:ScriptHook.php] [hot:HookManager.php] [hot:LayeredSettings.php] | C-TOOL-4.b, C-TOOL-5.b |
| C-TOOL-4.e | Jail LSP + MCP stdio servers (LATE) | M | [bootstrap:lspClient,mcpClient] [lang:settings.sandboxScope.,sandbox.] [docs:MCP.md,PERMISSIONS.md,SETTINGS.md,README.md] [hot:McpClient.php] [hot:LspLauncher.php] [hot:MCP/StdioMcpServer.php] | C-TOOL-4.d, C-TOOL-5.c, C-TOOL-7.c, C-MCP-1.a, C-MCP-3.a |
| C-TOOL-4.f | Egress proxy core, new files only (LATE) | M | [hot:DescriptorInheritanceGuardTest.php] [hot:ReadPathCensusTest.php] | none |
| C-TOOL-4.g | `bashSandbox: "allowlist"` + `sandboxAllowedDomains` wiring (LATE) | M | [bootstrap:egressProxy(new),tools] [lang:settings.bashSandbox.,settings.sandboxAllowedDomains.] [docs:PERMISSIONS.md,SETTINGS.md,README.md] [hot:LaunchContext.php] [hot:LayeredSettings.php] | C-TOOL-4.f, C-TOOL-4.c |
| C-TOOL-4.h | Darwin process groups + wall fallback without GNU `timeout` (LATE) | M | [workflows] [docs:TROUBLESHOOTING.md] [hot:BackgroundShellRegistry.php] [hot:ProcessContainment.php] | C-TOOL-7.a, C-TOOL-3.c |
| C-TOOL-6.a | Browser/computer-use MCP presets, docs only (phase: normal) | S | [docs:MCP.md] | none |
| C-TOOL-6.b | NotebookEdit + Read shows `.ipynb` as cell pages (LATE) | M | [runtime:WRITE_CAPABLE_TOOL_NAMES] [catalog] [docs:README.md,ARCHITECTURE.md,AGENTS_AUTHORING.md,PERMISSIONS.md,SETTINGS.md] [hot:Read.php,ToolLimits.php,PermissionGate.php,PermissionRule.php,SafetyClassifier.php,ProtectFilesHook.php,TurnContextBlock.php,ReadPathCensusTest.php,tool-count word tests] | C-TOOL-2.d, C-TOOL-5.a |
| C-TOOL-6.c | Script runner core: jailed PHP child, fd-3 bridge (LATE) | M | [hot:DescriptorInheritanceGuardTest.php] | C-TOOL-4.b |
| C-TOOL-6.d | Script tool: nested calls through gate + hooks (LATE) | M | [runtime:WRITE_CAPABLE_TOOL_NAMES,executeSequentially,executeNested(new)] [catalog] [docs:README.md,ARCHITECTURE.md,AGENTS_AUTHORING.md,PERMISSIONS.md,SETTINGS.md] [hot:tool-count word tests] | C-TOOL-6.c |
| C-TOOL-6.e | User-tier script tools `~/.candy-code/tools/*.json` (LATE) | M | [bootstrap:userScriptTools(new)] [runtime:USER_TOOL_PREFIX(new),isWriteCapableTool] [lang:cli.usertools.] [docs:PLUGINS.md,PERMISSIONS.md] [hot:LaunchContext.php,PermissionGate.php,ReadPathCensusTest.php,Catalog/ToolDocGenerator.php] | C-TOOL-4.b |
| C-TOOL-6.f | Canvas tool core, HTML into session MediaStore (LATE) | M | [catalog] [docs:README.md,ARCHITECTURE.md,AGENTS_AUTHORING.md,PERMISSIONS.md,SETTINGS.md,MEDIA.md] [hot:MediaStore.php,RuntimeTest.php,tool-count word tests] | none |
| C-TOOL-6.g | Canvas web half: `MediaKind::Html`, sandboxed `/canvas/{token}`, CanvasCard (LATE) | M | [protocol] [docs:MEDIA.md,SERVER.md] [hot:TranscriptProjector.php,Server/Server.php,Cli/Serve.php,web ToolCard.vue,web protocol/auth.ts] | C-TOOL-6.f, C-WEB-1.d |
| C-TOOL-6.h | Client-hosted tools core: `client__*` proxies over the turn channel (LATE) | M | [runtime:CLIENT_TOOL_PREFIX,isWriteCapableTool] [docs:PERMISSIONS.md] [hot:EngineBackend.php,ChildChannel.php,PermissionGate.php,SessionHost.php,TurnRunner.php,Catalog/ToolDocGenerator.php,RuntimeTest.php] | C-TOOL-6.e |
| C-TOOL-6.i | Client tools: `tools.client.*` protocol, `server.clientTools` gate, web store + sample tool (LATE) | M | [protocol] [lang:settings.server.clientTools.] [docs:SETTINGS.md,README.md,SERVER.md] [hot:ServerContext.php,ServerConfig.php,web stores/session.ts] | C-TOOL-6.h |
| C-TOOL-6.j | PTY relay core for interactive background shells (LATE) | M | [hot:BackgroundShellRegistry.php,DescriptorInheritanceGuardTest.php,ReadPathCensusTest.php] | C-TOOL-3.c, C-TOOL-4.h |
| C-TOOL-6.k | ShellSend tool + interactive background Bash + `/shells send` (LATE) | M | [runtime:WRITE_CAPABLE_TOOL_NAMES] [catalog] [lang:cmd.shells.,host.shells.send.] [docs:README.md,ARCHITECTURE.md,AGENTS_AUTHORING.md,PERMISSIONS.md,SETTINGS.md,COMMANDS.md] [hot:Bash.php,PermissionGate.php,tool-count word tests] | C-TOOL-6.j, C-TOOL-3.d |
| C-TOOL-6.l | Web terminal input: `shells.input` + polling terminal in Processes (LATE) | M | [protocol] [docs:SERVER.md] [hot:ServerContext.php] | C-TOOL-6.k, C-TOOL-3.e |

Totals: 44 steps. 13 S, 31 M, 0 L. 18 LATE (C-TOOL-4.b–h, C-TOOL-6.b–l); 26 normal (including the two LATE-item slivers C-TOOL-4.a and C-TOOL-6.a).

## Steps

### C-TOOL-1.a — Background-shell two-part cursor
- item: C-TOOL-1 (sources: SHL-1-P1)
- kind: fix · MAJOR · effort M
- approach: Root cause `candy-code/src/Background/BackgroundShellRegistry.php:503-546` (`read()`): one global cursor over stdout‖stderr, split as `stdoutOffset=min(cursor,stdoutSize)` / `stderrOffset=cursor-stdoutSize`. Valid only while stdout is frozen; when stdout grows after stderr was read, stdout bytes are skipped and stderr is replayed (reproduced in plan-review-p2). The docblock's "identical arithmetic while both streams grow" (:540) is false. Fix: a two-part cursor, one offset per stream, each advancing in its own file. The wire form is an opaque token `"<out>.<err>"` (ShellOutput `cursor` becomes a string). A bare integer is still accepted and decoded with today's arithmetic, so a cursor threaded before the upgrade keeps working on a frozen stdout. Default fresh-read tail-wins is kept per stream. The shrink remap (`lastStdoutSize`) collapses into the per-stream rule. ShellOutput paints `[cursor: 150.50]`. Rejected alternative: merging the streams with `2>&1`, because ShellOutput returns stdout and stderr separately.
- files.create: none
- files.modify: `candy-code/src/Background/BackgroundShellRegistry.php` (read(), its docblock, class docblock cursor paragraph), `candy-code/src/Tools/BuiltIn/ShellOutput.php` (class docblock, inputSchema `cursor`, execute cursor parse, paint `[cursor: …]`), `candy-code/src/Tools/BuiltIn/Bash.php` (only if its background-start answer tells the model the cursor shape)
- tests: modify `candy-code/tests/Sessions/BackgroundShellRegistryTest.php` (add `testInterleavedGrowthDeliversEveryByteExactlyOnce`: a live shell writes out/err/sleep/out/err with threaded reads, and each stream's concatenation equals its file bytes; keep `testAStdoutThatShrinksAloneDoesNotStrandTheUnreadStderr` green), `candy-code/tests/Tools/ShellOutputTest.php` (schema and paint pins; a legacy-int cursor decode), `candy-code/tests/Tools/BashBackgroundTest.php` (threaded-cursor flows)
- docs/guards: none. `tools/gen-tool-docs.php` renders names and glosses only, and the gloss is unchanged.
- serialized: [hot:candy-code/src/Background/BackgroundShellRegistry.php]
- deps: none
- phase: normal
- ruling: none
- risks: a token-shaped cursor is a schema change the model must re-learn mid-session; the integer-compat decode covers resumed sessions.

### C-TOOL-1.b — rg `--no-config` jail fix
- item: C-TOOL-1 (sources: SHL-4-P1)
- kind: fix · MAJOR · effort S
- approach: Root cause `candy-code/src/Tools/BuiltIn/Grep.php:972`: the rg command line has no `--no-config`, and `ProcessContainment::scrubbedEnv()` strips only secret-shaped names, so the operator's `RIPGREP_CONFIG_PATH` reaches rg. A `--follow` line in that file then walks a jail-internal symlink out of the root, and the hit path is still jail-prefixed, so `hitPath()` cannot tell (reproduced). The same leak breaks engine parity (`--smart-case`, `--max-columns`, `--type-not`). Fix: `--no-config` as the first rg flag. Also drop `RIPGREP_CONFIG_PATH` from the env handed to `runCaptured`, as belt and braces.
- files.create: none
- files.modify: `candy-code/src/Tools/BuiltIn/Grep.php` (rg command build ~:972, the "no -L/--follow" docblock ~:920)
- tests: modify `candy-code/tests/Tools/BuiltIn/GrepOptionInjectionTest.php` (add `testRipgrepConfigFileCannotWidenTheWalk`: putenv a temp rc holding `--follow`, a jail with a symlink to an outside dir, assert no hit, skip when rg is absent; plus a pin that the built command carries `--no-config`)
- docs/guards: none (the description does not enumerate rg flags)
- serialized: none
- deps: none
- phase: normal
- ruling: none
- risks: rg older than 0.9 lacks `--no-config`; the hosts in scope all ship rg ≥ 11.

### C-TOOL-1.c — Format hook skips failed edits, never blesses stale
- item: C-TOOL-1 (sources: EDT-1-P1)
- kind: fix · MAJOR · effort S
- approach: Root cause `candy-code/src/Hooks/BuiltIn/PostEditFormatHook.php` execute() (:97-120). It formats failed edits too ("exactly as the lint hook reasons") and then calls `$this->ledger?->record($abs, null)`, which stores the CURRENT hash. `HookContext` (`candy-code/src/Hooks/HookContext.php:21-31`) has no failure bit, and only gate refusals skip PostToolUse. So when a stale Write is refused, the formatter rewrites the user's file and blesses it, and the model's retry then overwrites the user's edits. Fix, two independent guards. (1) `HookContext` gains `public bool $toolFailed = false` plus `withToolFailed()`; `Runtime::settle()` (:3410) passes `$result->isError()`; the format hook returns early when it is set. (2) The hook records a kept rewrite only when the ledger's pre-format `staleness($abs, $before)` was null, so it never blesses an already-stale file. The lint hook keeps linting failed edits, because lint reads and format writes. The script-hook stdin payload is NOT widened.
- files.create: none
- files.modify: `candy-code/src/Hooks/HookContext.php` (ctor field, withHeartbeat/withToolInput/withToolOutput copies, new withToolFailed), `candy-code/src/Runtime.php` (settle() ~:3410), `candy-code/src/Hooks/BuiltIn/PostEditFormatHook.php` (execute + docblock), `candy-code/docs/HOOKS.md` ("Post-edit format" section ~:1070-1110: failed edits are not formatted; a stale file is never recorded)
- tests: modify `candy-code/tests/Hooks/BuiltIn/PostEditFormatHookTest.php` (`testAFailedEditIsNeitherFormattedNorRecorded`, `testAStaleFileIsNotBlessedByTheFormatter`), `candy-code/tests/Hooks/HookContextTest.php` (field round-trip through every `with*`), `candy-code/tests/Hooks/PostToolUseAttributionTest.php` (a refused Write reaches PostToolUse with `toolFailed` true)
- docs/guards: `docs/HOOKS.md` sentence in-step; `HookEnvironmentDocumentationDriftTest` is unaffected because the payload is unchanged
- serialized: [runtime:settle] [docs:HOOKS.md]
- deps: none
- phase: normal
- ruling: none
- risks: the other `new HookContext(` sites (7 in src/) rely on the defaulted trailing param; a positional caller would break, so use named args.

### C-TOOL-7.a — Background-shell registry nits
- item: C-TOOL-7 (sources: SHL-1-P2, SHL-1-P3, SHL-1-P4)
- kind: fix · MINOR · effort M
- approach: Four fixes on one file family, kept in one lane so the registry is touched once.
  - (1) Wire, don't delete, the dead `forgetHandles()` (`candy-code/src/Background/BackgroundShellRegistry.php:692`; zero callers). The registry remembers the pid that owns `$handles`. On entry to `start/status/read/stop/teardown`, a process whose `getmypid()` differs (a forked tool or turn child) calls `$this->forgetHandles($this->localIds())`, so a child never `proc_get_status`/closes handles it did not spawn. Rewrite both docblocks (:109-114, :684-690) to say exactly that.
  - (2) Rename the lying tests: `tests/Sessions/BackgroundShellRegistryTest.php:388` `…FifteenHundredSeconds` (it asserts 1800) and `:473` `…SixtyFirstStart…` (`MAX_SHELLS = 64`).
  - (3) Per-launch shell dir: `Bootstrap::backgroundShells()` (`candy-code/src/Cli/Bootstrap.php:8405`) mints `forSession('main')` for every launch on the machine. Use `forSession('launch-' . getmypid())` (through the existing `sanitizeSessionId`), and have `sweepCorpseFiles()` also remove sibling `launch-*` dirs whose pid is dead and whose mtime is past `CORPSE_RETENTION_SECONDS`.
  - (4) The `ulimit -f` cap (`buildWrapper()` :371-387) fells any job that writes a >64 MiB file. Cap the SINKS, not the job: inside the inner `bash -c`, route stdout and stderr through process-substitution recorders `>(ulimit -f N; exec cat > "$OUT")`. The cap is scoped to the recorders, and the trailer `wait`s for them before writing the exit file. The OUTPUT_LIMIT narrative is then derived from sink size ≥ cap at settle. Measured fallback, if bash's procsub `wait` proves unreliable: keep `ulimit -f` and state the cap in Bash's schema sentence (`Bash.php` ~:268) and in ShellOutput's exit note.
- files.create: none
- files.modify: `candy-code/src/Background/BackgroundShellRegistry.php` (class docblock OUTPUT/fork paragraphs, start(), buildWrapper(), sweepCorpseFiles(), forgetHandles() docblock, observe exit narrative), `candy-code/src/Cli/Bootstrap.php` (backgroundShells() :8396-8407 + docblock), `candy-code/src/Tools/BuiltIn/Bash.php` (background schema sentence ~:268 if the fallback is taken), `candy-code/src/Tools/BuiltIn/ShellOutput.php` (exit-note wording)
- tests: modify `candy-code/tests/Sessions/BackgroundShellRegistryTest.php` (two renames; add `testAForkedChildDropsTheParentsHandles` (pcntl-gated: the child's `localIds()` is empty after first use), `testABigFileTheCommandWritesIsNotCapped` (65 MiB `dd` to a non-sink path succeeds), `testTheSinkCapStillFellsARunawayLog`, `testTwoLaunchesKeepSeparateShellDirs`), `candy-code/tests/Tools/BashBackgroundTest.php` (schema prose pin if it moves)
- docs/guards: no new proc_open token (the recorders live inside the existing wrapper); re-run `candy-code/tests/Support/DescriptorInheritanceGuardTest.php`
- serialized: [bootstrap:backgroundShells] [hot:candy-code/src/Background/BackgroundShellRegistry.php]
- deps: C-TOOL-1.a (same file; sequence)
- phase: normal
- ruling: none
- risks: procsub semantics differ on bash 3.2 (macOS stock); the lane must measure there or take the documented fallback for that host.

### C-TOOL-7.b — Ls bounded heap
- item: C-TOOL-7 (sources: SHL-2-P1)
- kind: fix · MINOR · effort S
- approach: Root cause `candy-code/src/Tools/BuiltIn/Ls.php:313-347` (`collect()`): every entry is `is_dir`'d, ignore-checked and `realpath`'d into `$entries`, then `usort`ed, and the cap applies after. The comment "the walk IS the cost being bounded" (:306) is false. Fix: keep a bounded max-heap of the `maxEntries` smallest basenames plus a total counter, and report "N more entries not shown" with the same note shape. Defer `realpath` and the jail filter until after heap admission where possible. The listing order is unchanged.
- files.create: none
- files.modify: `candy-code/src/Tools/BuiltIn/Ls.php` (collect() + its docblock, the truncation note)
- tests: modify `candy-code/tests/Tools/BuiltIn/LsTest.php` (`testHugeDirectoryKeepsTheFirstNByNameWithoutHoldingAll`: a 20k-entry fixture, a `memory_get_peak_usage` bound, the same first-N as a full sort, and the total count is right)
- docs/guards: none
- serialized: none
- deps: none
- phase: normal
- ruling: none
- risks: the ignored/escaped counts must still be exact, so they stay full-walk counters (cheap integers).

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

### C-TOOL-7.d — JSON-output escaping of control/format chars
- item: C-TOOL-7 (sources: CC-new#12 JSON-output escaping)
- kind: fix · NIT · effort S
- approach: `NonInteractive::encodeDocument()` (`candy-code/src/Cli/NonInteractive.php:1341-1354`) is the single encoder for `-p --output-format json`, `stream-json` (`StreamJsonEmitter.php:175`) and the subcommand JSON documents. It encodes with `JSON_UNESCAPED_UNICODE`, so DEL, C1 (U+0080–U+009F) and Unicode Cf format characters (bidi overrides U+202A–U+202E, U+2066–U+2069, zero-width U+200B–U+200F, U+FEFF, U+00AD, U+061C…) reach a consumer's terminal raw. Fix: a post-pass that rewrites those code points as `\uXXXX`. That is safe on the whole encoded string because json_encode output only carries them inside strings, and decoding is byte-identical. The two direct `json_encode` sites in `Subcommands.php` (:1516, :2419) are checked and routed through the encoder if they emit contract documents.
- files.create: `candy-code/tests/Cli/EncodeDocumentEscapingTest.php`
- files.modify: `candy-code/src/Cli/NonInteractive.php` (encodeDocument + docblock), `candy-code/src/Cli/Subcommands.php` (only if the two direct sites are contract documents)
- tests: create `candy-code/tests/Cli/EncodeDocumentEscapingTest.php` (no raw DEL/C1/Cf bytes in output; `json_decode` round-trip identity; plain ASCII and CJK unchanged); modify `candy-code/tests/Cli/StreamJsonEmitterTest.php` (a bidi-override payload arrives escaped)
- docs/guards: none (`ReadmeJsonErrorContractDriftTest` unaffected: same document shape)
- serialized: [headless]
- deps: none
- phase: normal
- ruling: none
- risks: consumers that grep raw output for an emoji or CJK text are unaffected; only Cf/C1 change.

### C-TOOL-7.e — Bounded resample of all-malformed tool-call replies
- item: C-TOOL-7 (sources: CC-new#12 unparseable tool-call retry)
- kind: fix · NIT · effort S
- approach: Today a call whose arguments did not decode becomes an A11 error result (`Runtime::malformedArguments()` :4183, `failure()`), costing a whole step. Mirror Claude Code: in `Runtime::runBatch()` (:1899) and `runStreaming()`, when a reply has ≥1 tool call, every call carries `argumentsError()`, and no user-visible text was streamed, discard the reply and re-issue the SAME request, at most `MAX_MALFORMED_RESAMPLES = 2` times. The usage of discarded attempts is still summed into the step (billing honest). A one-line notice goes through the channel `TransientFailure::announceRateLimit` uses. After the bound, today's A11 path runs unchanged. This is a count bound, not a timeout (E646-clean).
- files.create: none
- files.modify: `candy-code/src/Runtime.php` (runBatch, runStreaming, new const + helper)
- tests: modify `candy-code/tests/RuntimeMalformedToolArgumentsTest.php` (`testAnAllMalformedReplyIsResampledAndTheGoodReplyRuns`, `testAfterTwoResamplesTheA11ErrorResultStands`, `testAReplyWithStreamedTextIsNotResampled`, usage summed across attempts)
- docs/guards: none
- serialized: [runtime:runBatch,runStreaming]
- deps: none
- phase: normal
- ruling: none
- risks: a deterministic model at temperature 0 repeats the same broken call; the bound caps the waste at two requests.

### C-TOOL-2.a — WebFetch HTML→Markdown
- item: C-TOOL-2 (sources: GAP-TL-12)
- kind: gap · effort M
- approach: `WebFetch` returns 2xx bodies verbatim (`candy-code/src/Tools/BuiltIn/WebFetch.php:268` description, :415 body path). Add `HtmlToMarkdown`, which uses ext-dom `DOMDocument` + libxml (no network, no entity loading). It drops script/style/nav/svg/iframe/noscript and keeps headings, links (absolute-resolved against the final URL), lists, code/pre, blockquote and simple tables. It applies when Content-Type is text/html or xhtml. New arg `format: "markdown"|"raw"` (default markdown); the 64 KiB model cap applies after conversion. The untrusted-content framing is unchanged. If ext-dom is absent (it is not in `composer.json` require), the tool falls back to raw with a one-line note, and `ext-dom` joins `suggest`.
- files.create: `candy-code/src/Tools/Web/HtmlToMarkdown.php`, `candy-code/tests/Tools/Web/HtmlToMarkdownTest.php`, `candy-code/tests/Tools/WebFetchMarkdownTest.php`
- files.modify: `candy-code/src/Tools/BuiltIn/WebFetch.php` (description, inputSchema, execute body path), `candy-code/composer.json` (`suggest.ext-dom`)
- tests: create the two above (converter fixtures: nested lists, relative links, a script-injection fixture stays inert text; a WebFetch loopback fixture with format markdown/raw); modify `candy-code/tests/Tools/BuiltInToolTest.php` (`testWebFetchToolHasCorrectNameAndDescription` description pin)
- docs/guards: none (gloss unchanged); new test files are listed for the weld
- serialized: none
- deps: none
- phase: normal
- ruling: none
- risks: hostile HTML is a libxml parser input; use `LIBXML_NONET | LIBXML_NOERROR` and a size cap before parse.

### C-TOOL-2.b — Pluggable search backends
- item: C-TOOL-2 (sources: GAP-TL-13)
- kind: gap · effort M
- approach: `WebSearch` (`candy-code/src/Tools/BuiltIn/WebSearch.php` ctor :90-115) talks only to a SearXNG endpoint (`CANDY_CODE_SEARCH_ENDPOINT` / `webSearchEndpoint`) and has no default. Extract a `SearchBackend` interface with a `SearxngBackend` (today's code, moved), plus `BraveBackend`, `TavilyBackend` and `ExaBackend`, each over fixed https hosts with an API key from env (`BRAVE_SEARCH_API_KEY`, `TAVILY_API_KEY`, `EXA_API_KEY`). The backend is chosen by a new user-tier setting `webSearchBackend` (enum `searxng|brave|tavily|exa`; default `searxng`, so behaviour is unchanged). `C-TOOL-2.c` adds `anthropic` to the enum. WebSearch keeps constructing itself (Bootstrap, Chat and `/websearch` construction sites are untouched); the unconfigured refusal names the chosen backend's missing key. Results are normalised to one `SearchHit` shape, so the digest is backend-agnostic. Upstream dials reuse WebFetch's pinned resolver and blocklist.
- files.create: `candy-code/src/Tools/Web/Search/SearchBackend.php`, `candy-code/src/Tools/Web/Search/SearchHit.php`, `candy-code/src/Tools/Web/Search/SearxngBackend.php`, `candy-code/src/Tools/Web/Search/BraveBackend.php`, `candy-code/src/Tools/Web/Search/TavilyBackend.php`, `candy-code/src/Tools/Web/Search/ExaBackend.php`, `candy-code/src/Tools/Web/Search/SearchBackends.php` (factory), `candy-code/tests/Tools/Web/Search/SearchBackendsTest.php`, `candy-code/tests/Tools/Web/Search/HostedBackendShapesTest.php`
- files.modify: `candy-code/src/Tools/BuiltIn/WebSearch.php` (ctor, execute, unconfiguredRefusal), `candy-code/src/Tools/ToolLimits.php` (`WEB_SEARCH_BACKEND_KEY`), `candy-code/src/Config/Settings/Definitions/ToolSettings.php` (definition beside `webSearchEndpoint` :262-275, user tier), `candy-code/lang/en.php`, `candy-code/README.md` + `candy-code/docs/SETTINGS.md` (via `php tools/gen-settings-doc.php --write`), `candy-code/docs/ENVIRONMENT.md` + README env table (three key rows)
- tests: create the two above (fake transport per backend: request shape, key header, response→SearchHit mapping, missing-key refusal); modify `candy-code/tests/Tools/WebSearchToolTest.php`, `candy-code/tests/Tools/WebSearchEndpointSettingTest.php` (default stays searxng)
- docs/guards: `EnvRosterDriftTest` (three env vars, both directions), `SettingsSchemaDocDriftTest`, `TrustKeyDocumentationDriftTest` (README user-tier key enumeration); verify `ProcessContainment::isSecretEnvName()` strips the three keys from child envs (add a pin in `tests/Cli/BootstrapSecretEnvAllowlistTest.php` if not)
- serialized: [lang:settings.webSearchBackend.] [docs:SETTINGS.md] [docs:README.md] [docs:ENVIRONMENT.md]
- deps: none
- phase: normal
- ruling: none
- risks: hosted APIs change response shapes; keep each mapper tolerant and pinned by a recorded-fixture test.

### C-TOOL-2.c — Anthropic server-side `web_search` backend
- item: C-TOOL-2 (sources: GAP-TL-13 (Anthropic leg))
- kind: gap · effort M
- approach: Add `anthropic` to `webSearchBackend`. When it is chosen AND the provider is the direct Anthropic wire, `formatAnthropicTools()` (`candy-code/src/Providers/Concerns/AnthropicMessagesWire.php` ~:547) swaps the local `WebSearch` definition for the server tool `{type: "web_search_20250305", name: "web_search", max_uses: webSearchMaxResults}`. Search then runs provider-side, inside the same reply.
  - Parse (batch and stream, ~:1019 block switch): `server_tool_use` / `web_search_tool_result` blocks are never surfaced as client tool calls. Result URLs become a UI-only "Sources" notice.
  - `pause_turn`: continue the step by resending with the partial assistant content verbatim (bounded continuation count, not a timeout).
  - Replay: history replays the text only. That is valid because candy-code stores no citation blocks.
  - Spend: `usage.server_tool_use.web_search_requests` is recorded on `Usage` as an unpriced extra, so spend reads `≥`.
  - Any other provider with `anthropic` selected: WebSearch refuses with a named reason. `/websearch` explains that it needs an HTTP backend.
- files.create: `candy-code/tests/Providers/AnthropicServerWebSearchTest.php`
- files.modify: `candy-code/src/Providers/Concerns/AnthropicMessagesWire.php` (formatAnthropicTools, response/stream parse, pause_turn continuation), `candy-code/src/Providers/AnthropicProvider.php` (capability method), `candy-code/src/Tools/BuiltIn/WebSearch.php` (anthropic arm refusal), `candy-code/src/Tools/Web/Search/SearchBackends.php`, `candy-code/src/Config/Settings/Definitions/ToolSettings.php` (enum value), `candy-code/src/Usage.php` (server-tool request count, unpriced), `candy-code/docs/SETTINGS.md` (regen)
- tests: create the file above (tool swap only under backend=anthropic on AnthropicProvider; server blocks never become ToolCalls; pause_turn continuation; usage count recorded; replay is text-only); modify `candy-code/tests/Providers/PromptPrefixByteStabilityTest.php` (tool-list carve-out under the anthropic backend)
- docs/guards: `SettingsSchemaDocDriftTest`
- serialized: [hot:candy-code/src/Providers/Concerns/AnthropicMessagesWire.php] [docs:SETTINGS.md]
- deps: C-TOOL-2.b
- phase: normal
- ruling: none
- risks: per-search billing is not in the price table; spend reads `≥` until a price row lands (state it in the notice).

### C-TOOL-2.d — Read PDFs: tool_result `document` block + pdftotext text
- item: C-TOOL-2 (sources: GAP-TL-20, SHL-3-P1, p2-miss: SHL-3 PDF as document, k2-sum TL-20 downgrade)
- kind: gap · effort M
- approach: Read returns only a notice for a PDF (`candy-code/src/Tools/BuiltIn/Read.php:750-763`). Its "mention the file (@path)" advice is a road the model cannot take, because @-mentions resolve only on user prompts. Mirror the image path (SHL-3 T2), split across two legs.
  - **Document leg:**
    - `Tools\ToolResult` and `Messages\ToolResultMessage` gain `documentBytes` (+name). Read carries in-cap PDFs (`FileMentions::DOCUMENT_MAX_BYTES`, and a tighter tool-result byte budget const).
    - `AnthropicMessagesWire::toolResultDocument()` emits a `document` block inside `tool_result` when `supportsDocuments()`, not an error, and the bytes sniff `%PDF-`. It shares an encoder with `AttachmentEncoding`'s user-attachment leg (:89).
    - The fields thread through the same codecs as `imageBytes`: `Runtime::settle()` (~:3531-3562), `Runtime::encodeResult/decodeResult` (:3823/:3853), `EngineBackend::runCompleteInChild` (:4898, ~:5046-5056) and `settleFromResultFrame` (:5247, ~:5304-5321).
  - **Text leg:** when `DetectsCapabilities::capabilityPresent('pdftotext')` is true, Read runs `pdftotext -layout <path> -` through the existing `CapturesProcessOutput::runCaptured` helper (no new proc_open token) and pages the text with the existing offset/limit args. The notice wording is fixed: no "@-mention" advice to the model.
  - Gemini and Bedrock wires have no tool calling (`supportsFunctionCalling()` false), so tool-result media is moot there. OpenAI chat `role: tool` stays the documented decline; see C-TOOL-2.g for Responses.
- files.create: `candy-code/tests/Providers/ToolResultDocumentBlocksTest.php`, `candy-code/tests/Tools/BuiltIn/ReadPdfTextTest.php`
- files.modify: `candy-code/src/Tools/BuiltIn/Read.php` (PDF branch + description), `candy-code/src/Tools/ToolResult.php`, `candy-code/src/Messages/ToolResultMessage.php` (field, withToolCallId copy), `candy-code/src/Runtime.php` (settle, encodeResult, decodeResult), `candy-code/src/Backend/EngineBackend.php` (runCompleteInChild message codec, settleFromResultFrame), `candy-code/src/Providers/Concerns/AnthropicMessagesWire.php` (anthropicBlocks tool_result arm, new toolResultDocument), `candy-code/src/Providers/AttachmentEncoding.php` (shared `anthropicDocument()` helper), `candy-code/docs/MEDIA.md` (Read-PDF paragraph)
- tests: create the two above (supportsDocuments true/false, cap edge, is_error stays text, `%PDF-` sniff; pdftotext arm with an injected capability seam, skipped when the binary is absent); modify `candy-code/tests/Tools/BuiltIn/ReadBinaryScanTest.php` (PDF polarity flip), `candy-code/tests/Tools/ToolResultTest.php`, `candy-code/tests/Providers/PromptStabilityTest.php` (document-bearing prompt pin)
- docs/guards: `docs/MEDIA.md` in-step; Read's gloss is unchanged, so no gen-tool-docs
- serialized: [runtime:settle,encodeResult,decodeResult] [hot:candy-code/src/Backend/EngineBackend.php] [hot:candy-code/src/Providers/Concerns/AnthropicMessagesWire.php]
- deps: none
- phase: normal
- ruling: none
- risks: a 20 MiB PDF in history re-sends every turn; the tool-result byte budget must be far below the user-attachment cap (lane decision, recorded in the const docblock).

### C-TOOL-2.e — User PDF attachments on Bedrock/Gemini/OpenAI-chat
- item: C-TOOL-2 (sources: p1-miss: documents on non-Anthropic wires)
- kind: gap · effort M
- approach: CTX-2 declined `document` blocks outside the Anthropic family (`candy-code/src/Providers/AttachmentEncoding.php` class docblock; `BedrockProvider::supportsDocuments()` :445 returns false; `GeminiProvider::supportsDocuments()` :259 false). Add each wire's native shape:
  - Bedrock Converse: `document{format:"pdf", name, source:{bytes}}`, with the name sanitised to Converse's charset.
  - Gemini/Vertex-Gemini: `inlineData{mimeType:"application/pdf"}`.
  - OpenAI chat/completions: `{type:"file", file:{filename, file_data:"data:application/pdf;base64,…"}}`.
  Flip `supportsDocuments()`:
  - Bedrock: the Claude-3+/Nova family allow-list it already uses for vision (:454).
  - GeminiProvider and Vertex's Gemini arm: true.
  - OpenAIProvider: official OpenAI vision models only.
  - SGLang and Custom stay false; they keep the "named, never dropped" decline.
  Bedrock first, as the plan-review asked.
- files.create: `candy-code/tests/Providers/DocumentAttachmentWireTest.php`
- files.modify: `candy-code/src/Providers/AttachmentEncoding.php` (bedrockBlocks, geminiParts, openAiContent, class docblock), `candy-code/src/Providers/BedrockProvider.php` (supportsDocuments), `candy-code/src/Providers/GeminiProvider.php` (supportsDocuments), `candy-code/src/Providers/VertexProvider.php` (supportsDocuments :411, Gemini arm), `candy-code/src/Providers/OpenAIProvider.php` (supportsDocuments :253), `candy-code/docs/ADDING_A_PROVIDER.md` (capability notes)
- tests: create the file above (one shape pin per wire; declined wires still name the document); modify `candy-code/tests/Providers/AttachmentWireTest.php`
- docs/guards: `docs/ADDING_A_PROVIDER.md` in-step
- serialized: [hot:candy-code/src/Providers/AttachmentEncoding.php] [hot:candy-code/src/Providers/OpenAIProvider.php]
- deps: none
- phase: normal
- ruling: none
- risks: OpenAIProvider is also C-PRV-2's file; schedule them in different waves.

### C-TOOL-2.f — MCP images/PDF resources into the tool-result channel
- item: C-TOOL-2 (sources: GAP-MCP-14, k2-sum MCP-14)
- kind: gap · effort S
- approach: `McpToolBridge::replyResult()` (`candy-code/src/Tools/McpToolBridge.php:600`) renders image/audio parts as size text (`renderInlineBytes` :839). Change: decode the FIRST in-cap image part whose bytes sniff (`FileMentions::sniffImage`) into the `ToolResult` image channel, keeping the size line for every part so non-vision wires lose nothing. An embedded `application/pdf` resource blob goes to `documentBytes` (from C-TOOL-2.d). Audio stays described. `McpCallTool` shares `replyResult`, so it inherits this with no change (verify).
- files.create: `candy-code/tests/Tools/McpToolBridgeImageChannelTest.php`
- files.modify: `candy-code/src/Tools/McpToolBridge.php` (replyResult, renderInlineBytes), `candy-code/docs/MCP.md` (output section)
- tests: create the file above (first image wins; an oversize image stays text; a PDF resource goes to the document channel; audio unchanged); modify `candy-code/tests/Tools/McpToolBridgeTest.php`, `candy-code/tests/Tools/McpToolBridgeResultCapTest.php`
- docs/guards: `docs/MCP.md` in-step; `DocFigureProseDriftTest` MCP arms if a counted sentence moves
- serialized: [docs:MCP.md]
- deps: C-TOOL-2.d
- phase: normal
- ruling: none
- risks: a server returning many screenshots per call sends only the first; state it in the caption.

### C-TOOL-2.g — OpenAI Responses image/document tool results
- item: C-TOOL-2 (sources: GAP-TL-19 (wire half))
- kind: gap · effort S
- approach: NARROWED (plan review): reuse C-PRV-2.b's image arm and add only the `input_file` (document) parts; no hedges, the wire file is `candy-code/src/Providers/Concerns/OpenAiResponsesWire.php`. Once C-PRV-2's Responses wire exists, `function_call_output.output` can be an array of `input_text` + `input_image` (data URL) + `input_file` parts. Emit them when the tool result carries `imageBytes` / `documentBytes` and the provider `supportsVision()` / `supportsDocuments()`, under the same decision chain as `AnthropicMessagesWire::toolResultImage()`. Chat-completions keeps the recorded decline (the `toolResultImage` docblock).
- files.create: `candy-code/tests/Providers/ResponsesToolResultMediaTest.php`
- files.modify: the Responses wire file C-PRV-2 creates (expected `candy-code/src/Providers/Concerns/OpenAiResponsesWire.php`; confirm against design-prv.md), `candy-code/src/Providers/AttachmentEncoding.php` (shared data-URL helper), `candy-code/docs/MEDIA.md` (Responses document parts)
- tests: create the file above (image part, file part, non-vision model keeps the caption, is_error stays text)
- docs/guards: `docs/MEDIA.md` one line
- serialized: [hot:candy-code/src/Providers/AttachmentEncoding.php]
- deps: C-PRV-2 (Responses wire), C-TOOL-2.d, C-PRV-2.b (its image arm)
- phase: normal
- ruling: none
- risks: path unknown until C-PRV-2's design lands.

### C-TOOL-3.a — Tool-tail slot store + PtyRun live progress
- item: C-TOOL-3 (sources: GAP-SH-08, k2-dorm `ToolProgress` unpainted)
- kind: dormant-wire · effort S
- approach: Core half of the live tail.
  - (1) `ToolTailSlots`: a process-global store shaped like `candy-code/src/Media/PreviewSlots.php` (MAX_SLOTS backstop). It holds the newest tail text, `truncated` and `seq` per toolCallId, and drops out-of-order seq. It is never serialized (the same leak law).
  - (2) PtyRun streams progress. It implements `AcceptsToolProgress` (`candy-code/src/Tools/AcceptsToolProgress.php`), which EngineBackend::turnTools already binds (:3218). `PtyDriver::run()` (`candy-code/src/Support/PtyDriver.php:93`) gains `?\Closure $onChunk`, fed from `collectWindow()`. PtyRun strips ANSI from each chunk with `\SugarCraft\Core\Util\Ansi::strip()` before feeding a `ShellTailRing` the way Bash feeds one (`Bash.php:531-541`). Bash's ring does no stripping of its own; the renderer sanitises at paint time (C-TOOL-3.b).
- files.create: `candy-code/src/Tui/ToolTailSlots.php`, `candy-code/tests/Tui/ToolTailSlotsTest.php`, `candy-code/tests/Tools/PtyRunToolProgressTest.php`
- files.modify: `candy-code/src/Tools/BuiltIn/PtyRun.php` (implements, withToolProgressEmitter, drive()), `candy-code/src/Support/PtyDriver.php` (run, collectWindow)
- tests: create the three above (slot bound, seq ordering, clear; PtyRun emits frames, gated like the existing pty tests)
- docs/guards: none
- serialized: none
- deps: none
- phase: normal
- ruling: none
- risks: PtyRun output is screen-shaped; the tail is a best-effort plain-text view, not the final screen.

### C-TOOL-3.b — TUI paints the live shell tail
- item: C-TOOL-3 (sources: GAP-SH-08, k2-sum live defect, k2-dorm)
- kind: dormant-wire · effort M
- approach: `Chat.php` records `ToolProgress` and discards it in both folds: `applyBackendToolEvent()` :3702 (settled queue) and `pumpLiveToolEvents()` :4230 (live pump; the comment says "The TUI paints no live tail this wave"). Both arms call `ToolTailSlots::set()`, and `replaceToolRunningPlaceholder()` (:4345, beside `PreviewSlots::clear` :4349) clears the slot. `Renderer::renderPendingToolCall()` (`candy-code/src/Renderer.php:5623`) paints the last N (6) tail lines under the running row, after `previewLines()`. The lines are dim, control and bidi sanitised through the renderer's existing frame sanitiser, display-cell clipped with `clipWithEllipsis`, one line per row (render invariants), with a `… N earlier lines` caption when truncated. The stale comments are rewritten.
- files.create: `candy-code/tests/Renderer/RunningToolTailTest.php`, `candy-code/tests/Chat/ToolTailFoldTest.php`
- files.modify: `candy-code/src/Chat.php` (applyBackendToolEvent ToolProgress arm, pumpLiveToolEvents ToolProgress arm, replaceToolRunningPlaceholder), `candy-code/src/Renderer.php` (renderPendingToolCall + new private tailLines()), `candy-code/lang/en.php` (`tui.tool.tail_more`)
- tests: create the two above (golden paint, an over-wide line clipped, a bidi/C1 payload sanitised, the slot cleared on finish, a settled-queue replay paints nothing stale)
- docs/guards: none
- serialized: [chat:applyBackendToolEvent,pumpLiveToolEvents,replaceToolRunningPlaceholder] [renderer:renderPendingToolCall] [lang:tui.tool.tail]
- deps: C-TOOL-3.a
- phase: normal
- ruling: none
- risks: repaint cadence follows the live pump; without ext-pcntl the tail appears only on settle (documented already for agent lines).

### C-TOOL-3.c — Session-owned background shells
- item: C-TOOL-3 (sources: GAP-SH-04 serve half, p2-miss: SHL no web/serve shell list)
- kind: gap · effort M
- approach: One `BackgroundShellRegistry` per pid+root (`LaunchContext::backgroundShellRegistryFor()` :690). Served sessions share `workspace->backend` and its tool instances (`SessionHost.php:1626`, `TurnRunner::backendForTurn()` :276 only rebinds the session id), so every served session (and every future TUI tab) reads and stops every other session's shells. Fix by owner tagging, not by splitting tables, which keeps one teardown:
  - Registry rows gain `owner` (session id or null), carried through `start`/`toArray`/`merge`/`parseRow`. `status`/`read`/`stop` take an optional owner and refuse a foreign id with the same "not registered" answer. A new `ownedBy(?string)` lists rows.
  - New seam `AcceptsShellOwner::withShellOwner(?string)`, implemented by Bash, ShellOutput and ShellStop.
  - `EngineBackend::turnTools()` (:3151) binds `$this->sessionId` BEFORE the `AcceptsToolProgress` arm (:3218), which `continue`s.
  - A null owner (a launch before its session row is minted) stays visible to all, which is today's behaviour.
- files.create: `candy-code/src/Tools/AcceptsShellOwner.php`, `candy-code/tests/Tools/ShellOwnershipTest.php`
- files.modify: `candy-code/src/Background/BackgroundShellRegistry.php` (row shape, start/status/read/stop, merge/parseRow, ownedBy), `candy-code/src/Tools/BuiltIn/Bash.php`, `candy-code/src/Tools/BuiltIn/ShellOutput.php`, `candy-code/src/Tools/BuiltIn/ShellStop.php` (implement + scope lookups), `candy-code/src/Backend/EngineBackend.php` (turnTools)
- tests: create the file above (two owners on one registry cannot read or stop each other's shells; a null owner is visible; the owner survives a fork merge); modify `candy-code/tests/Sessions/BackgroundShellRegistryTest.php`, `candy-code/tests/Tools/ShellStopTest.php`
- docs/guards: `candy-code/tests/Support/OneTypePerFile` gate via `tools/check-one-type-per-file.php` (new interface file)
- serialized: [hot:candy-code/src/Backend/EngineBackend.php] [hot:candy-code/src/Background/BackgroundShellRegistry.php]
- deps: C-TOOL-1.a, C-TOOL-7.a (same registry/ShellOutput files)
- phase: normal
- ruling: none
- risks: P-SESSION-TABS assumes per-tab isolation; land this before it, or tell that lane.

### C-TOOL-3.d — `/shells` list/output/stop
- item: C-TOOL-3 (sources: GAP-SH-04, CLAW-new#6 (TUI half))
- kind: gap · effort M
- approach: The human has no view of background shells; ShellOutput and ShellStop are model-only. Add a `/shells` built-in with a host command, so served and web sessions get it free through `command.exec`, with verbs:
  - `list` (bare form): id, label, state, exit code, age, owner-filtered to `$context->currentSessionId()`;
  - `output <id> [cursor]`: the tail via `read()`;
  - `stop <id>`: the kill ladder.
  The registry reaches the command as a workspace service. `Bootstrap::workspace()` (:1885-1910, the `withService` chain) registers `self::backgroundShells($root)` under `BackgroundShellRegistry::class`, so `CommandContext` is unchanged (`$context->workspace?->service(...)`, the ScheduleRegistry pattern). Read-only judgement: `list`/`output` are admitted in a read-only window and `stop` is not (a `shells` arm in `Chat::isReadOnlySafeCommand()` :10317, the `/schedules` precedent at :10334). The spec file is NEW (per-command-disjoint, not serialized).
- files.create: `candy-code/builtin-commands/2905-shells.php`, `candy-code/src/Host/Commands/ShellsCommand.php`, `candy-code/tests/Host/Commands/ShellsCommandTest.php`
- files.modify: `candy-code/src/Cli/Bootstrap.php` (workspace() service chain), `candy-code/src/Chat.php` (new `handleShellsCommand()` beside handleSchedulesCommand :15246; isReadOnlySafeCommand arm), `candy-code/lang/en.php`, `candy-code/README.md` + `candy-code/docs/COMMANDS.md` (via `php tools/gen-command-docs.php --write`), `candy-code/docs/ARCHITECTURE.md` ("dispatch arms for N built-in slash commands" count)
- tests: create the file above (list, output, stop, foreign-owner refusal, the not-configured reply); modify `candy-code/tests/Commands/MenuBarLabelDerivationTest.php` (hint row), `candy-code/tests/Chat/PaletteSupersetDispatchTest.php` (survivor rationale row), `candy-code/tests/Commands/BuiltInCommandsTest.php` (if it pins a count)
- docs/guards: `CommandDocsDriftTest`, `ReadmeRosterDriftTest`, `DocFigureProseDriftTest` (ARCHITECTURE.md slash-command count) all moved in-step
- serialized: [chat:handleShellsCommand(new),isReadOnlySafeCommand] [commands] [bootstrap:workspace] [lang:cmd.shells.,host.shells.] [docs:README.md] [docs:COMMANDS.md] [docs:ARCHITECTURE.md]
- deps: C-TOOL-3.c
- phase: normal
- ruling: none
- risks: Claude Code's equivalent is `/tasks`; do not alias to it (a future `/tasks` for agents would collide).

### C-TOOL-3.e — Web Processes section + `shells.*` methods
- item: C-TOOL-3 (sources: CLAW-new#6, p2-miss: SHL no web/serve shell list)
- kind: gap · effort M
- approach: Structured protocol methods: `shells.list` (Read scope), `shells.output {shellId, cursor}` (Read) and `shells.stop {shellId}` (Write, idempotent). They resolve the session's workspace registry like the other per-session methods and filter by owner (C-TOOL-3.c). The web gets a Processes section inside `BackgroundPanel.vue`, as a new child component (no new SidePanels tab, which keeps the hot `SidePanels.vue` and `stores/agents/panels.ts` untouched). It shows rows with state, exit code and recent output, plus a targeted Stop, polling `shells.list` while the panel is open (no new event type).
- files.create: `candy-code/src/Protocol/Methods/ShellsMethods.php`, `candy-code/tests/Protocol/ShellsMethodsTest.php`, `candy-code-web/src-web/stores/agents/shells.ts`, `candy-code-web/src-web/components/panels/ProcessesSection.vue`, `candy-code-web/src-web/components/panels/__tests__/processesSection.spec.ts`
- files.modify: `candy-code/src/Protocol/Dispatcher.php` (register, beside BgMethods :138), `candy-code/src/Protocol/Schema/MethodSchemas.php`, `candy-code/src/Protocol/Schema/Definitions.php` (background-shell row def), `candy-code/docs/protocol/candy-code.v1.schema.json` + `candy-code/docs/SERVER.md` (via `php scripts/gen-protocol-schema.php --write`), `candy-code-web/src-web/protocol/generated.ts` (via `candy-code-web/scripts/gen-protocol.mjs`), `candy-code-web/src-web/components/panels/BackgroundPanel.vue` (mount the section)
- tests: create the PHP and spec files above (owner filter, stop idempotency, scope enforcement; section renders rows, Stop calls `shells.stop`, write-scope gating)
- docs/guards: `ProtocolSchemaDriftTest`, `ServerProtocolDocumentationDriftTest` (regen in-step)
- serialized: [protocol]
- deps: C-TOOL-3.c
- phase: normal
- ruling: none
- risks: polling cost is bounded by the open panel; stop polling on close.

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

### C-TOOL-5.b — Default formatter table (`formatAuto`)
- item: C-TOOL-5 (sources: GAP-EDT-04)
- kind: gap · effort M
- approach: `FormatRunner` (`candy-code/src/Format/FormatRunner.php`) runs only the explicit `formatCommands` map, and `Bootstrap::hooks()` (:5826, :5843-5848) registers the hook only when a command exists. Add `FormatAutoTable`: extension → candidate formatters in preference order (gofmt -w, rustfmt, ruff format / black, a project-local `node_modules/.bin/prettier --write`, shfmt -w, terraform fmt, …). It is gated on binary presence through an injectable `?callable $binaryPresent` seam (the `LspLauncher::fromConfig` shape). Explicit `formatCommands` entries win per extension. New user-tier bool `formatAuto` (default false). Formatters read project config (prettier plugins execute JS), so the AUTO arm also requires project hooks trust, the same gate as C-TOOL-7.c. The hook registers when commands exist OR formatAuto is on.
- files.create: `candy-code/src/Format/FormatAutoTable.php`, `candy-code/tests/Format/FormatAutoTableTest.php`
- files.modify: `candy-code/src/Format/FormatRunner.php` (auto fallback per extension), `candy-code/src/Cli/Bootstrap.php` (hooks() format block), `candy-code/src/Config/Settings/Definitions/HooksMcpSettings.php` (`formatAuto` beside `formatCommands` :52), `candy-code/lang/en.php`, `candy-code/docs/HOOKS.md` ("Post-edit format": the auto table + trust), `candy-code/docs/SETTINGS.md` + `candy-code/README.md` (regen)
- tests: create the file above (preference order, absent binaries skipped, explicit entries win, untrusted roots get no auto arm); modify `candy-code/tests/Format/FormatRunnerTest.php`, `candy-code/tests/Hooks/BuiltIn/PostEditFormatHookTest.php` (registration when only formatAuto is set)
- docs/guards: `SettingsSchemaDocDriftTest`, `TrustKeyDocumentationDriftTest`
- serialized: [bootstrap:hooks] [lang:settings.formatAuto.] [docs:HOOKS.md] [docs:SETTINGS.md] [docs:README.md]
- deps: C-TOOL-1.c (PostEditFormatHook + HOOKS.md section), C-TOOL-7.c (trust-gate pattern)
- phase: normal
- ruling: none
- risks: a formatter's style can fight the project's own config; only project-local prettier is used, never a global one, for that reason.

### C-TOOL-5.c — LSP installer core
- item: C-TOOL-5 (sources: GAP-EDT-07)
- kind: gap · effort M
- approach: `LspInstaller`: explicit, never automatic, installs into `~/.candy-code/lsp/` (0700).
  - Single-binary servers (rust-analyzer, and others the ruling admits) come from a pinned manifest `LspReleases` (language → version, per-OS/arch URL, sha256), via Guzzle with a connect timeout only. The download is checksum-verified before extraction, with an atomic rename into `bin/`.
  - npm-distributed servers (intelephense, typescript-language-server, pyright) use `npm install --prefix ~/.candy-code/lsp --ignore-scripts <pkg>@<pin>` through `CapturesProcessOutput::runCaptured` (no new proc_open token).
  - `LspLauncher`'s AUTO_TABLE probe looks in `~/.candy-code/lsp/bin` (and `node_modules/.bin`) before PATH, through the existing `binaryPresent` seam.
- files.create: `candy-code/src/LSP/LspInstaller.php`, `candy-code/src/LSP/LspReleases.php`, `candy-code/tests/LSP/LspInstallerTest.php`
- files.modify: `candy-code/src/LSP/LspLauncher.php` (probe dir precedence, fromConfig)
- tests: create the file above (fake transport, no network: a checksum mismatch refuses and leaves nothing; the atomic install; the npm arm builds an argv with `--ignore-scripts`); modify `candy-code/tests/LSP/LspLauncherAutoDetectTest.php` (the install dir wins over PATH)
- docs/guards: none (the CLI and docs ride C-TOOL-5.d)
- serialized: none
- deps: C-TOOL-7.c (LspLauncher.php)
- phase: normal
- ruling: LSP download policy: may candy-code download and run pinned third-party language-server binaries (and npm packages with `--ignore-scripts`), and which servers are on the list?
- risks: pins rot; the manifest needs an owner and a refresh cadence.

### C-TOOL-5.d — `candy-code lsp install/list` subcommand
- item: C-TOOL-5 (sources: GAP-EDT-07)
- kind: gap · effort S
- approach: New subcommand `lsp` with `install <language>` and `list` (installed, pinned and available), over `LspInstaller`. It adds `lsp` to `ParsedArgs::SUBCOMMANDS` (:56) and `SUBCOMMAND_FLAGS`, a dispatch arm in `Subcommands`, a completion verb label (`Subcommands.php` ~:2573 roster), and help text through `Lang`. The prose counts move in-step: ARCHITECTURE.md "eleven subcommands" + list (`DocFigureProseDriftTest` :722), and docs/MCP.md's help-listed subcommand counts (`testMcpCommandsSurfaceCountsHelpRowsAndTheRunArm`) if `lsp` is help-listed.
- files.create: `candy-code/tests/Cli/LspSubcommandTest.php`
- files.modify: `candy-code/src/Cli/ParsedArgs.php`, `candy-code/src/Cli/Subcommands.php` (dispatch + completion verbs), `candy-code/lang/en.php` (`cli.lsp.*`, `cli.completion.verb.lsp`), `candy-code/README.md` (CLI section), `candy-code/docs/ARCHITECTURE.md`, `candy-code/docs/MCP.md` (count prose only if it moves)
- tests: create the file above; modify `candy-code/tests/Cli/HelpTest.php`
- docs/guards: `DocFigureProseDriftTest` (subcommand word-count + list), `HelpTest`, `ReadmeRosterDriftTest` if the README CLI roster is pinned
- serialized: [headless] [lang:cli.lsp.] [docs:README.md] [docs:ARCHITECTURE.md] [docs:MCP.md]
- deps: C-TOOL-5.c
- phase: normal
- ruling: same as C-TOOL-5.c
- risks: none beyond the ruling.

### C-TOOL-4.a — `sandbox` row in `candy-code doctor`
- item: C-TOOL-4 (sources: CC-new#11 (AppArmor userns diagnostic in `doctor`), GAP-SH-06)
- kind: gap · effort S
- approach:
  - `Subcommands::doctorProbes()` (`candy-code/src/Cli/Subcommands.php:381`) gains a `sandbox` row, backed by a private `sandboxProbe(?string $sysctlPath = null)` seam. The row goes after `permission policy` and is spelled `'sandbox' => static function` so the drift guard's label scrape counts it.
  - The row reports three things:
    - the configured `bashSandbox` mode;
    - where `bwrap` resolves (`Bubblewrap::resolvedBinary()`);
    - whether the jail actually starts. Presence is not capability (the ISS-083 doctrine), so the row EXERCISES the jail through a new public `Bash::sandboxRefusal(): ?string`. That method is the probe closure extracted from `Bash::execute()` (`Bash.php:545-570`), so doctor asks Bash's exact question through Bash's own `runCaptured`. No new proc_open token.
  - New `Bubblewrap::appArmorRestrictsUserns(string $path = <the /proc/sys/kernel/apparmor_restrict_unprivileged_userns const>): ?bool` (null when the file is absent) adds the AppArmor diagnostic. The path is the test seam.
  - Verdicts:
    - `off`: OK. The detail still says whether the jail would start here, and why not.
    - Configured and starts: OK.
    - Configured and refused: WARN with the remedy. The remedy is to install bubblewrap or, on an AppArmor host, add an `/etc/apparmor.d` profile granting bwrap `userns` (the sysctl is the blunt fallback).
    - Non-Linux: the Linux-only line.
  - The row never FAILs, per `doctor()`'s WARN doctrine (:186-190).
  - **Why `phase: normal` inside a LATE item:**
    - It is S effort and needs no ruling.
    - It uses nothing from the 4.b seam, and adds no spawn site and no setting.
    - It pays off today: on Ubuntu 24.04 a configured `bashSandbox` refuses every Bash call, and nothing explains why before the first refusal.
- files.create: `candy-code/tests/Cli/DoctorSandboxProbeTest.php`
- files.modify: `candy-code/src/Cli/Subcommands.php` (`doctorProbes()`, new `sandboxProbe()`), `candy-code/src/Tools/BuiltIn/Bash.php` (extract `sandboxRefusal()` from the `execute()` probe block; `execute()` calls it), `candy-code/src/Tools/Sandbox/Bubblewrap.php` (`appArmorRestrictsUserns()` + the sysctl path const), `candy-code/lang/en.php` (`cli.doctor.sandbox_*`), `candy-code/docs/TROUBLESHOOTING.md` (:10 headline "fifteen checks" → "sixteen checks"; a bwrap/AppArmor row), `candy-code/tests/Support/ReadPathCensusTest.php` (the `Tools/Sandbox/Bubblewrap.php|file_get_contents` row gains a PROCESS_DERIVED sysctl verdict)
- tests:
  - create the file above. It pins:
    - the row is present and in place;
    - the off / starts / refused verdicts, through an injected sysctl path and an injected refusal;
    - a sysctl file reading `1` names the AppArmor remedy, and an absent file names none;
    - the Lang keys resolve (a typo'd key would surface raw).
  - modify `candy-code/tests/Tools/BashSandboxTest.php`: `sandboxRefusal()` returns exactly the reason `execute()` refuses with.
- docs/guards: `DocFigureProseDriftTest` doctor-count pin (the `doctorProbes()` label scrape ↔ TROUBLESHOOTING.md "sixteen checks; exits 1 if any FAILs"); `ReadPathCensusTest`. Keep green: `DoctorFts5ProbeTest` (row order), `DoctorExtensionProbesTest`, `DoctorBypassNoticeProbeTest`.
- serialized: [headless] [lang:cli.doctor.sandbox_] [docs:TROUBLESHOOTING.md] [hot:candy-code/src/Tools/BuiltIn/Bash.php] [hot:candy-code/tests/Support/ReadPathCensusTest.php]
- deps: none
- phase: normal
- ruling: none
- risks: C-TUI-5.a (design-tui.md) also plans a `sandbox` doctor probe, and it moves `doctorProbes()` into a new `DoctorChecks.php`. There should be one row with one owner: this step owns it. C-TUI-5.a drops its sandbox bullet and carries this row along in the extraction. The two steps serialize on [headless] and TROUBLESHOOTING.md in either order.

### C-TOOL-4.b — SandboxBackend seam + macOS Seatbelt backend
- item: C-TOOL-4 (sources: GAP-SH-06)
- kind: gap · effort M
- approach:
  - **Interface.** New `SandboxBackend` with:
    - `name()`, `mode()`;
    - `argv(string $writableRoot, bool $interactive = false, ?string $tmpDir = null, ?string $chdir = null, array $readOnlyBinds = [])`, plus `wrap()` with the same parameters;
    - `probe(\Closure $run): ?string`;
    - `describe(string $writableRoot)`.
    - `$chdir` and `$readOnlyBinds` exist from day one because 4.d and 4.e need them: hook payload files are staged in /tmp, and an MCP server's cwd is not its writable root (C-MCP-1.a).
  - **Bubblewrap.** It implements the interface. The shared escape-hatch logic (`PROTECTED_PATHS`, `PROTECTED_GIT_PATHS`, `gitDirs()`, `normalise()`, `Bubblewrap.php:62-70,265-312`) moves to `JailPaths`, so both backends protect the same paths. Bubblewrap keeps its constants as aliases.
  - **Factory.** `SandboxFactory::fromSetting(mixed $value, string $os = PHP_OS_FAMILY): ?SandboxBackend` keeps `Bubblewrap::fromSetting()`'s vocabulary, including its unknown-is-strictest rule.
    - Darwin → `Seatbelt`.
    - Everything else → `Bubblewrap`. Its probe already refuses off Linux, so Windows keeps today's fail-closed refusal.
  - **Seatbelt.** It runs `/usr/bin/sandbox-exec -p <SBPL> -D ROOT=… -D TMP=… <cmd>`. The profile mirrors bwrap:
    - `(allow default)`, then `(deny file-write*)`;
    - allow writes under ROOT, `$TMPDIR`, /private/tmp, /private/var/folders, /dev/null and the ttys;
    - then re-deny the JailPaths escape hatches. SBPL is last-match-wins, so the deny must come after the allow.
    - Linked-worktree gitdirs are writable after the same back-pointer check as bwrap.
    - `no-network` adds `(deny network*)` and keeps local unix sockets.
    - Every path is realpath'd first (/tmp → /private/tmp).
    - The probe runs the exact profile over /usr/bin/true.
  - **Callers.** `Bash` and `PtyRun` hold `?SandboxBackend` and build it through the factory (`Bash.php:186`, `PtyRun.php:176`). Their refusal text names `name()` instead of hard-coding "bubblewrap". The 4.a doctor row reports the backend through the factory.
- files.create: `candy-code/src/Tools/Sandbox/SandboxBackend.php`, `candy-code/src/Tools/Sandbox/SandboxFactory.php`, `candy-code/src/Tools/Sandbox/Seatbelt.php`, `candy-code/src/Tools/Sandbox/JailPaths.php`, `candy-code/tests/Tools/Sandbox/SandboxFactoryTest.php`, `candy-code/tests/Tools/Sandbox/SeatbeltTest.php`
- files.modify: `candy-code/src/Tools/Sandbox/Bubblewrap.php` (implements; delegates to JailPaths), `candy-code/src/Tools/BuiltIn/Bash.php` (ctor/`withSandbox()`/`sandbox()` types, `fromCatalog()`, refusal text, `sandboxRefusal()`), `candy-code/src/Tools/BuiltIn/PtyRun.php` (same), `candy-code/src/Config/Settings/Definitions/ToolSettings.php` (`bashSandbox` reader symbol + readBy → `SandboxFactory::fromSetting`), `candy-code/src/Cli/Subcommands.php` (`sandboxProbe()` via the factory), `candy-code/lang/en.php` (`settings.bashSandbox.help`, `cli.doctor.sandbox_*` Darwin arm), `candy-code/docs/PERMISSIONS.md` (Sandbox §: a macOS paragraph, and what differs there — no private /tmp, no PID/IPC namespaces), `candy-code/docs/SETTINGS.md` + `candy-code/README.md` (regen), `.github/workflows/ci.yml` (`macos-smoke`: run SeatbeltTest on a real Darwin)
- tests:
  - create `SandboxFactoryTest`. It pins OS routing (including the Windows refusal), the vocabulary, and unknown → strictest for each backend.
  - create `SeatbeltTest`. It pins:
    - the profile text: deny-after-allow order, the protected paths, the no-network clause;
    - the planted-gitdir refusal, reused;
    - the argv shape;
    - a live leg that is skipped unless the host is Darwin with sandbox-exec.
  - modify `candy-code/tests/Tools/BashSandboxTest.php` and `candy-code/tests/Tools/PtyRunToolTest.php` (types; refusal text names the backend), `candy-code/tests/Cli/DoctorSandboxProbeTest.php` (Darwin arm via an injected OS), `candy-code/tests/Support/ReadPathCensusTest.php` (the Bubblewrap.php `file_get_contents` rows move to JailPaths.php).
- docs/guards: `SettingsSchemaDocDriftTest` (readBy text), `SettingsSchemaTest::testEveryReaderSymbolResolvesToARealMethod`, `ReadPathCensusTest`, `tools/check-one-type-per-file.php` (four new types in four files)
- serialized: [headless] [workflows] [lang:settings.bashSandbox., cli.doctor.sandbox_] [docs:PERMISSIONS.md] [docs:SETTINGS.md] [docs:README.md] [hot:candy-code/src/Tools/BuiltIn/Bash.php] [hot:candy-code/src/Tools/BuiltIn/PtyRun.php] [hot:candy-code/tests/Support/ReadPathCensusTest.php]
- deps: C-TOOL-4.a
- phase: LATE
- ruling: none
- risks: `sandbox-exec` is deprecated by Apple, though it still ships and Claude Code and Codex use it. The full candy-code suite never runs on macOS, so the `macos-smoke` leg added here is the only live verification of the profile.

### C-TOOL-4.c — bwrap hardening (CC-new#11)
- item: C-TOOL-4 (sources: CC-new#11)
- kind: gap · effort S
- approach:
  - **Nested user namespaces.** Where the host's bwrap supports it, `argv()` adds `--unshare-user --disable-userns` (bwrap ≥ 0.8). A jailed command then cannot create a fresh user namespace, which is the usual way back out of a mount jail.
    - Support is decided by the probe. It runs the hardened flag set first and, only if that fails, the plain set. The memo records `hardened`, and `describe()` says when this host's bwrap cannot block nested namespaces.
    - The jail itself never falls open: if the plain set also fails, the command is refused, as today.
  - **Opt-in.** New user-tier bool `bashSandboxNestedUserns` (default false; CC's "opt-in nested user namespaces, user tier only") drops the two flags. It is for users who run rootless podman/buildah or a Chromium sandbox inside Bash. `SandboxFactory` reads it.
  - **Config write-protection.** This is CC's "write-protect the MCP config passed on the CLI". candy-code has no `--mcp-config`; the analogue is `--config <file>` (`LaunchContext::configPathOverride()`, `LaunchContext.php:313`).
    - That file can sit inside the writable root, where a jailed command could rewrite the next launch's user-tier policy (for example `bashSandbox: off`).
    - It is re-bound read-only when it falls under the root.
    - `~/.candy-code` is already read-only, via `--ro-bind / /` (and via `PROTECTED_PATHS` when the root is `$HOME`). This step pins both cases with tests.
  - **Shell startup files: n/a.** Bash runs a non-login, non-interactive `bash -c` (`Bash.php:478-480`), which reads no rc file. Only `$BASH_ENV` is honoured, and the bash that sources it is already the jailed one. PERMISSIONS.md records this.
- files.create: `candy-code/tests/Tools/Sandbox/BubblewrapHardeningTest.php`
- files.modify: `candy-code/src/Tools/Sandbox/Bubblewrap.php` (argv flags, two-stage probe, `withNestedUserns()`, `withProtectedPaths()`), `candy-code/src/Tools/Sandbox/SandboxFactory.php` (the new key + the `--config` override), `candy-code/src/Config/Settings/Definitions/ToolSettings.php` (`bashSandboxNestedUserns`), `candy-code/src/Config/LayeredSettings.php` (LAYERED_KEYS generated region), `candy-code/lang/en.php` (`settings.bashSandboxNestedUserns.*`), `candy-code/docs/PERMISSIONS.md` (Sandbox §: nested-userns bullet, `--config` bullet, startup-files sentence), `candy-code/docs/SETTINGS.md` + `candy-code/README.md` (regen via `php tools/gen-settings-doc.php --write`)
- tests:
  - create the file above. It pins:
    - the flags are present by default when supported, absent when opted in, and absent-and-described when the probe says unsupported;
    - a failing plain set still refuses;
    - a `--config` file inside the root is re-bound read-only, and one outside the root is untouched;
    - a `$HOME` root protects `.candy-code`, and no other root binds anything under `~` writable.
  - modify `candy-code/tests/Tools/BashSandboxTest.php` (argv pins that enumerate flags), `candy-code/tests/Config/LayeredSettingsTest.php` (user-tier roster).
- docs/guards: `SettingsSchemaDocDriftTest`, `TrustKeyDocumentationDriftTest` (README user-tier enumeration), `LayeredSettingsTest`
- serialized: [lang:settings.bashSandboxNestedUserns.] [docs:PERMISSIONS.md] [docs:SETTINGS.md] [docs:README.md] [hot:candy-code/src/Config/LayeredSettings.php]
- deps: C-TOOL-4.b
- phase: LATE
- ruling: none
- risks: setuid-bwrap installs (older RHEL/Debian) may reject `--unshare-user`. The two-stage probe then runs unhardened and says so; measure this on one such host before claiming the flag lands everywhere.

### C-TOOL-4.d — `sandboxScope`: jail hooks, lint, format and test runs
- item: C-TOOL-4 (sources: GAP-SH-06)
- kind: gap · effort M
- approach:
  - **Setting.** New user-tier list `sandboxScope` over the `SandboxScope` consts `hooks`, `lint`, `format` and `test`.
    - The default is `[]`, so nothing changes until the user opts in.
    - An unknown entry reads as every scope (the strictest reading, `fromSetting()`'s rule), and the doctor's `settings keys` audit names it.
    - A scope is live only while `bashSandbox` is not `off`, and it uses the same backend and mode.
  - **Wiring.** `Bootstrap::hooks()` (:5826) resolves the backend once through `SandboxFactory` and probes it once through `Bash::sandboxRefusal()` (4.a). It then hands the backend to:
    - `FormatRunner`, `LintRunner` and `TestRunner`, through `withSandbox()`;
    - `HookManager::loadEntries()` (:134), which applies it to every `ScriptHook` it builds.
  - **Runners.** Each runner wraps its line with `$backend->wrap($line, $rootReal)` before `runCaptured()` (LintRunner.php:218, FormatRunner.php:213, TestRunner). The writable root is the project root, because formatters and test runs must write there.
  - **Script hooks.** `ScriptHook` spawns at :679 and :692 become `spawnSpec([...$backend->argv($cwd, readOnlyBinds: $stagedPayloadFiles), '/bin/sh', '-c', $command])`.
    - The staged payload files are `tempnam(sys_get_temp_dir())` (:968). The jail's private /tmp would hide them, so they are bound read-only.
    - This is a prefix on the existing proc_open sites: no new token and no roster row.
  - **Fail closed.** If the jail cannot start:
    - a hook takes its existing could-not-execute path, which is a deny on a gating event;
    - lint, format and test skip, with the reason as their note.
    - None of them runs unconfined.
- files.create: `candy-code/src/Tools/Sandbox/SandboxScope.php`, `candy-code/tests/Tools/Sandbox/ScopedRunnerJailTest.php`
- files.modify: `candy-code/src/Hooks/ScriptHook.php` (`withSandbox()`, the two spawn sites, the refusal), `candy-code/src/Hooks/HookManager.php` (`loadEntries()`, `withScriptSandbox()`), `candy-code/src/Lint/LintRunner.php`, `candy-code/src/Lint/TestRunner.php`, `candy-code/src/Format/FormatRunner.php` (each: `withSandbox()` + the wrapped line), `candy-code/src/Cli/Bootstrap.php` (`hooks()`), `candy-code/src/Tools/Sandbox/SandboxFactory.php` (scope read), `candy-code/src/Config/Settings/Definitions/ToolSettings.php` (`sandboxScope`), `candy-code/src/Config/LayeredSettings.php` (gen), `candy-code/lang/en.php` (`settings.sandboxScope.*`, `sandbox.*` notices), `candy-code/docs/HOOKS.md` (script hooks + post-edit format/lint/test: "Sandboxed runs"), `candy-code/docs/PERMISSIONS.md` (Sandbox §), `candy-code/docs/SETTINGS.md` + `candy-code/README.md` (regen)
- tests:
  - create the file above. It pins:
    - in-scope lines carry the prefix, and out-of-scope lines are byte-identical to today;
    - an unknown entry means every scope;
    - payload files are bound read-only;
    - the hook cwd is the jail's `--chdir`;
    - a refused jail denies a PreToolUse hook and leaves a skip note for lint/format/test;
    - `bashSandbox: off` leaves every scope inert.
  - modify `candy-code/tests/Hooks/ScriptHookTest.php` (only where the spawn spec is pinned), `candy-code/tests/Format/FormatRunnerTest.php`, `candy-code/tests/Lint/TestRunnerTest.php`, `candy-code/tests/Config/LayeredSettingsTest.php`.
- docs/guards: `SettingsSchemaDocDriftTest`, `TrustKeyDocumentationDriftTest`, `LayeredSettingsTest`. `DescriptorInheritanceGuardTest` stays green with no new row (prefix only).
- serialized: [bootstrap:hooks] [lang:settings.sandboxScope., sandbox.] [docs:HOOKS.md] [docs:PERMISSIONS.md] [docs:SETTINGS.md] [docs:README.md] [hot:candy-code/src/Hooks/ScriptHook.php] [hot:candy-code/src/Hooks/HookManager.php] [hot:candy-code/src/Config/LayeredSettings.php]
- deps: C-TOOL-4.b, C-TOOL-5.b (FormatRunner + the `Bootstrap::hooks()` format block). It must also run after the C-EXT steps that edit ScriptHook.php/HookManager.php (C-EXT-1.a, 6.a, 6.c).
- phase: LATE
- ruling: none
- risks: a jailed formatter or test runner that caches under `~` (go, cargo, npm, pip) fails, because `~` is read-only. HOOKS.md must give the cache-into-the-project or `$TMPDIR` recipe that the Bash sandbox docs already give.

### C-TOOL-4.e — Jail LSP and MCP stdio servers
- item: C-TOOL-4 (sources: GAP-SH-06)
- kind: gap · effort M
- approach:
  - `SandboxScope` gains `lsp` and `mcp`.
  - **LSP.**
    - `LspConnection` takes a `list<string> $spawnPrefix`, beside `serverArgs` (:274).
    - `connect()` spawns `spawnSpec([...$prefix, $command, ...$args])` (:340). The E672 pre-check (:320) still checks `$command` itself.
    - `LspLauncher::launch()`'s default `$connect` closure (:302) passes the prefix.
    - `parseServer()` (:413) reads a per-server `sandbox: false` opt-out. The `lsp` map is user-tier only, so the opt-out is the user's own.
    - `Bootstrap::lspClient()` (:8447) resolves the backend, the scope and the probe.
    - A refused jail means the server is not started, and the reason rides the launcher's existing `problems()` channel.
  - **MCP.**
    - The adapter `candy-code/src/MCP/StdioMcpServer.php` takes a jail prefix, and `spawnPlan()` (:147-161) returns `spawnSpec([...$prefix, ...$argv])`. The proc_open itself lives in `sugar-mcp-client`; `spawnPlan()` is the containment seam handed to that library, so the library is untouched.
    - `McpClient::buildServer()`'s stdio arm (:731) honours `"sandbox": false` from USER-tier entries only. A project `.mcp.json` must not opt its own server out of the user's jail, so a project value is ignored with a notice.
    - `sandbox` is not in `McpTrustPins::UNPINNED_KEYS`, so editing it re-asks trust.
    - `Bootstrap::mcpClient()` (:7942) passes the backend.
  - **Paths.** The writable root is the project root. `--chdir` is the spawn cwd (4.b's `$chdir`), so C-MCP-1.a's user-tier `$HOME` cwd survives the jail without making `$HOME` writable.
- files.create: `candy-code/tests/Tools/Sandbox/ServerJailTest.php`
- files.modify: `candy-code/src/LSP/LspConnection.php` (ctor, `connect()`), `candy-code/src/LSP/LspLauncher.php` (`parseServer()`, `launch()`), `candy-code/src/MCP/StdioMcpServer.php` (ctor, `spawnPlan()`), `candy-code/src/MCP/McpClient.php` (`buildServer()` stdio arm, `withSandbox()`), `candy-code/src/Cli/Bootstrap.php` (`lspClient()`, `mcpClient()`), `candy-code/src/Tools/Sandbox/SandboxScope.php`, `candy-code/lang/en.php` (`settings.sandboxScope.help`, `sandbox.*` notices), `candy-code/docs/MCP.md` (§`.mcp.json` table: the stdio Keys cell gains `sandbox`; a "Jailed servers" paragraph), `candy-code/docs/PERMISSIONS.md` (Sandbox §), `candy-code/docs/SETTINGS.md` + `candy-code/README.md` (regen; `lsp` entry keys)
- tests:
  - create the file above. It pins:
    - LSP and MCP spawn specs carry the prefix only when in scope and not opted out;
    - a project-tier opt-out is ignored, with the notice;
    - `--chdir` is the spawn cwd while the writable root is the project root;
    - a refused jail means the server is not started and the problem is reported;
    - the E672 pre-check still names the server binary.
  - modify `candy-code/tests/LSP/LspLauncherTest.php`, `candy-code/tests/MCP/StdioMcpServerTest.php`, `candy-code/tests/MCP/McpTrustPinsTest.php` (`sandbox` is pinned).
- docs/guards: `DocFigureProseDriftTest::testMcpServerTypesKeysAndEnvInterpolationMatchTheFactory` (the stdio arm's `$config[...]` reads ↔ the MCP.md Keys cell), `SettingsSchemaDocDriftTest`. The `tools/check-child-lifetimes.php` row `sugar-mcp-client/src/StdioMcpServer.php::start` is unchanged (same fd spec; re-run it to confirm). `DescriptorInheritanceGuardTest` is unchanged (prefix only).
- serialized: [bootstrap:lspClient, mcpClient] [lang:settings.sandboxScope., sandbox.] [docs:MCP.md] [docs:PERMISSIONS.md] [docs:SETTINGS.md] [docs:README.md] [hot:candy-code/src/MCP/McpClient.php] [hot:candy-code/src/LSP/LspLauncher.php] [hot:candy-code/src/MCP/StdioMcpServer.php]
- deps: C-TOOL-4.d, C-TOOL-5.c and C-TOOL-7.c (LspLauncher.php), C-MCP-1.a and C-MCP-3.a (the StdioMcpServer adapter, the McpClient stdio arm and the MCP.md Keys cell)
- phase: LATE
- ruling: none
- risks: `npx -y` and `uvx` servers write their package cache under `~`, which is read-only in the jail, so the first jailed launch fails its handshake. MCP.md needs the `npm_config_cache`/`UV_CACHE_DIR` → `$TMPDIR` env recipe, with the per-server opt-out as the fallback.

### C-TOOL-4.f — Egress proxy core (new files only)
- item: C-TOOL-4 (sources: GAP-SH-07)
- kind: gap · effort M
- approach:
  - **`EgressPolicy`.**
    - `sandboxAllowedDomains` entries parse as `host-glob[:port]`; the default ports are 80 and 443.
    - Matching uses the grant side of `FetchTarget::matchesDomain($pattern, includeSubdomains: false)` (`candy-code/src/Permissions/FetchTarget.php:94`): `github.com` is exactly that host, and subdomains need `*.github.com`.
    - The host is resolved once through `WebFetch::resolveViaSystemDns()` and refused if ANY answer is `WebFetch::addressIsBlocked()` (`WebFetch.php:867,906`). So an allowed name that resolves to 127.0.0.1 or 169.254.169.254 is still refused.
    - The vetted address is the one dialled, so there is no DNS-rebinding window.
    - The resolver and the blocklist are injectable for tests.
  - **`EgressProxy`.** A ReactPHP server (`react/socket` is already required) on a unix socket, created 0600 inside a 0700 directory.
    - It serves `CONNECT host:port` (HTTPS, git over https, any TLS) and absolute-form HTTP/1.1 requests.
    - A deny answers `403`, with a body naming the host and `sandboxAllowedDomains`.
    - Only the upstream dial has a connect timeout. Tunnels are reaped when idle but never get a total bound (E646 spirit).
  - **`EgressProxyProcess`.** The launch-scoped helper.
    - `start()` proc_opens `spawnSpec([PHP_BINARY, '-r', <stub>])`, the `Agents/ProcessExecutor.php:682` worker shape: the stub requires this installation's autoload and calls `EgressProxy::serve()`.
    - stderr goes to a 0600 log beside the socket; it is never discarded.
    - The child exits on stdin EOF (the parent's end is close-on-exec via `ProcessContainment::closeOnExec()`) and on a `posix_getppid()` change.
    - `stop()` signals the recorded group and reaps it.
  - **`EgressBridge`.** The in-jail half.
    - `main()` binds `127.0.0.1:<port>` FIRST, so the command's first connect cannot race the listener. It then forks.
    - The child `pcntl_exec`s the command, keeping the session and pty when interactive, and calls `ForkedChild::exitNow(127)` only if the exec fails.
    - The parent relays TCP ↔ the bound unix socket, reaps the child, and exits with the child's status.
- files.create: `candy-code/src/Tools/Sandbox/EgressPolicy.php`, `candy-code/src/Tools/Sandbox/EgressProxy.php`, `candy-code/src/Tools/Sandbox/EgressProxyProcess.php`, `candy-code/src/Tools/Sandbox/EgressBridge.php`, `candy-code/tests/Tools/Sandbox/EgressPolicyTest.php`, `candy-code/tests/Tools/Sandbox/EgressProxyTest.php`, `candy-code/tests/Tools/Sandbox/EgressBridgeTest.php`, `candy-code/tests/Tools/Sandbox/EgressProxyProcessTest.php`
- files.modify: `candy-code/tests/Support/DescriptorInheritanceGuardTest.php` (an ACCOUNTED_FOR row `Tools/Sandbox/EgressProxyProcess.php::start`, count 1, with its reason), `candy-code/tests/Support/ReadPathCensusTest.php` (a row only if the census sees the stub's `require`, as it does for ProcessExecutor)
- tests:
  - create `EgressPolicyTest`: exact vs glob grants, port rules, refusal of a blocked address behind an allowed name, resolve-once pinning.
  - create `EgressProxyTest` (temp socket, injected resolver): an allowed CONNECT relays bytes to a local fake upstream; a deny is a 403 naming the setting; absolute-form GET works; a malformed request gets 400; the socket is mode 0600.
  - create `EgressBridgeTest`: the relay core in-process, plus the fork/exec leg in a php subprocess that forwards exit status 7.
  - create `EgressProxyProcessTest`: start/stop; the child exits on the parent's stdin EOF; no orphan after `stop()`.
- docs/guards: `DescriptorInheritanceGuardTest` (the new long-lived spawn row), `ChildStderrCaptureTest` (stderr goes to a file, not discarded), `ForkedChildExitConventionTest` (the bridge's fork child), `tools/check-one-type-per-file.php`. No user docs: nothing is wired yet.
- serialized: [hot:candy-code/tests/Support/DescriptorInheritanceGuardTest.php] [hot:candy-code/tests/Support/ReadPathCensusTest.php]
- deps: none (a self-contained core; LATE by item)
- phase: LATE
- ruling: none
- risks: unix-socket paths are capped near 104 bytes on macOS and 108 on Linux. The socket directory must be a short mkdtemp-style path under `sys_get_temp_dir()`, never under a deep `$HOME`.

### C-TOOL-4.g — `bashSandbox: "allowlist"` + `sandboxAllowedDomains` wiring
- item: C-TOOL-4 (sources: GAP-SH-07)
- kind: gap · effort M
- approach:
  - **Mode.** `Bubblewrap::MODES` (:53-56) gains `MODE_ALLOWLIST = 'allowlist'`, and `fromSetting()` maps it. Unknown values stay strictest. In this mode `argv()`:
    - leaves the network unshared (no `--share-net`);
    - binds the launch's proxy socket at `/tmp/.candy-egress.sock`, after `--tmpfs /tmp`;
    - `--setenv`s `HTTP_PROXY`, `HTTPS_PROXY`, `http_proxy` and `https_proxy` to `http://127.0.0.1:<port>`, plus `NO_PROXY=localhost,127.0.0.1`;
    - ends with the `EgressBridge` prefix (`PHP_BINARY -r <stub> -- <sock> <port> --`) before the command.
    - bwrap brings `lo` up in the new network namespace, so the bridge's loopback listener works.
  - **Description.** `describe()` names the allowed domains. It says other hosts get a 403, and non-HTTP traffic (ssh remotes, database ports) fails.
  - **Seatbelt.** It reads `allowlist` as `no-network`, with a `describe()` sentence saying so. A loopback-port arm is a follow-up. This fails closed, not open.
  - **Setting.** New user-tier `sandboxAllowedDomains` (StringList, default `[]`).
  - **Lifecycle.**
    - New `Bootstrap::egressProxy()`. When the resolved mode is `allowlist`, `Bootstrap::tools()` (:8523) starts the launch's `EgressProxyProcess` in the PARENT, before any fork, memoised per launch. Forked turn and tool children reuse the inherited socket path.
    - New `LaunchContext::registerEgressProxyShutdown()`, in the `registerBackgroundShellShutdown()` shape (`LaunchContext.php:879`), stops the proxy. It is keyed to the pid that started it.
    - `SandboxFactory` hands the socket path to the backend.
- files.create: `candy-code/tests/Tools/Sandbox/EgressAllowlistModeTest.php`
- files.modify: `candy-code/src/Tools/Sandbox/Bubblewrap.php` (MODES, `fromSetting()`, `argv()`, `describe()`), `candy-code/src/Tools/Sandbox/Seatbelt.php` (`allowlist` → no-network), `candy-code/src/Tools/Sandbox/SandboxFactory.php` (egress socket), `candy-code/src/Cli/Bootstrap.php` (new `egressProxy()`, `tools()`), `candy-code/src/Cli/LaunchContext.php` (new `registerEgressProxyShutdown()`, `stopEgressProxy()`), `candy-code/src/Config/Settings/Definitions/ToolSettings.php` (`bashSandbox` enum values, `sandboxAllowedDomains`), `candy-code/src/Config/LayeredSettings.php` (gen), `candy-code/lang/en.php` (`settings.bashSandbox.help`, `settings.sandboxAllowedDomains.*`), `candy-code/docs/PERMISSIONS.md` (Sandbox §: the values table gains `allowlist`; a "Domain allowlist" subsection), `candy-code/docs/SETTINGS.md` + `candy-code/README.md` (regen)
- tests:
  - create the file above. It pins:
    - the argv: no `--share-net`, the socket bind after `--tmpfs /tmp`, the proxy env, the bridge prefix;
    - the vocabulary;
    - the proxy starts once, in the parent, and stops at shutdown;
    - Seatbelt `allowlist` = `no-network`;
    - a live leg gated on a working bwrap: `curl` through the jail reaches an allowed fake upstream and gets a 403 from any other host.
  - modify `candy-code/tests/Tools/BashSandboxTest.php` (`testTheSettingVocabulary`), `candy-code/tests/Config/LayeredSettingsTest.php`.
- docs/guards: `SettingsSchemaDocDriftTest`, `TrustKeyDocumentationDriftTest`, `LayeredSettingsTest`. The proxy's spawn row belongs to 4.f; this step adds no proc_open.
- serialized: [bootstrap:egressProxy (new), tools] [lang:settings.bashSandbox., settings.sandboxAllowedDomains.] [docs:PERMISSIONS.md] [docs:SETTINGS.md] [docs:README.md] [hot:candy-code/src/Cli/LaunchContext.php] [hot:candy-code/src/Config/LayeredSettings.php]
- deps: C-TOOL-4.f, C-TOOL-4.c
- phase: LATE
- ruling: none
- risks: clients that ignore proxy env vars, or speak non-HTTP protocols, simply fail closed. With the default empty list, `allowlist` behaves exactly like `no-network` until the user lists hosts; the docs must say so up front.

### C-TOOL-4.h — Darwin process groups + wall fallback without GNU `timeout`
- item: C-TOOL-4 (sources: p2-miss: SHL macOS/Windows setsid + timeout fallback)
- kind: gap · effort M
- approach:
  - **Root cause.** `ProcessContainment::detachedSpawnBinary()` (:185) returns '' wherever no `setsid -w` exists (stock macOS), so `spawnSpec()` (:227) hands back the bare command. Two things degrade:
    - background shells record `grouped: false` (`BackgroundShellRegistry.php:313`);
    - the synchronous capture's group kill falls back to a single pid (`CapturesProcessOutput.php:388`).
  - **New prefix.** New `detachedSpawnPrefix(): list<string>` probes two candidates, in order, through the ONE existing probe proc_open (a loop, not a second token):
    - first `[setsid, '-w', '--']`;
    - then `[PHP_BINARY, '-r', <stub>, '--']`. The stub calls `posix_setsid()`, then `pcntl_exec('/usr/bin/env', $args)`, and exits 127 if the exec fails.
    - A candidate passes only if `/bin/sh -c 'exit 7'` comes back 7 AND the child is its own group leader.
    - The PHP arm needs ext-posix and ext-pcntl in the CLI. The probe proves that, and drops the arm otherwise.
    - Because the exec replaces the PHP process, the recorded pid becomes the command itself: group leader, session leader, and source of the exit status.
  - **Callers.** `detachedSpawnBinary()` becomes `prefix[0] ?? ''`, so every existing caller (the E672 pre-checks, `CapturesProcessOutput`) stays correct unchanged. `spawnSpec()` prepends the prefix in both argv and string form. A new `groupsSpawns(): bool` feeds the registry's `grouped` flag.
  - **Wall fallback.** In `buildWrapper()` (:371), the else-arm (no `timeout`) starts a TERM-trapping watchdog subshell before `exec bash -c "$SHELL_INNER"`: `trap 'kill $s' TERM; sleep N & s=$!; wait $s; kill -KILL -$$ || kill -KILL $$`.
    - The watchdog's pid rides the env (`SHELL_WATCHDOG`), and the trailer TERMs it before stamping the exit file. A settled shell therefore leaves no sleeping member for `groupMemberAlive()` to count as live.
    - The `timeout` arm stays byte-identical.
  - Windows stays out of scope (C-TUI-6).
- files.create: `candy-code/tests/Support/ProcessContainmentPhpSetsidTest.php`, `candy-code/tests/Sessions/BackgroundShellWallFallbackTest.php`
- files.modify: `candy-code/src/Support/ProcessContainment.php` (new `detachedSpawnPrefix()` and `groupsSpawns()`, `detachedSpawnBinary()`, `spawnSpec()`, a test seam that forces the candidate list), `candy-code/src/Background/BackgroundShellRegistry.php` (`buildWrapper()` else-arm + trailer, `start()`'s `grouped`, the class-docblock "THE GROUP IS ONLY AS REAL AS setsid" paragraph :57-64), `.github/workflows/ci.yml` (`macos-smoke`: run ProcessContainmentPhpSetsidTest on a real Darwin), `candy-code/docs/TROUBLESHOOTING.md` (a macOS row: process groups via the PHP helper, and the wall without GNU timeout)
- tests:
  - create `ProcessContainmentPhpSetsidTest` (the PHP arm forced on Linux). It pins:
    - the pid is its own pgid;
    - exit 7 is forwarded, and a missing binary gives 127;
    - the string form runs through `/bin/sh -c`;
    - a CLI without pcntl drops the arm, giving today's bare command;
    - a real setsid is still preferred when present.
  - create `BackgroundShellWallFallbackTest` (PATH without `timeout`). It pins:
    - a shortened wall kills the group;
    - a command that finishes leaves no live member;
    - the `timeout` arm's wrapper string is unchanged.
  - modify `candy-code/tests/Sessions/BackgroundShellRegistryTest.php` (wrapper pins), `candy-code/tests/Tools/ShellStopTest.php` (grouped narration, where pinned).
- docs/guards: `DescriptorInheritanceGuardTest` (the probe stays one short-lived proc_open, closed by a literal proc_close, so no roster row is needed); `ChildStderrCaptureTest` (the watchdog writes nothing)
- serialized: [workflows] [docs:TROUBLESHOOTING.md] [hot:candy-code/src/Background/BackgroundShellRegistry.php] [hot:candy-code/src/Support/ProcessContainment.php]
- deps: C-TOOL-7.a and C-TOOL-3.c (both edit BackgroundShellRegistry.php; C-TOOL-1.a precedes them)
- phase: LATE
- ruling: none
- risks:
  - On Darwin every contained spawn then pays a PHP start-up (about 20-50 ms).
  - `groupMemberAlive()` reads `/proc`, which Darwin lacks, so sweeps of shells whose leader already exited still signal per pid there. The watchdog wall is what bounds lifetime on Darwin.

### C-TOOL-6.a — Browser-control and computer-use MCP presets (docs)
- item: C-TOOL-6 (sources: GAP-TL-21, GAP-TL-25)
- kind: gap · effort S
- approach:
  - **Why this is `phase: normal`.** The brief allows a cheap sliver of a LATE item to run early. This one qualifies: it is S effort, doc-only (plus one test that pins the doc), and needs no ruling. It depends on no LATE step, and no LATE step depends on it.
  - **Placement.** A new `### Browser control and computer use` subsection in `candy-code/docs/MCP.md`, after `### Adding servers` (:424) and before `## Listing without starting` (:494).
  - **Three entries.** Each is shown twice: once for the project `.mcp.json` (which needs `trustedProjectMcp`, per the trust gate) and once for the user `~/.candy-code/mcp.json` (admitted on home ownership alone, per "Two tiers, one precedence" :96).
    - **Playwright MCP:** `npx -y @playwright/mcp@<exact version>`, with its isolated-profile flag.
    - **Chrome DevTools MCP:** `npx -y chrome-devtools-mcp@<exact version>`, with its isolated-profile flag.
    - **One computer-use stdio server.** The lane picks a maintained one, pins it, and states its platform scope. If none is maintained when the lane writes the section, the section says so instead of naming one; GAP-TL-25 rates this low priority.
  - **Pinning.** Every package carries an exact version, never `@latest` or a bare name. The lane reads each version and flag from the package's registry page on the day it writes, and dates the section.
  - **Caveats, in prose.**
    - Stdio MCP servers run outside the Bash jail (until C-TOOL-4.e), so a browser server reaches any URL and any profile it opens.
    - Every `mcp__playwright__*` call is write-class and asks by default (`PermissionGate::isWriteTool()`, `PermissionGate.php:1551`). The section gives example `permissionRules` that allow the snapshot and screenshot tools by name.
    - A large schema set may defer behind `mcp_list`/`mcp_call` ("Deferred schemas", :671).
    - `candy-code mcp import claude <file>` is the route for servers already in a Claude config.
  - No engine code changes.
- files.create: `candy-code/tests/Config/McpBrowserPresetDocTest.php`
- files.modify: `candy-code/docs/MCP.md` (new subsection after "Adding servers", plus a "See also" line if useful)
- tests: create `McpBrowserPresetDocTest`. It extracts the fenced JSON blocks under the new heading and pins:
  - each block decodes;
  - every server entry passes `McpForeignTranslate::normalizeEntry()` (`src/MCP/McpForeignTranslate.php:107`, the loader's own vocabulary);
  - every `npx`/`uvx` package argument carries an exact `@<semver>`;
  - the heading exists, so a renamed section cannot leave the test green with nothing checked.
- docs/guards: `DocFigureProseDriftTest` (MCP arms) and `McpStartupOrderingDocDriftTest` both re-run unchanged. The new subsection sits outside the server-type-table → "Foreign spellings" span that the second reads, and it adds no counted figure. No tool, command, env-var or setting roster moves.
- serialized: [docs:MCP.md]
- deps: none
- phase: normal
- ruling: none
- risks: package versions and flags drift after the section is written. The pins make that drift visible rather than silent, but each bump needs a dated refresh.

### C-TOOL-6.b — `NotebookEdit` tool + Read renders `.ipynb` as cell pages
- item: C-TOOL-6 (sources: GAP-TL-15)
- kind: gap · effort M
- approach:
  - **Model.** New `Notebook\Notebook` handles nbformat v4.
    - `parse()` decodes with `json_decode(..., false)`, so `{}` and `[]` survive the round trip.
    - Cells are addressed by their nbformat ≥4.5 `id`. In an id-less notebook they are addressed as `cell-<n>` (0-based).
    - `replace()`, `insert()` and `delete()` return new instances.
    - `serialize()` reuses the source file's detected indent (nbformat writes 1 space) and keeps the original key order. It encodes with `JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_LINE_TERMINATORS|JSON_PRESERVE_ZERO_FRACTION` and adds a trailing newline.
    - Cells the edit does not touch (metadata, outputs, attachments) come back byte-identical.
  - **Edit semantics** (a lane decision, recorded in the class docblock).
    - `replace` swaps `source` and keeps `id` and `metadata`. On a code cell it clears `outputs` and nulls `execution_count`, because outputs of the old source would misreport the new one.
    - `insert` goes after `cell_id`, or first when `cell_id` is absent. It requires `cell_type` and mints an 8-hex id on ≥4.5.
    - `delete` removes the cell.
    - A cell-type flip adds or drops `outputs`/`execution_count` to match the new type.
  - **Tool.** `NotebookEdit`: `#[BuiltInTool(name: 'NotebookEdit', permission: Write, position: 42, gloss: 'replace, insert or delete one Jupyter notebook cell by id, keeping every other cell, its outputs and metadata')]`.
    - Args: `file_path`, `cell_id`, `new_source`, `cell_type` (code|markdown), `edit_mode` (replace|insert|delete).
    - The path argument is `file_path`, not Claude Code's `notebook_path`. That is the tree's convention (`FilesTouched::PATH_ARGUMENT`, "the argument key every built-in file tool names its path under"), and it lets every path roster below take NotebookEdit by name alone.
    - It implements `Tool`, `BuildsFromCatalog` and `AcceptsWorktreeJail` (via the `RebindsWorktreeJail` trait).
    - The write path: `PathJail::resolve()` → refuse on `ReadLedger::staleness()` (Edit's message family) → honour C-TOOL-5.a's `requireReadBeforeWrite` → `AtomicFileWriter::replace()` → `ReadLedger::record()`.
    - A non-v4 or unparsable file is refused, and nothing is written.
  - **Read.** A `.ipynb` that parses as v4 and fits the byte cap answers numbered cell pages instead of raw JSON.
    - Each cell gets a header like `[cell 3 · code · id 4f1c…]`, its source lines, and an output digest (`stream 140 chars`, `image/png 18 KB`, `error: ValueError`). Output bytes are never included.
    - `offset`/`limit` count cells for a notebook.
    - The ledger observes the raw file (stat + hash), so NotebookEdit's staleness check sees the same bytes.
    - Anything unparsable falls back to today's text paging.
  - **Parity with Edit wherever a roster names Edit.**
    - `PermissionRule::SUBJECT_ARGUMENTS` (:195) and `PATH_SUBJECT_TOOLS` (:273).
    - `PermissionGate::EDIT_TOOLS` (:850), which gives the in-root grant under accept-edits.
    - `SafetyClassifier::classify()` (:385). Without this arm, `default => null` lets `auto` allow the call unclassified.
    - `ProtectFilesHook::inputsFor()` (:590, spelled `Notebookedit`).
    - `FilesTouched::MODIFY_TOOLS`, `StaleReadStrategy::WRITERS`, `PruningPolicy::PROTECTED_TOOLS`, `TurnContextBlock::FILE_WRITING_TOOLS`, and `ToolSummary::of()`.
    - Format and lint hooks stay `^(Write|Edit|ApplyPatch)$` (`EditedFiles::MATCHER`): a notebook's JSON is not source to reformat.
  - `Runtime::WRITE_CAPABLE_TOOL_NAMES` (:704) adds `NotebookEdit` in the same commit, because the drift test pins it to the catalog's Write class.
- files.create: `candy-code/src/Notebook/Notebook.php`, `candy-code/src/Notebook/NotebookPages.php` (Read's cell-page renderer), `candy-code/src/Tools/BuiltIn/NotebookEdit.php`, `candy-code/tests/Notebook/NotebookTest.php`, `candy-code/tests/Tools/BuiltIn/NotebookEditTest.php`, `candy-code/tests/Tools/BuiltIn/ReadNotebookTest.php`, `candy-code/tests/Permissions/NotebookEditPermissionTest.php`, `candy-code/tests/fixtures/notebooks/` (nbformat-written: v4.5 with stream, image and error outputs; v4.4 without ids; a v3 file)
- files.modify:
  - Tools: `candy-code/src/Tools/BuiltIn/Read.php` (`execute()`: a notebook branch before `readPage()`; one sentence in the description), `candy-code/src/Runtime.php` (`WRITE_CAPABLE_TOOL_NAMES`), `candy-code/src/Tools/ToolLimits.php` (`applyTo()`: a NotebookEdit arm for `requireReadBeforeWrite`).
  - Permissions: `candy-code/src/Permissions/PermissionRule.php` (`SUBJECT_ARGUMENTS`, `PATH_SUBJECT_TOOLS`), `candy-code/src/Permissions/PermissionGate.php` (`EDIT_TOOLS`), `candy-code/src/Permissions/SafetyClassifier.php` (`classify()`), `candy-code/src/Hooks/BuiltIn/ProtectFilesHook.php` (`inputsFor()`).
  - Context rosters: `candy-code/src/Context/Compaction/FilesTouched.php`, `candy-code/src/Context/Pruning/Strategies/StaleReadStrategy.php`, `candy-code/src/Context/Pruning/PruningPolicy.php`, `candy-code/src/Context/TurnContextBlock.php`, `candy-code/src/Agents/Live/ToolSummary.php`.
  - Docs: `candy-code/docs/PERMISSIONS.md` (the hand-kept pattern roster at :760; the subject-argument sentence at :607; the `auto` row at :204, "`Edit`/`Write`/`NotebookEdit` by target"). Regenerated by gen-tool-docs: `candy-code/README.md`, `candy-code/docs/ARCHITECTURE.md`, `candy-code/docs/AGENTS_AUTHORING.md`, `candy-code/docs/PERMISSIONS.md`, `candy-code/docs/SETTINGS.md`. Also hand-edit the unanchored README counts at :176 ("thirty-one of them ship on every launch") and :2629, which no generator anchor covers.
  - Guard tests: `candy-code/tests/Config/ReadmeRosterDriftTest.php` (`$spelled` stops at 32), `candy-code/tests/Config/ReadmeSettingsTierClaimTest.php` (`SPELLED_COUNTS` stops at 32), `candy-code/tests/Config/DocFigureProseDriftTest.php` (`$wordNumbers` holds 33, 34 and 36 only). Each gains the words this count needs. `candy-code/tests/Support/ReadPathCensusTest.php` gets a `PATH_JAIL` row for NotebookEdit's read.
- tests:
  - create `NotebookTest`:
    - unedited fixtures round-trip byte-identical;
    - `{}` vs `[]`, `1.0`, ` ` and id-less addressing all survive;
    - each edit mode, the outputs reset on replace, and the type flip.
  - create `NotebookEditTest`:
    - the catalog entry (position 42, Write);
    - refusals: jail escape, a stale ledger, unread-with-setting-on, v3;
    - the atomic write and the ledger record.
  - create `ReadNotebookTest`: cell headers, output digests with no bytes, cell-based offset/limit, the raw fallback, ledger observe.
  - create `NotebookEditPermissionTest`:
    - accept-edits allows inside the root and asks outside;
    - `auto` classifies by target;
    - `plan` denies;
    - `Deny NotebookEdit(secrets/**)` binds;
    - `.env` spellings are refused by protect-files.
  - modify `candy-code/tests/Permissions/SafetyClassifierTest.php` (the new arm).
- docs/guards:
  - `ToolDocDriftTest` (`php tools/gen-tool-docs.php --write`).
  - `PermissionsIntroDriftTest::testThePatternToolRosterIsTheWholeCatalog` (the hand roster at PERMISSIONS.md:760).
  - RuntimeTest's `testTheWriteToolRosterDoesNotDriftFromThePermissionGate`.
  - `PermissionRulePatternTest` (the SUBJECT_ARGUMENTS instrument reads the schema).
  - The three word-map tests above, `ReadPathCensusTest`, and `tools/check-one-type-per-file.php`.
- serialized: [runtime:WRITE_CAPABLE_TOOL_NAMES] [catalog] [docs:README.md] [docs:ARCHITECTURE.md] [docs:AGENTS_AUTHORING.md] [docs:PERMISSIONS.md] [docs:SETTINGS.md] [hot:candy-code/src/Tools/BuiltIn/Read.php] [hot:candy-code/src/Tools/ToolLimits.php] [hot:candy-code/src/Permissions/PermissionGate.php] [hot:candy-code/src/Permissions/PermissionRule.php] [hot:candy-code/src/Permissions/SafetyClassifier.php] [hot:candy-code/src/Hooks/BuiltIn/ProtectFilesHook.php] [hot:candy-code/src/Context/TurnContextBlock.php] [hot:candy-code/tests/Support/ReadPathCensusTest.php] [hot:candy-code/tests/Config/DocFigureProseDriftTest.php] [hot:candy-code/tests/Config/ReadmeRosterDriftTest.php] [hot:candy-code/tests/Config/ReadmeSettingsTierClaimTest.php]
- deps: C-TOOL-2.d (Read.php), C-TOOL-5.a (`requireReadBeforeWrite`, ToolLimits)
- phase: LATE
- ruling: none
- risks: PHP's JSON writer is not Python's. Byte-identical untouched cells depend on detecting the indent and on the escape flags; the nbformat-written fixtures are the proof. If one of them diverges, the fix is to splice the edited cell's span into the original bytes, never to accept a whole-file reformat.

### C-TOOL-6.c — Code-mode Script runner core (jailed PHP child + fd-3 tool bridge)
- item: C-TOOL-6 (sources: GAP-TL-N3, OC-new#12 (Code Mode))
- kind: gap · effort M
- approach:
  - **Purpose.** This is the engine half of Code Mode. A model-written script runs in a child whose only effect channel is a tool bridge. One model call can then chain many tool calls (OpenCode 2's default MCP surface), while 6.d still judges every effect per call. This step is policy-free and wires nothing.
  - **Signature.** `ScriptRunner::run(string $code, string $chdir, \Closure $dispatch, \Closure $catalog, ?SandboxBackend $jail, float $wallSeconds, ?\Closure $onWait = null): ScriptRun`.
  - **Setup.**
    - The code is staged 0600 in a per-run short `mkdtemp` directory under `sys_get_temp_dir()`.
    - A `stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM)` is created. The parent's end gets `ProcessContainment::closeOnExec()`; the child's end becomes descriptor 3.
  - **The spawn.** One `proc_open(ProcessContainment::spawnSpec([...$jailArgv, PHP_BINARY, '-n', '-d', 'display_errors=stderr', '-d', 'memory_limit=256M', '-r', ScriptPrelude::source(), '--', $staged]), [0 ⇒ pipe (closed at once), 1, 2 ⇒ pipes, 3 ⇒ child end], …, ProcessContainment::scrubbedEnv())`.
    - `$jailArgv = $jail->argv($scratch, false, null, $chdir)`, using C-TOOL-4.b's `$chdir` parameter. Only the scratch directory is writable; the project is visible read-only.
    - The caller passes a no-network backend. So the child's only route to the tree or the network is the bridge.
  - **The loop.** `stream_select` over stdout, stderr and fd 3 in 200 ms slices, calling `$onWait` once per slice. `ScriptBridge` decodes NDJSON frames:
    - `{"id","method":"call","params":{"name","arguments"}}` → `$dispatch()` → `{content,isError}`;
    - `"list"` → `$catalog()`, the name plus a one-line signature for each tool (Code Mode's budgeted signatures);
    - `"return"` carries the script's JSON return value.
  - **Bounds.** These are process bounds, not LLM bounds, so E646 is untouched.
    - The wall clock counts SCRIPT time only. It pauses while a bridged call is in flight, so a permission ask or a long inner Bash never kills the script. On expiry the recorded group gets TERM, then KILL.
    - Caps: 1 MiB per frame, 256 calls per run, 64 KiB each for stdout and stderr (the tail is kept and dropped bytes are counted), 64 KiB for the return value.
    - Each cap breach is a named entry in `ScriptRun`, never a silent cut.
  - **Cleanup.** `ProcessReaper::terminateAndClose($process, $groupId)` runs in a `finally`, and the scratch directory is removed.
  - **Prelude** (`ScriptPrelude::source()`).
    - It defines `tool(string $name, array $args = []): array`, a blocking request/response over `php://fd/3`, and `tools(): array`.
    - It `require`s the staged file, prepending `<?php` when the script lacks it, and sends the return value back.
    - With `-n` the child has core PHP plus json only, and the 6.d description says so.
  - **Result.** `ScriptRun` is a DTO: stdout, stderr, exit code, return JSON, a call ledger (name, ok/error/denied, bytes), timedOut, and any refusal.
- files.create: `candy-code/src/Tools/Script/ScriptRunner.php`, `candy-code/src/Tools/Script/ScriptBridge.php`, `candy-code/src/Tools/Script/ScriptPrelude.php`, `candy-code/src/Tools/Script/ScriptRun.php`, `candy-code/tests/Tools/Script/ScriptRunnerTest.php`, `candy-code/tests/Tools/Script/ScriptBridgeTest.php`
- files.modify: `candy-code/tests/Support/DescriptorInheritanceGuardTest.php` (a `SHORT_VIA_HELPER` row at :606: `Tools/Script/ScriptRunner.php::run`, count 1. It is reaped by `ProcessReaper::terminateAndClose()` in the `finally`, the same shape as the provider rows. The fd-3 entry is detail, not an exemption, per the file's own doctrine.)
- tests:
  - create `ScriptBridgeTest`: split and coalesced frames, an oversize frame refused, malformed JSON answered with an error frame, an unknown method.
  - create `ScriptRunnerTest`. The unjailed legs pass `null` (the runner holds no policy). It pins:
    - three `tool()` calls are answered in order;
    - `tools()` returns the injected catalog;
    - the return value round-trips;
    - stdout and stderr are captured and capped;
    - wall expiry kills the group and sets timedOut;
    - the clock pauses during a slow dispatch;
    - the 257th call is refused;
    - no orphan remains after `run()`.
  - A jailed leg is skipped unless bwrap probes. It proves that a `file_put_contents()` into `$chdir` fails and a `fsockopen('127.0.0.1')` fails, while the bridge still answers.
- docs/guards: `DescriptorInheritanceGuardTest` (the row), `ChildStderrCaptureTest` (stderr is piped and returned, never discarded), `ReadPathCensusTest` (only if it counts the staging write; the prelude's `require` sits inside a string literal), `tools/check-one-type-per-file.php` (four types, four files). No user docs: nothing is wired yet.
- serialized: [hot:candy-code/tests/Support/DescriptorInheritanceGuardTest.php]
- deps: C-TOOL-4.b (`SandboxBackend`/`SandboxFactory` and the `$chdir` parameter)
- phase: LATE
- ruling: Two questions. Script language: a PHP child (`PHP_BINARY -n`, always present; recommended) or node (absent on many hosts)? Isolation floor: when no jail probes (no bwrap, no `sandbox-exec`, Windows), does Script refuse (recommended: without the jail, plain PHP in the child writes the tree and every per-call gate is theatre), or run with an every-call Ask that shows the full source?
- risks: bwrap must pass the inherited fd 3 through; it does not close unnamed fds by default, and the jailed leg proves this per host. With `-n`, a script gets no mbstring or ctype on distros that ship those as shared extensions.

### C-TOOL-6.d — `Script` catalog tool: nested calls through the gate and hook chain
- item: C-TOOL-6 (sources: GAP-TL-N3, OC-new#12 (Code Mode))
- kind: gap · effort M
- approach:
  - **Tool.** `ScriptTool`: `#[BuiltInTool(name: 'Script', permission: Write, position: 44, gloss: 'run a short PHP script whose only effects are tool calls, each permissioned like a direct call')]`.
    - Args: `code` (≤ 64 KiB), `timeout` (seconds of script time; default 60, max 600), `description`.
    - It implements `Tool` and `BuildsFromCatalog`.
    - It implements `AcceptsHeartbeat`: the wait loop beats, so `completeAsync()`'s silence watchdog never takes a long script for a hung turn.
    - It implements `AcceptsWorktreeJail` (chdir becomes the worktree) and the new `DispatchesNestedTools`.
  - **The floor** (6.c's ruling). Script builds its own jail with `SandboxFactory::fromSetting('no-network')`, independent of `bashSandbox`, and probes it. If the probe fails, Script refuses and quotes the backend's `describe()` — or, if the owner rules otherwise, takes the every-call Ask arm instead.
  - **Interface.** `DispatchesNestedTools` is new, beside `DelegatesToEngine`: `withNestedDispatch(\Closure $call, \Closure $catalog): static`.
  - **Binding lives in Runtime, not `EngineBackend::turnTools()` (:3151).** This is a deviation from the brief, found on the tree.
    - The dispatcher needs the live `App`, the gate, the hook chain and `$onPermissionRequest`. Only `Runtime::executeSequentially()` (:2221) holds all four at call time; `turnTools()` runs before the turn's App exists.
    - So `executeSequentially()` binds the tool it finds.
    - Script is not `ParallelSafe`, so it is always a barrier (`runsConcurrently()`, :2199) and always reaches that arm.
    - Sub-agents build their own Runtime and bind the same way. `EngineBackend.php` is untouched.
  - **`Runtime::executeNested(ToolCall, App, ?callable)`** is new and private. It runs the `executeSequentially()` pipeline:
    - `findTool`, `malformedArguments`, `hookContext`;
    - `gate()`: the PreToolUse chain, PermissionGate and `settleAsk`;
    - `executeGuarded`;
    - `settle()`: PostToolUse and withholding.
    - It passes `$onEvent = null`, so no ToolStarted/ToolFinished frames go out for ids the model never issued. Inner ids are `<outer>~<n>`.
    - It refuses, as an error result, any target that is a `DispatchesNestedTools` (no recursion), a `DelegatesToEngine` (Task, Workflow, AskUser, PlanExit, the messaging tools, the Generate* tools, Canvas) or a `MutatesContextLedger` (Prune, Compress, Recall).
    - MCP bridges, `mcp_call`, the file tools, Bash and `user__*` all stay callable; that is the point of Code Mode.
    - A sub-agent's grant hook judges inner calls too.
  - **What the user sees.**
    - One Script row. Its ask shows the code.
    - Under `default`, each inner write asks again, exactly as a direct call would; an "always" grant covers both.
    - The result ends with a call ledger, e.g. `Edit src/a.php → ok`, `Bash … → denied (rule)`.
    - `plan` and `dont-ask` deny Script (Write class).
    - `auto` leaves Script itself unclassified (`SafetyClassifier::classify()` `default => null`) and judges every inner call. That is sound only because of the floor.
  - `Runtime::WRITE_CAPABLE_TOOL_NAMES` adds `Script`.
- files.create: `candy-code/src/Tools/BuiltIn/ScriptTool.php`, `candy-code/src/Tools/DispatchesNestedTools.php`, `candy-code/tests/Tools/BuiltIn/ScriptToolTest.php`, `candy-code/tests/Runtime/NestedToolDispatchTest.php`
- files.modify:
  - `candy-code/src/Runtime.php` (`WRITE_CAPABLE_TOOL_NAMES`, the binding in `executeSequentially()`, the new `executeNested()`).
  - `candy-code/docs/PERMISSIONS.md` (a "Script" paragraph: one ask for the script, one per inner write, the jail floor; plus the hand roster at :760).
  - Regenerated by gen-tool-docs: `candy-code/README.md`, `candy-code/docs/ARCHITECTURE.md`, `candy-code/docs/AGENTS_AUTHORING.md`, `candy-code/docs/SETTINGS.md`, plus the unanchored README counts at :176/:2629.
  - Word maps: `candy-code/tests/Config/ReadmeRosterDriftTest.php`, `candy-code/tests/Config/ReadmeSettingsTierClaimTest.php`, `candy-code/tests/Config/DocFigureProseDriftTest.php`.
- tests:
  - create `NestedToolDispatchTest` (a fake Script and fake inner tools on a real Runtime). It pins:
    - an inner Edit under `default` reaches `$onPermissionRequest`;
    - a deny rule refuses an inner call and carries its DenialKind;
    - a PreToolUse DENY hook blocks an inner call;
    - PostToolUse withholding replaces inner output;
    - Script, Task and Prune targets are refused;
    - inner ids are `~n`, and no inner ToolStarted frames go out;
    - `plan` and `dont-ask` refuse the outer call.
  - create `ScriptToolTest`. It pins:
    - the catalog entry (position 44, Write), the schema, the timeout clamp;
    - the floor refusal with a failing probe;
    - the ledger formatting;
    - a jailed end-to-end leg (skipped without bwrap) that Reads and Edits a temp file through the bridge.
- docs/guards: `ToolDocDriftTest`, `PermissionsIntroDriftTest` (PERMISSIONS.md:760), RuntimeTest's `testTheWriteToolRosterDoesNotDriftFromThePermissionGate` (same commit), the three word-map tests, `tools/check-one-type-per-file.php`
- serialized: [runtime:WRITE_CAPABLE_TOOL_NAMES,executeSequentially,executeNested(new)] [catalog] [docs:README.md] [docs:ARCHITECTURE.md] [docs:AGENTS_AUTHORING.md] [docs:PERMISSIONS.md] [docs:SETTINGS.md] [hot:candy-code/tests/Config/DocFigureProseDriftTest.php] [hot:candy-code/tests/Config/ReadmeRosterDriftTest.php] [hot:candy-code/tests/Config/ReadmeSettingsTierClaimTest.php]
- deps: C-TOOL-6.c
- phase: LATE
- ruling: none (6.c's floor ruling decides the refusal arm)
- risks: inner calls emit no live frames, so a long script shows only its own running row until it settles. If that proves too dark, a follow-up adds nested progress through `AcceptsToolProgress`; its web half is [protocol].

### C-TOOL-6.e — User-tier script tools from `~/.candy-code/tools/*.json`
- item: C-TOOL-6 (sources: GAP-PLG-04)
- kind: gap · effort M
- approach:
  - **Where they come from.** User tier only: `HomeDirectory::owned() . '/.candy-code/tools/*.json'`. A project `.candy-code/tools/` is never read.
  - **File shape.** One tool per file: `{"name","description","inputSchema","argv","timeout"?}`. Load-time validation:
    - `name` matches `^[a-z][a-z0-9_-]{0,47}$`;
    - `description` is 1..1024 characters;
    - `inputSchema` is `type: object`;
    - `argv` is a non-empty list of strings, and element 0 carries no placeholder, so the model never picks the program;
    - every `{{prop}}` names a declared property;
    - `timeout` is 1..600 s (default 60).
  - **Bad files.** A file that fails validation, or a duplicate name, is skipped and named once at launch through `Bootstrap::warnPermissionConfigInTranscript()` (Lang `cli.usertools.*`).
  - **Loading.**
    - `UserToolLoader` reads the directory once.
    - New `Bootstrap::userScriptTools(?string $root)` memoises the result per pid, the `mcpToolShape()` (:8310) idea. The wire roster is frozen for the session (the prompt-cache law); edits land on the next launch.
    - `LaunchContext::unfilteredTools()` appends `...Bootstrap::userScriptTools($root)` after `...Bootstrap::mcpTools($root)` (:1715), so `allowedTools`/`disabledTools` filter them like MCP bridges.
  - **`UserScriptTool`.** `NAME_PREFIX = 'user__'`; the wire name is `user__<name>`.
    - It implements `AcceptsHeartbeat` (`runCaptured()`'s `$onWait`) and `AcceptsWorktreeJail` (cwd becomes the worktree).
    - Args are validated against `required` and scalar types.
    - Scalars are substituted into `{{prop}}`. An element that is exactly an absent optional placeholder is dropped; array and object values are refused.
    - EVERY element is `escapeshellarg`'d, then joined, then wrapped through the `bashSandbox` backend (`SandboxFactory`, C-TOOL-4.b) with the project root writable, exactly as for Bash.
    - It runs through `CapturesProcessOutput::runCaptured()`: cwd is the root, the Bash output cap applies, the timeout is the tool's own, and `$envOverrides` carries `CANDY_CODE_TOOL_INPUT=<args JSON>`. That adds no new proc_open token.
    - A non-zero exit is an error result with the stderr tail.
  - **A `user__` name is a write, the MCP rule's twin.**
    - New `Runtime::USER_TOOL_PREFIX = UserScriptTool::NAME_PREFIX` sits beside `MCP_TOOL_PREFIX` (:754) and is read by `isWriteCapableTool()` (:1296).
    - `PermissionGate::isWriteTool()` (:1551) gains a `user__` arm, placed BEFORE the final literal `return str_starts_with($call->name, 'mcp__');`, because RuntimeTest's prefix pin (~:3780) matches that statement verbatim.
    - `evaluateAuto()` (:889) routes `user__*` into the MCP Ask branch. The classifier sees no shell text and would otherwise allow the call while resetting the breaker.
    - A `permissionRules` entry `Allow user__fmt` grants one tool.
  - **Docs.**
    - `docs/PLUGINS.md` gets a "User script tools" section: the shape, the prefix, the write class, the freeze, no project tier. Per the ruling, bundles are listed as a third declined surface.
    - `docs/PERMISSIONS.md` gets a `user__*` row beside the `mcp__*` row at :509, and the `auto` row at :204.
- files.create: `candy-code/src/Tools/UserTools/UserScriptTool.php`, `candy-code/src/Tools/UserTools/UserToolLoader.php`, `candy-code/src/Tools/UserTools/UserToolSpec.php`, `candy-code/tests/Tools/UserTools/UserToolLoaderTest.php`, `candy-code/tests/Tools/UserTools/UserScriptToolTest.php`, `candy-code/tests/Permissions/UserToolPermissionTest.php`
- files.modify: `candy-code/src/Cli/Bootstrap.php` (new `userScriptTools()`), `candy-code/src/Cli/LaunchContext.php` (`unfilteredTools()`), `candy-code/src/Runtime.php` (`USER_TOOL_PREFIX`, `isWriteCapableTool()`), `candy-code/src/Permissions/PermissionGate.php` (`isWriteTool()`, `evaluateAuto()`), `candy-code/lang/en.php` (`cli.usertools.*`), `candy-code/docs/PLUGINS.md`, `candy-code/docs/PERMISSIONS.md`, `candy-code/tests/Support/ReadPathCensusTest.php` (an `OWNED_HOME` row for the loader's read)
- tests:
  - create `UserToolLoaderTest`:
    - the validation matrix;
    - the project tier is ignored;
    - an unowned home yields nothing;
    - a duplicate is refused;
    - notices route through Lang;
    - the roster stays memoised after a mid-session file edit.
  - create `UserScriptToolTest`:
    - an argument like `; rm -rf /` stays one argv element;
    - a placeholder in element 0 is refused at load;
    - an absent optional placeholder is dropped;
    - array arguments are refused;
    - the env carries the JSON;
    - a timeout kills the group;
    - a non-zero exit is an error;
    - a sandbox-wrapped leg (skipped without bwrap).
  - create `UserToolPermissionTest`:
    - `default` asks;
    - `plan` and `dont-ask` deny;
    - `auto` asks and leaves the breaker counters untouched;
    - `Allow user__fmt` allows;
    - `Runtime::stepRequestedAWrite([user__x])` is true;
    - the gate still holds the literal `mcp__` statement.
- docs/guards: `ReadPathCensusTest` (`OWNED_HOME`), `LangParityTest`, `tools/check-one-type-per-file.php`. `DescriptorInheritanceGuardTest` and `ChildStderrCaptureTest` are unchanged: `runCaptured()` is an accounted site. No built-in catalog, so no gen-tool-docs.
- serialized: [bootstrap:userScriptTools(new)] [runtime:USER_TOOL_PREFIX(new),isWriteCapableTool] [lang:cli.usertools.] [docs:PLUGINS.md] [docs:PERMISSIONS.md] [hot:candy-code/src/Cli/LaunchContext.php] [hot:candy-code/src/Permissions/PermissionGate.php] [hot:candy-code/tests/Support/ReadPathCensusTest.php]
- deps: C-TOOL-4.b
- phase: LATE
- ruling: May user-tier JSON define model-callable exec tools (recommended: yes — user tier only, write class, `auto` asks)? May plugin bundles ship them (recommended: no — a third declined surface beside `hooks/` and `.mcp.json`)?
- risks: tests must never see a developer's real `~/.candy-code/tools/`. The loader keys off `HomeDirectory::owned()`, which the suites already sandbox; one test asserts that sandboxing.

### C-TOOL-6.f — `Canvas` tool core (HTML artifact into the session MediaStore)
- item: C-TOOL-6 (sources: GAP-TL-24)
- kind: gap · effort M
- approach:
  - **Tool.** `CanvasTool`: `#[BuiltInTool(name: 'Canvas', permission: NoAsk, position: 45, gloss: 'save a self-contained HTML page as a session artifact the web client can display')]`.
    - Args: `html` (required) and `title` (≤ 80 chars; used in the result text only).
    - It refuses empty content, non-UTF-8 content, and anything over `CanvasTool::MAX_BYTES` (512 KiB).
    - It does not sanitise: the TUI never renders the page, and the web half will use a sandboxed, no-same-origin iframe.
  - **Permission class: NoAsk, not Read** (a deviation from the brief). `ToolPermissionClass.php:15-25` defines Read as "changes nothing" and NoAsk as "writes only state the harness owns". The session media directory is harness state — the same argument RuntimeTest's roster comment makes for `GenerateImage`, which is Ask only because it dials a server (`GenerateImage.php:104`). Both classes are allowed in every mode, so no prompt either way; NoAsk is the honest label.
  - **Session id.** Canvas implements `DelegatesToEngine` only to read the bound engine's `sessionId()`, exactly as GenerateImage does (`GenerateImage.php:153,575`), with the same fallback folder.
    - `EngineBackend::turnTools()` already binds every such tool, so the engine needs no edit.
    - Accepted side effect: EngineExecutor keeps DelegatesToEngine tools out of sub-agent toolsets.
  - **Store.** New `MediaStore::putDocument(string $bytes, string $ext): string` returns the absolute path.
    - It reuses `normalizeExtension()`, `resolveTarget()` and `publish()`: mode 0600, a `.partial` file renamed into place, the collision window.
    - The id is `MediaStore::idOf(basename)`, so `media.fetch` can already serve it by id.
  - **No `MediaKind::Html` in this step** (a deviation from the brief, found on the tree).
    - `Protocol/Schema/Definitions.php:196` builds `MEDIA_REF.kind` from `MediaKind::cases()`. A new case therefore reshapes `docs/protocol/candy-code.v1.schema.json` and `generated.ts` (ProtocolSchemaDriftTest), which is a [protocol] lane.
    - It would also widen the `mediaKinds` provider vocabulary (`CapabilityDiscoverer.php:86`, `tryFrom`).
    - The case lands with the web/protocol half, together with `TranscriptProjector::mediaRef()`'s kind arm (:357, today "not video ⇒ image").
  - **Not projected yet.** The result sets no `imagePath`, so `TranscriptProjector::mediaRefs()` (:301) emits nothing and the TUI never tries to paint HTML. The user sees `Canvas saved — <title>: artifact <id>, <path> (<n> bytes)`.
  - **Roster.** RuntimeTest's not-a-tree-write roster (`readOnlyBuiltInToolNames()`, :4161) adds `Canvas`, with a sentence citing the GenerateImage doctrine. The source scan then holds, because CanvasTool calls no write primitive itself; MediaStore does the writing.
- files.create: `candy-code/src/Tools/BuiltIn/CanvasTool.php`, `candy-code/tests/Tools/BuiltIn/CanvasToolTest.php`
- files.modify:
  - `candy-code/src/Support/MediaStore.php` (new `putDocument()`), `candy-code/tests/Support/MediaStoreTest.php` (putDocument legs), `candy-code/tests/RuntimeTest.php` (`readOnlyBuiltInToolNames()`).
  - Docs: `candy-code/docs/MEDIA.md` (a Canvas paragraph: where the HTML lands, the cap, the sweep, "not yet shown by the web client"), `candy-code/docs/PERMISSIONS.md` (the hand roster at :760). Regenerated by gen-tool-docs: `candy-code/README.md`, `candy-code/docs/ARCHITECTURE.md`, `candy-code/docs/AGENTS_AUTHORING.md`, `candy-code/docs/SETTINGS.md`, plus the unanchored README counts at :176/:2629.
  - Word maps: `candy-code/tests/Config/ReadmeRosterDriftTest.php`, `candy-code/tests/Config/ReadmeSettingsTierClaimTest.php`, `candy-code/tests/Config/DocFigureProseDriftTest.php`.
- tests:
  - create `CanvasToolTest`. It pins:
    - the catalog entry (position 45, NoAsk);
    - the file lands under the bound session's directory with mode 0600;
    - the result carries the id and path;
    - refusals: empty, invalid UTF-8, over the cap;
    - the fallback session when unbound;
    - no `imagePath` on the result;
    - `pathFor(id)` resolves the file.
  - modify `MediaStoreTest`: putDocument's extension check, collision numbering, partial-file cleanup.
- docs/guards: `ToolDocDriftTest`, `PermissionsIntroDriftTest`, RuntimeTest's `testEveryToolOnTheReadOnlyListCallsNoWritePrimitiveInItsOwnSource` and its write-roster drift test (NoAsk, so Canvas stays out of `WRITE_CAPABLE`), the three word-map tests, `docs/MEDIA.md` in-step
- serialized: [catalog] [docs:README.md] [docs:ARCHITECTURE.md] [docs:AGENTS_AUTHORING.md] [docs:PERMISSIONS.md] [docs:SETTINGS.md] [docs:MEDIA.md] [hot:candy-code/src/Support/MediaStore.php] [hot:candy-code/tests/RuntimeTest.php] [hot:candy-code/tests/Config/DocFigureProseDriftTest.php] [hot:candy-code/tests/Config/ReadmeRosterDriftTest.php] [hot:candy-code/tests/Config/ReadmeSettingsTierClaimTest.php]
- deps: none. This is the core; the web/protocol half (`MediaKind::Html`, the projector arm, CanvasCard, CSP) is a later step that depends on this one.
- phase: LATE
- ruling: none
- risks: until the web half lands, the artifact is visible only as a path. A `media.fetch` of its id today labels it `kind: image`, `application/octet-stream` (the projector's else-arm). That is harmless while no event advertises the id.

### C-TOOL-6.g — Canvas web half: `MediaKind::Html`, sandboxed `/canvas/{token}` page, `CanvasCard`
- item: C-TOOL-6 (sources: GAP-TL-24)
- kind: gap · effort M
- approach:
  - **Kind.** `MediaKind::Html = 'html'` (`src/Media/MediaKind.php`), plus `isGenerative(): bool` (true for Image and Video only).
    - The enum doubles as the `mediaKinds` provider vocabulary, so `CapabilityDiscoverer::fromConfig()` (:86, `tryFrom`) would accept `"html"` as a generation modality. It now refuses any non-generative kind, with its existing "is not a known modality" error.
    - `ProviderFactory::configuredMediaKinds()` hard-codes image/video and needs no change.
    - `Definitions.php:196` derives `MEDIA_REF.kind` from `cases()`, so the gen chain moves (`kind: "image" | "video" | "html"`).
  - **Projection.** CanvasTool (6.f) now returns `imagePath: <stored .html path>` with `imageBytes: null`.
    - This is TUI-safe: every painter gates on `ToolResult::hasImage()` (bytes, `src/ToolResult.php:281`), never on the path, and the provider wires read bytes only.
    - `TranscriptProjector::mediaRef()` (:351-364) gains an Html arm keyed on the extension (`html` → `MediaKind::Html`), and `MEDIA_MIME_BY_EXTENSION` (:70) gains `text/html; charset=utf-8`.
    - After C-WEB-1.d, a store-contained path with no bytes must still project, because the store is what makes the card loadable. This step pins that.
    - `media.fetch` reads the same formatter and needs no edit.
  - **Why an HTTP route, not `srcdoc` or `blob:`.**
    - Both inherit the app's CSP (`script-src` falls back to `'self'`, `Responses.php:33`), so canvas scripts would never run.
    - A same-origin frame without a sandbox could read the login secret the page keeps in localStorage (ISS-007).
  - **Mint.** `POST /api/canvas {sessionId, artifactId}`.
    - It sits behind AuthMiddleware like every `/api/*` path (cookie+secret or bearer; Origin checked).
    - It resolves `MediaStore::forSession($sessionId)->pathFor($artifactId)` (the `media.fetch` precedent). Anything not ending `.html` gets the single `not_found` answer.
    - It answers `{url: "/canvas/<token>", expiresInSeconds: 60}`.
    - New `CanvasLinks` wraps `ExpiringSecrets::new(60, $clock, 256)` (`src/Server/Auth/ExpiringSecrets.php`), holding `sessionId\nartifactId`. It lives in the serve PARENT. MediaStore's root is global, keyed by session id, so a gateway-routed root needs nothing relayed.
  - **Serve.** `GET|HEAD /canvas/{token}`, public like `/s/{token}` (the token is the capability).
    - The link is single use (`consume()`), and the file is re-resolved per request. Unknown, expired and spent tokens all answer one identical 404.
    - Headers come from a new `Responses::CANVAS_HEADERS`:
      - `Content-Security-Policy: sandbox allow-scripts; default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data: blob:; font-src data:; media-src data: blob:; connect-src 'none'; form-action 'none'; base-uri 'none'; frame-ancestors 'self'`;
      - `X-Frame-Options: SAMEORIGIN`, `Cache-Control: no-store`, `Content-Type: text/html; charset=utf-8`.
    - `secured()` (:64) keeps explicit headers, so the app's `DENY`/`frame-ancestors 'none'` never reach this response. The app CSP itself is unchanged: `frame-src` falls back to `default-src 'self'`.
  - **Wiring.**
    - `Router` gets an optional `?\Closure $canvas` arm, placed ahead of the api/static fall-through (the share precedent).
    - `Server::new()` (:153) takes it and passes it to the Router built at :218.
    - `Serve.php` builds the controller beside the share controller (:406) and passes it at :455.
  - **Web.**
    - New `CanvasCard.vue` is click-to-load, like MediaCard's video. On click it mints a link through a new `canvasLink()` in `protocol/auth.ts`, which sits beside `ticket()` (:125) and reuses its private `post()`.
    - It shows `<iframe sandbox="allow-scripts" referrerpolicy="no-referrer">`: no `allow-same-origin`, popups, forms or top navigation. Links are single use, so it mints again on every mount.
    - `ToolCard.vue`'s media loop (:86-88) sends `kind === 'html'` refs to CanvasCard.
    - `TranscriptItem.vue` needs no edit (it only mounts ToolCard). This corrects the brief.
  - **What the user sees:** the web transcript's Canvas row gets a "Show canvas" card that renders the page in a locked-down frame. The TUI keeps 6.f's text line.
- files.create: `candy-code/src/Server/Http/CanvasPageController.php`, `candy-code/src/Server/Http/CanvasLinks.php`, `candy-code/tests/Server/CanvasPageTest.php`, `candy-code/tests/Server/CanvasLinksTest.php`, `candy-code-web/src-web/components/CanvasCard.vue`, `candy-code-web/src-web/components/__tests__/canvasCard.spec.ts`
- files.modify:
  - Media: `candy-code/src/Media/MediaKind.php` (the case, `isGenerative()`), `candy-code/src/Media/Capability/CapabilityDiscoverer.php` (`fromConfig()`), `candy-code/src/Tools/BuiltIn/CanvasTool.php` (created by 6.f: sets `imagePath`), `candy-code/src/Host/TranscriptProjector.php` (`MEDIA_MIME_BY_EXTENSION`, the `mediaRef()` kind arm).
  - Server: `candy-code/src/Server/Http/Router.php` (canvas arm), `candy-code/src/Server/Http/Responses.php` (`CANVAS_HEADERS`), `candy-code/src/Server/Server.php` (`new()` and ctor param, the Router construction), `candy-code/src/Cli/Serve.php` (:406, :455).
  - Docs: `candy-code/docs/MEDIA.md` (6.f's Canvas paragraph: "not yet shown by the web client" becomes how the web shows it), `candy-code/docs/SERVER.md` (two hand rows in the "HTTP endpoints" table, plus the regenerated tables), `candy-code/docs/protocol/candy-code.v1.schema.json` (regen).
  - Web: `candy-code-web/src-web/protocol/generated.ts` (regen), `candy-code-web/src-web/protocol/auth.ts` (`canvasLink()`), `candy-code-web/src-web/components/ToolCard.vue` (the media loop).
- tests:
  - create `CanvasPageTest` (on `tests/Server/SharePageTest.php`'s fixture). It pins:
    - a mint without a credential is refused, and a non-`.html` or unknown artifact is `not_found`;
    - the GET carries the exact sandbox CSP and SAMEORIGIN, and no `DENY`;
    - a second GET of the same token 404s, an expired token 404s, and the three 404 bodies are byte-identical;
    - a POST to `/canvas/…` is 405.
  - create `CanvasLinksTest`: TTL, single use, value binding, the capacity bound.
  - create `canvasCard.spec.ts`:
    - nothing loads before the click;
    - the `sandbox` attribute is exactly `allow-scripts`;
    - the src is the minted url;
    - a remount mints again;
    - a refusal shows an error state.
  - modify:
    - `candy-code/tests/Host/TranscriptProjectorMediaTest.php`: html kind and mime; a bytes-less store path projects;
    - `candy-code/tests/Tools/BuiltIn/CanvasToolTest.php`: 6.f's "no imagePath" pin flips;
    - `candy-code/tests/Media/CapabilityDiscovererTest.php`: `"html"` is refused;
    - `candy-code/tests/Protocol/MediaMethodsTest.php`: a Canvas id fetches with the html mime;
    - `candy-code-web/src-web/components/__tests__/components.spec.ts`: ToolCard sends html refs to CanvasCard and image refs to MediaCard;
    - `candy-code-web/src-web/protocol/__tests__/auth.spec.ts`: `canvasLink()` posts with the secret header and maps refusals.
- docs/guards: `ProtocolSchemaDriftTest`, `ServerProtocolDocumentationDriftTest` (regen in-step), `docs/MEDIA.md` in-step, `tools/check-one-type-per-file.php` (two new classes). No proc_open; no Lang keys (HTTP refusals are kind+message per `Responses`; the card's strings are web-side).
- serialized: [protocol] [docs:MEDIA.md] [docs:SERVER.md] [hot:candy-code/src/Host/TranscriptProjector.php] [hot:candy-code/src/Server/Server.php] [hot:candy-code/src/Cli/Serve.php] [hot:candy-code-web/src-web/components/ToolCard.vue] [hot:candy-code-web/src-web/protocol/auth.ts]
- deps: C-TOOL-6.f (CanvasTool, `MediaStore::putDocument()`), C-WEB-1.d (rewrites `mediaRefs()`/`mediaRef()` first)
- phase: LATE
- ruling: none
- risks: a sandboxed frame still shares the tab's event loop, so a busy-looping page can stall the UI; hence click-to-load. Single-use links mean a manual "reload frame" shows the 404 until the card is shown again.

### C-TOOL-6.h — Client-hosted tools, engine + host core (`client__*` proxies over the turn channel)
- item: C-TOOL-6 (sources: OC-new#2)
- kind: gap · effort M
- approach:
  - **Shape.** An attached client registers tool descriptors for one session. The next turn offers each one as `client__<name>`. A call travels turn child → serve parent → that client, and the answer comes back the same way. This step builds everything except the protocol door (6.i), so no proxy can exist until 6.i lands.
  - **Host core** (`src/Host/ClientTools/`):
    - `ClientToolSpec` is a validated descriptor: `name` matches `^[a-z][a-z0-9_]{0,47}$`; `description` is 1..1024 characters; `inputSchema` is `type: object`, ≤ 16 KiB; `timeoutSeconds` is 1..600 (default 120).
    - `ClientToolRegistry`, one per SessionHost:
      - `register(string $clientKey, list<ClientToolSpec>): string $registrationId` replaces that client's set (≤ 16 tools). A name another client already holds is refused.
      - `forget($clientKey)` drops a client's set.
      - `proxies(): list<ClientToolProxy>` and `owner(string $name): ?string` answer the lookups.
    - `ClientToolProxy` (Tool, `NAME_PREFIX = 'client__'`):
      - It is not `ParallelSafe`, so it is always a barrier and runs in the turn child, where the channel lives.
      - It implements `DelegatesToEngine` with a no-op `withEngine()`, purely for the side effect AskUserTool documents: EngineExecutor withholds engine-bound tools from Task sub-agents and workflow stages. A sub-agent must not drive the user's browser.
      - It implements the new seam `RelaysClientToolCalls::withClientCaller(\Closure): Tool`.
  - **Why new frame kinds and not the approver closure.**
    - `RelaysPermissionAsks` (`src/Tools/RelaysPermissionAsks.php`) hands a tool the turn's approver. Its answer is an `ApprovalVerdict` with a clipped note (`ChildChannel::clipNote()`), and the question surfaces as `permission.requested` to every client. A tool result is neither.
    - So the call rides the SAME socket (ChildChannel, the 1.C ask channel) with two new kinds:
      - `client_call` (child→parent): `callId`, `toolCallId`, `name`, `arguments`, `deadlineSeconds`;
      - `client_result` (parent→child): `callId`, `content`, `isError`.
    - `ChildChannel::callClient()` blocks like `ask()` (:265) and buffers any steer or cancel frames that overtake the reply. EOF settles as an error result, never a success.
    - `clientCaller()` hands out the closure the way `approver()` (:253) does.
  - **EngineBackend.**
    - The child setup (:4903-4915) binds `->withClientCaller($channel->clientCaller())` beside the approver.
    - `turnTools()` (:3151) binds `RelaysClientToolCalls` tools next to the approver arm (:3278).
    - The parent frame loop gains a `client_call` arm beside the `ask` arm (:4188). It builds a `PendingClientCall` (new, `src/Backend/`, the `PendingAsk` twin; `resolve()` is once-only and writes `client_result`). Then:
      - the call counts with the pending asks, so the idle ceiling pauses (:4082);
      - a loop timer at `deadlineSeconds` resolves it as an error ("the client did not answer within N s");
      - every leftover is resolved as an error when the turn settles.
    - The sink is a new `withClientCallSink(?\Closure)`. With none bound, the call is answered at once: "no client is attached to serve client__x".
    - The deadline is a TOOL deadline on a person's or a browser's answer, not an LLM timeout, so E646 is untouched.
    - An unforked blocking turn (no ext-pcntl) has no channel. The proxy refuses there rather than block the loop.
  - **Host binding.** `SessionHost` owns the registry and gains four methods:
    - `registerClientTools()`;
    - `forgetClientTools()`, which resolves that client's pending calls as errors;
    - `resolveClientCall(callId, registrationId, content, isError): bool`. The first answer wins, and a wrong registrationId reads the same as a stale id;
    - `onClientCall(?\Closure)`, for 6.i.
    - It passes the registry and its sink into `TurnRunner::backendForTurn()` (:276, called at `SessionHost.php:1773`). That appends `Bootstrap::filterToolSet($registry->proxies())` via `withTools()`, so `disabledTools: ["client__*"]` removes them.
    - A registration takes effect at the NEXT turn, because the roster is fixed per dispatch.
  - **Write class.**
    - New `Runtime::CLIENT_TOOL_PREFIX = ClientToolProxy::NAME_PREFIX` sits beside `MCP_TOOL_PREFIX` (:754) and is read by `isWriteCapableTool()` (:1296).
    - `PermissionGate::isWriteTool()` (:1551) gets a `client__` arm BEFORE the literal `mcp__` return, which RuntimeTest pins verbatim.
    - `evaluateAuto()` (:880) routes `client__*` to Ask before the classifier. The classifier sees no shell text and would otherwise allow the call and reset the breaker.
    - The result: `default` asks, `plan` and `dont-ask` deny, and `Allow client__browser_context` grants one tool.
    - `ToolDocGenerator.php:176` prints "and anything starting `mcp__`" for the `tools:classes` region, and gains `client__` there.
- files.create: `candy-code/src/Host/ClientTools/ClientToolSpec.php`, `candy-code/src/Host/ClientTools/ClientToolRegistry.php`, `candy-code/src/Host/ClientTools/ClientToolProxy.php`, `candy-code/src/Tools/RelaysClientToolCalls.php`, `candy-code/src/Backend/PendingClientCall.php`, `candy-code/tests/Host/ClientTools/ClientToolRegistryTest.php`, `candy-code/tests/Host/ClientTools/ClientToolProxyTest.php`, `candy-code/tests/Backend/ForkChannelClientCallRoundTripTest.php`, `candy-code/tests/Permissions/ClientToolPermissionTest.php`
- files.modify:
  - Engine: `candy-code/src/Backend/ChildChannel.php` (the two kinds, `callClient()`, `clientCaller()`, the frame table in the class docblock), `candy-code/src/Backend/EngineBackend.php` (`withClientCallSink()`, `withClientCaller()`, `turnTools()`, the completeAsync frame loop, the child setup).
  - Host: `candy-code/src/Host/SessionHost.php` (the registry, the four methods, the `backendForTurn` call at :1773), `candy-code/src/Host/TurnRunner.php` (`backendForTurn()` params).
  - Permissions: `candy-code/src/Runtime.php` (`CLIENT_TOOL_PREFIX`, `isWriteCapableTool()`), `candy-code/src/Permissions/PermissionGate.php` (`isWriteTool()`, `evaluateAuto()`).
  - Docs: `candy-code/src/Tools/Catalog/ToolDocGenerator.php` (the prefix tail at :176), `candy-code/docs/PERMISSIONS.md` (a `client__*` row beside the `mcp__*` row at :509; the `auto` row at :204; the regenerated `tools:classes` region).
  - Tests: `candy-code/tests/RuntimeTest.php` (the prefix pin).
- tests:
  - create `ClientToolRegistryTest`: the spec validation matrix, per-client replace, a cross-client name conflict, `forget`, the 16-tool cap, `client__` naming.
  - create `ClientToolProxyTest`: the schema passes through; refusal without a caller; content/isError mapping; not ParallelSafe; withheld from a sub-agent toolset.
  - create `ForkChannelClientCallRoundTripTest` (the `ForkChannelAskRoundTripTest` shape, a real fork). It pins:
    - the child's call reaches the sink, and the resolve comes back as content;
    - the deadline timer answers with an error;
    - a steer that overtakes the reply is buffered;
    - parent EOF gives an error;
    - the idle ceiling is suspended while a call is pending (the `ForkChannelIdleTimerSuspendedTest` precedent);
    - with no sink, "no client attached" comes back at once.
  - create `ClientToolPermissionTest`: `default` asks; `plan`/`dont-ask` deny; `auto` asks with the breaker untouched; `Allow client__x` allows; `Runtime::stepRequestedAWrite([client__x])` is true; the literal `mcp__` statement still holds.
  - modify `candy-code/tests/Host/SessionHostTest.php`: a registration reaches the next turn's tools; `disabledTools` drops it; `forget` fails the pending calls; the first result wins.
- docs/guards: `ToolDocDriftTest` (`php tools/gen-tool-docs.php --write` moves only PERMISSIONS.md's `tools:classes` line), RuntimeTest's prefix and write-roster pins, `tools/check-one-type-per-file.php`. No proc_open (the frames ride the existing turn socket); no user-facing strings (the proxy's refusals are model-facing results).
- serialized: [runtime:CLIENT_TOOL_PREFIX(new),isWriteCapableTool] [docs:PERMISSIONS.md] [hot:candy-code/src/Backend/EngineBackend.php] [hot:candy-code/src/Backend/ChildChannel.php] [hot:candy-code/src/Permissions/PermissionGate.php] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Host/TurnRunner.php] [hot:candy-code/src/Tools/Catalog/ToolDocGenerator.php] [hot:candy-code/tests/RuntimeTest.php]
- deps: C-TOOL-6.e (edits the same `isWriteTool()`/`isWriteCapableTool()`/`evaluateAuto()` lines and the RuntimeTest prefix pin)
- phase: LATE
- ruling: Which client may register tools, and is the feature off by default? Recommended:
  - any client holding Write scope on the session may register, and its registrations die with its connection;
  - the feature is OFF by default, behind a user-config-only `server.clientTools: false`. A project config can never turn it on;
  - every `client__*` call is write class, so `default` asks.
- risks: a misbehaving client can only make its own tools answer badly or late: the deadline and the turn's cancel bound every call, and the result is model-facing text like any tool output.

### C-TOOL-6.i — Client-hosted tools: `tools.client.*` protocol, `server.clientTools` gate, web store + sample tool
- item: C-TOOL-6 (sources: OC-new#2)
- kind: gap · effort M
- approach:
  - **Gate (per 6.h's ruling).**
    - New user-config-only setting `server.clientTools` (Bool, default false, `SettingCategory::Server`, `RiskClass::Security`), defined beside `server.dirBrowse` (`ServerSettings.php:133`).
    - `ServerConfig::resolve()` reads it the same way as dirBrowse (:227), into a new `$clientTools` property with `withClientTools()`.
    - When it is off, every `tools.client.*` call answers `forbidden` with kind `client_tools_disabled`.
  - **Methods.** A new `Protocol/Methods/ClientToolMethods.php`, registered in `Dispatcher::methods()` (:126):
    - `tools.client.register {sessionId, tools: [{name, description, inputSchema, timeoutSeconds?}]}` (Write scope):
      - calls `SessionHost::registerClientTools($call->client->id(), …)`;
      - returns `{registrationId, names: ["client__…"], effective: "next_turn"}`;
      - an empty `tools` list withdraws the set.
    - `tools.client.result {sessionId, callId, registrationId, content (≤ 256 KiB), isError?}` (Write scope):
      - calls `resolveClientCall()` and returns `{accepted}`;
      - a stale or foreign id is `accepted: false`, never an error that says which.
  - **Event.** `tool.client.call` carries `{callId, registrationId, name, arguments, deadlineAt}`.
    - It needs a new `SessionEvent::TOOL_CLIENT_CALL`, an `EventType::CATALOGUE` row `[false, session, …]` (ephemeral like `tool.progress`, :62), and an `EventSchemas` row.
    - `ServerContext::feedFor()` (:290) binds `$host->onClientCall()` to `$host->announce()` (`SessionHost.php:395`). Delivery is therefore the feed's own path, and gateway fan-out works unchanged.
    - The client whose registrationId matches answers; every other client ignores the event.
    - The event is not journaled: a call replayed after a reconnect could not be answered, because the registration died with the socket.
  - **Disconnect.** `ServerContext::removeClient()` (:229) calls `forgetClientTools($client->id())` on every open host. A browser that goes away fails its pending calls at once, not at the deadline.
  - **Web.**
    - **New `stores/clientTools.ts`.** It holds a table of local implementations.
      - `attach(sessionId)` registers them through `connection.request('tools.client.register', …)`. `stores/session.ts` calls it after each successful `subscribe()`, so a resync registers again.
      - It listens through `connection.onEvent` (the `stores/approvals.ts:117` pattern) for `tool.client.call` events with its registrationId, runs the handler under the event's deadline, and answers with `tools.client.result`.
      - A `client_tools_disabled` refusal marks the store off for that connection, without retry.
    - **One sample tool**, `lib/clientTools/browserContext.ts` → `client__browser_context`: locale, time zone, viewport, device pixel ratio, colour-scheme preference and online state. Nothing private.
    - A file-picker tool needs a user gesture to open the picker, so it is a follow-up.
    - **No `stores/reducer.ts` edit** (correcting the brief). The reducer ignores unknown events, the store subscribes on its own, and the transcript already shows the call as an ordinary `tool.started`/`tool.finished` row.
  - **What the user sees:** with `"server.clientTools": true` in `~/.candy-code/config.json`, the model can call `client__browser_context`. Under `default` it asks first, then the answer shows like any tool row.
- files.create: `candy-code/src/Protocol/Methods/ClientToolMethods.php`, `candy-code/tests/Protocol/ClientToolMethodsTest.php`, `candy-code-web/src-web/stores/clientTools.ts`, `candy-code-web/src-web/lib/clientTools/browserContext.ts`, `candy-code-web/src-web/stores/__tests__/clientTools.spec.ts`
- files.modify:
  - Setting: `candy-code/src/Config/Settings/Definitions/ServerSettings.php`, `candy-code/src/Server/ServerConfig.php` (`resolve()`, the new property and `withClientTools()`), `candy-code/lang/en.php` (`settings.server.clientTools.*`).
  - Protocol: `candy-code/src/Protocol/Dispatcher.php` (`methods()`), `candy-code/src/Protocol/Schema/MethodSchemas.php`, `candy-code/src/Protocol/Schema/EventSchemas.php`, `candy-code/src/Protocol/EventType.php` (CATALOGUE), `candy-code/src/Host/SessionEvent.php` (the const), `candy-code/src/Protocol/ServerContext.php` (`feedFor()`, `removeClient()`).
  - Docs: `candy-code/docs/SETTINGS.md` + `candy-code/README.md` (via `php tools/gen-settings-doc.php --write`), `candy-code/docs/SERVER.md` (a short "Client-hosted tools" paragraph, plus the regenerated tables), `candy-code/docs/protocol/candy-code.v1.schema.json` (regen).
  - Web: `candy-code-web/src-web/protocol/generated.ts` (regen), `candy-code-web/src-web/stores/session.ts` (`subscribe()`), `candy-code-web/README.md` (one feature bullet).
- tests:
  - create `ClientToolMethodsTest` (on the `tests/Server/Support/ProtocolFixture.php` + `ScriptedTurnBackend.php` fixtures). It pins:
    - a call is refused while the setting is off, and a Read-scope client is refused;
    - after a register, the next turn's backend offers `client__x`;
    - `tool.client.call` reaches only the session's subscribers and carries the registrationId;
    - the first result wins, and a wrong registrationId is `accepted: false`;
    - a disconnect fails the pending call at once.
  - create `clientTools.spec.ts`:
    - it registers after subscribe;
    - it answers only its own registrationId;
    - a handler that overruns the deadline is answered as an error;
    - the disabled kind stops retries;
    - the sample's payload shape.
  - modify `candy-code/tests/Cli/ServeArgsTest.php` (the key's bool validation), and `candy-code/tests/Protocol/EventTypeTest.php` if it pins the catalogue size.
- docs/guards: `ProtocolSchemaDriftTest`, `ServerProtocolDocumentationDriftTest`, `SettingsSchemaDocDriftTest`, `TrustKeyDocumentationDriftTest` (a Security-class key), `LangParityTest`, `tools/check-one-type-per-file.php`
- serialized: [protocol] [lang:settings.server.clientTools.] [docs:SETTINGS.md] [docs:README.md] [docs:SERVER.md] [hot:candy-code/src/Protocol/ServerContext.php] [hot:candy-code/src/Server/ServerConfig.php] [hot:candy-code-web/src-web/stores/session.ts]
- deps: C-TOOL-6.h
- phase: LATE
- ruling: none (6.h's ruling sets the scope and the default)
- risks:
  - Under the multi-root gateway, a workspace-host child sees the gateway as its only client. A browser leaving a gateway-routed root therefore triggers no `removeClient()` there, and its pending calls end at the deadline instead.
  - A registration changes the tool roster, so that session's next turn misses the prompt cache once.

### C-TOOL-6.j — PTY relay core for interactive background shells
- item: C-TOOL-6 (sources: GAP-SH-01)
- kind: gap · effort M
- approach:
  - **Why a relay.** A pty master is an fd in the process that opened it. Bash runs in a tool or turn child that exits when the call returns, so the master has to live in a process of its own that outlives every call. The same reason gives a plain background shell file sinks and no pipes (`BackgroundShellRegistry::start()` :254).
  - **`PtyRelay`** (new, `src/Background/PtyRelay.php`) is a PHP process.
    - **Spawn.** It opens the pty at the requested size (`\SugarCraft\Pty\Pty::open($cols, $rows)`, `sugar-pty/src/Pty.php:59`) and spawns `['bash', '-c', $command]` on it with a controlling terminal (`Pty::spawn()`, which forks through `sugar-pty/src/Spawn.php`; that lib's own roster accounts for it).
      - The relay records the child's exit status from its own wait.
      - So `buildWrapper()`'s trailer (:371) is not used, and neither is `ulimit -f`, whose SIGXFSZ would kill the relay, not the shell.
    - **I/O.** It writes the pty child's pid to `<id>.pid`, appends master output to `<id>.out`, and feeds `<id>.in` to the master. The FIFO is opened `r+`, so the open never blocks and the relay never sees EOF when a writer closes.
    - **Bounds** (process bounds; E646 untouched):
      - the lifetime wall `MAX_LIFETIME_SECONDS` (:142), enforced as TERM then KILL on the child's group;
      - the output cap of `OUTPUT_LIMIT_BLOCKS * 512` bytes (:161). A breach kills the child and records `OUTPUT_LIMIT_EXIT_CODE` (:173), so `outputLimitNote()` narrates it exactly as for a plain shell.
    - **Exit.** When the child exits, the relay writes `<id>.exit` and exits.
    - **Signals.**
      - A `pcntl_signal(SIGTERM)` handler forwards TERM to the child's group (a pty child leads its own session), waits 2 s, then KILLs.
      - If the relay itself is SIGKILLed, the master closes and the kernel hangs up the pty session.
  - **Start.** New `startInteractive(string $command, string $label, int $cols = self::INTERACTIVE_COLS, int $rows = self::INTERACTIVE_ROWS, array $envOverrides = [], ?string $owner = null)`; the owner comes from C-TOOL-3.c.
    - It refuses (an `ok: false` answer, no spawn) without `ProcessContainment::interactiveAvailable()` (:538), `posix_mkfifo` or `pcntl_signal`.
    - It mints the id and the sinks (`sinkFiles()`, plus `<id>.in` through `posix_mkfifo(…, 0600)`). The command is staged in `<id>.cmd` (0600; the relay unlinks it after reading), so no command text rides argv or environ.
    - It spawns `ProcessContainment::spawnSpec([PHP_BINARY, '-d', 'display_errors=stderr', '-r', 'require <autoload>; exit(\…\PtyRelay::main());', '--', $dir, $id, $cols, $rows])`.
      - This is the `WorkspaceHostClient.php:119-125` precedent, using `BackgroundSupervisor::autoloadPath()` (:1011).
      - stdin and stdout are /dev/null; stderr is `<id>.err`.
    - **No hidden `bin/candy-code` verb.** The `-r` + autoloader form works the same in a checkout and in the phar, and leaves [headless] untouched.
    - The row records `interactive: true`, `cols`, `rows`, `ptyPidPath` and `inPath`. Its `grouped` flag comes from C-TOOL-4.h's `groupsSpawns()`.
  - **Input.** New `send(string $shellId, string $bytes, string $origin = 'model'): array{ok, error?, cursor?}`.
    - It refuses an unknown or foreign-owner id, a non-interactive row, a settled row, and more than 4096 bytes.
    - It answers the stdout size BEFORE the write as `cursor`, so the caller reads what followed from there.
    - The write is non-blocking, through a fresh `r+` open of the FIFO.
    - It stamps `lastInputAt`; `origin: 'human'` also bumps `humanInputs`.
    - 6.k and 6.l are its only callers.
  - **Teardown parity.**
    - `runKillLadder()` (:792) also signals the recorded pty pid's group for an interactive row, as a backstop to the relay's own forwarding.
    - `sweepCorpseFiles()` (:406) learns the `.in`, `.cmd` and `.pid` suffixes.
    - `toArray()`, `merge()` and `parseRow()` (:1015) carry the new fields across a fork.
  - Nothing is reachable yet: Bash refuses the combination until 6.k.
- files.create: `candy-code/src/Background/PtyRelay.php`, `candy-code/tests/Sessions/PtyRelayTest.php`, `candy-code/tests/Sessions/BackgroundShellInteractiveTest.php`
- files.modify:
  - `candy-code/src/Background/BackgroundShellRegistry.php`: `startInteractive()`, `send()`, `runKillLadder()`, `sweepCorpseFiles()`, `pathFor()`, `toArray()`/`merge()`/`parseRow()`, and two constants.
  - `candy-code/tests/Support/DescriptorInheritanceGuardTest.php`: an `ACCOUNTED_FOR` row `Background/BackgroundShellRegistry.php::startInteractive`, count 1, beside the `::start` row (:174). Its reason: a long-lived relay with file sinks, group-killed by ShellStop, the wall and the launch teardown.
  - `candy-code/tests/Support/ReadPathCensusTest.php`: `SELF_LOCATED` rows for the relay's `.cmd` read and its FIFO/`.out` opens, for `send()`'s FIFO open and the registry's `.pid` read, and the `scandir` sweep row's suffix list.
- tests:
  - create `PtyRelayTest` (gated on `interactiveAvailable()` and pcntl; every child reaped in `tearDown`). It pins:
    - text written to the FIFO comes back in `.out`;
    - `stty size` reports the requested geometry;
    - the exit file carries `exit 7`;
    - TERM on the relay's group ends the child;
    - the output cap kills the child and records 153;
    - a SIGKILLed relay leaves no child behind;
    - `.cmd` is gone after start.
  - create `BackgroundShellInteractiveTest`. It pins:
    - the refusal arms;
    - the row shape and its fork round trip;
    - `send()` refuses non-interactive, settled, oversize and foreign-owner input;
    - the cursor/`read()` pairing;
    - `humanInputs` counting;
    - `stop()` kills the relay and the pty group;
    - the sweep removes a corpse FIFO.
  - modify `candy-code/tests/Sessions/BackgroundShellRegistryTest.php` (the sweep-pattern pin).
- docs/guards: `DescriptorInheritanceGuardTest` (the row), `ChildStderrCaptureTest` (the relay's stderr is a file sink, never discarded), `ReadPathCensusTest`, `tools/check-one-type-per-file.php`. No user docs, since nothing is reachable until 6.k.
- serialized: [hot:candy-code/src/Background/BackgroundShellRegistry.php] [hot:candy-code/tests/Support/DescriptorInheritanceGuardTest.php] [hot:candy-code/tests/Support/ReadPathCensusTest.php]
- deps: C-TOOL-3.c (owner tagging), C-TOOL-4.h (`groupsSpawns()` and the Darwin prefix in `spawnSpec()`); both edit BackgroundShellRegistry.php
- phase: LATE
- ruling: none
- risks:
  - The relay needs ext-ffi (sugar-pty), ext-pcntl and ext-posix in the CLI, and refuses without them.
  - A pty child that ignores SIGHUP survives a SIGKILLed relay until the registry's ladder signals the recorded pid group. On Darwin, `groupMemberAlive()` cannot read `/proc`, so that pid file is the only handle.

### C-TOOL-6.k — `ShellSend` tool, Bash interactive background runs, `/shells send`
- item: C-TOOL-6 (sources: GAP-SH-01)
- kind: gap · effort M
- approach:
  - **Bash.** The refusal at `Bash.php:515-521` becomes a route. `run_in_background` + `interactive` now calls `startInteractive()` (6.j) with the same sandbox-prefixed command string the plain background path uses, so `bashSandbox` still applies.
    - The result names the shell id and says "type into it with ShellSend".
    - The `interactive` and `run_in_background` schema descriptions say so too.
  - **Tool.** `ShellSend`: `#[BuiltInTool(name: 'ShellSend', permission: Write, position: 43, gloss: 'type keystrokes into an interactive background shell started with Bash run_in_background and interactive')]`.
    - Args:
      - `shell_id`;
      - `keys`, in PtyRun's step grammar: `PtyRun::decode()` (`PtyRun.php:304`) over `KEY_TOKENS` (:95), at most 4096 decoded bytes;
      - `wait_ms`, 0..10000, default 500.
    - It implements `Tool`, `BuildsFromCatalog` and `AcceptsShellOwner` (C-TOOL-3.c).
    - It calls `send(…, 'model')` and waits `wait_ms`. It then returns the output that followed, read through `read()` from the returned cursor, stripped with `\SugarCraft\Core\Util\Ansi::strip()` (`sugar-core/src/Util/Ansi.php:912`) and capped like ShellOutput.
    - It refuses:
      - an unknown or foreign-owner id;
      - a non-interactive shell ("start it with interactive: true; a plain background shell has no terminal to type into");
      - a settled shell;
      - empty or oversize keys.
  - **Permissions, and why ShellSend stays OUT of `ShellSubjectTools` (`['Bash','PtyRun']`).** The class docblock carries this argument.
    - That roster means "the subject IS a command line handed to `/bin/sh -c`". Membership makes any rule naming `Bash` bind the tool by symmetry. It also feeds `classifyBash()`, the argument-scoped session memo and the modal's subject lines.
    - ShellSend spawns nothing. Its keys land in whatever program already owns the pty (a REPL, a pager, `vim`), and the Bash call that started that program was judged when it ran. Judging the keys as a shell line would:
      - let `Allow Bash(git *)` grant typing `git …` into a Python REPL;
      - let `auto` "classify" REPL input as safe shell.
      Membership would therefore make allow rules over-grant.
    - Instead, ShellSend follows the keystroke rule PtyRun's steps already follow. `PermissionGate::decide()`'s `$typesKeystrokes` (:629-633) and `evaluateAuto()`'s keystroke arm (:900-906) both include `ShellSend`. So:
      - it asks every time in every non-bypass mode, and no session "always" covers it;
      - `auto` never allows it and never resets its breaker on it;
      - only an explicit `permissionRules` entry answers first.
    - The unswitchable floor still holds. `isRmRfRootOrHome()` (:1246) gains a ShellSend arm that runs each decoded line through the same `ShellWords` check, so `rm -rf /` typed into a shell trips it even under bypass.
    - `Runtime::WRITE_CAPABLE_TOOL_NAMES` (:704) adds `ShellSend` (Write class; its drift test pins this).
  - **ShellOutput.** On an interactive row it ANSI-strips the display text and notes the terminal size. When `humanInputs` has grown it adds "the user typed into this shell N times since your last read" (6.j counts; 6.l feeds).
  - **`/shells send <id> <keys…>`** in `ShellsCommand` (created by C-TOOL-3.d).
    - The rest of the line is keys in the same grammar, sent with `origin: 'human'`.
    - The read-only judgement needs no Chat.php edit. 3.d's arm routes through `ShellsCommand::isReadOnlyVerb()` (the `/schedules` precedent, `Chat.php:10334`), which leaves `send` out.
    - `cmd.shells.hint` gains the verb.
- files.create: `candy-code/src/Tools/BuiltIn/ShellSend.php`, `candy-code/tests/Tools/ShellSendTest.php`, `candy-code/tests/Tools/BashInteractiveBackgroundTest.php`, `candy-code/tests/Permissions/ShellSendPermissionTest.php`
- files.modify:
  - Tools: `candy-code/src/Tools/BuiltIn/Bash.php` (the refusal becomes a route; the schema text), `candy-code/src/Tools/BuiltIn/ShellOutput.php` (the interactive display), `candy-code/src/Runtime.php` (`WRITE_CAPABLE_TOOL_NAMES`).
  - Permissions: `candy-code/src/Permissions/PermissionGate.php` (`decide()`, `evaluateAuto()`, `isRmRfRootOrHome()`).
  - Command: `candy-code/src/Host/Commands/ShellsCommand.php` (created by C-TOOL-3.d: the `send` verb), `candy-code/lang/en.php` (`cmd.shells.hint`, `host.shells.send.*`).
  - Docs:
    - `candy-code/docs/PERMISSIONS.md`: the hand roster at :760, and a ShellSend sentence beside PtyRun's keystroke rule. That sentence must say an explicit `Allow ShellSend` covers every shell and every key.
    - Regenerated by `php tools/gen-tool-docs.php --write`: `candy-code/README.md`, `candy-code/docs/ARCHITECTURE.md`, `candy-code/docs/AGENTS_AUTHORING.md`, `candy-code/docs/PERMISSIONS.md`, `candy-code/docs/SETTINGS.md`.
    - Regenerated by `php tools/gen-command-docs.php --write`: `candy-code/README.md` + `candy-code/docs/COMMANDS.md`.
    - Hand-edit the unanchored README tool counts. `grep -n 'thirty' README.md` finds :1401, :1402, :1411 and :2629 at HEAD; 6.b, 6.d and 6.f move them first.
  - Word maps: `candy-code/tests/Config/ReadmeRosterDriftTest.php`, `candy-code/tests/Config/ReadmeSettingsTierClaimTest.php`, `candy-code/tests/Config/DocFigureProseDriftTest.php`.
- tests:
  - create `ShellSendTest`:
    - the catalog entry (position 43, Write);
    - grammar decode (`<Enter>`, `<C-c>`);
    - each refusal;
    - a live leg, gated like `PtyRunToolTest`, where `cat` echoes what was sent and `wait_ms` returns it stripped.
  - create `BashInteractiveBackgroundTest`:
    - the combination now starts a relay row;
    - the staged command carries the sandbox prefix;
    - ShellOutput strips ANSI and reports `humanInputs`.
  - create `ShellSendPermissionTest`:
    - `default` asks even with a session "always";
    - `auto` asks and leaves the breaker counters alone;
    - `plan` and `dont-ask` deny;
    - `Allow ShellSend` allows, and `Allow Bash(*)` does NOT cover ShellSend;
    - `rm -rf /<Enter>` is denied under bypass.
  - modify `candy-code/tests/Tools/BashBackgroundTest.php` (the refusal pin becomes the route pin), `candy-code/tests/Tools/ShellOutputTest.php`, `candy-code/tests/Host/Commands/ShellsCommandTest.php` (created by 3.d: the `send` verb, foreign-owner refusal, read-only refusal).
- docs/guards: `ToolDocDriftTest`, `CommandDocsDriftTest`, `PermissionsIntroDriftTest` (PERMISSIONS.md:760), RuntimeTest's write-roster drift test (same commit), the three word-map tests, `ShellSubjectToolsCompletenessTest` (stays green: the roster is unchanged; re-run because the new gate arms sit beside its sites), `LangParityTest`, `MenuBarLabelDerivationTest` (if it pins the hint), `tools/check-one-type-per-file.php`
- serialized: [runtime:WRITE_CAPABLE_TOOL_NAMES] [catalog] [lang:cmd.shells.,host.shells.send.] [docs:README.md] [docs:ARCHITECTURE.md] [docs:AGENTS_AUTHORING.md] [docs:PERMISSIONS.md] [docs:SETTINGS.md] [docs:COMMANDS.md] [hot:candy-code/src/Tools/BuiltIn/Bash.php] [hot:candy-code/src/Permissions/PermissionGate.php] [hot:candy-code/tests/Config/DocFigureProseDriftTest.php] [hot:candy-code/tests/Config/ReadmeRosterDriftTest.php] [hot:candy-code/tests/Config/ReadmeSettingsTierClaimTest.php]
- deps: C-TOOL-6.j, C-TOOL-3.d
- phase: LATE
- ruling: none
- risks: a forced ask on every keystroke call makes a long REPL session chatty. The escape hatch is an explicit `Allow ShellSend` rule, which covers every shell and every key.

### C-TOOL-6.l — Web terminal input: `shells.input` + a polling terminal in the Processes section
- item: C-TOOL-6 (sources: GAP-SH-05, p2-miss: WEB-8 Terminal panel (scoped down))
- kind: gap · effort M
- approach:
  - **What this is, plainly.** A polling, line-oriented terminal over the session's interactive background shells.
    - The browser reads the shell's output sink through `shells.output` on a timer and writes into the pty through `shells.input`.
    - It is NOT PTY-over-WebSocket and not a terminal emulator. WEB-8's full Terminal panel stays out until a server PTY channel exists (p2-miss).
    - Full-screen programs (vim, htop) show as escape-stripped noise; drive those with PtyRun or ShellSend, or in the TUI.
    - The method description, the component docblock and the candy-code-web README all say so.
  - **Method.** `shells.input {sessionId, shellId, text?, key?}` in `ShellsMethods` (created by C-TOOL-3.e), Write scope.
    - `text` is literal UTF-8, at most 4096 bytes, with no NUL.
    - `key` is an enum over a subset of `PtyRun::KEY_TOKENS` (:95): Enter, Esc, Tab, Backspace, Up, Down, Left, Right, C-c, C-d, C-z.
    - At least one of the two is required. The bytes sent are `text . KEY_TOKENS[key]`, so the input line sends `{text: line, key: "Enter"}` in one call.
    - It resolves the session's registry and owner like the other `shells.*` methods (C-TOOL-3.c/3.e), then calls `send($id, $bytes, 'human')` (6.j). It returns `{accepted: true, cursor}`.
    - A non-interactive or settled shell answers `conflict`, with kind `shell_not_interactive` or `shell_settled`.
    - **Human-originated, so no permission gate.** A person typing is the authority the gate defers to, as with a permission answer; the Write scope is the gate.
    - **Audited**, two ways:
      - one server-log line per call through a new `ServerContext::logLine()` beside `logError()` (:114): method, session, shell id, client id, principal and byte count, never the bytes (Appendix O §8.6 keeps bodies out of logs);
      - `send()`'s `humanInputs` stamp (6.j), which ShellOutput (6.k) reports to the model, so the agent learns the user typed into its shell.
  - **Web.**
    - `ProcessesSection.vue` (created by 3.e) expands an interactive running row into a tail pane.
      - It polls `shells.output {shellId, cursor}` every 750 ms, only while the row is expanded and the panel is open (3.e's polling discipline), and keeps the last 64 KiB.
      - It renders through a new `ansiSegments()` in `lib/ansi.ts`. SGR bold, dim, italic, underline, inverse and 16-colour foreground/background become classes. Every other sequence is removed by the existing `SEQUENCES` regex (:9). A lone `\r` or a backspace rewrites the current line.
      - Below the pane sit an input line (Enter sends) and key buttons (Ctrl-C, Ctrl-D, Tab, Esc, ↑, ↓). Without Write scope they are disabled, like 3.e's Stop.
      - Non-interactive rows keep the plain tail and get no input.
    - `stores/agents/shells.ts` (3.e) gains `input()` and `tail()`.
- files.create: `candy-code/tests/Protocol/ShellsInputTest.php`, `candy-code-web/src-web/stores/agents/__tests__/shellsInput.spec.ts`
- files.modify:
  - Server: `candy-code/src/Protocol/Methods/ShellsMethods.php` (created by C-TOOL-3.e: `shells.input`), `candy-code/src/Protocol/Schema/MethodSchemas.php`, `candy-code/src/Protocol/ServerContext.php` (new `logLine()`).
  - Generated: `candy-code/docs/protocol/candy-code.v1.schema.json` + `candy-code/docs/SERVER.md` (regen), `candy-code-web/src-web/protocol/generated.ts` (regen).
  - Web: `candy-code-web/src-web/stores/agents/shells.ts` (3.e), `candy-code-web/src-web/components/panels/ProcessesSection.vue` (3.e), `candy-code-web/src-web/lib/ansi.ts` (`ansiSegments()`), `candy-code-web/README.md` (one bullet, with the "polling, not PTY-over-WS" sentence).
- tests:
  - create `ShellsInputTest`:
    - a Read-scope client is refused, and so is a foreign owner;
    - `text` + `key` reach the FIFO in order (a live leg gated on pty availability);
    - non-interactive and settled shells answer their kinds;
    - NUL and oversize text are refused;
    - exactly one log line, with the byte count and no content;
    - `humanInputs` increments.
  - create `shellsInput.spec.ts`: the `input`/`tail` request shapes, the cursor advance, the buffer cap.
  - modify `candy-code-web/src-web/components/panels/__tests__/processesSection.spec.ts` (created by 3.e):
    - the tail pane appears only for interactive rows;
    - polling stops on collapse and on panel close;
    - Enter sends `{text, key: 'Enter'}`;
    - the key buttons;
    - Write-scope gating.
  - modify `candy-code-web/src-web/lib/__tests__/ansi.spec.ts`: SGR classes, non-SGR removal, CR overwrite, backspace.
- docs/guards: `ProtocolSchemaDriftTest`, `ServerProtocolDocumentationDriftTest` (regen in-step). No proc_open; no Lang keys (the refusals are protocol kinds).
- serialized: [protocol] [docs:SERVER.md] [hot:candy-code/src/Protocol/ServerContext.php]
- deps: C-TOOL-6.k, C-TOOL-3.e
- phase: LATE
- ruling: none
- risks: 750 ms polling makes typing feel laggy against echo-heavy programs; a push channel is the WEB-8 follow-up, not this step. Pasted text over 4096 bytes is refused rather than chunked.

## Notes for the orchestrator

- **Serialized surfaces, most-touched first:**
  - `[runtime]`: 1.c, 7.e, 2.d, 6.b, 6.d, 6.e, 6.h, 6.k.
  - `[docs:SETTINGS.md]` / `[docs:README.md]`: every setting- or tool-adding step. They are regenerated by `gen-settings-doc` / `gen-tool-docs`, so if two land in one wave, regenerate at weld.
  - `[protocol]`: 3.e, 6.g, 6.i, 6.l. One per wave.
  - `[catalog]`: 6.b, 6.d, 6.f, 6.k.
  - `[bootstrap]`, by method: backgroundShells (7.a), lspClient (7.c, 4.e), workspace (3.d), hooks (5.b, 4.d), egressProxy (4.g), userScriptTools (6.e).
  - `[headless]`: 7.d, 5.d, 4.a, 4.b.
  - `[chat]`: 3.b (fold arms), 3.d (handleShellsCommand / isReadOnlySafeCommand).
  - `[renderer]`: 3.b.
- **Hot files, not in the set, that need a one-lane-per-wave rule:**
  - `candy-code/src/Background/BackgroundShellRegistry.php`: 1.a → 7.a → 3.c → 4.h → 6.j, a strict chain already encoded in deps.
  - `candy-code/src/Backend/EngineBackend.php`: 2.d, 3.c, 6.h, and likely C-PRV/C-AGT steps.
  - `candy-code/src/Providers/Concerns/AnthropicMessagesWire.php`: 2.c, 2.d, and likely C-PRV.
  - `candy-code/src/Providers/AttachmentEncoding.php`: 2.d, 2.e, 2.g.
  - `candy-code/src/Providers/OpenAIProvider.php`: 2.e and C-PRV-2.
  - `candy-code/src/LSP/LspLauncher.php`: 7.c → 5.c → 4.e.
  - `candy-code/src/Tools/BuiltIn/Read.php`: 2.d → 6.b.
  - `candy-code/src/Tools/BuiltIn/Bash.php`: 7.a, 3.c, 4.a, 4.b, 6.k.
  - `candy-code/tests/Support/DescriptorInheritanceGuardTest.php`: 4.f, 6.c, 6.j.
  - `candy-code/src/Config/LayeredSettings.php`: its generated block, plus the `LayeredSettingsTest` key list.
  - `candy-code/src/Permissions/PermissionGate.php`: 6.b, 6.e, 6.h, 6.k.
  - `candy-code/src/Host/TranscriptProjector.php`: 6.g, after C-WEB-1.
  - `candy-code/src/Protocol/ServerContext.php`: 6.i, 6.l, C-WEB-1.a.
  - `candy-code/src/Cli/LaunchContext.php`: 4.g, 6.e.
- **Cross-area deps:**
  - C-PRV-2 → 2.g. The Responses wire path is assumed to be `src/Providers/Concerns/OpenAiResponsesWire.php`; confirm against design-prv.
  - C-WEB-1(.d) → 6.g. TranscriptProjector media refs and MediaStore.
  - C-MCP-1.a and C-MCP-3.a → 4.e. StdioMcpServer and McpClient.
  - C-EXT-1.a / 6.a / 6.c → 4.d. ScriptHook and HookManager.
  - 3.c should land before P-SESSION-TABS, which needs per-session shell isolation.
- **Tool position roster.** 41 is reserved for C-AGT-5's Schedule tool. 42 NotebookEdit (6.b), 43 ShellSend (6.k), 44 Script (6.d), 45 Canvas (6.f).
- **Every new built-in carries the same guard work:**
  - three tests spell the tool count in words: `ReadmeRosterDriftTest`, `ReadmeSettingsTierClaimTest` (both stop at 32) and `DocFigureProseDriftTest` (holds 33/34/36 only), so each needs extending;
  - `PermissionsIntroDriftTest` guards a hand list at PERMISSIONS.md:760;
  - C-AGT-5's Schedule tool is subject to all of the above too.
- **Doctor-row collision.** C-TUI-5.a also plans a `sandbox` doctor probe and moves `doctorProbes()` into `DoctorChecks.php`. C-TOOL-4.a owns the row; C-TUI-5.a should drop its sandbox bullet and carry the row through the extraction. The TROUBLESHOOTING.md check count "fifteen" → "sixteen" is pinned by `DocFigureProseDriftTest`.
- **Child-lifetime roster.** `tools/check-child-lifetimes.php` EXCLUDES candy-code. candy-code spawn sites are rostered in `candy-code/tests/Support/DescriptorInheritanceGuardTest.php` (`ACCOUNTED_FOR` / `SHORT_VIA_HELPER`); that is where the brief's "roster row" lands for every candy-code step.
- **Fix-ups carried for the lanes, not edited into their blocks:**
  - 6.e must also add `user__` to the "anything starting `mcp__`" text at `src/Tools/Catalog/ToolDocGenerator.php:176`, which is generated into PERMISSIONS.md; 6.h does the same for `client__`.
  - The MCP stdio spawn seam is `src/MCP/StdioMcpServer.php::spawnPlan()`, not `McpClient.php`; the `proc_open` itself lives in sugar-mcp-client.
- **Owner rulings queued:**
  - **5.c / 5.d:** the LSP download policy and the server list.
  - **6.c:** Script language and isolation floor. Recommended: a PHP child that refuses without a sandbox.
  - **6.e:** may user-tier JSON define exec tools (recommended: yes), and may bundles ship them (recommended: no)?
  - **6.h:** client-tool registration scope and default. Recommended: Write scope; off by default behind `server.clientTools: false`; every `client__*` call is a write.
  - **7.c (soft):** gate auto-detect on hooks trust or on `trustedProjectSettings`.
- **Phase rule.** Two LATE items have normal-phase slivers, and no normal step depends on a LATE step:
  - **4.a:** S, no setting, no spawn, no ruling; it surfaces the Ubuntu 24.04 AppArmor refusal before the jail silently refuses every Bash call.
  - **6.a:** S and doc-only.
- **Step counts above the brief's "2–4 per L item":**
  - C-TOOL-4 bundles five distinct sub-features: the seam/Seatbelt, runner jails, egress, hardening and setsid. Merging them would produce L steps.
  - C-TOOL-6 is split per tool type, as the owner asked.
- **Not verified:**
  - live behaviour of bash procsub `wait` on macOS bash 3.2 (7.a's sink-only cap has a documented fallback);
  - the OpenAI chat `file` part on non-official OpenAI-compatible hosts (2.e keeps SGLang and Custom declined);
  - the Responses wire file path (2.g).
- **COMPARISON.md:** cell flips for TL-12/13/15/19/20/21/24/25/N3, SH-01/04/05/06/07/08, EDT-02/04/07/10, MCP-14 and PLG-04 are collected at the gate, not in lanes.
