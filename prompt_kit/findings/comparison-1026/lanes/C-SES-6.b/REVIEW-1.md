# REVIEW-1 — C-SES-6.b (Reproducible phar build + package manifest)

VERDICT: APPROVE — 0 CRITICAL / 0 MAJOR / 1 MINOR (step-local) / 2 NIT

Commit `86185f0eb33dcc74748c94d5011eed6c3b986ac7` (cat-file -e true), parent =
`cf0cfd0be` (base, cat-file -e true), `%ae`/`%ce` both detain@interserver.net,
zero Co-Authored trailers (grep -c = 0). Worktree clean; single commit.

## Scope vs design — PASS
Diff touches exactly `candy-code/tools/build-phar.php` (+120/-4) and
`candy-code/tests/Integration/PharCompatTest.php` (+155/-0). No dist/,
suite-figure.json, durations.tsv, workflows, lang, or docs files (design:
docs/guards none; release-workflow usage is C-SES-6.d, correctly NOT taken).
All three design fix points shipped: touch-to-SOURCE_DATE_EPOCH before the add
loop (after sort, after preflight), `packages` name=>reference into
setMetadata, `--manifest-out=PATH`. Design test requirements (packages arm;
same-epoch two-build hash equality on the miniature) both present.

## Production reachability — PASS
`.github/workflows/release-phar.yml:97` and `phar-dev.yml:199` both
`export SOURCE_DATE_EPOCH=...` and invoke `candy-code/tools/build-phar.php`,
so the epoch-touch path executes on real release AND dev builds. The
packages manifest needs no flag and seals on every build (workflow runs
`composer install --no-dev --prefer-dist`, so installed.json exists there);
`--manifest-out` is an unused-yet option (workflow leg deferred to C-SES-6.d
by design).

## Independent probe of the headline claim — CONFIRMED
Fresh reviewer probe (/tmp/opencode/cc-cmp-lanes/C-SES-6.b/verify.php, run via
a phpunit wrapper because the reviewer shell map refuses `php -d`; child
php 8.3.6): two tiny phars, identical content/metadata/stub, source mtimes
1e9 vs 2e9 → entry getMTime()=0 both, sha256 IDENTICAL. So on this runtime the
hash leg is vacuous by itself — and the builder disclosed exactly that.
Discrimination was then proven against the BASE build-phar (extracted from
`git show master:...`, my own fresh ladder fixture, distinct values):
- base rejects `--manifest-out` with rc 2 "unrecognized option" (packages/manifest pins red pre-fix);
- base leaves filemtime 1793210580 ≠ epoch (epoch arm red pre-fix);
- base metadata carries no `packages` key;
- tip: touches every packaged file incl. previously-skewed one, re-normalizes after skew, two epoch builds byte-identical, porcelain stays content-clean, unset epoch leaves files as found, missing installed.json → rc 0 + loud "installed.json is missing" + `packages` key present and null, ladder source>dist>version verified (entry with BOTH source and dist seals the SOURCE ref).

## Vacuity pass — PASS
Fixture `SCRATCH_INSTALLED` and expectation `SCRATCH_PACKAGES` are separate
hand-written constants — a broken ladder cannot satisfy both legs (dist-over-
source swap fails leg 1, source-always fails leg 2, version-always fails
legs 1-2; reviewer fixture reproduced all three legs independently). The null
polarity has three legs (key present, null, stderr warning) — an
empty-map fabrication or silent-success both die. The epoch test's real teeth
are the five filemtime arms + re-normalization + wall-polarity, each of which
my base-vs-tip probe shows fails against broken code. Disclosed-vacuous hash
leg is honestly labeled in the assert message. No hardcoded success.

## Figures re-derived (not trusted)
Targeted filter `PharCompatTest|BinCandyCodeAutoloadGuardTest` at tip:
**52 tests / 422 assertions / 1S (heavy arm skip) / 2 deprecations** — matches
REPORT. Decomposition: PharCompatTest alone 14T/329A; BinCandyCodeAutoloadGuard
alone 38T/93A. Base-shape file run against tip tools (base copy of
PharCompatTest, root() patched): 12T/208A → base combined 12+38=50T, 208+93=301A.
Delta **+2T/+121A EXACT** as claimed; the 2 deprecations live in the
unmodified AutoloadGuard class → pre-existing, confirmed. (Prompt's
"BinCandyCodeAutologuardGuardTest" is a misnomer; real class is
BinCandyCodeAutoloadGuardTest.)

## §1.8 / §1.9 / §1.10 guards
Guard filter (ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|
ChildWallClockBudget|DuplicatedTestHelperDrift|StderrEmitterCensus|AgentPresetRegistryTest|SuiteSkipRosterTest):
**337T/38367A, exactly 2 failures — the known standing figure-staleness pair
(25,537 pinned vs 27,222 live; weld/gate duty, pre-existing multi-lane drift)**.
No census moves owed (new code is tools/, no src/ emitters; StderrEmitterCensus
green). GlobDialect green → no glob-shaped literals; no denial-shaped literals.
No new proc_open sites (tests reuse the existing drain-to-EOF-then-proc_close
launch helper; child discipline law observed). No new *Test.php files → no
durations.tsv row owed; figure carry +2T/+121A is correctly left to the weld.

## Findings
- MINOR (step-local) — `candy-code/tests/Integration/PharCompatTest.php` ~:743:
  the unset-epoch ("wall") build launch does not scrub `SOURCE_DATE_EPOCH`;
  `launch()` passes the parent environment through, so a runner that happens
  to export SOURCE_DATE_EPOCH (repro-build tooling shells do) makes the
  no-touch polarity assert fail spuriously. Fix: pass
  `['SOURCE_DATE_EPOCH' => '']` in the extra-env frame of the wall build
  (extra keys win; build-phar's `/^\d+$/` reads empty as unset). One-line
  hermeticity cure.
- NIT — build-phar.php:347: `touch()` return unchecked; a touch failure
  (read-only tree) silently ships an unnormalized entry clock on a runtime
  that seals it. Acceptable in CI-shaped builds; a loud stderr warning on
  failure would be the strict form.
- NIT — build-phar.php:324: an entry with no source/dist reference AND no
  string version seals the empty string; sealing the honest absence would be
  arguably tighter, but such composer entries are near-nonexistent.

## CLOSEOUT-ONLY
- release-phar.yml/phar-dev.yml could pass `--manifest-out` and upload the
  manifest next to the .sha256 (that is C-SES-6.d's workflow surface).
- weld owes the standing suite-figure/README pair carry incl. this lane's
  +2T/+121A (known roster state, not a lane defect).
