# 14 — sugar-crush server mode (WebSocket) + `sugar-crush-web` multi-session web UI: design report

Feeds: the open follow-ups on the attached TUI and the missing protocol events, and the open TLS decision (synthesis Part IV).

**What this is.** A design for two features the user asked for:

1. **Server mode.** `sugarcrush serve` runs in the foreground or as a background daemon and starts a WebSocket server.
2. **`sugar-crush-web`.** A separate Vite + Vue package in `/home/sites/sugarcraft/sugar-crush-web` that drives that server and controls several sessions at once.

Paths are relative to `/home/sites/sugarcraft/sugar-crush/` unless they start with `/` or with a lib directory name.

---

Only the sections an open item still cites are kept: the attach design (§4.9), the event catalogue (§6.5) and the open TLS question (§10). The shipped behaviour is documented in `sugar-crush/docs/SERVER.md`.

## 4. Server mode design

### 4.9 The TUI as a client (Phase 8)

- `sugarcrush attach [url|--discover]` reads `server.json` (or a URL plus token), opens a WS, and runs the normal `Program(App)` with a **`Backend\RemoteBackend`** (implements `Backend`, `ObservesReasoning`) plus a `RemoteSessionHost` proxy that implements the same `SessionHost` client surface over JSON-RPC.
- Because Chat already delegates to `Host\*` services after the extraction, the remote variant swaps the service implementations, not Chat.
- **Benefits:**
  - the TUI and browser see the same live session;
  - turns survive closing the terminal;
  - the TUI gains approvals parity automatically.
- **Optional cheap remote-TUI channel:** because candy-core `Program` accepts `input`/`output` streams and `windowSize` (`ProgramOptions.php`), a `/pty` WS endpoint could stream the real TUI into xterm.js (ttyd-style). candy-wish already does "TUI over SSH via sshd". This is low-effort but **not** multi-session-friendly. Keep it as a footnote, not the plan.

## 6. Wire protocol (`sugarcrush.v1`)

### 6.5 Event catalogue (v1)

Durable (D) events are written to `session_events` before they are broadcast. Ephemeral (E) events are live only.

| Type | D/E | `data` | Source in sugar-crush |
|---|---|---|---|
| `session.created` / `session.updated` / `session.deleted` | D (server scope) | `SessionSummary {id, name, provider, model, permissionMode, createdAt, updatedAt, messageCount, spentUsd, status}` | store ops, title rename |
| `session.status` | D | `{status: "idle"\|"busy"\|"waiting_permission"\|"queued"\|"compacting"\|"error", detail?}` | TurnController |
| `message.created` | D | `{messageId, role, content, createdAt, kind:"user"\|"assistant"\|"system"\|"notice"}` | user prompt rows, notices, compaction summaries |
| `turn.started` | D | `{turnId, messageId (user), provider, model, maxSteps, delivery}` | dispatch |
| `assistant.started` | D | `{turnId, partId}` | first token |
| `assistant.delta` | **E** | `{turnId, partId, offset, text}` | `token` frames. `offset` = byte offset into the part, so duplicates and gaps are detectable |
| `reasoning.delta` | **E** | `{turnId, partId, offset, text}` | `reasoning` frames (empty heartbeats are **not** forwarded) |
| `assistant.completed` | D | `{turnId, partId, messageId, content, reasoning?, lengthStopped, stepsTruncated}` | `result` frame. Closes the delta stream |
| `tool.started` | D | `{turnId, toolCallId, name, description?, arguments}` | `started` frame (`encodeEvent`) |
| `tool.progress` | E | `{toolCallId, tail}` | future (Bash streaming) |
| `tool.finished` | D | `{turnId, toolCallId, name, isError, durationMs, content (≤256 KiB, `truncated`), diff? (unified), image? {protocol, mime, bytesB64 ≤ 2 MiB}\|{path}, denial? {kind: DenialKind, reason}}` | `finished` frame, `DenialKind` |
| `permission.requested` | D | `{askId, turnId, toolCallId, tool, arguments, reason, source, mode, options:["once","always","reject"], alwaysScope}` | §5 |
| `permission.resolved` | D | `{askId, reply, note?, by:{clientId, principal}, cancelled?:bool, cascaded?:bool}` | §6.7 |
| `subagent.started` / `subagent.progress` (E, throttled 1/s) / `subagent.finished` | D/E/D | `{id, parentToolCallId, name, task, tail?, usage?, result?}` | `subagent` frames, `SubAgentActivity` |
| `usage.updated` | D | `{turnId?, step?, usage{totalTokens, inputTokens, outputTokens, cacheReadTokens, reasoningTokens, costUsd}, sessionSpentUsd, contextTokens, contextLimit, contextPct}` | `usage` frames + `Usage::toArray()` + `ContextWindow`/token calibration |
| `spend_cap.breached` | D | `{calls, spent, cap}` | `spend_cap` frame |
| `turn.step` | E | `{turnId, step, maxSteps}` | `step` frame |
| `turn.steered` | D | `{turnId, steerId, step, messageId}` | `steer_ack` |
| `turn.completed` | D | `{turnId, stopReason: "end_turn"\|"max_steps"\|"length"\|"cancelled"\|"spend_cap"\|"error", error?}` | settle |
| `turn.queued` / `turn.dequeued` | D | `{queueId, text, position}` | queue |
| `compaction.started` / `compaction.completed` | D | `{kind:"llm"\|"heuristic"\|"truncate", before, after, savedPct, summaryMessageIds[]}` | `Chat.php` |
| `notice` | D | `{level:"info"\|"warn"\|"error", text, code?}` | `RuntimeNoticeSink` (per session), launch notices |
| `session.titled` | D | `{name}` | `SessionTitledMsg` |
| `prompt.suggestion` | E | `{text}` | `schedulePromptSuggestion` |
| `bg.started` / `bg.output` (E) / `bg.status` / `bg.completed` | D/E/D/D (server scope) | `BackgroundSession::toArray()` | supervisor listener |
| `workflow.*` | D | stage events | `WorkflowEngine` |
| `server.tick` | E (server scope) | `{now, turnsRunning}` | every `tickIntervalMs` |
| `server.shutdown` | E | `{reason, graceSeconds}` | §4.6 |
| `server.overflow` | E | `{sessionId?, dropped, action:"resubscribe"}` | §6.9 |

**Not in the stream: secrets.**
- `settings.*` values for provider `apiKey`-like keys are masked.
- Hook and MCP env are never sent.
- `notice` text is clipped (`RuntimeNoticeSink::clip()` exists).


## 10. Open question

**Still open (decision 7):** is non-loopback via a reverse proxy enough for v1, or is built-in TLS wanted?
