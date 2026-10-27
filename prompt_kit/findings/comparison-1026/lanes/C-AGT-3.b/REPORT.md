# C-AGT-3.b — agent-scoped memory tier (`memory:` selects the agent's own store)

Commit: 2eda0df9633d44aab1df959dcf30a5bf245e79fa on cc/C-AGT-3.b (base 86f43fae2), author/committer Joe Huss <detain@interserver.net> (%ae/%ce greps =1 each, zero Co-Authored trailers), 24 files, +1201/−74. Tree clean after commit; never pushed.

## What landed
- NEW src/Memory/AgentMemory.php: per-agent store resolution — `user` → `~/.candy-code/agent-memory/<name>/`, `project` → `<root>/.candy-code/agent-memory/<name>/`, `local` → `<root>/.candy-code/agent-memory-local/<name>/` with a planted `*`-only `.gitignore`. Reader never creates (ProjectMemoryWriter law); post-mkdir ContainedPath::below gate refuses symlink-routed stores; preset names sanitised to the store's note-id grammar (index stem refused).
- `AgentPreset::$memory` / `Agent::$memory` now `?MemoryScope = null` (absent = no store at all, not the user tier). fromArray/toArray null-safe; unknown declared spellings in preset files now refuse via declaredCase (fourth field in the family). Foreign boundary stays lenient → null.
- MemoryWriter::forAgent() re-binds every scope into the agent's own store (root `''` — cannot reach session home or repo store); MemoryTool::withWriter().
- MemoryBlock::agentIndex() renders the `<agent-memory>` fence (same budgets/escape law, no new consts); PromptFence::TAGS widened to twelve; AgentManager::resolveBatchSystemPrompts appends the index between bodies and the skill listing (fails open; a non-empty index counts as a declaration).
- TaskTool: re-binds the session MemoryTool for a `memory:` child and auto-grants one when absent, honouring the deny floor (`disallowedTools` un-argumented name/wildcard denials block the grant; scoped denials stay per-call, mirroring resolveGrantedTools).
- FrontmatterKeyAudit::INERT[AGENT] emptied; docs moved in the SAME commit: AGENTS_AUTHORING (row live + all-live intro + no-Inert paragraph), MEMORY.md (roster sentence, three-tiers pointer, Memory-tool paragraph, new `## Per-agent memory (memory:)` section, layout tree row), PROMPT_ENGINEERING (twelve tags + agent-memory sentence).
- Test flips in-step: AgentPresetTest (default none), AgentPresetFieldCarryTest (null decodes ×2 + docblock), AgentPresetRegistryTest (sounding rides an unknown key), ForeignAgentPresetRegistryTest (unknown enum → null), BootstrapLaunchNoticeRoutingTest (inert memory clause retired), PromptSectionTest + BaseSystemPromptTest (twelfth fence tag).

## Gates (cwd candy-code worktree, plain pipe, env -u LINES -u COLUMNS)
- `--filter 'AgentMemory|TaskToolAgentMemory|ForeignAgentPresetRegistry'` → Tests: 71, Assertions: 245 — OK.
- `--filter 'Background|Schedule'` (serialization sweep) → Tests: 476, Assertions: 4746, Failures: 1 (the base-red below; zero memory/serialization reds — old frames decode losslessly).
- Full brief gate `--filter 'AgentMemory|TaskToolAgentMemory|AgentPreset|ForeignAgentPreset|MemoryBlock|ReadPathCensus|Inert|Memory|Frontmatter|LaunchNotice|PromptSection|BaseSystemPrompt|DocFigure|GlobDialect|ReadmeSuiteFigure|DuplicatedTestHelper|ChildWallClock'` → Tests: 1316, Assertions: 21782, Failures: 3:
  1. ReadmeSuiteFigureDriftTest::testReadmeHeadlineTestsFigureMatchesTheLiveEnumeration
  2. ReadmeSuiteFigureDriftTest::testArtifactIsNotStaleAgainstTheLiveEnumeration
  3. DreamPassTest::testChatSchedulesThePassAtTurnCloseAndReportsWhatChanged
  Reds 1–2 are the allowed staleness pair (lane forbidden from suite-figure.json/README); live enum 26130 vs pinned 25537 — the bulk of that delta is foreign-era drift at base, my two new files add ~24. Red 3 is FOREIGN-INHERITED: proven red at pristine base 86f43fae2 by git-stash probe (identical closure-vs-null assertion at DreamPassTest:288).

## DUR rows for the gate agent (new test files)
- tests/Memory/AgentMemoryTest.php
- tests/Tools/TaskToolAgentMemoryTest.php

## Disclosures
- Legacy BackgroundSupervisor frames carrying `memory` = `user` decode to the USER tier; the store starts empty so no bytes reach the prompt until a note exists (AgentMemory + Agent::fromArray docblocks).
- The shipped preset trio (coder/reviewer/security-auditor) declares `memory: project`: their delegations now auto-grant Memory and open empty project stores — zero prompt-byte change until a note is saved.
- No ReadPathCensus row needed: AgentMemory uses only is_dir/mkdir/file_put_contents/file_exists (unscanned); every read goes through the already-rostered MemoryStore.
- Symlink-escape trade (disclosed in AgentMemoryTest + AgentMemory docblock): the containment gate runs after mkdir, so a refused create() can leave a directory as residue through the planted link; no caller ever receives a store bound to it — the same trade ProjectMemoryWriter documents.
- PermissionRule::matchesToolName is bare fnmatch (case-sensitive) — the deny-grammar pin uses 'Mem'+chr(42), and GlobDialect law kept every wildcard literal out of test string tokens.
