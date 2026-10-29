# C-AGT-6.e — FIX-1 (round 1)

Branch cc/C-AGT-6.e: 15be076bc → e4cb6f6ab ("C-AGT-6.e: review fixes (round 1)"). Author+committer Joe Huss <detain@interserver.net> (separate %ae/%ce greps: 1/1), zero Co-Authored-By. Diff: exactly 2 files, +10/−2. No suite-figure.json / durations.tsv / dist touched.

## Finding 1 — MAJOR (step-local): unaccounted proc_open string reference
- candy-code/tests/Support/DescriptorInheritanceGuardTest.php:708-711 — added one NOT_A_SPAWN row keyed `Workspace/PrStatusCache.php::fetchViaGh`, `count => 1`, reason wording mirroring the GhRunner capability-probe rows ("function_exists() capability probe: with proc_open disabled the gh pr view watch is skipped, the chip stays null"). Census roster moves in-step per §1.8.

## Finding 2 — NIT: arm() docblock truth-flip
- candy-code/src/Workspace/PrStatusCache.php:195-201 — docblock no longer claims the shared one-shot is kept "at the earliest live due time"; states honestly that a live timer is kept as-is and never re-compared, so a late-joining entry can wait up to one TTL for its first fetch (next beat re-arms; chip is decoration). Comments-only; no behavior change.

## Gate results (plain pipe, cwd=candy-code, env -u LINES -u COLUMNS)
- RED PRE: `--filter 'DescriptorInheritanceGuard'` @15be076bc → 15T/1F, failure exactly `+ 'Workspace/PrStatusCache.php::fetchViaGh: string reference'` (guard :1596).
- GREEN POST: same filter @e4cb6f6ab → Tests: 15, Assertions: 5127, 0F (5126→5127 = the new roster entry counted; 2 PHPUnit Deprecations pre-existing at base).
- `--filter 'PrStatusCache'` @e4cb6f6ab → Tests: 28, Assertions: 60, OK (matches reviewer's recorded figure exactly).
- php -l: both files clean.

## Pinning note
Finding 1's guard IS the pin — the roster row is load-bearing (removal re-reddens deterministically, proven by the pre-fix run above). Finding 2 is a docblock-only truth flip; no DocFigure arm covers the arm() prose and the reviewer prescribed wording-only.
