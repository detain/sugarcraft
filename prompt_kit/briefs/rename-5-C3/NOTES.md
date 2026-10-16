# C3 — sugar-crumbs → sugar-navstack (batch C, ns Crumbs→NavStack, type lib, bins {})

Base 4d472bc62 · branch rename/c3 · worktree /home/sites/sugarcraft-c3 · NEVER push master; push origin rename/c3 only.

## Procedure log
- **Tag-prep** @ebdd90b9a — `prompt_kit/briefs/rename-baseline.md:71` baseline row tagged `@rename-keep` (the dry-run's 1 R3 hit there; 64→63 files).
- **R1 dry-run** — 63 files / 7 path renames (R1=35 R2=20 R3=240 R6=1). Change-set reviewed: crush-internals discrimination PASS (no sugar-crush file touched — its "crumbs" is an unrelated agent-view breadcrumb subsystem; none of its tokens match R1–R4 patterns). Zero consumers of the lib besides root composer.
- **R2** — `scripts/rename-remote.sh sugar-crumbs sugar-navstack` rc=0; verified record name=sugar-navstack (description via renames.json fallback — no bootstrap-org-repos edit needed).
- **R3** — `tools/rename-lib.php --old=sugar-crumbs` rc=0. php -l sweep 19/19 clean (for-loop + `timeout 10` per file — xargs -0 form hung on an implicit stdin read, harness-only note).
- **Glued-path blind spots (lane-manual git mv)**: `findings/plan_sugar-crumbs.md`→`plan_sugar-navstack.md`; `docs/repo_map/sugarcraft_sugar-crumbs.md`→`sugarcraft_sugar-navstack.md`; `docs/repo_map/update_sugarcraft_sugar-crumbs.md`→`update_sugarcraft_sugar-navstack.md`; **plus `findings/sugar-crumbs.md`→`sugar-navstack.md` — the tool did NOT path-rename findings/ (only content); the recon assumption "auto-renames" was FALSE**. Cites self-healed by R3 except a historical tagged B8 NOTES line (left).
- **Bare-`crumbs` leftover step files (judgment call)**: `docs/plans/leftover/phase-04-ssot-others/step-03-crumbs-uses-zone.md`, `phase-10-apps/step-21-crumbs-pushdir-view-filter.md`, `step-22-crumbs-closable-url-semantic.md` renamed to `navstack-` + their 3 `leftover/README.md` cites + internal planned-branch names `ai/crumbs-*`→`ai/navstack-*` (verified via `git ls-remote` that no such branches exist — names were plan-internal). Not residue-flagged (bare word), renamed for disambiguation since the project's OTHER "crumbs" vocabulary (sugar-crush agent trail) survives — bare crumbs is now ambiguous repo-wide.
- **Capital-hyphen**: `findings/sugar-navstack.md:1` H1 `# Sugar-Crumbs ...`→`# Sugar-Navstack ...` manually.
- **PROJECT_NAMES.md:133** rationale rewritten truthfully + `@rename-keep` (keeps "renamed from SugarCrumbs" history): `nav stack = push/pop navigation stack + breadcrumb trail IS the function. delightful.`
- **R4.1** formerly-notice + namespace-changed lines under README H1 (B8 format, each physical old-token line tagged). **R4.2** added `"bubbleo"` keyword (upstream name — AGENTS checklist; original keywords never had it). **R4.3/4.4/4.5 N/A honestly**: lib owns NO env vars (zero getenv) and NO data paths.
- **R5** root composer require(:82)+repositories(:387) auto-rewrote; `composer update sugarcraft/sugar-navstack --no-install` → lock ops: -sugar-crumbs +sugar-navstack, 0 old-name in lock; `refresh-deps --mode=linked --libs=sugar-navstack` rc=0; `gen-docs` + `--check` rc=0; `gen-ansi-readme --check` rc=0.
- **renames.json flip** line 23 → `"status":"renamed"` via python per-line assert (1/1 numstat, JSON revalidated). Flip makes gen-docs mint `docs/lib/sugar-crumbs.html` redirect stub (18 stubs now; generator itself bakes `@rename-keep` into stub lines — B10 "tagging" need not be manual).
- **Follow-ups** appended to `prompt_kit/briefs/rename-followups.md`: 3 `ansi/sugarcraft/*.ansi` logo-wall tiles + `media/icons/sugar-navstack.png` unchanged-bytes note.

## Judged non-issues
- `scripts/__pycache__/generate_icons.cpython-313.pyc` contains stale `sugar-crumbs` bytes — PRE-EXISTING tracked pycache (last touched by B11 merge 9a1b5ae8d); residue tool skips binaries; not touched (owner hygiene).
- `plan_rename.md` 6 untagged old-slug mentions — plan's own status list, orchestrator-owned, residue-excluded.
- Packagist badge vendor `sugarcore/` in lib README — pre-existing cross-lib badge convention (sugar-readline/sugar-stickers same); only the slug part rewrote. R9 (packagist create) NOT this lane.
- Root `composer validate` version-field warning — pre-existing at base. `sugar-navstack/composer.lock` stale — gitignored local artifact.
- Bare `crumb(s)` domain vocab survives inside the lib (Breadcrumb zone ids `crumb-N`, prose), sugar-dash Breadcrumb components, candy-core ScreenStack, and sugar-crush's unrelated crumbs subsystem — none are brand tokens.

## Gates
- Residue `--old=sugar-crumbs`: **0 findings** (pre-stub and post-stub).
- Lib suite: **144T/260A OK** = baseline EXACT, rc=0 (cwd=sugar-navstack, plain pipe, -u LINES -u COLUMNS, timeout 600).
- tools/tests: **426T/1484A OK** = floor EXACT.
- check-path-repos `--no-lib-path-repos` rc=0; check-child-lifetimes rc=0; gen-docs --check + gen-ansi --check rc=0.
- Consumers: NONE (no lib requires sugar-crumbs/navstack; sugar-crush clean — `git status` shows zero sugar-crush/ files changed).

## Deviations
1. findings/<slug>.md path rename added (recon assumption wrong — see blind-spot list).
2. Leftover bare-`crumbs` step filenames renamed (judgment; disambiguation from crush crumbs vocab).
3. `bubbleo` keyword added (R4.2 checklist; pre-existing omission).
