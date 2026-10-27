# candy-code: candidate checklist for the next feature plan (2026-10-26)

**Sources used** (all in `prompt_kit/findings/comparison-1026/`):

| Report | Sections read |
|---|---|
| `cc-audit-k1.md` | Summary, Gaps, Dormant code |
| `cc-audit-k2.md` | Summary, Gaps, Dormant code |
| `cc-audit-k3.md` | Summary, Gaps, Dormant code |
| `plan-review-p1.md` | Summary, Problems, Missing from the plan |
| `plan-review-p2.md` | Summary, Problems, Missing from the plan |
| `comp-claude-code.md` | What's new (for the plan) |
| `comp-openclaw.md` | What's new (for the plan) |
| `comp-opencode.md` | What's new (for the plan) |

**Source-ID shorthand.**
- `GAP-*` is an audit gap block.
- `k1-dorm#n`, `k2-dorm`, `k3-dorm#n` are audit Dormant-code items; `k1-sum`, `k2-sum` and `k3-sum` are audit Summary findings.
- `AREA-n-Pn` is a plan-review problem.
- `p1-miss:` and `p2-miss:` are plan-review "Missing from the plan" bullets.
- `CC-new#n`, `OC-new#n` and `CLAW-new#n` are competitor "What's new" items (Claude Code, OpenCode, OpenClaw).

**Counts: 70 candidates, plus the 4 already chosen.**

| Area | fix | dormant-wire | gap | competitor-feature | Total |
|---|---|---|---|---|---|
| PRV Providers & models | 2 | 0 | 2 | 1 | 5 |
| CTX Context, memory & instructions | 3 | 0 | 2 | 1 | 6 |
| AGT Agents, git & automation | 3 | 1 | 3 | 0 | 7 |
| EXT Skills, commands, hooks & plugins | 2 | 1 | 4 | 0 | 7 |
| MCP | 2 | 1 | 3 | 0 | 6 |
| TOOL Tools, shell & editing | 2 | 1 | 4 | 0 | 7 |
| TUI TUI & UX | 1 | 1 | 5 | 0 | 7 |
| WEB Web client | 2 | 1 | 4 | 0 | 7 |
| SES Sessions, sharing, observability & distribution | 6 | 0 | 3 | 0 | 9 |
| SEC Security, permissions & trust | 4 | 1 | 4 | 0 | 9 |
| **Total** | **27** | **7** | **34** | **2** | **70** |

- **Fix candidates:**
  - 2 CRITICAL (C-SEC-1, C-SEC-2);
  - 16 MAJOR, each grouping one or more of the 28 MAJOR problems plus the audit live defects;
  - 9 minor/nit bundles, one per area (SEC's minors went to TOOL and SES).
- **Recommendations:** yes 62, no 8.
- At 16 per screen the checklist fills 5 screens (70 ÷ 16 = 4.4).

---

## Already chosen

These four are in the plan. The items below were folded into them and are not repeated as candidates.

- **P-CTX-BREAKDOWN:** a context panel with a breakdown by source.
  - Folded in:
    - GAP-PRM-15 (`/context --raw` prompt dump, as the drill-down);
    - GAP-MOD-15w, its cache-hit-% readout half (also MOD-19 web and MOD-N3 web; the `/effort` half is in C-WEB-2).
  - Consumes:
    - C-MCP-4's user-tier server rows (computed today, never shown);
    - C-CTX-2's `context.changed` reload (GAP-INS-08w) to stay live.
- **P-SESSION-TABS:** session tabs.
  - Folded in:
    - GAP-SES-12: one TurnRunner per tab, so background tabs keep streaming and tab switches are no longer refused mid-turn.
    - GAP-UI-N6: attention badges on the tab strip, plus an OSC 2 window-title count.
- **P-AGENT-ATTACH:** attach to running sub-agents.
  - Folded in:
    - k1-dorm#3: the inert preset `color:` key, used for the Agent View header, the AgentStrip and the web tree node colour.
    - CLAW-new#7: web Subagents panel parity (per-child activity rows, running countdown, launch rows linking in).
    - CC-new#9: subagent fork/background parity (note only).
- **P-IMAGE-MENU:** the generate-image popup gets a top-menu entry and a non-full-width `/generate` popup.
  - Folded in: nothing.
  - Adjacent: C-WEB-3 wires the dormant `canGenerateVideo` as a video tab in the same GeneratePanel (k3-sum: the Generate form is image-only).

---

## Candidates by area

### Providers & models (PRV)

#### C-PRV-1
- label: Honest /effort & safe login
- desc: `/effort` reports success but sends nothing on non-reasoning OpenAI models and non-shaping providers, and `login` wipes the provider block and echoes the key. Fix both; add Bedrock and Codex effort.
- kind: fix
- severity: MAJOR
- effort: M
- sources: PRV-2-P1, PRV-4-P1, PRV-4-P2, p1-miss: Bedrock request-side thinking/effort, p1-miss: Codex parity (`-c model_reasoning_effort`)
- recommend: yes — silent set-but-ignored effort and a config wipe

#### C-PRV-2
- label: Responses, Azure, OpenRouter
- desc: An OpenAI Responses wire (reasoning items, image tool results), plus first-class `azure` and `openrouter` types, which today need an undocumented `custom` block. OpenCode/OpenClaw have them.
- kind: gap
- effort: L
- sources: GAP-MOD-02, GAP-MOD-05/06, p1-miss: OpenAI Responses API
- recommend: yes — common setups; Responses also unlocks images in tool results

#### C-PRV-3
- label: Fast mode, ultrathink, advisor
- desc: A `serviceTier` setting with a `/fast` toggle, an `ultrathink` keyword that raises effort for one turn, and an Advisor tool that consults a second model. Claude Code/OpenClaw parity.
- kind: gap
- effort: M
- sources: GAP-MOD-N2, GAP-MOD-N1, CC-new#7
- recommend: yes — fast and ultrathink are cheap; advisor optional

#### C-PRV-4
- label: CLI provider isolation
- desc: Claude-subscription and Codex CLI routes apply their own CLAUDE.md, hooks and permission mode, outside candy-code's engine. Strip that foreign config and map modes. Idea from OpenClaw.
- kind: competitor-feature
- effort: M
- sources: CLAW-new#4, PRV-4-P3, p1-miss: Codex parity (mode → `-s`, `--output-schema`)
- recommend: yes — foreign hooks run outside the permission engine today

#### C-PRV-5
- label: Provider minor/nit cleanup
- desc: Thinking budget sent to auxiliary backends, ungated budgets that 400, a sync catalog GET freezing the TUI for 3 s, Gemini responseSchema, catalog merge order, README codex wording.
- kind: fix
- severity: MINOR/NIT
- effort: M
- sources: CTX-1-P1, CTX-1-P2, PRV-2-P2, PRV-1-P1, PRV-3-P1, PRV-1-P2, PRV-4-P4
- recommend: yes — removes hard 400s and a 3-second freeze

### Context, memory & instructions (CTX)

#### C-CTX-1
- label: Instruction & rule delivery
- desc: Mid-session CLAUDE.md edits never reach the model on the forked TUI/serve path (reproduced), and Cursor glob rules import disabled. Prime the diff before the fork and enable glob rules.
- kind: fix
- severity: MAJOR
- effort: M
- sources: CTX-3-P1, INT-2-P1, INT-2-P2, p1-miss: CTX-3 `@import` children untracked
- recommend: yes — an advertised feature is silently broken (reproduced)

#### C-CTX-2
- label: Served turn-end parity
- desc: Serve never drains runtime notices (recall, fallback, instruction loads), and never runs auto-memory, dream, prompt suggestion or auto-commit. Wire both into SessionHost. Found by the audit.
- kind: fix
- severity: MAJOR
- effort: M
- sources: GAP-MEM-10w, GAP-MEM-02w, GAP-INS-08w, GAP-UI-34, k1-sum (a)(b), k1-dorm#1, k1-dorm#6, MOD-14 web
- recommend: yes — web users silently lose memory, notices and suggestions

#### C-CTX-3
- label: Memory & instruction editing
- desc: `/instructions open` and a `#` quick-add for CLAUDE.md/AGENTS.md (TUI + web), model recall over past sessions and the journal, memory export, visible imports, standing intents. Claude Code/OpenClaw.
- kind: gap
- effort: L
- sources: GAP-INS-16, GAP-MEM-11/12, GAP-MEM-14, GAP-MEM-N3, GAP-SES-11 (model half)
- recommend: yes — editing and recall are core parity; intents optional

#### C-CTX-4
- label: Prompt sources & styles
- desc: Output styles, SOUL/USER persona files, headless `--append-system-prompt`, a live date plus knowledge-cutoff note, an instruction walk past the git root, URL/absolute sources and extra reference dirs.
- kind: gap
- effort: L
- sources: GAP-PRM-10, GAP-PA-04, GAP-PRM-09, GAP-PRM-14, GAP-INS-07, GAP-INS-11, GAP-PRM-N2
- recommend: yes — date fix and styles are cheap; append needs a ruling

#### C-CTX-5
- label: Compaction & cache economics
- desc: A cheaper summary model, cache-reusing summaries, a fallback that never fails the turn when a summary times out or overflows, keep-warm pings, and idle compaction timed to the cache TTL.
- kind: competitor-feature
- effort: L
- sources: OC-new#4, OC-new#5, OC-new#7, OC-new#9, CLAW-new#5, GAP-MOD-N3, GAP-CTX-N2, p1-miss: CTX-5 short cache-warm idle tier, CTX-5-P2, CC-new#10 (idle-compaction toggle)
- recommend: yes — fallback stops failed turns; spend levers need a ruling

#### C-CTX-6
- label: Context minor/nit cleanup
- desc: Served idle alarm lost mid-turn, PDF page/aggregate caps, `/memory open` skips the git journal, a Lang plural, `instructionsExclude` path matching, a stale `/goal` docblock, a recall row.
- kind: fix
- severity: MINOR/NIT
- effort: S
- sources: CTX-5-P1, CTX-2-P1, VIS-2-P1, VIS-6-P1, INT-1-P1, k1-dorm#7, p2-miss: TUI-6 `Message::recallNotice()` row + English plural
- recommend: yes — small, mostly one-file fixes

### Agents, git & automation (AGT)

#### C-AGT-1
- label: Automation trigger fixes
- desc: `/loop` on serve registers but never fires. Persisted schedules carry no root, so they fire in whichever repo wins the lock. Webhook bodies over 64 KiB arrive empty yet still bill a turn.
- kind: fix
- severity: MAJOR
- effort: M
- sources: GAP-AUT-08, k3-sum (live defect), k3-dorm#1, AUT-1-P1, AUT-6-P1, p1-miss: schedules UX (`/loop` for served sessions)
- recommend: yes — silent non-firing and wrong-repo edits; front-load

#### C-AGT-2
- label: /review & CI action fixes
- desc: `/review a..b`, `HEAD@{1}` and `@{u}` bill a review turn with no diff attached. The composite `action.yml` install cannot resolve and has never run. Fix ref handling and run the action in CI.
- kind: fix
- severity: MAJOR
- effort: M
- sources: GIT-1-P1, GIT-1-P2, GIT-2-P1, GIT-2-P2
- recommend: yes — paid turns with empty input; CI action unusable

#### C-AGT-3
- label: Preset memory & MCP scope
- desc: The preset `memory:` key is parsed but unread, and `McpClient::setAgentPreset()` has no caller, so `mcp_call` ignores a preset's `mcpServers`. Wire per-agent memory and the MCP allowlist.
- kind: dormant-wire
- effort: M
- sources: GAP-MEM-13, GAP-MCP-11, k1-dorm#2, k2-dorm (`setAgentPreset`), k2-sum (MCP-11 ✅→🟡)
- recommend: yes — scoped sub-agents can reach every MCP server today

#### C-AGT-4
- label: Primary agents & @agent
- desc: Run the main chat as a preset (`/agent use`, a Tab cycle, a web select), add `@agent:<name>` mentions, and let SendMessage reach other sessions. Claude Code, OpenCode and OC2 parity.
- kind: gap
- effort: L
- sources: GAP-AGT-01/02, GAP-AGT-02w, GAP-AGT-20, GAP-AGT-N3, GAP-TL-23
- recommend: yes — headline OpenCode/Claude Code gap; messaging part optional

#### C-AGT-5
- label: Schedules, webhooks, CI bot
- desc: A model-callable Schedule tool, `/schedules add`, a web schedules table, a heartbeat kind, signed inbound and HMAC outbound webhooks, a PR-comment bot, a GitLab recipe. CC/OpenClaw.
- kind: gap
- effort: L
- sources: GAP-TL-22, GAP-AUT-07, GAP-PA-02, GAP-GIT-12, GAP-AUT-05, p1-miss: schedules UX, p1-miss: inbound webhook signatures, p1-miss: outbound webhooks, p2-miss: outbound webhooks, p2-miss: GIT-12 bot, p2-miss: GitLab CI component
- recommend: yes — Schedule tool and GitHub signatures first; bot needs a ruling

#### C-AGT-6
- label: /commit, /pr & worktrees
- desc: `/commit` drafting its message via CommitMessageWriter, a `/pr` canned turn, `--worktree` main sessions, `/review` ranges and `--pr`, and PR status chips in the web. Claude Code parity.
- kind: gap
- effort: M
- sources: GAP-GIT-03, GAP-GIT-04, GAP-GIT-08, p2-miss: built-in `/commit` (×2), p2-miss: GIT-10 review scope parity, p2-miss: WEB-8 PR chips
- recommend: yes — /commit reuses the existing writer; long-standing lead

#### C-AGT-7
- label: Automation minor/nit cleanup
- desc: Hand-written rows never fire, bypass rows run unattended, `-p` emits session_id before persisting, `--json-schema` ignored on the TUI and first-step only, dont-ask clamped up, webhook hygiene.
- kind: fix
- severity: MINOR/NIT
- effort: M
- sources: AUT-1-P2, AUT-1-P3, AUT-1-P4, AUT-2-P1, AUT-4-P1, AUT-4-P2, AUT-6-P2, AUT-6-P3
- recommend: yes — closes unattended-bypass and silent-ignore holes

### Skills, commands, hooks & plugins (EXT)

#### C-EXT-1
- label: Claude hook launch refusals
- desc: Hook names collide across the user and project `.claude/settings.json`, and any `async`, `statusMessage` or non-command type refuses the whole launch. Skip with a notice instead; file-qualify names.
- kind: fix
- severity: MAJOR
- effort: M
- sources: INT-4-P1, INT-4-P2, INT-4-P3, GAP-HK-07, k2-sum (HK-07 ✅→🟡), p1-miss: CC hook `type: prompt|agent`
- recommend: yes — common Claude Code layouts stop candy-code from starting

#### C-EXT-2
- label: Wire dormant skill code
- desc: Wire `dispatchSkill` (context: fork), `loadSkillAsset` and SkillManager enable/disable. Honour the inert keys: skill allowed/disallowed-tools, model and effort; command model, agent and subtask.
- kind: dormant-wire
- effort: L
- sources: GAP-SKL-10, GAP-SKL-15, GAP-SKL-11, GAP-CMD-04, k2-dorm (`dispatchSkill`, `loadSkillAsset`, App helpers, SkillManager accessors, inert frontmatter), k1-dorm#8
- recommend: yes — owner law; Claude Code skills rely on these keys

#### C-EXT-3
- label: Skill reload, search, create
- desc: `/skills reload` delivered as turn-context deltas, `skills_search` instead of silent listing truncation, a bundled skill-creator, `/agents new`, self-learning skills. From OpenClaw, OpenCode and CC.
- kind: gap
- effort: L
- sources: GAP-SKL-13, GAP-SKL-14, GAP-AGT-07, CLAW-new#1, CLAW-new#2, OC-new#6
- recommend: yes — reload, search and creator are M; self-learning optional

#### C-EXT-4
- label: Plugin marketplace
- desc: `bundles marketplace add` reading Claude's `marketplace.json`, install by name, `bundles update` and `search`, `/<bundle>:<cmd>` namespacing, and recommendations. In CC, OpenCode and OpenClaw.
- kind: gap
- effort: L
- sources: GAP-SKL-09, GAP-PLG-02, GAP-CMD-10, p1-miss: plugin registry/marketplace tier, CC-new#12 (plugin recommendations)
- recommend: yes — after C-SEC-1 and C-SEC-3 harden bundle installs

#### C-EXT-5
- label: Full palette & key-bound cmds
- desc: The palette has 49 rows. Add every arg-taking slash command, file command and MCP prompt, and let any slash command bind to a key (`command:<name>`). OpenCode/Claude Code.
- kind: gap
- effort: M
- sources: GAP-CMD-08, CC-new#12 (key-bindable slash commands)
- recommend: yes — makes the palette a true superset

#### C-EXT-6
- label: HTTP/prompt hooks & hook log
- desc: `type: http` and `type: prompt` hooks (Claude Code has five kinds), a `/hooks log` ring of recent verdicts, and the envelope's `systemMessage` shown as a notice. From the audit.
- kind: gap
- effort: L
- sources: GAP-HK-03, GAP-HK-08
- recommend: no — L effort; the hook log alone is the cheap win

#### C-EXT-7
- label: Extensions minor/nit cleanup
- desc: Bundle decline notices miss Claude's manifest keys, bundle install inconsistencies, read-only `/hooks` rebuilds the launch chain, the palette "superset" wording.
- kind: fix
- severity: MINOR/NIT
- effort: S
- sources: PLG-1-P4, PLG-1-P5, VIS-3-P2, VIS-4-P1
- recommend: yes — small hygiene fixes

### MCP

#### C-MCP-1
- label: MCP cwd & hook-bypass holes
- desc: User-tier stdio servers start with cwd = the untrusted checkout, and deferred tools called via `mcp_call` escape `mcp__*` PreToolUse hooks and allow rules. Spawn in $HOME; match both names.
- kind: fix
- severity: MAJOR
- effort: M
- sources: MCP-3-P1, MCP-7-P1, MCP-7-P2
- recommend: yes — untrusted repos can run code; deny hooks silently lapse

#### C-MCP-2
- label: MCP on serve, web, -p
- desc: `@mcp:` mentions and `/mcp__server__prompt` commands work only in the TUI, because SessionHost and `-p` never get the MCP provider. Wire them, and add a web resource picker (OpenClaw).
- kind: dormant-wire
- effort: M
- sources: GAP-MCP-06, GAP-MCP-07, GAP-CMD-09, MCP-1-P1, p1-miss: MCP surfaces in serve/web and `-p`, CLAW-new#9, k2-dorm (`ContextMentions`, `McpPromptCommands`)
- recommend: yes — small wiring, big web and headless parity win

#### C-MCP-3
- label: Elicitation, roots, streaming
- desc: Answer server requests (ping, roots/list, elicitation forms in the TUI and web), add the streamable-HTTP GET channel and progress, and move long calls to background. Claude Code, OpenCode 2.
- kind: gap
- effort: L
- sources: GAP-MCP-08, GAP-MCP-03, p1-miss: MCP elicitation/sampling/roots/logging/ping, OC-new#1, CC-new#2
- recommend: yes — ping, roots and elicitation first; streaming can trail

#### C-MCP-4
- label: MCP management & OAuth
- desc: Runtime `/mcp enable|disable`, web liveness and user-tier server rows (computed, never shown), `/mcp login` that a remote browser can finish, and `mcp import --write`. Claude Code/OpenCode.
- kind: gap
- effort: L
- sources: GAP-MCP-10, GAP-MCP-04, GAP-MCP-15, k2-dorm (`McpDecision` user servers)
- recommend: yes — user-tier rows are dormant; OAuth unreachable from the web

#### C-MCP-5
- label: candy-code as MCP server
- desc: `candy-code mcp serve` (stdio) exposes read-only tools (Read/Glob/Grep/RepoMap) under the permission gate, so other agents can call it. Claude Code and OpenClaw have it.
- kind: gap
- effort: L
- sources: GAP-MCP-13, p1-miss: MCP server mode
- recommend: no — L effort and needs an owner ruling first

#### C-MCP-6
- label: MCP minor/nit cleanup
- desc: `mcp_list` truncates at 64 KiB with no per-tool lookup, a forked child's SSE TLS close_notify, the login URL scraped from merged output, a stale COMPARISON line on reconnect.
- kind: fix
- severity: MINOR/NIT
- effort: S
- sources: MCP-7-P3, MCP-4-P1, MCP-5-P1, VIS-3-P1
- recommend: yes — small hygiene fixes

### Tools, shell & editing (TOOL)

#### C-TOOL-1
- label: Shell, grep & format fixes
- desc: The background-shell cursor loses stdout and replays stderr. rg honours `RIPGREP_CONFIG_PATH`, so a `--follow` line escapes the jail. The format hook re-records failed edits. All reproduced.
- kind: fix
- severity: MAJOR
- effort: M
- sources: SHL-1-P1, SHL-4-P1, EDT-1-P1
- recommend: yes — data loss, jail escape and corrupted output, all reproduced

#### C-TOOL-2
- label: Web fetch, search & media
- desc: WebFetch converts HTML to Markdown, search gets pluggable backends (Brave/Tavily/Exa, Anthropic web_search), and Read images, PDFs and MCP images reach every vision wire. Every competitor has these.
- kind: gap
- effort: L
- sources: GAP-TL-12, GAP-TL-13, GAP-TL-19 (wire half), GAP-TL-20, GAP-MCP-14, SHL-3-P1, k2-sum (TL-19/TL-20/MCP-14 downgrades), p1-miss: documents on non-Anthropic wires, p2-miss: SHL-3 PDF as document
- recommend: yes — every competitor converts HTML and reads PDFs

#### C-TOOL-3
- label: Live shell tail & /shells
- desc: The TUI records `ToolProgress` but never paints it. Add a live tail under running tools, `/shells` list/output/stop (plus a web Processes tab) and per-session shell tables on serve.
- kind: dormant-wire
- effort: M
- sources: GAP-SH-08, GAP-SH-04, k2-dorm (`ToolProgress` unpainted), k2-sum (live defect), CLAW-new#6, p2-miss: SHL no web/serve shell list
- recommend: yes — the data already flows; every competitor shows it live

#### C-TOOL-4
- label: Sandbox breadth & egress
- desc: A sandbox-backend seam with macOS Seatbelt, jails for hooks, lint, LSP and MCP, a CONNECT-proxy domain allowlist, Claude Code's bwrap hardening, and a macOS setsid helper. CC/OpenClaw.
- kind: gap
- effort: L
- sources: GAP-SH-06, GAP-SH-07, CC-new#11, p2-miss: SHL macOS/Windows setsid + timeout fallback
- recommend: no — L effort; Linux bwrap already covers the main path

#### C-TOOL-5
- label: Edit safety, formatters, LSP
- desc: Opt-in read-before-write, UTF-8 BOM preservation, a default formatter table gated on installed binaries, and an explicit `candy-code lsp install <lang>`. Claude Code/OpenCode.
- kind: gap
- effort: L
- sources: GAP-EDT-02, GAP-EDT-10, GAP-EDT-04, GAP-EDT-07
- recommend: yes — S/M safety wins; LSP download needs a ruling

#### C-TOOL-6
- label: New tool types (long tail)
- desc: NotebookEdit, a code-mode Script tool, user script and client-hosted tools, Canvas, browser/computer-use MCP presets, and typing into a running pty (TUI + web terminal). The long tail.
- kind: gap
- effort: L
- sources: GAP-TL-15, GAP-TL-N3, GAP-PLG-04, GAP-TL-24, GAP-TL-21, GAP-TL-25, GAP-SH-01, GAP-SH-05, OC-new#2, OC-new#12 (Code Mode), p2-miss: WEB-8 Terminal panel
- recommend: no — a grab-bag of L items; pick individually later

#### C-TOOL-7
- label: Tools minor/nit cleanup
- desc: Wire the dead `forgetHandles()`; `ulimit -f` kills big writes; misleading test names; Ls sorts the whole dir; LSP auto-detect runs untrusted project code; CC's tool-call retry and JSON escaping.
- kind: fix
- severity: MINOR/NIT
- effort: S
- sources: SHL-1-P2, SHL-1-P3, SHL-1-P4, SHL-2-P1, EDT-2-P1, CC-new#12 (unparseable tool-call retry, JSON-output escaping)
- recommend: yes — small; wires dead `forgetHandles()` instead of deleting it

### TUI & UX (TUI)

#### C-TUI-1
- label: Vim text objects & counts
- desc: The sugar-forms text-object machinery has zero callers. Wire `d/y/c` with `i/a` objects, `c`, counts and line-wise `V` into ComposerVim; settle the R4 VisualLine ruling.
- kind: dormant-wire
- effort: M
- sources: GAP-UI-07, k3-dorm#2, k3-sum (§6.6 vim), p2-miss: TUI-3 counts/`cw`/text objects, p2-miss: TUI-3/R4 ruling
- recommend: yes — the code is already written; owner law

#### C-TUI-2
- label: Diff review & highlighting
- desc: A diff-review overlay (file list, ± counts, checkpoint-base cycling) with a web base selector, side-by-side diffs when wide, more highlight languages and highlighted diffs. CC/OpenCode.
- kind: gap
- effort: L
- sources: GAP-GIT-09, GAP-UI-05, GAP-UI-04
- recommend: yes — the review pane is Claude Code's flagship diff surface

#### C-TUI-3
- label: Transcript actions
- desc: Pick-and-edit an earlier message (TUI picker; web "Edit & resend" and "Rewind to here"), `/copy` a reply or code block, a one-key verbose toggle, an inactivity recap. Claude Code parity.
- kind: gap
- effort: M
- sources: GAP-UI-15, GAP-GIT-06, GAP-UI-16, GAP-UI-32, GAP-UI-N4
- recommend: yes — everyday conveniences, mostly S–M

#### C-TUI-4
- label: Custom themes & statusLine
- desc: User JSON themes in `~/.candy-code/themes/` (with dracula and tokyoNight in the web too), and `statusLine` commands that receive Claude Code's session JSON on stdin. CC/OpenCode parity.
- kind: gap
- effort: M
- sources: GAP-UI-02, GAP-UI-23
- recommend: yes — Claude Code statusLine scripts expect the JSON

#### C-TUI-5
- label: Setup, doctor, config reload
- desc: A first-run provider wizard, a `/doctor` host command (web too, with an AppArmor userns check), settings hot reload with `/settings reload`, and Claude permissions import. CC/OpenCode.
- kind: gap
- effort: L
- sources: GAP-UI-29, GAP-CFG-06, GAP-CFG-05, GAP-CFG-08, CC-new#11 (doctor diagnostic)
- recommend: yes — first-run and doctor cut onboarding friction

#### C-TUI-6
- label: Windows, i18n, a11y, voice
- desc: Native Windows (ConPTY, shell grouping, self-update swap), TUI and web-chrome locales, a screen-reader render mode, push-to-talk and web dictation. Competitors have some or all of these.
- kind: gap
- effort: L
- sources: GAP-PLAT-04, p2-miss: Windows support (×2), GAP-PLAT-10, p2-miss: WEB-7c i18n, GAP-UI-30, GAP-MOD-23, GAP-WEB-13, OC-new#10
- recommend: no — each part is L; web dictation (S) could ship alone

#### C-TUI-7
- label: TUI minor/nit cleanup
- desc: The startup summary omits personal instruction files and runs git and a skill scan in `view()` behind a weak memo key; the macOS notice is an env-name probe that fires every launch.
- kind: fix
- severity: MINOR/NIT
- effort: S
- sources: VIS-1-P1, VIS-1-P2, TUI-5-P1
- recommend: yes — small fixes

### Web client (WEB)

#### C-WEB-1
- label: Web stale-state fixes
- desc: A served `/model` switch never updates the session row, so the picker sticks. The `@` file index is frozen for the daemon's life. Read-image MediaCards cannot be fetched. From the reviews and audit.
- kind: fix
- severity: MAJOR
- effort: M
- sources: WEB-2-P1, WEB-5-P2, TUI-1-P1, GAP-TL-19 (web half), k2-sum (`media.fetch` live defect), k2-dorm (broken Read-image path), p2-miss: @-mention freshness, p2-miss: web model switch
- recommend: yes — visible breakage in everyday web use

#### C-WEB-2
- label: Served command parity
- desc: Host bodies for TUI-only built-ins (`/review`, `/security-review`, `/effort`, `/budget`, `/help`), typed `/skill-name` on serve and `-p`, and `/btw` allowed mid-turn. Found by the audit.
- kind: gap
- effort: M
- sources: GAP-CMD-01, GAP-GIT-10, GAP-MOD-15w (`/effort` half), GAP-SKL-06, GAP-CTX-11w, k1-sum (c), p1-miss: VIS-5 parity, p2-miss: `/review` on serve/web/headless
- recommend: yes — 16 built-ins refuse `ui_only` in the web today

#### C-WEB-3
- label: Wire uncalled web RPCs
- desc: Seven RPCs lack a browser caller. Add a Files panel (`files.read`), memory History/Undo, session Share/Revoke, workflow run detail, close-root, and a video tab for `canGenerateVideo`.
- kind: dormant-wire
- effort: M
- sources: k3-dorm#3, k3-dorm#4, k1-dorm#4, k1-dorm#5, WEB-1-P1, SES-3-P4, p2-miss: WEB-8 Files panel, p2-miss: WEB-1 residual dormant methods
- recommend: yes — owner law; the server handlers already exist

#### C-WEB-4
- label: Composer & chrome polish
- desc: Drag-drop images, PDF upload, Ctrl+G history search, paste chips, a `?` help overlay, a keymap, an elapsed timer, a thinking toggle, expand-all and a branch badge. Claude Code/OpenCode.
- kind: gap
- effort: M
- sources: GAP-MOD-22w, GAP-UI-12, GAP-UI-09, GAP-UI-11, GAP-UI-28, GAP-UI-25, GAP-UI-31, GAP-UI-22, GAP-UI-32 (web half), GAP-GIT-01
- recommend: yes — mostly client-only S items

#### C-WEB-5
- label: Remote serve: TLS & push
- desc: `--tls-cert` on serve, `serve url --qr` pairing links, consent-gated Web Push for questions and completions, a `server.logs` tail and a confirmed Stop for Admin tokens. CC/OC2.
- kind: gap
- effort: L
- sources: GAP-WEB-04, GAP-WEB-10, GAP-WEB-N4, GAP-OBS-01, k3-dorm#3 (`server.shutdown`), p2-miss: WEB-8 pairing/QR, p2-miss: WEB-8 web push, OC-new#12 (pair/QR, push notifications)
- recommend: no — waits on owner security ruling R13

#### C-WEB-6
- label: SDK, IDE & desktop clients
- desc: Publish the TS protocol client (+ a PHP facade), a thin VS Code extension, a verified JetBrains ACP run, a desktop shell, `candy-code://` deep links, chat-channel daemons. CC/OpenCode.
- kind: gap
- effort: L
- sources: GAP-AUT-06, GAP-PLG-05, GAP-WEB-05, GAP-WEB-06, GAP-WEB-07, GAP-PRM-13, GAP-WEB-09, GAP-PA-01, GAP-PA-03, p2-miss: VS Code/IDE extension, CC-new#12 (deep links)
- recommend: no — mostly L; SDK publish and JetBrains check are cheap

#### C-WEB-7
- label: Web minor/nit cleanup
- desc: Export fails over 4 MiB, the frame cap has no trailing flush, `fs.search` is undebounced, cleared history returns from other tabs, README/docblock nits, a typed AskUser `options[]` field.
- kind: fix
- severity: MINOR/NIT
- effort: M
- sources: WEB-1-P2, WEB-4-P1, WEB-5-P3, WEB-6-P1, WEB-6-P2, WEB-6-P3, WEB-7-P1, p2-miss: WEB-5 structured AskUser `options[]`
- recommend: yes — small fixes

### Sessions, sharing, observability & distribution (SES)

#### C-SES-1
- label: Spend ledger honesty
- desc: Headless `-p` spend never reaches `usage_records`. The web shows $0.00 for unpriced sessions, the TUI prints an exact $, and the per-session cost cache is write-only. Fix it and show the spend.
- kind: fix
- severity: MAJOR
- effort: M
- sources: SES-1-P1, SES-1-P2, SES-1-P3, SES-1-P4, p2-miss: headless `-p` spend, p2-miss: per-session spend on picker/sidebar
- recommend: yes — breaks the ≥ law and under-reports spend

#### C-SES-2
- label: Session search fixes
- desc: FTS5 body hits are fetched and then hidden by the fuzzy gate, and the first search reindexes with an fsync per row on the TUI thread (128 s). Fix both, paint snippets, and add web body search.
- kind: fix
- severity: MAJOR
- effort: M
- sources: SES-2-P1, SES-2-P2, p2-miss: picker body snippet (`SessionRow::withBodySnippet` dormant), GAP-SES-11
- recommend: yes — the shipped full-text search is unreachable today

#### C-SES-3
- label: Share link lifecycle
- desc: Share pages outlive session delete and prune, and the token lands in the request log. Revoke on delete and add `/share list` across sessions; optional public read-only links (OpenClaw).
- kind: fix
- severity: MAJOR
- effort: M
- sources: SES-3-P1, SES-3-P2, p2-miss: cross-session `/share list` + web share UI, CLAW-new#8
- recommend: yes — share links survive deletion and leak into logs

#### C-SES-4
- label: OTel spans & serve export
- desc: Batching stamps spans at encode time (~0 ms), export POSTs run synchronously on the TUI path, and retry `tick()` is dormant. Also thread the sink into serve and `-p`.
- kind: fix
- severity: MAJOR
- effort: M
- sources: OBS-1-P1, OBS-1-P2, OBS-1-P3, OBS-1-P4, GAP-OBS-02, p2-miss: serve + headless OTel sink threading
- recommend: yes — exported telemetry is wrong today

#### C-SES-5
- label: Self-update & image fixes
- desc: Self-update reads the 119 MiB phar into memory (fatal at 128M), strips 0755 and hangs on stalls. The GHCR image ships a stale cached layer under new tags. Add versioned tags.
- kind: fix
- severity: MAJOR
- effort: M
- sources: DST-3-P1, DST-3-P2, DST-3-P3, DST-3-P4, DST-3-P5, DST-2-P1, DST-2-P2, p2-miss: versioned Docker tags/provenance
- recommend: yes — updates fail on default PHP; images are mislabeled

#### C-SES-6
- label: Installer, brew & updates
- desc: An `install.sh` that verifies sha256 and minisig, a Homebrew tap, an update-available notice, reproducible and slim phars, and phars that boot without the `.phar` suffix. Competitors all ship these.
- kind: gap
- effort: M
- sources: GAP-PLAT-03, GAP-PLAT-05, DST-1-P1, p2-miss: packaging channels, p2-miss: reproducible phar builds, p2-miss: slim phar
- recommend: yes — every competitor has a one-line installer

#### C-SES-7
- label: Session import & lifecycle
- desc: Import Claude Code/Codex/OpenCode transcripts, push a local session to a server, let `attach` run slash commands and see foreign turns, plus incognito, archive/restore and a resume-cost guard.
- kind: gap
- effort: L
- sources: GAP-SES-10, GAP-SES-14, GAP-WEB-N5, GAP-SES-N1, CLAW-new#3, OC-new#8, CC-new#10 (resume-cost guard), p2-miss: session import
- recommend: yes — session import is a gap against all three competitors

#### C-SES-8
- label: Usage insights & JSONL
- desc: A web Usage panel with retention, an `/insights` report, `/usage` attribution to skills, agents and MCP with advice, a `candy-code usage` CLI, JSONL transcripts plus `transcript_path`. Claude Code.
- kind: gap
- effort: L
- sources: GAP-SES-15w/17w, GAP-OBS-03, GAP-OBS-06, GAP-OBS-05, CC-new#5, OBS-3-P1, p2-miss: OBS-06 insights, p2-miss: optional `candy-code usage` verb
- recommend: yes — the web Usage panel is S; insights optional

#### C-SES-9
- label: Sessions/dist minor cleanup
- desc: A late settle resurrects deleted rows, a ledger docblock and full scans, a share buffer leak, serve `/status` text, unsnapshotted totals, the phar lacks agent presets, release `${{ }}` interpolation.
- kind: fix
- severity: MINOR/NIT
- effort: S
- sources: SES-1-P5, SES-1-P6, SES-3-P3, OBS-2-P1, OBS-3-P2, DST-1-P2, DST-1-P3, DST-1-P4, p2-miss: OBS-05 doc drift
- recommend: yes — small fixes

### Security, permissions & trust (SEC)

#### C-SEC-1
- label: Bundle install symlink wipe
- desc: The cleanup after a failed `bundles install <git-url>` follows symlinks in the clone, so a repo with `x -> ../../..` empties $HOME (reproduced). Never descend links; clone with symlinks off.
- kind: fix
- severity: CRITICAL
- effort: S
- sources: PLG-1-P1, PLG-1-P3
- recommend: yes — reproduced $HOME deletion from a malicious repo

#### C-SEC-2
- label: Full diff in web approvals
- desc: The web Edit approval previews only the top-level old/new pair and drops `edits[]` and `replace_all`, so the approver sees one hunk while Edit writes N. Ship a server-computed diff.
- kind: fix
- severity: CRITICAL
- effort: M
- sources: WEB-5-P1, WEB-5-P4, GAP-EDT-03
- recommend: yes — approvers currently authorize changes they cannot see

#### C-SEC-3
- label: Third-party shell posture
- desc: Commands from git-cloned bundles and MCP prompt bodies run `!cmd` shell forms unprompted; MCP does so under an unrelated trust grant. Use one provenance-aware shell gate for every tier.
- kind: fix
- severity: MAJOR
- effort: M
- sources: PLG-1-P2, MCP-2-P1, p1 Summary theme (`refuseCommandShell`), p1-miss: unified third-party shell posture
- recommend: yes — remote text reaches the local shell unprompted

#### C-SEC-4
- label: Serve trust fixes
- desc: `context.trust` cannot reach non-primary roots (the prompt loops forever), failed Always-trust writes are swallowed, and served commands ignore `trustedProjectCommands`. Route by root.
- kind: fix
- severity: MAJOR
- effort: M
- sources: WEB-3-P1, WEB-3-P2, WEB-3-P3, GAP-CMD-02, k2-sum (live defect), p2-miss: WEB-3 trust prompt discovery
- recommend: yes — trust is unanswerable on every secondary root

#### C-SEC-5
- label: Trust dialog & targets
- desc: TrustAsks has one target (MCP), and the trusted hooks/commands/settings lists have no prompt anywhere. Add a TUI launch trust dialog, web targets and MCP pin re-approval. Claude Code.
- kind: dormant-wire
- effort: L
- sources: GAP-PER-11, GAP-MCP-N1, k3-dorm#5
- recommend: yes — the registry exists; Claude Code-style launch trust dialog

#### C-SEC-6
- label: Permission rules UI & audit
- desc: List, edit and revoke saved grants (TUI + web Rules), send `remember: user` over the wire, allow project deny/ask-only rules, add a web audit view and OTel safety events per verdict. OpenCode/CC.
- kind: gap
- effort: M
- sources: GAP-PER-04, OC-new#3, GAP-PER-14, CC-new#4
- recommend: yes — saved grants are invisible and unrevocable today

#### C-SEC-7
- label: Safer defaults & review
- desc: `-p` defaults to Manual/deny like Claude Code, `--restricted`/`--safe-mode`, plan mode drops write tools from the roster, and model-judged review covers writes, with a chosen reviewer model.
- kind: gap
- effort: M
- sources: CC-new#1, GAP-PER-N2, GAP-TL-27, k2-sum (TL-27 web ✅→🟡), GAP-PER-07, CC-new#8
- recommend: yes — bypass-by-default `-p` is now the outlier

#### C-SEC-8
- label: Secret & untrusted hygiene
- desc: Fence MCP tool results as untrusted, redact secrets (opt-in) before tool output reaches history, strip invisible Unicode at submit, and add `${keychain:…}` provider keys. CC/OpenClaw.
- kind: gap
- effort: M
- sources: GAP-PER-10, GAP-PER-09, GAP-UI-N5, GAP-MOD-25
- recommend: yes — MCP fence is S and closes a prompt-injection path

#### C-SEC-9
- label: Managed tier & multi-user
- desc: Root-owned `/etc/candy-code` managed settings, instructions and hooks that users cannot override, plus per-user serve tokens with scope subsets. Claude Code/OpenClaw enterprise features.
- kind: gap
- effort: L
- sources: GAP-PER-13, GAP-INS-06, GAP-HK-06, GAP-PA-08
- recommend: no — enterprise demand unproven; owner ruling needed

---

## Overflow

These were dropped because they are out of scope, deferred by design, or need no action.

- GAP-MOD-09 + p1-miss "PRV-4 tier 3 MCP-OAuth extraction": native subscription OAuth logins. Scoped out by design (vendor terms). The keychain half went to C-SEC-8.
- GAP-AGT-N4: thread-bound sub-agent sessions. Needs a chat-channel surface first.
- GAP-PA-05 / PA-06 / PA-07: device nodes, voice wake, email/calendar. Out of product scope; use MCP servers.
- GAP-WEB-12: cloud execution. Out of scope; the Docker serve path is the answer.
- OC-new#11: live heap-dump diagnostics (`debug heap-dump --server`). Low value for a PHP daemon.
- Parity notes needing no plan item:
  - CC-new#3: tool search behind gateways.
  - CC-new#6: on-demand instruction cap.
  - CC-new#9: subagent fork/background.
  - CLAW-new#10: TL-N2, CTX-N4 and SH-09 are already at parity.
  - OC-new#7: background compaction, already pre-fetched; skip-rule parity is in C-CTX-5.

---

## Suggested order

- **Wave 0: security and data-loss fixes, no dependencies.**
  - C-SEC-1, C-SEC-2, C-SEC-3, C-MCP-1, C-TOOL-1, C-AGT-1, C-EXT-1.
  - Land C-SEC-1 and C-SEC-3 before C-EXT-4 (the marketplace installs through `bundles install`) and before C-MCP-2 (wiring MCP prompts into serve widens the `!cmd` hole).
- **Serve parity:**
  - C-CTX-2's notice drain comes first. It feeds P-CTX-BREAKDOWN's live reload and the served prompt suggestions.
  - C-SEC-4 comes before C-SEC-5, which widens the trust targets that C-SEC-4 makes routable.
- **Fix before extend:**
  - C-PRV-1 → C-PRV-3 (ultrathink rides the honest effort map) and C-WEB-2 (the `/effort` host body).
  - C-AGT-2 → C-AGT-6 (review ranges) and C-WEB-2 (the `/review` host body).
  - C-AGT-1 → C-AGT-5 (the Schedule tool rides root-bound rows and the serve pump).
  - C-EXT-1 → C-EXT-6 (hook kinds need the tolerant parser).
  - C-SES-2 → C-CTX-3 (model recall over sessions) and C-SES-7.
  - C-SES-3 → C-WEB-3 (the Share/Revoke menu).
  - C-SES-4 → C-SEC-6 (OTel safety events).
  - C-SES-5 → C-SES-6 (the update notice and installer use the fixed self-update).
- **Wire before extend:**
  - C-PRV-2 (the Responses wire) → C-TOOL-2's OpenAI image-in-tool-result leg.
  - C-AGT-3 (preset MCP scope) → C-AGT-4 (primary agents run under presets).
  - C-MCP-2 → C-MCP-3 (web elicitation uses the same served provider).
  - In C-TOOL-3, the `/shells` list follows C-TOOL-1's cursor fix.
- **The four picks:**
  - P-SESSION-TABS (one TurnRunner per tab) and P-AGENT-ATTACH share the session-window renderer. Sequence them in one lane or in consecutive waves.
  - P-CTX-BREAKDOWN consumes C-MCP-4's user-tier rows if both are picked.
- **Protocol chain (one lane per wave):**
  - C-SEC-2, C-SEC-4, C-SEC-5, C-SEC-6, C-WEB-4 (PDF upload), C-WEB-5, C-MCP-3, C-MCP-4, C-SES-7 (`session.import`), C-SES-8 and probably P-CTX-BREAKDOWN all touch `src/Protocol/**` → `generated.ts`.
  - Spread them across waves.
- **Owner rulings that gate items:**
  - R4 VisualLine: C-TUI-1.
  - R13 remote security: C-WEB-5, C-AGT-5's bot, C-AGT-6's `/pr`.
  - Prompt-append doctrine: C-CTX-4.
  - Spend levers: C-CTX-5.
  - LSP download policy: C-TOOL-5.
  - Never-write import: C-MCP-4.
  - `autoReview` default: C-SEC-7.
  - Serve telemetry: C-SES-4's serve leg.
