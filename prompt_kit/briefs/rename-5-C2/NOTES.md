# rename-5-C2 — sugar-stickers → sugar-flexbox (lib, ns Stickers→Flexbox) <!-- @rename-keep -->

<!-- @rename-keep: this file is the lane work-record; old-name mentions below are AS WRITTEN history. -->

## Verdict
(in progress — R1–R7 this lane; NO R8/R9 here. Branch `rename/c2`, base `4d472bc62`.)

## Entry facts (verified)

- tools/renames.json line 22: kind=rename, sugar-stickers → sugar-flexbox, lib, ns Stickers→Flexbox, bins {}, no extra_literals, no protect. <!-- @rename-keep -->
- Derived (RenameLib::derive): pascal SugarStickers→SugarFlexbox, compact sugarstickers→(R4 replaces bare compact run with new slug `sugar-flexbox` outside python paths; zero compact sites found anyway), env prefixes SUGAR_STICKERS_/SUGARSTICKERS_→SUGAR_FLEXBOX_ (ZERO env sites — lib, honest none), PROTECT auto = none (no `sugar-stickers-*` entries). <!-- @rename-keep -->
- Baseline suite (rename-baseline.md:85): **221T/476A/0F/0E/0S rc0**. <!-- @rename-keep -->

## Recon map

- CONSUMERS: **ZERO**. No lib composer.json requires the package (own manifest name-line only); zero `SugarCraft\Stickers` PHP refs outside the lib tree (vendor excluded). Root composer.json require :96 + repositories :485 only. No sugar-crush test literals. Consumer suite list for R6 = empty beyond the lib itself + tools/tests guards. <!-- @rename-keep -->
- NEW-SIDE collision: `sugar-flexbox`/`SugarFlexbox`/`sugarflexbox`/`SugarCraft\Flexbox`/`SUGAR_FLEXBOX` — ABSENT tree-wide except plan_rename.md + renames.json (both excluded). Existing bare `FlexBox` word inside the lib (its own class!) and generic `flexbox` prose in sugar-dash/FlexLayout.php — NOT package refs, untouched by rules (R1 needs `SugarCraft\` prefix; `Flex` dir segment safe — no bare-word rules). <!-- @rename-keep -->
- Lib internals: src/Flex/{Align,Direction,FlexBox,FlexItem}.php, src/Table/{Column,Table,TableRenderer}.php, src/{Viewport,Scrollbar,Lang}.php. Post-rename `SugarCraft\Flexbox\Flex\FlexBox` (redundant-by-design, upstream-faithful class name kept). Composer autoload PSR-4 `SugarCraft\Stickers\*` ×3 + Tests → R1 rewrites all. <!-- @rename-keep -->
- `tests/StickersTest.php` (class `StickersTest`, kitchen-sink FlexBox+Table suite): bare ns-segment word — invisible to R1–R7 + residue rules. LANE-MANUAL rename → `FlexboxTest.php` + class `FlexboxTest` (precedent: candy-git-server/GitServerTest). No filename collision (`sugar-flexbox/tests/`). <!-- @rename-keep -->
- Lang: `src/Lang.php` `NAMESPACE = 'stickers'` → R6 → `'flexbox'`; docblock bare `'stickers'` word → MANUAL rewrite. LangCoverageTest asserts no domain literal (slug mentions auto-rewrite). lang/en.php content has no slug. <!-- @rename-keep -->
- ENV/DATA paths (R4.4/R4.5): zero getenv/`_ENV`/XDG/HOME/`.config`/`.cache` in src+bin → HONEST NONE, no shims. <!-- @rename-keep -->
- CAP-HYPHEN (B5 law): `# Sugar-Stickers Audit Findings` in findings doc — invisible to tool AND residue → MANUAL. Full sweep found exactly 1 site. <!-- @rename-keep -->
- GLUED `_sugar-stickers` (lookbehind blindspot): findings_resume_plan.md `plan_sugar-stickers.md` cite + repo_map self/other-docs cites of `sugarcraft_sugar-stickers.md`/`update_sugarcraft_sugar-stickers.md` → MANUAL sweep after confirming tool path-moves. `plan_<slug>.md` NOT reliably moved by tool (B7/B8/B12 precedent) → lane `git mv` if dry-run shows no move. <!-- @rename-keep -->
- UPSTREAM keep: package name `76creates/stickers` in composer description + MATCHUPS upstream-map URL row + `docs/repo_map/76creates_stickers.md`/`pr_76creates_stickers.md` + their glued cites — upstream project name, lowercase bare `stickers` matches no rule → KEPT (discoverability: add `"stickers"` keyword per R4.2 upstream-name convention). <!-- @rename-keep -->
- GENERIC-WORD audit: `stickers` lowercase survivors after rename: docs/plans/leftover/step-02-stickers-composes-bits.md + step-12-stickers-sticky-syncscroll.md (paths+filenames, historical leftover-plan shorthand — LEAVE, space-form non-brand per C.4); generate_icons.py prompt string "candy star sticker..." (describes committed PNG bytes — LEAVE, B12 precedent); upstream refs above. Bare-word `StickersTest` handled by manual rename. <!-- @rename-keep -->
- vhs.yml :155 + :225 matrix, codecov.yml flag+component :188-189/:526-531, root README.md :68, use_libs.txt :10, php74_branch.md :418/:521, .claude/skills/record-vhs-demo/SKILL.md :116, candy-hermit/CALIBER_LEARNINGS.md :13, docs/_data pair + docs/lib page + index.html tiles + search-index.json, repo_map pair, research doc, findings pair, docs/plans/leftover ×3, scripts/generate_icons.py dict key — ALL auto R2/R3 territory. MATCHUPS row sits in ## Libraries (type=lib unchanged) → R4.8 moot, verify only. <!-- @rename-keep -->
- ansi/: NO sugar-stickers wordmark art → R4.9 honest none. .vhs/: flexbox/sort-filter/table tapes + committed GIFs (old wordmark pixels possible → followups note; tape paths lib-relative keep working). <!-- @rename-keep -->
- Tracked oddity (recorded): `scripts/__pycache__/generate_icons.cpython-313.pyc` is committed and contains the slug bytes — NUL-byte content-excluded (isContentExcluded), path has no token → residue-safe, untouched. `.opencode/memory/` UNTRACKED (not scanned). `.caliber/`, `.logs/` untracked/excluded.
- prompt_kit/CONTEXT.md `sugar-stickers` lines: 883/887/933/968/976 TAGGED; **:1356 UNTAGGED** (php-dup-finger external-project dep record) → tag in tag-prep. rename-baseline.md :85 → tag in tag-prep. <!-- @rename-keep -->
- EXTERNAL owner note: /home/sites/php-dup-finder (separate repo) declares sugarcraft/sugar-stickers via type:vcs — out of this plan's scope; old Packagist stays installable per R9 preamble. <!-- @rename-keep -->

## Steps

- tag-prep:
- R1 dry-run:
- R2 remote rename:
- R3 tool run:
- R4 manual:
- R5 wiring:
- R6 gates:
- R7 commit:
