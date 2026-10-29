# C-CTX-3.e — WELD (batch-14, W22-run)

Welded 2026-10-28 atop master e91eb1d47 (batch-13 tip), wave position 1/5.

## Picks (zero conflicts, %ae/%ce detain, cherry-pick -x)
- 877062eab → fdcb23bb1 (memory export command + import scope)
- f799a5429 → f2d31b13a (review fixes round 1; §4 cite)

## Merged-tree gates (cwd=candy-code, plain pipe) — ALL GREEN
- `--filter 'MemoryExport|MemoryImport'` → OK 78T/273A (exact vs REVIEW-2)
- `--filter MemoryCommand` → OK 36T/138A (exact)
- `--filter 'DocFigureProseDrift|MemoryDocumentationDrift|FrontmatterCallSites'` → OK 108T/4165A (T exact; +5A tree-scan wobble vs review 4160A)

## DUR rows (scripts/parallel-tests-durations.tsv)
- MINTED `candy-code/tests/Host/Commands/MemoryExportCommandTest.php 0.231 8`
- RE-DERIVED `candy-code/tests/Commands/MemoryImportCommandTest.php` 1.104/11 → 1.874/16 (lane added 2 methods + ambient; row moved to its descending slot ~line 187)

## Notes
- REVIEW-2.md canonical (round 2 CLEAN). REPORT.md stale disk copy backed up to /tmp/opencode/b14-disk-backup then replaced by lane-committed version (canonical superset, landed via f2d31b13a).
- NIT carry (REVIEW-2, owner closeout only): export overwrite-risk wording note.
- No regen: zero Protocol/docs/web file movement; gen-command/tool/settings-doc --write reported up to date.
