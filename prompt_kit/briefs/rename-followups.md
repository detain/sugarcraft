# rename-followups — deferred art/content work from Procedure R entries

Per plan_rename.md R4.9/R4.10: artwork and recorded GIFs keep the old wordmarks after a rename;
redraws are NOT part of this plan. Each entry records what still shows the pre-rename brand.

## honey-flap → candy-flappy (Batch A entry 1, pilot) <!-- @rename-keep -->

- `ansi/candy-flappy/*.ansi` — 99 of 144 art files still draw the old wordmark (`HONEY`,
  `HONEYFLAP`, `FLAP` lettering; 5 files carry the full `HONEYFLAP` lockup). Art CONTENT is
  excluded from the rewrite (Part C.3); only paths/keys were renamed. Redraw owed by whoever
  next touches the ansi corpus (`php tools/gen-ansi-readme.php` after any redraw).
- `candy-flappy/.vhs/play.gif` — rendered demo shows the pre-rename prompt/name; CI re-renders
  from `.vhs/play.tape` (already rewritten) on the next change to the lib. Left as-is per R4.10.
- `candy-flappy/.assets/icon.png` and `media/icons/candy-flappy.png` — binary icon may depict old
  branding; content untouched (binary exclusion). Redraw if the icon carries wordmarks.

## sugar-calendar → sugar-datepicker (Batch A entry 3) <!-- @rename-keep -->

- `ansi/sugar-datepicker/*.ansi` — 114 of 147 art files still render the word `Calendar`/`CALENDAR`
  inside the captured picker UI (title labels). Art CONTENT is excluded from the rewrite (Part C.3);
  only paths/keys were renamed. Redraw owed by whoever next touches the ansi corpus
  (`php tools/gen-ansi-readme.php` after any redraw).
- `sugar-datepicker/.vhs/{basic,constraints}.gif` — rendered demos show the old prompt path; CI re-renders
  from the (already rewritten) `.tape` files on the next change to the lib. Left as-is per R4.10.
- `sugar-datepicker/.assets/icon.png` and `media/icons/sugar-datepicker.png` — binary icon content
  untouched (binary exclusion). Redraw if the icon carries wordmarks.

## B1 sugar-stash → candy-git (2026-10-15) <!-- @rename-keep -->
- `ansi/sugarcraft/sugarcraft-3-16-l-2.ansi` — the ecosystem-banner art still spells `sugar-stash` in its <!-- @rename-keep -->
  lib-name column (1 line, art CONTENT is excluded from the rewrite per Part C.3; `gen-ansi-readme --check`
  agrees with ansi.json unchanged). Redraw owed with the next ansi-corpus pass.
- `candy-git/.vhs/{play,stage}.gif` — rendered demos still show the old ` SugarStash ` title-bar brand; <!-- @rename-keep -->
  CI re-renders from the already-rewritten `.tape` files on the next change to the lib. Left as-is per R4.10.
- `candy-git/.assets/icon.png` + `media/icons/candy-git.png` + `docs/img/icons/candy-git.png` — binary
  icon content untouched (path renamed only).
- ansi/candy-sql/: 167 art pieces; some embed the pre-rename text wordmark `CandyQuery`/`QUERY` (e.g. candy-sql-5-256-m-2.ansi) — art content is C.3-excluded, redraw deferred (human), per plan R4.9.
- `ansi/sugarcraft/sugarcraft-3-16-l-2.ansi` — the ecosystem-banner art still spells `candy-serve` in its <!-- @rename-keep -->
  lib-name column (art CONTENT is C.3-excluded; `gen-ansi-readme --check` green with unchanged ansi.json). Redraw owed.
- candy-git-server ships NO `.vhs/` demos and no ansi/candy-git-server/ corpus; docs/index.html tile still points at <!-- @rename-keep -->
  `candy-git-server/.vhs/config.gif` (pre-existing broken ref inherited from the candy-serve era — VHS demo owed, not rename debt). <!-- @rename-keep historical record -->
- `candy-git-server/.assets/icon.png` + `media/icons/candy-git-server.png` + `docs/img/icons/candy-git-server.png` — binary <!-- @rename-keep -->
  icon content untouched (paths renamed from candy-serve.* only). <!-- @rename-keep historical record -->

## B5 sugar-glow → candy-mdview (2026-10-16) <!-- @rename-keep -->
- `ansi/sugarcraft/sugarcraft-{1-16-m-1,1-256-s-2,3-16-l-2,4-256-l-1}.ansi` — the ecosystem-banner art still <!-- @rename-keep -->
  spells `sugar-glow` in its lib-name column (4 lines total, one per tile; art CONTENT is excluded from the <!-- @rename-keep -->
  rewrite per Part C.3; `gen-ansi-readme --check` green with unchanged ansi.json). Redraw owed with the next <!-- @rename-keep -->
  ansi-corpus pass.
- `candy-mdview/.vhs/{pager,render}.gif` — rendered demos still show the old `glow`-era branding; CI re-renders <!-- @rename-keep -->
  from the already-rewritten `.tape` files (they drive ./bin/candy-mdview now) on the next change to the lib. Left as-is per R4.10.
- `candy-mdview/.assets/icon.png` + `media/icons/candy-mdview.png` + `docs/img/icons/candy-mdview.png` — binary
  icon content untouched (paths renamed from sugar-glow.* only). <!-- @rename-keep -->

## B6 sugar-spark → candy-ansi-inspect (2026-10-16) <!-- @rename-keep -->
- `ansi/candy-ansi-inspect/` — 162 art files still paint the `sugar-spark` wordmark (C.3 content-excluded, <!-- @rename-keep -->
  paths renamed). Redraw only if a future art pass happens; `php tools/gen-ansi-readme.php` regenerates README from ansi.json.
- PRE-EXISTING MISLABEL for human triage: `findings/sugar-charts-sparkline.md` is titled "# Audit: sugar-spark" <!-- @rename-keep -->
  but its content audits `src/Sparkline.php` = the sugar-charts Sparkline port, not this app (likely spark↔sparkline
  name-confusion at audit time, 2026-06-30). Its 3 old-slug lines were tagged @rename-keep pre-R3 so the rename
  would not mint false claims about candy-ansi-inspect. Retitle/merge into the sugar-charts records when triaged.
- `candy-kv/.vhs/*.gif` — 5 demo GIFs (basic, binary, glob, multidb, reverse-order) renamed with the dir by R3; their frames
  still show the pre-rename wordmark. CI `.github/workflows/vhs.yml` re-renders on the next tape run; do not hand-patch.
- PRE-EXISTING MISLABEL for human triage: `docs/plans/leftover/phase-12-deferred/step-09-expect-expansion.md:10` names
  `candy-skate` (a lib that never existed) as a task-automation consumer of Expect — verified present already at 6b64cab86,
  NOT a rename artifact; may have meant sugar-skate/candy-kv <!-- @rename-keep --> but a key/value store is not a
  scripted-dialog consumer, so do not auto-rewrite to candy-kv (would mint a false claim). Retitle/reword when triaged.
- `docs/plans/leftover/phase-10-apps/step-35-skate-import-typo-ttl-stdin-tx.md` filename + branch cite `ai/skate-import-typo-...`
  carry the bare word skate <!-- @rename-keep --> — historical plan/branch names, intentionally left (slug never appears; benign).
- `ansi/candy-mail/*.ansi` — 40 wordmark art files (migrated from `ansi/sugar-post/` by R3 <!-- @rename-keep -->): their bytes still
  render the pre-rename `SUGARPOST` wordmark. Content is rename-excluded (.ansi); re-generate via the ansi toolchain when triaged.
- `candy-mail/.vhs/showcase.gif` — demo GIF renamed with the dir; its frames still show the pre-rename wordmark. CI
  `.github/workflows/vhs.yml` re-renders on the next tape run; do not hand-patch.

## B11 candy-freeze → candy-codeshot (2026-10-16) <!-- @rename-keep -->
- `candy-codeshot/.vhs/{ansi-input,screenshot}.gif` — 2 demo GIFs renamed with the dir by R3; their frames <!-- @rename-keep -->
  still show the pre-rename `candyfreeze` wordmark. CI `.github/workflows/vhs.yml` re-renders from the <!-- @rename-keep -->
  already-rewritten `.tape` files on the next tape run; do not hand-patch.
