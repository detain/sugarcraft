# 03 — opencode-dynamic-context-pruning (DCP) vs sugar-crush

**Competitor:** `Tarquinen/opencode-dynamic-context-pruning` (npm `@tarquinen/opencode-dcp`), an **opencode plugin**, not a full agent.
**Studied:** shallow clone `/home/sites/crush-research-repos/opencode-dynamic-context-pruning` @ `f8232fd` (2026-09-24, package `3.2.0`), plus the published `2.1.8` tarball (unpacked to `/tmp/dcp-old/package`) to recover the older three-tool design, the opencode host @ `a79ecfe` (`/home/sites/crush-research-repos/opencode`) for the hooks it relies on, and the GitHub issue tracker (`gh issue list/view`) for known failures.
**Baseline:** `prompt_kit/findings/crush-report/00-sugar-crush-baseline.md` ("baseline §N"). sugar-crush claims that the design depends on were re-checked in source; the paths are under `sugar-crush/`.

Paths without a prefix are DCP paths (`lib/...`, `index.ts`). opencode paths start with `opencode/packages/...`. sugar-crush paths start with `src/...`.

---

## 1. Overview

**What it is.** DCP is a ~10.6k-line TypeScript plugin, AGPL-3.0, that sits between opencode's session store and the LLM request. It uses opencode's `experimental.chat.messages.transform` hook to **rewrite the message list on every request without touching stored history**. It exposes one model-callable tool, `compress`, which lets the agent replace parts of its own conversation that it has finished with by summaries it writes itself. It also runs two zero-cost automatic strategies, deduplication and purging of errored inputs.

From the README ("How It Works"): *"Your session history is never modified — DCP replaces pruned content with placeholders before sending requests to your LLM."*

**Project status.** README: *"Development on DCP has slowed because most new context-management work has moved to Sleev"*, a local proxy that ships the same ideas to Claude Code, Codex, OpenCode, Pi and Hermes.

**Version history.** The history matters because the user's favourite idea appears in both designs:

| Version | Model-facing design |
|---|---|
| 2.x (e.g. 2.1.8) | **Three tools**: `prune` (drop tool outputs by numeric ID), `distill` (replace tool outputs with a model-written distillation), `compress` (summarise a message range). A `<prunable-tools>` list of `ID: tool, param (~N tokens)` is injected every turn. Automatic strategies: dedup, **supersedeWrites**, purgeErrors. |
| 3.0.0 | *"The previous 3-tool system (`distill`, `compress`, `prune`) has been replaced with a **single `compress` tool**"* (release notes). Rationale given: *"Reduced cache invalidation"*, *"Infinite conversations — … the previous tool pruning approach limited this by leaving user/AI messages untouched"*, *"Simplified model behavior — The model no longer needs to choose between 3 context management tools"*. supersedeWrites was dropped. |
| 3.1.x | Experimental `compress.mode: "message"` (per-message summaries with priority labels), multi-range batches, `summaryBuffer`, protect tags, and a TUI panel. |
| 3.2.0 | OpenCode V2 plugin API (`lib/v2/*`), compact IDs (`@4@`, `@b1@`, `@blocked@`), and a hook on opencode's own compaction. |

**Architecture.** The plugin returns this hook map (`index.ts:57-135`):

```
experimental.chat.system.transform   -> append DCP system prompt (lib/hooks.ts:58)
experimental.chat.messages.transform -> the whole pruning pipeline, per LLM request (lib/hooks.ts:107)
experimental.text.complete           -> strip DCP tags the model echoed in its output (lib/hooks.ts:292)
command.execute.before               -> /dcp and /dcp-compress (lib/hooks.ts:167)
event                                -> time each compress call (message.part.updated) (lib/hooks.ts:301)
tool: { compress }                   -> range or message mode (index.ts:82-89)
config                               -> register /dcp-compress, add `compress` to experimental.primary_tools,
                                        default the `compress` permission (index.ts:90-134)
```

opencode fires `experimental.chat.messages.transform` **once per LLM step** inside the agent loop (`opencode/packages/opencode/src/session/prompt.ts:1255`) and **also on the compaction input** (`.../session/compaction.ts:379`). DCP therefore affects every step of a turn, not only turn boundaries.

**What it does best:**

1. **Agent-driven self-compression with explicit, stable handles.** Every message carries an injected ID (`<dcp-message-id>m0007</dcp-message-id>`). The model chooses `startId`/`endId` ranges it considers *closed* and writes the summary itself. The summary is stored as a "block" `bN`, and the raw messages vanish from the next request.
2. **Non-destructive projection.** Pruning is a pure function of (stored history, plugin state) that is re-applied on each request. That gives free undo (`/dcp decompress N`, `/dcp recompress N`), and history survives intact for the UI, for export and for opencode's own compaction.
3. **Nested compression without information loss.** A new range that covers an earlier block must re-emit the block's `(bN)` placeholder, which DCP expands back into the stored summary. Any placeholder the model forgets is appended automatically. Consumed blocks are deactivated and remain restorable.
4. **Protected content that survives summarisation.** Outputs of `task`, `skill`, `todowrite` and `todoread`, file-glob-protected tool calls, `<protect>…</protect>` text and (optionally) user messages are appended verbatim to any summary that covers them. The model cannot summarise them away.
5. **Pressure-graded nudges that keep the cache stable.** There are three reminder types (turn, iteration, context-limit), gated by min/max token thresholds and a frequency. They are *anchored* to specific message IDs and persisted, so the same bytes re-appear at the same place on every later request instead of moving to the tail.
6. **Zero-cost automatic strategies, applied only when the cache is being broken anyway.** Deduplication of identical tool calls and purging of inputs to failed tools are recomputed only when the `compress` tool runs (`lib/compress/pipeline.ts:72-73`). The prompt-prefix change happens once per compression rather than once per step.
7. **Real token accounting.** It uses the provider-reported tokens of the last assistant step (`lib/token-utils.ts:9-38`) plus the Anthropic tokenizer for per-message sizes.
8. **Operator controls.** Manual mode, a focused manual trigger (`/dcp-compress <focus>`), sweep, a per-category context breakdown, all-time stats, editable prompts, and per-model thresholds.

---

## 2. Agent loop (only the parts DCP touches)

DCP has no loop of its own. It depends on these properties of opencode's loop:

- **Per-step transform.** `prompt.ts:1255` runs `plugin.trigger("experimental.chat.messages.transform", {}, { messages: msgs })` before building every model request. DCP mutates `msgs` in place (`filterMessagesInPlace`, `lib/messages/shape.ts:35`), and opencode then converts it with `MessageV2.toModelMessagesEffect`. A `compress` call made at step *k* is already reflected at step *k+1* of the same turn.
- **Structured tool parts.** opencode messages are `{info, parts[]}`. Tool parts carry `callID`, `tool`, `state.input`, `state.output` and `state.status`. DCP edits `part.state.output` and `part.state.input` in the transformed copy to prune (`lib/messages/prune.ts:75-159`).
- **Tool execution context.** `compress.execute(args, toolCtx)` gets `toolCtx.ask()` (the permission prompt, `lib/compress/pipeline.ts:50`), `toolCtx.metadata({title})` (the UI title "Compress Range: <topic>"), `sessionID`, `messageID` and `callID`.
- **Output post-processing.** `experimental.text.complete` lets DCP strip DCP tags that the model echoed into its own text (`stripHallucinationsFromString`, `lib/messages/utils.ts:170-178`). This fights a feedback loop where the model copies `mNNNN</parameter>` into its replies (issues #555, #632).
- **Doom loops.** None of DCP's own. The relevant failure mode is a *compression* loop. Issue #573 records 71 blocks and 738,738 tokens burnt in one session (§4.16).

---

## 3. Agents and sub-agents

- **Sub-agents are excluded by default.**
  - DCP adds `compress` to opencode's `experimental.primary_tools` unless `experimental.allowSubAgents` is set (`index.ts:106-117`).
  - opencode's Task tool turns every primary tool into a `deny` rule for child sessions (`opencode/packages/opencode/src/tool/task.ts:150-154`).
  - The transform handler also exits early when `state.isSubAgent`, which is detected from `session.parentID` (`lib/state/utils.ts:54-61`; `lib/hooks.ts:129-131`).
  - The 2.x README gave the reason: *"Subagents are not designed to be token efficient; what matters is that the final message returned to the main agent is a concise summary of findings."*
- **With `allowSubAgents`:**
  - The sub-agent receives `SUBAGENT_SYSTEM_EXTENSION` (`lib/prompts/extensions/system.ts:12-19`): *"The initial subagent instruction is imperative and must be followed exactly. It is the only user message intentionally not assigned a message ID, and therefore is not eligible for compression."* `assignMessageRefs` skips the first user message of a sub-agent session (`lib/message-ids.ts:136-139`).
  - In the parent, `injectExtendedSubAgentResults` (`lib/messages/inject/subagent-results.ts:19-84`) replaces the `<task_result>` body with the child session's last assistant text. If the child's second-to-last assistant message called `compress`, its text is prepended (`lib/subagents/subagent-results.ts:16-36`). That way a sub-agent that compressed just before reporting does not lose half its report.
  - **Known bug #595:** resuming a sub-agent via `task_id` rewrites *all* earlier task results with the latest reply, because the cache is keyed per call while the fetch reads the child's *current* last message.
- **No parent↔child communication** is added by DCP.

---

## 4. Context handling and compaction (the core of DCP)

### 4.1 The principle: project, don't mutate

Stored opencode history is never edited. DCP keeps a per-session **ledger** (`SessionState`, `lib/state/types.ts:95-114`) and re-derives the outbound view on every request:

- `prune.tools`: `Map<callID, tokens>` of tool calls whose content is pruned
- `prune.messages`:
  - `byMessageId`: `{tokenCount, allBlockIds, activeBlockIds}`
  - `blocksById`: `CompressionBlock`
  - `activeBlockIds`
  - `activeByAnchorMessageId`
  - `nextBlockId`, `nextRunId`
- `messageIds`: `byRawId` / `byRef`, i.e. opencode message id ↔ `m0001`
- `nudges`: three persisted anchor sets
- `toolParameters`: `callID → {tool, parameters, status, turn, tokenCount}`, FIFO-capped at 1000 (`lib/state/tool-cache.ts:7`)
- `stats`, `manualMode`, `compressPermission`, `modelContextLimit`, `lastCompaction`, `currentTurn`

The ledger is saved as JSON at `~/.local/share/opencode/storage/plugin/dcp/<sessionId>.json` (`lib/state/persistence.ts:45-51`).

**Consequences:**
- Undo is a flag flip (`deactivatedByUser`).
- History export and the TUI still show everything.
- opencode's own `/compact` sees the projected view (§4.12).

### 4.2 The per-request pipeline (V1, `lib/hooks.ts:107-164`)

```
filterMessagesInPlace          drop malformed messages
checkSession                   detect session switch / opencode compaction; count turns (step-start parts)
syncCompressPermissionState    honour host `permission.compress` (global + per agent)
[return if sub-agent and !allowSubAgents]
stripHallucinations            remove any <dcp…> tags / trailing mNNNN</parameter> that leaked into text or tool output
cacheSystemPromptTokens        for /dcp context
assignMessageRefs              give every new message the next mNNNN (monotonic, never reused)
syncCompressionBlocks          re-derive which blocks are active (origin message still exists? user-deactivated? consumed?)
syncToolCache                  record tool name/params/status/turn/tokens for each callID
buildToolIdList
prune                          (a) replace compressed ranges with their summary block, (b) placeholder pruned tool outputs,
                               (c) blank question inputs, (d) blank string inputs of errored tools
injectExtendedSubAgentResults  (allowSubAgents only)
buildPriorityMap               (message mode only)
injectCompressNudges           create/replay anchored reminders
injectMessageIds               append the ID tag to every message
applyPendingManualTrigger      swap the /dcp-compress user text for the trigger prompt
stripStaleMetadata             drop provider metadata from assistant parts produced by another model
```

V2 (`lib/v2/index.ts:173-243`) runs the same steps through `ctx.session.hook(kind, …)` for both `"context"` and `"compaction"`. During compaction, `injectCompressNudges(…, createAnchors = kind === "context")` only *replays* existing nudges and creates no new anchors (`:217`). The test `tests/compaction-nudges.test.ts` pins that the cached prefix is byte-identical in that case.

### 4.3 How the model is told what is prunable: injected IDs

- **Format** (`lib/message-ids.ts`):
  - Raw messages: `m0001`…`m9999` (`formatMessageRef`, `:28-36`). The hard cap is 9999 (`MESSAGE_REF_MAX_INDEX`, `:14`). Overflowing it throws *"Message ID alias capacity exceeded"*, which makes long sessions unusable (issue #549).
  - Blocks: `b1`, `b2`, ….
  - V2 compact mode: `@4@`, `@b1@`, `@blocked@`, with no cap.
- **Tag:** `\n<dcp-message-id>m0007</dcp-message-id>`, with an optional `priority="high"` attribute in message mode (`formatMessageIdTag`, `:103-125`). A protected user message shows `BLOCKED` instead of its ref.
- **Placement** (`injectMessageIds`, `lib/messages/inject/inject.ts:151-222`):
  - User messages: appended to every text part. If there is no text part, a synthetic text part is added.
  - Assistant messages: appended to **every completed tool output** (`appendToAllToolParts`). Failing that, to the last text part. Failing that, as a synthetic text part placed *before* the first tool part.
  - The prompts explain this: *"The same ID tag appears in every tool output of the message it belongs to — each unique ID identifies one complete message."*
- **Idempotence:** `appendToTextPart`/`appendToToolPart` skip a part that already `includes(tag)` (`lib/messages/utils.ts:94-132`). Because refs are assigned once and in order, the bytes are identical on every request, which keeps the prefix cache-stable.
  - **Bug #614:** a tool part that was still streaming on one request and completed on the next took a different branch, so the tag was injected twice. The prefix changed mid-history and the DeepSeek cache hit rate fell from 95-99% to about 55% until a manual compaction.
- **v2.x used tool-level IDs.** A `<prunable-tools>` block was appended to the last message every turn (`/tmp/dcp-old/package/dist/lib/messages/inject.js:19-24`, `:139-171`):
  ```
  <prunable-tools>
  The following tools have been invoked and are available for pruning. This list does not mandate immediate action. Consider your current goals and the resources you need before pruning valuable tool inputs or outputs. Consolidate your prunes for efficiency; it is rarely worth pruning a single tiny tool output. Keep the context free of noise.
  20: read, /path/to/file.ts (~1500 tokens)
  …
  </prunable-tools>
  ```
  - The numeric ID is the tool's index in `toolIdList`.
  - Protected tools, protected file paths and already-pruned calls are omitted.
  - For Claude models, which reject assistant turns that begin with injected text, the block is attached as a **synthetic completed tool part** named `context_info` instead of a text part (`rejectsTextParts`, `createSyntheticToolPart`). Gemini gets a fake `thoughtSignature`.

### 4.4 The `compress` tool, range mode (default)

**Registration:** `createCompressRangeTool` (`lib/compress/range.ts:59-203`). The description is the editable `compress-range` prompt plus a non-editable format block (`lib/prompts/extensions/tool.ts:7-24`).

**Schema** (`range.ts:30-57`):

```ts
{
  topic: string,      // "Short label (3-5 words) for display - e.g., 'Auth System Exploration'"
  content: [{         // "One or more ranges to compress, each with start/end boundaries and a summary"
    startId: string,  // "Message or block ID marking the beginning of range (e.g. m0001, b2)"
    endId: string,    // "Message or block ID marking the end of range (e.g. m0012, b5)"
    summary: string   // "Complete technical summary replacing all content in range"
  }]
}
```

**Tool description** (`lib/prompts/compress-range.ts:8-67`, XML-ID variant, quoted verbatim):

> Collapse a range in the conversation into a detailed summary.
>
> THE SUMMARY
> Your summary must be EXHAUSTIVE. Capture file paths, function signatures, decisions made, constraints discovered, key findings... EVERYTHING that maintains context integrity. This is not a brief note - it is an authoritative record so faithful that the original conversation adds no value.
>
> USER INTENT FIDELITY
> When the compressed range includes user messages, preserve the user's intent with extra care. Do not change scope, constraints, priorities, acceptance criteria, or requested outcomes.
> Directly quote user messages when they are short enough to include safely. Direct quotes are preferred when they best preserve exact meaning.
>
> Yet be LEAN. Strip away the noise: failed attempts that led nowhere, verbose tool outputs, back-and-forth exploration. What remains should be pure signal - golden nuggets of detail that preserve full understanding with zero ambiguity.
>
> COMPRESSED BLOCK PLACEHOLDERS
> When the selected range includes previously compressed blocks, use this exact placeholder format when referencing one:
> - `(bN)`
>
> Compressed block sections in context are clearly marked with a header:
> - `[Compressed conversation section]`
>
> Compressed block IDs always use the `bN` form (never `mNNNN`) and are represented in the same XML metadata tag format.
>
> Rules:
> - Include every required block placeholder exactly once.
> - Do not invent placeholders for blocks outside the selected range.
> - Treat `(bN)` placeholders as RESERVED TOKENS. Do not emit `(bN)` text anywhere except intentional placeholders.
> - If you need to mention a block in prose, use plain text like `compressed bN` (not as a placeholder).
> - Preflight check before finalizing: the set of `(bN)` placeholders in your summary must exactly match the required set, with no duplicates.
>
> These placeholders are semantic references. They will be replaced with the full stored compressed block content when the tool processes your output.
>
> FLOW PRESERVATION WITH PLACEHOLDERS
> When you use compressed block placeholders, write the surrounding summary text so it still reads correctly AFTER placeholder expansion.
> - Treat each placeholder as a stand-in for a full conversation segment, not as a short label.
> - Ensure transitions before and after each placeholder preserve chronology and causality.
> - Do not write text that depends on the placeholder staying literal (for example, "as noted in `(b2)`").
> - Your final meaning must be coherent once each placeholder is replaced with its full compressed block content.
>
> BOUNDARY IDS
> You specify boundaries by ID using the injected IDs visible in the conversation:
> - `mNNNN` IDs identify raw messages
> - `bN` IDs identify previously compressed blocks
>
> Each message has an ID inside XML metadata tags like `<dcp-message-id>...</dcp-message-id>`.
> The same ID tag appears in every tool output of the message it belongs to — each unique ID identifies one complete message.
> Treat these tags as boundary metadata only, not as tool result content.
>
> Rules:
> - Pick `startId` and `endId` directly from injected IDs in context.
> - IDs must exist in the current visible context.
> - `startId` must appear before `endId`.
> - Do not invent IDs. Use only IDs that are present in context.
>
> BATCHING
> When multiple independent ranges are ready and their boundaries do not overlap, include all of them as separate entries in the `content` array of a single tool call. Each entry should have its own `startId`, `endId`, and `summary`.

**Execution** (`range.ts:66-201`, `pipeline.ts:37-116`):

1. `validateArgs`: the topic must be non-empty, `content` must be non-empty, and every field must be a non-empty string (`range-utils.ts:15-40`). Manual mode refuses unless a trigger is pending: *"Manual mode: compress blocked. Do not retry until `<compress triggered manually>` appears in user context."* (`pipeline.ts:44-48`).
2. `toolCtx.ask({permission:"compress"})`: the host permission prompt, used only when the permission is `ask`.
3. Re-fetch the raw session messages, re-assign refs, then run **`deduplicate` + `purgeErrors`**. This is the only place the automatic strategies are recomputed.
4. `resolveRanges` → `resolveBoundaryIds` (`search.ts:46-113`).
   - Errors are worded for the model, e.g. *"startId m0042 is not available in the current conversation context. Choose an injected ID visible in context."* and *"startId … appears after endId … Start must come before end."*
   - `resolveSelection` collects every raw message, tool `callID` and active block anchored inside the range, plus each message's token count (`:115-203`).
5. `validateNonOverlapping` across the batch (`range-utils.ts:71-102`).
6. For each range, build the stored summary:
   - `parseBlockPlaceholders` accepts `(bN)` or `{block_N}`.
   - `validateSummaryPlaceholders` drops unknown, duplicate or unrequired placeholders and returns the *missing* required ones. Boundary blocks are optional.
   - `injectBlockPlaceholders` replaces each placeholder with the stored block body (header and footer stripped) and auto-injects a boundary block at the start or end if one was not referenced.
   - `appendProtectedUserMessages`: only when `protectUserMessages`. Heading: *"The following user messages were sent in this conversation verbatim:"*.
   - `appendProtectedPromptInfo`: `<protect>…</protect>` spans, only when `protectTags`. Heading: *"The following protected prompt information was included in this conversation verbatim:"*.
   - `appendProtectedTools`: completed outputs of protected tools and file patterns. Heading: *"The following protected tools were used in this conversation as well:"* followed by `### Tool: task` and the output.
   - `appendMissingBlockSummaries`: *"The following previously compressed summaries were also part of this conversation section:"* followed by `### (bN)` and the body.
7. `wrapCompressedSummary` (`state.ts:52-64`) stores:
   ```
   [Compressed conversation section]
   <summary>

   <dcp-message-id>b3</dcp-message-id>
   ```
8. `applyCompressionState` (`state.ts:66-272`):
   - creates `CompressionBlock{blockId, runId, topic, startId, endId, anchorMessageId, compressMessageId, compressCallId, consumedBlockIds, effectiveMessageIds, effectiveToolIds, directMessageIds, compressedTokens, summaryTokens, durationMs, …}`;
   - deactivates consumed blocks and records their `parentBlockIds`;
   - marks every covered message as in an active block;
   - counts `compressedTokens` from **newly** covered messages only, so re-compressing does not double-count savings.
9. `finalizeSession`: clears `compress-pending`, saves state, and sends the notification (§11).
10. **Tool result returned to the model:** `Compressed ${n} messages into [Compressed conversation section].`

**How a compressed range is replaced on later requests** (`filterCompressedRanges`, `lib/messages/prune.ts:161-244`):
- At the block's **anchor**, the first raw message of the range, DCP inserts a **synthetic user message** whose only text part is the stored summary.
- Its ids are deterministic: `msg_dcp_summary_<sha256(blockId:anchor)[0:16]>` (`createSyntheticUserMessage`, `lib/messages/utils.ts:15-50`).
- The message's `agent`/`model` fields are cloned from the nearest preceding user message.
- **Every raw message inside an active block is dropped.**
- Placeholders, not summaries, are used for *tool-level* pruning (§4.8).

**Hidden cost (the summary is held twice).** The `compress` tool call itself stays in the assistant message that made it, with the full summary in its arguments, and the same summary is injected at the anchor. DCP deals with this indirectly:
- In message mode, messages containing a completed `compress` call are always classified `high` priority (`lib/messages/priority.ts:57`).
- The message-mode prompt says *"If prior compress-tool results are present, always compress and summarize them minimally only as part of a broader compression pass."*
- The summary tokens add to the context until a later range covers the compress call.

### 4.5 The `compress` tool, message mode (experimental)

**Schema** (`lib/compress/message.ts:17-42`): `{topic, content: [{messageId, topic, summary}]}`. Each entry becomes its own block with `startId = endId = messageId`.

**Prompt** (`lib/prompts/compress-message.ts:6-48`), key paragraphs verbatim:

> Collapse selected individual messages in the conversation into detailed summaries.
> …
> If a message contains no significant technical decisions, code changes, or user requirements, produce a minimal one-line summary rather than a detailed one.
> …
> The `priority` attribute indicates relative context cost. You MUST compress high-priority messages when their full text is no longer necessary for the active task.
> If prior compress-tool results are present, always compress and summarize them minimally only as part of a broader compression pass. Do not invoke the compress tool solely to re-compress an earlier compression result.
> Messages marked as `<dcp-message-id>BLOCKED</dcp-message-id>` cannot be compressed.
> …
> BATCHING
> Select MANY messages in a single tool call when they are safe to compress.
> …
> GENERAL CLEANUP
> Use the topic "general cleanup" for broad cleanup passes.
> During general cleanup, compress all medium and high-priority messages that are not relevant to the active task.
> Optimize for reducing context footprint, not for grouping messages by topic.
> Do not compress away still-active instructions, unresolved questions, or constraints that are likely to matter soon.
> Prioritize the earliest messages in the context as they will be the least relevant to the active task.

**Priorities** come from the per-message token count (`lib/messages/priority.ts:7-8,64-74`): `high` at ≥ 5000 tokens, `medium` at ≥ 500, `low` otherwise, and `high` always for messages that contain a completed `compress` call.

**Partial success** (`lib/compress/message-utils.ts:151-255`):
- Bad entries become grouped "soft issues" (`blocked`, `invalid-format`, `block-id`, `not-in-context`, `protected`, `already-compressed`, `duplicate`). The good entries are applied, and the model gets `Compressed N messages into [Compressed conversation section].\nSkipped K issues:\n- messageIds m0003, m0004 are already part of active compressions.`
- The call throws only when *nothing* resolved.
- This is more forgiving than range mode, which throws on the first bad boundary.

### 4.6 The DCP system prompt (appended to the last system part every request)

`lib/prompts/system.ts:6-38`, verbatim:

> You operate in a context-constrained environment. Manage context continuously to avoid buildup and preserve retrieval quality. Efficient context management is paramount for your agentic performance.
>
> The ONLY tool you have for context management is `compress`. It replaces older conversation content with technical summaries you produce.
>
> `<dcp-message-id>` and `<dcp-system-reminder>` tags are environment-injected metadata. Do not output them.
>
> THE PHILOSOPHY OF COMPRESS
> `compress` transforms conversation content into dense, high-fidelity summaries. This is not cleanup - it is crystallization. Your summary becomes the authoritative record of what transpired.
>
> Think of compression as phase transitions: raw exploration becomes refined understanding. The original context served its purpose; your summary now carries that understanding forward.
>
> COMPRESS WHEN
>
> A section is genuinely closed and the raw conversation has served its purpose:
> - Research concluded and findings are clear
> - Implementation finished and verified
> - Exploration exhausted and patterns understood
> - Dead-end noise can be discarded without waiting for a whole chapter to close
>
> DO NOT COMPRESS IF
> - Raw context is still relevant and needed for edits or precise references
> - The target content is still actively in progress
> - You may need exact code, error messages, or file contents in the immediate next steps
>
> Before compressing, ask: _"Is this section closed enough to become summary-only right now?"_
>
> Evaluate conversation signal-to-noise REGULARLY. Use `compress` deliberately with quality-first summaries. Prioritize stale content intelligently to maintain a high-signal context window that supports your agency.
>
> It is of your responsibility to keep a sharp, high-quality context window for optimal performance.

**Extensions** appended after it (`lib/prompts/extensions/system.ts`):
- **Protected tools:** *"The following tools are environment-managed: `task`, `skill`, `todowrite`, `todoread`. Their outputs are automatically preserved during compression. Do not include their content in compress tool summaries — the environment retains it independently."*
- **Manual mode:** *"Manual mode is enabled. Do NOT use compress unless the user has explicitly triggered it through a manual marker. Only use the compress tool after seeing `<compress triggered manually>` … Issue exactly ONE compress tool per manual trigger … After completing a manually triggered context-management action, STOP IMMEDIATELY."*
- **Sub-agent:** see §3.

**Skipped for internal calls.** `isInternalAgentCall` matches opencode's title generator and summariser prompts (`INTERNAL_AGENT_SIGNATURES`, `lib/hooks.ts:42-56`): *"You are a title generator"*, *"You are a helpful AI assistant tasked with summarizing conversations"*, …. Bug #581 recorded a false positive that blocked nudges on main sessions.

### 4.7 Nudges: thresholds, kinds, frequency, placement

**Thresholds** (`lib/messages/inject/utils.ts:87-163`):
- `compress.minContextLimit` (default **50000**) and `compress.maxContextLimit` (default **100000**). Each accepts a number or `"X%"` of the model's context window.
- Per-model overrides: `modelMinLimits` / `modelMaxLimits`, keyed `"provider/model"`.
- With `summaryBuffer: true` (the default), **the token count of active summaries is added to the max limit**, so the summaries alone cannot keep the session above the limit.
- The current size is `getCurrentTokenUsage`: the last assistant step's `input + output + reasoning + cache.read + cache.write`, as reported by the provider (`lib/token-utils.ts:9-38`). It reports 0 when that step predates an opencode compaction.

**Three nudge texts** (`lib/prompts/*.ts`, verbatim; each is wrapped in `<dcp-system-reminder>`):
- **context-limit** (over max):
  > CRITICAL WARNING: MAX CONTEXT LIMIT REACHED
  > You are at or beyond the configured max context threshold. This is an emergency context-recovery moment.
  > You MUST use the `compress` tool now. Do not continue normal exploration until compression is handled.
  > If you are in the middle of a critical atomic operation, finish that atomic step first, then compress immediately.
  > SELECTION PROCESS
  > Start from older, resolved history and capture as much stale context as safely possible in one pass.
  > Avoid the newest active working messages unless it is clearly closed.
  > SUMMARY REQUIREMENTS
  > Your summary MUST cover all essential details from the selected messages so work can continue.
  > If the compressed range includes user messages, preserve user intent exactly. Prefer direct quotes for short user messages to avoid semantic drift.
- **turn** (between min and max, at a new user turn):
  > Evaluate the conversation for compressible ranges.
  > If any messages are cleanly closed and unlikely to be needed again, use the compress tool on them.
  > If direction has shifted, compress earlier ranges that are now less relevant.
  > The goal is to filter noise and distill key information so context accumulation stays under control.
  > Keep active context uncompressed.
- **iteration** (between min and max, after a long autonomous run):
  > You've been iterating for a while after the last user message.
  > If there is a closed portion that is unlikely to be referenced immediately (for example, finished research before implementation), use the compress tool on it now.

**Anchoring rules** (`injectCompressNudges`, `lib/messages/inject/inject.ts:33-149`):
- If the last assistant message contains a completed `compress`, **all anchors are cleared** and nothing is injected. This works as a post-compression cooldown.
- Below min: turn and iteration anchors are cleared.
- Over max: the last message becomes a context-limit anchor, but only if at least `nudgeFrequency` (default **5**) messages have passed since the previous context-limit anchor (`addAnchor`, `utils.ts:165-193`).
- Between min and max:
  - When the newest message is a user message, both it and the previous assistant message are added to turn anchors. With `nudgeForce: "soft"` (default) the nudge renders on the **assistant** message; with `"strong"` it renders on the user message (`collectTurnNudgeAnchors`, `utils.ts:271-288`).
  - When at least `iterationNudgeThreshold` (default **15**) messages have passed since the last user message, an iteration anchor is added, again spaced by `nudgeFrequency`.
- Anchors are **persisted** and **re-rendered at the same messages on every later request**. That keeps the bytes before the newest message stable, so a nudge does not invalidate the cache the way a moving tail reminder would.

**Placement** (`injectAnchoredNudge`, `utils.ts:211-248`):
- The nudge is appended to the anchored message's last text part. For an assistant message with no text part, a synthetic text part is inserted *before* the first tool part.
- **Known failure #520:** with the default `soft` force, the nudge lands in an assistant message. If that message is last, the request ends on an assistant turn, and Anthropic Claude 4.6+ rejects it with *"This model does not support assistant message prefill"*.

**Guidance appended to nudges:**
- Range mode: *"Compressed block context: - Active compressed blocks in this session: 2 (b1, b3) - If your selected compression range includes any listed block, include each required placeholder exactly once in the summary using `(bN)`."* (`lib/prompts/extensions/nudge.ts:4-17`).
- Message mode: *"Message priority context: - Higher-priority older messages consume more context and should be compressed right away if it is safe to do so. - High-priority message IDs before this point: m0003, m0010"*.

**v2.x nudges** were count-based rather than size-based:
- A counter grew by one per tool result (`state/tool-cache.js:38`). When it reached `nudgeFrequency` (default **10**), a three-option nudge was injected: *"CRITICAL CONTEXT WARNING / Your context window is filling with tool. Strict adherence to context hygiene is required … `distill` KNOWLEDGE PRESERVATION … `compress` PHASE COMPLETION … `prune` NOISE REMOVAL: If you read files or ran commands that yielded no value, use the `prune` tool to remove them. If newer tools supersedes older ones, prune the old"*.
- After any prune, a **cooldown** message replaced the list: *"Context management was just performed. Do NOT use the prune tool again. A fresh list will be available after your next tool use."*
- The 2.x system prompt also carried a TIMING rule worth copying: *"Prefer managing context at the START of a new agentic loop (after receiving a user message) rather than at the END of your previous turn … AVOID USING MANAGEMENT TOOLS AS THE ONLY TOOL CALLS IN YOUR RESPONSE, PARALLELIZE WITH OTHER RELEVANT TOOLS"*.

### 4.8 Automatic strategies and replacement placeholders

| Strategy | Rule | What is replaced | Where |
|---|---|---|---|
| **Deduplication** (default on) | Group unpruned, unprotected tool calls by `tool::JSON(sorted non-null params)`; mark every member except the newest | Completed output → `"[Output removed to save context - information superseded or no longer needed]"`. `edit`, `write` and `question` outputs are **never** replaced (`prune.ts:92`) | `lib/strategies/deduplication.ts:12-125` |
| **Purge errors** (default on, `turns: 4`) | A tool with `status: "error"` whose turn age (`step-start` count) is at least `turns` | **Every string input** → `"[input removed due to failed tool call]"`. The error message is kept | `lib/strategies/purge-errors.ts:15-86`, `prune.ts:130-159` |
| Question inputs (via any prune) | Pruned `question` tool | `input.questions` → `"[questions removed - see output for user's answers]"` | `prune.ts:101-128` |
| **Supersede writes** (2.x only, removed in 3.0) | A `write` to path P followed later by a `read` of P | The write's input | `/tmp/dcp-old/package/dist/lib/strategies/supersede-writes.js` |
| `/dcp sweep [n]` (user command) | Every tool since the last user message, or the last *n* tools, minus protected ones | As dedup | `lib/commands/sweep.ts:125-266` |

**When strategies run.** In v3, `deduplicate`/`purgeErrors` are called **only from `prepareSession`**, i.e. when the model runs `compress` (`pipeline.ts:72-73`). The README states the intent: *"Recalculated when the compress tool runs, so prompt cache is only impacted alongside compression."* In 2.x they ran on every request ("Runs automatically on every request with zero LLM cost"), which is what produced the Anthropic cache complaints in #387.

**Turn protection** (`turnProtection`, default off, 4 turns): tool calls younger than *N* turns are not even entered into `toolParameters` (`tool-cache.ts:41-52`). They are invisible to dedup, purge and sweep. 2.x also used this to hide them from `<prunable-tools>`.

### 4.9 Protected tools, files and content

- `DEFAULT_PROTECTED_TOOLS = [task, skill, todowrite, todoread, compress, batch, plan_enter, plan_exit, write, edit]` (`lib/config.ts:88-99`).
  - **In code this default is only applied to `commands.protectedTools`** (sweep, `config.ts:674`). `strategies.deduplication.protectedTools` and `purgeErrors.protectedTools` default to `[]`, and `compress.protectedTools` to `[task, skill, todowrite, todoread]` (`:101`, `:699`).
  - The README's *"By default, these tools are always protected from pruning … The `protectedTools` arrays in `commands` and `strategies` add to this default list"* overstates it. Dedup *could* prune a duplicate `task` call with identical arguments. `edit`/`write` outputs are hard-skipped in `pruneToolOutputs` anyway.
- `protectedFilePatterns`: globs matched against `filePath`/`path`, the `apply_patch` `*** Add|Delete|Update File:` lines, and `multiedit` entries (`lib/protected-patterns.ts:64-107`). Matches are excluded from dedup, purge and sweep, and their outputs are appended to compress summaries.
- The V2 alias map extends protection lists across the tool rename: `task→subagent`, `bash→shell`, `apply_patch→patch` (`lib/v2/index.ts:67-81`).

### 4.10 Token accounting and `/dcp context`

- **Live size:** provider-reported, as described in §4.7.
- **Per-message and per-tool sizes:** `@anthropic-ai/tokenizer`, falling back to `chars/4` (`token-utils.ts:69-76`). Bug #638: the tokenizer builds a new WASM instance per call (~70 ms each), which blocks the host on long sessions.
- **`/dcp context` breakdown** (`lib/commands/context.ts`, header comment):
  - SYSTEM = first assistant step's input + cache − tokenizer(first user message)
  - TOOLS = tokenizer(inputs + outputs) − pruned
  - USER = tokenizer(all user text)
  - ASSISTANT = the residual
  - TOTAL = the last step's API totals

### 4.11 Interaction with prompt caching

- README: *"When DCP prunes content, it changes messages, which invalidates cached prefixes from that point forward."* There is no impact under request-based billing or uniform pricing.
- The 2.1.8 README measured *"cache hit rates were approximately 80% with DCP enabled vs 85% without for most providers"* and warned that Claude subscription users deplete limits faster.
- **v3 design decisions that exist for caching:**
  1. Strategies are recomputed only on compress (§4.8).
  2. Refs are assigned once, monotonically, and never renumbered.
  3. Nudges are anchored and replayed rather than appended to the moving tail.
  4. Summary message ids are deterministic hashes.
  5. Echoed tags are stripped so the model cannot feed them back.
- The maintainer on #387: *"anthropic caching is really terrible … any kind of modifications invalidate the entire cache other than the system prompt, also their cache read tokens are 'free' on subscription, so losing any cache is really bad."* Manual mode exists partly for this reason.
- **Why the main model, not a cheaper one, writes the summaries** (#387, #502): *"you would lose all cache read for the tool processing call as you're now on a different model … Cache read for opus is half the cost of normal input tokens on haiku."* The summary is produced on a warm cache as an ordinary tool call.

### 4.12 Interaction with opencode's own compaction

- **opencode's native mechanisms** (both remain active alongside DCP):
  - **Tool-output prune** (`opencode/.../session/compaction.ts:271-314`, behind `cfg.compaction.prune`): walks back from the end, skipping the last 2 user turns. It protects the newest `PRUNE_PROTECT = 40_000` tokens of tool output and the `skill` tool. Once more than `PRUNE_MINIMUM = 20_000` tokens would be freed, it sets `part.state.time.compacted`, and the output then renders as `"[Old tool result content cleared]"` (`message-v2.ts:297-298`).
  - **LLM compaction**, which fires `experimental.chat.messages.transform` on the selected head (`compaction.ts:379`). The summariser therefore sees DCP's projected view, with summaries in place of raw ranges. That is cheaper, and it is why #521 ("compressed messages reappear after /compact") could be fixed.
- **DCP V1 on detecting compaction** (an assistant message with `summary === true`):
  - `checkSession` → `resetOnCompaction` wipes `toolParameters`, prune maps, blocks, refs and nudges (`lib/state/utils.ts:331-345`).
  - `isMessageCompacted` treats every message created before `lastCompaction` as gone.
  - Bug #551: refs the model saw before compaction are now unknown, and the error *"is not available in the current conversation context"* misleads it.
- **DCP V2** hooks `"compaction"` as well as `"context"`. It syncs blocks against the full history, *"Compaction may select only a prefix; block origins can be in the retained tail"* (`lib/v2/index.ts:201`), and passes `view.summaryBase` so summaries can anchor even when no user message precedes them.
- **Opportunity DCP lacks:** opencode offers `experimental.session.compacting` to inject context into or replace the compaction prompt (`opencode/packages/plugin/src/index.ts:299-310`). DCP does not use it; issue #577 asks for compaction hooks for cross-plugin compatibility.

### 4.13 Manual mode, the manual trigger, and undo

- `manualMode.enabled` (default false) and `automaticStrategies` (default true). `/dcp manual [on|off]` persists per session.
- `/dcp-compress [focus]` replaces the user's text with this trigger (`lib/commands/manual.ts:23-29`):
  ```
  <compress triggered manually>
  Manual mode trigger received. You must now use the compress tool.
  Find the most significant completed conversation content that can be compressed into a high-fidelity technical summary.
  Follow the active compress mode, preserve all critical implementation details, and choose safe targets.
  Return after compress with a brief explanation of what content was compressed.
  ```
  It appends the active-block guidance and *"Additional user focus:\n<focus>"*.
- `/dcp decompress <n>` sets `deactivatedByUser` on the block (or on the whole run in message mode), re-syncs, and reports the restored messages and tokens. It refuses with *"Compression 2 is inside compression 5. Restore compression 5 first."* when an active ancestor consumed it (`lib/commands/decompress.ts:153-275`).
- `/dcp recompress <n>` reverses that, provided the origin compress message still exists (`lib/commands/recompress.ts:106-224`).
- Issue **#611** argues that manual mode should be the default because *"in practice that sometimes destroys content the user still needed … Under context pressure … the model tends to make increasingly aggressive and imprecise choices"*.

### 4.14 The 2.x three-tool design: selective removal in its purest form

This is the version that matches the user's stated preference ("agents selectively remove or compact parts of their own history once they are finished with it").

- **`prune`** (`ids: string[]`, numeric IDs from `<prunable-tools>`):
  > Use this tool to remove tool outputs from context entirely. No preservation - pure deletion. … `prune` is surgical deletion - eliminating noise (irrelevant or unhelpful outputs), superseded information (older outputs replaced by newer data), or wrong targets (you accessed something that turned out to be irrelevant). … BATCH WISELY! Pruning is most effective when consolidated. Don't prune a single tiny output - accumulate several candidates before acting. Do NOT prune when: NEEDED LATER … UNCERTAINTY … Before pruning, ask: _"Is this noise, or will it serve me?"_ … Pruning that forces re-fetching is a net loss.
- **`distill`** (`targets: [{id, distillation}]`):
  > Use this tool to distill relevant findings from a selection of raw tool outputs into preserved knowledge … This is not mere summarization; it is high-fidelity extraction that makes the original output obsolete. Your distillation must be COMPLETE. Capture function signatures, type definitions, business logic, constraints, configuration values... EVERYTHING essential. … Prefer keeping raw outputs when: PRECISION MATTERS: You will edit the file, grep for exact strings, or need line-accurate references. … Before distilling, ask yourself: _"Will I need the raw output for upcoming work?"_ If you plan to edit a file you just read, keep it intact.
  - The distillation text is kept as the `distill` call's own arguments; `distill` is a protected tool. The raw output becomes the generic placeholder.
- **`compress`**: the range tool, described in the 2.x system prompt as *"a sledgehammer"* and *"Be VERY CAREFUL AND CONSERVATIVE"*.
- **Validation** (`prune-shared.js`):
  - Out-of-range, unknown, protected, file-protected and already-pruned IDs are skipped.
  - If none remain, the call throws *"Invalid IDs provided: [..]. Only use numeric IDs from the <prunable-tools> list."*
  - Otherwise the model gets a list of what was pruned plus *"Note: N IDs were skipped …"*.
- **Why 3.0 dropped it** (release notes; #387):
  - Choosing between three tools was hard for models.
  - Rewriting the `<prunable-tools>` block every turn cost cache.
  - Tool-only pruning left user and assistant text growing forever.

**Lesson for sugar-crush.** Tool-level drop or distil and range-level compress are complementary. sugar-crush stores **one history row per tool result** (`Chat::toolResultMessage`, `src/Chat.php:4017-4023`), so a single ID namespace naturally covers both granularities (§13.2).

### 4.15 Persistence and state hygiene

- One JSON file per session holds the prune maps, blocks, nudge anchors, stats and manual flag (`lib/state/persistence.ts:244-277`). It is reloaded and validated defensively (`loadPruneMessagesState`, `lib/state/utils.ts:118-289`).
- `messageIds` are *not* persisted. They are re-derived deterministically in message order.
- State files are never deleted when a session is deleted (#557).
- `syncCompressionBlocks` (`lib/messages/sync.ts:15-124`) runs on every request:
  - It deactivates a block whose origin compress message no longer exists, e.g. after a revert or fork. This is how "revert to a pre-compression message" naturally decompresses (#527).
  - It re-applies `consumedBlockIds` deactivation in creation order.

### 4.16 Known limitations and issues (from README and tracker)

| # | Problem | Lesson |
|---|---|---|
| #573 | **Compression snowball.** Each new block re-absorbed the previous block's placeholder plus a small new tail, so the summary grew monotonically to ~68k tokens (234k chars). The context-limit nudge fired 45 times, 71 blocks were created, and 738,738 tokens were burnt. | Add a size guard: refuse a summary that is not substantially smaller than what it replaces, and bound nested re-expansion. |
| #614 | A double-injected ID tag in mid-history broke the prefix cache permanently (95% → 55% hits). | Ref rendering must be a pure function of immutable data. |
| #615 | Providers that omit tool-call ids get AI-SDK fallbacks like `bash:0`, which repeat across messages. Pruning applied to the wrong calls, even removing outputs before the model read them. | Use harness-assigned, globally unique tool-call ids. |
| #520 | The nudge landed in the trailing assistant message; Anthropic rejected it as "prefill". | Never make the request end on a synthetic assistant row. |
| #551, #533 | Refs and blocks were wiped by host compaction (since fixed for blocks). | Keep the ledger across compaction and give accurate errors. |
| #549 | Hard cap of 9999 refs. | Use unbounded refs. |
| #608, #632, #555 | Models (especially GPT) copy `@N@`, `mNNNN</parameter>` and whole nudge blocks into their replies. | Strip both on output and on re-send. |
| #611 | Autonomous compression destroys still-needed detail under pressure. | Offer manual and auto modes, easy undo, and conservative defaults. |
| #595 | A resumed sub-agent's results were all rewritten. | Key by call, not by session. |
| #503 | Users worried that cost tracking becomes discontinuous (rebutted: cost sums per-step usage). | Show cumulative spend independently of context size. |
| #638 | A WASM tokenizer is constructed on every call (70 ms). | Cache the tokenizer. |
| #387 | Anthropic subscription: per-request rewrites killed the cache. | Batch mutations. |

---

## 5. Prompt generation (what DCP adds to each request)

- **System:**
  - the DCP block from §4.6, appended to the last system string (`lib/hooks.ts:96-104`);
  - plus the protected-tools, manual and sub-agent extensions;
  - skipped for internal title and summary calls.
- **Tool description:** the compress prompt plus the format block (§4.4/§4.5). These are reloaded from override files when `experimental.customPrompts` is on.
  - Override precedence: `.opencode/dcp-prompts/overrides/` > `$OPENCODE_CONFIG_DIR/dcp-prompts/overrides/` > `~/.config/opencode/dcp-prompts/overrides/` (`lib/prompts/store.ts:173-192`, `:400-420`).
  - Reminder prompts are auto-wrapped in `<dcp-system-reminder>`. The tool format schema is deliberately *not* editable (`lib/prompts/extensions/tool.ts:1-3`).
- **Mid-conversation:** ID tags on every message, anchored nudges, and synthetic summary user messages.
- DCP does not add env info, git, the file tree or memory; those come from opencode.

## 6. Memory

None. Compression blocks are session-scoped. A user comparing DCP to `cortexkit/magic-context` (#552) noted: *"MC vectorizes text and stores them in a separate database, then injects only the 'relevant' (most similar) entries into context … DCP simply summarizes the current context."* No retrieval of pruned content exists beyond user-driven `/dcp decompress`. magic-context's "rehydrate old chunks when referred to by number" is the obvious missing feature.

## 7. Tools and editing

- DCP's only tool is `compress`.
- It parses host tool parameters to find file paths, covering `read`/`write`/`edit` `filePath` or `path`, `apply_patch` headers, and `multiedit` entries, for file-glob protection.
- It leaves `edit`/`write` outputs alone.
- V2 "Code Mode" `execute` calls are protected when any nested call matches (`lib/protected-patterns.ts:139-158`).

## 8. Git integration

None. The plugin's only git interaction is incidental: reverting or forking a session to before a compress call deactivates the block (§4.15).

## 9. Extensibility

DCP is a case study of a plugin hook surface that sugar-crush does not have:
- a message-list transform called per LLM step and on the compaction input;
- a system-prompt transform;
- an output-text transform;
- command interception;
- event subscription;
- plugin tools;
- config mutation (inject commands, `primary_tools`, default permissions).

opencode also offers `experimental.session.compacting` (customise the compaction prompt) and `experimental.compaction.autocontinue` (`opencode/packages/plugin/src/index.ts:299-325`).

**DCP config layering:** `~/.config/opencode/dcp.jsonc` < `$OPENCODE_CONFIG_DIR/dcp.jsonc` < `.opencode/dcp.jsonc`. The project tier may set everything. Array keys union across layers (`config.ts:826-881`, `:957`). Unknown keys are warned about via toast.

## 10. Permissions and safety

- `compress.permission`: `allow` (default), `ask` or `deny`.
  - `deny` means the tool is not registered at all (`index.ts:83`).
  - An explicit host `permission.compress: deny`, global or per agent, is detected and honoured (`lib/host-permissions.ts:565-588`).
  - `ask` runs opencode's permission prompt with `always: ["*"]` (`pipeline.ts:50-55`).
  - On OpenCode V2, `ask` is unsupported and refuses with an explicit message (`lib/v2/index.ts:155-162`).
- Safety against prompt-injection or forged IDs:
  - only IDs present in the current context resolve;
  - `<dcp…>` tags in model output or tool output are stripped before re-sending (`stripHallucinations` runs first in the pipeline);
  - in message mode, block IDs are rewritten to `BLOCKED` inside rendered summaries (`replaceBlockIdsWithBlocked`).

## 11. UX

- **Notification after each compression** (`lib/ui/notification.ts:172-306`). It is sent as an opencode **"ignored" message** (`noReply: true, parts:[{ignored:true}]`), which the user sees and the model never does (`:308-347`). Alternatively it is a 5 s toast (`pruneNotificationType: "toast"`). `pruneNotification` is `off`, `minimal` or `detailed`. The detailed format:
  ```
  ▣ DCP | -48.2K removed, +3.1K summary
  │████████░░░░░░░░░░░░⣿⣿⣿⣿████████████████████████│      (█ active, ░ pruned, ⣿ just compressed)
  ▣ Compression #4 -12.3K removed, +1.2K summary
  → Topic: Auth System Exploration
  → Items: 23 messages and 31 tools compressed
  → Compression (~1.2K): <summary>                           (only with compress.showCompression)
  ```
- `/dcp stats` (`lib/commands/stats.ts`) shows the session's tokens in/out, compression ratio `N:1`, compression time, messages and tools, plus all-time tokens saved, tools pruned, messages pruned and sessions.
- `/dcp context` shows the category breakdown (§4.10). `/dcp sweep` lists what it pruned.
- **TUI panel** (`lib/tui/*`, `tui.tsx`): `/dcp` opens an OpenTUI panel with Context, Stats and a manual-mode toggle. Known issues: no keyboard navigation (#591) and no sweep/decompress buttons (#578).
- **Timing:** the event hook measures how long each compress call took and stores it on the block, for stats.

---

## 12. Comparison table

| Feature | DCP (opencode) | sugar-crush (baseline ref) | Gap |
|---|---|---|---|
| Non-destructive projection of history before each request | Yes, the core design (§4.1) | **ABSENT.** Compaction rewrites `Chat` history in place (§3.3); `Runtime::buildMessages` only sanitises (`src/Runtime.php:2768-2779`) | Large |
| Model-callable tool to compress or forget its own history | `compress` (range/message); 2.x `prune`/`distill` | **ABSENT** (§3.3, §6.2 "a context-pruning tool") | Large: the user's headline ask |
| Stable, model-visible message/tool IDs | `mNNNN`/`bN` tags, monotonic | **ABSENT.** `Message` has no id field (`src/Message.php:24-129`) | Large (prerequisite) |
| Harness-unique tool-call ids | No; relies on host, bug #615 | **ABSENT.** DSML/MiniMax parsers mint `dsml_call_<i>`/`minimax_xml_call_<i>` per response (`src/Providers/ToolCallParser/DsmlToolCallParser.php:335`, `MinimaxXmlFallbackToolCallParser.php:203`) | Large: the default DeepSeek-V4 path |
| Structured tool-call replay across turns | Host (opencode) does it | **PARTIAL.** Within a turn only; across turns plain assistant text (§0.3, `EngineBackend.php:2071-2082`) | Large |
| Context management *inside* a turn | Yes (per-step transform plus nudges) | **ABSENT.** Submit-time only (§3.3) | Large |
| Dedup of identical tool calls | Yes | ABSENT | Medium |
| Purge inputs of failed tools | Yes (after 4 turns) | ABSENT | Small |
| Supersede writes / stale reads | 2.x supersedeWrites | ABSENT | Medium (Write args are full files once replayed) |
| Age-based tool-output clearing | opencode native prune (40k protect / 20k minimum) | ABSENT (§3.3) | Medium |
| Context-pressure reminders | 3 kinds, min/max/frequency, anchored | **PARTIAL.** One 70% system row (`Chat.php:15312`), hoisted into the system message by SGLang | Medium |
| Absolute "smart-zone" thresholds (tokens or %), per model | Yes | **ABSENT.** % of window only (70/85/95; DeepSeek-V4 window 1,048,570 → first reminder at ~734k) | Medium |
| Token measure | Provider-reported last step | Estimate × calibration, ignores system and tools (§3.2) | Small/medium |
| Protected tools, files, `<protect>` spans | Yes | n/a | Medium (needed with any pruning) |
| Manual trigger with focus | `/dcp-compress <focus>` | PARTIAL: `/compact` without focus (§3.3) | Small |
| Undo a compression | `/dcp decompress` / `recompress` | PARTIAL: `/rewind` restores the whole transcript checkpoint (§8) | Medium |
| Compression notifications and stats | Detailed / minimal / toast, progress bar, all-time stats | PARTIAL: `contextCompactedMessage` savings notice (§3.3) | Small |
| UI-only notices (never sent to model) | "ignored" messages | **ABSENT.** All System rows go to the model and SGLang hoists them (§3.1; `SglangProvider.php:1596-1612`) | Medium |
| Sub-agent context management | Opt-in, task prompt protected | ABSENT. Task sub-agents run up to 50 steps with no compaction (§2.2) | Medium |
| Cache-aware mutation batching | Yes (§4.11) | PARTIAL: section stability; `CacheBreakpoints` DORMANT (§3.5) | Medium |
| Compaction hook for extensions | Host `experimental.session.compacting`; DCP V2 hooks compaction | DORMANT: `PreCompact` hook event has no dispatch site (§9.3) | Small (wire it) |
| Ledger persistence | Per-session JSON | Transcript only, in `session.db` (§8) | Part of the design |

---

## 13. Recommended improvements for sugar-crush

### 13.1 Prioritised list

**P0-1. Stable per-row and per-tool-call identity (prerequisite for everything else). Effort M.**
- *Idea:* every history row and every tool call gets a harness-assigned, globally unique, persisted id, plus a short model-visible ref.
- *Why:* DCP depends on stable `mNNNN` handles. Its worst bugs (#615, #614, #551, #549) are identity bugs. sugar-crush's default DeepSeek-V4 path mints `dsml_call_0`, `dsml_call_1`, … per response, so ids repeat across steps.
- *DCP:* `lib/message-ids.ts:127-181` (monotonic refs keyed by the host's unique message id).
- *sugar-crush:*
  - Add `id`/`ref`/`stepId` to `src/Message.php`, and round-trip them in `jsonSerialize`/`fromArray` (`:520-656`).
  - Add a `Support\ToolCallIdAllocator` used in `Runtime` right after an `AssistantMessage` is parsed, before `executeToolCalls` (`src/Runtime.php:1650`). It rewrites any empty or non-unique provider id to `tc_<sessionShort>_<seq>`.
  - Carry the ref in the `started`/`finished` frames (`EngineBackend.php:1892-1912`).
  - Details in §13.2 A.

**P0-2. Structured cross-turn replay of tool calls. Effort M.**
- *Idea:* rebuild `AssistantMessage(toolCalls)` and `ToolResultMessage(id)` pairs from Chat rows instead of plain assistant text.
- *Why:* the model loses which tool produced which text (baseline §0.3). Pruning placeholders such as "output of Read src/X pruned" only make sense when the call and its arguments are visible.
- *DCP:* gets this from opencode's parts model.
- *sugar-crush:*
  - Rewrite `EngineBackend::toTypedMessages()` (`src/Backend/EngineBackend.php:2071-2083`). Chat rows already persist `toolResults[].id/name/arguments` (`src/Message.php:541-555`), so the data is there.
  - Group rows by the new `stepId` so parallel calls replay as one assistant message.
  - Unanswered placeholders are handled by the existing `HistorySanitizer` (`src/Messages/HistorySanitizer.php:80-141`).
- *Note:* this *increases* tokens, because arguments come back (Write `content`!). That is why P0-3 must ship with it.

**P0-3. Context ledger and projector, with automatic zero-cost strategies. Effort M/L.**
- *Idea:* DCP's non-destructive "transform before send", applied at `Runtime::buildMessages()` on every step, with dedup, stale-read and superseded-write-input pruning and errored-input purging.
- *Why:* it gives in-turn context relief and needs no model cooperation, so it is the cheapest big win.
- *DCP:* `lib/hooks.ts:107-164`, `lib/messages/prune.ts`, `lib/strategies/*`, 2.x `supersede-writes.js`.
- *sugar-crush:* new `src/Context/Pruning/*` (§13.2 B-E). Hook it into `Runtime::buildMessages` and into `Chat`'s token estimate and compaction input.

**P0-4. Agent-callable `Prune` and `Compress` tools. Effort L.**
- *Idea:* the user's headline feature. The model drops or distils finished tool outputs (`Prune`) and replaces closed ranges with its own summaries (`Compress`, with nested blocks). Applied immediately, within the turn.
- *Why:* compaction currently happens only at submit, through a separate cold-cache summariser, with no knowledge of what is "finished". DCP's #387 argument: the main model summarising on a warm cache is cheaper than a second model reading everything cold.
- *DCP:* §4.4/§4.5/§4.14.
- *sugar-crush:* new tools bound per turn through a new `Tools\MutatesContextLedger` interface in `EngineBackend::turnTools()` (`:1049-1087`). A new `ledger` fork frame carries the changes to `Chat` (§13.2 F-H).

**P1-5. Pressure-graded, anchored nudges with absolute thresholds. Effort M.**
- *Why:* with a 1M window, sugar-crush's 70% reminder fires at ~734k tokens, far past the quality "smart zone". DCP's default is 50k/100k absolute.
- *DCP:* §4.7.
- *sugar-crush:*
  - Add `Context\Pruning\NudgePolicy`. Fold `Chat::contextReminderMessage()` (`:15312`) into it as the "turn" nudge.
  - Inject the nudge into the newest tool-result or user message, **never** as a trailing assistant or system row.

**P1-6. UI-only notice rows. Effort S.**
- *Why:* DCP's "ignored" messages show compression receipts without polluting context. In sugar-crush every System row (launch notices, cancel and compaction notices, the reminder) is sent to the model, and `SglangProvider::formatMessages` hoists all of them into the **leading** system message (`src/Providers/SglangProvider.php:1596-1612`).
- *sugar-crush:* add a `Message::$uiOnly` flag (or `Role::Notice`). `EngineBackend::toTypedMessages` skips flagged rows. Use it for all compaction, prune and cancel notices.

**P1-7. Commands: `/context`, `/compress [focus]`, `/decompress [b]`, `/recompress [b]`, `/sweep [n]`, `/pruning auto|manual|off`. Effort M.**
- *DCP:* `lib/commands/*`.
- *sugar-crush:* add the commands to `Chat::dispatchCommand` (`src/Chat.php:8240`) and `CommandRegistry`. `docs/COMMANDS.md` and the README roster are drift-tested.

**P1-8. Sub-agent self-pruning. Effort S/M** (after P0-4).
- *Why:* `TaskTool::runOnEngine` runs up to 50 steps (`TaskTool.php:135,453`) with zero context management.
- *DCP:* `SUBAGENT_SYSTEM_EXTENSION`, first user message unprunable.
- *sugar-crush:* an ephemeral ledger per sub-agent run, and the task prompt pinned. Serialise the ledger into `SuspendedDelegations` so resume keeps it.

**P1-9. Run compaction on the projected view; wire `PreCompact`. Effort S.**
- *DCP:* opencode runs the transform on the compaction input (`compaction.ts:379`), and #577 asks for compaction hooks.
- *sugar-crush:*
  - Feed `ContextProjector` output to `exchangesToSummarize()` and `scheduleParkedCompaction()` (`Chat.php:10975`).
  - Dispatch the DORMANT `HookEvent::PreCompact` before both Chat compaction and agent `Compress`.

**P2-10. Cache-health telemetry after compressions. Effort S.**
- *DCP:* #614 was only diagnosed from cache-hit telemetry.
- *sugar-crush:* wire the DORMANT `CacheBreakpoints::observeCacheHealth()` (`src/Providers/CacheBreakpoints.php:342-366`) into usage accounting (`Chat::turnEstimateObservation`, `:14944`). Add a `cached_tokens / prompt_tokens` ratio to `/context`. When wiring `CacheBreakpoints::apply()` for Bedrock and Vertex, place a breakpoint just before the newest compression anchor.

**P2-11. Group pruned targets with the dormant `Compactor`. Effort S.**
- *sugar-crush:* `src/Compactor.php` and `CompactedGroup.php` group file paths by category, e.g. "code ×12, config ×3".
- Use them to render the `/sweep`, `Prune` and `/context` "pruned items" lists and the compression notice, instead of listing every path. This wires dormant code without inventing new code. It is a filesystem helper (`is_file`/`filesize`), so it suits the UI only, never the prompt.

**P2-12. Recall of pruned content. Effort M.**
- *Why:* neither DCP nor sugar-crush can re-surface a pruned output on demand (magic-context can, #552).
- *sugar-crush:* add a `Recall` tool (`{ref}`) that returns the raw stored content of a pruned row or block. The raw history is kept, because projection is non-destructive. Gate it to N calls per turn.

### 13.2 Detailed implementation design: agent-driven self-pruning and compaction

The design follows the project rules: immutable `with*()`/`mutate()`, one type per PSR-4 file, `::new()` factories, drift-tested docs, and wiring dormant code rather than deleting it.

#### A. Identity: data-model changes

1. **`src/Message.php` (Chat row).** Add three constructor fields:
   - `public readonly ?string $id = null`: storage id, `r_<16hex>` from `bin2hex(random_bytes(8))`.
   - `public readonly ?int $ref = null`: the model-visible short ref, monotonic per session and never reused.
   - `public readonly ?string $stepId = null`: which engine step produced the row. Parallel tool results share it, and the step's assistant narration lives on the first row.

   Also add `public readonly bool $uiOnly = false` (P1-6). Extend `jsonSerialize()`/`fromArray()`.
   - **Legacy transcripts:** `Chat` assigns `id`/`ref` in order on load and re-persists, so refs are stable from then on. Refs are never derived from position after first assignment, which avoids the DCP #614/#551 failure class.
   - Every `with*()` copier in `Message` must carry the new fields. A test asserts that each `with*` method preserves them.
2. **Ref allocation.**
   - `Chat` owns `nextRef` (persisted in the ledger, §B) and assigns refs to user rows at `Chat::submit()`.
   - The **turn child** assigns refs to rows it creates (assistant steps, tool results). `EngineBackend::completeAsync()` receives `nextRef` and returns the new high-water mark in the `result` frame. The `started` frame carries `ref` and `stepId`, so `Chat` stamps the placeholder row with the child's ref. `Chat::toolResultMessage()` (`src/Chat.php:4017`) copies them onto the result row.
3. **Tool-call ids.**
   - New `src/Support/ToolCallIdAllocator.php` with `assign(AssistantMessage $m): AssistantMessage`. It rewrites `''`, duplicates within the session, and the known per-response patterns (`dsml_call_\d+`, `minimax_xml_call_\d+`) to `tc_<sessionShort>_<seq>`. `sessionShort` comes from the session id so ids stay unique across `/branch` forks.
   - Call it in `Runtime::runStreaming()`/`runBatch()` before tool execution. `HistorySanitizer` and the fork frames then only ever see unique ids.
   - Anthropic/Bedrock id charset `[A-Za-z0-9_-]` is satisfied.
4. **Typed messages** (`src/Messages/*`). Add optional `?int $ref` (and `?string $stepId`) to `UserMessage`, `AssistantMessage` and `ToolResultMessage`, with accessors `ref()`/`stepId()`. Providers ignore them. Only the projector reads them.

#### B. The ledger: `src/Context/Pruning/ContextLedger.php` and friends

One type per file, all `final readonly`:

| Class | Fields / purpose |
|---|---|
| `ContextLedger` | `prunes: array<int ref, PruneEntry>`, `blocks: array<int blockId, CompressionBlock>`, `nudges: NudgeAnchors`, `stats: PruneStats`, `nextRef`, `nextBlockId`, `mode: PruningMode`. Methods: `withPrune()`, `withBlock()`, `withBlockDeactivated(int, bool byUser)`, `withNudges()`, `apply(LedgerDelta)`, `toArray()`/`fromArray()` (lenient like DCP's `loadPruneMessagesState`) |
| `PruneEntry` | `ref`, `kind` (`Output`, `Distilled`, `Inputs`, `ErroredInputs`), `reason` (`Noise`, `Superseded`, `Duplicate`, `StaleRead`, `Errored`, `Done`), `by` (`Model`, `Strategy`, `User`), `distillation ?string`, `supersededByRef ?int`, `tokens`, `createdAt`, `originRef` (the Prune call's own row) |
| `CompressionBlock` | DCP's `CompressionBlock` (§4.4 step 8), with refs instead of message ids: `id`, `topic`, `fromRef`, `toRef`, `anchorRef`, `summary`, `consumedBlockIds`, `parentBlockIds`, `active`, `deactivatedByUser`, `compressedTokens`, `summaryTokens`, `originRef`, `createdAt` |
| `NudgeAnchors` | `limit: list<int>`, `turn: list<int>`, `iteration: list<int>` |
| `LedgerDelta` | An ordered list of ops (`AddPrune`, `AddBlock`, `DeactivateBlock`, `SetNudges`, `BumpRefs`) that can be applied idempotently. It crosses the fork socket |
| `PruningMode` (enum) | `Auto`, `Manual`, `Off` |

**Persistence.** Add a `context_ledgers(session_id TEXT PRIMARY KEY, ledger_json TEXT, updated_at INT)` table to `EnhancedSessionStore` (`src/Session/EnhancedSessionStore.php:140-210`), with `saveLedger()`/`loadLedger()`.
- `Chat::persistTranscript()` (`:1512`) also saves the ledger.
- `forkSession` copies the ledger, and `saveCheckpoint` snapshots it, so `/rewind` and `/branch` restore matching pruning state.
- The ledger is per session; a deleted session deletes its ledger, which DCP lacks (#557).

**Sync** (`ContextLedger::syncAgainst(array $rows)`), DCP's `syncCompressionBlocks`:
- A block or prune whose `originRef` row no longer exists becomes inactive. This happens after `/rewind`, or when Chat compaction dropped the row.
- Blocks whose range rows were summarised away by Chat compaction become inert.
- Refs are never reused.

#### C. The projector: `src/Context/Pruning/ContextProjector.php`

`ContextProjector::new(PruningPolicy $policy)->project(array $typedMessages, ContextLedger $ledger): ProjectedContext` returns the messages plus a `projectedTokens` estimate. It is a pure function, so tests can pin byte stability. Steps, in order (modelled on `lib/hooks.ts:133-161`):

1. **Strip echoed refs** from assistant text: `RefTag::stripFrom()` removes `<ctx-ref …/>` patterns, the analogue of DCP's `stripHallucinations`.
2. **Apply blocks.** Drop every row whose ref lies inside an active block. At `anchorRef`, insert a synthetic **`UserMessage`**:
   ```
   [Compressed section b3: "Auth system exploration" — replaces r12…r40]
   <summary>
   ```
   - Merge it into the following user message if two user roles would end up adjacent, since some chat templates reject that.
   - Do **not** use `SystemMessage`: SglangProvider would hoist it to the top.
3. **Apply prunes.** Replace a `ToolResultMessage` content with a precise placeholder that is better than DCP's generic one:
   - `[r17 Read src/Tools/Bash.php — output pruned (superseded by r31). Re-run the tool if you need it.]`
   - distilled: `[r17 Read src/Tools/Bash.php — distilled]\n<distillation>`
   - errored inputs: the AssistantMessage tool-call `arguments` string values become `"[input removed: call failed, error kept]"`
   - superseded Write input: `content` → `"[file content elided: src/X.php was re-read at r44]"`
4. **Attach ref tags.**
   - Append `\n<ctx-ref r="17"/>` to each `ToolResultMessage` and each `UserMessage` that has a ref.
   - For assistant steps, the ref rides on that step's first tool result.
   - These are deterministic, cheap and once per row.
   - Rows inside the *current* step get tags too, so the model can prune a just-finished exploration batch.
5. **Apply nudges** at anchored refs (§E). Append to a `ToolResultMessage` or `UserMessage` content only.
6. Return the result. `HistorySanitizer::sanitize()` runs afterwards as today.

**Wiring:**
- `Runtime::buildMessages()` (`src/Runtime.php:2768-2779`) becomes `HistorySanitizer::sanitize($this->projector->project($messages, $app->contextLedger)->messages)`.
- `App` gets `withContextLedger()`.
- `Chat::rawTokenProxy()`/`estimateTokenCount()` (`:14709-14742`) measure the **projected** view, so thresholds fall after pruning. DCP's #536 is the cautionary tale: a stuck token count meant nudges never fired.
- `Chat` compaction's `exchangesToSummarize()` input and the `scheduleParkedCompaction()` summary request also take the projected view (P1-9).

**Cache contract** (documented and tested):
- (a) Ref tags, placeholders and nudges are pure functions of immutable row data plus the ledger.
- (b) The ledger only changes at three points:
  1. turn start: `Chat::submit()` runs strategies;
  2. a model `Prune`/`Compress` call;
  3. an over-max emergency inside a turn.
- (c) The bytes before the earliest changed ref are identical across requests.

#### D. Automatic strategies: `src/Context/Pruning/Strategies/*`

Each strategy implements `PruningStrategy::propose(array $typedMessages, ContextLedger $ledger, PruningPolicy $p): LedgerDelta`:

| Strategy | Rule | Default |
|---|---|---|
| `DuplicateCallStrategy` | Same tool and canonical arguments (sorted keys, nulls dropped, the `description` arg ignored); keep the newest. Read-only tools (`Read`, `Glob`, `Grep`, `WebFetch`, `WebSearch`, `Lsp`) plus `Bash` | on |
| `StaleReadStrategy` | `Read` of path P followed later by `Edit`/`Write` of P, or another `Read` of P | on. **DCP lacks this**, and it is high-yield for coding agents |
| `SupersededWriteInputStrategy` | `Write` `content` / `Edit` `old_string`+`new_string` arguments, once a later `Read`/`Write` of P exists (2.x supersedeWrites, extended to Edit) | on |
| `ErroredInputStrategy` | A failed call older than N user turns: blank its string arguments | on, N = 4 |
| `ToolOutputAgeStrategy` | opencode-native style: protect the newest 40k tokens of tool output and the last 2 user turns; prune older outputs once total savings exceed 20k | **only at the over-max emergency** |

`PruningPolicy::protectedTools` defaults to `Task`, `Skill`, `Prune`, `Compress`, `Edit`, `Write` outputs (DCP's set, adapted). Add `protectedFilePatterns` globs, via the existing `Tools\IgnoreRules`/fnmatch helpers.

**Run points:**
- `Chat::submit()`, before `dispatchTurn()` (`src/Chat.php:7150`, `:7869`), when mode ≠ Off. This is a turn boundary: the cache breaks once.
- Inside `ExecutesContextOps`, whenever the model calls `Prune`/`Compress`.
- In `EngineBackend::runTurn()` between steps (`:974-982`), only when the projected size exceeds `maxContextTokens`: the emergency path.

#### E. Nudges: `src/Context/Pruning/NudgePolicy.php`

**Inputs:**
- the projected token count, using the provider-reported `Usage->promptTokens` of the last step when available, as DCP does;
- `minContextTokens` (default **60000**) and `maxContextTokens` (default **120000**), each accepting `"N%"`, plus a `summaryBuffer`;
- `nudgeFrequency` **5** and `iterationNudgeThreshold` **10**. sugar-crush counts tool rows, which are finer-grained than DCP's messages.

**Texts:** adapt DCP's three nudges (§4.7) and wrap them in `<context-reminder>`. These replace `Chat::contextReminderMessage()`'s System row; keep the wording constant in one place and reflection-test it like `CONTEXT_REMINDER_PREFIX`.

**Rules:**
- Anchors persist in the ledger and are re-rendered at the same ref (cache-stable).
- Clear all anchors after a successful `Compress`/`Prune` (cooldown).
- **Never** append to an assistant message and never create a trailing assistant row (DCP #520).
- Manual mode disables nudges. Strategies keep running unless `strategiesInManual = false`.

#### F. The tools

Both tools live in `src/Tools/BuiltIn/` and implement `Tool`, `PromptGuidance` and a new `Tools\MutatesContextLedger` (`withLedger(\Closure $read, \Closure $apply): Tool`).
- `EngineBackend::turnTools()` (`:1080-1084`) binds them to the turn's ledger the same way `DelegatesToEngine` is bound.
- They are **not** `ParallelSafe`. They must run in the turn child that owns the ledger, not in a forked parallel child (`Runtime::executeConcurrently`, `src/Runtime.php:1853`).
- They are registered in `Bootstrap::unfilteredTools()` (`src/Cli/Bootstrap.php:6815-6937`) when `contextPruning.mode ≠ off`, and are subject to `allowedTools`/`disabledTools`.

**`Prune`** (2.x `prune`+`distill`, merged):

```json
{
  "type": "object",
  "required": ["targets", "description"],
  "properties": {
    "description": {"type": "string", "description": "5-10 word label shown in the transcript"},
    "reason": {"type": "string", "enum": ["noise", "superseded", "done"]},
    "targets": {
      "type": "array", "minItems": 1,
      "items": {
        "type": "object", "required": ["ref"],
        "properties": {
          "ref": {"type": "string", "description": "A tool-result ref such as r17, copied from <ctx-ref r=\"17\"/>"},
          "distillation": {"type": "string", "description": "Optional. Complete technical substitute for the output. Omit to drop the output entirely."}
        }
      }
    }
  }
}
```

Description (adapted from 2.x `prune.md`/`distill.md`):

> Remove or distill tool outputs you are finished with. Each tool result carries a `<ctx-ref r="N"/>` tag; pass `rN`. Without `distillation` the output is replaced by a one-line placeholder that keeps the tool name and its main argument (you can re-run the tool). With `distillation`, your text replaces the output — make it complete: signatures, values, paths, exact error strings. Do NOT prune output you will edit against or quote exactly in the next steps. Batch several targets per call; a single tiny output is not worth a call. Parallelise this call with your next real tool calls rather than making it your only action.

- **Validation:**
  - an unknown, non-tool, protected, already-pruned or current-call ref becomes a soft issue (DCP message-mode style, §4.5);
  - a `distillation` at least as long as the raw output is rejected;
  - the call throws only when nothing applied.
- **Result:** `Pruned 4 outputs (~18.2K tokens): read ×3, grep ×1. Skipped: r9 (protected: Task).` Use `Compactor` for the grouping (P2-11).

**`Compress`** (DCP v3 range mode):

```json
{
  "type": "object",
  "required": ["topic", "ranges", "description"],
  "properties": {
    "description": {"type": "string"},
    "topic": {"type": "string", "description": "3-5 word label, e.g. 'Auth system exploration'"},
    "ranges": {
      "type": "array", "minItems": 1,
      "items": {
        "type": "object", "required": ["from", "to", "summary"],
        "properties": {
          "from": {"type": "string", "description": "First ref of the range: rN or a block bN"},
          "to": {"type": "string", "description": "Last ref of the range: rN or bN"},
          "summary": {"type": "string", "description": "Exhaustive technical summary replacing everything in the range; include each covered (bN) exactly once"}
        }
      }
    }
  }
}
```

- **Description:** reuse DCP's range prompt (§4.4) nearly verbatim. It is well-tuned and its placeholder rules are needed. Add sugar-crush-specific rules:
  - *"Never include the newest user message or your current step."*
  - *"Outputs of Task and Skill, and rows the user wrapped in `<protect>`, are re-attached automatically — do not restate them."*
- **Validation:**
  - DCP's set: boundaries exist, are ordered, do not overlap within the batch, and every placeholder is known, required and unique; missing placeholders are auto-appended; boundary blocks are auto-injected.
  - **Plus a size guard against DCP #573:** reject when `summaryTokens > 0.5 × newlyCompressedTokens + 2000`. Error: *"Summary (~N tokens) is not much smaller than the content it replaces (~M tokens); compress a larger closed range or write a tighter summary."*
  - **Plus a nesting guard:** refuse when expanding placeholders would push the stored block above `maxBlockTokens` (default 16k). Instead tell the model to leave the earlier block standalone: `from` must start after it.
- **Protected content:** appended verbatim as in DCP `appendProtectedTools` (§4.4 step 6). Task results, Skill bodies, rows marked `<protect>`, and user rows when `protectUserMessages` is set.
- **Result:** `Compressed 23 rows (~41.0K tokens) into block b3 (~2.4K tokens).`

**Both tools:**
- Dispatch the DORMANT `HookEvent::PreCompact` (§9.3), and refuse when a hook denies.
- Refuse in manual mode unless a `/compress` trigger is pending (DCP `pipeline.ts:44-48`).

#### G. Crossing the fork boundary

- **New event** `src/Events/ContextLedgerChanged.php` (`LedgerDelta $delta`), emitted through `$onEvent` by the tools and by the emergency strategy run.
- **Encoding:**
  - `EngineBackend::encodeEvent()` (`:1870-1913`) gains `kind: 'ledger'` carrying `delta->toArray()`.
  - `decodeEvent()` (`:1923-1990`) validates it strictly, as it already does for `subagent`.
  - The `result` frame (`:1700-1719`) also carries the final `ledgerHighWater` (`nextRef`, `nextBlockId`) so a lost frame cannot desynchronise refs.
- **Chat:**
  - The tool-event pump applies `ContextLedgerChanged` to `Chat`'s ledger immediately, so the UI can dim rows mid-turn, then persists.
  - Add a Msg `src/ContextLedgerUpdatedMsg.php` for the blocking (no-pcntl) path, `completeAsyncBlocking` (`:1998`).
  - `HistoryCompactedMsg` (`src/HistoryCompactedMsg.php:112-118`) stays the carrier for *Chat-initiated* LLM compaction. When that compaction drops rows, `Chat` calls `ContextLedger::syncAgainst()`.
- **In-turn effect.** In `runTurn()`, the child keeps a local `$ledger`. After each step it sets `$app = $app->withMessages([...])->withContextLedger($ledger)` (`:978-982`). Step *k+1*'s `buildMessages()` projects the compressed view, so in-turn compaction works without a second model.

#### H. Chat integration and UI

- `Chat` gains a `ContextLedger $contextLedger` property, loaded in `Bootstrap::chat()` with the session and changed only via `mutate()`.
- **Transcript** (`src/Renderer.php`):
  - pruned tool rows render dimmed with a `pruned`/`distilled` badge;
  - rows inside an active block collapse into one row, `▣ Compressed b3 · Auth system exploration · −41.0K +2.4K` (Ctrl+O expands to the summary, and to the raw rows on a second press);
  - every receipt is a `uiOnly` notice row in DCP's detailed format, including the `│███░░⣿│` bar.
- **Status bar:** `ctx 38% (−52K pruned)`.
- **Commands** (P1-7):

  | Command | Behaviour |
  |---|---|
  | `/context` | Category breakdown as in DCP §4.10, plus cache-hit ratio |
  | `/compress [focus]` | Sends DCP's `COMPRESS_TRIGGER_PROMPT` plus focus as the user turn. It works in manual mode and allows exactly one call |
  | `/decompress [bN]` / `/recompress [bN]` | Flip `deactivatedByUser`. Refuse when an active ancestor consumed the block |
  | `/sweep [n]` | Prune tool outputs since the last user message, or the last *n* |
  | `/pruning auto\|manual\|off` | Persisted per session |

  Docs: `docs/COMMANDS.md` and the README roster (drift-tested by `ReadmeRosterDriftTest::testTheSlashCommandRosterIsExactlyWhatTheRegistryAdvertises`).
- **Config:** a `contextPruning` object in `src/Config/LayeredSettings.php`, user tier: `mode`, `minContextTokens`, `maxContextTokens`, `nudgeFrequency`, `iterationNudgeThreshold`, `protectedTools`, `protectedFilePatterns`, `strategies.{duplicates,staleReads,supersededWrites,erroredInputs:{turns}}`, `subAgents`, `maxBlockTokens`.
  - The project tier may only *add* protections.
  - New env var `SUGARCRUSH_CONTEXT_PRUNING=auto|manual|off`, which must be added to `docs/ENVIRONMENT.md` (`EnvRosterDriftTest`).
  - **Recommended default: `auto` for strategies, `manual` for `Compress`** (the #611 lesson) until evals show the model compresses sensibly. `Prune` is safe enough for `auto`.

#### I. Sub-agents

- `TaskTool::runOnEngine()` (`src/Tools/BuiltIn/TaskTool.php:430-630`) creates an **ephemeral** `ContextLedger` for the child run. Its ref namespace is private and it is not persisted.
- The sub-agent's first `UserMessage` (the task) gets no ref, so it is unprunable, mirroring DCP's sub-agent extension.
- `Prune`/`Compress` are included in the sub-agent's tool grant unless the preset's `tools:` omits them.
- `SuspendedDelegations` serialises the ledger with the transcript (`src/Agents/SuspendedDelegations.php:42-111`, adding `ContextLedger` to its allow-listed classes) so `resume` continues with the same pruned view.

#### J. Tests to add

`tests/` mirrors `src/`. New files must be added to `scripts/parallel-tests-durations.tsv`, and `sugar-crush/tests/Config/Support/suite-figure.json` refreshed.

| Test file | What it pins |
|---|---|
| `tests/MessageIdentityTest.php` | `id`/`ref`/`stepId`/`uiOnly` survive every `with*()` and the `jsonSerialize`→`fromArray` round trip; legacy rows get refs once and keep them |
| `tests/Support/ToolCallIdAllocatorTest.php` | Two DSML responses that each yield `dsml_call_0` become distinct `tc_…` ids; provider-unique ids are preserved; charset is valid |
| `tests/Backend/EngineBackendStructuredReplayTest.php` | Chat tool rows → `AssistantMessage(toolCalls)` + `ToolResultMessage` pairs; parallel rows with one `stepId` → one assistant message; `uiOnly` rows skipped; unfinished placeholder → sanitizer's interrupted result |
| `tests/Context/Pruning/ContextLedgerTest.php` | Immutability; `apply(LedgerDelta)` is idempotent; lenient `fromArray`; `syncAgainst` deactivates orphaned blocks and prunes |
| `tests/Context/Pruning/ContextProjectorTest.php` | Block → synthetic user summary at the anchor, range rows dropped; adjacent-user merge; placeholder formats; **byte-identical output for two projections of the same input** (cache-stability golden); ref tags once per row; echoed `<ctx-ref>` stripped from assistant text |
| `tests/Context/Pruning/Strategies/DuplicateCallStrategyTest.php`, `StaleReadStrategyTest.php`, `SupersededWriteInputStrategyTest.php`, `ErroredInputStrategyTest.php`, `ToolOutputAgeStrategyTest.php` | One file per rule; protected tools and globs are respected; the newest copy is kept |
| `tests/Context/Pruning/NudgePolicyTest.php` | Below min: none. Between min and max: turn nudge at a new user turn; iteration nudge after the threshold, spaced by frequency. Over max: limit nudge. Anchors replay at the same ref; cleared after Compress; **never placed on an assistant message** (DCP #520); manual mode disables |
| `tests/Tools/BuiltIn/PruneToolTest.php` | Drop vs distil; soft issues (unknown, protected, already pruned, current call); a distillation longer than the raw output is rejected; result text; `PreCompact` deny refuses |
| `tests/Tools/BuiltIn/CompressToolTest.php` | Unknown/reversed/overlapping boundaries; placeholder required/duplicate/unknown; auto-append of missing blocks; consumed blocks deactivated; **size guard (DCP #573)**; nesting cap; multi-range batch; protected Task output appended verbatim; manual-mode refusal |
| `tests/Backend/EngineBackendLedgerFrameTest.php` | `ledger` frame encode/decode round trip; malformed frames dropped; the `result` frame carries the high-water mark |
| `tests/Backend/InTurnCompressionTest.php` | A scripted fake provider calls `Compress` at step 3; the captured `CompleteRequest` at step 4 has fewer messages and contains the summary; Chat's ledger received the delta |
| `tests/Chat/ContextCommandsTest.php` | `/context`, `/compress focus`, `/decompress`, `/recompress`, `/sweep`, `/pruning`; the ledger persists with the transcript; `/branch` copies it; `/rewind` restores the checkpointed ledger |
| `tests/Chat/ProjectedTokenEstimateTest.php` | The estimate drops after a prune; compaction input is the projected view |
| `tests/Tools/BuiltIn/TaskToolLedgerTest.php` | The sub-agent prompt cannot be pruned; the ledger survives a `SuspendedDelegations` resume |
| `tests/Providers/SglangSystemRowHoistTest.php` | `uiOnly` notices never reach `formatMessages` (guards P1-6 and §14 item 3) |

Drift updates: the README tool roster (`ReadmeRosterDriftTest::testTheCapabilitiesToolRosterNamesEveryToolALaunchShips`), `docs/COMMANDS.md`, `docs/ENVIRONMENT.md`, and `docs/PROMPT_ENGINEERING.md` (new reminder text and the ref tag).

#### K. Rollout order

| Phase | Contents | Effort |
|---|---|---|
| 1 | A + P0-2 structured replay + P1-6 `uiOnly` | M |
| 2 | B + C + D (strategies at turn start only) + `/context`, `/sweep` | M |
| 3 | `Prune` tool + G (fork frames) + H transcript badges | M |
| 4 | `Compress` tool + blocks + `/compress`, `/decompress`, `/recompress` + E nudges | L |
| 5 | I sub-agents, P2-10 cache telemetry, P2-11 Compactor wiring, P2-12 `Recall` | S/M each |

---

## 14. Problems in sugar-crush exposed by this comparison

1. **Tool-call ids are not unique on the default model path.** DeepSeek-V4 uses the DSML parser, which mints `dsml_call_<index>` per response (`src/Providers/ToolCallParser/DsmlToolCallParser.php:335`). MiniMax mints `minimax_xml_call_<index>` (`MinimaxXmlFallbackToolCallParser.php:203`). Steps 1 and 2 of one turn can both contain `dsml_call_0`.
   - `HistorySanitizer` keys `callIds`/`answeredIds` by id (`src/Messages/HistorySanitizer.php:82-96`). A second step's unanswered call can be marked "answered" by the first step's result, and OpenAI-compatible servers receive duplicate `tool_call_id`s.
   - Any future id-keyed feature (pruning, structured replay, `Chat` placeholder matching by `pendingToolCallId`) inherits DCP's bug #615.
   - *Inferred from code; not reproduced.*
2. **Cross-turn tool replay is lossy** (baseline §0.3, confirmed at `EngineBackend.php:2071-2083`). The data needed to fix it is already persisted (`Message::jsonSerialize` keeps `toolResults[].id/name/arguments`, `src/Message.php:541-555`). It is only discarded at conversion time.
3. **Every in-history System row is hoisted into the leading system message on SGLang** (`src/Providers/SglangProvider.php:1596-1612`).
   - That covers the 70% context reminder, compaction notices, `_Request cancelled._`, launch notices and spend-cap notices.
   - Any new such row changes message 0, so the whole conversation misses SGLang's radix prefix cache, and the row loses its chronological position.
   - DCP's design shows how much effort the cache needs. sugar-crush's own "Static → PerSession → PerTurn" ordering cannot help when message 0 changes.
4. **The volatile `<env>` block sits inside message 0.** It is re-rendered every step, includes git status, and adds diffs after any write step (baseline §4 slot 11).
   - Section ordering keeps the *system prompt's* prefix stable. But the conversation history comes **after** message 0, so a change in `<env>` (an edit makes `git status` and `git diff` change) probably forces a full re-prefill of the history on the next step.
   - *Inferred from reading; measure with `prompt_tokens_details.cached_tokens` across a write step.* If confirmed, move `<env>` deltas into the newest user or tool message (DCP-style anchored injection) and keep message 0 static per session.
5. **Context management never happens inside a turn** (baseline §3.3), and **Task sub-agents (up to 50 steps) have none at all.** DCP shows the fix is a per-step projection, not a bigger summariser.
6. **Thresholds are percent-of-window only.** With DeepSeek-V4's 1,048,570-token window, the first reminder comes at ~734k tokens and compaction at ~891k, far beyond where coding quality holds. DCP's absolute 50k/100k "smart zone" defaults, overridable per model, are the better model.
7. **The token estimate ignores the system prompt and tool schemas** (baseline §3.2). It is calibrated by ratio, but a large `<env>` diff or MCP tool set shifts the true size without changing the estimate. DCP uses the provider-reported totals of the last step.
8. **`ContextCompactor` treats every tool row as its own "exchange"** (baseline §3.3 points 1-2). `removeToolResults()` matches a wire shape that is never produced. Pruning old tool outputs, the single most effective cheap reduction in both DCP and opencode-native, is therefore effectively impossible today.
9. **Only the last assistant step's text survives the turn** (`EngineBackend::runTurn()` returns `$lastAssistant?->content()`, `:985`, `:1019`). Interim narration such as "I'll check X because Y" is not in Chat history, so the next turn loses the model's own rationale for its tool calls. Structured replay (P0-2) should restore it via `stepId`.
10. **No UI-only channel for notices.** Every receipt the UI shows also costs context and, on SGLang, cache (item 3). DCP's "ignored message" pattern is a missing primitive.
11. **Dormant pieces that this design would wire rather than duplicate:**
    - `HookEvent::PreCompact` (no dispatch site);
    - `CacheBreakpoints::observeCacheHealth()`/`apply()`;
    - `Compactor`/`CompactedGroup` (UI grouping);
    - `ContextCompactor::compactSkills()` (it could become a strategy that clears `Skill` bodies the model already applied; protect by default, prune on request).
