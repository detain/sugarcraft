# C-SEC-6.e — WELD (batch-14, W23-run)

Welded 2026-10-28 atop master e91eb1d47, wave position 5/5 (W23 lane).

## Pick (verified single-commit lane, zero conflicts, %ae/%ce detain, cherry-pick -x)
- ee4789c04 → af169e2ba (OTel safety events per verdict; §4 cite; branch tip == base+1 confirmed before pick)

## Merged-tree gates (cwd=candy-code, plain pipe) — ALL GREEN
- Otel band `tests/Observability tests/Cli/NonInteractiveOtelExportTest.php tests/Host/SessionHostOtelFoldTest.php` → OK 87T/353A (exact)
- `--filter 'OtlpEncoderTest|OtlpSafetyEventTest'` → OK 25T/121A (exact)
- OtlpSafetyEventTest alone → OK 11T/54A (exact)

## DUR row
- MINTED `candy-code/tests/Observability/OtlpSafetyEventTest.php 0.096 11`

## Notes
- REVIEW-1 canonical (staged): r1 CLEAN full-pass — reason_token privacy structural (enum slice only), 3 verdict doors fold onto the turn span, no-verdict path byte-identical.
- +11T suite contribution. No regen; lang/en.php not touched by this pick.
