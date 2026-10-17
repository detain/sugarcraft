# rename-5-e7 NOTES — candy-vt → sugar-vterm, ns `SugarCraft\Vt` → `SugarCraft\Vterm` <!-- @rename-keep lane e7 record -->

<!-- @rename-keep historical provenance -->
Base `6709f44bd` → commits: `d166f40fa` (tag-prep) → `18e39ef53` (rename, TIP). Branch `rename/e7`, never master, pushed once.

## Entry facts
- Widest blast radius of batch-E: `SugarCraft\Vt\Terminal` is the cell-grid oracle. TRUE ns consumers (grep `SugarCraft\Vt`): candy-vcr (36 files, the "116 refs" era figure), candy-ansi (3), sugar-dashboard (2), candy-pty (1), candy-git-server (1), candy-ansi-inspect (1), prompt_kit (1). charts/animation/image do NOT reference the ns (brief era-label; run anyway as transitive safety — all exact). sugar-crush: comment-only refs (3 files, prose rewrite). <!-- @rename-keep lane e7 record -->
- Brief headline "Vt→Vterm" matches renames.json:47 `ns_old:Vt ns_new:Vterm`. No `use SugarCraft\Vt;` single-segment imports found beyond path-forms; no CANDY_VT* env (no LegacyEnv); no src/Lang.php (R6 n/a); no bins; no data paths → honest none. <!-- @rename-keep lane e7 record -->

## Self-lock law
Own NOTES file tracked → every old-token line in it carries @rename-keep from birth. Tag-prep (commit d166f40fa) tagged 80 must-not-rename lines / 16 files first: crush_code_* ledgers (RESUME 1, backlog_triage 3, concurrency 1, hardening_backlog 11, vt_unification_design 9, worklog 4), session_handoff 2, twilight 10, ansi-tmux-ansicode-audit 24, improvements-2026-05 1, monorepo-audit-2026-05-27 2, php74_branch 3, CONTEXT.md 5, P4.S3-step-brief 2, rename-baseline 1 (the vt suite-figure row :62), scripts/refresh-deps.php 1 (:319 comment). rename-5-D1/NOTES.md was already 100% pre-tagged (D1 law worked — zero edits needed on an earlier lane's NOTES).

## Steps
1. Tag-prep → commit `d166f40fa`.
2. `php tools/rename-lib.php --old=candy-vt --dry-run` = 286 content files, 12 path renames, R1=694 R2=34 R3=740 (non-zero plan). Ran for real; rc=0. Path renames incl. `candy-vt/→sugar-vterm/`, findings/plan_, docs/repo_map/sugarcraft_*, docs/research/libraries/*, docs/plans/candy-vt-graphics.md, step-03 leftover, docs/_data, media+docs icons. <!-- @rename-keep lane e7 record -->
3. Manual glued sweeps (tool misses): `sugarcraft_candy-vt.md` ×22 content pointers (update_sugarcraft_sugar-vterm.md self-file, cross refs from sugarcraft_+update_sugarcraft_candy-ansi-inspect.md) → `sugarcraft_sugar-vterm.md`; `plan_candy-vt.md` in findings/plan.md → `plan_sugar-vterm.md`. **BLINDSPOT (NEW, mine):** first sed pass was unguarded and clobbered tagged historical line hardening_backlog:20803 (contained glued `plan_candy-vt.md:616`) → restored from HEAD via python (diff-vs-HEAD line-walk, '@rename-keep' filter); net-zero verified. LAW: never blind-sed a file that contains tagged lines — restore-tagged-lines pass after every manual sweep.
4. `Candy-Vt` Cap-Hyphen: zero hits. `.vhs` post-\n tokens: `candy-vt/.vhs` paths inside .tape comments rewritten by R3; verified clean. Bare-Vt identifiers kept per D1 Mosaic*Test precedent: candy-vcr `VtParityTest` class + `vtCarriesMarker` + DIVERGENCE_NOTE + `Vt\` shorthand (outside residue vocabulary, historical names). <!-- @rename-keep lane e7 record -->
5. Golden-tape ruling (Phase-7 ask): **content-freeze SAFE** — disk evidence: zero old tokens in candy-vcr/tests/golden/tapes/*.tape (MANIFEST.md too), tests/fixtures/renderer-frame.golden, sugar-vterm/tests/fixtures/*.ansi+*.golden, vcr src/bin/examples. The tapes goldens record TTY byte streams + class-name-free JSON; no ns strings embed. Gate = vcr full suite re-run (exact). Cosmetic residue routed to rename-followups.md: ansi/sugarcraft art CONTENT (8 files candy-vt wordmark + sugarcraft-2-tc-l-3.ansi one `SugarCraft\Vt`, C.3 content-excluded), sugar-vterm/.vhs/snapshot-demo.gif pixels, icon PNG bytes. <!-- @rename-keep lane e7 record -->
6. sugar-vterm/README Formerly notice (C10 form, ns-change sentence, self-tagged). renames.json:47 `"status":"renamed"` via targeted `sed 47s` (own line only; json validated). `php tools/gen-docs.php` post-flip → 99 files, moved-stub `docs/lib/candy-vt.html` auto-minted (self-tagged "a moved-stub must name the old slug"). <!-- @rename-keep lane e7 record -->
7. Rename commit `18e39ef53` (286 files; %ae=1 %ce=1 detain; no Co-Authored-By).

## Gates (committed tip 18e39ef53, all timeout-wrapped, env -u LINES -u COLUMNS </dev/null)
- re-link after path rename: hand-pruned 2 dangling `*/vendor/sugarcraft/candy-vt` symlinks; **NEW BLINDSPOT:** refresh-deps option is `--libs=a,b` (plural) — first relink attempt died `unknown option: --lib=candy-vcr` (rc=2). Re-ran 11 libs → 11/11 ok, linked=10 (sugar-vterm appears as consumer via siblings), 0 dangling. <!-- @rename-keep lane e7 record -->
- residue `--old=candy-vt`: 0 findings. path-repos/child-lifetimes (28 sites/8 accounted/0 problems)/gen-docs --check (61 pages + 38 stubs)/gen-ansi --check: all rc=0. <!-- @rename-keep lane e7 record -->
- sugar-vterm suite: **976T/12873A EXACT** vs base re-measure (brief-era 912/12643 stale, disclosed). tools/tests **426/1484 EXACT** (= own-base pin; 429/1517 is e6's tip figure).
- candy-vcr full: **989T/4213A/14S EXACT** (LoopPin-before-HangWatchdog bootstrap untouched by rename — files moved, order intact). golden tapes green ⇒ freeze ruling PROVEN.
- consumers: charts 654/1551 EXACT, animation 191/887 EXACT, image 661/14817/6S EXACT, candy-ansi 360/901 OK (no base num), candy-git-server 354/875 OK, candy-pty: full suite carries base-era hang liability (standing red family, not chased) → Ioctl/Size filter 55T/141A/3S green + VimSmoke 1S (skipped here) + `class_exists('SugarCraft\Vterm\Terminal\Terminal')` autoload probe via pty vendor = ok. sugar-dashboard **7483T/14859A/2F = standing AtomicJsonFile `.lock`-residue pair** (WeatherModuleTest::testCacheRoundTripThroughAtomicFileLeavesNoTemp + PersistenceTest::testSaveLeavesNoLeftoverTempFiles; campaign-known "dash 2F", vt-independent — dash's 2 consumer files were ns-token edits in Foundation/Buffer+Cell). <!-- @rename-keep -->
- candy-code K=8 SHARDED CONSERVATION: base (pre-rename, at 6709f44bd) shard-sum **22,564T/423,770A**; tip **22,564T/423,738A, rc=0, 0 FAIL/ERROR lines**. ΔT=0 exact; ΔA=−32 = known data-provider wobble class (lane-ks precedent: gate pins T/S/E/F exact, A wobble tolerated by design).

## Deviations
- Brief consumer list (charts/animation/image) is not the true vt-consumer set — replaced by disk truth + ran listed three anyway.
- Own suite base = 976/12873 (brief "~912T/12643A era" stale at 6709f44bd).

## Weld-relevant seams (for closeout)
- PROJECT_NAMES.md:135: tool rewrote the vt slug on a line that ALSO holds `**CandyAnsi**` (e6's token) → if e6 rewrote the same line, weld merge conflict same-line; content intent identical (both renames), trivial manual fuse. <!-- @rename-keep -->
- Same-file collision candidates with other lanes: candy-code/src/Chat.php + tests (comments only; e-crush lane F owns big crush edits).
- R8/R9-D duty: packagist for sugarcraft/sugar-vterm is Phase-10 owner checklist (fire-and-record, not this lane).
