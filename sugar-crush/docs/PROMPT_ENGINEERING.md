# Prompt engineering — why the system prompt is layered the way it is

This page is the rationale register for sugar-crush's prompt layering, written so the design
survives contributors who did not design it. The order of record is the code in
`Runtime::systemPromptSections()`; nothing here is authoritative against it. Where this page
explains a decision it names the symbol that carries it, and where a guard test pins a claim the
guard is named. Line numbers are deliberately absent — they rot, and a doc that rots is worse than
a doc that does not exist.

For the assembly-order list in the architecture survey see `docs/ARCHITECTURE.md` ("The system
prompt, in assembly order"); this page is the *why* beside that *what*.

## The eleven slots, in order of record

`Runtime::systemPromptSections()` returns an ordered list of `PromptSection`s, base first and the
volatile `<env>` block last. Counted from the live method, there are eleven slots:

1. **Base identity** (`Runtime::basePrompt()`) — the Static heredoc that opens every prompt.
   Unfenced, because it is harness voice with no untrusted input.
2. **Maxims** (`MaximsSection`) — the `core.maxims` voice layer, directly behind the base identity
   and ahead of every derived layer. Static and unfenced: its bytes are class constants.
3. **Tool guidance** (`Runtime::toolGuidanceSection()`) — appended only when at least one wired
   tool implements `PromptGuidance` and contributes a non-empty fragment; with no qualifying tool
   the slot is absent from the list entirely, not rendered empty. Fragments are ordered by
   `name()`, so the bytes cannot depend on registration order. Static.
4. **Repo map** (`RepoMapBlock`) — fenced `repo-map`; per-session memoized snapshot of derived
   repository facts.
5. **User-tier rules** — each enabled rule from `RuleLoader::load()` whose tier is `user` gets its
   own fence, `user-rules`, with the operator-authority preamble — except a `paths:`-scoped rule,
   which is delivered at tool time instead (see the trigger bullets below).
6. **Instruction documents** — `InstructionFileLoader::loadRoot()` then `loadForced()` (read
   through their path-keyed sibling `loadDocuments()`), each non-blank document its own
   `project-instructions` fence with the project-authority preamble. Budgeted like the rules
   (audit 15d-09, C3): each document's framed, post-escape section is held to
   `Runtime::MAX_INSTRUCTION_DOCUMENT_BYTES` and all of them together to
   `Runtime::MAX_INSTRUCTION_BYTES`, and the loader does not read a file over
   `InstructionFileLoader::MAX_DOCUMENT_BYTES` at all. A document that does not fit is never
   clipped: it becomes one `InstructionFileLoader::pointer()` line, naming the file and its size,
   in a deferral fence, and a `refusedPaths()` entry. An `@import` that would carry its document
   past that ceiling is replaced at its import site by an `import-deferred` note carrying the same
   pointer line.
7. **Project-tier rules** — the same `project-instructions` fence and preamble as the documents,
   because the authorship claim is identical: bytes shipped inside the checkout. A
   `paths:`-scoped project rule is skipped here the same way.
8. **Memory** (`MemoryBlock`) — fenced `project-memory`; the scope-selected standing notes,
   memoized per session like the repo map.
9. **Enabled skill bodies** — every skill in `$app->enabledSkills` contributes its full
   `Skill::systemPromptContribution()` as a PerTurn section, name and body through
   `PromptFence::escape()`. Held to `CompactorConfig`'s `skillBudgetPerSkill` and
   `skillBudgetCombined` tokens, measured with `TokenEstimate::ofText()`: a body over either keeps
   its `## Skill:` heading and is replaced by one line saying how to load it (the Skill tool, or
   Read on its file), never clipped.
10. **Skill listing** — `SkillMatcher::listForPrompt()` names the remaining *discovered* skills at
    level-1 metadata (name and description), excluding those whose bodies the previous slot
    already carries. PerTurn. Fenced `available-skills` with the skill-listing preamble, because
    names and descriptions are skill authors' text — a cloned checkout's `.claude/skills` among
    them, read with no trust gate. Every line goes through `SkillPromptLine::render()`: collapsed
    to one line, `PromptFence::escape()`d, then clipped to `SkillPromptLine::LISTING_MAX_BYTES`, and
    opened with a provenance badge from `SkillOrigin::badge()` — `[built-in]`, `[user]`,
    `[project]`, plus `foreign: claude` or `foreign: opencode` for another tool's format
    (audit 15d-02). `SkillPathNudge` uses the same helper at its own entry cap, without the badge.
11. **Environment** (`EnvironmentBlock`) — fenced `env`, **LAST**. The P3.S1 invariant.

Slots 1–3 are the Static prefix; 4–8 are PerSession; 9–11 are PerTurn. A section whose `render()`
returns the empty string folds out of both wire forms — an absent layer adds no bytes, no empty
fence and no dangling separator.

## Why that order

Two ladders are the same ladder, laid out in different currencies:

- **Mutation frequency.** `Stability` orders the layers by how often their bytes change, because
  Anthropic-side prefix caching bills any change anywhere in the prefix as a miss for everything
  after it. The cacheable identity rides first, the session snapshots next, the per-turn volatile
  material last. `<env>` LAST is load-bearing (the P3.S1 decision, recorded in
  `Runtime::systemPromptSections()`): `EnvironmentBlock::render()` live-polls git status, so any
  position earlier than the end would void the cacheable prefix for every layer behind it from the
  first file write of the session. Before P3.S1 the git block sat near the front, and the ordering
  note in `docs/ARCHITECTURE.md` still carries the corrected record of that inversion.
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
apart from the git block (polled live on every render).

## The assemble invariant

`Runtime::assembleSections()` is one fold producing both wire forms: the flat system-prompt string
and the ordered block list that `CompleteRequest::$systemBlocks` carries. Both arms write from the
same accumulator, so concatenating the blocks byte-for-byte yields the string — the identity holds
*by construction*, not by convention. `Runtime::run()` destructures that fold into the
`CompleteRequest`.

The separator rule lives in exactly one place: adjacent rendered sections are joined by one blank
line, and a body that already opens with one is never given a second. Naive `implode` over the
bodies would double the separators the golden fixtures pin byte-for-byte. The fold also preserves
the one-render-per-build cost contract: `EnvironmentBlock::render()` pays its five git subprocess
polls exactly once per build, inside the fold, never once per wire form.

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
- **The roster is the nine tags `PromptFence::tags()` returns**, read at this base: `env`,
  `project-memory`, `repo-map`, `project-instructions`, `system-reminder`, `user-rules`,
  `prior-summary`, `harness-injected`, `available-skills`. Two entries are noteworthy in kind:
  `prior-summary` is the tag `Chat::renderPriorSummariesForSummary()` wraps in a *summariser
  request* outside the assembled system prompt — foreign by exactly the route a repo file is — and
  `harness-injected` is the roster's one pre-registered, defang-only tag: nothing emits it yet, and
  a tag added before the bytes that need it is free, whereas a tag added after leaves a forging
  window. `available-skills` is the fence around the skill listing (slot 10), whose names and
  descriptions are skill authors' text (audit 15d-02).
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

## Cache breakpoints — the contract, and the fact that it is not armed

`CacheBreakpoints` implements the Anthropic prompt-cache mark plan: applied **wipe-then-reapply**
on every step of an agentic turn (upstream measured that without the wipe each step *adds* its
marks and the request crosses the API cap and 400s), budget of `CacheBreakpoints::MAX_BREAKPOINTS`
— four — for the whole request, default plan last-tool + last-system + last-two-messages, with a
20-block lookback repair chain and intermediate marks spaced inside it. When a gateway injects its
own automatic (non-ephemeral) cache marks those are preserved, never wiped and never overwritten,
but they consume slots from the same cap: `CacheBreakpoints::BUDGET_WITH_AUTOMATIC` is the
explicit budget minus one. Input already breaching the cap on foreign marks throws rather than
returning a doomed request.

**None of this is wired.** There is no production caller: no file under `src/` or `bin/` other
than the class itself constructs or consults `CacheBreakpoints`; its only callers are its tests.
That is the adjudicated intended state of its step (§1.10 of the plan: shipping a class unwired is
an outcome here; deleting or stubbing it is not), and the contract above should be read as a
not-yet-armed seam, not as live behaviour. Two consequences ride with it:

- The kill switch `SUGARCRUSH_DISABLE_PROMPT_CACHE` is tabulated **dormant by design** in
  `docs/ENVIRONMENT.md`: unset, empty and the literal `0` read as enabled, any other text disables;
  the only reader is `CacheBreakpoints::disabledFromEnvironment()`, static by design so a consumer
  consults it once at construction and `apply()` itself stays environment-free. A disabled
  instance still runs every input-derived duty — the shape throws, the ephemeral wipe, the
  over-cap breach — and then adds zero *new* ephemeral breakpoints; "disabled" honestly means no
  new marks, not caching off for mechanisms this class does not own.
- `CacheBreakpoints::observeCacheHealth()` is a live channel that is not yet live: the unary
  response path routes no cache fields into it, and widening `CompleteResponse` belongs to the
  wiring step. When wiring lands there is also a recorded hazard: the last-system mark must travel
  through the `systemBlocks` block form, because the string arm discards in-messages system rows.

## Session affinity — dormant-id state

`Providers\Concerns\SessionAffinity` declares the `X-SugarCrush-Session` header (full SHA-256 hex
of the session id, never the raw id — a raw id pins one user's traffic and leaks a persistent
identifier to every proxy hop; and no header at all rather than an empty one when there is no
session, so session-less traffic does not concentrate on one warm backend). The trait ships on
`CustomProvider` and `SglangProvider`, but the id lives on the host classes and **nothing on the
wired path passes one**: both providers stay at the default null, so the wire carries no affinity
header and renders byte-identically to the pre-trait shape. The consumer contract for the future
wiring step is recorded in the trait: the id must be the *current* session's, re-taken across
`/resume`, `/branch` and session switches.

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
  prompts to keep in sync with measured contradictions between them.
- **Widening context-file discovery to other vendors' files.** There is no specification to model
  precedence on; the rules tier gives the same benefit without inheriting the ambiguity.
- **Moving the base prompt to XML-tagged sections.** An open empirical question, pinned expensive
  by the heading-level tests, and the preference it encodes is model-family-specific.
- **A `<system-reminder>` channel inside user turns.** User and tool content can be forged by
  anything that writes there; if a reminder channel is ever needed the non-spoofable appended
  system role is preferred — and note the roster defangs the tag inside fenced sections precisely
  because the emitted channel exists (its emitters today are the two path nudges,
  `SkillPathNudge` and `RulePathNudge`, both on the tool-output side).
- **Keyword and intent triggers are built but not applied.** Every rule carries its `paths:` /
  `keywords:` / `description` triggers into its `Rule` object, and only the first is consulted.
  The `paths:` half shipped (P6.S5b): the splice in `Runtime::systemPromptSections()` skips every
  rule `RulePathNudge::isPathScoped()` claims, and `Bootstrap` wires `RulePathNudge` into Read,
  Edit, Write, Glob and Grep, which deliver the rule in their tool output on the first touch of a
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
  (slots 5 and 7), except `paths:`-scoped ones, which enter tool output (next bullet).
- **Triggers.** Built per rule; only `paths:` is applied. A `paths:`-scoped rule leaves the
  system prompt and reaches the model through `RulePathNudge`, inside the `<system-reminder>`
  block that Read, Edit, Write, Glob and Grep append to their output when they touch a matching
  file. `KeywordTrigger` and `IntentTrigger` have no consumer in `src/` — see the register above.
  The listing-and-selection trigger family for *skills* does ship: slot 10 exists so discovered
  skills are auto-triggerable through the `Skill` tool.
- **Skills.** Two channels, deliberately one body path: explicitly enabled skills contribute full
  bodies via `Skill::systemPromptContribution()` (slot 9) and are excluded from the
  `SkillMatcher::listForPrompt()` metadata listing (slot 10) so no skill is in the prompt twice;
  path-scoped skills additionally surface a first-touch nudge through `SkillPathNudge`, which
  rides into tool output under the reminder tag, not into the system prompt.
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
