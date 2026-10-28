# WELD — C-TOOL-7.d (2026-10-28)

Lane commit 674e54bc0 (cc/C-TOOL-7.d, base b985fbe51) → pick **15ec4b791** on master.
Clean cherry, zero conflicts (NonInteractive.php/Subcommands-adjacent surface untouched
by the sibling welds since the lane base). No weld cures — step lands as reviewed.

## Findings disposition

- REVIEW-1: CLEAN, 1 NIT.
- NIT SKIPPED BY DECISION (recorded here per weld order):
  `NonInteractive.php` ~:1506 — the `(string)` cast on `preg_replace_callback`'s return
  maps a hypothetical PCRE-internal null (backtrack-limit exhaustion, ~10^6 Cf chars in
  one document) to empty string rather than throwing. Practically unreachable: the sweep
  class `[\x{7F}-\x{9F}\p{Cf}]` is single-char with a linear scan, and the file's
  failure polarity elsewhere is throw-not-cast. A `?? throw new \JsonException(...)`
  would match the `$json === false` arm's house discipline — candidate for a later
  hardening lane, not weld-blocking.

## Durations row (weld duty)

`candy-code/tests/Cli/EncodeDocumentEscapingTest.php	0.003	4` (junit per-testcase sum
at pick, single-file run; set-equality 1468+1=1469, body duration-desc invariant held,
tie group path-asc between Cli/Bypass… and Cli/NonInteractiveMentions…).
suite-figure.json untouched.

## Merged-tree gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS)

- `NonInteractive|StreamJson|EncodeDocumentEscapingTest` → **OK 165T/812A** — exact vs REVIEW-1.
- `BinCandyCodeAutoloadGuard|ReadmeJsonErrorContractDrift` → **OK 57T/550A** — exact vs REVIEW-1.

## Absorption / cleanup

`git cherry master cc/C-TOOL-7.d` → `-`; worktree `/home/sites/cc-cmp-C-TOOL-7.d`
removed, branch `cc/C-TOOL-7.d` deleted after proof.
