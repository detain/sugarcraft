# Review — `plan_comparison.md` (2026-10-26)

**Verdict.** The plan is mostly safe to execute.
- Re-running `schedule_plan.py` reproduces `schedule.json` exactly, with no warnings.
- No step runs before a declared dep.
- All 40 CRITICAL/MAJOR fixes land in W1–W9, and LATE holds only the six large items.

Two MAJOR problems need fixing before W2 starts:
- the protocol-output rule contradicts itself;
- W18 pairs two steps that one of their own designs says never to pair.

Ten MINOR and six NIT findings follow.

**Method.**
- **Concurrency.** Read the design blocks (approach, files, tests, guards, risks) of every lane in W1, W2, W5, W12, W15, W20, W26 and W32. A script compared every lane's declared files against files named only in approach, tests, guards or risks text, across all 40 waves.
- **Dependencies.** Swept 130 wiring steps (Chat, Bootstrap, App, SessionHost, protocol) plus every other step for `Class::method` and protocol method/event names. Grep-verified 60 of the used symbols on master.
- **Plan text.** Checked plan §0–§2 and §6–§8 against the tree, `package.json` and the generator CLIs.

---

## MAJOR

### M1. W18 — C-AGT-4.b and P-AGENT.b share a wave that the design forbids
- **Location:** W18 · C-AGT-4.b, P-AGENT.b.
- **Evidence:**
  - `design-agt.md:244` (C-AGT-4.b risks): "shares `handleAgentsCommand`, `AgentsCommand` and the status line with P-AGENT-ATTACH. **Never run both in one wave.**"
  - The scheduler never reads `risks:`, so the rule was dropped.
  - Concrete shared surfaces:
    - `candy-code/tests/Commands/KeyBindingDriftTest.php`. P-AGENT.b lists it in tests (`design-picks.md:468-469`); C-AGT-4.b names it only in docs/guards (`design-agt.md:239`).
    - `KeyBindingRegistry.php` rows (hub; the regions are disjoint).
    - `docs/AGENTS.md`, and the README Keys table.
    - Chat key handling: C-AGT-4.b adds an Alt+A key arm plus `primaryAgent` Chat state (ctor + `mutate()`); P-AGENT.b adds `draft()`/`withDraft()`.
- **Fix:** add `P-AGENT.b` to C-AGT-4.b's `deps:` (C-AGT-4.b moves to W19; re-run the scheduler).
- **Rule for the future:** every "never/not in the same wave" risk note becomes a `deps:` edge or a shared `[hot:…]` tag. Only three such notes exist:
  - this one;
  - `design-ses.md:162` (C-SES-2.b vs P-TABS.c, W4): harmless, because P-TABS.c only creates `SessionsPane.php`;
  - `design-ext.md:317` (C-EXT-3.d vs C-AGT-4, W21): harmless, because 4.c/4.d don't touch `AgentsHostCommand`/`handleAgentsCommand`.

### M2. Protocol lanes get contradictory orders about generated outputs
- **Location:** plan §1.8 (`plan_comparison.md:54`) vs §2 (`plan_comparison.md:86`). Affects every `[protocol]` step (33), e.g. W2 C-SEC-2.b/C-SES-3.c, W5 C-SES-2.c/P-AGENT.c, W12 C-WEB-3.f/C-WEB-7.c, W20 C-WEB-4.f/C-CTX-3.c, W26 C-SES-8.a.
- **Evidence:**
  - §2 says lanes "do NOT commit" `candy-code.v1.schema.json`, the SERVER.md generated tables or `generated.ts`.
  - §1.8 says every guard moves in the SAME commit and names `ProtocolSchema`.
  - The step entries make each lane's own guard depend on those outputs:
    - plan :278 (C-SEC-2.b), :366, :637, :741, :857, :1511 and :1559 list `gen-protocol-schema.php --check`, `ProtocolSchemaDriftTest` and `npm run gen:protocol -- --check` as the step's guards;
    - many designs list the outputs under files.modify "(regen)";
    - C-SES-3.c and C-SES-8.a modify `src-web/protocol/__tests__/generated.spec.ts`, which reads `generated.ts`.
  - A lane that obeys §2 leaves `ProtocolSchemaDriftTest` and `generated.spec.ts` red. The §1.6 reviewer then files that as a finding. The fix agent regenerates and commits, and two protocol lanes per wave then collide in the same generated files at cherry-pick. The weld procedure only resolves "soft-doc and en.php appends".
- **Fix:** add this sentence to §1.8 and §2: "A protocol lane runs `php scripts/gen-protocol-schema.php --write` (cwd `candy-code`) and `npm run gen:protocol` (cwd `candy-code-web`) locally to run its guards. It then restores the three generated files (`git checkout -- <the three paths>`) before committing. `ProtocolSchemaDriftTest`, `ServerProtocolDocumentationDriftTest` and `generated.spec.ts` are judged on the welded tree, and reviewers must not file their red in a lane worktree." Also add "resolve generated files by regenerating" to weld step 1.

---

## MINOR

### m1. W32 — hidden shared `ReadPathCensusTest.php`
- **Location:** W32 · C-TOOL-4.b, C-WEB-5.c.
- **Evidence:**
  - C-TOOL-4.b marks `[hot:candy-code/tests/Support/ReadPathCensusTest.php]` and moves the Bubblewrap rows to JailPaths (`design-tool.md:502`).
  - C-WEB-5.c adds a census row for the VAPID key read, but names the file only in docs/guards (`design-web.md:628`), so the hot contract is broken.
  - The roster is path-keyed (`ReadPathCensusTest.php:129ff`), so a textual merge is probably clean. It still breaks the "one lane per shared file" rule.
- **Fix:** add `modify candy-code/tests/Support/ReadPathCensusTest.php` to C-WEB-5.c's tests, plus the same `[hot:]` tag. The scheduler then moves one of the two lanes to W33.

### m2. The scheduler ignores `[docs:X]` tags, so the doc caps are broken
- **Location:**
  - W20 `docs/MEMORY.md`: **6 lanes** (C-CTX-2.f, 3.a, 3.b, 3.c, 3.d, 3.e; cap 2; the scheduler saw 2);
  - W21 `MEMORY.md`: 3 lanes (C-CTX-3.f, 4.b, 4.e);
  - W22 `SETTINGS.md`: 4 lanes (C-AGT-5.f, C-CTX-4.d, C-EXT-3.e, C-EXT-5.c; cap 3).
- **Evidence:** `schedule_plan.py` `tags()` drops `docs` tags. `soft_docs` counts only pages listed in the files fields, and C-CTX-2.f, 3.b, 3.d and 3.e declare MEMORY.md only as `[docs:MEMORY.md]`.
- **Fix:** in `main()`, add every `[docs:<page>]` to `s["files"]` as `candy-code/docs/<page>` before packing, then re-run. W20/W21 then spread their MEMORY.md lanes.

### m3. W20 — C-CTX-3.c relies on `context.changed`, which lands in W23
- **Location:** W20 · C-CTX-3.c; W23 · C-CTX-2.d.
- **Evidence:**
  - `design-prv-ctx.md:849`: "A successful write announces `context.changed` (from C-CTX-2.d, **if present**)". It is never present at W20.
  - C-CTX-2.d's announce list (`design-prv-ctx.md:210`: settle, compaction, command results) does not include `context.instructionWrite`, so the live panel refresh is lost on both orders.
  - C-CTX-3.c and C-CTX-3.a (same wave) also each need "the instruction paths of this session". 3.a adds an accessor to `ContextSources.php` for that; 3.c jails `context.instructionWrite` by its own reading of the same list.
- **Fix:**
  - Add to C-CTX-2.d's approach: "`ContextMethods::instructionWrite` also announces `context.changed {reason: instructions}`".
  - Strike "if present" from C-CTX-3.c, so the lane does not branch on it.
  - Optional: add `C-CTX-3.a` to C-CTX-3.c's deps and have 3.c reuse 3.a's accessor (3.c moves to W21).

### m4. Chat region claims leave out constructor/`mutate()` edits and key handling
- **Location:**
  - W20 C-CTX-3.b `[chat:submit]`: its "uiOnly confirm row" needs new Chat state plus Enter/Esc handling while it is pending, and no confirm mechanism exists today (`grep pendingConfirm` finds nothing);
  - W9 C-AGT-2.b adds a `reviewModeRestore` field (`design-agt.md:127`);
  - W18 C-AGT-4.b adds `primaryAgent` state.
- **Evidence:** `Chat::__construct` is about 1,000 lines (`src/Chat.php:718`), and `mutate()` re-lists every promoted property (`:8668`). Two lanes that each add a field append at the same two places. That causes an adjacent-line conflict that the region claims do not show. No such pair exists in the sampled waves, so this is latent.
- **Fix:** add a rule to the BRIEF and §2: "adding a Chat field means claiming `[chat:ctor]`". Add `ctor` (and, for C-CTX-3.b, `key dispatch`) to those three steps.

### m5. The plan's "Tests — create / modify" labels are wrong for 121 files
- **Location:** §4, rendered by `build_plan.py`.
- **Evidence:**
  - 116 test files labelled **create** already exist on master. Examples:
    - C-EXT-1.a `ClaudeSettingsHooksTest.php`;
    - C-SES-3.a `ShareStoreTest.php`, `SharePageTest.php`;
    - C-EXT-1.b `DocFigureProseDriftTest.php`, `PolicySnapshotTest.php`.
  - 5 labelled **modify** do not exist:
    - C-AGT-1.c `HookWebhooksBodyCapStackTest.php` (the design says create);
    - C-AGT-2.b `SessionHostModeCeilingTest.php`;
    - C-WEB-5.e `StaticFilesServiceWorkerTest.php`;
    - C-SEC-7.a and C-TUI-5.b paths missing the `candy-code/` prefix.
  - The cause is that `build_plan.py` applies a verb filter across bullet continuations. The design blocks themselves are right.
  - The consequence: §1.5's "list every NEW `*Test.php`" feeds the durations TSV, and a lane told to "create" an existing file may overwrite it.
- **Fix:** derive the create/modify labels from `files.create`, `git ls-files` and earlier-step creates instead of the prose verb, then regenerate §4. Until then, add a line to §1.5: "trust `git diff --diff-filter=A`, not the plan label, for new tests".

### m6. Three MAJOR fixes run after feature lanes
- **Location:** C-SEC-4.a (W6), C-WEB-1.b (W6), C-WEB-1.a (W7).
- **Evidence:**
  - `schedule_plan.py` puts MAJOR fixes and owner picks in the same class 0 and breaks ties by critical path. These fixes have cp=1, so long-chain P-* steps (P-MENU.c, P-AGENT.c, P-CTX.b, P-IMG.b…) take W3–W5 first.
  - The fixes then lose on the protocol cap and on SessionHost/StatusBar hot tags.
  - None of the three has a dep.
- **Owner intent:** "fixes and prerequisites first".
- **Fix:** split the classes: CRITICAL = -1, MAJOR fix = 0, owner picks = 1, dormant/minor fixes = 2, gaps = 3. Then re-run (expect all three in W2–W4).

### m7. Commands in §1, §2 and §7 that trip an orchestrator
- **Location and evidence:**
  - §2 (`:90`) names `tools/gen-command-docs.php`, `tools/gen-tool-docs.php`, `tools/gen-settings-doc.php` and `scripts/gen-protocol-schema.php`. None exists at the repo root; all live under `candy-code/` (only §7 spells the full path).
  - Every generator defaults to `--help` (`candy-code/scripts/gen-protocol-schema.php:31`, `candy-code/tools/gen-settings-doc.php:30`). The weld's "runs `scripts/gen-protocol-schema.php`" and §7.3's bare `gen-settings-doc.php` therefore print usage and exit 0, a silent no-op on a step meant to "fail loud".
  - `npm run gen:protocol`/`build` need cwd `candy-code-web`, and `candy-code-web/node_modules` is absent on the main checkout and in every fresh worktree. §1.2, the weld and §7 never say `npm ci`, and web lanes cannot run vitest.
  - §1.2 `refresh-deps.php --mode=linked` without `--libs` refreshes every lib in every worktree (`scripts/refresh-deps.php:64-66`): 10 lanes × about 60 composer updates.
- **Fix:**
  - write the full paths and `--write` everywhere: `php candy-code/scripts/gen-protocol-schema.php --write`, `php candy-code/tools/gen-settings-doc.php --write`, and so on;
  - add `(cd candy-code-web && npm ci)` to §1.2 (web lanes), the weld step 2 and §7.1;
  - use `refresh-deps.php --mode=linked --libs=candy-code[,<sugar-* libs the step edits>]`.

### m8. Ruling R-EXT-1 answers only half of C-EXT-2.b's question
- **Location:** W8 · C-EXT-2.b; §6.1 R-EXT-1.
- **Evidence:**
  - The design ruling asks two things (`design-ext.md:174`, `:577`):
    - the tier scope;
    - "are the built-ins' `allowed-tools: Read,Grep,Bash` / `effort: high` stripped?"
  - §6.1 answers only "as written, for all tiers".
  - The built-ins really ship `allowed-tools: "Read,Grep,Bash"` (e.g. `src/Skills/BuiltIn/php-best-practices/SKILL.md:5`, and `composer-wizard` adds `Composer`). Under the ruling, loading any built-in skill pre-approves bare Bash for the turn. That security choice is left to the lane agent.
- **Fix:** add the second answer to §6.1, either "strip or narrow the built-ins' `allowed-tools`/`effort` in C-EXT-2.b" or "keep them, accepted". Then C-EXT-2.b's brief names `src/Skills/BuiltIn/*/SKILL.md` in files.modify if stripped.

### m9. `dormantRoster.spec.ts` becomes a guard that later lanes never hear about
- **Location:** W15 · C-WEB-3.g, and every later `[protocol]` lane that adds a method (e.g. W17 C-SES-7.e `session.archive`/`unarchive`, W26 C-SES-8.a `usage.rollup`, LATE C-TOOL-6.i).
- **Evidence:**
  - `design-web.md:430`: "Methods other areas add … must already have callers or join the set."
  - The spec scans `generated.ts`, which lanes do not commit (M2). A method whose web caller ships in a later step therefore turns the spec red only on the welded tree.
  - §1.8's guard list does not include it.
- **Fix:**
  - add `dormantRoster.spec.ts` to §1.8 and to weld step 3's guard run;
  - add a line to the BRIEF: "a protocol step that adds a method with no browser caller in the same step adds it to the dormant set with a reason; the caller step removes it".

### m10. W26 — C-TOOL-2.g duplicates C-PRV-2.b and carries stale hedges
- **Location:** W26 · C-TOOL-2.g; W15 · C-PRV-2.b.
- **Evidence:**
  - C-PRV-2.b already sends image tool results on the Responses wire as `input_text` + `input_image` (`design-prv-ctx.md:501`).
  - C-TOOL-2.g re-specifies images plus documents (`design-tool.md:278-292`) and still says "confirm against design-prv.md" (the file is `design-prv-ctx.md`, and the path is `Concerns/OpenAiResponsesWire.php`, created by C-PRV-2.a) and "path unknown until C-PRV-2's design lands".
  - Its docs/guards names `docs/MEDIA.md`, which is not in its files list or a `[docs:]` tag.
- **Fix:** narrow C-TOOL-2.g to `input_file` (document) parts, reusing C-PRV-2.b's image arm. Replace the hedges with the concrete path. Add `candy-code/docs/MEDIA.md` to files.modify.

---

## NIT

- **n1.** §0 (`:14`, `:21`) says "72 items … 67 checklist candidates". `candidates.md` has 70 C-items, minus C-WEB-6 and C-SEC-9, which leaves 68, and §5 lists 73 rows. §0's design-file list also omits `design-web7.md` (C-WEB-7.*). Make it "73 … 68" and add the file.
- **n2.** Scheduler blind spots, all latent today:
  - `norm_path()` drops extensionless files, so `candy-code/bin/candy-code` (C-SES-6.a W7, C-AGT-6.c W19, C-CTX-4.g W22, C-SES-7.c W27) and `candy-code/Dockerfile` are never conflict-checked. They do not collide today.
  - `[headless]` is defined as a serialization set in `BRIEF-design.md:75`, but neither §2 nor the code enforces it.
  - Pseudo-paths such as `command.exec`, `command.list` and `session.send` are treated as files, which only causes false conflicts.

  Fix: whitelist `bin/candy-code` and `Dockerfile` in `norm_path()`, and drop dotted tokens that have no `/`.
- **n3.** `BRIEF-design.md:74` says "Exactly ONE protocol lane per wave", while §2 and `CAPPED` say 2. Designs were written to the one-lane assumption. Keep 2 only with M2's fix; otherwise set `CAPPED["protocol"]=1`.
- **n4.** §1.10: spawns in sibling libs that candy-code requires (sugar-mcp-client, sugar-pty) are also accounted in `DescriptorInheritanceGuardTest::ACCOUNTED_FOR_IN_LIBS` (`candy-code/tests/Support/DescriptorInheritanceGuardTest.php:490`), not only in the root roster (`tools/check-child-lifetimes.php:9-16,52`). Reword: "candy-code and its required libs → DescriptorInheritanceGuardTest; every other lib → `tools/check-child-lifetimes.php`".
- **n5.** Generator scripts appear as files to modify:
  - C-SEC-3.b lists `tools/gen-settings-doc.php` under Files — modify (plan `:391`);
  - plan `:741` cites `tools/gen-protocol-schema.php` (it lives in `scripts/`);
  - several designs put `php tools/gen-*.php --write` inside files.modify.

  This causes false serialization and invites a lane to edit a generator. Move these to docs/guards.
- **n6.** Some design hedges are now settled by §6.1:
  - C-SEC-7.e "either flip it … or keep it off" (`design-sec.md:583`);
  - C-SEC-3.b "the ruling picks which".

  The plan's "see §6" covers them, but lanes get only the design block plus §1–§2. Add §6 to the hand-off line in §4's preamble ("hand the lane … §1–§2 **and §6**").

---

## Verified OK

- **Paths exist:**
  - `candy-code/tests/Support/DescriptorInheritanceGuardTest.php`;
  - `candy-code/tools/gen-{command-docs,tool-docs,settings-doc}.php` (all three take `--write|--check`);
  - `candy-code/scripts/gen-protocol-schema.php`;
  - `candy-code/tests/Config/Support/refresh-suite-figure.php` and `suite-figure.json`;
  - `scripts/parallel-tests-durations.tsv`;
  - `tools/check-{one-type-per-file,child-lifetimes,path-repos}.php`;
  - the npm scripts `build`, `gen:protocol` and `test` in `candy-code-web/package.json`;
  - the guards `tests/LangParityTest.php`, `tests/Context/GlobDialectDifferentialTest.php`, `tests/Config/DocFigureProseDriftTest.php`, `tests/Tools/Catalog/ToolDocDriftTest.php` and `tests/Support/ReadPathCensusTest.php`;
  - `ProcessContainment::spawnSpec` (`src/Support/ProcessContainment.php:227`).
- **Schedule integrity:**
  - re-running `schedule_plan.py` gives byte-identical waves, with no warnings;
  - every declared dep lands in an earlier wave;
  - LATE starts only at W32;
  - no wave has more than 10 lanes;
  - at most one runtime, renderer, catalog and workflows lane per wave;
  - the `[commands]` surface is never used by more than 2 lanes in a wave, and every lane on it carries the tag.
- **Sampled waves, no hidden overlap found:**
  - **W1:** the Server.php mention in C-AGT-1.c is root-cause text only.
  - **W2:** EngineBackend `completeAsyncBlocking` vs `promptTools` are disjoint. Definitions (C-SEC-2.b) vs MethodSchemas (C-SES-3.c) are disjoint. The App.php mention in P-TABS.b is context only.
  - **W5:** the Chat and Runtime mentions in C-SES-1.a, C-CTX-1.b and P-CTX.b are references only.
  - **W12:** C-TOOL-3.b and C-TUI-7.a Chat methods are disjoint, and the gather arm lives in `HandlesRunControl::handleRunControlMessages`. C-MCP-2.a says "Chat.php untouched". The Bootstrap and MethodSchemas regions are disjoint.
  - **W15:** three disjoint Chat claims. Bootstrap `workspace` vs `instructionLoader`/`forcedInstructions` are disjoint. The NonInteractive and Subcommands hubs are single-lane. P-TABS.e's dep C-TOOL-3.c (W14) holds.
  - **W20:** disjoint at file level, apart from m2, m3 and m4.
  - **W26:** C-TOOL-2.c and C-TOOL-2.g do not share `AnthropicMessagesWire.php`.
  - **W32:** apart from m1, the hooks lanes (C-EXT-6.b `HookRegistry`, C-EXT-6.c `ScriptHook`/`HookConfig`/`HookManager`) are disjoint.
- **Dependencies:**
  - The sweep of all 328 steps found every non-master `Class::method` the steps use created either by the step itself or by an earlier wave. Examples:
    - `ShareStore::liveAll` W1→W2/W2;
    - `EffortGate` W2→W4;
    - `ContextPane` W3→W4;
    - `ToolTailSlots::set` W10→W12;
    - `UsageHostCommand::rollupFacts` W8→W26;
    - `Sanitize::stripInvisibleFormatting` (sugar-core) W16→W28;
    - `OpenAiResponsesWire` W13→W15/W26.
  - About 60 used symbols were grep-confirmed on master: `Bootstrap::mcpClient`, `McpPromptCommands::materialize`, `RuntimeNoticeSink::record`, `Bootstrap::{backgroundShells,sessionRetentionDays,permissionGate,hooks,forcedInstructions,instructionLoader,macAltNotice}`, `Chat::{isReadOnlySafeCommand,skillInvocationDraft,pumpRuntimeNotices,memoryOpenArm,handleAgentsCommand,submitToAgents,relayWidgetCmd}`, `SessionHost::{admit,admitPrompt,admitExpanded,parkExpansion}`, `SpendLedger::boundUsd`, `SessionRow::$costUnpriced`/`$bodySnippet`, `MentionIndex::forget`, `Definitions::PENDING_ASK`, and the `chat.session-cycle`/`shell.new-session` bindings.
  - The only protocol-name gap is m3 (`context.changed`).
- **No duplicates:**
  - no new file is created by two steps;
  - no pre-existing non-test file appears in any `files.create`;
  - the 11 new `builtin-commands/*.php` files clash with no existing number or name.
- **Rulings:** every non-`none` design ruling maps to a §6.1, §6.2 or §6.3 entry (C-TUI-6's five live in §6.3's generic list), except the half-answer in m8.
- **Owner intent:**
  - CRITICAL fixes are in W1/W2, and all MAJOR fixes are in W1–W9 (exception m6);
  - LATE holds exactly the six named items;
  - the four early slivers sit at C-TOOL-4.a W13, C-TUI-6.k W13, C-EXT-6.a W22 and C-TOOL-6.a W26, as §0 states;
  - every step carries file lists.
