# cc-audit-k3: COMPARISON.md audit of PER · GIT · UI · WEB · AUT · CFG · OBS · PA, plus §1 (web/table), §4.10-4.12, §6.6-6.7

Audited against live code at HEAD 5e9195687 (2026-10-26), statically. Nothing was executed except `php -r` roster counts over `candy-code/vendor/autoload.php`. All paths are relative to `/home/sites/sugarcraft/candy-code/` unless they start with `candy-code-web/` or `sugar-*`.

## Summary

**Verdict:** the candy-code-web column in scope is badly stale, and so is a smaller but real set of candy-code cells. WEB-1 through WEB-7 shipped (Rules, Changes, Context, Generate and Catalog panels; About modal; host bodies; `fs.search` `@` popup; image paste; prompt history; trust prompt; `tool.progress`). Many web cells still describe the pre-campaign state: 🧪 "never calls", or ❌. On the candy-code side the campaign's TUI ships are not reflected in UI-09, UI-10, UI-25 or UI-33. UI-33 still says ❌ "No startup banner", while §1 and §4.11 describe StartupSummary.

**Counts**

| Item | Count |
|---|---|
| Cell changes | **39** (20 with a glyph change) |
| Detail-only changes | **29** |
| Narrative fixes | **22** |
| Gap blocks | **62** |
| Dormant-code items | **5** |

**Live roster figures:**
- **Protocol:** `Dispatcher::methods()` registers **69** methods and `docs/protocol/candy-code.v1.schema.json` lists 69. `EventType::CATALOGUE` has **35** events.
- **Web callers:** the web requests **61 of 69**. The 8 never requested are `files.read`, `memory.log`, `memory.undo`, `session.share`, `session.unshare`, `workflow.status`, `workspace.close` and `server.shutdown`; the last is Admin-scope, arrives as a notification, and is handled at `candy-code-web/src-web/stores/connection.ts:137`.
- **Built-in slash commands:** 57 are slash-visible. 41 have host bodies and run on the server over `command.exec`; 16 are client-only and refused `ui_only`: `new sessions effort exit theme settings keys help compress newrule pane layout editor budget review security-review`.

**Stale doc counts:**
- §1 says "8 of the **66**". The 8 is right but the total is 69.
- §4.12 says "58 of the 66". It should be 61 of 69.
- §6 says files.read, workflow.status and workspace.close are the only uncalled methods. That list misses four: memory.log, memory.undo, session.share and session.unshare.
- §1 says "`/theme`, `/keys`, `/sessions` are terminal-only". That is true but under-reported: there are 16 such built-ins, and `/review` and `/security-review` are the material ones.

**The §6.6 vim claim is verified.** TUI-3B shipped: c73a89859 ported the Vim layer onto TextArea with `VimAction::CursorUp/CursorDown` (`sugar-forms/src/Vim/VimAction.php:29-32`). The VisualLine j/k inversion is still pinned (`sugar-forms/src/Vim/VimKeyHandler.php:259-260`). The composer aliases `V` to `v`, so VisualLine is unreachable from the app.

**The §6.7 "WEB-1..WEB-7 shipped" claim holds, with two corrections:**
- The Generate form is image-only. `canGenerateVideo` is computed but nothing reads it.
- Item 5 omits two WEB-5b ships: the `fs.search` `@` popup and image paste.

WEB-7c (i18n) is deferred, as the doc says.

**New live defect (AUT-08, dormant):**
- **Symptom:** `/loop` typed into a served session (browser `command.exec`) answers "registered" but never fires.
- **Cause:** `ScheduleRegistry::fireDue()` skips ephemeral rows on serve (`src/Background/ScheduleRegistry.php:395-397`), and the only ephemeral pump is Chat's (`src/Chat.php:18556-18566`).
- **Scope:** that affects every served session.

**Scoreboard deltas (§2) if every glyph change below is applied** (taxonomy rows only; UI-N3 and WEB-N3 are 🆕 rows and excluded):

| Column | Category or total | Current | After |
|---|---|---|---|
| candy-code | UI | ✅21 🟡12 ❌1 | ✅24 🟡10 ❌0 |
| candy-code | PA | 🟡2 ❌6 | 🟡3 ❌5 |
| candy-code | **Total** | | **✅227 🟡92 🧪1 ❌35 ➖1** |
| candy-code-web | PER | ✅7 🟡5 🧪1 ❌2 | ✅7 🟡7 ❌1 |
| candy-code-web | GIT | 🟡5 🧪1 ❌4 ➖2 | 🟡7 ❌3 ➖2 |
| candy-code-web | UI | ✅14 🟡9 ❌10 ➖1 | ✅14 🟡11 ❌8 ➖1 |
| candy-code-web | AUT | ✅1 🟡1 ❌4 ➖6 | ✅2 🟡2 ❌2 ➖6 |
| candy-code-web | CFG | ✅4 🟡2 ❌2 | ✅4 🟡3 ❌1 |
| candy-code-web | OBS | 🟡4 ❌2 | ✅1 🟡3 ❌2 |
| candy-code-web | **Total** | | **✅92 🟡164 🧪2 ❌71 ➖20 ❓7** |

Each total sums to 356.

**Out-of-scope observations, not acted on:**
- The §2 notes (L105-107) carry dated history: "2026-10-26: … at 3c890860d (plan-reverify-1026 rcp1–rcp5)", "closeout", "transcription slip". These break the no-SHA/no-history rule.
- §1 L51 ("The 2026-10-16 compile also listed … no longer a gap") is history voice.
- Appendix A in-scope details still carry `_2026-10-18 remediated:_`, `_2026-10-26 superseded:_` and "(ISSUES P1/P2)" clutter in about 30 bullets. The ones in scope are replaced below.

## Cell changes

### PER-03 · candy-code-web
- current: `🧪 `permission.rules` (read-only) exists but the web never calls it; `/permissions` prints the gate as text; no rule editor`
- new: 🟡 Rules panel lists the effective mode (+source), allow/ask/deny rules and session grants read-only; no rule editor
- detail: `permission.rules` (Scope::Read) answers mode, modeSource, rules and sessionGrants. The Rules side panel renders them read-only and reloads on session switch, using a generation latch against the switch race. Rules change only in settings files, and the mode moves through the status-bar select. — _Evidence:_ `candy-code-web/src-web/components/panels/RulesPanel.vue:7-55`; `src/Protocol/Methods/PermissionMethods.php:53,213-227`; `candy-code-web/src-web/components/panels/SidePanels.vue:98`
- why: RulesPanel (WEB-1c) calls the method; the 🧪 premise is false.

### PER-07 · candy-code
- current: `🟡 — Auto mode is a regex classifier; with `autoReview` on, every non-read-only call — not just flagged ones — goes to the `ExecReviewer``
- new: 🟡 Regex classifier; opt-in `autoReview` sends flagged calls and every unflagged non-read-only shell call to `ExecReviewer`
- detail: The classifier's pattern families are:
  - curl/wget-into-shell: pipes, process substitution `bash <(…)`, download-then-run, pipes into python/perl/ruby/node/php, and `$(…)`/backtick URLs
  - external endpoints
  - pre-session deletion, including `git clean -f`
  - force-push and reset-hard

  Security findings always ask. With `autoReview` on, unflagged Bash/PtyRun calls that are not auto-allowed read-only lines also go to the reviewer. Edit, Write and WebFetch keep the classifier-only verdict. — _Evidence:_ `src/Permissions/SafetyClassifier.php:79-106,210-224`; `src/Permissions/PermissionGate.php:909-921,998-1003`
- why: The unflagged review covers ShellSubjectTools only, not every call. The old detail's "Verified-by-repro … all Allow" list is now matched by the classifier.

### PER-09 · candy-code
- current: `🟡 — `SecretRedactor` covers memory, journal, dream writes and the audit log (redaction wired into `AuditHook`); streamed tool output stays raw`
- new: 🟡 Credential env vars scrubbed from child processes; `SecretRedactor` covers memory, journal, dreams, audit log and `/share`; tool output raw
- detail: SECRET_ENV_PATTERNS scrubs `*_API_KEY`, `*_TOKEN`, `*_SECRET`, `AWS_*`, `*_PASSWORD/_PASS/_PWD`, `*_DSN`, `*_CREDENTIALS`, `*_KEY`, `DATABASE_URL` and `PGPASSWORD`. SSH_AUTH_SOCK is kept on purpose, and `secretEnvAllowlist` releases named variables. `env`/`printenv` are never auto-run. Streamed and recorded tool output is not redacted, and `/export` writes raw bytes by design (`/share` redacts). — _Evidence:_ `src/Support/ProcessContainment.php:115-135`; `src/Permissions/ReadOnlyCommands.php:353-359`; `src/Commands/ExportCommand.php:20-33`; `src/Hooks/BuiltIn/AuditHook.php:479-503`
- why: The env-scrubbing half of the row was missing from the cell. The old detail ("Not scrubbed: *_PASSWORD, PGPASSWORD, … env/printenv run unasked") is false.

### PER-11 · candy-code-web
- current: `❌ No workspace-trust dialog in the web`
- new: 🟡 Context panel prompts to trust an untrusted project `.mcp.json` (this run, or saved user-tier); no general trust dialog
- detail: A `context.sources` measurement mints one server-scope trust prompt per untrusted root: a `permission.asked` with kind "trust". The Context panel answers it with `context.trust` (Scope::Approve). A user-tier remember writes `trustedProjectMcp`; a project-tier remember is refused. The approvals drawer skips these prompts, so they show only in the Context panel, and MCP is the only grantable target. — _Evidence:_ `src/Protocol/TrustAsks.php:5-30`; `src/Protocol/Methods/ContextMethods.php:50,62-86`; `candy-code-web/src-web/components/panels/ContextPanel.vue:15-24`; `candy-code-web/src-web/stores/approvals.ts:124-128`
- why: WEB-3b shipped a trust prompt.

### PER-14 · candy-code
- current: `✅ AuditHook logs every call/refusal to `$TMPDIR/candy-code-audit-<euid>/audit.log` (4 KiB in, 200 B out); never rotated`
- new: ✅ AuditHook logs every call/refusal to `~/.candy-code/audit/audit.log` (4 KiB in, 200 B out, redacted); rolls to `.1` at 5 MiB
- detail: The default directory is under the user's home (0700, owner-checked); the euid-scoped temp directory is only a fallback. Excerpts are redacted before they are cut, and are written only after PostToolUse hooks allow the output. Lines are injection-safe. — _Evidence:_ `src/Hooks/BuiltIn/AuditHook.php:81-114,279-305,479-503`
- why: The cell has the wrong path and wrong rotation. The old detail said "sits in tmp … unredacted".

### GIT-01 · candy-code-web
- current: `❌ No branch/status display; `files.changed` is unused`
- new: 🟡 Changes panel lists changed/untracked files (`files.changed`) with the workspace diff; no branch display
- detail: The Changes panel loads `files.changed` and `files.diff` together, fail-soft per side. Clicking a file narrows the diff to it, and untracked files are labelled. SessionSummary carries no branch, so the web never shows one. — _Evidence:_ `candy-code-web/src-web/components/panels/ChangesPanel.vue:9-58`; `src/Protocol/Methods/FilesMethods.php:38-39`; `candy-code-web/src-web/protocol/generated.ts` (SessionSummary, no `branch`)
- why: WEB-1b wired `files.changed`.

### GIT-09 · candy-code-web
- current: `🧪 Per-call diffs render; the session-wide `files.diff` / `files.changed` methods exist but are unused; `/diff` prints text`
- new: 🟡 Changes panel: changed-file list + whole workspace diff (click narrows to a file); per-call diffs; no per-turn/checkpoint view
- detail: The diff arrives whole and is carved per file client-side (`diffSectionForPath`), then rendered by DiffView. There is no checkpoint/turn base selector. `/diff [n]` still prints text. — _Evidence:_ `candy-code-web/src-web/components/panels/ChangesPanel.vue:31-72`; `candy-code-web/src-web/lib/diff.ts`; `candy-code-web/src-web/components/DiffView.vue`
- why: The methods are called; 🧪 is false.

### GIT-10 · candy-code-web
- current: `❌ No review command`
- new: ❌ `/review` and `/security-review` are terminal-only (refused `ui_only`); no review panel
- detail: Both specs have a Chat handler but no host command, so `command.list` marks them `runsIn: client` and the composer refuses them. — _Evidence:_ `builtin-commands/2840-review.php`; `src/Protocol/Methods/CommandMethods.php:117-120`; `candy-code-web/src-web/stores/session.ts:168-171`
- why: The old cell implied no command exists at all; the cause is the missing host body (see GAP-GIT-10).

### UI-09 · candy-code
- current: `🟡 ↑/↓ on empty box recalls prompts across sessions (`prompt_history.jsonl`); no reverse/incremental history search`
- new: ✅ ↑/↓ on an empty box recalls prompts across sessions (`prompt_history.jsonl`); Ctrl+G reverse search overlay (Ctrl+R = session picker)
- detail: HistorySearch filters recalled prompts newest-first by case-insensitive containment. Pressing Ctrl+G again cycles matches. Enter accepts into the draft without sending, and Esc restores the pre-search draft. There is no session/project scope switch. — _Evidence:_ `src/Tui/HistorySearch.php:5-25`; `src/Commands/KeyBindingRegistry.php:947-949`; `src/Chat.php:17781-17810`; `src/Renderer.php:6418-6424`
- why: TUI-2 shipped.

### UI-09 · candy-code-web
- current: `❌ No ↑ prompt history or Ctrl+R; only a persisted per-session draft`
- new: 🟡 ↑/↓ walks a per-browser 50-prompt history ring; no reverse/incremental search
- detail: `promptHistory.ts` keeps a most-recent-first ring in localStorage, capped at 50, skipping a duplicate of the head. The composer's ↑/↓ walk it and restore the draft on the way back. — _Evidence:_ `candy-code-web/src-web/stores/promptHistory.ts:1-36`; `candy-code-web/src-web/components/Composer.vue:147-208`
- why: WEB-5a-i shipped.

### UI-10 · candy-code
- current: `🟡 `@path` mentions with shell-style Tab completion plus `@diff`, `@session:`, `@https://`, `$skill`; no popup or fuzzy picker`
- new: ✅ `@` opens a fuzzy file popup (git-listed files, Smith-Waterman rank); Tab still completes paths; `@diff`, `@session:`, `@https://`, `$skill`
- detail: MentionIndex memoizes the per-project file list (tracked plus non-ignored) and ranks it with sugar-fuzzy's Smith-Waterman, basename first. ↑/↓/Tab/Enter accept and Esc closes. Outside git the popup is empty and unique-prefix Tab completion remains. Mentions attach on Enter, up to 20 files of 256 KiB each. — _Evidence:_ `src/Attachments/MentionIndex.php:54`; `src/Renderer.php:1862-1868`; `src/Protocol/Methods/FsMethods.php:41-44` (same index)
- why: TUI-1 shipped.

### UI-10 · candy-code-web
- current: `🟡 `@path` mentions are resolved server-side when sent, but there is no autocomplete or file picker`
- new: ✅ `@` popup ranks workspace files via `fs.search` (the TUI's MentionIndex ranking, root-jailed); mentions resolve server-side
- detail: `fs.search` (Scope::Read) reuses MentionIndex over the caller's own workspace, re-checks containment, and caps rows at the TUI popup limit. The composer queries it for the trailing `@partial`. It returns files only: no folder, agent or MCP rows. — _Evidence:_ `src/Protocol/Methods/FsMethods.php:33-71`; `candy-code-web/src-web/components/Composer.vue:18-19,72-100`; `candy-code-web/src-web/stores/session.ts:193-199`
- why: WEB-5b shipped.

### UI-12 · candy-code-web
- current: `❌ No image paste or drag-drop`
- new: 🟡 Clipboard image paste (PNG/JPEG/GIF/WebP) attaches chips sent with `session.send`; no drag-drop
- detail: `readPastedImages` turns clipboard Blobs into strict-base64 entries of the sniffed raster type. Images never ride a `/command` or `!bang`. No drop handler exists. — _Evidence:_ `candy-code-web/src-web/lib/paste.ts:1-20`; `candy-code-web/src-web/components/Composer.vue:70-71,114-122`; `candy-code-web/src-web/stores/session.ts:172-176`
- why: WEB-5b shipped.

### UI-16 · candy-code-web
- current: `✅ — Virtualized transcript; the streaming tail renders as pre-wrap text and re-parses Markdown once on completion; a polite status announces the finished reply`
- new: 🟡 Native browser text selection copies; no copy-message or copy-code-block button
- detail: No component calls the Clipboard API (a grep for `navigator.clipboard`/`copy` across `src-web` finds no caller), so code blocks and replies have no copy affordance. — _Evidence:_ `candy-code-web/src-web/components/MessageMarkdown.vue`; `candy-code-web/src-web/components/TranscriptItem.vue`
- why: The cell text describes transcript rendering, not copying, which is the wrong row. The detail bullet was already ❌.

### UI-19 · candy-code-web
- current: `✅ Side panels: Agents, Todo, Background, Workflows, Memory (drawer, or its own column on wide screens)`
- new: ✅ Ten side panels: Agents, Todo, Background, Workflows, Memory, Rules, Catalog, Changes, Context, Generate (drawer or own column)
- detail: cell unchanged otherwise. — _Evidence:_ `candy-code-web/src-web/stores/agents/panels.ts:5`; `candy-code-web/src-web/components/panels/SidePanels.vue:94-104`
- why: Five panels shipped since.

### UI-21 · candy-code-web
- current: `✅ Tool cards: icon, name, key argument, +/− stats, duration; collapsed on success, open on error/refusal`
- new: ✅ Tool cards: icon, name, key argument, live output tail while running, +/− stats, duration; collapsed on success
- detail: While a call runs, the newest ephemeral `tool.progress` frame's tail renders in a live `<pre>`, terminal sequences stripped, with an "older output dropped" tag. `tool.finished` replaces it. — _Evidence:_ `candy-code-web/src-web/components/ToolCard.vue:44,69-71`; `candy-code-web/src-web/stores/reducer.ts:70`; `src/Protocol/EventType.php:62`
- why: WEB-4 shipped.

### UI-23 · candy-code
- current: `✅ — `statusLine` runs in a forked child (BangShell shape) with the line delivered via an IPC result message; no frame-blocking`
- new: 🟡 `statusLine` shell command runs in a forked child and fills a status-bar segment; no session JSON on stdin (model, cost, context)
- detail: The line refreshes every 2 s (configurable up to 1 h). Output is stripped of ANSI, zone sentinels and newlines and fitted last, and returns over ToolIpc, so a frame never blocks. Stdin is closed at spawn and only the inherited, scrubbed env is passed. Claude Code's JSON payload has no counterpart. — _Evidence:_ `src/Config/StatusLineCommand.php:528,590-623`
- why: The scriptable half lacks session context, which competitors provide. The old cell described the implementation, not the feature.

### UI-25 · candy-code
- current: `🟡 Chord overrides per binding id via the `keybindings` setting: a registered override beats the shipped row (`KeyBindingRegistry::chordFor`:252), dispatch funnels…`
- new: ✅ `keybindings` remaps any binding id to a chord or null (unbind); collisions announced; `/keys reload` re-applies; `/keys` shows effective
- detail: Overrides and suppressions register at launch after validation. `/keys reload` re-runs the same path mid-session (there is no file watcher) and clears every derived memo. An unbound chord is rewritten to an inert sentinel, so the shell regains the key. Vim keys are a mode, not registry rows, and are not rebindable. — _Evidence:_ `src/Commands/KeyBindingRegistry.php:200-301`; `src/Commands/KeyBinding.php:174`; `src/Chat.php:11947-11955`; `src/Config/Settings/Definitions/InterfaceSettings.php:281-289`
- why: TUI-4a/4b/4c shipped. The old cell said "Launch-only", and the old detail is ❌ plus `_remediated_`.

### UI-26 · candy-code-web
- current: `✅ — Still needs HTTPS/localhost for the Notification API, but a refused/unavailable notify now explains why (insecure/denied/unsupported shown in the topbar and drawer)`
- new: ✅ Opt-in desktop notifications for questions; needs HTTPS or localhost, and says why when insecure, denied or unsupported
- detail: cell unchanged in substance; current-state voice. — _Evidence:_ `candy-code-web/src-web/stores/settings.ts:8-14`; `candy-code-web/src-web/components/approvals/ApprovalsDrawer.vue:93`
- why: "now explains" is history voice.

### UI-30 · candy-code-web
- current: `✅ — Contrast spec derives pairs from tokens.css with the WCAG formula (no text token under 4.5:1); streaming no longer re-announces via aria-live`
- new: ✅ WCAG AA token pairs pinned by a contrast spec; finished replies and arriving questions announced via polite live regions
- detail: The streaming tail is plain pre-wrap text announced once, politely, on completion. A newly arrived question announces its origin from App.vue's region. — _Evidence:_ `candy-code-web/src-web/views/SessionView.vue:34-35,171`; `candy-code-web/src-web/lib/questions.ts:9-36`; `candy-code-web/src-web/styles/tokens.css`
- why: "no longer" is history voice, and the question announcements (WEB-7a) were missing. The old detail carries `_remediated_` clutter.

### UI-33 · candy-code
- current: `❌ — No startup banner (version, model, cwd, loaded files); the empty-transcript placeholder now renders as styled italics, not literal underscores`
- new: ✅ Fresh launch shows a four-row summary: version, provider·model·mode, cwd·branch, loaded instruction files, memory/skill/MCP counts, a tip
- detail: StartupSummary replaces the empty-conversation placeholder view-side only, with no transcript row, so `/clear` brings it back. Facts are gathered fail-soft, and the tip rotates by session id. It has no logo, effort, account or announcements. — _Evidence:_ `src/Support/StartupSummary.php:5-40,166`; `src/Renderer.php:4214-4224`
- why: VIS-1 shipped. The cell contradicted §1 and §4.11.

### UI-N3 · candy-code-web
- current: `🧪 Generated images/videos render as MediaCards, but there is no generate form: `generate.submit/status` and canGenerate* are unused`
- new: 🟡 Generate panel: txt2img form over `generate.submit`/`status`, results as MediaCards; no img2img, inpaint or video
- detail: The panel is offered only when `server.hello` reports image capability. It sends prompt, negative prompt and count, then polls `generate.status` and renders artifacts through MediaCard (`media.fetch`). `canGenerateVideo` is computed but nothing reads it. A typed `/generate <prompt>` also runs server-side. — _Evidence:_ `candy-code-web/src-web/components/panels/GeneratePanel.vue:7-38`; `candy-code-web/src-web/stores/connection.ts:90-91`; `candy-code-web/src-web/stores/generate.ts:85-109`; `src/Protocol/Methods/MediaMethods.php:93-110`
- why: WEB-1d shipped.

### WEB-02 · candy-code
- current: `✅ HTTP+WS JSON-RPC 2.0 `candy-code.v1`: 66 methods, 35 events, generated schema, validated params, idempotency, seq replay`
- new: ✅ HTTP+WS JSON-RPC 2.0 `candy-code.v1`: 69 methods, 35 events, generated schema, validated params, idempotency, seq replay
- detail: cell only. — _Evidence:_ `src/Protocol/Dispatcher.php:126-147`; `docs/protocol/candy-code.v1.schema.json` (`methods`: 69); `src/Protocol/EventType.php:47` (`CATALOGUE`: 35)
- why: The three media methods and `server.hello` are not in the 66.

### WEB-02 · candy-code-web
- current: `✅ Speaks the server's HTTP + WS JSON-RPC API; requests 58 of 66 methods`
- new: ✅ Speaks the server's HTTP + WS JSON-RPC API; requests 61 of 69 methods
- detail: Never requested: `files.read`, `memory.log`, `memory.undo`, `session.share`, `session.unshare`, `workflow.status`, `workspace.close`, and `server.shutdown` (Scope::Admin; it arrives as a notification and is handled). — _Evidence:_ `src/Protocol/Methods/FilesMethods.php:40`; `MemoryMethods.php:70-71`; `SessionMethods.php:57-58`; `WorkflowMethods.php:59`; `WorkspaceMethods.php:37`; `candy-code-web/src-web/stores/connection.ts:137`
- why: The detail's 17-method "Unused" list is stale (most are now called).

### WEB-10 · candy-code
- current: `❌ No mobile app; the responsive web UI ships an installable PWA manifest (`manifest.webmanifest`, 969e63005)`
- new: ❌ No mobile app; the responsive web UI is installable as a PWA (`manifest.webmanifest`)
- detail: cell only. — _Evidence:_ `candy-code-web/public/manifest.webmanifest`; `candy-code-web/index.html:7`; `src/Server/Http/StaticFiles.php:52`
- why: Commit SHA in a cell.

### WEB-N2 · candy-code-web
- current: `✅ — `server.overflow` resubscribes the named (or every followed) session from its cursor; `server.shutdown` surfaces the reason and grace`
- new: ✅ Sidebar lists sessions of every running root (`workspace.list`); the folder picker opens new roots; no way to close a root host
- detail: With dir-browse on, the sessions store reads `workspace.list` and pages `session.list` per running non-primary root. `workspace.open {browse:true}` starts a host. A host death's `server.overflow` resubscribes the named session, or every followed one, from its cursor. `workspace.close` is never called. — _Evidence:_ `candy-code-web/src-web/stores/sessions.ts:58-76`; `candy-code-web/src-web/stores/newSession.ts:47-59`; `candy-code-web/src-web/stores/connection.ts:124-140`
- why: The cell described overflow handling, not multi-root. The old detail ("The web ignores server.overflow") is false.

### WEB-N3 · candy-code
- current: `🟡 Server side complete (durable seq log, `hello {resume}` replay, idempotency keys); `candy-code attach` never reconnects`
- new: ✅ Durable seq log, `hello {resume}` replay, idempotency keys; `candy-code attach` redials with backoff and resumes from its cursor
- detail: The server logs durable events with a gap-free per-session `seq` before broadcast. 20,000 are kept in the session DB, so seqs continue across restarts. Reconnecting the attach client:
  - It redials after 0.25 s, doubling to 4 s, for up to 12 attempts.
  - It re-says `server.hello` with `resume: {sessionId: lastSeq}`.
  - When the log can no longer serve the cursor, it stitches a paged reset snapshot.
  - A deliberate close, a refused resume or an exhausted ladder ends it.
  — _Evidence:_ `src/Host/RemoteSessionHost.php:85-95,866-935`; `docs/SERVER.md:990-1000`
- why: ISS-114 (d32c08f66) predates the doc. The old detail ("lastSeq … nothing reads it") is false.

### AUT-07 · candy-code-web
- current: `❌ —`
- new: 🟡 `/schedules` list/enable/disable/delete runs server-side (serve fires persisted rows); no schedules panel
- detail: SchedulesCommand has a host body (`runsIn: server`). Serve's BackgroundEvents tick fires due persisted rows through `fireDue` as background sessions, and the Background panel shows them. — _Evidence:_ `src/Host/Commands/SchedulesCommand.php:5-30,53-58`; `src/Host/BackgroundEvents.php:110-130,227`; `src/Host/SessionHost.php:1647`
- why: The command is reachable from the composer.

### AUT-08 · candy-code-web
- current: `❌ No loop/recurring prompts in the web`
- new: ❌ `/loop` is accepted over `command.exec` but its in-memory row never fires in a served session; no loop UI
- detail: LoopCommand registers an ephemeral (non-persisted) Schedule and replies "registered". Only Chat's pump fires ephemeral rows; `ScheduleRegistry::fireDue()`, the serve path, skips them. A browser-typed loop therefore silently never runs. — _Evidence:_ `src/Host/Commands/LoopCommand.php:63-81`; `src/Background/ScheduleRegistry.php:391-397`; `src/Chat.php:18556-18566`; `src/Host/SessionHost.php:1647`
- why: The glyph stands, but the cell must not imply the command is simply absent: it is a live silent failure.

### AUT-09 · candy-code-web
- current: `❌ `/goal` and `/grind` are client-only — refused `ui_only` over the wire`
- new: ✅ `/goal` and `/grind` run server-side via `command.exec`; the served host runs the title-model judge loop between turns
- detail: After each settled turn with a live goal, SessionHost schedules the judge, mirroring Chat's loop through the shared GoalLoop decision table (budget and stop conditions). — _Evidence:_ `src/Host/SessionHost.php:1841-1870`; `src/Host/Commands/GoalHostCommand.php`; `src/Host/Commands/GrindHostCommand.php`; `src/Protocol/Methods/CommandMethods.php:117-120`
- why: WEB-2 shipped Goal/Grind host bodies.

### CFG-04 · candy-code
- current: `✅ 43 `CANDY_CODE_*` variables (+20 provider/OS vars) documented in `docs/ENVIRONMENT.md` and `--help`, drift-tested`
- new: ✅ 45 `CANDY_CODE_*` variables (plus OTel, provider, terminal and OS vars) in `docs/ENVIRONMENT.md` and `--help`, drift-tested
- detail: cell only. The two `CANDY_CODE_OTEL_*` variables (OBS-1) bring the app table to 45. `CANDY_CODE_TOOL_CALL_PARSER` at `:297` is a config-interpolation example, not a read variable. — _Evidence:_ `docs/ENVIRONMENT.md:30-167`; `tests/Config/EnvRosterDriftTest.php`
- why: The count is stale.

### CFG-06 · candy-code-web
- current: `❌ No diagnostics page (`doctor` is CLI)`
- new: 🟡 About modal shows server version, providers, tools and health; no `doctor` checks in the browser
- detail: AboutModal reads `server.info` (version, protocol, root, providers, tools, commands, modes, agents) and `server.health` (ok, uptime, sessions open, turns running, draining), each fail-soft. — _Evidence:_ `candy-code-web/src-web/components/AboutModal.vue:7-113`
- why: WEB-1c shipped a partial diagnostics surface.

### CFG-08 · candy-code
- current: `🟡 mcp import claude/opencode translates MCP configs; memory import; no settings/permission import`
- new: 🟡 `mcp import claude/opencode`, memory import; Claude hooks, rules and commands read live; no settings or permission import
- detail: Foreign configuration is read live rather than migrated:
  - the `hooks` block of `.claude/settings.json`
  - `.claude/rules` and `.cursor/rules` trees
  - `.claude/commands` and `.opencode/command(s)`, at lowest precedence
  - foreign agents and skills

  Permissions, env and model in `.claude/settings.json` are not imported. — _Evidence:_ `src/Hooks/ClaudeSettingsHooks.php`; `src/Commands/ForeignCommandDiscovery.php`; `src/Host/Commands/MemoryCommand.php:211`
- why: INT-2/3/4 shipped.

### OBS-01 · candy-code-web
- current: `🟡 — Swallowed internal errors now reach the server log; the log itself is still only visible to `serve`'s stdout, not the browser`
- new: 🟡 Internal errors reach the server log (`serve logs`); no log view in the browser
- detail: The Dispatcher logs handler failures to the serve log; the browser has no log method or panel. — _Evidence:_ `src/Protocol/Dispatcher.php:365-370`; `docs/SERVER.md:123-212`
- why: History voice. The detail glyph is ❌ against the 🟡 cell.

### OBS-03 · candy-code-web
- current: `🟡 Spend per session in status bar, tiles and summaries; no usage report page`
- new: 🟡 Spend per session in status bar, tiles and summaries; `/usage` rollups run server-side as text; no usage page
- detail: UsageHostCommand returns the 7/30/all day×model rows over `command.exec`. — _Evidence:_ `src/Host/Commands/UsageHostCommand.php`; `builtin-commands/2210-usage.php`
- why: The host body is reachable from the web.

### OBS-04 · candy-code-web
- current: `🟡 Top bar: connection state + RTT; dashboard: web and server versions; `server.health`/`server.info` unused`
- new: ✅ `/status` runs server-side; About modal shows `server.info` + `server.health`; top bar shows connection state + RTT
- detail: StatusHostCommand answers the read-only snapshot over `command.exec`. — _Evidence:_ `src/Host/Commands/StatusHostCommand.php`; `candy-code-web/src-web/components/AboutModal.vue:43-51`; `candy-code-web/src-web/components/StatusBar.vue:31`
- why: Both methods are called; `/status` is a server built-in.

### OBS-05 · candy-code
- current: `🟡 Transcripts in SQLite (session show), sub-agent trails on disk; no JSONL files; -p runs leave nothing`
- new: 🟡 Transcripts in SQLite (`session show`, `/export` md/json/html, `-p` runs persist), sub-agent trails on disk; no JSONL files
- detail: The durable per-session event log lives in `session_events` (capped). `-p` runs are saved to a session row and are resumable. `/export` writes raw local files; `/share` writes redacted ones. — _Evidence:_ `src/Cli/NonInteractive.php:295-311,643-651`; `src/Commands/ExportCommand.php:216-218`; `src/Session/EnhancedSessionStore.php:594-640`
- why: AUT-2 made `-p` persist.

### OBS-05 · candy-code-web
- current: `🟡 Inherited (server-side session store/event log); not exposed`
- new: 🟡 Session-row menu downloads a transcript (`session.export`: Markdown, JSON, text); no raw on-disk files
- detail: — _Evidence:_ `candy-code-web/src-web/components/SessionSidebar.vue:26,93-97,187-189`; `candy-code-web/src-web/stores/sessions.ts:113-115`
- why: WEB-1a wired export.

### PA-02 · candy-code
- current: `❌ No heartbeat/proactive check-ins; `/goal` and `/grind` are user-started loops, not scheduled outreach`
- new: 🟡 No heartbeat; `schedules.json` rows and `/loop` re-run a check-in prompt on an interval, but nothing reaches you off-terminal
- detail: Persisted schedule rows fire background sessions whose results announce in the TUI or web. There is no push channel and no built-in maintenance prompt. — _Evidence:_ `src/Background/ScheduleRegistry.php:193,391`; `src/Host/Commands/LoopCommand.php:5-25`
- why: This is parity with Claude Code's 🟡 for self-paced `/loop` and routines. AUT-1 shipped since the cell was written.

### Detail-only changes (cell unchanged)

- **PER-08 · candy-code** (L3734): cell unchanged, detail only. Bash is deliberately not jailed, and path checks are spelling-based: `cat .e?v` and `$var` indirection get through, as the docs admit. The protect-files hook covers both shell subjects, checking every decoded PtyRun step like a Bash command. — _Evidence:_ `src/Hooks/BuiltIn/ProtectFilesHook.php:139-293,426-430,656-668`; `src/Permissions/WritePathScope.php:101`. Why: "PtyRun is not covered at all" is false.
- **PER-12 · candy-code-web** (L3775): cell unchanged, detail only. `bg.spawn` derives the child's mode from the server's configured mode, so it never escapes a server started without `--allow-bypass`, and it relays the child's asks to the approvals drawer. — _Evidence:_ `src/Protocol/Methods/BgMethods.php:93-101,131-132`; `src/Background/BackgroundPermissionRelay.php`. Why: drop `live probe` and the `_2026-10-18 remediated:_` clutter.
- **PER-N1 · candy-code** (L3814): cell unchanged, detail only. Blocks a model from granting itself trust. PtyRun steps fire the same PreToolUse shell hooks, protect-files floor included, and run inside the bash sandbox like Bash. — _Evidence:_ `src/Hooks/BuiltIn/ProtectFilesHook.php:227-293,426-430,656-668`; `src/Permissions/ShellSubjectTools.php:51`. Why: "PtyRun bypasses this floor entirely (ISSUES P1)" is false, plus `_remediated_` clutter.
- **GIT-10 · candy-code** (L3927): cell unchanged, detail only (✅). `/review [ref]` and `/security-review [ref]` submit a canned report-only prompt that closes on an `@diff` mention scoped to the revision, so the change set rides the ordinary mention path; both start a turn. — _Evidence:_ `builtin-commands/2840-review.php`; `builtin-commands/2845-security-review.php`; `src/Commands/ReviewCommand.php`; `src/Chat.php:11799`. Why: the detail glyph ❌ and "docs/COMMANDS.md" evidence are stale.
- **GIT-11 · candy-code** (L3937): cell unchanged, detail only (✅).
  - The composite action `candy-code/action.yml` takes inputs prompt, permission-mode (default `plan`), max-cost (1.00), model and a version pin.
  - It runs `-p --output-format json` and parses the envelope into the step summary.
  - PR posting is left to the caller.
  - `docs/CI.md` carries the CI/container recipe and the fork-PR/injection doctrine.

  — _Evidence:_ `action.yml:41-64,112-118`; `docs/CI.md`. Why: the detail glyph ❌ and "grep github action = 0" are stale.
- **UI-02 · candy-code-web** (L3971): cell unchanged, detail only. Three token sets (auto/dark/light), cycled from the top bar and kept per viewer. The token pairs are WCAG AA-checked by a contrast spec derived from `tokens.css`. The TUI's dracula/tokyoNight/ansi sets are not mirrored. — _Evidence:_ `candy-code-web/src-web/stores/settings.ts:5,23-40`; `candy-code-web/src-web/styles/tokens.css`. Why: "Several token pairs miss WCAG AA (ISSUES)" plus `_remediated_`.
- **UI-05 · candy-code** (L4000): cell unchanged, detail only.
  - Edit/Write results build a unified diff with project-relative headers.
  - It is painted boxed with a DiffGutter, capped at `diffPreviewRows` (default 24), and Ctrl+O expands it.
  - The Edit/Write/ApplyPatch approval modal previews the same diff.
  - There is no side-by-side view, no word-level highlighting and no syntax highlighting.
  - `/diff [n]` prints a checkpoint patch.

  — _Evidence:_ `src/Tools/Concerns/BuildsUnifiedDiff.php:165-168`; `src/Renderer.php:286-294,7259-7261`; `src/Tools/BuiltIn/Edit.php:573`; `src/Tui/DiffGutter.php`. Why: "the permission modal shows no diff" and "absolute path headers" are false.
- **UI-07 · candy-code** (L4020): cell unchanged, detail only. Same text minus "(TUI-3A, 271634138)". Why: SHA and wave tag.
- **UI-11 · candy-code** (L4060): cell unchanged, detail only. Pastes that would bury the composer collapse to numbered, expandable chips over a height-capped input. A paste that is only an image path becomes a mention. Bracketed paste is sanitised (escapes and C0/C1 stripped). — _Evidence:_ `src/Renderer.php:1842`; `src/Chat.php` paste-chip region (UNVERIFIED line). Why: the old detail is the pre-fix bug text plus `_remediated_`.
- **UI-30 · candy-code** (L4250): cell unchanged, detail only.
  - `CANDY_CODE_REDUCED_MOTION` makes every spinner static at once.
  - NO_COLOR and WCAG-projected chrome contrast apply.
  - Mouse can be turned off.
  - Ctrl+F walks a keyboard row cursor over collapsible transcript rows, and Ctrl+O expands the focused one.
  - All strings go through `Lang::t`, but only `en` ships.
  - There is no screen-reader/plain-text mode and no colour-blind palettes.

  — _Evidence:_ `src/Cli/Bootstrap.php:1232-1240`; `src/Commands/KeyBindingRegistry.php:908-914`; `src/Theme.php:237-480`. Why: "the main spinner never animates anyway" is false.
- **UI-31 · candy-code** (L4260): cell unchanged, detail only. The main-turn spinner animates with an elapsed clock, the thinking row carries a paint-time silence gap, and sub-agent live lines animate with tool count, clock and tokens. StallDetector covers background sessions. — _Evidence:_ `src/Tui/AgentActivityLine.php`; `src/Agents/Live/AgentLiveRegistry.php:62`. Why: the old detail is the pre-fix bug text plus `_remediated_`.
- **UI-N1 · candy-code-web** (L4301): cell unchanged, detail only (🟡, not ❌). The token sets are fixed per theme with no background-aware projection like `Theme::pair`, but every text/background pair is checked to WCAG AA by a spec over `tokens.css`. — _Evidence:_ `candy-code-web/src-web/styles/tokens.css`. Why: glyph mismatch.
- **WEB-04 · candy-code** (L4393): cell unchanged, detail only. Host check (DNS rebinding), Origin check, `--allowed-ips`, and trusted-proxy X-Forwarded-For/Proto (Secure cookie only behind a trusted HTTPS proxy). Request bodies carry a read deadline enforced ahead of auth (408). — _Evidence:_ `docs/SERVER.md:1144-1270`; `src/Server/Server.php:507-525`. Why: "slowloris stalls the listener (ISSUES)" plus `_remediated_`.
- **WEB-04 · candy-code-web** (L4394): cell unchanged, detail only. The top bar wraps and compacts at 375 px, and the PWA manifest makes the UI installable. — _Evidence:_ `candy-code-web/src-web/App.vue`; `candy-code-web/public/manifest.webmanifest`. Why: `_remediated_` clutter.
- **WEB-11 · candy-code** (L4463): cell unchanged, detail only.
  - Host allow-list; Origin check on every upgrade and non-GET; Sec-Fetch-Site cross-site refused.
  - Headers: CSP, nosniff, DENY, no-referrer, COOP, CORP.
  - Port-scoped cookie name.
  - Logout ends the cookie session and closes its sockets.
  - The sign-in lockout never falls on the owner token.
  - Token rotation signs everyone out (4001).
  - Cookie sessions persist hash-only across restarts (0600, pruned on boot).

  — _Evidence:_ `src/Server/Http/AuthMiddleware.php:211-227`; `src/Server/Http/ApiController.php:31,133-153`; `src/Server/Auth/RateLimiter.php:14-27`; `src/Server/Auth/CookieSessions.php:16-19`. Why: the "Gaps (ISSUES): …" list is false, plus `_remediated_`.
- **WEB-11 · candy-code-web** (L4464): cell unchanged, detail only. The page never holds the token: a code in the URL fragment becomes a cookie, then a 30 s single-use ticket per socket. — _Evidence:_ `candy-code-web/src-web/protocol/auth.ts`. Why: "Detail in DEEP-DIVE … ISSUES" is not a current-state reference.
- **WEB-14 · candy-code** (L4493): cell unchanged, detail only. `always` is remembered per session with argument-scoped patterns and cascades only to questions the pattern covers. `reject` + `cascade` stops the turn, and `server.askTimeoutSeconds` is optional. Background sessions relay their asks to every client (`permission.asked`, named by bg id), answered the same way with first-answer-wins. — _Evidence:_ `src/Protocol/Methods/PermissionMethods.php:20-42,75-80`. Why: "bg.spawn work never asks at all (P1)" is false.
- **WEB-N2 · candy-code** (L4513): cell unchanged, detail only. A token-authenticated UNIX socket is bound after spawn, and secrets go on stdin, not argv. A host death is announced as `server.overflow {sessionId, action: resubscribe}`, which the web acts on. — _Evidence:_ `src/Server/Workspace/Gateway.php:579-601`; `src/Server/Workspace/WorkspaceHostProcess.php`; `candy-code-web/src-web/stores/connection.ts:131`. Why: "which the web ignores — ISSUES" is false.
- **WEB-N4 · candy-code-web** (L4534): cell unchanged, detail only. The About modal shows `server.info` + `server.health` (uptime, sessions, turns, draining). A `server.shutdown` notification names the reason and grace in the connection banner. There are no stop or log controls: `server.shutdown` is Admin-scope and deliberately not requested, and logs stay CLI-only (`serve logs`). — _Evidence:_ `candy-code-web/src-web/components/AboutModal.vue:7-113`; `candy-code-web/src-web/stores/connection.ts:68,137`. Why: "server.shutdown and server.health are unused" is false.
- **AUT-01 · candy-code** (L4556): cell unchanged, detail only (✅, not 🟡). `NonInteractive::run` builds the engine backend, closes AskUser/PlanExit and prints the final message. `-p` defaults to bypass-permissions, with a stderr notice. `--max-turns` overrides the step ceiling (default 1000). `maxCostUsd`/`CANDY_CODE_MAX_COST` are validated and enforced. Each run is saved to a session row, and `--resume` works. — _Evidence:_ `src/Cli/NonInteractive.php:275,295-311,643-651`; `src/Cli/Bootstrap.php:189-197`. Why: glyph mismatch, a stale "(ISSUES P1)", and `_remediated_`.
- **AUT-02 · candy-code** (L4566): cell unchanged, detail only (✅). `--output-format text|json|stream-json`. stream-json writes one NDJSON `EventEnvelope` per typed event through `StreamJsonEmitter` and ends with the success document. json carries `session_id` and `usage`. Error types usage/provider_configuration/installation/backend/encoding/mcp-config/not-found share the envelope. — _Evidence:_ `src/Cli/NonInteractive.php:70-78,567`; `src/Cli/StreamJsonEmitter.php:48`; `src/Cli/ParsedArgs.php:33`. Why: "stream-json → exit 2" plus `_superseded_`.
- **AUT-03 · candy-code** (L4576): cell unchanged, detail only. Piped stdin is read behind a `stream_select` first-byte watch (2 s). An open, silent pipe counts as no piped context, with a stderr notice. — _Evidence:_ `src/Cli/NonInteractive.php:208,1719-1767`. Why: the old repro text plus `_remediated_`.
- **AUT-04 · candy-code** (L4586): cell unchanged, detail only (✅). `--max-turns` is parsed strictly like `--max-cost` (flag over config) and caps tool steps. `--max-cost`/`maxCostUsd` caps spend on the `-p` path. — _Evidence:_ `src/Cli/ArgvParser.php:535-620`; `src/Cli/Bootstrap.php:3541-3562`; `src/Cli/NonInteractive.php:275`. Why: "--max-turns → unrecognized" plus `_superseded_`.
- **AUT-05 · candy-code** (L4596): cell unchanged, detail only. A composite GitHub Action plus `docs/CI.md`: fork-PR/injection doctrine, HOME, exit codes, JSON contract and serve tokens, and a systemd unit for serve. PR/issue posting is left to the caller. — _Evidence:_ `action.yml`; `docs/CI.md`; `docs/examples/candy-code.service`. Why: the old evidence cites only README prose.
- **AUT-07 · candy-code** (L4616): cell unchanged, detail only (✅).
  - Rows in `~/.candy-code/schedules.json` fire background sessions when due.
  - They are evaluated inside the TUI pump and serve's BackgroundEvents tick, with a per-row 0600 lock against double fires.
  - A busy previous run skips its slot.
  - `/schedules` lists and flips rows.
  - `/loop` adds in-process rows.

  — _Evidence:_ `src/Background/ScheduleRegistry.php:193,391-420`; `src/Host/BackgroundEvents.php:110-130,227`; `src/Chat.php:18520-18566`. Why: "No scheduler code exists" plus `_superseded_`.
- **AUT-08 · candy-code** (L4626): cell unchanged, detail only (✅). `/loop <interval> <prompt>` (seconds, or an s/m/h/d suffix) registers an ephemeral row whose prompt queues as the next turn each interval. The first fire comes one interval out. Rows end with the process or with `/schedules delete`. TUI only; see AUT-08 web. — _Evidence:_ `src/Host/Commands/LoopCommand.php:5-81`; `src/Chat.php:18556-18566`. Why: the glyph ❌ and `_superseded_` are stale.
- **AUT-09 · candy-code-web** (L4637): see the cell change above.
- **AUT-11 · candy-code** (L4656): cell unchanged, detail only (✅). `POST /api/hooks/{name}` on serve: a per-hook secret (constant-time header check), a rate limit and a body cap. The body is spliced into the configured prompt as untrusted input and runs through the same queue/`bg.spawn` door. It is off by default (404 unless configured) and inbound only. — _Evidence:_ `src/Server/Http/ApiController.php:33-49`; `src/Server/Http/HookWebhooks.php:14-47`. Why: the glyph ❌ and `_superseded_`.
- **CFG-03 · candy-code** (L4699): cell unchanged, detail only. Every layer is schema-checked. Unknown keys get did-you-mean suggestions (case/`-`/`_`-folded, Levenshtein ≤2), and wrong types are named at launch. There is no JSON Schema file for editors; the shipped schema is the RPC protocol's. — _Evidence:_ `src/Config/LayeredSettings.php:1155-1185`; `src/Config/Settings/SettingsSchema.php`. Why: the old repro text ("doctor printed No problems") plus `_remediated_`.
- **CFG-06 · candy-code** (L4729): cell unchanged, detail only. `doctor` constructs the configured provider and reports a failure, and opens a real PDO handle to probe ext-sqlite3. There is no network or auth round-trip. — _Evidence:_ `src/Cli/Subcommands.php:298-470` (line range UNVERIFIED after edits). Why: the "Gaps: …" text is false, plus `_remediated_`.

## Narrative fixes

- §1 L55: current "8 of the 66 protocol methods are never requested from the browser (`server.shutdown` still arrives as a notification and is handled)." → new "8 of the 69 protocol methods are never requested from the browser: `files.read`, `memory.log`, `memory.undo`, `session.share`, `session.unshare`, `workflow.status`, `workspace.close`, and the Admin-scope `server.shutdown` (which arrives as a notification and is handled)."
- §1 L56: current "`/theme`, `/keys` and `/sessions` are terminal-only (`/model`, `/compact` and `/init` gained host bodies and now run over the wire)." → new "16 built-ins have no host body and are refused `ui_only` over the wire, `/review`, `/security-review`, `/effort`, `/budget`, `/help` and `/keys` among them (`/theme`, `/settings`, `/sessions` and `/new` have web-native equivalents); the other 41, `/model`, `/compact`, `/init`, `/goal` and `/grind` included, run on the server."
- §1 L57: current "No panel shows loaded instructions, skills, MCP or hooks; only text commands (`/context`, `/mcp`, `/notices`) hint at them." → new "The Context panel (`context.sources`) lists loaded instruction files, memory notes, skills and MCP servers, with a project-trust prompt; hooks show only as `/hooks` text."
- §1 L58: current "No P1 remains: background sessions inherit the server's permission mode and route their asks to the approvals drawer." → new "Background sessions inherit the server's permission mode and route their asks to the approvals drawer; a `/loop` typed in the browser registers but never fires (the served host has no ephemeral-schedule pump)."
- §1 L67 (Sessions & automation, trails cell): current "webhook triggers are serve-only (no outbound); share links need a running `serve` (no hosted endpoint)" → new "webhook triggers are serve-only (no outbound); share links need a running `serve` (no hosted endpoint); `/loop` never fires in a served session"
- §1 L68 (UX, parity cell): current "Status bar, permission modal depth, steering (Enter/Tab/Esc)" → new "Status bar, permission modal depth with diff preview, steering (Enter/Tab/Esc), fuzzy `@` file popup, Ctrl+G history search, rebindable keymap with `/keys reload`"
- §1 L68 (UX, trails cell): current "startup banner is one summary line, not a logo block; history-search UI polish and a richer vim vocabulary remain" → new "the startup summary is four text rows (no logo, effort, account or announcements); no screen-reader mode; vim lacks counts, text objects, `cw` and registers; `statusLine` gets no session JSON"
- §1 L69 (Surfaces, parity cell): current "lossless reconnect (OpenClaw and OpenCode 2.0.31 replay too; `candy-code attach` never reconnects)" → new "lossless reconnect in both clients (OpenClaw and OpenCode 2.0.31 replay too; `candy-code attach` redials with backoff and resumes from its cursor)"
- §1 L69 (Surfaces, trails cell): current "Linux-first, no Windows; Composer-only install" → new "Linux-first, no Windows; installs via Composer, a release PHAR (`self-update`) or the serve Docker image, with no native installer" (PHAR: `.github/workflows/release-phar.yml`, `src/Cli/SelfUpdate.php`; Docker: `candy-code/Dockerfile`, `.github/workflows/candy-code-image.yml`)
- §4.10 L699-701: current "  - **But:**" followed by "    - ✅ `PtyRun` is inside the permission model: …" and "    - ✅ Edit/Write approvals preview the diff …" → new: move L700 and L701 above L699 as plain sibling bullets ("  - `PtyRun` is inside the permission model: its steps are rule/classifier/breaker/grant subjects, render in the modal, drive shell hooks and honour the bwrap sandbox." and "  - Edit/Write/ApplyPatch approvals preview the diff and show full arguments, with `v` to expand."), with no ✅ glyphs, which are history markers in prose.
- §4.10 L702: current "    - Auto mode is a regex classifier (opt-in `autoReview` feeds every non-read-only call to the LLM reviewer)." → new "    - Auto mode is a regex classifier (opt-in `autoReview` sends flagged calls and every unflagged non-read-only shell call to the LLM reviewer; Edit/Write/WebFetch stay classifier-only)."
- §4.10 L703: current "    - `-p` still defaults to bypass (with the notice on stderr), but its spend cap is now enforced." → new "    - `-p` defaults to bypass (with a notice on stderr) and enforces its spend cap; rules live in the user tier only; no TUI workspace-trust dialog; no managed policy."
- §4.10 (insert after L703): new bullet "- **candy-code-web**: server-wide approvals drawer (once / always-for-session / reject / reject & stop, with Edit/Write diff preview, AskUser choice buttons and Markdown plan approval); a read-only Rules panel (mode, rules, session grants); a project `.mcp.json` trust prompt in the Context panel; `remember: \"user\"` is not offered over the wire." (`candy-code-web/src-web/components/PermissionCard.vue:5,28,56-110`; `RulesPanel.vue`; `ContextPanel.vue`; `src/Protocol/Methods/PermissionMethods.php:68-70`)
- §4.11 L718: current "- **candy-code-web**: sessions sidebar with question badges, tab-title counts, opt-in desktop notifications, status bar (step, tokens, spend, mode)." → new "- **candy-code-web**: dashboard (served root, open questions, recent sessions, versions); sessions sidebar with question badges, tab-title counts, opt-in desktop notifications and polite live-region announcements; status bar (step, tokens, spend, mode select, model select, Compact button); About modal over `server.info` + `server.health`."
- §4.12 L726: current "- **candy-code-web**: multi-session cockpit (tabs + live 3×3 grid), server-wide approvals drawer, panels for agents/todo/background/workflows/memory, settings with provenance. Requests 58 of the 66 protocol methods (`server.shutdown` still arrives as a notification and is handled)." → new "- **candy-code-web**: multi-session cockpit (tabs + live 3×3 grid), server-wide approvals drawer, ten side panels (agents, todo, background, workflows, memory, rules, agent catalog, changes, context, generate), settings with provenance. Requests 61 of the 69 protocol methods (`server.shutdown` arrives as a notification and is handled)."
- §6 L772: current "(files.read, workflow.status and workspace.close still have no web caller)" → new "(files.read, memory.log, memory.undo, session.share, session.unshare, workflow.status and workspace.close still have no web caller)"
- §6.6 L829 (append to the Composer bullet): "Still open: vim counts, text objects (the sugar-forms `TextObject` resolver is unwired), `cw`, registers and line-wise `V`; session JSON for `statusLine`; a copy-reply/copy-code command; transcript search; a screen-reader mode."
- §6.7 L835: current "the image/video Generate form on the `canGenerate*` capabilities (`generate.submit`/`status`, `media.fetch`)" → new "the image Generate form (txt2img; `canGenerateVideo` is computed but unread) on `generate.submit`/`status` and `media.fetch`"
- §6.7 L835: current "(`sessions.remove` now called)" → new "(`sessions.remove` wired)"
- §6.7 L838 (insert after "the prompt-history ring,"): "the `@` file popup over the new `fs.search` method (the TUI's MentionIndex ranking), clipboard image paste riding `session.send` `images`,"
- §6.7 (new item 9 before "Ideas", or a trailing paragraph): "9. **Still open:** a browser `/loop` registers but never fires on serve (SessionHost has no ephemeral pump); host bodies for `/review`, `/security-review`, `/effort` and `/budget`; callers for `files.read`, `memory.log`/`undo`, `session.share`/`unshare`, `workflow.status` and `workspace.close`; a help/shortcuts overlay; copy buttons; drag-drop; reverse history search; a global thinking toggle; branch display; and i18n of the chrome (backlog)."
- §6.7 L844: current "OpenClaw's Control UI side panels (Files / Terminal / Review)" → new "OpenClaw's Control UI side panels (Files and Terminal; Review is partly covered by the Changes panel)"

## Gaps (input for the new plan)

Hot-file legend: ⚠HOT marks a serialization-hot file. ⚠PROTO marks the protocol chain (`src/Protocol/**` → `docs/protocol` → `candy-code-web/src-web/protocol/generated.ts`, one change per wave; gates `scripts/gen-protocol-schema.php --check` and `npm run gen:protocol -- --check`). ⚠HEADLESS marks the headless set.

Every web change also needs a rebuild of the committed `candy-code-web/dist/`. New candy-code test files must land in `scripts/parallel-tests-durations.tsv` and refresh `tests/Config/Support/suite-figure.json`.

### GAP-PER-04 · Rule tiers beyond user, and remember-to-config over the wire
- have / missing: Rules exist only in the user tier plus session grants. A project may add only `disabledTools`. `permission.respond` refuses `remember:"project"` (Forbidden) and `remember:"user"` (UnsupportedInServer). Claude Code ✅ has managed > flags > local > project > user, with deny winning.
- approach: (a) Wire `remember:"user"` in `PermissionMethods::respond` through the SettingsWriter path the TUI's `p` (save to config) already uses. No schema change: the enum already lists `user`. (b) Admit project-tier narrowing rules only (deny/ask, never allow) through LayeredSettings' project whitelist, so the self-grant floor holds.
- files: modify `src/Protocol/Methods/PermissionMethods.php:61-70`, `src/Config/LayeredSettings.php:819-860`, `docs/PERMISSIONS.md` ⚠HOT, `docs/SERVER.md` ⚠HOT, `candy-code-web/src-web/components/PermissionCard.vue` (add an "Always (save)" verdict).
- guards: `tests/Config/PermissionsIntroDriftTest.php`, `TrustKeyDocumentationDriftTest.php`.
- effort: M; deps: none.

### GAP-PER-07 · Model-judged review beyond shell
- have / missing: regex SafetyClassifier, plus opt-in ExecReviewer for flagged and unflagged non-read-only shell calls. Claude Code ✅ ships a trained classifier that judges every action.
- approach: generalise `PermissionGate::autoReviewsUnflaggedShell()` into a scope-driven `autoReviewsUnflagged()`, with a new `autoReviewScope: shell|writes|all` key, reusing ExecReviewer's prompt and verdict arms. Owner ruling on making `autoReview` default-on when a title backend exists.
- files: modify `src/Permissions/PermissionGate.php:909-921,998-1003`, `src/Permissions/ExecReviewer.php`, `src/Config/Settings/Definitions/PermissionSettings.php`, `docs/PERMISSIONS.md` ⚠HOT, `docs/SETTINGS.md` (regenerated by `tools/gen-settings-doc.php`) ⚠HOT.
- guards: settings doc regeneration; `tests/Permissions/*`.
- effort: M; deps: owner ruling.

### GAP-PER-09 · Secret redaction of tool output
- have / missing: env scrub, plus SecretRedactor on memory, audit and `/share`. Tool results enter history and `sessions.db` raw (Claude Code is 🟡 too, but persisted secrets matter).
- approach: an opt-in `redactToolOutput` that runs `SecretRedactor::redact()` once at the Runtime tool-result fold, before the history append. It never alters what the tool executed. `/export` stays raw by ruling.
- files: modify `src/Runtime.php` ⚠HOT, a settings definition, `lang/en.php` ⚠HOT, `docs/SETTINGS.md` (generated) ⚠HOT.
- guards: settings doc regeneration.
- effort: M; deps: none.

### GAP-PER-10 · Fence MCP tool results as untrusted
- have / missing: PromptFence frames memory, instructions and env; McpToolBridge returns raw server text. Claude Code and OpenClaw ✅.
- approach: wrap the text parts of `McpToolBridge::call()` in a PromptFence untrusted block (`PromptFence::escape()` plus a provenance tag naming the server and tool), mirroring how McpResourceProvider defers framing to the surface.
- files: modify `src/Tools/McpToolBridge.php:537-560`; reuse `src/Context/PromptFence.php:301`; add a test.
- guards: none in docs. Check the `docs/MCP.md` wording if it claims raw output (UNVERIFIED).
- effort: S; deps: none.

### GAP-PER-11 · Workspace-trust dialog (TUI) and wider trust targets (web)
- have / missing: The `trusted*` user lists are frozen per process. The TUI only announces refusals. The web Context panel prompts only for project `.mcp.json` (`TrustAsks::TARGET_MCP`). Claude Code ✅ shows a launch trust dialog.
- approach: a one-time launch modal when the root has project hooks, MCP, commands or settings and appears in none of the `trusted*` lists. It reuses the TrustAsks record shape and the PermissionPrompt modal. A grant writes the user-tier list and takes effect next launch (frozen-roster law; say so in the modal). Widen the TrustAsks targets (hooks, commands, settings) for the web in the same lane.
- files: create `src/Tui/TrustPrompt.php`; modify `src/Cli/Bootstrap.php` ⚠HOT (launch-notice/trust region ~4567-4623, UNVERIFIED line), `src/Chat.php` ⚠HOT, `src/Renderer.php` ⚠HOT, `lang/en.php` ⚠HOT, `src/Protocol/TrustAsks.php:28` ⚠PROTO (target enum), `src/Protocol/Methods/ContextMethods.php:80-90` ⚠PROTO, `docs/PERMISSIONS.md` ⚠HOT, `candy-code-web/src-web/components/panels/ContextPanel.vue`.
- guards: TrustKeyDocumentationDriftTest; KeyBindingDriftTest if the modal adds rows; protocol drift gates.
- effort: L; deps: one protocol wave.

### GAP-PER-13 · Managed policy layer
- have / missing: nothing. Claude Code ✅ has managed settings with lock keys.
- approach: read root-owned `/etc/candy-code/managed-settings.json` (an ownership/mode check like `HomeDirectory::owned`) as the top LayeredSettings tier. Managed keys cannot be overridden; add `allowManagedRulesOnly` and a doctor row.
- files: modify `src/Config/LayeredSettings.php`, `src/Config/Settings/*`, `src/Cli/Subcommands.php` ⚠HEADLESS, `docs/SETTINGS.md` ⚠HOT, `docs/PERMISSIONS.md` ⚠HOT.
- guards: settings doc regeneration; DocFigureProseDriftTest (tier counts).
- effort: L; deps: owner ruling.

### GAP-PER-14 · Permission audit view in the web
- have / missing: AuditHook writes a redacted, rotated log on disk; the web has no audit view. Claude Code is 🟡 (recently denied list).
- approach: a new `permission.audit` method (Scope::Read) tailing the AuditHook log, bounded and already redacted, rendered as a "Recent decisions" section of the Rules panel.
- files: modify `src/Protocol/Methods/PermissionMethods.php` ⚠PROTO, `src/Protocol/Schema/MethodSchemas.php` ⚠PROTO, `src/Hooks/BuiltIn/AuditHook.php` (reader), `candy-code-web/src-web/components/panels/RulesPanel.vue`.
- guards: protocol drift gates; ServerProtocolDocumentationDriftTest.
- effort: M; deps: a protocol wave.

### GAP-PER-N2 · `--restricted` / `--safe-mode`
- have / missing: plan and dont-ask modes, and user-tier `disabledTools`. Claude Code ✅ has both flags.
- approach: `--restricted` disables `ShellSubjectTools::NAMES` plus the other code-running tools at launch and confines file tools. `--safe-mode` ignores the `trusted*` lists and foreign skills, agents and commands for the process.
- files: modify `src/Cli/ArgvParser.php` ⚠HEADLESS, `src/Cli/ParsedArgs.php` ⚠HEADLESS, `src/Cli/Bootstrap.php` ⚠HOT (tools build), `src/Cli/Help.php`, `README.md` ⚠HOT, `docs/PERMISSIONS.md` ⚠HOT.
- guards: ReadmeRosterDriftTest; the ArgvParser const-mirror test.
- effort: M; deps: none.

### GAP-GIT-01 · Branch in the web
- have / missing: the TUI tags sessions with a branch; SessionSummary carries none. Claude Code ✅ shows a footer branch/PR badge.
- approach: add an optional `branch` to SessionSummary from the session row, and render it in StatusBar and the sidebar row.
- files: modify `src/Protocol/Schema/Definitions.php` ⚠PROTO, the summary producer in `src/Protocol/Methods/SessionMethods.php` ⚠PROTO, `candy-code-web/src-web/components/StatusBar.vue` ⚠HOT, `candy-code-web/src-web/components/SessionSidebar.vue` ⚠HOT.
- guards: protocol drift gates.
- effort: S; deps: a protocol wave.

### GAP-GIT-03 · `/commit`
- have / missing: CommitMessageWriter (title model, Conventional Commits) and AutoCommitter's hardened GitRunner exist; there is no `/commit`. Claude Code ✅ commits on request.
- approach: a builtin `/commit [message]` with a dual host body. It commits the user's changes through AutoCommitter's single-commit path and drafts the subject with CommitMessageWriter when no message is given. The host body makes it `runsIn: server`, so the web gets it for free.
- files: create `builtin-commands/2850-commit.php`, `src/Host/Commands/CommitHostCommand.php`; modify `src/Workspace/AutoCommitter.php` (public entry), `src/Chat.php` ⚠HOT (handler), `lang/en.php` ⚠HOT, `docs/COMMANDS.md` (`tools/gen-command-docs.php`) ⚠HOT, `README.md` ⚠HOT roster.
- guards: CommandDocsDriftTest, ReadmeRosterDriftTest, BuiltInCommandsTest, suite figure.
- effort: M; deps: none.

### GAP-GIT-04 · PR creation
- have / missing: Bash guidance plus `attribution.pr`. Claude Code ✅ has gh-driven PRs and `--from-pr`.
- approach: a canned `/pr` prompt shaped like `/review` (a ReviewCommand-style const) instructing `gh pr create` with attribution. `--from-pr` resume is a later M.
- files: create `builtin-commands/2860-pr.php`, `src/Commands/PrCommand.php`, `src/Host/Commands/PrHostCommand.php`; modify `lang/en.php` ⚠HOT, `docs/COMMANDS.md` ⚠HOT, `README.md` ⚠HOT.
- guards: CommandDocsDriftTest, ReadmeRosterDriftTest.
- effort: S; deps: owner gate on gh shell-out (plan R13).

### GAP-GIT-06 · Rewind/undo affordances in the web
- have / missing: `/undo`, `/redo` and `/rewind` work only as typed text. Claude Code ✅ restores code from the UI.
- approach: client-only. A "Rewind to here" action on user rows sends `command.exec /rewind n`; the existing snapshot refresh (`subscribe(true)`) repaints.
- files: modify `candy-code-web/src-web/components/TranscriptItem.vue`, `candy-code-web/src-web/stores/session.ts` ⚠HOT.
- guards: vitest; dist rebuild.
- effort: S; deps: none.

### GAP-GIT-08 · Main-session worktree
- have / missing: worktrees exist only for sub-agent presets. Claude Code ✅ has `--worktree` and Enter/ExitWorktree.
- approach: a `--worktree [name]` launch flag reusing `src/Agents/WorktreeManager.php` to create and enter a worktree before Bootstrap opens the session, removing it on exit when unused.
- files: modify `src/Cli/ArgvParser.php` ⚠HEADLESS, `src/Cli/ParsedArgs.php` ⚠HEADLESS, `src/Cli/Bootstrap.php` ⚠HOT, `src/Agents/WorktreeManager.php`, `README.md` ⚠HOT.
- guards: ReadmeRosterDriftTest; the ArgvParser mirror test.
- effort: M; deps: none.

### GAP-GIT-09 · Review pane / per-turn diff
- have / missing: TUI `/diff [n]` prints fenced text; the web Changes panel shows the whole workspace diff. Claude Code ✅ has a diff panel with per-file stats, per-turn views and base cycling.
- approach:
  - TUI: a diff-review overlay over CheckpointDiff: file list with +/− counts from sugar-diff Hunks, ←/→ to cycle the checkpoint base, Enter to show a file.
  - Web: a `checkpoint` parameter on `files.diff` (CheckpointDiff) and a base selector in ChangesPanel.
- files: create `src/Tui/DiffReview.php`; modify `src/Host/Commands/DiffCommand.php`, `src/Workspace/CheckpointDiff.php`, `src/Chat.php` ⚠HOT, `src/Renderer.php` ⚠HOT, `src/Commands/KeyBindingRegistry.php` ⚠HOT, `src/Protocol/Methods/FilesMethods.php` ⚠PROTO, `candy-code-web/src-web/components/panels/ChangesPanel.vue`.
- guards: KeyBindingDriftTest, README Keys, protocol drift gates.
- effort: L; deps: a protocol wave.

### GAP-GIT-10 · `/review` and `/security-review` over the wire
- have / missing: both are Chat-handled only and refused `ui_only` in the web. Claude Code ✅.
- approach: a ReviewHostCommand returning `CommandEffect::submitPrompt(ReviewCommand::prompt($ref, $security))`, exactly InitHostCommand's shape (`src/Host/Commands/InitHostCommand.php:29`), plus `->withHostCommand()` on both specs. Admission is by construction (`runsIn: server`). Verify that SessionHost's send path resolves `@diff` the way ContextMentions does in the TUI.
- files: create `src/Host/Commands/ReviewHostCommand.php`; modify `builtin-commands/2840-review.php`, `builtin-commands/2845-security-review.php`, and `docs/COMMANDS.md` if it shows where a command runs.
- guards: the `tests/Host/WebTwoHostCommandsTest.php` pattern; BuiltInCommandsTest.
- effort: S; deps: none.

### GAP-GIT-12 · PR-comment / issue-triage bot
- have / missing: action.yml only. Claude Code ✅ has `@claude` in comments.
- approach: an `issue_comment`/`pull_request_review_comment` example workflow that calls the existing action in `plan` mode and posts with `gh`, with the fork-PR/injection doctrine in `docs/CI.md`.
- files: modify `action.yml` (optional comment-post input), `docs/CI.md`. Ship the example under `docs/examples/`, not `.github/workflows/`.
- guards: none.
- effort: M; deps: owner security ruling.

### GAP-UI-02 · Custom themes (TUI) and the theme set (web)
- have / missing: six fixed TUI themes (`Theme::byName` throws for anything else); the web has auto/dark/light. Claude Code and OpenCode ✅ load custom JSON themes.
- approach: user `~/.candy-code/themes/*.json` mapping StyleTheme tokens, resolved after the built-ins and still run through `Theme::pair` contrast projection, validated with did-you-mean. The web mirrors dracula and tokyoNight as `tokens.css` sets.
- files: create `src/Config/ThemeFile.php`; modify `src/Theme.php:60,158-214`, the theme setting definition, `docs/SETTINGS.md` ⚠HOT, `candy-code-web/src-web/styles/tokens.css`, `candy-code-web/src-web/stores/settings.ts`.
- guards: settings doc regeneration; the web contrast spec (`styles/__tests__`).
- effort: M; deps: none.

### GAP-UI-04 · Syntax-highlighting breadth
- have / missing: RegexHighlighter with 7 keyword sets; diffs and tool output are not highlighted. Claude Code and OpenCode ✅.
- approach: widen the sugar-core rule tables (rust, java, c/cpp, ruby, yaml, html/css), and highlight diff bodies by file extension in DiffGutter.
- files: modify `sugar-core/src/Syntax/RegexHighlighter.php:42-96`, `sugar-markdown/src/SyntaxHighlighter.php`, `src/Tui/DiffGutter.php`.
- guards: sugar-core and sugar-markdown lib suites.
- effort: M; deps: none.

### GAP-UI-05 · Side-by-side diffs
- have / missing: unified only, in both clients. OpenCode ✅ auto-splits by width.
- approach: a SideBySideFormat writer in sugar-diff over Hunk/Line (per-side line numbers already exist), selected in DiffGutter when wide, with a `diffStyle: auto|unified|split` setting. The web adds a DiffView split mode, client-only.
- files: create `sugar-diff/src/Writer/SideBySideFormat.php`; modify `src/Tui/DiffGutter.php`, `src/Renderer.php` ⚠HOT (~286-297), a settings definition, `candy-code-web/src-web/components/DiffView.vue`, `candy-code-web/src-web/lib/diff.ts`.
- guards: sugar-diff lib tests; settings doc regeneration.
- effort: M; deps: none.

### GAP-UI-07 · vim vocabulary (text objects, `c`, counts)
- have / missing: ComposerVim handles d/y pending operators, p/P, and visual span operators. Claude Code ✅ has text objects, `.` repeat and remaps. The sugar-forms text-object machinery exists but is unwired (see Dormant code).
- approach: extend ComposerVim's pending grammar:
  - after `d`/`y`/`c`, an `i`/`a` arms a text object resolved by `TextObject::resolve(buffer, cursor, scope, target)`, feeding the existing kill/yank;
  - `c` = delete, then Insert (as `o` does);
  - a numeric-prefix accumulator multiplies motion delegation.

  Vim keys stay out of KeyBindingRegistry. Line-wise `V` waits on the R4 ruling.
- files: modify `src/Tui/ComposerVim.php:42-90`, `src/Chat/Handlers/HandlesKeys.php:320` region, `sugar-forms/src/TextArea/TextArea.php` (range-delete primitive if missing), `README.md` ⚠HOT, `docs/SETTINGS.md` (vimMode help) ⚠HOT.
- guards: ComposerVim tests; sugar-forms lib suite.
- effort: M; deps: none.

### GAP-UI-09 · Reverse history search in the web
- have / missing: a ↑/↓ ring only. Claude Code ✅ has Ctrl+R.
- approach: a client-only overlay on Ctrl+G (browsers reserve Ctrl+R) filtering `promptHistory` by containment, newest first, mirroring HistorySearch's Enter and Esc semantics.
- files: modify `candy-code-web/src-web/components/Composer.vue`, `candy-code-web/src-web/stores/promptHistory.ts`.
- guards: vitest; dist rebuild.
- effort: S; deps: none.

### GAP-UI-11 · Large-paste collapse in the web
- have / missing: none. Claude Code, OpenCode and the TUI ✅.
- approach: client-only. A paste over N lines becomes a "[Pasted #n +X lines]" chip expanded into the sent text, mirroring the TUI chips.
- files: modify `candy-code-web/src-web/components/Composer.vue`, `candy-code-web/src-web/lib/paste.ts`.
- guards: vitest.
- effort: S; deps: none.

### GAP-UI-12 · Drag-drop images in the web
- have / missing: clipboard paste only. Claude Code ✅.
- approach: `@dragover`/`@drop` on the composer feeding `readPastedImages` (DataTransfer already satisfies `ClipboardFiles`, per `lib/paste.ts`).
- files: modify `candy-code-web/src-web/components/Composer.vue`.
- guards: vitest.
- effort: S; deps: none.

### GAP-UI-15 · Pick-and-edit an earlier message
- have / missing: `/undo` restores the last prompt, and `/rewind n` exists. Claude Code ✅ has a message picker that seeds the input.
- approach:
  - TUI: a MessagePicker overlay (HistorySearch/SessionPicker shape) over user turns; Enter runs RewindCommand `--chat` to that turn and seeds the draft.
  - Web: "Edit & resend" on user rows, sending `command.exec /rewind n --chat` and then filling the composer.
- files: create `src/Tui/MessagePicker.php`; modify `src/Chat.php` ⚠HOT, `src/Renderer.php` ⚠HOT, `src/Commands/KeyBindingRegistry.php` ⚠HOT (a chord ruling, since Esc Esc is `chat.cancel`), `candy-code-web/src-web/components/TranscriptItem.vue`, `candy-code-web/src-web/stores/session.ts` ⚠HOT.
- guards: KeyBindingDriftTest, README Keys.
- effort: M; deps: chord ruling.

### GAP-UI-16 · Copy reply / code block
- have / missing: mouse drag-select in the TUI; native selection in the web. Claude Code and OpenCode ✅ have `/copy` and copy keys.
- approach: a TUI `/copy [n]` (last reply, or the nth fenced block) through SystemClipboard (OSC 52, tmux, pbcopy, wl-copy). The web adds copy buttons on code blocks and assistant rows via `navigator.clipboard`, guarded for secure context like notifications.
- files: create `builtin-commands/1910-copy.php`, `src/Commands/CopyCommand.php`; modify `src/Support/SystemClipboard.php`, `src/Chat.php` ⚠HOT, `lang/en.php` ⚠HOT, `docs/COMMANDS.md` ⚠HOT, `README.md` ⚠HOT, `candy-code-web/src-web/components/MessageMarkdown.vue`, `candy-code-web/src-web/components/TranscriptItem.vue`.
- guards: CommandDocsDriftTest, ReadmeRosterDriftTest.
- effort: M; deps: none.

### GAP-UI-22 · Global thinking toggle in the web
- have / missing: per-fold toggles only. Claude Code and OpenCode ✅.
- approach: a client setting in `stores/settings.ts` driving ReasoningFold's default open/closed state, with a top-bar toggle.
- files: modify `candy-code-web/src-web/stores/settings.ts`, `candy-code-web/src-web/components/ReasoningFold.vue`, `candy-code-web/src-web/App.vue`.
- guards: vitest.
- effort: S; deps: none.

### GAP-UI-23 · `statusLine` session JSON
- have / missing: a forked child runs the command with stdin closed. Claude Code ✅ passes JSON (model, cost, context %, cache, git, session).
- approach: build a JSON snapshot in Chat at fork time (model, provider, context tokens and %, spend, mode, cwd, branch, session id, version) from the facts the status bar already renders. Write it to `$pipes[0]` before `fclose` in `StatusLineCommand::run()`, keeping the deadline, and document the schema.
- files: modify `src/Config/StatusLineCommand.php:590-623`, `src/Chat.php` ⚠HOT (fork site), `docs/SETTINGS.md` (statusLine help) ⚠HOT, `README.md` ⚠HOT.
- guards: settings doc regeneration.
- effort: M; deps: none.

### GAP-UI-25 · Keymap customisation in the web
- have / missing: fixed web shortcuts. Claude Code and OpenCode ✅.
- approach: a client-only map in `stores/settings.ts` for the handful of web shortcuts (palette, approvals, tab chord), with a small editor in the settings view's client section.
- files: modify `candy-code-web/src-web/stores/settings.ts`, `candy-code-web/src-web/lib/tabChord.ts`, `candy-code-web/src-web/App.vue`.
- guards: vitest.
- effort: S; deps: GAP-UI-28 (the overlay lists the shortcuts).

### GAP-UI-28 · Help / shortcuts overlay in the web
- have / missing: none. Claude Code and OpenCode ✅.
- approach: `?` on an empty composer opens a modal listing the web shortcuts plus `command.list` rows, with `runsIn` markers.
- files: create `candy-code-web/src-web/components/HelpOverlay.vue`; modify `candy-code-web/src-web/App.vue`, `candy-code-web/src-web/components/Composer.vue`.
- guards: vitest; dist rebuild.
- effort: S; deps: none.

### GAP-UI-29 · First-run setup
- have / missing: the echo backend plus a notice; `candy-code login [provider]` exists. Claude Code and OpenCode ✅ have a provider wizard.
- approach: a first-run overlay when no provider is configured. It picks from ProviderFactory's list, takes a key or runs the login flow, and saves to the user tier via SettingsWriter. The web Dashboard shows a notice when the server reports no provider.
- files: create `src/Tui/FirstRunWizard.php`; modify `src/Cli/Bootstrap.php` ⚠HOT, `src/Chat.php` ⚠HOT, `src/Renderer.php` ⚠HOT, `lang/en.php` ⚠HOT, `candy-code-web/src-web/views/DashboardView.vue`.
- guards: KeyBindingDriftTest if the overlay adds rows.
- effort: L; deps: none.

### GAP-UI-30 · Screen-reader mode
- have / missing: reduced motion, contrast and a row cursor. Claude Code ✅ has a screen-reader mode.
- approach: a `screenReader` setting that selects a plain-text Renderer branch: no box drawing, numbered menus, static spinners via `AgentLiveRegistry::setStaticSpinner`, and bell cues.
- files: modify `src/Renderer.php` ⚠HOT, a settings definition, `docs/SETTINGS.md` ⚠HOT.
- guards: settings doc regeneration.
- effort: L; deps: none.

### GAP-UI-31 · Elapsed timer in the web
- have / missing: step n/m only. Claude Code and the TUI ✅.
- approach: client-only. A ticking `working · 0:42` from the `turn.started` timestamp.
- files: modify `candy-code-web/src-web/components/StatusBar.vue` ⚠HOT, `candy-code-web/src-web/stores/reducer.ts`.
- guards: vitest.
- effort: S; deps: none.

### GAP-UI-32 · One-key compact/verbose toggle
- have / missing: the live `expandToolOutput` setting and a per-row Ctrl+O. Claude Code ✅.
- approach: a new `chat.verbose-toggle` binding that flips `expandToolOutput` live. The web gets an "expand all tool cards" toggle.
- files: modify `src/Commands/KeyBindingRegistry.php` ⚠HOT, `src/Chat/Handlers/HandlesKeys.php`, `lang/en.php` ⚠HOT, `README.md` ⚠HOT (Keys), `candy-code-web/src-web/components/Transcript.vue`.
- guards: KeyBindingDriftTest.
- effort: S; deps: none.

### GAP-UI-34 · Prompt suggestions in the web
- have / missing: the TUI-only TitleService suggestion. Claude Code ✅.
- approach: SessionHost requests the TitleService suggestion after a settled turn, as Chat does, and emits a new ephemeral `prompt.suggested` event. The Composer shows it as a grey placeholder that → accepts.
- files: modify `src/Host/SessionHost.php`, `src/Host/TitleService.php`, `src/Protocol/EventType.php` ⚠PROTO, `src/Protocol/Schema/EventSchemas.php` ⚠PROTO, `candy-code-web/src-web/components/Composer.vue`.
- guards: protocol drift gates.
- effort: M; deps: a protocol wave.

### GAP-UI-N4 · Inactivity recap
- have / missing: none. Claude Code ✅ (one-line recap after ≥3 min unfocused).
- approach: Chat tracks FocusMsg and last activity; past the threshold with ≥3 turns, a TitleService recap call lands a uiOnly notice. `/recap` on demand gets a host body so the web has it too.
- files: create `builtin-commands/3510-recap.php`, `src/Host/Commands/RecapHostCommand.php`; modify `src/Chat.php` ⚠HOT, `src/Host/TitleService.php`, `lang/en.php` ⚠HOT, `docs/COMMANDS.md` ⚠HOT.
- guards: CommandDocsDriftTest, ReadmeRosterDriftTest.
- effort: M; deps: none.

### GAP-UI-N5 · Strip invisible Unicode before sending
- have / missing: markers on display only. Claude Code ✅ strips.
- approach: one server-side choke shared by both clients. Strip the `markInvisibleFormatting` set (U+200B, bidi, U+2060, U+FEFF) at Chat submit and in `TurnMethods` `session.send`, emitting a notice, with a setting to keep them.
- files: modify `src/Chat.php` ⚠HOT (submit), `src/Protocol/Methods/TurnMethods.php:56-67`, and the sugar-core `Sanitize` lib.
- guards: sugar-core suite.
- effort: S; deps: none.

### GAP-UI-N6 · Cross-session attention in the TUI
- have / missing: notify covers the current session only. Claude Code ✅ (agent view counts and notifies).
- approach: badges on the session tab strip from background and other sessions' waiting state (BackgroundEvents), plus an OSC 2 window-title count.
- files: modify `src/Tui/SessionTabs.php`, `src/Renderer.php` ⚠HOT, `src/Chat.php` ⚠HOT.
- guards: none.
- effort: M; deps: none.

### GAP-WEB-04 · Built-in TLS / pairing
- have / missing: SSH tunnel or proxy only; `--allow-remote` is cleartext. Claude Code and OpenCode 2 ✅ (Remote Control, pairing/QR).
- approach: `--tls-cert/--tls-key` on serve (ReactPHP SecureServer), plus `serve url --qr` rendering a LoginCodes one-time link.
- files: modify `src/Cli/Serve.php`, `src/Server/Listener.php`, `src/Server/ServerConfig.php`, `docs/SERVER.md` ⚠HOT, `src/Cli/Help.php`, `README.md` ⚠HOT.
- guards: ReadmeRosterDriftTest; EnvRosterDriftTest if env vars are added.
- effort: L; deps: owner security ruling (R13).

### GAP-WEB-05 · Desktop app
- have / missing: none. Claude Code and OpenCode ✅.
- approach: a Tauri/Electron shell over the candy-code-web bundle with a `serve --parent-pid` sidecar (ParentPidWatchdog exists).
- files: create a new `candy-code-desktop/` app.
- guards: n/a.
- effort: L; deps: owner.

### GAP-WEB-06 · VS Code extension
- have / missing: ACP only. Claude Code and OpenCode ✅.
- approach: a thin extension launching `candy-code serve --parent-pid` and hosting the web bundle in a webview; diff hand-off is a later step.
- files: create a new extension package.
- guards: n/a.
- effort: L; deps: owner.

### GAP-WEB-07 · JetBrains via ACP, unexercised
- have / missing: documented but never run. Claude Code ✅ has a plugin.
- approach: verify ACP live against a JetBrains AI Assistant/ACP host and record the result in `docs/SERVER.md`.
- files: modify `docs/SERVER.md` ⚠HOT.
- guards: none.
- effort: S; deps: none.

### GAP-WEB-09 · Chat channels
- have / missing: none. Claude Code and OpenClaw ✅.
- approach: a separate daemon per channel over serve's RPC (`bg.spawn`, `session.send`, `permission.respond`) using the existing token store (plan WEB-8).
- files: create a new app outside candy-code.
- guards: n/a.
- effort: L; deps: owner.

### GAP-WEB-10 · Mobile
- have / missing: an installable PWA. Claude Code and OpenClaw ✅.
- approach: Web Push for questions (service worker plus VAPID) behind a consent gate, then a pairing flow (GAP-WEB-04).
- files: create `candy-code-web/src-web/sw.ts`; modify `candy-code-web/public/manifest.webmanifest`, plus a server push store in `src/Server/`.
- guards: CSP review (`StaticFiles`).
- effort: L; deps: GAP-WEB-04.

### GAP-WEB-12 · Cloud execution
- have / missing: the Docker image for self-hosting. Claude Code ✅.
- approach: out of scope; document the Docker serve path as the answer.
- files: `docs/SERVER.md` ⚠HOT.
- guards: none.
- effort: L; deps: owner.

### GAP-WEB-13 · Voice
- have / missing: none. Claude Code 🟡, OpenClaw ✅.
- approach: browser Web Speech dictation button in the Composer, client-only.
- files: modify `candy-code-web/src-web/components/Composer.vue`.
- guards: vitest.
- effort: S; deps: none.

### GAP-WEB-N4 · Server status/stop/logs in the browser
- have / missing: the About modal. Claude Code and OpenCode 2 ✅ (CLI service control).
- approach: a `server.logs` tail method (Scope::Admin) and a confirmed "Stop server" calling `server.shutdown` when the client holds Admin scope. This flips the deliberate-dormant negative-control test, so it needs an owner call.
- files: modify `src/Protocol/Methods/ServerMethods.php` ⚠PROTO, `src/Server/ErrorLog.php`, `candy-code-web/src-web/components/AboutModal.vue`, `candy-code-web/src-web/__tests__/shutdownDormant.spec.ts`.
- guards: protocol drift gates.
- effort: M; deps: owner ruling.

### GAP-WEB-N5 · `attach` slash commands and foreign turns
- have / missing: slash commands run locally, and another client's turn shows only on re-attach. OpenCode and OpenClaw ✅.
- approach: RemoteSessionHost routes `/name` through `command.exec` when `command.list` says `runsIn: server`, and follows `turn.started`/`assistant.delta` from other clients on its subscription.
- files: modify `src/Host/RemoteSessionHost.php`, `src/Chat.php` ⚠HOT (dispatch when the backend is remote), `docs/SERVER.md:1011-1014` ⚠HOT.
- guards: ServerProtocolDocumentationDriftTest.
- effort: M; deps: none.

### GAP-AUT-05 · PR bot
- see GAP-GIT-12 (same lane).

### GAP-AUT-06 · SDK
- have / missing: the schema plus an unpublished typed TS client. Claude Code and OpenCode ✅.
- approach: publish `generated.ts` + `client.ts` + `jsonrpc.ts` + `reconnect.ts` as an npm package (they have no Vue deps), and add a PHP client facade over RemoteSessionHost.
- files: create `candy-code-web/packages/client/` (or a split package); modify `candy-code-web/package.json`, `.github/workflows/*` ⚠HOT.
- guards: `npm run gen:protocol -- --check`.
- effort: M; deps: owner on package naming.

### GAP-AUT-07 · Schedules panel in the web
- have / missing: `/schedules` as text. Claude Code ✅ (routines UI).
- approach: render `/schedules` CommandResult rows as a table in a Background-panel subsection, client-only; a `schedules.list` method later if needed.
- files: modify `candy-code-web/src-web/components/panels/BackgroundPanel.vue`.
- guards: vitest.
- effort: S; deps: none.

### GAP-AUT-08 · `/loop` never fires in served sessions (live defect)
- have / missing: an ephemeral row is registered, but SessionHost has no pump. OpenClaw and Claude Code ✅.
- approach: stamp the ephemeral Schedule with its owning `sessionId` (LoopCommand gets it from CommandContext). SessionHost (or the BackgroundEvents tick) queues due ephemeral rows for that session through the `session.queue` door, mirroring `Chat.php:18556-18566`. Rows die with the session or host. Add a regression test that a served `/loop` fires.
- files: modify `src/Background/Schedule.php` (sessionId), `src/Background/ScheduleRegistry.php:193-205,391-397` (a `dueEphemeralFor(sessionId)` variant), `src/Host/Commands/LoopCommand.php:63-81`, `src/Host/SessionHost.php` (pump on idle/timer), `src/Host/BackgroundEvents.php`.
- guards: suite figure and durations TSV for new tests.
- effort: M; deps: none (front-load: it is a live silent failure).

### GAP-CFG-05 · Hot reload of config files
- have / missing: next-turn re-reads for some keys and `/keys reload`; the rest restart. Claude Code and OpenClaw ✅ watch files.
- approach: a per-turn digest-compare of the settings files (the MCP and `/keys reload` precedent) plus an explicit `/settings reload`. Move restart-roster keys that are safe to next-turn.
- files: modify `src/Config/LayeredSettings.php`, `src/Chat.php` ⚠HOT, `src/Cli/Bootstrap.php` ⚠HOT, `docs/SETTINGS.md` ⚠HOT.
- guards: settings doc regeneration (apply-mode table).
- effort: M; deps: none.

### GAP-CFG-06 · Doctor over the wire
- have / missing: the About modal only. Claude Code ✅ has `/doctor`.
- approach: a `/doctor` host command returning TranscriptTable rows from the same checks `Subcommands` doctor runs. That needs no protocol change, and the web gets it via `command.exec`.
- files: create `builtin-commands/1345-doctor.php`, `src/Host/Commands/DoctorHostCommand.php`; modify `src/Cli/Subcommands.php` ⚠HEADLESS (extract a pure check list), `docs/COMMANDS.md` ⚠HOT.
- guards: CommandDocsDriftTest.
- effort: M; deps: none.

### GAP-CFG-08 · Settings/permission import
- have / missing: MCP and memory import, plus live foreign reads. Claude Code and OpenClaw ✅ have migrate/import.
- approach: `candy-code import claude`, which previews and then writes `.claude/settings.json` `permissions.allow/deny` (translated with the existing `(prefix:*)` mapping), model and env into the user tier.
- files: create `src/Config/ClaudeSettingsImport.php`; modify `src/Cli/Subcommands.php` ⚠HEADLESS, `src/Cli/Help.php`, `README.md` ⚠HOT.
- guards: ReadmeRosterDriftTest.
- effort: M; deps: none.

### GAP-OBS-01 · Log view in the web
- have / missing: server log only. OpenClaw ✅ shows logs in the Control UI.
- approach: shares the `server.logs` method with GAP-WEB-N4, rendered as an About-modal tab.
- files: as GAP-WEB-N4.
- guards: protocol drift gates.
- effort: M; deps: GAP-WEB-N4.

### GAP-OBS-02 · OTel on serve and `-p`
- have / missing: the sink exists only in `Bootstrap::chat()`. Claude Code, OpenCode and OpenClaw ✅.
- approach: thread `Bootstrap::observabilitySink()` into SessionHost's TurnRunner fold, and into NonInteractive's event observer (the one StreamJsonEmitter already rides).
- files: modify `src/Cli/Bootstrap.php:1160-1170,1257` ⚠HOT, `src/Host/TurnRunner.php`, `src/Host/SessionHost.php`, `src/Cli/NonInteractive.php` ⚠HEADLESS, `docs/ENVIRONMENT.md` ⚠HOT, `tests/Observability/ObservabilityWiringTest.php`.
- guards: EnvRosterDriftTest.
- effort: M; deps: owner (plan marks serve telemetry owner-gated).

### GAP-OBS-03 · Usage page in the web
- have / missing: `/usage` as text. Claude Code ✅.
- approach: client-only. Render the `/usage` CommandResult rows as a table in a panel.
- files: create `candy-code-web/src-web/components/panels/UsagePanel.vue`; modify `candy-code-web/src-web/components/panels/SidePanels.vue` ⚠HOT, `candy-code-web/src-web/stores/agents/panels.ts`.
- guards: vitest.
- effort: S; deps: none.

### GAP-OBS-05 · JSONL transcripts on disk
- have / missing: SQLite plus `/export` md/json/html. Claude Code ✅ (JSONL plus `transcript_path` to hooks).
- approach: a `jsonl` export format in ShareSession raw serialisation, plus an optional `transcriptJsonl` setting that appends per message from `saveTranscript()`'s per-message diff; pass the path in the hook payload.
- files: modify `src/Share/ShareSession.php:35-45,175`, `src/Commands/ExportCommand.php:216-218`, `src/Session/EnhancedSessionStore.php`, the hook payload in `src/Hooks/ScriptHook.php`, `docs/HOOKS.md` (UNVERIFIED path).
- guards: ConfigWriteProducerDocumentationDriftTest if a setting is added.
- effort: M; deps: none.

### GAP-OBS-06 · Insights across sessions
- have / missing: `/usage` rollups. Claude Code ✅ (`/insights`).
- approach: an `/insights` HTML report over `usage_records` plus session FTS (SES-2), rendered through ShareSession's HTML renderer.
- files: create `src/Commands/InsightsCommand.php`, `builtin-commands/2215-insights.php`; modify `docs/COMMANDS.md` ⚠HOT.
- guards: CommandDocsDriftTest.
- effort: L; deps: none.

### GAP-PA-01 · Always-on channel gateway
- see GAP-WEB-09 (serve `--detach` and systemd are the daemon half). effort: L.

### GAP-PA-02 · Heartbeat
- have / missing: schedules and `/loop`, with nothing off-terminal. OpenClaw ✅.
- approach: a `heartbeat` schedule kind that runs a maintenance prompt and notifies through `notify` (OSC 9) and web push (GAP-WEB-10) only when the result is non-empty.
- files: modify `src/Background/Schedule.php`, `src/Host/BackgroundEvents.php`, `src/Tui/TerminalNotifier.php`.
- guards: none.
- effort: M; deps: GAP-WEB-10 for push.

### GAP-PA-03 · Multi-channel inbox
- see GAP-WEB-09. effort: L.

### GAP-PA-04 · Persona files
- have / missing: personal `~/.candy-code/AGENTS.md`. OpenClaw ✅ (SOUL, IDENTITY, USER).
- approach: add `SOUL.md`/`USER.md` as personal-tier aliases in the instruction loader's FILENAMES pattern (INT-1 precedent), fenced like the others.
- files: modify the instruction loader in `src/Context/` (INS overlap; coordinate with the INS auditor), `docs/MEMORY.md`.
- guards: MemoryDocumentationDriftTest.
- effort: S; deps: none.

### GAP-PA-05 / PA-06 / PA-07 · Device nodes, voice wake, email/calendar
- approach: out of product scope; email and calendar via MCP servers. Document rather than build. effort: L each; deps: owner.

### GAP-PA-08 · Multi-user access control
- have / missing: a single owner principal. OpenClaw ✅.
- approach: per-user tokens with scope subsets in TokenStore (Scope already models read/write/approve/admin), plus an allowlist file.
- files: modify `src/Server/Auth/TokenStore.php`, `src/Server/Http/AuthMiddleware.php`, `docs/SERVER.md` ⚠HOT.
- guards: ServerProtocolDocumentationDriftTest.
- effort: L; deps: owner security ruling.

## Dormant code

1. **`/loop` on served sessions.** Reachable from the browser via `command.exec` but never fires. `src/Host/Commands/LoopCommand.php:63-81` registers an ephemeral row through the registry that `src/Host/SessionHost.php:1647` hands to host commands. `src/Background/ScheduleRegistry.php:395-397` (`fireDue`, the serve path) skips non-persisted rows, and the only ephemeral pump is `src/Chat.php:18556-18566`. **Wiring needed:** a SessionHost pump keyed by owning session (GAP-AUT-08).
2. **The sugar-forms vim text-object machinery has zero consumers:**
   - `sugar-forms/src/Vim/VimKeyHandler.php:289-304` (`handleTextObject`)
   - `sugar-forms/src/Vim/TextObject.php:62,78` (`isTarget`, `resolve`)
   - `sugar-forms/src/Vim/VimOperator.php:19-31`
   - `sugar-forms/src/Vim/TextObjectScope.php`
   - `sugar-forms/src/Vim/VimAction.php:65-71` (`Delete/Change/YankTextObject`)

   Neither TextArea, TextInput nor ComposerVim calls them. **Wiring needed:** ComposerVim's pending grammar resolves `d/y/c` + `i/a` + target through `TextObject::resolve()` (GAP-UI-07).
3. **Protocol methods with server handlers but no web caller:**
   - `files.read` (`src/Protocol/Methods/FilesMethods.php:40`): wire a read-only file viewer in the Changes/Context panels.
   - `memory.log` and `memory.undo` (`MemoryMethods.php:70-71`): a history list and undo in MemoryPanel.
   - `session.share` and `session.unshare` (`SessionMethods.php:57-58`): a "Share link / Revoke" item on the session-row menu.
   - `workflow.status` (`WorkflowMethods.php:59`): run detail in WorkflowPanel.
   - `workspace.close` (`WorkspaceMethods.php:37`): a "close root host" action on non-primary roots in the sidebar.

   `server.shutdown` (`src/Protocol/Methods/ServerMethods.php:46`) stays deliberately dormant behind its negative-control test (`candy-code-web/src-web/__tests__/shutdownDormant.spec.ts`).
4. **`canGenerateVideo`** is computed at `candy-code-web/src-web/stores/connection.ts:91` and nothing reads it; GeneratePanel gates on image only (`GeneratePanel.vue:34`). **Wiring needed:** a video tab in GeneratePanel once `generate.submit` accepts a video kind. Today `MediaMethods.php:93-99` registers image submission only.
5. **The trust targets beyond MCP are unrealised.** `src/Protocol/TrustAsks.php:28` declares a single grantable target (`TARGET_MCP`) in a registry built for more. The `trustedProjectHooks`, `trustedProjectCommands` and `trustedProjectSettings` predicates (`src/Config/Settings/Definitions/PermissionSettings.php:134-152`) have no prompt anywhere. **Wiring needed:** GAP-PER-11.
