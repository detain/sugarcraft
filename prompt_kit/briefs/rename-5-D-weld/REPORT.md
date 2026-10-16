# Batch-D weld report (2026-10-16, sole master writer)

Verdict: **WELD COMPLETE — pushed master `05d5ed31f`** (tracker+report follow as fast-forwards). All gates green on committed tip. origin/master in sync 0/0.

## Pre-state
- Verified via `git fetch origin '+refs/heads/rename/d*:...'`: all 7 lane refs present; master==origin/master==`55fb3bb93`; tree clean. No rebase needed (origin/master ancestor of HEAD by definition). d2–d7 branched from `55fb3bb93`; d1 branched from C-tip `8c30e631a` (15 commits behind — no conflict effect).
- git config authors Joe Huss/detain@interserver.net directly; every merge/fix commit verified `%ae`/`%ce` grep -c detain == 1, no Co-Authored-By.

## Merges (`git merge --no-ff origin/rename/dN`, sequential)
1. **d1 candy-mosaic→sugar-image** → `6ae4a8016`, ZERO conflicts. <!-- @rename-keep -->
2. **d2 candy-shine→sugar-markdown** → `1c12e740a`, 9 conflict files: tools/renames.json (take-both per-line union via repin pass), .github/workflows/vhs.yml, README.md, docs/_data pair, docs/lib page, docs/repo_map_step_30.md, findings/plan.md (HEAD side + shine→markdown/CandyShine→SugarMarkdown/`SugarCraft\Shine\Renderer`→`SugarCraft\Markdown\Renderer` flips), findings/resume_prompt.md (old names historical → @rename-keep both sides). Lesson captured: end-marker regex needs `>>>>>>> ref` WITH space; rename/rename markers carry `:path` suffix.
3. **d3 sugar-reel→sugar-video** → `78664e6a2`, 20 conflict files. Per-hunk line-zip resolver (difflib over marker-stripped canon): equal→keep; canon-equal→HEAD (+keep-marker merge); NORM-collapse-equal→HEAD with d3 flips applied ONLY on unmarked lines; else anomaly→flip(HEAD)+manual review. composer.lock taken `--ours` (every conflict). ns flip uses SINGLE backslashes (`SugarCraft\Reel`→`SugarCraft\Video`) — doubled-backslash first attempt silently no-opped, merge aborted+redone. HalfBlockRenderer alias kept d1's ImageHalfBlockRenderer spelling (correct — d1 renamed mosaic side). Post-merge residue found 4 cross-scope untagged lane-NOTES lines → tagged `239584c96`. <!-- @rename-keep -->
4. **d4 candy-flip→sugar-animation** → `9a0975bb0`, 32 conflict files (worklog 14 hunks, sugar-image/sugar-video cross-refs, PosixMasterPty, backlog, plan_crush_media). Two resolver bugs caught+fixed mid-weld: (a) 'equal' branch kept HEAD raw without flip (93 unflipped findings), (b) FLIPS/NORM missed FlipDecoder/FlipFrame alias words. **Critical repair**: resolver 'replace'-branch anomaly handling silently dropped theirs-appended sections in prompt_kit/briefs/rename-followups.md — D4+D5 sections restored byte-faithful from `origin/rename/d4|d5`; D6/D7 appended; final order D3–D7 verified. <!-- @rename-keep -->
5. **d5 candy-kit→sugar-cli** → `8b06fb794`, 30 conflict files + 47 cross-lane residue findings in files d5 never touched (repo_map step/update files, scripts/bootstrap-org-repos.sh, set-org-default-master.sh, sugar-crush/docs/ARCHITECTURE.md, sugar-widgets/examples/item-list.php, use_libs.txt). Generic sweep `d-sweep.py <old> <new> <ns…> <pascal…> [tags]` = run `check-rename-residue --old=X`, flip every untagged finding line (assert non-noop), tag historical ones. 42 flipped → 0. renames.json recovery lesson: NEVER `checkout --ours` it before repin (markers are the repin input). <!-- @rename-keep -->
6. **d6 honey-bounce→sugar-spring** → `cadd4f192` (138 files; d-sweep 39 flips → 0 findings). After this, **no `honey-*` dir remains** (plan Phase-6 check satisfied, `ls -d honey-*` empty). <!-- @rename-keep -->
7. **d7 candy-palette→sugar-colorprofile** → `e07ba4f66` (111 files; d-sweep 77 flips → 0 findings; crush refs comment-only — its consumer churn fully covered by the single weld K=8 run). d7 conflicted composer.lock had been swallowed by the resolver (it sat in the U-list) → restored `--ours`, lock validity re-asserted. <!-- @rename-keep -->

## renames.json
Take-both per-line union each merge (repin against markers, compact one-line-per-entry, protect keys + C-era statuses preserved). Final: all 7 D lines `renamed`, zero old slugs present, E/F lines untouched pending. Brief's "Batch-E flaky entry bump" contingency was vacuous — no `flaky` entry exists in renames.json and no lane touched any E line.

## Post-weld
- **Lock refresh** `01c87e172`: exactly ONE `composer update` of the 7 new slugs `--no-install`; lock contains zero `sugarcraft/{candy-mosaic,candy-shine,sugar-reel,candy-flip,candy-kit,honey-bounce,candy-palette}` entries. (refresh-deps `--root` afterward re-resolved third-party dev refs — that drift reverted to keep the committed targeted-refresh lock authoritative.) <!-- @rename-keep -->
- **Husk cleanup**: `rm -rf candy-mosaic candy-shine sugar-reel candy-flip candy-kit honey-bounce candy-palette` (each verified 0 tracked files first — vendor/.phpunit.cache/composer.lock husks only). <!-- @rename-keep -->
- **Dangling vendor links**: 37 stale `*/vendor/sugarcraft/<old>` symlinks pruned repo-wide (refresh-deps does not prune — d3 lesson): consumers candy-sql, sugar-crush, root vendor, sugar-widgets, sugar-flexbox, candy-mdview, candy-gifview, candy-flappy, sugar-gallery, candy-top, candy-log, candy-scriptui, sugar-overlay.
- **Re-link**: `php scripts/refresh-deps.php --libs=<7>,sugar-crush --root --mode=linked` → 8/8 linked, escaping=0, root rc=0, committed-tree guard rc=0, tree clean after (scratch manifests discarded).

## Keep-tag commit `05d5ed31f`
`--all-done` at base `55fb3bb93` already carried 56 findings (measured in a temp worktree) — C-weld-era R8R9.md/REPORT.md quote old slugs untagged; weld added 6 more (D4/D5/D7 NOTES cross-refs). Tagged 46 lines `<!-- @rename-keep -->` (27 R8R9.md + 16 REPORT.md + 3 NOTES) per house precedent → `--all-done` **0 findings (37 entries checked)**.

## Gates (all on committed tip)
| Gate | Result |
|---|---|
| residue --all-done | **0 findings** / 37 entries |
| residue --old ×7 | mosaic 0 · shine 0 · reel 0 · flip 0 · kit 0 · honey-bounce 0 · palette 0 | <!-- @rename-keep -->
| sugar-image | **661T/14817A/6S** EXACT |
| sugar-markdown | **938/2378** EXACT |
| sugar-video | **552/8488/1S** EXACT |
| sugar-animation | **191/887** EXACT |
| sugar-cli | **272/1634** EXACT |
| sugar-spring | **210/6088** EXACT |
| sugar-colorprofile | **529/5537/1S** EXACT |
| crush K=8 (parallel-tests.sh, --timeout 900) | **22,564T conserved** — run 1 all 8 shards rc=0 (0F/0E, 2S=canary pair); verification rerun logged 1F = ChatTest::testAlwaysReply… 'Tool execution timed out or produced no result' async-timeout flake, **green isolated** (1T/11A) — not rename-related |
| tools/tests | **426T/1484A** EXACT (sugar-image/vendor/bin/phpunit, _bootstrap, --no-configuration) |
| check-path-repos --no-lib-path-repos | rc0 |
| check-child-lifetimes | rc0 (60 libs, 28 proc_open sites, accounted) |
| gen-docs --check | rc0 (61 pages + 37 stubs) |
| gen-ansi-readme --check | rc0 (3595 pieces) |

## Push
`git fetch origin master` → `55fb3bb93` still ancestor → plain `git push origin master` → `55fb3bb93..05d5ed31f`; `rev-list --left-right --count origin/master...HEAD` = **0/0**. No force, oc.7z/foreign untracked untouched (absent from staging throughout).

## Anomalies / notes for R-agent & closeout
- R8/R9 NOT done here (by order): all 7 D entries status `renamed`, remote-rename + packagist-create owed serially per renames.json.
- `check-rename-residue --all-done` at base was NOT zero (56 C-era findings) — C-weld closeout evidently skipped it; now healed by tagging, future welds should re-run --all-done at base BEFORE gate expectation.
- K=8 rerun flake family (ChatTest async 'produced no result') is load-shaped; conservation truth = run 1.
- d4 followups-section drop was the weld's only real data-loss scare — recovered from lane refs; resolver's 'replace' branch is the known hazard for append-style doc conflicts (readme/tracker files): verify theirs-append sections survive after every future weld using this resolver.
- Pre-existing base-true @rename-keep-marker lines in prompt_kit/findings/crush-report/09-aider.md + docs/old/crush_report.md are aider-idiom dirt, never chased (per campaign law).
