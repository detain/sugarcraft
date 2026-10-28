# C-SES-6.b — Reproducible phar build + package manifest — LANDED

- Commit: `86185f0eb33dcc74748c94d5011eed6c3b986ac7` on `cc/C-SES-6.b` (base cf0cfd0be), worktree /home/sites/cc-cmp-C-SES-6.b. Author+committer Joe Huss <detain@interserver.net> (%ae/%ce grepped separately = 1/1), zero Co-Authored-By, never pushed.
- Files (exactly the design's modify list): `candy-code/tools/build-phar.php`, `candy-code/tests/Integration/PharCompatTest.php`. +271/−4.

## What shipped
1. **Entry-clock pinning.** When `SOURCE_DATE_EPOCH` is valid, every packaged file is `touch()`ed to it immediately before the add loop (after sort, after the integrity preflight — porcelain reads content, so the touch can never dirty the gate; pinned). Unset/malformed epoch leaves files exactly as found (pinned opposite polarity).
2. **Packages manifest.** `vendor/composer/installed.json` → `name => reference` (source ref, else dist ref, else recorded version) sealed in metadata under `packages`. Missing/unparsable → `null` + loud stderr warning, never a fabricated empty map (hatch-mode leg pins this).
3. **`--manifest-out=PATH`** writes the same map as JSON; a failed requested disclosure exits 2 (no silent-success on the reporting channel).
4. Header docblock: the CI-only touch caveat (design risk), the composer-1/2 shape handling, and the measured determinism facts are documented.

## Measured truth shaping the test design (disclosed)
On PHP 8.3.6 `Phar::addFile`/`addFromString` seal entries with mtime **0** — source mtimes reach neither the tar bytes (LE + octal-ASCII probes negative) nor the hash (mtime-skewed rebuilds hash-identical; scratch probes in /tmp/opencode/cc-cmp-lanes/C-SES-6.b/probe*.php). The design's "two same-epoch builds hash equal" pin is therefore TRUE-on-this-runtime regardless of the touch, so the arm is given real discrimination behaviorally: per-file `filemtime == epoch` after a pinned build, re-normalization after a mid-test mtime skew, and content-clean `git status --porcelain`. The hash leg stays as the byte-promise guard for runtimes that DO seal the clock. Also measured: PHP's `touch()` does not invalidate the caller's stat cache — the reads clear it explicitly (in-test comment).

## Tests (targeted only; no full suite)
- New arms RED before fix (base build-phar swap): both fail exactly as designed (unrecognized option / mtime 1793209876 ≠ epoch). GREEN after.
- `PharCompatTest|BinCandyCodeAutoloadGuardTest`: tip 52T/422A/1S (heavy arm skipped w/o CANDY_CODE_PHAR), base 50T/301A/1S → delta **+2T/+121A**; the 2 PHPUnit Deprecations are pre-existing at base (byte-count identical), not introduced here.
- Guards: `ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift|StderrEmitterCensus|AgentPresetRegistryTest|SuiteSkipRoster` 337T — only the 2 weld-owned figure-staleness failures (25,537 pinned vs 27,222 live; the +2/+121 carry is this lane's, the rest is pre-existing multi-lane drift). suite-figure.json + durations.tsv untouched per law.
- `php -l` clean on both files. No new *Test.php FILES (methods only → no durations row owed; figure re-pin carry: +2 tests, +121 assertions).

## Scope notes
- `.github/workflows/release-phar.yml` NOT touched (design's files.modify excludes it; §2 one-lane-per-workflow-file). Its lock-less-dev-master disclosure stays honest: the packages manifest now records WHICH references rode each build, but the workflow's own wording change belongs to whichever lane owns it (C-SES-6.d/e or the gate).
- No docs/guards required by the block; none touched. No en.php keys. No commits outside the worktree.
