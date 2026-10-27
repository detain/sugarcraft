### C-AGT-3.b — Per-agent memory from the preset `memory:` key
- item: C-AGT-3 (sources: GAP-MEM-13, k1-dorm#2)
- kind: dormant-wire · effort M
- approach: Today `memory:` is parsed onto `AgentPreset`/`Agent` and read by nothing (`src/Support/FrontmatterKeyAudit.php:122`, `INERT[AGENT]`). It also defaults to `User` when absent (`AgentPresetRegistry.php:608`, `Agent.php:104,176`), so "declared" is indistinguishable from "absent". Fix:
  - make the field nullable: absent = no agent memory, with a `memorySet` sentinel if a wither needs it;
  - add `Memory\AgentMemory`, which resolves a per-agent `MemoryStore`: `user` → `~/.candy-code/agent-memory/<name>/`, `project` → `<root>/.candy-code/agent-memory/<name>/`, `local` → `<root>/.candy-code/agent-memory-local/<name>/` with a `*` `.gitignore`. Names are sanitised to the note-id grammar;
  - `AgentManager::resolveBatchSystemPrompts()` appends that store's `MemoryBlock` index to the child prompt;
  - `TaskTool` re-binds the child's `Memory` tool through `MemoryTool::withWriter(MemoryWriter::forAgent(...))`, adding it to the grant when `memory:` is declared (Claude Code's auto-grant).
  - Remove `memory` from `INERT[AGENT]`.
- files.create: `candy-code/src/Memory/AgentMemory.php`
- files.modify: `candy-code/src/Agents/AgentPreset.php` (`$memory` nullable), `candy-code/src/Agents/Agent.php` (ctor, `fromArray`, `fromPreset`, `toArray`, docblock :39), `candy-code/src/Agents/AgentPresetRegistry.php` (`parseMemoryScope` default), `candy-code/src/Agents/ForeignAgentPresetRegistry.php` (:604 default), `candy-code/src/Memory/MemoryWriter.php` (`forAgent`), `candy-code/src/Tools/BuiltIn/MemoryTool.php` (`withWriter`), `candy-code/src/Context/MemoryBlock.php` (agent-index render), `candy-code/src/Agents/AgentManager.php` (`resolveBatchSystemPrompts`), `candy-code/src/Tools/BuiltIn/TaskTool.php` (child tool re-bind beside the SendMessage re-bind ~:1330), `candy-code/src/Support/FrontmatterKeyAudit.php` (`INERT`, docblock), `candy-code/docs/AGENTS_AUTHORING.md` (row :217 Inert → live; example :138), `candy-code/docs/MEMORY.md` (agent-memory section)
- tests: create `candy-code/tests/Memory/AgentMemoryTest.php` (tier paths, sanitising, the local `.gitignore`), `candy-code/tests/Tools/TaskToolAgentMemoryTest.php` (a `memory: project` preset sees its index in the child prompt, and its save lands in the agent store, not the shared one; no `memory:` means no index and no grant); modify `candy-code/tests/Agents/AgentPresetRegistryTest.php` (absent → null), `candy-code/tests/Integration/ForeignAgentPresetWiringTest.php` (the :355 encoded-field list if `memory` goes null)
- docs/guards: `candy-code/tests/Config/InertFrontmatterDocumentationDriftTest.php` (moves: `memory` leaves the inert roster), `candy-code/tests/Config/MemoryDocumentationDriftTest.php` (`docs/MEMORY.md`), `docs/AGENTS_AUTHORING.md`. If `AgentMemory` reads files through a new site, add a `ReadPathCensusTest` row.
- serialized: [hot:candy-code/src/Agents/AgentManager.php] [hot:candy-code/src/Tools/BuiltIn/TaskTool.php] [docs:AGENTS_AUTHORING.md] [docs:MEMORY.md]
- deps: C-AGT-3.a
- phase: normal
- ruling: none
- risks: making the field nullable touches every `Agent` (de)serialisation, including background-session records. The lane greps `->memory->value` and `'memory'` keys.

---

## C-AGT-4 — Primary agents & @agent

