# REVIEW-1 — C-AGT-3.b (agent-scoped memory tier)

Lane commit `2eda0df96` (24 files, +1201/−74) on base `86f43fae2`.
NOTE: the lane worktree `/home/sites/cc-cmp-C-AGT-3.b` and branch `cc/C-AGT-3.b` were pruned
MID-review by the concurrent weld. The patch is absorbed: `git cherry` reports `-` for
2eda0df96; master carries pick `09da7d3e9` (identical content — diff stat and hunks verified
from git objects; gates 1–3 were run live in the worktree before it vanished; later checks ran
in the main tree at master, which contains the same content).

## VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 MINOR (2 informational notes)

## Gates re-run by reviewer (cwd candy-code, plain pipe, env -u LINES -u COLUMNS, targeted only)
1. `--filter 'AgentMemory|TaskToolAgentMemory|ForeignAgentPreset'` → **105T/349A OK**
   (builder's 71T/245A was the narrower `ForeignAgentPresetRegistry` filter — consistent).
2. `--filter 'Memory|Agent|Background|Schedule'` → **3265T/25208A, exactly F1**:
   `DreamPassTest::testChatSchedulesThePassAtTurnCloseAndReportsWhatChanged`
   (assertNull, tests/Memory/DreamPassTest.php:288). **Base-red CONFIRMED statically**:
   `git show --stat 2eda0df96 -- src/Chat.php src/Memory/DreamPass.php tests/Memory/DreamPassTest.php
   src/Chat/Handlers/` = EMPTY — the lane touches none of the failing path; the test file is
   byte-identical base↔tip. Foreign-inherited; not caused by the lane.
3. five-guard (`ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift`)
   → **133T/15699A, Failures:2 = ONLY the allowed staleness pair** (live 26,130 vs pinned 25,537;
   README/suite-figure are weld-only files, lane correctly forbidden). GlobDialect and
   DuplicatedTestHelperDrift green ⇒ §1.9 literal hazards clean (chr(42) despell used deliberately,
   comment-pinned at AgentMemoryTest:165-167; helpers lane-suffixed Cagt3b).
4. `--filter 'InertFrontmatter|FrontmatterKeyAudit'` → **32T/114A OK** (INERT[AGENT] emptied with
   the three doc rows in-step; AGENTS_AUTHORING.md:138 `memory: project # user | project | local`).
5. `--filter 'PromptStability|BaseSystemPrompt|PromptSection|MemoryBlock'` → **135T/1199A OK**
   (12th tag `agent-memory` in PromptFence::TAGS coheres with the fold guards; PROMPT_ENGINEERING
   twelve-tags prose moved same commit).
6. child-lifetimes / path-repos: harness denies raw `php`; static proof instead —
   `git show 2eda0df96 | grep '^+.*(proc_open|popen|pcntl_fork|forkProcess)'` = EMPTY (no new
   spawn sites → roster verdict unchanged); no composer.json in the 24-file diff. Builder claims rc0.

## Security/correctness axes
1. **No cross-store leak.** `MemoryWriter::forAgent` passes projectRoot `''` →
   `repository()` and the project-scope `createForRoot('')` both refuse (realpath('')-CWD law) —
   every save/locate/update/delete routes into the agent's own store, never the repo store and
   never the session home store (`~/.candy-code/agent-memory/<name>` vs `~/.candy-code/memory`;
   AgentMemoryTest:208/:219 assert the sibling directory is never even laid down). Prompt-side:
   `resolveBatchSystemPrompts` resolves the index per member by its OWN name+tier
   (AgentManager:2367); pins prove silent members forward the shared bytes verbatim
   (TaskToolAgentMemoryTest:93), an empty store mints zero prompt bytes (null map, :111), a failed
   store read fails open to zero bytes (:166), and a sibling agent's `locate()` cannot see another
   agent's note (AgentMemoryTest:206-207). `agentIndex` lists only Project+User of the passed
   store with the same budgets/escaping as the session block; the `agent/` sub-dir is never
   surfaced and unreachable via the tool (MemoryTool save restricts scope to project|user,
   :200-201). Host RPC surface (`memory.*`) and `/memory` command act on the session stores only
   (WorkspaceContext::memoryStore) — no path reaches an agent store from outside the delegation.
2. **Deny floor preserved.** `memoryToolDenied` mirrors `AgentManager::resolveGrantedTools`
   grammar: un-argumented exact/wildcard denials of `Memory` block the auto-grant; argument-scoped
   denials defer to the per-call gate (docblock says so, pin covers both polarities:
   TaskToolAgentMemoryTest:63-77 — exact, wildcard via `Mem`+chr(42), foreign tool, scoped, empty).
   `carriesMemoryTool` prevents a double bind; re-bind loop uses `withWriter` = `new self(...)`
   on the readonly tool — session instance untouched (immutability law held).
3. **Serialization sweep (independent grep).** All `->memory` / `'memory'` wire sites:
   Agent ctor/`fromArray` (`MemoryScope::tryFrom((string)($data['memory'] ?? ''))` — tryFrom yields
   null on absent/unknown, the house pattern already used for the sibling `effort`/`isolation`
   fields), `toArray` (`$this->memory?->value`), `fromPreset` carry, AgentPresetRegistry
   parseMemoryScope (unknown declared spelling now refused via declaredCase — fail-closed),
   ForeignAgentPresetRegistry:607 lenient `enum(... ?? null)`. Legacy background frames carrying
   explicit `'user'` restore the (empty) user tier; disclosed with pin at
   TaskToolAgentMemoryTest:172-190 (legacy→User, absent→null, round trips). No remaining
   unconditional dereference site; MemoryTool:56 and the Memory/*.php hits are unrelated
   same-named members.
4. **INERT[AGENT] emptied** in FrontmatterKeyAudit with doc rows moved in the same commit;
   both drift guards green (gate 4).
5. **Scope + author law.** 24-file list = brief's declared files exactly (only deviation: builder
   edited ForeignAgentPresetRegistryTest, not the brief's "Wiring" name — content-verified). No
   forbidden files. New files = only the 2 declared tests, both listed in REPORT.md:24-25 for the
   weld's durations rows. Single commit; `%ae`=`%ce`=detain@interserver.net (separate greps);
   zero Co-Authored trailers; parent = base 86f43fae2.

## Informational notes (no action required)
- INFO-1: `safeName` sanitization can merge two distinct spellings into one store dir
  ('MEMORY' and '.MEMORY' → `_MEMORY`; 'team lead 2' / 'team/lead/2' → `team_lead_2`).
  Collisions require same tier + same sanitized name, i.e. same trust domain; the grammar cap is
  documented in the AgentMemory class docblock and pinned at AgentMemoryTest:45-76. Accepted design.
- INFO-2: legacy background frames that explicitly carry `'memory':'user'` resume with a fresh
  empty user-tier agent store (an empty index = zero prompt bytes, per pin :111). Disclosed
  honestly in AgentMemory.php:54 and the frame test. Faithful degradation.

Reviewed by cc-cmp step reviewer, 2026-10-27.
