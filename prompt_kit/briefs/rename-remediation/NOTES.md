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
