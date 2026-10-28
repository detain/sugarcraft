# BRIEF C-EXT-2.d — `/skills enable|disable` + picker toggle through SkillManager

area: ext  item: C-EXT-2 (sources: k2-dorm SkillManager accessors)  kind: dormant-wire · effort M  wave:   serialized: [docs:SKILLS.md] [docs:SETTINGS.md] [docs:COMMANDS.md] [lang:host.skills.,cmd.skills.,tui.app.] [hot:candy-code/src/Host/Commands/SkillsHostCommand.php] [hot:candy-code/src/App/App.php]
deps: none

## approach
`SkillManager::enable()`/`disable()` (`SkillManager.php:292-320`) have no caller. `App::handleSelectSkill()` (`App.php:4735-4785`) re-implements enable inline and can never disable. Changes:
- `/skills disable <name>` and `/skills enable <name>` write `disabledSkills` (user tier) through the workspace `SettingsWriter`, the RenderModeHostCommand idiom.
- The same commands flip the live registry (`$context->workspace->skills` → `SkillRegistry::disable/enable`), so the Skill tool and `$name` mentions honour the change at once. The frozen listing catches up next session, or through C-EXT-3.a's delta.
- The App picker routes through `SkillManager::enable()`, and re-selecting an enabled skill calls `SkillManager::disable()`.
- `applyToApp()` stays an embedder API and is not deleted.
- Serve gets the change free through `SkillsHostCommand`.

## files.create
none

## files.modify
`candy-code/src/Host/Commands/SkillsHostCommand.php` (`run` verbs), `candy-code/src/App/App.php` (`handleSelectSkill`), `candy-code/lang/en.php` (`host.skills.*`, `cmd.skills.hint`, `tui.app.*`), `candy-code/docs/SKILLS.md`, `candy-code/docs/SETTINGS.md` ("Saving from the settings view": a new writer door), `candy-code/docs/COMMANDS.md` (regen through `tools/gen-command-docs.php`)

## tests
create `candy-code/tests/Host/Commands/SkillsEnableDisableTest.php` (persisted plus live; an unknown name is refused). Modify `candy-code/tests/Host/Commands/SkillsHostCommandTest.php`, `candy-code/tests/App/AppSkillDispatchTest.php` (toggle off), `candy-code/tests/Config/Settings/SettingsWriterCensusTest.php` (the new `write()` caller).

## docs/guards
SettingsWriterCensusTest and SETTINGS.md move in-step; gen-command-docs.

## risks
one launch's registry is shared by its forked children, so a disable mid-turn takes effect from the next turn.

## ruling
none
