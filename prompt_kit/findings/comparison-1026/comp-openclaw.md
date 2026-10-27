# comp-openclaw — OpenClaw column refresh (2026.9.8 → 2026.10.7)

## Summary

**Verdict:** bump the column to **`OpenClaw 2026.10.7`**. The refresh changes the text of 19 cells (15 taxonomy rows and 4 🆕 rows) but **no glyphs**, so every §2 scoreboard count stays the same and only the header changes. Most of the changes come from one release, 2026.10.5. That is a large feature release (2,250 PRs) with these headline changes:
- Skill Workshop 2.0;
- `skills_search` / `skills_read` tools;
- Subagents and Processes panels;
- public conversation links;
- the X channel;
- an always-on stop after repeated identical tool errors.

One 2026.10.7 behaviour change matters for INS-01: managed Claude CLI turns no longer load `CLAUDE.md`.

- **Versions** (`npm view openclaw dist-tags time --json`, 2026-10-26):
  - `latest` is 2026.10.7, published 2026-10-24.
  - `extended-stable` is 2026.9.34, a Gateway-only LTS line.
  - `beta` is 2026.10.10-beta.3.
  - The stable releases after 2026.9.8 are 9.9, 10.1, 10.2, 10.3, 10.4, 10.5, 10.6 and 10.7. **There is no 2026.10.0.**
- **Appendix B's claim** is "release notes up to 2026.10.4 … later releases are reliability fixes only and change no row":
  - **"Change no row" holds for 9.9–10.4:** no glyph moves.
  - **"Reliability fixes only" is overstated:**
    - 10.1 has a 13-item `### Changes` list (Code Mode store/load persistence, Workboard sidebar, MCP Apps tool calls, memory-plugin-owned flush, cloud workers for enterprise repos, and others).
    - 9.9 and 10.3 add models.
  - **Releases after 10.4 do change cells:**
    - 10.5 is a major feature release.
    - 10.6 is a security patch (Node floor raised).
    - 10.7 changes INS-01's basis.
- **Sources:**
  - The installed 2026.9.8 package, used as the baseline.
  - `npm pack openclaw@2026.10.7`, extracted to `/tmp/claude-1000/-home-sites-sugarcraft/10a6cc1f-690d-425f-9160-c07945c954f0/scratchpad/comp-openclaw/pkg-10.7/` and read only.
  - A docs-tree diff (`diff -rq`: 703 files changed, 12 added, 9 removed).
  - The per-version changelogs at `https://raw.githubusercontent.com/openclaw/openclaw/main/CHANGELOG/<ver>.md` for 2026.9.9 through 2026.10.7.
  - The GitHub releases (`gh release view`).
  - A spot-check of docs.openclaw.ai: `/tools/skills` serves the `skills_search` text.
- **Evidence notation:** `oc@2026.10.7/…` means a path inside that npm tarball.
- **Header and label renames for the applier:**
  - `"OpenClaw 2026.9.8" → "OpenClaw 2026.10.7"` covers the §2/§3 header rows and 398 Appendix A labels.
  - Four more occurrences are narrative: L11, §4.1 header L551, Appendix B L4937 and L4940. They are listed under Narrative fixes.
- **Cells that were already wrong at 2026.9.8:** none found. The stale items were only:
  - PLAT-05 "npm latest = 2026.10.4";
  - the `noContextFiles: true` evidence for INS-01, a loader flag that no longer exists in the 10.7 dist.
- **UNVERIFIED:**
  - 10.7 startup time and installed size. 10.7 was not installed or run, per the brief.
  - Whether standalone Claude CLI runs without Gateway MCP, and paired-node Claude runs, still load `CLAUDE.md`.
  - A doc-versus-changelog discrepancy: 10.2's changelog says Anthropic provider compaction is on by default, while `oc@2026.10.7/docs/providers/anthropic.md` says "Anthropic server-side compaction is opt-in". No cell depends on it.

## Changelog digest

Each version's changelog is at `CHANGELOG/<ver>.md` (raw URL above). Category tags are the COMPARISON category codes.

**2026.9.9** (npm 2026-10-08; 112 PRs) is mostly reliability work: update and Doctor recovery, Windows, iMessage and Discord delivery, cron. New:
- Claude Haiku 5.5 via the API and Claude CLI; the `haiku` alias and the Anthropic utility-model default move to 5.5 [MOD].
- GPT-6.1 Sol in the Codex list; bundled Codex 0.160.0 [MOD].
- Telegram `/controlui` Mini App; `/dashboard` now opens session dashboards [WEB].
- Plugin input policies can reject requests before they run on paired computers [HK, PER].
- The Windows MXC sandbox runs without a service [SH].
- Row impact: none.

**2026.10.1** (10-10; 396 PRs). `### Changes`:
- Code Mode `store`/`load` values persist across cells and restarts [TL-N3].
- Workboard sidebar navigation and board pinning [AGT-N1, UI].
- MCP Apps can call their tools while their views stay open, with synced forms, files and context [MCP].
- Inline YouTube playback [UI].
- macOS sidebar identity and plugin session catalogs [WEB-05, SES-10].
- Sealed immutable installation generations with activation and recovery [PLAT-05].
- The selected memory plugin owns the pre-compaction flush [MEM-15].
- Per-plugin isolated state and ingress queues [PLG].
- Cloud workers for enterprise repos [WEB-12].
- RPC response-size and heap diagnostics [OBS].
- Haiku 5.5 [MOD].
- 19 plugin-SDK deprecations reached their removal targets [PLG-05].
- Row impact: none at glyph level.

**2026.10.2** (10-12; 80 PRs) is fixes:
- Provider-managed compaction checkpoints for eligible OpenAI Responses (and, per the changelog, Anthropic API-key) chats [CTX-05]. The OpenAI compact endpoint was already default in 9.8 docs.
- `/stop` clears queued follow-ups [UI-14].
- Credential-masking fixes for long tool output [PER-09].
- Row impact: none.

**2026.10.3** (10-13; 79 PRs) is update-blocker fixes:
- Meta Muse Wonder and MiniMax M3.1 Flash Preview [MOD].
- IPv6 provider fallback [MOD].
- FreeBSD npm-package recovery helpers [PLAT-04].
- Row impact: none.

**2026.10.4** (10-15; 55 PRs) is reliability:
- `/compact` gives an active answer up to 60 s [CTX-04].
- Standalone plugin hook files take effect at startup [HK].
- Headless passkey dismissal [TL-21].
- Row impact: none.

**2026.10.5** (10-18; 2,250 PRs, 637 contributors) is the **feature release**:
- **Sidebar** [AGT-12, UI-19, SH-04]: agent search and pinning, plus **Subagents and Processes side panels** with targeted Stop (#164061).
- **Skill Workshop 2.0** [SKL-14, PLG-N2]: the agent saves and patches learned skills directly, with change history, saved versions, Undo, archive and restore, and auto-archive of skills unused for 30 days (#161057). The proposal queue, `workshop evaluate/apply` and the `skill_proposal_evaluate` / `skill_proposal_changed` hooks were removed.
- **`skills_search` / `skills_read` tools** [SKL-04, SKL-05, PRM-06]: a bounded prompt directory, with omitted skills searchable. The tools are absent from the 9.8 dist and present in 10.5+.
- **Public conversation links** at the normal chat URL, readable anonymously (#166049) [SES-09; detail only].
- **X/Twitter channel**, bundled, with guest mode (#165354) [WEB-09].
- **Phone-call task briefs**, steering and outcome reports (#165716) [PA, WEB-13].
- **Always-on stop after 3 consecutive identical tool errors** (#167628) [TL-N2].
- **Automatic compaction can continue after a summary timeout**, dropping older unsummarized context (#164246) [CTX-05].
- **Managed worktrees** get a global cap (`worktreeMaxCount`, default 4096) with eviction, and background gc (#164758) [GIT-08, AGT-18; detail].
- Gateway clients can browse and search ClawHub skills and plugins (#163459) [SKL-09, PLG-02].
- Gemini 4 Argon and Gemini 3.8 Flash (#171852) [MOD-03].
- Plugin hooks need fresh approval before changing trusted, inline-approved tool arguments (#142700) [HK-05].
- Restricted senders may delegate only to hidden helpers [PER-05].
- Exec-policy JSON error document [AUT-12].
- The experimental **`openclaw fleet` command was retired** (#165825); no row cites it.
- The SQLite schema moved from 24 to 25.

**2026.10.6** (10-22; 233 PRs). "This is a patch release with no new features."
- Security: eMudhra certificate remediation; the **Node floor is now 24.22.1 / 26.12.1** [PLAT-01].
- Truncated command output keeps its tail (#174030) [SH-09].
- Native apps resume chats after reconnecting [WEB-N3].
- Queued messages survive Gateway restarts [SES].
- `/new` and `/reset` keep the managed checkout [GIT].
- Opt-in `agents.defaults.compaction.midTurnPrecheck` [CTX].
- `/btw`, `/side` and `/loop` keep multiline input [CTX-11, AUT-08].

**2026.10.7** (10-24; 61 PRs) is a patch release:
- **Managed Claude CLI turns now use OpenClaw's tool surface and stop loading Claude user settings, `CLAUDE.md` and rules, auto-memory, user hooks and plugins, Chrome, and Claude-only MCP servers** (PR #172026) [INS-01, HK-07, MCP].
- Agents continue unfinished tool work after an empty length-limited response (#181553) [CTX-N4].
- A multi-file `apply_patch` validates every target before changing any [TL-05].
- Gemini 4 Argon added [MOD].

**extended-stable 2026.9.33 / 9.34** (10-15 / 10-24) and **beta 2026.10.10-beta.3** (10-21) are LTS backports and beta reliability work. Neither affects a row.

## Cell changes

### PLAT-01 · OpenClaw 2026.10.7
- current: `✅ TypeScript on Node 24.16+/26 (Bun opt-in); native Swift/Kotlin/WinUI companion apps; Control UI is Vite + Lit`
- new: ✅ TypeScript on Node 24.22.1+/26.12.1+ (Bun opt-in; Linux companion/macOS app use a bundled Bun fork); companion apps; Lit UI
- detail: ✅ — Gateway, CLI, TUI and the embedded agent runtime ship as the `openclaw` npm package (ESM bundles; 2026.10.7 tarball: 14,041 files). `engines.node` is `>=24.22.1 <25 || >=26.12.1`, raised in 2026.10.6 for the eMudhra certificate compromise; Node 26 is recommended and Bun 1.4+ is opt-in, while fresh Linux-companion setups and the macOS app run the Gateway on a bundled OpenClaw Bun fork. Companion apps: macOS menu-bar, iOS/watchOS, Android, Windows Hub (WinUI), Linux companion; the Control UI is a Vite + Lit SPA. — _Evidence:_ `oc@2026.10.7/package.json` (engines); `oc@2026.10.7/docs/install/index.md`; `oc@2026.10.7/docs/platforms/linux.md` (Use the bundled runtime); CHANGELOG/2026.10.6.md (Security and Privacy)
- why: The Node floor rose in 10.6, and bundled-Bun Gateways are new.

### PLAT-05 · OpenClaw 2026.10.7
- current: `✅ `openclaw update` with stable / extended-stable / beta / dev channels, auto-updates, rollback, `/update` from chat`
- new: ✅ `openclaw update` with stable / extended-stable / beta / dev channels, auto-updates, rollback, `/update` from chat
- detail: ✅ — Four channels: stable (npm `latest`), extended-stable (a Gateway-only LTS line), beta and dev (git main). Automatic updates, guarded upgrades with Doctor, sealed immutable installation generations with activation and recovery, and a rollback/recovery journal; owners update from chat (`/update`) or Control UI Updates. npm dist-tags on 2026-10-26: latest 2026.10.7, extended-stable 2026.9.34, beta 2026.10.10-beta.3. — _Evidence:_ `npm view openclaw dist-tags time --json`; CHANGELOG/2026.10.1.md (Changes: sealed generations); `oc@2026.10.7/docs/cli/update.md`; https://github.com/openclaw/openclaw/releases/tag/v2026.9.34
- why: Detail-only refresh; the cell text is unchanged. The recorded "npm latest = 2026.10.4" is stale.

### PLAT-09 · OpenClaw 2026.10.7
- current: `🟡 Fast CLI cold start (0.14 s, ~68 MB RSS measured) but heavy install (735 MB package dir); Gateway is a daemon`
- new: 🟡 CLI cold start 0.14 s / ~68 MB RSS (measured on 9.8); npm package 250 MB unpacked (9.8: 389 MB); Gateway is a daemon
- detail: 🟡 — Measured on the installed 2026.9.8: `openclaw --version` took 0.14 s wall and 68.7 MB max RSS, and the installed package directory was 735 MB (dist + node_modules). 2026.10.7 was not installed or run here, so its startup and installed size are UNVERIFIED. Its npm tarball unpacks to 250 MB / 14,041 files against 389 MB / 13,320 for 2026.9.8 (`dist/` 239 MB vs 370 MB). The runtime is a long-lived Gateway with SQLite state, so startup is not paid per message. — _Evidence:_ `npm view openclaw@2026.10.7 dist.unpackedSize dist.fileCount`; `npm view openclaw@2026.9.8 dist.unpackedSize dist.fileCount`; `du -sh` of both `dist/` trees
- why: The footprint figure was version-specific; the package shrank by about 36%.

### MOD-N2 · OpenClaw 2026.10.7
- current: `✅ `/fast status/auto/on/off/default` maps to provider service tiers per session`
- new: ✅ `/fast status/auto/on/off/ultrafast/default` maps to provider service tiers per session (Ultrafast only on eligible Codex)
- detail: ✅ — `/fast auto|on|off|ultrafast` persists a session override and `/fast default` clears it. On OpenAI/Codex, ordinary Fast uses the priority service tier. Codex requests Ultrafast only for an explicit `ultrafast` choice when an authenticated app-server catalog advertises it for the selected native model (`appServer.enableUltrafast: false` disables it). Direct Anthropic maps to `service_tier=auto` or `standard_only`. — _Evidence:_ `oc@2026.10.7/docs/tools/slash-commands.md` (/fast)
- why: A new `ultrafast` level.

### INS-01 · OpenClaw 2026.10.7
- current: `🟡 Native runtime does NOT read CLAUDE.md; only via the claude-cli backend (Claude Code itself) or one-time `openclaw migrate claude``
- new: 🟡 No live CLAUDE.md read: native runtime ignores it, managed claude-cli turns exclude it (10.7); one-time `openclaw migrate claude`
- detail: 🟡 — The embedded runner injects only OpenClaw's own workspace bootstrap set; its 10.7 resource loader handles skills, prompts and extensions and has no ancestor context-file loader. Since 2026.10.7, managed `claude-cli` turns run on OpenClaw's policy-filtered tool surface and "exclude Claude's user settings, instruction files, auto-memory, user hooks, plugins, Chrome, and MCP servers configured only in Claude"; restricted runs already passed `claudeMdExcludes`. What remains is the one-time `openclaw migrate claude` import: project `CLAUDE.md` and `.claude/CLAUDE.md` go into workspace AGENTS.md, and `~/.claude/CLAUDE.md` into USER.md, with preview and backup. Whether standalone CLI runs without Gateway MCP still load CLAUDE.md is UNVERIFIED. — _Evidence:_ `oc@2026.10.7/docs/gateway/cli-backends.md` ("Managed turns also exclude…"); CHANGELOG/2026.10.7.md (PR #172026); `oc@2026.10.7/dist/settings-manager-DipDVfMv.mjs:3721` (DefaultResourceLoader); `oc@2026.10.7/dist/cli-shared-BZRFWmwK.mjs:31` (CLAUDE_RESTRICTED_SETTINGS); `oc@2026.10.7/docs/install/migrating-claude.md`
- why: The claude-cli path that justified the old wording no longer loads CLAUDE.md. The glyph stays 🟡 for consistency with INS-03, where Claude Code's import-only path is rated 🟡.

### PRM-06 · OpenClaw 2026.10.7
- current: `✅ `<available_skills>` XML (name, description, location) in the Skills section; model reads SKILL.md on demand`
- new: ✅ Budgeted `<available_skills>` directory (name, description, location); omitted skills found via `skills_search`
- detail: ✅ — The Skills section carries a bounded skill directory (budget `skills.limits.maxSkillsPromptChars`; small catalogs appear in full). When skills are omitted, the prompt says so explicitly and the agent is told to search by task goal with `skills_search` before work that involves files, specialized tools or a reusable workflow; `/context` counts only the catalog actually rendered. — _Evidence:_ `oc@2026.10.7/docs/tools/skills.md` (Search installed skills); `oc@2026.10.7/docs/concepts/context.md`
- why: The listing is no longer the only way the model discovers skills.

### CTX-05 · OpenClaw 2026.10.7
- current: `✅ Structured LLM summary; default "safeguard" mode audits required headings and keeps history if no summary passes`
- new: ✅ Structured LLM summary; safeguard audit keeps history if no summary passes; a summary timeout commits a summary-less compaction
- detail: ✅ — Built-in compaction summarizes older history in one model request, and safeguard mode audits required headings and identifiers. If an automatic compaction's summary times out (summary deadline, HTTP 408 or 504) while the turn is active, OpenClaw now commits the compaction without a summary. Recent messages and complete tool pairs stay verbatim and the previous summary carries forward, but older unsummarized facts leave the model context, even in safeguard mode; this is logged with no chat notice. If compaction is declined, a bounded recent-history view is used. The OpenAI Responses and xAI compact endpoints are the default; Anthropic server compaction is opt-in. — _Evidence:_ `oc@2026.10.7/docs/concepts/compaction.md` (Auto-compaction; Provider checkpoints); `oc@2026.10.7/docs/providers/anthropic.md` (Server-side compaction); CHANGELOG/2026.10.5.md (#164246)
- why: "Keeps history" no longer holds for summary timeouts.

### CTX-N4 · OpenClaw 2026.10.7
- current: `🟡 Auto-continues only when an OpenAI Responses reply hits the output limit while generating a tool call`
- new: 🟡 Continues after an output-limit cut mid tool call (OpenAI Responses) or an empty `length` reply; cut-off partial text is not
- detail: 🟡 — When a Responses request hits its output limit while generating a tool call, the admitted tools finish, the partial call never executes, and the run continues from the recorded results. Since 2026.10.7, an empty `length` response that leaves no completed or pending reply also continues the transcript with the normal tools, on the same retry budget. A partial text answer stopped at `length` is still not continued. — _Evidence:_ `oc@2026.10.7/docs/concepts/agent-loop.md` (Compaction and retries); CHANGELOG/2026.10.7.md (#181553)
- why: The continuation trigger is broader.

### SES-10 · OpenClaw 2026.10.7
- current: `✅ Session catalogs discover Claude Code, Codex, OpenCode, Pi sessions; Claude CLI rows can be adopted (history imported)`
- new: ✅ Session catalogs (Claude Code, Codex, OpenCode, Pi) + `openclaw sessions import claude|codex <id>` / `--all` into durable sessions
- detail: ✅ — Session catalogs discover native Claude Code, Codex, OpenCode and Pi sessions on the Gateway, paired nodes and apps. `openclaw sessions import <catalog> <thread-id>` (or `--all`, with `--catalog`, `--host`, `--limit` and `--dry-run`) copies a transcript into an ordinary OpenClaw session as untrusted reference material: a draft visible to its creator, at most 50,000 items / 64 MiB keeping the newest, and incremental on re-import. It does not resume the native session. — _Evidence:_ `oc@2026.10.7/docs/cli/sessions.md` (Import transcripts); `oc@2026.10.7/docs/nodes/session-catalogs.md`
- why: A CLI bulk-import surface was added.

### AGT-12 · OpenClaw 2026.10.7
- current: `✅ Control UI shows child transcripts live (view-only), unread child failures, running-tasks indicator; `/subagents log``
- new: ✅ Control UI Subagents panel (status, activity, view-only transcripts, Stop) + per-child rows in the chat; `/subagents log`
- detail: ✅ — The chat side panel has a **Subagents** tab that groups running and finished child runs with elapsed time, tool activity, view-only transcripts beside the parent, and a targeted Stop. Under the working line, each unfinished subagent gets a row with its name and current activity headline, the working indicator counts subagents down ("3 subagents running"), and launch rows show running, failed or stopped status. `/subagents list/info/log` remain. — _Evidence:_ `oc@2026.10.7/docs/web/control-ui.md`; `oc@2026.10.7/docs/web/control-ui/chat.md`; CHANGELOG/2026.10.5.md (#164061)
- why: There is a new dedicated live panel.

### SKL-04 · OpenClaw 2026.10.7
- current: `✅ Compact list (name, description, location) in the system prompt; body read with `read` when needed`
- new: ✅ Budgeted list (name, description, location) in the prompt; body via `skills_read`/`read`; `skills_search` finds omitted ones
- detail: ✅ — The prompt snapshot keeps names and locations first, then shortened descriptions, within `skills.limits.maxSkillsPromptChars`. Skills omitted by the budget remain discoverable through `skills_search` (an in-memory lexical index over names, descriptions and bounded body text, at most 20 results, with coverage reporting). `skills_read` loads a complete SKILL.md by exact name, up to 256 KiB for skills not in the prompt directory. — _Evidence:_ `oc@2026.10.7/docs/tools/skills.md` (Search installed skills; Token impact)
- why: There is now a fallback past the budget.

### SKL-05 · OpenClaw 2026.10.7
- current: `✅ Model picks skills itself by reading the listed SKILL.md (no dedicated Skill tool); `$skill` references`
- new: ✅ Model loads listed skills with `skills_read` (or `read`) and finds unlisted ones with `skills_search`; `$skill` forces reads
- detail: ✅ — There are now dedicated skill tools. `skills_search({query, limit?})` searches the session's eligible catalog, and `skills_read({name})` loads a complete SKILL.md. A `read` policy grant also permits `skills_read`, and an explicit `skills_read` denial wins. Code Mode exposes `skills.search/read/list`, and Codex gets the tools through its dynamic tool surface. Disabled, filtered and model-hidden skills never appear in results, and up to 8 `$name` references per message force reads. — _Evidence:_ `oc@2026.10.7/docs/tools/skills.md` (Search installed skills); `oc@2026.10.7/docs/plugins/codex-harness/runtime-behavior.md`; `skills_search` absent from the 9.8 `dist/`, present in `oc@2026.10.7/dist/tool-display-C9Npyj04.mjs`
- why: "No dedicated Skill tool" is now wrong.

### SKL-14 · OpenClaw 2026.10.7
- current: `✅ Bundled skill-creator; Skill Workshop proposals (`/learn`, `skill_workshop` tool); self-learning experience review`
- new: ✅ Bundled skill-creator; Skill Workshop 2.0: agent saves/patches learned skills directly, versioned with Undo/archive; `/learn`
- detail: ✅ — `skill_workshop` (list/view/create/patch/write_file/remove_file/archive/restore) is how the agent writes its own "learned skills". It does so mid-turn after hard multi-step work or a misleading skill, through a background review after 10 model iterations or a skill read (`skills.workshop.autonomous.mode: "auto"` by default), via `/learn [sources]`, or with "Learn from history". Every change applies immediately, is announced in chat ("Say "undo""), and saves the previous version (newest 10 kept). Skills unused for 30 days are auto-archived, and the Control UI and `openclaw skills workshop list|changes|show|archive|restore` show history and diffs. The proposal queue was removed in 2026.10.5. — _Evidence:_ `oc@2026.10.7/docs/tools/skill-workshop.md`; `oc@2026.10.7/docs/tools/self-learning.md`; CHANGELOG/2026.10.5.md (#161057)
- why: The proposal queue is gone; saves are direct and versioned.

### PLG-N2 · OpenClaw 2026.10.7
- current: `🟡 Skill Workshop evaluators (`workshop evaluate`, `skill_proposal_evaluate`) and SDK test utilities; no plugin cost/eval suite`
- new: 🟡 SDK test utilities, `--json` costUsd and a source-only Code Mode model matrix; Workshop evaluators removed in 10.5; no plugin eval
- detail: 🟡 — The plugin SDK ships testing utilities, and `openclaw agent --json` reports `costUsd`, assistant turns and tool summaries. The Code Mode model matrix (`pnpm qa:code-mode-models`) measures pass rate, latency and cost, but only from a source checkout. The Skill Workshop evaluators (`skills workshop evaluate`, `skill_proposal_evaluate` hooks) were removed with the proposal queue in 2026.10.5. There is no plugin effect or cost evaluation. — _Evidence:_ `oc@2026.10.7/docs/plugins/sdk-testing.md`; `oc@2026.10.7/docs/cli/agent.md`; `oc@2026.10.7/docs/plugins/sdk-migration/removed-surfaces.md` (Skill Workshop proposal hooks)
- why: The evaluators the cell cites no longer exist. The glyph is kept on costUsd plus the model matrix; `❌` is also defensible.

### MCP-06 · OpenClaw 2026.10.7
- current: `🟡 Resources reachable by the model via generated `resources_list`/`resources_read` tools; MCP Apps UI resources rendered`
- new: 🟡 Model reads resources via generated `resources_list`/`resources_read`; opt-in MCP Apps plugin extensions add composer attach
- detail: 🟡 — Resources reach the model only through the generated `resources_list`/`resources_read` tools, and there is no general user @-mention of MCP resources. With the opt-in MCP Apps host (`mcp.apps.enabled`), the Control UI also recognizes OpenAI MCP Plugin Extensions. Servers that advertise them can offer composer resource search and attach, app-selection model context, and file viewers (opaque resource URIs, rechecked per session). — _Evidence:_ `oc@2026.10.7/docs/cli/mcp/apps.md` (Plugin extensions); `oc@2026.10.7/docs/tools/mcp.md`; `oc@2026.10.7/docs/cli/mcp/registry.md`
- why: Plugin extensions are new since 9.8 (not in `oc/docs/cli/mcp/apps.md`) and give partial user-side resource attach.

### TL-N2 · OpenClaw 2026.10.7
- current: `🟡 Repetitive-call detection is opt-in; a post-compaction repeat guard is on by default`
- new: 🟡 3 identical consecutive tool errors always end the turn; general repeat detection is opt-in; post-compaction guard on by default
- detail: 🟡 — Since 2026.10.5, three consecutive identical tool errors (same tool, arguments and error result) always end the turn with a recorded error and recovery guidance, even with `tools.loopDetection.enabled: false`. Changed arguments or results, and successful calls, reset the streak. The rolling-history detectors for no-progress and repeated successful calls stay opt-in (`enabled: false` by default), and the post-compaction guard runs unless explicitly disabled. — _Evidence:_ `oc@2026.10.7/docs/tools/loop-detection.md`; `oc@2026.10.7/docs/gateway/config-tools/built-in-tools.md`; CHANGELOG/2026.10.5.md (#167628)
- why: A new always-on guard. It only catches identical errors, so the glyph stays 🟡.

### SH-09 · OpenClaw 2026.10.7
- current: `✅ Output bounded by live tool-result caps (16k–64k chars by window); Tokenjuice plugin compacts noisy output`
- new: ✅ Output bounded by live tool-result caps (16k–64k chars by window), tail kept when shortened; Tokenjuice plugin compacts noise
- detail: ✅ — Exec output is bounded by the live tool-result caps (16k/32k/64k chars by context window, ≤30% of the window). Since 2026.10.6, shortened output keeps its end, "where the error usually is". Backgrounded commands return a short output tail, and `process poll/log` read retained output. The Tokenjuice plugin compacts noisy output. — _Evidence:_ CHANGELOG/2026.10.6.md (#174030); `oc@2026.10.7/docs/gateway/background-process.md`; `oc/docs/gateway/config-agents/workspace-and-bootstrap.md`
- why: Tail-preserving truncation was added.

### UI-19 · OpenClaw 2026.10.7
- current: `✅ Control UI sidebar (agents, groups, people) + side panel tabs: Files, Review, Browser, Terminal, Desktop, Side chat, Dashboard`
- new: ✅ Control UI sidebar + panels: Files, Review, Browser, Terminal, Desktop, Side chat, Dashboard, Subagents, Processes
- detail: ✅ — The sidebar shows agents (rows, search over six agents, pinning, "Show all" team mode), groups and people. Side panels (via **+** or the chat header **Panels** menu) are Files, Review, Browser, Terminal (bundled Nerd Font), Desktop, Side chat, Dashboard, **Subagents**, and **Processes**: background exec commands with status, exit info, recent output and a per-process Stop. Plugin pages can dock a conversation beside them. — _Evidence:_ `oc@2026.10.7/docs/web/control-ui.md`; `oc@2026.10.7/docs/web/control-ui/panels.md`; `oc@2026.10.7/docs/gateway/background-process.md` (Control UI)
- why: Two new panels.

### WEB-09 · OpenClaw 2026.10.7
- current: `✅ 20+ channels: Telegram (bundled), WhatsApp, Discord, Slack, iMessage, Signal, Matrix, Teams, Google Chat, IRC, LINE...`
- new: ✅ 20+ channels: Telegram and X (bundled), WhatsApp, Discord, Slack, iMessage, Signal, Matrix, Teams, Google Chat, IRC, LINE...
- detail: ✅ — Most channels are official installable plugins (`clawhub:@openclaw/whatsapp`, `/discord`, `/slack`, and so on), with per-channel threads, rich messages and voice. Telegram and, since 2026.10.5, X/Twitter are bundled. X turns bot mentions into group-thread conversations with allowlisted public replies and an optional restricted guest mode; DMs and media uploads are not supported. — _Evidence:_ `oc@2026.10.7/docs/channels/index.md`; `oc@2026.10.7/docs/channels/x.md`; `oc@2026.10.7/dist/extensions/x/` (new vs 9.8); CHANGELOG/2026.10.5.md (#165354)
- why: There is a new bundled channel.

## New 🆕 row candidates

Glyph rules follow the legend. "UNRATED" means not researched here. The IDs are proposals; no `SKL-N`, `SH-N2` or `MCP-N2` rows exist yet.

1. **SKL-N1 · 🆕 Self-learning skills.** The agent saves and patches its own procedural skills from experience, versioned with undo.
   - **OpenClaw** ✅ — Skill Workshop 2.0, on by default:
     - mid-turn saves and patches;
     - a background review every 10 iterations;
     - `/learn`;
     - every change announced with "say undo";
     - 10 versions per skill;
     - archive/restore and 30-day unused auto-archive;
     - a Control UI diff/history library.
     - _Evidence:_ `oc@2026.10.7/docs/tools/skill-workshop.md`, `docs/tools/self-learning.md`.
   - **candy-code** 🟡 (from its SKL-14 cell): the opt-in dream pass proposes up to 3 drafts that the user accepts or rejects; there are no direct saves, versions or undo.
   - **candy-code-web** 🟡: `/skills accept` / `reject` only.
   - **Claude Code, OpenCode 1.18.35 and 2.0.31:** UNRATED.
2. **SKL-N2 · 🆕 Skill search beyond the prompt listing.** A model-callable search over installed skills that the budgeted listing omits.
   - **OpenClaw** ✅ — `skills_search` / `skills_read`, with coverage reporting. _Evidence:_ `oc@2026.10.7/docs/tools/skills.md`.
   - **candy-code:** UNRATED, likely ❌. §4.3 says the listing is "bounded by `skills.listingMaxBytes`, ranked truncation", and no search tool is described.
   - **Other columns:** UNRATED.
3. **SH-N2 · 🆕 Background-process inspector UI.** A list of running and finished shell processes with output and a per-process stop.
   - **OpenClaw** ✅ — the Processes panel. _Evidence:_ `oc@2026.10.7/docs/gateway/background-process.md` (Control UI).
   - **candy-code-web** 🟡 (from its SH-04 cell): the Background panel manages `/bg` sessions, with no per-shell-process list.
   - **Other columns:** UNRATED.
4. **MCP-N2 · 🆕 MCP Apps / interactive server UIs.** These are sandboxed server-provided views and app-to-tool calls; OpenAI plugin extensions add composer resources and file viewers.
   - **OpenClaw** ✅ — opt-in `mcp.apps.enabled`. MCP Apps predates 9.8; plugin extensions are new.
   - **Other columns:** UNRATED.
   - Lower priority; it could instead stay as MCP-06 detail.
5. **PA-N1 · 🆕 Delegated phone calls.** Place a call with a task brief, steer it live and get an outcome report.
   - **OpenClaw** 🔌 — the voice-call plugin, which is not in the bundled `dist/extensions`. _Evidence:_ CHANGELOG/2026.10.5.md (#165716).
   - **Other columns:** UNRATED.
   - Low priority for a coding agent.

## Narrative fixes

- Header table L11: current "| **OpenClaw** | 2026.9.8 (installed here) | The installed package's ~1,300 doc pages, `dist/` source, CLI help, the `clawhub` CLI, and GitHub changelogs to 2026.10.4 |" → new "| **OpenClaw** | 2026.10.7 (npm latest; installed baseline 2026.9.8) | The 2026.10.7 npm package's ~1,300 doc pages and `dist/` source (diffed against the installed 2026.9.8), CLI help, the `clawhub` CLI, and GitHub changelogs to 2026.10.7 |"
- §4.1 L551: current "| Claude Code 2.1.301 | OpenCode 1.18.35 | OpenClaw 2026.9.8 |" → new "| Claude Code 2.1.301 | OpenCode 1.18.35 | OpenClaw 2026.10.7 |". Coordinate with the Claude Code and OpenCode bumps.
- §4.1 L553: current "**CLAUDE.md is not read** by the native runtime" → new "**CLAUDE.md is not read**: the native runtime ignores it and managed Claude CLI turns exclude it (one-time `openclaw migrate claude` import only)"
- §4.3 L594: current "`<available_skills>` (name, description, path), budgeted" → new "`<available_skills>` (name, description, path), budgeted; omitted skills found via `skills_search`"
- §4.3 L595: current "Reads the SKILL.md with `read` (no Skill tool)" → new "`skills_read` (or `read`) by name; `skills_search` for skills the listing omits"
- §4.4 L610: current "✅ View-only child transcripts; `sessions_send` steering" → new "✅ Subagents panel (view-only transcripts, activity, Stop); `sessions_send` steering"
- §4.9 L688: current "**Safeguard** mode audits headings, pending asks and identifiers, and aborts rather than write a lossy summary" → new "**Safeguard** mode audits headings, pending asks and identifiers and keeps history on a failed audit; a summary timeout commits a summary-less compaction"
- §6 L844: current "OpenClaw's Control UI side panels (Files / Terminal / Review)" → new "OpenClaw's Control UI side panels (Files / Terminal / Review / Subagents / Processes)"
- Appendix B L4937: current "**OpenClaw 2026.9.8.**" → new "**OpenClaw 2026.10.7.**"
- Appendix B L4940: current "- <https://docs.openclaw.ai> and GitHub release notes up to 2026.10.4 (later releases are reliability fixes only and change no row)." → new "- <https://docs.openclaw.ai>, the 2026.10.7 npm package (docs + `dist/`, diffed against the installed 2026.9.8), and the per-version changelogs 2026.9.9–2026.10.7 (`CHANGELOG/<ver>.md` on GitHub). 2026.10.5 is the feature release behind the refresh; 9.9–10.4, 10.6 and 10.7 are mostly reliability and security fixes."
- §4.7 MCP bullets (L658-659), §4.12 and §1 need no change. "stdio, SSE and HTTP with OAuth; resources and prompts as model-side tools" and "Tool Search on by default (marked experimental)…" are still accurate in the 10.7 docs.

## What's new (for the plan)

OpenClaw 2026.10.7 now has these features that candy-code would need to match:

1. **Self-learning skills (SKL-N1).** The agent creates and patches its own skills during a turn and in a background review, with every change announced and undoable ("say undo"), version history, archive/restore, 30-day unused auto-archive, and `/learn [paths/urls]`. candy-code only has opt-in dream-pass drafts behind accept/reject. Needs:
   - a `SkillWorkshop`-style tool;
   - a versioned store under `~/.candy-code/skills-learned/`;
   - undo and history commands;
   - review triggers.
2. **Skill search (SKL-N2).** `skills_search` (lexical index over name, description and bounded body text, with coverage reporting) plus `skills_read` (exact name). The prompt listing names its omissions instead of silently truncating. candy-code's `skills.listingMaxBytes` truncation drops skills with no way back.
3. **Session import (SES-10; candy-code ❌).** Import Claude Code and Codex transcripts with `sessions import <catalog> <id>` / `--all`. Imports are bounded (50k items / 64 MiB), incremental on re-import, and land as untrusted reference material.
4. **Isolation for CLI-backed providers.** OpenClaw's managed Claude CLI turns strip Claude's own `CLAUDE.md`, rules, auto-memory, user hooks, plugins and MCP, and route all tools through the host's policy-filtered MCP bridge, so the host stays the only policy and instruction authority. candy-code's `bare: false` Claude-subscription route should get an explicit, tested policy for the same problem: double instructions and foreign hooks running outside candy-code's permission engine.
5. **Compaction summary-timeout fallback.** When the summarizer times out (deadline, 408 or 504), OpenClaw commits a deterministic summary-less compaction that keeps recent turns and tool pairs verbatim, instead of failing the turn. A bounded recent-history view covers declined compaction. Check that candy-code degrades this way.
6. **Web: Processes panel (SH-N2).** A per-shell-process list with status, exit code, recent output and a targeted Stop. candy-code-web's Background panel covers only `/bg` sessions.
7. **Web: Subagents panel parity (AGT-12).** Per-child activity rows under the working line, a countdown of running subagents, and launch rows linking into the panel. candy-code-web already has stronger controls (pause, resume, background); this is presentation only.
8. **Web: public read-only links at the normal chat URL (SES-09; web ❌).** Anonymous read access to existing and future messages, revocable.
9. **Web: MCP resource attach (MCP-06; web ❌).** Composer search and attach of server resources, which OpenClaw does via MCP Apps plugin extensions.
10. **Hygiene, already at parity:**
    - TL-N2: candy-code's repeat guard already covers OpenClaw's identical-error stop.
    - CTX-N4: candy-code already continues length-stopped replies.
    - SH-09: candy-code keeps head and tail.

    No plan items there.
