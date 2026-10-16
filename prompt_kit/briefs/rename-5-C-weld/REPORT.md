# Batch-C weld report (rename campaign, 2026-10-16)

Verdict: **ALL 13 C-lanes welded, all 5 gates green, master pushed.**
Pushed chain: `c7eee1b9e..71bcec54f` (weld tip), report commit follows. `origin/master...master = 0/0`.

## Base / upstream state
- Prompt expected base 4d472bc62; actual origin/master had moved to c7eee1b9e (foreign docs-only commit: sugar-crush/COMPARISON.md + sugar_issues.md). Weld based on c7eee1b9e (ancestor of 4d472bc62-era tree + tip); absorbed as ancestor, not rewritten.
- All 13 lane branches verified local == origin == brief SHAs before merging.

## Merges (--no-ff, sequential c1→c13, author-law merge commits)
| lane | merge | rename |
|---|---|---|
| c1 | ec0182c02 | sugar-boxer → sugar-panels (dir was sugar-boxer, not candy-boxer) |
| c2 | 2edec5165 | sugar-stickers → sugar-flexbox |
| c3 | b89d6bd81 | sugar-crumbs → sugar-navstack |
| c4 | 94ebdf5b3 | sugar-veil → sugar-overlay (amended in-step: markers staged by a bad `&&`-less chain — cures ReelDependencyTest:49 base-red) |
| c5 | 1886268e7 | candy-hermit → sugar-quickpick |
| c6 | a50376f1c | candy-lister → sugar-treelist |
| c7 | 16bdaecc3 | sugar-mcp → sugar-mcp-client |
| c8 | aeebb8ffa | sugar-dash → sugar-dashboard |
| c9 | 0bd7c7edb | sugar-bits → sugar-widgets |
| c10 | e15a11a69 | candy-forms → sugar-forms |
| c11 | f11a79947 | candy-mouse → sugar-mouse (amended after parallel-git race mislabeled fb306fd0f) |
| c12 | d77767515 | candy-focus → sugar-focus |
| c13 | 5ffbf924f | candy-fuzzy → sugar-fuzzy |

Post-merge weld commits:
- 696fec66f `rename: C-weld lock refresh` — ONE `composer update <13 new slugs> --no-install` after all merges (lock verified: 0 stale old-slug packages, 13 new present; sugar-readline dev-master hash bump side-effect accepted). No per-lib composer.lock committed.
- 67274cfa3 — c10 re-added (modify/delete) sugar-bits/tests/AliasesTest.php; folded its 3 forms tokens into canonical sugar-widgets/tests/AliasesTest.php, dropped the stray copy.
- 71bcec54f `rename: C-weld residue sweep` — Phase-A live-doc retoken (README, MATCHUPS, index.html, PROJECT_NAMES incl. SugarForms row from c10, docs/_data json+body, search-index.json manual, gen-docs regen); Phase-B `<!-- @rename-keep -->` provenance tags on 69 historical lines.

## Conflict resolution patterns used
- tools/renames.json: 3-way per-entry line merge keyed (batch,kind,old); field-wise prefer side differing from base; status takes max progress; c7 `"protect":["sugar-mcp-client"]`, c10 fold L61→sugar-forms, c11 merge L60→sugar-mouse all verified present in final file; 13/13 entries status=renamed; compact one-line style preserved.
- Root composer.json: take-both on require/repositories (all 13 new slugs in, all 13 old out).
- composer.lock: ours during merges, refreshed once at the end (above).
- PROJECT_NAMES.md ~L55: hand-merge — "Layout | Layout, Grid, Panels, Flexbox" carries BOTH c1 and c2 entries; c12: HEAD SugarFocus row kept, stale CandyFocus side dropped.
- docs/lib/<old>.html clashes: keep the redirect-stub side (c2 ours / c10 theirs patterns).
- docs/index.html, MATCHUPS.md, README.md, vhs.yml arrays, use_libs.txt: token-union resolution (new spellings from each side, foreign content preserved).
- prompt_kit/briefs/rename-followups.md: append-append → both blocks kept.
- Repair incident: a python re.sub replacement mangled renames.json (backslash handling) → file corrupted with trailing dup block; fixed surgically with str.replace + json.loads verification.
- Tooling for these shapes: /tmp/opencode/weld/{weld_union.py,repin_renames.py,union_blocks.py,full_lane.sh}.

## Vendor hygiene (post-merge, required by the renames)
- sugar-crush vendor had dangling old-name symlinks → `refresh-deps --mode=linked --libs=sugar-crush` (23/23 ok).
- 12 remaining old lib dirs existed on disk as gitignored husks (vendor/ + .phpunit.cache/ + composer.lock only; 0 tracked files) → removed (RenamesJsonTest requires exactly one side on disk).
- 13 consumer libs (candy-codetime, candy-files, candy-git, candy-kv, candy-mdview, candy-minesweeper, candy-scriptui, candy-sql, candy-ssh-launcher, candy-top, sugar-charts, sugar-gallery, sugar-readline) relinked `--mode=linked`. Final `--status`: linked=55 published=0 mixed=0.

## Gates (on committed tip; tree clean)
1. Residue: `--all-done` → 30 entries, 0 findings. Per-old ×13 (sugar-boxer sugar-stickers sugar-crumbs sugar-veil candy-hermit candy-lister sugar-dash sugar-mcp sugar-bits candy-forms candy-mouse candy-focus candy-fuzzy) → all 1 entry checked, 0 findings. **PASS**
2. 13 lib suites (linked, `env -u LINES -u COLUMNS vendor/bin/phpunit </dev/null`): panels 201/456 · flexbox 221/476 · navstack 144/260 · overlay 258/561 · quickpick 159/601 (exact figure requires a pty for QuickPickSigwinchTest; pipe shape = 159/597+1 skip) · treelist 162/386 · mcp-client 226/899 · dashboard 7483/14859 + 2 pre-existing F (WeatherModule/Persistence, expected) · widgets 570/1246 · forms 2343/4564 · mouse 343/916 · focus 93/412 · fuzzy 1006/35741. **PASS 13/13 exact**
3. sugar-crush sharded K=8 (`scripts/parallel-tests.sh --durations scripts/parallel-tests-durations.tsv --against-json sugar-crush/tests/Config/Support/suite-figure.json`, fresh --out): all 8 shards rc=0, wall 218s; conservation tests=22564 vs pinned 22564 (**+0T**), assertions +86 wobble (tolerated by design), 0F/0E/2S; CONSERVATION PASS. ReelDependencyTest ran green in shard 7 — the c4-carried :49 census red is GONE. **PASS**
4. tools/tests: 426T/1484A OK. (First run caught the old-dir husks via RenamesJsonTest; green after husk removal + relinks.) **PASS**
5. Policy rc=0 ×4: check-path-repos --no-lib-path-repos · check-child-lifetimes (60 libs, 28 proc_open sites, 8 findings, 8 accounted, 0 problems) · gen-docs --check (61 pages + 30 stubs) · gen-ansi-readme --check (3595 pieces). **PASS**

## Push
- fetch → origin/master c7eee1b9e confirmed ancestor (0/57); `pull --rebase` attempt aborted deliberately (would flatten the merge chain and conflict on unrelated lane patches) — direct fast-forward push c7eee1b9e..71bcec54f; post-fetch verify 0/0. **DONE**

## Not done (later agents, per brief)
- R8/R9 per-lane remote-done commits + Packagist creates/abandons for the 13 new slugs.
- Batch D lanes.
