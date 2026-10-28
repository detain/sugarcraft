# REVIEW-2 — lane C-TOOL-5.c (LSP installer core) @ abdc611d3 (amended; base 4b771634d)

VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 MINOR / 0 NIT. Round-1 findings both genuinely cured.

## Phantom-fix check (first) — PASS with attribution note
- `@unlink($staged);` present in the chmod arm (LspInstaller.php:353) before the return — NIT-1 landed in the blob.
- `testTheChosenExpressionResolvesTheInstallTreeAheadOfThePathProbe` EXISTS — but in tests/LSP/LspLauncherAutoDetectTest.php:356, not LspInstallerTest. FIX-1.md names the correct file; the brief's ":305 genre" is a genre citation, not a location claim. Blob-verified at tip.

## Scope — PASS
- Full diff = same 8 files REVIEW-1 scoped (the brief's "11-file" is an orchestrator miscount; r1's authoritative scope line is 8). Amendment delta (08e4d7d6a→abdc611d3) = +2 LspInstaller / +24 InstallerTest / +21 AutoDetectTest / +8 REPORT — no new files, no drift outside declared set.
- Single commit; %ae and %ce separately = detain@interserver.net; empty body; zero trailers; porcelain 0 after gates.

## Pin honesty — PASS
- Launcher pin slices the ACTUAL precedence expression: fail-closed assertIsInt on both anchors (`installedBinaryPath(...)` binding; `self::capabilityPresent` in-window) then offset-asserts `$installed ??` precedes the probe. Re-verified M1 analytically: the `capabilityPresent ? $command : ($installed ?? '')` reorder keeps both anchors but flips offsets → exactly the new pin reddens; the behavioral trio stays green (planted names absent from PATH) — the vacuity is genuinely closed. Alternative reorders that drop `$installed ??` or move the probe before the anchor hit the fail-closed legs. Text-order-only is the disclosed limit; a behavioral seam (capabilityPresent $pathList threading) would be wider surface — r1 endorsed the structural option, house genre precedent at :305, accepted.
- Chmod pin slices BOTH arms to first `}`; unlink-before-return asserted with fail-closed legs. M2 re-verified: deleting the chmod unlink → exactly 1F (branch not behaviorally reachable hermetically — disclosed in test comment, honest).

## Gates — reproduced exact (cwd=candy-code, plain pipe, -u LINES -u COLUMNS; PtyRun never)
- --filter '(LSP|ReadPathCensus)': OK 212T/1527A, PHPUnit Deprecations: 2 = the foreign AccessorDeprecationTest pair. Matches FIX-1 claim.

## Weld roster (confirmations)
- ReadPathCensus 2 OWNED_HOME fopen rows + TreeWideGuardRoster scandir row rode the commit in-step; no generated/suite-figure/durations files in diff.
- DURATIONS row owed by weld: LspInstallerTest is now 20 test methods (r1's 19T is stale — chmod pin added); AutoDetectTest 21T. Net suite-figure carry +2T vs r1 window (210→212 confirmed live).
- lsp-install doc rows ride C-TOOL-5.d per design block (docs: none in-lane) — closeout collects.
