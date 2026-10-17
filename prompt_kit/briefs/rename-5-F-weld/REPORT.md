# Batch-F WELD report (2026-10-17, sole master writer)

## Verdict
COMPLETE. master `8f266dd28` → f1 `627400de5` → f2 `cb0afcf96` → heal `75ab66f98` → tracker `c1805072f` → this report. All gates green on committed tip. R8/R9-F NOT run (serial agent's, per plan).

## Merges / conflicts
- rename/f1 tip was `a8ff49943`, one commit past the briefed `b54726b7a` (green-serial figure re-pin 22,571/424,926 touching candy-code/README.md:2228 + suite-figure.json). Recorded; no effect (f2's README hunks at :82/:98/:2027 — disjoint).
- rename/f2 `bd98ed3b8` child of b54726b7a — merge auto-merged everything.
- ZERO conflicts both merges (origin had not moved; master==origin==8f266dd28 verified pre-merge).
- composer.lock: no take-ours needed (branch side clean); single `composer update sugarcraft/candy-code sugarcraft/candy-code-web --no-install` after both merges = "Nothing to modify in lock file" — lock already exact (2 new slugs, 0 old). No lock commit minted.
- tools/renames.json: rows 57+58 already `renamed` (lane-set), verified at tip; all other rows untouched; `json_decode(..., JSON_THROW_ON_ERROR)` OK.

## Heal (1 commit)
- 75ab66f98: @rename-keep on 4 f1 historical MEASURED comments (BaseSystemPromptTest:959, SessionStartHookWireTest:527, BootstrapLaunchFormatConstantsTest:319, PromptStabilityTest:844) — old-slug spellings quoting the byte-change history, added after f1's own residue run. f1 NOTES' "residue 0" was true at its run; stale by its last commits.

## Gates @ tip
1. bin: `candy-code/bin/candy-code` executable, mode preserved; needed linked vendor → `refresh-deps --mode=linked --libs=candy-code` (f1-era vendor lived under sugar-crush/); `php candy-code/bin/candy-code --help` rc0, prints CandyCode help.
2. residue: `--all-done` 52 entries 0 findings; `--old=sugar-crush` 0; `--old=sugar-crush-web` 0. `SugarCraft\Crush` live-zero (candy-code src/tests/bin + candy-code-web/src); remaining hits = docs/old/* archive, RenameLibTest fixture heredoc, plan_rename ledger, .opencode memory (all recognized-historical). Glued `sugarcru` sweep: same classes only.
3. K=8: shard-sum 1834+2832+2396+3466+3383+2971+2563+3126 = **22,571T EXACT** vs f1 floor; 2S (canaries shards 3+7); 0F/0E; rc_marker=ok ×8; A=424,974 (+48 vs serial 424,926 — accepted provider-wobble class); conservation rc0 (fail-closed script).
4. npm: `npm --prefix candy-code-web ci` (0 vulns) → build 725ms rc0, lint rc0, typecheck rc0, vitest **211/211** (24 files). dist parity: post-build `git diff --exit-code` on dist EMPTY.
5. tools/tests 429T/1517A EXACT 0F (first run 1F — RenamesJsonTest one-side-on-disk law accused the two husk dirs; see 6). policy ×4: path-repos --no-lib-path-repos, child-lifetimes, gen-docs --check, gen-ansi-readme --check all rc0. php -l sweep: 2,467 changed PHP files, zero errors.
6. LegacyMigration dual-read (env -i throwaway HOME): `SUGARCRUSH_DATA` → exactly ONE `candy-code: env SUGARCRUSH_DATA is deprecated, use the CANDY_CODE_ prefix` line, rc0; `CANDY_CODE_DATA` → silent, rc0.
7. husks: sugar-crush/ (131M) + sugar-crush-web/ (180M) held only composer.lock/.phpunit caches/vendor/node_modules/test-results/docs-cache, 0 tracked each → removed (gate-enforced). Root `.sugar-crush/` (memory/ + worktrees/ runtime data, 0 tracked) left untouched — not policed, user data.
8. tracked `^sugar-crush` paths: 0. Chain over base at heal time: 18 commits (12 f1 + 3 f2 + 2 merges + heal); +tracker +this report = 20 at push.

## Union audit
- prompt_kit/briefs/rename-followups.md: tip md5 == rename/f1 == rename/f2 version — f1's +19 lines (bdf812b78, 11ba5edf5) survived; f2 added nothing (its records ride NOTES).
- plan_rename.md: untouched by lanes (no drop possible); F-weld block added by tracker commit c1805072f.
- rename-5-f1/NOTES.md (46L) + rename-5-f2/NOTES.md (35L) present at tip.

## Anomalies
- Brief SHA for f1 tip stale by one re-pin commit (handled, recorded).
- candy-code/vendor absent post-merge (lane dirs were renamed; vendor is gitignored residue that rode the old husk) — restored via refresh-deps linked, rc of committed-tree guard OK.
- tools/tests 1F pre-husk-removal is the intended fail-closed shape of RenamesJsonTest (documented above), not a code defect.
- No `git worktree`/branch cleanup performed (brief: near-end skip housekeeping). Branches rename/f1+f2 and master history remain for the R8/R9-F serial agent.
