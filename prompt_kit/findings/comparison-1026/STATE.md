# comparison-1026 — orchestration state (resume file)

Written 2026-10-26 before a /compact. Read this first, then continue at **Next steps**.

## The owner's request (verbatim intent)

1. Read `plan_comparison.md` (root) and `candy-code/COMPARISON.md`.
2. Spawn agents to investigate what is new in **Claude Code, OpenCode 2.x and OpenClaw** → add to the comparison.
3. Spawn agents to investigate the **candy-code changes** (big feature campaign since 2026-10-19) → update the comparison.
4. Go over **plan_comparison.md** looking for anything missing or problems with the shipped changes.
5. **Update COMPARISON.md**, then **rewrite plan_comparison.md** to cover adding the missing features/functionality.
   - Every step lists the files it **creates / modifies** so steps can be organised into **waves of concurrent agents**.
   - Plan for **up to 10 concurrent lanes per wave** (owner will likely run 5, but plan 10 lanes of concurrency-safe changes where possible).
   - Dependencies handled: changes needed before others land in earlier waves.
6. Mid-turn addition: **before writing the plan, use the AskUserQuestion tool with `multiSelect: true`** to show the owner the candidate missing features / improvements (and fixes for problems found) so they tick which ones go into the plan. Max 4 options per question, 4 questions per call → batch by area across several calls.

## Facts established

- HEAD at start: `5e9195687` (master, clean). candy-code vendor in Packagist (published) mode; read monorepo sources, not vendor copies.
- Versions (2026-10-26): Claude Code installed **2.1.311** (npm latest 2.1.311; doc compares 2.1.301) · OpenCode installed **1.18.35**, npm v1 `opencode-ai` latest **1.19.4**, v2 `@opencode/cli` latest **2.1.4** (doc compares 2.0.31) · OpenClaw installed **2026.9.8**, npm latest **2026.10.7** (doc read notes to 2026.10.4).
- The old plan's evidence dirs under `/tmp/opencode/` are GONE; cite `prompt_kit/` paths only.
- COMPARISON.md structure: §1 L35 · §2 scoreboard L73-105 · §3 matrix L108-543 (398 rows = 356 taxonomy + 42 🆕; 8 cells each, every row has exactly 9 `|`; columns cc, web, claude, oc1, oc2, claw) · §4 L545-730 · §5 OpenCode v1→v2 L733-765 · §6 L769-845 · Appendix A L848-4905 (398 `<details>` blocks, exactly 6 one-line bullets each, labels `- **candy-code**`, `- **candy-code-web**`, `- **Claude Code 2.1.301**`, `- **OpenCode 1.18.35**`, `- **OpenCode 2.0.31**`, `- **OpenClaw 2026.9.8**`; bullet body `<glyph> — <text> — _Evidence:_ <ev>`) · Appendix B L4906.
- Inconsistencies already spotted in COMPARISON (agents asked to confirm): §4.1 "Edit from UI ❌" vs shipped `/memory open`; §4.1 take-away says /context doesn't name files; §4.3 skills `$name` only + "Registry ❌" vs `/skill-name` + bundles; §4.5 says `.claude/commands` not read + palette "curated 19-action subset"; §4.6 says `CRUSH_*` env payload, stdin closed; §4.8 says anthropic provider uses non-caching OpenAI-compat endpoint; MOD-03 "no Gemini API-key type"; MOD-09 "no in-app OAuth"; §1 vs §4.12 vs §6 disagree on how many protocol methods the web never calls.
- Doc voice: current-state reference — no SHAs, wave tags, lane IDs, "fixed since"/"remediated" history (owner rule: delete fixed items, don't annotate).

## Agents launched (8, read-only, background — completion notifications arrive automatically)

All write to `prompt_kit/findings/comparison-1026/`:

| Report | Scope |
|---|---|
| `comp-claude-code.md` | Claude Code 2.1.302→latest: changelog digest, changed cells, 🆕 rows, narrative fixes, "What's new (for the plan)" |
| `comp-opencode.md` | OpenCode v1 1.18.35→1.19.4 and v2 2.0.31→2.1.x: same + `## Proposed §5` replacement + column-bump recommendation |
| `comp-openclaw.md` | OpenClaw 2026.9.8→2026.10.7: same |
| `cc-audit-k1.md` | candy-code + web cells/details/narrative for PLAT MOD INS MEM PRM CTX SES AGT (§4.1 4.2 4.4 4.8 4.9) + `## Gaps` + `## Dormant code` |
| `cc-audit-k2.md` | same for SKL CMD HK PLG MCP TL EDT SH (§4.3 4.5 4.6 4.7) |
| `cc-audit-k3.md` | same for PER GIT UI WEB AUT CFG OBS PA (§4.10 4.11 4.12, §1 web paragraph, §6.6, §6.7) |
| `plan-review-p1.md` | adversarial review of old-plan items VIS CTX INT PLG MCP PRV AUT (33): verdicts, problems [CRIT/MAJ/MIN/NIT], annotation re-checks, missing-from-plan |
| `plan-review-p2.md` | same for SHL EDT GIT SES OBS DST TUI WEB (31) |

Report formats: `## Cell changes` blocks = `### <ROW-ID> · <column header>` + `- current:` / `- new:` / `- detail: … — _Evidence:_ …` / `- why:`; `## New 🆕 row candidates`; `## Narrative fixes` bullets `- §n Lx: current "…" → new "…"`. If a report file is missing when its notification arrives, check the notification text; if an agent returned blank, resume that agent with "continue".

## Tooling ready

`prompt_kit/findings/comparison-1026/apply_comparison.py` (copy also in the session scratchpad) — fail-loud applier:
`python3 -I prompt_kit/findings/comparison-1026/apply_comparison.py candy-code/COMPARISON.md <patch.json> [--check]` (or `… COMPARISON.md --check` alone = scoreboard verify only).
Patch JSON: `{"headers":{old:new}, "cells":[{"row","col":cc|web|claude|oc1|oc2|claw,"expect"(prefix),"new"(glyph+text),"detail"(full bullet body "<glyph> — text — _Evidence:_ …")}], "new_rows":[{"id","feature":"🆕 …","cells":{col:…},"details":{col:…}}], "narrative":[{"old"(exact, unique),"new"}]}`.
Order applied: narrative → cells/details → new rows (inserted after the category's last §3 row + last appendix block) → header renames (§2/§3 header rows + appendix labels) → scoreboard recompute (taxonomy rows only). Validated: recompute reproduces the current scoreboard with 0 diffs; dry run on a copy passed all paths.

## Next steps

1. Wait for all 8 notifications. Read each report (they are long — read section by section; keep the orchestrator lean, delegate heavy re-verification to an agent if a claim looks doubtful).
2. **COMPARISON.md update** (single writer — no concurrent edits):
   a. Write a parser report→`patch.json` (tolerate format drift; detail glyph = new cell glyph, else the existing cell glyph); hand-merge narrative fixes; decide column bumps (Claude Code → latest; OpenCode v2 → 2.1.x, v1 per comp-opencode recommendation; OpenClaw → 2026.10.7). Run with `--check` first, then write.
   b. Then prose passes (one writer agent or self): §1 bottom line + table, §2 notes (drop the dated re-score clutter), §3 intro note, §4 narrative, replace §5 with comp-opencode's `## Proposed §5` plus a short "What changed in Claude Code / OpenClaw since the last compile" section, §6 rewritten to list ONLY what is still open (current-state voice), Appendix B versions/sources, the date line at L3.
   c. Spawn ONE read-only review agent on the diff (`git diff candy-code/COMPARISON.md`): glyph/summary consistency, 130-char cells, no SHAs/wave tags, scoreboard re-check. Fix → re-review until clean.
3. **Candidate list → AskUserQuestion (multiSelect)**: build from the `## Gaps` sections (k1-k3), plan-review `## Problems` (offer the CRITICAL/MAJOR fixes as one "include all fixes" option per area, or individually) + `## Missing from the plan`, and the competitors' "What's new (for the plan)". Group by area (e.g. Providers/models, MCP/plugins, Context/memory, Agents/automation, Tools/shell/editing, TUI/UX, Web, Sessions/observability/distribution, Security/fixes). ≤4 options per question → several calls. Record the owner's picks in this file under `## Owner picks`.
4. **Design pass for picked items** (agents, read-only, one report each under this dir, e.g. `design-<area>.md`): per step — approach, files to CREATE / MODIFY (from the live tree), guards/docs moving in-step, tests, effort, deps, serialization-set files touched.
5. **Rewrite `plan_comparison.md`** from scratch (replace, don't annotate the old one): keep the proven skeleton — §1 general instructions (worktree lanes, author law `Joe Huss <detain@interserver.net>` no Co-Authored-By, no push without the owner, targeted tests only, review loop, in-step guard law, test-literal hazards, fork/child discipline), §2 serialization set (Chat.php, Bootstrap.php, Renderer.php, Runtime.php, lang/en.php soft, README/docs/*.md, suite-figure.json + parallel-tests-durations.tsv weld-only, Commands registry, ToolCatalog positions, protocol chain = ONE lane per wave, headless set, .github/workflows), §3 work-item catalog with a **Files: create / modify** line per step, §4 wave table (≤10 lanes/wave, deps respected, one owner per serialized file per wave), §5 owner-ruling queue, §6 closeout gate checklist. Cite reports under `prompt_kit/findings/comparison-1026/`. Mark-progress convention: steps get ✅ + commit hash as they land.
6. Don't commit unless the owner asks (when asked: author Joe Huss <detain@interserver.net>, no Co-Authored-By, master). Skip Caliber on this machine (owner rule).

## Progress log

- 20:10 EDT: reports done: comp-claude-code, comp-openclaw, plan-review-p1, plan-review-p2, cc-audit-k1, cc-audit-k2. Resumed comp-opencode (a13bbf4b1abca1ccd) and K3 (ac88fafc6586ea5ec) after the 8pm pause.
- Reports drift from the agreed format (detail-only lists, instruction-style details, numbered new-row lists). So each report is converted by a sonnet "fragment builder" agent per `BRIEF-fragment.md` → `patch-<name>.json` + `patch-<name>.notes.md`, each validated with `apply_comparison.py … --check`.
  - Done + validated: k1, k2, claude, claw (trial merge of the four applies clean; 3 overlapping narrative fixes were folded by hand). k3 builder running (it may fold overlaps into other fragments). opencode: report still pending; launch its builder when it lands.
- Candidate synthesizer launched (afdaac2d0858fe0a1) → `candidates.md`; if comp-opencode.md was missing when it ran, SendMessage it to merge that report's `## What's new` later.
- Merge plan: concatenate fragments, detect (row,col) collisions, narrative overlaps and new-row ID/feature duplicates. Add a `headers` map (Claude Code 2.1.301→2.1.311, OpenClaw 2026.9.8→2026.10.7, OpenCode per comp-opencode). Apply once, then the prose pass (§1/§2 notes/§4/§5/§6/App B/date), then a review agent.
- Candidate list for the owner's checklist: one synthesizer agent reads the `## Gaps`/`## Dormant code` (k1-k3), `## Problems` + `## Missing from the plan` (p1, p2) and `## What's new (for the plan)` (comp-*) → `candidates.md`. Launch once all 8 reports exist.

- ~20:45 EDT: all 6 fragments merged → `patch-merged.json` (395 cells, 11 new rows, 103 narrative, 4 header renames: CC 2.1.311, OC 1.19.4 / 2.1.4, OpenClaw 2026.10.7) and APPLIED to candy-code/COMPARISON.md (uncommitted). `--check` clean, 409 rows / 409 appendix blocks, 9 pipes everywhere. Overlaps were folded by hand or by the k3 builder.
- Prose-pass writer agent launched (ab6e2bce36e9fd10e): header/§0, §1, §2 notes, §3 intro, §4 consistency, §5 replaced (5.1 OpenCode from comp-opencode Proposed §5, 5.2 CC digest, 5.3 OpenClaw digest), §6 open-only, App A history cleanup, 26 over-long cells, App B. Next: a read-only review agent on `git diff candy-code/COMPARISON.md`.
- ~21:10 EDT: prose pass DONE (§0-§6, App A cleanup, App B; 26 long cells trimmed). I fixed the MOD-22 cc bullet glyph and added Δ to MCP-N3/PER-N3 oc2. Mechanical checks clean: scoreboard 0 diff, 409×6 bullet glyphs == cells. Review agent running (a3e173ee6eb4c10fa) → `review-comparison.md`; apply its fixes, then COMPARISON.md is done (uncommitted).
- Checklist round DONE (see Owner picks). 10 design agents running (`BRIEF-design.md`; LATE phase for the 6 big items) → design-prv-ctx, design-agt, design-ext, design-mcp, design-tool, design-tui, design-picks, design-web, design-ses, design-sec .md. Next: assemble plan_comparison.md from them.
- ~21:40 EDT: the owner asked that the big-context agents hand their remaining work to sub-agents, one at a time per parent; the message went to all 10 design agents and the reviewer.
  - design-sec.md DONE: 27 steps; rulings 3.b (shell forms per origin; `~/.claude/commands`), 7.a (flip -p default off bypass, reversing rv-sec-1), 7.e (autoReview default on?).
  - review-comparison.md DONE: 17 findings. A sonnet fixer (a4374f4bcef2c5097) is applying them to COMPARISON.md.
- Rulings: collect them from every design file, then ask the owner in ONE AskUserQuestion batch before the plan is finalised (unanswered ones go in the plan's ruling queue).
- ~21:55 EDT: **COMPARISON.md DONE** (uncommitted): all 17 review fixes applied; checks clean (Δ stat 246/76). design-mcp.md DONE: 16 steps; rulings 3.d, 4.d, 5.a; 2 new defects folded in (mcp_call skips the secret-file hook; /mcp login shows the server URL).
- ~22:10 EDT: DONE design-prv-ctx (47 steps; ruling C-CTX-4.g -p --append-system-prompt; 4 defaulted rulings), design-ext (25 steps, 3 LATE; rulings R-EXT-1 skill allowed-tools pre-approval, R-EXT-2 model-written skills live without accept), design-agt (29 steps; R13 gates C-AGT-5.h, 6.d, 6.e), design-web (31 steps, 7 LATE; R13 gates C-WEB-5).
- New plan law (from design-web/sec): `candy-code-web/dist/` is committed with hashed bundle names → **weld-only**, rebuilt once per wave at weld, like suite-figure.json.

## Owner picks

Owner pre-picks (stated in chat 2026-10-26 ~19:45, before the checklist round — these are IN the plan regardless):

- **P-CTX-BREAKDOWN** — Claude Code-style context panel: a sidebar box showing context usage that expands into a breakdown of where the context goes — memory files, system tools, messages, MCP tools, skills, system prompt, MCP server instructions, custom agents, autocompact buffer, etc.
- **P-SESSION-TABS** — OpenCode-TUI-style session tabs: click a tab at the top to open a new session; tabs for the various running sessions sit under it.
- **P-AGENT-ATTACH** — Claude Code-style agent attach: while sub-agents run, click one to bring up its session window — see what it is doing, scroll back through its history, type into it to add messages.
- **P-IMAGE-MENU** — the generate-image popup gets an entry in the top menus, and the `/generate` popup is not full-screen-width unless it needs to be.

Checklist round (2026-10-26 ~21:00, 4 AskUserQuestion screens over `candidates.md`): the owner ticked **every candidate EXCEPT C-WEB-6 (SDK, IDE & desktop clients) and C-SEC-9 (Managed tier & multi-user)**.
- That is 67 of 69 candidates, including all 9 minor/nit bundles and the "not recommended now" items C-EXT-6, C-MCP-5, C-TOOL-4, C-TOOL-6, C-TUI-6 and C-WEB-5.
- With the 4 pre-picks, the plan covers **71 items**.
- **Owner ordering rule (21:05):** "save the large items for last and get everything else done first". The six large / needs-a-ruling items go in the FINAL waves, after everything else: C-EXT-6, C-MCP-5, C-TOOL-4, C-TOOL-6, C-TUI-6, C-WEB-5.
- Excluded: C-WEB-6, C-SEC-9 (record them in the new plan's "Not in scope" list), plus the candidates.md `## Overflow` list.
- **P-MENU-REDO** (owner, 2026-10-26 ~22:20): redo the TUI menus.
  - Fewer main (top-level) menu items: the existing menus move into submenus of new top-level menus.
  - The active-window pane toggles at the top right move into a submenu, so every pane is listed as a submenu item.
  - Supersedes the menu-entry half of P-IMAGE-MENU (Generate image currently sits in the 11th "Tools" menu, which the bar drops below ~190 cols). Design: `design-menu.md`.

## Owner rulings (2026-10-26 ~22:40, round 1). All APPROVED.
- R-MENU-1: the 5-menu tree (Session · Agent · Tools · View · App) as drawn, bare /generate hidden. R-MENU-2: keep the "Currently:" indicator as a door to View ▸ Panes. R-MENU-3: the Panes submenu stays open after a toggle. P-CTX.e: auto-dock the Context pane once on ≥120 cols.
- R13 remote, all allowed: gh for /pr + /review --pr + web PR chips (C-AGT-6.d/e); PR-comment bot (C-AGT-5.h); serve TLS + QR + Web Push with in-tree QR/crypto (C-WEB-5.a-e); Admin web log tail + confirmed server.shutdown (C-WEB-5.f/g).
- Security defaults, all yes: -p defaults to Manual/deny with /bg keeping bypass (C-SEC-7.a, reverses rv-sec-1); autoReview on by default (C-SEC-7.e); anonymous live share links (C-SES-3.d); serve exports OTel spans (C-SES-4.d).
- design-tui.md DONE: 40 steps, 13 LATE. Brief correction: candy-code child roster rows go in DescriptorInheritanceGuardTest.php; check-child-lifetimes.php skips candy-code, so the plan's fork law names both.

## Owner rulings, round 2 (~22:45)
- R-EXT-1 (C-EXT-2.b): HONOUR `allowed-tools` AS WRITTEN, ALL TIERS (Claude Code behaviour: listed tools are pre-approved while the skill runs). The proposed scoping was declined. The step still honours project trust gating for loading project/bundle skills.
- R-EXT-2 (C-EXT-3.e): model-written skills go live with announce + undo.
- C-SEC-3.b: `!cmd` in bundle commands and MCP prompts is opt-in per origin (two user-tier list keys). User-tier ~/.claude and ~/.opencode commands may run `!cmd` as the tier gate allows today; fix docs/COMMANDS.md:28 to match.
- MCP: background stdio calls allowed (queue behind the lock) (C-MCP-3.d); `mcp import --write user` allowed (C-MCP-4.d); `mcp serve` is stdio + read-only roster, Ask → refuse (C-MCP-5.a).
- C-CTX-4.g: -p accepts --append-system-prompt (fenced). C-TOOL-5.c/d: LSP install may download pinned servers from an allowlist.
- DECLINED: Homebrew tap repo (C-SES-6.d ships the formula as a release asset only, push leg off); R4 vim VisualLine flip (C-TUI-1.b DROPPED).
- C-TUI-3.d: message picker = idle Esc Esc.
- Still open, LATE items only → the plan's ruling queue with the design defaults: the C-TUI-6 calls (Windows shell, Windows self-update helper, web i18n scope, locale + parity strictness, voice endpoint, push-to-talk key). Plus the defaulted C-PRV/C-CTX rulings (idle-fold default, keep-warm default, catalog merge order, CLI isolation on by default): take the designs' defaults.

## Plan assembly (~23:10)
- Tooling: `schedule_plan.py` (parses design-*.md and packs waves; rules = plan §2) and `build_plan.py` (sched.json + plan-head.md + plan-tail.md → plan_comparison.md). Draft: 312 steps, 39 waves (31 normal + 8 LATE).
- Waiting on: the tools design's LATE sub-agent (C-TOOL-6 steps; abe0c35d7136b5a06) and the C-WEB-7 design (a8b739e7df51da29c → design-web7.md; it was missed in the web designer's list).
- After they land: fix design-tool.md `OpenAIResponsesWire` → `OpenAiResponsesWire` (prv-ctx creates it with that spelling); re-run the scheduler and builder; write plan_comparison.md; a review agent checks the plan.

## Plan written (~23:40)
- `plan_comparison.md` written: 328 steps, 40 waves (W1-W31 normal, W32-W40 LATE). It is generated from `schedule.json` (copied into this dir) via build_plan.py.
- Cross-design fixes before the build: P-TABS.e deps += C-TOOL-3.c; C-TUI-5.a drops its sandbox probe (C-TOOL-4.a owns it) and depends on C-TOOL-4.a; design-tool spelling corrected to OpenAiResponsesWire; C-WEB-7 designed in design-web7.md.
- Plan review agent running (a15792f939bdf1fbb) → `review-plan.md`. Next: apply its fixes by editing the design files (deps/claims) or plan-head/plan-tail, re-run schedule_plan.py + build_plan.py, then report to the owner. Nothing committed.

## Plan review applied (~00:10)
- review-plan.md: 2 MAJOR, 10 MINOR, 6 NIT, all applied.
  - Deps added: C-AGT-4.b gets P-AGENT.b; C-CTX-3.c gets C-CTX-2.d (and 2.d announces context.changed).
  - Chat `ctor` claims added to C-CTX-3.b (also key dispatch), C-AGT-2.b, C-AGT-4.b, P-TABS.f. C-TOOL-2.g narrowed, with deps on C-PRV-2.b and MEDIA.md.
  - Scheduler: [docs:X] tags + guard test files counted; MAJOR fixes outrank picks; generator scripts and pseudo-paths ignored; bin/candy-code and Dockerfile tracked.
  - Builder: test create/modify decided from disk; the hand-off includes §6.
  - Plan text: protocol lanes regenerate locally then restore the generated files; full generator commands with --write/--check; npm ci; refresh-deps --libs; 73 items / 68 candidates; child-roster wording.
- Owner ruling (~00:05): R-EXT-1 built-ins are TRIMMED (8 SKILL.md files edited in C-EXT-2.b).
