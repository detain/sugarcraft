# Rename Phase-4 Batch-B Entry 3: candy-serve → candy-git-server — execution NOTES <!-- @rename-keep ledger -->
Base: 4bb435f3e (master==origin). Foreign oc.7z moved to /tmp/oc.7z.b3-hold (preflight demands clean tree; RESTORE at close).

- R0 PREP: recon complete (see plan/notes reads + tool mechanics in session record). Entry = tools/renames.json line 10. PROTECT collision check: no 'candy-serve-*' slugs exist → protect empty; candy-git is a DIFFERENT literal — R3's `candy-serve` token cannot match `candy-git`. <!-- @rename-keep ledger -->

## R1 dry-run (post tag-prep 1a6ce4bbe + notes commit)
- Plan: 122 content files, 13 path renames (R1=186 R2=126 R3=432 R5=42 R6=1 R7=45). exit=0.
- COLLISION CHECK PASS: zero `candy-git/` paths in mv list; `candy-git-server` token cannot match sibling `candy-git` (distinct literal). The feared prefix collision is reverse-direction (a future candy-git-* rename must protect candy-git-server) — minted for closeout.
- Tag-prep delta confirmed: prompt_kit/CONTEXT.md, rename-baseline.md, rename-4-B1/NOTES.md dropped from rewrite set after @rename-keep; this NOTES.md's own historical lines tagged too.
- bin/candy-git-server → bin/candy-git-server path rename present. Bare `soft-serve serve/init/...` usage docblock NOT rewritten by any rule (no bin/ prefix) → R4 manual prose fix (keep one "drop-in for charmbracelet/soft-serve" historical mention). <!-- @rename-keep ledger -->
- tests/ServeTest.php keeps filename (tool renames dirs/known paths only; "ServeTest" matches no residue rule — confirm post-R3).
- docs/repo_map/pr_charmbracelet_soft-serve.md: 3 content mentions rewritten, filename stays (upstream record). charmbracelet_soft-serve*.md path set untouched by rules — correct.
- Consumers: root composer.json only (R3:2 require+repositories). No other lib requires sugarcraft/candy-git-server.

## R2 remote (scripts/rename-remote.sh)
- sugarcraft/candy-serve → sugarcraft/candy-git-server renamed; verified record JSON printed; ls-remote master=3c2bdc881 (pre-sync split tip — legacy refs mint at CI-sync); old URL still redirects. <!-- @rename-keep ledger -->

## R3 tool run + R4 followups (manual pass)
- exit 0: 122 files, 13 paths (same shape as dry-run). Zero candy-git/ paths touched (collision gate PASS).
- bin usage block rewritten to candy-git-server; one historical `soft-serve` mention kept TAGGED (residue [BIN] rule caught it in step). <!-- @rename-keep ledger -->
- LegacyEnv shim added in bin (A2 house shape): CANDY_SERVE_ → CANDY_GIT_SERVER_ with per-var stderr notice, tagged. Data dir default is sys_get_temp_dir() — EPHEMERAL, NO user-data migration needed (honest none; no ~/.candy-serve store exists). <!-- @rename-keep ledger -->
- 16 lang files: user-facing command strings `soft-serve ...` → `candy-git-server ...` (sed whole-file; zero charmbracelet refs inside lang so no over-replace). ssh/soft_serve_host KEY FILENAMES kept verbatim (upstream-compatible on-disk layout; underscore spelling matches no rule). <!-- @rename-keep ledger -->
- Wire-visible brand changed mechanically: header X-CandyServe-User(-Sig) → X-CandyGitServer-User(-Sig), Server: CandyServe/1.0 → CandyGitServer/1.0, config default name likewise (consistent across src+tests; suite green proves it). No header-fallback code — rename scope only. <!-- @rename-keep ledger -->
- CANDY_SERVE_INITIAL_ADMIN_KEYS is doc-only (never read by code) — lang/README refs auto-rewrote to CANDY_GIT_SERVER_INITIAL_ADMIN_KEYS; pre-existing doc/code drift NOT chased. <!-- @rename-keep ledger -->
- A2 underscore-blindspot sweep: 26 glued refs fixed in docs/repo_map/update_sugarcraft_*.md + findings/findings_resume_plan.md (_candy-serve → _candy-git-server).
- tests/ServeTest.php → tests/GitServerTest.php (+class) — bare "ServeTest" matches no rule; renamed for truth.
- PROJECT_NAMES rationale rewritten honest (was `candy + serve`); composer description de-framed from "PHP port of" to "modeled on the charmbracelet/soft-serve Go project" + keyword soft-serve added (residue-safe); root README/docs tiles/MATCHUPS/wish cross-link auto-rewrote; upstream repo_map records (charmbracelet_soft-serve*, scoop/winget bucket rows) tagged historical.
- Formerly/namespace/env-compat README blockquote added (B2 shape, tagged).
- docs/plans + findings ledgers mechanically rewritten (living surfaces, campaign precedent); rename-followups.md: banner art + pre-existing broken .vhs/config.gif tile ref + binary icon note.

## R5 wiring
- Root composer require+repositories rewrote by tool; composer update sugarcraft/candy-git-server --no-install → lock clean. Only consumer = root (grep empty). refresh-deps --mode=linked --libs=candy-git-server OK. gen-docs rc=0 (68), gen-docs --check rc=0, gen-ansi-readme --check rc=0 (3595 pieces agree).

## R6 gates
- residue --old=candy-serve: 7 [BIN] soft-serve findings first pass → 4 fixed / 3 tagged (upstream bucket records + bin history + ledger) → 0 findings. <!-- @rename-keep ledger -->
- candy-git-server suite 354T/875A/0F/0E/0S/0W rc=0 EXACT vs baseline (8.9s, </dev/null).
- candy-git untouched: git diff --cached name-only -M | grep -c '^candy-git/' == 0.
- tools/tests 426T/1484A rc=0 EXACT floor. path-repos --no-lib-path-repos rc=0 (61 libs).
- diff --stat: 129 files +1042/−1024 staged pre-commit (+renames.json flip +stub +followups after).

## R9 packagist + close
- Pre-sync create 406 (packagist derived old name from stale split composer.json at 3c2bdc881); forced update old 202 did not flip it while sync lagged. After CI-sync: split master=24effeca8 (legacy/candy-serve + candy-serve-final @3c2bdc881 minted — law holds on fast-forward syncs too). create 202 clean; forced update new (job a6f1fd91); dev-master reference == split master EXACT. <!-- @rename-keep -->
- published 0dec50424 (status=packagist-done + abandon line). tracker 0f4f18542 pushed. oc.7z restored. ahead/behind 0/0. B3 COMPLETE.
- LAW ADD (for candy-git-adjacent futures): create MUST follow CI-sync — packagist's name check reads the SPLIT repo, not GitHub's rename. Retry-after-sync beats any 406 cure dance.
