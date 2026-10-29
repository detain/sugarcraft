# C-CTX-3.c REVIEW-1 (round 1, fresh reviewer)

Scope reviewed: cc/C-CTX-3.c tip c33e189af (single commit on base 86f80fef8), worktree clean, 10 files +1071/−4, vs BRIEF (design-prv-ctx.md:843-868) + REPORT.md.

VERDICT: FINDINGS — 0 CRITICAL / 1 MAJOR / 2 step-local MINOR.

## Findings

1. **MAJOR** `candy-code/src/Protocol/Methods/ContextMethods.php:151` (instructionWrite sentinel branch; eager create at `:257` in personalWriteTarget).
   When the personal listing row is the `~/.claude/CLAUDE.md` stand-in (`~/.candy-code/AGENTS.md` absent), the panel Edit spells `path:'personal'` + `expectBytes` = listed bytes (e.g. 512). `personalWriteTarget()` **creates a 0-byte 0600 AGENTS.md before the expectBytes latch at :188-190**, so the write refuses `instruction_changed` — but the empty primary now MASKS the CLAUDE.md stand-in (loadPersonal: primary wins whenever it exists). Net: a REFUSED operation silently evicts the operator's personal instructions from context and lands nothing, violating the step's own refusal-costs-nothing law (pinned for listed rows by the symlink-swap test; unpinned for the sentinel arm). Fix: resolve the sentinel target path WITHOUT creating it, run the expectBytes latch against `is_file ? filesize : 0`, and defer the empty-file create into the success write path only. Add regression pin: expectBytes refusal on a sentinel row creates no file and leaves the stand-in loaded. Not disclosed in REPORT.md.

2. **MINOR (step-local)** `candy-code/src/Protocol/Schema/MethodSchemas.php:7` — unused import `SugarCraft\Code\Context\InstructionFileLoader` (only occurrence in file). Fix: reference `InstructionFileLoader::MAX_DOCUMENT_BYTES` (=61_440, ContextMethods already uses it) in the two `Schema::string(61440)` at :524/:525 — satisfies php-cs-fixer no_unused_imports and the cap-drift law.

3. **MINOR (step-local)** `candy-code-web/src-web/components/Composer.vue:648-652` (answerQuickAdd) — with the chip up, editing the draft then Enter confirms the STALE offer line and success clears the edited draft; no modelValue watcher retires quickAdd (:324 watch covers chips/images only), contradicting the :622-633 docblock sentence that an edited-draft send re-evaluates the shape. Fix: in answerQuickAdd re-derive `quickAddLine(props.modelValue)`; if it differs from the offered line, re-seat (or retire) before confirming.

## Contract items — PASS

- **Server block**: Scope::Write registration ✓; jail = fresh contextSources() listing equality + held() re-containment ✓; exactly-one-of content/append (-32602), payload + resulting-size caps, expectBytes latch (-32009), is_link refusal, announce context.changed(reason instructions) on success only ✓; MethodSchemas registration ✓; ContainedPath roster row + numeral pair (SIXTY-FIVE/THIRTY-THREE) moved in-step ✓. §6: no ruling touches this step. E646: N/A.
- **Web block**: ContextPanel per-row Edit gated on write scope, expectBytes latch, reload-on-conflict, personal sentinel kept ✓. Composer `#` chip: append '- text\n', Esc-retire; '##'/multi-line/bare/mid-busy never raise — all pinned.
- **Hot-file (Composer.vue) vs cc/C-MCP-2.c @ 58fff9bb6**: its hunks @@ 29/38/113/141/152/159/178/189/541/559/587(-597)/640 vs this lane's @@ 4/360/598(-603)/609/738/954 → SECTION-DISJOINT (closest 597 vs 598, adjacent-only). Welder re-gate rider documented ✓.
- **Generated trio**: schema/SERVER.md/generated.ts NOT in `git show c33e189af --name-only`; tree clean → §1.8 restore honored; trio regen + dist = documented weld riders ✓.
- **Tests non-vacuous**: 10T/42A behavioral (real temp HOME jails, symlink swaps, byte latches). Mutation reasoning: expectBytes-latch removal → refusal test flips to success (red) with direction pinned by carry-arm expectBytes=23; busy-neuter → mid-turn chip test reds; canWrite-neuter → affordance legs red. 9-red claim plausible.
- **Gates reproduced** (worktree, linked vendor): php -l ×4 clean; phpunit `ContextInstructionWrite|ContextMethods|ReadPathCensus` 30T/725A; docs window 113T/4514A; vitest SERIAL 4 files 35/35 pass.
- **Foreign expected-reds reproduced at base**: ContainedPathInventoryTest 2 arms (AgentMemory/SkillLoader roster drift, W20-cure family) fail identically base vs tip with the lane's ContextMethods row as clean diff context — lane-consistent, NOT filed. 2 fixture deprecations = disclosed pair. ReadPathCensusTest `Chat.php|file_get_contents` 2→3 arm red at BASE (Chat.php untouched by lane) — third member of the same foreign W20-cure family; **carry to welder/closeout, not a lane finding**. ProtocolSchemaDriftTest staleness + README/suite-figure staleness pair = expected §1.8 trio shape.
- Report traceability: takeover disclosure (~01:00) content-verified against BRIEF; provenance irrelevant per contract.

## Fix order

MAJOR-1 first (needs src reorder + new pin); minors ride the same fix lane. Re-review after fixes.
