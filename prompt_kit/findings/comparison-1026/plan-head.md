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
