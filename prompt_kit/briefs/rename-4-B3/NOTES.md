# Rename Phase-4 Batch-B Entry 3: candy-serve → candy-git-server — execution NOTES <!-- @rename-keep ledger -->
Base: 4bb435f3e (master==origin). Foreign oc.7z moved to /tmp/oc.7z.b3-hold (preflight demands clean tree; RESTORE at close).

- R0 PREP: recon complete (see plan/notes reads + tool mechanics in session record). Entry = tools/renames.json line 10. PROTECT collision check: no 'candy-serve-*' slugs exist → protect empty; candy-git is a DIFFERENT literal — R3's `candy-serve` token cannot match `candy-git`. <!-- @rename-keep ledger -->

## R1 dry-run (post tag-prep 1a6ce4bbe + notes commit)
- Plan: 122 content files, 13 path renames (R1=186 R2=126 R3=432 R5=42 R6=1 R7=45). exit=0.
- COLLISION CHECK PASS: zero `candy-git/` paths in mv list; `candy-serve` token cannot match sibling `candy-git` (distinct literal). The feared prefix collision is reverse-direction (a future candy-git-* rename must protect candy-git-server) — minted for closeout.
- Tag-prep delta confirmed: prompt_kit/CONTEXT.md, rename-baseline.md, rename-4-B1/NOTES.md dropped from rewrite set after @rename-keep; this NOTES.md's own historical lines tagged too.
- bin/soft-serve → bin/candy-git-server path rename present. Bare `soft-serve serve/init/...` usage docblock NOT rewritten by any rule (no bin/ prefix) → R4 manual prose fix (keep one "drop-in for charmbracelet/soft-serve" historical mention).
- tests/ServeTest.php keeps filename (tool renames dirs/known paths only; "ServeTest" matches no residue rule — confirm post-R3).
- docs/repo_map/pr_charmbracelet_soft-serve.md: 3 content mentions rewritten, filename stays (upstream record). charmbracelet_soft-serve*.md path set untouched by rules — correct.
- Consumers: root composer.json only (R3:2 require+repositories). No other lib requires sugarcraft/candy-serve.
