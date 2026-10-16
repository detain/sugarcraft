# Rename baseline snapshot (plan_rename.md Step 0.1)

- **Master SHA at baseline:** `40a4919fc` (matches the SHA the plan was revised against — no Phase 8A re-verify needed at this step, but later steps must re-check)
- **Date:** 2026-10-15
- **Branch:** master, `git pull --ff-only origin master` → "Already up to date."

## Deviation notes

1. **Untracked-file residue at start:** `git status --porcelain` showed exactly one entry —
   `?? plan_rename.md` (the plan itself, added to version control by this step). No tracked-file
   modifications were present, satisfying the Part A precondition. No foreign operator dirt
   (crush_libs.md / plan_crush_media.md / .sugar-crush/ were absent or gitignored).
2. **refresh-deps --status (pre-fix):** `linked=56  published=0  mixed=0`. Six libs report
   `-  no vendor/sugarcraft` because they declare no sugarcraft sibling deps at all:
   `candy-ansi, candy-focus, candy-fuzzy, candy-layout, sugar-crush-web, sugar-diff`.
   All other 56 libs were already linked; `--mode=linked` was still run across all libs per plan.
3. **Branch protection:** `gh api repos/detain/sugarcraft/branches/master/protection` →
   HTTP 404 `Branch not protected`. No required_status_checks contexts exist, so later CI job
   renames cannot strand a required check. (404/no-protection is a valid recorded result per Step 0.1 item 5.)

## Baseline test figures (per lib)

Command per lib: `cd /home/sites/sugarcraft/<lib> && vendor/bin/phpunit --colors=never`.
PTY libs (candy-pty, candy-core, candy-wish, candy-vcr, candy-scriptui, sugar-crush) run under the
Part A rule-9 watchdog (1800 s). Pre-existing failures are RECORDED, not fixed.
sugar-crush uses the sharded runner mirroring ci.yml "Run tests (sugar-crush — sharded, E671)".

| lib | tests | assertions | failures | errors | skipped | rc | note |
|---|---|---|---|---|---|---|---|
| candy-ansi | 360 | 901 | 0 | 0 | 0 | 0 |  |
| candy-async | 117 | 259 | 0 | 0 | 0 | 0 |  |
| candy-buffer | 315 | 1712 | 0 | 0 | 0 | 0 |  |
| candy-core | 1383 | 28917 | 0 | 0 | 25 | 0 |  |
| candy-files | 193 | 716 | 0 | 0 | 0 | 0 |  |
| candy-flip | 196 | 903 | 0 | 0 | 0 | 0 |  |
| candy-focus | 93 | 412 | 0 | 0 | 0 | 0 |  |
| candy-forms | 2326 | 4529 | 0 | 0 | 0 | 0 |  |
| candy-freeze | 350 | 752 | 0 | 0 | 0 | 0 |  | <!-- @rename-keep -->
| candy-fuzzy | 1006 | 35741 | 0 | 0 | 0 | 0 |  |
| candy-hermit | 159 | 601 | 0 | 0 | 0 | 0 |  |
| candy-input | 409 | 41381 | 0 | 0 | 1 | 0 |  |
| candy-kit | 272 | 1634 | 0 | 0 | 0 | 0 |  |
| candy-layout | 293 | 3315 | 0 | 0 | 0 | 0 |  |
| candy-lister | 162 | 386 | 0 | 0 | 0 | 0 |  | <!-- @rename-keep -->
| candy-log | 190 | 372 | 0 | 0 | 0 | 0 |  |
| candy-metrics | 174 | 464 | 0 | 0 | 0 | 0 |  |
| candy-mines | 180 | 820 | 1 | 0 | 0 | 1 | W=4 | <!-- @rename-keep -->
| candy-mold | 59 | 217 | 0 | 0 | 0 | 0 |  | <!-- @rename-keep historical provenance -->
| candy-mosaic | 661 | 14820 | 0 | 0 | 5 | 0 |  |
| candy-mouse | 204 | 500 | 0 | 0 | 0 | 0 |  |
| candy-palette | 529 | 5537 | 0 | 0 | 1 | 0 |  |
| candy-pty | — | — | — | — | — | 137 | HUNG: lib's own HangWatchdog SIGKILLed the runner at 53% (418/708) — PosixPumpTest::testRunWithNullChildReturnsZero in-flight 90.5s > 90s budget (child stuck in do_select with [true] defunct zombie). Pre-existing under this env; see /tmp/opencode/rename-baseline/candy-pty.txt forensic dump |
| candy-query | 1580 | 11900 | 0 | 0 | 0 | 0 |  | <!-- @rename-keep -->
| candy-serve | 354 | 875 | 0 | 0 | 0 | 0 |  | <!-- @rename-keep baseline pre-rename -->
| candy-shell | 334 | 694 | 0 | 0 | 0 | 0 |  | <!-- @rename-keep baseline pre-rename -->
| candy-shine | 938 | 2378 | 0 | 0 | 0 | 0 |  |
| candy-sprinkles | 865 | 2896 | 0 | 0 | 0 | 0 |  |
| candy-testing | 267 | 5417 | 0 | 0 | 2 | 0 |  |
| candy-tetris | 214 | 4322 | 0 | 0 | 0 | 0 |  |
| candy-top | 2110 | 107777 | 0 | 0 | 0 | 0 |  |
| candy-vcr | 989 | 4209 | 0 | 0 | 14 | 0 |  |
| candy-vt | 976 | 12873 | 0 | 0 | 0 | 0 |  |
| candy-wish | 260 | 976 | 0 | 0 | 0 | 0 |  |
| candy-mouse | 139 | 416 | 0 | 0 | 0 | 0 |  |
| honey-bounce | 210 | 6088 | 0 | 0 | 0 | 0 |  |
| honey-flap | 64 | 172 | 0 | 0 | 0 | 0 |  | <!-- @rename-keep -->
| sugar-bits | 570 | 1246 | 0 | 0 | 0 | 0 |  |
| sugar-boxer | 201 | 456 | 0 | 0 | 0 | 0 |  |
| sugar-calendar | 149 | 382 | 0 | 0 | 0 | 0 |  | <!-- @rename-keep -->
| sugar-charts | 654 | 1551 | 0 | 0 | 0 | 0 |  |
| sugar-crumbs | 144 | 260 | 0 | 0 | 0 | 0 |  |
| sugar-crush-web | 5 | 36 | 0 | 0 | 0 | 0 |  |
| sugar-dash | 7483 | 14859 | 2 | 0 | 0 | 1 |  |
| sugar-diff | 70 | 225 | 0 | 0 | 0 | 0 |  |
| sugar-gallery | 264 | 1162 | 0 | 0 | 0 | 0 |  |
| sugar-glow | 122 | 313 | 0 | 0 | 0 | 0 |  | <!-- @rename-keep -->
| sugar-mcp | 226 | 899 | 0 | 0 | 0 | 0 |  |
| sugar-post | 203 | 361 | 0 | 0 | 0 | 0 |  | <!-- @rename-keep -->
| candy-forms | 72 | 158 | 0 | 0 | 0 | 0 |  |
| sugar-readline | 336 | 1076 | 0 | 0 | 0 | 0 |  |
| sugar-reel | 552 | 8489 | 0 | 0 | 0 | 0 |  |
| sugar-skate | 212 | 466 | 0 | 0 | 0 | 0 |  | <!-- @rename-keep -->
| sugar-spark | 278 | 1412 | 0 | 0 | 0 | 0 |  | <!-- @rename-keep -->
| sugar-stash | 326 | 4638 | 0 | 0 | 0 | 0 |  | <!-- @rename-keep -->
| sugar-stickers | 221 | 476 | 0 | 0 | 0 | 0 |  |
| sugar-table | 510 | 1237 | 0 | 0 | 0 | 0 |  |
| sugar-tick | 144 | 349 | 0 | 0 | 0 | 0 | <!-- @rename-keep --> |
| sugar-toast | 278 | 669 | 0 | 0 | 0 | 0 |  |
| sugar-veil | 258 | 561 | 0 | 0 | 0 | 0 |  |
| sugar-wishlist | 257 | 1160 | 0 | 0 | 0 | 0 |  | <!-- @rename-keep -->
### sugar-crush (sharded, CI-shape)

Mirrors `.github/workflows/ci.yml` step "Run tests (sugar-crush — sharded, E671)" from the repo root:
`bash scripts/parallel-tests.sh 4 --durations scripts/parallel-tests-durations.tsv --against-json sugar-crush/tests/Config/Support/suite-figure.json --out <fresh> --timeout 1200`
(CI pins K=min(nproc,4); box has 48 cores → K=4.)

| lib | tests | assertions | failures | errors | skipped | rc | note |
|---|---|---|---|---|---|---|---|
| sugar-crush | 22564 | 423668 | 0 | 0 | 2 | 0 | CONSERVATION PASS vs pinned figure 22564/423652/0/0/2 (+16A wobble); 4 shards rc=0, wall 557s. Evidence /tmp/opencode/rename-baseline/sugar-crush-shard.txt |

## Pre-existing failures catalog (RECORDED, not fixed)

1. **candy-mines** — `DifficultyStatsTest::testSaveLeavesNoTempArtifacts` fails (+4 Warnings) — 180T/820A/1F, rc=1. <!-- @rename-keep -->
2. **sugar-dash** — `Weather\WeatherModuleTest::testCacheRoundTripThroughAtomicFileLeavesNoTemp` and
   `State\PersistenceTest::testSaveLeavesNoLeftoverTempFiles` fail — 7483T/14859A/2F, rc=1.
   (Known AtomicJsonFile temp-residue family in dash.)
3. **candy-pty** — suite HUNG under its own HangWatchdog (see row above); no figures recorded.

## Tools guards (Step 0.1 item 4) — ALL rc=0 from repo root

| # | command | rc |
|---|---|---|
| 1 | `candy-core/vendor/bin/phpunit --no-configuration tools/tests/` | 0 — OK (390 tests, 1065 assertions), 31s |
| 2 | `php tools/check-path-repos.php --no-lib-path-repos` | 0 |
| 3 | `php tools/check-path-repos.php` | 0 |
| 4 | `php tools/check-path-repos.php --unused` | 0 |
| 5 | `php tools/check-child-lifetimes.php` | 0 |
| 6 | `php tools/check-one-type-per-file.php` | 0 |
| 7 | `php tools/gen-docs.php --check` | 0 |
| 8 | `php tools/gen-ansi-readme.php --check` | 0 |

## Method notes / hazards recorded for later steps

- Suite loops MUST run with stdin `</dev/null`: the first candy-serve attempt STOPPED (state T, <!-- @rename-keep historical hazard note -->
  SIGTTIN) because its git-server children read stdin while the loop ran under a PTY session;
  with `</dev/null` candy-serve passed 354T/875A in <1 min. Not a pre-existing defect. <!-- @rename-keep historical hazard note -->
- 61 lib suites + sugar-crush sharded = 62 test surfaces baselined.
