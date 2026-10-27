# comp-opencode — OpenCode columns and §5 refresh (2026-10-26)

Reviewed: `candy-code/COMPARISON.md` at HEAD 5e9195687. Sources: anomalyco/opencode tags v1.18.35, v1.19.4, v2.0.31, v2.1.0, v2.1.1, v2.1.3 and v2.1.4, shallow-fetched into the scratchpad and read as data only (nothing was built or executed). Also used: the v2 `CHANGELOG.md` shipped at v2.1.4, <https://opencode.ai/changelog> (the only source of v2.1.4 notes), GitHub release notes for v1.19.0–1.19.4, npm metadata and tarballs of ecosystem plugins, and a read-only look at `~/.config/opencode`, `~/.cache/opencode`, `~/.local/share/opencode` and the repo's `.opencode/`.

## Summary

**Verdict: move the v2 column to `OpenCode 2.1.4`. Moving the v1 column to `OpenCode 1.19.4` is recommended but optional.** 42 cells change, 15 of them in status. §5 also needs a rewrite: three of its claims were wrong when written, and four of its regressions have been partly reversed.

- **Versions (verified).**
  - `npm view opencode-ai dist-tags`: `latest: '1.19.4'` (published 2026-10-22). This is also the GitHub "Latest" release.
  - `npm view @opencode/cli dist-tags`: `latest: '2.1.4'` (published 2026-10-23).
  - Homebrew core `opencode` is now 2.1.4 (formulae.brew.sh/api/formula/opencode.json).
  - v2 tags have no GitHub releases (`gh release view v2.1.4` returns "release not found"). Their notes live in the repo `CHANGELOG.md` (which stops at v2.1.3) and on opencode.ai/changelog (which covers v2.1.4). v2.0.31 and v2.1.1 have no notes anywhere.
- **v1 column (1.18.35 → 1.19.4): one status change.**
  - SES-09 goes ✅→❌. Session sharing was discontinued in 1.19.1, and the share host `opncd.ai` now returns **410 Gone**, so the installed 1.18.35's `/share` is dead too. SES-09 v1 should be ❌ whichever version the header names.
  - Text-only changes: PLAT-01, PLAT-05, PLAT-09, SES-10, SES-14, CMD-01.
  - Two "(installed here)" / "(v1 only)" errors to fix in AUT-09 and PRM-N3: opencode-goal-plugin is **not** installed on this host and now supports both majors.
  - Recommendation: bump the header to 1.19.4. Every 1.18.35→1.19.4 behaviour delta is source-verified (180 files; mostly installer, upgrade, share removal and docs), and Appendix B should keep the note that the installed copy is 1.18.35.
- **v2 column (2.0.31 → 2.1.4): 33 cell changes, 14 of them in status.**
  - Up: MOD-13 🟡→✅, MOD-22 🟡→✅, MOD-23 ❌→🟡, CTX-09 ❌→🔌, CTX-N1 ❌→🟡, TL-11 ❌→🟡, TL-N1 ❌→🟡, UI-20 ❌→🟡, AUT-09 ❌→🔌, PRM-N3 ❌→🔌, MEM-08 ❓→🔌.
  - Down: AGT-16 ❓→❌, TL-22 ❓→❌, TL-28 ❓→❌.
  - SES-09 drops its Δ, because v1 now matches.
- **New Δ / status statistic for §5:** 242 of 398 rows carry Δ (was 240), and 72 differ in status (was 77).
- **New §2 scoreboard totals:**
  - OpenCode 1.19.4: ✅198 🟡82 🔌23 ❌52 ➖1. The only category change is SES, from ✅12 🟡5 to ✅11 🟡5 ❌1.
  - OpenCode 2.1.4: ✅201 🟡81 🔌15 ❌57 ➖1 ❓1. Category changes:

    | Category | Before | After |
    |---|---|---|
    | MOD | ✅18 🟡4 ❌3 | ✅20 🟡3 ❌2 |
    | MEM | 🟡2 🔌10 ❌2 ❓1 | 🟡2 🔌11 ❌2 |
    | CTX | ✅9 🟡2 ❌2 | ✅9 🟡2 🔌1 ❌1 |
    | AGT | ✅15 🟡3 ❌2 ➖1 ❓1 | ✅15 🟡3 ❌3 ➖1 |
    | TL | ✅14 🟡4 ❌8 ❓2 | ✅14 🟡5 ❌9 |
    | UI | ✅24 🟡8 ❌2 | ✅24 🟡9 ❌1 |
    | AUT | ✅3 🟡7 ❌1 ❓1 | ✅3 🟡7 🔌1 ❓1 |

  - Method: a parser over §3 using the §2 rule (taxonomy IDs `<CAT>-<digits>` only). The parser reproduces the current OpenCode columns exactly before the changes are applied.
- **§5 claims re-verified at 2.1.4.**
  - All gains are still present:
    - durable inbox
    - instruction diffs
    - Code Mode as default
    - elicitation
    - background shell and sub-agents
    - worktree tools
    - `/btw`
    - session tabs
    - output-limit auto-continuation
    - cache warming
    - native compaction
    - persisted Allow always
    - `permission.evaluate`
    - org policies
    - pair, QR, OpenTunnel and the iOS app
  - Elicitation was effectively broken during tool calls until 2.1.4. Before that the form never appeared and the call waited until it timed out.
  - Regressions still true at 2.1.4:
    - no CLAUDE.md
    - discovery up to `$HOME`
    - no LSP
    - no structured output
    - no `@file` in templates
    - skills are not slash commands
    - no SSE MCP
    - no `agent create`
    - no `github` / `pr` (the Action still installs v1)
    - `request.body` not sent
    - generic Gemini prompt
    - 2-tier edit fallback
    - `external_directory` allowed by default
    - no doom-loop guard
    - sub-agents use their own rules
  - **Partly reversed in 2.1.x:**
    - todo tool, behind `experimental.todo`
    - PDF prompt attachments (2.1.4)
    - session archiving
    - the plugin ecosystem (goal plugin, magic-context, supermemory, plannotator, froggy and handoff now have v2 builds)
  - `/share` is no longer a v2-only regression: v1 lost it too.
- **§5 errors at the time it was written.**
  1. "opencode-goal-plugin explicitly does not support OpenCode 2": v2 support shipped in 0.11.0 on 2026-09-30, and 0.11.1 was verified by its author on 2.0.16, 2.0.26 and 2.0.31.
  2. "opencode-pty is V1-API": 0.4.0 (2026-09-18) ships an `opencode-pty/v2` entry, but that entry registers only two web-UI commands and none of the `pty_*` agent tools. The practical verdict ("no agent PTY via plugin on v2") stands, but the wording needs fixing.
  3. "v1 re-resolves `opencode-agent-memory@latest` at startup": false. v1's `Npm.add` reuses the cached `@latest` directory whenever `node_modules/<name>` exists. The cache still holds 0.2.0, which v1 keeps loading. The same latent trap exists for `opencode-handoff@latest`: cached 0.5.0, while npm latest 0.6.1 is v2-only.
- **UNVERIFIED.**
  - PLAT-09 startup timings were not re-measured on 1.19.4 or 2.1.4, because downloaded binaries were not executed. The sizes come from npm.
  - The code path behind 2.1.4's PDF attachment change is not pinned. It rests on the shipped doc diff only.
  - It was not investigated why `autoupdate: true` left this host on 1.18.35.

## Changelog digest

### v1 line: 1.18.35 → 1.19.4

There is no 1.18.36; the release after 1.18.35 is 1.19.0. Source: GitHub releases, cross-checked with `git diff v1.18.35 v1.19.4`.

| Release | Date | Change | Category / row |
|---|---|---|---|
| 1.19.0 | 2026-10-11 | `opencode upgrade --major` upgrades to OpenCode 2 and removes v1. TUI `/update` installs a 1.x update or offers 2.0. Updates are **no longer installed automatically**; `autoupdate: true` or `"notify"` now only notifies. | PLAT-05, CMD-01 |
| 1.19.0 | | OpenAI `ultrafast` service tier allowed. | MOD-N2 (text only, no change proposed) |
| 1.19.0 | | PTY connection origins and tickets are validated before the PTY lookup. | WEB-11 (hardening, no change) |
| 1.19.1 | 2026-10-15 | **Session sharing discontinued.** `/share` and `/unshare` removed from the TUI and app. `opencode import <share URL>` now fails with "Importing from share URLs is no longer supported". The GitHub agent no longer posts share links. `opencode pr` no longer imports a session from the PR body. | SES-09, SES-10, SES-14, CMD-01, GIT-04/12 (no status change) |
| 1.19.2 | 2026-10-16 | 1.x stays on 1.x: update checks read only npm `opencode-ai@latest`; the curl install script refuses 2.x; Homebrew-core installs (which now track v2) are skipped; yarn upgrade fixed. | PLAT-03, PLAT-05 |
| 1.19.3 | 2026-10-16 | Configured reasoning options generate reasoning variants, replacing catalog variants while keeping user overrides. | MOD-15 (no change) |
| 1.19.4 | 2026-10-22 | Bundled Bun 1.4.4 (TLS, HTTP and WebSocket security fixes). The linux-x64 package grows from 185.7 MB to 214.5 MB. | PLAT-01, PLAT-09 |

### v2 line: 2.0.31 → 2.1.4

Sources: the v2.1.4 tag's `CHANGELOG.md` (through v2.1.3) and opencode.ai/changelog (v2.1.4). v2.0.31 and v2.1.1 have no notes; v2.1.1's diff is TUI model/free-model/export dialogs only.

- **2.0.32 (2026-10-16)**
  - `/copy` copies the latest assistant reply, or a completed compaction summary (UI-16, SES-08).
  - `auth login` no longer crashes headless (MOD-09).
  - Gemini `MALFORMED_RESPONSE` finishes are retried or continued (MOD-03).
  - Windows snapshot capture is resilient (GIT-05).
  - Experimental SDK `client.voice.session()` for GPT-Live/Realtime and xAI voice (MOD-23, WEB-13).
- **2.1.0 (2026-10-20)**
  - Windows persistent PTYs (PLAT-04, SH-01).
  - **`todowrite` restored behind `experimental.todo`**, with TUI session/sidebar todo display (TL-11, UI-19, UI-20).
  - **Session archiving restored** (SES-17).
  - Saved permission actions and resources are editable through `client.permission.saved.update` (PER-04).
  - **Summary compaction covers the whole conversation and keeps recent text verbatim; it starts earlier by default** (CTX-03, CTX-05).
  - `streamUsage` compatibility option, plus retries for broken pipes, buffered-generation failures and keep-alives (MOD-18).
  - Long TUI, `run` and `mini` requests survive Bun's fetch deadline.
  - Instruction baselines and image trimming stabilised for cache reuse (PRM-N1).
  - Bounded skill discovery and watches (SKL-02).
  - **Breaking SDK/HTTP/plugin contract changes:** provider replay data renamed to `native`, compaction `keepID`, credential `metadata`, `session.compacted` removed (PLG-05, PLG-07).
  - `ctx.session.list` for plugins (PLG-03).
  - Gemini Live voice (MOD-23).
  - `/models` opens on the current model.
- **2.1.2 (2026-10-21)**
  - Free-limit recovery UI.
  - `OPENCODE_DISABLE_FREE_MODELS` (CFG-04).
  - `experimental.location_idle_timeout`.
  - Installer creates `.zshrc` / `config.fish` (PLAT-03).
  - SDK `retryAfterMs` (MOD-18).
  - Code Mode async jobs no longer interleave or lose writes (TL-N3, MCP-12).
- **2.1.3 (2026-10-22)**
  - Bun 1.4.4.
  - Titles use the lowest suitable model variant (MOD-13, SES-04).
  - Custom-provider `baseURL` overrides catalog endpoints (MOD-08).
  - Compaction summaries may use the full output limit (CTX-05).
  - `apply_patch` permissions migrate to `edit` (CFG-08).
  - **MCP results keep media inline and save binary resources to files** (MCP-14).
  - **MCP instruction, skill and reference updates send only changed entries** (PRM-06, PRM-N1).
  - The TUI no longer crashes without file watchers.
  - Desktop Pierre highlighting.
  - SDK `client.voice.models()`.
- **2.1.4 (2026-10-23)**
  - **MCP elicitation forms now appear during tool calls**; before this the call waited until timeout. Desktop and web render forms too (MCP-08).
  - **Experimental `background_compaction`** (CTX-N1).
  - Overlong compaction requests shorten older tool output first, and more overflow errors are recognised (CTX-06, CTX-13).
  - ChatGPT context caps became a documented built-in plugin.
  - `opencode debug heap-dump [--server]`, plus desktop "Capture heap snapshots" (OBS, CFG-06).
  - **Experimental TUI tools `tui_open_terminal` / `tui_write_terminal`** (TL-N1, SH-05).
  - **SDK `connectClientMcp()`**: the client-hosted MCP transport (PLG, MCP).
  - MCP OAuth callback and remote idle-timeout fixes, non-object output schemas accepted (MCP-04, MCP-05).
  - V1 sessions imported into the right project (SES-10).
  - Not in any changelog, but in the shipped-doc diff from 2.1.3 to 2.1.4: **PDF prompt attachments are sent as document media** (MOD-22).
  - Shipped docs at v2.1.4 (absent at v2.0.31): **`agents.compaction.model`** (MOD-13).

### Ecosystem changes that move 🔌 cells (npm, not OpenCode releases)

| Plugin | Version (date) | Major-version support |
|---|---|---|
| opencode-goal-plugin | 0.11.0 (2026-09-30), 0.11.1 (2026-10-18) | v1 + v2 ("Add OpenCode 2 support"; 0.11.1 fixes Code Mode tool discovery) |
| @cortexkit/opencode-magic-context | 0.49.23 (2026-10-25) | v1 + v2 (ships `dist/v2/`) |
| opencode-supermemory | 2.0.17 | v1 + v2 |
| @plannotator/opencode | 0.28.31 | v1 + v2 |
| opencode-froggy | 1.6.2 | v2 |
| opencode-handoff | 0.6.1 (2026-10-21) | v2 ≥2.1.2 only; 0.5.0 is the last v1 release |
| opencode-agent-memory | 0.3.0 | v2 only |
| opencode-pty | 0.4.0 | `/v2` entry exists but registers no agent tools |
| oh-my-openagent | 5.1.48 | loads on v2 with agents, tools and hooks inactive |

Still V1-only: DCP 3.2.0, subtask2 0.3.5, opencode-conductor-plugin 1.32.0, opencode-scheduler 1.3.0, opencode-gpt-imagegen 1.1.0, harness-memory 0.5.1, opencode-wakatime 1.4.0, smart-title, agent-skills, skillful, agent-identity.

## Cell changes

Headers below use the proposed new column names. All Appendix A labels `**OpenCode 2.0.31**` (398×) and `**OpenCode 1.18.35**` (398×) rename mechanically to `**OpenCode 2.1.4**` and `**OpenCode 1.19.4**`, as do the "1.18.35 (✅): … 2.0.31 (❌): …" prefixes inside v2 DETAIL texts.

### PLAT-01 · OpenCode 2.1.4
- current: ``✅ Δ Same TS/Effect stack, re-cut as core/server/cli/tui/app packages; native `@opencode/ai` provider``
- new: ✅ Δ Same TS/Effect stack, re-cut as core/server/cli/tui/app packages; native `@opencode/ai` provider layer; ~240 MB binary
- detail: Same architecture. The linux-x64 platform package grew from 211.8 MB (2.0.31) to 240.4 MB unpacked (2.1.4); 2.1.3 bundles Bun 1.4.4. — _Evidence:_ `npm view @opencode/cli-linux-x64@2.1.4 dist.unpackedSize` = 240417179 (2.0.31: 211793612); opencode.ai/changelog v2.1.3
- why: binary size stale.

### PLAT-03 · OpenCode 2.1.4
- current: `✅ Δ v2: curl /v2/install, npm @opencode/cli, brew opencode-v2, AUR opencode-beta, mise≥2026.10.9, Wi`
- new: ✅ Δ curl /v2/install, npm @opencode/cli, Homebrew core `opencode` (now v2) or tap opencode-v2, AUR opencode-beta, mise, WinGet, Docker
- detail: Homebrew core's `opencode` formula now ships v2 (2.1.4). v1 1.19.2 stops offering 1.x updates to Homebrew-core installs. The README lists the tap `anomalyco/tap/opencode-v2` (recommended) and `brew install opencode` (official, "updated less"). — _Evidence:_ formulae.brew.sh/api/formula/opencode.json (stable 2.1.4, read 2026-10-26); anomalyco/opencode@v2.1.4:README.md L50-58; v1.19.2 release notes
- why: Homebrew core flipped to v2.

### PLAT-04 · OpenCode 2.1.4
- current: `✅ Δ Linux (glibc+musl), macOS, Windows native incl. ARM64 builds; WSL still supported`
- new: ✅ Δ Linux (glibc+musl), macOS, Windows native incl. ARM64 (persistent PTY terminals on Windows since 2.1.0); WSL supported
- detail: 2.1.0 added persistent PTYs for Windows x64 and ARM64. 2.1.3 moved Windows managed services to port 28894 to avoid WSL-reserved ranges. — _Evidence:_ anomalyco/opencode@v2.1.4:CHANGELOG.md v2.1.0 (#56245), v2.1.3 (#57428)
- why: Windows parity gap closed.

### PLAT-09 · OpenCode 2.1.4
- current: ``✅ Δ `opencode --version` measured 0.15 s / ~89 MB RSS (v1: 1.1 s / 200 MB); 212 MB binary``
- new: ✅ Δ `opencode --version` 0.15 s / ~89 MB RSS measured on 2.0.31 (not re-run on 2.1.4); ~240 MB binary
- detail: The timing was not re-measured: downloaded binaries were not executed in this pass. Size is from npm. — _Evidence:_ `npm view @opencode/cli-linux-x64@2.1.4 dist.unpackedSize`
- why: size stale; measurement provenance.

### MOD-13 · OpenCode 2.1.4
- current: ``🟡 Δ `small_model` now only sets the `title` agent model; compaction always uses the session model``
- new: ✅ Δ Title agent model via `small_model`/`agents.title.model` (lowest suitable variant); `agents.compaction.model` for summaries
- detail: Summary compaction now honours `agents.compaction.model` and falls back to the session model if it can't be resolved; native compaction always uses the session model. Since 2.1.3, titles use the lowest suitable model variant. There is no automatic cheap-model pick, unlike v1's `small_model` default. — _Evidence:_ anomalyco/opencode@v2.1.4:services/www/src/docs/content/compaction.mdx L91-104, L202 (v2.0.31 same page: "There is no separate compaction model"); CHANGELOG v2.1.3 (#51939, #57648); migrate-v1.mdx "small_model becomes … agents.title.model"
- why: "compaction always uses the session model" is no longer true.

### MOD-22 · OpenCode 2.1.4
- current: `🟡 Δ read tool still passes PDFs, but PDF prompt attachments are NOT sent to the model in v2`
- new: ✅ Δ PDFs sent as document media from composer attachments and the read tool; models without PDF input get a short note
- detail: The v2.1.4 attachment table lists `PDF → Document media`. At v2.0.31 the same page said PDF prompt attachments were not sent. The change appears between 2.1.3 and 2.1.4 and is in no changelog; the source path was not pinned (UNVERIFIED at code level). — _Evidence:_ anomalyco/opencode@v2.1.4:services/www/src/docs/content/attachments.mdx L60-70 vs @v2.0.31 same file L60-69
- why: regression reversed.

### MOD-23 · OpenCode 2.1.4
- current: `❌ No voice/audio input or speech output (TUI audio is only notification sounds)`
- new: 🟡 Δ Experimental SDK `client.voice.session()` brokers realtime voice models (OpenAI, xAI, Gemini Live); no voice in TUI/desktop
- detail: History:
  - 2.0.32: server-brokered realtime voice sessions (WebRTC/WebSocket) for OpenAI GPT-Live/Realtime and xAI Grok Voice.
  - 2.1.0: Gemini Live.
  - 2.1.3: `client.voice.models()` and Console-hosted GPT-Live.

  This is an SDK-only API: the TUI and desktop have no voice UI. — _Evidence:_ anomalyco/opencode@v2.1.4:packages/core/src/voice.ts, packages/server/src/handlers/voice.ts (both absent at v2.0.31); CHANGELOG v2.0.32 (#55951), v2.1.0 (#56888), v2.1.3 (#57194)
- why: new provider-level audio I/O.

### CTX-03 · OpenCode 2.1.4
- current: ``✅ Δ Auto-compact when estimated request exceeds window minus `buffer` (default 10% of limit); `compa``
- new: ✅ Δ Auto-compact when estimated request exceeds window minus `buffer` (default 15% of limit, ≥16k); `compaction.auto`
- detail: 2.1.0 starts compaction earlier by default. A buffer at least as large as the model limit is ignored (2.1.4). — _Evidence:_ anomalyco/opencode@v2.1.4:services/www/src/docs/content/compaction.mdx L87 (v2.0.31: "10% of the limit"); CHANGELOG v2.1.0 (#57091); opencode.ai/changelog v2.1.4 (#58313)
- why: default changed.

### CTX-05 · OpenCode 2.1.4
- current: `✅ Δ Rolling structured summary by the session model + ~15k-token recent tail; or provider-native enc`
- new: ✅ Δ Whole-conversation summary (cache-reusing) + ~15k tokens kept verbatim; optional summary model; or provider-native compaction
- detail: Since 2.1.0 the summary request sends the whole conversation, so it reuses the prompt cache. The summary is placed beside the last ~15k tokens kept verbatim (`keep.tokens`), and kept text is referenced by `metadata.keepID`. `agents.compaction.model` selects another summariser. Overlong compaction requests shorten older tool output first (2.1.4). Native encrypted compaction now only needs the same provider and model. — _Evidence:_ compaction.mdx@v2.1.4 L8-23, L91-104, L174; CHANGELOG v2.1.0 (#57091, breaking-changes "Summary compactions reference kept messages"), v2.1.3 (#57564); opencode.ai/changelog v2.1.4 (#58015)
- why: method changed.

### CTX-09 · OpenCode 2.1.4
- current: `❌ Δ DCP plugin targets the V1 plugin API (@opencode-ai/plugin ≥1.18.29) and does not run on v2`
- new: 🔌 Δ Via @cortexkit/opencode-magic-context (`ctx_reduce`, v2 build); DCP still targets the V1 plugin API only
- detail: magic-context 0.49.23 ships a `dist/v2/` build and documents OpenCode 2 behaviour. Its model tool `ctx_reduce` "queue[s] stale tagged content for removal, cache-aware"; `ctx_expand` restores ranges. DCP 3.2.0 still peers `@opencode-ai/plugin >=1.18.29` and has no v2 export. Neither is installed on this host for v2. — _Evidence:_ npm @cortexkit/opencode-magic-context@0.49.23 tarball `package/dist/v2/*` and README "Agent tools at a glance"; npm @tarquinen/opencode-dcp@3.2.0 peerDependencies/exports
- why: a v2 plugin now provides model-driven pruning.

### CTX-N1 · OpenCode 2.1.4
- current: `❌ No background pre-summarisation; compaction runs at the next safe point when over budget`
- new: 🟡 Δ Experimental `background_compaction`: summary prepared at 85% of threshold, swapped in at threshold (held in memory)
- detail: Opt-in under `experimental`. It is skipped for native compaction, for sessions under ~64k tokens, right after a model switch, and when the provider rejects a request as too long. An unused summary still costs tokens, and held summaries are lost on restart. — _Evidence:_ opencode.ai/changelog v2.1.4 (#57444, #58294); compaction.mdx@v2.1.4 L106-137; config.mdx@v2.1.4 L651
- why: feature added (experimental, so 🟡).

### SES-09 · OpenCode 2.1.4
- current: `❌ Δ Share removed: 2.0.30 deleted the retired sharing backend and /share`
- new: ❌ Share removed: 2.0.30 deleted the retired sharing backend and /share
- detail: Unchanged text. Drop Δ: v1 removed sharing in 1.19.1 and the backend returns 410, so the lines no longer differ. — _Evidence:_ CHANGELOG v2.0.30 (#55716); v1.19.1 release notes
- why: Δ convention (cell no longer differs from 1.x).

### SES-17 · OpenCode 2.1.4
- current: `🟡 Δ Projects auto-archive when their directories vanish; sessions never auto-pruned; uninstall keeps`
- new: 🟡 Δ Archive/restore sessions (2.1.0, desktop/web + SDK filter); projects auto-archive when dirs vanish; nothing auto-pruned
- detail: 2.1.0 restored session archiving, with archive-state filtering and restore (`client.session.list/update`); the desktop/web app has archive actions. There is still no age-based retention. — _Evidence:_ CHANGELOG v2.1.0 (#53759); anomalyco/opencode@v2.1.4:packages/app/src/shell/commands/palette.ts (archive)
- why: archive restored.

### AGT-16 · OpenCode 2.1.4
- current: ``❓ Δ No native teams; V1 swarm plugins (oh-my-openagent pins @opencode-ai/plugin 1.18.31) don't run; ``
- new: ❌ Δ No native teams; oh-my-openagent 5.1.48 loads on v2 with agents/tools/hooks inactive; subtask2/conductor V1-only
- detail: The previously unverified plugin path is now checked:
  - oh-my-openagent 5.1.48's own bundle says "OpenCode 2 support is limited: the plugin loads, but its agents, tools and hooks are not active here."
  - subtask2 0.3.5 and opencode-conductor-plugin 1.32.0 depend only on `@opencode-ai/plugin` (V1).

  No v2 team/swarm plugin was found. — _Evidence:_ npm oh-my-openagent@5.1.48 tarball `package/dist/index.js`; `npm view @spoons-and-mirrors/subtask2@0.3.5 peerDependencies`; `npm view opencode-conductor-plugin@1.32.0 dependencies`
- why: ❓ resolved.

### MEM-06 · OpenCode 2.1.4
- current: `🔌 Δ agent-memory 0.3 journal search (v2 API); supermemory 2.0.17 supports v1+v2; others unverified o`
- new: 🔌 Δ agent-memory 0.3 journal search (v2 only); supermemory 2.0.17 and magic-context hybrid `ctx_search` run on v1+v2
- detail: magic-context's `ctx_search` covers memories, conversation history, git commits and notes, with local or remote embeddings plus keyword search. It ships a v2 build. — _Evidence:_ npm @cortexkit/opencode-magic-context@0.49.23 README (tool table, embedding section), `package/dist/v2/`; npm opencode-supermemory@2.0.17 exports `./v2`
- why: "others unverified" resolved.

### MEM-08 · OpenCode 2.1.4
- current: `❓ Δ No native consolidation; magic-context/harness-memory v2 compatibility unverified`
- new: 🔌 Δ No native consolidation; 🔌 magic-context dreamer (v2 build) verifies/dedups memories; harness-memory stays V1-API
- detail: The magic-context "dreamer" runs idle-time maintenance in ephemeral child sessions (verify against the codebase, curate duplicates and stale entries, promote recurring ones) and is triggerable with `/ctx-dream`. harness-memory 0.5.1 depends on `@opencode-ai/sdk ^1.3.13` only. — _Evidence:_ npm @cortexkit/opencode-magic-context@0.49.23 README L48, L213-227; `npm view harness-memory@0.5.1 dependencies`
- why: ❓ resolved.

### PRM-06 · OpenCode 2.1.4
- current: ``✅ Δ Each step lists permitted skills (id, name, description); `opencode/autoinvoke:false` hides one;``
- new: ✅ Δ Permitted skills (id, name, description) in the instruction baseline; updates sent as changed entries only (2.1.3)
- detail: 2.1.3 changed MCP-instruction, skill and reference updates to "send only changed entries instead of repeating the full list". `opencode/autoinvoke:false` still hides a skill, and the body still comes via the skill tool. Changelog only; source not traced. — _Evidence:_ CHANGELOG v2.1.3 (#57626)
- why: delivery changed (low priority).

### PRM-N3 · OpenCode 2.1.4
- current: `❌ Δ No completion contract; opencode-goal-plugin does not support OpenCode 2`
- new: 🔌 Δ No native contract; 🔌 opencode-goal-plugin 0.11+ (v2 bridge: own /goal, evidence-gated completion audits)
- detail: 0.11.0 (2026-09-30) "Add OpenCode 2 support": a `setup` entrypoint with a v2 bridge mapping hooks onto `session.hook`, `tool.transform` and `command.transform`. 0.11.1 adds a Code Mode hint. The author verified it on 2.0.16, 2.0.26 and 2.0.31. Not installed on this host. — _Evidence:_ npm opencode-goal-plugin@0.11.1 tarball `package/CHANGELOG.md` (0.11.0, 0.11.1 entries), README compatibility table
- why: the previous claim was wrong.

### MCP-08 · OpenCode 2.1.4
- current: `🟡 Δ Elicitation supported (forms and URL requests); sampling still not`
- new: 🟡 Δ Elicitation forms (text/number/choice/multiselect/boolean/URL) in TUI, desktop and web; working since 2.1.4; no sampling
- detail: Until 2.1.4 an elicitation raised during a tool call never showed its form, and the call waited until timeout. 2.1.4 fixed that and added desktop/web form controls. The client advertises `elicitation: {form, url}` and registers `elicitation/create`; there is no sampling handler. — _Evidence:_ opencode.ai/changelog v2.1.4 (#58184, #58305); anomalyco/opencode@v2.1.4:packages/core/src/mcp/client.ts L203, L222-227; mcp-servers.mdx L443-449
- why: earlier "supported" overstated 2.0.31.

### MCP-14 · OpenCode 2.1.4
- current: `✅ Δ Per-server timeouts startup/catalog/execution (12 h default execution); large outputs shortened `
- new: ✅ Δ Per-server startup/catalog/execution timeouts; large output to managed file; media inline, binary resources saved to files
- detail: 2.1.3: MCP results keep supported media inline, decode textual resources, and save unsupported binary resources to files. 2.1.4: remote MCP requests survive Bun's fetch idle timeout. — _Evidence:_ CHANGELOG v2.1.3 (#57640); opencode.ai/changelog v2.1.4 (#58341)
- why: handling extended (low priority).

### TL-04 · OpenCode 2.1.4
- current: ``❌ No multiedit/batch edit tool in 1.18.35 (`experimental.batch_tool` key has no implementation)``
- new: ❌ No multiedit/batch edit tool in v2 (`experimental.batch_tool` is ignored with a warning)
- detail: The v2 cell was copied from v1. v2 lists `batch_tool` among accepted-but-unsupported V1 experimental fields. — _Evidence:_ anomalyco/opencode@v2.1.4:services/www/src/docs/content/migrate-v1.mdx L476
- why: wrong version in cell.

### TL-11 · OpenCode 2.1.4
- current: `❌ Δ No todo tool in v2 (todowrite/sidebar todos removed)`
- new: 🟡 Δ Experimental `todowrite` (`experimental.todo`, off by default): full-list replace, 4 statuses, no priority
- detail: Restored in 2.1.0. Registered only when `experimental.todo === true`, as a direct tool (`codemode:false`), with statuses pending, in_progress, completed and cancelled. The v1 `priority` field is gone. — _Evidence:_ anomalyco/opencode@v2.1.4:packages/core/src/tool/plugin/todo.ts L13-23, L50; migrate-v1.mdx L126-135; config.mdx L637; CHANGELOG v2.1.0 (#56947)
- why: regression partly reversed.

### TL-22 · OpenCode 2.1.4
- current: `❓ Δ No cron tool; opencode-scheduler is V1-API (@opencode-ai/plugin) — v2 support unverified`
- new: ❌ Δ No cron tool; opencode-scheduler 1.3.0 ships only a V1-API entry (@opencode-ai/plugin), so it cannot load on v2
- detail: The latest scheduler (2026-02-17) peers `@opencode-ai/plugin >=1.0.0` with a single `.` export. "V1 plugin implementations do not run in V2." — _Evidence:_ `npm view opencode-scheduler@1.3.0 peerDependencies exports`; migrate-v1.mdx@v2.1.4 (Plugins callout)
- why: ❓ resolved.

### TL-28 · OpenCode 2.1.4
- current: `❓ Δ No media generation; opencode-gpt-imagegen is V1-API — v2 support unverified`
- new: ❌ Δ No media generation; opencode-gpt-imagegen 1.1.0 (2026-10-22) still targets the V1 plugin API only
- detail: The latest release depends on `@opencode-ai/plugin ^1.14.41` with a single export and no v2 entry. — _Evidence:_ `npm view opencode-gpt-imagegen@1.1.0 dependencies exports`
- why: ❓ resolved.

### TL-N1 · OpenCode 2.1.4
- current: `❌ Δ No agent PTY driver; persistent PTYs back the user /terminal only; opencode-pty is V1-API`
- new: 🟡 Δ Experimental TUI tools `tui_open_terminal`/`tui_write_terminal` type into the session terminal; no output read-back
- detail: 2.1.4 lets the TUI serve its own tools to the server as client-MCP server `tui`, enabled in the TUI experiments dialog ("TUI tools"):
  - `open_terminal` opens a terminal without taking focus.
  - `write_terminal` sends raw text (a trailing `\n` runs it).

  No read tool exists, so the agent drives blind. opencode-pty 0.4.0's `opencode-pty/v2` entry registers only two web-UI commands; its `pty_spawn/write/read/list/kill` tools are not registered on v2. — _Evidence:_ anomalyco/opencode@v2.1.4:packages/tui/src/context/client-mcp.tsx L11-14, L82-95; packages/tui/src/component/dialog-experiments.tsx L25; opencode.ai/changelog v2.1.4 (#57506); npm opencode-pty@0.4.0 tarball `package/dist/src/v2/index.js` (setup: `command.transform` only)
- why: partial native capability added; plugin claim corrected.

### SH-05 · OpenCode 2.1.4
- current: `❌ Δ No stdin interaction with agent commands; opencode-pty is V1-API (user /terminal is interactive)`
- new: ❌ Δ No stdin to agent commands; experimental tui_write_terminal types blind into the user's terminal; pty plugin's v2 entry has no tools
- detail: Same status: the agent still cannot interact with its own running commands. See TL-N1 for the experimental write-only terminal tool and opencode-pty's v2 entry. — _Evidence:_ as TL-N1
- why: wording ("V1-API") inaccurate.

### UI-16 · OpenCode 2.1.4
- current: `✅ <leader>y copies last reply, /copy transcript, copy-on-select with mouse`
- new: ✅ Δ /copy copies the latest reply (or compaction summary); <leader>y; transcript via palette or /export; copy-on-select
- detail: 2.0.32 changed `/copy` from copying the transcript to copying the latest assistant message, or the completed compaction summary when that is newest. — _Evidence:_ CHANGELOG v2.0.32 (#56053, #56055)
- why: behaviour differs from v1 (Δ added).

### UI-19 · OpenCode 2.1.4
- current: `🟡 Δ Sidebar: context + MCP only (no LSP, todo or modified-files panels); diff viewer separate`
- new: 🟡 Δ Sidebar: context + MCP, plus todos when `experimental.todo` is on; no LSP or modified-files panels; diff viewer separate
- detail: 2.1.0 added todo lists to session output, compact cards and the sidebar, gated by the experimental flag. — _Evidence:_ CHANGELOG v2.1.0 (#56947)
- why: todo panel back (experimental).

### UI-20 · OpenCode 2.1.4
- current: `❌ Δ No todo list (no todo tool)`
- new: 🟡 Δ Todo list in session output, compact cards and sidebar only with `experimental.todo` (off by default)
- detail: See TL-11. — _Evidence:_ CHANGELOG v2.1.0 (#56947); migrate-v1.mdx@v2.1.4 L126
- why: regression partly reversed.

### PER-04 · OpenCode 2.1.4
- current: `✅ Δ Saved approvals are durable, project-scoped allow rules (v1: in-memory until restart)`
- new: ✅ Δ Saved approvals are durable project-scoped allow rules (v1: in-memory); list/edit/delete via `/api/permission/saved`
- detail: 2.1.0 added editing of saved permission actions and resources (`client.permission.saved.update`). The generated client exposes GET, PATCH and DELETE on `/api/permission/saved`. No TUI or desktop UI for them was found. — _Evidence:_ CHANGELOG v2.1.0 (#56659); anomalyco/opencode@v2.1.4:packages/client/src/promise/generated/client.ts L1605-1640; permissions.mdx L289-292
- why: management added.

### PLG-10 · OpenCode 2.1.4
- current: `🟡 Δ V1 ecosystem must be ported: agent-memory 0.3 + official Figma are v2; DCP, pty, subtask2 still `
- new: 🟡 Δ Partial re-port: agent-memory 0.3, goal plugin, plannotator, supermemory, magic-context, froggy, handoff run on v2; DCP/subtask2 V1
- detail: v2 builds:
  - opencode-goal-plugin 0.11+
  - @plannotator/opencode 0.28.31
  - opencode-supermemory 2.0.17
  - magic-context 0.49.23
  - opencode-froggy 1.6.2
  - opencode-handoff 0.6.1 (v2 only)
  - opencode-agent-memory 0.3.0 (v2 only)
  - official Figma

  Partial: opencode-pty 0.4.0 (v2 entry has no agent tools); oh-my-openagent 5.1.48 (load-only). V1-only: DCP 3.2.0, subtask2, conductor, scheduler, gpt-imagegen, wakatime. — _Evidence:_ npm metadata and tarballs listed in the Changelog digest ecosystem subsection
- why: ecosystem moved.

### WEB-13 · OpenCode 2.1.4
- current: ``🟡 Δ Voice only via the iOS app's voice-call delegation; no voice in TUI/desktop``
- new: 🟡 Δ iOS app voice-call delegation plus experimental SDK realtime voice sessions; no voice in TUI/desktop
- detail: See MOD-23. — _Evidence:_ anomalyco/opencode@v2.1.4:packages/core/src/voice.ts; packages/app/src/session/timeline/voice-delegation.ts
- why: SDK voice added.

### AUT-09 · OpenCode 2.1.4
- current: `❌ Δ opencode-goal-plugin explicitly does not support OpenCode 2; no native goal mode`
- new: 🔌 Δ opencode-goal-plugin 0.11+ supports v2 (registers /goal itself, auto-continue, audited completion); no native goal mode
- detail: On v2 the plugin registers `/goal` through `ctx.command.transform`, so a legacy `command.goal` config entry should be removed. Completion-audit verifier child sessions are retained, because v2's plugin API cannot remove sessions. — _Evidence:_ npm opencode-goal-plugin@0.11.1 README (OpenCode 2 sections, L64-101, L527) and CHANGELOG 0.11.0
- why: the previous claim was wrong.

### PLAT-01 · OpenCode 1.19.4
- current: `✅ TypeScript on Bun compiled to one ~186 MB binary; Effect-TS core, OpenTUI+SolidJS TUI, Electron de`
- new: ✅ TypeScript on Bun compiled to one ~215 MB binary; Effect-TS core, OpenTUI+SolidJS TUI, Electron desktop
- detail: 1.19.4 bundles Bun 1.4.4. — _Evidence:_ `npm view opencode-linux-x64@1.19.4 dist.unpackedSize` = 214522169 (1.18.35: 185690378; installed `~/.opencode/bin/opencode` is 185,690,240 bytes)
- why: header bump only.

### PLAT-05 · OpenCode 1.19.4
- current: ``✅ `autoupdate` true/false/notify on startup; `opencode upgrade [ver] --method`; separate v2 channel ``
- new: ✅ Notify-only update checks (`autoupdate: false` disables); `/update` installs 1.x or offers v2; `opencode upgrade [ver] [--major]`
- detail: Since 1.19.0 updates are never installed automatically. `/update` installs a newer 1.x or offers 2.0. `upgrade --major` installs v2 and removes v1. 1.19.2 pins update checks to npm `opencode-ai@latest`, and the curl script refuses 2.x. — _Evidence:_ v1.19.0 and v1.19.2 release notes; anomalyco/opencode@v1.19.4:packages/web/src/content/docs/config.mdx (Autoupdate), tui.mdx (`/update`), cli.mdx (`--major`)
- why: semantics changed.

### PLAT-09 · OpenCode 1.19.4
- current: ``🟡 `opencode --version` measured 1.1 s / ~200 MB RSS; 186 MB binary; `opencode debug startup` prints ``
- new: 🟡 `opencode --version` 1.1 s / ~200 MB RSS (measured on 1.18.35); ~215 MB binary; `opencode debug startup` prints timing
- detail: Not re-measured on 1.19.4. — _Evidence:_ npm `opencode-linux-x64@1.19.4` unpackedSize
- why: header bump only.

### SES-09 · OpenCode 1.19.4
- current: `✅ /share → public opncd.ai/s/<id> (synced live); share manual/auto/disabled; /unshare deletes data`
- new: ❌ Sharing discontinued: 1.19.1 removed /share, /unshare and share-URL import; the opncd.ai share host returns 410
- detail: In 1.19.x, `SessionShare.share` always fails with "Session sharing has been discontinued" (the endpoints remain for API compatibility, and unshare only clears the local link). The TUI commands `session.share`/`session.unshare` were removed. Even the installed 1.18.35 cannot share: `https://opncd.ai/s/<id>` and `/share/<id>` return HTTP 410. — _Evidence:_ v1.19.1 release notes; anomalyco/opencode@v1.19.4:packages/opencode/src/share/session.ts L19-23; `git diff v1.18.35 v1.19.4 -- packages/tui/src/routes/session/index.tsx`; `curl -o /dev/null -w '%{http_code}' https://opncd.ai/s/abcdefgh` → 410 (2026-10-26)
- why: status change, which holds even if the header stays 1.18.35.

### SES-10 · OpenCode 1.19.4
- current: ``✅ `opencode import <file.json/share URL>` ``
- new: ✅ `opencode import <file.json>`; share-URL import refused since 1.19.1 (sharing discontinued)
- detail: An http(s) argument now fails with "Importing from share URLs is no longer supported". — _Evidence:_ `git diff v1.18.35 v1.19.4 -- packages/opencode/src/cli/cmd/import.ts`
- why: text.

### SES-14 · OpenCode 1.19.4
- current: `🟡 attach to remote server, share-URL import, TUI+web on one server; no one-click web↔CLI teleport`
- new: 🟡 attach to remote server, TUI+web on one server; no share-URL import (1.19.1); no one-click web↔CLI teleport
- detail: as SES-10. — _Evidence:_ as SES-10
- why: text.

### CMD-01 · OpenCode 1.19.4
- current: `✅ ~33 built-in slash commands: /init /review /compact /undo /redo /fork /timeline /share /sessions /`
- new: ✅ ~32 built-in slash commands: /init /review /compact /undo /redo /fork /timeline /sessions /models /agents /diff /update…
- detail: 1.19.1 removed `/share` and `/unshare`; 1.19.0 added `/update`. — _Evidence:_ `git diff v1.18.35 v1.19.4 -- packages/tui/src/routes/session/index.tsx`; v1.19.4 `tui.mdx` "### update"
- why: roster changed.

### AUT-09 · OpenCode 1.19.4
- current: `🔌 🔌 opencode-goal-plugin (installed here): /goal with auto-continue, limits, evidence-gated completi`
- new: 🔌 🔌 opencode-goal-plugin (v1 and v2; not installed on this host): /goal with auto-continue, limits, evidence-gated completion
- detail: The plugin appears in neither `~/.config/opencode/opencode.jsonc` (plugin list L44-56) nor `.opencode/opencode.jsonc`. There is no `opencode-goal-plugin*` directory under `~/.cache/opencode/packages` or anywhere under /home/my and /home/sites to depth 6. — _Evidence:_ read-only listing of those paths (2026-10-26); npm opencode-goal-plugin@0.11.1
- why: "(installed here)" is false.

### PRM-N3 · OpenCode 1.19.4
- current: `🔌 No native completion contract; 🔌 opencode-goal-plugin adds evidence-gated /goal completion (v1 onl`
- new: 🔌 No native completion contract; 🔌 opencode-goal-plugin adds evidence-gated /goal completion (v1 and v2)
- detail: See PRM-N3 · OpenCode 2.1.4. — _Evidence:_ npm opencode-goal-plugin@0.11.1 CHANGELOG 0.11.0
- why: "(v1 only)" is false.

**Rows re-checked with no change** (v2 cell still accurate at 2.1.4):
- Instructions and prompts: INS-01/02/04/07 (AGENTS.md only, walk to `$HOME` — `config/plugin/instruction.ts` L38-41, L87); PRM-01 (system-prompt dir is anthropic, gpt, gpt-astra, kimi, meta, trinity, plus the 15-line `session/runner/prompt/system.txt`).
- Models: MOD-24 (no `json_schema`/StructuredOutput anywhere in core, server or client).
- Agents: AGT-05 (`request.body` "kept but not sent", migrate-v1 L181); AGT-07 (CLI handlers: acp, api, auth, debug, default, mcp, mini, models, pair, plugin, reload, run, serve, service, session, stats, uninstall, upgrade; only `debug heap-dump` added).
- Commands and skills: CMD-01 (34 named `slash:` entries identical at 2.0.31 and 2.1.4); CMD-06 (no `@` expansion in `config/plugin/command.ts`); SKL-06 (`@skill-id`, skills.mdx L201).
- MCP: MCP-02 (stdio and Streamable HTTP only, mcp-servers.mdx L79, L135); MCP-12 (`codemode` defaults true).
- Tools and editing: TL-03/EDT-01 (`edit.ts` L176-177, L223 unchanged); TL-16/EDT-05/06/07 (`// TODO: Add LSP …` in edit.ts L205, write.ts L44, file-mutation.ts L131; migrate-v1 L451); TL-N2 (doom_loop survives only in `v1/config/permission.ts`).
- Permissions: PER-05 (permissions.mdx L269, agents.mdx L118); PER-08 (permissions.mdx L143-144, L238-240).
- Git and automation: GIT-04/11/12 and AUT-05 (`github/action.yml` L61 `curl -fsSL https://opencode.ai/install` — the v1 script, which refuses 2.x — and L70 `opencode github run`).
- AUT-08 stays ❓: no v2 loop plugin was identified, and no proof of absence was attempted.

## New 🆕 row candidates

| Proposed ID | Feature | OpenCode 1.19.4 | OpenCode 2.1.4 | Other columns |
|---|---|---|---|---|
| PLG-N3 (or MCP-N2) | 🆕 Client-hosted tools: a UI client serves tools to the agent (e.g. drive the visible terminal pane) | ❌ not found (UNVERIFIED negative) | 🟡 Experimental client-MCP transport: SDK `connectClientMcp()`; the TUI serves `tui_open_terminal`/`tui_write_terminal` behind the experiments dialog (`packages/tui/src/context/client-mcp.tsx` L1-14, L75-95; changelog v2.1.4 #57504/#57505/#57506) | UNRATED. Hint: Claude Code's IDE integration exposes IDE-side tools to the model, worth checking |
| PER-N3 | 🆕 Persisted-approval management (list / edit / revoke saved "always" grants) | ❌ "always" approvals are in-memory until restart (existing PER-04 v1 cell) | 🟡 API/SDK only: GET/PATCH/DELETE `/api/permission/saved` (`client.permission.saved.*`); no TUI or desktop UI found in `packages/tui/src` or `packages/app/src/settings` | UNRATED. Hint: Claude Code `/permissions`; candy-code's Rules view (`permission.rules`) in web |
| OBS-N1 | 🆕 Live diagnostics capture (heap snapshot of a running server/app without restart) | UNRATED (`cli/cmd/tui.ts` imports `writeHeapSnapshot`; use not traced) | ✅ `opencode debug heap-dump [--server <url>]` (`packages/cli/src/commands/handlers/debug/heap-dump.ts`); desktop palette "Capture heap snapshots" (changelog v2.1.4 #58252, #58259, #58311) | UNRATED |
| UI-N7 (low value) | 🆕 Experimental-feature toggles in the UI | UNRATED | ✅ TUI experiments dialog (`packages/tui/src/component/dialog-experiments.tsx`) and desktop `settings/experimental`; `cli.json experimental` map | UNRATED |

## Narrative fixes

- Header L10: current `"| **OpenCode** | **1.18.35** (installed here) and **2.0.31** (latest major) | Source at both tags, shipped docs, CLI help, local configs and installed plugins. v1 latest is 1.19.3, so the installed copy is 4 releases behind |"` → new `"| **OpenCode** | **1.19.4** (latest v1; 1.18.35 is installed here) and **2.1.4** (latest v2; not installed here) | Source at 1.18.35, 1.19.4, 2.0.31 and 2.1.4, shipped docs and changelogs, CLI help, local configs and installed plugins. 1.18.35 → 1.19.4 changes only updates, session sharing, reasoning variants and the bundled Bun |"`
- Legend L26: current `"| Δ | (OpenCode 2.0.31 column) Changed from 1.18.35 |"` → new `"| Δ | (OpenCode 2.1.4 column) Changed from 1.19.4 |"`
- Contents L31: current `"[5. OpenCode 1.18.35 → 2.0.31](#5-opencode-11835--2031-what-changes-in-the-comparison)"` → new `"[5. OpenCode 1.19.4 → 2.1.4](#5-opencode-1194--214-what-changes-in-the-comparison)"`
- §1 L62: current `"native `Prune`/`Compress`/`Recall` tools (OpenCode needs the DCP plugin; Claude Code and OpenClaw have none);"` → new `"native `Prune`/`Compress`/`Recall` tools (OpenCode needs a plugin: DCP on 1.x, magic-context on either major; Claude Code and OpenClaw have none);"`
- §1 L65: current `"OpenCode 1.x needs the opencode-pty plugin; Claude Code has none)"` → new `"OpenCode 1.x needs the opencode-pty plugin and 2.1.4 has only an experimental, write-only `tui_write_terminal`; Claude Code has none)"`
- §1 L67: current `"(as Claude Code and OpenClaw; OpenCode 1.18.35 has no lock and `/goal` only via a plugin)"` → new `"(as Claude Code and OpenClaw; OpenCode has no lock and `/goal` only via opencode-goal-plugin, now on both majors)"`
- §1 L69: current `"lossless reconnect (OpenClaw and OpenCode 2.0.31 replay too; `candy-code attach` never reconnects)"` → new `"lossless reconnect (OpenClaw and OpenCode 2.x replay too; `candy-code attach` never reconnects)"`
- §2 L75: current `"| OpenCode 1.18.35 | OpenCode 2.0.31 |"` → new `"| OpenCode 1.19.4 | OpenCode 2.1.4 |"`. Also update the category cells and Total cells listed in the Summary, and add a footnote line: `_2026-10-26: OpenCode columns re-derived from §3 after the bump to 1.19.4 / 2.1.4._`
- §3 L118: current `"| OpenCode 1.18.35 | OpenCode 2.0.31 |"` → new `"| OpenCode 1.19.4 | OpenCode 2.1.4 |"`
- §4 L547: current `"The OpenCode cells in this section describe 1.18.35; §5 lists what 2.0.31 changes."` → new `"The OpenCode cells in this section describe the 1.x line (1.19.4; this host runs 1.18.35, which still advertises session sharing that no longer works); §5 lists what 2.1.4 changes."`
- §4 L656: current `"No sampling or elicitation (disabled deliberately), and no deferred loading by default (an experimental code mode hides MCP tools behind one `execute` tool; v2 makes it the default)."` → new `"No sampling or elicitation (disabled deliberately), and no deferred loading by default (an experimental code mode hides MCP tools behind one `execute` tool). v2 makes Code Mode the default, drops SSE, and supports elicitation; its forms render in TUI, desktop and web since 2.1.4."`
- §4 L689: current `"| 🔌 opencode-dynamic-context-pruning |"` → new `"| 🔌 opencode-dynamic-context-pruning (1.x only); magic-context `ctx_reduce` on either major |"`
- §4 L705: current `""always" approvals lost on restart; no sandbox, no trust dialog."` → new `""always" approvals lost on restart; no sandbox, no trust dialog. v2 persists approvals per project (editable through its API) but allows external directories by default and drops the doom-loop guard."`
- §4 L728: current `"- **OpenCode**: one server, many clients (TUI, web, desktop, VS Code extension, ACP, SDK, GitHub/GitLab bots, `attach`)."` → new `"- **OpenCode**: one server, many clients (TUI, web, desktop, VS Code extension, ACP, SDK, GitHub/GitLab bots, `attach`); v2 adds a per-user background service, password-protected web, `opencode pair` links/QR, OpenTunnel and an iOS app, but its CLI has no GitHub bot."`
- §5 L733-765: replace wholesale with the section below.
- §6.5 MCP row (third cell): current `"Configurable timeouts, 401 surfacing, embedded resource/image rendering and correct `claude-mcp` args shipped earlier"` → new `"Configurable timeouts, 401 surfacing, embedded resource/image rendering and correct `claude-mcp` args shipped earlier; still open: elicitation (OpenCode 2.1.4 renders forms in TUI, desktop and web) and sampling"`
- §6.7 item 8: current `"OpenCode v2's pairing links / QR codes and end-to-end tunnel for phone access;"` → new `"OpenCode v2's pairing links / QR codes and end-to-end tunnel for phone access, and its browser-rendered MCP elicitation forms;"`
- Appendix B L4910: current `"A follow-up pass compared OpenCode 1.18.35 with 2.0.31."` → new `"A follow-up pass compared the OpenCode 1.x and 2.x lines, at 1.19.4 and 2.1.4."`
- Appendix B L4931: current `"**OpenCode 1.18.35 and 2.0.31.**"` → new `"**OpenCode 1.19.4 and 2.1.4.**"`
- Appendix B L4932: current `"- Source at both tags of <https://github.com/anomalyco/opencode> (`packages/opencode/src/`, v2 core), the docs shipped with each tag, and <https://opencode.ai/docs/>."` → new `"- Source at the 1.18.35, 1.19.4, 2.0.31 and 2.1.4 tags of <https://github.com/anomalyco/opencode> (`packages/opencode/src/`, v2 `packages/core`), the docs shipped with each tag, the v2 `CHANGELOG.md`, <https://opencode.ai/changelog> and <https://opencode.ai/docs/>. v1 notes are GitHub releases; v2 tags have none. The installed binary is 1.18.35; v2 was read, never run."`
- Appendix B, plugin bullet: current `"READMEs of installed ecosystem plugins (opencode-goal-plugin, opencode-pty, ocx, DCP, opencode-agent-memory, …)."` → new `"npm metadata, READMEs and tarballs of ecosystem plugins (opencode-goal-plugin, opencode-pty, magic-context, DCP, opencode-agent-memory, supermemory, plannotator, oh-my-openagent, …)."`

No change is needed for the §1 bullets at L39-L58 or for §6.2/§6.4. Their OpenCode statements still hold, or they are candy-code statements.

## Proposed §5

```markdown
## 5. OpenCode 1.19.4 → 2.1.4: what changes in the comparison

The "OpenCode 1.19.4" column in §3 and the OpenCode cells in §4 describe the 1.x line. This host runs 1.18.35, which differs from 1.19.4 only in:
- update behaviour;
- session sharing, which is gone on both (the share host answers 410);
- reasoning-variant generation;
- the bundled Bun.

Upstream ships two maintained lines from one repo:
- **v1:** npm `opencode-ai`, `latest` = 1.19.4 (also GitHub's "Latest" release).
  - 1.19.x stays on 1.x: update checks read only `opencode-ai@latest`, the curl script refuses 2.x, and updates are notify-only.
  - `/update` and `opencode upgrade --major` are the explicit routes to v2.
- **v2:** npm `@opencode/cli`, `latest` = 2.1.4.
  - Install with `curl …/v2/install`, `npm i -g @opencode/cli`, the `anomalyco/tap/opencode-v2` tap, mise, or `brew install opencode`: the Homebrew core formula now ships v2. The v2 installer *replaces* the v1 binary.
  - v2 publishes release notes in the repo `CHANGELOG.md` and at opencode.ai/changelog, not as GitHub releases.
  - 2.1 renamed parts of the SDK, HTTP and plugin contracts: provider replay data moved to `native`, and summary compactions reference kept messages by `keepID`.

In the 2.1.4 column, **242 of 398 rows carry Δ and 72 have a different status code from 1.19.4**, which is why it gets its own column.

**What v2 gains (relevant to candy-code):**
- **Durable prompt inbox.** Typing while busy steers at the next step, as in v1. v2 adds an explicit queue-until-idle (leader+Enter) and makes the inbox durable. Execution is crash-recoverable through write-ahead claims under one per-user background service that every client attaches to (TUI, web, desktop, iOS, ACP).
- **Cache-stable instructions.** A frozen per-session baseline. Later AGENTS.md edits reach the model as a **unified diff message** ("The instructions from X changed. Here's the diff:"). MCP instructions, skills and references are also sent as changed entries only. The TUI shows "Instructions updated" / "Loaded <file>" rows, but not their content.
- **MCP through Code Mode by default.** One `execute` sandbox tool with budgeted one-line signatures, so full schemas aren't sent every request.
  - MCP **elicitation** forms (text, number, choice, multiselect, boolean, URL) render in the TUI, desktop and web.
  - An experimental client-MCP transport lets a UI client serve its own tools to the agent.
- **Built in:**
  - background shell and background sub-agents;
  - worktree tools;
  - `/btw`;
  - session tabs;
  - output-limit auto-continuation;
  - cache warming;
  - provider-native (OpenAI Responses) compaction;
  - whole-conversation summaries that reuse the prompt cache, with an optional `agents.compaction.model`;
  - session archiving;
  - PDF prompt attachments;
  - persistent PTY terminals, now on Windows too.
- **Experimental, off by default:**
  - background ("ahead-of-need") compaction;
  - the `todowrite` tool and todo panel;
  - TUI tools that let the agent open and type into the session terminal (write-only);
  - an SDK realtime-voice API.
- **Permissions.** Persisted per-project "Allow always" grants, listable, editable and deletable through `/api/permission/saved`. A working `permission.evaluate` plugin hook (v1's `permission.ask` never fired). Org policies, with Console-managed policies outranking everything.
- **Remote access.** Password-protected web by default, one-time `opencode pair` links / QR codes, OpenTunnel end-to-end remote, and an iOS app.

**What v2 still removes or regresses (re-checked at 2.1.4):**
- **Instructions:** no CLAUDE.md fallback at all (AGENTS.md only; discovery walks up to `$HOME`).
- **Code intelligence and tasks:** no LSP (no diagnostics, no lsp tool; `lsp` config is accepted but inert) and no structured output. The todo tool exists only behind `experimental.todo`, without v1's priority field.
- **Commands and MCP:**
  - no `@file` in command templates;
  - skills are no longer slash commands (load them with `@skill-id`);
  - no pruning options (`compaction.prune`/`tail_turns` are ignored);
  - no SSE MCP transport;
  - no `opencode agent create` wizard;
  - no `opencode github` / `opencode pr` (the repo's GitHub Action still installs v1 and runs `opencode github run`).
- **Prompts and editing:**
  - per-agent temperature/top_p in `request.body` is accepted but not sent; set them on a model variant instead;
  - models without an override, Gemini included, get a 15-line generic prompt (gpt/astra/kimi/trinity/meta and Anthropic keep theirs);
  - the fuzzy edit chain is two fallbacks (typographic variants, then trailing whitespace) instead of nine replacers.
- **Permissions:** `external_directory` is *allowed* by default; there is no doom-loop guard; sub-agents use their own rules instead of inheriting the parent's denies.
- **Sharing:** gone on both lines (2.0.30 and 1.19.1), so it no longer separates them.

**Plugins.** V1 plugin implementations still don't run on v2, but much of the ecosystem now ships v2 builds:
- **Run on v2:**
  - opencode-agent-memory 0.3.0 (v2 only)
  - opencode-goal-plugin 0.11+ (both majors; its author verified 2.0.16–2.0.31)
  - @plannotator/opencode (both)
  - opencode-supermemory 2.0.17 (both)
  - @cortexkit/opencode-magic-context (both: `ctx_reduce` pruning, hybrid recall, an overnight "dreamer")
  - opencode-froggy 1.6 (Claude-Code-style hooks)
  - opencode-handoff 0.6 (v2 ≥2.1.2 only)
- **Partial:** opencode-pty 0.4.0 has an `opencode-pty/v2` entry that registers only its web-UI commands, none of the `pty_*` agent tools; oh-my-openagent 5.1.48 loads on v2 with its agents, tools and hooks inactive.
- **Still V1-only:** DCP 3.2.0, subtask2, opencode-conductor-plugin, opencode-scheduler, opencode-gpt-imagegen, harness-memory.

**Effect on the candy-code comparison:**
- **Where v2 catches up with candy-code:**
  - cache-stable instructions (frozen baseline plus diffs);
  - a detachable background service (WEB-N4 ❌→✅) and durable event replay (WEB-N3 🟡→✅);
  - ahead-of-need compaction (CTX-N1, experimental);
  - a configurable summary model (MOD-13);
  - PDF attachments (MOD-22);
  - model-driven pruning, memory consolidation and goal mode through plugins that now run on v2.
- **Where v2 pulls further ahead of candy-code:**
  - the push transport around background work (output streamed to a managed file with a completion notification, versus candy-code's poll-`ShellOutput` loop);
  - the single-`execute` Code Mode packaging;
  - MCP elicitation forms in every client, including the browser (candy-code has no elicitation or sampling);
  - client-hosted tools;
  - built-in remote pairing and tunnel (candy-code signs in by token and attaches, but has no pair/QR/tunnel).

  Background shell and deferred MCP loading themselves have since shipped in candy-code: `Bash run_in_background` over a `BackgroundShellRegistry` with `ShellOutput`/`ShellStop`, and `mcp_list`/`mcp_call` deferral.
- **Where v2 falls further behind candy-code:**
  - no LSP;
  - no structured output (candy-code has `--json-schema`);
  - no CLAUDE.md;
  - a permissive external-dir default;
  - no doom-loop guard;
  - todos off by default;
  - PTY driving only as a blind, experimental write (candy-code's `PtyRun` reads output).

**Host-specific notes:**
- **Installed copy.** 1.18.35 (curl install at `~/.opencode/bin/opencode`); v2 is not installed. With `autoupdate: true` the binary still stayed on 1.18.35 through runs up to 2026-10-19 (cause not investigated). On 1.19.x the same setting only notifies.
- **Plugin pins for v1.** v1 resolves an unpinned or `@latest` plugin once and reuses `~/.cache/opencode/packages/<spec>` while that directory exists, so it keeps loading the cached `opencode-agent-memory` 0.2.0 even though `@latest` is now the v2-only 0.3.0. The same holds for `opencode-handoff` (cached 0.5.0; `@latest` 0.6.1 needs OpenCode ≥2.1.2). Both break only if that cache is cleared or the config moves to a fresh machine. Pinning `opencode-agent-memory@0.2.0` and `opencode-handoff@0.5.0` is a cheap safeguard for v1. v2 keeps a separate cache (`~/.cache/opencode/npm/`) and would fetch the v2 builds.
- **v2 config migration.**
  - It would ignore `subagent_depth: 4` (moved to `experimental.subagent_depth`), make `lsp: true` and the `lsp` permission entries inert, and map `autoupdate: true` to `update: "auto"`.
  - Of the 11 global plugin entries, only plannotator, agent-memory and handoff would run.
  - opencode-pty would need the `opencode-pty/v2` spec and still give the agent no PTY tools.
  - DCP (global `@latest` and this repo's pinned `@3.1.3`), subtask2 and conductor would not run.
- **Large first-start import.** On first start, v2 would import the v1 session store, `opencode.db`, 11.7 GB here (not exercised).
```

## What's new (for the plan)

OpenCode 2.1.4 capabilities that candy-code lacks or only partly matches:

1. **MCP elicitation.** Forms (text, number, choice, multiselect, boolean, URL) appear during tool calls in the TUI, desktop and web. candy-code has neither elicitation nor sampling; this needs an engine handler plus TUI modal plus web drawer.
2. **Client-hosted tools.** A connected client (TUI or SDK) serves tools to the agent over a client-MCP channel (`connectClientMcp`). candy-code-web could expose, for example, a browser-side terminal or file picker.
3. **Persisted-approval management.** Saved "always" grants can be listed, edited and deleted (GET/PATCH/DELETE `/api/permission/saved`). candy-code persists rules but would need a list/revoke surface, especially in the web Rules view.
4. **Configurable summary model.** `agents.compaction.model` runs summaries on a cheaper model, with fallback to the session model.
5. **Cache-reusing whole-conversation summaries.** The summary request re-sends the full conversation so it hits the prompt cache, keeps ~15k tokens verbatim, and references kept text by message ID instead of duplicating it.
6. **Changed-entry-only updates** for MCP instructions, skills and references (extending the instruction-diff idea that candy-code already has for instruction files).
7. **Background compaction** (experimental) prepared at 85% of the threshold. candy-code already pre-fetches summaries; keep parity on the skip rules (native compaction, model switch, under ~64k tokens).
8. **Session archive and restore** with archive filtering in the list/search APIs.
9. **Overlong-compaction resilience.** When the compaction request itself overflows, shorten older tool output first.
10. **Realtime voice API** (experimental, SDK). Lower priority; no TUI or desktop UI either.
11. **Live heap-dump diagnostics.** `debug heap-dump --server <url>`, without restarting the server.
12. **Remaining earlier v2 gaps**, unchanged: push-style background completion notifications, a single-`execute` Code Mode packaging for MCP, and `pair` links / QR / end-to-end tunnel for phone access.
