# Phase 3 Batch A entry 1 (PILOT): honey-flap → candy-flappy <!-- @rename-keep -->

Procedure R pilot run, 2026-10-15. R7 commit carries the tree rename; this file
records steps, eye-checks, findings and fixes.

## Steps
- R1 dry-run: 65 content files, 168 paths, R1=34 R2=25 R3=1074 (after tagging
  historical prompt_kit campaign refs @rename-keep in bf23476b1). Full file list
  reviewed; no over/under-match beyond findings below.
- R2 remote first: `scripts/rename-remote.sh` renamed sugarcraft/honey-flap → <!-- @rename-keep -->
  candy-flappy; description/homepage/topics verified; old URL redirects.
- R3 tool run: exit 0, executed 65 files / 169 paths — matched plan.
- R4 follow-ups: README formerly + namespace notices (both `@rename-keep`);
  composer keywords deduped + `flapioca` added (description already carried the
  upstream Go name kbrgl/flapioca); XDG high-score dir migration shipped in
  `bin/candy-flappy` via `LegacyPaths::migrate()` (`.honey-flap` → `.candy-flappy` <!-- @rename-keep -->)
  with STDERR notice; MATCHUPS row already under `## Apps` (kind app→app, no move);
  ansi regenerated via `tools/gen-ansi-readme.php`.
- R5 wiring: root composer require+repositories rewritten by tool;
  `composer update 'sugarcraft/*' --no-install` — lock diff verified
  sugarcraft-only (content-hash + one package block swap);
  `refresh-deps --mode=linked --libs=candy-flappy` 10/10; gen-docs ok
  (61 pages + 3 redirect stubs, `--check` clean).
- R6 gates: candy-flappy suite vs baseline 64T/172A/0F/0E/0S (see R7 verification);
  tools/tests 422T/1434A → 425T/1469A (+RefreshDepsScratchRestoreTest 2T,
  +underscore-path RenameLibTest case 1T; tree-scan assertion wobble included);
  `check-path-repos --no-lib-path-repos` rc 0; residue checker 0 findings.

## User data / env statement
- No `HONEY_FLAP_*` / `HONEYFLAP_*` application env vars exist (the only
  uppercase wordmarks live inside excluded `*.ansi` art content) → no LegacyEnv
  import needed. User data: XDG config high-score dir migrated via
  LegacyPaths in the bin entry (only path the app writes).

## FINDING A — refresh-deps clobber (defect, fixed @ a4511da67)
`refresh-deps.php` linked-mode `finally` ran `git checkout -- <lib>/composer.json`
restoring from INDEX; Procedure R stages `git mv` (index = old bytes at new
path), so the R5 run rolled the freshly renamed manifest back to old-name bytes.
Fixed: byte-snapshot restore + `SUGARCRAFT_REFRESH_ROOT` test seam +
`RefreshDepsScratchRestoreTest` (mutation-proven: old restore shape → exactly the
primary assert red). Pilot tree repaired from a canonical worktree re-run
(byte-comparison proved damage limited to the one manifest).

## FINDING B — rename-lib underscore-joined paths (tool bug, fixed @ 53d9ec944)
Path plan targets `findings/plan_<old>.md`, `docs/repo_map/sugarcraft_<old>.md`,
`docs/repo_map/update_sugarcraft_<old>.md` (plan C.2), but the content-strict
`(?<![A-Za-z0-9_])` lookbehind rejected slug occurrences after `_` → 3 paths not
moved (contents were rewritten). Fixed with a relaxed path-context class
(`pathContext` flag threaded through rewritePath→rewriteSegment→r3/r4 patterns);
content rules stay strict (pinned by new RenameLibTest case incl. negative
fixtures: `docs/candy-shine_scores.md` must NOT move, `_slug` in content must
survive). Mutation proof: flipping the flag back → exactly 1 failure.
The 3 doc paths themselves land post-R7 by re-running the fixed tool on the
clean tree (it re-detects moved-state and applies only remaining pathPlan moves),
together with manual `_`-joined link fixes (`_honey-flap.md` refs in <!-- @rename-keep -->
`docs/repo_map/update_sugarcraft_candy-flappy.md` ×13 and
`findings/findings_resume_plan.md` ×1) — unreachable by strict content rules and
invisible to the residue checker (filename refs are content with `_` prefix).

## FINDING C — prefix-family prose misplacements (manual truth-fixes in R7)
Mechanical R3 rewrote family rosters into false statements:
`CLAUDE.md` Physics list gained `candy-flappy` (moved to Foundation list),
`PROJECT_NAMES.md` wordplay rationale + Honey- family example updated to state
the rename history. DocsQuickstart/tree guards green after edits.

## Eye-check (pilot: ≥10 files opened)
candy-flappy/composer.json (name, keywords, PSR-4 `SugarCraft\Flappy\`, bins,
honey-bounce dep untouched) · README.md (notices+badges) · bin/candy-flappy
(LegacyPaths block before Program run) · src/Game.php (HIGH_SCORE_FILE now
`.candy-flappy/scores.json`, `$isFlap` bare word correctly untouched) ·
tests/GameTest.php · .vhs/play.tape (bin path) · golden fixtures (pure SGR) ·
docs/_data/candy-flappy.json + .body.html · root composer.json (require +
repositories) · codecov.yml (6) · .github/workflows/vhs.yml (all=(…) + matrix) ·
ansi/ansi.json (160 new keys, zero old keys) · php74_branch.md row ·
CLAUDE.md · PROJECT_NAMES.md.

## Deviations from brief
1. Two campaign-maintenance commits precede R7: 4eb5d0bd2 (step 2.5 NOTES.md
   was untracked dirt blocking the tool's clean-tree preflight) and bf23476b1
   (@rename-keep tagging of historical campaign records — plan's own mechanism).
2. Tools/tests delta +3T (two tool-defect regression tests) rides two separate
   `rename: fix …` commits per the pilot mandate (fix the TOOL, never hand-patch).
3. `gh run list` uses `-R detain/sugarcraft` (CI lives on the origin remote;
   brief's `sugarcraft/sugarcraft` has no Actions runs).
4. The 3 underscore doc paths land in a post-R7 follow-up commit (tool preflight
   requires a clean tree; R7 lands everything else). Tracked in FINDING B above.
5. Deferred art/icon redraws recorded in prompt_kit/briefs/rename-followups.md
   (99/144 wordmarks, play.gif, 🐝 emoji in docs/_data).
