# rename-5-D7 NOTES — candy-palette → sugar-colorprofile <!-- @rename-keep -->

Lane: D7 (batch-D line 41, ns Palette→ColorProfile, type=lib, bins {}). Branch `rename/d7` off `55fb3bb93`, worktree `/home/sites/sugarcraft-d7`. NEVER push master; push branch only. No R8/R9, no remote ops (D1 law — weld/serial phase owns remotes). <!-- @rename-keep -->

## Entry facts (verified)

- tools/renames.json:41 kind=rename, candy-palette → **sugar-colorprofile**, lib, ns Palette→ColorProfile, bins {}, no extra_literals/protect, status was pending. **C10-era "already candy-palette — no rename needed" disclosure REFUTED**: C10 is the candy-forms lane (its NOTES carry zero palette lines); disk entry is the authority and it demands the rename. BRIEF-LABEL DEVIATION: orchestrator brief's memory said "palette→sugar-palette/merge/fold" possibilities — disk truth is sugar-colorprofile rename; proceeded per disk. <!-- @rename-keep -->
- Derived: R2 brand CandyPalette→SugarColorprofile (28); compact candypalette ZERO sites (honest none); env CANDY_PALETTE_*/CANDYPALETTE_* ZERO sites → no LegacyEnv, honest none; R6 Lang NAMESPACE 'palette'→'colorprofile' (tool-applied, no test pins the domain string). <!-- @rename-keep -->
- Pre-existence census clean: no `SugarCraft\ColorProfile` files, no `sugar-colorprofile` dir/package/page at base.
- Consumers (composer requires): candy-log, candy-mosaic, honey-bounce, sugar-reel + root manifest. sugar-crush: NOT a dependency — only prose/test-comment mentions (bootstrap.php:477 comment, StdinConstantReaderCensusTest, SuiteChildStdin* comments) — all R1/R3-auto, filter-green.
- Generic-word hazard (brief CAUTION): bare `palette`/`Palette` domain vocabulary LEFT UNTOUCHED everywhere — sugar-crush PaletteState/paletteStandsDown/palette keys, candy-sprinkles `SugarCraft\Sprinkles\Palette`, candy-top `SugarCraft\Top\Theme\Palette`+PaletteMsg, candy-core `Util\Palettes`, candy-mosaic `as PaletteCapability` alias + PaletteCapabilityReferenceTest name, sugar-toast/sugar-datepicker own palettes, CSS/ansi prose. Discrimination law: only ns-qualified (`SugarCraft\Palette` R1 boundary), slug-token (R3 boundary), brand Pascal (R2) and tagged-line greps were ever used — NEVER a bare-word sed. Verification: `--filter Palette` (185T, crush machinery) and `--filter ColorProfile` (5T, lib-ns consumers) both green = vocabulary intact + refs rewritten. <!-- @rename-keep -->
- Baselines R0 re-measured at base in-lane: candy-palette **529T/5537A/1S EXACT** (record :51 agrees); records — candy-log 190/372, candy-mosaic 661/14820/5S, honey-bounce 210/6088, sugar-reel 552/8489. <!-- @rename-keep -->

## Self-lock (verified)

No sentinels tagged inside the lib at tag-prep (tool would skip its path plan). Post-move README Formerly/Namespace notices carry sentinels — harmless, path no longer matches. Redirect stub `docs/lib/candy-palette.html` self-names old slug WITH generator's own `@rename-keep: a moved-stub must name the old slug` comment. <!-- @rename-keep -->

## Tag-prep (commit c4691d009)

41 lines / 12 files `@rename-keep historical provenance`: crush_code_hardening_backlog ×19, worklog ×4, triage ×1 (E334/E302/E318 historical rows + `Iterm2Image` fatal-message lines), CONTEXT ×1 (+3 already tagged), crush-report impact wave5 ×1, php74_branch ×2, plan_crush_media ×3, findings/resume_prompt ×2 (+2 already tagged), findings/monorepo-audit-2026-05-27 ×4, findings/improvements-2026-05 ×2 (+2 already tagged), rename-baseline:51, rename-remediation/NOTES:67. JUDGED-REWRITE (living docs): findings/*, docs/repo_map*/** incl. own pair + step files, docs/research/**, leftover phase-step files, leftover/updates.md, leftover_updates_later.md, AUDIT_UPSTREAM_PROMPT.md, index.html, README.md, AGENTS/.claude/.cursor rules, generate_icons.py, all code/CI. plan_rename.md historical table rows left (renames.json mirror row is entry authority, residue tool exempts its own map). <!-- @rename-keep -->

## Steps

- R1 dry-run: 175 content files / 178 paths; R1=203 R2=28 R3=1155 R6=1 (log /tmp/opencode/d7-dryrun.txt). Path plan = dir move + 12 non-ansi paths (own _data/lib/icon/repo_map/research/findings pair + step-03 file) + 165 ansi paths — matched D1 shape. <!-- @rename-keep -->
- R2 remote rename: SKIPPED per lane law (no remote ops).
- R3 `php tools/rename-lib.php --old=candy-palette`: rc=0, counts exact vs dry-run, `candy-palette/` → `sugar-colorprofile/`; all changed PHP php -l clean; Lang NAMESPACE='colorprofile' confirmed. <!-- @rename-keep -->
- R4 manual sweeps (blindspot set): (a) GLUED pointers — `findings/plan_candy-palette.md` ×5 (findings_resume_plan ×4 + lib CALIBER_LEARNINGS ×1), `sugarcraft_candy-palette.md` ×2 (update_sugarcraft_sugar-colorprofile.md self-pair); (b) CAP-HYPHEN `Candy-Palette`/`Sugar-Palette` ZERO; (c) `.vhs/*.tape` palette tokens ZERO (no post-\n blindspot); (d) bare-Pascal sweep in lib-ns context — all `Palette`/`ColorProfile` class bare-names KEPT per C4/D1 law (`...ColorProfile\Palette`, `...ColorProfile\ColorProfile`); (e) keywords += "palette" (upstream name "colorprofile" retained), README Formerly + Namespace-change notices added. <!-- @rename-keep -->
- R5: status flipped pending→renamed IN the rename commit; `composer update 'sugarcraft/*' --no-install` — lock diff pure sugarcraft (−candy-palette +sugar-colorprofile, only name line outside); gen-docs 92 files + redirect stub mint, `--check` rc=0 (61 pages + 31 stubs); gen-ansi-readme `--check` rc=0; refresh-deps linked ×5 consumers + sugar-crush for the filter gate. <!-- @rename-keep -->

## Gates (on committed tip)

- residue `--old=candy-palette`: **0 findings rc=0** (pre-commit and post-stage). <!-- @rename-keep -->
- sugar-colorprofile suite: **529T/5537A/1S EXACT** vs R0. <!-- @rename-keep -->
- candy-log 190/372 EXACT; honey-bounce 210/6088 EXACT; sugar-reel 552/8488/1S (record -1A/+1S = same drift D1 disclosed); candy-mosaic 661/14817/6S (record -3A/+1S = same drift D1 disclosed). <!-- @rename-keep -->
- crush `--filter ColorProfile` 5T/17A OK + `--filter Palette` 185T/2162A OK (comment-only crush touches; generic machinery green — discrimination above). Full K=8 not run per brief (D1 precedent). <!-- @rename-keep -->
- tools/tests **426T/1484A OK** (via sugar-colorprofile/vendor/bin/phpunit --bootstrap tools/tests/_bootstrap.php --no-configuration tools/tests).
- check-path-repos `--no-lib-path-repos` rc=0; check-child-lifetimes rc=0; check-one-type-per-file rc=0; gen-docs `--check` rc=0; gen-ansi-readme `--check` rc=0. <!-- @rename-keep -->

## Deviations / findings

1. C10 "palette already renamed — no-op" claim REFUTED at disk (see Entry facts); D7 executed the real rename.
2. No env shims (zero sites), no bins, no data paths (lib is stateless) — honest none, documented.
3. ansi art CONTENT (113 .ansi wordmark bytes) + icon PNGs + recorded GIFs → prompt_kit/briefs/rename-followups.md D7 section.
4. Root worktree carried no vendor/ at start (fresh-worktree fact, D1 law); tools/tests run via the lib's own phpunit binary.
