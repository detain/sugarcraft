# rename-5-e5 NOTES — candy-input → sugar-input <!-- @rename-keep -->

Lane: e5 (batch-E, tools/renames.json:48, ns Input→Input UNCHANGED, type=lib, bins {}). Branch `rename/e5` off `6709f44bd`, worktree `/home/sites/sugarcraft-e5`. NEVER push master; push branch rename/e5 only. No R8/R9, NO remote (brief law — weld/serial phase owns remotes).

## Entry facts (verified)

- renames.json line 48: kind=rename, candy-input → **sugar-input**, lib, ns_old==ns_new==`Input`, bins {}, no extra_literals/protect, status pending. Derived: pascal CandyInput→SugarInput, compact candyinput→sugarinput, env CANDY_INPUT_/CANDYINPUT_ (ZERO sites tree-wide → honest none, no LegacyEnv; libs have no bin anyway). <!-- @rename-keep -->
- R1 SKIPPED by tool (ns unchanged) — so `SugarCraft\Input\` (and every `InputDriver`/`EscapeDecoder`/`InputReader`/`ReactInputDriver` symbol + all FQNs) ride UNCHANGED. The generic-word risk is therefore ZERO in code.
- R2 `CandyInput`→`SugarInput`: 18 sites, all brand-name prose/metadata (PROJECT_NAMES, README, MATCHUPS, docs/_data json, index.html, lib page). NONE collide with sugar-forms `Input` family (which is `SugarCraft\Forms\Input` / `TextInput` — never `CandyInput`). <!-- @rename-keep -->
- R6 Lang domain: candy-input/src/Lang.php NAMESPACE='input' → stays 'input' (lowercase of unchanged ns). lang/en.php keys bare. Tool reports R6:1 (self-match on NAMESPACE line); net effect verified no-op. <!-- @rename-keep -->
- R3 slug: 228 sites. Consumer manifests requiring sugarcraft/candy-input: **candy-pty, sugar-readline** (+ root composer.json). Docblock/convention refs (living, rewrite): candy-pty/CALIBER+README+src/Posix/ReactPump.php, sugar-readline/README+src/Readline.php+examples/interactive.php, sugar-mouse/src/MouseAction.php, candy-vcr/tests/CoreEmitterRoundTripTest.php, use_libs.txt, steps/J1_COMPOSER_REPOS.md. <!-- @rename-keep -->
- CI/wiring: candy-input is NOT in vhs.yml matrix, NOT in affected-libs WINDOWS_LIBS/MACOS_LIBS, NO child-lifetimes roster row, NO .vhs/ (non-visual). Only codecov.yml flag/component refs (R3-auto). Confirmed via grep. <!-- @rename-keep -->
- Pre-existence census clean: no `sugar-input` dir/repo/page, no `SugarInput` class, `ls -d sugar-input` absent. Only refs are plan_rename.md + renames.json (this campaign's own defs) and charmbracelet_huh.md repo_map (living plan naming the future slug) — all correct.
- Baseline R0 (RE-MEASURED at base 6709f44bd, linked, plain-pipe -u LINES -u COLUMNS): candy-input **409T/41381A/0F/0E/1S exit0** EXACT vs rename-baseline.md:41. Paste/Escape-proc paths deterministic (no timer/loop hazards in this lib's suite — no tests/bootstrap.php, no Loop::get()). <!-- @rename-keep -->

## Self-lock law (never tag path-move targets)

candy-input/** (all files — README/CALIBER/phpunit.xml/composer.json/src/tests — need content rewrite after the dir move), findings/candy-input.md, findings/plan_candy-input.md, docs/_data/candy-input.{json,body.html}, docs/lib/candy-input.html, media+docs/img icons (binary anyway). README Formerly notice added POST-move. <!-- @rename-keep -->

## Tag-prep set (history → @rename-keep per-line; established-file precedent)

- prompt_kit/CONTEXT.md:880 (PR #1231/#1238/#1368 dated narratives; sibling lines 883/887/990/1009-1358 already tagged by prior lanes)
- prompt_kit/briefs/rename-baseline.md:41 (baseline row under old name; D1 tagged :49 same file)
- docs/plans/crush_code_backlog_triage.md:179; crush_code_hardening_backlog.md:20657/20663/20669 (20665 already tagged); crush_code_worklog.md:6277 (11818 already tagged) — r82/E715 ledger CLOSED records
- php74_branch.md:359/481/541 — frozen php7.4-compat planning snapshot (its candy-shine/candy-flip/honey-bounce lines already `@rename-keep` from D/A lanes — file precedent confirmed)
- findings/resume_prompt.md:111/112 (16/84/129 already tagged) — dated assignment ledger; D1 tagged resume_prompt wholesale
- JUDGED-REWRITE (living, tool handles): findings/{candy-input,plan_candy-input,plan,findings_resume_plan,plan_candy-pty,plan_sugar-cli,plan_sugar-crush,plan_sugar-mouse,plan_sugar-readline,sugar-cli,sugar-dashboard,sugar-mouse}.md, docs/repo_map_step_*.md + supervisor/update*, docs/research/ansi-tmux-ansicode-audit.md, prompt_kit/findings/btop-upstream-prs.md (forward-looking PR guidance), all PHP/code, codecov.yml, README/MATCHUPS/index.html/PROJECT_NAMES, use_libs.txt, steps/J1. <!-- @rename-keep -->

## Steps

- R0 done (base figure above, EXACT).
- R1 dry-run read: 61 content files / 7 path renames; R2=18 R3=228 R6=1; ns unchanged so R1=0 (expected, NOT a stop).
- R2 remote rename: SKIPPED per brief law (no remote ops in lanes).
- R3 tool run.
- R4 manual sweeps (expected blindspots): (a) GLUED `findings/plan_candy-input.md` pointers ×7 (findings_resume_plan ×6 + findings/plan.md ×1) — R3 lookbehind `(?<![A-Za-z0-9_])` rejects the `_` prefix → invisible to tool → manual sed to `plan_sugar-input.md`; (b) Cap-Hyphen `Candy-Input` — grep proved ZERO sites, honest none; (c) `.vhs` post-\n — none (no tapes); (d) `as *Input*` alias — only forms `TextInput as TextInputWidget` (NOT mine, forms family, leave); (e) suffixed repo_map/research filenames — none exist for candy-input. Bare `Input` class symbols never touched (R1 skipped). <!-- @rename-keep -->
- R5 wiring: codecov.yml ×6 auto; gen-docs.php after renames.json status flip (mint redirect stub docs/lib/candy-input.html); refresh-deps --mode=linked sugar-input,candy-pty,sugar-readline (+ root); prune dangling candy-input vendor symlinks. <!-- @rename-keep -->
- R6 gates on committed tip: residue --old=candy-input 0; sugar-input suite EXACT vs R0 409/41381/1S; consumers candy-pty + sugar-readline suites (compare to their base re-measure); crush `--filter 'Input|Paste|Key'`; tools/tests 426/1484; path-repos/child-lifetimes/gen-docs --check/gen-ansi --check rc0. <!-- @rename-keep -->
- R7 commits: tag-prep → rename → this NOTES. Author law detain/interserver.net split-var, no Co-Authored-By.
