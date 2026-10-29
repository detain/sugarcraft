### C-SEC-7.c — Plan mode drops the tools the gate always refuses
- item: C-SEC-7 (sources: GAP-TL-27, k2-sum TL-27 web ✅→🟡)
- kind: gap · effort M
- approach:
  - Plan mode refuses writes at the gate, but the model still sees every tool (`docs/PERMISSIONS.md:487-488`: "The tool list is the same in every mode").
  - The gap's proposal (drop `Runtime::WRITE_CAPABLE_TOOL_NAMES`) is wrong. Plan mode allows `Write` of a `.md` into `.candy-code/plans/` and read-only `Bash`, so both must stay.
  - Instead, `EngineBackend::turnTools()` (:3151) drops each tool whose declaration the gate refuses: `PermissionGate::refuses(ToolDeclaration)` (:564), the gate's own declaration verdict. The roster therefore cannot drift from the enforcement. Apply it only while the mode is Plan. `PlanExit` stays.
  - Accept one prompt-cache break per mode switch, and document it beside the cache law.
- files.create: `candy-code/tests/Backend/PlanModeToolRosterTest.php`
- files.modify: `candy-code/src/Backend/EngineBackend.php` (`turnTools`), `candy-code/docs/PERMISSIONS.md` (:487-488), `candy-code/docs/PROMPT_ENGINEERING.md` (cache-law carve-out; verify the page in-step)
- tests:
  - The new test: in Plan, `Edit`/`ApplyPatch` are absent while `Write`, `Bash`, `Read` and `PlanExit` are present; in Default, the roster is unchanged.
  - modify `candy-code/tests/Providers/PromptPrefixByteStabilityTest.php`: the prefix is stable within a mode, with the one mode-switch break carved out.
- docs/guards: `PermissionsIntroDriftTest` (the "same in every mode" sentence)
- serialized: [hot:candy-code/src/Backend/EngineBackend.php] [docs:PERMISSIONS.md] [docs:PROMPT_ENGINEERING.md]
- deps: none
- phase: normal
- ruling: none
- risks: a configured `Deny <Tool>` rule now also hides that tool in Plan mode. That is correct by construction, but the doc must say so.

### C-SEC-7.d — Model-judged review for writes, with a chosen reviewer model
