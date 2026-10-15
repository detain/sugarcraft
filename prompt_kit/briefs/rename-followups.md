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
