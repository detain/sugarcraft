# C-PRV-2.c — REVIEW-2 (round-2 fix verification)

Base reviewed: tip b08ca96054163c2650d1f568572fea93069f38d0 (parent e8668b386 = r1 tip, intact).
Verdict: **CLEAN / WELD-READY — 0 CRITICAL / 0 MAJOR / 0 step-local MINOR / 2 NIT (closeout)**

## r1 step-local MINOR — CLOSED (verified on disk)
- `git show --stat b08ca9605`: exactly +7 lines to `candy-code/tests/Providers/AzureOpenRouterTypesTest.php` ONLY (3 comment + 4 provider rows). Zero src change, zero scope creep; parent is exactly e8668b386 (not amended).
- The 4 required rows are present as distinct named data rows in `malformedHeaderBlocks` (file :316-329):
  `case-variant owned name` (['AUTHORIZATION' => 'Bearer smuggled'] → 'builds that header itself'),
  `untrimmed name` (['X-Probe ' => 'x'] → 'header names must be'),
  `empty value` (['X-Probe' => ''] → 'needs a non-empty string value'),
  `non-string value` (['X-Probe' => 42] → same needle).
  Provider widened 5→9 rows. Needles match the real refusal messages at
  `src/Providers/CustomProvider.php:207/:213/:219`.
- REPORT.md FIX-1 row names match git show byte-for-byte (no phantom).

## Tests re-run independently (plain pipe, env -u LINES -u COLUMNS, cwd=candy-code)
- `--filter 'AzureOpenRouterTypesTest'`: **21T / 53A green** — builder claim EXACT (+4T/+8A = 2 assertions/row).
- Builder's window `--filter 'AzureOpenRouterTypes|CustomProvider|ProviderFactory|ProviderErrorCopy'`: **265T / 744A green** — builder claim EXACT; the 2 PHPUnit deprecations are the disclosed pre-existing AccessorDeprecation ones.
- Task-specified wider filter `--filter 'Azure|CustomProvider|ProviderFactory|ProviderErrorCopy'`: 267T / 750A green ('Azure' additionally matches other Azure*-named classes).
- No new reds anywhere; tree clean before and after.

## Non-vacuity (reasoned from pin structure; repo kept read-only, builder's mut-backup consistent)
- `OWNED_HEADER_NAMES = ['authorization', 'content-type']` (CustomProvider.php:96, lowercase) and the owned check runs `strtolower($key)` (:211). Dropping strtolower reddens ALL THREE owned rows — the two original rows carry camelCase keys — matching the builder's M1 "3F" claim exactly; the new case-variant row alone discriminates the leg.
- Dropping the `!== trim($key)` leg (:204): 'X-Probe ' passes name, owned (strtolower not owned), and value checks → no exception → 'untrimmed name' row red.
- Dropping `$value === ''` (:217): '' is a string with no control chars → 'empty value' row red.
- Dropping `!is_string($value)` (:217): strict_types is declared, preg_match with int 42 raises TypeError ≠ expected InvalidArgumentException → 'non-string value' row red (builder's M4 shape).

## Laws
- Author: %ae and %ce grepped separately → both detain@interserver.net; plain message, zero Co-Authored trailers.
- Not pushed: `git branch -r` contains nothing for cc/C-PRV-2.c; no remote-tracking ref.
- b08ca9605 + e8668b386 both `cat-file -e` OK.
- No new test file → no durations.tsv row owed; +4T live-enum feeds the closeout figure re-pin (weld duty, standard).

## NITs (closeout-only, NOT step-local)
1. Two name-check sub-clauses still lack dedicated rows (control-char-in-NAME; non-string array key via numeric cast). r1's leg inventory did not list them among the unpinned-but-implemented set, so the MINOR is closed as scoped; both are operator-config-side, not untrusted-input reachable. Record for ledger arithmetic honesty.
2. r1 carries confirmed still open per ruling: window-figure discrepancy (494 vs 530T/43972A) and ENVIRONMENT.md azure 'provider will reject' wording — closeout, not re-raised here.
