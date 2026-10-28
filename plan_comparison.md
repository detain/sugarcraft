# candy-code comparison plan — closing the gaps (2026-10-26)

This plan turns the 2026-10-26 comparison refresh into waves of concurrent agent lanes. It replaces the previous plan_comparison.md, whose items have shipped; the problems found in them are carried here as fix steps.

**How to run it.**
- **Host:** myadmin1 (`ssh -i ~/.ssh/id_ed25519_new my@myadmin1`, checkout at `/home/sites/sugarcraft`), under **OpenCode**.
- **Start or resume:** `/cc-cmp-run`, or `/cc-cmp-run slots=<n>`.
- **Agents:** `.opencode/agents/cc-cmp-*.md`:
  - `cc-cmp-orchestrator` (primary);
  - `cc-cmp-lead`, the rolling run lead;
  - `cc-cmp-builder`, `cc-cmp-reviewer`, `cc-cmp-fixer`, `cc-cmp-welder`, `cc-cmp-wave-reviewer`, `cc-cmp-gate`.
- **Command:** `.opencode/commands/cc-cmp-run.md`.
- **Run state** lives in the repo: `prompt_kit/findings/comparison-1026/lanes/run-state.json`. Any host can resume from master.
- Every file the run needs is in this repo; nothing depends on the machine that wrote the plan.

**Where it comes from.** Everything lives in `prompt_kit/findings/comparison-1026/`:
- the comparison itself is `candy-code/COMPARISON.md` (refreshed against Claude Code 2.1.311, OpenCode 1.19.4 / 2.1.4, OpenClaw 2026.10.7);
- the research reports: `comp-claude-code.md`, `comp-opencode.md`, `comp-openclaw.md`, `cc-audit-k1.md`, `cc-audit-k2.md`, `cc-audit-k3.md`, `plan-review-p1.md`, `plan-review-p2.md`;
- the owner's checklist source: `candidates.md`;
- the per-area designs that hold every step's full brief: `design-prv-ctx.md`, `design-agt.md`, `design-ext.md`, `design-mcp.md`, `design-tool.md`, `design-tui.md`, `design-picks.md`, `design-menu.md`, `design-web.md`, `design-web7.md`, `design-ses.md`, `design-sec.md`;
- the scheduler that produced §3–§4: `schedule_plan.py` + `build_plan.py`. Re-run them after editing a design file instead of hand-editing the wave tables.

## 0. Scope

**In scope: 73 items the owner picked.**
- The owner's own five:
  - **P-CTX** context breakdown: a sidebar box that expands into where the context goes (memory files, system tools, messages, MCP tools, skills, system prompt, MCP server instructions, custom agents, autocompact buffer);
  - **P-TABS** OpenCode-style session tabs, with a "+ New session" tab and a tab per running session;
  - **P-AGENT** click a running sub-agent to open its session window, watch it, scroll its history and type into it;
  - **P-IMG** the generate-image popup sized to its content (its menu entry moved into P-MENU);
  - **P-MENU** the menu bar redone from 11 menus to 5 (Session · Agent · Tools · View · App), with every pane listed under View ▸ Panes ▸.
- 68 checklist candidates (`candidates.md`): every candidate except C-WEB-6 and C-SEC-9. That includes all CRITICAL/MAJOR fixes, the nine minor/nit bundles, the dormant-code wiring, and the competitor-parity features.

**Ordering rule (owner):** "save the large items for last and get everything else done first". C-EXT-6, C-MCP-5, C-TOOL-4, C-TOOL-6, C-TUI-6 and C-WEB-5 are phase **LATE** and run only after every normal step has landed. Four small, independent slivers of them run early because they are S effort and need no ruling: C-EXT-6.a (hook `systemMessage` notice), C-TOOL-4.a (`doctor` sandbox/AppArmor check), C-TOOL-6.a (browser/computer-use MCP preset docs) and C-TUI-6.k (web dictation).

**Dropped by ruling:** C-TUI-1.b (the R4 vim VisualLine flip; declined) and P-IMG.a (superseded by P-MENU.b + P-MENU.d).

## 1. General instructions (binding on every lane)

1. **Concurrency: rolling, up to 10 agents busy at all times (owner rule).**
   - A wave lists up to 10 lanes that are mutually safe. The waves are a **priority order, not a barrier** (§2 Rolling execution).
   - When some lanes are in review or fix, the free agent slots start eligible steps from later waves. A step is eligible when all its deps are welded and it collides with no lane still in flight.
   - `schedule_plan.py --next` makes that call; agents never judge collisions by hand.
   - The default is 10 slots; `/cc-cmp-run slots=<n>` lowers it.
2. **Isolation.** Each lane works in its own git worktree cut from master:
   - `git -C "$REPO" worktree add "$REPO/../cc-cmp-<step>" -b cc/<step> master`, where `REPO=$(git rev-parse --show-toplevel)` (`/home/sites/sugarcraft` on myadmin1);
   - then run the worktree's OWN `php scripts/refresh-deps.php --mode=linked --libs=candy-code[,<sugar-* libs the step edits>]` (never all libs: 10 lanes × every lib is hours of composer), and verify the siblings are linked before briefing work;
   - web lanes also run `(cd candy-code-web && npm ci)` in the worktree (node_modules is not checked in).
3. **Commits.**
   - One step = one cohesive commit on the lane branch.
   - The weld agent cherry-picks into master in wave order, then removes the worktree and branch after proving absorption (`git cherry` plus a content check).
   - Commits go straight to master — no PRs — and **nothing is pushed without the owner's say-so**.
4. **Author law.** Author and committer are `Joe Huss <detain@interserver.net>`. Build the address from split shell vars (`A='detain';B='interserver.net'`) and set `GIT_AUTHOR_*` / `GIT_COMMITTER_*`. Verify `%ae` and `%ce`. **No Co-Authored-By trailers.** Never run `caliber refresh`; if a Caliber hook stages agent-config files, unstage them.
5. **Tests.**
   - Run targeted tests for the change only. **Never run the full candy-code suite mid-campaign**; the closeout gate (§7) runs it once.
   - Never edit `candy-code/tests/Config/Support/suite-figure.json` or the root `scripts/parallel-tests-durations.tsv`. Instead, list every NEW `*Test.php` in the lane report, and the gate adds it to the durations TSV (an unlisted test file runs in zero shards).
   - A fix lands with a regression test that fails before the fix.
6. **Review/fix LOOPS — loops, not a fixed number of rounds (owner rule).** Three loops, each repeating until its exit condition holds:
   - **Step loop (every lane):** a read-only review agent checks the lane's diff against its brief. Findings go to a fix agent, then a NEW review agent (never the same reviewer twice in a row). Repeat until a fresh review returns **0 critical and 0 major**, with every MINOR that is local to the step fixed too. Only then is the lane welded.
   - **Wave loop (once every step of a wave is welded):** a read-only review agent checks the merged wave as a whole: cross-lane interactions, merged-tree test and guard results, generated files, and each lane's §4 claims against what landed. Findings go to a fix agent (on master, one commit per fix), then a NEW wave reviewer. Repeat until clean, then mark the wave `✅` in §3.
     - Under rolling execution, later steps keep running meanwhile.
     - A wave-loop fix never touches a file claimed by a lane still in flight; it waits for that lane to weld.
   - **Gate loop (§7):** full battery → fix the reds → re-run the full battery, until it is green with no drift.
   - Each loop records every round's verdict under `prompt_kit/findings/comparison-1026/lanes/<step>/` (step loop) or `lanes/W<n>/` (wave loop).
   - If an agent returns blank, resume the SAME agent with "continue" before re-cutting.
   - If a loop goes past 5 rounds on the same finding, stop and ask the owner. Repeated non-convergence means the design or a ruling is wrong.
7. **Context hygiene (owner rule).** Big agents keep their context down:
   - finish the part in hand, write it to disk;
   - hand the rest to ONE sub-agent at a time, each returning ≤10 lines.
   - The orchestrator delegates verification and research rather than reading large files itself.
8. **In-step guard law.** Every guard a change touches moves in the SAME commit:
   - doc drift guards: `ReadmeRosterDriftTest`, `EnvRosterDriftTest`, `TrustKeyDocumentationDriftTest`, `ConfigWriteProducerDocumentationDriftTest`, `KeyBindingDriftTest`, `ToolDoc`, `ProtocolSchema`, `LangParityTest`, …;
   - census rosters: `ReadPathCensusTest`, the contained-path inventory, the built-in tool corpus, …;
   - word-number figures in docs.
   - A tool, slash command, env var, key binding or setting added without its doc row goes red.
   - **Protocol lanes:** run `php scripts/gen-protocol-schema.php --write` (cwd `candy-code`) and `npm run gen:protocol` (cwd `candy-code-web`) locally to run the guards, then restore the three generated files (`git checkout -- candy-code/docs/protocol/candy-code.v1.schema.json candy-code/docs/SERVER.md candy-code-web/src-web/protocol/generated.ts`) before committing. `ProtocolSchemaDriftTest`, `ServerProtocolDocumentationDriftTest`, `generated.spec.ts` and `dormantRoster.spec.ts` are judged on the welded tree; reviewers do not file their red in a lane worktree.
   - **Dormant web methods:** a protocol step that adds a method with no browser caller in the same step adds it to the dormant set in `candy-code-web/src-web/__tests__/dormantRoster.spec.ts` (once C-WEB-3.g has created it) with a reason; the step that adds the caller removes it.
9. **Test-literal hazards.** No glob-shaped string literals in fixtures; no `**Refused:**`-shaped literals; whole-wire scans use per-process `uniqid` sentinels.
10. **Fork/child discipline.**
    - Every new `proc_open`/fork rides `ProcessContainment::spawnSpec`.
    - candy-code and the libs it requires (e.g. sugar-mcp-client, sugar-pty) are accounted in `candy-code/tests/Support/DescriptorInheritanceGuardTest.php` (`ACCOUNTED_FOR_IN_LIBS` for the libs); every other lib goes in the root `tools/check-child-lifetimes.php` roster (it skips candy-code).
    - Census pins poll-then-reap.
11. **Project laws.**
    - **E646:** never a blanket total-request timeout on LLM calls; connect timeouts are fine.
    - Dormant code is **wired, never deleted**.
    - One type per PSR-4 file (`tools/check-one-type-per-file.php`).
    - User strings go through `Lang::t()` with keys in `candy-code/lang/en.php`.
    - Fix root causes, never disable-and-TODO.
12. **Verification and evidence.**
    - Disk-verify every agent claim before review or weld: branch ref, `git cat-file`, worktree list.
    - Lane evidence and reviews go under `prompt_kit/findings/comparison-1026/lanes/<step>/`, never `/tmp`.
    - Lane returns are ≤30 lines, verdict first.
13. **Progress marks.** As each step is reviewed, fixed and committed, mark it in §4 as `✅ <short-hash> <step-id>`, continuously.

## 2. Concurrency rules (how §3–§4 were packed)

The scheduler enforces these; the weld relies on them.

| Surface | Rule within one wave |
|---|---|
| Any shared file | At most ONE lane — except the rows below. |
| `candy-code/src/Chat.php` | Up to 3 lanes whose `[chat:<region>]` claims (named methods/regions) are disjoint; a claim with no region owns the file. Adding a Chat field means claiming `ctor` (the ~1,000-line constructor and `mutate()` re-list every property), and new key handling means claiming `key dispatch`. |
| `candy-code/src/Cli/Bootstrap.php` | Up to 2 lanes with disjoint `[bootstrap:<method>]` claims. |
| Hub files: `Host/SessionHost.php`, `Cli/Subcommands.php`, `App/App.php`, `Backend/EngineBackend.php`, `Cli/NonInteractive.php`, `Cli/ArgvParser.php`, `Cli/ParsedArgs.php`, `Config/LayeredSettings.php`, `Commands/KeyBindingRegistry.php`, `Protocol/Schema/MethodSchemas.php`, `Protocol/Schema/Definitions.php`, `Protocol/ServerContext.php`, `MCP/McpClient.php` | Up to 2 lanes whose declared regions (the methods named after the path in the step's files list) are disjoint. A lane stays inside its declared region. |
| `candy-code/src/Runtime.php`, `candy-code/src/Renderer.php`, `candy-code/src/Tui/Renderer.php` | ONE lane. |
| Protocol chain (`src/Protocol/**`, method/event schemas) | Up to 2 lanes. Lanes regenerate locally for their guards but do NOT commit the generated outputs (`candy-code/docs/protocol/candy-code.v1.schema.json`, the `candy-code/docs/SERVER.md` generated tables, `candy-code-web/src-web/protocol/generated.ts`) — see §1.8. The weld regenerates them once after merging the wave. |
| `[commands]` registry/dispatch surface (`CommandRegistry.php`, `Specs/BuiltInCommands.php`, the READ_ONLY_COMMANDS roster, existing `builtin-commands/*.php` rows) | Up to 2 lanes. A NEW `builtin-commands/*.php` file is per-command and free. |
| `[catalog]` (`src/Tools/Catalog/ToolCatalog.php` + built-in tool positions), `.github/workflows/*` | ONE lane each. |
| `candy-code/lang/en.php` | Soft: any number of lanes, each appending under its own key prefix. |
| `candy-code/README.md` (4), `docs/COMMANDS.md`, `docs/SETTINGS.md`, `docs/SERVER.md` (3 each), other `docs/*.md` (2) | Soft cap per page. Lanes edit different sections. Generated regions (`<!-- commands:… -->`, `<!-- settings:… -->`, `<!-- tools:… -->`, `<!-- protocol:… -->`) are regenerated by the weld (§2 weld step 2). Generator scripts are run, never edited by a lane. |
| `candy-code-web/dist/`, `suite-figure.json`, `parallel-tests-durations.tsv` | **Weld/gate-only.** A lane never commits them. The weld rebuilds `candy-code-web/dist/` once per wave (`npm run build`) because its hashed bundle names change on every build. |
| Dependencies | A step runs only after every dep landed in an EARLIER wave. |
| Phase | LATE steps start only after every normal step is welded. |

**Rolling execution.** The run lead (`cc-cmp-lead`) works in ticks. Each tick:
1. **Weld:** every lane whose step loop came back clean is welded into master, in wave-then-listed order.
2. **Wave loops:** any wave whose steps are now all welded starts its wave loop.
3. **Fill:** the lead runs `python3 -I prompt_kit/findings/comparison-1026/schedule_plan.py prompt_kit/findings/comparison-1026 --next --state prompt_kit/findings/comparison-1026/lanes/run-state.json --slots <N>`. It treats every in-flight lane (and every blocked one) as holding its §2 claims, and returns the highest-priority steps that fit: earliest wave first, then MAJOR fixes, owner picks, other fixes, features. Each returned step gets a fresh worktree.
4. **Launch:** builders for new lanes, fresh reviewers, fixers, and wave reviewers/fixers go out in parallel, up to N agents.

So while 2 lanes are in review, the other 8 slots build steps from the next waves. LATE steps become eligible only when every normal step is welded.

**Weld procedure, per batch of clean lanes:**
1. Cherry-pick the lanes in wave-then-listed order, resolving soft-doc and en.php appends; resolve any generated file by regenerating, never by hand.
2. Regenerate (cwd `candy-code` unless noted): `php scripts/gen-protocol-schema.php --write` (if a protocol lane ran), `php tools/gen-command-docs.php --write`, `php tools/gen-tool-docs.php --write`, `php tools/gen-settings-doc.php --write`; then `(cd ../candy-code-web && npm ci && npm run gen:protocol && npm run build)`.
3. Run each lane's targeted tests on the merged tree, plus the drift guards touched, `ProtocolSchemaDriftTest`, `ServerProtocolDocumentationDriftTest`, and vitest `generated.spec.ts` + `dormantRoster.spec.ts` (`--no-file-parallelism`).
4. Commit the regeneration as `weld: W<n> regenerate`.
5. Put `✅ <short-hash>` before each welded step in §4. Commit the regeneration, the marks, the lanes' review files and `lanes/run-state.json` as `weld: <step ids>`.
6. Once a wave's steps are all welded, run its **wave loop** (§1.6). When it is clean, mark `✅ W<n>` in §3.

## 3. Wave overview

328 steps in 42 waves: W1–W32 normal, W33–W42 LATE (the large items, run last by owner rule). Lanes per wave ≤ 10, and every lane in a wave is independent of every other lane in it. Waves are a **priority order, not a barrier**: under rolling execution (§2) free agent slots start eligible steps from later waves while earlier lanes are still in review or fix. Prefix a wave with ✅ once its wave loop is clean.

| Wave | Lanes | Steps |
|---|---|---|
| ✅ W1 (closed 2026-10-27) | 10 | C-SEC-1.a · C-SEC-2.a · C-TOOL-1.a · C-AGT-1.a · C-TOOL-1.c · C-EXT-1.a · C-MCP-1.a · C-SES-3.a · C-AGT-1.c · C-AGT-2.a |
| ✅ W2 (closed 2026-10-27) | 10 | C-SEC-2.b · C-SEC-3.a · C-SES-3.b · C-PRV-1.a · C-SES-2.a · C-SES-3.c · C-SES-4.a · C-SES-5.a · C-AGT-2.c · C-EXT-1.b |
| ✅ W3 (closed 2026-10-27) | 10 | C-SEC-3.b · C-MCP-1.b · C-SES-5.b · C-CTX-1.a · C-PRV-1.b · C-PRV-1.c · C-SEC-4.b · C-SES-2.b · C-WEB-1.d · C-CTX-1.b |
| ✅ W4 (closed 2026-10-27) | 10 | C-SES-2.c · C-SEC-4.a · C-SES-1.a · C-SES-5.d · C-TOOL-1.b · C-WEB-1.c · P-CTX.a · P-TABS.b · P-TABS.d · P-IMG.b |
| ✅ W5 (closed 2026-10-27) | 10 | C-WEB-1.a · C-WEB-1.b · P-CTX.c · P-CTX.d · P-TABS.a · P-MENU.a · P-CTX.b · P-IMG.c · P-TABS.h · C-TOOL-7.a |
| ✅ W6 (closed 2026-10-27) | 10 | P-MENU.c · P-AGENT.a · P-TABS.c · P-MENU.b · P-CTX.f · C-TOOL-7.c · C-AGT-1.b · C-AGT-3.a · C-CTX-2.b · C-EXT-2.a |
| ✅ W7 (closed 2026-10-27) | 10 | P-CTX.e · P-AGENT.c · P-CTX.g · C-AGT-7.a · C-AGT-2.b · C-AGT-3.b · C-EXT-7.b · C-SEC-5.a · C-SES-6.a · C-SES-9.a |
| ✅ W8 (closed 2026-10-28) | 10 | P-MENU.d · P-AGENT.d · C-CTX-2.a · C-EXT-2.b · C-SES-4.b · C-SES-9.c · C-SES-9.d · C-WEB-3.a · C-WEB-3.b · C-WEB-3.c |
| ✅ W9 (closed 2026-10-28) | 10 | C-SES-5.c · P-MENU.e · C-CTX-2.c · C-WEB-3.d · C-AGT-7.c · C-CTX-6.c · C-CTX-6.d · C-EXT-7.a · C-MCP-6.a · C-PRV-5.a |
| ✅ W10 (closed 2026-10-28) | 10 | C-CTX-6.a · C-EXT-2.c · C-SEC-5.c · C-TOOL-3.a · C-AGT-7.b · C-CTX-1.c · C-CTX-2.e · C-MCP-4.a · C-PRV-5.b · C-PRV-5.c |
| ✅ W11 (closed 2026-10-28) | 10 | C-MCP-2.a · C-CTX-6.b · C-EXT-2.d · C-PRV-5.d · C-SEC-5.b · C-SES-1.b · C-SES-9.b · C-TOOL-3.b · C-TOOL-7.b · C-TOOL-7.d |
| ✅ W12 (closed 2026-10-28) | 10 | C-MCP-2.b · C-SEC-5.d · C-TOOL-7.e · C-TUI-1.a · C-TUI-7.a · C-TUI-7.b · C-WEB-3.f · C-WEB-7.a · C-WEB-7.b · C-TOOL-5.b |
| ✅ W13 (closed 2026-10-28) | 10 | C-EXT-2.e · C-WEB-7.c · C-WEB-7.d · C-TOOL-3.c · C-MCP-3.a · C-TOOL-5.c · C-AGT-5.a · C-SES-3.d · C-TUI-6.k · C-AGT-6.b |
| ✅ W14 (closed 2026-10-28) | 10 | P-TABS.e · C-WEB-3.e · C-TOOL-4.a · C-AGT-4.a · C-AGT-5.b · C-EXT-4.a · C-PRV-2.a · C-SES-7.a · C-TOOL-3.d · C-TUI-2.a |
| ✅ W15 (closed 2026-10-28) | 10 | P-TABS.f · C-WEB-3.g · C-TUI-2.b · C-WEB-4.a · C-AGT-5.c · C-AGT-5.e · C-AGT-5.g · C-AGT-6.d · C-CTX-4.c · C-EXT-3.c |
| ✅ W16 (closed 2026-10-29, wave loop r1 CLEAN (0C/0M/3nits)) | 10 | P-TABS.g · C-CTX-2.d · C-EXT-4.b · C-EXT-5.a · C-MCP-3.b · C-MCP-4.b · C-PRV-2.b · C-SEC-6.a · C-SEC-7.d · C-SEC-8.c |
| W17 | 10 | P-AGENT.b · C-CTX-5.c · C-PRV-2.c · C-SES-4.c · C-SES-6.b · C-SES-6.c · C-SES-8.c · C-SES-8.f · C-TOOL-2.d · C-TOOL-3.e |
| W18 | 10 | C-AGT-4.b · C-PRV-3.a · C-SEC-8.e · C-TOOL-5.a · C-TUI-2.d · C-TUI-3.c · C-TUI-3.f · C-TUI-5.d · C-WEB-4.b · C-WEB-4.c |
| W19 | 10 | C-PRV-4.a · C-SES-4.d · C-TOOL-2.b · C-TUI-2.c · C-WEB-4.d · C-WEB-4.e · C-AGT-5.h · C-AGT-6.a · C-CTX-2.f · C-CTX-3.b |
| W20 | 10 | C-SES-7.e · C-AGT-5.d · C-AGT-6.c · C-CTX-3.a · C-CTX-3.d · C-CTX-4.a · C-CTX-5.a · C-EXT-3.b · C-EXT-3.d · C-EXT-4.c |
| W21 | 10 | C-WEB-2.b · C-WEB-4.f · C-CTX-3.c · C-CTX-3.e · C-CTX-4.f · C-CTX-4.g · C-EXT-3.e · C-EXT-4.d · C-EXT-5.b · C-EXT-5.c |
| W22 | 10 | C-AGT-4.c · C-AGT-4.d · C-CTX-3.f · C-CTX-4.b · C-EXT-3.a · C-EXT-6.a · C-MCP-3.c · C-MCP-3.d · C-PRV-3.b · C-PRV-3.c |
| W23 | 10 | C-AGT-4.e · C-AGT-5.f · C-CTX-4.d · C-CTX-4.e · C-MCP-2.c · C-MCP-4.d · C-PRV-4.b · C-SEC-6.c · C-SEC-6.e · C-SEC-7.c |
| W24 | 10 | C-AGT-6.e · C-CTX-5.b · C-PRV-3.d · C-SEC-6.b · C-SEC-6.d · C-SEC-8.a · C-SEC-8.f · C-SES-6.d · C-SES-7.b · C-SES-7.d |
| W25 | 10 | C-CTX-5.d · C-SEC-7.a · C-SEC-7.b · C-SES-7.f · C-SES-7.g · C-SES-8.a · C-SES-8.d · C-TOOL-2.a · C-TOOL-2.c · C-TOOL-2.e |
| W26 | 10 | C-CTX-6.e · C-SEC-7.e · C-SES-6.e · C-SES-8.e · C-TOOL-2.f · C-TOOL-2.g · C-TOOL-6.a · C-TUI-2.f · C-TUI-2.g · C-TUI-3.a |
| W27 | 10 | C-MCP-4.c · C-SEC-8.b · C-SES-7.c · C-SES-8.g · C-TUI-3.b · C-TUI-3.e · C-TUI-4.b · C-TUI-4.c · C-WEB-2.c · C-WEB-2.f |
| W28 | 6 | C-SEC-8.d · C-TOOL-5.d · C-TUI-2.e · C-TUI-3.g · C-WEB-2.d · C-WEB-2.g |
| W29 | 3 | C-SES-8.b · C-TUI-3.d · C-TUI-4.a |
| W30 | 3 | C-TUI-5.a · C-TUI-5.e · C-WEB-2.a |
| W31 | 2 | C-TUI-5.b · C-WEB-2.e |
| W32 | 1 | C-TUI-5.c |
| W33 (LATE) | 8 | C-TOOL-4.b · C-TUI-6.b · C-TUI-6.i · C-EXT-6.c · C-MCP-5.a · C-WEB-5.a · C-EXT-6.b · C-WEB-5.g |
| W34 (LATE) | 6 | C-TOOL-4.c · C-TOOL-4.f · C-TOOL-4.h · C-TOOL-6.f · C-TUI-6.j · C-WEB-5.b |
| W35 (LATE) | 7 | C-TOOL-4.d · C-TUI-6.f · C-WEB-5.c · C-TOOL-6.c · C-TUI-6.c · C-MCP-5.b · C-TOOL-6.g |
| W36 (LATE) | 5 | C-TOOL-4.e · C-TOOL-4.g · C-TOOL-6.j · C-TUI-6.g · C-WEB-5.d |
| W37 (LATE) | 3 | C-TOOL-6.e · C-EXT-6.d · C-WEB-5.e |
| W38 (LATE) | 2 | C-TUI-6.a · C-TOOL-6.h |
| W39 (LATE) | 4 | C-TUI-6.l · C-TOOL-6.k · C-TOOL-6.i · C-TUI-6.d |
| W40 (LATE) | 4 | C-TUI-6.e · C-TUI-6.m · C-TUI-6.n · C-TOOL-6.d |
| W41 (LATE) | 3 | C-TOOL-6.b · C-TOOL-6.l · C-TUI-6.h |
| W42 (LATE) | 1 | C-WEB-5.f |

## 4. Waves and steps

Each lane entry is the brief's summary. **The full brief (approach, root cause, test detail, risks) is the step's block in its design file** — hand the lane agent that block plus §1, §2 and §6 of this plan (§6 settles the design's open hedges). Mark progress as each step is reviewed, fixed and committed: put ✅ and the short commit hash before the step id (e.g. `✅ a1b2c3d4 C-SEC-1.a`).

### W1

- Chat.php region claims: C-AGT-2.a (reviewTurn)
- Bootstrap.php method claims: C-AGT-1.a (workspace)

#### ✅ eeb908c5d C-SEC-1.a — Bundle install never follows symlinks
- **Item:** C-SEC-1 (sources: PLG-1-P1, PLG-1-P3) · fix · CRITICAL · effort S
- **What:** Root cause: `Subcommands::removeTree()` (`candy-code/src/Cli/Subcommands.php:1920-1935`) recurses on `\is_dir($child)` at :1931, and `is_dir()` follows symlinks. A clone holding `x -> ../../..` therefore empties `$HOME` on every …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Cli/Subcommands.php`, `candy-code/lang/en.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Bundles/BundleInstallTest.php`
- **Docs/guards:** none. The refusal names the link, and no doc page describes the cleanup. Child lifetime: no new spawn.
- **Serialized:** [headless] [lang:cli.bundles.install.]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-sec.md` → `### C-SEC-1.a`

#### ✅ cc0c9644a C-SEC-2.a — Web Edit card shows every edit and the raw arguments
- **Item:** C-SEC-2 (sources: WEB-5-P1, WEB-5-P4) · fix · CRITICAL · effort S
- **What:** Root cause 1: `diffPairsFor()` (`candy-code-web/src-web/lib/lineDiff.ts:38-41`) returns only the top-level `old_string`/`new_string` pair whenever it is present. `Edit::editList()` …
- **Files — create:** `candy-code-web/src-web/components/__tests__/permissionCardDiff.spec.ts`
- **Files — modify:** `candy-code-web/src-web/lib/lineDiff.ts`, `candy-code-web/src-web/components/PermissionCard.vue`
- **Tests — create:** none · **modify:** `candy-code-web/src-web/lib/__tests__/lineDiff.spec.ts`, `candy-code-web/src-web/components/__tests__/permissionCardExtras.spec.ts`
- **Docs/guards:** vitest (`--no-file-parallelism`). Rebuild web dist under the orchestrator's dist rule (see Notes). No PHP guard.
- **Serialized:** [hot:candy-code-web/src-web/components/PermissionCard.vue] [hot:candy-code-web/dist]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-sec.md` → `### C-SEC-2.a`

#### ✅ f1faf48f8 C-TOOL-1.a — Background-shell two-part cursor
- **Item:** C-TOOL-1 (sources: SHL-1-P1) · fix · MAJOR · effort M
- **What:** Root cause `candy-code/src/Background/BackgroundShellRegistry.php:503-546` (`read()`): one global cursor over stdout‖stderr, split as `stdoutOffset=min(cursor,stdoutSize)` / `stderrOffset=cursor-stdoutSize`. Valid only while …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Background/BackgroundShellRegistry.php`, `candy-code/src/Tools/BuiltIn/ShellOutput.php`, `candy-code/src/Tools/BuiltIn/Bash.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Sessions/BackgroundShellRegistryTest.php`, `candy-code/tests/Tools/ShellOutputTest.php`, `candy-code/tests/Tools/BashBackgroundTest.php`
- **Docs/guards:** none. `tools/gen-tool-docs.php` renders names and glosses only, and the gloss is unchanged.
- **Serialized:** [hot:candy-code/src/Background/BackgroundShellRegistry.php]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-1.a`

#### ✅ 5d1647f4b C-AGT-1.a — Bind persisted schedules to a project root
- **Item:** C-AGT-1 (sources: AUT-1-P1, p1-miss: schedules UX) · fix · MAJOR · effort M
- **What:** Root cause: a row is `name/every/prompt/provider/permissionMode/nextDue/enabled` (`src/Background/Schedule.php:136-195`), and `ScheduleSpawner::closure` spawns at the HOST's root (`src/Background/ScheduleSpawner.php:58`). One …
- **Files — create:** `candy-code/docs/SCHEDULES.md`
- **Files — modify:** `candy-code/src/Background/Schedule.php`, `candy-code/src/Background/ScheduleRegistry.php`, `candy-code/src/Background/ScheduleSpawner.php`, `candy-code/src/Host/Commands/SchedulesCommand.php`, `candy-code/src/Cli/Bootstrap.php`, `candy-code/lang/en.php`, `candy-code/docs/README.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Sessions/ScheduleTest.php`, `candy-code/tests/Sessions/ScheduleRegistryTest.php`, `candy-code/tests/Sessions/ScheduleCrossHostFireTest.php`, `candy-code/tests/Host/BackgroundEventsSchedulesTest.php`, `candy-code/tests/Chat/ScheduleLoopFireTest.php`, `candy-code/tests/Host/Commands/LoopSchedulesCommandTest.php`
- **Docs/guards:** new `docs/SCHEDULES.md` plus its `docs/README.md` index row. `ReadPathCensusTest` / `ProjectTierRefusalInventoryTest` rows for `ScheduleRegistry` are unchanged (same file, same read). The …
- **Serialized:** [bootstrap:workspace] [lang: `host.schedules.*`] [docs:SCHEDULES.md] [docs:README.md (docs/ index)]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-agt.md` → `### C-AGT-1.a`

#### ✅ 8aa2acada C-TOOL-1.c — Format hook skips failed edits, never blesses stale
- **Item:** C-TOOL-1 (sources: EDT-1-P1) · fix · MAJOR · effort S
- **What:** Root cause `candy-code/src/Hooks/BuiltIn/PostEditFormatHook.php` execute() (:97-120). It formats failed edits too ("exactly as the lint hook reasons") and then calls `$this->ledger?->record($abs, null)`, which stores the CURRENT …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Hooks/HookContext.php`, `candy-code/src/Runtime.php`, `candy-code/src/Hooks/BuiltIn/PostEditFormatHook.php`, `candy-code/docs/HOOKS.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Hooks/BuiltIn/PostEditFormatHookTest.php`, `candy-code/tests/Hooks/HookContextTest.php`, `candy-code/tests/Hooks/PostToolUseAttributionTest.php`
- **Docs/guards:** `docs/HOOKS.md` sentence in-step; `HookEnvironmentDocumentationDriftTest` is unaffected because the payload is unchanged
- **Serialized:** [runtime:settle] [docs:HOOKS.md]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-1.c`

#### ✅ 7949cc23d C-EXT-1.a — CC hook entries: accept `statusMessage`/`async`, Claude exit-code dialect
- **Item:** C-EXT-1 (sources: INT-4-P1, GAP-HK-07, k2-sum HK-07 ✅→🟡) · fix · MAJOR · effort M
- **What:** **Root cause.** `candy-code/src/Hooks/ClaudeSettingsHooks.php:98` has `ENTRY_KEYS = ['type','command','timeout']`, and `:247-255` refuses the whole launch on any other key. Claude Code writes `statusMessage` and `async` …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Hooks/ClaudeSettingsHooks.php`, `candy-code/src/Hooks/ScriptHook.php`, `candy-code/lang/en.php`, `candy-code/docs/HOOKS.md`
- **Tests — create:** `candy-code/tests/Hooks/ScriptHookClaudeDialectTest.php` · **modify:** `candy-code/tests/Hooks/ClaudeSettingsHooksTest.php`
- **Docs/guards:** `docs/HOOKS.md`. Check whether `DocFigureProseDriftTest`'s HOOKS.md entry-key pin covers the CC bullets, and move it if so. No child-lifetime change.
- **Serialized:** [docs:HOOKS.md] [lang:hooks.notice.]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-ext.md` → `### C-EXT-1.a`

#### ✅ 2e1ef6430 C-MCP-1.a — User-tier stdio servers spawn in $HOME, project tier at its root
- **Item:** C-MCP-1 (sources: MCP-3-P1) · fix · MAJOR · effort M
- **What:** Root cause: the library spawns with `proc_open(..., null, $env)`, so the child inherits candy-code's cwd (`sugar-mcp-client/src/StdioMcpServer.php:373-383`, arg 4 `null`). The adapter passes only argv and env …
- **Files — create:** `sugar-mcp-client/tests/StdioMcpServerCwdTest.php`, `sugar-mcp-client/tests/Fixtures/cwd_server.php`, `candy-code/tests/MCP/StdioMcpUserTierCwdTest.php`
- **Files — modify:** `sugar-mcp-client/src/StdioMcpServer.php`, `sugar-mcp-client/README.md`, `candy-code/src/MCP/StdioMcpServer.php`, `candy-code/src/MCP/McpClient.php`, `candy-code/docs/MCP.md`
- **Tests — create:** `sugar-mcp-client/tests/StdioMcpServerCwdTest.php`, `candy-code/tests/MCP/StdioMcpUserTierCwdTest.php` · **modify:** none
- **Docs/guards:** `candy-code/tests/Config/DocFigureProseDriftTest.php::testMcpServerTypesKeysAndEnvInterpolationMatchTheFactory` reads the stdio arm's `$config[...]` reads against the table's Keys cell, so the doc …
- **Serialized:** [docs:MCP.md §Two tiers + §`.mcp.json`] [hot:sugar-mcp-client/src/StdioMcpServer.php] [hot:candy-code/src/MCP/McpClient.php]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-mcp.md` → `### C-MCP-1.a`

#### ✅ 0e7412c16 C-SES-3.a — Shares die with their session; token redacted from the serve log
- **Item:** C-SES-3 (sources: SES-3-P1, SES-3-P2) · fix · MAJOR · effort M
- **What:** **Root cause.** `shares` has no FK to `sessions` (`src/Share/ShareStore.php:54-62`), and no delete or prune path touches it. `SessionStore::deleteSessionTrees()` (`src/Session/SessionStore.php:1005-1052`) is the single choke that …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Session/SessionStore.php`, `candy-code/src/Share/ShareStore.php`, `candy-code/src/Server/Server.php`
- **Tests — create:** `candy-code/tests/Session/SessionDeleteRevokesSharesTest.php` · **modify:** `candy-code/tests/Share/ShareStoreTest.php`, `candy-code/tests/Server/SharePageTest.php`
- **Docs/guards:** none
- **Serialized:** none (hot file: `SessionStore.php`, see Notes)
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-3.a`

#### ✅ ccfb2d363 C-AGT-1.c — Webhooks refuse over-size bodies with 413
- **Item:** C-AGT-1 (sources: AUT-6-P1) · fix · MAJOR · effort S
- **What:** Root cause: `RequestBodyBufferMiddleware(MAX_API_BODY_BYTES)` (`src/Server/Server.php:241`) does not refuse an over-limit body; it swaps in an empty body and calls `$next`. `HookWebhooks::handle()` then splices …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Server/Http/HookWebhooks.php`, `candy-code/docs/SERVER.md`
- **Tests — create:** `candy-code/tests/Server/HookWebhooksBodyCapStackTest.php` · **modify:** `candy-code/tests/Server/HookWebhooksTest.php`
- **Docs/guards:** `docs/SERVER.md` row; `ServerProtocolDocumentationDriftTest` stays green (the endpoint table wording only)
- **Serialized:** [hot:candy-code/src/Server/Http/HookWebhooks.php] [docs:SERVER.md]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-agt.md` → `### C-AGT-1.c`

#### ✅ 8ee649805 C-AGT-2.a — `/review` refuses refs the scanner cannot attach
- **Item:** C-AGT-2 (sources: GIT-1-P1) · fix · MAJOR · effort M
- **What:** Root cause: `ContextMentions::isDiffRef()` checks only `REF_PATTERN` (`src/Attachments/ContextMentions.php:93-96`). The scanner then strips trailing `}` (`trimmed()` :522-527, `TRAILING_PUNCTUATION` :79), and `diff()` runs …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Attachments/ContextMentions.php`, `candy-code/src/Commands/ReviewCommand.php`, `candy-code/src/Chat.php`, `candy-code/lang/en.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Chat/ReviewCommandTest.php`, `candy-code/tests/Attachments/ContextMentionsTest.php`
- **Docs/guards:** none (the `/review` hint has no argument roster in the docs beyond `[<ref>]`)
- **Serialized:** [chat:reviewTurn] [hot:candy-code/src/Attachments/ContextMentions.php] [lang: `cmd.review.*`]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-agt.md` → `### C-AGT-2.a`

### W2

- protocol lanes: C-SEC-2.b, C-SES-3.c (weld regenerates schema → SERVER.md → generated.ts once)
- Bootstrap.php method claims: C-EXT-1.b (checkPolicyFreshness, hookFileEntries, reportSkippedClaudeSettingsEvents)

#### ✅ 7d7bed319 C-SEC-2.b — Server-computed diff on `permission.asked`
- **Item:** C-SEC-2 (sources: GAP-EDT-03, WEB-5-P4) · fix · CRITICAL · effort M
- **What:** The TUI already shows the real file diff. `Renderer.php:7258-7263` dispatches to `Edit/Write/ApplyPatch::previewDiff($root, $args)`. The web computes its own snippet diff instead, and shows ApplyPatch only as raw JSON. New …
- **Files — create:** `candy-code/src/Permissions/AskDiffPreview.php`
- **Files — modify:** `candy-code/src/Backend/PendingAsk.php`, `candy-code/src/Backend/EngineBackend.php`, `candy-code/src/Host/TranscriptProjector.php`, `candy-code/src/Protocol/SessionFeed.php`, `candy-code/src/Protocol/Schema/Definitions.php`, `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts`, `candy-code-web/src-web/components/PermissionCard.vue`, `candy-code-web/src-web/lib/lineDiff.ts`
- **Tests — create:** `candy-code/tests/Permissions/AskDiffPreviewTest.php`, `candy-code/tests/Host/PermissionAskDiffEventTest.php`, `candy-code/tests/Permissions/PendingAskDiffTest.php` · **modify:** `candy-code-web/src-web/components/__tests__/permissionCardDiff.spec.ts`
- **Docs/guards:** `scripts/gen-protocol-schema.php --check`, `npm run gen:protocol -- --check`, `ServerProtocolDocumentationDriftTest`, `ProtocolSchemaDriftTest`. Web dist rebuild.
- **Serialized:** [protocol] [hot:candy-code/src/Backend/EngineBackend.php] [hot:candy-code/src/Host/TranscriptProjector.php] [hot:candy-code-web/src-web/components/PermissionCard.vue] [hot:candy-code-web/dist]
- **Deps:** C-SEC-2.a (W1)
- **Brief:** `prompt_kit/findings/comparison-1026/design-sec.md` → `### C-SEC-2.b`

#### ✅ 2dd2f9a7d C-SEC-3.a — One provenance-aware `!cmd` gate (refuse MCP and bundle shell forms)
- **Item:** C-SEC-3 (sources: PLG-1-P2, MCP-2-P1, p1 Summary theme, p1-miss "unified third-party shell posture") · fix · MAJOR · effort M
- **What:** Root cause: `TurnController::refuseCommandShell()` (`candy-code/src/Host/TurnController.php:521-575`) gates only the `project` tier (:528) and the `mcp` tier (:545-554), and both legs check only `trustedProjectCommands`. After …
- **Files — create:** `candy-code/src/Commands/CommandShellPosture.php`, `candy-code/src/Commands/ShellProvenance.php`
- **Files — modify:** `candy-code/src/Host/TurnController.php`, `candy-code/src/Commands/McpPromptCommands.php`, `candy-code/lang/en.php`, `candy-code/docs/COMMANDS.md`, `candy-code/docs/MCP.md`, `candy-code/docs/PLUGINS.md`
- **Tests — create:** `candy-code/tests/Commands/CommandShellPostureTest.php`, `candy-code/tests/Host/TurnControllerBundleShellTest.php` · **modify:** `candy-code/tests/Host/TurnControllerTest.php`, `candy-code/tests/Commands/McpPromptCommandsTest.php`
- **Docs/guards:** grep `candy-code/tests/Config/` for pins on the COMMANDS.md tier table, MCP.md :875-876 and the PLUGINS.md declined list (DocFigureProseDriftTest family). Move any that fire in this step. No …
- **Serialized:** [hot:candy-code/src/Host/TurnController.php] [lang:host.command_shell.] [docs:COMMANDS.md] [docs:MCP.md] [docs:PLUGINS.md]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-sec.md` → `### C-SEC-3.a`

#### ✅ 01d34eb49 C-SES-3.b — `/share list` across sessions; revoke hardening
- **Item:** C-SES-3 (+ C-SES-9's SES-3-P3) (sources: SES-3-P1, SES-3-P3, p2-miss: cross-session `/share list`) · fix · MAJOR · effort S
- **What:** **`/share list`.** New subcommand: render `ShareStore::liveAll()` as token prefix, session name (or "deleted session"), expiry and format. `/share revoke` with no token now lists every live share, not just …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Commands/ShareCommand.php`, `candy-code/src/Host/Commands/ShareHostCommand.php`, `candy-code/lang/en.php`, `candy-code/docs/COMMANDS.md`
- **Tests — create:** `candy-code/tests/Host/ShareHostCommandBufferTest.php` · **modify:** `candy-code/tests/Commands/ShareCommandTest.php`
- **Docs/guards:** `docs/COMMANDS.md` regen; `CommandDocsDriftTest`
- **Serialized:** [lang:cmd.share.] [docs:COMMANDS.md]
- **Deps:** C-SES-3.a (W1)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-3.b`

#### ✅ e55b78f22 C-PRV-1.a — `EffortGate`, a per-model shaper predicate, and Bedrock / Codex / claude-code effort
- **Item:** C-PRV-1 (sources: PRV-2-P1, p1-miss: Bedrock request-side thinking/effort, p1-miss: Codex parity (`-c model_reasoning_effort`)) · fix · MAJOR · effort M
- **What:** Root cause: `/effort` gates per provider, through the empty marker `ShapesReasoningEffort` (`candy-code/src/Providers/ShapesReasoningEffort.php:34`). But `OpenAIProvider` sends `reasoning_effort` only inside `isReasoningFamily()` …
- **Files — create:** `candy-code/src/Providers/EffortGate.php`, `candy-code/src/Providers/ThinkingCapability.php`, `candy-code/tests/Providers/EffortGateTest.php`
- **Files — modify:** `candy-code/src/Providers/ShapesReasoningEffort.php`, `candy-code/src/Providers/OpenAIProvider.php`, `candy-code/src/Providers/AnthropicProvider.php`, `candy-code/src/Providers/VertexProvider.php`, `candy-code/src/Providers/GeminiProvider.php`, `candy-code/src/Providers/SglangProvider.php`, `candy-code/src/Providers/BedrockProvider.php`, `candy-code/src/Providers/CodexProvider.php`, `candy-code/src/Providers/CodexInvocation.php`, `candy-code/src/Providers/ClaudeCodeProvider.php`, `candy-code/src/Providers/ClaudeCodeInvocation.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Providers/EffortBudgetTest.php`, `candy-code/tests/Providers/BedrockProviderTest.php`, `candy-code/tests/Providers/CodexProviderTest.php`, `candy-code/tests/Providers/ClaudeCodeProviderTest.php`, `candy-code/tests/Providers/OpenAIProviderTest.php`
- **Docs/guards:** `candy-code/docs/ADDING_A_PROVIDER.md`: the shaper contract; lang `settings.reasoningEffort.help` names which provider/model pairs shape, then regenerate `candy-code/docs/SETTINGS.md` with `php …
- **Serialized:** [lang:settings.reasoningEffort.*] [docs:SETTINGS.md] [docs:ADDING_A_PROVIDER.md] [hot:candy-code/src/Providers/OpenAIProvider.php] [hot:candy-code/src/Providers/AnthropicProvider.php] …
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-PRV-1.a`

#### ✅ 85be78e05 C-SES-2.a — FTS index: one-transaction rebuild + persisted build marker
- **Item:** C-SES-2 (sources: SES-2-P2) · fix · MAJOR · effort M
- **What:** **Root cause.** `SessionSearchIndex::$built` starts `false` in every process (`src/Session/SessionSearchIndex.php:68-70`), so `needsRebuild()` (`:102-105`) is true on each launch's first body search. …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Session/SessionSearchIndex.php`, `candy-code/src/Session/EnhancedSessionStore.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Session/SessionSearchIndexTest.php`
- **Docs/guards:** none
- **Serialized:** none (hot file: `EnhancedSessionStore.php`, see Notes)
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-2.a`

#### ✅ 5c83a0ce3 C-SES-3.c — `session.shares` read RPC (for C-WEB-3's menu)
- **Item:** C-SES-3 (sources: p2-miss: `session.shares` RPC + web share UI, SES-3-P4) · fix · MAJOR · effort S
- **What:** Add `session.shares {sessionId?}`, Scope Read. It returns the live shares of one session, or of every session when `sessionId` is absent. Each row carries a token prefix, the full token (the method is token-scoped, the same as …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Protocol/Methods/SessionMethods.php`, `candy-code/src/Protocol/Schema/MethodSchemas.php`, `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts`, `candy-code-web/src-web/protocol/__tests__/generated.spec.ts`
- **Tests — create:** none · **modify:** `candy-code/tests/Protocol/ShareMethodsTest.php`
- **Docs/guards:** the generation chain; `ProtocolSchemaDriftTest`, `ServerProtocolDocumentationDriftTest`, `generated.spec.ts`
- **Serialized:** [protocol] [docs:SERVER.md (regen only)]
- **Deps:** C-SES-3.a (W1)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-3.c`

#### ✅ ea6ee5594 C-SES-4.a — Consume-time span stamps, one OTLP body per batch, PID-guarded flush
- **Item:** C-SES-4 (sources: OBS-1-P1, OBS-1-P4, OBS-1-P2 (one-body half)) · fix · MAJOR · effort M
- **What:** **Root cause (timestamps).** `BatchEventSink::consume()` buffers raw events (`src/Observability/BatchEventSink.php:62-71`), and `OtlpEncoder::encode()` stamps `wallClockNanos()` at ENCODE time …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Observability/BatchEventSink.php`, `candy-code/src/Observability/OtelSink.php`, `candy-code/src/Observability/OtlpEncoder.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Observability/BatchEventSinkTest.php`, `candy-code/tests/Observability/OtelSinkTest.php`, `candy-code/tests/Observability/OtlpEncoderTest.php`
- **Docs/guards:** none
- **Serialized:** none
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-4.a`

#### ✅ e3eef8ac3 C-SES-5.a — Self-update robustness: exec bit, HTTP status, stall abort, temp cleanup, pagination
- **Item:** C-SES-5 (sources: DST-3-P2, DST-3-P3, DST-3-P4, DST-3-P5, DST-3-P1 (stranded-temp half)) · fix · MAJOR · effort M
- **What:** All in `src/Cli/SelfUpdate.php`. **DST-3-P2, the exec bit.** Capture `fileperms($phar) & 0777` before the swap. `chmod` the temp file after verification and before the `rename` (`:451`). `chmod` the `.old` copy after `copy()` …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Cli/SelfUpdate.php`, `candy-code/lang/en.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Cli/SelfUpdateTest.php`
- **Docs/guards:** none
- **Serialized:** [lang:cli.selfupdate.]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-5.a`

#### ✅ f57221068 C-AGT-2.c — Composite action installs with root semantics + self-test
- **Item:** C-AGT-2 (sources: GIT-2-P1, GIT-2-P2) · fix · MAJOR · effort M
- **What:** Root cause: `action.yml:103` runs `composer global require "sugarcraft/candy-code:…"`. Stability flags are root-only, the global root is `stable`, and Packagist carries no stable sibling, so the install cannot resolve; no …
- **Files — create:** `.github/workflows/candy-code-action-selftest.yml`, `tools/tests/ActionYmlInstallTest.php`
- **Files — modify:** `candy-code/action.yml`, `candy-code/docs/CI.md`, `candy-code/README.md`
- **Tests — create:** `tools/tests/ActionYmlInstallTest.php` · **modify:** none
- **Docs/guards:** `docs/CI.md`, `README.md` install block. `ReadmeRosterDriftTest` is unaffected (not a roster).
- **Serialized:** [workflows] [docs:CI.md] [docs:README.md]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-agt.md` → `### C-AGT-2.c`

#### ✅ 602ec5c22 C-EXT-1.b — Skip-and-record unsupported CC entries, file-qualified names, `~/.claude/settings.json` in policy freshness
- **Item:** C-EXT-1 (sources: INT-4-P1, INT-4-P2, INT-4-P3, GAP-HK-07, p1-miss: CC hook `type: prompt|agent`) · fix · MAJOR · effort M
- **What:** **Root causes.** `ClaudeSettingsHooks.php:257-265` refuses the launch for any non-`command` type. `:269` names rows `cc:{event}:{index}` with a per-file index, so a user `PreToolUse` plus a trusted project `PreToolUse` collide. …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Hooks/ClaudeSettingsHooks.php`, `candy-code/src/Cli/Bootstrap.php`, `candy-code/src/Config/PolicySnapshot.php`, `candy-code/docs/HOOKS.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Cli/BootstrapHookFileTest.php`, `candy-code/tests/Hooks/ClaudeSettingsHooksTest.php`, `candy-code/tests/Cli/ClaudeSettingsHooksSkipRecordTest.php`, `candy-code/tests/Cli/BootstrapLaunchFormatConstantsTest.php`, `candy-code/tests/Cli/PolicyFreshnessQuarantineTest.php`, `candy-code/tests/Config/PolicySnapshotTest.php`, `candy-code/tests/Config/DocFigureProseDriftTest.php`
- **Docs/guards:** HOOKS.md. `BootstrapLaunchNoticeCapCensusTest` must stay at 1 for this drain, and the step asserts it unchanged.
- **Serialized:** [bootstrap:hookFileEntries,checkPolicyFreshness,reportSkippedClaudeSettingsEvents] [docs:HOOKS.md]
- **Deps:** C-EXT-1.a (W1)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ext.md` → `### C-EXT-1.b`

### W3

- protocol lanes: C-SEC-4.b (weld regenerates schema → SERVER.md → generated.ts once)
- Chat.php region claims: C-PRV-1.b (handleEffortCommand, withEngineSettings); C-SES-2.b (buildSessionPicker, runSessionListAction, sanitizeSessionRows)
- Bootstrap.php method claims: C-PRV-1.b (withResolvedReasoningEffort)

#### ✅ 393635d2c C-SEC-3.b (foreign-row/doc-truth half; opt-in half carried: per-origin trustedBundleCommands/mcpPromptShell follow-up lane after C-EXT-4.c — row-identity gap, dated 2026-10-27) — Per-origin shell opt-in and the foreign-row decision
- **Item:** C-SEC-3 (sources: PLG-1-P2 fix option 2, MCP-2-P1 fix option 2, p1-miss "foreign-user" tier) · fix · MAJOR · effort S
- **What:** Two user-tier-only list keys: `trustedBundleCommands`: bundle names whose command `!` forms may run; `mcpPromptShell`: MCP server names whose prompt bodies may run `!` forms. Neither key is ever project-settable. The …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Commands/CommandShellPosture.php`, `candy-code/src/Host/TurnController.php`, `candy-code/src/Config/Settings/Definitions/PermissionSettings.php`, `candy-code/src/Config/LayeredSettings.php`, `candy-code/lang/en.php`, `candy-code/docs/SETTINGS.md`, `candy-code/README.md`, `candy-code/docs/COMMANDS.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Commands/CommandShellPostureTest.php`, `candy-code/tests/Host/TurnControllerBundleShellTest.php`
- **Docs/guards:** `tools/gen-settings-doc.php --check`; `TrustKeyDocumentationDriftTest` (the README user-tier-only roster); `DocFigureProseDriftTest` (the SETTINGS.md and README word numbers); …
- **Serialized:** [hot:candy-code/src/Host/TurnController.php] [hot:candy-code/src/Config/LayeredSettings.php] [hot:candy-code/src/Config/Settings/Definitions/PermissionSettings.php] [lang:settings.] …
- **Deps:** C-SEC-3.a (W2)
- **Ruling:** Should bundle and MCP-prompt `!cmd` be opt-in per origin (two new user-tier list keys)? And do user-tier foreign commands (`~/.claude|.opencode/commands`) run … — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-sec.md` → `### C-SEC-3.b`

#### ✅ 9c3857a5b C-MCP-1.b — Deferred `mcp_call` keeps `mcp__*` hooks and exact Allow grants
- **Item:** C-MCP-1 (sources: MCP-7-P1, MCP-7-P2) · fix · MAJOR · effort M
- **What:** Root cause (hooks): `HookRegistry::findMatches()` tests each matcher against the bare `$toolName` (`candy-code/src/Hooks/HookRegistry.php:191-211`), fed `$context->toolName` by `executeHooks()` (`:875`) and `chainBudgetSeconds()` …
- **Files — create:** `candy-code/tests/Hooks/McpCallHookParityTest.php`
- **Files — modify:** `candy-code/src/Hooks/HookRegistry.php`, `candy-code/src/Permissions/PermissionRule.php`, `candy-code/src/Tools/BuiltIn/McpCallTool.php`, `candy-code/docs/MCP.md`, `candy-code/docs/HOOKS.md`, `candy-code/docs/PERMISSIONS.md`
- **Tests — create:** `candy-code/tests/Hooks/McpCallHookParityTest.php` · **modify:** `candy-code/tests/Permissions/McpDispatchParityTest.php`, `candy-code/tests/Hooks/HookRegistryTest.php`
- **Docs/guards:** no drift arm pins the replaced sentences (grep `additionally match`/`translate in neither` in tests: 0). Check `tests/Hooks/ProtectFilesPolicySurfacesTest.php` if the PERMISSIONS.md ProtectFiles row …
- **Serialized:** [docs:MCP.md §Deferred schemas] [docs:HOOKS.md] [docs:PERMISSIONS.md] [hot:candy-code/src/Hooks/HookRegistry.php] [hot:candy-code/src/Permissions/PermissionRule.php]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-mcp.md` → `### C-MCP-1.b`

#### ✅ a8ead9976 C-SES-5.b — Signature over the sha256 sidecar (stream-safe verify)
- **Item:** C-SES-5 (sources: DST-3-P1, DST-3-P5 (minisig naming/format)) · fix · MAJOR · effort M
- **What:** **Root cause.** `verifySignature()` reads the whole ~119 MiB phar with `file_get_contents($temp)` (`SelfUpdate.php:678`) to call `sodium_crypto_sign_verify_detached` (`:714`). That is fatal under the default 128M `memory_limit`. …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Cli/SelfUpdate.php`, `candy-code/lang/en.php`, `candy-code/README.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Cli/SelfUpdateTest.php`
- **Docs/guards:** README.md only
- **Serialized:** [lang:cli.selfupdate.sig] [docs:README.md]
- **Deps:** C-SES-5.a (W2)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-5.b`

#### ✅ 893f271cf C-CTX-1.a — Prime the instruction-diff tracker before the turn forks
- **Item:** C-CTX-1 (sources: CTX-3-P1) · fix · MAJOR · effort M
- **What:** Root cause: `Runtime::instructionDiff()` (`candy-code/src/Runtime.php:5742-5754`) memoises `InstructionDiffTracker::capture($loader)` on the first `turnContext()`, and that runs only inside the forked child. …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Runtime.php`, `candy-code/src/Backend/EngineBackend.php`, `candy-code/src/Context/InstructionDiffTracker.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Providers/PromptPrefixByteStabilityTest.php`
- **Docs/guards:** none
- **Serialized:** [runtime:primeInstructionDiff (new), instructionDiff] [hot:candy-code/src/Backend/EngineBackend.php] [hot:candy-code/src/Context/InstructionDiffTracker.php]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-CTX-1.a`

#### ✅ a992413b0 C-PRV-1.b — Honest `/effort` and an honest launch tier
- **Item:** C-PRV-1 (sources: PRV-2-P1) · fix · MAJOR · effort S
- **What:** `Chat::handleEffortCommand()` (`Chat.php:12234-12310`) asks `EffortGate::verdict($provider, $engine->model())`. It refuses per model (`chat.effort.unsupported_model`) and takes its status shape from `EffortGate::shape()`. Correct …
- **Files — create:** `candy-code/tests/Cli/LaunchEffortNoticeTest.php`
- **Files — modify:** `candy-code/src/Chat.php`, `candy-code/src/Cli/LaunchContext.php`, `candy-code/src/Cli/Bootstrap.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Chat/EffortCommandTest.php`
- **Docs/guards:** `candy-code/docs/SETTINGS.md` hand prose for `reasoningEffort` (outside generated regions)
- **Serialized:** [chat:handleEffortCommand, withEngineSettings] [bootstrap:withResolvedReasoningEffort] [lang:chat.effort.*] [docs:SETTINGS.md] [hot:candy-code/src/Cli/LaunchContext.php]
- **Deps:** C-PRV-1.a (W2)
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-PRV-1.b`

#### ✅ 0e25ae5c7 C-PRV-1.c — Safe `candy-code login`: merge, refuse a type clash, no echo
- **Item:** C-PRV-1 (sources: PRV-4-P1, PRV-4-P2) · fix · MAJOR · effort S
- **What:** Root cause 1: `Subcommands::loginStoreKey()` replaces the provider block outright with `$providers[$type] = ['type' => $type, 'apiKey' => $key]` (`candy-code/src/Cli/Subcommands.php:973`). Rotating a key wipes `model`, …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Cli/Subcommands.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Cli/LoginSubcommandTest.php`
- **Docs/guards:** lang `cli.sub.login.type_mismatch`; no doc row changes
- **Serialized:** [headless] [lang:cli.sub.login.*]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-PRV-1.c`

#### ✅ 40d19f15f C-SEC-4.b — `context.trust` routable, failed writes surfaced, prompt discoverable
- **Item:** C-SEC-4 (sources: WEB-3-P1, WEB-3-P2, WEB-3-P3, p2-miss "WEB-3 trust prompt discovery") · fix · MAJOR · effort M
- **What:** P1 root cause: the `context.trust` params (`MethodSchemas.php:413-418`) carry no `sessionId`. `Gateway::routeOf()` (`src/Server/Workspace/Gateway.php:289-298`) therefore sends the call to the primary process. The prompt was …
- **Files — create:** `candy-code/tests/Server/GatewayTrustRoutingTest.php`, `candy-code-web/src-web/stores/__tests__/contextTrustRouting.spec.ts`, `candy-code-web/src-web/components/__tests__/statusBarTrustBadge.spec.ts`
- **Files — modify:** `candy-code/src/Protocol/Schema/MethodSchemas.php`, `candy-code/src/Protocol/Methods/ContextMethods.php`, `candy-code/src/Protocol/Methods/SessionMethods.php`, `candy-code/src/Protocol/TrustAsks.php`, `candy-code/lang/en.php`, `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts`, `candy-code-web/src-web/stores/contextSources.ts`, `candy-code-web/src-web/components/panels/ContextPanel.vue`, `candy-code-web/src-web/components/StatusBar.vue`
- **Tests — create:** none · **modify:** `candy-code/tests/Protocol/ContextTrustPromptTest.php`, `candy-code-web/src-web/stores/__tests__/contextSources.spec.ts`
- **Docs/guards:** protocol gen checks; `ServerProtocolDocumentationDriftTest`; the SERVER.md error-kind roster (new `trust_write_failed`). Web dist rebuild.
- **Serialized:** [protocol] [hot:candy-code/src/Protocol/TrustAsks.php] [hot:candy-code-web/src-web/components/panels/ContextPanel.vue] [hot:candy-code-web/src-web/stores/contextSources.ts] …
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-sec.md` → `### C-SEC-4.b`

#### ✅ f05d52c06 C-SES-2.b — Picker shows body hits + snippets + per-row spend; `/` filter reaches FTS
- **Item:** C-SES-2 (+ C-SES-1's SES-1-P4 picker half) (sources: SES-2-P1, p2-miss: picker body snippet (`SessionRow::withBodySnippet` dormant), SES-1-P4, p2-miss: … · fix · MAJOR · effort M
- **What:** **Root cause.** `buildSessionPicker()` (`src/Chat.php:14668-14689`) fetches FTS-ranked rows through `sessionPickerRows()` (`:14714-14733`), then calls `withQuery()`. `SessionPicker::rankedLayout()` …
- **Files — create:** `candy-code/src/App/SessionPickerSearchMsg.php`
- **Files — modify:** `candy-code/src/Chat.php`, `candy-code/src/Chat/Handlers/HandlesSessionSurface.php`, `candy-code/src/Tui/SessionPicker.php`
- **Tests — create:** `candy-code/tests/Chat/SessionPickerDebouncedSearchTest.php` · **modify:** `candy-code/tests/Chat/SessionCommandTest.php`, `candy-code/tests/Tui/SessionPickerFilterTest.php`, `candy-code/tests/Tui/SessionPickerSlashFilterTest.php`, `candy-code/tests/Tui/SessionPickerGoldenTest.php`
- **Docs/guards:** none (no new key binding, since `/` already exists)
- **Serialized:** [chat:buildSessionPicker,sanitizeSessionRows,runSessionListAction] [hot:candy-code/src/Chat/Handlers/HandlesSessionSurface.php] [hot:candy-code/src/Tui/SessionPicker.php]
- **Deps:** C-SES-2.a (W2)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-2.b`

#### ✅ 8c93928f2 C-WEB-1.d — Read-image MediaCards adopt into the session media store
- **Item:** C-WEB-1 (sources: GAP-TL-19 web half, k2-sum `media.fetch` live defect, k2-dorm broken Read-image path) · fix · MAJOR · M
- **What:** **Root cause.** `TranscriptProjector::mediaRefs()` (`candy-code/src/Host/TranscriptProjector.php:301-303`, `mediaRef()` :351-364) projects Read's workspace `imagePath` (set at `src/Tools/BuiltIn/Read.php:745`) as an artifact …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Host/TranscriptProjector.php`, `candy-code/src/Host/SessionSnapshot.php`, `candy-code/src/Support/MediaStore.php`, `candy-code/docs/MEDIA.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Host/TranscriptProjectorMediaTest.php`, `candy-code/tests/Protocol/MediaMethodsTest.php`
- **Docs/guards:** `docs/MEDIA.md`. `tests/Support/ReadPathCensusTest.php` is unaffected (no new read sink); confirm it stays green.
- **Serialized:** [hot:candy-code/src/Host/TranscriptProjector.php] [docs:MEDIA.md]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-web.md` → `### C-WEB-1.d`

#### ✅ 592261f3c C-CTX-1.b — Cursor glob rules fire; disabled imported rules are explained
- **Item:** C-CTX-1 (sources: INT-2-P1, INT-2-P2) · fix · MAJOR · effort S
- **What:** Root cause: `candy-code/src/Context/Rule.php:233-238` ANDs `alwaysApply` into `enabled`. Cursor's canonical Auto-Attached shape (`globs: src/**/*.ts` + `alwaysApply: false`) therefore parses as disabled, and `RuleLoader::load()` …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Context/Rule.php`, `candy-code/src/Commands/RulesCommand.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Context/RuleLoaderTest.php`, `candy-code/tests/Commands/RulesCommandTest.php`
- **Docs/guards:** `candy-code/docs/MEMORY.md` (Cursor mapping table)
- **Serialized:** [lang:cmd.rules.alwaysapply_hint*] [docs:MEMORY.md] [hot:candy-code/src/Context/Rule.php]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-CTX-1.b`

### W4

- protocol lanes: C-SES-2.c, C-SEC-4.a (weld regenerates schema → SERVER.md → generated.ts once)
- Bootstrap.php method claims: P-TABS.d (chat)

#### ✅ 46f517459 C-SES-2.c — Protocol: summaries carry snippets + honest spend; web body search + live `≥`
- **Item:** C-SES-2 (+ C-SES-1's web half) (sources: GAP-SES-11, SES-1-P2, SES-1-P4, p2-miss: per-session spend on sidebar) · fix · MAJOR · effort M
- **What:** **Root cause (web search).** `SessionMethods::list()` filters `query` by `str_contains` over name, id and `last_preview` (`src/Protocol/Methods/SessionMethods.php:82-113`). It never reaches FTS. **Root cause (spend).** The …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Protocol/Methods/SessionMethods.php`, `candy-code/src/Host/SessionHub.php`, `candy-code/src/Protocol/ServerContext.php`, `candy-code/src/Protocol/Schema/Definitions.php`, `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts`, `candy-code-web/src-web/stores/reducer.ts`, `candy-code-web/src-web/stores/sessions.ts`, `candy-code-web/src-web/components/SessionSidebar.vue`
- **Tests — create:** `candy-code/tests/Server/SessionListBodySearchTest.php` · **modify:** `candy-code/tests/Host/SessionHubTest.php`, `candy-code-web/src-web/stores/__tests__/reducer.spec.ts`, `candy-code-web/src-web/components/__tests__/statusBar.spec.ts`, `candy-code-web/src-web/components/__tests__/sidebar.spec.ts`
- **Docs/guards:** the generation chain: 1. `php candy-code/scripts/gen-protocol-schema.php` regenerates the schema JSON and `docs/SERVER.md`; 2. `npm run gen:protocol` regenerates `generated.ts`. Guards moved: …
- **Serialized:** [protocol] [hot:candy-code/src/Protocol/ServerContext.php] [hot:candy-code-web/src-web/components/SessionSidebar.vue] [docs:SERVER.md (regen only)]
- **Deps:** C-SES-2.a (W2)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-2.c`

#### ✅ 745fe7d14 C-SEC-4.a — Served sessions honour `trustedProjectCommands`
- **Item:** C-SEC-4 (sources: GAP-CMD-02, k2-sum live defect) · fix · MAJOR · effort S
- **What:** Root cause: two served callers of `CommandLoader::loadAll()` omit its second argument, `bool $projectCommandsTrusted` (`src/Commands/CommandLoader.php:605`): `SessionHost::__construct()` …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Host/SessionHost.php`, `candy-code/src/Protocol/Methods/CommandMethods.php`
- **Tests — create:** `candy-code/tests/Host/ServedProjectCommandTrustTest.php` · **modify:** none
- **Docs/guards:** none
- **Serialized:** [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Protocol/Methods/CommandMethods.php] (body-only; see Notes)
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-sec.md` → `### C-SEC-4.a`

#### ✅ 512a60936 C-SES-1.a — Headless `-p` spend reaches the ledger; `session list` shows spend
- **Item:** C-SES-1 (sources: SES-1-P1, p2-miss: headless `-p` spend, SES-1-P4 `session list` half) · fix · MAJOR · effort M
- **What:** **Root cause.** `NonInteractive::run()` collects each step's `Usage` into `$sessionUsages` (`src/Cli/NonInteractive.php:405-409`), but only for the JSON envelope (`:609`). `appendUsageRecord` has exactly two callers, …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Cli/NonInteractive.php`, `candy-code/src/Cli/Subcommands.php`, `candy-code/lang/en.php`, `candy-code/README.md`
- **Tests — create:** `candy-code/tests/Cli/NonInteractiveUsageLedgerTest.php` · **modify:** `candy-code/tests/Cli/SessionSubcommandsTest.php`
- **Docs/guards:** the README.md paragraphs only. No new verb, flag or env var, so no roster guard moves.
- **Serialized:** [headless] [lang:cli.session.list.spend] [docs:README.md]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-1.a`

#### ✅ dd79e7d73 C-SES-5.d — Workflows: GHCR image cache-bust, release filter, versioned tags
- **Item:** C-SES-5 (sources: DST-2-P1, DST-2-P2, p2-miss: versioned Docker tags/provenance) · fix · MAJOR · effort M
- **What:** **Root cause.** The `RUN composer create-project sugarcraft/candy-code:dev-master` instruction text never changes (`candy-code/Dockerfile:63-69`), and the workflow caches with `cache-from: type=gha`. The layer is therefore served …
- **Files — create:** `tools/tests/CandyCodeImageCacheBustTest.php`
- **Files — modify:** `.github/workflows/candy-code-image.yml`, `candy-code/docs/DOCKER.md`, `candy-code/docs/SERVER.md`
- **Tests — create:** `tools/tests/CandyCodeImageCacheBustTest.php` · **modify:** none
- **Docs/guards:** DOCKER.md and SERVER.md prose
- **Serialized:** [workflows] [docs:DOCKER.md] [docs:SERVER.md]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-5.d`

#### ✅ 7c237e059 C-TOOL-1.b — rg `--no-config` jail fix
- **Item:** C-TOOL-1 (sources: SHL-4-P1) · fix · MAJOR · effort S
- **What:** Root cause `candy-code/src/Tools/BuiltIn/Grep.php:972`: the rg command line has no `--no-config`, and `ProcessContainment::scrubbedEnv()` strips only secret-shaped names, so the operator's `RIPGREP_CONFIG_PATH` reaches rg. A …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Tools/BuiltIn/Grep.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Tools/BuiltIn/GrepOptionInjectionTest.php`
- **Docs/guards:** none (the description does not enumerate rg flags)
- **Serialized:** none
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-1.b`

#### ✅ 4e3535ed4 C-WEB-1.c — `@`-mention index refreshes at every turn end (TUI + serve)
- **Item:** C-WEB-1 (sources: WEB-5-P2, TUI-1-P1, p2-miss: @-mention freshness) · fix · MAJOR · S
- **What:** **Root cause.** `MentionIndex::files()` (`candy-code/src/Attachments/MentionIndex.php:103-111`) memoizes one `git ls-files` per root per process. `forget()` (:540-560), "the hook a future refresh command would call", has only …
- **Files — create:** `candy-code/tests/Host/TurnRunnerMentionFreshnessTest.php`
- **Files — modify:** `candy-code/src/Host/TurnRunner.php`, `candy-code/src/Attachments/MentionIndex.php`
- **Tests — create:** `candy-code/tests/Host/TurnRunnerMentionFreshnessTest.php` · **modify:** `candy-code/tests/Server/FsSearchTest.php`
- **Docs/guards:** MentionIndex docblock only (no README/docs prose pins the staleness).
- **Serialized:** [hot:candy-code/src/Host/TurnRunner.php]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-web.md` → `### C-WEB-1.c`

#### ✅ 4a94eb432 P-CTX.a — Context attribution by category (core + `/context` text)
- **Item:** P-CTX-BREAKDOWN (sources: owner pick; PRM-12; CC `/context` grid, `comp-claude-code.md` PRM-12 row) · feature · effort M
- **What:** **What exists.** `ContextBreakdown` (`src/Context/ContextBreakdown.php:94-105`) knows the per-label prompt layers (`Runtime::promptSectionSizes()`, `src/Runtime.php:4389`), a single tools figure, history, pruning and cache. **New …
- **Files — create:** `candy-code/src/Context/ContextCategory.php`, `candy-code/src/Context/ContextCategories.php`
- **Files — modify:** `candy-code/src/Context/ContextBreakdown.php`, `candy-code/src/Host/ContextSources.php`, `candy-code/src/Commands/ContextCommand.php`, `candy-code/src/Backend/EngineBackend.php`, `candy-code/lang/en.php`, `candy-code/docs/CONTEXT.md`
- **Tests — create:** `candy-code/tests/Context/ContextCategoriesTest.php` · **modify:** `candy-code/tests/Commands/ContextCommandTest.php`, `candy-code/tests/Chat/ContextCommandsTest.php`
- **Docs/guards:** `docs/CONTEXT.md` (prose; no roster guard)
- **Serialized:** [lang] (prefix `cmd.context.category.`) [docs:CONTEXT.md]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-picks.md` → `### P-CTX.a`

#### ✅ 88ac5e485 P-TABS.b — TabScope envelope + SessionDeck (wires dormant SessionTabs detach/summary)
- **Item:** P-SESSION-TABS (sources: GAP-SES-12, GAP-UI-N6) · feature · effort M
- **What:** **The problem.** Today one `App::$chat` receives every Msg (`App::delegateToChat`, `src/App/App.php:4298`). For a parked tab to keep streaming, its Cmds must come back addressed to it. **`TabScope::cmd(string $tabKey, …
- **Files — create:** `candy-code/src/App/Tabs/TabScope.php`, `candy-code/src/App/Tabs/TabScopedMsg.php`, `candy-code/src/App/Tabs/SessionDeck.php`, `candy-code/src/App/Tabs/OpenTabMsg.php`, `candy-code/src/App/Tabs/CloseTabMsg.php`, `candy-code/src/App/Tabs/CycleTabMsg.php`
- **Files — modify:** `candy-code/src/Tui/SessionTab.php`, `candy-code/src/Tui/SessionTabs.php`
- **Tests — create:** `candy-code/tests/App/Tabs/TabScopeTest.php`, `candy-code/tests/App/Tabs/SessionDeckTest.php` · **modify:** `candy-code/tests/Tui/SessionTabsTest.php`, `candy-code/tests/Host/SessionTabsExtractionParityTest.php`
- **Docs/guards:** none
- **Serialized:** none
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-picks.md` → `### P-TABS.b`

#### ✅ da772ff56 P-TABS.d — Tab-Chat factory (`Bootstrap::tabChat`)
- **Item:** P-SESSION-TABS (sources: GAP-SES-12) · feature · effort M
- **What:** `Bootstrap::chat()` (`src/Cli/Bootstrap.php:1183`) is the only Chat constructor. It also seeds launch notices, opens/locks the launch session and arms the notice drain (`drainsRuntimeNotices: true`, :1415). Extract its body into …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Cli/Bootstrap.php`, `candy-code/src/Cli/LaunchContext.php`, `candy-code/src/App/App.php`
- **Tests — create:** `candy-code/tests/Cli/TabChatFactoryTest.php` · **modify:** none
- **Docs/guards:** none
- **Serialized:** [bootstrap:chat] [hot:candy-code/src/Cli/LaunchContext.php] [hot:candy-code/src/App/App.php]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-picks.md` → `### P-TABS.d`

#### ✅ 6caf1052d P-IMG.b — Content-sized, centred `/generate` popup
- **Item:** P-IMAGE-MENU (sources: owner pick) · feature · effort M
- **What:** **Root cause.** `Tui\Renderer::renderView` diverts to `renderMediaForm()` before the band is laid out (`src/Tui/Renderer.php:646-650`). It hands `MediaForm::view()` the full `$cols × $paneRows` (:940-960), and `view()` draws its …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Tui/MediaForm/MediaForm.php`, `candy-code/src/Tui/MediaForm/FormTabs.php`, `candy-code/src/Tui/Renderer.php`
- **Tests — create:** `candy-code/tests/Tui/MediaForm/MediaFormPopupSizeTest.php` · **modify:** `candy-code/tests/App/MediaFormWiringTest.php`
- **Docs/guards:** none (`tests/Tui/MediaForm/golden/*` unaffected: `view()` contract unchanged)
- **Serialized:** [renderer:Tui/Renderer.php renderView+renderMediaForm] (layout #1 of 3)
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-picks.md` → `### P-IMG.b`

### W5

- protocol lanes: C-WEB-1.a (weld regenerates schema → SERVER.md → generated.ts once)
- Chat.php region claims: P-CTX.d (measureContextCmd (new method) + session-surface route arm)
- Bootstrap.php method claims: C-TOOL-7.a (backgroundShells)

#### ✅ 09feae49b C-WEB-1.a — Served `/model` and `/rename` reach the session summary
- **Item:** C-WEB-1 (sources: WEB-2-P1, p2-miss: web model switch) · fix · MAJOR · S
- **What:** **Root cause, model.** The `SetModel` arm (`candy-code/src/Host/SessionHost.php:1348-1353`) only swaps `$sessionBackend`, while `ServerContext::summary()` (`src/Protocol/ServerContext.php:320-340`) reads `provider`/`model` from …
- **Files — create:** `candy-code/tests/Protocol/SessionSummaryModelOverlayTest.php`
- **Files — modify:** `candy-code/src/Host/SessionHost.php`, `candy-code/src/Protocol/ServerContext.php`, `candy-code/src/Protocol/EventType.php`, `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts`
- **Tests — create:** `candy-code/tests/Protocol/SessionSummaryModelOverlayTest.php` · **modify:** `candy-code/tests/Host/WebTwoHostCommandsTest.php`
- **Docs/guards:** `php candy-code/scripts/gen-protocol-schema.php --check` (`tests/Protocol/ProtocolSchemaDriftTest.php`); `npm run gen:protocol -- --check`; …
- **Serialized:** [protocol] (EventType description only — no MethodSchemas change) [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Protocol/ServerContext.php]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-web.md` → `### C-WEB-1.a`

#### ✅ 34a295d2d C-WEB-1.b — Status-bar model select never shows an unapplied pick
- **Item:** C-WEB-1 (sources: WEB-2-P1 client half) · fix · MAJOR · S
- **What:** **Root cause.** `pickModel()` (`candy-code-web/src-web/components/StatusBar.vue:52-55`) emits `/model` and leaves the `<select>` DOM showing the pick. The bound `:value` (the summary) did not change, so Vue never repaints. A …
- **Files — create:** none
- **Files — modify:** `candy-code-web/src-web/components/StatusBar.vue`
- **Tests — create:** none · **modify:** `candy-code-web/src-web/components/__tests__/statusBar.spec.ts`
- **Docs/guards:** vitest; dist rebuild (see Notes).
- **Serialized:** [hot:candy-code-web/src-web/components/StatusBar.vue]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-web.md` → `### C-WEB-1.b`

#### ✅ 9aa5258b1 P-CTX.c — ContextPane component (collapsed meter → expanded breakdown)
- **Item:** P-CTX-BREAKDOWN (sources: owner pick) · feature · effort M
- **What:** `ContextPane::renderModel(ContextPaneModel, Theme, width, rows)` and `layout()` (row keys), the pure "sidebar box". `ContextPaneModel` holds `?ContextCategories`, the live history tokens (status-bar figure), the window, …
- **Files — create:** `candy-code/src/Tui/Components/ContextPane.php`, `candy-code/src/Tui/Components/ContextPaneModel.php`
- **Files — modify:** `candy-code/lang/en.php`
- **Tests — create:** `candy-code/tests/Tui/Components/ContextPaneTest.php` · **modify:** none
- **Docs/guards:** none
- **Serialized:** [lang] (prefix `tui.context.`)
- **Deps:** P-CTX.a (W4)
- **Brief:** `prompt_kit/findings/comparison-1026/design-picks.md` → `### P-CTX.c`

#### ✅ 967479a37 P-CTX.d — Chat context-snapshot producer (TurnRunner-held) (erratum 2026-10-27: pick 967479a37 undisclosed +10 Chat-test widening disclosed at W5 wave review)
- **Item:** P-CTX-BREAKDOWN (sources: owner pick) · feature · effort S
- **What:** The pane must never measure in `view()`: `promptSectionSizes()` builds a Runtime, and the first call can touch disk. Follow TodoPane's "read, never fetched" law (`src/Tui/Components/TodoPane.php:103-115`): `TurnRunner` gains …
- **Files — create:** `candy-code/src/App/ContextMeasuredMsg.php`
- **Files — modify:** `candy-code/src/Host/TurnRunner.php`, `candy-code/src/Chat.php`, `candy-code/src/Chat/Handlers/HandlesTurnCompletion.php`, `candy-code/src/Chat/Handlers/HandlesSessionSurface.php`
- **Tests — create:** `candy-code/tests/Host/TurnRunnerContextTest.php`, `candy-code/tests/Chat/ContextSnapshotScheduleTest.php` · **modify:** none
- **Docs/guards:** `tests/Chat/RouteTraitParityTest` (or its real name: the "no class claimed twice" pin) covers the new arm
- **Serialized:** [chat:measureContextCmd (new method) + session-surface route arm] [hot:candy-code/src/Chat/Handlers/HandlesTurnCompletion.php] [hot:candy-code/src/Host/TurnRunner.php]
- **Deps:** P-CTX.a (W4)
- **Brief:** `prompt_kit/findings/comparison-1026/design-picks.md` → `### P-CTX.d`

#### ✅ eecf4d5b0 P-TABS.a — Window kit: shared session-window primitives
- **Item:** P-SESSION-TABS + P-AGENT-ATTACH (sources: GAP-SES-12, GAP-UI-N6, k1-dorm#3) · feature · effort M
- **What:** New pure value and renderer classes under `src/Tui/Window/`, shared by both picks. The model and its values: `WindowRef{kind, key, label, ?color, ?parentKey}`; `WindowAttention` enum (Idle/Working/Waiting/Unseen/Failed), with a …
- **Files — create:** `candy-code/src/Tui/Window/WindowKind.php`, `candy-code/src/Tui/Window/WindowRef.php`, `candy-code/src/Tui/Window/WindowAttention.php`, `candy-code/src/Tui/Window/WindowViewport.php`, `candy-code/src/Tui/Window/WindowViewports.php`, `candy-code/src/Tui/Window/WindowList.php`, `candy-code/src/Tui/Window/WindowEntry.php`, `candy-code/src/Tui/Window/WindowStrip.php`, `candy-code/src/Tui/Window/AgentColor.php`
- **Files — modify:** `candy-code/lang/en.php`
- **Tests — create:** `candy-code/tests/Tui/Window/WindowStripTest.php`, `candy-code/tests/Tui/Window/WindowViewportsTest.php`, `candy-code/tests/Tui/Window/WindowAttentionTest.php`, `candy-code/tests/Tui/Window/AgentColorTest.php` · **modify:** none
- **Docs/guards:** none (one type per file: 9 files, 9 types)
- **Serialized:** [lang] (prefix `tui.window.`)
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-picks.md` → `### P-TABS.a`

#### ✅ c1267a71a P-MENU.a — Nested menu kit (`Tui/Menu/*`)
- **Item:** P-MENU-REDO (sources: owner pick) · feature · effort M
- **What:** No nested menu exists. `MenuBar` models a menu as a flat `list<string>` with one static row cursor (`MenuBar.php:74-82`, :127). The kit is therefore new, pure and theme-driven, in `candy-code/src/Tui/Menu/`: the same shape as …
- **Files — create:** `candy-code/src/Tui/Menu/MenuNode.php`, `candy-code/src/Tui/Menu/MenuNodeKind.php`, `candy-code/src/Tui/Menu/MenuTree.php`, `candy-code/src/Tui/Menu/MenuCursor.php`, `candy-code/src/Tui/Menu/MenuStep.php`, `candy-code/src/Tui/Menu/MenuCascade.php`, `candy-code/src/Tui/Menu/MenuPanel.php`
- **Files — modify:** none
- **Tests — create:** `candy-code/tests/Tui/Menu/MenuCursorTest.php`, `candy-code/tests/Tui/Menu/MenuCascadeTest.php`, `candy-code/tests/Tui/Menu/golden/`, `candy-code/tests/Tui/Menu/MenuTreeTest.php` · **modify:** none
- **Docs/guards:** none. The new test files go to the weld for `scripts/parallel-tests-durations.tsv`.
- **Serialized:** none
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-menu.md` → `### P-MENU.a`

#### ✅ 4e91ddcb2 P-CTX.b — `/context --raw [path]` prompt dump (GAP-PRM-15)
- **Item:** P-CTX-BREAKDOWN (sources: GAP-PRM-15) · gap · effort S
- **What:** There is no raw system-prompt dump today. `Runtime::buildSystemPrompt()` is private (`src/Runtime.php:4362`). Add a public one-line `Runtime::assembledSystemPrompt(App)`, and a new opt-in `Backend\DumpsSystemPrompt` capability …
- **Files — create:** `candy-code/src/Backend/DumpsSystemPrompt.php`
- **Files — modify:** `candy-code/src/Runtime.php`, `candy-code/src/Backend/EngineBackend.php`, `candy-code/src/Host/Commands/ContextHostCommand.php`, `candy-code/builtin-commands/1350-context.php`, `candy-code/lang/en.php`, `candy-code/docs/COMMANDS.md`, `candy-code/docs/PROMPT_ENGINEERING.md`
- **Tests — create:** `candy-code/tests/Host/Commands/ContextRawDumpTest.php` · **modify:** none
- **Docs/guards:** `docs/COMMANDS.md` via `tools/gen-command-docs.php --write` with `tests/Commands/CommandDocsDriftTest.php`; `docs/PROMPT_ENGINEERING.md`
- **Serialized:** [runtime] [commands] (existing row `1350-context.php`) [lang] (prefix `cmd.context.raw.`) [docs:COMMANDS.md] [docs:PROMPT_ENGINEERING.md]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-picks.md` → `### P-CTX.b`

#### ✅ a28779b00 P-IMG.c — Web: Generate entry in the top bar
- **Item:** P-IMAGE-MENU (sources: owner pick) · feature · effort S
- **What:** The web Generate panel is only a drawer tab and a palette row. Add a `generate` button to the top bar beside settings/about, shown only when `connection.canGenerateImage`. It opens `usePanelsStore().show('generate')`. The drawer …
- **Files — create:** none
- **Files — modify:** `candy-code-web/src-web/App.vue`
- **Tests — create:** `candy-code-web/src-web/components/__tests__/topbarGenerate.spec.ts` · **modify:** none
- **Docs/guards:** web unit tests
- **Serialized:** [hot:candy-code-web/src-web/App.vue]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-picks.md` → `### P-IMG.c`

#### ✅ c6f3e3f56 P-TABS.h — Web: `+` new-session tab and unseen badges
- **Item:** P-SESSION-TABS (sources: GAP-UI-N6 web half; owner pick) · feature · effort S
- **What:** The web tab strip (`components/grid/SessionTabs.vue`) already streams every session and badges asks, but has no `+`. Add a trailing `+` tab → `useNewSessionStore().start()` (picker-aware), then route to the new session. Add an …
- **Files — create:** none
- **Files — modify:** `candy-code-web/src-web/components/grid/SessionTabs.vue`, `candy-code-web/src-web/stores/layout.ts`
- **Tests — create:** `candy-code-web/src-web/components/grid/__tests__/sessionTabsNew.spec.ts` · **modify:** `candy-code-web/src-web/stores/__tests__/layout.spec.ts`
- **Docs/guards:** web unit tests
- **Serialized:** none
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-picks.md` → `### P-TABS.h`

#### ✅ ccd7c37b2 C-TOOL-7.a — Background-shell registry nits
- **Item:** C-TOOL-7 (sources: SHL-1-P2, SHL-1-P3, SHL-1-P4) · fix · MINOR · effort M
- **What:** Four fixes on one file family, kept in one lane so the registry is touched once. (1) Wire, don't delete, the dead `forgetHandles()` (`candy-code/src/Background/BackgroundShellRegistry.php:692`; zero callers). The registry …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Background/BackgroundShellRegistry.php`, `candy-code/src/Cli/Bootstrap.php`, `candy-code/src/Tools/BuiltIn/Bash.php`, `candy-code/src/Tools/BuiltIn/ShellOutput.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Sessions/BackgroundShellRegistryTest.php`, `candy-code/tests/Tools/BashBackgroundTest.php`
- **Docs/guards:** no new proc_open token (the recorders live inside the existing wrapper); re-run `candy-code/tests/Support/DescriptorInheritanceGuardTest.php`
- **Serialized:** [bootstrap:backgroundShells] [hot:candy-code/src/Background/BackgroundShellRegistry.php]
- **Deps:** C-TOOL-1.a (W1)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-7.a`

### W6

- protocol lanes: P-CTX.f, C-AGT-1.b (weld regenerates schema → SERVER.md → generated.ts once)
- Bootstrap.php method claims: P-AGENT.a (workspace); C-TOOL-7.c (lspClient)

#### ✅ e3a84187d P-MENU.c — Menu overlay seam: every frame paints, clamps and scans the open menu
- **Item:** P-MENU-REDO (sources: owner pick; latent bugs 1–3 above) · fix · major · effort M
- **What:** **Root causes.** All three are read from source; the lane's regression tests must go red first. 1. `renderAgentDashboard()` overlays nothing and scans titles only (`src/Tui/Renderer.php:905-927`). Yet F10 …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Tui/Renderer.php`, `candy-code/src/Tui/Components/MenuBar.php`
- **Tests — create:** `candy-code/tests/Tui/MenuOverlayFrameTest.php` · **modify:** `candy-code/tests/Tui/MenuDropdownTest.php`, `candy-code/tests/Tui/MenuBarMouseTest.php`
- **Docs/guards:** none
- **Serialized:** [renderer:Tui/Renderer.php overlayDropdown + scanChrome + the five frame-path tails] [hot:candy-code/src/Tui/Components/MenuBar.php]
- **Deps:** P-IMG.b (W4), P-MENU.a (W5)
- **Brief:** `prompt_kit/findings/comparison-1026/design-menu.md` → `### P-MENU.c`

#### ✅ 953c01c9e P-AGENT.a — Live-agent registry: session scope + preset colour (k1-dorm#3)
- **Item:** P-AGENT-ATTACH + P-SESSION-TABS (sources: k1-dorm#3, GAP-SES-12) · dormant-wire · effort M
- **What:** **Session scope.** `AgentLiveRegistry` is ONE workspace service (`src/Cli/Bootstrap.php:1893`), and `apply()` (`src/Agents/Live/AgentLiveRegistry.php:125`) keeps no session. With several hosted Chats, tab A's strip would list tab …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Agents/Live/AgentLiveState.php`, `candy-code/src/Agents/Live/AgentLiveRegistry.php`, `candy-code/src/Tui/AgentStrip.php`, `candy-code/src/Tui/AgentViewHeader.php`, `candy-code/src/Tui/AgentActivityLine.php`, `candy-code/src/Tui/Components/AgentDashboardPane.php`, `candy-code/src/Support/FrontmatterKeyAudit.php`, `candy-code/src/Cli/Bootstrap.php`, `candy-code/docs/AGENTS_AUTHORING.md`, `candy-code/docs/AGENTS.md`
- **Tests — create:** `candy-code/tests/Agents/Live/AgentLiveSessionScopeTest.php`, `candy-code/tests/Tui/AgentColorPaintTest.php` · **modify:** `candy-code/tests/Agents/Live/AgentLiveRegistryTest.php`, `candy-code/tests/Tui/AgentStripTest.php`, `candy-code/tests/Support/FrontmatterKeyAuditTest.php`
- **Docs/guards:** `docs/AGENTS_AUTHORING.md` `| Field | Effect today |` table, moved in-step with `tests/Config/InertFrontmatterDocumentationDriftTest.php`; `docs/AGENTS.md`
- **Serialized:** [bootstrap:workspace] [docs:AGENTS_AUTHORING.md] [docs:AGENTS.md]
- **Deps:** P-TABS.a (W5)
- **Brief:** `prompt_kit/findings/comparison-1026/design-picks.md` → `### P-AGENT.a`

#### ✅ 940fea719 P-TABS.c — SessionsPane component (vertical `+` / sessions / agents list)
- **Item:** P-SESSION-TABS + P-AGENT-ATTACH (sources: GAP-UI-N6; owner wording "a tab up top to open a new session … tabs for the running sessions under it"; OC2 vertical … · feature · effort M
- **What:** `SessionsPane::renderModel(WindowList, Theme, int width, int rows)` draws the dockable pane form of the window list: row 0 is `＋ New session`; then one row per session tab: attention glyph, name, badge and summary (`⠋ step 4 · 2 …
- **Files — create:** `candy-code/src/Tui/Components/SessionsPane.php`
- **Files — modify:** `candy-code/lang/en.php`
- **Tests — create:** `candy-code/tests/Tui/Components/SessionsPaneTest.php` · **modify:** none
- **Docs/guards:** none
- **Serialized:** [lang] (prefix `tui.sessions.`)
- **Deps:** P-TABS.a (W5)
- **Brief:** `prompt_kit/findings/comparison-1026/design-picks.md` → `### P-TABS.c`

#### ✅ 901f40d9a P-MENU.b — `ShellMenu`: the five-menu tree
- **Item:** P-MENU-REDO (sources: owner pick) · feature · effort S
- **What:** **Builder.** `ShellMenu::tree(?App $a = null, ?list<CommandSpec> $rows = null): MenuTree` builds the tree in "Proposed menu tree" from a nested `PLACEMENT` const keyed by command **name**. Dispatch can then go by name, and a …
- **Files — create:** `candy-code/src/Tui/Menu/ShellMenu.php`
- **Files — modify:** `candy-code/lang/en.php`
- **Tests — create:** `candy-code/tests/Tui/Menu/ShellMenuTest.php` · **modify:** none
- **Docs/guards:** none. `tests/Commands/RegistryTranslationTest.php` does not move, because no category changes.
- **Serialized:** [lang] (prefix `tui.menu.`)
- **Deps:** P-MENU.a (W5)
- **Ruling:** R-MENU-1. Approve the five menus, the placements and hiding bare `/generate` from the menu (default: as drawn). — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-menu.md` → `### P-MENU.b`

#### ✅ a863fea41 P-CTX.f — Protocol: `context.sources` categories
- **Item:** P-CTX-BREAKDOWN (sources: owner pick; PRM-12 web) · feature · effort S
- **What:** `ContextSources::serialize()` (`src/Host/ContextSources.php:217-233`) adds `categories: [{key, label, tokens|null, share, sub:[{label, tokens}]}]` plus `buffer` and `mcpServers`. The `context.sources` breakdown schema …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Host/ContextSources.php`, `candy-code/src/Protocol/Schema/Definitions.php`, `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts`
- **Tests — create:** none · **modify:** `candy-code/tests/Protocol/ContextSourcesTest.php`
- **Docs/guards:** `tools/gen-protocol-schema.php --check`, `npm run gen:protocol`, `tests/Config/ServerProtocolDocumentationDriftTest.php`, `tests/Protocol/ProtocolSchemaDriftTest.php`
- **Serialized:** [protocol] [docs:SERVER.md]
- **Deps:** P-CTX.a (W4)
- **Brief:** `prompt_kit/findings/comparison-1026/design-picks.md` → `### P-CTX.f`

#### ✅ e36d86d84 C-TOOL-7.c — LSP auto-detect project-trust gate
- **Item:** C-TOOL-7 (sources: EDT-2-P1) · fix · MINOR · effort S
- **What:** Root cause `candy-code/src/LSP/LspLauncher.php:107-147` (`AUTO_TABLE`) and `fromConfig()` :172. With user-tier `lspAutoDetect` on, any opened repo's `Cargo.toml` starts rust-analyzer (which runs `build.rs` and proc-macros), and …
- **Files — create:** none
- **Files — modify:** `candy-code/src/LSP/LspLauncher.php`, `candy-code/src/Cli/Bootstrap.php`, `candy-code/lang/en.php`, `candy-code/docs/SETTINGS.md`
- **Tests — create:** none · **modify:** `candy-code/tests/LSP/LspLauncherAutoDetectTest.php`
- **Docs/guards:** `SettingsSchemaDocDriftTest` (help text regen)
- **Serialized:** [bootstrap:lspClient] [lang:settings.lspAutoDetect.] [docs:SETTINGS.md]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-7.c`

#### ✅ 2d45b868f C-AGT-1.b — Fire `/loop` on served sessions
- **Item:** C-AGT-1 (sources: GAP-AUT-08, k3-sum (live defect), k3-dorm#1, p1-miss: schedules UX (`/loop` for served sessions)) · dormant-wire · MAJOR · effort M
- **What:** Root cause: `LoopCommand` registers an ephemeral row (`src/Host/Commands/LoopCommand.php:65-72`), but `fireDue()` skips non-persisted rows (`ScheduleRegistry.php:395`), and the only ephemeral pump is `Chat::pumpSchedules()` …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Background/Schedule.php`, `candy-code/src/Background/ScheduleRegistry.php`, `candy-code/src/Host/Commands/LoopCommand.php`, `candy-code/src/Host/SessionHost.php`, `candy-code/src/Protocol/ServerContext.php`, `candy-code/lang/en.php`, `candy-code/docs/SCHEDULES.md`
- **Tests — create:** `candy-code/tests/Host/SessionHostLoopFireTest.php` · **modify:** `candy-code/tests/Sessions/ScheduleRegistryTest.php`
- **Docs/guards:** `docs/SCHEDULES.md` section. No new fork or child.
- **Serialized:** [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Protocol/ServerContext.php] [lang: `host.loop.*`] [docs:SCHEDULES.md]
- **Deps:** C-AGT-1.a (W1)
- **Brief:** `prompt_kit/findings/comparison-1026/design-agt.md` → `### C-AGT-1.b`

#### ✅ 0c051ee95 C-AGT-3.a — Preset `mcpServers` scopes `mcp_call`/`mcp_list`
- **Item:** C-AGT-3 (sources: GAP-MCP-11, k2-dorm (`setAgentPreset`), k2-sum (MCP-11 ✅→🟡)) · dormant-wire · effort S
- **What:** Today `AgentManager::resolveGrantedTools()` (`src/Agents/AgentManager.php:1496-1580`) filters `McpToolBridge`s by `mcpServers`, but the deferred dispatchers `McpCallTool`/`McpListTool` keep the launch `McpClient`. That client is …
- **Files — create:** none
- **Files — modify:** `candy-code/src/MCP/McpClient.php`, `candy-code/src/MCP/McpToolShape.php`, `candy-code/src/Tools/BuiltIn/McpCallTool.php`, `candy-code/src/Tools/BuiltIn/McpListTool.php`, `candy-code/src/Agents/AgentManager.php`, `candy-code/docs/AGENTS.md`
- **Tests — create:** `candy-code/tests/Agents/McpDispatcherPresetScopeTest.php` · **modify:** `candy-code/tests/MCP/McpToolShapeTest.php`
- **Docs/guards:** `docs/AGENTS.md`. No roster guard moves.
- **Serialized:** [hot:candy-code/src/Agents/AgentManager.php] [docs:AGENTS.md]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-agt.md` → `### C-AGT-3.a`

#### ✅ 020772dca C-CTX-2.b — Extract the turn-settle side work into one host-neutral gate
- **Item:** C-CTX-2 (sources: GAP-MEM-02w, k1-sum (b), k1-dorm#6) · dormant-wire · MAJOR · effort M
- **What:** Today, auto-memory, the dream pass, prompt suggestion and auto-commit are gated only in `candy-code/src/Chat/Handlers/HandlesTurnCompletion.php:304-336`. Auto-commit's body lives in `Chat::scheduleAutoCommit()` …
- **Files — create:** `candy-code/src/Host/TurnSettleWork.php`, `candy-code/tests/Host/TurnSettleWorkTest.php`
- **Files — modify:** `candy-code/src/Workspace/AutoCommitter.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Workspace/AutoCommitterTest.php`
- **Docs/guards:** none
- **Serialized:** none
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-CTX-2.b`

#### ✅ 376e9d0f1 C-EXT-2.a — Skill resources: `asset` loads, resource listing, `${CLAUDE_SKILL_DIR}`
- **Item:** C-EXT-2 (sources: GAP-SKL-10, k2-dorm `loadSkillAsset`) · dormant-wire · effort M
- **What:** **What's dormant.** `SkillLoader::loadSkillAsset()` (`SkillLoader.php:1054`, containment-checked through `ContainedPath::within`) has no caller. Read is jailed to the project root, so a user-tier skill's `scripts/` and …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Tools/BuiltIn/SkillTool.php`, `candy-code/src/Skills/SkillLoader.php`, `candy-code/docs/SKILLS.md`
- **Tests — create:** `candy-code/tests/Tools/BuiltIn/SkillToolAssetTest.php` · **modify:** `candy-code/tests/Tools/BuiltIn/SkillToolTest.php`, `candy-code/tests/Skills/SkillLoaderTest.php`
- **Docs/guards:** SKILLS.md. Re-pin any tool-schema byte figure in `tests/Providers/PromptStabilityTest.php` if the Skill schema moves it. The gen-tool-docs roster renders name and gloss only, so it does not move. …
- **Serialized:** [docs:SKILLS.md]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-ext.md` → `### C-EXT-2.a`

### W7

- protocol lanes: P-AGENT.c (weld regenerates schema → SERVER.md → generated.ts once)
- Chat.php region claims: P-CTX.e (handlePointer); C-AGT-7.a (subscriptions); C-AGT-2.b (applyCommandResult (none — the restore lives in the settle trait), ctor, reviewTurn, togglePermissionMode)
- Bootstrap.php method claims: C-SEC-5.a (new methods beside trustProjectMcp)

#### ✅ 66559688e P-CTX.e — Shell wiring: docked Context pane + clickable status-bar meter
- **Item:** P-CTX-BREAKDOWN (sources: owner pick) · feature · effort M
- **What:** **The pane.** Add `Pane::Context` (dockable, icon `◔`, `tabCycle()`), a `MenuBar::PANE_TABS` entry, and a `Tui\Renderer::renderPane()` arm (`src/Tui/Renderer.php:1944`) through `keyed()`. `ContextPane::render(App …)` builds the …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Tui/Pane.php`, `candy-code/src/Tui/Components/MenuBar.php`, `candy-code/src/Tui/Components/ContextPane.php`, `candy-code/src/Tui/Renderer.php`, `candy-code/src/Renderer.php`, `candy-code/src/Chat.php`, `candy-code/src/App/App.php`, `candy-code/src/Tui/KeyboardHandler.php`, `candy-code/src/Commands/KeyBindingRegistry.php`, `candy-code/lang/en.php`, `candy-code/README.md`
- **Tests — create:** `candy-code/tests/Tui/ContextPaneShellTest.php` · **modify:** `candy-code/tests/Tui/PaneTest.php`, `candy-code/tests/Tui/MenuBarCompactStripTest.php`, `candy-code/tests/Commands/KeyBindingDriftTest.php`
- **Docs/guards:** `README.md`; `tests/Commands/KeyBindingDriftTest.php` moved in-step
- **Serialized:** [renderer:Tui/Renderer.php renderPane; Renderer.php renderStatusBar] (layout #2 of 3) [chat:handlePointer] [lang] (prefix `tui.pane.title.context`, `keys.context.`) [docs:README.md] …
- **Deps:** P-IMG.b (W4), P-CTX.c (W5), P-CTX.d (W5)
- **Ruling:** auto-dock the Context pane once on ≥120-col terminals after the first reply (proposed), or leave it off until the user docks it? — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-picks.md` → `### P-CTX.e`
- **Note (2026-10-27):** auto-dock ≥120 honoured (R-MENU family, decided ON); first-dock fires once per process after the ≥80 pane floor lands the pane at ~160 cols; refusal retries on later resize.

#### ✅ e68d0e69b P-AGENT.c — Protocol: `agents.list` colour + `agents.message` mode
- **Item:** P-AGENT-ATTACH (sources: k1-dorm#3, CLAW-new#7) · feature · effort S
- **What:** `agents.list` items gain an optional `color` (the preset's, resolved through `AgentColor` to a hex or omitted). `agents.message` gains an optional `mode: steer|interrupt` (default steer). `AgentsMethods` passes it to …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Protocol/Methods/AgentsMethods.php`, `candy-code/src/Protocol/Schema/MethodSchemas.php`, `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts`
- **Tests — create:** `candy-code/tests/Server/AgentsColorAndInterruptTest.php` · **modify:** none
- **Docs/guards:** the gen `--check`s; `tests/Config/ServerProtocolDocumentationDriftTest.php`
- **Serialized:** [protocol] [docs:SERVER.md]
- **Deps:** P-AGENT.a (W6)
- **Brief:** `prompt_kit/findings/comparison-1026/design-picks.md` → `### P-AGENT.c`

#### ✅ 1ce007b2d P-CTX.g — Web: context meter + category breakdown + cache %
- **Item:** P-CTX-BREAKDOWN (sources: PRM-12 web; GAP-MOD-15w cache half; MOD-19 web; MOD-N3 web) · feature · effort M
- **What:** `ContextPanel.vue` gets the same model as the TUI box: a stacked category bar; category rows with expandable sub-rows (layers, files, MCP servers, largest messages); the "not sent" MCP-instructions row; a hint naming `/context …
- **Files — create:** none
- **Files — modify:** `candy-code-web/src-web/components/panels/ContextPanel.vue`, `candy-code-web/src-web/components/StatusBar.vue`, `candy-code-web/src-web/stores/contextSources.ts`
- **Tests — create:** none · **modify:** `candy-code-web/src-web/components/panels/__tests__/contextPanel.spec.ts`, `candy-code-web/src-web/components/__tests__/statusBar.spec.ts`
- **Docs/guards:** web unit tests
- **Serialized:** [hot:candy-code-web/src-web/components/StatusBar.vue] [hot:candy-code-web/src-web/stores/contextSources.ts]
- **Deps:** P-CTX.f (W6)
- **Brief:** `prompt_kit/findings/comparison-1026/design-picks.md` → `### P-CTX.g`

#### ✅ f2db15f0a C-AGT-7.a — Schedule hygiene: watch tick, bypass ceiling, run limit
- **Item:** C-AGT-7 (sources: AUT-1-P2, AUT-1-P3, AUT-1-P4) · fix · MINOR/NIT · effort M
- **What:** P2: `Chat::subscriptions()` arms the 2 s tick only when a row is enabled or a daemon is active (`src/Chat.php:18118-18128`), so a row hand-written after an empty launch never fires in that TUI. Add a slow 30 s watch tick, armed …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Chat.php`, `candy-code/src/Background/Schedule.php`, `candy-code/src/Background/ScheduleRegistry.php`, `candy-code/src/Background/ScheduleSpawner.php`, `candy-code/lang/en.php`, `candy-code/docs/SCHEDULES.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Chat/ScheduleLoopFireTest.php`, `candy-code/tests/Sessions/ScheduleTest.php`, `candy-code/tests/Sessions/ScheduleRegistryTest.php`
- **Docs/guards:** `docs/SCHEDULES.md`; the `ForeignAgentPresetWiringTest` permissionMode census rows for `Schedule.php`/`ScheduleRegistry.php` already exist (the comment widens if the rationale changes)
- **Serialized:** [chat:subscriptions] [lang: `host.schedules.*`] [docs:SCHEDULES.md]
- **Deps:** C-AGT-1.a (W1), C-AGT-1.b (W6)
- **Brief:** `prompt_kit/findings/comparison-1026/design-agt.md` → `### C-AGT-7.a`

#### ✅ bb1f488d1 C-AGT-2.b — Review turns run read-only (plan clamp)
- **Item:** C-AGT-2 (sources: GIT-1-P2) · fix · MINOR · effort M
- **What:** Root cause: the "report, do not edit" mandate is prompt prose. The review turn runs under whatever mode the session holds (`src/Chat.php:11798` plain `submit()`), and the only test pins docblock text …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Chat.php`, `candy-code/src/Chat/Handlers/HandlesTurnCompletion.php`, `candy-code/src/Host/SubmitOptions.php`, `candy-code/src/Host/SessionHost.php`, `candy-code/src/Commands/ReviewCommand.php`, `candy-code/lang/en.php`
- **Tests — create:** `candy-code/tests/Host/SessionHostModeCeilingTest.php` · **modify:** `candy-code/tests/Chat/ReviewCommandTest.php`
- **Docs/guards:** none (no key, setting or command roster moves)
- **Serialized:** [chat:reviewTurn, togglePermissionMode, applyCommandResult (none — the restore lives in the settle trait),ctor] [hot:candy-code/src/Chat/Handlers/HandlesTurnCompletion.php] …
- **Deps:** C-AGT-2.a (W1)
- **Brief:** `prompt_kit/findings/comparison-1026/design-agt.md` → `### C-AGT-2.b`

#### ✅ 09da7d3e9 C-AGT-3.b — Per-agent memory from the preset `memory:` key
- **Item:** C-AGT-3 (sources: GAP-MEM-13, k1-dorm#2) · dormant-wire · effort M
- **What:** Today `memory:` is parsed onto `AgentPreset`/`Agent` and read by nothing (`src/Support/FrontmatterKeyAudit.php:122`, `INERT[AGENT]`). It also defaults to `User` when absent (`AgentPresetRegistry.php:608`, `Agent.php:104,176`), so …
- **Files — create:** `candy-code/src/Memory/AgentMemory.php`
- **Files — modify:** `candy-code/src/Agents/AgentPreset.php`, `candy-code/src/Agents/Agent.php`, `candy-code/src/Agents/AgentPresetRegistry.php`, `candy-code/src/Agents/ForeignAgentPresetRegistry.php`, `candy-code/src/Memory/MemoryWriter.php`, `candy-code/src/Tools/BuiltIn/MemoryTool.php`, `candy-code/src/Context/MemoryBlock.php`, `candy-code/src/Agents/AgentManager.php`, `candy-code/src/Tools/BuiltIn/TaskTool.php`, `candy-code/src/Support/FrontmatterKeyAudit.php`, `candy-code/docs/AGENTS_AUTHORING.md`, `candy-code/docs/MEMORY.md`
- **Tests — create:** `candy-code/tests/Memory/AgentMemoryTest.php`, `candy-code/tests/Tools/TaskToolAgentMemoryTest.php` · **modify:** `candy-code/tests/Agents/AgentPresetRegistryTest.php`, `candy-code/tests/Integration/ForeignAgentPresetWiringTest.php`
- **Docs/guards:** `candy-code/tests/Config/InertFrontmatterDocumentationDriftTest.php` (moves: `memory` leaves the inert roster), `candy-code/tests/Config/MemoryDocumentationDriftTest.php` (`docs/MEMORY.md`), …
- **Serialized:** [hot:candy-code/src/Agents/AgentManager.php] [hot:candy-code/src/Tools/BuiltIn/TaskTool.php] [docs:AGENTS_AUTHORING.md] [docs:MEMORY.md]
- **Deps:** C-AGT-3.a (W6)
- **Brief:** `prompt_kit/findings/comparison-1026/design-agt.md` → `### C-AGT-3.b`

#### ✅ 066d6e8ce C-EXT-7.b — `bundles install`: refuse dot names, strip `.git` after clone
- **Item:** C-EXT-7 (sources: PLG-1-P5) · fix · NIT · effort S
- **What:** `Subcommands.php:1692` accepts a leading-dot manifest name, which `BundleDiscovery::scanContainer`'s glob never finds, so the bundle installs invisibly. The git arm keeps `.git` while the path arm strips it (docblock :1606-1612). …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Cli/Subcommands.php`, `candy-code/lang/en.php`, `candy-code/docs/PLUGINS.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Bundles/BundleInstallTest.php`
- **Docs/guards:** PLUGINS.md. No new spawn site.
- **Serialized:** [headless] [docs:PLUGINS.md] [lang:cli.bundles.install.]
- **Deps:** C-SEC-1.a (W1)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ext.md` → `### C-EXT-7.b`

#### ✅ b20852ec8 C-SEC-5.a — Trust-target census and one shared grant door
- **Item:** C-SEC-5 (sources: GAP-PER-11, GAP-MCP-N1, k3-dorm#5) · dormant-wire · effort M
- **What:** The four trust lists exist (`PermissionSettings.php:134-152`, `Bootstrap.php:153/172/187`, `LayeredSettings::PROJECT_SETTINGS_TRUST_KEY`), and so do two writers: `SettingsWriter::grantTrust($key, $root)` …
- **Files — create:** `candy-code/src/Config/Trust/TrustTarget.php`, `candy-code/src/Config/Trust/ProjectTrustCensus.php`
- **Files — modify:** `candy-code/src/Cli/Bootstrap.php`, `candy-code/src/Cli/McpDecision.php`, `candy-code/lang/en.php`
- **Tests — create:** `candy-code/tests/Config/Trust/ProjectTrustCensusTest.php`, `candy-code/tests/Cli/BootstrapProjectTrustGrantTest.php` · **modify:** none
- **Docs/guards:** none in this step; the user-visible docs land with 5.b and 5.d. The writes go through the existing `SettingsWriter`/`writeUserConfig` doors, not Chat's `$onConfigChange`, so …
- **Serialized:** [bootstrap:new methods beside trustProjectMcp] [lang:server.trust.ask.]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-sec.md` → `### C-SEC-5.a`

#### ✅ 60bc8f0a4 C-SES-6.a — A phar renamed without `.phar` boots
- **Item:** C-SES-6 (sources: DST-1-P1) · fix · MINOR · effort M
- **What:** **Root cause.** The stub derives the archive identity only from `Phar::running(false)`, which is `''` in stub context, or from a `.phar` suffix on `__FILE__` (`candy-code/bin/candy-code:32-37`). The usual `~/bin/candy-code` …
- **Files — create:** none
- **Files — modify:** `candy-code/README.md`, `candy-code/tests/Integration/BinCandyCodeAutoloadGuardTest.php`, `candy-code/tests/Integration/PharCompatTest.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Integration/PharCompatTest.php`, `candy-code/tests/Integration/BinCandyCodeAutoloadGuardTest.php`
- **Docs/guards:** README Install
- **Serialized:** [headless] [docs:README.md]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-6.a`

#### ✅ 98b862e9f C-SES-9.a — Ledger: no resurrection, `ts` index, one-snapshot rollups
- **Item:** C-SES-9 (sources: SES-1-P5, SES-1-P6, OBS-3-P2) · fix · MINOR/NIT · effort S
- **What:** **SES-1-P5.** `appendUsageRecord()` recreates a missing row as `candy-code/unknown` (`src/Session/EnhancedSessionStore.php:2436-2438`), so a late settle resurrects a deleted session. The recreate exists on purpose, for the lazy …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Session/SessionStore.php`, `candy-code/src/Session/EnhancedSessionStore.php`, `candy-code/src/Host/Commands/UsageHostCommand.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Session/UsageRecordsTest.php`
- **Docs/guards:** none
- **Serialized:** none (hot files: `EnhancedSessionStore.php`, `SessionStore.php`, `UsageHostCommand.php`)
- **Deps:** C-SES-3.a (W1)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-9.a`

### W8

- Chat.php region claims: C-EXT-2.b (gateToolCall); C-SES-4.b (subscriptions)

#### ✅ 65335c6c4 P-MENU.d — Swap the bar to the five-menu tree: keyboard, click, dispatch, `»` overflow
- **Item:** P-MENU-REDO (sources: owner pick; supersedes P-IMG.a; latent bug 4) · feature · effort M
- **What:** **MenuBar.** `menus()` reads the `ShellMenu::tree()` titles. The static `$activeMenu`/`$activeItem` pair becomes one static `MenuCursor`. `handleKey()` is `MenuCursor::key()`, and `selectItem()` takes a path (an int still means a …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Tui/Components/MenuBar.php`, `candy-code/src/Tui/Components/MenuSelectedMsg.php`, `candy-code/src/App/App.php`, `candy-code/src/Tui/KeyboardHandler.php`, `candy-code/src/Commands/KeyBindingRegistry.php`, `candy-code/lang/en.php`, `candy-code/README.md`, `candy-code/docs/MEDIA.md`
- **Tests — create:** `candy-code/tests/Tui/MenuReachabilityTest.php`, `candy-code/tests/Tui/MenuKeyboardOwnershipTest.php`, `candy-code/tests/App/MenuActionDispatchTest.php` · **modify:** `candy-code/tests/Tui/MenuBarTest.php`, `candy-code/tests/Tui/MenuOpenTest.php`, `candy-code/tests/Tui/MenuDropdownTest.php`, `candy-code/tests/Tui/MenuBarMouseTest.php`, `candy-code/tests/Tui/TuiComponentTest.php`, `candy-code/tests/Tui/ShellContrastTest.php`, `candy-code/tests/Commands/KeyBindingDriftTest.php`
- **Docs/guards:** README.md and docs/MEDIA.md prose. There is no generated roster: `docs/COMMANDS.md` does not render categories (`CommandDocGenerator.php:107-118`). `KeyBindingDriftTest` moves in-step with the …
- **Serialized:** [lang] (prefix `keys.menu.`, `tui.menu.more`) [docs:README.md] [docs:MEDIA.md] [hot:candy-code/src/App/App.php] [hot:candy-code/src/Tui/Components/MenuBar.php] …
- **Deps:** P-MENU.b (W6), P-MENU.c (W6)
- **Brief:** `prompt_kit/findings/comparison-1026/design-menu.md` → `### P-MENU.d`

#### ✅ ceed81ef0 P-AGENT.d — Web agents parity: colours, interrupt send, running-agents line (CLAW-new#7)
- **Item:** P-AGENT-ATTACH (sources: CLAW-new#7, k1-dorm#3) · feature · effort M
- **What:** `agents.ts` keeps the catalogue's colours by name and `message(sessionId, id, text, mode)`. `SubAgentTree` paints a colour dot per run. `AgentView` gets an "interrupt" send (a toggle, plus Ctrl+Enter) with the same `⚡`/`⧗` row …
- **Files — create:** `candy-code-web/src-web/components/agents/RunningAgentsLine.vue`
- **Files — modify:** `candy-code-web/src-web/stores/agents/agents.ts`, `candy-code-web/src-web/components/agents/SubAgentTree.vue`, `candy-code-web/src-web/components/agents/AgentView.vue`, `candy-code-web/src-web/views/SessionView.vue`
- **Tests — create:** `candy-code-web/src-web/components/agents/__tests__/agentsParity.spec.ts` · **modify:** none
- **Docs/guards:** web unit tests
- **Serialized:** [hot:candy-code-web/src-web/views/SessionView.vue]
- **Deps:** P-AGENT.c (W7)
- **Brief:** `prompt_kit/findings/comparison-1026/design-picks.md` → `### P-AGENT.d`

#### ✅ 6ce6df1f0 C-CTX-2.a — Drain the runtime-notice sink in serve
- **Item:** C-CTX-2 (sources: GAP-MEM-10w, k1-sum (a), k1-dorm#1, MOD-14 web) · dormant-wire · MAJOR · effort S
- **What:** Root cause: serve arms the process sink in `Bootstrap::workspace()` (`candy-code/src/Cli/Bootstrap.php:1678-1679`), but the only `drain()` is `Chat::pumpRuntimeNotices()` (`candy-code/src/Chat.php:18636`). On served sessions, …
- **Files — create:** `candy-code/tests/Host/ServedRuntimeNoticeDrainTest.php`
- **Files — modify:** `candy-code/src/Host/SessionHost.php`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** `candy-code/docs/SERVER.md` (### Events): notice rows on served sessions; `candy-code/docs/MEMORY.md` L455-458: the recall row is drained by `Chat::pumpRuntimeNotices()` and, on serve, by …
- **Serialized:** [docs:SERVER.md] [docs:MEMORY.md] [hot:candy-code/src/Host/SessionHost.php]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-CTX-2.a`

#### ✅ 4c1e8bb82 C-EXT-2.b — Honour skill `allowed-tools`/`disallowed-tools`/`model`/`effort` for the active turn
- **Item:** C-EXT-2 (sources: GAP-SKL-11, k2-dorm inert frontmatter, k1-dorm#8) · dormant-wire · effort M (upper)
- **What:** **What's inert.** `FrontmatterKeyAudit.php:120-135` lists four skill keys as INERT; nothing on a live path reads them. Two traps: `Skill::$effort` defaults to `'medium'` when absent (`Skill.php:24`), so "declared" cannot be told …
- **Files — create:** `candy-code/src/Skills/ActiveSkillEffects.php`
- **Files — modify:** `candy-code/src/Permissions/PermissionGate.php`, `candy-code/src/Runtime.php`, `candy-code/src/Chat.php`, `candy-code/src/Skills/Skill.php`, `candy-code/src/Skills/SkillFrontmatter.php`, `candy-code/src/Support/FrontmatterKeyAudit.php`, `candy-code/docs/SKILLS.md`, `candy-code/README.md`, `candy-code/src/Skills/BuiltIn/api-design/SKILL.md`, `candy-code/src/Skills/BuiltIn/composer-wizard/SKILL.md`, `candy-code/src/Skills/BuiltIn/laravel-best-practices/SKILL.md`, `candy-code/src/Skills/BuiltIn/php-best-practices/SKILL.md`, `candy-code/src/Skills/BuiltIn/phpunit-master/SKILL.md`, `candy-code/src/Skills/BuiltIn/security-audit/SKILL.md`, `candy-code/src/Skills/BuiltIn/symfony-best-practices/SKILL.md`, `candy-code/src/Skills/BuiltIn/testing-strategies/SKILL.md`
- **Tests — create:** `candy-code/tests/Skills/ActiveSkillEffectsTest.php`, `candy-code/tests/Permissions/PermissionGateTurnGrantsTest.php`, `candy-code/tests/Runtime/RuntimeSkillEffectsTest.php` · **modify:** `candy-code/tests/Support/FrontmatterKeyAuditTest.php`, `candy-code/tests/Config/InertFrontmatterDocumentationDriftTest.php`, `candy-code/tests/Skills/BuiltInSkillsTest.php`, `candy-code/tests/Skills/SkillFrontmatterTest.php`
- **Docs/guards:** the INERT ↔ SKILLS.md guard moves in-step. README :2370.
- **Serialized:** [runtime:gate,run step loop] [chat:gateToolCall] [docs:SKILLS.md] [docs:README.md] [hot:candy-code/src/Permissions/PermissionGate.php] [hot:candy-code/src/Support/FrontmatterKeyAudit.php]
- **Deps:** none
- **Ruling:** R-EXT-1. Does `allowed-tools` pre-approval apply only to user-tier skills and only to argument-scoped rules (never bare `Bash`/`Write`), with project, bundle … — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-ext.md` → `### C-EXT-2.b`

#### ✅ a3bf74a61 C-SES-4.b — Non-blocking export + wired `tick()` retry
- **Item:** C-SES-4 (sources: OBS-1-P2, OBS-1-P3) · fix · MINOR · effort M
- **What:** **Root cause.** POSTs are synchronous Guzzle calls, up to `CANDY_CODE_OTEL_TIMEOUT` each (`OtelSink.php:48-58`), made inside `consume()` on the TUI `update()` path. The `tick()` retry arm (`BatchEventSink.php:77-86`) has no …
- **Files — create:** `candy-code/src/Observability/RetryingEventSink.php`, `candy-code/src/Observability/AsyncOtlpTransport.php`, `candy-code/src/App/TelemetryRetryTickMsg.php`
- **Files — modify:** `candy-code/src/Observability/BatchEventSink.php`, `candy-code/src/Observability/OtelSink.php`, `candy-code/src/Chat.php`, `candy-code/src/Chat/Handlers/HandlesSessionSurface.php`, `candy-code/docs/ENVIRONMENT.md`
- **Tests — create:** `candy-code/tests/Observability/AsyncOtlpTransportTest.php`, `candy-code/tests/Observability/ChatTelemetryRetryTickTest.php` · **modify:** `candy-code/tests/Observability/BatchEventSinkTest.php`
- **Docs/guards:** the `docs/ENVIRONMENT.md` rows are prose only, with no new variable (`EnvRosterDriftTest` is unaffected)
- **Serialized:** [chat:subscriptions] [hot:candy-code/src/Chat/Handlers/HandlesSessionSurface.php] [docs:ENVIRONMENT.md]
- **Deps:** C-SES-4.a (W2)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-4.b`

#### ✅ 5badf6d2f C-SES-9.c — Phar installs ship bundled presets; build-phar docblocks truthful
- **Item:** C-SES-9 (sources: DST-1-P2, DST-1-P3) · dormant-wire · MINOR · effort S
- **What:** **Root cause.** `AgentPresetRegistry::bundledPresetsDirectory()` calls `realpath()` on a `phar://` path, which returns false, so the method answers null (`src/Agents/AgentPresetRegistry.php:390-395`). `isBundledPresetPath()` …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Agents/AgentPresetRegistry.php`, `candy-code/tools/build-phar.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Agents/AgentPresetRegistryTest.php`
- **Docs/guards:** none
- **Serialized:** none
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-9.c`

#### ✅ fc5523777 C-SES-9.d — Release workflows pass tags/inputs via `env:`
- **Item:** C-SES-9 (sources: DST-1-P4) · fix · NIT · effort S
- **What:** **Root cause.** `${{ github.event.inputs.tag }}` and `${{ steps.*.outputs.* }}` are interpolated straight into `run:` scripts: `release-phar.yml:50-51`, `:62`, `:88`, `:91`, `:206`; `release-app-phar.yml:64-65`, `:97`, `:114`, …
- **Files — create:** `tools/tests/WorkflowRunInterpolationTest.php`
- **Files — modify:** `.github/workflows/release-phar.yml`, `.github/workflows/release-app-phar.yml`, `.github/workflows/release-candy-top-phar.yml`
- **Tests — create:** `tools/tests/WorkflowRunInterpolationTest.php` · **modify:** none
- **Docs/guards:** none
- **Serialized:** [workflows]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-9.d`

#### ✅ e651724ca C-WEB-3.a — Memory History & Undo (`memory.log`/`memory.undo`)
- **Item:** C-WEB-3 (sources: k1-dorm#4, k3-dorm#3, WEB-1-P1, p2-miss: WEB-1 residual dormant methods) · dormant-wire · S
- **What:** **Gap.** `memory.log` and `memory.undo` (`candy-code/src/Protocol/MethodSchemas`/`MemoryMethods.php:70-71`) have no browser caller. **Store.** `stores/agents/memory.ts` gains `log(limit)` and `undo(revision?)`. **Panel.** …
- **Files — create:** `candy-code-web/src-web/components/panels/__tests__/memoryHistory.spec.ts`
- **Files — modify:** `candy-code-web/src-web/stores/agents/memory.ts`, `candy-code-web/src-web/components/panels/MemoryPanel.vue`
- **Tests — create:** `candy-code-web/src-web/components/panels/__tests__/memoryHistory.spec.ts` · **modify:** none
- **Docs/guards:** `candy-code-web/README.md` side-panel bullet (soft, see Notes); vitest; dist rebuild.
- **Serialized:** none
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-web.md` → `### C-WEB-3.a`

#### ✅ bb8878195 C-WEB-3.b — Files panel + read-only file viewer (`files.read`)
- **Item:** C-WEB-3 (sources: k3-dorm#3 (`files.read`), p2-miss: WEB-8 Files panel, WEB-1-P1) · dormant-wire · M
- **What:** **FilesPanel.** A new Files side panel: a search box over the existing `fs.search`, through `stores/session.ts searchFiles()`; empty lists the front of the project. Clicking a row opens a shared `FileViewer`. **FileViewer.** Uses …
- **Files — create:** `candy-code-web/src-web/components/panels/FilesPanel.vue`, `candy-code-web/src-web/components/FileViewer.vue`, `candy-code-web/src-web/components/panels/__tests__/filesPanel.spec.ts`
- **Files — modify:** `candy-code-web/src-web/stores/agents/panels.ts`, `candy-code-web/src-web/components/panels/SidePanels.vue`, `candy-code-web/src-web/components/panels/ChangesPanel.vue`
- **Tests — create:** `candy-code-web/src-web/components/panels/__tests__/filesPanel.spec.ts` · **modify:** `candy-code-web/src-web/components/panels/__tests__/changesPanel.spec.ts`
- **Docs/guards:** `candy-code-web/README.md` ("ten" side panels → eleven; soft); vitest; dist rebuild.
- **Serialized:** [hot:candy-code-web/src-web/components/panels/SidePanels.vue] [hot:candy-code-web/src-web/stores/agents/panels.ts] [hot:candy-code-web/src-web/components/panels/ChangesPanel.vue]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-web.md` → `### C-WEB-3.b`

#### ✅ 5a9b2f2c6 C-WEB-3.c — Live workflow run status (`workflow.status`)
- **Item:** C-WEB-3 (sources: k3-dorm#3 (`workflow.status`), WEB-1-P1) · dormant-wire · S
- **What:** **Gap.** `workflow.status` (`WorkflowMethods.php:59`) has no caller. A run row's status comes from the transcript-derived `workflow.runs`, so it goes stale while a run is live. **Fix.** `stores/agents/workflows.ts` gains …
- **Files — create:** `candy-code-web/src-web/components/panels/__tests__/workflowStatus.spec.ts`
- **Files — modify:** `candy-code-web/src-web/stores/agents/workflows.ts`, `candy-code-web/src-web/components/panels/WorkflowPanel.vue`
- **Tests — create:** `candy-code-web/src-web/components/panels/__tests__/workflowStatus.spec.ts` · **modify:** none
- **Docs/guards:** vitest; dist rebuild.
- **Serialized:** none
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-web.md` → `### C-WEB-3.c`

### W9

- Chat.php region claims: C-CTX-6.d (memoryOpenArm)

#### ✅ 6ab223f36 C-SES-5.c — Workflows: release pipelines sign the sidecar
- **Item:** C-SES-5 (sources: DST-3-P1 (signing half), DST-3-P5 (release-note verify line)) · fix · MAJOR · effort S
- **What:** **The added signature.** In all three phar pipelines, move the checksum step BEFORE signing. Signing then additionally signs the `.sha256` sidecar's body into `<app>.phar.sha256.sig`, which is uploaded as an asset. **Additive.** …
- **Files — create:** none
- **Files — modify:** `.github/workflows/release-phar.yml`, `.github/workflows/phar-dev.yml`, `.github/workflows/release-app-phar.yml`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** none
- **Serialized:** [workflows]
- **Deps:** C-SES-9.d (W8)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-5.c`

#### ✅ 255c1599a P-MENU.e — Pane toggles leave the bar: View ▸ Panes owns `panetab:`, hover, indicator door
- **Item:** P-MENU-REDO (sources: owner pick) · feature · effort M
- **What:** **The bar.** `compose()` drops the pane-tab strip and its three densities (`MenuBar.php:94-114`, :220-288). The right end keeps only the `Currently: <pane>` indicator (R-MENU-2). It goes terse (label only) below 61 cols and …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Tui/Components/MenuBar.php`, `candy-code/src/Tui/Menu/ShellMenu.php`, `candy-code/src/App/App.php`, `candy-code/README.md`, `candy-code/docs/ARCHITECTURE.md`
- **Tests — create:** `candy-code/tests/Tui/MenuHoverTest.php`, `candy-code/tests/App/PanesSubmenuToggleTest.php` · **modify:** `candy-code/tests/Tui/MenuBarCompactStripTest.php`, `candy-code/tests/App/MenuBarPaneTabClickTest.php`, `candy-code/tests/App/DockDragBytePathTest.php`, `candy-code/tests/MousePaneJumpDoesNotHandOverTest.php`, `candy-code/tests/Tui/ComponentTest.php`, `candy-code/tests/Tui/TodoPaneTest.php`, `candy-code/tests/Tui/Components/SettingsPaneTest.php`, `candy-code/tests/Tui/PaneReverseCycleTest.php`
- **Docs/guards:** README.md and docs/ARCHITECTURE.md prose. No generated roster moves. The `mouse.*` key rows do not change, so `KeyBindingDriftTest` does not move.
- **Serialized:** [docs:README.md] [docs:ARCHITECTURE.md] [hot:candy-code/src/App/App.php] [hot:candy-code/src/Tui/Components/MenuBar.php]
- **Deps:** P-MENU.d (W8)
- **Ruling:** R-MENU-2 (keep the `Currently:` indicator as a door to View ▸ Panes; default yes) and R-MENU-3 (the submenu stays open after a toggle; default yes) — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-menu.md` → `### P-MENU.e`

#### ✅ b4d34cecd C-CTX-2.c — Run auto-memory, dream pass and auto-commit on served settles
- **Item:** C-CTX-2 (sources: GAP-MEM-02w, k1-dorm#6) · dormant-wire · MAJOR · effort M
- **What:** In `SessionHost::settleWith()`, after `releaseQueue()` and the goal decision, call `TurnSettleWork::plan()` with the host's state: `$this->turn !== null` means the turn carried on; `workspace->memoryStore`, `summaryBackend` and …
- **Files — create:** `candy-code/tests/Host/SessionHostTurnSettleWorkTest.php`
- **Files — modify:** `candy-code/src/Host/SessionHost.php`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** `candy-code/docs/MEMORY.md`: Auto-memory and Dream pass now say they run on TUI and served sessions; `candy-code/docs/SERVER.md` (### Sub-agents, workflows, memory and todos); keep …
- **Serialized:** [docs:MEMORY.md] [docs:SERVER.md] [hot:candy-code/src/Host/SessionHost.php]
- **Deps:** C-CTX-2.b (W6), C-CTX-2.a (W8)
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-CTX-2.c`

#### ✅ f566243c6 C-WEB-3.d — Close a non-primary root's host (`workspace.close`)
- **Item:** C-WEB-3 (sources: k3-dorm#3 (`workspace.close`), WEB-1-P1) · dormant-wire · S
- **What:** **Gap.** `workspace.close {root, force?}` (`WorkspaceMethods.php:37`) has no caller. The gateway's idle recycle is today's only release. **Menu item.** Rows whose `summary.root` is not the server root (`SessionSidebar.vue …
- **Files — create:** `candy-code-web/src-web/components/__tests__/sidebarCloseRoot.spec.ts`
- **Files — modify:** `candy-code-web/src-web/stores/sessions.ts`, `candy-code-web/src-web/components/SessionSidebar.vue`
- **Tests — create:** `candy-code-web/src-web/components/__tests__/sidebarCloseRoot.spec.ts` · **modify:** none
- **Docs/guards:** `candy-code-web/README.md` sessions-sidebar bullet (soft); vitest; dist rebuild.
- **Serialized:** [hot:candy-code-web/src-web/components/SessionSidebar.vue] [hot:candy-code-web/src-web/stores/sessions.ts]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-web.md` → `### C-WEB-3.d`

#### ✅ 75853c72e C-AGT-7.c — Webhook mode clamp + hygiene
- **Item:** C-AGT-7 (sources: AUT-6-P2, AUT-6-P3) · fix · MINOR/NIT · effort S
- **What:** AUT-6-P2: `answersAsksWithoutAHuman()` (`src/Server/ServerConfig.php:574`) classes the strictest mode, `dont-ask`, with bypass. Hooks refuse it (:753), and `hookPermissionModeFallback()` (:592) widens a dont-ask server's hooks to …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Server/ServerConfig.php`, `candy-code/src/Server/Http/HookWebhooks.php`, `candy-code/lang/en.php`, `candy-code/docs/SERVER.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Server/ServerConfigHookModeInheritanceTest.php`, `candy-code/tests/Server/ServerConfigWebhooksTest.php`, `candy-code/tests/Server/HookWebhooksTest.php`
- **Docs/guards:** `docs/SERVER.md`
- **Serialized:** [hot:candy-code/src/Server/Http/HookWebhooks.php] [hot:candy-code/src/Server/ServerConfig.php] [lang: `settings.webhooks.*`] [docs:SERVER.md]
- **Deps:** C-AGT-1.c (W1)
- **Brief:** `prompt_kit/findings/comparison-1026/design-agt.md` → `### C-AGT-7.c`

#### ✅ 16266186d C-CTX-6.c — Context nit bundle
- **Item:** C-CTX-6 (sources: VIS-6-P1, k1-dorm#7, INT-1-P1, CTX-5-P2, p2-miss: TUI-6 English plural) · fix · MINOR/NIT · effort S
- **What:** **VIS-6-P1:** `Runtime::announceMemoryRecall()` (`Runtime.php:5991-5994`) injects the English plural as a parameter. Split `chat.memory.recalled_notice` (`lang/en.php:1898`) into `.one`/`.many` keys. **k1-dorm#7:** truth-flip the …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Runtime.php`, `candy-code/lang/en.php`, `candy-code/builtin-commands/3400-goal.php`, `candy-code/docs/SETTINGS.md`, `candy-code/docs/CONTEXT.md`
- **Tests — create:** none · **modify:** `candy-code/tests/LangParityTest.php`
- **Docs/guards:** settings gen + `candy-code/tests/Config/Settings/SettingsSchemaDocDriftTest.php`
- **Serialized:** [runtime:announceMemoryRecall] [commands] [lang:chat.memory.recalled_notice.*, settings.*.help] [docs:SETTINGS.md] [docs:CONTEXT.md]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-CTX-6.c`

#### ✅ dd6fd4069 C-CTX-6.d — `/memory open` edits enter the memory journal
- **Item:** C-CTX-6 (sources: VIS-2-P1) · fix · NIT · effort S
- **What:** Root cause: `Chat::memoryOpenArm()` (`Chat.php:15628-15660`) answers before `MemoryCommand`'s `MemoryHistoryCommand::record` (`MemoryCommand.php:60-62, 82-84`), so `/memory open` edits never reach `/memory log`/`undo`. Fix: …
- **Files — create:** `candy-code/tests/Chat/MemoryOpenJournalTest.php`
- **Files — modify:** `candy-code/src/Commands/EditorCommand.php`, `candy-code/src/Chat.php`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** none
- **Serialized:** [chat:memoryOpenArm] [hot:candy-code/src/Commands/EditorCommand.php]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-CTX-6.d`

#### ✅ be4c24ae5 C-EXT-7.a — `/hooks` reads the live chain; bundle decline notices see Claude manifest keys
- **Item:** C-EXT-7 (sources: VIS-3-P2, PLG-1-P4; VIS-4-P1 is closed in C-EXT-5.b) · fix · MINOR/NIT · effort S
- **What:** **VIS-3-P2.** `HooksHostCommand.php:30` calls `Bootstrap::hooks()`, which re-runs `installSecretEnvAllowlist` (:5830), `lspClient` (:5869) and `TeamManager::useLaunchHooks` (:5983). That makes the "read-only" `/hooks` command a …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Host/Commands/HooksHostCommand.php`, `candy-code/src/Bundles/Bundle.php`, `candy-code/lang/en.php`, `candy-code/docs/PLUGINS.md`
- **Tests — create:** `candy-code/tests/Host/Commands/HooksHostCommandLiveChainTest.php` · **modify:** `candy-code/tests/Bundles/BundleDeclinedSurfacesTest.php`
- **Docs/guards:** PLUGINS.md only.
- **Serialized:** [docs:PLUGINS.md] [lang:cmd.hooks.]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-ext.md` → `### C-EXT-7.a`

#### ✅ 6ae2c9e36 C-MCP-6.a — MCP hygiene: `mcp_list` lookup, login-URL scan, SSE fork TLS
- **Item:** C-MCP-6 (sources: MCP-7-P3, MCP-5-P1, MCP-4-P1, VIS-3-P1) · fix · MINOR (the login-URL leg is upgraded, see the first bullet) · effort S
- **What:** Three legs, one lane, disjoint files. **Login URL (upgrade).** `McpLoginFork::poll()` keeps a 1500-byte tail (`candy-code/src/Support/McpLoginFork.php:35,116`) and takes the FIRST `https?://\S+` (`:120`). The real child prints …
- **Files — create:** `candy-code/tests/MCP/SseMcpServerForkTlsTest.php`
- **Files — modify:** `candy-code/src/Support/McpLoginFork.php`, `candy-code/src/Tools/BuiltIn/McpListTool.php`, `candy-code/src/MCP/SseMcpServer.php`, `candy-code/docs/MCP.md`
- **Tests — create:** `candy-code/tests/MCP/SseMcpServerForkTlsTest.php` · **modify:** `candy-code/tests/Chat/McpLoginForkTest.php`, `candy-code/tests/MCP/McpDispatcherToolsTest.php`
- **Docs/guards:** `candy-code/tests/Tools/BuiltInToolCorpus.php` and `candy-code/tests/Tools/Catalog/ToolDocDriftTest.php` read `mcp_list` (externally wired, not in the plain roster). Re-run both. No catalog position …
- **Serialized:** [docs:MCP.md §Deferred schemas] [hot:candy-code/src/Tools/BuiltIn/McpListTool.php] [hot:candy-code/src/Support/McpLoginFork.php]
- **Deps:** C-MCP-1.b (W3)
- **Brief:** `prompt_kit/findings/comparison-1026/design-mcp.md` → `### C-MCP-6.a`

#### ✅ aa0d35002 C-PRV-5.a — Per-family thinking ceilings and omission
- **Item:** C-PRV-5 (sources: CTX-1-P2, PRV-2-P2) · fix · MINOR · effort M
- **What:** Root cause: the only bound is a floor (`ProviderFactory.php:999-1006`), while the wire sets `max_tokens = max(…, 1.5×budget)` (`AnthropicMessagesWire.php:509-515`). These therefore 400 on every turn: `thinkingBudget: 50000` …
- **Files — create:** `candy-code/tests/Providers/ThinkingCeilingTest.php`
- **Files — modify:** `candy-code/src/Providers/AnthropicProvider.php`, `candy-code/src/Providers/Concerns/AnthropicMessagesWire.php`, `candy-code/src/Providers/Concerns/GeminiGenerateContentWire.php`, `candy-code/src/Providers/EffortBudget.php`, `candy-code/src/Providers/ProviderFactory.php`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** `candy-code/docs/SETTINGS.md` hand prose for `thinkingBudget` (L344, L491)
- **Serialized:** [docs:SETTINGS.md] [hot:candy-code/src/Providers/AnthropicProvider.php] [hot:candy-code/src/Providers/Concerns/AnthropicMessagesWire.php] …
- **Deps:** C-PRV-1.a (W2)
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-PRV-5.a`

### W10

- protocol lanes: C-MCP-4.a (weld regenerates schema → SERVER.md → generated.ts once)
- Chat.php region claims: C-CTX-2.e (scheduleAutoCommit, schedulePromptSuggestion); C-PRV-5.c (pickPaletteProvider, update dispatch arm (ModelCatalogReadyMsg))
- Bootstrap.php method claims: C-PRV-5.b (summaryBackend, titleBackend, toollessBackend)

#### ✅ 4219a10c4 C-CTX-6.a — Served idle alarm survives a running turn
- **Item:** C-CTX-6 (sources: CTX-5-P1) · fix · MINOR · effort S
- **What:** Root cause: the served alarm is armed only on submit (`SessionHost.php:562`) as a one-shot `addTimer` (:656-661), and it returns on `isBusy()` (:678) with the timer already nulled and never re-armed. Idleness is measured from …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Host/SessionHost.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Host/SessionHostIdleAutoTest.php`
- **Docs/guards:** none
- **Serialized:** [hot:candy-code/src/Host/SessionHost.php]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-CTX-6.a`

#### ✅ 8ad2de199 C-EXT-2.c — Forked skills (`context: fork`) via the turn-bound Task tool
- **Item:** C-EXT-2 (sources: GAP-SKL-15, k2-dorm `dispatchSkill`, k1-dorm#8) · dormant-wire · effort M
- **What:** **What's dormant.** `App::dispatchSkill()` (`App.php:2528`) has no production caller; it is a single-shot pool run. `SkillTool::execute()` loads every skill inline. **SkillTool fork path.** SkillTool gains …
- **Files — create:** `candy-code/src/Skills/SkillFork.php`
- **Files — modify:** `candy-code/src/Tools/BuiltIn/SkillTool.php`, `candy-code/src/Tools/BuiltIn/TaskTool.php`, `candy-code/src/Backend/EngineBackend.php`, `candy-code/src/App/App.php`, `candy-code/src/Skills/SkillMentions.php`, `candy-code/src/Skills/Skill.php`, `candy-code/src/Skills/SkillFrontmatter.php`, `candy-code/src/Support/FrontmatterKeyAudit.php`, `candy-code/lang/en.php`, `candy-code/docs/SKILLS.md`, `candy-code/README.md`
- **Tests — create:** `candy-code/tests/Tools/BuiltIn/SkillToolForkTest.php`, `candy-code/tests/Skills/SkillForkTest.php` · **modify:** `candy-code/tests/App/AppSkillDispatchTest.php`, `candy-code/tests/Skills/SkillMentionTest.php`, `candy-code/tests/Support/FrontmatterKeyAuditTest.php`, `candy-code/tests/Tools/TaskToolEngineTest.php`
- **Docs/guards:** the INERT ↔ docs guard. The Skill tool description changes, but gen-tool-docs renders name and gloss only, so it does not move.
- **Serialized:** [docs:SKILLS.md] [docs:README.md] [lang:tui.app.] [hot:candy-code/src/Backend/EngineBackend.php] [hot:candy-code/src/Tools/BuiltIn/TaskTool.php] [hot:candy-code/src/App/App.php] …
- **Deps:** C-EXT-2.a (W6), C-EXT-2.b (W8)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ext.md` → `### C-EXT-2.c`

#### ✅ 2a24daeba C-SEC-5.c — TUI trust dialog component
- **Item:** C-SEC-5 (sources: GAP-PER-11 TUI half) · dormant-wire · effort S
- **What:** A self-contained immutable `TrustDialog` model. It takes the `TrustTarget` list from 5.a and the root. It shows one row per target, with what granting enables (hooks run programs, MCP starts servers, commands may run `!` forms, …
- **Files — create:** `candy-code/src/Tui/TrustDialog.php`, `candy-code/tests/Tui/TrustDialogTest.php`
- **Files — modify:** `candy-code/lang/en.php`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** none. The dialog keys are local to the modal and do not join `KeyBindingRegistry`; if the lane registers them, `KeyBindingDriftTest` and its doc move in-step.
- **Serialized:** [lang:tui.trust.]
- **Deps:** C-SEC-5.a (W7)
- **Brief:** `prompt_kit/findings/comparison-1026/design-sec.md` → `### C-SEC-5.c`

#### ✅ 634fb94f0 C-TOOL-3.a — Tool-tail slot store + PtyRun live progress
- **Item:** C-TOOL-3 (sources: GAP-SH-08, k2-dorm `ToolProgress` unpainted) · dormant-wire · effort S
- **What:** Core half of the live tail. (1) `ToolTailSlots`: a process-global store shaped like `candy-code/src/Media/PreviewSlots.php` (MAX_SLOTS backstop). It holds the newest tail text, `truncated` and `seq` per toolCallId, and drops …
- **Files — create:** `candy-code/src/Tui/ToolTailSlots.php`, `candy-code/tests/Tui/ToolTailSlotsTest.php`, `candy-code/tests/Tools/PtyRunToolProgressTest.php`
- **Files — modify:** `candy-code/src/Tools/BuiltIn/PtyRun.php`, `candy-code/src/Support/PtyDriver.php`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** none
- **Serialized:** none
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-3.a`

#### ✅ e359fc884 C-AGT-7.b — Headless honesty: `session_id`, `--json-schema`
- **Item:** C-AGT-7 (sources: AUT-2-P1, AUT-4-P1, AUT-4-P2) · fix · MINOR · effort M
- **What:** AUT-2-P1: the envelope carries `$opened['id']` (`src/Cli/NonInteractive.php:597-612`) and is printed (:636-641) before the save (:653-664). For `json`/`stream-json`, persist first inside the same try and render after, with …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Cli/NonInteractive.php`, `candy-code/src/Cli/ArgvParser.php`, `candy-code/lang/en.php`, `candy-code/README.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Cli/NonInteractiveSessionPersistenceTest.php`, `candy-code/tests/Cli/LaunchFlagsTest.php`, `candy-code/tests/Cli/JsonSchemaEnvelopeTest.php`
- **Docs/guards:** README envelope paragraph. `candy-code/tests/DenialPrefixRosterTest.php` anchors on "are **conditional**" (count-word-free), so it stays green. …
- **Serialized:** [headless] [lang: `cli.*`] [docs:README.md]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-agt.md` → `### C-AGT-7.b`

#### ✅ 7f6968f9f C-CTX-1.c — Track `@import` children in the instruction diff
- **Item:** C-CTX-1 (sources: p1-miss: CTX-3 `@import` children untracked) · fix · MINOR · effort S
- **What:** The tracker's declared limit (`InstructionDiffTracker.php:59-63`) leaves a child-only edit to an `@import`ed file invisible until the next refresh point. Capture records each root document's resolved import children as stat-only …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Context/InstructionDiffTracker.php`, `candy-code/src/Context/InstructionFileLoader.php`, `candy-code/src/Context/ImportResolver.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Context/InstructionDiffTurnContextTest.php`
- **Docs/guards:** `candy-code/docs/MEMORY.md` (instruction-diff paragraph)
- **Serialized:** [docs:MEMORY.md] [hot:candy-code/src/Context/InstructionDiffTracker.php] [hot:candy-code/src/Context/InstructionFileLoader.php]
- **Deps:** C-CTX-1.a (W3)
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-CTX-1.c`

#### ✅ 14118b17d C-CTX-2.e — The TUI settle consumes the same gate
- **Item:** C-CTX-2 (sources: GAP-MEM-02w ("one decision table, two hosts")) · dormant-wire (consolidation) · effort S
- **What:** Replace the inline gating at `HandlesTurnCompletion.php:304-336` with `TurnSettleWork::plan()`. Chat wraps each closure in `Cmd::promise`, and the landers stay unchanged. `Chat::scheduleAutoCommit()` and …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Chat/Handlers/HandlesTurnCompletion.php`, `candy-code/src/Chat.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Chat/AutoCommitTurnTest.php`, `candy-code/tests/Chat/AutoMemoryConsolidationWiringTest.php`
- **Docs/guards:** none
- **Serialized:** [chat:scheduleAutoCommit, schedulePromptSuggestion] [hot:candy-code/src/Chat/Handlers/HandlesTurnCompletion.php]
- **Deps:** C-CTX-2.b (W6)
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-CTX-2.e`

#### ✅ a6fa47900 C-MCP-4.a — User-tier rows + liveness in `context.sources` (data step)
- **Item:** C-MCP-4 (sources: k2-dorm `McpDecision` user servers, GAP-MCP-10 web-liveness half); feeds P-CTX-BREAKDOWN · dormant-wire · effort M
- **What:** Dormant data: `McpDecision::mcpServerInventory()` already computes `userStatus/userPath/userServers/userSkipped/userError` (`candy-code/src/Cli/McpDecision.php:294-340, 648-680`). `ContextSources::answer()` maps only the project …
- **Files — create:** `candy-code/tests/Protocol/ContextSourcesMcpUserTierTest.php`
- **Files — modify:** `candy-code/src/Host/ContextSources.php`, `candy-code/src/Protocol/Schema/Definitions.php`, `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts`, `candy-code-web/src-web/components/panels/ContextPanel.vue`
- **Tests — create:** `candy-code/tests/Protocol/ContextSourcesMcpUserTierTest.php` · **modify:** `candy-code/tests/Protocol/ContextSourcesTest.php`, `candy-code-web/src-web/components/panels/__tests__/contextPanel.spec.ts`
- **Docs/guards:** `scripts/gen-protocol-schema.php` → `candy-code-web/scripts/gen-protocol.mjs` regen in-step. `candy-code/tests/Protocol/ProtocolSchemaDriftTest.php` and …
- **Serialized:** [protocol] [hot:candy-code/src/Host/ContextSources.php] [hot:candy-code-web/src-web/components/panels/ContextPanel.vue]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-mcp.md` → `### C-MCP-4.a`

#### ✅ e2e05598d C-PRV-5.b — No thinking budget on the title and auxiliary backends
- **Item:** C-PRV-5 (sources: CTX-1-P1) · fix · MINOR · effort S
- **What:** Root cause: `Bootstrap::toollessBackend()` (`Bootstrap.php:9484-9516`) builds from the selected provider's block, `thinkingBudget` included, and the Anthropic budget survives `withModel()` (`AnthropicProvider.php:395-409`). So …
- **Files — create:** `candy-code/tests/Cli/ToollessBackendThinkingTest.php`
- **Files — modify:** `candy-code/src/Cli/Bootstrap.php`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** `candy-code/docs/SETTINGS.md` thinkingBudget prose ("not sent on title and auxiliary calls")
- **Serialized:** [bootstrap:toollessBackend, titleBackend, summaryBackend] [docs:SETTINGS.md]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-PRV-5.b`

#### ✅ 27757dd94 C-PRV-5.c — Non-blocking model-catalog prefetch
- **Item:** C-PRV-5 (sources: PRV-1-P1) · fix · MINOR · effort M
- **What:** Root cause: `Chat::pickPaletteProvider()` (`Chat.php:17060`, call at :17129) calls `ModelCatalog::prefetch()` → `OpenAiModelsEndpoint::fetch()`, a blocking Guzzle GET with a 3 s budget (`OpenAiModelsEndpoint.php:63-89`). On a …
- **Files — create:** `candy-code/src/App/ModelCatalogReadyMsg.php`, `candy-code/tests/Chat/PaletteCatalogAsyncTest.php`
- **Files — modify:** `candy-code/src/Providers/OpenAiModelsEndpoint.php`, `candy-code/src/Providers/HasModelCatalog.php`, `candy-code/src/Providers/ModelCatalog.php`, `candy-code/src/Providers/OpenAIProvider.php`, `candy-code/src/Providers/SglangProvider.php`, `candy-code/src/Chat.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Providers/OpenAiModelsEndpointTest.php`
- **Docs/guards:** none
- **Serialized:** [chat:pickPaletteProvider, update dispatch arm (ModelCatalogReadyMsg)] [hot:candy-code/src/Providers/OpenAIProvider.php]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-PRV-5.c`

### W11

- protocol lanes: C-SEC-5.b (weld regenerates schema → SERVER.md → generated.ts once)
- Chat.php region claims: C-TOOL-3.b (applyBackendToolEvent, pumpLiveToolEvents, replaceToolRunningPlaceholder)

#### ✅ 51812d155 C-MCP-2.a — `@mcp:` mentions in serve and `-p`; MCP prompt commands in serve
- **Item:** C-MCP-2 (sources: GAP-MCP-06, GAP-MCP-07, GAP-CMD-09, MCP-1-P1, p1-miss "MCP surfaces in serve/web and -p", k2-dorm `ContextMentions`/`McpPromptCommands`) · dormant-wire · effort M
- **What:** Root cause: only Chat passes an MCP provider. `TurnController::userTurnMessage()` already accepts `$mcpProvider` and arms `withMcpReader` (`candy-code/src/Host/TurnController.php:1032-1075`), but `SessionHost::admitPrompt` omits …
- **Files — create:** `candy-code/tests/Host/SessionHostMcpSurfaceTest.php`
- **Files — modify:** `candy-code/src/Host/SessionHost.php`, `candy-code/src/Cli/NonInteractive.php`, `candy-code/src/Commands/McpPromptCommands.php`, `candy-code/docs/MCP.md`
- **Tests — create:** `candy-code/tests/Host/SessionHostMcpSurfaceTest.php` · **modify:** `candy-code/tests/Cli/NonInteractiveMentionsTest.php`, `candy-code/tests/Host/TurnControllerMcpSeamTest.php`
- **Docs/guards:** `candy-code/tests/Config/DocFigureProseDriftTest.php`: `testMcpResourceMentionProseReadsTheKeywordLegItself` flips: the absence legs over `mcpProvider`/`withMcpReader` in NonInteractive.php and …
- **Serialized:** [headless:NonInteractive] [docs:MCP.md §Resources + §Prompts] [hot:candy-code/src/Host/SessionHost.php]
- **Deps:** C-SEC-3.a (W2), C-SEC-3.b (W3)
- **Brief:** `prompt_kit/findings/comparison-1026/design-mcp.md` → `### C-MCP-2.a`

#### ✅ b72a169f2 C-CTX-6.b — PDF page check and an aggregate document budget
- **Item:** C-CTX-6 (sources: CTX-2-P1) · fix · MINOR · effort M
- **What:** Root cause: PDFs have only a per-file 20 MiB cap (`FileMentions.php:58-64`). `AttachmentEncoding.php:87-95` re-encodes every earlier document on every request, so a 15 MB + 10 MB pair puts every later request over 32 MiB and each …
- **Files — create:** `candy-code/tests/Backend/DocumentAggregateBudgetTest.php`
- **Files — modify:** `candy-code/src/Attachments/FileMentions.php`, `candy-code/src/Backend/EngineBackend.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Attachments/FileMentionsTest.php`
- **Docs/guards:** `candy-code/docs/MEDIA.md` (attachment limits); lang `chat.attach.pdf_pages`, `chat.attach.document_withheld`
- **Serialized:** [lang:chat.attach.*] [docs:MEDIA.md] [hot:candy-code/src/Backend/EngineBackend.php]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-CTX-6.b`

#### ✅ fa14b82c6 C-EXT-2.d — `/skills enable|disable` + picker toggle through SkillManager
- **Item:** C-EXT-2 (sources: k2-dorm SkillManager accessors) · dormant-wire · effort M
- **What:** `SkillManager::enable()`/`disable()` (`SkillManager.php:292-320`) have no caller. `App::handleSelectSkill()` (`App.php:4735-4785`) re-implements enable inline and can never disable. Changes: `/skills disable <name>` and `/skills …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Host/Commands/SkillsHostCommand.php`, `candy-code/src/App/App.php`, `candy-code/lang/en.php`, `candy-code/docs/SKILLS.md`, `candy-code/docs/SETTINGS.md`, `candy-code/docs/COMMANDS.md`
- **Tests — create:** `candy-code/tests/Host/Commands/SkillsEnableDisableTest.php` · **modify:** `candy-code/tests/Host/Commands/SkillsHostCommandTest.php`, `candy-code/tests/App/AppSkillDispatchTest.php`, `candy-code/tests/Config/Settings/SettingsWriterCensusTest.php`
- **Docs/guards:** SettingsWriterCensusTest and SETTINGS.md move in-step; gen-command-docs.
- **Serialized:** [docs:SKILLS.md] [docs:SETTINGS.md] [docs:COMMANDS.md] [lang:host.skills.,cmd.skills.,tui.app.] [hot:candy-code/src/Host/Commands/SkillsHostCommand.php] [hot:candy-code/src/App/App.php]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-ext.md` → `### C-EXT-2.d`

#### ✅ e07d05447 C-PRV-5.d — Gemini schema, documented catalog order, codex login wording
- **Item:** C-PRV-5 (sources: PRV-3-P1, PRV-1-P2, PRV-4-P4) · fix · MINOR/NIT · effort S
- **What:** **PRV-3-P1:** `GeminiGenerateContentWire.php:280-293` sends the operator's raw JSON Schema as `responseSchema` with `responseMimeType: application/json`, on a first step that also declares tools (`Runtime.php:845-872`). Fix: send …
- **Files — create:** `candy-code/tests/Providers/GeminiResponseSchemaTest.php`
- **Files — modify:** `candy-code/src/Providers/Concerns/GeminiGenerateContentWire.php`, `candy-code/README.md`, `candy-code/docs/SETTINGS.md`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** README provider table (`ReadmeRosterDriftTest` unaffected; wording only)
- **Serialized:** [docs:README.md] [docs:SETTINGS.md] [hot:candy-code/src/Providers/Concerns/GeminiGenerateContentWire.php]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-PRV-5.d`

#### ✅ f90523394 C-SEC-5.b — Web trust targets and MCP pin re-approval
- **Item:** C-SEC-5 (sources: GAP-PER-11 web half, GAP-MCP-N1, k3-dorm#5) · dormant-wire · effort M
- **What:** `TrustAsks` (`src/Protocol/TrustAsks.php:33`) declares one grantable target (`TARGET_MCP`) and keys records by root. It becomes keyed by root+target. `ContextMethods::sources()` mints one prompt per …
- **Files — create:** `candy-code/tests/Protocol/ContextTrustTargetsTest.php`
- **Files — modify:** `candy-code/src/Protocol/TrustAsks.php`, `candy-code/src/Protocol/Methods/ContextMethods.php`, `candy-code/src/Protocol/Methods/SessionMethods.php`, `candy-code/src/Host/ContextSources.php`, `candy-code/src/Protocol/Schema/Definitions.php`, `candy-code/src/Protocol/Schema/MethodSchemas.php`, `candy-code/lang/en.php`, `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts`, `candy-code-web/src-web/stores/contextSources.ts`, `candy-code-web/src-web/components/panels/ContextPanel.vue`, `candy-code-web/src-web/components/StatusBar.vue`, `candy-code/docs/PERMISSIONS.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Protocol/ContextTrustPromptTest.php`, `candy-code/tests/Protocol/ContextSourcesTest.php`, `candy-code-web/src-web/components/panels/__tests__/contextPanel.spec.ts`
- **Docs/guards:** protocol gen checks; `ServerProtocolDocumentationDriftTest`; `TrustKeyDocumentationDriftTest` (PERMISSIONS.md trust paragraph). Web dist rebuild.
- **Serialized:** [protocol] [hot:candy-code/src/Protocol/TrustAsks.php] [hot:candy-code/src/Host/ContextSources.php] [hot:candy-code-web/src-web/components/panels/ContextPanel.vue] …
- **Deps:** C-SEC-4.b (W3), C-SEC-5.a (W7)
- **Brief:** `prompt_kit/findings/comparison-1026/design-sec.md` → `### C-SEC-5.b`

#### ✅ 46d64b18a C-SES-1.b — Background announcement obeys the `≥` law
- **Item:** C-SES-1 (sources: SES-1-P3) · fix · MINOR · effort S
- **What:** **Root cause.** `BackgroundSession::announcement()` prints `sprintf('$%.4f', $this->costUsd)` only when the cost is above zero (`src/Background/BackgroundSession.php:327-329`). It ignores `spendUnpriced` (`:77`, `:118-123`): an …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Background/BackgroundSession.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Chat/BackgroundResultAnnouncedTest.php`
- **Docs/guards:** none
- **Serialized:** none
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-1.b`

#### ✅ b2b049b5b C-SES-9.b — Serve `/status` prints live spend and cap
- **Item:** C-SES-9 (sources: OBS-2-P1) · fix · MINOR · effort S
- **What:** **Root cause.** Serve constructs `StatusHostCommand` with no arguments, through `withHostCommand(StatusHostCommand::class)` in `builtin-commands/1340-status.php`. It therefore prints `cmd.status.spend.untracked` …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Host/Commands/CommandContext.php`, `candy-code/src/Host/SessionHost.php`, `candy-code/src/Host/Commands/StatusHostCommand.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Host/WebTwoHostCommandsTest.php`
- **Docs/guards:** none
- **Serialized:** none (hot files: `SessionHost.php`, `CommandContext.php`)
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-9.b`

#### ✅ 1999f4114 C-TOOL-3.b — TUI paints the live shell tail
- **Item:** C-TOOL-3 (sources: GAP-SH-08, k2-sum live defect, k2-dorm) · dormant-wire · effort M
- **What:** `Chat.php` records `ToolProgress` and discards it in both folds: `applyBackendToolEvent()` :3702 (settled queue) and `pumpLiveToolEvents()` :4230 (live pump; the comment says "The TUI paints no live tail this wave"). Both arms …
- **Files — create:** `candy-code/tests/Renderer/RunningToolTailTest.php`, `candy-code/tests/Chat/ToolTailFoldTest.php`
- **Files — modify:** `candy-code/src/Chat.php`, `candy-code/src/Renderer.php`, `candy-code/lang/en.php`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** none
- **Serialized:** [chat:applyBackendToolEvent,pumpLiveToolEvents,replaceToolRunningPlaceholder] [renderer:renderPendingToolCall] [lang:tui.tool.tail]
- **Deps:** C-TOOL-3.a (W10)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-3.b`

#### ✅ 08e91c647 C-TOOL-7.b — Ls bounded heap
- **Item:** C-TOOL-7 (sources: SHL-2-P1) · fix · MINOR · effort S
- **What:** Root cause `candy-code/src/Tools/BuiltIn/Ls.php:313-347` (`collect()`): every entry is `is_dir`'d, ignore-checked and `realpath`'d into `$entries`, then `usort`ed, and the cap applies after. The comment "the walk IS the cost …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Tools/BuiltIn/Ls.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Tools/BuiltIn/LsTest.php`
- **Docs/guards:** none
- **Serialized:** none
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-7.b`

#### ✅ 15ec4b791 C-TOOL-7.d — JSON-output escaping of control/format chars
- **Item:** C-TOOL-7 (sources: CC-new#12 JSON-output escaping) · fix · NIT · effort S
- **What:** `NonInteractive::encodeDocument()` (`candy-code/src/Cli/NonInteractive.php:1341-1354`) is the single encoder for `-p --output-format json`, `stream-json` (`StreamJsonEmitter.php:175`) and the subcommand JSON documents. It encodes …
- **Files — create:** `candy-code/tests/Cli/EncodeDocumentEscapingTest.php`
- **Files — modify:** `candy-code/src/Cli/NonInteractive.php`, `candy-code/src/Cli/Subcommands.php`
- **Tests — create:** `candy-code/tests/Cli/EncodeDocumentEscapingTest.php` · **modify:** `candy-code/tests/Cli/StreamJsonEmitterTest.php`
- **Docs/guards:** none (`ReadmeJsonErrorContractDriftTest` unaffected: same document shape)
- **Serialized:** [headless]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-7.d`

### W12

- protocol lanes: C-MCP-2.b, C-WEB-3.f (weld regenerates schema → SERVER.md → generated.ts once)
- Chat.php region claims: C-TUI-7.a (applyCommandResult (ClearTranscript/SwitchSession arms), init)
- Bootstrap.php method claims: C-TUI-7.b (announceMacAltFallback, macAltNotice, terminalSpeaksKittyKeyboard); C-TOOL-5.b (hooks)

#### ✅ fad3d931e #### C-MCP-2.b — `command.list`/`command.exec` carry MCP prompts; `mcp.resources` method
- **Item:** C-MCP-2 (sources: GAP-MCP-07, GAP-CMD-09, CLAW-new#9) · dormant-wire · effort M
- **What:** `CommandMethods::list/exec` read only `workspace->commandLoader->loadAll()` (`candy-code/src/Protocol/Methods/CommandMethods.php:49-62, 66-91, 127-138`), so served MCP prompts are neither listed nor executable via `command.exec` …
- **Files — create:** `candy-code/src/Protocol/Methods/McpMethods.php`, `candy-code/tests/Protocol/CommandMethodsMcpPromptsTest.php`, `candy-code/tests/Protocol/McpMethodsTest.php`
- **Files — modify:** `candy-code/src/Protocol/Methods/CommandMethods.php`, `candy-code/src/Protocol/Dispatcher.php`, `candy-code/src/Protocol/Schema/MethodSchemas.php`, `candy-code/src/Host/SessionHost.php`, `candy-code/src/MCP/McpClient.php`, `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts`, `candy-code/docs/MCP.md`
- **Tests — create:** `candy-code/tests/Protocol/CommandMethodsMcpPromptsTest.php`, `candy-code/tests/Protocol/McpMethodsTest.php` · **modify:** `candy-code/tests/Server/ConformanceTest.php`
- **Docs/guards:** protocol regen; `ProtocolSchemaDriftTest`, `ServerProtocolDocumentationDriftTest`; `candy-code/tests/Tui/McpPanelTest.php` (the resources count still renders).
- **Serialized:** [protocol] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/MCP/McpClient.php] [hot:candy-code/src/Protocol/Methods/CommandMethods.php]
- **Deps:** C-MCP-2.a (W11)
- **Brief:** `prompt_kit/findings/comparison-1026/design-mcp.md` → `### C-MCP-2.b`

#### ✅ a93d124b6 C-SEC-5.d — TUI launch trust dialog wiring
- **Item:** C-SEC-5 (sources: GAP-PER-11) · dormant-wire · effort M
- **What:** At an interactive launch only, never `-p` or serve, `LaunchContext` seeds the App with `TrustDialog::for(Bootstrap::pendingProjectTrust($root))` when the list is non-empty. The App is built at :2274. `App` holds the dialog as an …
- **Files — create:** `candy-code/tests/App/LaunchTrustDialogTest.php`
- **Files — modify:** `candy-code/src/App/App.php`, `candy-code/src/Tui/KeyboardHandler.php`, `candy-code/src/Cli/LaunchContext.php`, `candy-code/docs/PERMISSIONS.md`, `candy-code/lang/en.php`, `candy-code/src/Tui/Renderer.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Tui/KeyboardHandlerTest.php`
- **Docs/guards:** `TrustKeyDocumentationDriftTest` (PERMISSIONS.md trust prose); `MouseModalGuardTest` if the overlay must refuse clicks underneath.
- **Serialized:** [hot:candy-code/src/App/App.php] [hot:candy-code/src/Tui/KeyboardHandler.php] [hot:candy-code/src/Cli/LaunchContext.php] [renderer] (conditional) [docs:PERMISSIONS.md] [lang:tui.trust.]
- **Deps:** C-SEC-5.a (W7), C-SEC-5.c (W10)
- **Brief:** `prompt_kit/findings/comparison-1026/design-sec.md` → `### C-SEC-5.d`

#### ✅ 9070bd57e C-TOOL-7.e — Bounded resample of all-malformed tool-call replies
- **Item:** C-TOOL-7 (sources: CC-new#12 unparseable tool-call retry) · fix · NIT · effort S
- **What:** Today a call whose arguments did not decode becomes an A11 error result (`Runtime::malformedArguments()` :4183, `failure()`), costing a whole step. Mirror Claude Code: in `Runtime::runBatch()` (:1899) and `runStreaming()`, when a …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Runtime.php`
- **Tests — create:** none · **modify:** `candy-code/tests/RuntimeMalformedToolArgumentsTest.php`
- **Docs/guards:** none
- **Serialized:** [runtime:runBatch,runStreaming]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-7.e`

#### ✅ d88f83e87 C-TUI-1.a — Vim text objects, `c` and counts in ComposerVim
- **Item:** C-TUI-1 (sources: GAP-UI-07, k3-dorm#2, k3-sum §6.6 vim, p2-miss: TUI-3 counts/`cw`/text objects) · dormant-wire · effort M
- **What:** The sugar-forms text-object machinery is never called from the composer: `VimKeyHandler::handleTextObject()` (`sugar-forms/src/Vim/VimKeyHandler.php:289-304`) `TextObject::isTarget()`/`resolve()` …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Tui/ComposerVim.php`, `candy-code/src/Chat/Handlers/HandlesKeys.php`, `candy-code/lang/en.php`
- **Tests — create:** `candy-code/tests/Tui/ComposerVimTextObjectTest.php`, `candy-code/tests/Tui/ComposerVimCountTest.php` · **modify:** `candy-code/tests/Tui/ComposerVimTest.php`, `candy-code/tests/Chat/ComposerVimModeTest.php`
- **Docs/guards:** the `settings.vimMode.help` text only. Vim keys are a mode, not KeyBindingRegistry rows, so README Keys does not move. LangParityTest.
- **Serialized:** [lang:settings.vimMode.] [hot:candy-code/src/Chat/Handlers/HandlesKeys.php (TUI-3A vim arms)]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-1.a`

#### ✅ 03280fdea C-TUI-7.a — Startup summary: personal files + gather off `view()`
- **Item:** C-TUI-7 (sources: VIS-1-P1, VIS-1-P2) · fix · MINOR/NIT · effort M
- **What:** VIS-1-P1: `StartupSummary::instructionFiles()` (`src/Support/StartupSummary.php:282-290`) lists `loadDocuments()` only, while `/context` also lists `loadPersonal()`. Fix: append the personal documents with a personal marker, …
- **Files — create:** `candy-code/src/App/StartupSummaryGatheredMsg.php`
- **Files — modify:** `candy-code/src/Support/StartupSummary.php`, `candy-code/src/Chat.php`, `candy-code/src/Chat/Handlers/HandlesRunControl.php`, `candy-code/lang/en.php`
- **Tests — create:** `candy-code/tests/Chat/StartupSummaryGatherTest.php` · **modify:** `candy-code/tests/Support/StartupSummaryTest.php`
- **Docs/guards:** `tests/Chat/RouteTraitParityTest.php`
- **Serialized:** [chat:init, applyCommandResult (ClearTranscript/SwitchSession arms)] [hot:candy-code/src/Support/StartupSummary.php] [hot:candy-code/src/Chat/Handlers/HandlesRunControl.php] [lang:chat.startup.]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-7.a`

#### ✅ ea1f22bf7 C-TUI-7.b — Mac Option-as-Meta notice: once per version, tmux-aware
- **Item:** C-TUI-7 (sources: TUI-5-P1) · fix · NIT · effort S
- **What:** `Bootstrap::terminalSpeaksKittyKeyboard()` (`Bootstrap.php:10001-10015`) checks only `TERM`/`TERM_PROGRAM`. So tmux-on-kitty (`TERM_PROGRAM=tmux`), and a Terminal.app user who already set Option-as-Meta, see the row on **every** …
- **Files — create:** `candy-code/src/Support/MacAltNotice.php`
- **Files — modify:** `candy-code/src/Cli/Bootstrap.php`, `candy-code/lang/en.php`
- **Tests — create:** `candy-code/tests/Support/MacAltNoticeStampTest.php` · **modify:** `candy-code/tests/Commands/MacAltNoticeTest.php`
- **Docs/guards:** a ReadPathCensusTest row (the stamp read)
- **Serialized:** [bootstrap:macAltNotice, terminalSpeaksKittyKeyboard, announceMacAltFallback] [lang:cli.notice.mac_alt] [hot:candy-code/tests/Support/ReadPathCensusTest.php]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-7.b`

#### ✅ bf42820d8 C-WEB-3.f — Video tab in the Generate panel (`generate.submit` kind)
- **Item:** C-WEB-3 (sources: k3-dorm#4, k3-sum (Generate form image-only)) · dormant-wire · M
- **What:** **Gap.** `canGenerateVideo` (`candy-code-web/src-web/stores/connection.ts:91`) is read by nothing. `GeneratePanel.vue:34` gates on image only. `generate.submit` (`candy-code/src/Protocol/Methods/MediaMethods.php:166-232`) always …
- **Files — create:** `candy-code/tests/Protocol/GenerateVideoSubmitTest.php`
- **Files — modify:** `candy-code/src/Protocol/Methods/MediaMethods.php`, `candy-code/src/Protocol/Schema/MethodSchemas.php`, `candy-code/src/Tools/BuiltIn/GenerateVideo.php`, `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts`, `candy-code-web/src-web/stores/generate.ts`, `candy-code-web/src-web/components/panels/GeneratePanel.vue`, `candy-code/docs/MEDIA.md`
- **Tests — create:** `candy-code/tests/Protocol/GenerateVideoSubmitTest.php` · **modify:** `candy-code-web/src-web/components/panels/__tests__/generatePanel.spec.ts`
- **Docs/guards:** the protocol gates; `docs/MEDIA.md`; `candy-code/tests/Server/HelloMediaFlagsTest.php` stays green; no new fork site (rides `TurnController::forkPayload`), so no child-lifetime row.
- **Serialized:** [protocol] [hot:candy-code-web/src-web/components/panels/GeneratePanel.vue] [docs:MEDIA.md]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-web.md` → `### C-WEB-3.f`

#### ✅ 0e2a4e08e C-WEB-7.a — Shell tail trailing-edge flush + `discard()` docblock truth
- **Item:** C-WEB-7 (sources: WEB-4-P1, WEB-6-P3) · fix · MINOR · effort S
- **What:** **Root cause, the tail.** `ShellTailRing::onChunk()` (`candy-code/src/Tools/ShellTailRing.php:55-67`) only sets `dirty` for a chunk that arrives within `frameIntervalSeconds` (0.5 s, `Bash.php:708`) of the last frame. Only …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Tools/ShellTailRing.php`, `candy-code/src/Tools/BuiltIn/Bash.php`, `candy-code/src/Server/Auth/SessionFileStore.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Tools/ShellTailRingTest.php`, `candy-code/tests/Tools/BashToolProgressTest.php`
- **Docs/guards:** none (no roster, no doc page); no child-lifetime row (no new spawn)
- **Serialized:** [hot:candy-code/src/Tools/BuiltIn/Bash.php]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-web7.md` → `### C-WEB-7.a`

#### ✅ c69f190b1 C-WEB-7.b — Web client nits: `@` debounce, cross-tab history clear + palette action, re-announced questions
- **Item:** C-WEB-7 (sources: WEB-5-P3 (client half), WEB-6-P1, WEB-6-P2, WEB-7-P1) · fix · MINOR · effort M
- **What:** Client-only, no protocol. **WEB-5-P3, debounce.** Root cause: `Composer.vue:86-101` watches `mentionPartial` with no debounce, so every keystroke after `@` is a synchronous `fs.search` on the shared serve loop. Fix: a leading + …
- **Files — create:** `candy-code-web/src-web/components/panels/__tests__/paletteClearHistory.spec.ts`
- **Files — modify:** `candy-code-web/src-web/components/Composer.vue`, `candy-code-web/src-web/stores/promptHistory.ts`, `candy-code-web/src-web/components/panels/CommandPalette.vue`, `candy-code-web/src-web/lib/questions.ts`, `candy-code-web/README.md`
- **Tests — create:** `candy-code-web/src-web/components/panels/__tests__/paletteClearHistory.spec.ts` · **modify:** `candy-code-web/src-web/components/__tests__/composerFilesImages.spec.ts`, `candy-code-web/src-web/components/__tests__/composerHistory.spec.ts`, `candy-code-web/src-web/lib/__tests__/questions.spec.ts`
- **Docs/guards:** `candy-code-web/README.md` (soft; no test pins it); `npm run typecheck`, `npm run lint`, vitest; dist rebuilt at weld
- **Serialized:** [hot:candy-code-web/src-web/components/Composer.vue] [hot:candy-code-web/src-web/stores/promptHistory.ts] [hot:candy-code-web/src-web/components/panels/CommandPalette.vue]
- **Deps:** C-SEC-4.b (W3)
- **Brief:** `prompt_kit/findings/comparison-1026/design-web7.md` → `### C-WEB-7.b`

#### ✅ 3ecf83d95 C-TOOL-5.b — Default formatter table (`formatAuto`)
- **Item:** C-TOOL-5 (sources: GAP-EDT-04) · gap · effort M
- **What:** `FormatRunner` (`candy-code/src/Format/FormatRunner.php`) runs only the explicit `formatCommands` map, and `Bootstrap::hooks()` (:5826, :5843-5848) registers the hook only when a command exists. Add `FormatAutoTable`: extension → …
- **Files — create:** `candy-code/src/Format/FormatAutoTable.php`, `candy-code/tests/Format/FormatAutoTableTest.php`
- **Files — modify:** `candy-code/src/Format/FormatRunner.php`, `candy-code/src/Cli/Bootstrap.php`, `candy-code/src/Config/Settings/Definitions/HooksMcpSettings.php`, `candy-code/lang/en.php`, `candy-code/docs/HOOKS.md`, `candy-code/docs/SETTINGS.md`, `candy-code/README.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Format/FormatRunnerTest.php`, `candy-code/tests/Hooks/BuiltIn/PostEditFormatHookTest.php`
- **Docs/guards:** `SettingsSchemaDocDriftTest`, `TrustKeyDocumentationDriftTest`
- **Serialized:** [bootstrap:hooks] [lang:settings.formatAuto.] [docs:HOOKS.md] [docs:SETTINGS.md] [docs:README.md]
- **Deps:** C-TOOL-1.c (W1), C-TOOL-7.c (W6)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-5.b`

### W13

- protocol lanes: C-WEB-7.c, C-WEB-7.d (weld regenerates schema → SERVER.md → generated.ts once)
- Chat.php region claims: C-EXT-2.e (submit custom-command branch)

#### ✅ f63217396 C-EXT-2.e — Command frontmatter `model`/`agent`/`subtask`
- **Item:** C-EXT-2 (sources: GAP-CMD-04, k2-dorm inert command keys) · dormant-wire · effort M
- **What:** **What's inert.** `CommandSpec.php:205-210` parses `model` and `subtask`, both INERT (`FrontmatterKeyAudit.php:131-134`). `agent` is KNOWN but not parsed. `TurnController::expandCustomCommand()` (:448) expands into the current …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Commands/CommandSpec.php`, `candy-code/src/Host/TurnController.php`, `candy-code/src/Chat.php`, `candy-code/src/Host/SessionHost.php`, `candy-code/src/Support/FrontmatterKeyAudit.php`, `candy-code/lang/en.php`, `candy-code/docs/COMMANDS.md`
- **Tests — create:** `candy-code/tests/Host/TurnControllerCommandRoutingTest.php`, `candy-code/tests/Chat/CustomCommandModelOverrideTest.php` · **modify:** `candy-code/tests/Host/SessionHostCommandsTest.php`, `candy-code/tests/Commands/CommandLoaderTest.php`, `candy-code/tests/Support/FrontmatterKeyAuditTest.php`, `candy-code/tests/Config/InertFrontmatterDocumentationDriftTest.php`
- **Docs/guards:** the INERT ↔ COMMANDS.md guard.
- **Serialized:** [chat:submit custom-command branch] [docs:COMMANDS.md] [lang:host.turn.] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Host/TurnController.php] …
- **Deps:** C-EXT-2.b (W8)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ext.md` → `### C-EXT-2.e`
- **Erratum (2026-10-29, W13 loop r2):** the modify-test name `InertFrontmatterDocumentationDriftTest` above is stale for this step — what moved in-commit was `tests/Config/BootstrapLaunchNoticeRoutingTest.php` (fixture flip subtask→subtaskx); no InertFrontmatter arm changed here.

#### ✅ 1aab5f2f9 C-WEB-7.c — Paged `session.export` + memoized non-git `fs.search` walk
- **Item:** C-WEB-7 (sources: WEB-1-P2, WEB-5-P3 (server half), p2-miss: web session export over 4 MiB) · fix · MINOR · effort M
- **What:** **Root cause, export (WEB-1-P2).** `SessionMethods::export()` (`candy-code/src/Protocol/Methods/SessionMethods.php:237-258`) returns the whole rendered transcript in one answer. `Dispatcher::answer()` …
- **Files — create:** `candy-code/src/Attachments/WorkspaceWalk.php`, `candy-code/tests/Server/SessionExportPagingTest.php`, `candy-code/tests/Attachments/WorkspaceWalkTest.php`, `candy-code-web/src-web/stores/__tests__/sessionsExportPaging.spec.ts`
- **Files — modify:** `candy-code/src/Protocol/Methods/SessionMethods.php`, `candy-code/src/Protocol/Schema/MethodSchemas.php`, `candy-code/src/Protocol/Methods/FsMethods.php`, `candy-code-web/src-web/stores/sessions.ts`, `candy-code/docs/SERVER.md`, `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code-web/src-web/protocol/generated.ts`
- **Tests — create:** `candy-code/tests/Server/SessionExportPagingTest.php`, `candy-code/tests/Attachments/WorkspaceWalkTest.php`, `candy-code-web/src-web/stores/__tests__/sessionsExportPaging.spec.ts` · **modify:** `candy-code-web/src-web/stores/__tests__/sessionsRowActions.spec.ts`, `candy-code-web/src-web/components/__tests__/sidebarActions.spec.ts`
- **Docs/guards:** `php candy-code/scripts/gen-protocol-schema.php` (`--check` is guarded by `candy-code/tests/Protocol/ProtocolSchemaDriftTest.php`); `npm run gen:protocol -- --check` …
- **Serialized:** [protocol] [hot:candy-code/src/Protocol/Methods/SessionMethods.php] [hot:candy-code-web/src-web/stores/sessions.ts] [docs:SERVER.md]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-web7.md` → `### C-WEB-7.c`

#### ✅ 7b730e485 C-WEB-7.d — Typed AskUser `choices` on the ask payload
- **Item:** C-WEB-7 (sources: p2-miss: WEB-5 structured AskUser `options[]`) · fix · MINOR · effort S
- **What:** **Root cause.** The web reads AskUser's choices by parsing the untyped `arguments.options` (`PermissionCard.vue:39-53`). It silently drops to free text above 8 choices. Nothing in the protocol schema states the contract. **The …
- **Files — create:** `candy-code/tests/Protocol/AskUserChoicesWireTest.php`
- **Files — modify:** `candy-code/src/Protocol/SessionFeed.php`, `candy-code/src/Host/TranscriptProjector.php`, `candy-code/src/Protocol/Schema/Definitions.php`, `candy-code-web/src-web/components/PermissionCard.vue`, `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code-web/src-web/protocol/generated.ts`
- **Tests — create:** `candy-code/tests/Protocol/AskUserChoicesWireTest.php` · **modify:** `candy-code-web/src-web/components/__tests__/permissionCardExtras.spec.ts`
- **Docs/guards:** the protocol gen chain (`gen-protocol-schema.php --check` / `ProtocolSchemaDriftTest`, `npm run gen:protocol -- --check` / `generated.spec.ts`, `ServerProtocolDocumentationDriftTest`; Definitions do …
- **Serialized:** [protocol] [hot:candy-code/src/Protocol/SessionFeed.php] [hot:candy-code/src/Host/TranscriptProjector.php] [hot:candy-code-web/src-web/components/PermissionCard.vue]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-web7.md` → `### C-WEB-7.d`

#### ✅ 1f1db4f97 C-TOOL-3.c — Session-owned background shells
- **Item:** C-TOOL-3 (sources: GAP-SH-04 serve half, p2-miss: SHL no web/serve shell list) · gap · effort M
- **What:** One `BackgroundShellRegistry` per pid+root (`LaunchContext::backgroundShellRegistryFor()` :690). Served sessions share `workspace->backend` and its tool instances (`SessionHost.php:1626`, `TurnRunner::backendForTurn()` :276 only …
- **Files — create:** `candy-code/src/Tools/AcceptsShellOwner.php`, `candy-code/tests/Tools/ShellOwnershipTest.php`
- **Files — modify:** `candy-code/src/Background/BackgroundShellRegistry.php`, `candy-code/src/Tools/BuiltIn/Bash.php`, `candy-code/src/Tools/BuiltIn/ShellOutput.php`, `candy-code/src/Tools/BuiltIn/ShellStop.php`, `candy-code/src/Backend/EngineBackend.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Sessions/BackgroundShellRegistryTest.php`, `candy-code/tests/Tools/ShellStopTest.php`
- **Docs/guards:** `candy-code/tests/Support/OneTypePerFile` gate via `tools/check-one-type-per-file.php` (new interface file)
- **Serialized:** [hot:candy-code/src/Backend/EngineBackend.php] [hot:candy-code/src/Background/BackgroundShellRegistry.php]
- **Deps:** C-TOOL-1.a (W1), C-TOOL-7.a (W5)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-3.c`

#### ✅ e71d398aa C-MCP-3.a — Server-request seam: ping, roots, elicitation, progress (library + core)
- **Item:** C-MCP-3 (sources: GAP-MCP-08, p1-miss "MCP elicitation/sampling/roots/logging/ping", OC-new#1, CC-new#2) · gap · effort M
- **What:** Today the client advertises `capabilities: {}` (`sugar-mcp-client/src/StdioMcpServer.php:408-414`), and `readResponse()` skips every method-bearing frame unanswered (`:1106-1160`), so a server's `ping`, `roots/list` or …
- **Files — create:** `candy-code/src/MCP/AnswersServerRequests.php`, `candy-code/src/MCP/McpServerRequests.php`, `candy-code/src/MCP/McpElicitation.php`, `sugar-mcp-client/tests/StdioMcpServerServerRequestTest.php`, `sugar-mcp-client/tests/Fixtures/server_request_server.php`, `candy-code/tests/MCP/McpServerRequestsTest.php`, `candy-code/tests/MCP/McpElicitationTest.php`
- **Files — modify:** `sugar-mcp-client/src/StdioMcpServer.php`, `sugar-mcp-client/README.md`, `candy-code/src/MCP/StdioMcpServer.php`, `candy-code/src/MCP/McpClient.php`
- **Tests — create:** `sugar-mcp-client/tests/StdioMcpServerServerRequestTest.php`, `candy-code/tests/MCP/McpServerRequestsTest.php`, `candy-code/tests/MCP/McpElicitationTest.php` · **modify:** `sugar-mcp-client/tests/StdioMcpServerSdkWireShapeTest.php`
- **Docs/guards:** `sugar-mcp-client/tests/McpMessageWireShapeTest.php` unchanged; `tools/check-child-lifetimes.php` unchanged (no new spawn); `php tools/check-one-type-per-file.php` (three new types, three files).
- **Serialized:** [hot:sugar-mcp-client/src/StdioMcpServer.php] [hot:candy-code/src/MCP/McpClient.php]
- **Deps:** C-MCP-1.a (W1)
- **Brief:** `prompt_kit/findings/comparison-1026/design-mcp.md` → `### C-MCP-3.a`

#### ✅ 14d37bbf5 C-TOOL-5.c — LSP installer core
- **Item:** C-TOOL-5 (sources: GAP-EDT-07) · gap · effort M
- **What:** `LspInstaller`: explicit, never automatic, installs into `~/.candy-code/lsp/` (0700). Single-binary servers (rust-analyzer, and others the ruling admits) come from a pinned manifest `LspReleases` (language → version, per-OS/arch …
- **Files — create:** `candy-code/src/LSP/LspInstaller.php`, `candy-code/src/LSP/LspReleases.php`, `candy-code/tests/LSP/LspInstallerTest.php`
- **Files — modify:** `candy-code/src/LSP/LspLauncher.php`
- **Tests — create:** none · **modify:** `candy-code/tests/LSP/LspLauncherAutoDetectTest.php`
- **Docs/guards:** none (the CLI and docs ride C-TOOL-5.d)
- **Serialized:** none
- **Deps:** C-TOOL-7.c (W6)
- **Ruling:** LSP download policy: may candy-code download and run pinned third-party language-server binaries (and npm packages with `--ignore-scripts`), and which servers … — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-5.c`

#### ✅ 5cd68af7e C-AGT-5.a — Model-callable `Schedule` tool
- **Item:** C-AGT-5 (sources: GAP-TL-22, p1-miss: schedules UX) · gap · effort M
- **What:** Add a `Schedule` tool (`#[BuiltInTool]` Ask class, position 41) with `create|list|delete` over `schedules.json`. Turns run forked, so the tool writes PERSISTED rows only; the file under its lock is the channel, and hosts reload …
- **Files — create:** `candy-code/src/Tools/BuiltIn/ScheduleTool.php`, `candy-code/src/Background/ScheduleFactory.php`
- **Files — modify:** `candy-code/src/Runtime.php`, `candy-code/src/Background/Schedule.php`, `candy-code/docs/SCHEDULES.md`, `candy-code/README.md`, `candy-code/docs/ARCHITECTURE.md`, `candy-code/docs/PERMISSIONS.md`, `candy-code/lang/en.php`
- **Tests — create:** `candy-code/tests/Tools/BuiltIn/ScheduleToolTest.php`, `candy-code/tests/Background/ScheduleFactoryTest.php` · **modify:** `candy-code/tests/Tools/Catalog/ToolCatalogTest.php`, `candy-code/tests/Tools/BuiltInToolCorpusTest.php`, `candy-code/tests/RuntimeTest.php`, `candy-code/tests/Permissions/ShellSubjectToolsCompletenessTest.php`
- **Docs/guards:** `php tools/gen-tool-docs.php --write` + `candy-code/tests/Tools/Catalog/ToolDocDriftTest.php`; a `candy-code/tests/Integration/ForeignAgentPresetWiringTest.php` census row for …
- **Serialized:** [catalog:position 41] [runtime:WRITE_CAPABLE_TOOL_NAMES] [docs:README.md] [docs:ARCHITECTURE.md] [docs:PERMISSIONS.md] [docs:SCHEDULES.md] [lang: `tool.schedule.*`]
- **Deps:** C-AGT-1.a (W1), C-AGT-1.c (W1), C-AGT-1.b (W6), C-AGT-7.a (W7)
- **Brief:** `prompt_kit/findings/comparison-1026/design-agt.md` → `### C-AGT-5.a`

#### ✅ a28124a7e C-SES-3.d — Live read-only share links
- **Item:** C-SES-3 (sources: CLAW-new#8) · feature · effort M
- **What:** **What it adds.** `/share serve --live [days]` publishes a link that re-renders the session's transcript at request time, so a reader sees existing and future messages. It keeps the same token, expiry and revoke semantics as …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Share/ShareStore.php`, `candy-code/src/Share/ShareRecord.php`, `candy-code/src/Server/Http/SharePageController.php`, `candy-code/src/Cli/Serve.php`, `candy-code/src/Commands/ShareCommand.php`, `candy-code/lang/en.php`, `candy-code/docs/SERVER.md`, `candy-code/docs/COMMANDS.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Server/SharePageTest.php`, `candy-code/tests/Share/ShareStoreTest.php`, `candy-code/tests/Commands/ShareCommandTest.php`
- **Docs/guards:** `CommandDocsDriftTest`. SERVER.md prose only; the method roster is unchanged.
- **Serialized:** [lang:cmd.share.live] [docs:SERVER.md] [docs:COMMANDS.md]
- **Deps:** C-SES-3.b (W2)
- **Ruling:** Ship anonymous LIVE read-only links, where anyone holding the token sees future messages until revoke or expiry? — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-3.d`

#### ✅ 7e97c9e4f C-TUI-6.k — Web dictation via the Web Speech API
- **Item:** C-TUI-6 (sources: GAP-WEB-13, GAP-MOD-23) · gap · effort S
- **What:** **Why normal, not LATE.** This is the cheap, independent sliver the brief allows. It is S effort, client-only, needs no ruling, touches no protocol or PHP, and has no dependency. `lib/dictation.ts`: `dictationSupported(win)` …
- **Files — create:** `candy-code-web/src-web/lib/dictation.ts`
- **Files — modify:** `candy-code-web/src-web/components/Composer.vue`
- **Tests — create:** `candy-code-web/src-web/lib/__tests__/dictation.spec.ts`, `candy-code-web/src-web/components/__tests__/composerDictation.spec.ts` · **modify:** none
- **Docs/guards:** vitest; dist rebuild at weld
- **Serialized:** [hot:candy-code-web/src-web/components/Composer.vue]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-6.k`

#### ✅ bec7fc651 C-AGT-6.b — `/review` ranges (`A..B`, `A...B`, `@{u}`)
- **Item:** C-AGT-6 (sources: p2-miss: GIT-10 review scope parity, GIT-1-P1 fix (b)) · gap · effort M
- **What:** `ContextMentions::diff()` (:234) learns ranges, through the same bounded `GitRunner` and safety flags: `A..B` → `git diff --no-ext-diff --no-textconv --no-color -M --patch A B`; `A...B` → `git diff --merge-base A B`, falling back …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Attachments/ContextMentions.php`, `candy-code/src/Commands/ReviewCommand.php`, `candy-code/lang/en.php`, `candy-code/docs/COMMANDS.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Attachments/ContextMentionsTest.php`, `candy-code/tests/Chat/ReviewCommandTest.php`
- **Docs/guards:** `candy-code/tests/Commands/CommandDocsDriftTest.php` (hint)
- **Serialized:** [hot:candy-code/src/Attachments/ContextMentions.php] [lang: `cmd.review.*`] [docs:COMMANDS.md]
- **Deps:** C-AGT-2.a (W1), C-AGT-2.b (W7)
- **Brief:** `prompt_kit/findings/comparison-1026/design-agt.md` → `### C-AGT-6.b`

### W14

- Chat.php region claims: P-TABS.e (withNoticeDrain (new wither)); C-TOOL-3.d (handleShellsCommand(new), isReadOnlySafeCommand)
- Bootstrap.php method claims: C-TOOL-3.d (workspace)

#### ✅ 2a6cd3c1f P-TABS.e — App hosts many Chats: scoped routing, park/unpark, background streaming (GAP-SES-12)
- **Item:** P-SESSION-TABS (sources: GAP-SES-12) · gap · effort M
- **What:** `App` gains a `SessionDeck`. Every Cmd a Chat returns, from `delegateToChat` (:4298), `runRegistryCommand` and `feedChat`, is wrapped by `TabScope::cmd($fgKey)`. App-level Cmds stay unscoped, so shell Msgs (SettingsSaved…) can …
- **Files — create:** none
- **Files — modify:** `candy-code/src/App/App.php`, `candy-code/src/Chat.php`, `candy-code/src/Commands/KeyBindingRegistry.php`, `candy-code/docs/ARCHITECTURE.md`, `candy-code/README.md`
- **Tests — create:** `candy-code/tests/App/BackgroundTabStreamingTest.php`, `candy-code/tests/App/TabScopedRoutingTest.php`, `candy-code/tests/App/TabCloseAndQuitTest.php` · **modify:** `candy-code/tests/Commands/KeyBindingDriftTest.php`, `candy-code/tests/App/AppModelTest.php`
- **Docs/guards:** `README.md` keys and tabs prose; `docs/ARCHITECTURE.md`; KeyBindingDriftTest moved in-step
- **Serialized:** [chat:withNoticeDrain (new wither)] [hot:candy-code/src/App/App.php] [hot:candy-code/src/Commands/KeyBindingRegistry.php] [docs:README.md] [docs:ARCHITECTURE.md]
- **Deps:** P-TABS.b (W4), P-TABS.d (W4), C-TOOL-3.c (W13)
- **Brief:** `prompt_kit/findings/comparison-1026/design-picks.md` → `### P-TABS.e`

#### ✅ 395bc096a C-WEB-3.e — Share link / Revoke on the session row
- **Item:** C-WEB-3 (sources: k1-dorm#5, SES-3-P4, WEB-1-P1, p2-miss: cross-session web share UI) · dormant-wire · S
- **What:** **Gap.** `session.share` and `session.unshare` (`SessionMethods.php:57-58`) have no caller. **Share.** The row menu gets "Share page…". It calls `session.share {sessionId, days?}` and shows `origin + path` with expiry and a copy …
- **Files — create:** `candy-code-web/src-web/components/__tests__/sidebarShare.spec.ts`
- **Files — modify:** `candy-code-web/src-web/stores/sessions.ts`, `candy-code-web/src-web/components/SessionSidebar.vue`
- **Tests — create:** `candy-code-web/src-web/components/__tests__/sidebarShare.spec.ts` · **modify:** none
- **Docs/guards:** `candy-code-web/README.md` sessions-sidebar bullet (soft); vitest; dist rebuild.
- **Serialized:** [hot:candy-code-web/src-web/components/SessionSidebar.vue] [hot:candy-code-web/src-web/stores/sessions.ts]
- **Deps:** C-SES-3.a (W1), C-SES-3.b (W2), C-SES-3.c (W2), C-SES-3.d (W13)
- **Brief:** `prompt_kit/findings/comparison-1026/design-web.md` → `### C-WEB-3.e`

#### ✅ 1945e347f C-TOOL-4.a — `sandbox` row in `candy-code doctor`
- **Item:** C-TOOL-4 (sources: CC-new#11 (AppArmor userns diagnostic in `doctor`), GAP-SH-06) · gap · effort S
- **What:** `Subcommands::doctorProbes()` (`candy-code/src/Cli/Subcommands.php:381`) gains a `sandbox` row, backed by a private `sandboxProbe(?string $sysctlPath = null)` seam. The row goes after `permission policy` and is spelled `'sandbox' …
- **Files — create:** `candy-code/tests/Cli/DoctorSandboxProbeTest.php`
- **Files — modify:** `candy-code/src/Cli/Subcommands.php`, `candy-code/src/Tools/BuiltIn/Bash.php`, `candy-code/src/Tools/Sandbox/Bubblewrap.php`, `candy-code/lang/en.php`, `candy-code/docs/TROUBLESHOOTING.md`, `candy-code/tests/Support/ReadPathCensusTest.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Tools/BashSandboxTest.php`
- **Docs/guards:** `DocFigureProseDriftTest` doctor-count pin (the `doctorProbes()` label scrape ↔ TROUBLESHOOTING.md "sixteen checks; exits 1 if any FAILs"); `ReadPathCensusTest`. Keep green: `DoctorFts5ProbeTest` …
- **Serialized:** [headless] [lang:cli.doctor.sandbox_] [docs:TROUBLESHOOTING.md] [hot:candy-code/src/Tools/BuiltIn/Bash.php] [hot:candy-code/tests/Support/ReadPathCensusTest.php]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-4.a`

#### ✅ 093ea6e13 C-AGT-4.a — Primary-agent core (preset → main backend)
- **Item:** C-AGT-4 (sources: GAP-AGT-01/02) · gap · effort M
- **What:** Add `Agents\PrimaryAgent`, which applies a preset to the MAIN backend and can be undone: narrow tools through a new public `AgentManager::toolsForPrimary(Agent)`, a wrapper over `resolveGrantedTools()` that carries C-AGT-3's MCP …
- **Files — create:** `candy-code/src/Agents/PrimaryAgent.php`, `candy-code/src/Agents/AgentMode.php`
- **Files — modify:** `candy-code/src/Agents/AgentManager.php`, `candy-code/src/Backend/EngineBackend.php`, `candy-code/src/Runtime.php`, `candy-code/src/Agents/AgentPreset.php`, `candy-code/src/Agents/AgentPresetRegistry.php`, `candy-code/src/Agents/ForeignAgentPresetRegistry.php`, `candy-code/src/Support/FrontmatterKeyAudit.php`, `candy-code/docs/AGENTS_AUTHORING.md`
- **Tests — create:** `candy-code/tests/Agents/PrimaryAgentTest.php` · **modify:** `candy-code/tests/Agents/AgentPresetRegistryTest.php`
- **Docs/guards:** `docs/AGENTS_AUTHORING.md`; `InertFrontmatterDocumentationDriftTest` if it enumerates known keys. The `ForeignAgentPresetWiringTest` permissionMode census needs a ROW for `Agents/PrimaryAgent.php`, …
- **Serialized:** [runtime:systemPromptSections] [hot:candy-code/src/Backend/EngineBackend.php] [hot:candy-code/src/Agents/AgentManager.php] [docs:AGENTS_AUTHORING.md]
- **Deps:** C-AGT-3.a (W6), C-AGT-3.b (W7)
- **Brief:** `prompt_kit/findings/comparison-1026/design-agt.md` → `### C-AGT-4.a`

#### ✅ 93448849594e9c779e12a6979f2d5ac7e721f05d C-AGT-5.b — `/schedules add`, fire outcomes, web schedules table
- **Item:** C-AGT-5 (sources: GAP-AUT-07, p1-miss: schedules UX) · gap · effort M
- **What:** `/schedules add <name> <interval> <prompt> [--mode m] [--provider p]` goes through `ScheduleFactory`, stamped with the host root. It is mutating, so it is refused in read-only windows; `READ_VERBS` is unchanged. The registry …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Host/Commands/SchedulesCommand.php`, `candy-code/src/Background/ScheduleRegistry.php`, `candy-code/src/Host/BackgroundEvents.php`, `candy-code/builtin-commands/2910-schedules.php`, `candy-code/lang/en.php`, `candy-code/docs/COMMANDS.md`, `candy-code/README.md`, `candy-code/docs/SCHEDULES.md`, `candy-code-web/src-web/components/panels/BackgroundPanel.vue`
- **Tests — create:** `candy-code-web/src-web/components/panels/__tests__/backgroundSchedules.spec.ts` · **modify:** `candy-code/tests/Host/Commands/LoopSchedulesCommandTest.php`, `candy-code/tests/Host/BackgroundEventsSchedulesTest.php`
- **Docs/guards:** `php tools/gen-command-docs.php --write` + `candy-code/tests/Commands/CommandDocsDriftTest.php`; `docs/SCHEDULES.md`; rebuild `candy-code-web/dist/` (weld)
- **Serialized:** [commands:2910-schedules.php] [hot:candy-code/src/Host/BackgroundEvents.php] [hot:candy-code-web/src-web/components/panels/BackgroundPanel.vue] [lang] [docs:COMMANDS.md] [docs:README.md] …
- **Deps:** C-AGT-5.a (W13)
- **Brief:** `prompt_kit/findings/comparison-1026/design-agt.md` → `### C-AGT-5.b`

#### ✅ 533a881ca C-EXT-4.a — Marketplace core: manifest reader, registry, provenance, search, recommender
- **Item:** C-EXT-4 (sources: GAP-PLG-02, GAP-SKL-09, p1-miss plugin registry tier, CC-new#12 recommendations) · gap · effort M
- **What:** **`Marketplace`.** Parses Claude's `.claude-plugin/marketplace.json`: name, owner, `plugins[]` with a source (relative path, `{source:github,repo}` or `{source:url|git,url}`), description, version, keywords/tags/category. It …
- **Files — create:** `candy-code/src/Bundles/Marketplace.php`, `candy-code/src/Bundles/MarketplaceEntry.php`, `candy-code/src/Bundles/MarketplaceRegistry.php`, `candy-code/src/Bundles/BundleSource.php`, `candy-code/src/Bundles/BundleRecommender.php`
- **Files — modify:** none
- **Tests — create:** `candy-code/tests/Bundles/MarketplaceTest.php`, `candy-code/tests/Bundles/MarketplaceRegistryTest.php`, `candy-code/tests/Bundles/BundleSourceTest.php`, `candy-code/tests/Bundles/BundleRecommenderTest.php` · **modify:** none
- **Docs/guards:** ReadPathCensusTest and ContainedPathInventoryTest rows for the new user-tier reads; one type per file.
- **Serialized:** none
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-ext.md` → `### C-EXT-4.a`

#### ✅ b4ff5dec3 C-PRV-2.a — OpenAI Responses wire (core)
- **Item:** C-PRV-2 (sources: GAP-MOD-02, p1-miss: OpenAI Responses API) · gap · effort M
- **What:** Today there is Chat Completions only. The vendored `openai-php/client` ^0.10 has no Responses resource. Add an opt-in `api: "responses"` key on `openai` blocks. A new trait, `Concerns\OpenAiResponsesWire`, speaks `POST …
- **Files — create:** `candy-code/src/Providers/Concerns/OpenAiResponsesWire.php`, `candy-code/tests/Providers/OpenAiResponsesWireTest.php`
- **Files — modify:** `candy-code/src/Providers/OpenAIProvider.php`, `candy-code/src/Providers/ProviderFactory.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Providers/ProviderFactoryTest.php`
- **Docs/guards:** `candy-code/docs/SETTINGS.md` (provider block `api` prose), `candy-code/docs/ADDING_A_PROVIDER.md`
- **Serialized:** [docs:SETTINGS.md] [docs:ADDING_A_PROVIDER.md] [hot:candy-code/src/Providers/OpenAIProvider.php] [hot:candy-code/src/Providers/ProviderFactory.php]
- **Deps:** C-PRV-1.a (W2)
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-PRV-2.a`

#### ✅ bc898875f15a828b0d7dba103cf5a6a0321a992c C-SES-7.a — Session importer core (CC / Codex / OpenCode / candy-code)
- **Item:** C-SES-7 (sources: GAP-SES-10, CLAW-new#3, p2-miss: session import) · gap · effort M
- **What:** **Formats.** `SessionImporter` auto-detects four formats: candy-code's own export JSON (`Exporter::toJson`, read back via `Message::fromArray`); Claude Code project JSONL; Codex rollout JSONL; the OpenCode `export` JSON. …
- **Files — create:** `candy-code/src/Session/Import/SessionImporter.php`, `candy-code/src/Session/Import/ImportFormat.php`, `candy-code/src/Session/Import/ImportedTranscript.php`, `candy-code/src/Session/Import/CandyCodeExportReader.php`, `candy-code/src/Session/Import/ClaudeCodeTranscriptReader.php`, `candy-code/src/Session/Import/CodexTranscriptReader.php`, `candy-code/src/Session/Import/OpenCodeExportReader.php`, `candy-code/src/Session/Import/SessionImportLedger.php`, `candy-code/src/Session/Import/ImportRefusedException.php`, `candy-code/tests/Session/Import/SessionImporterTest.php`, `candy-code/tests/Session/Import/TranscriptReadersTest.php`
- **Files — modify:** `candy-code/lang/en.php`
- **Tests — create:** `candy-code/tests/Session/Import/TranscriptReadersTest.php`, `candy-code/tests/Session/Import/SessionImporterTest.php` · **modify:** none
- **Docs/guards:** none (the CLI and RPC surfaces come in C-SES-7.b and C-SES-7.e)
- **Serialized:** [lang:session.import.]
- **Deps:** C-SES-2.a (W2)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-7.a`

#### ✅ 734f97dda76b02e912933a996f62368877349cdc C-TOOL-3.d — `/shells` list/output/stop
- **Item:** C-TOOL-3 (sources: GAP-SH-04, CLAW-new#6 (TUI half)) · gap · effort M
- **What:** The human has no view of background shells; ShellOutput and ShellStop are model-only. Add a `/shells` built-in with a host command, so served and web sessions get it free through `command.exec`, with verbs: `list` (bare form): …
- **Files — create:** `candy-code/builtin-commands/2905-shells.php`, `candy-code/src/Host/Commands/ShellsCommand.php`, `candy-code/tests/Host/Commands/ShellsCommandTest.php`
- **Files — modify:** `candy-code/src/Cli/Bootstrap.php`, `candy-code/src/Chat.php`, `candy-code/lang/en.php`, `candy-code/README.md`, `candy-code/docs/COMMANDS.md`, `candy-code/docs/ARCHITECTURE.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Commands/MenuBarLabelDerivationTest.php`, `candy-code/tests/Chat/PaletteSupersetDispatchTest.php`, `candy-code/tests/Commands/BuiltInCommandsTest.php`
- **Docs/guards:** `CommandDocsDriftTest`, `ReadmeRosterDriftTest`, `DocFigureProseDriftTest` (ARCHITECTURE.md slash-command count) all moved in-step
- **Serialized:** [chat:handleShellsCommand(new),isReadOnlySafeCommand] [commands] [bootstrap:workspace] [lang:cmd.shells.,host.shells.] [docs:README.md] [docs:COMMANDS.md] [docs:ARCHITECTURE.md]
- **Deps:** C-TOOL-3.c (W13)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-3.d`

#### ✅ ba057cad2 C-TUI-2.a — Highlighter language breadth + path→language
- **Item:** C-TUI-2 (sources: GAP-UI-04) · gap · effort M
- **What:** RegexHighlighter ships seven keyword sets: php, js, ts, python, go, bash and sql (`sugar-core/src/Syntax/RegexHighlighter.php:42-96`). Add rust, java, c/cpp, ruby, yaml and html/css, with aliases (`rs`, `cc`/`h`/`hpp`, `rb`, …
- **Files — create:** none
- **Files — modify:** `sugar-core/src/Syntax/RegexHighlighter.php`, `sugar-markdown/src/SyntaxHighlighter.php`, `sugar-markdown/README.md`
- **Tests — create:** none · **modify:** `sugar-core/tests/Syntax/RegexHighlighterTest.php`, `sugar-markdown/tests/SyntaxHighlighterTest.php`
- **Docs/guards:** sugar-core and sugar-markdown suites; `sugar-markdown` ReadmeSnippetsTest
- **Serialized:** none
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-2.a`

### W15

- Chat.php region claims: P-TABS.f (constructor(tabHosted) + presentSession + handleSessionPickerKey + Agent-View open-as-session + handlePaletteNewSession + refuseWhileInFlight + palette arm, ctor); C-AGT-5.c (pumpBackgroundSessions); C-AGT-6.d (handlePrCommand (new), reviewTurn)
- Bootstrap.php method claims: C-CTX-4.c (forcedInstructions, instructionLoader)

#### ✅ 0fb10ffbf0ca7014da60b7212026ad0e8e6d68a7 P-TABS.f — deck-aware session routes + palette Close tab
- **Item:** P-SESSION-TABS (sources: GAP-SES-12) · gap · effort M
- **What:** Inside Chat, four routes still swap the session in place through `switchToSession()` (`src/Chat.php:2355`): session-picker Enter (:15166) and fork (:15080); Agent View "open as session" (:9263); palette New session …
- **Files — create:** `candy-code/builtin-commands/0105-close-tab.php`
- **Files — modify:** `candy-code/src/Chat.php`, `candy-code/src/Palette/PaletteAction.php`, `candy-code/src/App/App.php`, `candy-code/lang/en.php`, `candy-code/docs/COMMANDS.md`
- **Tests — create:** `candy-code/tests/Chat/DeckHostedSessionRoutesTest.php` · **modify:** `candy-code/tests/MouseModalGuardTest.php`
- **Docs/guards:** `docs/COMMANDS.md` via `tools/gen-command-docs.php` with `tests/Commands/CommandDocsDriftTest.php`; the README slash roster does not move (`slashVisible: false`)
- **Serialized:** [chat:constructor(tabHosted) + presentSession + handleSessionPickerKey + Agent-View open-as-session + handlePaletteNewSession + refuseWhileInFlight + palette arm,ctor] [lang] (prefix `chat.tabs.`, …
- **Deps:** P-TABS.e (W14)
- **Brief:** `prompt_kit/findings/comparison-1026/design-picks.md` → `### P-TABS.f`

#### ✅ 770f8ee9bc75726c86f323a751d1233145130eda C-WEB-3.g — Dormant-method roster spec (fail-loud on new uncalled RPCs)
- **Item:** C-WEB-3 (sources: WEB-1-P1 (fix: dormancy roster spec)) · dormant-wire · S
- **What:** **What the spec derives.** One spec: the `METHOD_SCOPES` keys (`protocol/generated.ts`) minus every method a shipped module calls. It reuses the `shutdownDormant.spec.ts` source-scan regex (`request('M'` / `method: 'M'`). **What …
- **Files — create:** `candy-code-web/src-web/__tests__/dormantRoster.spec.ts`
- **Files — modify:** none
- **Tests — create:** `candy-code-web/src-web/__tests__/dormantRoster.spec.ts` · **modify:** none
- **Docs/guards:** vitest. The `candy-code/COMPARISON.md` §6.7 wording is the orchestrator's (see Notes).
- **Serialized:** [hot:candy-code-web/src-web/__tests__/dormantRoster.spec.ts]
- **Deps:** C-WEB-3.a (W8), C-WEB-3.b (W8), C-WEB-3.c (W8), C-WEB-3.d (W9), C-WEB-3.e (W14)
- **Brief:** `prompt_kit/findings/comparison-1026/design-web.md` → `### C-WEB-3.g`

#### ✅ e2bc4292dafc00444a13fc9687e4622507981fd3 C-TUI-2.b — sugar-diff side-by-side writer
- **Item:** C-TUI-2 (sources: GAP-UI-05) · gap · effort S
- **What:** sugar-diff writes unified output only (`sugar-diff/src/Writer/UnifiedFormat.php`). Add `SideBySideFormat`: `rows(Diff)` and `fromUnified(string)`, the latter over `Reader/UnifiedScan`. Both return paired `[left?, right?]` rows. …
- **Files — create:** `sugar-diff/src/Writer/SideBySideFormat.php`, `sugar-diff/src/Writer/SideBySideRow.php`
- **Files — modify:** `sugar-diff/README.md`
- **Tests — create:** `sugar-diff/tests/Writer/SideBySideFormatTest.php` · **modify:** none
- **Docs/guards:** sugar-diff suite; `tools/check-one-type-per-file.php`
- **Serialized:** none
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-2.b`

#### ✅ 9e22d932fab8c5c4942defd5aa6e84b67e57a158 C-WEB-4.a — Composer: drag-drop images, Ctrl+G history search, paste chips
- **Item:** C-WEB-4 (sources: GAP-UI-12, GAP-UI-09, GAP-UI-11) · gap · M
- **What:** Client-only, three features: **Drag-drop.** `@dragover.prevent`/`@drop` on the composer feeds `readPastedImages(event.dataTransfer)`. A DataTransfer already satisfies `ClipboardFiles` (`lib/paste.ts`). Drops use the same …
- **Files — create:** `candy-code-web/src-web/components/HistorySearch.vue`, `candy-code-web/src-web/components/__tests__/composerDropAndChips.spec.ts`
- **Files — modify:** `candy-code-web/src-web/components/Composer.vue`, `candy-code-web/src-web/lib/paste.ts`, `candy-code-web/src-web/stores/promptHistory.ts`
- **Tests — create:** `candy-code-web/src-web/components/__tests__/composerDropAndChips.spec.ts` · **modify:** `candy-code-web/src-web/lib/__tests__/paste.spec.ts`, `candy-code-web/src-web/stores/__tests__/promptHistory.spec.ts`
- **Docs/guards:** `candy-code-web/README.md` Composer bullet (soft); vitest; dist rebuild.
- **Serialized:** [hot:candy-code-web/src-web/components/Composer.vue]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-web.md` → `### C-WEB-4.a`

#### ✅ ff23948e797c90df66a4fa423b6323414b3552d0 C-AGT-5.c — Heartbeat schedule kind
- **Item:** C-AGT-5 (sources: GAP-PA-02) · gap · effort M
- **What:** A row gains `kind: "task"|"heartbeat"`. For a heartbeat, `ScheduleSpawner` appends "reply exactly HEARTBEAT_OK when nothing needs attention". When the daemon settles, Chat's `pumpBackgroundSessions()` suppresses the queued …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Background/Schedule.php`, `candy-code/src/Background/ScheduleSpawner.php`, `candy-code/src/Chat.php`, `candy-code/src/Host/Commands/SchedulesCommand.php`, `candy-code/src/Tools/BuiltIn/ScheduleTool.php`, `candy-code/lang/en.php`, `candy-code/docs/SCHEDULES.md`
- **Tests — create:** `candy-code/tests/Chat/HeartbeatScheduleTest.php` · **modify:** `candy-code/tests/Sessions/ScheduleTest.php`
- **Docs/guards:** `docs/SCHEDULES.md`
- **Serialized:** [chat:pumpBackgroundSessions] [lang: `chat.schedule.*`] [docs:SCHEDULES.md]
- **Deps:** C-AGT-5.b (W14)
- **Brief:** `prompt_kit/findings/comparison-1026/design-agt.md` → `### C-AGT-5.c`

#### ✅ 23e4dae805c2996d1df1921fe9a2d12ddae9b261 C-AGT-5.e — Outbound webhooks core (sign, async send, retry)
- **Item:** C-AGT-5 (sources: p1-miss: outbound webhooks, p2-miss: outbound webhooks) · gap · effort M
- **What:** Add a self-contained `Automation` namespace, with no serialized files: `OutboundWebhookConfig` parses a user-tier map `{name: {url, secret, events[]}}`. It requires https, or http only to loopback, and refuses redirects; …
- **Files — create:** `candy-code/src/Automation/OutboundWebhookConfig.php`, `candy-code/src/Automation/WebhookSigner.php`, `candy-code/src/Automation/WebhookEvent.php`, `candy-code/src/Automation/WebhookDispatcher.php`
- **Files — modify:** none
- **Tests — create:** `candy-code/tests/Automation/OutboundWebhookConfigTest.php`, `candy-code/tests/Automation/WebhookSignerTest.php`, `candy-code/tests/Automation/WebhookDispatcherTest.php` · **modify:** none
- **Docs/guards:** none in-step (5.f documents)
- **Serialized:** none
- **Deps:** C-AGT-1.a (W1), C-AGT-1.c (W1), C-AGT-1.b (W6)
- **Brief:** `prompt_kit/findings/comparison-1026/design-agt.md` → `### C-AGT-5.e`

#### ✅ 0cbd4e7c787bb41c66b7f49b6c1d25926c7b6619 C-AGT-5.g — GitLab CI recipe around the signed phar
- **Item:** C-AGT-5 (sources: p2-miss: GitLab CI component) · gap · effort S
- **What:** Add a `docs/CI.md` "GitLab CI" section: a `.gitlab-ci.yml` job that downloads the `candy-code/v*` release phar, verifies sha256 and the pinned-key minisig (the `release-phar.yml` assets), and runs `-p --output-format json …
- **Files — create:** none
- **Files — modify:** `candy-code/docs/CI.md`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** `docs/CI.md`
- **Serialized:** [docs:CI.md]
- **Deps:** C-AGT-1.c (W1), C-AGT-2.c (W2)
- **Brief:** `prompt_kit/findings/comparison-1026/design-agt.md` → `### C-AGT-5.g`

#### ✅ b6369af7869b565dea865497f3b8ba515f407eb0 C-AGT-6.d — `/pr` canned turn + `/review --pr <n>` (+ roster flips 8dab423f0)
- **Item:** C-AGT-6 (sources: GAP-GIT-04, p2-miss: GIT-10 review scope parity (`--pr`)) · gap · effort M
- **What:** `/pr [focus]` is a canned turn (a `PrCommand::PROMPT` const, shaped like `ReviewCommand`) telling the agent to push the branch and open a PR with `gh pr create`, honouring `attribution.pr`. Its host body `PrHostCommand` returns …
- **Files — create:** `candy-code/builtin-commands/2860-pr.php`, `candy-code/src/Commands/PrCommand.php`, `candy-code/src/Host/Commands/PrHostCommand.php`, `candy-code/src/Workspace/GhRunner.php`
- **Files — modify:** `candy-code/src/Attachments/ContextMentions.php`, `candy-code/src/Chat.php`, `candy-code/src/Commands/ReviewCommand.php`, `candy-code/lang/en.php`, `candy-code/docs/COMMANDS.md`, `candy-code/README.md`
- **Tests — create:** `candy-code/tests/Host/Commands/PrHostCommandTest.php`, `candy-code/tests/Workspace/GhRunnerTest.php` · **modify:** `candy-code/tests/Attachments/ContextMentionsTest.php`
- **Docs/guards:** `php tools/gen-command-docs.php --write` + `CommandDocsDriftTest`, `ReadmeRosterDriftTest`. If the lane must add a fresh spawn instead of `runCaptured`, it goes through …
- **Serialized:** [chat:handlePrCommand (new), reviewTurn] [hot:candy-code/src/Attachments/ContextMentions.php] [lang: `cmd.pr.*`, `cmd.review.*`] [docs:COMMANDS.md] [docs:README.md]. `builtin-commands/2860-pr.php` is …
- **Deps:** C-AGT-6.b (W13)
- **Ruling:** R13 (remote security). May candy-code shell out to `gh` (the user's GitHub credentials) for `/review --pr` and drive `gh pr create` from `/pr`? — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-agt.md` → `### C-AGT-6.d`

#### ✅ d361720d90920f17c1d42ef19032cb5768b24c43 C-CTX-4.c — Walk past the git root; absolute and `~` user-tier sources
- **Item:** C-CTX-4 (sources: GAP-INS-07, GAP-INS-11 (absolute half)) · gap · effort S
- **What:** A new user-tier bool `instructions.walkToRoot` lets `ancestorRoot()` (`InstructionFileLoader.php:845-880`) continue above the nearest `.git` up to, but not including, `$HOME`, under the same containment and budget. `loadForced()` …
- **Files — create:** `candy-code/tests/Context/InstructionWalkPastRootTest.php`
- **Files — modify:** `candy-code/src/Context/InstructionFileLoader.php`, `candy-code/src/Cli/Bootstrap.php`, `candy-code/src/Config/Settings/Definitions/MemoryRuleSettings.php`, `candy-code/src/Config/LayeredSettings.php`, `candy-code/docs/SETTINGS.md`, `candy-code/docs/MEMORY.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Support/ContainedPathInventoryTest.php`
- **Docs/guards:** settings gen + `SettingsSchemaDocDriftTest`; `ContainedPathInventoryTest`
- **Serialized:** [bootstrap:instructionLoader, forcedInstructions] [docs:SETTINGS.md] [docs:MEMORY.md] [hot:candy-code/src/Context/InstructionFileLoader.php] …
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-CTX-4.c`

#### ✅ a1adc4f35bf1efc8d96f161ddf84b690cc3b3c74 C-EXT-3.c — Bundled `skill-creator` + `/skills new <name>` scaffold
- **Item:** C-EXT-3 (sources: GAP-SKL-14) · gap · effort S
- **What:** Ship a ninth built-in skill, `skill-creator`. It teaches the `SKILL.md` format and the KNOWN keys from `FrontmatterKeyAudit` with their now-honoured meaning (fork, `allowed-tools`, `${CLAUDE_SKILL_DIR}`). It also covers gating …
- **Files — create:** `candy-code/src/Skills/BuiltIn/skill-creator/SKILL.md`
- **Files — modify:** `candy-code/src/Host/Commands/SkillsHostCommand.php`, `candy-code/lang/en.php`, `candy-code/docs/SKILLS.md`, `candy-code/README.md`, `candy-code/docs/COMMANDS.md`
- **Tests — create:** `candy-code/tests/Host/Commands/SkillsNewTest.php` · **modify:** `candy-code/tests/Skills/BuiltInSkillsTest.php`
- **Docs/guards:** README and SKILLS.md built-in counts; gen-command-docs.
- **Serialized:** [docs:SKILLS.md] [docs:README.md] [docs:COMMANDS.md] [lang:host.skills.new.] [hot:candy-code/src/Host/Commands/SkillsHostCommand.php]
- **Deps:** C-EXT-2.b (W8), C-EXT-2.c (W10)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ext.md` → `### C-EXT-3.c`

### W16

- protocol lanes: C-CTX-2.d (weld regenerates schema → SERVER.md → generated.ts once)
- Bootstrap.php method claims: C-SEC-7.d (permissionGate)

#### ✅ d489f425f407c2768d9679a70dde6b271edee6f9 P-TABS.g — Shell window strip + Sessions pane, attention badges, OSC 2 title (GAP-UI-N6)
- **Item:** P-SESSION-TABS (sources: GAP-UI-N6, GAP-SES-12) · gap · effort M
- **What:** `App::windowList()` builds the `WindowList` from the deck: tab names, attention and the summary through `SessionTabs::updateTabSummary` (`⠋ step 4 · 2 agents` from `AgentLiveRegistry::forSession`). **Horizontal.** …
- **Files — create:** none
- **Files — modify:** `candy-code/src/App/App.php`, `candy-code/src/Tui/Renderer.php`, `candy-code/src/Renderer.php`, `candy-code/src/Tui/Pane.php`, `candy-code/src/Tui/Components/MenuBar.php`, `candy-code/src/Tui/Components/SessionsPane.php`, `candy-code/src/Commands/KeyBindingRegistry.php`, `candy-code/lang/en.php`, `candy-code/README.md`
- **Tests — create:** `candy-code/tests/Tui/WindowStripShellTest.php`, `candy-code/tests/App/TabAttentionTest.php`, `candy-code/tests/App/WindowTitleTest.php` · **modify:** `candy-code/tests/Tui/PaneTest.php`, `candy-code/tests/Commands/KeyBindingDriftTest.php`
- **Docs/guards:** `README.md`; `tests/Commands/KeyBindingDriftTest.php` moved in-step
- **Serialized:** [renderer:Tui/Renderer.php renderView+renderPane; Renderer.php renderSessionTabStrip + AgentStrip call] (layout #3 of 3) [lang] (prefix `tui.window.title.`, `keys.sessions.`) [docs:README.md] …
- **Deps:** P-TABS.a (W5), P-AGENT.a (W6), P-TABS.c (W6), P-CTX.e (W7), P-TABS.f (W15)
- **Brief:** `prompt_kit/findings/comparison-1026/design-picks.md` → `### P-TABS.g`

#### ✅ 15c1f71fcf1a6be500f58726424d1d097eddb020 C-CTX-2.d — Served prompt suggestions and a `context.changed` event (protocol lane)
- **Item:** C-CTX-2 (sources: GAP-UI-34, GAP-INS-08w (event half), k1-dorm#6) · gap · effort M
- **What:** `ContextMethods::instructionWrite` also announces `context.changed {reason: instructions}` (C-CTX-3.c relies on it). Two new ephemeral events. `prompt.suggested {text, generation}`: On settle, the suggestion closure from …
- **Files — create:** `candy-code/tests/Host/ServedPromptSuggestionTest.php`, `candy-code-web/src-web/components/__tests__/composerSuggestion.spec.ts`, `candy-code-web/src-web/stores/__tests__/contextChanged.spec.ts`
- **Files — modify:** `candy-code/src/Host/SessionEvent.php`, `candy-code/src/Protocol/EventType.php`, `candy-code/src/Protocol/Schema/EventSchemas.php`, `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/src/Host/SessionHost.php`, `candy-code-web/src-web/protocol/generated.ts`, `candy-code-web/src-web/stores/reducer.ts`, `candy-code-web/src-web/components/Composer.vue`, `candy-code-web/src-web/stores/contextSources.ts`
- **Tests — create:** none · **modify:** `candy-code/tests/Protocol/EventTypeTest.php`
- **Docs/guards:** `candy-code/docs/SERVER.md` (### Events), guarded by `candy-code/tests/Config/ServerProtocolDocumentationDriftTest.php`; `candy-code/tests/Protocol/ProtocolSchemaDriftTest.php`; both gen `--check`s.
- **Serialized:** [protocol] [docs:SERVER.md] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code-web/src-web/components/Composer.vue] [hot:candy-code-web/src-web/stores/contextSources.ts]
- **Deps:** C-CTX-2.b (W6), C-CTX-2.c (W9)
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-CTX-2.d`

#### ✅ 3bc062fc4d7c0787f389869dcee49ffd7944cc3b C-EXT-4.b — `bundles marketplace add/list/remove/update`, `install name@mkt`, `update`, `search`, `recommend`
- **Item:** C-EXT-4 (sources: GAP-PLG-02, GAP-SKL-09) · gap · effort M
- **What:** `Subcommands::bundles()` (:1464) gains these verbs: **`marketplace add <git-url|path>`.** Clones with the EXISTING `bundlesCloneDefault()` spawn into a scratch dir, validates, pins the SHA, strips `.git` and renames into place. …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Cli/Subcommands.php`, `candy-code/lang/en.php`, `candy-code/docs/PLUGINS.md`, `candy-code/README.md`
- **Tests — create:** `candy-code/tests/Bundles/BundleMarketplaceCliTest.php`, `candy-code/tests/Bundles/BundleUpdateTest.php` · **modify:** `candy-code/tests/Bundles/BundleInstallTest.php`
- **Docs/guards:** `ParsedArgs::SUBCOMMAND_FLAGS` if a flag is added; a DocFigure subcommand fence if one pins `bundles`.
- **Serialized:** [headless] [docs:PLUGINS.md] [docs:README.md] [lang:cli.bundles.]
- **Deps:** C-SEC-1.a (W1), C-SEC-3.a (W2), C-SEC-3.b (W3), C-EXT-7.b (W7), C-EXT-4.a (W14)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ext.md` → `### C-EXT-4.b`

#### ✅ 179ac2e3f7e7ccb11e25d12b103503ef78c64297 C-EXT-5.a — PaletteCatalog core (action + prefill rows)
- **Item:** C-EXT-5 (sources: GAP-CMD-08) · gap · effort S
- **What:** a pure `PaletteCatalog::rows(list<CommandSpec> $slashRows): list<PaletteEntry>`. It emits every `PaletteAction` row, with order and labels unchanged. It then adds one Prefill row per slash-visible spec that has no …
- **Files — create:** `candy-code/src/Palette/PaletteCatalog.php`, `candy-code/src/Palette/PaletteEntry.php`, `candy-code/src/Palette/PaletteEntryKind.php`
- **Files — modify:** none
- **Tests — create:** `candy-code/tests/Palette/PaletteCatalogTest.php` · **modify:** none
- **Docs/guards:** none (one type per file)
- **Serialized:** none
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-ext.md` → `### C-EXT-5.a`

#### ✅ 5cac857fc31c7534b1da515a50dcd7683dce91b3 C-MCP-3.b — Wire server requests into turns (ask channel, progress, headless)
- **Item:** C-MCP-3 (sources: GAP-MCP-08, OC-new#1, CC-new#2) · gap · effort M
- **What:** MCP tools never see the turn's approver: `EngineBackend::turnTools()` binds `RelaysPermissionAsks` only inside the `DelegatesToEngine` arm (`candy-code/src/Backend/EngineBackend.php:3209-3283`), and `McpToolBridge` is `Tool, …
- **Files — create:** `candy-code/tests/Tools/McpToolProgressTest.php`, `candy-code/tests/Cli/NonInteractiveMcpToolsHeadlessTest.php`
- **Files — modify:** `candy-code/src/Tools/McpToolBridge.php`, `candy-code/src/Tools/BuiltIn/McpCallTool.php`, `candy-code/src/Tools/BuiltIn/AskUserTool.php`, `candy-code/src/Backend/EngineBackend.php`, `candy-code/src/Cli/NonInteractive.php`, `candy-code/lang/en.php`, `candy-code/docs/MCP.md`
- **Tests — create:** `candy-code/tests/Tools/McpToolProgressTest.php`, `candy-code/tests/Cli/NonInteractiveMcpToolsHeadlessTest.php` · **modify:** `candy-code/tests/Tools/BuiltInToolCorpus.php`
- **Docs/guards:** the `LangParityTest` keys (prefix `mcp.elicitation.`); the new MCP.md section gets no DocFigure arm unless the lane adds one (recommended: pin the advertised capability set against …
- **Serialized:** [headless:NonInteractive] [lang:mcp.elicitation.] [docs:MCP.md §Server requests (new)] [hot:candy-code/src/Backend/EngineBackend.php] [hot:candy-code/src/Tools/McpToolBridge.php]
- **Deps:** C-MCP-2.a (W11), C-MCP-3.a (W13)
- **Brief:** `prompt_kit/findings/comparison-1026/design-mcp.md` → `### C-MCP-3.b`

#### ✅ 6beef34f4c8e11b67c1253dd6b99f67d13855230 C-MCP-4.b — Runtime `/mcp enable|disable <server> [--persist]`
- **Item:** C-MCP-4 (sources: GAP-MCP-10) · gap · effort M
- **What:** Today a server can be disabled only through `disabledMcpServers` plus a relaunch (E696 deny map, applied at start and in `isServerAllowed()`, `candy-code/src/MCP/McpClient.php:204-207, 893-901`). `McpClient` gains …
- **Files — create:** `candy-code/tests/Commands/McpAuthCommandEnableDisableTest.php`, `candy-code/tests/MCP/McpRuntimeDisableTest.php`
- **Files — modify:** `candy-code/src/MCP/McpClient.php`, `candy-code/src/Commands/McpAuthCommand.php`, `candy-code/src/Tui/McpPanel.php`, `candy-code/lang/en.php`, `candy-code/docs/MCP.md`, `candy-code/docs/COMMANDS.md`
- **Tests — create:** `candy-code/tests/MCP/McpRuntimeDisableTest.php`, `candy-code/tests/Commands/McpAuthCommandEnableDisableTest.php` · **modify:** `candy-code/tests/Tui/McpPanelTest.php`
- **Docs/guards:** `DocFigureProseDriftTest`: `::testMcpCommandsSurfaceCountsHelpRowsAndTheRunArm` (match-arm order pin at :4050-4061, "five sub-commands" → "seven", the `cmd.mcp-auth.unknown` roster); …
- **Serialized:** [lang:cmd.mcp-auth.] [docs:MCP.md §Server auth + §Liveness] [docs:COMMANDS.md] [hot:candy-code/src/MCP/McpClient.php] [hot:candy-code/src/Commands/McpAuthCommand.php]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-mcp.md` → `### C-MCP-4.b`

#### ✅ cf36a8458c6bb299af289c23bafe4a3ef7f04423 C-PRV-2.b — Responses reasoning replay and image tool outputs
- **Item:** C-PRV-2 (sources: GAP-MOD-02 (reasoning items), p1-miss: OpenAI Responses API (encrypted reasoning replay), candidates: "Responses also unlocks images in tool … · gap · effort M
- **What:** On the responses arm, request `include: ["reasoning.encrypted_content"]` and `reasoning.summary: "auto"`. Stream `response.reasoning_summary_text.delta` into the existing thinking channel. Store each reasoning item (`id`, …
- **Files — create:** `candy-code/tests/Providers/OpenAiResponsesReasoningTest.php`
- **Files — modify:** `candy-code/src/Providers/Concerns/OpenAiResponsesWire.php`, `candy-code/src/Providers/OpenAIProvider.php`, `candy-code/src/Message.php`, `candy-code/src/Providers/Concerns/AnthropicMessagesWire.php`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** `candy-code/docs/ADDING_A_PROVIDER.md` (thinkingBlocks ownership)
- **Serialized:** [docs:ADDING_A_PROVIDER.md] [hot:candy-code/src/Providers/OpenAIProvider.php] [hot:candy-code/src/Message.php] [hot:candy-code/src/Providers/Concerns/AnthropicMessagesWire.php]
- **Deps:** C-PRV-2.a (W14)
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-PRV-2.b`

#### ✅ be226b2b03a94ae22db8d8145410c45b54a7e3a0 C-SEC-6.a — Saved-rule service and `/permissions saved` / `forget`
- **Item:** C-SEC-6 (sources: OC-new#3, GAP-PER-04) · gap · effort M
- **What:** Saved "always" grants (`p` → `permissionRules` in the user config) can only be added today. The one writer is `PermissionPrompt::persistRules()` (`src/Permissions/PermissionPrompt.php:262-305`). Extract a `SavedRules` service: …
- **Files — create:** `candy-code/src/Permissions/SavedRules.php`
- **Files — modify:** `candy-code/src/Permissions/PermissionPrompt.php`, `candy-code/src/Host/Commands/PermissionsCommand.php`, `candy-code/lang/en.php`, `candy-code/docs/PERMISSIONS.md`, `candy-code/docs/COMMANDS.md`
- **Tests — create:** `candy-code/tests/Permissions/SavedRulesTest.php`, `candy-code/tests/Host/Commands/PermissionsSavedRulesCommandTest.php` · **modify:** none
- **Docs/guards:** `tools/gen-command-docs.php --check`; `PermissionsIntroDriftTest` if the intro enumerates subcommands
- **Serialized:** [lang:host.permissions.] [lang:cmd.permissions.hint] [docs:PERMISSIONS.md] [docs:COMMANDS.md]. The `builtin-commands/1200-permissions.php` row is not edited; only its Lang value changes.
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-sec.md` → `### C-SEC-6.a`

#### ✅ fecec0d995ce520f8f131a2984d1f417b5051806 C-SEC-7.d — Model-judged review for writes, with a chosen reviewer model
- **Item:** C-SEC-7 (sources: GAP-PER-07, CC-new#8) · gap · effort M
- **What:** `PermissionGate::autoReviewsUnflaggedShell()` (:998-1003) sends only shell calls to `ExecReviewer`. The reviewer is always built on the title backend (`Bootstrap.php:6849-6862`). Generalise it to `autoReviewsUnflagged()`, driven …
- **Files — create:** `candy-code/tests/Permissions/AutoReviewScopeTest.php`
- **Files — modify:** `candy-code/src/Permissions/PermissionGate.php`, `candy-code/src/Permissions/ExecReviewer.php`, `candy-code/src/Config/Settings/Definitions/PermissionSettings.php`, `candy-code/src/Config/LayeredSettings.php`, `candy-code/src/Cli/Bootstrap.php`, `candy-code/lang/en.php`, `candy-code/docs/SETTINGS.md`, `candy-code/README.md`, `candy-code/docs/PERMISSIONS.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Permissions/UnflaggedShellReviewTest.php`, `candy-code/tests/Permissions/ExecReviewerTest.php`
- **Docs/guards:** `tools/gen-settings-doc.php --check`; `TrustKeyDocumentationDriftTest` (README user-tier-only roster and word number); `DocFigureProseDriftTest` (SETTINGS.md count)
- **Serialized:** [bootstrap:permissionGate] [hot:candy-code/src/Permissions/PermissionGate.php] [hot:candy-code/src/Config/LayeredSettings.php] [hot:candy-code/src/Config/Settings/Definitions/PermissionSettings.php] …
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-sec.md` → `### C-SEC-7.d`

#### ✅ bd539b638fdd31398a002f50185153d198e7abd6 C-SEC-8.c — `Sanitize::stripInvisibleFormatting()` in sugar-core
- **Item:** C-SEC-8 (sources: GAP-UI-N5) · gap · effort S
- **What:** `Sanitize::markInvisibleFormatting()` (`sugar-core/src/Util/Sanitize.php:359`) only marks. It has a two-tier rule: bidi, ZWSP, WJ and BOM are always marked, while ZWJ/ZWNJ/LRM/RLM/ALM are kept after a non-ASCII byte. Add a …
- **Files — create:** `sugar-core/tests/Util/SanitizeStripInvisibleFormattingTest.php`
- **Files — modify:** `sugar-core/src/Util/Sanitize.php`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** the sugar-core suite. A `Mirrors` citation is not needed; this is a SugarCraft original.
- **Serialized:** none
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-sec.md` → `### C-SEC-8.c`

### W17

- protocol lanes: C-TOOL-3.e (weld regenerates schema → SERVER.md → generated.ts once)
- Chat.php region claims: P-AGENT.b (submitToAgents + new draft()/withDraft())

#### P-AGENT.b — Agent windows: own viewport, rows/chips in the window list, `Ctrl+X i` interrupt-send
- **Item:** P-AGENT-ATTACH (sources: owner pick; CLAW-new#7 note; CC-new#9 parity note only) · feature · effort M
- **What:** **Gap 1: shared viewport.** The Agent View shares the main chat's scroll and draft. `App::openAgentView`/`closeAgentView` (`src/App/App.php:710,810`) never save `Chat::scrollOffset()` or the input, so the main draft becomes the …
- **Files — create:** none
- **Files — modify:** `candy-code/src/App/App.php`, `candy-code/src/Chat.php`, `candy-code/src/App/AgentControlMsg.php`, `candy-code/src/Tui/KeyboardHandler.php`, `candy-code/src/Commands/KeyBindingRegistry.php`, `candy-code/lang/en.php`, `candy-code/docs/AGENTS.md`
- **Tests — create:** `candy-code/tests/App/AgentWindowViewportTest.php`, `candy-code/tests/Chat/AgentInterruptSendTest.php` · **modify:** `candy-code/tests/Chat/AgentViewTest.php`, `candy-code/tests/Commands/KeyBindingDriftTest.php`
- **Docs/guards:** `docs/AGENTS.md`; `tests/Commands/KeyBindingDriftTest.php` moved in-step
- **Serialized:** [chat:submitToAgents + new draft()/withDraft()] [lang] (prefix `tui.agent_view.sent.`, `keys.agent-transcript.`) [docs:AGENTS.md] [hot:candy-code/src/App/App.php] …
- **Deps:** P-AGENT.a (W6), P-TABS.g (W16)
- **Brief:** `prompt_kit/findings/comparison-1026/design-picks.md` → `### P-AGENT.b`

#### ✅ 8780474729d27ccbc48a06b9a27c70d280a1f0b2 C-CTX-5.c — Idle fold timed to the prompt-cache TTL (welded 2026-10-29)
- **Item:** C-CTX-5 (sources: GAP-CTX-N2, CC-new#10, p1-miss: CTX-5 short cache-warm idle tier, CLAW-new#5 (bounded-history view, n/a)) · competitor-feature · effort M
- **What:** `compaction.idleAuto` becomes an enum: `off | hour | cacheTtl`. The bool keeps reading as `hour`/`off`. `cacheTtl` folds a session idle just short of its provider's cache TTL, from `MarksPromptCache` (5 min for most, 1 h when …
- **Files — create:** `candy-code/tests/Context/CacheTtlIdleFoldTest.php`
- **Files — modify:** `candy-code/src/Context/IdleCompactionPolicy.php`, `candy-code/src/Context/CompactorConfig.php`, `candy-code/src/Chat/Handlers/HandlesRunControl.php`, `candy-code/src/Host/SessionHost.php`, `candy-code/src/Config/Settings/Definitions/ContextSettings.php`, `candy-code/src/Config/LayeredSettings.php`, `candy-code/docs/SETTINGS.md`, `candy-code/docs/CONTEXT.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Chat/IdleAutoCompactionTest.php`, `candy-code/tests/Host/SessionHostIdleAutoTest.php`, `candy-code/tests/Context/IdleCompactionPolicyTest.php`
- **Docs/guards:** settings gen + `SettingsSchemaDocDriftTest`
- **Serialized:** [docs:CONTEXT.md] [docs:SETTINGS.md] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Chat/Handlers/HandlesRunControl.php] …
- **Deps:** C-CTX-6.a (W10)
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-CTX-5.c`

#### C-PRV-2.c — First-class `azure` and `openrouter` provider types
- **Item:** C-PRV-2 (sources: GAP-MOD-05/06) · gap · effort M
- **What:** Both types are reachable today only through an undocumented `custom` block. `azure` reuses `OpenAIProvider` (Chat Completions, or `api: responses` once C-PRV-2.a exists): keys: `resource` or `baseUrl`, plus …
- **Files — create:** `candy-code/tests/Providers/AzureOpenRouterTypesTest.php`
- **Files — modify:** `candy-code/src/Providers/ProviderFactory.php`, `candy-code/src/Providers/OpenAIProvider.php`, `candy-code/src/Providers/CustomProvider.php`, `candy-code/src/Cli/Subcommands.php`, `candy-code/README.md`, `candy-code/docs/ARCHITECTURE.md`, `candy-code/docs/ENVIRONMENT.md`, `candy-code/docs/ADDING_A_PROVIDER.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Providers/ProviderFactoryTest.php`, `candy-code/tests/Providers/ProviderErrorCopyTest.php`
- **Docs/guards:** `candy-code/tests/Config/DocFigureProseDriftTest.php::testProvidersSectionRosterDividesTheFactoryItDocuments` (ARCHITECTURE roster); `candy-code/tests/Config/EnvRosterDriftTest.php` (ENVIRONMENT.md); …
- **Serialized:** [headless] [docs:README.md] [docs:ARCHITECTURE.md] [docs:ENVIRONMENT.md] [docs:ADDING_A_PROVIDER.md] [lang:provider.* error copy] [hot:candy-code/src/Providers/ProviderFactory.php] …
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-PRV-2.c`

#### ✅ f5bd88ca37bed4ab43136d0a49b3f15681dc3475 C-SES-4.c — Headless `-p` exports spans (welded 2026-10-28)
- **Item:** C-SES-4 (sources: GAP-OBS-02 (`-p` half), p2-miss: serve + headless OTel sink threading) · gap · effort S
- **What:** `NonInteractive::run()` obtains `Bootstrap::observabilitySink()`, a static call, so `Bootstrap.php` is not edited. It is a null sink while dark. The `$onEvent` observer (`src/Cli/NonInteractive.php:576-579`) also calls …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Cli/NonInteractive.php`, `candy-code/docs/ENVIRONMENT.md`, `candy-code/tests/Observability/ObservabilityWiringTest.php`
- **Tests — create:** `candy-code/tests/Cli/NonInteractiveOtelExportTest.php` · **modify:** none
- **Docs/guards:** the ENVIRONMENT.md row and `ObservabilityWiringTest` flip in-step
- **Serialized:** [headless] [docs:ENVIRONMENT.md]
- **Deps:** C-SES-4.a (W2)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-4.c`

#### ✅ 8fce79ce04516d0bb6cb25ead42f8f01a947ea82 C-SES-6.b — Reproducible phar build + package manifest (welded 2026-10-29)
- **Item:** C-SES-6 (sources: p2-miss: reproducible phar builds) · gap · effort S
- **What:** **The gap.** `release-phar.yml:6-15` discloses lock-less `dev-master` resolution, and phar entries carry checkout-time mtimes. **Fix.** Before adding files (`:224` sorts them; `:270` adds them), `touch()` every packaged file to …
- **Files — create:** none
- **Files — modify:** `candy-code/tools/build-phar.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Integration/PharCompatTest.php`
- **Docs/guards:** none
- **Serialized:** none
- **Deps:** C-SES-9.c (W8)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-6.b`

#### C-SES-6.c — `install.sh` + Homebrew formula generator
- **Item:** C-SES-6 (sources: GAP-PLAT-03, p2-miss: packaging channels) · gap · effort M
- **What:** **`tools/install.sh`** (POSIX sh) does six things: 1. checks for `php` 8.3+; 2. resolves the latest `candy-code/v*` release, or `--channel dev`; 3. downloads the `.phar`, `.sha256` and `.sha256.sig` (falling back to `.minisig`); …
- **Files — create:** `candy-code/tools/install.sh`, `candy-code/packaging/homebrew/candy-code.rb.tmpl`, `candy-code/tests/Cli/InstallScriptTest.php`, `candy-code/tests/Cli/HomebrewFormulaTest.php`
- **Files — modify:** `candy-code/README.md`
- **Tests — create:** `candy-code/tests/Cli/InstallScriptTest.php`, `candy-code/tests/Cli/HomebrewFormulaTest.php` · **modify:** none
- **Docs/guards:** README Install; `EnvRosterDriftTest` is untouched by construction (no env vars)
- **Serialized:** [docs:README.md]
- **Deps:** C-SES-5.b (W3), C-SES-6.a (W7)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-6.c`

#### C-SES-8.c — Insights report core
- **Item:** C-SES-8 (sources: GAP-OBS-06, p2-miss: OBS-06 insights) · gap · effort M
- **What:** **Report contents.** `InsightsCommand::compose(facts)` builds a text summary plus a self-contained HTML report over a window (default 30 days): spend by model and by day (`≥` law); sessions and turns per day; busiest projects …
- **Files — create:** `candy-code/src/Commands/InsightsCommand.php`, `candy-code/src/Host/Commands/InsightsHostCommand.php`, `candy-code/tests/Commands/InsightsCommandTest.php`, `candy-code/tests/Host/Commands/InsightsHostCommandTest.php`
- **Files — modify:** `candy-code/lang/en.php`
- **Tests — create:** `candy-code/tests/Commands/InsightsCommandTest.php`, `candy-code/tests/Host/Commands/InsightsHostCommandTest.php` · **modify:** none
- **Docs/guards:** none (wiring is in C-SES-8.d)
- **Serialized:** [lang:cmd.insights.]
- **Deps:** C-SES-9.a (W7)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-8.c`

#### C-SES-8.f — JSONL transcript writer + `jsonl` export format
- **Item:** C-SES-8 (sources: GAP-OBS-05) · gap · effort M
- **What:** **Format.** `Exporter::toJsonl()` writes one `Message::toArray()` object per line. `ShareSession` gains `FORMAT_JSONL` (in `SUPPORTED_FORMATS`, with mime and extension), and `ExportCommand` maps `jsonl`. **Protocol untouched.** …
- **Files — create:** `candy-code/src/Share/JsonlTranscriptWriter.php`, `candy-code/tests/Share/JsonlTranscriptWriterTest.php`
- **Files — modify:** `candy-code/src/Util/Exporter.php`, `candy-code/src/Share/ShareSession.php`, `candy-code/src/Commands/ExportCommand.php`, `candy-code/lang/en.php`, `candy-code/docs/COMMANDS.md`
- **Tests — create:** `candy-code/tests/Share/JsonlTranscriptWriterTest.php` · **modify:** `candy-code/tests/Commands/ExportCommandTest.php`
- **Docs/guards:** the COMMANDS.md regen; `CommandDocsDriftTest`
- **Serialized:** [lang:cmd.export.] [docs:COMMANDS.md]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-8.f`

#### ✅ d284451fa963cca330542f013b1e9f3e01b433d6 C-TOOL-2.d — Read PDFs: tool_result `document` block + pdftotext text (welded 2026-10-29)
- **Item:** C-TOOL-2 (sources: GAP-TL-20, SHL-3-P1, p2-miss: SHL-3 PDF as document, k2-sum TL-20 downgrade) · gap · effort M
- **What:** Read returns only a notice for a PDF (`candy-code/src/Tools/BuiltIn/Read.php:750-763`). Its "mention the file (@path)" advice is a road the model cannot take, because @-mentions resolve only on user prompts. Mirror the image path …
- **Files — create:** `candy-code/tests/Providers/ToolResultDocumentBlocksTest.php`, `candy-code/tests/Tools/BuiltIn/ReadPdfTextTest.php`
- **Files — modify:** `candy-code/src/Tools/BuiltIn/Read.php`, `candy-code/src/Tools/ToolResult.php`, `candy-code/src/Messages/ToolResultMessage.php`, `candy-code/src/Runtime.php`, `candy-code/src/Backend/EngineBackend.php`, `candy-code/src/Providers/Concerns/AnthropicMessagesWire.php`, `candy-code/src/Providers/AttachmentEncoding.php`, `candy-code/docs/MEDIA.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Tools/BuiltIn/ReadBinaryScanTest.php`, `candy-code/tests/Tools/ToolResultTest.php`, `candy-code/tests/Providers/PromptStabilityTest.php`
- **Docs/guards:** `docs/MEDIA.md` in-step; Read's gloss is unchanged, so no gen-tool-docs
- **Serialized:** [runtime:settle,encodeResult,decodeResult] [hot:candy-code/src/Backend/EngineBackend.php] [hot:candy-code/src/Providers/Concerns/AnthropicMessagesWire.php]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-2.d`

#### C-TOOL-3.e — Web Processes section + `shells.*` methods
- **Item:** C-TOOL-3 (sources: CLAW-new#6, p2-miss: SHL no web/serve shell list) · gap · effort M
- **What:** Structured protocol methods: `shells.list` (Read scope), `shells.output {shellId, cursor}` (Read) and `shells.stop {shellId}` (Write, idempotent). They resolve the session's workspace registry like the other per-session methods …
- **Files — create:** `candy-code/src/Protocol/Methods/ShellsMethods.php`, `candy-code/tests/Protocol/ShellsMethodsTest.php`, `candy-code-web/src-web/stores/agents/shells.ts`, `candy-code-web/src-web/components/panels/ProcessesSection.vue`, `candy-code-web/src-web/components/panels/__tests__/processesSection.spec.ts`
- **Files — modify:** `candy-code/src/Protocol/Dispatcher.php`, `candy-code/src/Protocol/Schema/MethodSchemas.php`, `candy-code/src/Protocol/Schema/Definitions.php`, `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts`, `candy-code-web/scripts/gen-protocol.mjs`, `candy-code-web/src-web/components/panels/BackgroundPanel.vue`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** `ProtocolSchemaDriftTest`, `ServerProtocolDocumentationDriftTest` (regen in-step)
- **Serialized:** [protocol]
- **Deps:** C-TOOL-3.c (W13)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-3.e`

### W18

- Chat.php region claims: C-AGT-4.b (applyCommandResult, ctor, handleAgentsCommand, key dispatch)

#### C-AGT-4.b — `/agent use`, Alt+A cycle, TUI badge, serve effect
- **Item:** C-AGT-4 (sources: GAP-AGT-01/02) · gap · effort M
- **What:** `/agent use <preset>` and `/agent use default` become a verb of `AgentsCommand`/`AgentsHostCommand`, returning a new `CommandEffect::setPrimaryAgent(?string)`. Chat's `applyCommandResult` arm applies it through `PrimaryAgent` …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Commands/AgentsCommand.php`, `candy-code/src/Host/Commands/AgentsHostCommand.php`, `candy-code/src/Host/Commands/CommandEffect.php`, `candy-code/src/Host/Commands/CommandEffectKind.php`, `candy-code/src/Chat.php`, `candy-code/src/Host/SessionHost.php`, `candy-code/src/Renderer.php`, `candy-code/src/Commands/KeyBindingRegistry.php`, `candy-code/builtin-commands/0800-agents.php`, `candy-code/lang/en.php`, `candy-code/README.md`, `candy-code/docs/AGENTS.md`, `candy-code/docs/COMMANDS.md`
- **Tests — create:** `candy-code/tests/Chat/PrimaryAgentSwitchTest.php` · **modify:** `candy-code/tests/Commands/AgentsCommandTest.php`, `candy-code/tests/Host/SessionHostCommandsTest.php`
- **Docs/guards:** `candy-code/tests/Commands/KeyBindingDriftTest.php` + README Keys table; `candy-code/tests/Commands/CommandDocsDriftTest.php` (`php tools/gen-command-docs.php --write`); `docs/AGENTS.md`
- **Serialized:** [chat:handleAgentsCommand, applyCommandResult, key dispatch,ctor] [renderer:modeBadge] [commands:0800-agents.php] [hot:candy-code/src/Host/SessionHost.php] [lang: `cmd.agents.*`, …
- **Deps:** C-AGT-4.a (W14), P-AGENT.b (W17)
- **Brief:** `prompt_kit/findings/comparison-1026/design-agt.md` → `### C-AGT-4.b`

#### C-PRV-3.a — `serviceTier` setting and provider rebinding (core)
- **Item:** C-PRV-3 (sources: GAP-MOD-N2) · gap · effort M
- **What:** Add a `serviceTier` setting (`default|fast`; RiskClass Spend; layered; env `CANDY_CODE_SERVICE_TIER`). New interface `Providers\ShapesServiceTier`, with `serviceTierFor(string $model): ?string` (the wire value for fast) and …
- **Files — create:** `candy-code/src/Providers/ShapesServiceTier.php`, `candy-code/tests/Providers/ServiceTierTest.php`
- **Files — modify:** `candy-code/src/Providers/OpenAIProvider.php`, `candy-code/src/Providers/AnthropicProvider.php`, `candy-code/src/Backend/EngineBackend.php`, `candy-code/src/Cli/LaunchContext.php`, `candy-code/src/Config/Settings/Definitions/ModelProviderSettings.php`, `candy-code/src/Config/LayeredSettings.php`, `candy-code/docs/SETTINGS.md`, `candy-code/docs/ENVIRONMENT.md`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** `php tools/gen-settings-doc.php --write` under `candy-code/tests/Config/Settings/SettingsSchemaDocDriftTest.php`; `candy-code/tests/Config/EnvRosterDriftTest.php`
- **Serialized:** [lang:settings.serviceTier.*] [docs:SETTINGS.md] [docs:ENVIRONMENT.md] [hot:candy-code/src/Backend/EngineBackend.php] [hot:candy-code/src/Providers/OpenAIProvider.php] …
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-PRV-3.a`

#### C-SEC-8.e — `${keychain:<name>}` provider keys
- **Item:** C-SEC-8 (sources: GAP-MOD-25) · gap · effort M
- **What:** Provider keys sit in plaintext in a 0600 config, and `ProviderFactory::resolveEnv()` (`src/Providers/ProviderFactory.php:450`) resolves only `${VAR}`. Add `${keychain:<name>}`, resolved by a new `Keychain` that shells out to …
- **Files — create:** `candy-code/src/Support/Keychain.php`, `candy-code/tests/Support/KeychainTest.php`
- **Files — modify:** `candy-code/src/Providers/ProviderFactory.php`, `candy-code/docs/ENVIRONMENT.md`, `candy-code/lang/en.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Providers/ProviderFactoryTest.php`
- **Docs/guards:** The child lifetime is short and synchronous. If `DescriptorInheritanceGuardTest` (`candy-code/tests/Support/DescriptorInheritanceGuardTest.php`) does not classify it `short`, add an `ACCOUNTED_FOR` …
- **Serialized:** [docs:ENVIRONMENT.md] [lang:cli.keychain.]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-sec.md` → `### C-SEC-8.e`

#### C-TOOL-5.a — Opt-in read-before-write + UTF-8 BOM preservation
- **Item:** C-TOOL-5 (sources: GAP-EDT-02, GAP-EDT-10) · gap · effort M
- **What:** (1) New user-tier bool `requireReadBeforeWrite` (default false), read through `ToolLimits` like the other tool bounds. When it is on, these refuse an EXISTING file that has no `ReadLedger::has()` entry …
- **Files — create:** `candy-code/tests/Tools/BuiltIn/ReadBeforeWriteTest.php`, `candy-code/tests/Tools/Edit/BomPreservationTest.php`
- **Files — modify:** `candy-code/src/Tools/BuiltIn/Edit.php`, `candy-code/src/Tools/BuiltIn/Write.php`, `candy-code/src/Tools/BuiltIn/ApplyPatch.php`, `candy-code/src/Tools/Edit/IndentShift.php`, `candy-code/src/Tools/Edit/EditMatcher.php`, `candy-code/src/Tools/ToolLimits.php`, `candy-code/src/Config/Settings/Definitions/ToolSettings.php`, `candy-code/lang/en.php`, `candy-code/docs/SETTINGS.md`, `candy-code/README.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Tools/BuiltIn/EditTest.php`, `candy-code/tests/Tools/BuiltIn/WriteTest.php`, `candy-code/tests/Tools/BuiltIn/ApplyPatchTest.php`
- **Docs/guards:** `SettingsSchemaDocDriftTest`, `TrustKeyDocumentationDriftTest` (README user-tier enumeration)
- **Serialized:** [lang:settings.requireReadBeforeWrite.] [docs:SETTINGS.md] [docs:README.md]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-5.a`

#### C-TUI-2.d — Diff-review core (model, per-file stats, per-turn diff)
- **Item:** C-TUI-2 (sources: GAP-GIT-09) · gap · effort M
- **What:** Today `/diff [n]` prints fenced text (`src/Host/Commands/DiffCommand.php`) from `CheckpointDiff::of()`, which is the checkpoint against now. `CheckpointDiff` gains `files()`: per-file `{status, path, added, removed, patch slice}` …
- **Files — create:** `candy-code/src/Tui/DiffReview/DiffReview.php`, `candy-code/src/Tui/DiffReview/DiffReviewAction.php`, `candy-code/src/Tui/DiffReview/DiffReviewActionKind.php`, `candy-code/src/Tui/DiffReview/DiffReviewSource.php`, `candy-code/src/Tui/DiffReview/OpenDiffReviewMsg.php`
- **Files — modify:** `candy-code/src/Workspace/CheckpointDiff.php`, `candy-code/lang/en.php`
- **Tests — create:** `candy-code/tests/Tui/DiffReview/DiffReviewTest.php`, `candy-code/tests/Workspace/CheckpointDiffFilesTest.php` · **modify:** none
- **Docs/guards:** `tools/check-one-type-per-file.php`; LangParityTest
- **Serialized:** [lang:tui.diffreview.]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-2.d`

#### C-TUI-3.c — Message-picker core
- **Item:** C-TUI-3 (sources: GAP-UI-15) · gap · effort S
- **What:** A pure `MessagePicker` in the HistorySearch shape. Its rows are the session's conversation checkpoints, read from the store (the positions `/rewind n --chat` counts), each labelled with the prompt it restores. Newest first; …
- **Files — create:** `candy-code/src/Tui/MessagePicker/MessagePicker.php`, `candy-code/src/Tui/MessagePicker/MessagePickerAction.php`, `candy-code/src/Tui/MessagePicker/OpenMessagePickerMsg.php`
- **Files — modify:** `candy-code/lang/en.php`
- **Tests — create:** `candy-code/tests/Tui/MessagePicker/MessagePickerTest.php` · **modify:** none
- **Docs/guards:** `tools/check-one-type-per-file.php`; LangParityTest
- **Serialized:** [lang:tui.msgpicker.]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-3.c`

#### C-TUI-3.f — Recap core (`RecapHostCommand`)
- **Item:** C-TUI-3 (sources: GAP-UI-N4) · gap · effort S
- **What:** `/btw` already does a tool-less side call on the title backend as an async effect, with a server row mapper (`src/Host/Commands/BtwHostCommand.php:44-111`, `CommandEffect::async($call, self::answerRowMessage(...))`, …
- **Files — create:** `candy-code/src/Host/Commands/RecapHostCommand.php`
- **Files — modify:** `candy-code/src/Host/Commands/BtwHostCommand.php`, `candy-code/lang/en.php`
- **Tests — create:** `candy-code/tests/Host/Commands/RecapHostCommandTest.php` · **modify:** none
- **Docs/guards:** none (the command row lands in C-TUI-3.g)
- **Serialized:** [lang:host.recap.]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-3.f`

#### C-TUI-5.d — First-run wizard core + `ProviderKeyStore`
- **Item:** C-TUI-5 (sources: GAP-UI-29) · gap · effort M
- **What:** A first launch with no provider runs the echo backend behind a notice (`Bootstrap.php` ~L3339, L3473). `ProviderKeyStore` extracts the persist half of `Subcommands::loginStoreKey()` (L936-986): merge into the `providers` map, …
- **Files — create:** `candy-code/src/Tui/FirstRunWizard/FirstRunWizard.php`, `candy-code/src/Tui/FirstRunWizard/FirstRunWizardAction.php`, `candy-code/src/Tui/FirstRunWizard/FirstRunWizardActionKind.php`, `candy-code/src/Tui/FirstRunWizard/OpenFirstRunWizardMsg.php`, `candy-code/src/Config/ProviderKeyStore.php`
- **Files — modify:** `candy-code/lang/en.php`
- **Tests — create:** `candy-code/tests/Tui/FirstRunWizard/FirstRunWizardTest.php`, `candy-code/tests/Config/ProviderKeyStoreTest.php` · **modify:** none
- **Docs/guards:** SettingsWriterCensusTest (`ProviderKeyStore` is a `config.json` door); ReadPathCensusTest (the dismissal stamp); `tools/check-one-type-per-file.php`
- **Serialized:** [lang:tui.wizard.] [hot:candy-code/tests/Config/Settings/SettingsWriterCensusTest.php] [hot:candy-code/tests/Support/ReadPathCensusTest.php]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-5.d`

#### C-WEB-4.b — `?` help overlay + web keymap
- **Item:** C-WEB-4 (sources: GAP-UI-28, GAP-UI-25) · gap · M
- **What:** Client-only. **Help overlay.** `?` on an empty composer, or outside any input, opens `HelpOverlay`. It lists the web shortcuts plus `command.list` rows with `runsIn` markers. The global capture-phase handler lives in `App.vue`, …
- **Files — create:** `candy-code-web/src-web/components/HelpOverlay.vue`, `candy-code-web/src-web/lib/keymap.ts`, `candy-code-web/src-web/components/__tests__/helpOverlay.spec.ts`, `candy-code-web/src-web/lib/__tests__/keymap.spec.ts`
- **Files — modify:** `candy-code-web/src-web/App.vue`, `candy-code-web/src-web/components/panels/CommandPalette.vue`, `candy-code-web/src-web/lib/tabChord.ts`, `candy-code-web/src-web/components/settings/SettingsView.vue`, `candy-code-web/src-web/lib/__tests__/tabChord.spec.ts`
- **Tests — create:** `candy-code-web/src-web/components/__tests__/helpOverlay.spec.ts`, `candy-code-web/src-web/lib/__tests__/keymap.spec.ts` · **modify:** none
- **Docs/guards:** `candy-code-web/README.md` (soft); vitest; dist rebuild.
- **Serialized:** [hot:candy-code-web/src-web/App.vue] [hot:candy-code-web/src-web/components/settings/SettingsView.vue]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-web.md` → `### C-WEB-4.b`

#### C-WEB-4.c — Transcript toggles: show thinking, expand all tool cards
- **Item:** C-WEB-4 (sources: GAP-UI-22, GAP-UI-32 (web half)) · gap · S
- **What:** Client-only. **Store.** A per-viewer `stores/transcriptView.ts` (localStorage via `stores/storage.ts`) holds `showThinking` and `expandTools`. It is kept separate from `stores/settings.ts` so this step does not collide with …
- **Files — create:** `candy-code-web/src-web/stores/transcriptView.ts`, `candy-code-web/src-web/components/__tests__/transcriptToggles.spec.ts`
- **Files — modify:** `candy-code-web/src-web/components/Transcript.vue`, `candy-code-web/src-web/components/ReasoningFold.vue`, `candy-code-web/src-web/components/ToolCard.vue`
- **Tests — create:** `candy-code-web/src-web/components/__tests__/transcriptToggles.spec.ts` · **modify:** none
- **Docs/guards:** `candy-code-web/README.md` Transcript bullet (soft); vitest; dist rebuild.
- **Serialized:** [hot:candy-code-web/src-web/components/ToolCard.vue]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-web.md` → `### C-WEB-4.c`

### W19

- protocol lanes: C-WEB-4.e (weld regenerates schema → SERVER.md → generated.ts once)
- Chat.php region claims: C-AGT-6.a (new handleCommitCommand); C-CTX-3.b (ctor, key dispatch, submit)
- Bootstrap.php method claims: C-SES-4.d (chat(comment only), workspace)

#### C-PRV-4.a — Host permission mode and isolation for claude-code `bare: false`
- **Item:** C-PRV-4 (sources: CLAW-new#4) · competitor-feature · effort M
- **What:** With `bare: false` (the subscription route, `ClaudeCodeProvider.php:142-157`), the CLI loads the user's own `~/.claude` setting sources, CLAUDE.md, auto-memory, hooks, plugins and MCP. Its tools then run under foreign hooks and …
- **Files — create:** `candy-code/src/Providers/HonoursHostPermissionMode.php`, `candy-code/tests/Providers/CliProviderIsolationTest.php`
- **Files — modify:** `candy-code/src/Providers/ClaudeCodeProvider.php`, `candy-code/src/Providers/ClaudeCodeInvocation.php`, `candy-code/src/Backend/EngineBackend.php`, `candy-code/src/Providers/ProviderFactory.php`, `candy-code/README.md`, `candy-code/docs/SETTINGS.md`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** README and SETTINGS prose; lang for the disclosure notice
- **Serialized:** [docs:README.md] [docs:SETTINGS.md] [lang:provider.claude_code.isolation*] [hot:candy-code/src/Backend/EngineBackend.php] [hot:candy-code/src/Providers/ClaudeCode*.php] …
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-PRV-4.a`

#### C-SES-4.d — Serve exports spans (per-session encoders)
- **Item:** C-SES-4 (sources: GAP-OBS-02 (serve half), p2-miss: serve OTel sink threading) · gap · effort M
- **What:** **The trap.** One OTLP encoder keys open spans by `$event->step` (`OtlpEncoder.php:84-96`). Serve hosts several sessions per process, so their step numbers would collide on a shared sink. **Factory.** Add an `EventSinkFactory` …
- **Files — create:** `candy-code/src/Observability/EventSinkFactory.php`
- **Files — modify:** `candy-code/src/Cli/Bootstrap.php`, `candy-code/src/Host/SessionHost.php`, `candy-code/src/Observability/OtelConfig.php`, `candy-code/docs/ENVIRONMENT.md`, `candy-code/tests/Observability/ObservabilityWiringTest.php`
- **Tests — create:** `candy-code/tests/Host/SessionHostOtelFoldTest.php` · **modify:** none
- **Docs/guards:** the ENVIRONMENT.md row and `ObservabilityWiringTest` flip in-step
- **Serialized:** [bootstrap:workspace,chat(comment only)] [hot:candy-code/src/Host/SessionHost.php] [docs:ENVIRONMENT.md]
- **Deps:** C-SES-4.b (W8)
- **Ruling:** Serve telemetry: should `serve` export OTel spans when `OTEL_EXPORTER_OTLP_ENDPOINT` is set? (Owner-gated per OBS-02 and the Bootstrap comment.) — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-4.d`

#### C-TOOL-2.b — Pluggable search backends
- **Item:** C-TOOL-2 (sources: GAP-TL-13) · gap · effort M
- **What:** `WebSearch` (`candy-code/src/Tools/BuiltIn/WebSearch.php` ctor :90-115) talks only to a SearXNG endpoint (`CANDY_CODE_SEARCH_ENDPOINT` / `webSearchEndpoint`) and has no default. Extract a `SearchBackend` interface with a …
- **Files — create:** `candy-code/src/Tools/Web/Search/SearchBackend.php`, `candy-code/src/Tools/Web/Search/SearchHit.php`, `candy-code/src/Tools/Web/Search/SearxngBackend.php`, `candy-code/src/Tools/Web/Search/BraveBackend.php`, `candy-code/src/Tools/Web/Search/TavilyBackend.php`, `candy-code/src/Tools/Web/Search/ExaBackend.php`, `candy-code/src/Tools/Web/Search/SearchBackends.php`, `candy-code/tests/Tools/Web/Search/SearchBackendsTest.php`, `candy-code/tests/Tools/Web/Search/HostedBackendShapesTest.php`
- **Files — modify:** `candy-code/src/Tools/BuiltIn/WebSearch.php`, `candy-code/src/Tools/ToolLimits.php`, `candy-code/src/Config/Settings/Definitions/ToolSettings.php`, `candy-code/lang/en.php`, `candy-code/README.md`, `candy-code/docs/SETTINGS.md`, `candy-code/docs/ENVIRONMENT.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Tools/WebSearchToolTest.php`, `candy-code/tests/Tools/WebSearchEndpointSettingTest.php`
- **Docs/guards:** `EnvRosterDriftTest` (three env vars, both directions), `SettingsSchemaDocDriftTest`, `TrustKeyDocumentationDriftTest` (README user-tier key enumeration); verify …
- **Serialized:** [lang:settings.webSearchBackend.] [docs:SETTINGS.md] [docs:README.md] [docs:ENVIRONMENT.md]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-2.b`

#### C-TUI-2.c — TUI split diffs, highlighted diff bodies, `diffStyle`
- **Item:** C-TUI-2 (sources: GAP-UI-05, GAP-UI-04) · gap · effort M
- **What:** Split mode: `Renderer::renderDiff()` (`candy-code/src/Renderer.php` ~L5388-5600) paints unified rows through `DiffGutter::forDiff()`. Add a split layout from `SideBySideFormat::fromUnified()` when the new `diffStyle` setting …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Renderer.php`, `candy-code/src/Tui/DiffGutter.php`, `candy-code/src/Config/Settings/Definitions/InterfaceSettings.php`, `candy-code/src/Config/LayeredSettings.php`, `candy-code/src/Config/Settings/UiSettings.php`, `candy-code/lang/en.php`, `candy-code/docs/SETTINGS.md`, `candy-code/README.md`
- **Tests — create:** `candy-code/tests/Tui/DiffSplitRenderTest.php` · **modify:** `candy-code/tests/Tui/DiffGutterTest.php`
- **Docs/guards:** `php tools/gen-settings-doc.php --write`; SettingsSchemaDocDriftTest; ReadmeRosterDriftTest; the DocFigureProseDriftTest settings-count prose; `tests/Renderer/PaneWidthInvariantTest.php` must stay …
- **Serialized:** [renderer:Renderer::renderDiff/styleDiffLine] [hot:candy-code/src/Config/Settings/Definitions/InterfaceSettings.php] [hot:candy-code/src/Config/LayeredSettings.php] …
- **Deps:** C-TUI-2.a (W14), C-TUI-2.b (W15)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-2.c`

#### C-WEB-4.d — Elapsed turn timer in the status bar
- **Item:** C-WEB-4 (sources: GAP-UI-31) · gap · S
- **What:** Client-only. **Reducer.** `stores/reducer.ts` records `turnStartedAt = envelope.ts` on `turn.started` (:416-425) and clears it on `turn.completed`. **Status bar.** `StatusBar.vue` shows `working · 0:42` (with `· step n/m` when …
- **Files — create:** `candy-code-web/src-web/stores/__tests__/turnTimer.spec.ts`, `candy-code-web/src-web/components/__tests__/statusBarTimer.spec.ts`
- **Files — modify:** `candy-code-web/src-web/stores/reducer.ts`, `candy-code-web/src-web/components/StatusBar.vue`
- **Tests — create:** `candy-code-web/src-web/stores/__tests__/turnTimer.spec.ts`, `candy-code-web/src-web/components/__tests__/statusBarTimer.spec.ts` · **modify:** none
- **Docs/guards:** `candy-code-web/README.md` status-bar bullet (soft); vitest; dist rebuild.
- **Serialized:** [hot:candy-code-web/src-web/components/StatusBar.vue] [hot:candy-code-web/src-web/stores/reducer.ts]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-web.md` → `### C-WEB-4.d`

#### C-WEB-4.e — PDF upload from the browser (`session.send` `documents[]`)
- **Item:** C-WEB-4 (sources: GAP-MOD-22w) · gap · M
- **What:** **Gap.** PDFs reach the model only by `@path` of a server file. `session.send` carries `images[]` only (`candy-code/src/Protocol/Schema/MethodSchemas.php:206-222`, `TurnMethods.php:90-173`). **Wire.** Add `documents[]` with …
- **Files — create:** `candy-code/tests/Protocol/SessionSendDocumentsTest.php`, `candy-code-web/src-web/components/__tests__/composerDocuments.spec.ts`
- **Files — modify:** `candy-code/src/Protocol/Methods/TurnMethods.php`, `candy-code/src/Protocol/Schema/MethodSchemas.php`, `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts`, `candy-code-web/src-web/lib/paste.ts`, `candy-code-web/src-web/components/Composer.vue`, `candy-code-web/src-web/stores/session.ts`
- **Tests — create:** `candy-code/tests/Protocol/SessionSendDocumentsTest.php`, `candy-code-web/src-web/components/__tests__/composerDocuments.spec.ts` · **modify:** none
- **Docs/guards:** the protocol gates; `tests/Config/ServerProtocolDocumentationDriftTest.php`; `docs/SERVER.md`; `candy-code-web/README.md` (soft).
- **Serialized:** [protocol] [hot:candy-code-web/src-web/components/Composer.vue]
- **Deps:** C-WEB-4.a (W15)
- **Brief:** `prompt_kit/findings/comparison-1026/design-web.md` → `### C-WEB-4.e`

#### C-AGT-5.h — PR-comment bot example workflow
- **Item:** C-AGT-5 (sources: GAP-GIT-12, GAP-AUT-05, p2-miss: GIT-12 bot) · gap · effort S
- **What:** Ship `candy-code/docs/examples/pr-comment-bot.yml`, an `issue_comment` workflow: it is gated on an `@candy-code` mention AND `author_association` ∈ OWNER/MEMBER/COLLABORATOR; job 1 runs the existing action in `plan` mode on the …
- **Files — create:** `candy-code/docs/examples/pr-comment-bot.yml`, `tools/tests/PrCommentBotExampleTest.php`
- **Files — modify:** `candy-code/docs/CI.md`, `candy-code/docs/README.md`
- **Tests — create:** `tools/tests/PrCommentBotExampleTest.php` · **modify:** none
- **Docs/guards:** `docs/CI.md`
- **Serialized:** [docs:CI.md] [docs:README.md (docs index)]
- **Deps:** C-AGT-5.g (W15)
- **Ruling:** R13 (remote security). Is an issue-comment-triggered bot that posts with a write token acceptable, and under which association gate? — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-agt.md` → `### C-AGT-5.h`

#### C-AGT-6.a — `/commit` with a drafted message
- **Item:** C-AGT-6 (sources: GAP-GIT-03, p2-miss: built-in `/commit` (×2)) · gap · effort M
- **What:** `/commit [message]` is a builtin with a host body, so serve and web get it as `runsIn: server`. `CommitHostCommand` returns `CommandEffect::async`, which collects every changed path (`git status --porcelain -z`). With no message, …
- **Files — create:** `candy-code/builtin-commands/2850-commit.php`, `candy-code/src/Host/Commands/CommitHostCommand.php`
- **Files — modify:** `candy-code/src/Workspace/AutoCommitter.php`, `candy-code/src/Chat.php`, `candy-code/lang/en.php`, `candy-code/docs/COMMANDS.md`, `candy-code/README.md`
- **Tests — create:** `candy-code/tests/Host/Commands/CommitHostCommandTest.php` · **modify:** `candy-code/tests/Workspace/AutoCommitterTest.php`
- **Docs/guards:** `php tools/gen-command-docs.php --write` + `candy-code/tests/Commands/CommandDocsDriftTest.php`, `candy-code/tests/Config/ReadmeRosterDriftTest.php`, …
- **Serialized:** [chat:new handleCommitCommand] [lang: `cmd.commit.*`, `host.commit.*`] [docs:COMMANDS.md] [docs:README.md]. The new `builtin-commands/2850-commit.php` is per-command-disjoint, not [commands].
- **Deps:** C-AGT-2.a (W1), C-AGT-2.c (W2), C-AGT-2.b (W7)
- **Brief:** `prompt_kit/findings/comparison-1026/design-agt.md` → `### C-AGT-6.a`

#### C-CTX-2.f — "Loaded" and "updated" instruction rows
- **Item:** C-CTX-2 (sources: GAP-INS-08w (notice half), INS-12 web) · gap · effort S
- **What:** Neither surface tells the user when a nested instruction file is loaded on touch, or when a root file changes mid-session (OpenCode shows "↳ Loaded", OC2 "Instructions updated"). Both producers record one uiOnly row through …
- **Files — create:** `candy-code/tests/Context/InstructionVisibilityNoticeTest.php`
- **Files — modify:** `candy-code/src/Context/InstructionFileLoader.php`, `candy-code/src/Context/InstructionDiffTracker.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Context/InstructionFileLoaderMidSessionNoticeTest.php`
- **Docs/guards:** `candy-code/docs/MEMORY.md` (instruction files: what you see); lang keys `chat.instructions.loaded`, `chat.instructions.updated`
- **Serialized:** [lang:chat.instructions.*] [docs:MEMORY.md] [hot:candy-code/src/Context/InstructionFileLoader.php] [hot:candy-code/src/Context/InstructionDiffTracker.php]
- **Deps:** C-CTX-1.a (W3), C-CTX-2.a (W8)
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-CTX-2.f`

#### C-CTX-3.b — `#` quick-add to personal instructions (TUI)
- **Item:** C-CTX-3 (sources: GAP-INS-16 (`#` quick-add)) · gap · effort S
- **What:** A single-line submit starting with `#` followed by a non-`#` character becomes a quick-add, not a turn. A uiOnly confirm row asks: Enter appends `- <text>` to personal `~/.candy-code/AGENTS.md` (created 0600 if absent, …
- **Files — create:** `candy-code/tests/Chat/InstructionQuickAddTest.php`
- **Files — modify:** `candy-code/src/Chat.php`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** `candy-code/docs/MEMORY.md` (quick-add). No key binding, so `KeyBindingDriftTest` is untouched.
- **Serialized:** [chat:submit,ctor,key dispatch] [lang:chat.instructions.quick_add*] [docs:MEMORY.md]
- **Deps:** C-CTX-1.a (W3)
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-CTX-3.b`

### W20

- protocol lanes: C-SES-7.e (weld regenerates schema → SERVER.md → generated.ts once)
- Chat.php region claims: C-CTX-3.a (READ_ONLY_COMMANDS, handleInstructionsCommand (new), memoryOpenArm region); C-EXT-3.d (handleAgentsCommand)

#### C-SES-7.e — Protocol: `session.import`, ephemeral `session.create`, archive/unarchive + list filter
- **Item:** C-SES-7 (sources: GAP-SES-14 (server half), GAP-SES-N1 (serve half), OC-new#8, GAP-SES-10) · gap · effort M
- **What:** One protocol lane carries three server surfaces, plus the web side. **(1) `session.import {format, content, name?}`** (Write, idempotent). It runs the C-SES-7.a importer in-process and answers the new summary. It is bounded by …
- **Files — create:** `candy-code/tests/Server/SessionImportMethodTest.php`, `candy-code/tests/Server/SessionArchiveMethodsTest.php`
- **Files — modify:** `candy-code/src/Protocol/Methods/SessionMethods.php`, `candy-code/src/Protocol/Schema/MethodSchemas.php`, `candy-code/src/Protocol/Schema/Definitions.php`, `candy-code/src/Protocol/ServerContext.php`, `candy-code/src/Host/SessionHub.php`, `candy-code/src/Host/SessionHost.php`, `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts`, `candy-code-web/src-web/stores/sessions.ts`, `candy-code-web/src-web/stores/newSession.ts`, `candy-code-web/src-web/components/SessionSidebar.vue`, `candy-code-web/src-web/protocol/__tests__/generated.spec.ts`
- **Tests — create:** `candy-code/tests/Server/SessionImportMethodTest.php`, `candy-code/tests/Server/SessionArchiveMethodsTest.php` · **modify:** `candy-code/tests/Host/SessionHubTest.php`, `candy-code-web/src-web/components/__tests__/sidebarActions.spec.ts`, `candy-code-web/src-web/stores/__tests__/sessionsRowActions.spec.ts`
- **Docs/guards:** the generation chain; `ProtocolSchemaDriftTest`, `ServerProtocolDocumentationDriftTest`, `generated.spec.ts`
- **Serialized:** [protocol] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Host/SessionHub.php] [hot:candy-code/src/Protocol/ServerContext.php] …
- **Deps:** C-SES-7.a (W14)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-7.e`

#### C-AGT-5.d — Signed inbound webhooks + delivery dedupe
- **Item:** C-AGT-5 (sources: p1-miss: inbound webhook signatures) · gap · effort M
- **What:** Each `webhooks` row gains an optional `signature: {scheme: "github-sha256"|"hmac-sha256", header?}` (`ServerConfig::webhooksValue()` ~:700-770). With a scheme, `HookWebhooks::handle()` verifies `sha256=<hex>` over the RAW body …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Server/ServerConfig.php`, `candy-code/src/Server/Http/HookWebhooks.php`, `candy-code/lang/en.php`, `candy-code/docs/SERVER.md`
- **Tests — create:** `candy-code/tests/Server/HookWebhooksSignatureTest.php` · **modify:** `candy-code/tests/Server/ServerConfigWebhooksTest.php`
- **Docs/guards:** `docs/SERVER.md`; `candy-code/tests/Config/ServerProtocolDocumentationDriftTest.php` stays green
- **Serialized:** [hot:candy-code/src/Server/Http/HookWebhooks.php] [hot:candy-code/src/Server/ServerConfig.php] [lang: `settings.webhooks.*`] [docs:SERVER.md]
- **Deps:** C-AGT-1.c (W1), C-AGT-7.c (W9)
- **Brief:** `prompt_kit/findings/comparison-1026/design-agt.md` → `### C-AGT-5.d`

#### C-AGT-6.c — `--worktree [name]` main sessions
- **Item:** C-AGT-6 (sources: GAP-GIT-08) · gap · effort M
- **What:** `--worktree [name]` (TUI and `-p`) parses in `ArgvParser` and `ParsedArgs`. Before `Bootstrap::app()`/`NonInteractive`, `bin/candy-code` calls a new `Cli\MainWorktree::enter($root, $name)`. It reuses …
- **Files — create:** `candy-code/src/Cli/MainWorktree.php`
- **Files — modify:** `candy-code/src/Cli/ArgvParser.php`, `candy-code/src/Cli/ParsedArgs.php`, `candy-code/src/Cli/Help.php`, `candy-code/src/Agents/WorktreeManager.php`, `candy-code/lang/en.php`, `candy-code/README.md`
- **Tests — create:** `candy-code/tests/Cli/MainWorktreeTest.php` · **modify:** `candy-code/tests/Cli/ArgvParserTest.php`, `candy-code/tests/Cli/LaunchFlagsTest.php`
- **Docs/guards:** `candy-code/tests/Config/ReadmeRosterDriftTest.php` (flag roster); the ArgvParser/bin flag mirror in `ArgvParserTest`. WorktreeManager's git calls are existing accounted sites, so no child-lifetime …
- **Serialized:** [headless] [lang: `cli.*worktree*`] [docs:README.md]
- **Deps:** C-AGT-2.a (W1), C-AGT-2.c (W2), C-AGT-2.b (W7)
- **Brief:** `prompt_kit/findings/comparison-1026/design-agt.md` → `### C-AGT-6.c`

#### C-CTX-3.a — `/instructions [open <n|path>]`
- **Item:** C-CTX-3 (sources: GAP-INS-16) · gap · effort M
- **What:** `/memory open <id>` edits memory notes only, and nothing opens CLAUDE.md or AGENTS.md (`EditorCommand::openFile()` has one caller). Add `/instructions`. It lists the session's instruction files with tier and bytes: the same rows …
- **Files — create:** `candy-code/builtin-commands/2405-instructions.php`, `candy-code/src/Commands/InstructionsCommand.php`, `candy-code/src/Host/Commands/InstructionsHostCommand.php`, `candy-code/tests/Commands/InstructionsCommandTest.php`
- **Files — modify:** `candy-code/src/Chat.php`, `candy-code/src/Host/ContextSources.php`, `candy-code/README.md`, `candy-code/docs/COMMANDS.md`, `candy-code/docs/MEMORY.md`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** `php tools/gen-command-docs.php --write`; `CommandDocsDriftTest`; `ReadmeRosterDriftTest::testTheSlashCommandRosterIsExactlyWhatTheRegistryAdvertises`; `RegistryTranslationTest`
- **Serialized:** [chat:handleInstructionsCommand (new), memoryOpenArm region, READ_ONLY_COMMANDS] [commands] [lang:cmd.instructions.*, host.instructions.*] [docs:COMMANDS.md] [docs:README.md] [docs:MEMORY.md] …
- **Deps:** C-CTX-1.a (W3), C-CTX-6.d (W9)
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-CTX-3.a`

#### C-CTX-3.d — Model recall over past sessions and the compaction journal
- **Item:** C-CTX-3 (sources: GAP-MEM-11/12, GAP-SES-11 (model half)) · gap · effort M
- **What:** The FTS5 session search is user-only, and the compaction journal feeds only the dream pass. The `Memory` tool's `recall` action gains `source: notes|sessions|journal` (default `notes`, so the existing schema keeps its meaning): …
- **Files — create:** `candy-code/tests/Tools/BuiltIn/MemoryRecallSourcesTest.php`
- **Files — modify:** `candy-code/src/Tools/BuiltIn/MemoryTool.php`, `candy-code/src/Session/SessionSearchIndex.php`, `candy-code/src/Memory/CompactionJournal.php`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** `php tools/gen-tool-docs.php --write` (Memory gloss and schema) under `ToolDocDriftTest`; `candy-code/docs/MEMORY.md` (recall sources)
- **Serialized:** [docs:MEMORY.md] [docs:README.md (tool gen region)] [hot:candy-code/src/Tools/BuiltIn/MemoryTool.php]
- **Deps:** C-SES-2.a (W2), C-SES-2.b (W3), C-SES-2.c (W4)
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-CTX-3.d`

#### C-CTX-4.a — Live date and knowledge-cutoff note
- **Item:** C-CTX-4 (sources: GAP-PRM-14) · gap · effort S
- **What:** The `<env>` date is frozen at session start, and there is no cutoff note. `TurnContextBlock` gains `withCurrentDate()`. It renders "Current date: YYYY-MM-DD" only when the date differs from the `<env>` date, so the prefix never …
- **Files — create:** `candy-code/tests/Context/TurnContextDateTest.php`
- **Files — modify:** `candy-code/src/Context/TurnContextBlock.php`, `candy-code/src/Runtime.php`, `candy-code/src/Context/EnvironmentBlock.php`, `candy-code/src/Providers/ModelMetadata.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Context/EnvironmentBlockTest.php`, `candy-code/tests/Providers/PromptPrefixByteStabilityTest.php`
- **Docs/guards:** `candy-code/docs/PROMPT_ENGINEERING.md`
- **Serialized:** [runtime:turnContext] [docs:PROMPT_ENGINEERING.md] [hot:candy-code/src/Context/EnvironmentBlock.php]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-CTX-4.a`

#### C-CTX-5.a — Summary resilience: overflow retry, summary-model fallback, failure-path pins
- **Item:** C-CTX-5 (sources: OC-new#9, OC-new#4 (fallback half), CLAW-new#5) · competitor-feature · effort M
- **What:** Already shipped: `summaryModel`, cache-reusing summaries, a heuristic fold on any summary error, and a stall ceiling. Missing 1: a compaction request that itself overflows the window has no second try. Missing 2: a configured …
- **Files — create:** `candy-code/tests/Backend/CacheReusingSummaryBackendTest.php`, `candy-code/tests/Host/CompactionFallbackParityTest.php`
- **Files — modify:** `candy-code/src/Backend/CacheReusingSummaryBackend.php`, `candy-code/src/Host/CompactionService.php`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** `candy-code/docs/CONTEXT.md` (compaction failure modes)
- **Serialized:** [docs:CONTEXT.md] [hot:candy-code/src/Host/CompactionService.php] [hot:candy-code/src/Backend/CacheReusingSummaryBackend.php]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-CTX-5.a`

#### C-EXT-3.b — `skills_search` tool + an honest listing-overflow line
- **Item:** C-EXT-3 (sources: GAP-SKL-14 context, CLAW-new#2) · gap · effort M
- **What:** **Root cause.** `SkillMatcher.php:58` writes the overflow line `"- %d more skills; call Skill to list them"`. The Skill tool's own description (`SkillTool.php:84-99`) says it "does not itself list, search, or run skills". A …
- **Files — create:** `candy-code/src/Tools/BuiltIn/SkillsSearch.php`
- **Files — modify:** `candy-code/src/Skills/SkillMatcher.php`, `candy-code/docs/SKILLS.md`, `candy-code/README.md`, `candy-code/docs/ARCHITECTURE.md`, `candy-code/docs/AGENTS_AUTHORING.md`, `candy-code/docs/PERMISSIONS.md`, `candy-code/docs/SETTINGS.md`
- **Tests — create:** `candy-code/tests/Tools/BuiltIn/SkillsSearchTest.php` · **modify:** `candy-code/tests/Skills/SkillMatcherListingTest.php`, `candy-code/tests/Tools/BuiltInToolCorpus.php`, `candy-code/tests/Tools/BuiltInToolCorpusTest.php`, `candy-code/tests/Config/DocFigureProseDriftTest.php`
- **Docs/guards:** `tools/gen-tool-docs.php --write`, ReadmeRosterDriftTest, ToolDescriptionSentenceFloorTest.
- **Serialized:** [catalog] (the next free position, which the orchestrator assigns: 41 if this is the first new tool) [docs:README.md] [docs:ARCHITECTURE.md] [docs:AGENTS_AUTHORING.md] [docs:PERMISSIONS.md] …
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-ext.md` → `### C-EXT-3.b`

#### C-EXT-3.d — `/agents new` drafts, `/agents accept` promotes
- **Item:** C-EXT-3 (sources: GAP-AGT-07) · gap · effort M
- **What:** **Why the GAP's plan fails.** The GAP proposes a canned prompt that Writes `.candy-code/agents/<name>.md`. `ProtectFilesHook::WRITE_ONLY_PATTERNS` (`ProtectFilesHook.php:244`) denies that path outright, because a preset's …
- **Files — create:** `candy-code/src/Host/Commands/NewAgentPrompt.php`
- **Files — modify:** `candy-code/src/Host/Commands/AgentsHostCommand.php`, `candy-code/src/Chat.php`, `candy-code/builtin-commands/0800-agents.php`, `candy-code/lang/en.php`, `candy-code/docs/AGENTS_AUTHORING.md`, `candy-code/docs/COMMANDS.md`
- **Tests — create:** `candy-code/tests/Host/Commands/AgentsNewAcceptTest.php` · **modify:** `candy-code/tests/Chat/PaletteSupersetDispatchTest.php`
- **Docs/guards:** gen-command-docs; AGENTS_AUTHORING.md.
- **Serialized:** [chat:handleAgentsCommand] [commands:builtin-commands/0800-agents.php] [docs:AGENTS_AUTHORING.md] [docs:COMMANDS.md] [lang:host.agents.new.,cmd.agents.] …
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-ext.md` → `### C-EXT-3.d`

#### C-EXT-4.c — `/<bundle>:<cmd>` namespacing
- **Item:** C-EXT-4 (sources: GAP-CMD-10) · gap · effort S
- **What:** **Root cause.** `ForeignCommandDiscovery::bundleDirs()` (:143-155) and `walk()` (:217-229) key bundle rows by bare file name. Two bundles that both ship `review.md` collapse last-write-wins. **Fix.** Bundle rows are keyed …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Commands/ForeignCommandDiscovery.php`, `candy-code/src/Commands/CommandLoader.php`, `candy-code/docs/PLUGINS.md`, `candy-code/docs/COMMANDS.md`
- **Tests — create:** `candy-code/tests/Commands/BundleCommandNamespaceTest.php` · **modify:** `candy-code/tests/Commands/ForeignCommandDiscoveryTest.php`, `candy-code/tests/Bundles/BundleSurfaceWiringTest.php`
- **Docs/guards:** gen-command-docs if the table lists file rows.
- **Serialized:** [docs:PLUGINS.md] [docs:COMMANDS.md] [hot:candy-code/src/Commands/ForeignCommandDiscovery.php]
- **Deps:** C-SEC-3.a (W2), C-SEC-3.b (W3)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ext.md` → `### C-EXT-4.c`

### W21

- protocol lanes: C-WEB-4.f, C-CTX-3.c (weld regenerates schema → SERVER.md → generated.ts once)
- Chat.php region claims: C-WEB-2.b (skillInvocationDraft); C-EXT-5.b (palette — paletteItemLabels, runPaletteAction, runRootPaletteAction)
- Bootstrap.php method claims: C-EXT-4.d (chat() launch drain, launch-cap census map, reportBundleRecommendations (new))

#### C-WEB-2.b — Typed `/skill-name` on served sessions (shared rewrite)
- **Item:** C-WEB-2 (sources: GAP-SKL-06, p1-miss: VIS-5 parity) · gap · M
- **What:** **Gap.** Only `Chat::submit()` rewrites `/name args` into the `$name` mention channel (`candy-code/src/Chat.php:9586`, `skillInvocationDraft()` :11318). Served sessions send it as prose. **Lift.** Move the body into a pure …
- **Files — create:** `candy-code/src/Skills/SkillSlashInvocation.php`, `candy-code/tests/Skills/SkillSlashInvocationTest.php`, `candy-code/tests/Host/ServedSkillSlashTest.php`
- **Files — modify:** `candy-code/src/Chat.php`, `candy-code/src/Host/SessionHost.php`, `candy-code/docs/SKILLS.md`
- **Tests — create:** `candy-code/tests/Skills/SkillSlashInvocationTest.php`, `candy-code/tests/Host/ServedSkillSlashTest.php` · **modify:** `candy-code/tests/Skills/SlashSkillInvocationTest.php`
- **Docs/guards:** `docs/SKILLS.md`.
- **Serialized:** [chat:skillInvocationDraft] [hot:candy-code/src/Host/SessionHost.php] [docs:SKILLS.md]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-web.md` → `### C-WEB-2.b`

#### C-WEB-4.f — Branch badge (`SessionSummary.branch`)
- **Item:** C-WEB-4 (sources: GAP-GIT-01) · gap · S
- **What:** **Gap.** The `sessions` row already stores `git_branch` (`candy-code/src/Session/SessionStore.php:271,311,776`), but `SessionSummary` (`src/Protocol/Schema/Definitions.php:44-60`) does not carry it. **Server.** Add a nullable …
- **Files — create:** `candy-code/tests/Protocol/SessionSummaryBranchTest.php`, `candy-code-web/src-web/components/__tests__/branchBadge.spec.ts`
- **Files — modify:** `candy-code/src/Protocol/Schema/Definitions.php`, `candy-code/src/Protocol/ServerContext.php`, `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts`, `candy-code-web/src-web/components/StatusBar.vue`, `candy-code-web/src-web/components/SessionSidebar.vue`
- **Tests — create:** `candy-code/tests/Protocol/SessionSummaryBranchTest.php`, `candy-code-web/src-web/components/__tests__/branchBadge.spec.ts` · **modify:** none
- **Docs/guards:** the protocol gates; `candy-code-web/README.md` (soft).
- **Serialized:** [protocol] [hot:candy-code/src/Protocol/ServerContext.php] [hot:candy-code-web/src-web/components/StatusBar.vue] [hot:candy-code-web/src-web/components/SessionSidebar.vue]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-web.md` → `### C-WEB-4.f`

#### C-CTX-3.c — Web instruction editing and `#` quick-add (protocol lane)
- **Item:** C-CTX-3 (sources: GAP-INS-16 (web half)) · gap · effort M
- **What:** New write-scoped method `context.instructionWrite {sessionId, path, content? | append?}`. It is jailed to the paths `context.sources` lists for the session, plus the personal file. It refuses symlink escapes (`ContainedPath`), …
- **Files — create:** `candy-code/tests/Protocol/ContextInstructionWriteTest.php`, `candy-code-web/src-web/components/panels/__tests__/contextPanelEdit.spec.ts`, `candy-code-web/src-web/components/__tests__/composerQuickAdd.spec.ts`
- **Files — modify:** `candy-code/src/Protocol/Methods/ContextMethods.php`, `candy-code/src/Protocol/Schema/MethodSchemas.php`, `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts`, `candy-code-web/src-web/components/panels/ContextPanel.vue`, `candy-code-web/src-web/components/Composer.vue`, `candy-code/docs/MEMORY.md`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** `ServerProtocolDocumentationDriftTest`, `ProtocolSchemaDriftTest`, both gen `--check`s
- **Serialized:** [protocol] [docs:SERVER.md] [docs:MEMORY.md] [hot:candy-code-web/src-web/components/Composer.vue] [hot:candy-code-web/src-web/components/panels/ContextPanel.vue]
- **Deps:** C-CTX-1.a (W3), C-CTX-2.d (W16)
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-CTX-3.c`

#### C-CTX-3.e — Memory export and prompt-visible import scope
- **Item:** C-CTX-3 (sources: GAP-MEM-14) · gap · effort S
- **What:** Imports land in the agent scope (`ForeignMemoryImporter.php:254, 317`, `MemoryScope::Local`), outside the index and recall. `/memory import … --scope project|user` routes through `MemoryWriter`, so imported notes are indexed and …
- **Files — create:** `candy-code/tests/Host/Commands/MemoryExportCommandTest.php`
- **Files — modify:** `candy-code/src/Host/Commands/MemoryCommand.php`, `candy-code/src/Memory/ForeignMemoryImporter.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Commands/MemoryImportCommandTest.php`
- **Docs/guards:** `candy-code/docs/MEMORY.md` (import/export); lang `host.memory.export*`, `host.memory.usage.*`
- **Serialized:** [lang:host.memory.*] [docs:MEMORY.md]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-CTX-3.e`

#### C-CTX-4.f — Project references (extra read-only roots)
- **Item:** C-CTX-4 (sources: GAP-PRM-N2) · gap · effort M
- **What:** New user-tier only setting `references: [{path, name}]`. Each reference is advertised in `<env>` through `EnvironmentBlock::withSettings()`, with a capped `RepoMapBlock` summary. Read, Grep, Glob and Ls accept paths under a …
- **Files — create:** `candy-code/tests/Context/ProjectReferencesTest.php`
- **Files — modify:** `candy-code/src/Context/EnvironmentBlock.php`, `candy-code/src/Tools/Catalog/ToolBuildContext.php`, `candy-code/src/Cli/LaunchContext.php`, `candy-code/src/Tools/BuiltIn/Read.php`, `candy-code/src/Tools/BuiltIn/Grep.php`, `candy-code/src/Tools/BuiltIn/Glob.php`, `candy-code/src/Tools/BuiltIn/Ls.php`, `candy-code/src/Config/Settings/Definitions/ToolSettings.php`, `candy-code/src/Config/LayeredSettings.php`, `candy-code/docs/SETTINGS.md`, `candy-code/docs/PERMISSIONS.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Tools/BuiltIn/GlobTest.php`
- **Docs/guards:** settings gen + `SettingsSchemaDocDriftTest`; `candy-code/tests/Config/PermissionsIntroDriftTest.php` if the intro roster moves
- **Serialized:** [docs:SETTINGS.md] [docs:PERMISSIONS.md] [hot:candy-code/src/Cli/LaunchContext.php] [hot:candy-code/src/Context/EnvironmentBlock.php] [hot:candy-code/src/Config/LayeredSettings.php] …
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-CTX-4.f`

#### C-CTX-4.g — Headless `--append-system-prompt`
- **Item:** C-CTX-4 (sources: GAP-PRM-09) · gap · effort S
- **What:** New flag `--append-system-prompt <text|@file>`, valid with `-p` only (refused on a TUI launch, like the AUT-4-P1 lesson). The text is spliced as one `<user-rules>`-fenced section at the end of the system prompt: never an …
- **Files — create:** `candy-code/tests/Cli/AppendSystemPromptTest.php`
- **Files — modify:** `candy-code/src/Cli/ArgvParser.php`, `candy-code/src/Cli/ParsedArgs.php`, `candy-code/src/Cli/NonInteractive.php`, `candy-code/src/Runtime.php`, `candy-code/README.md`, `candy-code/docs/PROMPT_ENGINEERING.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Cli/ArgvParserTest.php`
- **Docs/guards:** `candy-code/tests/Config/ReadmeRosterDriftTest.php::testTheSubcommandFenceNamesEveryVerbTheParserAccepts` (flag roster); `candy-code/tests/Config/ReadmeJsonErrorContractDriftTest.php`
- **Serialized:** [headless] [runtime:systemPromptSections] [docs:README.md] [docs:PROMPT_ENGINEERING.md]
- **Deps:** none
- **Ruling:** May `-p` accept `--append-system-prompt` (fenced, never an override) against PROMPT_ENGINEERING.md's documented no-append doctrine? — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-CTX-4.g`

#### C-EXT-3.e — Self-learning skills: SkillWorkshop tool + versioned drafts + history/undo
- **Item:** C-EXT-3 (sources: CLAW-new#1, GAP-SKL-14) · gap · effort M
- **What:** **Tool.** A `SkillWorkshop` tool (Write class, so it is asked in default mode) lets the model create or patch a skill during a turn. **Store.** Writes land in a versioned store, `LearnedSkills`. It wraps `ProposedSkills`; each …
- **Files — create:** `candy-code/src/Skills/LearnedSkills.php`, `candy-code/src/Skills/LearnedSkillRevision.php`, `candy-code/src/Tools/BuiltIn/SkillWorkshop.php`
- **Files — modify:** `candy-code/src/Host/Commands/SkillsHostCommand.php`, `candy-code/lang/en.php`, `candy-code/docs/SKILLS.md`
- **Tests — create:** `candy-code/tests/Skills/LearnedSkillsTest.php`, `candy-code/tests/Tools/BuiltIn/SkillWorkshopTest.php`, `candy-code/tests/Host/Commands/SkillsHistoryUndoTest.php` · **modify:** `candy-code/tests/Tools/BuiltInToolCorpus.php`
- **Docs/guards:** gen-tool-docs, ReadmeRosterDriftTest, ReadPathCensusTest (a new user-tier path).
- **Serialized:** [catalog] [docs:README.md] [docs:ARCHITECTURE.md] [docs:AGENTS_AUTHORING.md] [docs:PERMISSIONS.md] [docs:SETTINGS.md] [docs:SKILLS.md] [lang:host.skills.history.] …
- **Deps:** C-EXT-3.c (W15)
- **Ruling:** R-EXT-2. May a model-written skill go live without `/skills accept` (OpenClaw's announce-and-undo), or do workshop skills stay drafts? — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-ext.md` → `### C-EXT-3.e`

#### C-EXT-4.d — Contextual bundle recommendations at launch
- **Item:** C-EXT-4 (sources: CC-new#12 plugin recommendations) · gap · effort S
- **What:** a new `Bootstrap::reportBundleRecommendations()` in the `chat()` launch drain block (~:1524) emits ONE aggregate row from `BundleRecommender`, for example "bundles matching this project: X@Y — candy-code bundles install X@Y". It …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Cli/Bootstrap.php`, `candy-code/lang/en.php`
- **Tests — create:** `candy-code/tests/Cli/BundleRecommendationNoticeTest.php` · **modify:** `candy-code/tests/Cli/BootstrapLaunchNoticeCapCensusTest.php`
- **Docs/guards:** the cap census row (1); PLUGINS.md mention is reported for the C-EXT-4.b owner or the weld.
- **Serialized:** [bootstrap:chat() launch drain, reportBundleRecommendations (new), launch-cap census map] [lang:bundles.recommend.]
- **Deps:** C-EXT-4.a (W14), C-EXT-4.b (W16)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ext.md` → `### C-EXT-4.d`

#### C-EXT-5.b — Palette shows every slash command, file command and MCP prompt
- **Item:** C-EXT-5 + C-EXT-7 VIS-4-P1 (sources: GAP-CMD-08, VIS-4-P1) · gap · effort M
- **What:** **Root cause.** `Chat::paletteItemLabels()`'s default arm (:16790) lists only `PaletteAction::all()`, and `runPaletteAction()` (:10491) throws on any other label. The 15 `SLASH_ONLY_SURVIVORS` …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Chat.php`, `candy-code/src/Palette/PaletteAction.php`, `candy-code/lang/en.php`, `candy-code/docs/COMMANDS.md`
- **Tests — create:** `candy-code/tests/Chat/PalettePrefillRowsTest.php` · **modify:** `candy-code/tests/Chat/PaletteSupersetDispatchTest.php`
- **Docs/guards:** COMMANDS.md. COMPARISON.md CMD-08 flips at closeout, not in-lane.
- **Serialized:** [chat:palette — paletteItemLabels, runPaletteAction, runRootPaletteAction] [docs:COMMANDS.md] [lang:palette.]
- **Deps:** C-EXT-5.a (W16)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ext.md` → `### C-EXT-5.b`

#### C-EXT-5.c — Key-bindable slash commands (`command:<name>`)
- **Item:** C-EXT-5 (sources: CC-new#12 key-bindable slash commands) · gap · effort M
- **What:** **Setting.** `keybindings` accepts `"command:<name>": "<chord>"`. `KeyBindingsValidator::validate()` (:42) checks the name syntactically (colon allowed, for bundle and MCP names) and the chord with the existing parser. …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Commands/KeyBindingRegistry.php`, `candy-code/src/Config/Settings/Validator/KeyBindingsValidator.php`, `candy-code/src/App/App.php`, `candy-code/src/Renderer.php`, `candy-code/src/Config/Settings/Definitions/InterfaceSettings.php`, `candy-code/lang/en.php`, `candy-code/docs/SETTINGS.md`
- **Tests — create:** `candy-code/tests/Commands/KeyBindingCommandBindingTest.php`, `candy-code/tests/App/CommandKeybindingDispatchTest.php` · **modify:** `candy-code/tests/Commands/KeysReloadTest.php`, `candy-code/tests/Cli/KeybindingCollisionNoticeTest.php`
- **Docs/guards:** SETTINGS.md through gen-settings-doc; the KeysReload memo census.
- **Serialized:** [renderer:renderKeyHelp] [docs:SETTINGS.md] [lang:keys.command.] [hot:candy-code/src/App/App.php] [hot:candy-code/src/Commands/KeyBindingRegistry.php]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-ext.md` → `### C-EXT-5.c`

### W22

- protocol lanes: C-AGT-4.c (weld regenerates schema → SERVER.md → generated.ts once)
- Chat.php region claims: C-AGT-4.d (mentionPopupRows, userTurnMessage); C-PRV-3.b (READ_ONLY_COMMANDS, handleFastCommand (new))

#### C-AGT-4.c — Web agent select + `SessionSummary.agent`
- **Item:** C-AGT-4 (sources: GAP-AGT-02w) · gap · effort M
- **What:** Add a nullable `agent` to `SessionSummary` (`src/Protocol/Schema/Definitions.php`), filled by `ServerContext::summary()` (:320) from `SessionHost::primaryAgent()`. A switch announces `session.updated`. Add a `mode` field to …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Protocol/Schema/Definitions.php`, `candy-code/src/Protocol/ServerContext.php`, `candy-code/src/Protocol/Methods/AgentsMethods.php`, `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts`, `candy-code-web/src-web/components/StatusBar.vue`, `candy-code-web/src-web/stores/agents/`
- **Tests — create:** none · **modify:** `candy-code/tests/Protocol/ProtocolSchemaDriftTest.php`, `candy-code-web/src-web/components/__tests__/statusBar.spec.ts`
- **Docs/guards:** `scripts/gen-protocol-schema.php --check`, `npm run gen:protocol -- --check`, `candy-code/tests/Config/ServerProtocolDocumentationDriftTest.php`; rebuild `candy-code-web/dist/` (weld)
- **Serialized:** [protocol] [hot:candy-code/src/Protocol/ServerContext.php] [hot:candy-code-web/src-web/components/StatusBar.vue] [docs:SERVER.md]
- **Deps:** C-AGT-4.b (W18)
- **Brief:** `prompt_kit/findings/comparison-1026/design-agt.md` → `### C-AGT-4.c`

#### C-AGT-4.d — `@agent:<name>` mentions (TUI, serve, `-p`, web)
- **Item:** C-AGT-4 (sources: GAP-AGT-20) · gap · effort M
- **What:** Reserve `@agent:<name>` in `ContextMentions` (`MENTION_PATTERN`/`KEYWORD_PATTERN` :73-76, `resolve()` :186). A known name becomes a framed directive attachment, "the user asks you to delegate this to agent `<name>` via the Task …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Attachments/ContextMentions.php`, `candy-code/src/Host/TurnController.php`, `candy-code/src/Chat.php`, `candy-code/src/Host/SessionHost.php`, `candy-code/src/Cli/NonInteractive.php`, `candy-code-web/src-web/components/Composer.vue`, `candy-code/README.md`, `candy-code/docs/AGENTS.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Attachments/ContextMentionsTest.php`, `candy-code/tests/Host/TurnControllerTest.php`, `candy-code/tests/Cli/NonInteractiveMentionsTest.php`, `candy-code-web/src-web/components/__tests__/composerFilesImages.spec.ts`
- **Docs/guards:** README mentions section; `docs/AGENTS.md`; rebuild `candy-code-web/dist/` (weld)
- **Serialized:** [chat:userTurnMessage, mentionPopupRows] [headless] [hot:candy-code/src/Attachments/ContextMentions.php] [hot:candy-code/src/Host/TurnController.php] [hot:candy-code/src/Host/SessionHost.php] …
- **Deps:** C-AGT-3.a (W6), C-AGT-3.b (W7)
- **Brief:** `prompt_kit/findings/comparison-1026/design-agt.md` → `### C-AGT-4.d`

#### C-CTX-3.f — Standing intents
- **Item:** C-CTX-3 (sources: GAP-MEM-N3) · gap · effort M
- **What:** Add an `intent` memory type with a `when:` trigger: a path glob, a tool name or a keyword. The model saves one with `Memory save type=intent when=…`. At tool time, `RulePathNudge` consults intent notes through the existing …
- **Files — create:** `candy-code/tests/Context/StandingIntentTest.php`
- **Files — modify:** `candy-code/src/Memory/MemoryWriter.php`, `candy-code/src/Memory/MemoryEntry.php`, `candy-code/src/Tools/BuiltIn/MemoryTool.php`, `candy-code/src/Context/RulePathNudge.php`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** tool gen (`ToolDocDriftTest`); `candy-code/docs/MEMORY.md` (intents)
- **Serialized:** [lang:host.memory.intent*] [docs:MEMORY.md] [hot:candy-code/src/Tools/BuiltIn/MemoryTool.php] [hot:candy-code/src/Context/RulePathNudge.php]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-CTX-3.f`

#### C-CTX-4.b — `SOUL.md` and `USER.md` persona files
- **Item:** C-CTX-4 (sources: GAP-PA-04) · gap · effort S
- **What:** `InstructionFileLoader::loadPersonal()` (:524) also reads `~/.candy-code/SOUL.md` and `~/.candy-code/USER.md`, OpenClaw's names. Each is fenced like the personal AGENTS.md, contained to the personal dir, and budgeted. …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Context/InstructionFileLoader.php`, `candy-code/src/Host/ContextSources.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Context/PersonalInstructionFileTest.php`
- **Docs/guards:** `candy-code/docs/MEMORY.md` (personal tier); keep `candy-code/tests/Config/MemoryDocumentationDriftTest.php` green
- **Serialized:** [docs:MEMORY.md] [hot:candy-code/src/Context/InstructionFileLoader.php] [hot:candy-code/src/Host/ContextSources.php]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-CTX-4.b`

#### C-EXT-3.a — `/skills reload` delivered as a turn-context roster delta
- **Item:** C-EXT-3 (sources: GAP-SKL-13, OC-new#6) · gap · effort M
- **What:** **Problem.** The roster is read once per launch, and the listing sits in the frozen system prompt. **Core.** `SkillRegistry::replaceAll()` swaps the skill set in place and keeps the disabled set. A new `SkillRosterDiffTracker` …
- **Files — create:** `candy-code/src/Skills/SkillRosterDiffTracker.php`
- **Files — modify:** `candy-code/src/Skills/SkillRegistry.php`, `candy-code/src/Context/TurnContextBlock.php`, `candy-code/src/Runtime.php`, `candy-code/src/Host/Commands/SkillsHostCommand.php`, `candy-code/lang/en.php`, `candy-code/docs/SKILLS.md`, `candy-code/docs/COMMANDS.md`
- **Tests — create:** `candy-code/tests/Skills/SkillRosterDiffTrackerTest.php`, `candy-code/tests/Host/Commands/SkillsReloadTest.php` · **modify:** `candy-code/tests/Skills/SkillRegistryTest.php`, `candy-code/tests/Context/TurnContextBlockTest.php`, `candy-code/tests/Providers/PromptPrefixByteStabilityTest.php`
- **Docs/guards:** SKILLS.md; gen-command-docs.
- **Serialized:** [runtime:turnContext] [docs:SKILLS.md] [docs:COMMANDS.md] [lang:host.skills.reload.] [hot:candy-code/src/Host/Commands/SkillsHostCommand.php]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-ext.md` → `### C-EXT-3.a`

#### C-EXT-6.a — Hook `systemMessage` shown as a notice
- **Item:** C-EXT-6 (sources: GAP-HK-08 systemMessage half) · gap · effort S
- **What:** **Root cause.** `ScriptHook.php:1633-1643` treats `systemMessage` as "HONORED BY REMOVAL": it is parsed and then dropped, because no human surface existed. `HOOKS.md:628` says "not read". **Fix.** A string `systemMessage` is …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Hooks/ScriptHook.php`, `candy-code/lang/en.php`, `candy-code/docs/HOOKS.md`
- **Tests — create:** `candy-code/tests/Hooks/ScriptHookSystemMessageNoticeTest.php` · **modify:** `candy-code/tests/Hooks/ScriptHookTest.php`
- **Docs/guards:** HOOKS.md
- **Serialized:** [docs:HOOKS.md] [lang:hooks.notice.]
- **Deps:** C-EXT-1.a (W1)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ext.md` → `### C-EXT-6.a`

#### C-MCP-3.c — HTTP/SSE server requests + streamable-HTTP GET channel
- **Item:** C-MCP-3 (sources: GAP-MCP-03, GAP-MCP-08, OC-new#1) · gap · effort M
- **What:** `HttpMcpServer` handshakes with `capabilities: {}` (`candy-code/src/MCP/HttpMcpServer.php:518-552`), and `replyFromEventStream()` (:828-851) looks only for its own id, dropping server requests and progress that ride the POST …
- **Files — create:** `candy-code/tests/MCP/HttpMcpServerGetStreamTest.php`, `candy-code/tests/MCP/SseMcpServerServerRequestTest.php`
- **Files — modify:** `candy-code/src/MCP/HttpMcpServer.php`, `candy-code/src/MCP/SseMcpServer.php`, `candy-code/src/MCP/SseLineParser.php`, `candy-code/src/MCP/McpClient.php`, `candy-code/docs/MCP.md`
- **Tests — create:** `candy-code/tests/MCP/HttpMcpServerGetStreamTest.php`, `candy-code/tests/MCP/SseMcpServerServerRequestTest.php` · **modify:** `candy-code/tests/MCP/HttpMcpServerStreamableHttpTest.php`
- **Docs/guards:** `DocFigureProseDriftTest::testMcpServerTypesKeysAndEnvInterpolationMatchTheFactory` (the http Keys cell gains `stream`) and `::testMcpTransportCensusRowsAreTheFactoryArms`; no proc_open, so the …
- **Serialized:** [docs:MCP.md §Supported transports + §Server requests] [hot:candy-code/src/MCP/McpClient.php]
- **Deps:** C-MCP-3.b (W16)
- **Brief:** `prompt_kit/findings/comparison-1026/design-mcp.md` → `### C-MCP-3.c`

#### C-MCP-3.d — Long MCP calls continue in background
- **Item:** C-MCP-3 (sources: CC-new#2) · feature · effort M
- **What:** Claude Code moves MCP calls running past 2 minutes to background tasks; candy-code has no equivalent (MCP-N2). A bridged or dispatched call runs in a forked grandchild (the house `Support/ForkedChild` helper). The library's fork …
- **Files — create:** `candy-code/src/MCP/McpBackgroundCalls.php`, `candy-code/src/Backend/McpBackgroundInbox.php`, `candy-code/tests/MCP/McpBackgroundCallTest.php`
- **Files — modify:** `candy-code/src/Tools/McpToolBridge.php`, `candy-code/src/Tools/BuiltIn/McpCallTool.php`, `candy-code/src/Backend/EngineBackend.php`, `candy-code/lang/en.php`, `candy-code/docs/MCP.md`
- **Tests — create:** `candy-code/tests/MCP/McpBackgroundCallTest.php` · **modify:** none
- **Docs/guards:** the fork census (`candy-code/tests/Support/ForkedChildExitConventionTest.php`) for the new fork site. Any `proc_open` must go through `ProcessContainment::spawnSpec` and get a …
- **Serialized:** [lang:mcp.background.] [docs:MCP.md §Server requests] [hot:candy-code/src/Backend/EngineBackend.php] [hot:candy-code/src/Tools/McpToolBridge.php]
- **Deps:** C-MCP-3.b (W16)
- **Ruling:** Background stdio calls hold that server's exchange lock until they finish, so later calls to the same server queue behind them. Accept, or background http/sse … — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-mcp.md` → `### C-MCP-3.d`

#### C-PRV-3.b — `/fast` command (TUI and served)
- **Item:** C-PRV-3 (sources: GAP-MOD-N2, CC parity) · gap · effort S
- **What:** `/fast [on|off]`: bare reports the state, and on/off toggles the live overlay. The setting persists to the user tier through `SettingsWriter`, like `persistEffort()` (`Chat.php:12332`). It refuses out loud when the provider/model …
- **Files — create:** `candy-code/builtin-commands/0360-fast.php`, `candy-code/src/Host/Commands/FastHostCommand.php`, `candy-code/tests/Chat/FastCommandTest.php`, `candy-code/tests/Host/Commands/FastHostCommandTest.php`
- **Files — modify:** `candy-code/src/Chat.php`, `candy-code/README.md`, `candy-code/docs/COMMANDS.md`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** `php tools/gen-command-docs.php --write`; `candy-code/tests/Commands/CommandDocsDriftTest.php`; …
- **Serialized:** [chat:handleFastCommand (new), READ_ONLY_COMMANDS] [commands] [lang:cmd.fast.*, host.fast.*] [docs:COMMANDS.md] [docs:README.md]
- **Deps:** C-PRV-3.a (W18)
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-PRV-3.b`

#### C-PRV-3.c — `ultrathink` raises effort for one turn
- **Item:** C-PRV-3 (sources: GAP-MOD-N1 (sic: CC-new#7 effort keyword), CC-new#7) · gap · effort S
- **What:** A typed prompt that contains the word `ultrathink` (case-insensitive, whole word, outside code fences) raises effort for that dispatch only. `TurnRunner::start()` serves both hosts (Chat at `Chat.php:12731`, SessionHost at …
- **Files — create:** `candy-code/src/Providers/EffortKeyword.php`, `candy-code/tests/Host/UltrathinkTurnTest.php`
- **Files — modify:** `candy-code/src/Host/TurnRunner.php`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** `candy-code/docs/SETTINGS.md` hand prose beside `reasoningEffort`; lang `chat.effort.ultrathink_*`
- **Serialized:** [lang:chat.effort.ultrathink*] [docs:SETTINGS.md] [hot:candy-code/src/Host/TurnRunner.php]
- **Deps:** C-PRV-1.a (W2), C-PRV-5.a (W9)
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-PRV-3.c`

### W23

- protocol lanes: C-AGT-5.f, C-SEC-6.c (weld regenerates schema → SERVER.md → generated.ts once)
- Chat.php region claims: C-AGT-4.e (pumpBackgroundSessions); C-AGT-5.f (pumpSchedules, requestPermission); C-CTX-4.e (handleOutputStyleCommand (new))
- Bootstrap.php method claims: C-AGT-5.f (workspace)

#### C-AGT-4.e — SendMessage reaches other sessions (`session:<id>`)
- **Item:** C-AGT-4 (sources: GAP-AGT-N3, GAP-TL-23) · gap · effort M
- **What:** Turns run in a `pcntl_fork()`ed child (`src/LSP/LspExchangeLock.php:11`), so delivery must cross processes. Use a disk mailbox: a new `Agents\Live\SessionMailbox`: 0700 `~/.candy-code/session-mail/<sessionId>/`, 0600 JSON files, …
- **Files — create:** `candy-code/src/Agents/Live/SessionMailbox.php`
- **Files — modify:** `candy-code/src/Tools/BuiltIn/SendMessageTool.php`, `candy-code/src/Permissions/PermissionGate.php`, `candy-code/src/Host/SessionHost.php`, `candy-code/src/Chat.php`, `candy-code/docs/AGENTS.md`, `candy-code/README.md`
- **Tests — create:** `candy-code/tests/Agents/Live/SessionMailboxTest.php`, `candy-code/tests/Tools/SendMessageCrossSessionTest.php` · **modify:** none
- **Docs/guards:** `candy-code/tests/Tools/Catalog/ToolDocDriftTest.php` if the `#[BuiltInTool]` gloss changes; `docs/AGENTS.md`; a `ReadPathCensusTest` row for the mailbox reads
- **Serialized:** [chat:pumpBackgroundSessions] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Permissions/PermissionGate.php] [docs:AGENTS.md] [docs:README.md (tools block)]
- **Deps:** C-AGT-1.b (W6), C-AGT-3.b (W7), C-AGT-7.a (W7)
- **Brief:** `prompt_kit/findings/comparison-1026/design-agt.md` → `### C-AGT-4.e`

#### C-AGT-5.f — Outbound webhooks wiring + `webhooksOut` setting
- **Item:** C-AGT-5 (sources: p1-miss: outbound webhooks, p2-miss: outbound webhooks) · gap · effort M
- **What:** Register a `webhooksOut` setting, user tier only, so a project can never add egress, through `SettingDefinition` in `ServerSettings.php`, read at restart. `Bootstrap::workspace()` registers one `WebhookDispatcher` service. TUI …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Config/Settings/Definitions/ServerSettings.php`, `candy-code/src/Cli/Bootstrap.php`, `candy-code/src/Chat.php`, `candy-code/src/Chat/Handlers/HandlesTurnCompletion.php`, `candy-code/src/Protocol/ServerContext.php`, `candy-code/src/Host/BackgroundEvents.php`, `candy-code/lang/en.php`, `candy-code/docs/SETTINGS.md`, `candy-code/docs/SERVER.md`
- **Tests — create:** `candy-code/tests/Automation/OutboundWebhookWiringTest.php` · **modify:** none
- **Docs/guards:** `php tools/gen-settings-doc.php` + `candy-code/tests/Config/Settings/SettingsSchemaDocDriftTest.php`; `candy-code/tests/Config/DocFigureProseDriftTest.php` (the settings count word "one hundred and …
- **Serialized:** [bootstrap:workspace] [chat:requestPermission, pumpSchedules] [hot:candy-code/src/Chat/Handlers/HandlesTurnCompletion.php] [hot:candy-code/src/Protocol/ServerContext.php] …
- **Deps:** C-AGT-5.c (W15), C-AGT-5.e (W15)
- **Brief:** `prompt_kit/findings/comparison-1026/design-agt.md` → `### C-AGT-5.f`

#### C-CTX-4.d — URL instruction sources and a shared egress guard
- **Item:** C-CTX-4 (sources: GAP-INS-11 (URL half)) · gap · effort M
- **What:** User-tier `instructions` entries that start with `https://` are fetched once per session in the parent prime, before any fork. Each fetch goes through an SSRF guard extracted from `WebFetch` into `Support\PublicUrlGuard`: …
- **Files — create:** `candy-code/src/Support/PublicUrlGuard.php`, `candy-code/tests/Support/PublicUrlGuardTest.php`, `candy-code/tests/Context/InstructionUrlSourceTest.php`
- **Files — modify:** `candy-code/src/Tools/BuiltIn/WebFetch.php`, `candy-code/src/Context/InstructionFileLoader.php`, `candy-code/docs/MEMORY.md`, `candy-code/docs/SETTINGS.md`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** SETTINGS/MEMORY prose; WebFetch's existing tests stay green
- **Serialized:** [docs:MEMORY.md] [docs:SETTINGS.md] [hot:candy-code/src/Context/InstructionFileLoader.php] [hot:candy-code/src/Tools/BuiltIn/WebFetch.php]
- **Deps:** C-CTX-4.c (W15)
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-CTX-4.d`

#### C-CTX-4.e — Output styles (`/output-style`)
- **Item:** C-CTX-4 (sources: GAP-PRM-10) · gap · effort M
- **What:** Styles are rulebook-kind files in `~/.candy-code/styles/*.md`, and in `.candy-code/styles/*.md` for the project. `RuleLoader` loads them with a `style` flag on `Rule`, and `RulesState` keeps exactly one style active per session. …
- **Files — create:** `candy-code/builtin-commands/1405-output-style.php`, `candy-code/src/Host/Commands/OutputStyleHostCommand.php`, `candy-code/tests/Context/OutputStyleTest.php`
- **Files — modify:** `candy-code/src/Context/Rule.php`, `candy-code/src/Context/RuleLoader.php`, `candy-code/src/Context/RulesState.php`, `candy-code/src/Chat.php`, `candy-code/README.md`, `candy-code/docs/COMMANDS.md`, `candy-code/docs/MEMORY.md`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** command gen; `CommandDocsDriftTest`; `ReadmeRosterDriftTest` slash roster; `RegistryTranslationTest`
- **Serialized:** [chat:handleOutputStyleCommand (new)] [lang:cmd.output-style.*, host.output_style.*] [docs:COMMANDS.md] [docs:README.md] [docs:MEMORY.md] [hot:candy-code/src/Context/Rule.php] …
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-CTX-4.e`

#### C-MCP-2.c — Web composer `@mcp:` resource picker
- **Item:** C-MCP-2 (sources: CLAW-new#9, p1-miss "web composer `@mcp:` completion") · feature · effort S
- **What:** The composer's `@` popup is file-only (`searchFiles` prop, `candy-code-web/src-web/components/Composer.vue:17-18, 72-110`). When the trailing partial starts with `mcp:`, the popup lists `mcp.resources` rows (fetched once per …
- **Files — create:** `candy-code-web/src-web/components/__tests__/composerMcpMentions.spec.ts`
- **Files — modify:** `candy-code-web/src-web/components/Composer.vue`, `candy-code-web/src-web/views/SessionView.vue`
- **Tests — create:** `candy-code-web/src-web/components/__tests__/composerMcpMentions.spec.ts` · **modify:** none
- **Docs/guards:** none (MCP.md parity sentence landed in C-MCP-2.a/2.b)
- **Serialized:** [hot:candy-code-web/src-web/components/Composer.vue] [hot:candy-code-web/src-web/views/SessionView.vue]
- **Deps:** C-MCP-2.b (W12)
- **Brief:** `prompt_kit/findings/comparison-1026/design-mcp.md` → `### C-MCP-2.c`

#### C-MCP-4.d — `mcp import --write user`
- **Item:** C-MCP-4 (sources: GAP-MCP-15) · gap · effort S
- **What:** `mcp import` is print-only by law (`candy-code/src/Cli/Subcommands.php:2348-2440`, "nothing_written"). Opt-in `--write user` appends the translated entries into `~/.candy-code/mcp.json` through `Support/AtomicFileWriter` (0600; …
- **Files — create:** `candy-code/tests/Cli/SubcommandsMcpImportWriteTest.php`
- **Files — modify:** `candy-code/src/Cli/Subcommands.php`, `candy-code/src/Cli/ParsedArgs.php`, `candy-code/src/MCP/McpForeignTranslate.php`, `candy-code/lang/en.php`, `candy-code/README.md`, `candy-code/docs/MCP.md`
- **Tests — create:** `candy-code/tests/Cli/SubcommandsMcpImportWriteTest.php` · **modify:** `candy-code/tests/Cli/SubcommandsMcpImportTest.php`, `candy-code/tests/Cli/StderrEmitterCensusTest.php`
- **Docs/guards:** `candy-code/tests/Support/ReadPathCensusTest.php` row for the new read of the existing user file (or reuse `McpDecision`'s read); …
- **Serialized:** [headless:Subcommands,ParsedArgs] [lang:cli.mcp.import.] [docs:README.md] [docs:MCP.md §Foreign spellings]
- **Deps:** none
- **Ruling:** Relax the "import never writes" law for an explicit `--write user` (user tier only, collisions refused)? — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-mcp.md` → `### C-MCP-4.d`

#### C-PRV-4.b — Codex isolation, mode → `-s`, `--output-schema`
- **Item:** C-PRV-4 (sources: PRV-4-P3, p1-miss: Codex parity (mode → `-s`, `--output-schema`)) · competitor-feature · effort M
- **What:** Root cause: `CodexInvocation::execArgs()` passes `-s` only when configured (`CodexInvocation.php:80-96`). Plan mode with a `workspace-write` config still edits files (`CodexProvider.php:27-31, 56-61`). Implement …
- **Files — create:** `candy-code/tests/Providers/CodexIsolationTest.php`
- **Files — modify:** `candy-code/src/Providers/CodexProvider.php`, `candy-code/src/Providers/CodexInvocation.php`, `candy-code/src/Providers/ProviderFactory.php`, `candy-code/README.md`, `candy-code/docs/SETTINGS.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Providers/CodexProviderTest.php`
- **Docs/guards:** README and SETTINGS posture prose (PRV-4-P3's "undocumented" half)
- **Serialized:** [docs:README.md] [docs:SETTINGS.md] [hot:candy-code/src/Providers/Codex*.php] [hot:candy-code/src/Providers/ProviderFactory.php]
- **Deps:** C-PRV-4.a (W19)
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-PRV-4.b`

#### C-SEC-6.c — Web saved-rule management and `remember: "user"`
- **Item:** C-SEC-6 (sources: GAP-PER-04(a), OC-new#3) · gap · effort M
- **What:** `PermissionMethods::respond()` refuses `remember:"user"` with `remember_user_unavailable` (`src/Protocol/Methods/PermissionMethods.php:68-70`). The fix answers Always and calls `SavedRules::append()` with the ask's patterns …
- **Files — create:** `candy-code/tests/Protocol/PermissionSavedRulesMethodsTest.php`, `candy-code-web/src-web/components/panels/__tests__/rulesPanelSaved.spec.ts`
- **Files — modify:** `candy-code/src/Protocol/Methods/PermissionMethods.php`, `candy-code/src/Protocol/Schema/MethodSchemas.php`, `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts`, `candy-code-web/src-web/components/PermissionCard.vue`, `candy-code-web/src-web/components/panels/RulesPanel.vue`, `candy-code-web/src-web/stores/approvals.ts`, `candy-code/docs/PERMISSIONS.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Server/PermissionFirstWinsTest.php`
- **Docs/guards:** protocol gen checks; `ServerProtocolDocumentationDriftTest`; `PermissionsIntroDriftTest`. Web dist rebuild.
- **Serialized:** [protocol] [hot:candy-code-web/src-web/components/PermissionCard.vue] [hot:candy-code-web/src-web/components/panels/RulesPanel.vue] [hot:candy-code-web/dist] [docs:PERMISSIONS.md]
- **Deps:** C-SEC-6.a (W16)
- **Brief:** `prompt_kit/findings/comparison-1026/design-sec.md` → `### C-SEC-6.c`

#### C-SEC-6.e — OTel safety events per verdict
- **Item:** C-SEC-6 (sources: CC-new#4) · gap · effort S
- **What:** Every refusal already reaches the encoder: `ToolFinished->result->denial` (`src/ToolResult.php:140`, a `DenialKind` of refused / unanswered / hook) crosses the fork today. Asks arrive as `PermissionAsked`, and rejections as …
- **Files — create:** `candy-code/tests/Observability/OtlpSafetyEventTest.php`
- **Files — modify:** `candy-code/src/Observability/OtlpEncoder.php`, `candy-code/docs/ENVIRONMENT.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Observability/OtlpEncoderTest.php`
- **Docs/guards:** `EnvRosterDriftTest` is untouched (no env var).
- **Serialized:** [hot:candy-code/src/Observability/OtlpEncoder.php] [docs:ENVIRONMENT.md]
- **Deps:** C-SES-4.a (W2), C-SES-4.b (W8), C-SES-4.c (W17), C-SES-4.d (W19)
- **Brief:** `prompt_kit/findings/comparison-1026/design-sec.md` → `### C-SEC-6.e`

#### C-SEC-7.c — Plan mode drops the tools the gate always refuses
- **Item:** C-SEC-7 (sources: GAP-TL-27, k2-sum TL-27 web ✅→🟡) · gap · effort M
- **What:** Plan mode refuses writes at the gate, but the model still sees every tool (`docs/PERMISSIONS.md:487-488`: "The tool list is the same in every mode"). The gap's proposal (drop `Runtime::WRITE_CAPABLE_TOOL_NAMES`) is wrong. Plan …
- **Files — create:** `candy-code/tests/Backend/PlanModeToolRosterTest.php`
- **Files — modify:** `candy-code/src/Backend/EngineBackend.php`, `candy-code/docs/PERMISSIONS.md`, `candy-code/docs/PROMPT_ENGINEERING.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Providers/PromptPrefixByteStabilityTest.php`
- **Docs/guards:** `PermissionsIntroDriftTest` (the "same in every mode" sentence)
- **Serialized:** [hot:candy-code/src/Backend/EngineBackend.php] [docs:PERMISSIONS.md] [docs:PROMPT_ENGINEERING.md]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-sec.md` → `### C-SEC-7.c`

### W24

- protocol lanes: C-AGT-6.e, C-SEC-6.d (weld regenerates schema → SERVER.md → generated.ts once)
- Chat.php region claims: C-CTX-5.b (dispatchTurn (ahead-of-need block)); C-SES-7.d (resume/switch arms, submit)
- Bootstrap.php method claims: C-PRV-3.d (advisorBackend (new)); C-SEC-6.b (permissionGate, permissionRules)

#### C-AGT-6.e — PR status chips in the web
- **Item:** C-AGT-6 (sources: p2-miss: WEB-8 PR chips) · gap · effort M
- **What:** For a session whose branch C-WEB-4 already exposes, the server reads `gh pr view <branch> --json number,state,url,statusCheckRollup` through `GhRunner`. It is cached per branch for 60 s and refreshed lazily on summary build, …
- **Files — create:** `candy-code/src/Workspace/PrStatusCache.php`
- **Files — modify:** `candy-code/src/Protocol/Schema/Definitions.php`, `candy-code/src/Protocol/ServerContext.php`, `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts`, `candy-code-web/src-web/components/StatusBar.vue`, `candy-code-web/src-web/components/SessionSidebar.vue`
- **Tests — create:** `candy-code/tests/Workspace/PrStatusCacheTest.php` · **modify:** `candy-code-web/src-web/components/__tests__/statusBar.spec.ts`, `candy-code-web/src-web/components/__tests__/sidebar.spec.ts`
- **Docs/guards:** protocol gen chain checks + `ServerProtocolDocumentationDriftTest`; rebuild `candy-code-web/dist/` (weld)
- **Serialized:** [protocol] [hot:candy-code/src/Protocol/ServerContext.php] [hot:candy-code-web/src-web/components/StatusBar.vue] [hot:candy-code-web/src-web/components/SessionSidebar.vue] [docs:SERVER.md]
- **Deps:** C-AGT-6.d (W15), C-WEB-4.a (W15), C-WEB-4.b (W18), C-WEB-4.c (W18), C-WEB-4.d (W19), C-WEB-4.e (W19), C-WEB-4.f (W21)
- **Ruling:** R13 (as C-AGT-6.d, plus showing CI status pulled with the user's GitHub token in a browser client) — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-agt.md` → `### C-AGT-6.e`

#### C-CTX-5.b — Ahead-of-need skip rules
- **Item:** C-CTX-5 (sources: OC-new#7 (skip-rule parity)) · competitor-feature · effort S
- **What:** The 70% tier prefetches summaries on both hosts: Chat at `Chat.php:10917-10935`, SessionHost at `SessionHost.php:1731-1749`. Move the request decision into `CompactionService::aheadOfNeedAllowed()` with OpenCode's skip rules: …
- **Files — create:** `candy-code/tests/Host/AheadOfNeedSkipRulesTest.php`
- **Files — modify:** `candy-code/src/Host/CompactionService.php`, `candy-code/src/Chat.php`, `candy-code/src/Host/SessionHost.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Host/SessionHostAheadOfNeedTest.php`
- **Docs/guards:** `candy-code/docs/CONTEXT.md` (one sentence)
- **Serialized:** [chat:dispatchTurn (ahead-of-need block)] [docs:CONTEXT.md] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Host/CompactionService.php]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-CTX-5.b`

#### C-PRV-3.d — Advisor tool
- **Item:** C-PRV-3 (sources: CC-new#7 (advisor half per candidates), GAP-MOD-N1) · gap · effort M
- **What:** A built-in `Advisor` tool, `#[BuiltInTool(...)]` at the next free position (41 at HEAD; the orchestrator assigns it). Argument `{question}`. The tool sends a bounded transcript digest (reusing `BtwHostCommand`'s digest builder) …
- **Files — create:** `candy-code/src/Tools/BuiltIn/AdvisorTool.php`, `candy-code/tests/Tools/BuiltIn/AdvisorToolTest.php`
- **Files — modify:** `candy-code/src/Cli/Bootstrap.php`, `candy-code/src/Tools/Catalog/ToolBuildContext.php`, `candy-code/src/Tools/Catalog/ToolCatalog.php`, `candy-code/src/Cli/LaunchContext.php`, `candy-code/src/Config/Settings/Definitions/ModelProviderSettings.php`, `candy-code/src/Config/LayeredSettings.php`, `candy-code/README.md`, `candy-code/docs/SETTINGS.md`, `candy-code/docs/ENVIRONMENT.md`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** `php tools/gen-tool-docs.php --write` under `candy-code/tests/Tools/Catalog/ToolDocDriftTest.php`; …
- **Serialized:** [catalog] [bootstrap:advisorBackend (new)] [lang:tool.advisor.*, settings.advisorModel.*] [docs:README.md] [docs:SETTINGS.md] [docs:ENVIRONMENT.md] [hot:candy-code/src/Cli/LaunchContext.php] …
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-PRV-3.d`

#### C-SEC-6.b — Project-tier deny/ask-only rules
- **Item:** C-SEC-6 (sources: GAP-PER-04(b)) · gap · effort M
- **What:** Today rules come only from the user tier: `Bootstrap::permissionConfigLayers()` (:6969) reads `settings.json` and `config.json`. Under the existing `trustedProjectSettings` grant, read `permissionRules` from …
- **Files — create:** `candy-code/src/Permissions/ProjectNarrowingRules.php`
- **Files — modify:** `candy-code/src/Cli/Bootstrap.php`, `candy-code/src/Host/Commands/PermissionsCommand.php`, `candy-code/lang/en.php`, `candy-code/docs/PERMISSIONS.md`, `candy-code/README.md`
- **Tests — create:** `candy-code/tests/Permissions/ProjectNarrowingRulesTest.php`, `candy-code/tests/Cli/BootstrapProjectNarrowingRulesTest.php` · **modify:** none
- **Docs/guards:** `PermissionsIntroDriftTest`; `TrustKeyDocumentationDriftTest` (README tier sentences); `DocFigureProseDriftTest` if PERMISSIONS.md counts tiers
- **Serialized:** [bootstrap:permissionGate, permissionRules] [lang:appLaunch.notice.] [docs:PERMISSIONS.md] [docs:README.md]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-sec.md` → `### C-SEC-6.b`

#### C-SEC-6.d — Web permission audit view
- **Item:** C-SEC-6 (sources: GAP-PER-14) · gap · effort M
- **What:** `AuditHook` already writes a redacted, rotated log with three line shapes (`src/Hooks/BuiltIn/AuditHook.php` header: `=>`, `=! DENY <kind>`, `=! WITHHELD`). Nothing reads it. New `AuditLogReader`: a bounded tail of …
- **Files — create:** `candy-code/src/Hooks/BuiltIn/AuditLogReader.php`, `candy-code/tests/Hooks/AuditLogReaderTest.php`, `candy-code/tests/Protocol/PermissionAuditMethodTest.php`, `candy-code-web/src-web/components/panels/__tests__/rulesPanelAudit.spec.ts`
- **Files — modify:** `candy-code/src/Protocol/Methods/PermissionMethods.php`, `candy-code/src/Protocol/Schema/MethodSchemas.php`, `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts`, `candy-code-web/src-web/components/panels/RulesPanel.vue`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** protocol gen checks; `ServerProtocolDocumentationDriftTest`. Web dist rebuild.
- **Serialized:** [protocol] [hot:candy-code-web/src-web/components/panels/RulesPanel.vue] [hot:candy-code-web/dist]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-sec.md` → `### C-SEC-6.d`

#### C-SEC-8.a — Fence MCP tool output as untrusted
- **Item:** C-SEC-8 (sources: GAP-PER-10) · gap · effort S
- **What:** `McpToolBridge::replyResult()` (`src/Tools/McpToolBridge.php:599-607`) is the one renderer for both a bridged MCP tool and `mcp_call` (`src/Tools/BuiltIn/McpCallTool.php`). It returns third-party server text raw. New …
- **Files — create:** `candy-code/src/Context/McpOutputFence.php`, `candy-code/tests/Context/McpOutputFenceTest.php`
- **Files — modify:** `candy-code/src/Tools/McpToolBridge.php`, `candy-code/src/Tools/BuiltIn/McpCallTool.php`, `candy-code/docs/MCP.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Tools/McpToolBridgeTest.php`, `candy-code/tests/Tools/McpToolBridgeResultCapTest.php`, `candy-code/tests/Permissions/McpDispatchParityTest.php`
- **Docs/guards:** none roster-pinned; check the `docs/MCP.md` wording
- **Serialized:** [hot:candy-code/src/Tools/McpToolBridge.php] [docs:MCP.md]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-sec.md` → `### C-SEC-8.a`

#### C-SEC-8.f — `candy-code login --keychain`
- **Item:** C-SEC-8 (sources: GAP-MOD-25) · gap · effort S
- **What:** `Subcommands::loginStoreKey()` (:937) writes the key into the user config. With `--keychain`, it instead stores the key through `Keychain::store()` (`secret-tool store` / `security add-generic-password -U`, secret on stdin, never …
- **Files — create:** `candy-code/tests/Cli/LoginKeychainTest.php`
- **Files — modify:** `candy-code/src/Cli/Subcommands.php`, `candy-code/src/Cli/ParsedArgs.php`, `candy-code/src/Cli/ArgvParser.php`, `candy-code/src/Support/Keychain.php`, `candy-code/README.md`, `candy-code/lang/en.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Cli/LoginSubcommandTest.php`
- **Docs/guards:** `ReadmeRosterDriftTest`; the completion roster pin
- **Serialized:** [headless] [docs:README.md] [lang:cli.login.keychain.]
- **Deps:** C-SEC-8.e (W18)
- **Brief:** `prompt_kit/findings/comparison-1026/design-sec.md` → `### C-SEC-8.f`

#### C-SES-6.d — Workflows: release ships slim gz phar, manifest, formula, installer
- **Item:** C-SES-6 (sources: p2-miss: slim phar, p2-miss: reproducible phar builds, GAP-PLAT-03) · gap · effort S
- **What:** `release-phar.yml` gains four outputs: a second build with `--gz`, shipped as `candy-code-gz.phar` with its own `.sha256` and signatures; the plain asset stays the self-update target; `--manifest-out` uploaded as …
- **Files — create:** none
- **Files — modify:** `.github/workflows/release-phar.yml`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** none
- **Serialized:** [workflows]
- **Deps:** C-SES-5.c (W9), C-SES-6.b (W17), C-SES-6.c (W17)
- **Ruling:** Create a Homebrew tap repo plus a push secret? (Without it the step ships the formula only as a release asset, and the push leg stays off.) — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-6.d`

#### C-SES-7.b — `candy-code session import` verb
- **Item:** C-SES-7 (sources: GAP-SES-10, p2-miss: session import) · gap · effort S
- **What:** Add `candy-code session import <file> [--format auto|candy-code|claude-code|codex|opencode] [--name <n>] [--output-format json]`. It runs the C-SES-7.a importer and prints the new id plus a `--resume` hint. …
- **Files — create:** `candy-code/tests/Cli/SessionImportSubcommandTest.php`
- **Files — modify:** `candy-code/src/Cli/Subcommands.php`, `candy-code/lang/en.php`, `candy-code/README.md`
- **Tests — create:** `candy-code/tests/Cli/SessionImportSubcommandTest.php` · **modify:** `candy-code/tests/Cli/SessionSubcommandsTest.php`
- **Docs/guards:** the README Subcommands block. `ReadmeRosterDriftTest` pins top-level verbs only, and `session` already exists.
- **Serialized:** [headless] [lang:cli.session.import] [docs:README.md]
- **Deps:** C-SES-7.a (W14)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-7.b`

#### C-SES-7.d — Resume-cost guard
- **Item:** C-SES-7 (sources: CC-new#10) · feature · effort M
- **What:** **When it fires.** After a stale resume (`--resume`, `--continue`, or a picker or tab switch onto a stored session), the FIRST submit is held once. **The estimate.** `ResumeCostEstimate` is a pure function of: the history token …
- **Files — create:** `candy-code/src/Host/ResumeCostEstimate.php`, `candy-code/tests/Host/ResumeCostEstimateTest.php`, `candy-code/tests/Chat/ResumeCostGuardTest.php`
- **Files — modify:** `candy-code/src/Chat.php`, `candy-code/src/Config/Settings/Definitions/InterfaceSettings.php`, `candy-code/lang/en.php`, `candy-code/docs/SETTINGS.md`
- **Tests — create:** `candy-code/tests/Host/ResumeCostEstimateTest.php`, `candy-code/tests/Chat/ResumeCostGuardTest.php` · **modify:** none
- **Docs/guards:** the SETTINGS.md regen; `SettingsSchemaDocDriftTest`; `DocFigureProseDriftTest` (the settings count word-number); the README Interface settings roster if it lists keys (verify in-step)
- **Serialized:** [chat:submit,resume/switch arms] [lang:chat.resume_cost] [docs:SETTINGS.md] [hot:candy-code/src/Config/Settings/Definitions/InterfaceSettings.php]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-7.d`

### W25

- protocol lanes: C-SES-8.a (weld regenerates schema → SERVER.md → generated.ts once)
- Chat.php region claims: C-SES-7.f (dispatchCommand, subscriptions); C-SES-8.d (handleInsightsCommand)
- Bootstrap.php method claims: C-SEC-7.a (permissionGate + DEFAULT_PERMISSION_MODE consts); C-SEC-7.b (bundles, filterToolSet, trustedRootsForThisProcess)

#### C-CTX-5.d — Opt-in prompt-cache keep-warm
- **Item:** C-CTX-5 (sources: GAP-MOD-N3, OC-new#5 (cache economics)) · competitor-feature · effort M
- **What:** New setting `promptCache.keepWarm` (int, default 0 = off). It is the number of warm pings allowed per idle stretch. Before the TTL lapses on an idle session that the cacheTtl fold did not take, a `Context\KeepWarmPolicy` decides …
- **Files — create:** `candy-code/src/Context/KeepWarmPolicy.php`, `candy-code/tests/Host/KeepWarmTest.php`
- **Files — modify:** `candy-code/src/Chat/Handlers/HandlesRunControl.php`, `candy-code/src/Host/SessionHost.php`, `candy-code/src/Backend/CacheHealthWatch.php`, `candy-code/src/Config/Settings/Definitions/ContextSettings.php`, `candy-code/src/Config/LayeredSettings.php`, `candy-code/docs/SETTINGS.md`, `candy-code/docs/CONTEXT.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Backend/CacheHealthWatchTest.php`
- **Docs/guards:** settings gen + `SettingsSchemaDocDriftTest`
- **Serialized:** [docs:CONTEXT.md] [docs:SETTINGS.md] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Chat/Handlers/HandlesRunControl.php] …
- **Deps:** C-CTX-5.c (W17)
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-CTX-5.d`

#### C-SEC-7.a — `-p` starts in `default` mode (asks, denies unattended)
- **Item:** C-SEC-7 (sources: CC-new#1) · gap · effort M
- **What:** `Bootstrap::DEFAULT_PERMISSION_MODE = BypassPermissions` (`src/Cli/Bootstrap.php:194`) is the fallback for both `-p` and the background daemon (:6827). Split it into `HEADLESS_DEFAULT_PERMISSION_MODE = Default` for `-p` and …
- **Files — create:** `candy-code/tests/Cli/HeadlessDefaultPermissionModeTest.php`
- **Files — modify:** `candy-code/src/Cli/Bootstrap.php`, `candy-code/src/Cli/NonInteractive.php`, `candy-code/src/Cli/HeadlessPermissionPrompt.php`, `candy-code/lang/en.php`, `candy-code/README.md`, `candy-code/docs/PERMISSIONS.md`
- **Tests — create:** none · **modify:** `tests/Cli/BootstrapPermissionGateTest.php`
- **Docs/guards:** `ReadmeRosterDriftTest`; `PermissionsIntroDriftTest`; `DocFigureProseDriftTest` (mode matrix)
- **Serialized:** [bootstrap:permissionGate + DEFAULT_PERMISSION_MODE consts] [headless] [lang:appLaunch.notice.] [lang:cli.headless.] [docs:README.md] [docs:PERMISSIONS.md]
- **Deps:** none
- **Ruling:** rv-sec-1 (`Bootstrap.php:6834`, "owner ruling: the default stands") kept the `-p` bypass default, and this pick reverses it. Confirm the flip for `-p` only, … — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-sec.md` → `### C-SEC-7.a`

#### C-SEC-7.b — `--restricted` and `--safe-mode`
- **Item:** C-SEC-7 (sources: GAP-PER-N2) · gap · effort M
- **What:** `--restricted` drops the code-running tools from the launch tool set through `Bootstrap::filterToolSet()` (:8671): `ShellSubjectTools::NAMES`, plus `Task`, `Workflow`, `ShellStop` and `mcp_call`. File tools stay under the …
- **Files — create:** `candy-code/tests/Cli/RestrictedSafeModeFlagsTest.php`
- **Files — modify:** `candy-code/src/Cli/ArgvParser.php`, `candy-code/src/Cli/ParsedArgs.php`, `candy-code/src/Cli/Subcommands.php`, `candy-code/src/Cli/Help.php`, `candy-code/src/Support/SessionRelaunch.php`, `candy-code/src/Cli/Bootstrap.php`, `candy-code/lang/en.php`, `candy-code/README.md`, `candy-code/docs/PERMISSIONS.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Cli/ArgvParserTest.php`, `candy-code/tests/Cli/HelpTest.php`
- **Docs/guards:** `ReadmeRosterDriftTest` (flag roster); the ArgvParser const-mirror test; the completion roster pin
- **Serialized:** [headless] [bootstrap:filterToolSet, trustedRootsForThisProcess, bundles] [lang:cli.] [docs:README.md] [docs:PERMISSIONS.md]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-sec.md` → `### C-SEC-7.b`

#### C-SES-7.f — `attach` runs server slash commands and follows foreign turns
- **Item:** C-SES-7 (sources: GAP-WEB-N5) · gap · effort M
- **What:** **Today.** An attached window runs slash commands on its own copy of the transcript and shows another client's turn only on re-attach (`docs/SERVER.md:1011-1014`). **Server-side commands.** When the backend is a `RemoteBackend`, …
- **Files — create:** `candy-code/src/App/RemoteFeedPumpMsg.php`, `candy-code/tests/Server/AttachRemoteCommandsTest.php`, `candy-code/tests/Server/AttachForeignTurnTest.php`
- **Files — modify:** `candy-code/src/Backend/RemoteBackend.php`, `candy-code/src/Host/RemoteSessionHost.php`, `candy-code/src/Chat.php`, `candy-code/src/Chat/Handlers/HandlesSessionSurface.php`, `candy-code/docs/SERVER.md`
- **Tests — create:** `candy-code/tests/Server/AttachRemoteCommandsTest.php`, `candy-code/tests/Server/AttachForeignTurnTest.php` · **modify:** none
- **Docs/guards:** SERVER.md prose; `ServerProtocolDocumentationDriftTest` (no roster change)
- **Serialized:** [chat:dispatchCommand,subscriptions] [hot:candy-code/src/Chat/Handlers/HandlesSessionSurface.php] [docs:SERVER.md]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-7.f`

#### C-SES-7.g — `/handoff --remote <url>` teleport
- **Item:** C-SES-7 (sources: GAP-SES-14) · gap · effort S
- **What:** `/handoff --remote <url>` exports the current session (`Exporter::toJson`). `RemoteSessionPush` connects through `Attach::connect()`, with the token from the state dir or env, the same way `attach` resolves it. It calls …
- **Files — create:** `candy-code/src/Session/Import/RemoteSessionPush.php`, `candy-code/tests/Server/HandoffRemotePushTest.php`
- **Files — modify:** `candy-code/src/Host/Commands/HandoffHostCommand.php`, `candy-code/lang/en.php`, `candy-code/docs/COMMANDS.md`, `candy-code/docs/SERVER.md`
- **Tests — create:** `candy-code/tests/Server/HandoffRemotePushTest.php` · **modify:** none
- **Docs/guards:** the COMMANDS.md regen; `CommandDocsDriftTest`
- **Serialized:** [lang:cmd.handoff.remote] [docs:COMMANDS.md] [docs:SERVER.md]
- **Deps:** C-SES-7.e (W20)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-7.g`

#### C-SES-8.a — `usage.rollup` RPC + web Usage panel with retention
- **Item:** C-SES-8 (sources: GAP-SES-15w/17w, GAP-OBS-03) · gap · effort M
- **What:** **The method.** `usage.rollup {sessionId?, scope: 'session'|'all', days?: 7|30}` (Read) returns: `{days[], models[], totals, unpriced, retentionDays, reason}`; built from `UsageHostCommand::rollupFacts()` (C-SES-9.a), one …
- **Files — create:** `candy-code/src/Protocol/Methods/UsageMethods.php`, `candy-code/tests/Protocol/UsageMethodsTest.php`, `candy-code-web/src-web/components/panels/UsagePanel.vue`, `candy-code-web/src-web/components/panels/__tests__/usagePanel.spec.ts`
- **Files — modify:** `candy-code/src/Protocol/Dispatcher.php`, `candy-code/src/Protocol/Schema/MethodSchemas.php`, `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts`, `candy-code-web/src-web/protocol/__tests__/generated.spec.ts`, `candy-code-web/src-web/components/panels/SidePanels.vue`, `candy-code-web/src-web/stores/agents/panels.ts`
- **Tests — create:** `candy-code/tests/Protocol/UsageMethodsTest.php`, `candy-code-web/src-web/components/panels/__tests__/usagePanel.spec.ts` · **modify:** none
- **Docs/guards:** the generation chain; `ProtocolSchemaDriftTest`, `ServerProtocolDocumentationDriftTest`, `generated.spec.ts`
- **Serialized:** [protocol] [hot:candy-code-web/src-web/components/panels/SidePanels.vue] [docs:SERVER.md (regen only)]
- **Deps:** C-SES-9.a (W7)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-8.a`

#### C-SES-8.d — `/insights` command wiring
- **Item:** C-SES-8 (sources: GAP-OBS-06) · gap · effort S
- **What:** Add a NEW spec file, `builtin-commands/2215-insights.php` (category App, `withHandler('handleInsightsCommand')`, `withHostCommand(InsightsHostCommand::class)`). It is per-command-disjoint, not serialized. Add …
- **Files — create:** `candy-code/builtin-commands/2215-insights.php`
- **Files — modify:** `candy-code/src/Chat.php`, `candy-code/README.md`, `candy-code/docs/COMMANDS.md`, `candy-code/tests/Commands/MenuBarLabelDerivationTest.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Commands/MenuBarLabelDerivationTest.php`, `candy-code/tests/Chat/PaletteSupersetDispatchTest.php`, `candy-code/tests/Commands/RegistryTranslationTest.php`
- **Docs/guards:** README slash roster → `ReadmeRosterDriftTest::testTheSlashCommandRosterIsExactlyWhatTheRegistryAdvertises`; the COMMANDS.md regen → `CommandDocsDriftTest`
- **Serialized:** [chat:handleInsightsCommand] [commands:READ_ONLY_COMMANDS] [docs:README.md] [docs:COMMANDS.md]
- **Deps:** C-SES-8.c (W17)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-8.d`

#### C-TOOL-2.a — WebFetch HTML→Markdown
- **Item:** C-TOOL-2 (sources: GAP-TL-12) · gap · effort M
- **What:** `WebFetch` returns 2xx bodies verbatim (`candy-code/src/Tools/BuiltIn/WebFetch.php:268` description, :415 body path). Add `HtmlToMarkdown`, which uses ext-dom `DOMDocument` + libxml (no network, no entity loading). It drops …
- **Files — create:** `candy-code/src/Tools/Web/HtmlToMarkdown.php`, `candy-code/tests/Tools/Web/HtmlToMarkdownTest.php`, `candy-code/tests/Tools/WebFetchMarkdownTest.php`
- **Files — modify:** `candy-code/src/Tools/BuiltIn/WebFetch.php`, `candy-code/composer.json`
- **Tests — create:** none · **modify:** `candy-code/tests/Tools/BuiltInToolTest.php`
- **Docs/guards:** none (gloss unchanged); new test files are listed for the weld
- **Serialized:** none
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-2.a`

#### C-TOOL-2.c — Anthropic server-side `web_search` backend
- **Item:** C-TOOL-2 (sources: GAP-TL-13 (Anthropic leg)) · gap · effort M
- **What:** Add `anthropic` to `webSearchBackend`. When it is chosen AND the provider is the direct Anthropic wire, `formatAnthropicTools()` (`candy-code/src/Providers/Concerns/AnthropicMessagesWire.php` ~:547) swaps the local `WebSearch` …
- **Files — create:** `candy-code/tests/Providers/AnthropicServerWebSearchTest.php`
- **Files — modify:** `candy-code/src/Providers/Concerns/AnthropicMessagesWire.php`, `candy-code/src/Providers/AnthropicProvider.php`, `candy-code/src/Tools/BuiltIn/WebSearch.php`, `candy-code/src/Tools/Web/Search/SearchBackends.php`, `candy-code/src/Config/Settings/Definitions/ToolSettings.php`, `candy-code/src/Usage.php`, `candy-code/docs/SETTINGS.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Providers/PromptPrefixByteStabilityTest.php`
- **Docs/guards:** `SettingsSchemaDocDriftTest`
- **Serialized:** [hot:candy-code/src/Providers/Concerns/AnthropicMessagesWire.php] [docs:SETTINGS.md]
- **Deps:** C-TOOL-2.b (W19)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-2.c`

#### C-TOOL-2.e — User PDF attachments on Bedrock/Gemini/OpenAI-chat
- **Item:** C-TOOL-2 (sources: p1-miss: documents on non-Anthropic wires) · gap · effort M
- **What:** CTX-2 declined `document` blocks outside the Anthropic family (`candy-code/src/Providers/AttachmentEncoding.php` class docblock; `BedrockProvider::supportsDocuments()` :445 returns false; `GeminiProvider::supportsDocuments()` …
- **Files — create:** `candy-code/tests/Providers/DocumentAttachmentWireTest.php`
- **Files — modify:** `candy-code/src/Providers/AttachmentEncoding.php`, `candy-code/src/Providers/BedrockProvider.php`, `candy-code/src/Providers/GeminiProvider.php`, `candy-code/src/Providers/VertexProvider.php`, `candy-code/src/Providers/OpenAIProvider.php`, `candy-code/docs/ADDING_A_PROVIDER.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Providers/AttachmentWireTest.php`
- **Docs/guards:** `docs/ADDING_A_PROVIDER.md` in-step
- **Serialized:** [hot:candy-code/src/Providers/AttachmentEncoding.php] [hot:candy-code/src/Providers/OpenAIProvider.php]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-2.e`

### W26

- protocol lanes: C-TUI-2.g (weld regenerates schema → SERVER.md → generated.ts once)
- Chat.php region claims: C-CTX-6.e (pumpRuntimeNotices); C-TUI-3.a (READ_ONLY_COMMANDS, handleCopyCommand (new method))
- Bootstrap.php method claims: C-SEC-7.e (permissionGate); C-SES-6.e (chat)

#### C-CTX-6.e — Structured, expandable recall row
- **Item:** C-CTX-6 (sources: p2-miss: TUI-6 `Message::recallNotice()` row) · gap · effort M
- **What:** Today the "💭 Recalled N notes" row is a count-only string. Add `RuntimeNoticeSink::recordStructured(kind, payload)`: a JSON datagram with a reserved prefix, bounded by `MAX_CHARS`. Only `memory.recall` with `{count, titles[]}` …
- **Files — create:** `candy-code/tests/Chat/RecallNoticeRowTest.php`
- **Files — modify:** `candy-code/src/Diagnostics/RuntimeNoticeSink.php`, `candy-code/src/Diagnostics/NoticeSink.php`, `candy-code/src/Runtime.php`, `candy-code/src/Message.php`, `candy-code/src/Chat.php`, `candy-code/src/Host/SessionHost.php`, `candy-code/src/Renderer.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Diagnostics/RuntimeNoticeSinkTest.php`
- **Docs/guards:** `candy-code/docs/MEMORY.md` L455-460 (recall row)
- **Serialized:** [runtime:announceMemoryRecall] [renderer] [chat:pumpRuntimeNotices] [lang:chat.memory.recalled_*] [docs:MEMORY.md] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Message.php]
- **Deps:** C-CTX-2.a (W8), C-CTX-6.c (W9)
- **Brief:** `prompt_kit/findings/comparison-1026/design-prv-ctx.md` → `### C-CTX-6.e`

#### C-SEC-7.e — `autoReview` default
- **Item:** C-SEC-7 (sources: GAP-PER-07 owner-ruling note; candidates.md "Owner rulings: `autoReview` default") · gap · effort S
- **What:** `autoReview` is `false` by default (`PermissionSettings.php:61`). Per the ruling, either flip it to "on when a title backend or `autoReviewModel` resolves", or record the decision to keep it off. When on, `permissionGate()` …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Config/Settings/Definitions/PermissionSettings.php`, `candy-code/src/Cli/Bootstrap.php`, `candy-code/docs/SETTINGS.md`, `candy-code/docs/PERMISSIONS.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Permissions/AutoReviewScopeTest.php`, `candy-code/tests/Cli/BootstrapPermissionGateTest.php`
- **Docs/guards:** `tools/gen-settings-doc.php --check`
- **Serialized:** [bootstrap:permissionGate] [hot:candy-code/src/Config/Settings/Definitions/PermissionSettings.php] [docs:SETTINGS.md] [docs:PERMISSIONS.md]
- **Deps:** C-SEC-7.d (W16)
- **Ruling:** Turn `autoReview` on by default whenever a title backend (or `autoReviewModel`) exists? Each review is a paid model call. — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-sec.md` → `### C-SEC-7.e`

#### C-SES-6.e — Update-available notice
- **Item:** C-SES-6 (sources: GAP-PLAT-05) · gap · effort M
- **What:** **`UpdateCheck`.** It runs on phar installs only (`Phar::running` identity), and does two things: `refreshIfStale(LoopInterface)`: at most once per 24 h, an async `React\Http\Browser` GET of the release list ON the TUI loop, with …
- **Files — create:** `candy-code/src/Cli/UpdateCheck.php`, `candy-code/tests/Cli/UpdateCheckTest.php`
- **Files — modify:** `candy-code/src/Support/StartupSummary.php`, `candy-code/src/Cli/Bootstrap.php`, `candy-code/lang/en.php`, `candy-code/docs/ENVIRONMENT.md`, `candy-code/README.md`
- **Tests — create:** `candy-code/tests/Cli/UpdateCheckTest.php` · **modify:** `candy-code/tests/Support/StartupSummaryTest.php`
- **Docs/guards:** `EnvRosterDriftTest`, moved by the ENVIRONMENT.md row in-step. The PLAT-07 COMPARISON cell flips at closeout.
- **Serialized:** [bootstrap:chat] [lang:startup.update] [docs:ENVIRONMENT.md] [docs:README.md] [hot:candy-code/src/Support/StartupSummary.php]
- **Deps:** C-SES-5.a (W2)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-6.e`

#### C-SES-8.e — `candy-code usage` CLI verb
- **Item:** C-SES-8 (sources: OBS-3-P1, p2-miss: optional `candy-code usage` verb) · gap · NIT · effort S
- **What:** `candy-code usage [7|30|all] [--session <id>] [--output-format json]` prints `UsageCommand::compose(UsageHostCommand::rollupFacts(...))`. JSON mode emits the facts map. The verb moves `ParsedArgs::SUBCOMMANDS`, the completion …
- **Files — create:** `candy-code/tests/Cli/UsageSubcommandTest.php`
- **Files — modify:** `candy-code/src/Cli/ParsedArgs.php`, `candy-code/src/Cli/ArgvParser.php`, `candy-code/src/Cli/Subcommands.php`, `candy-code/lang/en.php`, `candy-code/README.md`
- **Tests — create:** `candy-code/tests/Cli/UsageSubcommandTest.php` · **modify:** `candy-code/tests/Integration/BinCandyCodeDispatchTest.php`
- **Docs/guards:** the README Subcommands fence → `ReadmeRosterDriftTest::testTheSubcommandFenceNamesEveryVerbTheParserAccepts`
- **Serialized:** [headless] [lang:cli.usage,cli.completion.verb.usage] [docs:README.md]
- **Deps:** C-SES-9.a (W7)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-8.e`

#### C-TOOL-2.f — MCP images/PDF resources into the tool-result channel
- **Item:** C-TOOL-2 (sources: GAP-MCP-14, k2-sum MCP-14) · gap · effort S
- **What:** `McpToolBridge::replyResult()` (`candy-code/src/Tools/McpToolBridge.php:600`) renders image/audio parts as size text (`renderInlineBytes` :839). Change: decode the FIRST in-cap image part whose bytes sniff …
- **Files — create:** `candy-code/tests/Tools/McpToolBridgeImageChannelTest.php`
- **Files — modify:** `candy-code/src/Tools/McpToolBridge.php`, `candy-code/docs/MCP.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Tools/McpToolBridgeTest.php`, `candy-code/tests/Tools/McpToolBridgeResultCapTest.php`
- **Docs/guards:** `docs/MCP.md` in-step; `DocFigureProseDriftTest` MCP arms if a counted sentence moves
- **Serialized:** [docs:MCP.md]
- **Deps:** C-TOOL-2.d (W17)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-2.f`

#### C-TOOL-2.g — OpenAI Responses image/document tool results
- **Item:** C-TOOL-2 (sources: GAP-TL-19 (wire half)) · gap · effort S
- **What:** NARROWED (plan review): reuse C-PRV-2.b's image arm and add only the `input_file` (document) parts; no hedges, the wire file is `candy-code/src/Providers/Concerns/OpenAiResponsesWire.php`. Once C-PRV-2's Responses wire exists, …
- **Files — create:** `candy-code/tests/Providers/ResponsesToolResultMediaTest.php`
- **Files — modify:** `candy-code/src/Providers/Concerns/OpenAiResponsesWire.php`, `candy-code/src/Providers/AttachmentEncoding.php`, `candy-code/docs/MEDIA.md`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** `docs/MEDIA.md` one line
- **Serialized:** [hot:candy-code/src/Providers/AttachmentEncoding.php]
- **Deps:** C-PRV-2.a (W14), C-PRV-2.b (W16), C-PRV-2.c (W17), C-TOOL-2.d (W17)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-2.g`

#### C-TOOL-6.a — Browser-control and computer-use MCP presets (docs)
- **Item:** C-TOOL-6 (sources: GAP-TL-21, GAP-TL-25) · gap · effort S
- **What:** **Why this is `phase: normal`.** The brief allows a cheap sliver of a LATE item to run early. This one qualifies: it is S effort, doc-only (plus one test that pins the doc), and needs no ruling. It depends on no LATE step, and no …
- **Files — create:** `candy-code/tests/Config/McpBrowserPresetDocTest.php`
- **Files — modify:** `candy-code/docs/MCP.md`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** `DocFigureProseDriftTest` (MCP arms) and `McpStartupOrderingDocDriftTest` both re-run unchanged. The new subsection sits outside the server-type-table → "Foreign spellings" span that the second …
- **Serialized:** [docs:MCP.md]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-6.a`

#### C-TUI-2.f — Web split + highlighted DiffView
- **Item:** C-TUI-2 (sources: GAP-UI-05, GAP-UI-04) · gap · effort S
- **What:** Client-only. `lib/diff.ts` pairs removed and added runs into side-by-side rows (the C-TUI-2.b rule). `DiffView.vue` adds a split mode: `auto` splits at ≥ 1100 px, and a client pref `diffStyle` lives in `stores/settings.ts`. Diff …
- **Files — create:** none
- **Files — modify:** `candy-code-web/src-web/components/DiffView.vue`, `candy-code-web/src-web/lib/diff.ts`, `candy-code-web/src-web/lib/highlight.ts`, `candy-code-web/src-web/stores/settings.ts`
- **Tests — create:** `candy-code-web/src-web/lib/__tests__/diffSplit.spec.ts`, `candy-code-web/src-web/components/__tests__/diffView.spec.ts` · **modify:** `candy-code-web/src-web/lib/__tests__/highlight.spec.ts`
- **Docs/guards:** vitest `--no-file-parallelism`; the `dist/` rebuild at weld
- **Serialized:** [hot:candy-code-web/src-web/components/DiffView.vue] [hot:candy-code-web/src-web/stores/settings.ts]
- **Deps:** C-SEC-2.a (W1), C-SEC-2.b (W2)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-2.f`

#### C-TUI-2.g — Web checkpoint base selector (`files.diff` checkpoint)
- **Item:** C-TUI-2 (sources: GAP-GIT-09) · gap · effort M
- **What:** `files.diff` (`src/Protocol/Methods/FilesMethods.php:44-58`) diffs the workspace against a git `ref` only. Add an optional `checkpoint:int` with a required `sessionId`. It resolves through `Checkpoints::fileCheckpointPositions()` …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Protocol/Methods/FilesMethods.php`, `candy-code/src/Protocol/Schema/MethodSchemas.php`, `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts`, `candy-code-web/src-web/components/panels/ChangesPanel.vue`
- **Tests — create:** `candy-code/tests/Protocol/FilesDiffCheckpointTest.php` · **modify:** `candy-code-web/src-web/components/panels/__tests__/changesPanel.spec.ts`
- **Docs/guards:** ProtocolSchemaDriftTest; ServerProtocolDocumentationDriftTest; `gen-protocol-schema.php --check`; `npm run gen:protocol -- --check`; dist rebuild at weld
- **Serialized:** [protocol] [docs:SERVER.md]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-2.g`

#### C-TUI-3.a — `/copy [n]` in the TUI
- **Item:** C-TUI-3 (sources: GAP-UI-16) · gap · effort M
- **What:** The TUI copies only by mouse drag. A pure `CopyCommand::select(history, arg)` picks text: bare means the last assistant reply; `n` means the nth fenced code block, counting from the end of that reply. `Chat::handleCopyCommand()` …
- **Files — create:** `candy-code/src/Commands/CopyCommand.php`, `candy-code/builtin-commands/1910-copy.php`
- **Files — modify:** `candy-code/src/Chat.php`, `candy-code/lang/en.php`, `candy-code/README.md`, `candy-code/docs/COMMANDS.md`
- **Tests — create:** `candy-code/tests/Commands/CopyCommandTest.php`, `candy-code/tests/Chat/CopySlashCommandTest.php` · **modify:** none
- **Docs/guards:** `php tools/gen-command-docs.php --write`; CommandDocsDriftTest; ReadmeRosterDriftTest; BuiltInCommandsTest
- **Serialized:** [chat:handleCopyCommand (new method), READ_ONLY_COMMANDS] [commands] [lang:cmd.copy., chat.copy.] [docs:README.md] [docs:COMMANDS.md]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-3.a`

### W27

- protocol lanes: C-MCP-4.c (weld regenerates schema → SERVER.md → generated.ts once)
- Chat.php region claims: C-SES-7.c (ctor, persistence/memory guards); C-WEB-2.f (handleEffortCommand)
- Bootstrap.php method claims: C-SES-7.c (chat, openSession); C-SES-8.g (sessionStore)

#### ✅ 2ad862baf C-MCP-4.c — Served `/mcp login` + paste-back finish from a remote browser
- **Item:** C-MCP-4 (sources: GAP-MCP-04) · gap · effort M
- **What:** In the TUI, `Chat::handleMcpAuthCommand` intercepts `login <server>` into `McpLoginFork` (`candy-code/src/Chat.php:19810-19843`). Served `/mcp login` reaches `McpAuthHostCommand` → `printLoginGuidance()`, which only prints shell …
- **Files — create:** `candy-code/src/Host/ServedMcpLogins.php`, `candy-code/tests/Host/McpLoginServedTest.php`
- **Files — modify:** `candy-code/src/Host/Commands/McpAuthHostCommand.php`, `candy-code/src/Support/McpLoginFork.php`, `candy-code/src/Host/SessionHost.php`, `candy-code/src/Protocol/ServerContext.php`, `candy-code/lang/en.php`, `candy-code/docs/MCP.md`
- **Tests — create:** `candy-code/tests/Host/McpLoginServedTest.php` · **modify:** `candy-code/tests/Chat/McpLoginForkTest.php`
- **Docs/guards:** `DocFigureProseDriftTest::testTheLoginCommandWordRoutesThroughTheVerbGateAndGuidanceArm` (the guidance arm stays for bare `login`; re-pin the narrative and the `candy-code mcp auth login <server>` …
- **Serialized:** [lang:cmd.mcp-auth.login] [docs:MCP.md §Server auth + §Authorization-code login] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Protocol/ServerContext.php] …
- **Deps:** C-MCP-6.a (W9), C-MCP-4.b (W16)
- **Brief:** `prompt_kit/findings/comparison-1026/design-mcp.md` → `### C-MCP-4.c`

#### C-SEC-8.b — Opt-in secret redaction of tool output
- **Item:** C-SEC-8 (sources: GAP-PER-09) · gap · effort M
- **What:** Tool results enter history and `sessions.db` raw. `SecretRedactor` (`src/Memory/SecretRedactor.php:79`) covers only memory, audit and `/share`. `Runtime::resultMessage()` (`src/Runtime.php:3545-3570`) is documented as "the ONE …
- **Files — create:** `candy-code/tests/Runtime/ToolOutputRedactionTest.php`
- **Files — modify:** `candy-code/src/Runtime.php`, `candy-code/src/Config/Settings/Definitions/PermissionSettings.php`, `candy-code/src/Config/LayeredSettings.php`, `candy-code/lang/en.php`, `candy-code/docs/SETTINGS.md`, `candy-code/README.md`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** `tools/gen-settings-doc.php --check`; `TrustKeyDocumentationDriftTest` (README roster); `DocFigureProseDriftTest`
- **Serialized:** [runtime] [hot:candy-code/src/Config/LayeredSettings.php] [hot:candy-code/src/Config/Settings/Definitions/PermissionSettings.php] [lang:settings.redactToolOutput.] [docs:SETTINGS.md] [docs:README.md]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-sec.md` → `### C-SEC-8.b`

#### C-SES-7.c — Incognito launches (`--incognito`)
- **Item:** C-SES-7 (sources: GAP-SES-N1) · gap · effort M
- **What:** **The flag.** `--incognito` is parsed in `ArgvParser`/`ParsedArgs`, set through `LaunchContext::useIncognito()` from `bin/candy-code`, and refused together with `--continue` or `--resume`. **TUI launch.** …
- **Files — create:** `candy-code/tests/Chat/IncognitoLaunchTest.php`
- **Files — modify:** `candy-code/src/Cli/ArgvParser.php`, `candy-code/src/Cli/ParsedArgs.php`, `candy-code/src/Cli/LaunchContext.php`, `candy-code/src/Cli/NonInteractive.php`, `candy-code/src/Cli/Subcommands.php`, `candy-code/src/Cli/Bootstrap.php`, `candy-code/src/Chat.php`, `candy-code/src/Chat/Handlers/HandlesTurnCompletion.php`, `candy-code/src/Renderer.php`, `candy-code/lang/en.php`, `candy-code/README.md`
- **Tests — create:** `candy-code/tests/Chat/IncognitoLaunchTest.php` · **modify:** `candy-code/tests/Cli/ArgvParserTest.php`, `candy-code/tests/Cli/LaunchFlagsTest.php`, `candy-code/tests/Cli/NonInteractiveSessionPersistenceTest.php`
- **Docs/guards:** README flags table; `LaunchFlagsTest`
- **Serialized:** [headless] [bootstrap:openSession,chat] [chat:ctor,persistence/memory guards] [renderer:renderStatusBar] [hot:candy-code/src/Chat/Handlers/HandlesTurnCompletion.php] [lang:chat.incognito] …
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-7.c`

#### C-SES-8.g — JSONL wiring: setting, store mirror, `transcript_path` for hooks
- **Item:** C-SES-8 (sources: GAP-OBS-05, CC-new (JSONL + `transcript_path`)) · gap · effort M
- **What:** **The setting.** `transcriptJsonl` (Bool, default false, Interface category). **The mirror.** `Bootstrap::sessionStore()` wires `EnhancedSessionStore::withJsonlMirror(JsonlTranscriptWriter)` when the setting is on, and sets a …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Config/Settings/Definitions/InterfaceSettings.php`, `candy-code/src/Cli/Bootstrap.php`, `candy-code/src/Session/EnhancedSessionStore.php`, `candy-code/src/Hooks/ScriptHook.php`, `candy-code/lang/en.php`, `candy-code/docs/HOOKS.md`, `candy-code/docs/SETTINGS.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Hooks/ScriptHookTest.php`, `candy-code/tests/Hooks/ScriptHookJsonStdoutTest.php`, `candy-code/tests/Session/UsageRecordsTest.php`, `candy-code/tests/Host/TranscriptStoreTest.php`
- **Docs/guards:** the SETTINGS.md regen → `SettingsSchemaDocDriftTest`; the `DocFigureProseDriftTest` settings count; HOOKS.md moves from "six keys" to seven (grep the HOOKS.md figure prose)
- **Serialized:** [bootstrap:sessionStore] [hot:candy-code/src/Hooks/ScriptHook.php] [hot:candy-code/src/Session/EnhancedSessionStore.php] [hot:candy-code/src/Config/Settings/Definitions/InterfaceSettings.php] …
- **Deps:** C-SES-8.f (W17)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-8.g`

#### C-TUI-3.b — Web copy buttons, "Rewind to here", "Edit & resend"
- **Item:** C-TUI-3 (sources: GAP-UI-16, GAP-GIT-06, GAP-UI-15) · gap · effort M
- **What:** Client-only. Copy buttons: on code blocks (`MessageMarkdown.vue`) and on assistant rows (`TranscriptItem.vue`) via `navigator.clipboard.writeText`. They are hidden outside a secure context, the notifications precedent. User rows …
- **Files — create:** none
- **Files — modify:** `candy-code-web/src-web/components/MessageMarkdown.vue`, `candy-code-web/src-web/components/TranscriptItem.vue`, `candy-code-web/src-web/components/Transcript.vue`, `candy-code-web/src-web/views/SessionView.vue`, `candy-code-web/src-web/stores/session.ts`
- **Tests — create:** `candy-code-web/src-web/components/__tests__/transcriptActions.spec.ts`, `candy-code-web/src-web/components/__tests__/codeBlockCopy.spec.ts` · **modify:** none
- **Docs/guards:** vitest `--no-file-parallelism`; dist rebuild at weld
- **Serialized:** [hot:candy-code-web/src-web/components/TranscriptItem.vue] [hot:candy-code-web/src-web/stores/session.ts] [hot:candy-code-web/src-web/views/SessionView.vue]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-3.b`

#### C-TUI-3.e — One-key verbose toggle (TUI)
- **Item:** C-TUI-3 (sources: GAP-UI-32 TUI half) · gap · effort S
- **What:** `expandToolOutput` is Live (`Renderer.php:702-709` reads `UiSettings`), but flipping it takes the settings view. A new `chat.verbose-toggle` binding (proposed `Alt+O`, free in the registry) flips it in the **session tier**. The …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Chat/Handlers/HandlesKeys.php`, `candy-code/src/Commands/KeyBindingRegistry.php`, `candy-code/lang/en.php`, `candy-code/README.md`
- **Tests — create:** `candy-code/tests/Chat/VerboseToggleKeyTest.php` · **modify:** none
- **Docs/guards:** KeyBindingDriftTest; ReadmeRosterDriftTest; the `tests/Config/Settings/SettingsWriterCensusTest.php` producer row (a new caller of the writer)
- **Serialized:** [hot:candy-code/src/Chat/Handlers/HandlesKeys.php] [hot:candy-code/src/Commands/KeyBindingRegistry.php] [lang:keys.chat.verbose-toggle, chat.verbose.] [docs:README.md] …
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-3.e`

#### C-TUI-4.b — Web dracula + tokyoNight theme sets
- **Item:** C-TUI-4 (sources: GAP-UI-02 web half) · gap · effort S
- **What:** The web has auto, dark and light only (`stores/settings.ts` `ThemeChoice`). Add `[data-theme='dracula']` and `[data-theme='tokyoNight']` token sets mirroring the TUI palettes in `styles/tokens.css`. Widen `ThemeChoice` and …
- **Files — create:** none
- **Files — modify:** `candy-code-web/src-web/styles/tokens.css`, `candy-code-web/src-web/stores/settings.ts`
- **Tests — create:** `candy-code-web/src-web/stores/__tests__/settingsTheme.spec.ts` · **modify:** `candy-code-web/src-web/styles/__tests__/contrast.spec.ts`
- **Docs/guards:** vitest; dist rebuild at weld
- **Serialized:** [hot:candy-code-web/src-web/stores/settings.ts] [hot:candy-code-web/src-web/styles/tokens.css]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-4.b`

#### C-TUI-4.c — `statusLine` session JSON on stdin
- **Item:** C-TUI-4 (sources: GAP-UI-23) · gap · effort M
- **What:** `StatusLineCommand::run()` closes the child's stdin unwritten (`src/Config/StatusLineCommand.php:594-623`), so Claude Code statusLine scripts that read JSON get nothing. A pure `StatusLineSession` builds Claude Code's stdin …
- **Files — create:** `candy-code/src/Config/StatusLineSession.php`
- **Files — modify:** `candy-code/src/Config/StatusLineCommand.php`, `candy-code/src/Chat/Handlers/HandlesRunControl.php`, `candy-code/lang/en.php`, `candy-code/docs/SETTINGS.md`
- **Tests — create:** `candy-code/tests/Config/StatusLineSessionTest.php` · **modify:** `candy-code/tests/Config/StatusLineCommandTest.php`
- **Docs/guards:** SettingsSchemaDocDriftTest (if the help text appears); DocFigureProseDriftTest
- **Serialized:** [hot:candy-code/src/Chat/Handlers/HandlesRunControl.php] [lang:settings.statusLine.] [docs:SETTINGS.md]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-4.c`

#### C-WEB-2.c — Typed `/skill-name` and `$name` in `-p`
- **Item:** C-WEB-2 (sources: p1-miss: VIS-5 parity (`-p`), GAP-SKL-06) · gap · M
- **What:** **Gap.** `NonInteractive::historyFrom()` (`candy-code/src/Cli/NonInteractive.php`, ~:1375-1410) resolves only `@file` and `@keyword` mentions. `-p` has no `$name` skill channel at all, and no `/name` rewrite. **Fix.** Apply …
- **Files — create:** `candy-code/tests/Cli/NonInteractiveSkillSlashTest.php`
- **Files — modify:** `candy-code/src/Cli/NonInteractive.php`, `candy-code/docs/SKILLS.md`
- **Tests — create:** `candy-code/tests/Cli/NonInteractiveSkillSlashTest.php` · **modify:** none
- **Docs/guards:** `docs/SKILLS.md`. If README's `-p` section lists the mention kinds `-p` resolves, it moves too (`candy-code/tests/Config/ReadmeRosterDriftTest.php` re-run).
- **Serialized:** [headless] [docs:SKILLS.md]
- **Deps:** C-WEB-2.b (W21)
- **Brief:** `prompt_kit/findings/comparison-1026/design-web.md` → `### C-WEB-2.c`

#### C-WEB-2.f — `/effort` host body over a shared effort dial
- **Item:** C-WEB-2 (sources: GAP-MOD-15w (`/effort` half), GAP-CMD-01) · gap · M
- **What:** **Gap.** `/effort` (`candy-code/builtin-commands/0350-effort.php`) has no host body. Its logic (`Chat::handleEffortCommand`, `src/Chat.php:12234`) is the ShapesReasoningEffort judgement C-PRV-1 makes honest. **Lift.** Move the …
- **Files — create:** `candy-code/src/Commands/EffortDial.php`, `candy-code/src/Host/Commands/EffortHostCommand.php`, `candy-code/tests/Commands/EffortDialTest.php`, `candy-code/tests/Host/Commands/EffortHostCommandTest.php`
- **Files — modify:** `candy-code/builtin-commands/0350-effort.php`, `candy-code/src/Chat.php`, `candy-code/lang/en.php`
- **Tests — create:** `candy-code/tests/Commands/EffortDialTest.php`, `candy-code/tests/Host/Commands/EffortHostCommandTest.php` · **modify:** none
- **Docs/guards:** check `tests/Config/ConfigWriteProducerDocumentationDriftTest.php` and the `docs/SETTINGS.md` producer prose for the `reasoningEffort` writer list (a second door to the same key); …
- **Serialized:** [commands] [chat:handleEffortCommand] [lang:chat.effort.]
- **Deps:** C-PRV-1.a (W2), C-PRV-1.b (W3), C-PRV-1.c (W3)
- **Brief:** `prompt_kit/findings/comparison-1026/design-web.md` → `### C-WEB-2.f`

### W28

- protocol lanes: C-WEB-2.d (weld regenerates schema → SERVER.md → generated.ts once)
- Chat.php region claims: C-SEC-8.d (submit); C-TUI-2.e (handleDiffCommand); C-TUI-3.g (READ_ONLY_COMMANDS, handleRecapCommand (new), programOptions)

#### C-SEC-8.d — Strip invisible Unicode at submit (TUI and serve)
- **Item:** C-SEC-8 (sources: GAP-UI-N5) · gap · effort S
- **What:** Invisible formatting is only marked on display today. One rule applies at both submit chokes: TUI: `Chat::submit()` (`src/Chat.php:9389`); served: `SessionHost::submit()` (`src/Host/SessionHost.php:524`), which `session.send` …
- **Files — create:** `candy-code/tests/Host/SubmitInvisibleUnicodeTest.php`, `candy-code/tests/Chat/SubmitInvisibleUnicodeTest.php`
- **Files — modify:** `candy-code/src/Chat.php`, `candy-code/src/Host/SessionHost.php`, `candy-code/lang/en.php`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** none
- **Serialized:** [chat:submit] [hot:candy-code/src/Host/SessionHost.php] [lang:chat.notice.invisible_stripped]
- **Deps:** C-SEC-8.c (W16)
- **Brief:** `prompt_kit/findings/comparison-1026/design-sec.md` → `### C-SEC-8.d`

#### C-TOOL-5.d — `candy-code lsp install/list` subcommand
- **Item:** C-TOOL-5 (sources: GAP-EDT-07) · gap · effort S
- **What:** New subcommand `lsp` with `install <language>` and `list` (installed, pinned and available), over `LspInstaller`. It adds `lsp` to `ParsedArgs::SUBCOMMANDS` (:56) and `SUBCOMMAND_FLAGS`, a dispatch arm in `Subcommands`, a …
- **Files — create:** `candy-code/tests/Cli/LspSubcommandTest.php`
- **Files — modify:** `candy-code/src/Cli/ParsedArgs.php`, `candy-code/src/Cli/Subcommands.php`, `candy-code/lang/en.php`, `candy-code/README.md`, `candy-code/docs/ARCHITECTURE.md`, `candy-code/docs/MCP.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Cli/HelpTest.php`
- **Docs/guards:** `DocFigureProseDriftTest` (subcommand word-count + list), `HelpTest`, `ReadmeRosterDriftTest` if the README CLI roster is pinned
- **Serialized:** [headless] [lang:cli.lsp.] [docs:README.md] [docs:ARCHITECTURE.md] [docs:MCP.md]
- **Deps:** C-TOOL-5.c (W13)
- **Ruling:** same as C-TOOL-5.c — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-5.d`

#### C-TUI-2.e — Diff-review wiring (App slot, `/diff`, keys)
- **Item:** C-TUI-2 (sources: GAP-GIT-09) · gap · effort M
- **What:** In the TUI, a bare `/diff [n]` opens the review as an App shell slot. `Chat::handleDiffCommand()` (`Chat.php:15582`) returns `Cmd::send(new OpenDiffReviewMsg($n))`, the `openSettingsView()` technique, and `/diff [n] --print` …
- **Files — create:** none
- **Files — modify:** `candy-code/src/App/App.php`, `candy-code/src/Tui/Renderer.php`, `candy-code/src/Chat.php`, `candy-code/src/Host/Commands/DiffCommand.php`, `candy-code/src/Commands/KeyBindingRegistry.php`, `candy-code/lang/en.php`, `candy-code/README.md`, `candy-code/docs/COMMANDS.md`
- **Tests — create:** `candy-code/tests/App/DiffReviewWiringTest.php` · **modify:** none
- **Docs/guards:** `php tools/gen-command-docs.php --write`; CommandDocsDriftTest; KeyBindingDriftTest; ReadmeRosterDriftTest
- **Serialized:** [chat:handleDiffCommand] [renderer:Tui/Renderer::renderView slot chain] [hot:candy-code/src/App/App.php] [hot:candy-code/src/Commands/KeyBindingRegistry.php] [lang:keys.diffreview., cmd.diff.] …
- **Deps:** C-TUI-2.d (W18), C-TUI-2.c (W19)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-2.e`

#### C-TUI-3.g — Away recap wiring + `/recap`
- **Item:** C-TUI-3 (sources: GAP-UI-N4) · gap · effort M
- **What:** Claude Code shows a one-line recap after the user has been away for at least 3 minutes. Focus: sugar-core has `FocusLostMsg`/`FocusGainedMsg` and a ProgramOptions `reportFocus`, which Program tears down itself …
- **Files — create:** `candy-code/builtin-commands/3510-recap.php`, `candy-code/src/Tui/AwayTracker.php`
- **Files — modify:** `candy-code/src/Chat.php`, `candy-code/src/Chat/Handlers/HandlesRunControl.php`, `candy-code/src/Config/Settings/Definitions/InterfaceSettings.php`, `candy-code/src/Config/LayeredSettings.php`, `candy-code/src/Config/Settings/UiSettings.php`, `candy-code/lang/en.php`, `candy-code/README.md`, `candy-code/docs/COMMANDS.md`, `candy-code/docs/SETTINGS.md`
- **Tests — create:** `candy-code/tests/Chat/AwayRecapTest.php`, `candy-code/tests/Tui/AwayTrackerTest.php` · **modify:** none
- **Docs/guards:** gen-command-docs + gen-settings-doc; CommandDocsDriftTest; ReadmeRosterDriftTest; SettingsSchemaDocDriftTest; `tests/Chat/RouteTraitParityTest.php` (new Msg arms)
- **Serialized:** [chat:programOptions, handleRecapCommand (new), READ_ONLY_COMMANDS] [commands] [hot:candy-code/src/Chat/Handlers/HandlesRunControl.php] …
- **Deps:** C-TUI-3.f (W18)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-3.g`

#### C-WEB-2.d — Skills in the web `/` menu (`command.list` `source:"skill"`)
- **Item:** C-WEB-2 (sources: GAP-SKL-06 (menu half)) · gap · S
- **What:** **Server.** `CommandMethods::list()` (`candy-code/src/Protocol/Methods/CommandMethods.php:48-62`) adds one row per user-invocable skill: `source:"skill"`, `runsIn:"server"`. Built-in and file names win a clash, as they do at …
- **Files — create:** `candy-code/tests/Protocol/CommandListSkillRowsTest.php`
- **Files — modify:** `candy-code/src/Protocol/Methods/CommandMethods.php`, `candy-code/src/Protocol/Schema/MethodSchemas.php`, `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts`, `candy-code-web/src-web/stores/session.ts`
- **Tests — create:** `candy-code/tests/Protocol/CommandListSkillRowsTest.php` · **modify:** `candy-code-web/src-web/components/__tests__/components.spec.ts`
- **Docs/guards:** the protocol gates (as in C-WEB-1.a); `tests/Config/ServerProtocolDocumentationDriftTest.php`.
- **Serialized:** [protocol]
- **Deps:** C-WEB-2.b (W21)
- **Brief:** `prompt_kit/findings/comparison-1026/design-web.md` → `### C-WEB-2.d`

#### C-WEB-2.g — `/review` and `/security-review` host body
- **Item:** C-WEB-2 (sources: GAP-GIT-10, GAP-CMD-01, p2-miss: `/review` on serve/web/headless) · gap · S
- **What:** **Gap.** Both specs (`candy-code/builtin-commands/2840-review.php`, `2845-security-review.php`) are Chat-only (`Chat::reviewTurn()`, `src/Chat.php:11760-11800`). **ReviewHostCommand.** One class serves both spellings. It …
- **Files — create:** `candy-code/src/Host/Commands/ReviewHostCommand.php`, `candy-code/tests/Host/Commands/ReviewHostCommandTest.php`
- **Files — modify:** `candy-code/builtin-commands/2840-review.php`, `candy-code/builtin-commands/2845-security-review.php`
- **Tests — create:** `candy-code/tests/Host/Commands/ReviewHostCommandTest.php` · **modify:** none
- **Docs/guards:** `tests/Commands/BuiltInCommandsTest.php`.
- **Serialized:** [commands]
- **Deps:** C-AGT-2.a (W1), C-AGT-2.c (W2), C-AGT-2.b (W7)
- **Brief:** `prompt_kit/findings/comparison-1026/design-web.md` → `### C-WEB-2.g`

### W29

- Chat.php region claims: C-SES-8.b (accountUsage, usageRecordSink + its 4 settle arms); C-TUI-3.d (rewindToCheckpoint (new method))

#### C-SES-8.b — Ledger attribution by source + `/usage` advice
- **Item:** C-SES-8 (sources: CC-new#5, GAP-OBS-03 (attribution)) · feature · effort M
- **What:** **Columns.** `usage_records` gains `source TEXT` and `delegated_tokens INTEGER`, through a guarded migration. `delegated_tokens` comes from `Usage::$delegatedTokens`, the sub-agent share. **Threading the source.** …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Session/EnhancedSessionStore.php`, `candy-code/src/Host/SpendLedger.php`, `candy-code/src/Chat.php`, `candy-code/src/Host/SessionHost.php`, `candy-code/src/Commands/UsageCommand.php`, `candy-code/src/Host/Commands/UsageHostCommand.php`, `candy-code/lang/en.php`, `candy-code/docs/COMMANDS.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Session/UsageRecordsTest.php`, `candy-code/tests/Commands/UsageCommandTest.php`, `candy-code/tests/Host/UsageModelThreadingTest.php`, `candy-code/tests/Host/SpendLedgerTest.php`
- **Docs/guards:** the COMMANDS.md regen; `CommandDocsDriftTest`
- **Serialized:** [chat:accountUsage,usageRecordSink + its 4 settle arms] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Session/EnhancedSessionStore.php] …
- **Deps:** C-SES-9.a (W7)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ses.md` → `### C-SES-8.b`

#### C-TUI-3.d — Message-picker wiring (idle Esc Esc)
- **Item:** C-TUI-3 (sources: GAP-UI-15) · gap · effort M
- **What:** Claude Code opens its message picker on Esc Esc. Here Esc Esc is `chat.cancel` while a turn is in flight. When idle, the Escape arm only clears `lastEscapeAt` (`HandlesKeys.php:387-390`), so idle Esc Esc is free. The idle arm …
- **Files — create:** none
- **Files — modify:** `candy-code/src/App/App.php`, `candy-code/src/Tui/Renderer.php`, `candy-code/src/Chat.php`, `candy-code/src/Chat/Handlers/HandlesKeys.php`, `candy-code/src/Commands/KeyBindingRegistry.php`, `candy-code/lang/en.php`, `candy-code/README.md`
- **Tests — create:** `candy-code/tests/App/MessagePickerWiringTest.php` · **modify:** `candy-code/tests/Chat/EscapeSoftCancelTest.php`
- **Docs/guards:** KeyBindingDriftTest; ReadmeRosterDriftTest
- **Serialized:** [chat:rewindToCheckpoint (new method)] [renderer:Tui/Renderer::renderView slot chain] [hot:candy-code/src/App/App.php] [hot:candy-code/src/Chat/Handlers/HandlesKeys.php] …
- **Deps:** C-TUI-3.c (W18)
- **Ruling:** Chord: open the message picker on idle Esc Esc (empty draft, no turn), as in Claude Code? If unanswered, idle Esc Esc. — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-3.d`

#### C-TUI-4.a — Custom TUI themes from `~/.candy-code/themes/`
- **Item:** C-TUI-4 (sources: GAP-UI-02) · gap · effort M
- **What:** `Theme::byName()` throws for anything outside the six names (`src/Theme.php:60,158-214`). `ThemeFile` loads `~/.candy-code/themes/<name>.json` from the user directory only, never the project: `{"extends": "dark|light|…", …
- **Files — create:** `candy-code/src/Config/ThemeFile.php`, `candy-code/src/Config/Settings/Validator/ThemeNameValidator.php`
- **Files — modify:** `candy-code/src/Theme.php`, `candy-code/src/Config/Settings/Definitions/InterfaceSettings.php`, `candy-code/src/Config/Settings/OptionsProvider.php`, `candy-code/lang/en.php`, `candy-code/docs/SETTINGS.md`, `candy-code/README.md`
- **Tests — create:** `candy-code/tests/Config/ThemeFileTest.php` · **modify:** `candy-code/tests/ThemeTest.php`, `candy-code/tests/Chat/PaletteThemePickerTest.php`
- **Docs/guards:** gen-settings-doc; SettingsSchemaDocDriftTest; a `tests/Support/ReadPathCensusTest.php` row (verdict OWNED_HOME)
- **Serialized:** [hot:candy-code/src/Config/Settings/Definitions/InterfaceSettings.php] [lang:settings.theme., chat.theme.] [docs:SETTINGS.md] [docs:README.md] [hot:candy-code/tests/Support/ReadPathCensusTest.php]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-4.a`

### W30

- Chat.php region claims: C-TUI-5.a (READ_ONLY_COMMANDS, handleDoctorCommand (new)); C-TUI-5.e (switchProvider (new method))

#### C-TUI-5.a — `/doctor` host command + AppArmor userns probe
- **Item:** C-TUI-5 (sources: GAP-CFG-06, CC-new#11 doctor diagnostic) · gap · effort M
- **What:** `candy-code doctor` probes live inside `Subcommands::doctorProbes(ParsedArgs)` (`src/Cli/Subcommands.php:381-~620`). They use only `$args->root` and do no network I/O; the provider probe is factory-only. Extract them to …
- **Files — create:** `candy-code/src/Cli/DoctorChecks.php`, `candy-code/src/Host/Commands/DoctorHostCommand.php`, `candy-code/builtin-commands/1345-doctor.php`
- **Files — modify:** `candy-code/src/Cli/Subcommands.php`, `candy-code/src/Chat.php`, `candy-code/lang/en.php`, `candy-code/README.md`, `candy-code/docs/COMMANDS.md`, `candy-code/docs/TROUBLESHOOTING.md`
- **Tests — create:** `candy-code/tests/Cli/DoctorChecksTest.php`, `candy-code/tests/Host/Commands/DoctorHostCommandTest.php` · **modify:** `tests/Cli/DoctorBypassNoticeProbeTest.php`
- **Docs/guards:** gen-command-docs; CommandDocsDriftTest; ReadmeRosterDriftTest; BuiltInCommandsTest; a ReadPathCensusTest row for the `/proc/sys` read
- **Serialized:** [headless] [chat:handleDoctorCommand (new), READ_ONLY_COMMANDS] [commands] [lang:cmd.doctor., host.doctor., cli.doctor.] [docs:README.md] [docs:COMMANDS.md] [docs:TROUBLESHOOTING.md] …
- **Deps:** C-TOOL-4.a (W14)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-5.a`

#### C-TUI-5.e — First-run wizard wiring (App slot, web notice)
- **Item:** C-TUI-5 (sources: GAP-UI-29) · gap · effort M
- **What:** `App::init()` (`App.php:2631`) adds the wizard opener when `FirstRunWizard::shouldOffer()` is true. `Bootstrap` is untouched. The App slot routes keys like `mediaForm`. On Finish: `ProviderKeyStore::store()`, then a new public …
- **Files — create:** none
- **Files — modify:** `candy-code/src/App/App.php`, `candy-code/src/Tui/Renderer.php`, `candy-code/src/Chat.php`, `candy-code/src/Commands/KeyBindingRegistry.php`, `candy-code/lang/en.php`, `candy-code/README.md`, `candy-code-web/src-web/views/DashboardView.vue`
- **Tests — create:** `candy-code/tests/App/FirstRunWizardWiringTest.php`, `candy-code-web/src-web/views/__tests__/dashboardNoProvider.spec.ts` · **modify:** none
- **Docs/guards:** KeyBindingDriftTest; ReadmeRosterDriftTest; vitest; dist rebuild at weld
- **Serialized:** [chat:switchProvider (new method)] [renderer:Tui/Renderer::renderView slot chain] [hot:candy-code/src/App/App.php] [hot:candy-code/src/Commands/KeyBindingRegistry.php] [lang:keys.wizard.] …
- **Deps:** C-TUI-5.d (W18)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-5.e`

#### C-WEB-2.a — `/btw` over `command.exec` while a turn runs
- **Item:** C-WEB-2 (sources: GAP-CTX-11w, k1-sum (c)) · gap · S
- **What:** **Root cause.** `SessionHost::runCommand()` (`candy-code/src/Host/SessionHost.php:792-793`) refuses every built-in while busy, except workflow control. It bypasses `TurnController::midTurnRoute()` …
- **Files — create:** `candy-code/tests/Host/BtwMidTurnTest.php`
- **Files — modify:** `candy-code/src/Host/SessionHost.php`, `candy-code/docs/SERVER.md`
- **Tests — create:** `candy-code/tests/Host/BtwMidTurnTest.php` · **modify:** none
- **Docs/guards:** `docs/SERVER.md` prose (not a generated table).
- **Serialized:** [hot:candy-code/src/Host/SessionHost.php] [docs:SERVER.md]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-web.md` → `### C-WEB-2.a`

### W31

#### C-TUI-5.b — `candy-code import claude`
- **Item:** C-TUI-5 (sources: GAP-CFG-08) · gap · effort M
- **What:** Imports today cover only MCP and memory. `ClaudeSettingsImport` reads `~/.claude/settings.json`, plus the project's `.claude/settings.json` with `--project`. It maps `permissions.allow/deny/ask` to `permissionRules` through …
- **Files — create:** `candy-code/src/Config/ClaudeSettingsImport.php`
- **Files — modify:** `candy-code/src/Cli/Subcommands.php`, `candy-code/src/Cli/ParsedArgs.php`, `candy-code/src/Cli/ArgvParser.php`, `candy-code/src/Cli/Help.php`, `candy-code/lang/en.php`, `candy-code/README.md`, `candy-code/docs/PERMISSIONS.md`
- **Tests — create:** `candy-code/tests/Config/ClaudeSettingsImportTest.php`, `candy-code/tests/Cli/ImportSubcommandTest.php` · **modify:** `candy-code/tests/Cli/LoginSubcommandTest.php`, `tests/Cli/ArgvParserTest.php`
- **Docs/guards:** ReadmeRosterDriftTest; DocFigureProseDriftTest (the subcommand count words); HelpTest; `tests/Integration/BinCandyCodeDispatchTest.php`; SettingsWriterCensusTest and …
- **Serialized:** [headless] [lang:cli.sub.import.] [docs:README.md] [docs:PERMISSIONS.md] [hot:candy-code/tests/Support/ReadPathCensusTest.php] [hot:candy-code/tests/Config/Settings/SettingsWriterCensusTest.php]
- **Deps:** C-TUI-5.d (W18)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-5.b`

#### C-WEB-2.e — `/help` and `/budget` host bodies
- **Item:** C-WEB-2 (sources: GAP-CMD-01, p1-miss/p2-miss parity) · gap · M
- **What:** **Mechanism.** Admission flips by construction: `runsIn` is "server iff `hostCommand`" (`CommandMethods.php:117-120`). **HelpHostCommand.** It renders `CommandRegistry` slash rows grouped one heading per category (the property …
- **Files — create:** `candy-code/src/Host/Commands/HelpHostCommand.php`, `candy-code/src/Host/Commands/BudgetHostCommand.php`, `candy-code/tests/Host/Commands/HelpHostCommandTest.php`, `candy-code/tests/Host/Commands/BudgetHostCommandTest.php`
- **Files — modify:** `candy-code/builtin-commands/1100-help.php`, `candy-code/builtin-commands/2200-budget.php`, `candy-code/src/Host/Commands/CommandContext.php`, `candy-code/src/Host/SessionHost.php`, `candy-code/lang/en.php`
- **Tests — create:** `candy-code/tests/Host/Commands/HelpHostCommandTest.php`, `candy-code/tests/Host/Commands/BudgetHostCommandTest.php` · **modify:** none
- **Docs/guards:** `tests/Commands/BuiltInCommandsTest.php` (every hostCommand is a HostCommand) must stay green; `tests/LangParityTest.php`; `docs/COMMANDS.md` is unaffected (the generator renders no runs-in column); …
- **Serialized:** [commands] [lang:host.help.,host.budget.] [hot:candy-code/src/Host/SessionHost.php]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-web.md` → `### C-WEB-2.e`

### W32

- Chat.php region claims: C-TUI-5.c (handleSettingsCommand)

#### C-TUI-5.c — Settings hot reload + `/settings reload`
- **Item:** C-TUI-5 (sources: GAP-CFG-05) · gap · effort M
- **What:** Next-turn keys are already re-read every turn. Live keys only change through `Chat::applySettings()` (`Chat.php:7591`), so an external edit to `config.json`/`settings.json` waits for a restart. `SettingsFileWatch` keeps a …
- **Files — create:** `candy-code/src/Config/SettingsFileWatch.php`
- **Files — modify:** `candy-code/src/Chat.php`, `candy-code/src/Chat/Handlers/HandlesTurnCompletion.php`, `candy-code/lang/en.php`, `candy-code/docs/SETTINGS.md`, `candy-code/docs/COMMANDS.md`, `candy-code/README.md`
- **Tests — create:** `candy-code/tests/Config/SettingsFileWatchTest.php`, `candy-code/tests/Chat/SettingsReloadTest.php` · **modify:** none
- **Docs/guards:** gen-command-docs; CommandDocsDriftTest; SettingsSchemaDocDriftTest; a ReadPathCensusTest row (`hash_file`)
- **Serialized:** [chat:handleSettingsCommand] [hot:candy-code/src/Chat/Handlers/HandlesTurnCompletion.php] [lang:cmd.settings., chat.settings.reload.] [docs:SETTINGS.md] [docs:COMMANDS.md] [docs:README.md] …
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-5.c`

### W33 — LATE

#### C-TOOL-4.b — SandboxBackend seam + macOS Seatbelt backend
- **Item:** C-TOOL-4 (sources: GAP-SH-06) · gap · effort M
- **What:** **Interface.** New `SandboxBackend` with: `name()`, `mode()`; `argv(string $writableRoot, bool $interactive = false, ?string $tmpDir = null, ?string $chdir = null, array $readOnlyBinds = [])`, plus `wrap()` with the same …
- **Files — create:** `candy-code/src/Tools/Sandbox/SandboxBackend.php`, `candy-code/src/Tools/Sandbox/SandboxFactory.php`, `candy-code/src/Tools/Sandbox/Seatbelt.php`, `candy-code/src/Tools/Sandbox/JailPaths.php`, `candy-code/tests/Tools/Sandbox/SandboxFactoryTest.php`, `candy-code/tests/Tools/Sandbox/SeatbeltTest.php`
- **Files — modify:** `candy-code/src/Tools/Sandbox/Bubblewrap.php`, `candy-code/src/Tools/BuiltIn/Bash.php`, `candy-code/src/Tools/BuiltIn/PtyRun.php`, `candy-code/src/Config/Settings/Definitions/ToolSettings.php`, `candy-code/src/Cli/Subcommands.php`, `candy-code/lang/en.php`, `candy-code/docs/PERMISSIONS.md`, `candy-code/docs/SETTINGS.md`, `candy-code/README.md`, `.github/workflows/ci.yml`
- **Tests — create:** none · **modify:** `candy-code/tests/Tools/BashSandboxTest.php`, `candy-code/tests/Tools/PtyRunToolTest.php`, `candy-code/tests/Cli/DoctorSandboxProbeTest.php`, `candy-code/tests/Support/ReadPathCensusTest.php`
- **Docs/guards:** `SettingsSchemaDocDriftTest` (readBy text), `SettingsSchemaTest::testEveryReaderSymbolResolvesToARealMethod`, `ReadPathCensusTest`, `tools/check-one-type-per-file.php` (four new types in four files)
- **Serialized:** [headless] [workflows] [lang:settings.bashSandbox., cli.doctor.sandbox_] [docs:PERMISSIONS.md] [docs:SETTINGS.md] [docs:README.md] [hot:candy-code/src/Tools/BuiltIn/Bash.php] …
- **Deps:** C-TOOL-4.a (W14)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-4.b`

#### C-TUI-6.b — sugar-pty ConPTY FFI primitives
- **Item:** C-TUI-6 (sources: GAP-PLAT-04, p2-miss: Windows support (×2)) · gap · effort M
- **What:** `sugar-pty` has no Windows code (`src/Windows/` does not exist). `PtySystemFactory.php:17` throws `UnsupportedPlatformException` ("v2 ConPTY"). This step adds only the FFI layer and the pure helpers, with no contract adapters …
- **Files — create:** `sugar-pty/src/Windows/ConPtyApi.php`, `sugar-pty/src/Windows/Kernel32ConPty.php`, `sugar-pty/src/Windows/CommandLine.php`, `sugar-pty/src/Windows/EnvBlock.php`, `sugar-pty/src/Windows/WideString.php`
- **Files — modify:** `sugar-pty/src/Exception/UnsupportedPlatformException.php`
- **Tests — create:** `sugar-pty/tests/Windows/CommandLineTest.php`, `sugar-pty/tests/Windows/EnvBlockTest.php`, `sugar-pty/tests/Windows/WideStringTest.php`, `sugar-pty/tests/Windows/Kernel32ConPtyTest.php`, `sugar-pty/tests/Windows/FakeConPtyApi.php` · **modify:** `sugar-pty/tests/Exception/UnsupportedPlatformExceptionTest.php`
- **Docs/guards:** `tools/check-one-type-per-file.php`. No `proc_open` is added: `CreateProcessW` is not a PHP spawn, so `tools/check-child-lifetimes.php` sees no new site, and the job object is the containment.
- **Serialized:** none
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-6.b`

#### C-TUI-6.i — Screen-reader core + `screenReader` setting
- **Item:** C-TUI-6 (sources: GAP-UI-30) · gap · effort M
- **What:** Today there is reduced motion (`CANDY_CODE_REDUCED_MOTION`, read at `Bootstrap.php:1238`, launch-only), contrast and a row cursor, but no screen-reader mode. `ScreenReaderMode` is process-global and reads …
- **Files — create:** `candy-code/src/Tui/ScreenReaderMode.php`
- **Files — modify:** `candy-code/src/Config/Settings/Definitions/InterfaceSettings.php`, `candy-code/src/Config/LayeredSettings.php`, `candy-code/src/Config/Settings/UiSettings.php`, `candy-code/src/Agents/Live/AgentLiveRegistry.php`, `candy-code/lang/en.php`, `candy-code/docs/SETTINGS.md`, `candy-code/docs/ENVIRONMENT.md`
- **Tests — create:** `candy-code/tests/Tui/ScreenReaderModeTest.php` · **modify:** `candy-code/tests/Agents/Live/AgentLiveRegistryTest.php`
- **Docs/guards:** SettingsSchemaDocDriftTest; ReadmeRosterDriftTest; DocFigureProseDriftTest (the settings count words); `tools/check-one-type-per-file.php`
- **Serialized:** [lang:settings.screenReader.] [docs:SETTINGS.md] [docs:README.md] [docs:ENVIRONMENT.md] [hot:candy-code/src/Config/Settings/Definitions/InterfaceSettings.php] …
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-6.i`

#### C-EXT-6.c — `type: http` hooks (LATE)
- **Item:** C-EXT-6 (sources: GAP-HK-03) · gap · effort M
- **What:** **Shared extraction.** The envelope reader moves out of ScriptHook's private `envelope`/`fromEnvelope`/`malformedEnvelope` into `HookEnvelope`. The stdin payload moves out of ScriptHook's private `stdinPayload` (:1067) into …
- **Files — create:** `candy-code/src/Hooks/HttpHook.php`, `candy-code/src/Hooks/HookEnvelope.php`, `candy-code/src/Hooks/HookPayload.php`
- **Files — modify:** `candy-code/src/Hooks/ScriptHook.php`, `candy-code/src/Hooks/HookConfig.php`, `candy-code/src/Hooks/HookManager.php`, `candy-code/src/Hooks/ClaudeSettingsHooks.php`, `candy-code/lang/en.php`, `candy-code/docs/HOOKS.md`
- **Tests — create:** `candy-code/tests/Hooks/HttpHookTest.php`, `candy-code/tests/Hooks/HookEnvelopeTest.php` · **modify:** `candy-code/tests/Hooks/HookConfigTest.php`, `candy-code/tests/Hooks/ClaudeSettingsHooksTest.php`, `candy-code/tests/Hooks/ScriptHookJsonStdoutTest.php`, `candy-code/tests/Config/DocFigureProseDriftTest.php`
- **Docs/guards:** the HOOKS.md entry-key pin; one type per file.
- **Serialized:** [docs:HOOKS.md] [lang:hooks.http.] [hot:candy-code/src/Hooks/ScriptHook.php] [hot:candy-code/src/Hooks/HookConfig.php] [hot:candy-code/src/Hooks/HookManager.php]
- **Deps:** C-EXT-1.b (W2)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ext.md` → `### C-EXT-6.c`

#### C-MCP-5.a — `mcp serve` core: stdio JSON-RPC host over read-only tools
- **Item:** C-MCP-5 (sources: GAP-MCP-13, p1-miss "MCP server mode") · gap · effort M
- **What:** candy-code cannot act as an MCP server: no server-mode code exists, and `docs/MCP.md:880-890` says `serve` "speaks no MCP". `McpServeHost` runs an NDJSON JSON-RPC loop on stdin/stdout, framed with …
- **Files — create:** `candy-code/src/MCP/Serve/McpServeHost.php`, `candy-code/src/MCP/Serve/McpServeRoster.php`, `candy-code/tests/MCP/Serve/McpServeHostTest.php`
- **Files — modify:** none
- **Tests — create:** `candy-code/tests/MCP/Serve/McpServeHostTest.php` · **modify:** none
- **Docs/guards:** `php tools/check-one-type-per-file.php`; no new `proc_open` (Grep's `rg` spawn is an existing site).
- **Serialized:** none
- **Deps:** none
- **Ruling:** Confirm scope: stdio only, a read-only roster (Read/Glob/Grep/Ls/RepoMap), Ask → refuse, and no session or prompt exposure? — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-mcp.md` → `### C-MCP-5.a`

#### C-WEB-5.a — `serve --tls-cert/--tls-key` (built-in TLS)
- **Item:** C-WEB-5 (sources: GAP-WEB-04, OC-new#12) · gap · M
- **What:** **Gap.** `--allow-remote` is cleartext; `docs/SERVER.md:53` says "there is no built-in TLS". **Wrapping.** `Listener` already `implements ServerInterface` (`candy-code/src/Server/Listener.php:29`). `Server` (:249) wraps it in …
- **Files — create:** `candy-code/tests/Server/TlsListenerTest.php`
- **Files — modify:** `candy-code/src/Cli/Serve.php`, `candy-code/src/Cli/ParsedArgs.php`, `candy-code/src/Server/ServerConfig.php`, `candy-code/src/Server/Server.php`, `candy-code/src/Cli/Help.php`, `candy-code/lang/en.php`, `candy-code/docs/SERVER.md`, `candy-code/README.md`
- **Tests — create:** `candy-code/tests/Server/TlsListenerTest.php` · **modify:** `candy-code/tests/Cli/ServeArgsTest.php`
- **Docs/guards:** `docs/SERVER.md`, `README.md`; `tests/Config/ReadmeRosterDriftTest.php` re-run; `tests/LangParityTest.php`; no env var, no child process.
- **Serialized:** [headless] [docs:SERVER.md] [docs:README.md] [lang:cli.serve.tls.] [hot:candy-code/src/Cli/Serve.php]
- **Deps:** none
- **Ruling:** R13 — does the owner want built-in TLS on `serve` (and with it a supported remote/phone posture), or keep SSH-tunnel/proxy as the only remote answer? — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-web.md` → `### C-WEB-5.a`

#### C-EXT-6.b — Hook log file + `/hooks log` (LATE)
- **Item:** C-EXT-6 (sources: GAP-HK-08) · gap · effort M
- **What:** **Why a file.** Hooks run in forked turn and tool children, so an in-memory ring in the parent never sees them. That makes this M, not the cheap S sliver, so it stays LATE. **`HookLog`.** A bounded JSONL file, …
- **Files — create:** `candy-code/src/Hooks/HookLog.php`, `candy-code/src/Hooks/HookLogEntry.php`
- **Files — modify:** `candy-code/src/Hooks/HookRegistry.php`, `candy-code/src/Commands/HooksCommand.php`, `candy-code/src/Host/Commands/HooksHostCommand.php`, `candy-code/builtin-commands/1390-hooks.php`, `candy-code/lang/en.php`, `candy-code/docs/HOOKS.md`, `candy-code/docs/COMMANDS.md`
- **Tests — create:** `candy-code/tests/Hooks/HookLogTest.php`, `candy-code/tests/Hooks/HookLogConcurrentAppendTest.php`, `candy-code/tests/Host/Commands/HooksLogCommandTest.php` · **modify:** `candy-code/tests/Commands/HooksCommandTest.php`
- **Docs/guards:** gen-command-docs; ReadPathCensusTest (the log path).
- **Serialized:** [commands:builtin-commands/1390-hooks.php] [docs:HOOKS.md] [docs:COMMANDS.md] [lang:cmd.hooks.log.] [hot:candy-code/src/Hooks/HookRegistry.php]
- **Deps:** C-EXT-7.a (W9)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ext.md` → `### C-EXT-6.b`

#### C-WEB-5.g — Confirmed "Stop server" for Admin tokens
- **Item:** C-WEB-5 (sources: GAP-WEB-N4 (stop), k3-dorm#3 (`server.shutdown`)) · gap · S
- **What:** **Gap.** `server.shutdown` (Scope::Admin, `candy-code/src/Protocol/Methods/ServerMethods.php:46`) is deliberately dormant behind `candy-code-web/src-web/__tests__/shutdownDormant.spec.ts`. **Button.** `AboutModal.vue` shows a …
- **Files — create:** none
- **Files — modify:** `candy-code-web/src-web/components/AboutModal.vue`, `candy-code-web/src-web/__tests__/shutdownDormant.spec.ts`, `candy-code-web/src-web/__tests__/dormantRoster.spec.ts`, `candy-code-web/src-web/components/__tests__/aboutModal.spec.ts`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** `candy-code-web/README.md` (soft); vitest; dist rebuild.
- **Serialized:** [hot:candy-code-web/src-web/components/AboutModal.vue] [hot:candy-code-web/src-web/__tests__/dormantRoster.spec.ts]
- **Deps:** C-WEB-3.g (W15)
- **Ruling:** R13 — flip `server.shutdown` from deliberate-dormant to an Admin-only, confirmed browser action? — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-web.md` → `### C-WEB-5.g`

### W34 — LATE

- Chat.php region claims: C-TUI-6.j (requestPermission)

#### C-TOOL-4.c — bwrap hardening (CC-new#11)
- **Item:** C-TOOL-4 (sources: CC-new#11) · gap · effort S
- **What:** **Nested user namespaces.** Where the host's bwrap supports it, `argv()` adds `--unshare-user --disable-userns` (bwrap ≥ 0.8). A jailed command then cannot create a fresh user namespace, which is the usual way back out of a mount …
- **Files — create:** `candy-code/tests/Tools/Sandbox/BubblewrapHardeningTest.php`
- **Files — modify:** `candy-code/src/Tools/Sandbox/Bubblewrap.php`, `candy-code/src/Tools/Sandbox/SandboxFactory.php`, `candy-code/src/Config/Settings/Definitions/ToolSettings.php`, `candy-code/src/Config/LayeredSettings.php`, `candy-code/lang/en.php`, `candy-code/docs/PERMISSIONS.md`, `candy-code/docs/SETTINGS.md`, `candy-code/README.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Tools/BashSandboxTest.php`, `candy-code/tests/Config/LayeredSettingsTest.php`
- **Docs/guards:** `SettingsSchemaDocDriftTest`, `TrustKeyDocumentationDriftTest` (README user-tier enumeration), `LayeredSettingsTest`
- **Serialized:** [lang:settings.bashSandboxNestedUserns.] [docs:PERMISSIONS.md] [docs:SETTINGS.md] [docs:README.md] [hot:candy-code/src/Config/LayeredSettings.php]
- **Deps:** C-TOOL-4.b (W33)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-4.c`

#### C-TOOL-4.f — Egress proxy core (new files only)
- **Item:** C-TOOL-4 (sources: GAP-SH-07) · gap · effort M
- **What:** **`EgressPolicy`.** `sandboxAllowedDomains` entries parse as `host-glob[:port]`; the default ports are 80 and 443. Matching uses the grant side of `FetchTarget::matchesDomain($pattern, includeSubdomains: false)` …
- **Files — create:** `candy-code/src/Tools/Sandbox/EgressPolicy.php`, `candy-code/src/Tools/Sandbox/EgressProxy.php`, `candy-code/src/Tools/Sandbox/EgressProxyProcess.php`, `candy-code/src/Tools/Sandbox/EgressBridge.php`, `candy-code/tests/Tools/Sandbox/EgressPolicyTest.php`, `candy-code/tests/Tools/Sandbox/EgressProxyTest.php`, `candy-code/tests/Tools/Sandbox/EgressBridgeTest.php`, `candy-code/tests/Tools/Sandbox/EgressProxyProcessTest.php`
- **Files — modify:** `candy-code/tests/Support/DescriptorInheritanceGuardTest.php`, `candy-code/tests/Support/ReadPathCensusTest.php`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** `DescriptorInheritanceGuardTest` (the new long-lived spawn row), `ChildStderrCaptureTest` (stderr goes to a file, not discarded), `ForkedChildExitConventionTest` (the bridge's fork child), …
- **Serialized:** [hot:candy-code/tests/Support/DescriptorInheritanceGuardTest.php] [hot:candy-code/tests/Support/ReadPathCensusTest.php]
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-4.f`

#### C-TOOL-4.h — Darwin process groups + wall fallback without GNU `timeout`
- **Item:** C-TOOL-4 (sources: p2-miss: SHL macOS/Windows setsid + timeout fallback) · gap · effort M
- **What:** **Root cause.** `ProcessContainment::detachedSpawnBinary()` (:185) returns '' wherever no `setsid -w` exists (stock macOS), so `spawnSpec()` (:227) hands back the bare command. Two things degrade: background shells record …
- **Files — create:** `candy-code/tests/Support/ProcessContainmentPhpSetsidTest.php`, `candy-code/tests/Sessions/BackgroundShellWallFallbackTest.php`
- **Files — modify:** `candy-code/src/Support/ProcessContainment.php`, `candy-code/src/Background/BackgroundShellRegistry.php`, `.github/workflows/ci.yml`, `candy-code/docs/TROUBLESHOOTING.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Sessions/BackgroundShellRegistryTest.php`, `candy-code/tests/Tools/ShellStopTest.php`
- **Docs/guards:** `DescriptorInheritanceGuardTest` (the probe stays one short-lived proc_open, closed by a literal proc_close, so no roster row is needed); `ChildStderrCaptureTest` (the watchdog writes nothing)
- **Serialized:** [workflows] [docs:TROUBLESHOOTING.md] [hot:candy-code/src/Background/BackgroundShellRegistry.php] [hot:candy-code/src/Support/ProcessContainment.php]
- **Deps:** C-TOOL-1.a (W1), C-TOOL-7.a (W5), C-TOOL-3.c (W13)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-4.h`

#### C-TOOL-6.f — `Canvas` tool core (HTML artifact into the session MediaStore)
- **Item:** C-TOOL-6 (sources: GAP-TL-24) · gap · effort M
- **What:** **Tool.** `CanvasTool`: `#[BuiltInTool(name: 'Canvas', permission: NoAsk, position: 45, gloss: 'save a self-contained HTML page as a session artifact the web client can display')]`. Args: `html` (required) and `title` (≤ 80 …
- **Files — create:** `candy-code/src/Tools/BuiltIn/CanvasTool.php`, `candy-code/tests/Tools/BuiltIn/CanvasToolTest.php`
- **Files — modify:** `candy-code/src/Support/MediaStore.php`, `candy-code/tests/Support/MediaStoreTest.php`, `candy-code/tests/RuntimeTest.php`, `candy-code/docs/MEDIA.md`, `candy-code/docs/PERMISSIONS.md`, `candy-code/README.md`, `candy-code/docs/ARCHITECTURE.md`, `candy-code/docs/AGENTS_AUTHORING.md`, `candy-code/docs/SETTINGS.md`, `candy-code/tests/Config/ReadmeRosterDriftTest.php`, `candy-code/tests/Config/ReadmeSettingsTierClaimTest.php`, `candy-code/tests/Config/DocFigureProseDriftTest.php`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** `ToolDocDriftTest`, `PermissionsIntroDriftTest`, RuntimeTest's `testEveryToolOnTheReadOnlyListCallsNoWritePrimitiveInItsOwnSource` and its write-roster drift test (NoAsk, so Canvas stays out of …
- **Serialized:** [catalog] [docs:README.md] [docs:ARCHITECTURE.md] [docs:AGENTS_AUTHORING.md] [docs:PERMISSIONS.md] [docs:SETTINGS.md] [docs:MEDIA.md] [hot:candy-code/src/Support/MediaStore.php] …
- **Deps:** none
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-6.f`

#### C-TUI-6.j — Screen-reader wiring in the renderers + bell cues
- **Item:** C-TUI-6 (sources: GAP-UI-30) · gap · effort M
- **What:** With `screenReader` on: **Borders.** The 7 `Border::` sites in `src/Renderer.php` (L5467, 6069, 6186, 6538, 6917, 7090, 7894) go through `ScreenReaderMode::border()`. So do the raw `│`/`─` glyph sites: about 15 in `Renderer.php` …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Renderer.php`, `candy-code/src/Tui/Renderer.php`, `candy-code/src/Tui/TerminalNotifier.php`, `candy-code/src/Chat.php`, `candy-code/lang/en.php`
- **Tests — create:** `candy-code/tests/Renderer/ScreenReaderRenderTest.php`, `candy-code/tests/Chat/ScreenReaderBellCueTest.php` · **modify:** `candy-code/tests/Chat/TurnEndNotificationTest.php`
- **Docs/guards:** a `docs/SETTINGS.md` help-text tweak only if the 6.i help changes (regenerate); RouteTraitParityTest stays green (no new Msg)
- **Serialized:** [renderer:Renderer overlay chain ~L2004-2024, border/glyph sites, renderPalette, renderSessionPicker, renderPermissionPrompt; Tui/Renderer glyph sites] [chat:requestPermission] [lang:tui.sr.] …
- **Deps:** C-TUI-6.i (W33)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-6.j`

#### C-WEB-5.b — `serve url --qr` pairing QR
- **Item:** C-WEB-5 (sources: GAP-WEB-04 (pairing half), p2-miss: WEB-8 pairing/QR, OC-new#12) · gap · M
- **What:** **Flag.** `serve url` (`candy-code/src/Cli/Serve.php:864`, already a fresh LoginCodes one-time URL) gains `--qr`. **Rendering.** The sign-in URL prints as a terminal QR: two rows per text line in half-blocks, with a quiet zone. …
- **Files — create:** `candy-code/src/Server/Pairing/QrEncoder.php`, `candy-code/src/Server/Pairing/QrTerminal.php`, `candy-code/tests/Server/Pairing/QrEncoderTest.php`
- **Files — modify:** `candy-code/src/Cli/Serve.php`, `candy-code/src/Cli/ParsedArgs.php`, `candy-code/src/Cli/Help.php`, `candy-code/lang/en.php`, `candy-code/docs/SERVER.md`
- **Tests — create:** `candy-code/tests/Server/Pairing/QrEncoderTest.php` · **modify:** `candy-code/tests/Cli/ServeArgsTest.php`
- **Docs/guards:** `docs/SERVER.md`; `tests/LangParityTest.php`; one type per file (encoder and renderer are separate).
- **Serialized:** [headless] [docs:SERVER.md] [lang:cli.serve.qr.] [hot:candy-code/src/Cli/Serve.php]
- **Deps:** C-WEB-5.a (W33)
- **Ruling:** R13 + QR source — write an in-tree encoder (default here), add a third-party QR dependency, or upgrade sugar-dashboard's placeholder `QRCode` into a real … — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-web.md` → `### C-WEB-5.b`

### W35 — LATE

- protocol lanes: C-TUI-6.f, C-TOOL-6.g (weld regenerates schema → SERVER.md → generated.ts once)
- Bootstrap.php method claims: C-TOOL-4.d (hooks)

#### C-TOOL-4.d — `sandboxScope`: jail hooks, lint, format and test runs
- **Item:** C-TOOL-4 (sources: GAP-SH-06) · gap · effort M
- **What:** **Setting.** New user-tier list `sandboxScope` over the `SandboxScope` consts `hooks`, `lint`, `format` and `test`. The default is `[]`, so nothing changes until the user opts in. An unknown entry reads as every scope (the …
- **Files — create:** `candy-code/src/Tools/Sandbox/SandboxScope.php`, `candy-code/tests/Tools/Sandbox/ScopedRunnerJailTest.php`
- **Files — modify:** `candy-code/src/Hooks/ScriptHook.php`, `candy-code/src/Hooks/HookManager.php`, `candy-code/src/Lint/LintRunner.php`, `candy-code/src/Lint/TestRunner.php`, `candy-code/src/Format/FormatRunner.php`, `candy-code/src/Cli/Bootstrap.php`, `candy-code/src/Tools/Sandbox/SandboxFactory.php`, `candy-code/src/Config/Settings/Definitions/ToolSettings.php`, `candy-code/src/Config/LayeredSettings.php`, `candy-code/lang/en.php`, `candy-code/docs/HOOKS.md`, `candy-code/docs/PERMISSIONS.md`, `candy-code/docs/SETTINGS.md`, `candy-code/README.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Hooks/ScriptHookTest.php`, `candy-code/tests/Format/FormatRunnerTest.php`, `candy-code/tests/Lint/TestRunnerTest.php`, `candy-code/tests/Config/LayeredSettingsTest.php`
- **Docs/guards:** `SettingsSchemaDocDriftTest`, `TrustKeyDocumentationDriftTest`, `LayeredSettingsTest`. `DescriptorInheritanceGuardTest` stays green with no new row (prefix only).
- **Serialized:** [bootstrap:hooks] [lang:settings.sandboxScope., sandbox.] [docs:HOOKS.md] [docs:PERMISSIONS.md] [docs:SETTINGS.md] [docs:README.md] [hot:candy-code/src/Hooks/ScriptHook.php] …
- **Deps:** C-EXT-1.a (W1), C-TOOL-5.b (W12), C-TOOL-4.b (W33)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-4.d`

#### C-TUI-6.f — Web i18n infrastructure: `server.strings` + chrome migration
- **Item:** C-TUI-6 (sources: GAP-PLAT-10, p2-miss: WEB-7c i18n) · gap · effort M
- **What:** Web chrome strings are hard-coded English in the `.vue` files, and there is no i18n code in `src-web`. `candy-code/src/Lang.php` already resolves exact → base → `en` per key. **Server.** `WebStrings` serves ONLY the `web.` key …
- **Files — create:** `candy-code/src/Protocol/WebStrings.php`, `candy-code/scripts/gen-web-strings.php`, `candy-code/docs/protocol/web-strings.en.json`, `candy-code-web/scripts/gen-web-strings.mjs`, `candy-code-web/src-web/lib/strings.en.generated.ts`, `candy-code-web/src-web/lib/i18n.ts`, `candy-code-web/src-web/stores/locale.ts`
- **Files — modify:** `candy-code/src/Protocol/Methods/ServerMethods.php`, `candy-code/src/Protocol/Schema/MethodSchemas.php`, `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts`, `candy-code/lang/en.php`, `candy-code-web/package.json`, `candy-code-web/src-web/App.vue`, `candy-code-web/src-web/components/StatusBar.vue`, `candy-code-web/src-web/components/panels/SidePanels.vue`, `candy-code-web/src-web/components/ConnectionBanner.vue`
- **Tests — create:** `candy-code/tests/Protocol/WebStringsTest.php`, `candy-code/tests/Protocol/WebStringsDriftTest.php`, `candy-code-web/src-web/lib/__tests__/i18n.spec.ts`, `candy-code-web/src-web/stores/__tests__/locale.spec.ts`, `candy-code-web/src-web/__tests__/i18nCoverage.spec.ts` · **modify:** `candy-code-web/src-web/components/__tests__/statusBar.spec.ts`, `candy-code-web/src-web/__tests__/app.spec.ts`
- **Docs/guards:** ProtocolSchemaDriftTest and ServerProtocolDocumentationDriftTest, plus both protocol gens `--check`. The new WebStringsDriftTest. A ReadPathCensusTest row: `WebStrings` reads the installation's …
- **Serialized:** [protocol] [lang:web.chrome.] [docs:SERVER.md] [hot:candy-code-web/src-web/App.vue] [hot:candy-code-web/src-web/components/StatusBar.vue] [hot:candy-code-web/src-web/components/panels/SidePanels.vue] …
- **Deps:** none
- **Ruling:** reverse R6's deferral of WEB-7c and scope web i18n to chrome strings only, leaving server, tool and model text untranslated. Proposed: yes. — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-6.f`

#### C-WEB-5.c — Web Push core: VAPID keys, RFC 8291 cipher, allowlisted sender
- **Item:** C-WEB-5 (sources: GAP-WEB-10, p2-miss: WEB-8 web push, OC-new#12) · gap · M
- **What:** Pure server core, no wiring. **VAPID keys.** `VapidKeys` holds a P-256 pair, made once with `openssl_pkey_new` and stored 0600 in StateDir. It provides the ES256 VAPID JWT (RFC 8292, DER→raw signature). **Cipher.** …
- **Files — create:** `candy-code/src/Server/Push/VapidKeys.php`, `candy-code/src/Server/Push/PushPayloadCipher.php`, `candy-code/src/Server/Push/WebPushSender.php`, `candy-code/src/Server/Push/PushSubscription.php`, `candy-code/tests/Server/Push/VapidKeysTest.php`, `candy-code/tests/Server/Push/PushPayloadCipherTest.php`, `candy-code/tests/Server/Push/WebPushSenderTest.php`
- **Files — modify:** `candy-code/src/Server/StateDir.php`
- **Tests — create:** `candy-code/tests/Server/Push/PushPayloadCipherTest.php`, `candy-code/tests/Server/Push/VapidKeysTest.php`, `candy-code/tests/Server/Push/WebPushSenderTest.php` · **modify:** none
- **Docs/guards:** `tests/Support/ReadPathCensusTest.php` row for the key read (StateDir, OWNED_HOME); no proc_open, so no child-lifetime row.
- **Serialized:** none
- **Deps:** none
- **Ruling:** R13 + crypto source — in-tree RFC 8291/8292 (default here; ext-openssl only) or a web-push library dependency? — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-web.md` → `### C-WEB-5.c`

#### C-TOOL-6.c — Code-mode Script runner core (jailed PHP child + fd-3 tool bridge)
- **Item:** C-TOOL-6 (sources: GAP-TL-N3, OC-new#12 (Code Mode)) · gap · effort M
- **What:** **Purpose.** This is the engine half of Code Mode. A model-written script runs in a child whose only effect channel is a tool bridge. One model call can then chain many tool calls (OpenCode 2's default MCP surface), while 6.d …
- **Files — create:** `candy-code/src/Tools/Script/ScriptRunner.php`, `candy-code/src/Tools/Script/ScriptBridge.php`, `candy-code/src/Tools/Script/ScriptPrelude.php`, `candy-code/src/Tools/Script/ScriptRun.php`, `candy-code/tests/Tools/Script/ScriptRunnerTest.php`, `candy-code/tests/Tools/Script/ScriptBridgeTest.php`
- **Files — modify:** `candy-code/tests/Support/DescriptorInheritanceGuardTest.php`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** `DescriptorInheritanceGuardTest` (the row), `ChildStderrCaptureTest` (stderr is piped and returned, never discarded), `ReadPathCensusTest` (only if it counts the staging write; the prelude's …
- **Serialized:** [hot:candy-code/tests/Support/DescriptorInheritanceGuardTest.php]
- **Deps:** C-TOOL-4.b (W33)
- **Ruling:** Two questions. Script language: a PHP child (`PHP_BINARY -n`, always present; recommended) or node (absent on many hosts)? Isolation floor: when no jail probes … — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-6.c`

#### C-TUI-6.c — sugar-pty ConPTY Contract adapters + factory + WINDOWS_LIBS
- **Item:** C-TUI-6 (sources: GAP-PLAT-04, p2-miss: Windows support (×2)) · gap · effort M
- **What:** Implement `sugar-pty/src/Contract/*` over `ConPtyApi`. `ConPtySystem::open()` creates two pipes and a pseudo console. `capabilities()` reports `conpty: true`, `controllingTerminal: false`, `signals: false` and `fd: false`. …
- **Files — create:** `sugar-pty/src/Windows/ConPtySystem.php`, `sugar-pty/src/Windows/ConPtyPair.php`, `sugar-pty/src/Windows/ConPtyMasterPty.php`, `sugar-pty/src/Windows/ConPtySlavePty.php`, `sugar-pty/src/Windows/ConPtyChild.php`
- **Files — modify:** `sugar-pty/src/PtySystemFactory.php`, `scripts/affected-libs.php`, `.github/workflows/ci.yml`, `sugar-pty/README.md`, `sugar-pty/CALIBER_LEARNINGS.md`, `docs/MATCHUPS.md`, `docs/_data/sugar-pty.body.html`, `docs/lib/sugar-pty.html`
- **Tests — create:** `sugar-pty/tests/Windows/ConPtySystemTest.php`, `sugar-pty/tests/Windows/ConPtyMasterPtyTest.php`, `sugar-pty/tests/Windows/ConPtyChildTest.php`, `sugar-pty/tests/Windows/ConPtyLiveTest.php` · **modify:** `sugar-pty/tests/PtySystemFactoryTest.php`, `sugar-pty/tests/Contract/InterfacesTest.php`
- **Docs/guards:** `sugar-pty/tests/EnvRosterTest.php`: the README env table. `tools/check-docs-assets.php`, after gen-docs. `tools/check-path-repos.php --no-lib-path-repos`.
- **Serialized:** [workflows] [hot:scripts/affected-libs.php] [hot:docs/MATCHUPS.md]
- **Deps:** C-TUI-6.b (W33)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-6.c`

#### C-MCP-5.b — `candy-code mcp serve` verb, help, completion, docs
- **Item:** C-MCP-5 (sources: GAP-MCP-13) · gap · effort S
- **What:** The `mcp` verb gate (`candy-code/src/Cli/Subcommands.php:1952-1970`) routes `serve` to `McpServeHost::run()`. The completion roster `SUBCOMMAND_ACTIONS['mcp']` (:2587) gains `serve`. The help screen and `cli.mcp.usage` gain the …
- **Files — create:** `candy-code/tests/Cli/SubcommandsMcpServeTest.php`
- **Files — modify:** `candy-code/src/Cli/Subcommands.php`, `candy-code/lang/en.php`, `candy-code/docs/MCP.md`, `candy-code/README.md`
- **Tests — create:** `candy-code/tests/Cli/SubcommandsMcpServeTest.php` · **modify:** `candy-code/tests/Cli/HelpTest.php`
- **Docs/guards:** `DocFigureProseDriftTest`: `::testMcpCommandsSurfaceCountsHelpRowsAndTheRunArm` (help block rows, the "`candy-code serve` is not one of them" claim, the "eight answer and exit" sentence); …
- **Serialized:** [headless:Subcommands] [lang:cli.] [docs:MCP.md §Serving MCP] [docs:README.md]
- **Deps:** C-MCP-5.a (W33)
- **Brief:** `prompt_kit/findings/comparison-1026/design-mcp.md` → `### C-MCP-5.b`

#### C-TOOL-6.g — Canvas web half: `MediaKind::Html`, sandboxed `/canvas/{token}` page, `CanvasCard`
- **Item:** C-TOOL-6 (sources: GAP-TL-24) · gap · effort M
- **What:** **Kind.** `MediaKind::Html = 'html'` (`src/Media/MediaKind.php`), plus `isGenerative(): bool` (true for Image and Video only). The enum doubles as the `mediaKinds` provider vocabulary, so `CapabilityDiscoverer::fromConfig()` …
- **Files — create:** `candy-code/src/Server/Http/CanvasPageController.php`, `candy-code/src/Server/Http/CanvasLinks.php`, `candy-code/tests/Server/CanvasPageTest.php`, `candy-code/tests/Server/CanvasLinksTest.php`, `candy-code-web/src-web/components/CanvasCard.vue`, `candy-code-web/src-web/components/__tests__/canvasCard.spec.ts`
- **Files — modify:** `candy-code/src/Media/MediaKind.php`, `candy-code/src/Media/Capability/CapabilityDiscoverer.php`, `candy-code/src/Tools/BuiltIn/CanvasTool.php`, `candy-code/src/Host/TranscriptProjector.php`, `candy-code/src/Server/Http/Router.php`, `candy-code/src/Server/Http/Responses.php`, `candy-code/src/Server/Server.php`, `candy-code/src/Cli/Serve.php`, `candy-code/docs/MEDIA.md`, `candy-code/docs/SERVER.md`, `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code-web/src-web/protocol/generated.ts`, `candy-code-web/src-web/protocol/auth.ts`, `candy-code-web/src-web/components/ToolCard.vue`
- **Tests — create:** none · **modify:** `tests/Server/SharePageTest.php`, `candy-code/tests/Host/TranscriptProjectorMediaTest.php`, `candy-code/tests/Tools/BuiltIn/CanvasToolTest.php`, `candy-code/tests/Media/CapabilityDiscovererTest.php`, `candy-code/tests/Protocol/MediaMethodsTest.php`, `candy-code-web/src-web/components/__tests__/components.spec.ts`, `candy-code-web/src-web/protocol/__tests__/auth.spec.ts`
- **Docs/guards:** `ProtocolSchemaDriftTest`, `ServerProtocolDocumentationDriftTest` (regen in-step), `docs/MEDIA.md` in-step, `tools/check-one-type-per-file.php` (two new classes). No proc_open; no Lang keys (HTTP …
- **Serialized:** [protocol] [docs:MEDIA.md] [docs:SERVER.md] [hot:candy-code/src/Host/TranscriptProjector.php] [hot:candy-code/src/Server/Server.php] [hot:candy-code/src/Cli/Serve.php] …
- **Deps:** C-WEB-1.d (W3), C-TOOL-6.f (W34)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-6.g`

### W36 — LATE

- protocol lanes: C-TUI-6.g, C-WEB-5.d (weld regenerates schema → SERVER.md → generated.ts once)
- Bootstrap.php method claims: C-TOOL-4.e (lspClient, mcpClient); C-TOOL-4.g (egressProxy (new), tools)

#### C-TOOL-4.e — Jail LSP and MCP stdio servers
- **Item:** C-TOOL-4 (sources: GAP-SH-06) · gap · effort M
- **What:** `SandboxScope` gains `lsp` and `mcp`. **LSP.** `LspConnection` takes a `list<string> $spawnPrefix`, beside `serverArgs` (:274). `connect()` spawns `spawnSpec([...$prefix, $command, ...$args])` (:340). The E672 pre-check (:320) …
- **Files — create:** `candy-code/tests/Tools/Sandbox/ServerJailTest.php`
- **Files — modify:** `candy-code/src/LSP/LspConnection.php`, `candy-code/src/LSP/LspLauncher.php`, `candy-code/src/MCP/StdioMcpServer.php`, `candy-code/src/MCP/McpClient.php`, `candy-code/src/Cli/Bootstrap.php`, `candy-code/src/Tools/Sandbox/SandboxScope.php`, `candy-code/lang/en.php`, `candy-code/docs/MCP.md`, `candy-code/docs/PERMISSIONS.md`, `candy-code/docs/SETTINGS.md`, `candy-code/README.md`
- **Tests — create:** none · **modify:** `candy-code/tests/LSP/LspLauncherTest.php`, `candy-code/tests/MCP/StdioMcpServerTest.php`, `candy-code/tests/MCP/McpTrustPinsTest.php`
- **Docs/guards:** `DocFigureProseDriftTest::testMcpServerTypesKeysAndEnvInterpolationMatchTheFactory` (the stdio arm's `$config[...]` reads ↔ the MCP.md Keys cell), `SettingsSchemaDocDriftTest`. The …
- **Serialized:** [bootstrap:lspClient, mcpClient] [lang:settings.sandboxScope., sandbox.] [docs:MCP.md] [docs:PERMISSIONS.md] [docs:SETTINGS.md] [docs:README.md] [hot:candy-code/src/MCP/McpClient.php] …
- **Deps:** C-MCP-1.a (W1), C-TOOL-7.c (W6), C-MCP-3.a (W13), C-TOOL-5.c (W13), C-TOOL-4.d (W35)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-4.e`

#### C-TOOL-4.g — `bashSandbox: "allowlist"` + `sandboxAllowedDomains` wiring
- **Item:** C-TOOL-4 (sources: GAP-SH-07) · gap · effort M
- **What:** **Mode.** `Bubblewrap::MODES` (:53-56) gains `MODE_ALLOWLIST = 'allowlist'`, and `fromSetting()` maps it. Unknown values stay strictest. In this mode `argv()`: leaves the network unshared (no `--share-net`); binds the launch's …
- **Files — create:** `candy-code/tests/Tools/Sandbox/EgressAllowlistModeTest.php`
- **Files — modify:** `candy-code/src/Tools/Sandbox/Bubblewrap.php`, `candy-code/src/Tools/Sandbox/Seatbelt.php`, `candy-code/src/Tools/Sandbox/SandboxFactory.php`, `candy-code/src/Cli/Bootstrap.php`, `candy-code/src/Cli/LaunchContext.php`, `candy-code/src/Config/Settings/Definitions/ToolSettings.php`, `candy-code/src/Config/LayeredSettings.php`, `candy-code/lang/en.php`, `candy-code/docs/PERMISSIONS.md`, `candy-code/docs/SETTINGS.md`, `candy-code/README.md`
- **Tests — create:** none · **modify:** `candy-code/tests/Tools/BashSandboxTest.php`, `candy-code/tests/Config/LayeredSettingsTest.php`
- **Docs/guards:** `SettingsSchemaDocDriftTest`, `TrustKeyDocumentationDriftTest`, `LayeredSettingsTest`. The proxy's spawn row belongs to 4.f; this step adds no proc_open.
- **Serialized:** [bootstrap:egressProxy (new), tools] [lang:settings.bashSandbox., settings.sandboxAllowedDomains.] [docs:PERMISSIONS.md] [docs:SETTINGS.md] [docs:README.md] [hot:candy-code/src/Cli/LaunchContext.php] …
- **Deps:** C-TOOL-4.c (W34), C-TOOL-4.f (W34)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-4.g`

#### C-TOOL-6.j — PTY relay core for interactive background shells
- **Item:** C-TOOL-6 (sources: GAP-SH-01) · gap · effort M
- **What:** **Why a relay.** A pty master is an fd in the process that opened it. Bash runs in a tool or turn child that exits when the call returns, so the master has to live in a process of its own that outlives every call. The same reason …
- **Files — create:** `candy-code/src/Background/PtyRelay.php`, `candy-code/tests/Sessions/PtyRelayTest.php`, `candy-code/tests/Sessions/BackgroundShellInteractiveTest.php`
- **Files — modify:** `candy-code/src/Background/BackgroundShellRegistry.php`, `candy-code/tests/Support/DescriptorInheritanceGuardTest.php`, `candy-code/tests/Support/ReadPathCensusTest.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Sessions/BackgroundShellRegistryTest.php`
- **Docs/guards:** `DescriptorInheritanceGuardTest` (the row), `ChildStderrCaptureTest` (the relay's stderr is a file sink, never discarded), `ReadPathCensusTest`, `tools/check-one-type-per-file.php`. No user docs, …
- **Serialized:** [hot:candy-code/src/Background/BackgroundShellRegistry.php] [hot:candy-code/tests/Support/DescriptorInheritanceGuardTest.php] [hot:candy-code/tests/Support/ReadPathCensusTest.php]
- **Deps:** C-TOOL-3.c (W13), C-TOOL-4.h (W34)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-6.j`

#### C-TUI-6.g — Web i18n: migrate the remaining components
- **Item:** C-TUI-6 (sources: GAP-PLAT-10, p2-miss: WEB-7c i18n) · gap · effort M
- **What:** Mechanical migration of the other 39 `.vue` files under `src-web` (43 in total, minus the 4 done in C-TUI-6.f) to `t()`. Key prefixes by area: `web.composer.*`, `web.transcript.*`, `web.panels.*`, `web.agents.*`, …
- **Files — create:** none
- **Files — modify:** `candy-code-web/src-web/**/*.vue`, `candy-code-web/src-web/components/*.vue`, `candy-code-web/src-web/components/{agents,approvals,grid,panels,settings}/*.vue`, `candy-code-web/src-web/views/*.vue`, `candy-code-web/src-web/components/Composer.vue`, `candy-code/lang/en.php`, `candy-code/docs/protocol/web-strings.en.json`, `candy-code-web/src-web/lib/strings.en.generated.ts`
- **Tests — create:** `src-web/__tests__/i18nCoverage.spec.ts`, `candy-code-web/src-web/components/__tests__/*.spec.ts` · **modify:** none
- **Docs/guards:** WebStringsDriftTest; vitest; dist rebuild at weld
- **Serialized:** [lang:web.] [hot:candy-code-web/src-web/components/Composer.vue] [hot:candy-code-web/src-web — nearly every component; run as the ONLY web lane in its wave]
- **Deps:** C-TUI-6.k (W13), C-TUI-6.f (W35)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-6.g`

#### C-WEB-5.d — Web Push wiring: `push.*` RPCs, subscription store, triggers
- **Item:** C-WEB-5 (sources: GAP-WEB-10, p2-miss: WEB-8 web push) · gap · M
- **What:** **New RPCs** (`PushMethods`): `push.vapidKey` (read): the public key; `push.subscribe {endpoint, keys}` (write); `push.unsubscribe {endpoint}` (write). **Store.** `PushSubscriptionStore` keeps rows 0600 in StateDir, keyed by …
- **Files — create:** `candy-code/src/Protocol/Methods/PushMethods.php`, `candy-code/src/Server/Push/PushSubscriptionStore.php`, `candy-code/tests/Protocol/PushMethodsTest.php`, `candy-code/tests/Server/Push/PushSubscriptionStoreTest.php`
- **Files — modify:** `candy-code/src/Protocol/Dispatcher.php`, `candy-code/src/Protocol/Schema/MethodSchemas.php`, `candy-code/src/Protocol/ServerContext.php`, `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts`
- **Tests — create:** `candy-code/tests/Protocol/PushMethodsTest.php`, `candy-code/tests/Server/Push/PushSubscriptionStoreTest.php` · **modify:** none
- **Docs/guards:** the protocol gates; `tests/Config/ServerProtocolDocumentationDriftTest.php`; `docs/SERVER.md`; `dormantRoster.spec.ts` (C-WEB-3.g) must list `push.*` as dormant until C-WEB-5.e lands, or this lane …
- **Serialized:** [protocol] [hot:candy-code/src/Protocol/ServerContext.php] [docs:SERVER.md]
- **Deps:** C-WEB-5.c (W35)
- **Ruling:** R13 — default push payload (redacted generic text vs session titles) and whether serve may make outbound requests to third-party push services at all. — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-web.md` → `### C-WEB-5.d`

### W37 — LATE

- Bootstrap.php method claims: C-TOOL-6.e (userScriptTools(new)); C-EXT-6.d (hooks)

#### C-TOOL-6.e — User-tier script tools from `~/.candy-code/tools/*.json`
- **Item:** C-TOOL-6 (sources: GAP-PLG-04) · gap · effort M
- **What:** **Where they come from.** User tier only: `HomeDirectory::owned() . '/.candy-code/tools/*.json'`. A project `.candy-code/tools/` is never read. **File shape.** One tool per file: …
- **Files — create:** `candy-code/src/Tools/UserTools/UserScriptTool.php`, `candy-code/src/Tools/UserTools/UserToolLoader.php`, `candy-code/src/Tools/UserTools/UserToolSpec.php`, `candy-code/tests/Tools/UserTools/UserToolLoaderTest.php`, `candy-code/tests/Tools/UserTools/UserScriptToolTest.php`, `candy-code/tests/Permissions/UserToolPermissionTest.php`
- **Files — modify:** `candy-code/src/Cli/Bootstrap.php`, `candy-code/src/Cli/LaunchContext.php`, `candy-code/src/Runtime.php`, `candy-code/src/Permissions/PermissionGate.php`, `candy-code/lang/en.php`, `candy-code/docs/PLUGINS.md`, `candy-code/docs/PERMISSIONS.md`, `candy-code/tests/Support/ReadPathCensusTest.php`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** `ReadPathCensusTest` (`OWNED_HOME`), `LangParityTest`, `tools/check-one-type-per-file.php`. `DescriptorInheritanceGuardTest` and `ChildStderrCaptureTest` are unchanged: `runCaptured()` is an …
- **Serialized:** [bootstrap:userScriptTools(new)] [runtime:USER_TOOL_PREFIX(new),isWriteCapableTool] [lang:cli.usertools.] [docs:PLUGINS.md] [docs:PERMISSIONS.md] [hot:candy-code/src/Cli/LaunchContext.php] …
- **Deps:** C-TOOL-4.b (W33)
- **Ruling:** May user-tier JSON define model-callable exec tools (recommended: yes — user tier only, write class, `auto` asks)? May plugin bundles ship them (recommended: … — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-6.e`

#### C-EXT-6.d — `type: prompt` hooks (LATE)
- **Item:** C-EXT-6 (sources: GAP-HK-03, p1-miss CC hook `type: prompt`) · gap · effort M
- **What:** **`PromptHook`.** Implements HookInterface, deliberately not Bounded. Under E646 the verdict call carries only the provider connect timeout, as `ExecReviewer` does. It makes one tool-less completion on the title backend: a fixed …
- **Files — create:** `candy-code/src/Hooks/PromptHook.php`
- **Files — modify:** `candy-code/src/Hooks/HookConfig.php`, `candy-code/src/Hooks/HookManager.php`, `candy-code/src/Hooks/ClaudeSettingsHooks.php`, `candy-code/src/Cli/Bootstrap.php`, `candy-code/lang/en.php`, `candy-code/docs/HOOKS.md`
- **Tests — create:** `candy-code/tests/Hooks/PromptHookTest.php`, `candy-code/tests/Cli/BootstrapPromptHookWiringTest.php` · **modify:** `candy-code/tests/Hooks/HookConfigTest.php`, `candy-code/tests/Hooks/ClaudeSettingsHooksTest.php`, `candy-code/tests/Config/DocFigureProseDriftTest.php`
- **Docs/guards:** the HOOKS.md entry-key pin.
- **Serialized:** [bootstrap:hooks] [docs:HOOKS.md] [lang:hooks.prompt.] [hot:candy-code/src/Hooks/HookConfig.php] [hot:candy-code/src/Hooks/HookManager.php] [hot:candy-code/src/Hooks/ClaudeSettingsHooks.php]
- **Deps:** C-EXT-6.c (W33)
- **Brief:** `prompt_kit/findings/comparison-1026/design-ext.md` → `### C-EXT-6.d`

#### C-WEB-5.e — Web Push client: service worker + consent toggle
- **Item:** C-WEB-5 (sources: GAP-WEB-10, p2-miss: WEB-8 web push) · gap · M
- **What:** **Service worker.** `sw.ts` shows a notification on `push` and focuses or opens the session on click. It is emitted at the dist root by `vite.config.ts`. **Consent.** A "Notify me on this device" toggle in SettingsView's client …
- **Files — create:** `candy-code-web/src-web/sw.ts`, `candy-code-web/src-web/stores/push.ts`, `candy-code-web/src-web/stores/__tests__/push.spec.ts`
- **Files — modify:** `candy-code-web/vite.config.ts`, `candy-code-web/src-web/components/settings/SettingsView.vue`, `candy-code/src/Server/Http/StaticFiles.php`
- **Tests — create:** `candy-code-web/src-web/stores/__tests__/push.spec.ts`, `candy-code/tests/Server/StaticFilesServiceWorkerTest.php` · **modify:** `candy-code-web/src-web/__tests__/pwa.spec.ts`
- **Docs/guards:** `candy-code-web/README.md` (soft); vitest; dist rebuild; `dormantRoster.spec.ts` drops `push.*`.
- **Serialized:** [hot:candy-code-web/src-web/components/settings/SettingsView.vue]
- **Deps:** C-WEB-5.d (W36)
- **Ruling:** R13 (as C-WEB-5.d). — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-web.md` → `### C-WEB-5.e`

### W38 — LATE

#### C-TUI-6.a — Windows process layer (bash quoting, bash resolution, tree kill)
- **Item:** C-TUI-6 (sources: GAP-PLAT-04, p2-miss: Windows support (×2)) · gap · effort M
- **What:** Three root causes break every shell tool on NT before any PTY is involved: **Quoting.** The bash-script builders use `escapeshellarg()`, which is host-dependent: on Windows it emits `"…"` and blanks `%`, `!` and `"`. The sites …
- **Files — create:** `candy-code/src/Support/PosixShellQuote.php`, `candy-code/src/Support/WindowsShell.php`, `candy-code/src/Support/WindowsTaskKill.php`
- **Files — modify:** `candy-code/src/Support/ProcessContainment.php`, `candy-code/src/Tools/BuiltIn/Bash.php`, `candy-code/src/Background/BackgroundShellRegistry.php`, `candy-code/tests/Support/DescriptorInheritanceGuardTest.php`, `candy-code/tests/Support/ReadPathCensusTest.php`
- **Tests — create:** `candy-code/tests/Support/PosixShellQuoteTest.php`, `candy-code/tests/Support/WindowsShellTest.php`, `candy-code/tests/Support/WindowsTaskKillTest.php`, `candy-code/tests/Tools/BuiltIn/BashWindowsSpecTest.php`, `candy-code/tests/Sessions/BackgroundShellWindowsArmTest.php` · **modify:** `tests/Support/ProcessTreeKillTest.php`, `tests/Support/ProcessTreeKillAsyncTest.php`, `tests/Sessions/BackgroundShellRegistryTest.php`, `tests/Tools/BuiltIn/BashTimeoutTest.php`
- **Docs/guards:** DescriptorInheritanceGuardTest is candy-code's own child-lifetime roster. `tools/check-child-lifetimes.php` skips candy-code and must stay green. ReadPathCensusTest. …
- **Serialized:** [hot:candy-code/src/Support/ProcessContainment.php] [hot:candy-code/src/Background/BackgroundShellRegistry.php] [hot:candy-code/src/Tools/BuiltIn/Bash.php] …
- **Deps:** C-TOOL-4.a (W14), C-TOOL-4.b (W33), C-TOOL-4.c (W34), C-TOOL-4.f (W34), C-TOOL-4.h (W34), C-TOOL-4.d (W35), C-TOOL-4.e (W36), C-TOOL-4.g (W36)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-6.a`

#### C-TOOL-6.h — Client-hosted tools, engine + host core (`client__*` proxies over the turn channel)
- **Item:** C-TOOL-6 (sources: OC-new#2) · gap · effort M
- **What:** **Shape.** An attached client registers tool descriptors for one session. The next turn offers each one as `client__<name>`. A call travels turn child → serve parent → that client, and the answer comes back the same way. This …
- **Files — create:** `candy-code/src/Host/ClientTools/ClientToolSpec.php`, `candy-code/src/Host/ClientTools/ClientToolRegistry.php`, `candy-code/src/Host/ClientTools/ClientToolProxy.php`, `candy-code/src/Tools/RelaysClientToolCalls.php`, `candy-code/src/Backend/PendingClientCall.php`, `candy-code/tests/Host/ClientTools/ClientToolRegistryTest.php`, `candy-code/tests/Host/ClientTools/ClientToolProxyTest.php`, `candy-code/tests/Backend/ForkChannelClientCallRoundTripTest.php`, `candy-code/tests/Permissions/ClientToolPermissionTest.php`
- **Files — modify:** `candy-code/src/Backend/ChildChannel.php`, `candy-code/src/Backend/EngineBackend.php`, `candy-code/src/Host/SessionHost.php`, `candy-code/src/Host/TurnRunner.php`, `candy-code/src/Runtime.php`, `candy-code/src/Permissions/PermissionGate.php`, `candy-code/src/Tools/Catalog/ToolDocGenerator.php`, `candy-code/docs/PERMISSIONS.md`, `candy-code/tests/RuntimeTest.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Host/SessionHostTest.php`
- **Docs/guards:** `ToolDocDriftTest` (`php tools/gen-tool-docs.php --write` moves only PERMISSIONS.md's `tools:classes` line), RuntimeTest's prefix and write-roster pins, `tools/check-one-type-per-file.php`. No …
- **Serialized:** [runtime:CLIENT_TOOL_PREFIX(new),isWriteCapableTool] [docs:PERMISSIONS.md] [hot:candy-code/src/Backend/EngineBackend.php] [hot:candy-code/src/Backend/ChildChannel.php] …
- **Deps:** C-TOOL-6.e (W37)
- **Ruling:** Which client may register tools, and is the feature off by default? Recommended: any client holding Write scope on the session may register, and its … — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-6.h`

### W39 — LATE

- protocol lanes: C-TOOL-6.i (weld regenerates schema → SERVER.md → generated.ts once)

#### C-TUI-6.l — Voice core: `Transcriber`, `AudioRecorder`, voice settings
- **Item:** C-TUI-6 (sources: GAP-MOD-23, OC-new#10) · gap · effort M
- **What:** `src/Media/Voice/` does not exist. This step builds the core with no key or protocol wiring. **`AudioRecorder`.** It picks `arecord -q -f S16_LE -r 16000 -c 1 -d <max> <wav>`, else `sox -q -d -r 16000 -c 1 -b 16 <wav> trim 0 …
- **Files — create:** `candy-code/src/Media/Voice/VoiceConfig.php`, `candy-code/src/Media/Voice/AudioRecorder.php`, `candy-code/src/Media/Voice/AudioRecording.php`, `candy-code/src/Media/Voice/VoiceSession.php`, `candy-code/src/Media/Voice/Transcriber.php`, `candy-code/src/Media/Voice/TranscriptionException.php`, `candy-code/src/Media/Voice/TranscribeCmd.php`, `candy-code/src/App/VoiceTranscribedMsg.php`, `candy-code/src/App/VoiceFailedMsg.php`
- **Files — modify:** `candy-code/src/Config/Settings/Definitions/MediaSettings.php`, `candy-code/src/Config/LayeredSettings.php`, `candy-code/lang/en.php`, `candy-code/docs/SETTINGS.md`, `candy-code/docs/MEDIA.md`, `candy-code/tests/Support/ReadPathCensusTest.php`, `candy-code/tests/Support/DescriptorInheritanceGuardTest.php`
- **Tests — create:** `candy-code/tests/Media/Voice/` · **modify:** none
- **Docs/guards:** SettingsSchemaDocDriftTest; ReadmeRosterDriftTest; DocFigureProseDriftTest; ReadPathCensusTest; DescriptorInheritanceGuardTest; `tools/check-one-type-per-file.php`
- **Serialized:** [lang:settings.voice., media.voice.] [docs:SETTINGS.md] [docs:README.md] [docs:MEDIA.md] [hot:candy-code/src/Config/Settings/Definitions/MediaSettings.php] …
- **Deps:** none
- **Ruling:** the voice endpoint/egress default. Proposed: no default endpoint, and voice stays off until the user tier sets `voice.baseUrl`. Audio never leaves the machine … — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-6.l`

#### C-TOOL-6.k — `ShellSend` tool, Bash interactive background runs, `/shells send`
- **Item:** C-TOOL-6 (sources: GAP-SH-01) · gap · effort M
- **What:** **Bash.** The refusal at `Bash.php:515-521` becomes a route. `run_in_background` + `interactive` now calls `startInteractive()` (6.j) with the same sandbox-prefixed command string the plain background path uses, so `bashSandbox` …
- **Files — create:** `candy-code/src/Tools/BuiltIn/ShellSend.php`, `candy-code/tests/Tools/ShellSendTest.php`, `candy-code/tests/Tools/BashInteractiveBackgroundTest.php`, `candy-code/tests/Permissions/ShellSendPermissionTest.php`
- **Files — modify:** `candy-code/src/Tools/BuiltIn/Bash.php`, `candy-code/src/Tools/BuiltIn/ShellOutput.php`, `candy-code/src/Runtime.php`, `candy-code/src/Permissions/PermissionGate.php`, `candy-code/src/Host/Commands/ShellsCommand.php`, `candy-code/lang/en.php`, `candy-code/docs/PERMISSIONS.md`, `candy-code/README.md`, `candy-code/docs/ARCHITECTURE.md`, `candy-code/docs/AGENTS_AUTHORING.md`, `candy-code/docs/SETTINGS.md`, `candy-code/docs/COMMANDS.md`, `candy-code/tests/Config/ReadmeRosterDriftTest.php`, `candy-code/tests/Config/ReadmeSettingsTierClaimTest.php`, `candy-code/tests/Config/DocFigureProseDriftTest.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Tools/BashBackgroundTest.php`, `candy-code/tests/Tools/ShellOutputTest.php`, `candy-code/tests/Host/Commands/ShellsCommandTest.php`
- **Docs/guards:** `ToolDocDriftTest`, `CommandDocsDriftTest`, `PermissionsIntroDriftTest` (PERMISSIONS.md:760), RuntimeTest's write-roster drift test (same commit), the three word-map tests, …
- **Serialized:** [runtime:WRITE_CAPABLE_TOOL_NAMES] [catalog] [lang:cmd.shells.,host.shells.send.] [docs:README.md] [docs:ARCHITECTURE.md] [docs:AGENTS_AUTHORING.md] [docs:PERMISSIONS.md] [docs:SETTINGS.md] …
- **Deps:** C-TOOL-3.d (W14), C-TOOL-6.j (W36)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-6.k`

#### C-TOOL-6.i — Client-hosted tools: `tools.client.*` protocol, `server.clientTools` gate, web store + sample tool
- **Item:** C-TOOL-6 (sources: OC-new#2) · gap · effort M
- **What:** **Gate (per 6.h's ruling).** New user-config-only setting `server.clientTools` (Bool, default false, `SettingCategory::Server`, `RiskClass::Security`), defined beside `server.dirBrowse` (`ServerSettings.php:133`). …
- **Files — create:** `candy-code/src/Protocol/Methods/ClientToolMethods.php`, `candy-code/tests/Protocol/ClientToolMethodsTest.php`, `candy-code-web/src-web/stores/clientTools.ts`, `candy-code-web/src-web/lib/clientTools/browserContext.ts`, `candy-code-web/src-web/stores/__tests__/clientTools.spec.ts`
- **Files — modify:** `candy-code/src/Config/Settings/Definitions/ServerSettings.php`, `candy-code/src/Server/ServerConfig.php`, `candy-code/lang/en.php`, `candy-code/src/Protocol/Dispatcher.php`, `candy-code/src/Protocol/Schema/MethodSchemas.php`, `candy-code/src/Protocol/Schema/EventSchemas.php`, `candy-code/src/Protocol/EventType.php`, `candy-code/src/Host/SessionEvent.php`, `candy-code/src/Protocol/ServerContext.php`, `candy-code/docs/SETTINGS.md`, `candy-code/README.md`, `candy-code/docs/SERVER.md`, `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code-web/src-web/protocol/generated.ts`, `candy-code-web/src-web/stores/session.ts`, `candy-code-web/README.md`
- **Tests — create:** none · **modify:** `tests/Server/Support/ProtocolFixture.php`, `candy-code/tests/Cli/ServeArgsTest.php`, `candy-code/tests/Protocol/EventTypeTest.php`
- **Docs/guards:** `ProtocolSchemaDriftTest`, `ServerProtocolDocumentationDriftTest`, `SettingsSchemaDocDriftTest`, `TrustKeyDocumentationDriftTest` (a Security-class key), `LangParityTest`, …
- **Serialized:** [protocol] [lang:settings.server.clientTools.] [docs:SETTINGS.md] [docs:README.md] [docs:SERVER.md] [hot:candy-code/src/Protocol/ServerContext.php] [hot:candy-code/src/Server/ServerConfig.php] …
- **Deps:** C-TOOL-6.h (W38)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-6.i`

#### C-TUI-6.d — candy-code on Windows: PtyRun / interactive Bash over ConPTY, README banner, CI smoke job
- **Item:** C-TUI-6 (sources: GAP-PLAT-04, p2-miss: Windows support (×2)) · gap · effort M
- **What:** **The PTY opener.** candy-code opens PTYs through the deprecated POSIX-only facade `\SugarCraft\Pty\Pty::open()`, in `PtyDriver.php:116` and `CapturesProcessOutput.php:518`. A new `PtyBackend::open($cols, $rows)` returns a …
- **Files — create:** `candy-code/src/Support/PtyBackend.php`
- **Files — modify:** `candy-code/src/Support/PtyDriver.php`, `candy-code/src/Tools/Concerns/CapturesProcessOutput.php`, `candy-code/src/Support/ProcessContainment.php`, `candy-code/README.md`, `candy-code/docs/SERVER.md`, `.github/workflows/ci.yml`
- **Tests — create:** `candy-code/tests/Support/PtyBackendTest.php`, `candy-code/tests/Windows/WindowsBashSmokeTest.php`, `candy-code/tests/Windows/WindowsPtyRunSmokeTest.php` · **modify:** `tests/Tools/PtyRunToolTest.php`, `tests/Support/PtyDriverRetainTest.php`, `tests/Permissions/PtyRunBashPermissionParityTest.php`
- **Docs/guards:** DescriptorInheritanceGuardTest: the `ACCOUNTED_FOR_IN_LIBS` rows follow if the sugar-pty site set changes. ReadmeRosterDriftTest: the README is touched outside the rosters. The new tests are listed …
- **Serialized:** [workflows] [docs:README.md] [docs:SERVER.md] [hot:candy-code/src/Support/ProcessContainment.php] [hot:candy-code/src/Tools/Concerns/CapturesProcessOutput.php]
- **Deps:** C-TUI-6.c (W35), C-TUI-6.a (W38)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-6.d`

### W40 — LATE

- protocol lanes: C-TUI-6.n (weld regenerates schema → SERVER.md → generated.ts once)

#### C-TUI-6.e — Windows self-update relaunch swap
- **Item:** C-TUI-6 (sources: p2-miss: Windows support (×2)) · gap · effort S
- **What:** `SelfUpdate::run()` ends with `rename($temp, $phar)` (`SelfUpdate.php:451`), which fails on NT while the phar is the running image. The WINDOWS CAVEAT (L71-78) leaves the user with a manual file replace "until someone rules a …
- **Files — create:** `candy-code/src/Cli/WindowsSwap.php`
- **Files — modify:** `candy-code/src/Cli/SelfUpdate.php`, `candy-code/lang/en.php`, `candy-code/tests/Support/DescriptorInheritanceGuardTest.php`, `candy-code/tests/Support/ReadPathCensusTest.php`
- **Tests — create:** `candy-code/tests/Cli/WindowsSwapTest.php` · **modify:** `candy-code/tests/Cli/SelfUpdateTest.php`
- **Docs/guards:** README's self-update paragraph, if it states the manual replace; DescriptorInheritanceGuardTest; ReadPathCensusTest
- **Serialized:** [lang:cli.selfupdate.staged_windows, cli.selfupdate.swap_] [hot:candy-code/src/Cli/SelfUpdate.php] [hot:candy-code/tests/Support/DescriptorInheritanceGuardTest.php] …
- **Deps:** C-SES-5.a (W2), C-SES-5.b (W3), C-TUI-6.a (W38)
- **Ruling:** confirm that the detached `PHP_BINARY -r` helper is the relaunch dance `SelfUpdate.php:76-78` waits on. Proposed: yes; this step is that dance. — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-6.e`

#### C-TUI-6.m — TUI push-to-talk wiring
- **Item:** C-TUI-6 (sources: GAP-MOD-23) · gap · effort M
- **What:** Terminals deliver no key-up, so "push-to-talk" is a toggle. Alt+V starts recording, through an arm before the generic Char arm in `HandlesKeys.php`, beside Alt+M at ~L959. Alt+V again stops it and returns `TranscribeCmd::cmd()`. …
- **Files — create:** none
- **Files — modify:** `candy-code/src/Commands/KeyBindingRegistry.php`, `candy-code/src/Chat/Handlers/HandlesKeys.php`, `candy-code/src/Chat/Handlers/HandlesSessionSurface.php`, `candy-code/src/Renderer.php`, `candy-code/lang/en.php`, `candy-code/README.md`, `candy-code/docs/MEDIA.md`
- **Tests — create:** `candy-code/tests/Chat/VoicePushToTalkTest.php` · **modify:** none
- **Docs/guards:** KeyBindingDriftTest; RouteTraitParityTest (the two new Msg arms); ReadmeRosterDriftTest
- **Serialized:** [hot:candy-code/src/Commands/KeyBindingRegistry.php] [hot:candy-code/src/Chat/Handlers/HandlesKeys.php] [hot:candy-code/src/Chat/Handlers/HandlesSessionSurface.php] …
- **Deps:** C-TUI-6.l (W39)
- **Ruling:** the chord. Proposed: Alt+V, which is free in `KeyBindingRegistry`; Ctrl+V is `chat.paste-image` (L923), and the permission prompt's bare `v` is not a chord. — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-6.m`

#### C-TUI-6.n — Web MediaRecorder + `audio.transcribe` + the `canTranscribe` flag
- **Item:** C-TUI-6 (sources: GAP-MOD-23, GAP-WEB-13, OC-new#10) · gap · effort M
- **What:** **Server.** `MediaMethods::register()` (L91-111) gains `audio.transcribe`: Write scope, `{audioBase64, mime, language?}`, decoded size ≤ 8 MiB (under `MAX_CLIENT_FRAME_BYTES`). It writes a 0600 temp, forks the C-TUI-6.l …
- **Files — create:** `candy-code-web/src-web/lib/recorder.ts`
- **Files — modify:** `candy-code/src/Protocol/Methods/MediaMethods.php`, `candy-code/src/Protocol/Schema/MethodSchemas.php`, `candy-code/src/Media/Capability/MediaAffordance.php`, `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts`, `candy-code/docs/MEDIA.md`, `candy-code-web/src-web/stores/connection.ts`, `candy-code-web/src-web/lib/dictation.ts`, `candy-code-web/src-web/components/Composer.vue`, `candy-code/lang/en.php`
- **Tests — create:** `candy-code/tests/Protocol/AudioTranscribeMethodTest.php`, `candy-code-web/src-web/lib/__tests__/recorder.spec.ts`, `candy-code-web/src-web/components/__tests__/composerVoice.spec.ts` · **modify:** `candy-code/tests/Media/MediaAffordanceTest.php`, `candy-code-web/src-web/stores/__tests__/connectionMedia.spec.ts`
- **Docs/guards:** ProtocolSchemaDriftTest; ServerProtocolDocumentationDriftTest; both protocol gens `--check`; WebStringsDriftTest; vitest; dist rebuild at weld
- **Serialized:** [protocol] [docs:SERVER.md] [docs:MEDIA.md] [lang:web.composer.voice.] [hot:candy-code-web/src-web/components/Composer.vue] [hot:candy-code-web/src-web/stores/connection.ts]
- **Deps:** C-TUI-6.k (W13), C-TUI-6.g (W36), C-TUI-6.l (W39)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-6.n`

#### C-TOOL-6.d — `Script` catalog tool: nested calls through the gate and hook chain
- **Item:** C-TOOL-6 (sources: GAP-TL-N3, OC-new#12 (Code Mode)) · gap · effort M
- **What:** **Tool.** `ScriptTool`: `#[BuiltInTool(name: 'Script', permission: Write, position: 44, gloss: 'run a short PHP script whose only effects are tool calls, each permissioned like a direct call')]`. Args: `code` (≤ 64 KiB), …
- **Files — create:** `candy-code/src/Tools/BuiltIn/ScriptTool.php`, `candy-code/src/Tools/DispatchesNestedTools.php`, `candy-code/tests/Tools/BuiltIn/ScriptToolTest.php`, `candy-code/tests/Runtime/NestedToolDispatchTest.php`
- **Files — modify:** `candy-code/src/Runtime.php`, `candy-code/docs/PERMISSIONS.md`, `candy-code/README.md`, `candy-code/docs/ARCHITECTURE.md`, `candy-code/docs/AGENTS_AUTHORING.md`, `candy-code/docs/SETTINGS.md`, `candy-code/tests/Config/ReadmeRosterDriftTest.php`, `candy-code/tests/Config/ReadmeSettingsTierClaimTest.php`, `candy-code/tests/Config/DocFigureProseDriftTest.php`
- **Tests — create:** none · **modify:** none
- **Docs/guards:** `ToolDocDriftTest`, `PermissionsIntroDriftTest` (PERMISSIONS.md:760), RuntimeTest's `testTheWriteToolRosterDoesNotDriftFromThePermissionGate` (same commit), the three word-map tests, …
- **Serialized:** [runtime:WRITE_CAPABLE_TOOL_NAMES,executeSequentially,executeNested(new)] [catalog] [docs:README.md] [docs:ARCHITECTURE.md] [docs:AGENTS_AUTHORING.md] [docs:PERMISSIONS.md] [docs:SETTINGS.md] …
- **Deps:** C-TOOL-6.c (W35)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-6.d`

### W41 — LATE

- protocol lanes: C-TOOL-6.l (weld regenerates schema → SERVER.md → generated.ts once)

#### C-TOOL-6.b — `NotebookEdit` tool + Read renders `.ipynb` as cell pages
- **Item:** C-TOOL-6 (sources: GAP-TL-15) · gap · effort M
- **What:** **Model.** New `Notebook\Notebook` handles nbformat v4. `parse()` decodes with `json_decode(..., false)`, so `{}` and `[]` survive the round trip. Cells are addressed by their nbformat ≥4.5 `id`. In an id-less notebook they are …
- **Files — create:** `candy-code/src/Notebook/Notebook.php`, `candy-code/src/Notebook/NotebookPages.php`, `candy-code/src/Tools/BuiltIn/NotebookEdit.php`, `candy-code/tests/Notebook/NotebookTest.php`, `candy-code/tests/Tools/BuiltIn/NotebookEditTest.php`, `candy-code/tests/Tools/BuiltIn/ReadNotebookTest.php`, `candy-code/tests/Permissions/NotebookEditPermissionTest.php`, `candy-code/tests/fixtures/notebooks/`
- **Files — modify:** `candy-code/src/Tools/BuiltIn/Read.php`, `candy-code/src/Runtime.php`, `candy-code/src/Tools/ToolLimits.php`, `candy-code/src/Permissions/PermissionRule.php`, `candy-code/src/Permissions/PermissionGate.php`, `candy-code/src/Permissions/SafetyClassifier.php`, `candy-code/src/Hooks/BuiltIn/ProtectFilesHook.php`, `candy-code/src/Context/Compaction/FilesTouched.php`, `candy-code/src/Context/Pruning/Strategies/StaleReadStrategy.php`, `candy-code/src/Context/Pruning/PruningPolicy.php`, `candy-code/src/Context/TurnContextBlock.php`, `candy-code/src/Agents/Live/ToolSummary.php`, `candy-code/docs/PERMISSIONS.md`, `candy-code/README.md`, `candy-code/docs/ARCHITECTURE.md`, `candy-code/docs/AGENTS_AUTHORING.md`, `candy-code/docs/SETTINGS.md`, `candy-code/tests/Config/ReadmeRosterDriftTest.php`, `candy-code/tests/Config/ReadmeSettingsTierClaimTest.php`, `candy-code/tests/Config/DocFigureProseDriftTest.php`, `candy-code/tests/Support/ReadPathCensusTest.php`
- **Tests — create:** none · **modify:** `candy-code/tests/Permissions/SafetyClassifierTest.php`
- **Docs/guards:** `ToolDocDriftTest` (`php tools/gen-tool-docs.php --write`). `PermissionsIntroDriftTest::testThePatternToolRosterIsTheWholeCatalog` (the hand roster at PERMISSIONS.md:760). RuntimeTest's …
- **Serialized:** [runtime:WRITE_CAPABLE_TOOL_NAMES] [catalog] [docs:README.md] [docs:ARCHITECTURE.md] [docs:AGENTS_AUTHORING.md] [docs:PERMISSIONS.md] [docs:SETTINGS.md] [hot:candy-code/src/Tools/BuiltIn/Read.php] …
- **Deps:** C-TOOL-2.d (W17), C-TOOL-5.a (W18)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-6.b`

#### C-TOOL-6.l — Web terminal input: `shells.input` + a polling terminal in the Processes section
- **Item:** C-TOOL-6 (sources: GAP-SH-05, p2-miss: WEB-8 Terminal panel (scoped down)) · gap · effort M
- **What:** **What this is, plainly.** A polling, line-oriented terminal over the session's interactive background shells. The browser reads the shell's output sink through `shells.output` on a timer and writes into the pty through …
- **Files — create:** `candy-code/tests/Protocol/ShellsInputTest.php`, `candy-code-web/src-web/stores/agents/__tests__/shellsInput.spec.ts`
- **Files — modify:** `candy-code/src/Protocol/Methods/ShellsMethods.php`, `candy-code/src/Protocol/Schema/MethodSchemas.php`, `candy-code/src/Protocol/ServerContext.php`, `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts`, `candy-code-web/src-web/stores/agents/shells.ts`, `candy-code-web/src-web/components/panels/ProcessesSection.vue`, `candy-code-web/src-web/lib/ansi.ts`, `candy-code-web/README.md`
- **Tests — create:** none · **modify:** `candy-code-web/src-web/components/panels/__tests__/processesSection.spec.ts`, `candy-code-web/src-web/lib/__tests__/ansi.spec.ts`
- **Docs/guards:** `ProtocolSchemaDriftTest`, `ServerProtocolDocumentationDriftTest` (regen in-step). No proc_open; no Lang keys (the refusals are protocol kinds).
- **Serialized:** [protocol] [docs:SERVER.md] [hot:candy-code/src/Protocol/ServerContext.php]
- **Deps:** C-TOOL-3.e (W17), C-TOOL-6.k (W39)
- **Brief:** `prompt_kit/findings/comparison-1026/design-tool.md` → `### C-TOOL-6.l`

#### C-TUI-6.h — Community locale `candy-code/lang/<code>.php`
- **Item:** C-TUI-6 (sources: GAP-PLAT-10) · gap · effort M
- **What:** Only `lang/en.php` ships, at 3,292 lines. `Lang::useLaunchLocale()` already picks `lang/<tag>.php` from `LC_ALL`/`LC_MESSAGES`/`LANG`, and C-TUI-6.f's `server.strings` serves the same file's `web.*` slice to the browser. Ship ONE …
- **Files — create:** none
- **Files — modify:** `candy-code/README.md`, `candy-code/docs/CONTRIBUTING.md`, `candy-code/tests/LangParityTest.php`
- **Tests — create:** `candy-code/tests/LangCommunityLocaleTest.php` · **modify:** none
- **Docs/guards:** LangParityTest; LangLaunchLocaleTest; the generated pages stay English because the generators run under `Lang::inLocale('en')`
- **Serialized:** [lang:whole file — reads every key] [docs:README.md]
- **Deps:** C-TUI-6.j (W34), C-TUI-6.g (W36), C-TUI-6.e (W40), C-TUI-6.m (W40), C-TUI-6.n (W40)
- **Ruling:** which locale, which translation source (machine translation plus native review, or a community PR), and which parity policy. Default: strict and land last. — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-tui.md` → `### C-TUI-6.h`

### W42 — LATE

- protocol lanes: C-WEB-5.f (weld regenerates schema → SERVER.md → generated.ts once)

#### C-WEB-5.f — `server.logs` tail RPC + About-modal Logs tab
- **Item:** C-WEB-5 (sources: GAP-WEB-N4 (logs), GAP-OBS-01) · gap · M
- **What:** **Lift.** Move `Serve::tail()` (`candy-code/src/Cli/Serve.php:1134`) into `Server\LogTail`. `serve logs` (:794-830) and a new `server.logs {lines?}` (Scope::Admin) share it. **Output.** The RPC returns flattened lines …
- **Files — create:** `candy-code/src/Server/LogTail.php`, `candy-code/tests/Server/LogTailTest.php`, `candy-code/tests/Protocol/ServerLogsTest.php`
- **Files — modify:** `candy-code/src/Cli/Serve.php`, `candy-code/src/Protocol/Methods/ServerMethods.php`, `candy-code/src/Protocol/Schema/MethodSchemas.php`, `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts`, `candy-code-web/src-web/components/AboutModal.vue`
- **Tests — create:** `candy-code/tests/Server/LogTailTest.php`, `candy-code/tests/Protocol/ServerLogsTest.php` · **modify:** `candy-code-web/src-web/components/__tests__/aboutModal.spec.ts`
- **Docs/guards:** the protocol gates; `tests/Support/ReadPathCensusTest.php` (the tail read sink moves from Serve.php to LogTail.php in-step); `tests/Cli/StderrEmitterCensusTest.php` if `serve logs` output paths move.
- **Serialized:** [protocol] [hot:candy-code/src/Cli/Serve.php] [hot:candy-code-web/src-web/components/AboutModal.vue]
- **Deps:** C-SES-3.a (W1), C-SES-3.b (W2), C-SES-3.c (W2), C-SES-3.d (W13)
- **Ruling:** R13 — `ErrorLog`'s docblock says log lines "go to the 0600 server.log and never onto the wire". Does the owner accept Admin-scope log lines on the wire? — see §6
- **Brief:** `prompt_kit/findings/comparison-1026/design-web.md` → `### C-WEB-5.f`

## 5. Item index

Every picked item, its steps and the waves they land in.

| Item | Steps (wave) |
|---|---|
| P-AGENT | P-AGENT.a (W6), P-AGENT.b (W17), P-AGENT.c (W7), P-AGENT.d (W8) |
| P-CTX | P-CTX.a (W4), P-CTX.b (W5), P-CTX.c (W5), P-CTX.d (W5), P-CTX.e (W7), P-CTX.f (W6), P-CTX.g (W7) |
| P-IMG | P-IMG.b (W4), P-IMG.c (W5) |
| P-MENU | P-MENU.a (W5), P-MENU.b (W6), P-MENU.c (W6), P-MENU.d (W8), P-MENU.e (W9) |
| P-TABS | P-TABS.a (W5), P-TABS.b (W4), P-TABS.c (W6), P-TABS.d (W4), P-TABS.e (W14), P-TABS.f (W15), P-TABS.g (W16), P-TABS.h (W5) |
| C-AGT-1 | C-AGT-1.a (W1), C-AGT-1.b (W6), C-AGT-1.c (W1) |
| C-AGT-2 | C-AGT-2.a (W1), C-AGT-2.b (W7), C-AGT-2.c (W2) |
| C-AGT-3 | C-AGT-3.a (W6), C-AGT-3.b (W7) |
| C-AGT-4 | C-AGT-4.a (W14), C-AGT-4.b (W18), C-AGT-4.c (W22), C-AGT-4.d (W22), C-AGT-4.e (W23) |
| C-AGT-5 | C-AGT-5.a (W13), C-AGT-5.b (W14), C-AGT-5.c (W15), C-AGT-5.d (W20), C-AGT-5.e (W15), C-AGT-5.f (W23), C-AGT-5.g (W15), C-AGT-5.h (W19) |
| C-AGT-6 | C-AGT-6.a (W19), C-AGT-6.b (W13), C-AGT-6.c (W20), C-AGT-6.d (W15), C-AGT-6.e (W24) |
| C-AGT-7 | C-AGT-7.a (W7), C-AGT-7.b (W10), C-AGT-7.c (W9) |
| C-CTX-1 | C-CTX-1.a (W3), C-CTX-1.b (W3), C-CTX-1.c (W10) |
| C-CTX-2 | C-CTX-2.a (W8), C-CTX-2.b (W6), C-CTX-2.c (W9), C-CTX-2.d (W16), C-CTX-2.e (W10), C-CTX-2.f (W19) |
| C-CTX-3 | C-CTX-3.a (W20), C-CTX-3.b (W19), C-CTX-3.c (W21), C-CTX-3.d (W20), C-CTX-3.e (W21), C-CTX-3.f (W22) |
| C-CTX-4 | C-CTX-4.a (W20), C-CTX-4.b (W22), C-CTX-4.c (W15), C-CTX-4.d (W23), C-CTX-4.e (W23), C-CTX-4.f (W21), C-CTX-4.g (W21) |
| C-CTX-5 | C-CTX-5.a (W20), C-CTX-5.b (W24), C-CTX-5.c (W17), C-CTX-5.d (W25) |
| C-CTX-6 | C-CTX-6.a (W10), C-CTX-6.b (W11), C-CTX-6.c (W9), C-CTX-6.d (W9), C-CTX-6.e (W26) |
| C-EXT-1 | C-EXT-1.a (W1), C-EXT-1.b (W2) |
| C-EXT-2 | C-EXT-2.a (W6), C-EXT-2.b (W8), C-EXT-2.c (W10), C-EXT-2.d (W11), C-EXT-2.e (W13) |
| C-EXT-3 | C-EXT-3.a (W22), C-EXT-3.b (W20), C-EXT-3.c (W15), C-EXT-3.d (W20), C-EXT-3.e (W21) |
| C-EXT-4 | C-EXT-4.a (W14), C-EXT-4.b (W16), C-EXT-4.c (W20), C-EXT-4.d (W21) |
| C-EXT-5 | C-EXT-5.a (W16), C-EXT-5.b (W21), C-EXT-5.c (W21) |
| C-EXT-6 | C-EXT-6.a (W22), C-EXT-6.b (W33), C-EXT-6.c (W33), C-EXT-6.d (W37) |
| C-EXT-7 | C-EXT-7.a (W9), C-EXT-7.b (W7) |
| C-MCP-1 | C-MCP-1.a (W1), C-MCP-1.b (W3) |
| C-MCP-2 | C-MCP-2.a (W11), C-MCP-2.b (W12), C-MCP-2.c (W23) |
| C-MCP-3 | C-MCP-3.a (W13), C-MCP-3.b (W16), C-MCP-3.c (W22), C-MCP-3.d (W22) |
| C-MCP-4 | C-MCP-4.a (W10), C-MCP-4.b (W16), C-MCP-4.c (W27), C-MCP-4.d (W23) |
| C-MCP-5 | C-MCP-5.a (W33), C-MCP-5.b (W35) |
| C-MCP-6 | C-MCP-6.a (W9) |
| C-PRV-1 | C-PRV-1.a (W2), C-PRV-1.b (W3), C-PRV-1.c (W3) |
| C-PRV-2 | C-PRV-2.a (W14), C-PRV-2.b (W16), C-PRV-2.c (W17) |
| C-PRV-3 | C-PRV-3.a (W18), C-PRV-3.b (W22), C-PRV-3.c (W22), C-PRV-3.d (W24) |
| C-PRV-4 | C-PRV-4.a (W19), C-PRV-4.b (W23) |
| C-PRV-5 | C-PRV-5.a (W9), C-PRV-5.b (W10), C-PRV-5.c (W10), C-PRV-5.d (W11) |
| C-SEC-1 | C-SEC-1.a (W1) |
| C-SEC-2 | C-SEC-2.a (W1), C-SEC-2.b (W2) |
| C-SEC-3 | C-SEC-3.a (W2), C-SEC-3.b (W3) |
| C-SEC-4 | C-SEC-4.a (W4), C-SEC-4.b (W3) |
| C-SEC-5 | C-SEC-5.a (W7), C-SEC-5.b (W11), C-SEC-5.c (W10), C-SEC-5.d (W12) |
| C-SEC-6 | C-SEC-6.a (W16), C-SEC-6.b (W24), C-SEC-6.c (W23), C-SEC-6.d (W24), C-SEC-6.e (W23) |
| C-SEC-7 | C-SEC-7.a (W25), C-SEC-7.b (W25), C-SEC-7.c (W23), C-SEC-7.d (W16), C-SEC-7.e (W26) |
| C-SEC-8 | C-SEC-8.a (W24), C-SEC-8.b (W27), C-SEC-8.c (W16), C-SEC-8.d (W28), C-SEC-8.e (W18), C-SEC-8.f (W24) |
| C-SES-1 | C-SES-1.a (W4), C-SES-1.b (W11) |
| C-SES-2 | C-SES-2.a (W2), C-SES-2.b (W3), C-SES-2.c (W4) |
| C-SES-3 | C-SES-3.a (W1), C-SES-3.b (W2), C-SES-3.c (W2), C-SES-3.d (W13) |
| C-SES-4 | C-SES-4.a (W2), C-SES-4.b (W8), C-SES-4.c (W17), C-SES-4.d (W19) |
| C-SES-5 | C-SES-5.a (W2), C-SES-5.b (W3), C-SES-5.c (W9), C-SES-5.d (W4) |
| C-SES-6 | C-SES-6.a (W7), C-SES-6.b (W17), C-SES-6.c (W17), C-SES-6.d (W24), C-SES-6.e (W26) |
| C-SES-7 | C-SES-7.a (W14), C-SES-7.b (W24), C-SES-7.c (W27), C-SES-7.d (W24), C-SES-7.e (W20), C-SES-7.f (W25), C-SES-7.g (W25) |
| C-SES-8 | C-SES-8.a (W25), C-SES-8.b (W29), C-SES-8.c (W17), C-SES-8.d (W25), C-SES-8.e (W26), C-SES-8.f (W17), C-SES-8.g (W27) |
| C-SES-9 | C-SES-9.a (W7), C-SES-9.b (W11), C-SES-9.c (W8), C-SES-9.d (W8) |
| C-TOOL-1 | C-TOOL-1.a (W1), C-TOOL-1.b (W4), C-TOOL-1.c (W1) |
| C-TOOL-2 | C-TOOL-2.a (W25), C-TOOL-2.b (W19), C-TOOL-2.c (W25), C-TOOL-2.d (W17), C-TOOL-2.e (W25), C-TOOL-2.f (W26), C-TOOL-2.g (W26) |
| C-TOOL-3 | C-TOOL-3.a (W10), C-TOOL-3.b (W11), C-TOOL-3.c (W13), C-TOOL-3.d (W14), C-TOOL-3.e (W17) |
| C-TOOL-4 | C-TOOL-4.a (W14), C-TOOL-4.b (W33), C-TOOL-4.c (W34), C-TOOL-4.d (W35), C-TOOL-4.e (W36), C-TOOL-4.f (W34), C-TOOL-4.g (W36), C-TOOL-4.h (W34) |
| C-TOOL-5 | C-TOOL-5.a (W18), C-TOOL-5.b (W12), C-TOOL-5.c (W13), C-TOOL-5.d (W28) |
| C-TOOL-6 | C-TOOL-6.a (W26), C-TOOL-6.b (W41), C-TOOL-6.c (W35), C-TOOL-6.d (W40), C-TOOL-6.e (W37), C-TOOL-6.f (W34), C-TOOL-6.g (W35), C-TOOL-6.h (W38), C-TOOL-6.i (W39), C-TOOL-6.j (W36), C-TOOL-6.k (W39), C-TOOL-6.l (W41) |
| C-TOOL-7 | C-TOOL-7.a (W5), C-TOOL-7.b (W11), C-TOOL-7.c (W6), C-TOOL-7.d (W11), C-TOOL-7.e (W12) |
| C-TUI-1 | C-TUI-1.a (W12) |
| C-TUI-2 | C-TUI-2.a (W14), C-TUI-2.b (W15), C-TUI-2.c (W19), C-TUI-2.d (W18), C-TUI-2.e (W28), C-TUI-2.f (W26), C-TUI-2.g (W26) |
| C-TUI-3 | C-TUI-3.a (W26), C-TUI-3.b (W27), C-TUI-3.c (W18), C-TUI-3.d (W29), C-TUI-3.e (W27), C-TUI-3.f (W18), C-TUI-3.g (W28) |
| C-TUI-4 | C-TUI-4.a (W29), C-TUI-4.b (W27), C-TUI-4.c (W27) |
| C-TUI-5 | C-TUI-5.a (W30), C-TUI-5.b (W31), C-TUI-5.c (W32), C-TUI-5.d (W18), C-TUI-5.e (W30) |
| C-TUI-6 | C-TUI-6.a (W38), C-TUI-6.b (W33), C-TUI-6.c (W35), C-TUI-6.d (W39), C-TUI-6.e (W40), C-TUI-6.f (W35), C-TUI-6.g (W36), C-TUI-6.h (W41), C-TUI-6.i (W33), C-TUI-6.j (W34), C-TUI-6.k (W13), C-TUI-6.l (W39), C-TUI-6.m (W40), C-TUI-6.n (W40) |
| C-TUI-7 | C-TUI-7.a (W12), C-TUI-7.b (W12) |
| C-WEB-1 | C-WEB-1.a (W5), C-WEB-1.b (W5), C-WEB-1.c (W4), C-WEB-1.d (W3) |
| C-WEB-2 | C-WEB-2.a (W30), C-WEB-2.b (W21), C-WEB-2.c (W27), C-WEB-2.d (W28), C-WEB-2.e (W31), C-WEB-2.f (W27), C-WEB-2.g (W28) |
| C-WEB-3 | C-WEB-3.a (W8), C-WEB-3.b (W8), C-WEB-3.c (W8), C-WEB-3.d (W9), C-WEB-3.e (W14), C-WEB-3.f (W12), C-WEB-3.g (W15) |
| C-WEB-4 | C-WEB-4.a (W15), C-WEB-4.b (W18), C-WEB-4.c (W18), C-WEB-4.d (W19), C-WEB-4.e (W19), C-WEB-4.f (W21) |
| C-WEB-5 | C-WEB-5.a (W33), C-WEB-5.b (W34), C-WEB-5.c (W35), C-WEB-5.d (W36), C-WEB-5.e (W37), C-WEB-5.f (W42), C-WEB-5.g (W33) |
| C-WEB-7 | C-WEB-7.a (W12), C-WEB-7.b (W12), C-WEB-7.c (W13), C-WEB-7.d (W13) |

## 6. Owner rulings

### 6.1 Decided (2026-10-26)

| Ruling | Decision | Steps |
|---|---|---|
| R-MENU-1 | Approve the 5-menu tree as drawn (Session · Agent · Tools · View · App); bare `/generate` hidden from the menu, "Generate image…" is its door. | P-MENU.b, P-MENU.d |
| R-MENU-2 / R-MENU-3 | Keep the `Currently: <pane>` indicator as a door to View ▸ Panes; the Panes submenu stays open after a toggle. | P-MENU.e |
| P-CTX dock | Auto-dock the Context pane once on terminals ≥120 cols after the first reply. | P-CTX.e |
| R13 (remote / GitHub) | All allowed: `gh` for `/pr` and `/review --pr`, plus web PR/CI chips; the issue-comment PR bot (association-gated); serve TLS, QR pairing and Web Push with an in-tree QR encoder and RFC 8291/8292 crypto (no new deps); Admin-scope web log tail and a confirmed `server.shutdown`. | C-AGT-5.h, C-AGT-6.d, C-AGT-6.e, C-WEB-5.* |
| rv-sec-1 reversal | `-p` defaults to Manual/deny like Claude Code; locally spawned `/bg` daemons keep bypass. | C-SEC-7.a |
| autoReview | On by default whenever a review backend exists (each review is a paid call). | C-SEC-7.e |
| Live share links | Ship anonymous live read-only links (token holders see new messages until revoke or expiry). | C-SES-3.d |
| Serve telemetry | `serve` exports OTel spans when `OTEL_EXPORTER_OTLP_ENDPOINT` is set. | C-SES-4.d |
| R-EXT-1 | Honour a skill's `allowed-tools` **as written, for all tiers**: listed tools are pre-approved while the skill runs (Claude Code behaviour). Project and bundle skills load only under project trust, as today. candy-code's own 8 built-in skills are **trimmed** to the tools and effort each truly needs (no Bash pre-approval unless the skill runs shell). | C-EXT-2.b |
| R-EXT-2 | Model-written skills go live immediately with an announce-and-undo notice. | C-EXT-3.e |
| Third-party `!cmd` | Bundle and MCP-prompt shell forms are opt-in per origin (two user-tier list keys). User-tier `~/.claude` and `~/.opencode` commands may run `!cmd` as the tier gate allows today; correct `docs/COMMANDS.md:28` to match. | C-SEC-3.b |
| MCP | Background stdio calls allowed (later calls to that server queue behind the lock); `mcp import --write user` allowed (user tier, collisions refused); `mcp serve` is stdio-only with a read-only roster, Ask → refuse, no session/prompt exposure. | C-MCP-3.d, C-MCP-4.d, C-MCP-5.a |
| `-p --append-system-prompt` | Allowed, fenced, never an override; update PROMPT_ENGINEERING.md. | C-CTX-4.g |
| LSP downloads | `candy-code lsp install <lang>` may download pinned, checksummed servers from an allowlist (npm with `--ignore-scripts`). | C-TOOL-5.c, C-TOOL-5.d |
| Message picker | Idle Esc Esc opens it, as in Claude Code. | C-TUI-3.d |
| Homebrew tap | **Declined**: the formula ships as a release asset only, and the push leg stays off. | C-SES-6.d |
| R4 vim VisualLine | **Declined**: C-TUI-1.b is dropped; `V` keeps aliasing `v`. | — |

### 6.2 Take the design's default (no owner call needed unless the owner objects)

- C-PRV/C-CTX defaults as written in `design-prv-ctx.md`:
  - the idle-fold default;
  - the keep-warm default;
  - the model-catalog merge order;
  - CLI-provider isolation on by default for the Claude Code and Codex CLI routes.
- C-WEB-5 implementation sources: an in-tree QR encoder and in-tree Web Push crypto (no new dependency). The push payload defaults to redacted generic text.

### 6.3 ANSWERED — owner rulings, 2026-10-28 (LATE unlocked; scheduler gate unchanged: LATE steps start only when no normal step remains)

Step-letter mapping: the owner's C-TUI-6 letters below are §6.3 item letters; the design steps are: Windows shell = C-TUI-6.a, Windows self-update helper = C-TUI-6.e, web i18n scope = C-TUI-6.f, community locale = C-TUI-6.h, voice endpoint = C-TUI-6.l, push-to-talk = C-TUI-6.m.

C-TOOL-6 (all ADOPT recommendations):
- **C-TOOL-6.c — Script language + sandbox floor.** PHP child (`PHP_BINARY -n`), not node. Where no jail probe exists (no bwrap, no sandbox-exec, Windows) Script REFUSES — no ungated every-call-Ask arm.
- **C-TOOL-6.e — User-tier script tools.** Yes: user-tier only, write class, `auto` always asks. Plugin bundles shipping script tools: NO (third declined surface).
- **C-TOOL-6.h — Client-hosted tools.** Yes, but OFF by default behind user-config-only `server.clientTools: false` (project config can never enable). Any client holding Write scope may register; registrations die with the connection; every `client__*` call is write class so `default` asks.

C-TUI-6:
- **a — Windows shell.** Git-for-Windows `bash.exe` only (PATH → `%ProgramFiles%\Git\bin\bash.exe`); NO cmd.exe fallback; missing bash refuses with "install Git for Windows". Array spawn spec, host-independent PosixShellQuote, WindowsTaskKill (`taskkill /T /F`) tree kill; background rows stay `grouped:false`.
- **e — Windows self-update helper.** Yes — detached `PHP_BINARY -r` helper is the relaunch swap dance SelfUpdate waits on.
- **f — Web i18n scope.** Yes — chrome/UI strings only (`server.strings`); server, tool and model text stay untranslated.
- **h — Community locale (owner expanded: ONE → the recommended SET).** Ship ALL 11 recommended non-English codes from LOCALES.md: fr, de, es, pt, pt-br, zh-cn, zh-tw, ja, ru, it, ko (bare base codes per rule 1; regional file only where the set already has one, e.g. pt-br). Translation source: MACHINE TRANSLATION with MACHINE REVIEW — no native-review gate. Parity STRICT, lands LAST: every file carries the full English key set; later English key additions must be translated in-step across the shipped files. Step brief `design-tui.md` → C-TUI-6.h updated to match (11 files, `lang:whole-file` serialized reads, README Languages note lists all codes).
- **l — Voice endpoint.** No default endpoint; voice off until a user-tier sets `voice.baseUrl`; audio never leaves the machine by default.
- **m — Push-to-talk key.** Alt+V.

C-AGT-5 (owner, 2026-10-29): **C-AGT-5.b = option (a) ROOT-SCOPE.**
- **b — Schedule delete scope.** The ScheduleFactory delete guard is ROOT-SCOPED: a model-created row belonging to ANOTHER project root may NOT be deleted without the include_operator_rows door; same-root model-row deletes stay unrestricted; operator rows unchanged. Closes the C-AGT-5.a r1 MINOR-2 carry; releases the W14 loop and the C-AGT-5.c cascade.

No further owner questions outstanding (LATE rulings answered 2026-10-28; C-AGT-5.b answered 2026-10-29).

## 7. Closeout gate (one gate agent, after the last wave)

The gate is a LOOP (§1.6): run steps 1–4, then hand every red or drift to a fix agent, then re-run from step 1. Repeat until a full pass is green with no drift, then do steps 5–7.

1. **Full battery, plain-pipe synchronous.**
   - Run under `env -u LINES -u COLUMNS`; never tmux, setsid or tee.
   - The candy-code serial must be green.
   - Run vitest with `--no-file-parallelism` after `(cd candy-code-web && npm ci)`.
   - Spot-check e2e on the web flows that changed the protocol.
2. **Figure re-pin trio:**
   - `candy-code/tests/Config/Support/suite-figure.json`, via `php tests/Config/Support/refresh-suite-figure.php <junit>` (cwd `candy-code`), after a green log;
   - the `candy-code/README.md` headline figure;
   - the root `scripts/parallel-tests-durations.tsv`, with a row for every new `*Test.php`.

   Then check that the shard sum equals the figure.
3. **Regenerate, failing loud on drift:**
   - `php candy-code/tools/gen-command-docs.php --check`, `php candy-code/tools/gen-tool-docs.php --check` and `php candy-code/tools/gen-settings-doc.php --check` (each defaults to `--help` and exits 0, so always pass the flag);
   - `php candy-code/scripts/gen-protocol-schema.php --check`, then `(cd candy-code-web && npm run gen:protocol)`, after which `generated.ts` must be byte-clean;
   - `(cd candy-code-web && npm run build)`, after which `candy-code-web/dist/` must be clean;
   - `php tools/check-path-repos.php --no-lib-path-repos`, `php tools/check-child-lifetimes.php` and `php tools/check-one-type-per-file.php`.
4. **Drift-guard sweep:**
   - ReadmeRoster, EnvRoster, TrustKey, ConfigWriteProducer, KeyBinding, ToolDoc, ProtocolSchema, LangParity and DocFigureProse;
   - GlobDialectDifferential, with the corpus figure unchanged;
   - the census rosters.
5. **COMPARISON.md refresh:**
   - Re-score every cell a landed step moved, in both the §3 cell and its Appendix A bullet, glyphs equal.
   - Delete the closed gaps from §6. Don't annotate them; the doc stays in current-state voice.
   - Re-run `python3 -I prompt_kit/findings/comparison-1026/apply_comparison.py candy-code/COMPARISON.md --check`.
6. **Review-loop proof:** every step loop and every wave loop ended on a fresh review with 0 critical and 0 major, recorded under `prompt_kit/findings/comparison-1026/lanes/<step>/` and `lanes/W<n>/`. Keep a ledger at `prompt_kit/findings/comparison-1026/CLOSEOUT.md` with step → commit, per-wave counts, and residuals.
7. **Hygiene:** `git worktree list` shows the main checkout only, and no `cc/*` branches remain.

## 8. Not in scope

- **C-WEB-6 SDK, IDE and desktop clients** (owner: not picked): publishing the TS protocol client, a VS Code extension, a desktop shell, `candy-code://` deep links, chat-channel daemons.
- **C-SEC-9 managed tier and multi-user** (owner: not picked): `/etc/candy-code` managed settings, instructions and hooks; per-user serve tokens.
- **`candidates.md` § Overflow:**
  - native subscription OAuth logins (vendor terms);
  - thread-bound sub-agent sessions;
  - device nodes, voice wake, email and calendar (use MCP servers);
  - cloud execution (the Docker serve path is the answer);
  - live heap-dump diagnostics;
  - parity notes that need no work.
