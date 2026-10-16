# Rename 4-B9: sugar-wishlist → candy-ssh-launcher <!-- @rename-keep -->

Started 2026-10-16. Base 10f121d49 (worktree /home/sites/sugarcraft-b9, branch rename/b9). Entry: tools/renames.json line 16,
kind=rename, type=app, ns Wishlist→SshLauncher, bins {"bin/wishlist":"bin/candy-ssh-launcher"}, extra_literals same. <!-- @rename-keep -->
CONCURRENCY: lanes b10/b11/b12 run in parallel on other packages; stop after R7+branch push (weld agent does R8/R9).

## Recon findings (pre-R1)

- Baseline suite (rename-baseline.md:90): 257T/1160A/0F/0E/0S/0W — keep shape EXACT.
- Collision check: `SshLauncher`/`ssh-launcher`/`ssh_launcher` appear ONLY in plan_rename.md + tools/renames.json (the entry itself) — clear.
- Consumers of `sugarcraft/sugar-wishlist`: root composer.json (+ own manifest) ONLY. Zero PHP consumers of `SugarCraft\Wishlist\` outside the lib. <!-- @rename-keep -->
- sugar-crush single docblock hit src/Cli/NonInteractive.php:192 (`candy-mail`/`sugar-wishlist` provenance prose) — mechanical R3 rewrite, no test pins; run consumer guards filter at R6 (B8 precedent 276T set).
- CALIBER_LEARNINGS cross-refs sugar-toast ×2 / sugar-table ×1 / candy-git ×2 (pattern citations incl `sugar-wishlist/src/Lang.php` path) — mechanical, stay accurate.
- ENV: ZERO user env reads (only getenv('HOME') in bin + SshConfigParser). Plan R4.5 LegacyEnv = honest none. R5 derived prefixes SUGAR_WISHLIST_/SUGARWISHLIST_ = tool no-ops.
- DATA PATHS: `~/.config/wishlist.{yml,yaml,json}` + CWD `wishlist.{yml,yaml,json}` = UPSTREAM-COMPATIBLE (charmbracelet/wishlist config names) — plan:823/:951 KEEP exception. Not slug-derived → tool leaves; keep, README must explain. No LegacyPaths.
- Generic word `wishlist`: the config FORMAT keeps the name post-rename (files are still wishlist.yaml). Split rule:
  cli.* invocations/prefixes (binary identity) → flip to `candy-ssh-launcher`; config.*/endpoint.* messages (describe the wishlist config format) → KEEP truthful.
  Sites: src/Lang.php NAMESPACE 'wishlist' → 'sshlauncher' (precedent candy-mail 'mail', candy-kv 'kv'); lang/*.php ×16 cli.usage/unknown_arg/no_config/error/cancelled/ssh_not_executable flip;
  bin/wishlist stderr prefixes ("wishlist: could not find composer autoloader") + docblock `wishlist` prose flip; README/R4.1 usage prose flip.
  en.php config.*/endpoint.* 'wishlist config not found' etc. + de.php 'Wishlist-Konfiguration' etc. KEEP (format vocabulary).
- BIN residue rule shape: `` `wishlist``/$ wishlist/php wishlist `` (lookahead excludes [A-Za-z0-9_-]) — `'Usage: wishlist [` and `'wishlist: unknown arg'` NOT flagged, flipped for hygiene anyway (B8 law: bare-usage prose flips).
- BinCliTest:55 assertStringContainsString('wishlist', $out) pin → flips to 'candy-ssh-launcher' with the cli.usage change; :58 is derived verbatim (Lang::t) — survives. :101 wishlist.yml CWD-probe guard = format name KEEP.
- ConfigTest:141 tempnam prefix 'wishlist' → brand-derived → flip 'candy-ssh-launcher' (B6/B8 temp-prefix hygiene law). ConfigImportSshConfigTest 'ssh_config' prefixes unrelated.
- examples/sample-wishlist.yml = sample of the kept wishlist config format → KEEP (filename accurate).
- No ansi/ entry (no ansi/sugar-wishlist dir) — no art migration. .vhs/picker.tape + picker.gif exist: paths mechanical; GIF content shows old wordmark → followups line (CI re-renders).
- vhs.yml all=() row :147 + matrix :225, codecov.yml ×6, media/icons/sugar-wishlist.png, docs/_data pair, repo_map pair, findings/sugar-wishlist.md + findings/plan_sugar-wishlist.md, research doc, docs/MATCHUPS `SugarWishlist` ×2 (R2 pascal rule covers), root README row (icon/display/link) — tool territory (B1–B8 precedent).
- Glued blindspot sweep (`_`-lookbehind invisible): `plan_sugar-wishlist.md` content cites (findings/plan.md? + own CALIBER_LEARNINGS?) — grep at R4. findings/plan_sugar-wishlist.md file itself renames (B7 precedent).
- docs/plans/leftover/phase-10-apps/step-27-wishlist-proxyjump-identityfiles.md + step-28-wishlist-import-sshconfig.md: bare-`wishlist` filenames (feature plans referencing the app/config) — audit content at R4; NOT tool-visible (no slug literal).
- docs/repo_map/charmbracelet_wishlist.md ×25 = upstream repo records — KEEP (bare `wishlist`, invisible to all rules, truthful).
- php74_branch.md ×2: historical branch-survey tables carrying `sugar-wishlist` slug — tool rewrites mechanically OR tag as-written; decide at R4.
- prompt_kit/CONTEXT.md hits ALL already line-tagged @rename-keep (file-level skip protects from tool); baseline row 90 tagged in tag-prep.
- tools/tests expect 426T/1484A ± docs-dataset deltas accounted.

## Wishlist audit table (every flagged survivor post-R4, filled at R6) <!-- @rename-keep -->

| site | text | disposition |
|------|------|-------------|
| (fill) | | |

## Steps

- [x] recon
- [ ] tag-prep + R1 dry-run eye-review
- [ ] R2 remote rename
- [ ] R3 tool run + php -l sweep
- [ ] R4 followups (Lang NAMESPACE + cli.* locale flip, bin prose, README formerly/notice/format note, composer de-frame, glued sweep, sample file check, followups lines)
- [ ] R5 wiring + regen checks
- [ ] R6 gates (residue 0, suite exact, tools/tests, path-repos, crush guards, wishlist audit table)
- [ ] R7 commit (status=renamed) + PUSH BRANCH rename/b9 (NO R8/R9 — weld agent)

## Log

(async per step)
