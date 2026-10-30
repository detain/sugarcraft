# C-SES-9.c — FIX-1 (round-1 review items)

Verdict: **MINOR already landed at branch tip (no edit needed); NIT rejected — measurement shows it would INTRODUCE a defect, current code is correct; the adjudication is pinned as a WHY comment.**

## Branch state (disk-anchored, differs from brief)

- Brief said tip d5b5bd078 / base efb97a1b5. On disk: `cc/C-SES-9.c` == `efb97a1b5`
  ("weld-18g: batch log closed out"), tree clean, reflog shows the branch was
  re-cut from efb97a1b5 (single entry `branch: Created from efb97a1b5`).
- The step's ORIGINAL lane commit d5b5bd078 (parent c2477fe71, pre-weld master)
  is NOT on the current branch and is not an ancestor of efb97a1b5.
- The step's CONTENT reached master via `c8469c77d` — "weld: C-SES-9.c cure —
  the realpath trap is fixed, not disclosed" — proven ancestor of efb97a1b5
  (`git merge-base --is-ancestor c8469c77d efb97a1b5` → true).

## Finding 1 (MINOR): PharCompatTest.php:32-35 stale disclosure paragraph

**Status at this tip: ALREADY FIXED by the weld cure c8469c77d.** The file at
efb97a1b5:32-35 reads verbatim:

> The realpath trap was a disclosure until C-SES-9.c fixed it:
> bundledPresetsDirectory() now answers the is_dir-proofed phar:// path
> (Ruling superseded — see lanes/C-SES-9.c). Home/project preset roots are
> real paths and were never affected.

— i.e. exactly the reviewer's proposed truth-flip (the review ran against the
stale pre-weld commit d5b5bd078, where the old "stays a DISCLOSURE … Ruling:
disclose, do not edit" text still sits; `git show d5b5bd078:candy-code/tests/Integration/PharCompatTest.php`
:32-35 confirms). Repo-wide grep for `disclose, do not edit|stays a DISCLOSURE`
over candy-code/*.php: zero hits. No edit made — none is possible.

## Finding 2 (NIT): parent asserts `'phar://'.realpath($pharPath)` vs child raw argv

**REJECTED with evidence — the suggestion is backwards.** Measured on this box
(harness probe /tmp/opencode/ses9c-nit/, re-run of the child's exact mint+require
sequence through a symlinked directory: `link -> real`, argv path `…/link/probe.phar`):

    {"dir":"phar:///tmp/opencode/ses9c-nit/real/probe.phar/.candy-code/agents", …}

The phar stream wrapper resolves the archive path at construction/require time,
so the child's `__DIR__` (and hence `bundledPresetsDirectory()`) carries the
**realpath** of the archive, not the raw argv spelling. Swapping the parent
expectation to raw `$pharPath` would false-red exactly in the TMPDIR-symlink
scenario the NIT was raised for (macOS `/tmp → /private/tmp`); the current
`realpath($pharPath)` is the TMPDIR-symlink-SAFE form. The assertion message
("must name the directory INSIDE the archive, un-realpathed") refers to the
directory's phar-side tail never being realpath()'d (realpath is false for
`phar://…` — that is the retired trap), not to the archive-path spelling.

Fix shipped instead: a WHY comment at the assertion recording the measured
wrapper-resolution law so a future pass does not "fix" it the wrong way.

## Change

- candy-code/tests/Agents/AgentPresetRegistryTest.php — +5 comment lines above
  the `phar://' . realpath($pharPath)` assertion in
  testAPharInstallResolvesTheBundledPresetsInsideTheArchive (comment-only, +0T/+0A,
  suite-figure/durations/GlobDialect unaffected).

## Gate

    cwd=candy-code vendor/bin/phpunit -c phpunit.xml env -u LINES -u COLUMNS --filter 'PharCompat|AgentPresetRegistry'
    → OK, but there were issues!  Tests: 99, Assertions: 590, Skipped: 1, PHPUnit Deprecations: 2
    (comment-only change; deprecations are the pre-existing suite shape; 1 skip = SuiteSkipRoster)

vendor: worktree refresh-deps --mode=linked --libs=candy-code segfaults (rc=139,
composer SEGV, twice); gate run against a `cp -a` of the main-tree candy-code/vendor
whose autoload_psr4 resolves to THIS worktree's src/tests (verified before running).

## Commit

44946bef0d11c283e46c44cbdfa280edf0d03147 — `C-SES-9.c: review fixes (round 1)` on
cc/C-SES-9.c (tip verified by `git log -1 --format=%H`), %ae and %ce each
detain@interserver.net, zero Co-Authored trailers, tree clean after commit.
