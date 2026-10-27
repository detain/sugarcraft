### C-EXT-2.a — Skill resources: `asset` loads, resource listing, `${CLAUDE_SKILL_DIR}`
- item: C-EXT-2 (sources: GAP-SKL-10, k2-dorm `loadSkillAsset`)
- kind: dormant-wire · effort M
- approach:
  - **What's dormant.** `SkillLoader::loadSkillAsset()` (`SkillLoader.php:1054`, containment-checked through `ContainedPath::within`) has no caller. Read is jailed to the project root, so a user-tier skill's `scripts/` and `references/` are unreachable except by Bash.
  - **Fix.**
    - The Skill tool gains an optional `asset` argument: a relative path under `scripts/`, `references/` or `assets/`. The tool returns that file through `loadSkillAsset()` instead of the body, under the same two header lines.
    - Body results list up to 10 sampled resource paths, the OpenCode behaviour.
    - `${CLAUDE_SKILL_DIR}` and `${SKILL_DIR}` in a body become the base dir.
    - `SkillTool::invokedIn()` skips `asset` calls, so ReinjectionPlan never re-injects an asset as a body.
- files.create: none
- files.modify: `candy-code/src/Tools/BuiltIn/SkillTool.php` (`description`, `inputSchema`, `execute`, `invokedIn`), `candy-code/src/Skills/SkillLoader.php` (a resource-listing helper beside `loadSkillAsset`), `candy-code/docs/SKILLS.md` (the Skill tool and resources section)
- tests: create `candy-code/tests/Tools/BuiltIn/SkillToolAssetTest.php` (an asset loads; traversal is refused; outside the asset subdirs is refused; the listing is capped at 10; placeholder substitution; asset calls are excluded from `invokedIn`). Modify `candy-code/tests/Tools/BuiltIn/SkillToolTest.php` (schema), `candy-code/tests/Skills/SkillLoaderTest.php`.
- docs/guards: SKILLS.md. Re-pin any tool-schema byte figure in `tests/Providers/PromptStabilityTest.php` if the Skill schema moves it. The gen-tool-docs roster renders name and gloss only, so it does not move. Re-check `tests/Support/ReadPathCensusTest.php` now that the read path is live.
- serialized: [docs:SKILLS.md]
- deps: none
- phase: normal
- ruling: none
- risks: the Skill schema change moves the cached tool prefix once.

