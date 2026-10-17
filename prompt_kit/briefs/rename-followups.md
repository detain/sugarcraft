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
- ansi/candy-sql/: 167 art pieces; some embed the pre-rename text wordmark `CandyQuery`/`QUERY` (e.g. candy-sql-5-256-m-2.ansi) — art content is C.3-excluded, redraw deferred (human), per plan R4.9. <!-- @rename-keep -->
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
- `candy-ssh-launcher/.vhs/picker.gif` — demo GIF renamed with the dir by R3; its frames still show the pre-rename
  `wishlist` wordmark. <!-- @rename-keep --> CI `.github/workflows/vhs.yml` re-renders on the next tape run; do not hand-patch.
- `media/icons/candy-ssh-launcher.png` — icon carries copied pre-rename art bytes (regenerated via renamed dict key,
  prompt text unchanged); redraw optional at owner triage.

## B11 candy-freeze → candy-codeshot (2026-10-16) <!-- @rename-keep -->
- `candy-codeshot/.vhs/{ansi-input,screenshot}.gif` — 2 demo GIFs renamed with the dir by R3; their frames <!-- @rename-keep -->
  still show the pre-rename `candyfreeze` wordmark. CI `.github/workflows/vhs.yml` re-renders from the <!-- @rename-keep -->
  already-rewritten `.tape` files on the next tape run; do not hand-patch.

## C1 sugar-boxer → sugar-panels (2026-10-16) <!-- @rename-keep -->
- `sugar-panels/.vhs/{basic,borders,nested}.gif` — 3 demo GIFs renamed with the dir by R3; their frames <!-- @rename-keep -->
  still show the pre-rename `sugar-boxer` wordmark/paths. CI `.github/workflows/vhs.yml` re-renders from <!-- @rename-keep -->
  the already-rewritten `.tape` files on the next tape run; do not hand-patch.

## C2 sugar-stickers → sugar-flexbox (2026-10-16) <!-- @rename-keep -->
- `sugar-flexbox/.vhs/{flexbox,sort-filter,table}.gif` — 3 demo GIFs renamed with the dir by R3; their frames <!-- @rename-keep -->
  may still show the pre-rename wordmark. CI `.github/workflows/vhs.yml` re-renders from the already-rewritten <!-- @rename-keep -->
  `.tape` files on the next tape run; do not hand-patch.
- `ansi/sugarcraft/sugarcraft-3-16-l-2.ansi` — monorepo roster grid art carries an old-name cell (line 18); <!-- @rename-keep -->
  `.ansi` content is rename-excluded; re-generate via the ansi toolchain when triaged.
- `media/icons/sugar-flexbox.png` + `docs/img/icons/sugar-flexbox.png` — icon PNG path renamed; bytes unchanged <!-- @rename-keep -->
  (generate_icons.py prompt describes literal sticker imagery — semantically fine, redraw optional at owner triage).

## C3 sugar-crumbs → sugar-navstack (2026-10-16) <!-- @rename-keep -->
- `ansi/sugarcraft/sugarcraft-2-16-l-1.ansi`, `ansi/sugarcraft/sugarcraft-2-256-l-1.ansi`, <!-- @rename-keep -->
  `ansi/sugarcraft/sugarcraft-3-16-l-2.ansi` — each carries 1 `sugar-crumbs` tile in the pre-rendered <!-- @rename-keep -->
  monorepo logo-wall art. `.ansi` content is rename-excluded (tools and residue); re-render owed by <!-- @rename-keep -->
  whoever next touches the ansi corpus (`php tools/gen-ansi-readme.php` after any redraw). <!-- @rename-keep -->
- `media/icons/sugar-navstack.png` — renamed path, unchanged bytes (cookie-crumb motif predates the <!-- @rename-keep -->
  NavStack naming; generate_icons.py prompt text "golden cookie crumbs" kept truthful for existing art, <!-- @rename-keep -->
  B12 precedent). Redraw optional at owner triage.

## C8 sugar-dash → sugar-dashboard (2026-10-16) <!-- @rename-keep -->
- `ansi/sugar-dashboard/*.ansi` (179 files) — wordmark pixel art spells `SUGAR-DASH`; <!-- @rename-keep -->
  content is rename-excluded (.ansi); re-generate via the ansi toolchain when triaged. <!-- @rename-keep -->
- `sugar-dashboard/.vhs/*.gif` (153 GIFs) — renamed with the dir by R3; frames still show the pre-rename <!-- @rename-keep -->
  wordmark. CI `.github/workflows/vhs.yml` re-renders from the already-rewritten `.tape` files; do not hand-patch.
- `media/icons/sugar-dashboard.png` — icon carries copied pre-rename art bytes; redraw optional at owner triage.
- Data paths rewritten by R3 with NO migration shim (verdict: honest rename): `~/.cache/sugar-dash/` → <!-- @rename-keep -->
  `~/.cache/sugar-dashboard/` (WeatherModule cache — stale-tolerant, worst case one refetch) and <!-- @rename-keep -->
  `~/.config/sugar-dash/plugins` → `~/.config/sugar-dashboard/plugins` (Plugin/Discovery — users with external <!-- @rename-keep -->
  plugins under the old dir need to move them; owner triage if a shim is desired).

## C10 candy-forms→sugar-forms (2026-10-16, lane rename/c10) <!-- @rename-keep -->
- ansi/sugar-forms/*.ansi (158 files) + ansi/sugar-forms/50-sugar-forms.ansi still render old <!-- @rename-keep -->**CandyForms** / <!-- @rename-keep -->**CANDYFORMS** / <!-- @rename-keep -->**CANDY FORMS** wordmarks — pixel-art content excluded from rename tooling by design (C.3); needs hand-art re-roll. <!-- @rename-keep -->
- ansi/ansi.json desc "Truecolor SugarForms wordmark over twin hue-swept gradient bars" now leads the art (rewrite R2 on desc only); sibling desc "…CANDYFORMS block wordmark…" remains byte-true. <!-- @rename-keep -->
- media/icons/sugar-forms.png + docs/img/icons/sugar-forms.png: bytes unchanged (old-brand artwork at new path).
- .vhs/*.tape render rules kept filenames; recorded GIFs still show old brand until CI re-render. <!-- @rename-keep -->
- ansi/sugarcraft/sugarcraft-{2-16-l-1,2-256-l-1,3-16-l-2,5-256-l-1}.ansi banner art embeds old candy-forms slug in <!-- @rename-keep -->pixel content. <!-- @rename-keep -->

## C12 candy-focus → sugar-focus (2026-10-16) <!-- @rename-keep -->
- `ansi/sugarcraft/sugarcraft-2-16-l-1.ansi` + `sugarcraft-3-16-l-2.ansi` — wordmark art content lists the <!-- @rename-keep -->
  pre-rename slug (`.ansi` content is C.3-excluded); redraw optional at owner triage.
- `sugar-focus/.vhs/focus-ring.gif` — demo GIF frames show the pre-rename prompt wordmark; CI <!-- @rename-keep -->
  `.github/workflows/vhs.yml` re-renders from the already-rewritten `.tape` on the next tape run; do not hand-patch.

## C13 candy-fuzzy → sugar-fuzzy (2026-10-16) <!-- @rename-keep -->
- `ansi/candy-forms/candy-forms-2-256-m-1.ansi`, `ansi/sugarcraft/sugarcraft-4-256-l-1.ansi`, <!-- @rename-keep -->
  `ansi/sugarcraft/sugarcraft-5-256-l-1.ansi` — art CONTENT carries the pre-rename `candy-fuzzy` <!-- @rename-keep -->
  wordmark (C.3 excludes *.ansi content). Redraw or leave for the ansi tagger pass. <!-- @rename-keep -->

## D3 sugar-reel → sugar-video (2026-10-16) <!-- @rename-keep -->
- `ansi/sugarcraft/sugarcraft-1-tc-l-2.ansi` — art CONTENT carries the pre-rename <!-- @rename-keep -->
  `sugar-reel` wordmark (C.3 excludes *.ansi content). Redraw or leave for the ansi tagger pass. <!-- @rename-keep -->
- `sugar-video/.vhs/play.gif` — rendered bytes show the old `# SugarReel` prompt; CI vhs.yml re-renders. <!-- @rename-keep -->
- `scripts/generate_icons.py` art prose for the sugar-video key still says "film reel disc" — kept as common-English <!-- @rename-keep -->
  description of the existing artwork; regenerate when the icon art is next touched. <!-- @rename-keep -->

## D4 candy-flip → sugar-animation (2026-10-16) <!-- @rename-keep -->
- `ansi/sugarcraft/sugarcraft-3-16-l-2.ansi` — art CONTENT carries the pre-rename `candy-flip` <!-- @rename-keep -->
  wordmark (C.3 excludes *.ansi content); no `ansi/candy-flip/` dir existed. Redraw at owner triage. <!-- @rename-keep -->
- `sugar-animation/.vhs/play.gif` — recorded GIF frames show the pre-rename prompt wordmark; CI <!-- @rename-keep -->
  re-renders from the already-rewritten `.tape` on the next tape run; do not hand-patch.
- `sugar-animation/tests` temp-name prefixes `sc_flip_*`/`flip-color-`/`flip-lct-` kept — bare <!-- @rename-keep -->
  lowercase `flip` is C.4-rule-exempt opaque fixture noise, not brand prose.

## D5 candy-kit → sugar-cli (2026-10-16) <!-- @rename-keep -->
- `ansi/sugarcraft/sugarcraft-{2-16-l-1,3-16-l-2}.ansi` + `ansi/sugar-crush/30-crush-terminal.ansi` — <!-- @rename-keep -->
  art CONTENT embeds the pre-rename `candy-kit` slug (C.3 excludes *.ansi content). Redraw or leave <!-- @rename-keep -->
  for the ansi tagger pass.
- `sugar-cli/.vhs/cli-page.gif` — demo GIF frames show the pre-rename CandyKit wordmark; CI re-renders <!-- @rename-keep -->
  from the already-rewritten `.tape` on the next vhs run; do not hand-patch.

## D6 honey-bounce → sugar-spring (2026-10-16, lane rename/d6) <!-- @rename-keep -->
- `ansi/sugar-spring/*.ansi` (171 files) still render the old **HONEY BOUNCE** / **HONEYBOUNCE** block <!-- @rename-keep -->
  wordmarks — `*.ansi` content is C.3-excluded by design; needs hand-art re-roll at owner triage.
- `ansi/sugarcraft/*.ansi` (15 files), `ansi/sugar-crush/{41,42,50}-*.ansi` (3) and `ansi/candy-flappy/*.ansi` (2) <!-- @rename-keep -->
  embed the old slug/wordmark in pixel content (same C.3 exclusion; banners/captions list libs).
- `media/icons/sugar-spring.png` + `docs/img/icons/sugar-spring.png`: bytes unchanged (old honey-jar artwork at new path). <!-- @rename-keep -->
- `scripts/generate_icons.py:121` prompt still describes a honey jar (byte-true vs the PNG); rewrite together with the <!-- @rename-keep -->
  Phase-9/10 prefix-family prose.
- `sugar-spring/.vhs/*.gif` recorded frames show the pre-rename prompt; CI `vhs.yml` re-renders on next tape run. <!-- @rename-keep -->

## D7 candy-palette → sugar-colorprofile (2026-10-16, lane rename/d7) <!-- @rename-keep -->
- `ansi/sugar-colorprofile/*.ansi` (113 files) — art CONTENT still renders the pre-rename <!-- @rename-keep -->
  `CandyPalette` wordmark (`*.ansi` content is C.3-excluded). Redraw at the ansi tagger pass. <!-- @rename-keep -->
- `media/icons/sugar-colorprofile.png` + `docs/img/icons/sugar-colorprofile.png` — bytes unchanged <!-- @rename-keep -->
  (old-brand artwork moved to the new path). <!-- @rename-keep -->
- `.vhs/*.gif` recorded frames may show the pre-rename prompt; CI `vhs.yml` re-renders from the <!-- @rename-keep -->
  already-clean `.tape` files on the next tape run. <!-- @rename-keep -->

## e12 candy-pty → sugar-pty (2026-10-16, lane rename/e12) <!-- @rename-keep -->
- `ansi/sugarcraft/*.ansi` — the composite SugarCraft wordmark art CONTENT embeds the pre-rename <!-- @rename-keep -->
  `candy-pty` component row (`*.ansi` content is C.3-excluded). Redraw at the ansi tagger pass. <!-- @rename-keep -->
- `media/icons/sugar-pty.png` + `docs/img/icons/sugar-pty.png` — bytes unchanged (old-brand <!-- @rename-keep -->
  artwork moved to the new path). <!-- @rename-keep -->
