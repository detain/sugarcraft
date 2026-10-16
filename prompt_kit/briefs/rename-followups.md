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
