# Phase 4 Batch B — entry 2: candy-query → candy-sql (2026-10-15/16) <!-- @rename-keep -->

Procedure R chain from base `9ba813b8d` (== origin at start; tree clean except foreign `oc.7z` — moved to `/tmp/oc.7z.b2-hold` for the tool preflight (A2 family: preflight REJECTED the untracked root this time, unlike B1), restore after R7.

## Pre-R1 recon
- Baseline figure (rename-baseline.md:53): candy-query **1580T/11900A/0F/0E/0S** (col shape T/A/F/E/S/W; row now tagged @rename-keep).
- Env: ZERO getenv/$_ENV/putenv reads in `candy-query/src`+`bin` — `CANDY_QUERY_UPSTREAM*` exists only in docs/old (excluded) + CONTEXT.md historical lines (tagged) → **no LegacyEnv shim** (honest none, same verdict family as B1). <!-- @rename-keep -->
- User data paths: ZERO HOME/.config/XDG usage in src/bin; query history is an OPTIONAL caller-supplied SQLite path (`AppBuilder::withHistory($path)` → `SqliteHistoryStore(dirname+file)`) — no fixed dot-dir → **no LegacyPaths migration**.
- Completion assets: none shipped (bin/ has the single script). Underscore-blindspot refs: `_candy-query` ×4 content sites (findings/findings_resume_plan.md ×3, findings/plan.md ×1 — filename refs to `plan_candy-query.md` which the tool moves) → sed fix in R4 per B1 law. `project_candy_query_*` snake memory-IDs in CONTEXT.md are wiki-link block IDs, NOT package slugs — left as history (recorded). <!-- @rename-keep -->
- Consumers: zero libs require sugarcraft/candy-query (only root manifest) — grep-verified. <!-- @rename-keep -->
- ansi/candy-query/ art paints CANDY/QUERY wordmark → followups entry if confirmed at R6. <!-- @rename-keep -->
- Plan Phase-4 mandate: composer description → "Terminal SQL client for SQLite, MySQL and PostgreSQL — port of jorgerojas26/lazysql on the SugarCraft stack." + bootstrap (bin help header) to match.

## R1 preflight + dry-run
- `rename-lib.php --old=candy-query --dry-run`: 384 content files, 178 paths, R1=1163 R2=27 R3=1325 R5=2 R6=1. exit 0. Non-zero counts, ns pair Query→Sql present, dir move + bins sane. Cross-lib hits are docblock prose (sugar-dash/sugar-table/sugar-toast/tools/tests) — correct mechanical rewrites. <!-- @rename-keep -->
- Tag-prep commit (this entry): 26 CONTEXT.md historical lines + 1 rename-baseline.md row tagged `@rename-keep` (B1/A2 pattern). R5=2 hits were both CONTEXT.md historical `CANDY_QUERY_UPSTREAM*` mentions → vanish with tagging.
- Re-dry-run expected: 382 files (CONTEXT+baseline drop), R3 1325→1292, R5→0.
- Re-dry-run after tag-prep: 382 files (−2 exact), R3 1325→1292 (−33 exact), R5 2→0. Delta byte-clean vs prediction. Tag-prep commit c92c30e3a.
