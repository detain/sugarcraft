# WELD — C-TOOL-7.e (2026-10-28)

Lane commit 153675eb9 (cc/C-TOOL-7.e, base b985fbe51) → pick **9070bd57e** on master.
Clean cherry, zero conflicts — the in-step StderrEmitterCensusTest roster edit
(`src/Runtime.php` => 2 + FORTY-ONE→FORTY-THREE / SEVENTEEN→EIGHTEEN cardinality prose)
textually collided with nothing; the foreign NonInteractive/ArgvParser standing reds
did not touch the lane's rows.

## Findings disposition

- REVIEW-1: CLEAN 0C/0M (1 minor step-local, 2 nit).
- ACCEPTED RESIDUAL (reviewer-recommended, recorded here per weld order): a discarded
  reply's usage is unbilled if a LATER sequence in the same method throws — this mirrors
  the pre-existing shape (the dropped-attempt `$usages` already dies on throw), so it is
  not a lane regression; fold-into-exception or a one-line docblock note at the throw
  sites is a later-lane candidate.
- NITs left as-is (pre-existing deprecation family; em-dash prose cosmetic).

## Durations

No new test file (RuntimeMalformedToolArgumentsTest +4 methods ride the pre-existing
file; the census file predates the lane) → no durations.tsv row owed. 7.e touched
nothing in the TSV. suite-figure.json untouched (+4T rides the closeout re-pin,
as REVIEW-1 notes).

## Merged-tree gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS)

- `RuntimeMalformedToolArgumentsTest` → **OK 7T/49A** — exact vs REVIEW-1.
- `Retry|TransientFailure|UsageFold|LengthStop` → **OK 260T/768A** — exact vs REVIEW-1.
- `StderrEmitterCensusTest` → **95T/20687A, Failures: 3** — exactly the 3 foreign
  base-inherited shapes (NonInteractive fwrite 14→15; shapes ArgvParser 35→36 +
  NonInteractive 10→11; derived inherited arm). NO Runtime-row red: the in-step guard
  flip is correct on the merged tree.

## Absorption / cleanup

`git cherry master cc/C-TOOL-7.e` → `-`; worktree `/home/sites/cc-cmp-C-TOOL-7.e`
removed, branch `cc/C-TOOL-7.e` deleted after proof.
