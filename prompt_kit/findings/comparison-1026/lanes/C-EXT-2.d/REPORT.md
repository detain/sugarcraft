# C-EXT-2.d — DONE: /skills enable|disable + picker toggle through SkillManager

`/skills enable <name>` / `/skills disable <name>` persist the user-tier
`disabledSkills` list through the workspace `SettingsWriter` (refusal pre-check,
catch-throw → failure) and flip the live `SkillRegistry` when one is reachable;
unknown name / no writer / failed write refuse with nothing written.
`App::handleSelectSkill()` now routes the enable leg through the dormant
`SkillManager::enable()` (wired, not deleted) and re-selecting an enabled skill
toggles it off: `SkillManager::disable()` + a new `dropSelectedSkill()` that
removes the body from the chat backend carry. Serve/ACP get the verbs free
through the same host command; `applyToApp()` stays embedder-only.

Regression-first: new SkillsEnableDisableTest was 5/6 red, both App flips 2/2
red, census 1F before the src edits. Mutation: neutering the live-flip door
reddens exactly the 2 live pins; neutering dropSelectedSkill's filter reddens
exactly the backend-carries pin; both restored by md5-verified cp.

REVIEW-1 FIX (round 1): the write_failed leg was unpinned at the 6T tip (the
over-view line 6 claimed failed-write refusal without a test). Added a 7th
test — a throwing `SettingsWriter` door — asserting the Role::System failure
contains 'settings write failed' (not the generic host.skills.error
fall-through, which yields 'Skills: <msg>'), the persisted list stays empty,
and the live registry is not disabled; red-under-neuter proven (catch removed
→ exactly 1F naming the fall-through string, restored md5 076da49f). Gate
count for this file is now 7T/38A (the 6T gate lines below rode the 6T tree).
NIT deviations recorded: design-ext.md line 212 named `SkillsHostCommandTest.php`
for modification — untouched; the toggle lives wholly in the new file and the
SkillsHostCommand+SkillsEnableDisable prefix pins survive (16T green today); the "bites this session now"
wording is unconditional while the flip is `$live`-gated — the !live branch
is unreachable for TUI/serve drivers (both carry the workspace registry), so
awareness-only, no change.

Gates (targeted, cwd=candy-code, plain pipe, -u LINES -u COLUMNS):
- SkillsHostCommand+SkillsEnableDisable+RenderMode+AppSkillDispatch+Census: OK 56T/6173A
- tests/Host tests/Commands tests/App: OK 1741T/32518A; AppSkillTest+AppModelTest OK 71T/516A
- guard battery (DocFigure/Env/Readme/SettingsTier/WriteProducer/KeyBinding/TuiLang/GlobDialect/DupHelper/DupDocBlock): 424T, 1 FOREIGN red
- FOREIGN-BASE-RED disclosed: ChatHostLangCatalogueTest::testEveryCatalogueKeyIsAskedForByChatOrHost
  fails at base 4b771634d — a992413b0 (C-PRV-1.b) minted chat.effort.launch_unshaped called only from
  Cli/LaunchContext.php, which the guard's scan list omits. Out of ownership; report-only.
- php tools/gen-command-docs.php --check rc0 (table row regenerated in-commit; hand paragraph updated).

Files: src/Host/Commands/SkillsHostCommand.php, src/App/App.php, lang/en.php
(host.skills.{disabled_now,enabled_now,no_writer,toggle_unknown,write_failed} + usage/desc/hint +
tui.app.skill_disabled — all via Lang::t), docs/SKILLS.md + docs/SETTINGS.md + docs/COMMANDS.md,
tests/App/AppSkillDispatchTest.php (2 in-step flips), tests/Config/Settings/SettingsWriterCensusTest.php
(new toggleSkill->write roster row + docblock truth), NEW tests/Host/Commands/SkillsEnableDisableTest.php
(7T after the review fix — gate owes it a scripts/parallel-tests-durations.tsv row). suite-figure.json/durations.tsv/dist untouched.
