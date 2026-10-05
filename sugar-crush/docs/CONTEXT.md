# Context management

A long session outgrows the model's context window, and long before it does,
a crowded window costs money on every request and quality on every answer.
sugar-crush manages the window on four levels:

1. **Between prompts**, three *compaction tiers* watch the conversation and
   summarise its older exchanges before a request would be too big.
2. **Inside a turn**, every step's request is measured before it is sent and
   *pruned* — old tool output replaced by a one-line placeholder — or
   *summarised* when it is over the step budget.
3. **The agent itself** can prune its own finished tool output (`Prune`),
   compress a closed stretch of the conversation into a summary (`Compress`),
   and bring either back word for word (`Recall`).
4. **When something still goes wrong** — the provider rejects a request as too
   long, a reply is cut off at the output limit, a stream drops — the engine
   recovers instead of failing the turn.

Your transcript keeps every row through all of it. Pruning and compaction
change what the *model* is sent, never what you see on screen or what is saved
in the session.

---

## Seeing what fills the window

The status bar shows the history's size against the live model's window —
`~81K / 131K context (62%)` — and, once anything has been pruned, what the
pruning took out (`62%, −52K pruned`). While a turn runs it names the step
(`⠴ step 4`) and adds `· ctx P%` once that step's request is over its budget.
Figures marked `~` are estimates; spend figures marked `$` are the provider's
own counts.

`/context` (or `/tokens`) breaks the *next* request down: the system prompt
layer by layer, the tool schemas, the history, what pruning removed (each
pruned output by its `r17`-style ref, with why and by whom, and the files the
pruned calls read grouped by kind, `files: code ×3, config ×1`), the free space,
the largest messages, how much of each prompt the provider served from its
cache, and how many requests lost the prefix the request before them had
cached (a *cache break*: one after a prune or a compression is that rewrite's
price; two in a row mean a rewrite is not byte-stable). It is local and calls
no model.

The window is the provider's own `contextWindow()`; a backend that cannot say
(the offline echo provider) is measured against 100,000 tokens
(`Context\ContextWindow::FALLBACK_TOKENS`). The `contextWindow` setting
overrides it, then the model database (`Providers\ModelMetadata`, LiteLLM's
table, cached for a day), then the provider's built-in table — see
[Providers](../README.md#providers).

Token counts are script-weighted estimates (`Util\TokenEstimate::ofText()`):
about four characters a token for English and code, about two for other
alphabets, one for CJK, and two for an emoji, plus a small overhead per
message. Once the provider has reported a real count, the estimate is
calibrated against it.

## Compaction tiers (between prompts)

Each prompt you send is judged against three tiers. Each tier has a
percentage of the window **and** an optional absolute token cap, and fires at
whichever is lower (`CompactorConfig::tierThreshold()`):

| Tier | Percent | Cap | What happens |
|---|---|---|---|
| Reminder | `compaction.reminderPercent` 70 | `compaction.reminderTokens` 100,000 | A short notice rides along with the turn, and the summaries the next tier will need are requested **in the background** |
| Automatic | `compaction.autoPercent` 85 | `compaction.autoTokens` 150,000 | The older exchanges are replaced by summaries before the prompt is sent |
| Block | `compaction.blockPercent` 95 | `compaction.blockTokens` none | If the history is *still* over this after compacting, the turn is refused rather than sent |

The absolute caps exist because a percentage alone scales badly: on a
1M-token window, 70% is 700,000 tokens, far past the point where answers
stay sharp. With the default caps, a window up to about 142,000 tokens behaves
exactly as the percentages say; above that the reminder cap binds first, and
above about 176,000 the automatic cap does too. `compaction.modelTokenCaps`
sets different caps per model (`{"qwen3": {"autoTokens": 300000}}`, matched as
`provider/model` first, then the model id); a cap of `0` switches that cap
off. The percentages must ascend (reminder < automatic <
block), or all three fall back to their defaults. A `compaction.*` key saved
from the settings view applies to the next prompt; the full list is in
[`SETTINGS.md`](SETTINGS.md#compaction-thresholds).

Two safeguards keep the tiers from fighting the conversation:

- **An absolute tier stands down when it cannot help.** If a tier fired only
  because of its cap, and compacting could not bring the history under that
  cap anyway (the exchanges compaction must keep are themselves bigger), it
  skips this prompt instead of compacting for nothing
  (`ContextCompactor::absoluteTierIsFutile()`). A tier fired by its
  percentage never stands down.
- **A thrash breaker.** After three automatic compactions in a row that each
  left the context over the tier and the turn unsent, the next prompt is
  refused with a notice rather than compacted a fourth time
  (`compaction.refillLimit`, default `IdleCompactionPolicy::REFILL_LIMIT`).
  `/compact` and `/clear` still work.

A block-tier refusal is not a dead end: each further attempt keeps one fewer
recent exchange, and `/clear` frees the whole context at once. A session that
has grown past the whole window and sat idle for an hour
(`compaction.idleOfferSeconds`; `0` never offers) is offered `/compact` when
you come back.

### How the summaries are written

With a provider configured, the older exchanges are summarised **by a model**,
not by the local truncate-and-placeholder heuristic. The request is the
conversation's own — the same system prompt, tools and history the next turn
would send, plus one final instruction not to call tools — so everything
before that instruction is a prefix the provider has already cached, and no
tool can run or ask for permission. It uses the conversation's own model
unless `SUGARCRUSH_SUMMARY_MODEL` or the `summaryModel` setting names another
(a cache hit needs the same model, and a bad summary is permanent context
loss). The spend cap gates it: a capped session compacts on the heuristic and
says so. `compaction.mode` chooses: `llm` (the default) as described here,
`heuristic` to never make the call, or `off` to compact nothing on its own
(`/compact` still asks the model, and the block tier still refuses).

**Background summaries.** The summaries the automatic tier needs are
requested as soon as the session crosses the reminder tier, beside the turn
you just sent. When the automatic tier is reached they are usually already
there: they are spliced in and your prompt goes out at once. They are used
only while they still describe the conversation — a `/rewind`, `/clear`,
session switch or another compaction discards them — and when there is no
usable one yet, the tier parks your prompt until the summaries arrive. The
request runs off the render loop, so nothing freezes.

**State, not narrative.** A summary is a structured session-state block
(`Context\Compaction\StateSummaryTemplate`): goal, constraints, progress
(done / in progress / blocked), key decisions, current work, next step,
pending tasks, errors and fixes, plus the files read and modified and the
latest unresolved request, filled in mechanically. A later compaction merges
into the previous block instead of summarising the summary. If the model call
fails or answers something unusable, the heuristic fills the gaps and the
transcript says which one did the work.

**Rows are hidden, not deleted.** Compaction marks the condensed rows as
display-only: they stay in your transcript (and in the saved session), the
summary rows take their place in what the model reads, and a boundary notice
says the model now reads a summary.

### `/compact`

`/compact [focus]` compacts now, whatever the tiers say. The focus text steers
what the summary keeps (`/compact keep the migration plan`) and reaches
`PreCompact`/`PostCompact` hooks as their custom instructions. It answers at
once and the transcript compacts when the summaries arrive.

`/compact --self [focus]` works the other way round: the turn's own model
writes the summary with its `Compress` tool — one range over the whole closed
conversation — and **you preview it** in the permission modal before it
applies. Refusing leaves the conversation as it was. It is refused when
pruning is `off`.

A `PreCompact` hook that denies (or asks) skips any compaction, automatic or
manual, and the in-turn step summary below; `PostCompact` receives the
summary that was written. See [`HOOKS.md`](HOOKS.md#the-two-compaction-events).

### `/handoff`

`/handoff [focus]` is the way on from a context full of dead ends: instead of
compacting this session it opens a **new** one that starts from a state
summary of this one. The summary uses the same fixed headings a compaction
leaves (`Context\Compaction\StateSummaryTemplate`) — Goal, Constraints,
Progress, Key decisions, Current work, Next step, Pending tasks, Errors and
fixes — written by the summary model and steered by the focus, while the files
read and modified and your latest request are read off the transcript, never
the model. With no summary model, at the spend cap, or when the model fails,
the whole block is read off the transcript, so the command always opens a
session. The new session is a branch of this one (it keeps the todos and the
checkpoints, so `/rewind` there can step back into the full conversation), and
its transcript is that one summary, sent on its first turn. See
[`COMMANDS.md`](COMMANDS.md#the-built-in-commands).

## Inside a turn: the step budget

The tiers judge once, when you press Enter. A turn can then run hundreds of
tool steps, each adding output, so the engine also measures **every step's
request before sending it** — system prompt and tool schemas included,
anchored on the provider's own count for the step before — against a step
budget (`Context\ContextBudget::threshold()`): the smaller of 80% of the
window and the window less the output ceiling and a reserve (64K tokens, or a
fifth of the window if that is less), and never more than the automatic
tier's cap.

A request over budget gets relief, cheapest first, at most once each per step:

1. **A deterministic prune.** Older tool output that no longer earns its place
   is replaced by a one-line placeholder naming the call —
   `[Read src/Foo.php — output pruned to save context; re-run the tool if you need it]`.
   The rules run in order: a repeated identical call keeps only its newest
   output; a `Read` superseded by a newer whole-file read, or older than a
   later `Edit`/`Write` of the same path, is stale; the input of a superseded
   write and of a call that errored long ago is dropped; superseded copies of
   the harness's own state row go; and finally plain age. Output from the
   last two prompts and the newest 40,000 tokens of tool output is kept, and
   `Task`, `Skill`, `Edit` and `Write` results are never pruned
   (`Context\Pruning\PruningPolicy::PROTECTED_TOOLS`). The age rule acts only
   when it frees at least 20,000 tokens.
2. **A step summary.** If the request is still over, the agent's own model
   summarises what it has already been sent — same system prompt and tools, so
   the request reuses the provider's cache, plus an instruction not to call
   tools — and the summary (a `bN` block) stands in for those rows while the
   step in progress goes out whole. A failed summary never fails the turn.

In `auto` pruning mode the same rules, without the age rule, also run once at
the start of each turn when they would free at least 20,000 tokens.

## Pruning modes and the agent's own tools

How much pruning happens on its own is the session's *pruning mode*, from
`contextPruning.mode` (or `SUGARCRUSH_CONTEXT_PRUNING`), changeable for the
session with `/pruning auto|manual|off|default`:

| Mode | Automatic rules | `Prune` / `Recall` offered | Ref tags | `/sweep` |
|---|---|---|---|---|
| `auto` (default) | yes | yes | yes | yes |
| `manual` | no | no | yes | yes |
| `off` | no | no | no | refused |

Over-budget relief (the step budget above) runs in every mode — `off`
switches off the optional pruning, not the safety net.

**Refs.** Every tool result the model reads ends with a `<ctx-ref r="N"/>`
tag, and user prompts are numbered in the same sequence, so the model (and
you) can point at a specific output.

- **`Prune`** drops finished tool outputs from what the model is sent, by ref,
  or replaces each with a shorter *distillation* the model writes
  (`{"targets": [{"ref": "r17", "distillation": "…"}], "reason": "done"}`). It
  skips protected tools and anything already pruned, and costs no permission
  prompt. Its receipt, like `/sweep`'s, counts what it took by tool and the
  files those calls read by kind (`Read ×3; files: code ×2, config ×1`). In `auto` mode the model is nudged towards it as the context grows
  (`contextPruning.minContextTokens`, `maxContextTokens`, `nudgeFrequency`,
  `iterationNudgeThreshold`).
- **`Compress`** replaces a closed *range* of the conversation (`r12` to
  `r40`, or earlier `bN` blocks, which then nest by reference) with a summary
  the model writes, and keeps `Task` and `Skill` outputs verbatim beside it.
  A summary longer than about half of what it replaces is refused. It is offered
  only on a turn you start with `/compress [focus]` (in `auto` or `manual`
  mode), unless `contextPruning.compress` is `auto`, which offers it on every
  `auto` turn.
- **`Recall`** brings back, word for word, a pruned output (`r17`) or a
  compressed section (`b3`) as its own result — at most five calls a turn,
  40,000 bytes each. It does not undo the prune.

Commands for you:

| Command | Does |
|---|---|
| `/sweep [n]` | Prunes the tool outputs since your last prompt (or the last `n`) |
| `/pruning [mode]` | Shows or sets the session's mode; `default` follows the setting again |
| `/compress [focus]` | Starts a turn in which the model compresses the most significant closed part of the conversation |
| `/decompress [bN]` | Sends a compressed section in full again; with no argument, lists the sections |
| `/recompress [bN]` | Restores the summary `/decompress` took back |

The transcript marks each compressed section with a row such as
`▣ Compressed b3 · <topic> · −41K +2.4K`, and pruned rows carry a `⊟ pruned`
or `⊟ distilled` badge.

## After a compaction: re-injection

A summary keeps what the next step needs and drops the details. So the first
request after a compaction — `/compact`, the automatic tier, a step summary or
a `/compress` block — re-injects what was most likely lost, on the
`<turn-context>` row at the end of the request
(`Context\Compaction\ReinjectionPlan`):

- up to five of the files the agent was working on, re-read from disk (5,000
  tokens each; a larger file is named rather than included);
- the newest plan-mode plan from `.sugar-crush/plans/`;
- the bodies of the skills it had loaded (5,000 tokens each, 25,000 in all);
- a fresh git snapshot, and the open todo list.

The whole re-injection is capped at a tenth of the window. The model's own
`Compress` triggers none of it: the model chose what to keep. See
[`PROMPT_ENGINEERING.md`](PROMPT_ENGINEERING.md) for the turn-context row.

## Memory flush before a step summary

Right before the engine writes an in-turn step summary, it gives the model one
silent step in which only the `Memory` tool may run, to save what should
outlive the session — a preference, a correction, a decision and its reason —
before the summary drops it (`Context\Compaction\MemoryFlush`). It runs once
per compaction cycle, only when a summary is really about to be written, never
past the spend cap or a `PreCompact` refusal, and nothing about it reaches the
transcript but the notes it saved. The host-side compactions (`/compact`, the
automatic tier) flush the same way before their summary, on the same
once-per-cycle count kept on the session's context ledger; the overflow retry
below does not flush. See
[`MEMORY.md`](MEMORY.md#memory-flush-before-compaction).

## Recovery

- **Too long for the window.** A request the provider rejects as a context
  overflow (`Providers\ContextOverflow`) gets the step relief at full
  strength — every older tool output becomes its placeholder, then the
  summary — and is sent once more before the turn fails.
- **Cut off at the output limit.** A reply that stops at its output limit
  without calling a tool is continued where it stopped, up to three times, and
  reads as one answer. Where the provider allows it (`sglang`, and Claude on
  `vertex` or `bedrock`) the cut reply goes back as the start of the model's
  own message; elsewhere a request to continue is sent. Only a reply still cut
  off after that carries a notice.
- **A dropped stream.** A stream that drops after the reply has started
  showing is continued ("Continue where you left off") rather than restarted,
  so what you have read stays and the rest is appended; one that drops before
  anything showed is simply retried.
- **Empty replies.** A reply that only reasoned gets one nudge asking for an
  answer or a tool call; a fully empty one is re-requested up to twice.
- **Out of steps, or looping.** A turn that hits `maxToolSteps` (default 1000)
  or the repeat-call loop guard (the same tool, arguments and result: warned
  on the 3rd call, refused on the 5th, the turn ended on the 8th) gets one final
  request with tools disabled, asking the model to summarise what is done,
  what remains and what comes next.

## Sub-agents watch their own window

A delegated run — a `Task` sub-agent, a workflow stage — has its own context
and its own ledger. Once a request it sends still uses 80% of its window after
the step's relief, the model is told once to wrap up or hand off; at 90% the
run is stopped, and the `Task` result carries its partial output and a resume
id, so resuming it is the hand-off
(`Backend\EngineBackend::SUB_AGENT_WRAP_UP_PERCENT`). See
[`AGENTS.md`](AGENTS.md).

## Big tool outputs

Every tool result is bounded before it ever reaches the context:

- **Caps.** `Bash`, `Grep`, `Glob`, `Lsp` and `WebFetch` output is capped at
  `toolOutputCapBytes` (64 KiB), an MCP result at `mcpResultCapBytes`.
- **Spill.** A result larger than `toolSpillWindowPercent` (30%) of the
  window is not truncated blind: the full output goes to a private session
  file and the model gets a head-and-tail preview plus the path, which `Read`
  and `Grep` can open. `Task` and `Skill` results are exempt.
- **Paging.** `Read` returns a file a page at a time (`readPageLines` 2000
  lines, `readPageBytes` 50 KiB), with line numbers and an `offset`/`limit`
  to page on; a file too long for one page gets an outline (from a language
  server when one is configured).
- **Stale edits are refused.** The session keeps a ledger of what each file
  looked like when it was read (`Tools\ReadLedger`); an `Edit`, or a `Write`
  over an existing file, whose content changed since the model last read it
  is refused, so the model re-reads instead of editing a version that no
  longer exists.

All of these are settings (*Tools* rows of [`SETTINGS.md`](SETTINGS.md#every-key)),
and a project may not raise them, since every byte of a result is billed again
on each later request of the turn.

## At a glance

| Want to | Use |
|---|---|
| See what fills the window | `/context` |
| Compact now | `/compact [focus]`, or `/compact --self` to preview a model-written summary |
| Free old tool output now | `/sweep [n]` |
| Let the model compress | `/compress [focus]` |
| Change how much happens on its own | `/pruning auto\|manual\|off`, `contextPruning.mode` |
| Move the tiers | `compaction.*Percent`, `compaction.*Tokens`, `compaction.modelTokenCaps` |
| Summarise with a cheaper model | `summaryModel` / `SUGARCRUSH_SUMMARY_MODEL` |
| Carry on in a fresh context | `/handoff [focus]` (a new session seeded with a state summary) |
| Start over | `/clear` (keeps the session and its checkpoints) or **New session** |

## See also

- [`SETTINGS.md`](SETTINGS.md#compaction-thresholds) — the compaction and
  pruning keys.
- [`PROMPT_ENGINEERING.md`](PROMPT_ENGINEERING.md) — how the system prompt and
  the turn-context row are built, and why their bytes stay stable for the
  provider's cache.
- [`MEMORY.md`](MEMORY.md) — notes that outlive a compaction.
- [`HOOKS.md`](HOOKS.md) — `PreCompact` and `PostCompact`.
- [`AGENTS.md`](AGENTS.md) — sub-agents and their own context.
- The [README](../README.md#documentation-index) — every other page.
