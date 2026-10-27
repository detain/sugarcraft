### C-TOOL-1.b — rg `--no-config` jail fix
- item: C-TOOL-1 (sources: SHL-4-P1)
- kind: fix · MAJOR · effort S
- approach: Root cause `candy-code/src/Tools/BuiltIn/Grep.php:972`: the rg command line has no `--no-config`, and `ProcessContainment::scrubbedEnv()` strips only secret-shaped names, so the operator's `RIPGREP_CONFIG_PATH` reaches rg. A `--follow` line in that file then walks a jail-internal symlink out of the root, and the hit path is still jail-prefixed, so `hitPath()` cannot tell (reproduced). The same leak breaks engine parity (`--smart-case`, `--max-columns`, `--type-not`). Fix: `--no-config` as the first rg flag. Also drop `RIPGREP_CONFIG_PATH` from the env handed to `runCaptured`, as belt and braces.
- files.create: none
- files.modify: `candy-code/src/Tools/BuiltIn/Grep.php` (rg command build ~:972, the "no -L/--follow" docblock ~:920)
- tests: modify `candy-code/tests/Tools/BuiltIn/GrepOptionInjectionTest.php` (add `testRipgrepConfigFileCannotWidenTheWalk`: putenv a temp rc holding `--follow`, a jail with a symlink to an outside dir, assert no hit, skip when rg is absent; plus a pin that the built command carries `--no-config`)
- docs/guards: none (the description does not enumerate rg flags)
- serialized: none
- deps: none
- phase: normal
- ruling: none
- risks: rg older than 0.9 lacks `--no-config`; the hosts in scope all ship rg ≥ 11.

