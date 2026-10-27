# Design — Providers & models (C-PRV-1…5) and Context, memory & instructions (C-CTX-1…6)

Read-only design pass, 2026-10-26, tree `master @ 5e9195687`. All paths are repo-root-relative and were checked in the live tree: every MODIFY path exists, and every CREATE path is absent. `file:line` citations refer to that HEAD.

Two findings change the scope of the candidate blocks:
- **C-CTX-5:** the "cheaper summary model" and "cache-reusing summaries" halves are already shipped. `summaryModel` / `CANDY_CODE_SUMMARY_MODEL` is in `src/Config/Settings/Definitions/ModelProviderSettings.php:87-95`. `Bootstrap::summaryBackend()` → `src/Backend/CacheReusingSummaryBackend.php` sends `/compact` and the 85% tier through the main engine's cached prefix. Summary failures already fold heuristically: `CompactionService::summaryRejection()`, the error route, and the idle ceiling in `EngineBackend::summariseAsync()`. What remains is listed in C-CTX-5.a–d.
- **C-CTX-2.a:** the per-session sink seam already exists and has no production caller. `NoticeSink` holds per-instance state, and `RuntimeNoticeSink::using()` sits at `src/Diagnostics/RuntimeNoticeSink.php:715`. So the first served step really is S.

## Summary

| step id | title | effort | serialized tags | deps |
|---|---|---|---|---|
| C-CTX-2.a | Drain the runtime-notice sink in serve | S | [docs:SERVER.md] [docs:MEMORY.md] [hot:SessionHost.php] | none |
| C-CTX-1.a | Prime the instruction-diff tracker before the fork | M | [runtime:primeInstructionDiff, instructionDiff] [hot:EngineBackend.php] [hot:InstructionDiffTracker.php] | none |
| C-CTX-1.b | Cursor glob rules fire; off rules explained | S | [lang:cmd.rules.*] [docs:MEMORY.md] [hot:Rule.php] | none |
| C-CTX-2.b | `Host\TurnSettleWork` gate (core) | M | none | none |
| C-CTX-2.c | Auto-memory, dream, auto-commit on served settles | M | [docs:MEMORY.md] [docs:SERVER.md] [hot:SessionHost.php] | C-CTX-2.a, C-CTX-2.b |
| C-CTX-2.d | `prompt.suggested` + `context.changed` events | M | [protocol] [docs:SERVER.md] [hot:SessionHost.php] [hot:Composer.vue] [hot:contextSources.ts] | C-CTX-2.b, C-CTX-2.c |
| C-CTX-2.e | TUI settle consumes the same gate | S | [chat:scheduleAutoCommit, schedulePromptSuggestion] [hot:HandlesTurnCompletion.php] | C-CTX-2.b |
| C-CTX-2.f | "Loaded" / "updated" instruction rows | S | [lang:chat.instructions.*] [docs:MEMORY.md] [hot:InstructionFileLoader.php] [hot:InstructionDiffTracker.php] | C-CTX-2.a, C-CTX-1.a |
| C-CTX-1.c | Track `@import` children in the diff | S | [docs:MEMORY.md] [hot:InstructionDiffTracker.php] [hot:InstructionFileLoader.php] | C-CTX-1.a |
| C-PRV-1.a | `EffortGate` + per-model shaper predicate; Bedrock/Codex/claude-code effort | M | [lang:settings.reasoningEffort.*] [docs:SETTINGS.md] [docs:ADDING_A_PROVIDER.md] [hot:provider files] | none |
| C-PRV-1.b | Honest `/effort` and launch tier | S | [chat:handleEffortCommand, withEngineSettings] [bootstrap:withResolvedReasoningEffort] [lang:chat.effort.*] [hot:LaunchContext.php] | C-PRV-1.a |
| C-PRV-1.c | Safe `candy-code login` (merge, no echo) | S | [headless] [lang:cli.sub.login.*] | none |
| C-PRV-5.a | Per-family thinking ceilings and omission | M | [docs:SETTINGS.md] [hot:Anthropic/Gemini wires, ProviderFactory.php] | C-PRV-1.a |
| C-PRV-5.b | No thinking budget on title/aux backends | S | [bootstrap:toollessBackend, titleBackend, summaryBackend] [docs:SETTINGS.md] | none |
| C-PRV-5.c | Non-blocking model-catalog prefetch | M | [chat:pickPaletteProvider, update dispatch arm] [hot:OpenAIProvider.php] | none |
| C-PRV-5.d | Gemini schema, catalog order doc, codex wording | S | [docs:README.md] [docs:SETTINGS.md] [hot:GeminiGenerateContentWire.php] | none |
| C-PRV-2.a | OpenAI Responses wire (core) | M | [docs:SETTINGS.md] [docs:ADDING_A_PROVIDER.md] [hot:OpenAIProvider.php] [hot:ProviderFactory.php] | C-PRV-1.a |
| C-PRV-2.b | Responses reasoning replay + image tool outputs | M | [hot:OpenAIProvider.php] [hot:Message.php] | C-PRV-2.a |
| C-PRV-2.c | First-class `azure` and `openrouter` types | M | [headless] [docs:README.md] [docs:ARCHITECTURE.md] [docs:ENVIRONMENT.md] [docs:ADDING_A_PROVIDER.md] [lang] [hot:ProviderFactory.php] [hot:OpenAIProvider.php] | none |
| C-PRV-3.a | `serviceTier` setting + provider rebinding (core) | M | [docs:SETTINGS.md] [docs:ENVIRONMENT.md] [lang:settings.serviceTier.*] [hot:EngineBackend.php] [hot:OpenAIProvider.php] [hot:AnthropicProvider.php] [hot:ModelProviderSettings.php] [hot:LayeredSettings.php] | none |
| C-PRV-3.b | `/fast` command (TUI + served) | S | [chat:handleFastCommand (new)] [commands] [lang:cmd.fast.*, host.fast.*] [docs:COMMANDS.md] [docs:README.md] | C-PRV-3.a |
| C-PRV-3.c | `ultrathink` keyword raises effort for one turn | S | [lang:chat.effort.ultrathink*] [docs:SETTINGS.md] [hot:TurnRunner.php] | C-PRV-1.a, C-PRV-5.a |
| C-PRV-3.d | Advisor tool | M | [catalog] [bootstrap:advisorBackend (new)] [lang] [docs:README.md] [docs:SETTINGS.md] [docs:ENVIRONMENT.md] [hot:LaunchContext.php] [hot:ModelProviderSettings.php] [hot:LayeredSettings.php] | none |
| C-PRV-4.a | Host permission mode + isolation for claude-code `bare:false` | M | [docs:README.md] [docs:SETTINGS.md] [lang] [hot:EngineBackend.php] [hot:ClaudeCode*.php] [hot:ProviderFactory.php] | none |
| C-PRV-4.b | Codex isolation, mode → `-s`, `--output-schema` | M | [docs:README.md] [docs:SETTINGS.md] [hot:Codex*.php] [hot:ProviderFactory.php] | C-PRV-4.a |
| C-CTX-6.a | Served idle alarm survives a running turn | S | [hot:SessionHost.php] | none |
| C-CTX-6.b | PDF page check and aggregate document budget | M | [lang:chat.attach.*] [docs:MEDIA.md] [hot:EngineBackend.php] | none |
| C-CTX-6.c | Context nit bundle (plural, docblock, hints) | S | [runtime:announceMemoryRecall] [commands] [lang] [docs:SETTINGS.md] [docs:CONTEXT.md] | none |
| C-CTX-6.d | `/memory open` edits enter the memory journal | S | [chat:memoryOpenArm] [hot:EditorCommand.php] | none |
| C-CTX-6.e | Structured, expandable recall row | M | [runtime:announceMemoryRecall] [renderer] [chat:pumpRuntimeNotices] [lang] [hot:SessionHost.php] | C-CTX-6.c, C-CTX-2.a |
| C-CTX-3.a | `/instructions [open]` command | M | [chat:handleInstructionsCommand (new), memoryOpenArm] [commands] [lang:cmd.instructions.*, host.instructions.*] [docs:COMMANDS.md] [docs:README.md] [docs:MEMORY.md] [hot:EditorCommand.php] [hot:ContextSources.php] | C-CTX-6.d, C-CTX-1.a |
| C-CTX-3.b | `#` quick-add to personal instructions (TUI) | S | [chat:submit] [lang:chat.instructions.quick_add*] [docs:MEMORY.md] | C-CTX-1.a |
| C-CTX-3.c | Web instruction edit + `#` quick-add | M | [protocol] [docs:SERVER.md] [docs:MEMORY.md] [hot:Composer.vue] [hot:ContextPanel.vue] | C-CTX-1.a |
| C-CTX-3.d | Model recall over past sessions and the journal | M | [docs:MEMORY.md] [docs:README.md] [hot:MemoryTool.php] | C-SES-2 (other area) |
| C-CTX-3.e | Memory export + prompt-visible import scope | S | [lang:host.memory.*] [docs:MEMORY.md] | none |
| C-CTX-3.f | Standing intents | M | [lang:host.memory.intent*] [docs:MEMORY.md] [hot:MemoryTool.php] [hot:RulePathNudge.php] | none |
| C-CTX-4.a | Live date + knowledge-cutoff note | S | [runtime:turnContext] [docs:PROMPT_ENGINEERING.md] [hot:EnvironmentBlock.php] | none |
| C-CTX-4.b | `SOUL.md` / `USER.md` persona files | S | [docs:MEMORY.md] [hot:InstructionFileLoader.php] [hot:ContextSources.php] | none |
| C-CTX-4.c | Walk past the git root; absolute/`~` sources | S | [bootstrap:instructionLoader, forcedInstructions] [docs:SETTINGS.md] [docs:MEMORY.md] [hot:InstructionFileLoader.php] [hot:MemoryRuleSettings.php] [hot:LayeredSettings.php] | none |
| C-CTX-4.d | URL instruction sources + shared egress guard | M | [docs:MEMORY.md] [docs:SETTINGS.md] [hot:InstructionFileLoader.php] [hot:WebFetch.php] | C-CTX-4.c |
| C-CTX-4.e | Output styles (`/output-style`) | M | [chat:handleOutputStyleCommand (new)] [lang:cmd.output-style.*, host.output_style.*] [docs:COMMANDS.md] [docs:README.md] [docs:MEMORY.md] [hot:Rule.php/RuleLoader.php/RulesState.php] | none |
| C-CTX-4.f | Project references (extra read-only roots) | M | [docs:SETTINGS.md] [docs:PERMISSIONS.md] [hot:LaunchContext.php] [hot:EnvironmentBlock.php] [hot:LayeredSettings.php] | none |
| C-CTX-4.g | Headless `--append-system-prompt` | S | [headless] [runtime:systemPromptSections] [docs:README.md] [docs:PROMPT_ENGINEERING.md] | none (ruling) |
| C-CTX-5.a | Summary resilience: overflow retry, model fallback, failure pins | M | [docs:CONTEXT.md] [hot:CompactionService.php] [hot:CacheReusingSummaryBackend.php] | none |
| C-CTX-5.b | Ahead-of-need skip rules | S | [chat:dispatchTurn (ahead-of-need block)] [hot:SessionHost.php] [hot:CompactionService.php] | none |
| C-CTX-5.c | Idle fold timed to the cache TTL | M | [docs:CONTEXT.md] [docs:SETTINGS.md] [hot:SessionHost.php] [hot:HandlesRunControl.php] [hot:ContextSettings.php] [hot:LayeredSettings.php] | C-CTX-6.a |
| C-CTX-5.d | Opt-in prompt-cache keep-warm | M | [docs:CONTEXT.md] [docs:SETTINGS.md] [hot:SessionHost.php] [hot:HandlesRunControl.php] [hot:ContextSettings.php] [hot:LayeredSettings.php] | C-CTX-5.c |

---

### C-CTX-2.a — Drain the runtime-notice sink in serve
- item: C-CTX-2 (sources: GAP-MEM-10w, k1-sum (a), k1-dorm#1, MOD-14 web)
- kind: dormant-wire · MAJOR · effort S
- approach:
  - Root cause: serve arms the process sink in `Bootstrap::workspace()` (`candy-code/src/Cli/Bootstrap.php:1678-1679`), but the only `drain()` is `Chat::pumpRuntimeNotices()` (`candy-code/src/Chat.php:18636`). On served sessions, recall rows, FallbackProvider switch notices and parser warnings vanish.
  - The per-session seam already exists and has no production caller: `NoticeSink` instances plus `RuntimeNoticeSink::using()` (`RuntimeNoticeSink.php:715`), which a fork pins through `enterForkedChild()` (`EngineBackend.php:3835`).
  - Each `SessionHost` owns its own `NoticeSink`. It is armed cross-fork only when the workspace sink is armed, so tests and embedders stay unarmed.
  - Every fork the host starts runs under `RuntimeNoticeSink::using($this->notices, …)`: `dispatch()` around `runner()->start()` and `$run()`, `requestAheadOfNeed()`, and `scheduleGoalJudge()`.
  - `TurnRunner::start()` receives `notices: $this->notices` instead of the shared `workspace->notices` (`SessionHost.php:1785`).
  - Draining happens on a `notifyOnceWhenPending()` wake (re-armed every time), in `pump()`, and on settle. Each drained row goes through `appendMessageRow(Message::notice(...))`, so it is uiOnly, saved, and announced as `message.created` with kind `notice`, which the web reducer already renders.
  - `release()` resets the sink, closing its two fds.
- files.create: `candy-code/tests/Host/ServedRuntimeNoticeDrainTest.php`
- files.modify: `candy-code/src/Host/SessionHost.php` (`new()`/constructor, `dispatch()`, `requestAheadOfNeed()`, `scheduleGoalJudge()`, `pump()`, `settleWith()`, `release()`, new private `pumpRuntimeNotices()`)
- tests: create ServedRuntimeNoticeDrainTest. It pins:
  - a turn whose backend records a notice lands exactly one uiOnly notice row and one `message.created` event with kind `notice`;
  - two hosts on one workspace never see each other's rows;
  - an unarmed workspace leaves the host sink unarmed;
  - `release()` closes the fds.
- docs/guards:
  - `candy-code/docs/SERVER.md` (### Events): notice rows on served sessions;
  - `candy-code/docs/MEMORY.md` L455-458: the recall row is drained by `Chat::pumpRuntimeNotices()` and, on serve, by `SessionHost`;
  - no drift guard moves.
- serialized: [docs:SERVER.md] [docs:MEMORY.md] [hot:candy-code/src/Host/SessionHost.php]
- deps: none
- phase: normal
- ruling: none
- risks: a parent-side notice raised outside a host's `using()` window (e.g. MCP startup in the workspace process) still lands on the undrained workspace sink. This is stated in SERVER.md and not fixed here.

### C-CTX-1.a — Prime the instruction-diff tracker before the turn forks
- item: C-CTX-1 (sources: CTX-3-P1)
- kind: fix · MAJOR · effort M
- approach:
  - Root cause: `Runtime::instructionDiff()` (`candy-code/src/Runtime.php:5742-5754`) memoises `InstructionDiffTracker::capture($loader)` on the first `turnContext()`, and that runs only inside the forked child.
  - `EngineBackend::beginSessionTurn()` (`candy-code/src/Backend/EngineBackend.php:2843-2860`) primes only `primeSessionPrompt()`.
  - So every child captures "frozen = current disk" (`InstructionDiffTracker.php:177-190`), `recheck()` finds nothing, and the memo dies with the child. This was reproduced: on a forked turn 2 the diff is absent.
  - Fix: add a public `Runtime::primeInstructionDiff(App)` that fills the same `instruction-diffs:<loader id>` slot of the shared `SessionPromptMemo` in the parent.
  - Call it in `beginSessionTurn()` right after `primeSessionPrompt()`, inside the same try/catch. Also call it beside the `summariseAsync()` prime (`EngineBackend.php:4425`), so a summary fork never captures post-edit bytes.
  - Update the tracker docblock's capture-window limit (`:67-71`).
  - What the user sees: a CLAUDE.md edit made mid-session reaches the model on the next TUI or served turn.
- files.create: none
- files.modify:
  - `candy-code/src/Runtime.php` (new `primeInstructionDiff()`, `instructionDiff()`)
  - `candy-code/src/Backend/EngineBackend.php` (`beginSessionTurn()`, the `summariseAsync()` prime)
  - `candy-code/src/Context/InstructionDiffTracker.php` (docblock)
- tests: modify `candy-code/tests/Providers/PromptPrefixByteStabilityTest.php`. Add a fork-path twin of `testAMidSessionInstructionEditRidesTheTailRowAndNotTheFrozenPrefix` that drives `completeAsync()` twice (skipped without pcntl). On turn 2 the diff is present and the frozen prefix bytes are unchanged.
- docs/guards: none
- serialized: [runtime:primeInstructionDiff (new), instructionDiff] [hot:candy-code/src/Backend/EngineBackend.php] [hot:candy-code/src/Context/InstructionDiffTracker.php]
- deps: none
- phase: normal
- ruling: none
- risks: the prime only helps if the parent and child share one `SessionPromptMemo` instance. The test must therefore go through `completeAsync()`, not a direct Runtime call.

### C-CTX-1.b — Cursor glob rules fire; disabled imported rules are explained
- item: C-CTX-1 (sources: INT-2-P1, INT-2-P2)
- kind: fix · MAJOR · effort S
- approach:
  - Root cause: `candy-code/src/Context/Rule.php:233-238` ANDs `alwaysApply` into `enabled`. Cursor's canonical Auto-Attached shape (`globs: src/**/*.ts` + `alwaysApply: false`) therefore parses as disabled, and `RuleLoader::load()` drops it (`RuleLoader.php:377`).
  - A comma string such as `globs: a/*.ts, b/*.ts` also becomes one never-matching pattern (`Rule.php:277-283` → `stringListField()` :435-450).
  - Fix: `alwaysApply: false` disables a rule only when it has no `paths`/`globs`. Path-scoped rules are already deferred to tool time (`Runtime.php:4583-4597`).
  - Split comma-string globs into separate patterns, and record a `disabledBy: alwaysApply` reason on the rule.
  - `RulesCommand` prints one aggregate hint: "N imported rules are off (`alwaysApply: false`, no globs); turn one on with `/rules <name>`". `RulesHostCommand` reuses that output.
  - Document Cursor's mapping table in `docs/MEMORY.md` (:745-750).
- files.create: none
- files.modify: `candy-code/src/Context/Rule.php` (frontmatter parse, glob split), `candy-code/src/Commands/RulesCommand.php` (aggregate hint)
- tests:
  - modify `candy-code/tests/Context/RuleLoaderTest.php`:
    - globs + `alwaysApply:false` → enabled, path-scoped, and absent from the standing splice;
    - a comma glob → two patterns;
    - the no-globs pin at :884-913 stays;
  - modify `candy-code/tests/Commands/RulesCommandTest.php`: hint pin.
- docs/guards: `candy-code/docs/MEMORY.md` (Cursor mapping table)
- serialized: [lang:cmd.rules.alwaysapply_hint*] [docs:MEMORY.md] [hot:candy-code/src/Context/Rule.php]
- deps: none
- phase: normal
- ruling: none
- risks: a project that used `alwaysApply:false` to keep a globbed rule OFF now gets it at tool time. The `/rules` toggle is the escape, and the hint names it.

### C-CTX-2.b — Extract the turn-settle side work into one host-neutral gate
- item: C-CTX-2 (sources: GAP-MEM-02w, k1-sum (b), k1-dorm#6)
- kind: dormant-wire · MAJOR · effort M
- approach:
  - Today, auto-memory, the dream pass, prompt suggestion and auto-commit are gated only in `candy-code/src/Chat/Handlers/HandlesTurnCompletion.php:304-336`. Auto-commit's body lives in `Chat::scheduleAutoCommit()` (`Chat.php:15489-15560`).
  - Follow the GoalLoop precedent ("one decision table, two hosts") and create `Host\TurnSettleWork`: a final readonly value with a static `plan(...)`.
  - `plan()` takes what any settle knows:
    - carried-on / goal-active flags;
    - memory store;
    - engine, summary and title backends;
    - history, cap-reached flag, session id, generation;
    - project root, user config, session store.
  - It returns the four calls as nullable `\Closure(): PromiseInterface`, each resolving to the existing Msg: `PromptSuggestionMsg`, `MemoryConsolidatedMsg`, `DreamPassCompletedMsg` or `AutoCommittedMsg`.
  - Move the auto-commit body into a static `Workspace\AutoCommitter::turnCall()`.
  - This is a pure core step: no host is rewired.
- files.create: `candy-code/src/Host/TurnSettleWork.php`, `candy-code/tests/Host/TurnSettleWorkTest.php`
- files.modify: `candy-code/src/Workspace/AutoCommitter.php` (new static `turnCall()` plus the checkpoint-workspace reader it needs)
- tests:
  - create TurnSettleWorkTest, pinning each gate:
    - carried on → nothing;
    - goal live → no suggestion;
    - no memory store → no consolidation or dream;
    - cap reached → no suggestion;
    - `autoCommit` ≠ `turn` → no commit;
  - modify `candy-code/tests/Workspace/AutoCommitterTest.php`: `turnCall()` commits a turn's files with a plain subject when there is no title backend.
- docs/guards: none
- serialized: none
- deps: none
- phase: normal
- ruling: none
- risks: until C-CTX-2.e lands there are two copies of the auto-commit body (Chat's and AutoCommitter's). Schedule 2.e within a wave or two.

### C-CTX-2.c — Run auto-memory, dream pass and auto-commit on served settles
- item: C-CTX-2 (sources: GAP-MEM-02w, k1-dorm#6)
- kind: dormant-wire · MAJOR · effort M
- approach:
  - In `SessionHost::settleWith()`, after `releaseQueue()` and the goal decision, call `TurnSettleWork::plan()` with the host's state:
    - `$this->turn !== null` means the turn carried on;
    - `workspace->memoryStore`, `summaryBackend` and `titleBackend`;
    - `$this->backend()`;
    - `ledger()->capReached()`.
  - Run the consolidation, dream and auto-commit closures under `RuntimeNoticeSink::using($this->notices, …)`.
  - Each landing first bills through `ledger()->account()` with the model of the engine that ran it (the TUI's OBS-3 rule). It then appends the producer's existing `::notice()` row via `appendMessageRow()`, or appends nothing when the producer returns null.
  - A landing after `release()` is billed and dropped.
  - What the user sees: served sessions now get auto-memory notes, dream consolidation and `autoCommit: turn` commits, each with its transcript row.
- files.create: `candy-code/tests/Host/SessionHostTurnSettleWorkTest.php`
- files.modify: `candy-code/src/Host/SessionHost.php` (`settleWith()`, new private `runSettleWork()` plus its three landers)
- tests: create SessionHostTurnSettleWorkTest. It pins:
  - a settled turn with a memory store schedules consolidation and lands its notice, with spend accounted;
  - a queued prompt suppresses all three jobs;
  - `autoCommit: turn` commits through `AutoCommitter::turnCall()`.
- docs/guards:
  - `candy-code/docs/MEMORY.md`: Auto-memory and Dream pass now say they run on TUI and served sessions;
  - `candy-code/docs/SERVER.md` (### Sub-agents, workflows, memory and todos);
  - keep `candy-code/tests/Config/MemoryDocumentationDriftTest.php` green.
- serialized: [docs:MEMORY.md] [docs:SERVER.md] [hot:candy-code/src/Host/SessionHost.php]
- deps: C-CTX-2.a (the dream turn's fork notices need the host sink), C-CTX-2.b
- phase: normal
- ruling: none
- risks: the on-disk throttles (auto-memory 5 min, dream 2 h, per project) now also see served traffic, so a TUI and a server on one project share the budget. This is intended and is stated in MEMORY.md.

### C-CTX-2.d — Served prompt suggestions and a `context.changed` event (protocol lane)
- item: C-CTX-2 (sources: GAP-UI-34, GAP-INS-08w (event half), k1-dorm#6)
- kind: gap · effort M
- approach: `ContextMethods::instructionWrite` also announces `context.changed {reason: instructions}` (C-CTX-3.c relies on it). Two new ephemeral events.
  - `prompt.suggested {text, generation}`:
    - On settle, the suggestion closure from `TurnSettleWork` runs: `TitleService::suggestionCall()` on the title model.
    - If it lands still current (same generation, same history length, nothing in flight), SessionHost bills it and announces the `TitleService::sanitizeSuggestion()` line. It is never persisted.
    - The web reducer holds the suggestion and clears it on `turn.started` or on typing. `Composer.vue` shows it as grey placeholder text that → or Tab accepts.
  - `context.changed {reason}`:
    - SessionHost announces it after a settle, a compaction, and any command result that changes context (rules toggle, memory write, `/init`).
    - `stores/contextSources.ts` re-fetches `context.sources` while loaded, debounced to at least 500 ms.
    - This is the live reload P-CTX-BREAKDOWN consumes.
  - Gen chain: `scripts/gen-protocol-schema.php` → schema JSON and `docs/SERVER.md`, then `candy-code-web/scripts/gen-protocol.mjs` → `generated.ts`.
- files.create: `candy-code/tests/Host/ServedPromptSuggestionTest.php`, `candy-code-web/src-web/components/__tests__/composerSuggestion.spec.ts`, `candy-code-web/src-web/stores/__tests__/contextChanged.spec.ts`
- files.modify:
  - `candy-code/src/Host/SessionEvent.php` (two constants)
  - `candy-code/src/Protocol/EventType.php`
  - `candy-code/src/Protocol/Schema/EventSchemas.php`
  - `candy-code/docs/protocol/candy-code.v1.schema.json` (gen)
  - `candy-code/src/Host/SessionHost.php` (`settleWith()` suggestion lander; `applyCommandResult()` and `typedCompact()` announce `context.changed`)
  - `candy-code-web/src-web/protocol/generated.ts` (gen)
  - `candy-code-web/src-web/stores/reducer.ts` (suggestion state)
  - `candy-code-web/src-web/components/Composer.vue` (ghost text and accept)
  - `candy-code-web/src-web/stores/contextSources.ts` (debounced reload)
- tests:
  - ServedPromptSuggestionTest pins:
    - one `prompt.suggested` per settle;
    - a stale suggestion is billed and not announced;
    - `CANDY_CODE_DISABLE_PROMPT_SUGGESTIONS` suppresses it;
    - `context.changed` fires after a settle and after a `/rules` toggle.
  - modify `candy-code/tests/Protocol/EventTypeTest.php`.
  - web: the two specs above.
- docs/guards:
  - `candy-code/docs/SERVER.md` (### Events), guarded by `candy-code/tests/Config/ServerProtocolDocumentationDriftTest.php`;
  - `candy-code/tests/Protocol/ProtocolSchemaDriftTest.php`;
  - both gen `--check`s.
- serialized: [protocol] [docs:SERVER.md] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code-web/src-web/components/Composer.vue] [hot:candy-code-web/src-web/stores/contextSources.ts]
- deps: C-CTX-2.b, C-CTX-2.c
- phase: normal
- ruling: none
- risks: other web steps also edit Composer.vue (C-CTX-3.c, C-WEB-4's PDF upload). Keep this change to one placeholder slot and one key handler.

### C-CTX-2.e — The TUI settle consumes the same gate
- item: C-CTX-2 (sources: GAP-MEM-02w ("one decision table, two hosts"))
- kind: dormant-wire (consolidation) · effort S
- approach:
  - Replace the inline gating at `HandlesTurnCompletion.php:304-336` with `TurnSettleWork::plan()`. Chat wraps each closure in `Cmd::promise`, and the landers stay unchanged.
  - `Chat::scheduleAutoCommit()` and `schedulePromptSuggestion()` delegate to the plan.
  - Chat's duplicate auto-commit body is removed. It was moved to `AutoCommitter::turnCall()` in C-CTX-2.b, so nothing is dropped.
  - Behaviour is unchanged; the point is that the two hosts can no longer drift.
- files.create: none
- files.modify: `candy-code/src/Chat/Handlers/HandlesTurnCompletion.php` (AssistantMsg settle block), `candy-code/src/Chat.php` (`scheduleAutoCommit()`, `schedulePromptSuggestion()`)
- tests: modify `candy-code/tests/Chat/AutoCommitTurnTest.php` and `candy-code/tests/Chat/AutoMemoryConsolidationWiringTest.php`. Both stay green, and one parity assertion checks that the TUI and `SessionHost` schedule the same set for the same inputs.
- docs/guards: none
- serialized: [chat:scheduleAutoCommit, schedulePromptSuggestion] [hot:candy-code/src/Chat/Handlers/HandlesTurnCompletion.php]
- deps: C-CTX-2.b
- phase: normal
- ruling: none
- risks: the batch order must stay `doneCmd, suggest, consolidate, autoCommit, dream, judge, notify`. The goal judge and notify keep their gates.

### C-CTX-2.f — "Loaded" and "updated" instruction rows
- item: C-CTX-2 (sources: GAP-INS-08w (notice half), INS-12 web)
- kind: gap · effort S
- approach:
  - Neither surface tells the user when a nested instruction file is loaded on touch, or when a root file changes mid-session (OpenCode shows "↳ Loaded", OC2 "Instructions updated").
  - Both producers record one uiOnly row through `RuntimeNoticeSink::record()`:
    - `InstructionFileLoader::loadForPath()` on the first hit for a path;
    - `InstructionDiffTracker::recheck()` once per newly detected change.
  - Both run in the turn child, so the rows ride the transport to whichever host drains it: Chat in the TUI, `SessionHost` after C-CTX-2.a.
  - One row per path per session, counted against E199's per-turn budget.
- files.create: `candy-code/tests/Context/InstructionVisibilityNoticeTest.php`
- files.modify: `candy-code/src/Context/InstructionFileLoader.php` (`loadForPath()`), `candy-code/src/Context/InstructionDiffTracker.php` (`recheck()`)
- tests:
  - create InstructionVisibilityNoticeTest:
    - a first touch records one row and a second records none;
    - an edit records one "updated" row, and re-rendering the same state records none;
  - modify `candy-code/tests/Context/InstructionFileLoaderMidSessionNoticeTest.php` only if its counts move.
- docs/guards: `candy-code/docs/MEMORY.md` (instruction files: what you see); lang keys `chat.instructions.loaded`, `chat.instructions.updated`
- serialized: [lang:chat.instructions.*] [docs:MEMORY.md] [hot:candy-code/src/Context/InstructionFileLoader.php] [hot:candy-code/src/Context/InstructionDiffTracker.php]
- deps: C-CTX-2.a (served visibility), C-CTX-1.a (the "updated" row fires on the fork path only once the tracker is primed)
- phase: normal
- ruling: none
- risks: `-p` leaves the sink unarmed, so these rows are dropped there. That is the documented `-p` behaviour.

### C-CTX-1.c — Track `@import` children in the instruction diff
- item: C-CTX-1 (sources: p1-miss: CTX-3 `@import` children untracked)
- kind: fix · MINOR · effort S
- approach:
  - The tracker's declared limit (`InstructionDiffTracker.php:59-63`) leaves a child-only edit to an `@import`ed file invisible until the next refresh point.
  - Capture records each root document's resolved import children as stat-only entries. The children come from the loader's existing expansion, through `ImportResolver`.
  - `recheck()` stat-gates the children like roots. A changed child renders as the existing degradation line with a `Read <path>` pointer. There is no re-expansion per turn, so the cost model holds.
  - Children tracked per document are capped at 32. The docblock limit is removed.
- files.create: none
- files.modify: `candy-code/src/Context/InstructionDiffTracker.php` (`capture()`, `recheck()`, docblock), `candy-code/src/Context/InstructionFileLoader.php` (`loadDocuments()` rows carry their import children), `candy-code/src/Context/ImportResolver.php` (expose resolved child paths)
- tests: modify `candy-code/tests/Context/InstructionDiffTurnContextTest.php`. A child-only edit renders one pointer line; an unchanged child costs one stat and renders nothing.
- docs/guards: `candy-code/docs/MEMORY.md` (instruction-diff paragraph)
- serialized: [docs:MEMORY.md] [hot:candy-code/src/Context/InstructionDiffTracker.php] [hot:candy-code/src/Context/InstructionFileLoader.php]
- deps: C-CTX-1.a
- phase: normal
- ruling: none
- risks: deep import trees add stat calls on every step. The cap bounds them, and the docs state the cap.

### C-PRV-1.a — `EffortGate`, a per-model shaper predicate, and Bedrock / Codex / claude-code effort
- item: C-PRV-1 (sources: PRV-2-P1, p1-miss: Bedrock request-side thinking/effort, p1-miss: Codex parity (`-c model_reasoning_effort`))
- kind: fix · MAJOR · effort M
- approach:
  - Root cause: `/effort` gates per provider, through the empty marker `ShapesReasoningEffort` (`candy-code/src/Providers/ShapesReasoningEffort.php:34`).
  - But `OpenAIProvider` sends `reasoning_effort` only inside `isReasoningFamily()` (`OpenAIProvider.php:439-446, 477-494`). On `gpt-4o`, `gpt-4.1` and `gpt-5-chat-*` a "set" tier is silently dropped.
  - Fix part 1: give the interface `shapesEffortFor(string $model): bool` and implement it in OpenAI, Anthropic, Vertex, Gemini and SGLang.
  - Fix part 2: add `Providers\ThinkingCapability`, one per-family table of thinking support and output ceilings, for Anthropic and Gemini families. C-PRV-5.a reuses it.
  - Fix part 3: add `Providers\EffortGate` with `verdict(ProviderInterface, model)`, which unwraps a FallbackProvider to its primary, and `shape()`, the status phrase now inlined in `Chat.php:12262-12271`.
  - New shapers:
    - **Bedrock** for Claude-family ids: Converse `additionalModelRequestFields.thinking {type: enabled, budget_tokens}`, with temperature dropped and `maxTokens` above the budget.
    - **Codex:** `-c model_reasoning_effort=<level>` through `CodexInvocation::execArgs()`. The level map is verified against codex-cli 0.154.0.
    - **claude-code:** `--effort <level>`, verified present in `claude --help` 2.1.311 (low, medium, high, xhigh, max).
  - Rewrite the interface docblock's "deliberately NOT shapers" paragraph to match.
- files.create: `candy-code/src/Providers/EffortGate.php`, `candy-code/src/Providers/ThinkingCapability.php`, `candy-code/tests/Providers/EffortGateTest.php`
- files.modify:
  - `candy-code/src/Providers/ShapesReasoningEffort.php`
  - `candy-code/src/Providers/OpenAIProvider.php`
  - `candy-code/src/Providers/AnthropicProvider.php`
  - `candy-code/src/Providers/VertexProvider.php`
  - `candy-code/src/Providers/GeminiProvider.php`
  - `candy-code/src/Providers/SglangProvider.php`
  - `candy-code/src/Providers/BedrockProvider.php` (`complete()`, `completeStream()`, `inferenceConfig()`)
  - `candy-code/src/Providers/CodexProvider.php` (`options()`)
  - `candy-code/src/Providers/CodexInvocation.php` (`execArgs()`)
  - `candy-code/src/Providers/ClaudeCodeProvider.php` (`options()`)
  - `candy-code/src/Providers/ClaudeCodeInvocation.php` (`printModeArgs()`)
- tests:
  - create EffortGateTest: provider × model roster, e.g. openai+gpt-4o false, openai+o3 true, gemini-2.0-flash false, bedrock+anthropic.claude-* true, bedrock+llama false;
  - modify `candy-code/tests/Providers/EffortBudgetTest.php` (implementer roster pin);
  - modify `candy-code/tests/Providers/BedrockProviderTest.php` (thinking field present for Claude, absent otherwise);
  - modify `candy-code/tests/Providers/CodexProviderTest.php` (argv pin);
  - modify `candy-code/tests/Providers/ClaudeCodeProviderTest.php` (argv pin);
  - modify `candy-code/tests/Providers/OpenAIProviderTest.php`.
- docs/guards:
  - `candy-code/docs/ADDING_A_PROVIDER.md`: the shaper contract;
  - lang `settings.reasoningEffort.help` names which provider/model pairs shape, then regenerate `candy-code/docs/SETTINGS.md` with `php tools/gen-settings-doc.php --write`, guarded by `candy-code/tests/Config/Settings/SettingsSchemaDocDriftTest.php`.
- serialized: [lang:settings.reasoningEffort.*] [docs:SETTINGS.md] [docs:ADDING_A_PROVIDER.md] [hot:candy-code/src/Providers/OpenAIProvider.php] [hot:candy-code/src/Providers/AnthropicProvider.php] [hot:candy-code/src/Providers/BedrockProvider.php] [hot:candy-code/src/Providers/Codex*.php] [hot:candy-code/src/Providers/ClaudeCode*.php]
- deps: none
- phase: normal
- ruling: none
- risks:
  - Bedrock thinking is fixture-pinned only, since the repo holds no AWS credentials. Say so in the docblock (house "pin what was measured").
  - The codex level names must be read from the probed CLI, not guessed.

### C-PRV-1.b — Honest `/effort` and an honest launch tier
- item: C-PRV-1 (sources: PRV-2-P1)
- kind: fix · MAJOR · effort S
- approach:
  - `Chat::handleEffortCommand()` (`Chat.php:12234-12310`) asks `EffortGate::verdict($provider, $engine->model())`. It refuses per model (`chat.effort.unsupported_model`) and takes its status shape from `EffortGate::shape()`.
  - Correct the false claim "rides the reasoning-effort field of every request" (`lang/en.php:1872`).
  - `Chat::withEngineSettings()`, which re-applies after a `/model` or provider switch, applies a persisted tier only where it shapes, and says so once with a uiOnly row.
  - At launch, `LaunchContext::withResolvedReasoningEffort()` (`LaunchContext.php:2076-2081`) returns the engine untouched plus a reason when the configured tier would not shape. `Bootstrap::withResolvedReasoningEffort()` records that reason through `warnLaunchRows()`.
  - Result: a tier persisted on anthropic is no longer silently applied after switching to `custom`.
- files.create: `candy-code/tests/Cli/LaunchEffortNoticeTest.php`
- files.modify: `candy-code/src/Chat.php` (`handleEffortCommand()`, `withEngineSettings()`), `candy-code/src/Cli/LaunchContext.php` (`withResolvedReasoningEffort()`), `candy-code/src/Cli/Bootstrap.php` (`withResolvedReasoningEffort()`)
- tests:
  - modify `candy-code/tests/Chat/EffortCommandTest.php`: openai+gpt-4o refuses and persists nothing; openai+o3 sets.
  - create LaunchEffortNoticeTest: a persisted tier on a `custom` provider gives one launch row and an engine with no tier.
- docs/guards: `candy-code/docs/SETTINGS.md` hand prose for `reasoningEffort` (outside generated regions)
- serialized: [chat:handleEffortCommand, withEngineSettings] [bootstrap:withResolvedReasoningEffort] [lang:chat.effort.*] [docs:SETTINGS.md] [hot:candy-code/src/Cli/LaunchContext.php]
- deps: C-PRV-1.a
- phase: normal
- ruling: none
- risks: C-WEB-2's `EffortHostCommand` (other area) must use `EffortGate` too, or the web reopens the same hole. See Notes.

### C-PRV-1.c — Safe `candy-code login`: merge, refuse a type clash, no echo
- item: C-PRV-1 (sources: PRV-4-P1, PRV-4-P2)
- kind: fix · MAJOR · effort S
- approach:
  - Root cause 1: `Subcommands::loginStoreKey()` replaces the provider block outright with `$providers[$type] = ['type' => $type, 'apiKey' => $key]` (`candy-code/src/Cli/Subcommands.php:973`). Rotating a key wipes `model`, `thinkingBudget`, `modelPrices` and, worst of all, a gateway `baseUrl`.
  - Root cause 2: the key is read with `fgets(\STDIN)` with TTY echo on (`:942`).
  - Fix: merge `apiKey` (and `type`) into the existing block, and refuse without writing when the existing block's `type` differs.
  - When STDIN is a TTY, read the key with echo off through sugar-core's `SugarCraft\Core\Util\Tty`: raw mode, minimal Enter/Backspace/Ctrl-C handling, restored in `finally`.
  - No new child process. The existing `$loginKeyRead` seam keeps the suite TTY-free; add a `$loginTty` seam for the echo-off branch.
- files.create: none
- files.modify: `candy-code/src/Cli/Subcommands.php` (`loginStoreKey()`, new private key reader)
- tests: modify `candy-code/tests/Cli/LoginSubcommandTest.php`. `model`, `baseUrl` and `thinkingBudget` survive a key rotation; a mismatched type refuses and leaves the file byte-identical; the tty seam is restored after a thrown read.
- docs/guards: lang `cli.sub.login.type_mismatch`; no doc row changes
- serialized: [headless] [lang:cli.sub.login.*]
- deps: none
- phase: normal
- ruling: none
- risks: the raw-mode reader must restore the terminal on every exit path, including a SIGINT during the read.

### C-PRV-5.a — Per-family thinking ceilings and omission
- item: C-PRV-5 (sources: CTX-1-P2, PRV-2-P2)
- kind: fix · MINOR · effort M
- approach:
  - Root cause: the only bound is a floor (`ProviderFactory.php:999-1006`), while the wire sets `max_tokens = max(…, 1.5×budget)` (`AnthropicMessagesWire.php:509-515`).
  - These therefore 400 on every turn:
    - `thinkingBudget: 50000` (gives `max_tokens: 75000`);
    - `/effort low` on `claude-3-5-haiku`;
    - `/effort max` on `gemini-2.5-flash` (ceiling 24576);
    - any `/effort` on `gemini-2.0-flash`.
  - Fix: the Anthropic and Gemini wires read C-PRV-1.a's `ThinkingCapability` table.
    - Omit `thinking`/`thinkingConfig` for non-thinking families.
    - Clamp the budget to the family ceiling, keeping the 1024 floor.
    - Clamp `max_tokens` to the family output ceiling.
  - `ProviderFactory` refuses at launch a configured `thinkingBudget` above the largest ceiling of the configured type, with the same loud shape as the floor.
- files.create: `candy-code/tests/Providers/ThinkingCeilingTest.php`
- files.modify: `candy-code/src/Providers/AnthropicProvider.php` (`anthropicThinkingBudget()`), `candy-code/src/Providers/Concerns/AnthropicMessagesWire.php` (`anthropicBody()`), `candy-code/src/Providers/Concerns/GeminiGenerateContentWire.php` (thinkingConfig block :255-262), `candy-code/src/Providers/EffortBudget.php`, `candy-code/src/Providers/ProviderFactory.php` (thinkingBudget resolver)
- tests: create ThinkingCeilingTest with per-family wire-body pins: omission on non-thinking families, the clamp on budget and `max_tokens`, and the launch refusal above the ceiling.
- docs/guards: `candy-code/docs/SETTINGS.md` hand prose for `thinkingBudget` (L344, L491)
- serialized: [docs:SETTINGS.md] [hot:candy-code/src/Providers/AnthropicProvider.php] [hot:candy-code/src/Providers/Concerns/AnthropicMessagesWire.php] [hot:candy-code/src/Providers/Concerns/GeminiGenerateContentWire.php] [hot:candy-code/src/Providers/ProviderFactory.php]
- deps: C-PRV-1.a
- phase: normal
- ruling: none
- risks: the family table goes stale as models ship. An unknown family keeps today's behaviour (no clamp), so the table never refuses a model it does not know.

### C-PRV-5.b — No thinking budget on the title and auxiliary backends
- item: C-PRV-5 (sources: CTX-1-P1)
- kind: fix · MINOR · effort S
- approach:
  - Root cause: `Bootstrap::toollessBackend()` (`Bootstrap.php:9484-9516`) builds from the selected provider's block, `thinkingBudget` included, and the Anthropic budget survives `withModel()` (`AnthropicProvider.php:395-409`).
  - So session titles, prompt suggestions, `/btw`, autoReview and commit subjects all pay for thinking tokens and latency.
  - Fix: `toollessBackend()` takes a `keepThinking` flag. It unsets `thinkingBudget` from the config copy unless the flag is set, and only `summaryBackend()` sets it (summaries keep the configured reasoning).
- files.create: `candy-code/tests/Cli/ToollessBackendThinkingTest.php`
- files.modify: `candy-code/src/Cli/Bootstrap.php` (`toollessBackend()`, `titleBackend()`, `summaryBackend()`)
- tests: create ToollessBackendThinkingTest: the title backend's request has no `thinking` key; the summary backend's does.
- docs/guards: `candy-code/docs/SETTINGS.md` thinkingBudget prose ("not sent on title and auxiliary calls")
- serialized: [bootstrap:toollessBackend, titleBackend, summaryBackend] [docs:SETTINGS.md]
- deps: none
- phase: normal
- ruling: none
- risks: none beyond the Bootstrap slot.

### C-PRV-5.c — Non-blocking model-catalog prefetch
- item: C-PRV-5 (sources: PRV-1-P1)
- kind: fix · MINOR · effort M
- approach:
  - Root cause: `Chat::pickPaletteProvider()` (`Chat.php:17060`, call at :17129) calls `ModelCatalog::prefetch()` → `OpenAiModelsEndpoint::fetch()`, a blocking Guzzle GET with a 3 s budget (`OpenAiModelsEndpoint.php:63-89`). On a slow base this freezes keys and repaint once per process.
  - Fix: add `OpenAiModelsEndpoint::fetchAsync()` over `React\Http\Browser` (already required, `react/http ^1.11`), keeping the 3 s metadata budget. This is not an LLM call, so E646 does not apply.
  - Add `HasModelCatalog::listModelsAsync()` on OpenAI and SGLang, and `ModelCatalog::prefetchAsync()` returning a promise.
  - The palette returns immediately with the base tiers plus a `Cmd` that resolves to a new `App\ModelCatalogReadyMsg`. Chat's update arm then merges the live ids into the open picker.
- files.create: `candy-code/src/App/ModelCatalogReadyMsg.php`, `candy-code/tests/Chat/PaletteCatalogAsyncTest.php`
- files.modify: `candy-code/src/Providers/OpenAiModelsEndpoint.php`, `candy-code/src/Providers/HasModelCatalog.php`, `candy-code/src/Providers/ModelCatalog.php`, `candy-code/src/Providers/OpenAIProvider.php` (`listModelsAsync()`), `candy-code/src/Providers/SglangProvider.php` (`listModelsAsync()`), `candy-code/src/Chat.php` (`pickPaletteProvider()`, update dispatch arm for the new Msg)
- tests: create PaletteCatalogAsyncTest (the palette returns at once with the base tiers; the Msg merges the live ids; a failed fetch changes nothing); modify `candy-code/tests/Providers/OpenAiModelsEndpointTest.php` (async path)
- docs/guards: none
- serialized: [chat:pickPaletteProvider, update dispatch arm (ModelCatalogReadyMsg)] [hot:candy-code/src/Providers/OpenAIProvider.php]
- deps: none
- phase: normal
- ruling: none
- risks: the CLI `models` listing keeps the synchronous `fetch()`. Only the TUI path goes async.

### C-PRV-5.d — Gemini schema, documented catalog order, codex login wording
- item: C-PRV-5 (sources: PRV-3-P1, PRV-1-P2, PRV-4-P4)
- kind: fix · MINOR/NIT · effort S
- approach:
  - **PRV-3-P1:** `GeminiGenerateContentWire.php:280-293` sends the operator's raw JSON Schema as `responseSchema` with `responseMimeType: application/json`, on a first step that also declares tools (`Runtime.php:845-872`). Fix:
    - send standard JSON Schema as `responseJsonSchema`;
    - fall back to an OpenAPI-subset sanitiser (strip `$schema`, `additionalProperties`, `$defs`) where needed;
    - on a step that carries function declarations, omit the mime type and schema for this wire.
  - **PRV-1-P2:** the shipped merge order (`current-three > live > LiteLLM`, `ModelCatalog.php:18-26`) is documented truthfully in `docs/SETTINGS.md`. Switching to live-first is an optional owner call (Notes).
  - **PRV-4-P4:** `README.md:1595` stops calling `codex login` a "PKCE fork". It is a stdio passthrough to `codex login` (`Subcommands.php:876, 889-925`).
- files.create: `candy-code/tests/Providers/GeminiResponseSchemaTest.php`
- files.modify: `candy-code/src/Providers/Concerns/GeminiGenerateContentWire.php` (schema block), `candy-code/README.md` (L1595), `candy-code/docs/SETTINGS.md` (model-catalog prose)
- tests: create GeminiResponseSchemaTest: `responseJsonSchema` body pin, subset sanitiser, no schema on a tools-bearing step.
- docs/guards: README provider table (`ReadmeRosterDriftTest` unaffected; wording only)
- serialized: [docs:README.md] [docs:SETTINGS.md] [hot:candy-code/src/Providers/Concerns/GeminiGenerateContentWire.php]
- deps: none
- phase: normal
- ruling: none
- risks: AUT-4's terminal-answer constraint (another area) decides whether the constrained answer comes later. This step only stops the 400.

### C-PRV-2.a — OpenAI Responses wire (core)
- item: C-PRV-2 (sources: GAP-MOD-02, p1-miss: OpenAI Responses API)
- kind: gap · effort M
- approach:
  - Today there is Chat Completions only. The vendored `openai-php/client` ^0.10 has no Responses resource.
  - Add an opt-in `api: "responses"` key on `openai` blocks. A new trait, `Concerns\OpenAiResponsesWire`, speaks `POST /v1/responses` over Guzzle through `HttpClientDefaults`. E646: connect timeout only, no total timeout.
  - Request: `instructions`; `input` items (message, `function_call`, `function_call_output`); tools as `{type:function}`; `store:false`; `max_output_tokens`; `reasoning.effort` via C-PRV-1.a's predicate.
  - SSE parse: `response.output_text.delta`, `response.function_call_arguments.delta`/`.done`, `response.completed`. Usage, including `input_tokens_details.cached_tokens`, is folded into the usage `CacheHealthWatch` reads.
  - `OpenAIProvider::complete()`/`completeStream()` route to the trait when `api === responses`; `baseUrl` and `organization` are honoured.
- files.create: `candy-code/src/Providers/Concerns/OpenAiResponsesWire.php`, `candy-code/tests/Providers/OpenAiResponsesWireTest.php`
- files.modify: `candy-code/src/Providers/OpenAIProvider.php` (constructor `api`, `complete()`, `completeStream()`), `candy-code/src/Providers/ProviderFactory.php` (`TYPE_SCHEMAS['openai']` optional `api`, `createOpenAI()`)
- tests:
  - create OpenAiResponsesWireTest:
    - body pins;
    - an SSE fixture parses to text plus tool calls;
    - the usage/cached-token fold;
    - a Guzzle options pin: `connect_timeout` set, no `timeout`.
  - modify `candy-code/tests/Providers/ProviderFactoryTest.php`: `api` accepts `chat|responses` and refuses anything else.
- docs/guards: `candy-code/docs/SETTINGS.md` (provider block `api` prose), `candy-code/docs/ADDING_A_PROVIDER.md`
- serialized: [docs:SETTINGS.md] [docs:ADDING_A_PROVIDER.md] [hot:candy-code/src/Providers/OpenAIProvider.php] [hot:candy-code/src/Providers/ProviderFactory.php]
- deps: C-PRV-1.a
- phase: normal
- ruling: none
- risks: with no live key, the SSE event shapes are fixture-pinned. The trait docblock records what was measured and what was not.

### C-PRV-2.b — Responses reasoning replay and image tool outputs
- item: C-PRV-2 (sources: GAP-MOD-02 (reasoning items), p1-miss: OpenAI Responses API (encrypted reasoning replay), candidates: "Responses also unlocks images in tool results")
- kind: gap · effort M
- approach:
  - On the responses arm, request `include: ["reasoning.encrypted_content"]` and `reasoning.summary: "auto"`.
  - Stream `response.reasoning_summary_text.delta` into the existing thinking channel.
  - Store each reasoning item (`id`, `encrypted_content`, summary) as a `thinkingBlocks` entry of a distinct type on the assistant row. `Message::$thinkingBlocks` (`candy-code/src/Message.php:298`) is persisted and already used for Anthropic signatures.
  - Replay those entries verbatim in later `input`, so reasoning carries across tool steps with `store:false`. Each wire replays only its own block types.
  - Tool results that carry images go out as `function_call_output` with an `input_text` + `input_image` list, so a vision model sees them on this wire.
- files.create: `candy-code/tests/Providers/OpenAiResponsesReasoningTest.php`
- files.modify:
  - `candy-code/src/Providers/Concerns/OpenAiResponsesWire.php`
  - `candy-code/src/Providers/OpenAIProvider.php` (vision for tool results on this arm)
  - `candy-code/src/Message.php` (`withThinkingBlocks()` / row-hydrate filter at :794-797 and :1294, only if they reject the new block type)
  - `candy-code/src/Providers/Concerns/AnthropicMessagesWire.php` (`anthropicBlocks()` ignores foreign block types, if it does not already)
- tests: create OpenAiResponsesReasoningTest: encrypted items round-trip through save/load and replay; summary deltas reach the thinking channel; image tool outputs have the content-list shape; an Anthropic replay of a Responses-born row sends no foreign block.
- docs/guards: `candy-code/docs/ADDING_A_PROVIDER.md` (thinkingBlocks ownership)
- serialized: [docs:ADDING_A_PROVIDER.md] [hot:candy-code/src/Providers/OpenAIProvider.php] [hot:candy-code/src/Message.php] [hot:candy-code/src/Providers/Concerns/AnthropicMessagesWire.php]
- deps: C-PRV-2.a
- phase: normal
- ruling: none
- risks: C-TOOL-2's OpenAI image-in-tool-result leg (other area) rides this. That lane routes Read/MCP images into tool results; this step only serialises them on the Responses wire.

### C-PRV-2.c — First-class `azure` and `openrouter` provider types
- item: C-PRV-2 (sources: GAP-MOD-05/06)
- kind: gap · effort M
- approach: Both types are reachable today only through an undocumented `custom` block.
  - `azure` reuses `OpenAIProvider` (Chat Completions, or `api: responses` once C-PRV-2.a exists):
    - keys: `resource` or `baseUrl`, plus `apiKey`/`AZURE_OPENAI_API_KEY`;
    - base `https://{resource}.openai.azure.com/openai/v1/` with an `api-key` header and no `Authorization` header;
    - `name()` returns `azure` so cost, effort and error copy name it correctly.
  - `openrouter` is a `CustomProvider` preset:
    - base `https://openrouter.ai/api/v1`, `OPENROUTER_API_KEY`;
    - `HTTP-Referer`/`X-Title` headers, which needs a `headers` key on custom blocks;
    - `/models` through `HasModelCatalog`.
  - Add both to `availableTypes()`, `TYPE_SCHEMAS`, `defaultConfig()`, `REQUIRED_KEY_ENVS`, and `candy-code login`'s `LOGIN_KEY_ENVS` (`Subcommands.php:794-798`).
- files.create: `candy-code/tests/Providers/AzureOpenRouterTypesTest.php`
- files.modify:
  - `candy-code/src/Providers/ProviderFactory.php` (`availableTypes()`, `TYPE_SCHEMAS`, `defaultConfig()`, `REQUIRED_KEY_ENVS`, create match + two builders)
  - `candy-code/src/Providers/OpenAIProvider.php` (configurable `name`)
  - `candy-code/src/Providers/CustomProvider.php` (`headers`, `HasModelCatalog`)
  - `candy-code/src/Cli/Subcommands.php` (`LOGIN_KEY_ENVS`)
  - `candy-code/README.md` (L176 type list "nine type keys" → eleven; provider table near L1595)
  - `candy-code/docs/ARCHITECTURE.md` (Providers section roster)
  - `candy-code/docs/ENVIRONMENT.md` (two key vars)
  - `candy-code/docs/ADDING_A_PROVIDER.md`
- tests:
  - create AzureOpenRouterTypesTest: Azure base URL and `api-key` header pin with no Authorization; OpenRouter headers and catalog;
  - modify `candy-code/tests/Providers/ProviderFactoryTest.php`;
  - `candy-code/tests/Providers/ProviderErrorCopyTest.php` iterates `availableTypes()`, so add the error-copy lang keys it needs.
- docs/guards:
  - `candy-code/tests/Config/DocFigureProseDriftTest.php::testProvidersSectionRosterDividesTheFactoryItDocuments` (ARCHITECTURE roster);
  - `candy-code/tests/Config/EnvRosterDriftTest.php` (ENVIRONMENT.md);
  - README L176 prose;
  - `candy-code/tests/Cli/LoginSubcommandTest.php` (new types accepted).
- serialized: [headless] [docs:README.md] [docs:ARCHITECTURE.md] [docs:ENVIRONMENT.md] [docs:ADDING_A_PROVIDER.md] [lang:provider.* error copy] [hot:candy-code/src/Providers/ProviderFactory.php] [hot:candy-code/src/Providers/OpenAIProvider.php]
- deps: none
- phase: normal
- ruling: none
- risks: if C-PRV-1.c runs in the same wave, both edit `Subcommands.php` ([headless]). The orchestrator must put them in different waves.

### C-PRV-3.a — `serviceTier` setting and provider rebinding (core)
- item: C-PRV-3 (sources: GAP-MOD-N2)
- kind: gap · effort M
- approach:
  - Add a `serviceTier` setting (`default|fast`; RiskClass Spend; layered; env `CANDY_CODE_SERVICE_TIER`).
  - New interface `Providers\ShapesServiceTier`, with `serviceTierFor(string $model): ?string` (the wire value for fast) and `withServiceTier(?string): static`.
  - Implement it in `OpenAIProvider` (`service_tier: "priority"`).
  - Implement it in `AnthropicProvider` only if the lane confirms the current Messages API field for fast output against the API docs (the `claude-api` skill). Otherwise Anthropic is left out of the roster and refuses honestly.
  - `EngineBackend::withServiceTier()` is an overlay applied at the same provider-rebinding seam `RebindsModel::withModel()` uses. No `Runtime`/`App`/`CompleteRequest` change.
  - The launch tail applies the configured tier the way `withResolvedReasoningEffort` does.
- files.create: `candy-code/src/Providers/ShapesServiceTier.php`, `candy-code/tests/Providers/ServiceTierTest.php`
- files.modify:
  - `candy-code/src/Providers/OpenAIProvider.php`
  - `candy-code/src/Providers/AnthropicProvider.php` (conditional, see above)
  - `candy-code/src/Backend/EngineBackend.php` (overlay + rebinding seam)
  - `candy-code/src/Cli/LaunchContext.php` (launch tail)
  - `candy-code/src/Config/Settings/Definitions/ModelProviderSettings.php`
  - `candy-code/src/Config/LayeredSettings.php` (generated region)
  - `candy-code/docs/SETTINGS.md` (gen)
  - `candy-code/docs/ENVIRONMENT.md` (gen column)
- tests: create ServiceTierTest: wire pins for fast/default; a provider without the interface ignores nothing silently (the gate answers false); the launch tail applies the setting.
- docs/guards: `php tools/gen-settings-doc.php --write` under `candy-code/tests/Config/Settings/SettingsSchemaDocDriftTest.php`; `candy-code/tests/Config/EnvRosterDriftTest.php`
- serialized: [lang:settings.serviceTier.*] [docs:SETTINGS.md] [docs:ENVIRONMENT.md] [hot:candy-code/src/Backend/EngineBackend.php] [hot:candy-code/src/Providers/OpenAIProvider.php] [hot:candy-code/src/Providers/AnthropicProvider.php] [hot:candy-code/src/Config/Settings/Definitions/ModelProviderSettings.php] [hot:candy-code/src/Config/LayeredSettings.php] [hot:candy-code/src/Cli/LaunchContext.php]
- deps: none
- phase: normal
- ruling: none
- risks: priority tiers bill at a higher rate. The spend ledger must price them, or show "unpriced" (the existing unpriced-model notice path).

### C-PRV-3.b — `/fast` command (TUI and served)
- item: C-PRV-3 (sources: GAP-MOD-N2, CC parity)
- kind: gap · effort S
- approach:
  - `/fast [on|off]`: bare reports the state, and on/off toggles the live overlay.
  - The setting persists to the user tier through `SettingsWriter`, like `persistEffort()` (`Chat.php:12332`).
  - It refuses out loud when the provider/model pair has no tier, keeping the set-but-ignored ban.
  - New spec file `candy-code/builtin-commands/0360-fast.php` (per-command-disjoint, not serialized) with `withHandler('handleFastCommand')` and `withHostCommand(FastHostCommand::class)`. The served body is session-scoped like `ModelHostCommand`.
  - Add `fast` to `READ_ONLY_COMMANDS` (`Chat.php:10266`), since it never writes the session.
- files.create: `candy-code/builtin-commands/0360-fast.php`, `candy-code/src/Host/Commands/FastHostCommand.php`, `candy-code/tests/Chat/FastCommandTest.php`, `candy-code/tests/Host/Commands/FastHostCommandTest.php`
- files.modify: `candy-code/src/Chat.php` (new `handleFastCommand()`, `READ_ONLY_COMMANDS`), `candy-code/README.md` (gen slash roster), `candy-code/docs/COMMANDS.md` (gen)
- tests: FastCommandTest (on/off/status/refusal; persists only on success); FastHostCommandTest (served overlay; nothing written)
- docs/guards: `php tools/gen-command-docs.php --write`; `candy-code/tests/Commands/CommandDocsDriftTest.php`; `candy-code/tests/Config/ReadmeRosterDriftTest.php::testTheSlashCommandRosterIsExactlyWhatTheRegistryAdvertises`; `candy-code/tests/Commands/RegistryTranslationTest.php`; check `candy-code/tests/Config/ConfigWriteProducerDocumentationDriftTest.php` for a SettingsWriter-producer roster
- serialized: [chat:handleFastCommand (new), READ_ONLY_COMMANDS] [commands] [lang:cmd.fast.*, host.fast.*] [docs:COMMANDS.md] [docs:README.md]
- deps: C-PRV-3.a
- phase: normal
- ruling: none
- risks: none notable.

### C-PRV-3.c — `ultrathink` raises effort for one turn
- item: C-PRV-3 (sources: GAP-MOD-N1 (sic: CC-new#7 effort keyword), CC-new#7)
- kind: gap · effort S
- approach:
  - A typed prompt that contains the word `ultrathink` (case-insensitive, whole word, outside code fences) raises effort for that dispatch only.
  - `TurnRunner::start()` serves both hosts (Chat at `Chat.php:12731`, SessionHost at `SessionHost.php:1785`). It reads the latest typed User row through a pure `Providers\EffortKeyword` detector.
  - When `EffortGate::verdict()` says the pair shapes effort, the runner applies `$backend->withReasoningEffort(<top tier>)` to that run only. C-PRV-5.a's clamp keeps the top tier legal per family. Nothing is persisted, and tool continuations of the same turn keep the tier.
  - On a non-shaper it records one uiOnly row: "ultrathink: <provider>/<model> has no effort control; sent at the normal tier".
  - The keyword stays in the prompt text (Claude Code parity).
- files.create: `candy-code/src/Providers/EffortKeyword.php`, `candy-code/tests/Host/UltrathinkTurnTest.php`
- files.modify: `candy-code/src/Host/TurnRunner.php` (`start()`)
- tests: UltrathinkTurnTest: a keyword turn carries the top tier and the next turn does not; a non-shaper gets one notice; a word inside backticks is ignored.
- docs/guards: `candy-code/docs/SETTINGS.md` hand prose beside `reasoningEffort`; lang `chat.effort.ultrathink_*`
- serialized: [lang:chat.effort.ultrathink*] [docs:SETTINGS.md] [hot:candy-code/src/Host/TurnRunner.php]
- deps: C-PRV-1.a, C-PRV-5.a
- phase: normal
- ruling: none
- risks: a prompt that merely discusses the word "ultrathink" spends top-tier tokens. That is Claude Code's behaviour too, and it is documented.

### C-PRV-3.d — Advisor tool
- item: C-PRV-3 (sources: CC-new#7 (advisor half per candidates), GAP-MOD-N1)
- kind: gap · effort M
- approach:
  - A built-in `Advisor` tool, `#[BuiltInTool(...)]` at the next free position (41 at HEAD; the orchestrator assigns it).
  - Argument `{question}`. The tool sends a bounded transcript digest (reusing `BtwHostCommand`'s digest builder) plus the question to a tool-less backend on `advisorModel` / `CANDY_CODE_ADVISOR_MODEL`, and returns the answer as the tool result.
  - The tool is offered only when `advisorModel` is set. It is capped per turn, and refused once the spend cap is reached.
  - The new public `Bootstrap::advisorBackend()` sits beside `titleBackend()`/`summaryBackend()` over the private `toollessBackend()`.
  - `ToolBuildContext` carries it, and `LaunchContext::tools()` (:1681) passes it.
  - Usage from the call must reach the parent's `SpendLedger` through the seam that already bills in-turn sub-agent usage. The lane finds and names it.
- files.create: `candy-code/src/Tools/BuiltIn/AdvisorTool.php`, `candy-code/tests/Tools/BuiltIn/AdvisorToolTest.php`
- files.modify:
  - `candy-code/src/Cli/Bootstrap.php` (new `advisorBackend()`)
  - `candy-code/src/Tools/Catalog/ToolBuildContext.php`
  - `candy-code/src/Tools/Catalog/ToolCatalog.php` (position roster)
  - `candy-code/src/Cli/LaunchContext.php` (`tools()`)
  - `candy-code/src/Config/Settings/Definitions/ModelProviderSettings.php` (`advisorModel`)
  - `candy-code/src/Config/LayeredSettings.php` (gen)
  - `candy-code/README.md` (Capabilities roster, gen)
  - `candy-code/docs/SETTINGS.md` (gen)
  - `candy-code/docs/ENVIRONMENT.md` (gen)
- tests: AdvisorToolTest: absent without `advisorModel`; digest is bounded; per-turn cap; cap-reached refusal; usage reaches the ledger
- docs/guards: `php tools/gen-tool-docs.php --write` under `candy-code/tests/Tools/Catalog/ToolDocDriftTest.php`; `candy-code/tests/Config/ReadmeRosterDriftTest.php::testTheCapabilitiesToolRosterNamesEveryToolALaunchShips`; settings gen + `SettingsSchemaDocDriftTest`; `EnvRosterDriftTest`
- serialized: [catalog] [bootstrap:advisorBackend (new)] [lang:tool.advisor.*, settings.advisorModel.*] [docs:README.md] [docs:SETTINGS.md] [docs:ENVIRONMENT.md] [hot:candy-code/src/Cli/LaunchContext.php] [hot:candy-code/src/Config/Settings/Definitions/ModelProviderSettings.php] [hot:candy-code/src/Config/LayeredSettings.php]
- deps: none
- phase: normal
- ruling: none
- risks:
  - Billing an LLM call made inside the turn child is the hard part. If no seam exists, the lane must add one, not skip billing.
  - E646: no total timeout on the advisor call.

### C-PRV-4.a — Host permission mode and isolation for claude-code `bare: false`
- item: C-PRV-4 (sources: CLAW-new#4)
- kind: competitor-feature · effort M
- approach:
  - With `bare: false` (the subscription route, `ClaudeCodeProvider.php:142-157`), the CLI loads the user's own `~/.claude` setting sources, CLAUDE.md, auto-memory, hooks, plugins and MCP. Its tools then run under foreign hooks and permission settings, outside candy-code's engine.
  - New interface `Providers\HonoursHostPermissionMode::withHostPermissionMode(?PermissionMode): static`. `EngineBackend` rebinds the provider per turn with its gate's current mode, at the same seam as C-PRV-3.a, so Shift+Tab is honoured.
  - `ClaudeCodeProvider` maps the mode to `--permission-mode`, narrowing only: plan → `plan`, never a bypass the host did not grant.
  - Under a new block key `isolate` (default true, ignored when `bare` is true), the provider adds the isolation flags verified in `claude --help` 2.1.311: a narrowed `--setting-sources`, `--strict-mcp-config` (no foreign MCP) and `--disable-slash-commands`.
  - CLAUDE.md and auto-memory discovery have no confirmed switch outside `--bare`. If the probe finds none, the docs and a one-time launch notice say so, rather than claiming isolation.
  - Record a `PROBED_CLAUDE_VERSION` constant (house doctrine).
- files.create: `candy-code/src/Providers/HonoursHostPermissionMode.php`, `candy-code/tests/Providers/CliProviderIsolationTest.php`
- files.modify:
  - `candy-code/src/Providers/ClaudeCodeProvider.php` (`options()`, constructor `isolate`)
  - `candy-code/src/Providers/ClaudeCodeInvocation.php` (`printModeArgs()`)
  - `candy-code/src/Backend/EngineBackend.php` (per-turn rebinding)
  - `candy-code/src/Providers/ProviderFactory.php` (`TYPE_SCHEMAS['claude-code']` `isolate`, builder)
  - `candy-code/README.md` (L176 "safety hooks wire automatically" claim, claude-code row)
  - `candy-code/docs/SETTINGS.md` (claude-code block prose)
- tests: CliProviderIsolationTest: argv pins for `bare:false` + isolate; plan → `--permission-mode plan`; `isolate:false` keeps today's argv; `bare:true` unchanged.
- docs/guards: README and SETTINGS prose; lang for the disclosure notice
- serialized: [docs:README.md] [docs:SETTINGS.md] [lang:provider.claude_code.isolation*] [hot:candy-code/src/Backend/EngineBackend.php] [hot:candy-code/src/Providers/ClaudeCode*.php] [hot:candy-code/src/Providers/ProviderFactory.php]
- deps: none
- phase: normal
- ruling: none (default-on isolation with an `isolate: false` escape. If the owner prefers opt-in, see Notes.)
- risks:
  - A narrowed `--setting-sources` must not break the OAuth login, which lives in credentials, not settings. Probe it with a real subscription login before flipping the default.
  - No new spawn, so no roster row.

### C-PRV-4.b — Codex isolation, mode → `-s`, `--output-schema`
- item: C-PRV-4 (sources: PRV-4-P3, p1-miss: Codex parity (mode → `-s`, `--output-schema`))
- kind: competitor-feature · effort M
- approach:
  - Root cause: `CodexInvocation::execArgs()` passes `-s` only when configured (`CodexInvocation.php:80-96`). Plan mode with a `workspace-write` config still edits files (`CodexProvider.php:27-31, 56-61`).
  - Implement `HonoursHostPermissionMode` on `CodexProvider`. Always pass an explicit `-s`: the configured `sandbox`, else `workspace-write`, narrowed to `read-only` in plan and read-only modes. `danger-full-access` only when configured, and never widened.
  - Under `isolate` (default true), add `--ignore-user-config` (verified in `codex exec --help`: auth still uses `CODEX_HOME`), which drops foreign config.toml MCP servers and profiles.
  - `--output-schema <file>`: write `CompleteRequest::$jsonSchema` to a 0600 temp file, removed in `finally`. Flip `supportsJsonSchema()` to true only with a recorded live probe, and bump `PROBED_CODEX_VERSION`.
- files.create: `candy-code/tests/Providers/CodexIsolationTest.php`
- files.modify:
  - `candy-code/src/Providers/CodexProvider.php` (`options()`, `supportsJsonSchema()`, interface)
  - `candy-code/src/Providers/CodexInvocation.php` (`execArgs()`)
  - `candy-code/src/Providers/ProviderFactory.php` (`TYPE_SCHEMAS['codex']` `isolate`)
  - `candy-code/README.md` (codex row and L176 claim)
  - `candy-code/docs/SETTINGS.md` (codex posture)
- tests: CodexIsolationTest: plan → `-s read-only` over a `workspace-write` config; an explicit `-s` always present; `--ignore-user-config` under isolate; the schema temp file is created and removed; modify `candy-code/tests/Providers/CodexProviderTest.php`
- docs/guards: README and SETTINGS posture prose (PRV-4-P3's "undocumented" half)
- serialized: [docs:README.md] [docs:SETTINGS.md] [hot:candy-code/src/Providers/Codex*.php] [hot:candy-code/src/Providers/ProviderFactory.php]
- deps: C-PRV-4.a (the interface and the EngineBackend rebinding)
- phase: normal
- ruling: none
- risks: `--ignore-user-config` changes behaviour for anyone relying on config.toml defaults. `isolate:false` restores it, and the changelog line says so.

### C-CTX-6.a — Served idle alarm survives a running turn
- item: C-CTX-6 (sources: CTX-5-P1)
- kind: fix · MINOR · effort S
- approach:
  - Root cause: the served alarm is armed only on submit (`SessionHost.php:562`) as a one-shot `addTimer` (:656-661), and it returns on `isBusy()` (:678) with the timer already nulled and never re-armed. Idleness is measured from `lastPromptAt` (:704).
  - So a 65-minute turn loses the fold, and a 59-minute turn gets a billed fold a minute after it settles. The TUI re-checks every 60 s (`App.php:4691-4697`).
  - Fix:
    - stamp `lastSettledAt` in `settleWith()`;
    - measure idleness from max(prompt-accepted, turn-settled);
    - re-arm the alarm when it fires during a turn, and on every settle.
- files.create: none
- files.modify: `candy-code/src/Host/SessionHost.php` (`armIdleAutoCompaction()`, `onIdleAutoCompaction()`, `settleWith()`)
- tests: modify `candy-code/tests/Host/SessionHostIdleAutoTest.php`: a mid-turn fire re-arms; idleness counts from settle; a settle re-arms
- docs/guards: none
- serialized: [hot:candy-code/src/Host/SessionHost.php]
- deps: none
- phase: normal
- ruling: none
- risks: none.

### C-CTX-6.b — PDF page check and an aggregate document budget
- item: C-CTX-6 (sources: CTX-2-P1)
- kind: fix · MINOR · effort M
- approach:
  - Root cause: PDFs have only a per-file 20 MiB cap (`FileMentions.php:58-64`). `AttachmentEncoding.php:87-95` re-encodes every earlier document on every request, so a 15 MB + 10 MB pair puts every later request over 32 MiB and each turn 400s or 413s.
  - At attach time, `FileMentions` estimates pages (count `/Type /Page` objects) and refuses above 100 pages with a notice. Compressed object streams that hide the count are allowed through as unknown.
  - `EngineBackend::typedUserMessage()` (:5961) keeps a cumulative base64 budget. Older document blocks over it are withheld and replaced by a one-line placeholder ("document <name> withheld; re-attach to resend"), with one notice per turn.
- files.create: `candy-code/tests/Backend/DocumentAggregateBudgetTest.php`
- files.modify: `candy-code/src/Attachments/FileMentions.php` (page estimate, refusal), `candy-code/src/Backend/EngineBackend.php` (`typedUserMessage()`)
- tests: create DocumentAggregateBudgetTest: with two PDFs the older one is withheld and the newest kept; modify `candy-code/tests/Attachments/FileMentionsTest.php`: a >100-page refusal, and unknown counts pass
- docs/guards: `candy-code/docs/MEDIA.md` (attachment limits); lang `chat.attach.pdf_pages`, `chat.attach.document_withheld`
- serialized: [lang:chat.attach.*] [docs:MEDIA.md] [hot:candy-code/src/Backend/EngineBackend.php]
- deps: none
- phase: normal
- ruling: none
- risks: the page heuristic is approximate. It refuses only on a positive count over 100.

### C-CTX-6.c — Context nit bundle
- item: C-CTX-6 (sources: VIS-6-P1, k1-dorm#7, INT-1-P1, CTX-5-P2, p2-miss: TUI-6 English plural)
- kind: fix · MINOR/NIT · effort S
- approach:
  - **VIS-6-P1:** `Runtime::announceMemoryRecall()` (`Runtime.php:5991-5994`) injects the English plural as a parameter. Split `chat.memory.recalled_notice` (`lang/en.php:1898`) into `.one`/`.many` keys.
  - **k1-dorm#7:** truth-flip the `/goal` spec comment (`builtin-commands/3400-goal.php:15-19`). The served judge loop is wired (`SessionHost.php:1864-1930`).
  - **INT-1-P1:** add to the `instructionsExclude` help that patterns match ancestor files by absolute path (`**/CLAUDE.local.md` reaches ancestors), then regenerate SETTINGS.md.
  - **CTX-5-P2:**
    - `docs/CONTEXT.md` names the real reuse points: `SessionHost::requestAheadOfNeed()` on serve and `buildSummarizationRequest` in the TUI;
    - the `compaction.idleAuto` help discloses that no short cache-warm idle tier ships yet. C-CTX-5.c later replaces that sentence.
- files.create: none
- files.modify:
  - `candy-code/src/Runtime.php` (`announceMemoryRecall()`)
  - `candy-code/lang/en.php` (`chat.memory.recalled_notice.*`, `settings.instructionsExclude.help`, `settings.compaction.idleAuto.help`)
  - `candy-code/builtin-commands/3400-goal.php` (comment only)
  - `candy-code/docs/SETTINGS.md` (gen)
  - `candy-code/docs/CONTEXT.md`
- tests: modify `candy-code/tests/LangParityTest.php` only if it pins the removed key; add a one/many pin wherever the recall row is asserted today (grep `recalled_notice` in tests/)
- docs/guards: settings gen + `candy-code/tests/Config/Settings/SettingsSchemaDocDriftTest.php`
- serialized: [runtime:announceMemoryRecall] [commands] [lang:chat.memory.recalled_notice.*, settings.*.help] [docs:SETTINGS.md] [docs:CONTEXT.md]
- deps: none
- phase: normal
- ruling: none
- risks: none.

### C-CTX-6.d — `/memory open` edits enter the memory journal
- item: C-CTX-6 (sources: VIS-2-P1)
- kind: fix · NIT · effort S
- approach:
  - Root cause: `Chat::memoryOpenArm()` (`Chat.php:15628-15660`) answers before `MemoryCommand`'s `MemoryHistoryCommand::record` (`MemoryCommand.php:60-62, 82-84`), so `/memory open` edits never reach `/memory log`/`undo`.
  - Fix: `EditorCommand::openFile()` takes an optional on-saved closure, run on exit 0 when the file's hash changed. `memoryOpenArm()` passes one that records the note in the memory history.
- files.create: `candy-code/tests/Chat/MemoryOpenJournalTest.php`
- files.modify: `candy-code/src/Commands/EditorCommand.php` (`openFile()`, `openCollect()`), `candy-code/src/Chat.php` (`memoryOpenArm()`)
- tests: MemoryOpenJournalTest: `/memory open` followed by a stubbed editor edit, then `/memory log`, shows the entry; an unchanged file records nothing
- docs/guards: none
- serialized: [chat:memoryOpenArm] [hot:candy-code/src/Commands/EditorCommand.php]
- deps: none
- phase: normal
- ruling: none
- risks: none.

### C-CTX-6.e — Structured, expandable recall row
- item: C-CTX-6 (sources: p2-miss: TUI-6 `Message::recallNotice()` row)
- kind: gap · effort M
- approach:
  - Today the "💭 Recalled N notes" row is a count-only string.
  - Add `RuntimeNoticeSink::recordStructured(kind, payload)`: a JSON datagram with a reserved prefix, bounded by `MAX_CHARS`. Only `memory.recall` with `{count, titles[]}` uses it.
  - `Chat::pumpRuntimeNotices()` and `SessionHost`'s drain (C-CTX-2.a) turn that kind into a new `Message::recallNotice()` row (uiOnly; the content falls back to the count sentence).
  - The renderer shows it collapsed ("💭 Recalled 3 notes ▸") and expands it to the note titles through the transcript's existing expand affordance. No new key binding.
  - `announceMemoryRecall()` sends titles, not bodies.
- files.create: `candy-code/tests/Chat/RecallNoticeRowTest.php`
- files.modify:
  - `candy-code/src/Diagnostics/RuntimeNoticeSink.php` and `candy-code/src/Diagnostics/NoticeSink.php` (structured record and drain)
  - `candy-code/src/Runtime.php` (`announceMemoryRecall()`)
  - `candy-code/src/Message.php` (`recallNotice()`)
  - `candy-code/src/Chat.php` (`pumpRuntimeNotices()`)
  - `candy-code/src/Host/SessionHost.php` (drain mapping)
  - `candy-code/src/Renderer.php` (collapsed row)
- tests: RecallNoticeRowTest (structured round-trip through the transport; collapsed and expanded render; an old plain-string row still renders); modify `candy-code/tests/Diagnostics/RuntimeNoticeSinkTest.php` (datagram bound)
- docs/guards: `candy-code/docs/MEMORY.md` L455-460 (recall row)
- serialized: [runtime:announceMemoryRecall] [renderer] [chat:pumpRuntimeNotices] [lang:chat.memory.recalled_*] [docs:MEMORY.md] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Message.php]
- deps: C-CTX-6.c, C-CTX-2.a
- phase: normal
- ruling: none
- risks: this is the lowest-value step here (the plan review called it optional). The orchestrator may run it last in the area.

### C-CTX-3.a — `/instructions [open <n|path>]`
- item: C-CTX-3 (sources: GAP-INS-16)
- kind: gap · effort M
- approach:
  - `/memory open <id>` edits memory notes only, and nothing opens CLAUDE.md or AGENTS.md (`EditorCommand::openFile()` has one caller).
  - Add `/instructions`. It lists the session's instruction files with tier and bytes: the same rows as `ContextSources::priceInstructionFiles()` (`ContextSources.php:98`), from `loadDocuments()` + `loadPersonal()`.
  - `/instructions open <n|path>` opens the pick through `EditorCommand::openFile()`. It is jailed to the listed paths, plus the personal file, which it creates if missing.
  - The edits reach the model through `InstructionDiffTracker` (C-CTX-1.a).
  - Spec file `candy-code/builtin-commands/2405-instructions.php` (new, per-command-disjoint) with `withHandler('handleInstructionsCommand')` and `withHostCommand(InstructionsHostCommand::class)`. Served, it lists the rows, and `open` answers "needs a terminal" like `/memory open`.
  - `instructions` joins `READ_ONLY_COMMANDS`.
- files.create: `candy-code/builtin-commands/2405-instructions.php`, `candy-code/src/Commands/InstructionsCommand.php`, `candy-code/src/Host/Commands/InstructionsHostCommand.php`, `candy-code/tests/Commands/InstructionsCommandTest.php`
- files.modify: `candy-code/src/Chat.php` (new `handleInstructionsCommand()`, open arm sharing `memoryOpenArm()`'s shape, `READ_ONLY_COMMANDS`), `candy-code/src/Host/ContextSources.php` (expose the row list for reuse), `candy-code/README.md` (gen), `candy-code/docs/COMMANDS.md` (gen), `candy-code/docs/MEMORY.md`
- tests: InstructionsCommandTest: list; open-by-index; a path outside the list is refused; served `open` says it needs a terminal
- docs/guards: `php tools/gen-command-docs.php --write`; `CommandDocsDriftTest`; `ReadmeRosterDriftTest::testTheSlashCommandRosterIsExactlyWhatTheRegistryAdvertises`; `RegistryTranslationTest`
- serialized: [chat:handleInstructionsCommand (new), memoryOpenArm region, READ_ONLY_COMMANDS] [commands] [lang:cmd.instructions.*, host.instructions.*] [docs:COMMANDS.md] [docs:README.md] [docs:MEMORY.md] [hot:candy-code/src/Commands/EditorCommand.php] [hot:candy-code/src/Host/ContextSources.php]
- deps: C-CTX-6.d (same Chat region and EditorCommand), C-CTX-1.a (edits must reach the model on the fork path)
- phase: normal
- ruling: none
- risks: P-CTX-BREAKDOWN also reads `ContextSources`. Keep the row shape additive.

### C-CTX-3.b — `#` quick-add to personal instructions (TUI)
- item: C-CTX-3 (sources: GAP-INS-16 (`#` quick-add))
- kind: gap · effort S
- approach:
  - A single-line submit starting with `#` followed by a non-`#` character becomes a quick-add, not a turn.
  - A uiOnly confirm row asks: Enter appends `- <text>` to personal `~/.candy-code/AGENTS.md` (created 0600 if absent, `ContainedPath`-checked), Esc cancels and restores the draft.
  - Multi-line input and `##…` are never captured, so pasted markdown headings still go to the model.
  - The note reaches the model via the tracker (C-CTX-1.a).
- files.create: `candy-code/tests/Chat/InstructionQuickAddTest.php`
- files.modify: `candy-code/src/Chat.php` (`submit()` prefix arm and confirm handling)
- tests: InstructionQuickAddTest: confirm appends; Esc writes nothing; multi-line and `##` pass through as prompts
- docs/guards: `candy-code/docs/MEMORY.md` (quick-add). No key binding, so `KeyBindingDriftTest` is untouched.
- serialized: [chat:submit,ctor,key dispatch] [lang:chat.instructions.quick_add*] [docs:MEMORY.md]
- deps: C-CTX-1.a
- phase: normal
- ruling: none
- risks: a user who types a one-line prompt starting with `#` meets the confirm. Esc restores the draft, so nothing is lost.

### C-CTX-3.c — Web instruction editing and `#` quick-add (protocol lane)
- item: C-CTX-3 (sources: GAP-INS-16 (web half))
- kind: gap · effort M
- approach:
  - New write-scoped method `context.instructionWrite {sessionId, path, content? | append?}`. It is jailed to the paths `context.sources` lists for the session, plus the personal file.
  - It refuses symlink escapes (`ContainedPath`), and its size is capped at `InstructionFileLoader::MAX_DOCUMENT_BYTES`.
  - A successful write announces `context.changed` (from C-CTX-2.d,) so the panel refreshes.
  - Web: each instruction row in the Context panel gets an Edit affordance: a modal textarea, save with conflict detection on the listed byte count. The Composer turns a one-line `#text` into a confirm chip that appends to the personal file through the same method.
- files.create: `candy-code/tests/Protocol/ContextInstructionWriteTest.php`, `candy-code-web/src-web/components/panels/__tests__/contextPanelEdit.spec.ts`, `candy-code-web/src-web/components/__tests__/composerQuickAdd.spec.ts`
- files.modify:
  - `candy-code/src/Protocol/Methods/ContextMethods.php`
  - `candy-code/src/Protocol/Schema/MethodSchemas.php`
  - `candy-code/docs/protocol/candy-code.v1.schema.json` (gen)
  - `candy-code/docs/SERVER.md` (### Methods, gen)
  - `candy-code-web/src-web/protocol/generated.ts` (gen)
  - `candy-code-web/src-web/components/panels/ContextPanel.vue`
  - `candy-code-web/src-web/components/Composer.vue`
  - `candy-code/docs/MEMORY.md`
- tests: ContextInstructionWriteTest (listed path writes; unlisted, symlink-escaped and read-scope requests are refused; the size cap holds); the two web specs
- docs/guards: `ServerProtocolDocumentationDriftTest`, `ProtocolSchemaDriftTest`, both gen `--check`s
- serialized: [protocol] [docs:SERVER.md] [docs:MEMORY.md] [hot:candy-code-web/src-web/components/Composer.vue] [hot:candy-code-web/src-web/components/panels/ContextPanel.vue]
- deps: C-CTX-1.a, C-CTX-2.d (adds the `context.changed` event this step uses)
- phase: normal
- ruling: none
- risks: ContextPanel.vue is P-CTX-BREAKDOWN's main file. Sequence this step and that pick in different waves.

### C-CTX-3.d — Model recall over past sessions and the compaction journal
- item: C-CTX-3 (sources: GAP-MEM-11/12, GAP-SES-11 (model half))
- kind: gap · effort M
- approach:
  - The FTS5 session search is user-only, and the compaction journal feeds only the dream pass.
  - The `Memory` tool's `recall` action gains `source: notes|sessions|journal` (default `notes`, so the existing schema keeps its meaning):
    - `sessions` queries `SessionSearchIndex` (bm25 + snippet), limited to sessions of this project root and excluding the current one;
    - `journal` reads recent `CompactionJournal` entries.
  - Results are bounded (N hits × snippet bytes), passed through `SecretRedactor`, and fenced as untrusted data.
- files.create: `candy-code/tests/Tools/BuiltIn/MemoryRecallSourcesTest.php`
- files.modify: `candy-code/src/Tools/BuiltIn/MemoryTool.php` (schema, `recall()`), `candy-code/src/Session/SessionSearchIndex.php` (snippet query scoped by cwd), `candy-code/src/Memory/CompactionJournal.php` (bounded read view)
- tests: MemoryRecallSourcesTest: session hits are scoped to the root and exclude the current session; journal hits are bounded; secrets are redacted; default `notes` is unchanged
- docs/guards: `php tools/gen-tool-docs.php --write` (Memory gloss and schema) under `ToolDocDriftTest`; `candy-code/docs/MEMORY.md` (recall sources)
- serialized: [docs:MEMORY.md] [docs:README.md (tool gen region)] [hot:candy-code/src/Tools/BuiltIn/MemoryTool.php]
- deps: C-SES-2 (other area: session-search fixes, which make the FTS hits reachable)
- phase: normal
- ruling: none
- risks: cross-session recall can surface another session's secrets or prompts. Redaction and root scoping are mandatory, not optional.

### C-CTX-3.e — Memory export and prompt-visible import scope
- item: C-CTX-3 (sources: GAP-MEM-14)
- kind: gap · effort S
- approach:
  - Imports land in the agent scope (`ForeignMemoryImporter.php:254, 317`, `MemoryScope::Local`), outside the index and recall.
  - `/memory import … --scope project|user` routes through `MemoryWriter`, so imported notes are indexed and recalled.
  - `/memory export [path]` writes one markdown bundle with frontmatter per note, 0600, jailed like `/export`.
  - Both run in the host `MemoryCommand`, which the TUI router already reaches, so there is no Chat change.
- files.create: `candy-code/tests/Host/Commands/MemoryExportCommandTest.php`
- files.modify: `candy-code/src/Host/Commands/MemoryCommand.php` (`export` verb, import scope flag), `candy-code/src/Memory/ForeignMemoryImporter.php` (scope parameter)
- tests: MemoryExportCommandTest (bundle shape, 0600 mode, jail refusal); modify `candy-code/tests/Commands/MemoryImportCommandTest.php` (`--scope project` notes appear in recall)
- docs/guards: `candy-code/docs/MEMORY.md` (import/export); lang `host.memory.export*`, `host.memory.usage.*`
- serialized: [lang:host.memory.*] [docs:MEMORY.md]
- deps: none
- phase: normal
- ruling: none
- risks: none.

### C-CTX-3.f — Standing intents
- item: C-CTX-3 (sources: GAP-MEM-N3)
- kind: gap · effort M
- approach:
  - Add an `intent` memory type with a `when:` trigger: a path glob, a tool name or a keyword.
  - The model saves one with `Memory save type=intent when=…`.
  - At tool time, `RulePathNudge` consults intent notes through the existing `PathTrigger`/`KeywordTrigger` classes (`src/Context/Triggers/`), at the same seam that delivers path-scoped rules. A matching intent rides the tool result like a nudge, once per intent per turn.
  - Intents are listed in `/memory list` under their own heading.
- files.create: `candy-code/tests/Context/StandingIntentTest.php`
- files.modify: `candy-code/src/Memory/MemoryWriter.php` (`TYPES`), `candy-code/src/Memory/MemoryEntry.php` (`when` frontmatter), `candy-code/src/Tools/BuiltIn/MemoryTool.php` (save schema), `candy-code/src/Context/RulePathNudge.php` (intent lookup)
- tests: StandingIntentTest: a path intent fires on a matching Read and is silent elsewhere; a keyword intent fires once per turn; a malformed `when` is refused at save
- docs/guards: tool gen (`ToolDocDriftTest`); `candy-code/docs/MEMORY.md` (intents)
- serialized: [lang:host.memory.intent*] [docs:MEMORY.md] [hot:candy-code/src/Tools/BuiltIn/MemoryTool.php] [hot:candy-code/src/Context/RulePathNudge.php]
- deps: none
- phase: normal
- ruling: none
- risks: intents written by the model steer later turns. They are tagged and listed so the user can see and delete them.

### C-CTX-4.a — Live date and knowledge-cutoff note
- item: C-CTX-4 (sources: GAP-PRM-14)
- kind: gap · effort S
- approach:
  - The `<env>` date is frozen at session start, and there is no cutoff note.
  - `TurnContextBlock` gains `withCurrentDate()`. It renders "Current date: YYYY-MM-DD" only when the date differs from the `<env>` date, so the prefix never moves, and `Runtime::turnContext()` (:5712) passes today's date.
  - `EnvironmentBlock::capture()` adds "Knowledge cutoff: …" from a per-family table in `ModelMetadata`, only when the family is known. This line is part of the static per-session prefix.
- files.create: `candy-code/tests/Context/TurnContextDateTest.php`
- files.modify: `candy-code/src/Context/TurnContextBlock.php`, `candy-code/src/Runtime.php` (`turnContext()`), `candy-code/src/Context/EnvironmentBlock.php` (`capture()`), `candy-code/src/Providers/ModelMetadata.php` (cutoff table)
- tests: TurnContextDateTest (same day → no row; next day → one row); modify `candy-code/tests/Context/EnvironmentBlockTest.php` (cutoff for a known family, absent for an unknown one); modify `candy-code/tests/Providers/PromptPrefixByteStabilityTest.php` (a date change does not move the prefix)
- docs/guards: `candy-code/docs/PROMPT_ENGINEERING.md`
- serialized: [runtime:turnContext] [docs:PROMPT_ENGINEERING.md] [hot:candy-code/src/Context/EnvironmentBlock.php]
- deps: none
- phase: normal
- ruling: none
- risks: a hand-kept cutoff table goes stale. Render nothing when unsure.

### C-CTX-4.b — `SOUL.md` and `USER.md` persona files
- item: C-CTX-4 (sources: GAP-PA-04)
- kind: gap · effort S
- approach:
  - `InstructionFileLoader::loadPersonal()` (:524) also reads `~/.candy-code/SOUL.md` and `~/.candy-code/USER.md`, OpenClaw's names.
  - Each is fenced like the personal AGENTS.md, contained to the personal dir, and budgeted.
  - `ContextSources` prices them as personal-tier rows, so the Context panel and P-CTX-BREAKDOWN show them.
- files.create: none
- files.modify: `candy-code/src/Context/InstructionFileLoader.php` (`loadPersonal()`), `candy-code/src/Host/ContextSources.php` (`priceInstructionFiles()`)
- tests: modify `candy-code/tests/Context/PersonalInstructionFileTest.php`: both files load fenced; a symlink escape is refused and recorded; absent files cost nothing
- docs/guards: `candy-code/docs/MEMORY.md` (personal tier); keep `candy-code/tests/Config/MemoryDocumentationDriftTest.php` green
- serialized: [docs:MEMORY.md] [hot:candy-code/src/Context/InstructionFileLoader.php] [hot:candy-code/src/Host/ContextSources.php]
- deps: none
- phase: normal
- ruling: none
- risks: none.

### C-CTX-4.c — Walk past the git root; absolute and `~` user-tier sources
- item: C-CTX-4 (sources: GAP-INS-07, GAP-INS-11 (absolute half))
- kind: gap · effort S
- approach:
  - A new user-tier bool `instructions.walkToRoot` lets `ancestorRoot()` (`InstructionFileLoader.php:845-880`) continue above the nearest `.git` up to, but not including, `$HOME`, under the same containment and budget.
  - `loadForced()` (:1043-1075) refuses absolute and `~` patterns. It now accepts them when they come from the user tier only.
  - `Bootstrap::forcedInstructions()` (:8965) splits user-tier from project-tier patterns, so a checked-out repo can never name `/etc/...`.
  - Each match is resolved through a new `ContainedPath` anchor (the user's home).
- files.create: `candy-code/tests/Context/InstructionWalkPastRootTest.php`
- files.modify: `candy-code/src/Context/InstructionFileLoader.php` (`ancestorRoot()`, `loadForced()`), `candy-code/src/Cli/Bootstrap.php` (`instructionLoader()`, `forcedInstructions()`), `candy-code/src/Config/Settings/Definitions/MemoryRuleSettings.php`, `candy-code/src/Config/LayeredSettings.php` (gen), `candy-code/docs/SETTINGS.md` (gen), `candy-code/docs/MEMORY.md`
- tests: InstructionWalkPastRootTest (walks above `.git` only when set; stops before `$HOME`; a project-tier absolute pattern is still refused); modify `candy-code/tests/Support/ContainedPathInventoryTest.php` (`ROUTED_CALL_SITES` row for the new anchor)
- docs/guards: settings gen + `SettingsSchemaDocDriftTest`; `ContainedPathInventoryTest`
- serialized: [bootstrap:instructionLoader, forcedInstructions] [docs:SETTINGS.md] [docs:MEMORY.md] [hot:candy-code/src/Context/InstructionFileLoader.php] [hot:candy-code/src/Config/Settings/Definitions/MemoryRuleSettings.php] [hot:candy-code/src/Config/LayeredSettings.php]
- deps: none
- phase: normal
- ruling: none
- risks: walking above the repo can pick up a stray CLAUDE.md in a parent workspace. That is why it is opt-in.

### C-CTX-4.d — URL instruction sources and a shared egress guard
- item: C-CTX-4 (sources: GAP-INS-11 (URL half))
- kind: gap · effort M
- approach:
  - User-tier `instructions` entries that start with `https://` are fetched once per session in the parent prime, before any fork.
  - Each fetch goes through an SSRF guard extracted from `WebFetch` into `Support\PublicUrlGuard`: `BLOCKED_HOSTNAMES`, `BLOCKED_IP_RANGES` and the mixed-answer refusal, from `WebFetch.php:38, 103-117`. WebFetch keeps behaving identically through the shared class.
  - Limits: a 15 s connect+read budget (this is not an LLM call), `MAX_DOCUMENT_BYTES`, and HTTPS only.
  - Each document is cached for the session, fenced as untrusted remote content, and failures become a launch notice.
  - Project-tier URLs are refused.
- files.create: `candy-code/src/Support/PublicUrlGuard.php`, `candy-code/tests/Support/PublicUrlGuardTest.php`, `candy-code/tests/Context/InstructionUrlSourceTest.php`
- files.modify: `candy-code/src/Tools/BuiltIn/WebFetch.php` (delegate to the guard), `candy-code/src/Context/InstructionFileLoader.php` (`loadForced()` URL arm), `candy-code/docs/MEMORY.md`, `candy-code/docs/SETTINGS.md` (`instructions` prose)
- tests: PublicUrlGuardTest (WebFetch's existing cases move here unchanged); InstructionUrlSourceTest (fenced, cached, size-capped; private IPs and project tier refused)
- docs/guards: SETTINGS/MEMORY prose; WebFetch's existing tests stay green
- serialized: [docs:MEMORY.md] [docs:SETTINGS.md] [hot:candy-code/src/Context/InstructionFileLoader.php] [hot:candy-code/src/Tools/BuiltIn/WebFetch.php]
- deps: C-CTX-4.c (the user-tier pattern split)
- phase: normal
- ruling: none
- risks: C-TOOL-2 (other area) rewrites WebFetch's body to convert HTML to Markdown. The two must be in different waves.

### C-CTX-4.e — Output styles (`/output-style`)
- item: C-CTX-4 (sources: GAP-PRM-10)
- kind: gap · effort M
- approach:
  - Styles are rulebook-kind files in `~/.candy-code/styles/*.md`, and in `.candy-code/styles/*.md` for the project.
  - `RuleLoader` loads them with a `style` flag on `Rule`, and `RulesState` keeps exactly one style active per session.
  - They ride the loader path that Runtime already hands the `/rules` toggle set to (`Runtime.php:4603-4616`), so there is no Runtime edit. They are fenced as user rules and never override the base prompt (house doctrine). Switching styles is a refresh point, and the docs say so.
  - `/output-style [name|off]` lists, sets or clears the style. New spec `candy-code/builtin-commands/1405-output-style.php` (per-command-disjoint) with `withHandler('handleOutputStyleCommand')` and `withHostCommand(OutputStyleHostCommand::class)`.
- files.create: `candy-code/builtin-commands/1405-output-style.php`, `candy-code/src/Host/Commands/OutputStyleHostCommand.php`, `candy-code/tests/Context/OutputStyleTest.php`
- files.modify: `candy-code/src/Context/Rule.php` (`style` flag), `candy-code/src/Context/RuleLoader.php` (styles dirs), `candy-code/src/Context/RulesState.php` (active style), `candy-code/src/Chat.php` (new `handleOutputStyleCommand()`), `candy-code/README.md` (gen), `candy-code/docs/COMMANDS.md` (gen), `candy-code/docs/MEMORY.md` (rules tiers)
- tests: OutputStyleTest: one active at a time; `off` clears it; a style is present in the splice and the prefix moves only on a switch; served command parity
- docs/guards: command gen; `CommandDocsDriftTest`; `ReadmeRosterDriftTest` slash roster; `RegistryTranslationTest`
- serialized: [chat:handleOutputStyleCommand (new)] [lang:cmd.output-style.*, host.output_style.*] [docs:COMMANDS.md] [docs:README.md] [docs:MEMORY.md] [hot:candy-code/src/Context/Rule.php] [hot:candy-code/src/Context/RuleLoader.php] [hot:candy-code/src/Context/RulesState.php]
- deps: none
- phase: normal
- ruling: none
- risks: if the rulebook splice orders styles wrongly, a dedicated section would need `[runtime]`. The lane must report this rather than widen the step silently.

### C-CTX-4.f — Project references (extra read-only roots)
- item: C-CTX-4 (sources: GAP-PRM-N2)
- kind: gap · effort M
- approach:
  - New user-tier only setting `references: [{path, name}]`.
  - Each reference is advertised in `<env>` through `EnvironmentBlock::withSettings()`, with a capped `RepoMapBlock` summary.
  - Read, Grep, Glob and Ls accept paths under a reference as read-only roots, through a new `ToolBuildContext::$readRoots` that `LaunchContext::tools()` fills.
  - Write, Edit, Bash and the worktree jail stay confined to the session root.
  - A missing or non-directory reference becomes a launch notice. A reference inside the session root is refused as redundant.
- files.create: `candy-code/tests/Context/ProjectReferencesTest.php`
- files.modify:
  - `candy-code/src/Context/EnvironmentBlock.php` (`withSettings()`)
  - `candy-code/src/Tools/Catalog/ToolBuildContext.php`
  - `candy-code/src/Cli/LaunchContext.php` (`tools()`)
  - `candy-code/src/Tools/BuiltIn/Read.php`, `candy-code/src/Tools/BuiltIn/Grep.php`, `candy-code/src/Tools/BuiltIn/Glob.php`, `candy-code/src/Tools/BuiltIn/Ls.php` (jail check)
  - `candy-code/src/Config/Settings/Definitions/ToolSettings.php`
  - `candy-code/src/Config/LayeredSettings.php` (gen)
  - `candy-code/docs/SETTINGS.md` (gen)
  - `candy-code/docs/PERMISSIONS.md`
- tests: ProjectReferencesTest: Read inside a reference allowed; Write refused; a project-tier `references` key ignored; `<env>` lists them; modify `candy-code/tests/Tools/BuiltIn/GlobTest.php`
- docs/guards: settings gen + `SettingsSchemaDocDriftTest`; `candy-code/tests/Config/PermissionsIntroDriftTest.php` if the intro roster moves
- serialized: [docs:SETTINGS.md] [docs:PERMISSIONS.md] [hot:candy-code/src/Cli/LaunchContext.php] [hot:candy-code/src/Context/EnvironmentBlock.php] [hot:candy-code/src/Config/LayeredSettings.php] [hot:candy-code/src/Tools/BuiltIn/{Read,Grep,Glob,Ls}.php]
- deps: none
- phase: normal
- ruling: none
- risks:
  - Widening read jails is security-relevant. It stays user tier only, read-only and listed in `<env>`.
  - The C-SEC area may want to review it.

### C-CTX-4.g — Headless `--append-system-prompt`
- item: C-CTX-4 (sources: GAP-PRM-09)
- kind: gap · effort S
- approach:
  - New flag `--append-system-prompt <text|@file>`, valid with `-p` only (refused on a TUI launch, like the AUT-4-P1 lesson).
  - The text is spliced as one `<user-rules>`-fenced section at the end of the system prompt: never an override, escaped like every fence, bounded at `MAX_DOCUMENT_BYTES`.
- files.create: `candy-code/tests/Cli/AppendSystemPromptTest.php`
- files.modify: `candy-code/src/Cli/ArgvParser.php`, `candy-code/src/Cli/ParsedArgs.php`, `candy-code/src/Cli/NonInteractive.php`, `candy-code/bin/candy-code`, `candy-code/src/Runtime.php` (`systemPromptSections()`), `candy-code/README.md` (CLI table), `candy-code/docs/PROMPT_ENGINEERING.md` (the doctrine sentence that says there is deliberately no flag)
- tests: AppendSystemPromptTest: `-p` splice is fenced; a TUI launch is refused; `@file` read is capped; modify `candy-code/tests/Cli/ArgvParserTest.php`
- docs/guards: `candy-code/tests/Config/ReadmeRosterDriftTest.php::testTheSubcommandFenceNamesEveryVerbTheParserAccepts` (flag roster); `candy-code/tests/Config/ReadmeJsonErrorContractDriftTest.php`
- serialized: [headless] [runtime:systemPromptSections] [docs:README.md] [docs:PROMPT_ENGINEERING.md]
- deps: none
- phase: normal
- ruling: May `-p` accept `--append-system-prompt` (fenced, never an override) against PROMPT_ENGINEERING.md's documented no-append doctrine?
- risks: none once ruled.

### C-CTX-5.a — Summary resilience: overflow retry, summary-model fallback, failure-path pins
- item: C-CTX-5 (sources: OC-new#9, OC-new#4 (fallback half), CLAW-new#5)
- kind: competitor-feature · effort M
- approach:
  - Already shipped: `summaryModel`, cache-reusing summaries, a heuristic fold on any summary error, and a stall ceiling.
  - Missing 1: a compaction request that itself overflows the window has no second try.
  - Missing 2: a configured `summaryModel` that fails non-transiently (unknown model, 4xx) is never retried on the session's model.
  - Fix, inside `CacheReusingSummaryBackend::summariseAsync()`, which serves both hosts with no Chat or SessionHost edit:
    - on `ContextOverflow::matches()`, retry once with `CompactionService::clipOlderToolOutputs()` (older tool results cut to head and tail, the newest exchanges kept);
    - on a non-transient failure of an overridden summary model, retry once on `engine->withSummaryModel(null)`;
    - otherwise reject as today, which folds heuristically.
  - Pin CLAW-new#5 parity: a 408, a 504 or an idle-stall rejection lands a heuristic fold, and the parked turn proceeds on both hosts.
- files.create: `candy-code/tests/Backend/CacheReusingSummaryBackendTest.php`, `candy-code/tests/Host/CompactionFallbackParityTest.php`
- files.modify: `candy-code/src/Backend/CacheReusingSummaryBackend.php` (`summariseAsync()`), `candy-code/src/Host/CompactionService.php` (new `clipOlderToolOutputs()`)
- tests: CacheReusingSummaryBackendTest (overflow → one clipped retry; model failure → one session-model retry; transient errors not retried); CompactionFallbackParityTest (TUI and served parked turn both proceed on a heuristic fold after 408/504/stall)
- docs/guards: `candy-code/docs/CONTEXT.md` (compaction failure modes)
- serialized: [docs:CONTEXT.md] [hot:candy-code/src/Host/CompactionService.php] [hot:candy-code/src/Backend/CacheReusingSummaryBackend.php]
- deps: none
- phase: normal
- ruling: none
- risks:
  - Each retry is a second billed call. It is capped at one, and the ledger already bills summary calls.
  - E646: no total timeout is added.

### C-CTX-5.b — Ahead-of-need skip rules
- item: C-CTX-5 (sources: OC-new#7 (skip-rule parity))
- kind: competitor-feature · effort S
- approach:
  - The 70% tier prefetches summaries on both hosts: Chat at `Chat.php:10917-10935`, SessionHost at `SessionHost.php:1731-1749`.
  - Move the request decision into `CompactionService::aheadOfNeedAllowed()` with OpenCode's skip rules:
    - skip when the history is under a token floor (a named constant, 64k);
    - drop a held summary when the session's model changed since it was asked for, by adding the model id to the fingerprint.
  - Both call sites use it.
- files.create: `candy-code/tests/Host/AheadOfNeedSkipRulesTest.php`
- files.modify: `candy-code/src/Host/CompactionService.php`, `candy-code/src/Chat.php` (`dispatchTurn()` ahead-of-need block), `candy-code/src/Host/SessionHost.php` (`dispatch()` ahead-of-need block)
- tests: AheadOfNeedSkipRulesTest (under the floor → no request on either host; a model switch discards the held summary); modify `candy-code/tests/Host/SessionHostAheadOfNeedTest.php`
- docs/guards: `candy-code/docs/CONTEXT.md` (one sentence)
- serialized: [chat:dispatchTurn (ahead-of-need block)] [docs:CONTEXT.md] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Host/CompactionService.php]
- deps: none
- phase: normal
- ruling: none
- risks: a small-window model where 70% < 64k never prefetches, by design. It still folds at 85%, just not pre-fetched.

### C-CTX-5.c — Idle fold timed to the prompt-cache TTL
- item: C-CTX-5 (sources: GAP-CTX-N2, CC-new#10, p1-miss: CTX-5 short cache-warm idle tier, CLAW-new#5 (bounded-history view, n/a))
- kind: competitor-feature · effort M
- approach:
  - `compaction.idleAuto` becomes an enum: `off | hour | cacheTtl`. The bool keeps reading as `hour`/`off`.
  - `cacheTtl` folds a session idle just short of its provider's cache TTL, from `MarksPromptCache` (5 min for most, 1 h when marked), when the history is over a floor below the 85% tier.
  - The reason: the next prompt would otherwise prefill the whole conversation uncached.
  - `IdleCompactionPolicy` owns the decision. The TUI tick (`HandlesRunControl.php:289`, 60 s cadence from `App.php:4691-4697`) and the served alarm (fixed in C-CTX-6.a) consult it.
  - It ships opt-in. `/settings` already renders the key, which is Claude Code's settings toggle.
  - It replaces C-CTX-6.c's "not shipped" sentence.
- files.create: `candy-code/tests/Context/CacheTtlIdleFoldTest.php`
- files.modify: `candy-code/src/Context/IdleCompactionPolicy.php`, `candy-code/src/Context/CompactorConfig.php` (mode parse), `candy-code/src/Chat/Handlers/HandlesRunControl.php` (tick verdict), `candy-code/src/Host/SessionHost.php` (`armIdleAutoCompaction()` delay from the policy), `candy-code/src/Config/Settings/Definitions/ContextSettings.php`, `candy-code/src/Config/LayeredSettings.php` (gen), `candy-code/docs/SETTINGS.md` (gen), `candy-code/docs/CONTEXT.md`
- tests: CacheTtlIdleFoldTest (policy table: TTL boundary, floor, uncached provider never folds); modify `candy-code/tests/Chat/IdleAutoCompactionTest.php`, `candy-code/tests/Host/SessionHostIdleAutoTest.php`, `candy-code/tests/Context/IdleCompactionPolicyTest.php`
- docs/guards: settings gen + `SettingsSchemaDocDriftTest`
- serialized: [docs:CONTEXT.md] [docs:SETTINGS.md] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Chat/Handlers/HandlesRunControl.php] [hot:candy-code/src/Config/Settings/Definitions/ContextSettings.php] [hot:candy-code/src/Config/LayeredSettings.php]
- deps: C-CTX-6.a
- phase: normal
- ruling: none (ships opt-in; a default-on flip for caching providers is queued in Notes)
- risks: a 60 s tick against a 5 min TTL folds at about 4 min idle. That is acceptable and documented.

### C-CTX-5.d — Opt-in prompt-cache keep-warm
- item: C-CTX-5 (sources: GAP-MOD-N3, OC-new#5 (cache economics))
- kind: competitor-feature · effort M
- approach:
  - New setting `promptCache.keepWarm` (int, default 0 = off). It is the number of warm pings allowed per idle stretch.
  - Before the TTL lapses on an idle session that the cacheTtl fold did not take, a `Context\KeepWarmPolicy` decides to send a `max_tokens: 1` request over the frozen prefix, on the session engine, at most N times. It never sends while a turn is in flight or once the cap is reached.
  - `CacheHealthWatch` records warm hits, and the ledger bills them like any call. E646: no total timeout.
  - It uses the same TUI tick and served alarm as C-CTX-5.c.
- files.create: `candy-code/src/Context/KeepWarmPolicy.php`, `candy-code/tests/Host/KeepWarmTest.php`
- files.modify: `candy-code/src/Chat/Handlers/HandlesRunControl.php`, `candy-code/src/Host/SessionHost.php`, `candy-code/src/Backend/CacheHealthWatch.php`, `candy-code/src/Config/Settings/Definitions/ContextSettings.php`, `candy-code/src/Config/LayeredSettings.php` (gen), `candy-code/docs/SETTINGS.md` (gen), `candy-code/docs/CONTEXT.md`
- tests: KeepWarmTest (N pings max; none mid-turn; none past the cap; usage billed); modify `candy-code/tests/Backend/CacheHealthWatchTest.php`
- docs/guards: settings gen + `SettingsSchemaDocDriftTest`
- serialized: [docs:CONTEXT.md] [docs:SETTINGS.md] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Chat/Handlers/HandlesRunControl.php] [hot:candy-code/src/Config/Settings/Definitions/ContextSettings.php] [hot:candy-code/src/Config/LayeredSettings.php]
- deps: C-CTX-5.c
- phase: normal
- ruling: none (default off; see Notes)
- risks: warm pings cost money while nobody is watching. That is why the default is 0 and the count is shown in `/usage`.

---

## Notes for the orchestrator

**Shared hot files, with the steps that touch each:**

| File | Steps |
|---|---|
| `candy-code/src/Host/SessionHost.php` | C-CTX-2.a, 2.c, 2.d, 6.a, 6.e, 5.b, 5.c, 5.d |
| `candy-code/src/Context/InstructionFileLoader.php` | 2.f, 1.c, 4.b, 4.c, 4.d |
| `candy-code/src/Context/InstructionDiffTracker.php` | 1.a, 2.f, 1.c |
| `candy-code/src/Backend/EngineBackend.php` | C-CTX-1.a, C-CTX-6.b, C-PRV-3.a, C-PRV-4.a |
| `candy-code/src/Providers/OpenAIProvider.php` | C-PRV-1.a, 5.c, 2.a, 2.b, 2.c, 3.a |
| `candy-code/src/Providers/ProviderFactory.php` | C-PRV-5.a, 2.a, 2.c, 4.a, 4.b |
| `AnthropicProvider.php` / `Concerns/AnthropicMessagesWire.php` | C-PRV-1.a, 5.a, 2.b, 3.a |
| `candy-code/src/Config/LayeredSettings.php` + `docs/SETTINGS.md` (gen regions) | C-PRV-3.a, 3.d; C-CTX-4.c, 4.f, 5.c, 5.d, 6.c |
| `candy-code/src/Cli/LaunchContext.php` | C-PRV-1.b, 3.a, 3.d; C-CTX-4.f |
| `candy-code/src/Tools/BuiltIn/MemoryTool.php` | C-CTX-3.d, 3.f |
| `candy-code/src/Chat/Handlers/HandlesRunControl.php` | C-CTX-5.c, 5.d |
| `candy-code/src/Commands/EditorCommand.php` | C-CTX-6.d, 3.a |
| `candy-code/src/Message.php` | C-PRV-2.b, C-CTX-6.e |
| `candy-code-web/src-web/components/Composer.vue` | C-CTX-2.d, 3.c, and C-WEB-4 (other area) |

Further notes:
- `[docs:MEMORY.md]` is the most-touched page (15 steps) and `[docs:SETTINGS.md]` is next (16, mostly through the settings generator).
- **Cross-area couplings:**
  - P-CTX-BREAKDOWN consumes C-CTX-2.d (`context.changed`) and shares `Host/ContextSources.php` (C-CTX-3.a, 4.b), `ContextPanel.vue` (C-CTX-3.c) and `stores/contextSources.ts` (C-CTX-2.d).
  - C-WEB-2's `EffortHostCommand` must call C-PRV-1.a's `EffortGate`, so schedule it after C-PRV-1.a.
  - C-TOOL-2 shares `WebFetch.php` with C-CTX-4.d and rides C-PRV-2.b for OpenAI image tool results.
  - C-SES-2 must land before C-CTX-3.d.
  - AUT-4 (terminal-answer schema) is adjacent to C-PRV-5.d.
  - C-SEC may want to review C-CTX-4.f (widened read jails) and C-CTX-3.c (web writes to instruction files).
- **Rulings:**
  - Blocking: C-CTX-4.g (append-system-prompt against the doctrine).
  - Optional, not blocking (each step ships the proposed default):
    - default-on for the cacheTtl idle fold (C-CTX-5.c);
    - keep-warm default (C-CTX-5.d, ships 0);
    - live-first catalog merge order (C-PRV-5.d documents the shipped order);
    - claude-code/codex `isolate` default (C-PRV-4.a/b ship default-on with an `isolate:false` escape).
- **Could not verify (read-only pass):**
  - the Anthropic Messages field for "fast" output (C-PRV-3.a gates Anthropic on the lane's doc check);
  - whether Claude Code has a CLAUDE.md/auto-memory switch outside `--bare` (C-PRV-4.a probes it and discloses);
  - Responses API SSE shapes and Bedrock Converse thinking (fixture-pinned only);
  - codex `model_reasoning_effort` level names and an authenticated `--output-schema` run;
  - how in-turn tool LLM calls reach the parent `SpendLedger` (C-PRV-3.d must name the seam);
  - the PDF page heuristic's accuracy.
- **No new spawn** in any step: login uses sugar-core `Tty`, and catalog/URL/advisor use HTTP. So there are no `tools/check-child-lifetimes.php` roster rows. C-PRV-4.a/b change only the argv of existing rostered CLI spawns.
- **Weld list of NEW test files:**
  - PHP, under `candy-code/tests/`:
    - `Host/`: ServedRuntimeNoticeDrainTest, TurnSettleWorkTest, SessionHostTurnSettleWorkTest, ServedPromptSuggestionTest, UltrathinkTurnTest, CompactionFallbackParityTest, AheadOfNeedSkipRulesTest, KeepWarmTest
    - `Host/Commands/`: FastHostCommandTest, MemoryExportCommandTest
    - `Context/`: InstructionVisibilityNoticeTest, StandingIntentTest, TurnContextDateTest, InstructionWalkPastRootTest, InstructionUrlSourceTest, OutputStyleTest, ProjectReferencesTest, CacheTtlIdleFoldTest
    - `Providers/`: EffortGateTest, ThinkingCeilingTest, GeminiResponseSchemaTest, OpenAiResponsesWireTest, OpenAiResponsesReasoningTest, AzureOpenRouterTypesTest, ServiceTierTest, CliProviderIsolationTest, CodexIsolationTest
    - `Cli/`: LaunchEffortNoticeTest, ToollessBackendThinkingTest, AppendSystemPromptTest
    - `Chat/`: PaletteCatalogAsyncTest, FastCommandTest, MemoryOpenJournalTest, RecallNoticeRowTest, InstructionQuickAddTest
    - `Tools/BuiltIn/`: AdvisorToolTest, MemoryRecallSourcesTest
    - `Backend/`: DocumentAggregateBudgetTest, CacheReusingSummaryBackendTest
    - `Commands/`: InstructionsCommandTest
    - `Protocol/`: ContextInstructionWriteTest
    - `Support/`: PublicUrlGuardTest
  - Web specs, which are not in the durations TSV: `components/__tests__/composerSuggestion.spec.ts`, `components/__tests__/composerQuickAdd.spec.ts`, `stores/__tests__/contextChanged.spec.ts`, `components/panels/__tests__/contextPanelEdit.spec.ts`.
- **Protocol lanes:** only C-CTX-2.d and C-CTX-3.c touch `[protocol]`. Put them in different waves, and keep both away from the wave holding P-CTX-BREAKDOWN's protocol step if it has one.
