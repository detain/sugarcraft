# C-AGT-4.d — @agent:<name> mentions — lane report

## VERDICT: COMPLETE (2026-10-29)

- Final tip: **1b9baf4a0af0eabc0a6d0bc99703c759631c364b** (cat-file-verified, tree clean)
  - fde5f268b "C-AGT-4.d: @agent mentions — salvaged first cut" — 14 files, 604+/26-
  - 1b9baf4a0 "…agentRoster popup guard, roster-honest docblock, README + AGENTS doc rows" — 5 files, 38+/8-
- Both commits: %ae AND %ce = detain (verified by SEPARATE greps each), zero Co-Authored trailers.
- diff 4dd8c20ce..HEAD: 16 files, 635+/27-. All files in the brief's declared set;
  co-tenant bans honoured (zero touches: src/Hooks/**, SendMessageTool, SessionMailbox,
  lang/en.php, suite-figure.json, durations.tsv, dist/, protocol sources).
  Chat.php hunks confined to :10335-10362 (userTurnMessage) + :17740-17790 (mentionPopupRows)
  — far from C-AGT-5.f's :10618-10634 webhook pump region.

## IMPORTANT PROCESS NOTE (honest, dated 2026-10-29)

A concurrent writer (the "dead" prior session, or a twin) was LIVE in this worktree during
my run: it committed 1b9baf4a0 at 03:16:09, which bundled MY in-flight docblock edit
(Chat.php roster-honest popup docblock) with its own Composer.vue disabled-state guard
(`!props.agentRoster`) + spec call-shape fixes (searchFiles receives `agent:nope` sans
sigil, span-join row labels). I judged its content against the contract — it is correct
and law-conform — and adopted it rather than fighting it. No work lost, no duplicate
commits; tip then went quiet (no further movement observed). Orchestrator: if a twin
session still exists for C-AGT-4.d, it appears finished; prune via this SHA.

## What shipped (contract item by item)

1. ContextMentions: `agent` added to MENTION_PATTERN/KEYWORD_PATTERN (`agent:<name>`,
   colon-form reserved like `@mcp:`); `withAgentRoster(?\Closure)`; `agent()` leg —
   known name → framed directive attachment "The user asks you to delegate this to
   agent `<name>` via the Task tool" + substitution ban; unknown name / no roster /
   throwing roster → notice, never a guess; cap notice now names all six legs.
2. TurnController::userTurnMessage `?\Closure $agentRoster = null` tail param threaded
   to the scanner; Chat::userTurnMessage + SessionHost::admitPrompt pass their live
   AgentManager (`->get($name) !== null`); NonInteractive arms BOTH mention sites
   (:495 run() scanner, :1680 historyFrom fallback) via lazy+memoized
   launchAgentRoster() over `Bootstrap::agentManager($root)` (static public, exists @:2600;
   WorkspaceContext->agentManager is public readonly — verified).
3. TUI: mentionPopupRows() routes `agent:` partials to rankPaths over roster names
   (`agent:<name>` rows; Enter-accept + popup-close pinned); empty/no-manager roster →
   no rows, menu closes (docblock made truthful to code; renderMentionMenu treats
   slashless paths correctly — dirname '.', hostile-data sanitizer applies).
4. Headless -p: resolves typed mentions through the launch roster; unknown → stderr
   notice only; refusal costs the file/other legs nothing (pinned).
5. Web (client-only): Composer.vue `agentRoster` prop + `@agent:` popup rows
   ("delegate this prompt"), tab/enter accept inserting `@agent:<name>`,
   empty-rows fallback to file search mirroring `@mcp:`; SessionView.vue wires the
   agents-store roster (DEVIATION: SessionView.vue not in files.modify — minimal
   prop-plumbing for the very feature the block demands; keep-or-revert is a weld glance).
6. Docs in-step: README keyword-mentions bullet; docs/AGENTS.md new subsection
   "You delegate from the prompt: @agent:<name>" (colon-form rationale, refusal-
   costs-nothing, TUI+web popup, -p parity).

## Gates (targeted only; cwd=candy-code -c phpunit.xml env -u LINES -u COLUMNS plain pipe)

- `ContextMentions|AgentMention|NonInteractiveMentions|SessionHost|TurnController|DocFigureProseDrift|MentionFilePopup`:
  **269 tests OK** (2 pre-existing AccessorDeprecationTest deprecations, not lane).
- `TurnController|SessionHost|NonInteractive|ReadmeRosterDrift|GlobDialect|ClaimFamilies|TreeWideGuardRoster|DuplicatedTestHelper`:
  **329 OK**.
- Wide `Chat|…` family: 2005 tests, **14F — PROVEN foreign-inherited**: exact same 14
  names fail at base 4dd8c20ce in an archive probe (real vendor copy). Not re-run at
  close; names recorded (ApplySettings x2, AutomaticCompaction, ChatHostLangCatalogue,
  ColdResumeFinishedAgent, PaletteSuperset, RouteTraitParity, ChatTest title x5,
  CommandFailureTranscript stdout, DreamPass).
- `SymbolCitationDrift|StderrEmitterCensus|BootstrapLaunchFormatConstants|DuplicatedDocBlockLine`:
  126 tests, 1F = BootstrapLaunchFormatConstantsTest::testEveryDocPageQuotingAPromotedFormat…
  — **base-proven red** (PROJECT_TIER_TOOL_REMOVAL_FORMAT roster drift from another wave).
- php -l clean on every touched PHP file. vitest serial composerFilesImages.spec.ts:
  **15/15**. ~~vue-tsc --noEmit: rc=0~~ (ROUND-1 ERRATUM: that run was VACUOUS — the root
  tsconfig.json is `"files": []` + project references, so a bare `--noEmit` type-checks
  nothing. Honest gate, re-measured at fix tip: `npm run typecheck` (vue-tsc --build) =
  19 unique TS errors (21 lines incl. the dual-build contextSources duplicates), ZERO in
  lane files — matches the batch3 base-residual family: contextSources/dormRoster/
  statusBar/contextPanel/reducer/contextTrustRouting.)
- ReadmeSuiteFigureDrift staleness pair: red BY DESIGN (weld re-pin duty).

## Carries for the gate/welder (DUR)

- Live test-count carry: **+11T** from this lane (67→78 declared methods across the 5
  touched test files; zero new test FILES → durations.tsv needs no new row;
  DocFigureProseDriftTest flipped in-method, 92=92 methods).
- No new assertions-only drift: assertions figure refresh rides the weld green run
  (refresh-suite-figure.php); lane touched no protocol source (no trio regen owed).
- dist rebuild OWED by weld (Composer.vue/SessionView.vue/spec changed; lane never commits dist).
- Non-ASCII agent names are silently unscreenable in rankPaths (MentionIndex::screenable
  excludes non-ASCII) — roster names are slug-shaped today; noted for future roster rules.
