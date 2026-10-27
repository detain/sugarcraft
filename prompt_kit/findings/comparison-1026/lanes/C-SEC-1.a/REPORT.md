# C-SEC-1.a — Bundle install never follows symlinks

**Verdict: DONE** — commit `ce0d57b43` on `cc/C-SEC-1.a` (base `ff10efc43`), author+committer `detain@interserver.net` (verified `%ae`/`%ce` separately), zero Co-Authored trailers, tree clean, NOT pushed.

## What shipped (3 files, +237/−32)

- **`candy-code/src/Cli/Subcommands.php`**
  - `removeTree()` (PLG-1-P1): top-level `is_link($path)` → `@unlink` + return, BEFORE the `is_dir` door, and a child-level `is_link` arm in the loop — a link is unlinked as a link and never descended. Pre-fix, `is_dir()` followed the link and the walk emptied whatever it pointed at (a scratch holding `x -> ../../..` deleted $HOME on every failure exit).
  - `bundlesCloneDefault()` (PLG-1): argv is now `git -c protocol.ext.allow=never -c core.symlinks=false clone …` — links check out as plain working-tree files, so the scratch never holds a live link. Docblock names both belts (INT-M1 leg + new PLG-1 paragraph).
  - `copyBundleTree()` (PLG-1-P3): signature `bool` → `array{ok: bool, link: string}`; refuses at the FIRST `is_link($src)` met, naming it. mkdir/scandir/copy failures keep `link: ''` so callers keep the ordinary `read_failed` wording. Docblock cites why (is_dir/copy follow; installed bundles are loaded as configuration; loaders already refuse escaping links — refusal moves to the door and ends the cyclic loop by construction).
  - Both `bundlesInstall` call sites (path-source + cross-device copy-fallback) handle the array shape and emit the new message when a link is named.
- **`candy-code/lang/en.php`**: new key `cli.bundles.install.link_refused` (placeholders {source}+{link}), inserted alphabetically in the `cli.bundles.install.` block. candy-code ships en.php only — no other locales owed.
- **`candy-code/tests/Bundles/BundleInstallTest.php`**: 4 new test methods + 1 renamed argv pin (+2 re-pinned helpers), no new test FILES. See pins below.

## Regression proof (fails before the fix)

Pre-fix run of exactly the new tests: **Tests: 6, Failures: 6** (3 sentinel provider cases — canary deleted through the followed link; argv pin — second belt absent; path-source — install succeeded, EXIT 0 vs 1; copy refusal — bool vs array). Post-fix: **17/17 OK, 51 assertions** (`--filter BundleInstallTest`, includes all 12 pre-existing methods unchanged).

- `testAFailureExitNeverFollowsAScratchLinkOutsideTheRoot` × {no manifest, invalid name, existing name} — seam clone plants `scratch/link -> <outside sentinel dir>`; each failure exit must leave the sentinel byte-intact (canary + nested file) and the scratch gone.
- `testACyclicScratchLinkTerminatesInsteadOfRecursingForever` — `scratch/loop -> scratch`; failure + empty root. **Disclosure: green pre-fix too** — Linux MAXSYMLINKS=40 makes the 41-deep `is_dir` answer ELOOP/false and the following `unlink` deletes the link itself, so the kernel accidentally terminated the old recursion. This is a termination pin (locks the shape in for future walker changes), not a discriminator; the discriminators are the sentinel/copy tests.
- `testAPathSourceHoldingAnOutsideLinkIsRefusedAndNothingLeaks` + `testTheCopyRefusalNamesTheFirstLinkItMeets` (reflection-invokes the private static; two dangling links `aaa-first`/`zzz-second` prove it names the FIRST, i.e. scandir order, and refuses rather than copies through).
- `testTheDefaultCloneArgvPinsBothBeltsAheadOfTheCloneToken` (renamed): source-slice substring pin of `'git', '-c', 'protocol.ext.allow=never', '-c', 'core.symlinks=false', 'clone'` (both spellings, sum==1) — moved in the SAME commit as the argv (in-step guard law).

## Mutation matrix (cp backup + md5 restore, all clean)

| Mutation | Result |
|---|---|
| removeTree child `is_link` off only | green — top guard carries |
| removeTree top `is_link` off only | green — child guard carries |
| removeTree BOTH off (= true pre-fix) | **3/3 red** ✓ |
| copyBundleTree `is_link` arm dropped | **2/2 red** (path-source + copy-name) ✓ |

The two removeTree guards are each sufficient alone (belt-inside-belt); the test discriminates the pre-fix pair.

## Guards run (all green)

- `ReadPathCensusTest` 19T — no new censused sinks (`is_link` not a FUNCTION_SINK; scandir roster rows unchanged).
- `LangParityTest|CliLangCatalogueTest` 15T — new key resolves, catalogue agrees.
- `StderrEmitterCensusTest` 95T — bundlesLine call sites became ternary-in-one-call, no new emitter.
- `DuplicatedTestHelperDriftTest` 14T, `TreeWideGuardRosterTest` 17T — clean.
- Expected-staleness (merge-owned, NOT touched): `ReadmeSuiteFigureDriftTest` pair red, live enum **25,543 vs pinned 25,537 (+6)** — the +6 is 4 new methods with one DataProvider×3.
- Note: every phpunit run in this lib carries a pre-existing global "PHPUnit Deprecations: 2" from the bootstrap (reproduced on untouched base tests, e.g. the TUI tab-cycle test) — unrelated to this lane.

## Deviations / notes for weld

- Risk note honored: legit in-tree relative symlinks now arrive as plain files from a clone (`core.symlinks=false`) and a path source carrying ANY link is refused — stricter than before, disclosed in the refusal wording itself.
- No spawn added (child-lifetime roster untouched); no doc pages required by the step block; lang prefix `cli.bundles.install.` as serialized.
- `suite-figure.json` / `durations.tsv` untouched per law; no new test files, so no durations row owed.
## Fix round 2

Round-1 verdict FINDINGS (0C/0M/1 MINOR step-local/2 NIT), dispositioned:

- [MINOR step-local] FIXED — `BundleInstallTest.php:297-298` docblock truth-flip: pre-fix termination rode the kernel ELOOP ceiling (pin green pre-fix, as disclosed), not a crash; the refusal now unlinks the link as a link and never descends. Docblock-only, code semantics unchanged.
- [NIT] FIXED — line 13 above corrected to "4 new test methods + 1 renamed argv pin" (reviewer item 5 is the authority; line 41 was already correct).
- [NIT] DECLINED per round-2 brief — source-slice line coupling at :194 was reviewer-accepted as house pattern; no edit.

Fix commit `7ab00c983` on `cc/C-SEC-1.a` (tip), author+committer `detain@interserver.net` verified separately, zero trailers, tree clean, NOT pushed. Gate: `--filter BundleInstall` 17/17 OK, 51 assertions (only the disclosed pre-existing 2 PHPUnit deprecations).
