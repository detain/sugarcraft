# Batch-E WELD REPORT (2026-10-16, sole-writer master, pushed 0d5330d69)

## Chain (all --no-ff, author-law A/C detain@interserver.net verified per commit, no Co-Authored)
6709f44bd → e1 17830f8a3 → e2 91a8a343f → e3 b4820a441 → e4 3f159152e → e5 eecab40b7 → e6 9fca48a9b → e7 26e9c95fd → e8 7a54ae5e8 → e9 39f80cbf1 → e10 2141d0c29 → e11 23e63b408 → e12 b560053ef → lock d86179d1f → heal c61d075cc → alias-repair 7644209f3 → tracker 0d5330d69.

## Per-merge conflicts/resolutions (weld_fuse.py per-line union; rule: theirs-if-@rename-keep-tagged, else mine-if-tagged, else theirs + cumulative prior-lane namespace rewrites e1..e(N-1))
- e1 log / e2 metrics / e3 testing / e5 input: token-level fusions (e2: 21 files); e4 async: 0-conflict after amendment (first attempt crashed mid-fuse; commit amended clean).
- e6 ansi-parser: candy-ansi→sugar-ansi-parser ns Ansi→AnsiParser; brand-protect candy-ansi-inspect everywhere (prefix guard + brand_protect key landed from tool-lever additions in tools/rename-lib.php + tools/check-rename-residue.php — KEPT per brief); docs/lib/candy-ansi.html removed, redirect stub for new slug present.
- e7 vterm: 71 conflicts (incl. `:path`-suffixed conflict-marker labels on renamed files — PAT tolerant); PROJECT_NAMES.md fused SugarAnsiParser+SugarVterm both lines (playbook ~:135).
- e8 cellbuffer 41, e9 constraints 35: clean fusions.
- e10 style: 66 conflicts; weld_fuse PATCHED with moved-stub rule (docs/lib/*.html len-mismatch: side containing `Moved:` wins; stub rule per gen-docs stub_html() @:391).
- e11 ssh-server: 37 conflicts clean; e2+e11 shared repo_map/findings candy-metrics lines fused via prior-rewrite mechanism.
- e12 pty: 76 conflicts (74 fused); 2 manual — composer.lock took-OURS (regenerated in d86179d1f); prompt_kit/briefs/rename-followups.md UNION via marker deletion (only e3 unheaded wordmark bullet + e7 + e12 sections exist — matches lane NOTES claims; other lanes judged none needed). e12 CI rosters verified: pty-matrix.yml 13×sugar-pty, ci.yml 7, affected-libs.php 2, check-child-lifetimes.php 3; CANDY_PTY_*→SUGAR_PTY_* + LegacyEnv fallbacks landed.
- renames.json: each lane flipped own line(s) pending→renamed; 12 E lib entries all `renamed` (keymap rows per-lane too); batch-F 2 rows pending untouched; D/C packagist-done untouched; JSON valid (JSON_THROW_ON_ERROR).
- Root composer.json: 12 new in require+repositories, zero old names; input/constraints repo-only (base-true: transitive deps).

## Gate-0 cleanup
Husks removed (0 tracked each, verified): candy-log candy-metrics candy-testing candy-async candy-input candy-ansi candy-vt candy-buffer candy-layout candy-sprinkles candy-wish candy-pty; candy-ansi-inspect untouched. 331 dangling old-name vendor/sugarcraft symlinks pruned tree-wide (incl. crush 7, ansi-inspect 3 → those relinked+dump-autoload to 4/4 escaping=0). refresh-deps --mode=linked full-tree: OK, guard rc=0, tracked per-lib locks 0.

## Gate-1 residue (heal c61d075cc + 7644209f3)
--all-done 90 findings → 0 in one pass: 32 live-code rewrites (Buffer→CellBuffer, Layout→Constraints, Wish→SshServer, Vt→Vterm, Ansi→AnsiParser use-imports/docblocks in candy-tetris, candy-sql, sugar-charts×2, sugar-panels, sugar-crush ComponentTest, sugar-style src+tests, sugar-constraints Region, sugar-ssh-server BubbleTea, SKILL.md, 2 README/LEARNINGS lines); 41 historical @rename-keep tags (repo_map*, findings*, docs/dev, phase-03, NOTES e2/e4/e7/e8/e11/e12, R8R9:20/:66); 7 stale docs/lib/*.html regenerated (sources already clean — gen-docs rc=0 --check green). weld_fuse mispair cures: RegionBridgeTest.php:10 + SugarPanels.php:14 `as LayoutRegion`→`as ConstraintsRegion` (lane-intent alias, bodies use new symbol). --all-done==0 final; --old per-entry mode = default scope (0 findings covers all 12).

## Gate-2 suites (cwd=lib, env -u LINES -u COLUMNS phpunit </dev/null)
EXACT: log 190/372 · metrics 174/464 · testing 267/5416/3S · async 117/259 · input 409/41381/1S · ansi-parser 360/901 · vterm 976/12873 · cellbuffer 315T/1488A · constraints 293/3315 · style 865/2896 (post alias repair) · ssh-server 260/976 · pty 708/3100/17S. PROTECT: candy-ansi-inspect 278/1412 EXACT. Regression (heal-touched): tetris 214/4322 · sql 1580/11900 · charts 654/1551 · panels 201/456 all OK.

## Gate-3 crush K=8
scripts/parallel-tests.sh --durations scripts/parallel-tests-durations.tsv (repo root): 8/8 shards rc ok; T=22,564 EXACT (+0 vs figure), F0 E0 S2 match; A=423,770 vs pinned 423,652 = +118 wobble — disclosed (same provider-random family as D-weld's +64); figure re-pin deferred to serial R8/R9 pass per brief. No ChatTest flake hit.

## Gate-4 tools/tests
429T/1517A EXACT (floor raised 426/1484→429/1517 by e6 pins — matches expectation).

## Gate-5 policy rc0
check-path-repos --no-lib-path-repos 0 · check-child-lifetimes 0 (60 libs, 28 sites, 8 findings/8 accounted incl. flipped sugar-pty rows) · gen-docs --check 0 · gen-ansi-readme --check 0.

## Push + anomalies
origin 6709f44bd ancestor-verified → pushed plain; rev-list 0/0 (tracker commit rides final push). No oc.7z staging; no --force; candy-core untouched (e13 solo next). Anomalies: (1) weld_fuse alias-mispair class (import line from one side + body line from other) — only manifestation found+repaired; suites exercising all 4 files green. (2) +118A wobble largest recorded but invariant set (T/F/E/S) exact. (3) sugar-ansi-parser + sugar-constraints have vendor/ but no vendor/sugarcraft — benign (zero sibling requires in their composer.json).
