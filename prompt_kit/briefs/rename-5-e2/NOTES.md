# rename-5-e2 — candy-metrics → sugar-metrics (batch E, renames.json:52)

Base: 6709f44bd. Worktree /home/sites/sugarcraft-e2, branch rename/e2. Never master; push rename/e2 only.

## Scope facts (recon)

- ns `SugarCraft\Metrics\` UNCHANGED (Metrics→Metrics). bins {} (none). composer name only + dirs.
- Zero external composer consumers: root composer.json (:58 require, :219 repo url) + lib self only.
- Zero code refs `SugarCraft\Metrics\` outside lib (sugar-dashboard `MetricsGrid` = generic word, NOT a rename token; sugar-crush has zero Metrics tests → the briefed crush `--filter Metrics` gate is vacuous, disclosed).
- Zero env reads (no CANDY_METRICS_*/CANDYMETRICS_*), zero `candy_metrics`, zero compact `candymetrics` → honest none: NO LegacyEnv/LegacyPaths entries; R6 `Lang::NAMESPACE='metrics'` stays.
- No ansi/candy-metrics/ wordmark dir. ansi/sugarcraft/*.ansi art CONTENT lists lib names — C.3-excluded (residue never counts .ansi) → followups note.
- Base suite (R0, re-measured in-lane + rename-baseline.md:46 agrees): 174T/464A/0F/0E/0S exit0.
- Pre-existence check: zero `sugar-metrics`/`SugarMetrics` tokens at base → clean.
- No check-child-lifetimes row; ci.yml dynamic (no refs).

## @rename-keep tag-prep (16 physical lines — historical provenance, must NOT rename)

Tag shape: md → ` <!-- @rename-keep historical provenance -->`; PHP docblock → ` // @rename-keep historical PR-title citation` (precedent candy-core/src/Util/LegacyPaths.php:14; updates.md family precedent from Batch D).

- prompt_kit/CONTEXT.md:877 — merged PR #1228 security-log entry.
- docs/plans/crush_code_backlog_triage.md:204 — E740 CLOSED row (round-83 history).
- docs/plans/crush_code_hardening_backlog.md:20834,20840,20842 — E738/E740 weld-era history (:20832 already tagged at D).
- findings/monorepo-audit-2026-05-27.md:80,88 — dated audit scoreboard.
- findings/resume_prompt.md:72 — dated phase-0 resume prompt.
- php74_branch.md:377,527 — dated PHP-7.4 branch survey.
- steps/J1_COMPOSER_REPOS.md:9 — dated step note.
- docs/plans/leftover/updates.md:496,501 — PR#736/738 changelog (D1 precedent: updates.md PR-log lines are tagged, not rewritten).
- sugar-crush/src/Renderer.php:164, sugar-crush/src/Tui/Renderer.php:1255, sugar-crush/docs/ARCHITECTURE.md:1242 — quote the MERGED PR #79 title "Phase 9+: CandyMetrics — telemetry primitives + CandyWish middleware"; rewriting would fabricate a PR title that never existed.

## Judged-rewrite (tool handles; no tags)

Root README.md:59, PROJECT_NAMES.md, docs/MATCHUPS.md ×2, docs/index.html, docs/js/search-index.json, docs/sitemap.xml, docs/_data pair, docs/lib page (becomes sugar-metrics.html; old path gets gen-docs redirect stub), docs/admin page, docs/repo_map* WHOLE family incl. update_sugarcraft_candy-{wish,git-server}.md + sugar-toast + charmbracelet_{catwalk,promwish} + pr_* + update_stage3.txt + updates/supervisor/step_37/followups (living per D1; upstream catwalk/promwish names survive — R3 needs the full slug), docs/research/{INDEX,SELECTION_PLAN,libraries/candy-metrics-research.md}, findings/{candy-metrics.md,plan_candy-metrics.md} (+path renames), codecov.yml, .github/workflows/vhs.yml, scripts/{generate_icons.py,set-org-default-master.sh,bootstrap-org-repos.sh}, use_libs.txt, root composer.json, candy-core/src/Util/AtomicJsonFile.php:9 (living provenance-list of pattern origins — D1 law: PHP/code rewrite), sugar-widgets/examples/item-list.php:21 (`CandyMetrics` in a name list, R2), docs/plans/leftover/phase-09-media-games-logs/step-13+14-metrics*.md (D1 precedent: phase-07 mosaic step files rewritten zero tags), docs/plans/{AUDIT_UPSTREAM_PROMPT.md,leftover_updates_later.md,UPSTREAM_OPPORTUNITIES.md}, docs/plans/leftover/_templates/docs.md:55, prompt_kit/briefs/rename-baseline.md:46 (campaign tracker row), findings/findings_resume_plan.md living lines, whole lib dir (paths + content, incl. temp-prefix literals in HistogramBucketsTest/PrometheusFileBackendTest — R3 auto).

## Manual glued sweeps (tool lookbehinds can't reach; content-only, paths auto-rename)

- findings/findings_resume_plan.md: glued `findings/plan_candy-metrics.md` (:260,270,278) + table `plan_candy-metrics.md` (:1285) — underscore blocks R3 → sed to plan_sugar-metrics.md.
- docs/repo_map/update_sugarcraft_candy-metrics.md: 8× refs to `docs/repo_map/sugarcraft_candy-metrics.md` (lines 193,211,227,245,277,295,311,381) — same glued-underscore class.

## Execution record

1. Tag-prep commit (this + tags).
2. `php tools/rename-lib.php --old=candy-metrics` (dry-run first).
3. `php -l` every changed PHP; glued sweeps.
4. sugar-metrics/README.md Formerly note (post-move, house shape).
5. tools/renames.json line 52 pending→renamed (targeted one-line sed).
6. `php tools/gen-docs.php` re-run (mints docs/lib/candy-metrics.html redirect stub — self-tagged by tool).
7. refresh-deps --mode=linked --libs=sugar-metrics; prune dangling old vendor symlinks.
8. Gates at committed tip: residue 0; suite 174/464; tools/tests 426T/1484A; path-repos/child-lifetimes/gen-docs --check/gen-ansi --check rc0.

## Deviations / disclosures

- crush `--filter Metrics` consumer gate: vacuous (zero matching tests in tree) — documented, not faked.
- rename-baseline.md row rewrite keeps campaign tracker truthful.
- Sibling lanes (e11 wish etc.) touch shared files (update_sugarcraft_candy-wish.md, repo_map_update_followups.md) on disjoint lines → weld merge resolves.
- ANSI wordmark art (`ansi/sugarcraft/*.ansi` content) + docs/old/** + root composer.lock left old-named by C.3 policy; wordmark absence noted for followups (none exists for this lib, so nothing owed).
