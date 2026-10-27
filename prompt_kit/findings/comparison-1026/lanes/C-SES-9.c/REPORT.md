# C-SES-9.c — phar installs resolve their bundled presets inside the archive

Status: LANDED. Commit `d5b5bd078` on branch `cc/C-SES-9.c` (worktree /home/sites/cc-cmp-C-SES-9.c, base c2477fe71). Tree clean after commit. Never pushed/pulled.

## Changes (exactly the step's file set)
- `candy-code/src/Agents/AgentPresetRegistry.php`
  - `bundledPresetsDirectory()`: when `__DIR__` starts with `phar://`, return the normalized (un-realpath'd — realpath() is false for every phar path) `<archive>/.candy-code/agents` iff `is_dir()` holds; real-install branch byte-identical. Docblock truth-updated.
  - `isBundledPresetPath()`: a `phar://`-spelled candidate is compared by normalized prefix against the bundled directory; real paths keep the realpath branch. Both branches fold into ONE boundary-suffixed `str_starts_with($resolved, $bundled . '/')` on purpose — ContainedPathInventoryTest's hand-spelled census counts per-file boundary compares and pins this file at 1; the `'phar://'` literal compare is the measured non-counted URL-shape (instrument rule 3).
- `candy-code/tools/build-phar.php` (docblock only, zero behavior):
  - Deleted the "KNOWN PHAR DEGRADATION" disclosure block (the bug this step fixes).
  - Weight sentence corrected: uncompressed runtime phar "measures about 118 MiB on a real build" (published artifact measured 122,567,659 B), `--gz` pointer retained.
- `candy-code/tests/Agents/AgentPresetRegistryTest.php`:
  - New arm `testAPharInstallResolvesTheBundledPresetsInsideTheArchive` + `private const PHAR_PROBE_CHILD` (nowdoc child script). Child spawned `[PHP_BINARY, '-d', 'phar.readonly=0', script, phar, registrySrc]` (flag in argv only, mirroring PharCompatTest's LAW-3 door shape); mints a scratch phar the writePremisePhar way (setStub `__HALT_COMPILER` + addFromString + stopBuffering + unset) packing this very class file at `src/Agents/AgentPresetRegistry.php` plus `.candy-code/agents/bundled-{alpha,beta}.md`; requires the class from inside the archive (so its `__DIR__` is a phar path — no autoload needed: both private statics touch only PHP builtins + self::normalisePath); reflection-invokes both methods; echoes JSON. Parent asserts dir identity `phar://<realpath>/.candy-code/agents`, scandir listing of both presets, and prefix-match polarity (true in-archive / false real file). Child pipes drained to EOF then proc_close (pipe-EOF law); reaped on every path; proc_open-false and ext-phar-absent skips are unrostered (never fire on the gate box — git-gated family precedent; SuiteSkipRoster untouched).

## Regression proof
Pre-fix (src reverted to base via cp, md5-restored clean): arm fails exactly at `Failed asserting that null is identical to 'phar:///tmp/…/c-ses-9c-probe.phar/.candy-code/agents'` — the realpath trap. Post-fix green. No glob-shaped literals minted (scandir + str_ends_with filter; no `*`).

## Gates (all run in worktree, plain pipe, env -u LINES -u COLUMNS)
- `vendor/bin/phpunit --filter 'AgentPresetRegistry|Phar'` → `Tests: 112, Assertions: 587, PHPUnit Deprecations: 2, Skipped: 1` (skip = SuiteSkipRoster's own canary; deprecations pre-existing suite-wide).
- `vendor/bin/phpunit --filter 'ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift'` → `Tests: 133, Assertions: 15647, Failures: 2` = the allowed staleness pair ONLY (live enumeration 26,022 vs pinned 25,537 — foreign-era drift; closeout re-pin domain, figures untouched).
- `php tools/check-child-lifetimes.php` → rc=0, "33 proc_open sites, 9 findings, 9 accounted rows, 0 problems" (tests/ spawns not walked — lane-q2 precedent).

## Notes for reviewer/weld
- **SEAM (outside step file set): `tests/Integration/PharCompatTest.php` class docblock (~:32-35) still says the AgentPresetRegistry realpath trap "stays a DISCLOSURE, not a pin… Ruling: disclose, do not edit" — that sentence is FALSE after this commit. Needs a one-paragraph truth-flip (or removal) at weld; PharCompatTest is not in any C-SES-9.c-owned file list, so I did not edit it.**
- Pre-existing foreign red on base at this commit: `ContainedPathInventoryTest::testTheHandSpelledInventoryIncludingItsStatedExclusion` fails on `Host/Commands/ContextHostCommand.php`/`Host/TranscriptProjector.php` (unrostered) and `Tools/PathJail.php` 5→6 — NOT my file; my AgentPresetRegistry row stays at 1 (verified by the diff: absent from the delta). Whoever owns those lanes owes the roster flip.
- No new test FILES → no durations.tsv row; suite-figure.json untouched; nothing generated touched.
- Author verified: `%ae`=detain@interserver.net, `%ce`=detain@interserver.net (separate greps), zero Co-Authored-By.
