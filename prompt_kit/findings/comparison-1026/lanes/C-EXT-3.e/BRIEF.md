### C-EXT-3.e — Self-learning skills: SkillWorkshop tool + versioned drafts + history/undo
- item: C-EXT-3 (sources: CLAW-new#1, GAP-SKL-14)
- kind: gap · effort M
- approach:
  - **Tool.** A `SkillWorkshop` tool (Write class, so it is asked in default mode) lets the model create or patch a skill during a turn.
  - **Store.** Writes land in a versioned store, `LearnedSkills`. It wraps `ProposedSkills`; each revision is a `LearnedSkillRevision` kept under `history/<n>.md`, with bounded revisions and archive. Names, descriptions and bodies use `ProposedSkills`' caps and sanitizer.
  - **Commands.** `/skills history <name>` and `/skills undo <name>` are new.
  - **Background review.** The dream pass already drafts skills (`memory.dreamProposeSkills`) and stays the review leg, so no new trigger is added.
  - **Default.** Workshop output stays a draft behind `/skills accept`. R-EXT-2 decides whether it may go live with announce-and-undo, as OpenClaw does.
- files.create: `candy-code/src/Skills/LearnedSkills.php`, `candy-code/src/Skills/LearnedSkillRevision.php`, `candy-code/src/Tools/BuiltIn/SkillWorkshop.php`
- files.modify: `candy-code/src/Host/Commands/SkillsHostCommand.php` (`history`, `undo`), `candy-code/lang/en.php` (`host.skills.history.*`, `host.skills.undo.*`), `candy-code/docs/SKILLS.md`. gen-tool-docs pages as in C-EXT-3.b.
- tests: create `candy-code/tests/Skills/LearnedSkillsTest.php`, `candy-code/tests/Tools/BuiltIn/SkillWorkshopTest.php`, `candy-code/tests/Host/Commands/SkillsHistoryUndoTest.php`. Modify `candy-code/tests/Tools/BuiltInToolCorpus.php`.
- docs/guards: gen-tool-docs, ReadmeRosterDriftTest, ReadPathCensusTest (a new user-tier path).
- serialized: [catalog] [docs:README.md] [docs:ARCHITECTURE.md] [docs:AGENTS_AUTHORING.md] [docs:PERMISSIONS.md] [docs:SETTINGS.md] [docs:SKILLS.md] [lang:host.skills.history.] [hot:candy-code/src/Host/Commands/SkillsHostCommand.php]
- deps: C-EXT-3.c
- phase: normal
- ruling: R-EXT-2. May a model-written skill go live without `/skills accept` (OpenClaw's announce-and-undo), or do workshop skills stay drafts?
- risks: live learned skills persist model-authored instructions into every later session, a prompt-injection persistence vector.

