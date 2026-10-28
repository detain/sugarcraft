### C-TOOL-7.b — Ls bounded heap
- item: C-TOOL-7 (sources: SHL-2-P1)
- kind: fix · MINOR · effort S
- approach: Root cause `candy-code/src/Tools/BuiltIn/Ls.php:313-347` (`collect()`): every entry is `is_dir`'d, ignore-checked and `realpath`'d into `$entries`, then `usort`ed, and the cap applies after. The comment "the walk IS the cost being bounded" (:306) is false. Fix: keep a bounded max-heap of the `maxEntries` smallest basenames plus a total counter, and report "N more entries not shown" with the same note shape. Defer `realpath` and the jail filter until after heap admission where possible. The listing order is unchanged.
- files.create: none
- files.modify: `candy-code/src/Tools/BuiltIn/Ls.php` (collect() + its docblock, the truncation note)
- tests: modify `candy-code/tests/Tools/BuiltIn/LsTest.php` (`testHugeDirectoryKeepsTheFirstNByNameWithoutHoldingAll`: a 20k-entry fixture, a `memory_get_peak_usage` bound, the same first-N as a full sort, and the total count is right)
- docs/guards: none
- serialized: none
- deps: none
- phase: normal
- ruling: none
- risks: the ignored/escaped counts must still be exact, so they stay full-walk counters (cheap integers).

