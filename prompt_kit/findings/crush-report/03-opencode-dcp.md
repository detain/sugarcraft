# 03 — opencode-dynamic-context-pruning (DCP) vs sugar-crush

Feeds steps: 0.2, 1.A-1, 1.B-1, 1.B-2, 1.B-3, 2.1, 2.2-1, 2.2-2, 2.3, 2.4-1, 2.4-2, 2.9, 2.12, 3.B-2, 3.B-3, 3.B-4, 3.B-5, 5.6, N-P4b

**Competitor:** `Tarquinen/opencode-dynamic-context-pruning` (npm `@tarquinen/opencode-dcp`), an **opencode plugin**. Clone `/home/sites/crush-research-repos/opencode-dynamic-context-pruning` @ `f8232fd` (package `3.2.0`); the older three-tool design is in the published `2.1.8` tarball.

Paths without a prefix are DCP paths (`lib/...`, `index.ts`). opencode paths start with `opencode/packages/...`. sugar-crush paths start with `src/...` (under `sugar-crush/`); sugar-crush anchors are current as of master `574e4cccb`.

**What it is.** DCP rewrites the message list on every LLM request (opencode's `experimental.chat.messages.transform`, fired once per step and also on the compaction input) **without touching stored history**. It exposes a model-callable `compress` tool and runs zero-cost automatic strategies (dedup, purge errored inputs).

| Version | Model-facing design |
|---|---|
| 2.x (e.g. 2.1.8) | **Three tools**: `prune` (drop tool outputs by numeric ID), `distill` (replace tool outputs with a model-written distillation), `compress` (summarise a message range). Strategies: dedup, **supersedeWrites**, purgeErrors. |
| 3.0.0 | Single `compress` tool. Rationale: less cache invalidation, tool-only pruning left user/assistant text growing forever, models struggled to choose between 3 tools. supersedeWrites dropped. |
| 3.1.x | Experimental `compress.mode: "message"`, multi-range batches, `summaryBuffer`, protect tags. |
| 3.2.0 | Compact IDs (`@4@`, `@b1@`, `@blocked@`), hook on opencode's own compaction. |

sugar-crush's 3.B merges both: 2.x `prune`+`distill` → `Prune`, 3.x range `compress` → `Compress` (§13.2).

---

## 3. Sub-agents (→ 3.B-5)

- Sub-agents are excluded by default (the transform exits early for a child session). 2.x README: *"Subagents are not designed to be token efficient; what matters is that the final message returned to the main agent is a concise summary of findings."*
- With `allowSubAgents`:
  - The sub-agent receives `SUBAGENT_SYSTEM_EXTENSION` (`lib/prompts/extensions/system.ts:12-19`): *"The initial subagent instruction is imperative and must be followed exactly. It is the only user message intentionally not assigned a message ID, and therefore is not eligible for compression."* `assignMessageRefs` skips the first user message of a sub-agent session (`lib/message-ids.ts:136-139`).
  - In the parent, the `<task_result>` body is replaced with the child's last assistant text. If the child's second-to-last assistant message called `compress`, its text is prepended (`lib/subagents/subagent-results.ts:16-36`), so a sub-agent that compressed just before reporting does not lose half its report.
  - **Bug #595:** resuming a sub-agent via `task_id` rewrote *all* earlier task results with the latest reply (cache keyed per call, fetch read the child's *current* last message). Key by call.

---

## 4. Context handling and compaction (the core of DCP)

### 4.1 The principle: project, don't mutate (→ 2.2-1, 2.2-2)

Stored history is never edited. DCP keeps a per-session **ledger** (`SessionState`, `lib/state/types.ts:95-114`) and re-derives the outbound view on every request:

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

Saved as JSON per session (`lib/state/persistence.ts:45-51`). Consequences: undo is a flag flip (`deactivatedByUser`); history export and the UI still show everything; host compaction sees the projected view (§4.12).

### 4.2 The per-request pipeline (V1, `lib/hooks.ts:107-164`) (→ 2.2-1 projector order, 3.B-2)

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

During host compaction, V2 only *replays* existing nudges and creates no new anchors; `tests/compaction-nudges.test.ts` pins that the cached prefix is byte-identical in that case (test idea for 2.4-2/3.B-4).

### 4.3 How the model is told what is prunable: injected IDs (→ 1.B-1, 3.B-2 `RefTag`)

- **Format** (`lib/message-ids.ts`): raw messages `m0001`…`m9999` (hard cap 9999 throws *"Message ID alias capacity exceeded"*, #549 — use unbounded refs); blocks `b1`, `b2`, …; V2 compact `@4@`, `@b1@`, `@blocked@`.
- **Tag:** `\n<dcp-message-id>m0007</dcp-message-id>`. A protected user message shows `BLOCKED` instead of its ref.
- **Placement** (`injectMessageIds`, `lib/messages/inject/inject.ts:151-222`):
  - User messages: appended to every text part (synthetic text part if none).
  - Assistant messages: appended to **every completed tool output**; failing that, to the last text part; failing that, a synthetic text part *before* the first tool part.
  - Prompt wording: *"The same ID tag appears in every tool output of the message it belongs to — each unique ID identifies one complete message."*
- **Idempotence:** skip a part that already `includes(tag)` (`lib/messages/utils.ts:94-132`). Refs are assigned once and in order, so bytes are identical on every request.
  - **Bug #614:** a tool part still streaming on one request and completed on the next took a different branch, so the tag was injected twice; the prefix changed mid-history and DeepSeek cache hits fell from 95-99% to ~55%. Ref rendering must be a pure function of immutable data.
- **2.x tool-level list** (`<prunable-tools>` with `ID: tool, param (~N tokens)`, rewritten every turn) cost cache; protected/already-pruned calls were omitted. For Claude models, which reject assistant turns beginning with injected text, it was attached as a synthetic completed tool part instead of a text part.

### 4.4 The `compress` tool, range mode (default) (→ 3.B-4)

**Schema** (`lib/compress/range.ts:30-57`):

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

**Tool description** (`lib/prompts/compress-range.ts:8-67`, XML-ID variant, verbatim; 3.B-4 reuses it nearly verbatim):

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

1. `validateArgs`: topic non-empty, `content` non-empty, every field a non-empty string (`range-utils.ts:15-40`). Manual mode refuses unless a trigger is pending: *"Manual mode: compress blocked. Do not retry until `<compress triggered manually>` appears in user context."* (`pipeline.ts:44-48`).
2. `toolCtx.ask({permission:"compress"})` — only when the permission is `ask`.
3. Re-fetch raw messages, re-assign refs, then run **`deduplicate` + `purgeErrors`**. This is the only place the automatic strategies are recomputed.
4. `resolveRanges` → `resolveBoundaryIds` (`search.ts:46-113`). Model-facing errors, e.g. *"startId m0042 is not available in the current conversation context. Choose an injected ID visible in context."* and *"startId … appears after endId … Start must come before end."* `resolveSelection` collects every raw message, tool `callID` and active block anchored inside the range, plus each message's token count (`:115-203`).
5. `validateNonOverlapping` across the batch (`range-utils.ts:71-102`).
6. For each range, build the stored summary:
   - `parseBlockPlaceholders` accepts `(bN)` or `{block_N}`.
   - `validateSummaryPlaceholders` drops unknown, duplicate or unrequired placeholders and returns the *missing* required ones. Boundary blocks are optional.
   - `injectBlockPlaceholders` replaces each placeholder with the stored block body (header and footer stripped) and auto-injects a boundary block at the start or end if not referenced.
   - `appendProtectedUserMessages` (only with `protectUserMessages`). Heading: *"The following user messages were sent in this conversation verbatim:"*.
   - `appendProtectedPromptInfo` (`<protect>…</protect>` spans, only with `protectTags`). Heading: *"The following protected prompt information was included in this conversation verbatim:"*.
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
9. Clear `compress-pending`, save state, notify (§11).
10. **Tool result:** `Compressed ${n} messages into [Compressed conversation section].`

**Replacement on later requests** (`filterCompressedRanges`, `lib/messages/prune.ts:161-244`) (→ 2.4-1 apply-blocks step):
- At the block's **anchor** (first raw message of the range), insert a **synthetic user message** whose only text part is the stored summary.
- Deterministic ids: `msg_dcp_summary_<sha256(blockId:anchor)[0:16]>` (`lib/messages/utils.ts:15-50`).
- `agent`/`model` fields cloned from the nearest preceding user message.
- **Every raw message inside an active block is dropped.** Tool-level pruning uses placeholders, not summaries (§4.8).

**Hidden cost (the summary is held twice).** The `compress` call keeps the full summary in its arguments, and the same summary is injected at the anchor. DCP mitigates indirectly: message mode classifies messages with a completed `compress` call as `high` priority, and its prompt says *"If prior compress-tool results are present, always compress and summarize them minimally only as part of a broader compression pass. Do not invoke the compress tool solely to re-compress an earlier compression result."*

### 4.5 Message mode: partial success (→ 3.B-3 `Prune` validation)

Schema `{topic, content: [{messageId, topic, summary}]}`; each entry becomes its own block. Bad entries become grouped "soft issues" (`blocked`, `invalid-format`, `block-id`, `not-in-context`, `protected`, `already-compressed`, `duplicate`); the good entries are applied and the model gets `Compressed N messages into [Compressed conversation section].\nSkipped K issues:\n- messageIds m0003, m0004 are already part of active compressions.` The call throws only when *nothing* resolved (`lib/compress/message-utils.ts:151-255`). Priorities: `high` ≥ 5000 tokens, `medium` ≥ 500, `low` otherwise.

### 4.6 The DCP system prompt (→ 3.B-3/3.B-4 `PromptGuidance`)

Appended to the last system part every request (`lib/prompts/system.ts:6-38`), verbatim:

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

**Extensions** (`lib/prompts/extensions/system.ts`):
- **Protected tools:** *"The following tools are environment-managed: `task`, `skill`, `todowrite`, `todoread`. Their outputs are automatically preserved during compression. Do not include their content in compress tool summaries — the environment retains it independently."*
- **Manual mode:** *"Manual mode is enabled. Do NOT use compress unless the user has explicitly triggered it through a manual marker. Only use the compress tool after seeing `<compress triggered manually>` … Issue exactly ONE compress tool per manual trigger … After completing a manually triggered context-management action, STOP IMMEDIATELY."*
- **Sub-agent:** see §3.

Pitfall: DCP skips its prompt for internal title/summariser calls by string-matching their prompts; #581 recorded a false positive that blocked nudges on main sessions. Attach guidance only to the main/sub-agent runtime, never by sniffing prompt text.

### 4.7 Nudges: thresholds, kinds, frequency, placement (→ 3.B-4 `NudgePolicy`, 2.9)

**Thresholds** (`lib/messages/inject/utils.ts:87-163`):
- `compress.minContextLimit` (default **50000**) and `compress.maxContextLimit` (default **100000**). Each accepts a number or `"X%"` of the window.
- Per-model overrides: `modelMinLimits` / `modelMaxLimits`, keyed `"provider/model"`.
- With `summaryBuffer: true` (default), **active summary tokens are added to the max limit**, so summaries alone cannot keep the session above it.
- Current size: the last assistant step's `input + output + reasoning + cache.read + cache.write`, provider-reported (`lib/token-utils.ts:9-38`). It reports 0 when that step predates a host compaction. (#536: a stuck token count meant nudges never fired.)

**Three nudge texts** (verbatim; each wrapped in `<dcp-system-reminder>`):
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
- If the last assistant message contains a completed `compress`, **all anchors are cleared** and nothing is injected (post-compression cooldown).
- Below min: turn and iteration anchors are cleared.
- Over max: the last message becomes a context-limit anchor, but only if ≥ `nudgeFrequency` (default **5**) messages have passed since the previous one (`addAnchor`, `utils.ts:165-193`).
- Between min and max:
  - Newest message is a user message → it and the previous assistant message become turn anchors. `nudgeForce: "soft"` (default) renders on the **assistant** message; `"strong"` on the user message.
  - ≥ `iterationNudgeThreshold` (default **15**) messages since the last user message → iteration anchor, spaced by `nudgeFrequency`.
- Anchors are **persisted** and **re-rendered at the same messages on every later request**, so a nudge does not invalidate the cache the way a moving tail reminder would.

**Placement** (`injectAnchoredNudge`, `utils.ts:211-248`): appended to the anchored message's last text part. **Failure #520:** with `soft`, the nudge landed in a trailing assistant message, so the request ended on an assistant turn and Claude 4.6+ rejected it (*"This model does not support assistant message prefill"*). Never anchor on an assistant row.

**Guidance appended to nudges** (range mode, `lib/prompts/extensions/nudge.ts:4-17`): *"Compressed block context: - Active compressed blocks in this session: 2 (b1, b3) - If your selected compression range includes any listed block, include each required placeholder exactly once in the summary using `(bN)`."*

**2.x prompt rules worth keeping for `Prune`:** cooldown text after any prune — *"Context management was just performed. Do NOT use the prune tool again. A fresh list will be available after your next tool use."* — and the TIMING rule: *"Prefer managing context at the START of a new agentic loop (after receiving a user message) rather than at the END of your previous turn … AVOID USING MANAGEMENT TOOLS AS THE ONLY TOOL CALLS IN YOUR RESPONSE, PARALLELIZE WITH OTHER RELEVANT TOOLS"*.

### 4.8 Automatic strategies and replacement placeholders (→ 2.2-1, 2.3, 3.B-2 `/sweep`)

| Strategy | Rule | What is replaced | Where |
|---|---|---|---|
| **Deduplication** (default on) | Group unpruned, unprotected tool calls by `tool::JSON(sorted non-null params)`; mark every member except the newest | Completed output → `"[Output removed to save context - information superseded or no longer needed]"`. `edit`, `write` and `question` outputs are **never** replaced (`prune.ts:92`) | `lib/strategies/deduplication.ts:12-125` |
| **Purge errors** (default on, `turns: 4`) | A tool with `status: "error"` whose turn age (`step-start` count) is at least `turns` | **Every string input** → `"[input removed due to failed tool call]"`. The error message is kept | `lib/strategies/purge-errors.ts:15-86`, `prune.ts:130-159` |
| Question inputs (via any prune) | Pruned `question` tool | `input.questions` → `"[questions removed - see output for user's answers]"` | `prune.ts:101-128` |
| **Supersede writes** (2.x only) | A `write` to path P followed later by a `read` of P | The write's input | 2.x `dist/lib/strategies/supersede-writes.js` |
| `/dcp sweep [n]` (user command) | Every tool since the last user message, or the last *n* tools, minus protected ones | As dedup | `lib/commands/sweep.ts:125-266` |

**When strategies run.** In v3 only when the model runs `compress` (`pipeline.ts:72-73`): *"Recalculated when the compress tool runs, so prompt cache is only impacted alongside compression."* In 2.x they ran on every request, which produced the Anthropic cache complaints in #387. Batch mutations to turn boundaries.

**Turn protection** (`turnProtection`, default off, 4 turns): tool calls younger than *N* turns are not entered into `toolParameters` (`tool-cache.ts:41-52`), so dedup, purge and sweep never see them.

### 4.9 Protected tools, files and content (→ 2.2-1 `PruningPolicy`, 3.B-4)

- `DEFAULT_PROTECTED_TOOLS = [task, skill, todowrite, todoread, compress, batch, plan_enter, plan_exit, write, edit]` (`lib/config.ts:88-99`). In code it is applied only to sweep; dedup/purge default to `[]` and `compress.protectedTools` to `[task, skill, todowrite, todoread]`, so dedup *could* prune a duplicate `task` call. Lesson: apply one protected set uniformly to every strategy.
- `protectedFilePatterns`: globs matched against `filePath`/`path`, `apply_patch` `*** Add|Delete|Update File:` lines and `multiedit` entries (`lib/protected-patterns.ts:64-107`). Matches are excluded from dedup, purge and sweep, and their outputs are appended to compress summaries.

### 4.10 Token accounting and `/dcp context` (→ 2.1, 3.B-2/5.6 `/context`)

- **Live size:** provider-reported, §4.7.
- **Per-message/per-tool sizes:** tokenizer, falling back to `chars/4`. Bug #638: a WASM tokenizer built per call (~70 ms) blocked the host — cache the estimator.
- **`/dcp context` breakdown** (`lib/commands/context.ts`):
  - SYSTEM = first assistant step's input + cache − tokenizer(first user message)
  - TOOLS = tokenizer(inputs + outputs) − pruned
  - USER = tokenizer(all user text)
  - ASSISTANT = the residual
  - TOTAL = the last step's API totals

### 4.11 Interaction with prompt caching (→ 2.2-1 cache contract, 2.4-1/2.4-2)

- 2.1.8 README measured cache hit rates ~80% with DCP vs 85% without.
- **v3 design decisions that exist for caching:**
  1. Strategies are recomputed only on compress (§4.8).
  2. Refs are assigned once, monotonically, never renumbered.
  3. Nudges are anchored and replayed rather than appended to the moving tail.
  4. Summary message ids are deterministic hashes.
  5. Echoed tags are stripped so the model cannot feed them back.
- **The main model writes the summaries, not a cheaper one** (#387, #502): *"you would lose all cache read for the tool processing call as you're now on a different model … Cache read for opus is half the cost of normal input tokens on haiku."* The summary is produced on a warm cache as an ordinary tool call.

### 4.12 Interaction with host compaction (→ 2.2-1, 2.2-2, 2.4-2, 1.B-3)

- **opencode native tool-output prune** (`opencode/.../session/compaction.ts:271-314`): walks back from the end, skipping the last 2 user turns; protects the newest `PRUNE_PROTECT = 40_000` tokens of tool output and the `skill` tool; once more than `PRUNE_MINIMUM = 20_000` would be freed, sets `part.state.time.compacted`, and the output renders as `"[Old tool result content cleared]"` (`message-v2.ts:297-298`).
- **LLM compaction runs on the projected view** (the transform fires on the compaction input, `compaction.ts:379`): the summariser sees summaries in place of raw ranges. Cheaper, and it fixed #521 ("compressed messages reappear after /compact").
- **V1 reset on compaction** wiped refs, blocks and nudges (`lib/state/utils.ts:331-345`); #551: refs the model saw before compaction became unknown and the error *"is not available in the current conversation context"* misled it. Keep the ledger across compaction and give accurate errors.
- **V2** syncs blocks against the full history: *"Compaction may select only a prefix; block origins can be in the retained tail"* (`lib/v2/index.ts:201`).

### 4.13 Manual mode, the manual trigger, and undo (→ 3.B-2 `/pruning`, 3.B-4)

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
- `/dcp decompress <n>` sets `deactivatedByUser`, re-syncs, and reports restored messages and tokens. It refuses with *"Compression 2 is inside compression 5. Restore compression 5 first."* when an active ancestor consumed it (`lib/commands/decompress.ts:153-275`).
- `/dcp recompress <n>` reverses that, provided the origin compress message still exists (`lib/commands/recompress.ts:106-224`).
- **#611** argues manual should be the default: *"in practice that sometimes destroys content the user still needed … Under context pressure … the model tends to make increasingly aggressive and imprecise choices"*.

### 4.14 The 2.x `prune`/`distill` tools (→ 3.B-3 `Prune`)

- **`prune`** (`ids: string[]`):
  > Use this tool to remove tool outputs from context entirely. No preservation - pure deletion. … `prune` is surgical deletion - eliminating noise (irrelevant or unhelpful outputs), superseded information (older outputs replaced by newer data), or wrong targets (you accessed something that turned out to be irrelevant). … BATCH WISELY! Pruning is most effective when consolidated. Don't prune a single tiny output - accumulate several candidates before acting. Do NOT prune when: NEEDED LATER … UNCERTAINTY … Before pruning, ask: _"Is this noise, or will it serve me?"_ … Pruning that forces re-fetching is a net loss.
- **`distill`** (`targets: [{id, distillation}]`):
  > Use this tool to distill relevant findings from a selection of raw tool outputs into preserved knowledge … This is not mere summarization; it is high-fidelity extraction that makes the original output obsolete. Your distillation must be COMPLETE. Capture function signatures, type definitions, business logic, constraints, configuration values... EVERYTHING essential. … Prefer keeping raw outputs when: PRECISION MATTERS: You will edit the file, grep for exact strings, or need line-accurate references. … Before distilling, ask yourself: _"Will I need the raw output for upcoming work?"_ If you plan to edit a file you just read, keep it intact.
  - The distillation lives in the `distill` call's own arguments (a protected tool); the raw output becomes the generic placeholder.
- **Validation:** out-of-range, unknown, protected, file-protected and already-pruned IDs are skipped. If none remain the call throws *"Invalid IDs provided: [..]. Only use numeric IDs from the <prunable-tools> list."*; otherwise the model gets the pruned list plus *"Note: N IDs were skipped …"*.

Tool-level drop/distil and range-level compress are complementary. sugar-crush stores **one history row per tool result** (`Chat::toolResultMessage`, `src/Chat.php:4639`), so a single ref namespace covers both granularities (§13.2).

### 4.15 Persistence and state hygiene (→ 2.2-2)

- One JSON file per session holds prune maps, blocks, nudge anchors, stats and the manual flag; reloaded and validated defensively (`loadPruneMessagesState`, `lib/state/utils.ts:118-289`).
- `messageIds` are *not* persisted; re-derived deterministically in message order.
- State files are never deleted with the session (#557) — delete the ledger with its session.
- `syncCompressionBlocks` (`lib/messages/sync.ts:15-124`) runs every request: it deactivates a block whose origin compress message no longer exists (after a revert or fork — "revert to a pre-compression message" naturally decompresses, #527), and re-applies `consumedBlockIds` deactivation in creation order.

### 4.16 Known failures to design against

| # | Problem | Lesson (→ step) |
|---|---|---|
| #573 | **Compression snowball.** Each new block re-absorbed the previous block's placeholder plus a small new tail; the summary grew to ~68k tokens (234k chars), the context-limit nudge fired 45 times, 71 blocks, 738,738 tokens burnt. | Size guard + bounded nested re-expansion (3.B-4) |
| #614 | A double-injected ID tag mid-history broke the prefix cache permanently (95% → 55%). | Ref rendering is a pure function of immutable data (3.B-2) |
| #615 | Providers that omit tool-call ids got fallbacks like `bash:0`, repeated across messages; pruning hit the wrong calls. | Harness-assigned, globally unique tool-call ids (0.2) |
| #520 | Nudge in the trailing assistant message; Anthropic rejected it as "prefill". | Never end the request on a synthetic assistant row (3.B-4) |
| #551, #533 | Refs and blocks wiped by host compaction. | Keep the ledger across compaction (2.2-2) |
| #549 | Hard cap of 9999 refs. | Unbounded refs (1.B-1) |
| #608, #632, #555 | Models (especially GPT) copy `@N@`, `mNNNN</parameter>` and whole nudge blocks into replies. | Strip on output and on re-send (3.B-2 `RefTag::stripFrom`) |
| #611 | Autonomous compression destroys still-needed detail under pressure. | Manual + auto modes, easy undo, conservative defaults (3.B-2/3.B-4) |
| #595 | A resumed sub-agent's results were all rewritten. | Key by call, not by session (3.B-5) |
| #638 | WASM tokenizer constructed every call (70 ms). | Cache the estimator (2.1) |
| #387 | Per-request rewrites killed the Anthropic subscription cache. | Batch mutations (2.2-1) |

---

## 10. Permissions and safety (→ 3.B-3, 3.B-4)

- `compress.permission`: `allow` (default), `ask` or `deny`. `deny` means the tool is not registered at all; an explicit host `permission.compress: deny` (global or per agent) is honoured; `ask` uses the host permission prompt with `always: ["*"]`.
- Forged-ID safety: only IDs present in the current context resolve; `<dcp…>` tags in model or tool output are stripped before re-sending (first pipeline step); in message mode, block IDs inside rendered summaries are rewritten to `BLOCKED`.

## 11. Compression receipts (→ 3.B-3 H transcript)

- Sent as an opencode **"ignored" message** (user sees it, model never does) or a 5 s toast; `pruneNotification` is `off`, `minimal` or `detailed` (`lib/ui/notification.ts:172-347`). The detailed format:
  ```
  ▣ DCP | -48.2K removed, +3.1K summary
  │████████░░░░░░░░░░░░⣿⣿⣿⣿████████████████████████│      (█ active, ░ pruned, ⣿ just compressed)
  ▣ Compression #4 -12.3K removed, +1.2K summary
  → Topic: Auth System Exploration
  → Items: 23 messages and 31 tools compressed
  → Compression (~1.2K): <summary>                           (only with compress.showCompression)
  ```
- The event hook times each compress call and stores the duration on the block. Known UI gaps: no keyboard navigation (#591), no sweep/decompress buttons (#578).

---

## 13. Recommended improvements for sugar-crush

### 13.1 Prioritised list

**P0-1. Stable per-row and per-tool-call identity (→ 0.2, 1.B-1). Effort M.**
- Every history row and tool call gets a harness-assigned, globally unique, persisted id, plus a short model-visible ref. DCP's worst bugs (#615, #614, #551, #549) are identity bugs. The DSML parser mints `dsml_call_<index>` per response (`src/Providers/ToolCallParser/DsmlToolCallParser.php:399`) and MiniMax mints `minimax_xml_call_<n>` (`MinimaxXmlFallbackToolCallParser.php:267`), so ids repeat across steps.
- Add `id`/`ref`/`stepId` to `src/Message.php` and round-trip them in `jsonSerialize`/`fromArray` (`:765`, `:832`). Add `Support\ToolCallIdAllocator` in `Runtime::runStreaming`/`runBatch` (`:1419`/`:1608`) before tool execution, rewriting empty or non-unique ids to `tc_<sessionShort>_<seq>`. Carry the ref in the `started`/`finished` frames (`EngineBackend::encodeEvent` `:2608`). Details §13.2 A.

**P0-2. Structured cross-turn replay of tool calls (→ 1.B-2). Effort M.**
- Rebuild `AssistantMessage(toolCalls)` + `ToolResultMessage(id)` pairs from Chat rows instead of plain assistant text (`EngineBackend::toTypedMessages` `:2861-2893`). Chat rows already persist `toolResults[].id/name/arguments`. Group rows by `stepId` so parallel calls replay as one assistant message. Unanswered placeholders are handled by `HistorySanitizer` (`src/Messages/HistorySanitizer.php:80-141`).
- This *increases* tokens (arguments come back — Write `content`!), so P0-3 must ship with it. Pruning placeholders such as "output of Read src/X pruned" only make sense when the call and its arguments are visible.

**P0-3. Context ledger and projector, with automatic zero-cost strategies (→ 2.2-1, 2.2-2, 2.3, 3.B-2). Effort M/L.**
- DCP's non-destructive "transform before send" at `Runtime::buildMessages()` (`:3393`) on every step, with dedup, stale-read and superseded-write-input pruning and errored-input purging. In-turn relief with no model cooperation. New `src/Context/Pruning/*` (§13.2 B-E), also feeding Chat's token estimate and compaction input.

**P0-4. Agent-callable `Prune` and `Compress` tools (→ 3.B-3, 3.B-4). Effort L.**
- The model drops or distils finished tool outputs (`Prune`) and replaces closed ranges with its own summaries (`Compress`, nested blocks), applied within the turn. DCP's #387 argument: the main model summarising on a warm cache is cheaper than a second model reading everything cold. Bound per turn through a new `Tools\MutatesContextLedger` interface in `EngineBackend::turnTools()` (`:1537-1586`); a `ledger` fork frame carries changes to `Chat` (§13.2 F-H).

**P1-5. Pressure-graded, anchored nudges with absolute thresholds (→ 2.9, 3.B-4). Effort M.**
- On a 1M window a 70% reminder fires at ~734k tokens, far past the quality "smart zone"; DCP defaults to 50k/100k absolute, overridable per model.
- Add `Context\Pruning\NudgePolicy`; fold `Chat::contextReminderMessage()` (`:18179`) into it as the "turn" nudge. Inject into the newest tool-result or user message, **never** as a trailing assistant or system row.

**P1-7. Commands: `/context`, `/compress [focus]`, `/decompress [b]`, `/recompress [b]`, `/sweep [n]`, `/pruning auto|manual|off` (→ 3.B-2, 3.B-4, 5.6). Effort M.**
- Add to `Chat::dispatchCommand` (`:10337`) and `CommandRegistry::all`. `docs/COMMANDS.md` and the README roster are drift-tested.

**P1-8. Sub-agent self-pruning (→ 3.B-5). Effort S/M** (after P0-4).
- An ephemeral ledger per sub-agent run, the task prompt pinned (no ref). Serialise the ledger into `SuspendedDelegations` so resume keeps it.

**P1-9. Run compaction on the projected view; dispatch `PreCompact` (→ 2.2-2, 2.4-2, 2.12). Effort S.**
- Feed `ContextProjector` output to Chat's summary request (`buildSummarizationRequest` `:13050`, `scheduleParkedCompaction` `:13238`).
- Dispatch `HookEvent::PreCompact` (no dispatch site today) before both Chat compaction and agent `Compress`.

**P2-10. Cache-health telemetry after compressions (→ 3.B-5, 5.6). Effort S.**
- #614 was only diagnosed from cache-hit telemetry. Use the per-step `EngineBackend::observeCacheHealth` (`:1457`) data to show a `cached_tokens / prompt_tokens` ratio in `/context` and flag a drop after a compression.

**P2-11. Group pruned targets with the dormant `Compactor` (→ 3.B-5). Effort S.**
- `src/Compactor.php` / `CompactedGroup.php` group file paths by category ("code ×12, config ×3"). Use them for the `/sweep`, `Prune` and `/context` "pruned items" lists and the compression receipt. It is a filesystem helper (`is_file`/`filesize`), so UI only, never the prompt.

**P2-12. Recall of pruned content (→ 3.B-5). Effort M.**
- Neither DCP nor sugar-crush can re-surface a pruned output on demand (magic-context can, DCP #552). Add a `Recall` tool (`{ref}`) returning the raw stored content of a pruned row or block (raw history is kept, since projection is non-destructive). Gate to N calls per turn.

### 13.2 Detailed implementation design: agent-driven self-pruning and compaction

The design follows the project rules: immutable `with*()`/`mutate()`, one type per PSR-4 file, `::new()` factories, drift-tested docs, wiring dormant code rather than deleting it.

**Step placement** (from `impact/context-engine.md`; 3.B must not redefine any class an earlier step created):

| Step | Builds |
|---|---|
| 0.2 + 1.B-1 + 1.B-2 (= 3.B-1) | §A identity, id allocator, structured replay |
| 2.2-1 | §B core: `ContextLedger` (prunes only, lenient `fromArray`), `PruneEntry`, `LedgerDelta`, `PruningMode`, `PruningPolicy` (protected: Task, Skill, Edit, Write), `PruningStrategy` interface, `ContextProjector`, `ProjectedContext`; §D `ToolOutputAgeStrategy`; projector wired at `Runtime::buildMessages` |
| 2.3 | §D the four zero-cost strategies (+ `CanonicalArguments`) |
| 2.4-1 | `CompressionBlock` (`by: Harness`), `ContextLedger::withBlock()`, projector apply-blocks step |
| 2.2-2 | §B persistence, `syncAgainst()`, §G `ledger` fork frame, Chat property |
| 3.B-2 | `RefTag`, `/context`, `/sweep`, `/pruning`, `contextPruning` config + env |
| 3.B-3 | `MutatesContextLedger`, `Prune`, transcript badges |
| 3.B-4 | `Compress`, `NudgePolicy`, `/compress`, `/decompress`, `/recompress`, `/compact --self` |
| 3.B-5 | §I sub-agents, `Recall`, cache telemetry, `Compactor` receipts |

#### A. Identity: data-model changes (→ 0.2, 1.B-1, 1.B-2)

1. **`src/Message.php` (Chat row).** Add three constructor fields:
   - `public readonly ?string $id = null`: storage id, `r_<16hex>` from `bin2hex(random_bytes(8))`.
   - `public readonly ?int $ref = null`: the model-visible short ref, monotonic per session and never reused.
   - `public readonly ?string $stepId = null`: which engine step produced the row. Parallel tool results share it, and the step's assistant narration lives on the first row.

   Extend `jsonSerialize()`/`fromArray()`.
   - **Legacy transcripts:** `Chat` assigns `id`/`ref` in order on load and re-persists, so refs are stable from then on. Refs are never derived from position after first assignment, which avoids the DCP #614/#551 failure class.
   - Every `with*()` copier in `Message` must carry the new fields (they already carry `uiOnly`). A test asserts that each `with*` method preserves them.
2. **Ref allocation.**
   - `Chat` owns `nextRef` (persisted in the ledger, §B) and assigns refs to user rows at `Chat::submit()` (`:8764`).
   - The **turn child** assigns refs to rows it creates (assistant steps, tool results). `EngineBackend::completeAsync()` (`:1841`) receives `nextRef` and returns the new high-water mark in the `result` frame. The `started` frame carries `ref` and `stepId`, so `Chat` stamps the placeholder row with the child's ref. `Chat::toolResultMessage()` (`src/Chat.php:4639`) copies them onto the result row.
3. **Tool-call ids.**
   - New `src/Support/ToolCallIdAllocator.php` with `assign(AssistantMessage $m): AssistantMessage`. It rewrites `''`, duplicates within the session, and the known per-response patterns (`dsml_call_\d+`, `minimax_xml_call_\d+`) to `tc_<sessionShort>_<seq>`. `sessionShort` comes from the session id so ids stay unique across `/branch` forks.
   - Call it in `Runtime::runStreaming()`/`runBatch()` (`:1419`/`:1608`) before tool execution. `HistorySanitizer` and the fork frames then only ever see unique ids.
   - Anthropic/Bedrock id charset `[A-Za-z0-9_-]` is satisfied.
4. **Typed messages** (`src/Messages/*`). Add optional `?int $ref` (and `?string $stepId`) to `UserMessage`, `AssistantMessage` and `ToolResultMessage`, with accessors `ref()`/`stepId()`. Providers ignore them. Only the projector reads them.

#### B. The ledger: `src/Context/Pruning/ContextLedger.php` and friends (→ 2.2-1, 2.4-1, 2.2-2)

One type per file, all `final readonly`:

| Class | Fields / purpose |
|---|---|
| `ContextLedger` | `prunes: array<string toolCallId, PruneEntry>`, `blocks: array<int blockId, CompressionBlock>`, `nudges: NudgeAnchors`, `stats: PruneStats`, `nextRef`, `nextBlockId`, `mode: PruningMode`. Methods: `withPrune()`, `withBlock()`, `withBlockDeactivated(int, bool byUser)`, `withNudges()`, `apply(LedgerDelta)`, `toArray()`/`fromArray()` (lenient like DCP's `loadPruneMessagesState`) |
| `PruneEntry` | `toolCallId`, `ref ?int`, `kind` (`Output`, `Distilled`, `Inputs`, `ErroredInputs`), `reason` (`Noise`, `Superseded`, `Duplicate`, `StaleRead`, `Errored`, `Done`), `by` (`Model`, `Strategy`, `User`), `distillation ?string`, `supersededByRef ?int`, `tokens`, `createdAt`, `originRef` (the Prune call's own row) |
| `CompressionBlock` | DCP's `CompressionBlock` (§4.4 step 8), with refs instead of message ids: `id`, `topic`, `fromRef`, `toRef`, `anchorRef`, `summary`, `consumedBlockIds`, `parentBlockIds`, `active`, `deactivatedByUser`, `compressedTokens`, `summaryTokens`, `originRef`, `createdAt`, `by` (`Harness` for 2.4-1, `Model` for `Compress`) |
| `NudgeAnchors` | `limit: list<int>`, `turn: list<int>`, `iteration: list<int>` |
| `LedgerDelta` | An ordered list of ops (`AddPrune`, `AddBlock`, `DeactivateBlock`, `SetNudges`, `BumpRefs`) that can be applied idempotently. It crosses the fork socket |
| `PruningMode` (enum) | `Auto`, `Manual`, `Off` |

**Prune key.** Key `PruneEntry` by tool-call id (unique after 0.2, already persisted in `toolResults[].id`), not by row ref; this keeps 2.2-1 independent of 1.B-1. The ref→id map arrives with 1.B-1/3.B-2 and is what `Prune{ref}` resolves through.

**Persistence.** Add a `context_ledgers(session_id TEXT PRIMARY KEY, ledger_json TEXT, updated_at INT)` table in `EnhancedSessionStore::initEnhancedSchema` (`src/Session/EnhancedSessionStore.php:310-401`), with `saveLedger()`/`loadLedger()`.
- `Chat::persistTranscript()` (`:1735`) also saves the ledger.
- Forking (`copySessionState` `:141`) copies the ledger and `saveCheckpoint` (`:711`) snapshots it, so `/rewind` and `/branch` restore matching pruning state.
- The ledger is per session; a deleted session deletes its ledger (DCP #557).

**Sync** (`ContextLedger::syncAgainst(array $rows)`), DCP's `syncCompressionBlocks`:
- A block or prune whose `originRef` row no longer exists becomes inactive (after `/rewind`, or when Chat compaction dropped the row).
- Blocks whose range rows were summarised away by Chat compaction become inert.
- Refs are never reused.

#### C. The projector: `src/Context/Pruning/ContextProjector.php` (→ 2.2-1, 2.4-1, 3.B-2)

`ContextProjector::new(PruningPolicy $policy)->project(array $typedMessages, ContextLedger $ledger): ProjectedContext` returns the messages plus a `projectedTokens` estimate. It is a pure function, so tests can pin byte stability. Steps, in order (modelled on `lib/hooks.ts:133-161`):

1. **Strip echoed refs** from assistant text: `RefTag::stripFrom()` removes `<ctx-ref …/>` patterns, the analogue of DCP's `stripHallucinations`.
2. **Apply blocks.** Drop every row whose ref lies inside an active block. At `anchorRef`, insert a synthetic **`UserMessage`**:
   ```
   [Compressed section b3: "Auth system exploration" — replaces r12…r40]
   <summary>
   ```
   - Merge it into the following user message if two user roles would end up adjacent, since some chat templates reject that.
   - Do **not** use `SystemMessage`: `SglangProvider::formatMessages` (`:2316-2360`) hoists every System row into message 0.
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
- `Runtime::buildMessages()` (`src/Runtime.php:3393-3404`) becomes `HistorySanitizer::sanitize($this->projector->project($messages, $app->contextLedger)->messages)`.
- `App` gets `withContextLedger()`.
- `Chat::rawTokenProxy()`/`estimateTokenCount()` (`:17573`/`:17535`) measure the **projected** view, so thresholds fall after pruning. DCP's #536 is the cautionary tale: a stuck token count meant nudges never fired.
- Chat compaction's summary input (`buildSummarizationRequest` `:13050`, `scheduleParkedCompaction` `:13238`) also takes the projected view (P1-9).
- `ContextCompactor::removeToolResults` (`:1092`, called from `stagePairs` `:631`) is a confirmed no-op (Chat stores tool output as `Message::assistant(...)->withToolResults()`, which its filter never matches): delegate it to the projector rule rather than leaving a dead stage.

**Cache contract** (documented and tested):
- (a) Ref tags, placeholders and nudges are pure functions of immutable row data plus the ledger.
- (b) The ledger only changes at three points:
  1. turn start: `Chat::submit()` runs strategies;
  2. a model `Prune`/`Compress` call;
  3. an over-max emergency inside a turn.
- (c) The bytes before the earliest changed ref are identical across requests.

#### D. Automatic strategies: `src/Context/Pruning/Strategies/*` (→ 2.2-1, 2.3)

Each strategy implements `PruningStrategy::propose(array $typedMessages, ContextLedger $ledger, PruningPolicy $p): LedgerDelta`:

| Strategy | Rule | Default |
|---|---|---|
| `DuplicateCallStrategy` | Same tool and canonical arguments (sorted keys, nulls dropped, the `description` arg ignored); keep the newest. Read-only tools (`Read`, `Glob`, `Grep`, `WebFetch`, `WebSearch`, `Lsp`) plus `Bash` | on |
| `StaleReadStrategy` | `Read` of path P followed later by `Edit`/`Write` of P, or another `Read` of P | on. **DCP lacks this**, and it is high-yield for coding agents |
| `SupersededWriteInputStrategy` | `Write` `content` / `Edit` `old_string`+`new_string` arguments, once a later `Read`/`Write` of P exists (2.x supersedeWrites, extended to Edit) | on |
| `ErroredInputStrategy` | A failed call older than N user turns: blank its string arguments | on, N = 4 |
| `ToolOutputAgeStrategy` | opencode-native style: protect the newest 40k tokens of tool output and the last 2 user turns; prune older outputs once total savings exceed 20k | **only at the over-max emergency** |

`PruningPolicy::protectedTools` defaults to `Task`, `Skill`, `Prune`, `Compress`, `Edit`, `Write` outputs (DCP's set, adapted). Add `protectedFilePatterns` globs, via the existing `Tools\IgnoreRules`/fnmatch helpers. Path keys come from tool-call arguments, which within a turn exist only on typed `AssistantMessage::toolCalls()`; cross-turn dedup needs 1.B-2.

**Run points:**
- `Chat::submit()` (`:8764`), before `dispatchTurn()` (`:9902`), when mode ≠ Off. This is a turn boundary: the cache breaks once.
- Inside `ExecutesContextOps`, whenever the model calls `Prune`/`Compress`.
- In `EngineBackend::runTurn()` between steps (step loop `:1130-1304`), only when the projected size exceeds `maxContextTokens` (the 2.1 budget): the emergency path.

#### E. Nudges: `src/Context/Pruning/NudgePolicy.php` (→ 3.B-4)

**Inputs:**
- the projected token count, anchored on the provider-reported prompt tokens of the last step, as DCP does. `Usage::promptTokens()` returns null unless all three input buckets are reported, so fall back to `ownTokens()`/the estimate as Chat already does;
- `minContextTokens` (default **60000**) and `maxContextTokens` (default **120000**), each accepting `"N%"`, plus a `summaryBuffer`;
- `nudgeFrequency` **5** and `iterationNudgeThreshold` **10**. sugar-crush counts tool rows, which are finer-grained than DCP's messages.

**Texts:** adapt DCP's three nudges (§4.7) and wrap them in `<context-reminder>`. These replace `Chat::contextReminderMessage()`'s System row (`:18179`); keep the wording constant in one place and reflection-test it like `CONTEXT_REMINDER_PREFIX`.

**Rules:**
- Anchors persist in the ledger and are re-rendered at the same ref (cache-stable).
- Clear all anchors after a successful `Compress`/`Prune` (cooldown).
- **Never** append to an assistant message and never create a trailing assistant row (DCP #520).
- Manual mode disables nudges. Strategies keep running unless `strategiesInManual = false`.

#### F. The tools (→ 3.B-3, 3.B-4)

Both tools live in `src/Tools/BuiltIn/` and implement `Tool`, `PromptGuidance` and a new `Tools\MutatesContextLedger` (`withLedger(\Closure $read, \Closure $apply): Tool`).
- `EngineBackend::turnTools()` (`:1537-1586`) binds them to the turn's ledger the same way `DelegatesToEngine` is bound.
- They are **not** `ParallelSafe`. They must run in the turn child that owns the ledger, not in a forked parallel child (`Runtime::executeConcurrently`, `src/Runtime.php:1984`).
- They are registered in `Bootstrap::unfilteredTools()` (literal ends `src/Cli/Bootstrap.php:8056`; append before `...self::mcpTools($root)`) when `contextPruning.mode ≠ off`, and are subject to `allowedTools`/`disabledTools`.
- Add them to `PermissionGate`'s no-ask read-only list (`src/Permissions/PermissionGate.php:922`) and `ProtectFilesHook::READ_ONLY_TOOLS` (`:164`); otherwise they fall to `Ask` under the `default` mode.

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
- Dispatch `PreCompact` through `HookManager::preCompact()` (added by 2.12), and refuse when a hook denies.
- Refuse in manual mode unless a `/compress` trigger is pending (DCP `pipeline.ts:44-48`).

#### G. Crossing the fork boundary (→ 2.2-2, 3.B-3)

- **New event** `src/Events/ContextLedgerChanged.php` (`LedgerDelta $delta`), emitted through `$onEvent` by the tools and by the emergency strategy run.
- **Encoding:**
  - `EngineBackend::encodeEvent()` (`:2608`) gains `kind: 'ledger'` carrying `delta->toArray()`.
  - `decodeEvent()` (`:2671`) validates it strictly, as it already does for `subagent`.
  - The `result` frame (`runCompleteInChild` `:2300-2449`, read by `settleFromResultFrame` `:2542`) also carries the final `ledgerHighWater` (`nextRef`, `nextBlockId`) so a lost frame cannot desynchronise refs.
- **Chat:**
  - The tool-event pump (`Chat::pumpLiveToolEvents` `:4405`) applies `ContextLedgerChanged` to `Chat`'s ledger immediately, so the UI can dim rows mid-turn, then persists.
  - Add a Msg `src/ContextLedgerUpdatedMsg.php` for the blocking (no-pcntl) path, `completeAsyncBlocking` (`:2749`).
  - `HistoryCompactedMsg` stays the carrier for *Chat-initiated* LLM compaction. When that compaction drops rows, `Chat` calls `ContextLedger::syncAgainst()`.
- **In-turn effect.** In `runTurn()`, the child keeps a local `$ledger`. After each step it sets `$app = $app->withMessages([...])->withContextLedger($ledger)` (step loop `:1130-1304`). Step *k+1*'s `buildMessages()` projects the compressed view, so in-turn compaction works without a second model. Sub-agents get this for free: `TaskTool::runOnEngine` and `EngineExecutor` both go through `runTurn`.

#### H. Chat integration and UI (→ 3.B-2, 3.B-3, 3.B-4, N-P4b)

- `Chat` gains a `ContextLedger $contextLedger` property, loaded in `Bootstrap::chat()` with the session and changed only via `mutate()`.
- **Transcript** (`src/Renderer.php`):
  - pruned tool rows render dimmed with a `pruned`/`distilled` badge;
  - rows inside an active block collapse into one row, `▣ Compressed b3 · Auth system exploration · −41.0K +2.4K` (Ctrl+O expands to the summary, and to the raw rows on a second press);
  - every receipt is a `uiOnly` notice row (`Message::$uiOnly`) in DCP's detailed format (§11), including the `│███░░⣿│` bar.
- **Status bar:** `ctx 38% (−52K pruned)`.
- **Commands** (P1-7):

  | Command | Behaviour |
  |---|---|
  | `/context` | Category breakdown as in DCP §4.10, plus cache-hit ratio |
  | `/compress [focus]` | Sends DCP's `COMPRESS_TRIGGER_PROMPT` plus focus as the user turn. It works in manual mode and allows exactly one call |
  | `/decompress [bN]` / `/recompress [bN]` | Flip `deactivatedByUser`. Refuse when an active ancestor consumed the block |
  | `/sweep [n]` | Prune tool outputs since the last user message, or the last *n* |
  | `/pruning auto\|manual\|off` | Persisted per session |

  Docs: `docs/COMMANDS.md` and the README roster (drift-tested by `ReadmeRosterDriftTest::testTheSlashCommandRosterIsExactlyWhatTheRegistryAdvertises`). `/context` also goes in `Chat::READ_ONLY_COMMANDS` (`:9378`).
- **Config:** a `contextPruning` object in `src/Config/LayeredSettings.php` (`LAYERED_KEYS` `:410-432`), user tier: `mode`, `minContextTokens`, `maxContextTokens`, `nudgeFrequency`, `iterationNudgeThreshold`, `protectedTools`, `protectedFilePatterns`, `strategies.{duplicates,staleReads,supersededWrites,erroredInputs:{turns}}`, `subAgents`, `maxBlockTokens`.
  - The project tier may only *add* protections.
  - New env var `SUGARCRUSH_CONTEXT_PRUNING=auto|manual|off`, which must be added to `docs/ENVIRONMENT.md` (`EnvRosterDriftTest`).
  - **Recommended default: `auto` for strategies, `manual` for `Compress`** (the #611 lesson) until evals show the model compresses sensibly. `Prune` is safe enough for `auto`.

#### I. Sub-agents (→ 3.B-5)

- `TaskTool::runOnEngine()` (`src/Tools/BuiltIn/TaskTool.php:474-740`) creates an **ephemeral** `ContextLedger` for the child run. Its ref namespace is private and it is not persisted.
- The sub-agent's first `UserMessage` (the task) gets no ref, so it is unprunable, mirroring DCP's sub-agent extension.
- `Prune`/`Compress` are included in the sub-agent's tool grant unless the preset's `tools:` omits them.
- `SuspendedDelegations` serialises the ledger with the transcript (`src/Agents/SuspendedDelegations.php`, adding `ContextLedger` to its allow-listed classes `:61-68`) so `resume` continues with the same pruned view.

#### J. Tests to add

`tests/` mirrors `src/`. New files must be added to `scripts/parallel-tests-durations.tsv`; `sugar-crush/tests/Config/Support/suite-figure.json` is refreshed in the Final pass.

| Test file | What it pins (step) |
|---|---|
| `tests/MessageIdentityTest.php` | `id`/`ref`/`stepId` survive every `with*()` and the `jsonSerialize`→`fromArray` round trip; legacy rows get refs once and keep them (1.B-1) |
| `tests/Support/ToolCallIdAllocatorTest.php` | Two DSML responses that each yield `dsml_call_0` become distinct `tc_…` ids; provider-unique ids are preserved; charset is valid (0.2) |
| `tests/Backend/EngineBackendStructuredReplayTest.php` | Chat tool rows → `AssistantMessage(toolCalls)` + `ToolResultMessage` pairs; parallel rows with one `stepId` → one assistant message; `uiOnly` rows skipped; unfinished placeholder → sanitizer's interrupted result (1.B-2) |
| `tests/Context/Pruning/ContextLedgerTest.php` | Immutability; `apply(LedgerDelta)` is idempotent; lenient `fromArray`; `syncAgainst` deactivates orphaned blocks and prunes (2.2-1, 2.2-2) |
| `tests/Context/Pruning/ContextProjectorTest.php` | Block → synthetic user summary at the anchor, range rows dropped; adjacent-user merge; placeholder formats; **byte-identical output for two projections of the same input** (cache-stability golden); ref tags once per row; echoed `<ctx-ref>` stripped from assistant text (2.2-1, 2.4-1, 3.B-2) |
| `tests/Context/Pruning/Strategies/DuplicateCallStrategyTest.php`, `StaleReadStrategyTest.php`, `SupersededWriteInputStrategyTest.php`, `ErroredInputStrategyTest.php`, `ToolOutputAgeStrategyTest.php` | One file per rule; protected tools and globs are respected; the newest copy is kept (2.2-1, 2.3) |
| `tests/Context/Pruning/NudgePolicyTest.php` | Below min: none. Between min and max: turn nudge at a new user turn; iteration nudge after the threshold, spaced by frequency. Over max: limit nudge. Anchors replay at the same ref; cleared after Compress; **never placed on an assistant message** (DCP #520); manual mode disables (3.B-4) |
| `tests/Tools/BuiltIn/PruneToolTest.php` | Drop vs distil; soft issues (unknown, protected, already pruned, current call); a distillation longer than the raw output is rejected; result text; `PreCompact` deny refuses (3.B-3) |
| `tests/Tools/BuiltIn/CompressToolTest.php` | Unknown/reversed/overlapping boundaries; placeholder required/duplicate/unknown; auto-append of missing blocks; consumed blocks deactivated; **size guard (DCP #573)**; nesting cap; multi-range batch; protected Task output appended verbatim; manual-mode refusal (3.B-4) |
| `tests/Backend/EngineBackendLedgerFrameTest.php` | `ledger` frame encode/decode round trip; malformed frames dropped; the `result` frame carries the high-water mark (2.2-2, 3.B-3) |
| `tests/Backend/InTurnCompressionTest.php` | A scripted fake provider calls `Compress` at step 3; the captured `CompleteRequest` at step 4 has fewer messages and contains the summary; Chat's ledger received the delta (3.B-4) |
| `tests/Chat/ContextCommandsTest.php` | `/context`, `/compress focus`, `/decompress`, `/recompress`, `/sweep`, `/pruning`; the ledger persists with the transcript; `/branch` copies it; `/rewind` restores the checkpointed ledger (3.B-2, 3.B-4) |
| `tests/Chat/ProjectedTokenEstimateTest.php` | The estimate drops after a prune; compaction input is the projected view (2.2-2) |
| `tests/Tools/BuiltIn/TaskToolLedgerTest.php` | The sub-agent prompt cannot be pruned; the ledger survives a `SuspendedDelegations` resume (3.B-5) |

Drift updates: the README tool roster (`ReadmeRosterDriftTest::testTheCapabilitiesToolRosterNamesEveryToolALaunchShips`) and the rest of the new-tool set (`docs/ARCHITECTURE.md` "## Tools" count, `BuiltInToolCorpusTest` count, `docs/PERMISSIONS.md` name classes), `docs/COMMANDS.md`, `docs/ENVIRONMENT.md`, `docs/SETTINGS.md` layered-key list, and `docs/PROMPT_ENGINEERING.md` (new reminder text and the ref tag).

#### K. Rollout order

| Phase | Contents | Steps | Effort |
|---|---|---|---|
| 1 | A + P0-2 structured replay | 0.2, 1.B-1, 1.B-2 (= 3.B-1) | M |
| 2 | B + C + D (strategies at turn start only) + `/context`, `/sweep`, `/pruning` | 2.2-1, 2.3, 2.2-2, 3.B-2 | M |
| 3 | `Prune` tool + G (fork frames) + H transcript badges | 3.B-3 | M |
| 4 | `Compress` tool + blocks + `/compress`, `/decompress`, `/recompress` + E nudges | 2.4-1 (blocks), 3.B-4 | L |
| 5 | I sub-agents, P2-10 cache telemetry, P2-11 Compactor wiring, P2-12 `Recall` | 3.B-5 | S/M each |

---

## 14. Problems in sugar-crush exposed by this comparison

1. **Tool-call ids are not unique on the default model path (→ 0.2).** The DSML parser mints `dsml_call_<index>` per response (`DsmlToolCallParser.php:399`); MiniMax mints `minimax_xml_call_<n>` (`MinimaxXmlFallbackToolCallParser.php:267`). Steps 1 and 2 of one turn can both contain `dsml_call_0`. `HistorySanitizer` keys `callIds`/`answeredIds` by id (`src/Messages/HistorySanitizer.php:82-94`), so a second step's unanswered call can be marked "answered" by the first step's result, and OpenAI-compatible servers receive duplicate `tool_call_id`s. Any id-keyed feature (pruning, structured replay, placeholder matching by `pendingToolCallId`) inherits DCP's #615. *Inferred from code; not reproduced.*
2. **Cross-turn tool replay is lossy (→ 1.B-2).** `toTypedMessages` (`:2861`) replays tool output as `AssistantMessage($content)`; the persisted `toolResults[].id/name/arguments` are discarded at conversion time.
3. **Every in-history System row is hoisted into the leading system message on SGLang (→ 1.A-1)** (`SglangProvider::formatMessages` `:2336-2358`). Any new such row changes message 0, so the whole conversation misses SGLang's radix prefix cache, and the row loses its chronological position. This is also why the projector's block summary (§13.2 C step 2) and nudges must be user-role.
4. **Volatile `<env>` sits inside message 0 (→ 1.A-1, 1.A-2).** Git status/log/date are re-rendered each step; the conversation history comes after message 0, so an `<env>` change probably forces a full re-prefill. Measure with `prompt_tokens_details.cached_tokens` across a write step; DCP-style anchored injection (volatile part into the newest user/tool row) keeps message 0 static.
5. **Context management never happens inside a turn (→ 2.1, 2.2-1, 2.4-1).** DCP shows the fix is a per-step projection, not a bigger summariser.
6. **Thresholds are percent-of-window only (→ 2.9).** On DeepSeek-V4's 1,048,570-token window the first reminder comes at ~734k tokens and compaction at ~891k. DCP's absolute 50k/100k defaults, overridable per model, are the better model.
7. **The token estimate ignores the system prompt and tool schemas (→ 2.1).** DCP uses the provider-reported totals of the last step.
8. **`removeToolResults()` is a no-op (→ 2.2-1)**, so pruning old tool outputs — the single most effective cheap reduction in both DCP and opencode-native — is effectively impossible today.
9. **Only the last assistant step's text survives the turn (→ 1.B-2)** (`runTurn` returns `$lastAssistant?->content()`, `EngineBackend.php:1342`). Interim narration ("I'll check X because Y") is lost; structured replay should restore it via `stepId`.
10. **Dormant pieces this design wires rather than duplicates:** `HookEvent::PreCompact` (no dispatch site; 2.12), `Compactor`/`CompactedGroup` (UI grouping; 3.B-5).
