# C-PRV-4.a — WELD (batch-14, W22-run)

Welded 2026-10-28 atop master e91eb1d47, wave position 2/5.

## Picks (zero conflicts, %ae/%ce detain, cherry-pick -x)
- 958c5b939 → b5c81dff2 (host permission mode + isolation for `bare: false`)
- 204c33848 → 0a7582f13 (review fixes round 2; §4 cite)

## Merged-tree gates (cwd=candy-code, plain pipe) — ALL GREEN
- quintet exact-class filter (DocFigure|ReadmeRosterDrift|TrustKey|EnvRoster|ConfigWriteProducer) → OK 166T/44519A (T exact; +189A wobble — master moved past lane base b9a3b0fff)
- `--filter 'Backend\\EngineBackend'` → OK 274T/1964A (review said 272T/1894A at lane base; +2T/+70A from batch-13 era on master; green)
- CliProviderIsolationTest → OK 29T/71A (exact)
- tests/Providers dir → OK 2420T/8646A (review 2418T/8641A; base-era delta, expected)

## DUR row
- MINTED `candy-code/tests/Providers/CliProviderIsolationTest.php 0.135 29` (no row existed)

## Notes
- REVIEW-1 + REVIEW-2 canonical on disk (both staged). NIT carry (closeout): filter-spelling note in REVIEW-2.
- No regen (no protocol/web touch).
