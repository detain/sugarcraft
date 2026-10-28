# BRIEF — C-CTX-4.c
## Walk past the git root; absolute and `~` user-tier sources

- wave: 15 (from plan §3) | area: prv-ctx | kind: gap · effort S | effort:  | phase: normal
- deps: none
- serialized: [bootstrap:instructionLoader, forcedInstructions] [docs:SETTINGS.md] [docs:MEMORY.md] [hot:candy-code/src/Context/InstructionFileLoader.php] [hot:candy-code/src/Config/Settings/Definitions/MemoryRuleSettings.php] [hot:candy-code/src/Config/LayeredSettings.php]
- ruling: none

## Item
C-CTX-4 (sources: GAP-INS-07, GAP-INS-11 (absolute half))
## Approach

- A new user-tier bool `instructions.walkToRoot` lets `ancestorRoot()` (`InstructionFileLoader.php:845-880`) continue above the nearest `.git` up to, but not including, `$HOME`, under the same containment and budget.
- `loadForced()` (:1043-1075) refuses absolute and `~` patterns. It now accepts them when they come from the user tier only.
- `Bootstrap::forcedInstructions()` (:8965) splits user-tier from project-tier patterns, so a checked-out repo can never name `/etc/...`.
- Each match is resolved through a new `ContainedPath` anchor (the user's home).
## Files to create
- `candy-code/tests/Context/InstructionWalkPastRootTest.php`
## Files to modify
- `candy-code/src/Context/InstructionFileLoader.php` (`ancestorRoot()`
- `loadForced()`)
- `candy-code/src/Cli/Bootstrap.php` (`instructionLoader()`
- `forcedInstructions()`)
- `candy-code/src/Config/Settings/Definitions/MemoryRuleSettings.php`
- `candy-code/src/Config/LayeredSettings.php` (gen)
- `candy-code/docs/SETTINGS.md` (gen)
- `candy-code/docs/MEMORY.md`
## Tests
InstructionWalkPastRootTest (walks above `.git` only when set; stops before `$HOME`; a project-tier absolute pattern is still refused); modify `candy-code/tests/Support/ContainedPathInventoryTest.php` (`ROUTED_CALL_SITES` row for the new anchor)
## Docs / guards
settings gen + `SettingsSchemaDocDriftTest`; `ContainedPathInventoryTest`
## Risks
walking above the repo can pick up a stray CLAUDE.md in a parent workspace. That is why it is opt-in.
