# C-EXT-2.c — Forked skills (`context: fork`) via the turn-bound Task tool

**DONE.** Commit `3897f5aa456ef36a8db4cc0fc4aafefabaafc581` on `cc/C-EXT-2.c` (base b985fbe51), author/committer Joe Huss <detain@interserver.net> verified via separate %ae/%ce greps, zero trailers. Never pushed.

## What shipped (all 8 design points)
1. `SkillTool` gains `withForkDelegate(TaskTool)` + implements `ExemptFromParallelDeadline`; `EngineBackend::turnTools()` post-pass binds every SkillTool copy of the turn to the turn's BOUND Task copy — engine, permission relay, emitter and spend ride the Task path.
2. `TaskTool::runForkedSkill(Skill, string $task, string $toolCallId)` — non-schema (invisible to `description()`/`schema()`), reuses `private runOnEngine()` with the skill's `agent` preset (default `coder` via new `SkillFork::agentName()`), `model`/`effort` forwarded through two new tail params (`?Effort $declaredEffort`, `$declaredEffortBy`) that stay byte-identical under defaults; fork-specific refusal wording.
3. `SkillTool` body branch: fork + delegate → delegate fork; fork + NO delegate → inline body with `SkillTool::FORK_INLINE_NOTE`; `asset` reads stay inline; Stage-1 manifest rows are upgraded off disk via `Skill::fromFile`.
4. `SkillMentions::resolve()` attaches `FORK_DIRECTIVE` (invoke-through-Skill-tool order, `$ARGUMENTS`-substituted) instead of the body for `$name` and (via the existing `/name`→`$name` rewrite) slash mentions.
5. `Skill::$agent` typed field end-to-end (`SkillFrontmatter` stringField → `Skill::parse`/`toArray`/all three copy methods); `FrontmatterKeyAudit`: `agent` joins KNOWN[skill], `context` leaves INERT (map now empty).
6. `src/Skills/SkillFork.php` (new, one type/PSR-4): DEFAULT_AGENT, agentName() ladder, agent() — App::dispatchSkill's Agent construction (root/env block, skill model, +now effort) moved here; dispatchSkill delegates, stays the caller-less embedder seam (tripwire green).
7. `App::applySkillsToSystemPrompt`/`handleSelectSkill` docblocks + `lang/en.php` `tui.app.skill_fork` reworded to the live effect (keeps literal 'context: fork', avoids 'Enabled skill').
8. Docs in-step: SKILLS.md context row → Live + new `agent` row, "The `context:` field today" rewritten, splice bullet + diagnostics example corrected; README dormancy paragraph replaced; guards `InertFrontmatterDocumentationDriftTest` (4 README legs), `DocFigureProseDriftTest::testDormantSkillPathsStayUnreachableThroughTheWholeChain` (consulters 3→5: applySkillsToSystemPrompt/dispatchSkill/execute/handleSelectSkill/resolve) flipped same commit.

## Gates (cwd worktree candy-code, plain pipe, env -u LINES -u COLUMNS)
- `--filter 'SkillTool|SkillFork|SkillMention|TaskToolEngine|AppSkillDispatch|FrontmatterKeyAudit|InertFrontmatter|SkillFrontmatter|SkillRegistry|ActiveSkillEffects'` → **275 T / 2054 A OK** (only 2 pre-existing AccessorDeprecationTest deprecations).
- `--filter 'SkillTest|SkillFrontmatter|SkillFork|SkillMention|FrontmatterKeyAudit|InertFrontmatter'` → 113 T OK.
- `--filter 'DelegationLoader|TaskSpendPropagation|ToolLimits|ParallelDeadline|EngineBackend'` → **311 T / 1917 A OK**.
- `--filter 'TaskToolEngineTest'` → 17 T OK. `--filter 'SkillToolForkTest|SkillForkTest'` → 16 T / 82 A OK.
- Guard battery `ReadmeRosterDrift|DocFigureProseDrift|GlobDialect|DuplicatedTestHelperDrift|TreeWideGuardRoster|SymbolCitationDrift|StderrEmitterCensus|EnvRosterDrift|TrustKey|ConfigWriteProducer|KeyBindingDrift|BootstrapLaunchFormatConstants`: my two in-step guard flips green; **5 failures PROVEN BASE-INHERITED by `git stash -u` re-run at pristine b985fbe51** (identical set): StderrEmitterCensus ×3 (NonInteractive/ArgvParser counts, untouched by this lane) + TreeWideGuardRoster ×2 (JsonSchemaEnvelopeTest/MemoryRecallBlockTest walkers, not this lane's files).
- Family run incl. `ReadmeSuiteFigureDrift` → only the staleness pair red (artifact 25537 vs live 26402) — figure re-pin is the GATE's duty; suite-figure.json/durations.tsv untouched per law.

## NEW test files (gate must add durations.tsv rows)
- `candy-code/tests/Skills/SkillForkTest.php` (7 T)
- `candy-code/tests/Tools/BuiltIn/SkillToolForkTest.php` (9 T)

## Deviations / seams (disclosed)
- Comment-only touch to `src/Skills/ActiveSkillEffects.php` :55 (dormant-`context` sentence now false next to the live audit map; zero behavior) — outside the claim list, folded in per the docs-truth law.
- Test files touched beyond the brief's list, each obligatory in-step: `DocFigureProseDriftTest` (arm flipped, above), `SkillTest.php` (+1 toArray 'agent' assert), `SkillFrontmatterTest.php` (+agent default/verbatim legs).
- Seam NOT edited (out of ownership, hub file): `src/Cli/Bootstrap.php` :5955 comment still cites `context: fork` as an inert-key example — stale example wording only; owed by a later micro or closeout.
- E646 clean (no timers); no new proc_open/fork sites (child-lifetimes census untouched); refusal literals dodge GlobDialect/denial-prefix shapes.

---

## Round 2 — review fixes (REVIEW-1 M-1 + m-1)

Amended into ONE commit: tip `4ef0283d7` (was 3897f5aa4), 25 files, message preserved, %ae/%ce separate greps = detain/1 each, zero Co-Authored/Signed-off trailers, never pushed.

- **M-1 (MAJOR, in-step guard law §1.8)** — third live pin of the old inert truth: `tests/Cli/BootstrapLaunchNoticeRoutingTest.php` `testIgnoredFrontmatterReachesBothChannelsAsOneRowPerFormat` drove real `Bootstrap::chat()` on the `context: fork` fixture skill and asserted the named-inert row. Dropped the `context: fork` segment from the expected skill row (the `user_invocable` did-you-mean leg rides verbatim; `assertCount(4)` survives — unknown-key row still lands) and rewrote the docblock sentence (:933-935) to teach the live truth (`context: fork` passes too, C-EXT-2.c made it live). Discrimination proven: re-injecting the old expected row → exactly `Tests: 1, Failures: 1` (assert-contains miss at :965), restored md5 `d1799cad…` clean.
- **m-1 (MINOR, step-local)** — `docs/TROUBLESHOOTING.md:163-166`: removed `context: fork` from the "does nothing" list and added the truthful one-liner (fork runs in a sub-agent through the turn-bound Skill tool). The `allowed-tools` / `effort` half left byte-untouched per ruling (pre-existing at base, C-EXT-2.b seam → closeout). Unpinned by any guard; file-reading doc guards re-run green.

Gates (cwd worktree candy-code, plain pipe, env -u LINES -u COLUMNS, PtyRunToolTest never in filter):
- `--filter 'BootstrapLaunchNoticeRouting'` → 22 T / 148 A OK (2 pre-existing deprecations).
- `--filter 'SkillTool|SkillFork|SkillMention|TaskToolEngine|AppSkillDispatch|FrontmatterKeyAudit|InertFrontmatter|SkillFrontmatter|SkillRegistry|ActiveSkillEffects|DocFigureProseDrift'` → 368 T / 5990 A OK (builder's 275 T filter ∪ DocFigure).
- `--filter 'ReadmeRosterDrift|EnvRoster|DocFigure|ClaimFamiliesDocumentationDrift|McpStartupOrderingDocDrift'` → 147 T / 41792 A OK.
- Base-inherited reds (StderrEmitterCensus ×3, TreeWideGuardRoster ×2) and the ChatHostLangCatalogueTest `chat.effort.launch_unshaped` standing red unchanged — not this lane's, per REVIEW-1.
