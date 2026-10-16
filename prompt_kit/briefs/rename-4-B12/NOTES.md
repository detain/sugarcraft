# B12 candy-mold → candy-starter (app, ns UNCHANGED App\) — lane NOTES <!-- @rename-keep -->

<!-- @rename-keep: this file is the lane work-record; old-name mentions below are AS WRITTEN history. -->

## Verdict
DONE (R1–R7 this lane; R8/R9 weld owed). Branch `rename/b12`, base `10f121d49`. Suite EXACT vs baseline **59T/217A** (brief's "~212T" was the B7 candy-kv figure — DISCLOSED, baseline row rename-baseline.md:48 says 59T/217A). tools/tests floor **426T/1484A** exact. Residue **0**. Pushed: rename/b12 ONLY (no master, no merge, no packagist).

## Step log
- R0 tag-prep @75d72cc96: tagged CONTEXT.md ×5 lines + rename-baseline.md:48 + plan:78 ("recommend updating to CandyMold" historical). <!-- @rename-keep -->
- R1 dry-run: 39 files / 9 path renames / R2=23 / R3=207; R1 ns-rule correctly SKIPPED (ns_old==ns_new==`App`); eye-review clean (see App-ownership below). Re-run after refresh-deps completed (first attempt rc=2 from transient path-repo scratch dirt — B-notes law).
- R2 `rename-remote.sh candy-mold candy-starter` rc=0: repo renamed, description/homepage set, topics synced (no stale `mold` topic present post-keyword-edit — verified via gh api). <!-- @rename-keep -->
- R3 tool rc=0 EXACT vs dry-run; lib dir moved; `php -l` sweep OK (earlier 2 "LINT FAIL" were awk false-positives on old-path side of R entries).
- R3 follow (LANE-MANUAL, B7/B8 precedent): `git mv findings/plan_candy-mold.md findings/plan_candy-starter.md` — tool renames `<slug>.md` but not `plan_<slug>.md`.
- R4 manual set: formerly-notice (candy-starter/README.md, B8 format, tagged); pun "Pour your model into the mold…" rewritten to "Start from this template…" ×4 sites (candy-starter/README.md, docs/_data/candy-starter.body.html, docs/js/search-index.json, docs/index.html — index.html tile summaries are HAND-MAINTAINED, gen-docs --check only verifies counts, so it needed the direct edit too); composer keywords drop `mold`; PROJECT_NAMES.md:141 rationale rewritten (candy + starter = the create-project skeleton you start from); bin/start:25 `candy-template:` → `candy-starter:` (Finding 5 / plan Phase-1.2 FIXED in-rename — plan checkbox left as-written, audit record not this lane's bookkeeping); CALIBER_LEARNINGS bare-`mold` shorthand ×7 rewritten + SugarCraft\Mold hypothetical → `SugarCraft\*` + Finding-5 clause truth-flipped + glued `plan_candy-mold.md` cites → `plan_candy-starter.md`; research doc :612 CAP-HYPHEN `Candy-mold` → `CandyStarter` (invisible to tool+residue, B5 law); update_sugarcraft_candy-starter.md:275 stale pointer → sugarcraft_candy-starter.md; docs/repo_map/sugarcraft_candy-starter.md:23 stale README-quote synced; crush ledger "mold half" → "starter half" ×2 (triage:187, hardening:20691).
- R5: `composer update sugarcraft/candy-starter --no-install` (root lock: candy-mold removed, candy-starter locked); `refresh-deps --mode=linked --libs=candy-starter`; `gen-docs.php` + `--check` OK (13 stubs pre-R7); `gen-ansi-readme --check` OK. <!-- @rename-keep -->
- R6 gates: residue `--old=candy-mold` → 0 findings (&&-chained before staging); candy-starter suite 59T/217A EXACT (cwd=candy-starter, plain pipe, watchdog); tools/tests 426T/1484A exact; sugar-crush `--filter RepoMap` 172T/1003A green (the only `namespace App;` fixtures tree-wide live there); path-repos guard rc=0 (refresh-deps output). <!-- @rename-keep -->
- R7: renames.json entry (line 19) status pending→renamed via targeted one-line sed, JSON-validated, serial-verify re-read; gen-docs mints 14th redirect stub docs/lib/candy-mold.html. <!-- @rename-keep -->

## App-ownership audit (hazard 1) — NO TOOL DEFECT
- `SugarCraft\App\` refs tree-wide: **ZERO** (recon grep). mold's autoload is package-root `App\`→src/ (class `App\Counter`), `App\Tests\`→tests/ — untouched (ns unchanged; pascal token `CandyMold` is slug-derived, never bare `App`). <!-- @rename-keep -->
- Files with own `App`-shaped code that dry-run must NOT touch: `sugar-crush/tests/RepoMap/PhpSymbolExtractorTest.php:43,:120` (`namespace App;` heredoc fixtures) — untouched ✓, RepoMap filter green. sugar-crush `SugarCraft\Crush\App\App` (MediaForm docblocks) — untouched ✓.
- 39-file dry-run set = candy-mold/** + root composer + docs/CI/findings/prompt-kit cites ONLY. No non-consumer App owner in the change set. <!-- @rename-keep -->

## Consumers (hazard 3)
- `sugarcraft/candy-mold` requires: root composer.json :61 + :240 only (tool-rewritten) + root composer.lock (R5). Zero lib manifests, zero PHP `use App\...` imports outside the lib. Docs/CI surfaces (codecov flag/component, vhs.yml all=+matrix, root README row, MATCHUPS row already under ## Apps, docs tiles/_data/search-index/sitemap, repo_map pair, research, findings pair, php74_branch table row, bootstrap-org-repos/set-org-default lists) — tool-rewritten, eye-reviewed. <!-- @rename-keep -->

## Generic-word audit (hazard 2) — survivors are legit, KEPT
- `sugar-crush/src/Tui/MediaForm/MediaForm.php:21` "in exactly the {SettingsEditor} mold" — English idiom, unrelated package.
- `candy-shine/src/GithubEmoji.php:1056` `'moldova'` — country emoji.
- `candy-sql/tests/Admin/Calc/HostLoadSamplerTest.php:106` `TrimOldest…` — camelCase seam false-match.
- ansi/: `sugarcraft-3-16-l-2.ansi` + `sugarcraft-3-16-m-2.ansi` carry the old **wordmark art bytes** (content-excluded from residue tool); `candy-layout-7…` "Chocolate mold tray", `candy-tetris-10…` glass-furnace, `sugar-dash-10…` foundry-molding captions = art prose about OTHER libs (space-form, non-brand).
- `scripts/generate_icons.py:104` dict KEY renamed (R3) but prompt string "pink silicone candy mold tray with two small gummy bears" KEPT — truthful description of the existing committed PNG bytes.
- `starter` new-side: generic; no collision introduced (can't create residue).

## Deviations / follow-ups (weld & owner)
1. Brief suite figure 212T→ actual baseline 59T/217A (B7 mislabel carry) — weld agent expect 59T.
2. `plan_<slug>.md` NOT renamed by tool — lane-mv'd here; consider tool fix later (B7/B8 also lane-mv'd).
3. Icon PNG art + `.vhs/start.gif` (old wordmark pixels, regen loop re-commits from renamed tape on next vhs CI run) + ansi wordmark art → OPTIONAL owner regeneration; byte content intentionally untouched this lane.
4. phpunit.xml testsuite name stays `CandyTemplate` (no rule token; plan:78 recommendation tagged historical).
5. GitHub repo topics carry extra generic topics from old keyword sync (boilerplate/wizard/…) — harmless, owner curation call.
6. No LegacyEnv (zero CANDY_MOLD_*/MOLD_*), no LegacyPaths (zero getenv/HOME/XDG sites) — honest none; commit body states this.

## Figures
- R1: skipped (ns unchanged) / R2: 23 / R3: 207 / files: 39→(41 after manual set incl. renames.json+NOTES) / diff: 41 files 261+/261− (main commit).
- Gates: suite 59/217 EXACT · tools 426/1484 EXACT · RepoMap 172/1003 · residue 0 · gen-docs+ansi --check 0 · path-repos 0.
