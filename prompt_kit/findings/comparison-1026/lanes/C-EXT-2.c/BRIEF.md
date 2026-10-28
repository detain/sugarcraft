# BRIEF — C-EXT-2.c: Forked skills (`context: fork`) via the turn-bound Task tool

- area: ext
- src: design-ext.md
- item: C-EXT-2 (sources: GAP-SKL-15, k2-dorm `dispatchSkill`, k1-dorm#8)
- kind: dormant-wire · effort M
- phase: normal
- ruling: none
- wave: None | late: False
- deps: C-EXT-2.a (SkillTool), C-EXT-2.b (model/effort semantics, FrontmatterKeyAudit)
- serialized (claims): [docs:SKILLS.md] [docs:README.md] [lang:tui.app.] [hot:candy-code/src/Backend/EngineBackend.php] [hot:candy-code/src/Tools/BuiltIn/TaskTool.php] [hot:candy-code/src/App/App.php] [hot:candy-code/src/Support/FrontmatterKeyAudit.php]

## Files to create
`candy-code/src/Skills/SkillFork.php`

## Files to modify
`candy-code/src/Tools/BuiltIn/SkillTool.php`, `candy-code/src/Tools/BuiltIn/TaskTool.php` (`runForkedSkill`), `candy-code/src/Backend/EngineBackend.php` (`turnTools` post-pass), `candy-code/src/App/App.php` (`dispatchSkill`, `handleSelectSkill` status), `candy-code/src/Skills/SkillMentions.php`, `candy-code/src/Skills/Skill.php` + `candy-code/src/Skills/SkillFrontmatter.php` (`agent`), `candy-code/src/Support/FrontmatterKeyAudit.php` (KNOWN skill `agent`; drop INERT `context`), `candy-code/lang/en.php` (`tui.app.skill_fork`), `candy-code/docs/SKILLS.md` ("The context field today", row :271), `candy-code/README.md` (:2370 "no fork executor")

## Declared regions
{"candy-code/src/Tools/BuiltIn/SkillTool.php": ["?"], "candy-code/src/Tools/BuiltIn/TaskTool.php": ["runForkedSkill"], "candy-code/src/Backend/EngineBackend.php": ["turnTools"], "candy-code/src/App/App.php": ["dispatchSkill", "handleSelectSkill"], "candy-code/src/Skills/SkillMentions.php": ["?"], "candy-code/src/Skills/Skill.php": ["?"], "candy-code/src/Skills/SkillFrontmatter.php": ["agent"], "candy-code/src/Support/FrontmatterKeyAudit.php": ["agent", "context"], "candy-code/lang/en.php": ["tui.app.skill_fork"], "candy-code/docs/SKILLS.md": ["today", "field", "context"], "candy-code/README.md": ["fork", "executor"]}

## Tests
create `candy-code/tests/Tools/BuiltIn/SkillToolForkTest.php` (the fork runs on the bound Task; model and effort forwarded; an unbound tool falls back inline), `candy-code/tests/Skills/SkillForkTest.php`. Modify `candy-code/tests/App/AppSkillDispatchTest.php` (delegation stays green), `candy-code/tests/Skills/SkillMentionTest.php`, `candy-code/tests/Support/FrontmatterKeyAuditTest.php`, `candy-code/tests/Tools/TaskToolEngineTest.php`.

## Docs/guards
the INERT ↔ docs guard. The Skill tool description changes, but gen-tool-docs renders name and gloss only, so it does not move.

## Approach (verbatim)

- **What's dormant.** `App::dispatchSkill()` (`App.php:2528`) has no production caller; it is a single-shot pool run. `SkillTool::execute()` loads every skill inline.
- **SkillTool fork path.**
- SkillTool gains `withForkDelegate(TaskTool)`. `EngineBackend::turnTools()` (:3148) binds every SkillTool to the turn's BOUND Task copy after its loop, so the engine, permission relay, emitter and spend all ride the Task path.
- For an `isContextFork()` skill, SkillTool calls a new non-schema `TaskTool::runForkedSkill()`. That method reuses `runOnEngine` with the skill's `agent` (default roster preset `coder`), its model and effort, and the body plus args as the prompt.
- SkillTool becomes `ExemptFromParallelDeadline`.
- With no delegate bound, the skill loads inline with a one-line note.
- **Mentions.** A `$name` or `/name` mention of a fork skill attaches a directive to invoke it through the Skill tool, not the inline body.
- **Skill frontmatter.** `agent` joins KNOWN for skills; `context` leaves INERT.
- **`App::dispatchSkill()` consolidation.** Its Agent construction (env block at root, skill model) moves into a shared `SkillFork`, and `dispatchSkill()` delegates to it. It stays an embedder API; nothing is deleted.
- **Status text.** The App picker's `tui.app.skill_fork` status is reworded to say the skill runs forked when invoked.

## Risks
an unbound TaskTool refuses. The test must use the copy `turnTools()` binds, not the launch instance.
