# C-TOOL-3.c — Session-owned background shells
- area: tool | kind: gap · effort M | phase: normal
- item: C-TOOL-3 (sources: GAP-SH-04 serve half, p2-miss: SHL no web/serve shell list)
- deps: {st.get('deps','')}
- serialized: {st.get('serialized','')}
- ruling: none
- approach:
{st.get('approach','')}
- files.create: `candy-code/src/Tools/AcceptsShellOwner.php`, `candy-code/tests/Tools/ShellOwnershipTest.php`
- files.modify: `candy-code/src/Background/BackgroundShellRegistry.php` (row shape, start/status/read/stop, merge/parseRow, ownedBy), `candy-code/src/Tools/BuiltIn/Bash.php`, `candy-code/src/Tools/BuiltIn/ShellOutput.php`, `candy-code/src/Tools/BuiltIn/ShellStop.php` (implement + scope lookups), `candy-code/src/Backend/EngineBackend.php` (turnTools)
- tests: create the file above (two owners on one registry cannot read or stop each other's shells; a null owner is visible; the owner survives a fork merge); modify `candy-code/tests/Sessions/BackgroundShellRegistryTest.php`, `candy-code/tests/Tools/ShellStopTest.php`
- docs/guards: `candy-code/tests/Support/OneTypePerFile` gate via `tools/check-one-type-per-file.php` (new interface file)
- risks: P-SESSION-TABS assumes per-tab isolation; land this before it, or tell that lane.

(Extracted verbatim from schedule.json by lead — full design text in the referenced design file.)