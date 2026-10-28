# BRIEF — C-AGT-5.a — Model-callable `Schedule` tool
- area: agt | kind: gap · effort M | effort:  | wave-deps: C-AGT-1.a, C-AGT-1.b, C-AGT-1.c, C-AGT-7.a
- item: C-AGT-5 (sources: GAP-TL-22, p1-miss: schedules UX)
- serialized: [catalog:position 41] [runtime:WRITE_CAPABLE_TOOL_NAMES] [docs:README.md] [docs:ARCHITECTURE.md] [docs:PERMISSIONS.md] [docs:SCHEDULES.md] [lang: `tool.schedule.*`]
- ruling: none
- risks: a prompt-injected model can propose recurring runs. Ask class, the mode ceiling and the row cap are the bounds; the docs must say so.
- files.create: `candy-code/src/Tools/BuiltIn/ScheduleTool.php`, `candy-code/src/Background/ScheduleFactory.php`
- files.modify: `candy-code/src/Runtime.php` (`WRITE_CAPABLE_TOOL_NAMES`), `candy-code/src/Background/Schedule.php` (`createdBy`), `candy-code/docs/SCHEDULES.md`, `candy-code/README.md` + `candy-code/docs/ARCHITECTURE.md` + `candy-code/docs/PERMISSIONS.md` (regenerated tool blocks and counts), `candy-code/lang/en.php` (`tool.schedule.*`)
- tests: create `candy-code/tests/Tools/BuiltIn/ScheduleToolTest.php` (create writes a root-bound persisted row; a bypass mode is refused; the row cap; delete only touches model rows unless asked; list), `candy-code/tests/Background/ScheduleFactoryTest.php`; modify `candy-code/tests/Tools/Catalog/ToolCatalogTest.php` (position 41), `candy-code/tests/Tools/BuiltInToolCorpusTest.php`, `candy-code/tests/RuntimeTest.php` and `candy-code/tests/Permissions/ShellSubjectToolsCompletenessTest.php` (write-capable list)
- docs/guards: `php tools/gen-tool-docs.php --write` + `candy-code/tests/Tools/Catalog/ToolDocDriftTest.php`; a `candy-code/tests/Integration/ForeignAgentPresetWiringTest.php` census row for `Tools/BuiltIn/ScheduleTool.php` and `Background/ScheduleFactory.php` (`->permissionMode` readers); `docs/SCHEDULES.md`

## Approach

Add a `Schedule` tool (`#[BuiltInTool]` Ask class, position 41) with `create|list|delete` over `schedules.json`.
- Turns run forked, so the tool writes PERSISTED rows only; the file under its lock is the channel, and hosts reload by digest. It builds `ScheduleRegistry::loadDefault($context->root)` from `ToolBuildContext::$root`, so no LaunchContext/Bootstrap threading is needed.
- Rows are root-bound (C-AGT-1.a) and tagged `createdBy: "model"`.
- Mode is no looser than the session's and never bypass-class (the C-AGT-7.a ceiling); the default is the `dont-ask` floor.
- At most N model-created rows per root; the cadence floor comes from `Schedule`.
- A shared `Background\ScheduleFactory` holds the validation that `/schedules add` (5.b) reuses.
- Pin `Schedule` in `Runtime::WRITE_CAPABLE_TOOL_NAMES` (:704).
