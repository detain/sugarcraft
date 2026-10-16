# Rename lane C5 — candy-hermit → sugar-quickpick <!-- @rename-keep -->

Entry: tools/renames.json batch-C line 25. ns Hermit→QuickPick, type **lib**, bins {} — composer.json VERIFIED no `bin`. Branch `rename/c5` @ 4d472bc62, worktree /home/sites/sugarcraft-c5. Baseline (rename-baseline.md:40): **159T/601A/0F/0E/0S rc0**. <!-- @rename-keep -->

## Vocabulary + rulings
- R1 FQN `SugarCraft\Hermit` → `SugarCraft\QuickPick`; R2 brand `CandyHermit` → **SugarQuickpick** (lowercase-p — A3 brand/FQN asymmetry precedent); R3 slug; R4 compact (1 hit = this NOTES file's own regex quote — benign). <!-- @rename-keep -->
- Env: ZERO getenv/`getenv` reads in lib → NO LegacyEnv (B12 honest-none precedent). No src/Lang.php → R6 lang sweep N/A. No bins.
- Collision gate: repo-wide `QuickPick` census = plan + renames.json only → **no collision**, proceed.
- TYPE flip candy-→sugar-: lib both before/after → MATCHUPS row :68 (Libraries) + :162 table row already under correct sections; root README single table; **no row moves needed**. Upstream cite `theHermit` untouched by every rule (lookbehind).

## Lane-manual executed (first bare-ns_old-class case)
Tool leaves bare class `Hermit` (only namespace segment in R1). Manual set:
- sed `(?<![A-Za-z0-9_])Hermit(?![a-z0-9])` → `QuickPick` confined to sugar-quickpick/ + docs/_data body: 187 hits/18 files (class, `Hermit::new`, docblocks, error strings, test classes + helper makeQuickPick). `theHermit` upstream cites + `hermit_history.jsonl` default path + lowercase vars stay (B10/B7 honesty precedent).
- git mv ×5: src/QuickPick.php + tests/QuickPickTest/QuickPickRankerTest/QuickPickSigwinchTest/QuickPickFilterUtf8Test.php.
- README: formerly-notice line (B10/B12 shape, tagged) + architecture-tree realignment.
- PROJECT_NAMES.md: weak-table `**SugarQuickpick**…"Hermit" is an inherited name` row DELETED (resolved); Strong-table row added after SugarDatepicker (was-CandyHermit rationale, tagged); `(SugarQuickpick alternative)` rode R2. <!-- @rename-keep -->
- Glued cites fixed: findings_resume_plan.md ×8 + findings/plan.md ×1 `plan_candy-hermit.md`→`plan_sugar-quickpick.md`; repo_map update_ cite ×1; research doc `Candy-Hermit`→`Sugar-Quickpick` ×2. <!-- @rename-keep -->
- Historical bare-`Hermit` prose in findings//repo_map/research/leftover docs NOT sedded (upstream "The Hermit is a Go library" corruption risk; bare Hermit is not a residue token — same-algebra gate confirmed). <!-- @rename-keep -->
- Tool path-moves (verified in plan): lib dir, docs/_data ×2, docs/img+media icons, docs/lib page, repo_map ×2, research, findings ×2 (incl plan_ glued file).

## Consumers
Zero libs require the package. Root composer require+repositories (R3:2) — R5 pending. Cross-lib comment rewrites: sugar-crush Renderer.php/RendererTest, candy-core AtomicJsonFile, candy-ssh-launcher Picker, candy-sprinkles Bar/×2, candy-ansi README+learnings, fuzzy docs, root README, MATCHUPS, index.html, search-index.json, sitemap.xml, scripts/generate_icons.py, use_libs.txt, php74_branch.md, AUDIT_UPSTREAM_PROMPT, leftover steps, repo_map step/supervisor files — targeted gates in R6. <!-- @rename-keep -->

## Progress log
- [x] R0 tag-prep 1a48afb8b (CONTEXT ×2, baseline ×1, NOTES opened).
- [x] R1 dry-run: 79 files / 10 paths / R1=85 R2=21 R3=216 R4=1 — reviewed, no surprises beyond NOTES self-hit.
- [x] R2 rename-remote.sh rc0 — sugarcraft/sugar-quickpick verified record (description auto-refreshed from _data — still carries theHermit cite, honest).
- [x] R3 tool --apply rc0 (weak PROJECT_NAMES row pre-deleted pre-apply… actually tool rewrote it, manual delete post-apply as planned).
- [x] R4 manual set complete (sed 187, git mv 5, README/PROJECT_NAMES/glued cites/research cap-hyphen).
- [ ] R5 root composer update + refresh-deps linked + gen-docs/--check + gen-ansi --check.
- [ ] R6 residue 0 + suites + tools/tests 426/1484 + path-repos/child-lifetimes.
- [ ] R7 renames.json status=renamed + commit + push rename/c5.

## R6/R7 gates (CLOSED)
- residue --old=candy-hermit: 0 findings (NOTES:6 tag added in-lane).
- lib suite EXACT vs baseline: 159T/601A/0S under PTY (`script -qec`). Pipe shape legitimately skips QuickPickSigwinchTest::testTheHandlerBody...StdinIsATerminal (needs TTY stdin, TIOCSWINSZ) → 159T/597A/1S. BASELINE WAS PTY-RUN; deviation is harness shape, not rename. Future re-runs of this lane: use PTY for exactness.
- consumers: ZERO libs require the package. Comment-touch gates: sugar-crush --filter Renderer 1130T/151,512A rc0; candy-core --filter AtomicJsonFile 29T/79A; candy-sprinkles --filter Bar 68T/126A; candy-ssh-launcher --filter Picker 63T/195A. all rc0.
- tools/tests 426T/1484A exact floor; check-path-repos rc0; check-child-lifetimes rc0 (60 libs, 8/8 accounted); gen-docs 79 files + --check rc0; gen-ansi-readme --check rc0 (untouched).
- R7: renames.json:25 status pending→renamed (compact sed verified); gen-docs minted docs/lib/candy-hermit.html moved-stub (self @rename-keep). icon rotation verified net-state: 3 new paths identical content, all old paths gone; candy-hermit/ dir gone. diff symmetry 629+/629- (pure rename) + renames.json/NOTES.
- Deviations vs template: (1) bare-class Hermit→QuickPick rename was LANE-MANUAL (tool only knows qualified/brand/slug/compact forms) — src/Hermit.php→src/QuickPick.php + 4 test files git mv'd + case-sensitive sed 187 hits/18 files confined to lib + docs/_data body. (2) R2 brand = SugarQuickpick (lowercase p) per slug-derived rule + A3-approved asymmetry. (3) no env shim (lib reads zero env vars), no Lang (no lang files). (4) historical docs (findings/, repo_map/, research/, leftover steps) content rode the TOOL's R3 rewrite (standard, all prior lanes); their filenames keep bare-hermit words — not residue tokens.
