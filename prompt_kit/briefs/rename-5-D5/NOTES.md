# rename-5-D5 NOTES — candy-kit → sugar-cli <!-- @rename-keep -->

Lane: D5 (batch-D line 39, ns Kit→Cli, type=lib, bins {}). Branch `rename/d5` off `55fb3bb93`, worktree `/home/sites/sugarcraft-d5`. NEVER push master; push branch only. No R2/R8/R9 (brief law: no remote/packagist — serial post-weld phase owns remotes; D1 precedent).

## Entry facts (verified)

- tools/renames.json line 39: kind=rename, `candy-kit` → **`sugar-cli`**, lib, ns Kit→Cli, bins {}, no extra_literals/protect, status pending. <!-- @rename-keep -->
- BRIEF PREMISE CORRECTION: the spawn brief hypothesized "Phase 2 already created a redirect lib kit→candy-kit". DISK REFUTES: no `kit/` dir ever existed (git log --all --diff-filter=A kit/composer.json = empty; git log -S 'sugarcraft/kit' = empty), renames.json carries NO merge/redirect kit row, no docs/lib/kit.html stub. The lib has been `candy-kit` since inception (port of charmbracelet/fang). Phase-2 rows are infra steps 2.1–2.5 (none kit-related). Proceeding with the real disk entry: R-step alias machinery N/A (grep class_alias in candy-kit = 0; it is not a façade lib). <!-- @rename-keep -->
- Derived (per derive algebra): pascal CandyKit→SugarCli, compact candykit→sugarcli, env CANDY_KIT_/CANDYKIT_ → SUGAR_CLI_/SUGARCLI_ — env sites: ZERO tree-wide except third-party `COLUMNS` in Internal/WidthProbe.php (KEEP, not brand). No Lang.php, no lang/ dir, no bin/, no data paths → R6-lang no-op, no LegacyEnv/LegacyPaths (honest none). <!-- @rename-keep -->
- Pre-existence census: `SugarCraft\Cli` 0 sites tree-wide; `sugar-cli`/`SugarCli` only in plan_rename.md:155/343 + renames.json:39 (own entry). Clean.
- Consumers (composer require sugarcraft/candy-kit): candy-sql (1 use site: src/Terminal/BorderFrame.php `use SugarCraft\Kit\Frame`), sugar-crush (src/Cli/Help.php `use SugarCraft\Kit\HelpText` + tests/Support/ManifestDependencyReachTest live anti-vacuity pins :433 — both sides rewrite in-step, consistency structural), root composer.json + lock. Docblock/prose: ARCHITECTURE.md:1298 roster (live, auto), candy-core README:236, sugar-widgets/examples/item-list.php:20 word list (auto R2). <!-- @rename-keep -->
- Bare-`Kit` symbol hazard: IpmiPaintKit (candy-top test-support) contains substring `Kit` — matched by NO rule (all rules anchored: SugarCraft\Kit prefix / CandyKit / candy-kit). Zero `class Kit`/`as Kit` aliases tree-wide. <!-- @rename-keep -->
- `promptkit` upstream tokens (findings/erikgeiser_promptkit.md etc.) — no rule matches (no hyphen-Candy prefix, not Pascal `CandyKit`). Untouched by construction. <!-- @rename-keep -->
- Wiring: codecov.yml name/paths/component ×6 (auto R3), vhs.yml all= :146 + matrix :225 (auto), media/icons/candy-kit.png (path auto), ansi/: NO ansi/candy-kit dir — two mentions INSIDE ansi/sugarcraft/** wordmark art (.ansi content excluded from tool AND residue by extension law → stale bytes → followups). No research page, no docs/old refs. <!-- @rename-keep -->
- prompt_kit/tools/crush-dep-surface.php:30 `'candy-kit' => 'Kit',` — key auto-R3, VALUE bare 'Kit' is MANUAL sweep (C4 'Veil'→'Overlay', D1 'Mosaic'→'Image' precedent). <!-- @rename-keep -->

## Baselines (measured at branch base 55fb3bb93, linked, this worktree)

- candy-kit suite: **272T/1634A/0F/0E/0S/0W OK** (rename-baseline.md:42 EXACT — line tagged @rename-keep at tag-prep). <!-- @rename-keep -->
- candy-sql suite: **1580T/11900A OK** (entry-row figure EXACT).
- tools/tests: **426T/1484A OK** (via candy-kit/vendor/bin/phpunit). <!-- @rename-keep -->
- candy-code K=8 sharded CONSERVATION PASS at base: shards 22564T/**423771A**/0F/0E/2S vs pinned figure 22564/423652 (+0T/+119A tree-scan wobble, within conservation design). Conservation baseline for this lane = 22564T/423771A/2S.

## Self-lock law

NEVER @rename-keep any file whose PATH contains `candy-kit`: candy-kit/** (all), findings/{candy-kit,plan_candy-kit}.md, docs/repo_map/{sugarcraft_candy-kit,update_sugarcraft_candy-kit}.md, docs/_data/candy-kit.{json,body.html}, docs/lib/candy-kit.html, media/icons (binary). A sentinel anywhere skips the file's ENTIRE path plan (rename-lib keepsItsName law). Formerly notice goes into sugar-cli/README.md POST-move (path has no matching segment).

## Tag-prep set (history → @rename-keep per-line; living docs judged-REWRITE)

Tagged: prompt_kit/briefs/rename-baseline.md:42; prompt_kit/CONTEXT.md:944; prompt_kit/findings/crush-dep-surface.txt:149; docs/plans/crush_code_{RESUME:457, worklog:98/183/187/746/10295/13604, hardening_backlog:15110/15115/15123/15127/15133/15138/16042/16044, backlog_triage:509, concurrency:212}; findings/monorepo-audit-2026-05-27.md:54/60/67/566; findings/resume_prompt.md:82/83/109; php74_branch.md:381/505; steps/J1_COMPOSER_REPOS.md:33 (line already carries the precedent tag for sugar-dash sibling). <!-- @rename-keep -->
Judged-REWRITE (living): docs/plans/{UPSTREAM_OPPORTUNITIES.md ×3, AUDIT_UPSTREAM_PROMPT.md:158, README.md:41, plans_cleaning.md:67}, findings/{findings_resume_plan.md incl glued plan_candy-kit.md pointers ×6 → manual sweep, plan.md, candy-kit.md, plan_candy-kit.md}, docs/repo_map/** incl super-candy index rows, use_libs.txt, scripts/{set-org-default-master.sh, generate_icons.py, bootstrap-org-repos.sh}, .github/{ISSUE_TEMPLATE ×2, workflows/vhs.yml}, codecov.yml, root README.md, docs/MATCHUPS.md, PROJECT_NAMES.md (rationale rework in R4), all PHP/src/tests, ARCHITECTURE.md. <!-- @rename-keep -->
docs/old/crush_libs.md — EXCLUDED_PREFIXES by tool + residue (no tags needed).

## Steps

- R0 link+baselines: DONE (figures above; refresh-deps --mode=linked candy-kit,candy-sql,sugar-crush 3/3). <!-- @rename-keep -->
- tag-prep commit 4635ee4c0: 30 lines / 12 files tagged `@rename-keep historical provenance` (numstat pure 1x1 every file); NOTES skeleton 11 lines pre-tagged. <!-- @rename-keep -->
- R1 dry-run: 90 content files / 9 paths (dir move + _data x2 + lib html + img/icons png + media png + repo_map pair + findings pair); R1=101 R2=35 R3=310, R4/R5/R6=0 (compact/env/lang vocab absent — honest). Full eye-review passed; all 30 tagged lines confirmed ABSENT from plan. Log /tmp/opencode/d5-dryrun.txt. <!-- @rename-keep -->
- R2 remote: SKIPPED per brief law (weld/serial phase owns remotes; D1 precedent).
- R3 tool run EXACTLY ONCE: rc=0, counts == dry-run byte-exact; dir moved; php -l all 29 tracked lib PHP files rc=0 (first lint pass mis-scanned moved dir names from git status R-pair old paths — re-run on git-ls-files clean). <!-- @rename-keep -->
- R4 manual: (a) sugar-cli/README.md Formerly + Namespace-changed blockquotes (both tagged per plan verbatim shape); (b) glued `plan_candy-kit.md` x6 -> plan_sugar-cli.md in findings_resume_plan.md (living); (c) prompt_kit/tools/crush-dep-surface.php value `'sugar-cli' => 'Kit'` -> `'Cli'` (C4/D1 blindspot law); (d) PROJECT_NAMES row reworked residue-safe (no old-brand text); (e) composer keywords verified (fang+sugarcraft kept), description reads correctly; (f) MATCHUPS/index sections: type stays lib, no section move. <!-- @rename-keep -->
- R5 wiring: renames.json status pending->renamed (targeted sed, one line, keys verified via json.load); root `composer update sugarcraft/* --no-install` (lock 66x66: only the candy-kit->sugar-cli block + sugar-crush ambient ref + content-hash); refresh-deps linked 3/3 escaping=0 after pruning 2 dangling candy-kit vendor symlinks (candy-sql + sugar-crush; maxdepth-4 find law); gen-docs AFTER status flip -> redirect stub docs/lib/candy-kit.html minted (92 files, libs 40/apps 21), --check rc0; gen-ansi --check rc0. <!-- @rename-keep -->
- R6 gates (on committed tip 159cfea58, clean tree): residue `--old=candy-kit` 0 findings (pre- and post-stage); sugar-cli 272T/1634A OK EXACT; candy-sql 1580T/11900A OK EXACT; tools/tests 426T/1484A OK; **crush K=8 CONSERVATION PASS: 22564T/423738A/0F/0E/2S** vs base-run 22564T/423771A (T exact; both wobbles +86/+119 over pinned 423652 = tree-scan census family, within conservation design); check-path-repos --no-lib-path-repos rc0; check-child-lifetimes rc0. <!-- @rename-keep -->
- R7 commits: tag-prep 4635ee4c0 -> rename 159cfea58 (113 staged paths, author/committer detain 1/1, no trailer) -> gate-evidence (NOTES+followups+tracker row). Push rename/d5 ONLY. <!-- @rename-keep -->
- Residual owed by weld: R8/R9 remote+packagist, tracker row SHA re-anchor if rebased. <!-- @rename-keep -->
