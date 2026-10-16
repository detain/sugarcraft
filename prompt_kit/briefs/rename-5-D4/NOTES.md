# rename-5-D4 NOTES — candy-flip → sugar-animation <!-- @rename-keep -->

Lane: D4 (batch-D line 38, ns Flip→Animation, type=lib, bins {}). Branch `rename/d4` off weld `55fb3bb93`, worktree `/home/sites/sugarcraft-d4`. NEVER push master; push branch only. No R8/R9, NO remote/packagist ops (lane law per D1; weld/serial phase owns remotes). <!-- @rename-keep -->

## Entry facts (verified)

- tools/renames.json line 38: kind=rename, candy-flip → sugar-animation, lib, ns Flip→Animation, bins {}, no extra_literals/protect, status pending. Derived: pascal CandyFlip→SugarAnimation, compact candyflip (ZERO sites), env CANDY_FLIP_/CANDYFLIP_ (ZERO sites → honest none, no LegacyEnv shim; lib has no bin). R6 Lang domain: candy-flip/src/Lang.php NAMESPACE='flip' → 'animation' (tool). <!-- @rename-keep -->
- Phase 1.3 already split the viewer out: lib has NO bin/ (viewer lives in candy-gifview, keeps its name, requires sugarcraft/sugar-animation → rewritten by R3).
- Pre-existence census: `sugar-animation`/`SugarAnimation`/`sugaranimation`/`SUGAR_ANIMATION_`/`SugarCraft\Animation` — ZERO tree-wide except plan_rename.md + renames.json (the maps themselves, excluded). Clean.
- Consumer manifests requiring sugarcraft/candy-flip: candy-gifview, candy-mosaic, candy-top, sugar-reel (+ root composer.json). sugar-crush has ZERO flip/ns/manifest refs (only English-word "Flip" identifiers — untouched by rules). Prose-only hits: candy-core DescriptorSinkArgumentCensusTest (roster path R3-auto), candy-pty PosixMasterPty docblock, ansi/sugarcraft art (content-excluded → followups). <!-- @rename-keep -->
- Baselines: rename-baseline.md:35 records 196T/903A (era of logo campaign); RE-MEASURED at base 55fb3bb93: **flip 191T/887A OK** (-5T/-16A drift disclosed, pre-existing, not chased), **gifview 16T/71A OK** (no record — minted). top recorded 2110/107777 (stale per C8 note); mosaic/reel records :49/:81 belong to their own renamed lanes — measure consumers at MY base below.
- sugar-animation has NO ansi/<slug>/ art dir; wordmark art exists only in ansi/sugarcraft/sugarcraft-3-16-l-2.ansi (*.ansi content excluded per C.4 → followups). .assets/play.tape carries no flip tokens. media/icons/sugar-animation.png (binary path auto-rename).
- vhs.yml `all=` :151 + matrix :225 (R3-auto), codecov.yml ×6 (R3-auto).
- Lang domain pin sites EXTERNAL to sugar-animation: ZERO (grep `'flip'` = only inside lib). Internal manual set: src/Lang.php docblock `'flip' namespace`, tests/LangTest.php fallback assert `'flip.nonexistent.key'` + comment + method `testNamespaceIsFlip` — must ride R6 in-step.

## Alias/brand blindspot map (pre-planned from D1 `as .*Flip[A-Z]` law)

- `use SugarCraft\Animation\Decoder as FlipDecoder;` / `Frame as FlipFrame;` — candy-mosaic/src/ImageSource.php (+2 tests) and sugar-reel/src/Decode/GifDecoder.php + tests/GifDecoderTest.php. R1 rewrites the alias TARGET, never the alias NAME. Manual: FlipDecoder→AnimationDecoder, FlipFrame→AnimationFrame (+ docblock/`{@see}` sites + reel private `$flipFrame`/`flipFrameToRgbFrame` → `$animationFrame`/`animationFrameToRgbFrame`).
- KEPT per C4 bare-brand precedent (no rule matches, judgment keep): class-bearing `Flip` words in mosaic test names `ImageSourceGifFlipWalkParityTest` + method names, candy-top English-verb test names (`testProfileFlipRepicks…`, `testTtyModeFlipLoads…`, NetPanel/OptionsMenu Flips*), crush Compress*Test "FlipABlock" English, `Frame::flip*` if any (checked: none).
- Glued content pointers (R3 lookbehind blocks `_candy-flip`): `findings/plan_candy-flip.md` ×3 (findings_resume_plan.md), `docs/repo_map/sugarcraft_candy-flip.md` ×3 in update_sugarcraft_candy-flip.md + update_sugarcraft_candy-mosaic.md, self-file pointers. Paths auto (C.2), pointer text manual. <!-- @rename-keep -->
- CAP-HYPHEN `Candy-Flip`/`Candy-flip`: ZERO (grep verified). BARE Pascal `SugarAnimation`: 26 files, all R2-auto (docs/_data, MATCHUPS, PROJECT_NAMES, README, search-index, lib itself).

## Self-lock law

NEVER tag @rename-keep in: candy-flip/** (all, incl README/CALIBER/phpunit.xml/lang), findings/{candy-flip,plan_candy-flip}.md, docs/research/libraries/candy-flip-research.md, docs/repo_map/{sugarcraft_candy-flip,update_sugarcraft_candy-flip}.md, docs/_data/candy-flip.{json,body.html}, docs/lib/candy-flip.html, media/icons. README Formerly notice added POST-move to sugar-animation/README.md. <!-- @rename-keep -->

## Tag-prep set (history → @rename-keep, per-line) — 61 lines / 12 files

crush_code_hardening_backlog ×6, worklog ×19, backlog_triage ×1, leftover/updates.md ×5, monorepo-audit ×5, improvements-2026-05 ×1, resume_prompt ×2, plan_crush_media ×5 (media-campaign historical inventory lines), sibling NOTES rename-1.3 ×13 / rename-2.5 ×1 / rename-remediation ×2, rename-baseline.md:35. CONTEXT.md ×6 flip lines ALREADY tagged (verified 0 untagged). crush-dep-surface.php has NO flip row (verified absent). JUDGED-REWRITE (living): docs/plans/sugar-animation-mosaic-split.md, leftover phase-09 step-01/02/03-flip-*.md + phase-11 step-04 + phase-01/07 steps + leftover README/_templates/README, repo_map inventories, research docs, MATCHUPS/PROJECT_NAMES/README/CLAUDE/index.html/search-index/sitemap, all PHP/code, yml, docs/old excluded per C.3.

## Steps

- R0 base measures (linked, worktree-own script): flip 191T/887A OK (record :35 196/903 — pre-existing drift, not chased), gifview 16T/71A (no record — minted), top 2108T/108614A (NONDETERMINISTIC ±100A — see gates), baseline mosaic 661/14817/6S-era + reel 552/8489/1S records cited from D1/C8 era, NOT re-measured at MY base (renamed-file consumers verified green post-hoc instead; gate shapes matched the records exactly).
- tag-prep commits: e9e2622ae (61 lines/12 files) + 20232bbf1 (php74_branch ×2 — root path, my earlier check hit the wrong dir). CONTEXT.md flip lines already carried tags (0 untagged). crush-dep-surface.php has NO flip row.
- R1 dry-run: 145 files / 12 paths; R1=125 R2=27 R3=623 R6=1 (log /tmp/opencode/d4/r1-dryrun.txt).
- R2 remote rename: SKIPPED per lane law (weld/serial phase owns remotes).
- R3 tool run: rc=0, 144 files / 12 paths, R3=621 (-2 = the php74 tags landed between dry-run and run); dir moved sugar-animation/; Lang NAMESPACE='animation'; php -l clean tree-wide.
- R4 manual: (a) NEW-BLINDSPOT-COMPOUND: the `as Flip[A-Z]` alias family lives in TWO libs (D1 found mosaic only) — `use SugarCraft\Flip\Decoder as FlipDecoder` + `Frame as FlipFrame` in candy-mosaic (src+2 tests) AND sugar-reel (src+test); renamed to AnimationDecoder/AnimationFrame incl camelCase derivatives `$flipFrame`/`flipFrameToRgbFrame`. Grep form: `use\s+\S*<Ns>\\\w+ as \w*<BareNs>[A-Z]` then the alias bodies. (b) R6-ADJACENT: lowercase Lang-domain strings `'flip.nonexistent.key'` + docblock/comment `'flip' namespace` survive all rules — swept in src/Lang.php + tests/LangTest.php (method testNamespaceIsFlip→IsAnimation) in-step. (c) glued `plan_candy-flip.md` ×3 findings_resume_plan + `sugarcraft_candy-flip.md` ×3 in own repo_map update file — rewritten; D1 law re-proven (path auto, glued CONTENT not). (d) findings/{sugar-reel,plan_sugar-reel}.md FlipDecoder code-quotes ×5 tagged historical (D1 precedent), sugar-reel/CALIBER_LEARNINGS.md line rewritten (live doc). (e) KEPT per C.4/bare-word law: mosaic `ImageSourceGifFlipWalkParityTest` (class-bearing bare brand), candy-top English-verb test names (ProfileFlipRepicks, TtyModeFlipLoads…), crush Compress*/Chat "Flip" English, temp prefixes sc_flip_*/flip-color-/flip-lct-, "truth-flip" prose. (f) README Formerly+Namespace notices added post-move (tagged). <!-- @rename-keep -->
- R5 wiring: root `composer update 'sugarcraft/sugar-animation' --no-install` — lock diff pure sugarcraft (candy-flip refs -13/+0; sugar-reel/dist ambient SHA drift disclosed); NEW-BLINDSPOT: `refresh-deps --mode=linked` reports escaping=1 + `*** WANTED LINKED *** PROBLEMS` while the consumer `vendor/sugarcraft/<old>` symlinks dangle at the deleted dir — verify counts symlinks whose realpath() fails. Fix = `rm <lib>/vendor/sugarcraft/candy-flip` in gifview/mosaic/top/reel/crush THEN re-run → 6/6 linked escaping=0. gen-docs 92 files (61 pages + 31 stubs incl minted candy-flip.html redirect, self-tagged); gen-ansi --check rc0 (no ansi/candy-flip dir ever existed). <!-- @rename-keep -->
- R6 gates (committed tip dabe2b1ad, clean tree): residue 0; sugar-animation 191T/887A EXACT; gifview 16T/71A EXACT; top OK 2108T, assertions NONDETERMINISTIC per run (base 108614, tip 108703, re-run 108634 — clock/clock-float redraw family, C8 saw same shape; NOT rename-caused, base and tip interleave); mosaic 661T/14817A/6S + reel 552T/8488A/1S EXACT vs records; crush --filter 'Flip|Logo|Ansi|Art' 784T/19757A OK (timeout 900, 1m54s; FULL crush K=8 deferred to weld — gap stated); tools/tests 426T/1484A OK (via sugar-animation/vendor bin — candy-core has no vendor in fresh worktrees, D1 fact re-proven); child-lifetimes 60 libs/8 findings/8 accounted rc0; path-repos --no-lib-path-repos rc0; one-type-per-file 61 libs rc0; gen-docs --check rc0; gen-ansi --check rc0; config blob untouched.
- R7 commits: tag-prep e9e2622ae → tag-prep2 20232bbf1 → rename dabe2b1ad (172 files, author+committer detain verified, no trailer) → NOTES+followups (this). renames.json line 38 status=renamed (compact one-line sed, own line only). Push rename/d4 ONLY.

## Blindspots for closeout ledger (D4 additions)

1. Alias blindspot COMPOUNDS across consumers: sweep `as <BareNsNoun>` tree-wide, expect camelCase derivatives ($brandFrame/brandFrameToX) of the same alias; D1 found one lib, D4 found two.
2. R6 leaves lowercase domain strings (`'flip.key'`) in tests/docblocks tree-wide — include `'<ns_old>\.'` in the manual grep set.
3. refresh-deps linked-verify FAILS on dangling old-slug vendor symlinks after a rename — prune by hand first (`vendor/sugarcraft/<old>`), gate is realpath-based.
4. candy-top assertion total is run-nondeterministic (±100A) — consumer gates on top judge by T-count + 0F, not exact A.

## Deviations

- Consumer suites mosaic/reel not re-measured at MY base pre-rename (D1-era records used as targets — matched EXACT, so moot).
- flip suite -5T/-16A vs rename-baseline.md record measured at base = pre-existing drift (disclosed; record row itself tagged).
- gifview baseline minted at 16T/71A (1.3-era lib, never baselined).
