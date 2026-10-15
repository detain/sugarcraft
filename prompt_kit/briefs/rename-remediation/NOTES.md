# Rename remediation lane — NOTES (2026-10-15)

Base master 92ee5eaf5 (tree carried one uncommitted file: the T1 census row,
verified accurate against candy-gifview/src/Cli.php:97 + git history
d034580dc→4d6f81799, adopted and committed). NEVER pushed (operator authority).

## T1 — DescriptorSinkArgumentCensus roster row — DONE

Commit 52b0ce279 `candy-core: census roster row for candy-gifview SizeIoctl::query`.
- Row shape mirrors the closest sibling (candy-flip Renderer query(1) row):
  LITERAL_INT + judgement prose naming viewportCells(), the stream_isatty(STDOUT)
  gate, and BinSizeQueryPolarityTest (exists in candy-gifview/tests — disk-verified).
- History claims verified: site arrived via 4d6f81799 (bin split out of
  candy-flip) after d034580dc fixed the `(int) STDOUT` polarity in the old bin.
- Discrimination: WITH row → `--filter DescriptorSinkArgumentCensus` OK (20T/129A);
  WITHOUT row (file-swapped HEAD version, cp-restore after) → FAILURES naming
  exactly `candy-gifview/src/Cli.php::SizeIoctl::query(1) [LITERAL_INT]` (matches
  CI family 2 verbatim). php -l clean. No roster-count/numeral anchors exist
  (the "13 sites" hit at :1354 is a historical WHAT-USED-TO-SAY note).

## T2 — packagist.sh create via live create-package API — DONE

Commit (this NOTES' commit) `scripts: packagist.sh create via live create-package API`.
- cmd_create rewritten: `POST $API_BASE/create-package`,
  `-H "Authorization: Bearer $USERNAME:$TOKEN"`, JSON `{"repository":"https://github.com/sugarcraft/<slug>"}`.
  Header comment truth-flipped (per-endpoint auth shapes; old _update.json route REMOVED upstream).
- require_creds/mask_tokens/report/update/check byte-unchanged (update still
  query-string, CI-proven; mask still applied to all printed output).
- shellcheck -S warning clean; bash -n clean.
- Evidence harness: mock-packagist-create.sh (curl stub, fake creds only) —
  16/16 PASS: URL+method+Bearer header+content-type+flat body proven from the
  stub's argv dump; OLD route absent; token never in a URL (no apiToken= in
  create argv); 202→rc0; 401→rc1; 404→rc1; missing TOKEN or USERNAME → rc2
  with ZERO curl invocations. Run:
  `bash prompt_kit/briefs/rename-remediation/mock-packagist-create.sh`.
- plan_rename.md amended in place (2.4 precedent): the create prescription block
  re-corrected with the old `_update.json` shape struck through "(REMOVED
  upstream, HTML 404 as of the 2.5 live run 2026-10-15; kept for history)";
  R9 one-liner gains the required PACKAGIST_USERNAME. Tracker row 35 NOT touched.

## T3 — candy-top NTFS-safe fixture paths — DONE

Commit `1239d87a8` `candy-top: NTFS-safe fixture paths (colon dirs)`.
- Only 8 tracked colon paths existed (all under
  `candy-top/tests/fixtures/gpu/npu/.../intel_vpu/0000:00:0b.0/`); `git mv`'d
  the directory to `0000_00_0b.0`. `git ls-files | grep -E ':'` now empty.
- The path spelling is NOT opaque: PciCards::accel() matches sysfs BDF dirs
  with a colon-strict regex, and NpuTest pins busId '0000:00:0b.0' — so the
  literal stays in assertions/content and GpuTree::copyDir() restores `_`->`:`
  when materializing the temp root (pattern SAFE_PCI_BDF, strict hex-BDF only).
  candy-top is not in WINDOWS_LIBS, so runtime colon dirs never hit NTFS.
- No other consumer hardcodes the fixture colon path (repo grep: only
  assertSame lines + FakeGpu's string constant, both content).
- Driver-dir arm was dedupe-masked at HEAD (accel0/device duplicates the leaf
  files; full suite green even with the transform neutered). Added
  NpuTest::testDriverDirectorySlotIsFoundWithLiteralColonBdf — pins colon
  materialization + driver-only detection (rm -rf accel subtree, IntelNpu still
  finds slot). Discriminating: transform neutered -> exactly 1 failure
  (Tests: 4, Failures: 1), restore md5 ea75390e clean.
- Full suite: OK 2111T/107,871A exit0 (baseline 2110T +1 new test; A within
  +-54 wobble).

## T4 — PHPStan (CI family 4) — DONE (verdict: ALL PRE-EXISTING, nothing to fix)

CI shape reproduced exactly: `php candy-core/vendor/bin/phpstan analyse
--level=5 --no-progress -c candy-core/phpstan.neon` from repo root (candy-core
is the only phpstan.neon lib affected in 40a4919fc..92ee5eaf5 — candy-palette/
vcr/vt untouched in the range, candy-gifview has no phpstan.neon so CI never
analyses it). Result: 10 errors, 4 files:
  InputReader.php:471 smallerOrEqual.alwaysTrue
  Program.php:1234 unset.offset
  Util/AtomicJsonFile.php:309 class.notFound (ClosedResource)
  Util/Width.php:348 closure.unusedUse x5, :349 identical.alwaysTrue, :352 deadCode.unreachable
Discriminated as pre-existing by TWO methods: (1) git blame — every offending
line traces to 1df355903/e4c36bff8/3ec7bd421, all ancestors of 40a4919fc;
(2) worktree checkout of 40a4919fc + same phpstan run reproduces the SAME 10
errors at the SAME line:col. ZERO campaign-introduced findings — LegacyEnv.php
and LegacyPaths.php are clean under level 5; gifview Cli.php is outside every
phpstan matrix. Per law: recorded, not fixed, no baseline widening, no skips.
RECOMMEND minting this as a separate pre-campaign candy-core debt item (owner
call — it predates the rename era and only surfaced now because the chain
re-touched candy-core inside the diff window).

## T5 — CI families via live logs (run 38764012635, master @92ee5eaf5) — DONE

Verdict lines (evidence: `gh run view --log-failed --job <id> -R detain/sugarcraft`):

- F1 Windows checkout (11 jobs): FIXED-pending-push — log shows exactly
  `error: invalid path 'candy-top/tests/fixtures/.../intel_vpu/0000:00:0b.0/device'`
  exit 128; cured by 143b615bd (git ls-files colon sweep empty at tip).
- F2 candy-core census (Test 8.3/8.4 + Coverage): FIXED — CI failure names the
  exact site `candy-gifview/src/Cli.php::SizeIoctl::query(1)` @CensusTest:627;
  cured by 52b0ce279.
- F3 macOS candy-core (2F): PRE-EXISTING-RECORDED — f1 =
  ProgramRuntimeTeardownTest::testPendingEscapeFlushDoesNotFireAfterRun
  ("the ESC flush fired after run() returned"), the known macOS timing flake
  (rename-2.5 NOTES family 3); f2 = the F2 census site (fixed by T1).
- F4 PHPStan 8.3+8.4 candy-core: PRE-EXISTING-RECORDED — see T4 block; all 10
  errors reproduce identically at 40a4919fc; zero campaign-introduced; no
  baseline widening performed. Recommend minting a pre-campaign candy-core
  debt item.
- F5 candy-mines DifficultyStatsTest::testSaveLeavesNoTempArtifacts:
  PRE-EXISTING-RECORDED — byte-matches rename-baseline.md catalog item 1
  (180T/820A/1F + warnings); cross-reference only per brief.
- F6 candy-top BorderBatteryTest::testAppShrinksTheClockForThePresentBattery
  (140 === 52): PRE-EXISTING-RECORDED — campaign touched neither the test nor
  FrameBuilder (git log 40a4919fc..HEAD empty for both). NEW ROOT-CAUSE LEAD:
  the $budget() closure preg-requires the literal `17:13:20` spelling produced
  by `%X`; a CI locale rendering %X 12-hour makes the preg miss and
  mb_strlen fall back to the full 140-cell row — exactly the CI-vs-local
  divergence. Suggested follow-up (not taken; outside T5 remit): pin the test
  config to `%H:%M:%S` or setlocale in-test.
- F7 sugar-dash WeatherModuleTest+PersistenceTest residue pair:
  PRE-EXISTING-RECORDED — byte-matches rename-baseline.md catalog item 2
  (AtomicJsonFile temp-residue family).
- F8 sugar-crush DoctorExtensionProbesTest::testTheManifestDeclaresBothExtensionsAnd
  CarriesNoRepositoriesBlock + ReelDependencyTest::testTheManifestCarriesTheRequire
  WithoutAPathRepo: NEEDS-OWNER-RULING — both read the WORKING-TREE
  sugar-crush/composer.json, which CI's `check-path-repos.php --fix`
  deliberately injects before install (green locally, red in CI by design
  tension). Two legitimate cure shapes exist — (a) tests read the committed
  blob (`git show HEAD:sugar-crush/composer.json`), or (b) the injection step
  is scoped out of these manifests — choosing between them is a policy call
  the owner must make. Nothing disabled or skipped.
