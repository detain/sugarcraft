# Prompt engineering — why the system prompt is layered the way it is

This page is the rationale register for sugar-crush's prompt layering, written so the design
survives contributors who did not design it. The order of record is the code in
`Runtime::systemPromptSections()`; nothing here is authoritative against it. Where this page
explains a decision it names the symbol that carries it, and where a guard test pins a claim the
guard is named. Line numbers are deliberately absent — they rot, and a doc that rots is worse than
a doc that does not exist.

For the assembly-order list in the architecture survey see `docs/ARCHITECTURE.md` ("The system
prompt, in assembly order"); this page is the *why* beside that *what*.

## The thirteen slots, in order of record

`Runtime::systemPromptSections()` returns an ordered list of `PromptSection`s, base first and the
static `<env>` block last. Counted from the live method, there are thirteen slots:

1. **Base identity** (`Runtime::basePrompt()`) — the Static heredoc that opens every prompt.
   Unfenced, because it is harness voice with no untrusted input. For the DeepSeek-V4, Qwen3.8
   and MiniMax families (`ModelFamily::of()`, read off the served model when the provider reports
   one) its "Acting vs. asking" section closes with a per-family paragraph (`FamilyPrompt::of()`):
   a family lead plus Aider's `lazy` and `overeager` reminders, restated without emphasis. Every
   other model gets the heredoc unchanged. The family is resolved once per session and model, so
   the paragraph never moves inside a cached prefix.
2. **Maxims** (`MaximsSection`) — the `core.maxims` voice layer, directly behind the base identity
   and ahead of every derived layer. Static and unfenced: its bytes are class constants.
3. **Tool guidance** (`Runtime::toolGuidanceSection()`) — appended only when at least one wired
   tool implements `PromptGuidance` and contributes a non-empty fragment; with no qualifying tool
   the slot is absent from the list entirely, not rendered empty. Fragments are ordered by
   `name()`, so the bytes cannot depend on registration order. Static.
4. **Repo map** (`RepoMapBlock`) — fenced `repo-map`; per-session memoized snapshot of derived
   repository facts.
5. **Symbol map** (`SymbolMapBlock`) — fenced `symbol-map`; the definitions the rest of the workspace
   references most, ranked by PageRank over which files use which names (an Aider repo map, roadmap
   5.5-5), unfocused and capped at 1,024 tokens. PerSession and byte-stable: `EngineBackend` captures
   it once per session in the parent before a turn's fork (`Runtime::primeSymbolMap()`) and the
   session memo holds it until a refresh point; a session with no capture assembles no slot at all,
   so every other prompt build is unchanged.
6. **User-tier rules** — each enabled rule from `RuleLoader::load()` whose tier is `user` gets its
   own fence, `user-rules`, with the operator-authority preamble — except a `paths:`-scoped rule,
   which is delivered at tool time instead (see the trigger bullets below).
7. **Instruction documents** — `InstructionFileLoader::loadRoot()` then `loadForced()` (read
   through their path-keyed sibling `loadDocuments()`), each non-blank document its own
   `project-instructions` fence with the project-authority preamble. Budgeted like the rules
   (audit 15d-09, C3): each document's framed, post-escape section is held to
   `Runtime::MAX_INSTRUCTION_DOCUMENT_BYTES` and all of them together to
   `Runtime::MAX_INSTRUCTION_BYTES`, and the loader does not read a file over
   `InstructionFileLoader::MAX_DOCUMENT_BYTES` at all. A document that does not fit is never
   clipped: it becomes one `InstructionFileLoader::pointer()` line, naming the file and its size,
   in a deferral fence, and a `refusedPaths()` entry. An `@import` that would carry its document
   past that ceiling is replaced at its import site by an `import-deferred` note carrying the same
   pointer line. The verdicts are made in one place, `Runtime::planInstructionDocuments()`, which
   the launch also runs so one launch notice can name each file left out and why (audit R1).
8. **Project-tier rules** — the same `project-instructions` fence and preamble as the documents,
   because the authorship claim is identical: bytes shipped inside the checkout. A
   `paths:`-scoped project rule is skipped here the same way.
9. **Memory** (`MemoryBlock`) — fenced `project-memory`: an INDEX, one line per note (type, id,
   opening words, tags), never the note text; the user's own cross-project notes first (at most 4
   notes / 1 KB), then the project's, memoized per session like the repo map, followed outside the
   fence by the standing instructions on when to save a note and what not to save (anything the
   code already says), and the mandatory recall step: search memory — the turn's `memory-recall`
   block, then the `Memory` tool's `recall` — before answering about prior work. Agent-scope notes
   never reach it.
10. **Enabled skill bodies** — every skill in `$app->enabledSkills` contributes its full
   `Skill::systemPromptContribution()` as a PerTurn section, name and body through
   `PromptFence::escape()`. Held to `CompactorConfig`'s `skillBudgetPerSkill` and
   `skillBudgetCombined` tokens, measured with `TokenEstimate::ofText()`: a body over either keeps
   its `## Skill:` heading and is replaced by one line saying how to load it (the Skill tool, or
   Read on its file), never clipped. The budgets are the App's `compactorConfig` when it carries
   one, else `CompactorConfig::new()`'s defaults. An engine turn's App always carries one:
   `EngineBackend::withCompactorConfig()`'s, else those same defaults — the source the launch
   notice prices against, since no settings key feeds compaction budgets. Each build records its
   deferrals on `Runtime::skillDeferrals()`, and the launch names them in one notice (audit R1).
11. **Skill listing** — `SkillMatcher::listForPrompt()` names the remaining *discovered* skills at
    level-1 metadata (name and description), excluding those whose bodies the previous slot
    already carries. PerTurn. Fenced `available-skills` with the skill-listing preamble, because
    names and descriptions are skill authors' text — a cloned checkout's `.claude/skills` among
    them, read with no trust gate. Every line goes through `SkillPromptLine::render()`: collapsed
    to one line, `PromptFence::escape()`d, then clipped to `SkillPromptLine::LISTING_MAX_BYTES`, and
    opened with a provenance badge from `SkillOrigin::badge()` — `[built-in]`, `[user]`,
    `[project]`, plus `foreign: claude` or `foreign: opencode` for another tool's format
    (audit 15d-02). `SkillPathNudge` uses the same helper at its own entry cap, without the badge.
12. **Plan mode** (`PlanModeSection`) — fenced `system-reminder`, present only while the turn's
    permission gate is in `plan` mode (read off the hook chain's `PermissionGateHook`, which the TUI's
    `Alt+M` toggle swaps between turns; roadmap 5.7-1). It states what the gate enforces — read-only
    shell, every change refused, the one write a Markdown plan in `.sugar-crush/plans/` — so the
    model is told rather than finding out one denied call at a time. PerTurn, and placed directly
    ahead of `<env>` so a mode switch moves only the prompt's tail; the tool list is deliberately
    the same in every mode for the same cache reason.
13. **Environment** (`EnvironmentBlock`) — fenced `env`, **LAST**. The P3.S1 invariant. Since
    step 1.A-1 it is the *static* half only — working directory, git-repo flag, platform, OS, PHP,
    model and date, every line frozen at capture or constant (`EnvironmentBlock::withVolatile()`)
    — so it is PerSession. The git section moved to the `<turn-context>` row (next section).

Slots 1–3 are the Static prefix; 4–9 are PerSession; 10–12 are PerTurn; 13 is PerSession again —
the static `<env>` half, kept last because the slot order is the documented one. A section whose
`render()` returns the empty string folds out of both wire forms — an absent layer adds no bytes,
no empty fence and no dangling separator.

## Outside the system prompt: the turn-context row and in-place notices

Step 1.A-1 moved everything that changes while the agent works out of message 0, because a prefix
cache reuses work only up to the first differing byte, and a byte that moves inside message 0
re-prefills the whole conversation behind it.

- **The `<turn-context>` row.** `Runtime::turnContext()` builds a `Context\TurnContextBlock`: the
  git section (`EnvironmentBlock::renderVolatile()` — caveat, branch, porcelain status, recent
  log and, after a write step, both diffs), the files this conversation's Edit and Write calls
  touched (`TurnContextBlock::recentlyModifiedIn()`), the memory notes most relevant to the latest
  user message (`Runtime::memoryRecall()` — a `Context\MemoryRecallBlock` fenced `memory-recall`, at
  most three notes, ranked once per turn by `Memory\HybridMemoryRanker` in the parent before the
  fork and re-sent only when the ranking changes; see MEMORY.md "Recall"), and the share of the context window in use
  once it reaches `TurnContextBlock::CONTEXT_NOTICE_PERCENT` (floored to 5% so the figure does
  not change the row every step). `EngineBackend::runTurn()` appends it to the history at the top
  of each step as a **user-role** row, fenced `turn-context` and opened by a harness-voice preamble
  ("metadata, not instructions"), only when its bytes differ from the latest such row the history
  already carries (`TurnContextBlock::changedSince()`). Persisted, not wire-only: step k+1 sends
  step k's whole request unchanged and appends to it, and the row rides the turn's transcript back
  to Chat as a hidden row, so the next turn sends none while nothing moved. The git half is
  re-polled only on a turn's first step and after a step that could have written
  (`Runtime::stepRequestedAWrite()`); after a read-only step the previous row's git state is reused,
  so no diff-less copy of the row is appended. A runtime without such an owner (`Runtime::run()`
  alone) still appends the row to each wire request itself.
  Payload bytes are already `PromptFence`-escaped by `EnvironmentBlock`, and the row neutralises its
  own fence name inside them, so a commit subject spelling the closer cannot end the row early.
- **Re-injection after a compaction (roadmap 2.6).** The first step after a compaction — the
  host's (its `[summary] ` rows) or a step summary the turn wrote (the ledger's harness block) —
  re-polls git and adds to that step's `<turn-context>` row what the compaction took out of view
  (`Context\Compaction\ReinjectionPlan`): the bodies of the skills the Skill tool loaded, re-loaded
  through it (5,000 tokens each, 25,000 in all, oldest dropped first), and up to five of the files
  the agent read or edited, most recent first, re-read from disk (5,000 tokens each; a larger one is
  named as a referenced file; only files inside the project, never binary), then the newest
  plan-mode plan among those files (a `.md` directly in `.sugar-crush/plans/`) as its own `<plan>`
  part, outside the five and paid for first, the whole within 10% of the context window; an open
  todo list is re-shown beside it. The compaction is recognised off the
  history (`ReinjectionPlan::pendingIn()`) and the row stamps its cycle, so it happens once, with no
  flag across the fork and no file I/O on the render loop. Every row also carries the roster of
  skills loaded this session (`TurnContextBlock::withInvokedSkills()`), which is how a skill whose
  Skill call a compaction hid is still re-injected.
- **Files changed since the model read them.** `Tools\ReadLedger` (roadmap 3.I-2) is the
  session's read ledger: for each file, the stat signature and an `xxh128` hash of the bytes `Read`
  showed the model (hashed during the line-count pass, so it costs no second read) or that `Edit` and
  `Write` wrote. An `Edit`, or a `Write` with `overwrite`, of a file whose content no longer matches
  is refused in place — the error names the path, says the file was left unchanged and tells the
  model to Read it again — and the instance that enforces this says so in its tool description. It
  compares content, not the clock, so a `touch` or a rewrite of identical bytes is not a change, and
  a file the model never read is never refused (this is staleness, not read-before-write). The
  ledger crosses a parallel `Read` child through `CarriesSessionState` and the turn child through
  the `result` frame's `readLedger` key, so it lives as long as the session's tool set; at most 256
  paths are kept. `ReadLedger::notice()` renders the matching "files changed on disk since you last
  read them" paragraph for the `<turn-context>` row (`TurnContextBlock::withChangedSinceRead()`),
  re-read at the top of every step.
- **Pruned tool output and superseded turn-context rows.** When a step's request
  is over its budget, `EngineBackend::runTurn()` prunes before sending
  (`Context\Pruning\EmergencyPrune`), and `Runtime::buildMessages()` projects every
  request through the session's `Context\Pruning\ContextLedger`. An old tool result
  then reads, in place of its output,
  `[Read src/Tools/Bash.php — output pruned to save context; re-run the tool if you need it]`
  — the tool and its main argument, on one line, bounded to 120 characters
  (`Context\Pruning\PrunedOutputPlaceholder`) — and every `<turn-context>` row but
  the newest is left out, as its own preamble says it supersedes them. Both are
  pure functions of the rows and the ledger, and the ledger moves only at a
  few deliberate points — that over-budget point, a turn's start in the `auto`
  pruning mode (`Context\Pruning\TurnStartPruning`: superseded rows only —
  repeated calls, reads an edit made stale, replaced write content, long-failed
  inputs, old `<turn-context>` rows), and
  a person's `/sweep` — each in one batch, the automatic ones only once they
  free at least 20k tokens: the bytes before the first pruned row are the bytes
  the previous request sent. The ledger is the session's, so the next turn
  starts from it rather than from the full history.
- **Ref tags.** Unless the session's pruning mode is `off` (`/pruning`,
  `contextPruning.mode`, `SUGARCRUSH_CONTEXT_PRUNING`), every tool result ends
  with its ref on a line of its own — `<ctx-ref r="17"/>`
  (`Context\Pruning\RefTag`) — pruned placeholders included, so a result can
  be named (`r17`). A ref is the session's: handed out once per result in the
  order the model first read it and never reused or renumbered
  (`ContextLedger::refsFor()`, fixed where the turn ends), so the tag is the
  same bytes on every request. A tag the model copies into its own reply is
  stripped from the assistant text before the next request is built. User
  prompts carry refs too, in the same numbering (`ContextLedger::rowKeys()`,
  keyed by the prompt's bytes), so a range can start or end at one; a harness
  row (`<turn-context>`, a summary row) carries none, and the prompt being
  answered gets its ref once something follows it — a range never reaches it.
- **The model prunes too.** In the `auto` mode the model is offered `Prune`
  (`Tools\BuiltIn\Prune`): `{"targets": [{"ref": "r17", "distillation": "…"}],
  "reason": "noise|superseded|done"}`. Each target's output is replaced by the
  same one-line placeholder, or — with a distillation — by
  `[Read src/A.php — distilled by the model; re-run the tool for the full output]`
  and the model's own text. It lands in the turn's own ledger, so the turn's
  very next request already carries it, and the session keeps it like any other
  prune. Outputs of `Task`, `Skill`, `Edit` and `Write` (and `Prune`'s own
  receipts) are never pruned, and a distillation must be shorter than the
  output; such targets are skipped and named in the receipt. A `manual` or
  `off` session and a `-p` run are not offered the tool.
- **Sub-agents prune their own run.** A `Task` run granted `Prune` (its
  preset's `tools:` lists it, or names no tools) runs, in the `auto` mode, on
  an ephemeral ledger of its own: its refs number only its rows, its prunes
  and reminders never touch the parent's ledger, and nothing of it is kept
  with the parent session. The delegated prompt is never pruned (`Prune`
  refuses a prompt, and `Compress` needs a person's `/compress`). The ledger
  is saved with the run's transcript, so `resume` continues on the same
  pruned view. A preset that does not grant `Prune` runs with no ledger and no
  ref tags at all.
- **Nothing pruned is lost.** `Recall` (`Tools\BuiltIn\Recall`), offered
  wherever `Prune` is: `{"ref": "r17"}` returns a pruned output word for word
  (or a call's elided input, or a prompt or result a compressed section stands
  in for), and `{"ref": "b3"}` every row a compressed section replaced. The
  original arrives once, as the call's own result; the ledger is unchanged, so
  nothing already cached is rewritten. A ref still in view in full is refused;
  at most 5 calls per turn and 40000 bytes per call.
- **The model compresses, when asked.** `Compress` (`Tools\BuiltIn\Compress`):
  `{"topic": "Auth exploration", "ranges": [{"from": "r12", "to": "r40",
  "summary": "…"}]}` replaces a closed range — prompts, steps and outputs — with
  the model's own summary, as a range block in the turn's ledger: the next
  request sends one user row, `[Compressed section b3: "Auth exploration" —
  replaces r12…r40]` and the summary, in the range's place. A range that covers
  an earlier block names it once as `(b2)`, expanded to that block's summary;
  `Task` and `Skill` outputs inside it are re-attached verbatim. A summary more
  than half the tokens it newly replaces plus 2000 is refused (opencode-dcp's
  compression snowball), and so is a block whose placeholders would expand past
  16K tokens. Compress is MANUAL by default: it is offered only on a turn you
  start with `/compress [focus]`, whose prompt opens `<compress triggered
  manually>`, and that turn may make one successful call. `/decompress bN` and
  `/recompress bN` take a block back and restore it. `/compact --self [focus]`
  sends the same trigger with a `[compact --self]` line asking for ONE range
  over the whole closed conversation, written as a hand-over; on that turn the
  call is put to the person as a question showing the summary
  (`Context\Pruning\CompressPreviewHook`), so it applies only once read.
- **Context reminders.** In the `auto` mode, once the context passes 60K tokens
  the model is reminded to manage it (`Context\Pruning\NudgePolicy`): a
  `<context-reminder>` block — one fixed text per kind: a turn's prompt, a run
  of ten tool results since it, or past 120K the strong limit reminder —
  appended to the newest tool result or prompt, never to an assistant row (a
  request ending on a synthetic assistant turn is a prefill some providers
  reject). The anchor is kept in the ledger and re-rendered on that row on
  every later request, five rows at least between two, so a reminder never
  moves along the tail; a successful `Prune` or `Compress` clears them all.
- **Step summaries.** When a request is still over its budget after that prune,
  the engine asks the turn's model for a summary with the request it last sent
  plus one user row, `StepSummarizer::INSTRUCTION` ("…Do not call any tools —
  answer with the summary only. Cover, in this order: the user's requests (quote
  the most recent one verbatim); what has been done so far…"), keeping the same
  system prompt and tool block so the request is a cached prefix. The summary
  then stands in for those rows as one user-role row opening
  `[Conversation summary b1 — the harness condensed the earlier part of this
  conversation to free context; this summary replaces it]`
  (`Context\Pruning\CompressionBlock::HEADER`) — user-role, not system, because
  a system row past index 0 is hoisted or refused by several templates.
- **History system rows stay in place.** `SglangProvider::placeSystemRows()` — shared by
  `CustomProvider` — keeps ONE leading `system` row: the assembled prompt, then any history system
  rows that precede the first non-system row (a launch notice, the title one-shot's instruction).
  Every later system row — a cancellation marker, a compaction or context-tier notice, a
  queued-prompt notice — stays where it happened as a user-role `<system-notice>` row
  (`SglangProvider::systemNoticeContent()`). Hoisting those into message 0 made each notice void
  the whole prefix, and a `system` row at index > 0 is an HTTP 400 on the Qwen-family templates
  ("System message must be at the beginning.").

`tests/Providers/PromptPrefixByteStabilityTest.php` drives a real write-then-read turn and pins the
result: every wire row a step sent — its `<turn-context>` row included — is byte-identical in the
next step's request, message 0 included; `tests/Backend/TurnContextPersistedTest.php` pins that an
unchanged state is sent once across read-only steps and into the next turn. `tests/Prompt/PromptSnapshotDriftTest.php` pins both halves
against committed snapshots — a per-slot manifest of the system prompt (slot, stability, fence,
bytes, hash) and the turn-context row of the golden fixture.

## Freshness: one policy for every standing layer

The PerSession layers — the static `<env>`, the repo map, project memory and the standing
instruction slab (user rules, `CLAUDE.md`/`AGENTS.md` with their `@import`s, forced globs, project
rules) — are memoised per **session** through `Context\SessionPromptMemo`, not per `Runtime`
(which is per turn). They are read once, at the session's first build, and stay frozen until the
session's entries are dropped with `SessionPromptMemo::forget()`: on `/clear`, after a compaction
or rewind, or when another session id arrives. `EngineBackend` reads those points off the history
each turn runs on (`SessionPromptMemo::observeTurn()`): a history that no longer starts with the
previous turn's rows, or a turn from a different session than the last. An edit to `CLAUDE.md` mid-session therefore takes effect at
the next of those points — the trade Claude Code and Aider make, immediacy for a prefix that does
not move. Inputs that legitimately change a layer inside a session are part of its memo slot
instead of being frozen: the project root, the model name, the `/rules` toggle set and the
instruction-loader instance. `InstructionFileLoader` keeps its own per-instance cache, so the
refresh point for instruction documents is `forget()` together with
`InstructionFileLoader::refresh()` on the same, tool-shared instance. The memo lives on the parent
side of the per-turn fork: `EngineBackend` holds one per session and primes it before forking each
turn (`Runtime::primeSessionPrompt()`), so every turn's child inherits the layers warm.

## Why that order

Two ladders are the same ladder, laid out in different currencies:

- **Mutation frequency.** `Stability` orders the layers by how often their bytes change, because
  Anthropic-side prefix caching bills any change anywhere in the prefix as a miss for everything
  after it. The cacheable identity rides first, the session snapshots next, the per-turn volatile
  material last. `<env>` LAST was load-bearing (the P3.S1 decision, recorded in
  `Runtime::systemPromptSections()`): the block live-polled git status, so any position earlier
  than the end would void the cacheable prefix for every layer behind it from the first file write
  of the session. Before P3.S1 the git block sat near the front, and the ordering note in
  `docs/ARCHITECTURE.md` still carries the corrected record of that inversion. Step 1.A-1 finished
  the job by moving the git section out of the system prompt altogether, into the `<turn-context>`
  row; the static `<env>` half stays last.
- **Authority.** The base identity and maxims are harness voice and outrank everything. The repo
  map is harness-*derived fact* and sits with them because it is the same kind of thing the base
  is: read who you are and what is where, before the conventions that talk about both. The
  operator tier (`user-rules`) sits above every project-voiced layer because the operator outranks
  the repository, and below the harness layers because the harness outranks the operator. Project
  documents and project rules share a fence because they share an authorship claim. The two
  preambles assert exactly this ranking in prose, so a reader never has to reconcile a claim
  against a position.

Skills land after memory because a skill set is re-derived per `App`, and the listing comes after
the bodies because a skill whose full instructions already stand in the prompt has no business also
being advertised as a one-line call suggestion.

## Stability classes

`Stability` is a three-case enum — `Static`, `PerSession`, `PerTurn` — and a SugarCraft
architecture type, not a port. The classification is currently *declared, not acted on*: the
assembler's ordering is fixed by construction, not by consulting the enum, and each section's
`byteBudget()` is an advisory ceiling that no cap enforces yet. The consumers that act on the
tiers are the cache-breakpoint seam and per-tier compaction downstream. A boolean would not do:
"not static" cannot tell a per-session snapshot (safe to hold across the steps of an agentic loop)
apart from the git block (polled live on every render — which is why, since step 1.A-1, it is the
`<turn-context>` row and no longer a system-prompt section at all).

## The assemble invariant

`Runtime::assembleSections()` is one fold producing both wire forms: the flat system-prompt string
and the ordered block list that `CompleteRequest::$systemBlocks` carries. Both arms write from the
same accumulator, so concatenating the blocks byte-for-byte yields the string — the identity holds
*by construction*, not by convention. `Runtime::run()` destructures that fold into the
`CompleteRequest`.

The separator rule lives in exactly one place: adjacent rendered sections are joined by one blank
line, and a body that already opens with one is never given a second. Naive `implode` over the
bodies would double the separators the golden fixtures pin byte-for-byte. The fold also preserves
the one-render-per-build cost contract, and since step 1.A-1 the git half is not in the fold at
all: the static `<env>` section polls no git, and `EnvironmentBlock::renderVolatile()` pays its
five git subprocess polls once per step, for the `<turn-context>` row, never once per wire form.

## Fence and provenance rules

Dynamic bytes entering a fenced region are defanged by one authority, `PromptFence`, and by no
other:

- **One escape authority at every splice.** `PromptFence::escape()` is a dependency-free static so
  that every construction site — including the fences assembled inline in
  `Runtime::systemPromptSections()`, which no block class renders — reaches the same head.
- **The whole roster everywhere, not just the host fence.** Escaping only the enclosing tag would
  leave a payload free to open a nested instance of any other fence (unbalancing the section
  bounds a reader model tracks) or to forge `system-reminder`, which is a trust channel rather
  than a section. In the assembled prompt every roster tag is a delimiter regardless of which
  body carries the bytes.
- **The roster is the eleven tags `PromptFence::tags()` returns**, read at this base: `env`,
  `project-memory`, `repo-map`, `project-instructions`, `system-reminder`, `user-rules`,
  `prior-summary`, `harness-injected`, `available-skills`, `turn-context`, `system-notice`. Two entries are noteworthy in kind:
  `prior-summary` is the tag `Chat::renderPriorSummariesForSummary()` wraps in a *summariser
  request* outside the assembled system prompt — foreign by exactly the route a repo file is — and
  `harness-injected` is the roster's one pre-registered, defang-only tag: nothing emits it yet, and
  a tag added before the bytes that need it is free, whereas a tag added after leaves a forging
  window. `available-skills` is the fence around the skill listing (slot 11), whose names and
  descriptions are skill authors' text (audit 15d-02). `turn-context` and `system-notice` fence
  user-role rows outside the system prompt — the per-step state row and a history notice kept in
  place — and are on the roster so no repository byte can spell either harness voice.
- **Attribute-bearing and unterminated tags count.** A roster name matches when whitespace, `/`,
  `>` or the end of the payload follows it, so `<system-reminder priority="high">`, an attribute
  list broken across lines and an unterminated `<system-reminder foo="x"` all lose their `<` — a
  reader model takes each for the channel opening. `<envx>` (another name) and `< env>` (not tag
  syntax) stay byte-intact.
- **Chat-template control tokens too (audit 15d-10).** The same rewrite defangs the `<` of a
  control-token opener, `<|` or `<｜` (the fullwidth U+FF5C bar) with an optional `/` after the
  `<` — the bars `PromptFence::CONTROL_TOKEN_PIPES` lists — so `<|im_start|>` and `<｜User｜>`
  reach the prompt as `&lt;|im_start|>` and `&lt;｜User｜>`. The roster guards the fences a model *reads*; these guard the role boundaries a tokenizer
  *encodes* — a different layer, but the same forgery through the same splice, so it rides the one
  authority. A `&lt;|` in a prompt rendering is this rewrite, not corruption.
- **Escape, do not drop.** The rewrite replaces only the leading `<` of a matched tag with its HTML
  entity, so `&lt;/env>` is inert yet information-preserving; deletion silently rewrites commit
  subjects, and looser respellings invite lenient re-matching. Clean payloads render
  byte-identically, which is what keeps the golden corpus unperturbed.
- **Idempotent.** An already-escaped tag has no live `<` at a tag start to re-match, so a second
  pass provably changes nothing.
- **Fail fast.** A PCRE failure returns null, and null throws — shipping an unescaped body because
  one regex gave up is the swallowed-error shape this tree refuses. Prompt assembly has no silent
  fallback.
- **Escape before clip.** `RepoMapBlock`, `MemoryBlock` and `SkillPromptLine` run each line through
  `PromptFence::escape()` and only then through their `clip()` budget, because neutralising a tag
  *grows* the line and the growth must land inside the cap the budget promises.
- **Byte-oriented, no `u` modifier.** Diff bodies and paths can carry invalid UTF-8; a
  `/u` pattern on invalid input does not degrade, it fails. The roster names are ASCII.
  Encodability is a separate guarantee, made earlier: every loader that reads a prompt source
  off disk — instruction files and their imports, rules, skills, memory notes, the repo map's
  manifests — scrubs it to valid UTF-8 through `Utf8Scrub` (audit 15d-08), because the prompt
  is JSON-encoded into every request and one invalid byte used to fail all of them.

The provenance voice rides under its opener, split from the escaped body by a blank line:
`Runtime::USER_RULES_AUTHORITY_PREAMBLE` asserts operator authorship and states where it outranks
and where it yields; `Runtime::INSTRUCTIONS_AUTHORITY_PREAMBLE` asserts repository-maintainer
authorship and disclaims precedence over the harness layers above;
`Runtime::SKILL_LISTING_AUTHORITY_PREAMBLE` says the listing is harness-assembled while each
entry's text is its skill author's, explains the provenance badge and disclaims precedence the same
way; the `repo-map` and
`project-memory` fences open with count-bearing headers their block classes render instead of
preambles, because those layers describe derived state rather than claim authority.

The escape semantics are pinned at the splice level by the forgery guards
`BaseSystemPromptTest::testForgedInstructionDocumentCannotForgeFencesOrAuthorityVoice()`,
`BaseSystemPromptTest::testAForgedUserRuleBodyCannotEscapeItsOwnFence()`,
`BaseSystemPromptTest::testAForgedHarnessInjectedCloserInsideAnInstructionDocumentCannotRender()`,
`BaseSystemPromptTest::testAForgedSkillDescriptionCannotEscapeOrForgeAFence()`
and, on the summariser path, `Chat\CompactModelSummaryTest::testAForgedPriorSummaryCloserTravelsIntoTheNextRequestDefanged()`;
the roster itself is pinned whole by
`Context\PromptSectionTest::testTheEscapeRosterIsExactlyTheDerivedFenceTagList()` and its
defang-only entry by
`Context\PromptSectionTest::testEscapeNeutralisesTheHarnessInjectedTagThatNothingEmits()`.
Deleting a roster entry or an escape call reddens those tests; prose here never substitutes for
them.

## Cache breakpoints — the contract, and where it is armed

`CacheBreakpoints` implements the Anthropic prompt-cache mark plan: applied **wipe-then-reapply**
on every step of an agentic turn (upstream measured that without the wipe each step *adds* its
marks and the request crosses the API cap and 400s), budget of `CacheBreakpoints::MAX_BREAKPOINTS`
— four — for the whole request, default plan last-tool + last-system + last-two-messages, with a
20-block lookback repair chain and intermediate marks spaced inside it. When a gateway injects its
own automatic (non-ephemeral) cache marks those are preserved, never wiped and never overwritten,
but they consume slots from the same cap: `CacheBreakpoints::BUDGET_WITH_AUTOMATIC` is the
explicit budget minus one. Input already breaching the cap on foreign marks throws rather than
returning a doomed request.

**It is armed on one wire: Claude on `vertex`** (audit A15). `VertexProvider` hands every
Anthropic-shaped request body to `apply()` just before it is sent, which is every step of a turn.
The system prompt rides in as a leading `role: system` turn **in block form** and is lifted back
into the top-level `system` field afterwards, so the mark survives — the hazard this page used to
record (the string arm discards in-messages system rows) is closed by never using the string arm
for a marked request. `bedrock` places its own Converse `cachePoint` blocks rather than calling
the class, because Converse spells a breakpoint as a separate block, not a `cache_control` field.
No other provider marks anything: `openai` and `sglang` cache server-side without marks.

- The switches: the user-tier `promptCache` setting (on unless `false`) and
  `SUGARCRUSH_DISABLE_PROMPT_CACHE`, which outranks it, are read once when `ProviderFactory`
  builds the provider. Off means `apply()` is not called at all, so the body is byte-identical to
  an unmarked one. `CacheBreakpoints::disabledFromEnvironment()` stays the only reader of the
  variable, static so `apply()` itself stays environment-free.
- Models that never offered caching (Claude 3 Sonnet on Vertex) are not marked.
- `CacheBreakpoints::observeCacheHealth()` is fed by the turn loop: `EngineBackend` hands it
  every provider response's `Usage` (each step, and a stopped turn's summary request) through a
  backend-owned `Backend\CacheHealthWatch`, but only while the provider says the model's requests
  carry marks (`Providers\MarksPromptCache`, implemented by `vertex` and `bedrock`). The third
  consecutive response reporting both cache buckets at zero raises one `RuntimeNoticeSink`
  notice for the session; `openai`, `sglang`, Gemini and a provider with the marks switched off
  are never warned. On the forked TUI path the streak and the "already said" bit ride home on the
  turn's result frame, so the count spans turns. The buckets are also priced (see `modelPrices`
  in `SETTINGS.md`).
- The same watch also counts cache BREAKS, for every provider that reports a cache split
  (`CacheHealthWatch::observeReuse()`, opencode-dcp's #614 lesson): within one turn's
  conversation, a request that reads under half of the prefix the request before it sent —
  within 240 s, so an idle eviction is not one — lost bytes the provider had cached. One break
  after a prune or compression is that rewrite's price and is only counted; two in a row means
  the cache is not recovering, and one `RuntimeNoticeSink` notice says so for the session. A
  delegated run's requests are another conversation and are never compared with the parent's.

## Session affinity — the request carries the session

`Providers\Concerns\SessionAffinity` declares the `X-SugarCrush-Session` header (full SHA-256 hex
of the session id, never the raw id — a raw id pins one user's traffic and leaks a persistent
identifier to every proxy hop; and no header at all rather than an empty one when there is no
session, so session-less traffic does not concentrate on one warm backend). The trait ships on
`CustomProvider` and `SglangProvider`, and the id is **request-scoped**: `Chat` stamps its current
session onto the engine on every dispatch (`EngineBackend::withSessionId()`), the engine onto the
turn's `App`, and `Runtime::run()` onto each `CompleteRequest::$sessionId`, which the two
providers' completion post sites hash into the header. Because the id rides the request rather
than the provider, it follows `/resume`, `/branch` and session switches without rebuilding the
provider. The same per-turn id is what the hook chain now receives as `sessionId` on the engine
path (it used to be empty). A turn with no session — `-p`, a chat not yet saved — still sends no
header, and `embeddings()` (whose request names no session) falls back to the provider's
constructor id, which is null on the wired path.

## The "do not do this" register

`prompt_expand.md` §9.12 enumerates six standing prohibitions, each earned by an upstream deletion
or a standing policy. (The plan's done-when for this page says "nine"; the section at base carries
six — the count here is the count there, and the discrepancy is recorded rather than papered over.)

1. **Do not copy the "fewer than 4 lines / one-word answers are best" rule.** Anthropic deleted it;
   readable outcome-first prose beat concision as a metric.
2. **Do not stack `IMPORTANT:` / `CRITICAL:` markers.** When everything is critical nothing is;
   an anxious prompt produces a cautious, hedging model.
3. **Do not hardcode a thinking-token ladder.** The 4,000 / 10,000 / 31,999 numbers describe 2025
   behaviour and are gone; the modern mechanism is a whole-word regex on user input appending a
   meta message.
4. **Do not add a per-tool-result safety reminder.** It shipped, measured at over 15 percent of
   context across 32 days, drew ten issue reports, and its own author removed it. Per-call bytes
   compound.
5. **Do not add a user-supplied system-prompt override key.** Roo removed theirs as "footgun
   prompting"; `Config\LayeredSettings` is already right to omit it.
6. **Do not put a blanket total-request timeout on a completion.** Standing repo policy: a short
   connect timeout is fine, and cancellation is the mechanism for the long tail.

## What is deliberately not a layer

The plan's §18 register lists what was considered and left out; the prompt-relevant rows, with the
reason each stays out:

- **Per-provider prompt variants.** One strong prompt plus the operator's own layers, not ten
  prompts to keep in sync with measured contradictions between them. The per-family paragraph in
  slot 1 is the deliberate exception, and it stays small for this reason: three short paragraphs
  appended to one shared base, not a second base prompt per family.
- **Widening context-file discovery to other vendors' files.** There is no specification to model
  precedence on; the rules tier gives the same benefit without inheriting the ambiguity.
- **Moving the base prompt to XML-tagged sections.** An open empirical question, pinned expensive
  by the heading-level tests, and the preference it encodes is model-family-specific.
- **A `<system-reminder>` channel inside user turns.** User and tool content can be forged by
  anything that writes there; if a reminder channel is ever needed the non-spoofable appended
  system role is preferred — and note the roster defangs the tag inside fenced sections precisely
  because the emitted channel exists (its emitters today are the two path nudges,
  `SkillPathNudge` and `RulePathNudge`, both on the tool-output side, and the plan-mode contract
  `PlanModeSection`, in the system prompt itself — none of them inside a user turn).
- **Keyword and intent triggers are built but not applied.** Every rule carries its `paths:` /
  `keywords:` / `description` triggers into its `Rule` object, and only the first is consulted.
  The `paths:` half shipped (P6.S5b): the splice in `Runtime::systemPromptSections()` skips every
  rule `RulePathNudge::isPathScoped()` claims, and `Bootstrap` wires `RulePathNudge` into Read,
  Edit, Write, ApplyPatch, Glob and Grep, which deliver the rule in their tool output on the first touch of a
  matching file. The nudge re-walks the rules on every consult, so a scoped rule added or edited
  mid-session is delivered too. The other two are not applied: `KeywordTrigger` and
  `IntentTrigger` have no consumer in `src/`, so a `keywords:`- or `description:`-only rule
  renders into every session; and `Rule::$models` is parsed and read by nothing. Framing and
  escape are tier-blind, so this is a scoping gap, not a safety gap — named in the code comment,
  not hidden.
- **The agent-side second assembler.** `Agents\Agent::systemPrompt()` renders its own copy of the
  layer idea for workflow stages; it is live for the workflow engine but is not this page's
  pipeline, and wiring the per-step write signal into it was measured, escalated and left as its
  own build-it-out step.
- **Utility prompts and memory-consolidation passes.** Cheap polish or unmet dependencies; out of
  the prompt plan, recorded there.

## Prompt paths by feature

How each user-facing surface reaches the model, and where it does not reach:

- **Rules.** `RuleLoader::load()` is the single deduplicated, filename-ordered, enabled-only walk
  of the user, project and root tiers; the tier picks the *voice* (fence and preamble), never a
  second walk, and the rulebook toggles subtract inside that one entry point so the `/rules`
  listing and the prompt cannot disagree about which packs are on. Rules enter the system prompt
  (slots 6 and 8), except `paths:`-scoped ones, which enter tool output (next bullet).
- **Triggers.** Built per rule; only `paths:` is applied. A `paths:`-scoped rule leaves the
  system prompt and reaches the model through `RulePathNudge`, inside the `<system-reminder>`
  block that Read, Edit, Write, ApplyPatch, Glob and Grep append to their output when they touch a matching
  file. `KeywordTrigger` and `IntentTrigger` have no consumer in `src/` — see the register above.
  The listing-and-selection trigger family for *skills* does ship: slot 11 exists so discovered
  skills are auto-triggerable through the `Skill` tool.
- **Skills.** Two channels, deliberately one body path: explicitly enabled skills contribute full
  bodies via `Skill::systemPromptContribution()` (slot 10) and are excluded from the
  `SkillMatcher::listForPrompt()` metadata listing (slot 11) so no skill is in the prompt twice;
  path-scoped skills additionally surface a first-touch nudge through `SkillPathNudge`, which
  rides into tool output under the reminder tag, not into the system prompt.
- **Compaction summaries.** A compaction reaches the model as history rows, never as a system
  prompt layer. The summariser is a separate, tool-less request: `CompactionService::COMPACT_SUMMARY_PROMPT`
  asks for one six-facet record per condensed exchange, then one state block between
  `<session-state>` tags with the headings `StateSummaryTemplate::MODEL_HEADINGS` lists (Goal,
  Constraints, Progress with Done / In progress / Blocked, Key decisions, Current work, Next step,
  Pending tasks, Errors and fixes). `/compact <focus>` and a permitting PreCompact hook's note go
  out as one more message after the exchanges, escaped like every carried text. The reply is
  thrown away whole when it is not smaller than what it summarises or the provider cut it at its
  length limit. A usable block is audited: a missing or empty heading is filled from the heuristic
  block, and the three derived headings — files read, files modified (from the tool rows, via
  `FilesTouched`) and the user's latest request, verbatim — are never the model's to write. Every
  compaction, model-written or heuristic, leads its rows with that block (`[summary] Session state
  (compacted):`, at most 16,000 characters, ending in an instruction to continue with the next step),
  merged with the previous compaction's block rather than stacked beside it.
- **Hook context.** Hook stdout collected as `additionalContext` never enters the system prompt;
  it is appended to the relevant message through the `Runtime::annotate()` seam (the PostToolUse
  consumer is the live one), leaving the result byte-identical when the context is empty. Because
  it lands in transcript content, no fence and therefore no escape applies to it — another reason
  the prompt layers keep their one escape authority.

## Guards that police this page

This file joins the docs census every drift guard walks, so a claim here that stops being true
turns a test red rather than a reader confidently wrong: `GlobFigureDriftTest` (figures stated in
docs must match what the generators derive), `EnvRosterDriftTest` (every `SUGARCRUSH_` variable any
page names must have its row on the environment roster and read live code),
`TrustKeyDocumentationDriftTest` (trust-key prose census over docs and README),
`DocumentParagraphsTest` (balanced fences and paragraph discipline over `docs/` and `src/`), and
`SymbolCitationDriftTest` (every backticked test symbol in `docs/` must resolve). The forgery
guards named under the fence section are the behavioural half.

## See also

- `docs/ARCHITECTURE.md` — the runtime map, with the assembly-order survey.
- `docs/ENVIRONMENT.md` — the environment-variable roster, including the dormant prompt-cache row.
- `docs/MEMORY.md`, `docs/SKILLS.md`, `docs/SETTINGS.md`, `docs/COMMANDS.md`, `docs/HOOKS.md` —
  the per-feature surfaces whose prompt paths are summarised above.
