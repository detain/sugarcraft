### C-EXT-3.b — `skills_search` tool + an honest listing-overflow line
- item: C-EXT-3 (sources: GAP-SKL-14 context, CLAW-new#2)
- kind: gap · effort M
- approach:
  - **Root cause.** `SkillMatcher.php:58` writes the overflow line `"- %d more skills; call Skill to list them"`. The Skill tool's own description (`SkillTool.php:84-99`) says it "does not itself list, search, or run skills". A listing over `skills.listingMaxBytes` therefore points the model at a tool that cannot help.
  - **Fix.** A new `SkillsSearch` tool (`skills_search`, Read class, ParallelSafe, BuildsFromCatalog over `ToolBuildContext->skills`):
    - lexical ranking over name, description, `paths` and a bounded body head;
    - model-invocable and enabled skills only;
    - results carry the top K keys with their tier badge and a coverage line ("searched N of M"), with repo text through `SkillPromptLine::field`.
  - The overflow line now names `skills_search`.
- files.create: `candy-code/src/Tools/BuiltIn/SkillsSearch.php`
- files.modify: `candy-code/src/Skills/SkillMatcher.php` (`MORE_LINE_FORMAT`, notice text), `candy-code/docs/SKILLS.md` (listing budget). gen-tool-docs output: `candy-code/README.md`, `candy-code/docs/ARCHITECTURE.md`, `candy-code/docs/AGENTS_AUTHORING.md`, `candy-code/docs/PERMISSIONS.md`, `candy-code/docs/SETTINGS.md`.
- tests: create `candy-code/tests/Tools/BuiltIn/SkillsSearchTest.php`. Modify `candy-code/tests/Skills/SkillMatcherListingTest.php`, `candy-code/tests/Tools/BuiltInToolCorpus.php` / `candy-code/tests/Tools/BuiltInToolCorpusTest.php`, `candy-code/tests/Config/DocFigureProseDriftTest.php` (the built-in tool count, if spelled).
- docs/guards: `tools/gen-tool-docs.php --write`, ReadmeRosterDriftTest, ToolDescriptionSentenceFloorTest.
- serialized: [catalog] (the next free position, which the orchestrator assigns: 41 if this is the first new tool) [docs:README.md] [docs:ARCHITECTURE.md] [docs:AGENTS_AUTHORING.md] [docs:PERMISSIONS.md] [docs:SETTINGS.md] [docs:SKILLS.md]
- deps: none
- phase: normal
- ruling: none
- risks: a new tool schema moves the cached tool prefix once.


- ORCHESTRATOR ASSIGNMENT: catalog position = 42 (live max at cut = 41, verified src/Tools/BuiltIn grep; no other in-flight lane adds a tool).
