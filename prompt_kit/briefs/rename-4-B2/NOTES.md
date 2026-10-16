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
## R2 remote
- `scripts/rename-remote.sh candy-query candy-sql`: verified record (301 + name candy-sql). Repo DESCRIPTION on GitHub carried the stale "Terminal SQLite browser" wording — updated via `gh repo edit` to the plan's SQL-client sentence (R4 alignment).

## R3 run
- Tool: 382 files, 179 paths (+1 bin path = A1/B1 shape), R1=1163 R2=27 R3=1292 R6=1, exit 0.
- `php -l`: all touched PHP + bin clean. JSON valid (lib/root/renames/ansi/search-index/_data).
- Underscore C.2 doc paths moved by tool: findings/candy-sql.md, findings/plan_candy-sql.md, docs/repo_map/sugarcraft_candy-sql.md + update_sugarcraft_candy-sql.md — 53d9e944 fix re-confirmed.

## R4 followups (all landed pre-R7)
- README `> Formerly published as sugarcraft/candy-query` + `> Namespace changed SugarCraft\Query\ → SugarCraft\Sql\` (both @rename-keep, candy-git house format).
- composer description → plan's exact SQL-client sentence (the raw manifest literally said "Terminal SQLite browser" — plan premise TRUE) + keywords deduped (`sugarcraft`×2 pre-existing, A2 family) + added mysql/postgresql.
- bin help header → "candy-sql - Terminal SQL client for SQLite, MySQL and PostgreSQL" (bootstrap-description mandate). Root README row, docs/MATCHUPS row, docs/index.html tile title+summary, docs/_data (description/ogDescription/tagline) aligned browser→client.
- PROJECT_NAMES.md rationale rewritten honestly (FINDING C family; line tagged @rename-keep — residue caught it, A2 pattern).
- `_candy-query` blindspot: 4 sites sed→`_candy-sql` (findings_resume_plan ×3, plan.md ×1). ZERO remain untagged. `project_candy_query_*` snake memory-IDs left (block IDs ≠ slug; recorded).
- Env: zero reads → NO LegacyEnv. Data paths: zero (history = optional caller-supplied path) → NO LegacyPaths. MATCHUPS/index.html section move MOOT (row already under Apps). vhs.yml both hand-lists auto-rewritten (verified).
- ansi wordmark: recorded in rename-followups.md (167 pieces, some embed CandyQuery/QUERY text).
- Pre-existing FOREIGN vendor detritus hit: candy-sql/vendor/sugarcraft/candy-zone broken symlink (Phase-1.1 leftover) reddened refresh-deps `escaping=1` → removed the stale untracked symlink; refresh now OK rc0. NOT rename-related.

## R5 wiring
- root composer require+repositories rewritten by tool; `composer update sugarcraft/candy-sql --no-install`: lock churn = content-hash + ONE relocated package block (79/78 lines), sugarcraft-only.
- refresh-deps --mode=linked --libs=candy-sql: linked=1 21/21 OK rc0 (after candy-zone detritus cure); guard --no-lib-path-repos rc0. Consumers: root only.
- gen-docs + --check rc0 (61 pages + 6 stubs; candy-query stub arrives after R7 flip per A3 pattern — re-run inside R7). gen-ansi-readme --check agrees (3595 pieces).

## R6 verification (so far)
- Residue --old=candy-query: **0 findings** (post git add -A).
- candy-sql suite: **1580T/11900A OK exit0 — EXACT baseline** (0/0 delta).
- Sibling suites (prose-edited libs): sugar-toast 278T/669A EXACT; sugar-table 510T/1237A EXACT; sugar-dash 7483T/14859A/2F — EXACT baseline shape, reds are the two documented pre-existing AtomicJsonFile residue tests (WeatherModuleTest::testCacheRoundTripThroughAtomicFileLeavesNoTemp + State\PersistenceTest::testSaveLeavesNoLeftoverTempFiles), untouched by this entry.
- tools/tests: **426T/1484A EXACT floor**. check-path-repos rc0.
- Harness lesson RE-LEARNED live: `cd <lib> && ( watchdog ) &` backgrounds the whole chain → suite rc=127 (B1's recorded trap; use `;` after cd).
