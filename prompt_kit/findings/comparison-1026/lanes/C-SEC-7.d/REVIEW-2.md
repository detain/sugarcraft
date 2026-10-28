# C-SEC-7.d — REVIEW-2 (independent r2 cure reviewer, 2026-10-28)

Verdict: **CLEAN** — 0 CRITICAL / 0 MAJOR / 0 MINOR. Both r1 MINORs cured in the
amend; no new findings; step loop exit condition (0C/0M, step-local MINORs fixed)
met. Ready to weld.

## State verified
- Branch `cc/C-SEC-7.d` tip `75beffe3ec81a1b3859e378a7915cda221885c58`, ONE commit
  (`git rev-list master..HEAD` = 1) on base `e4a705244`; tree porcelain-clean.
- Numstat 14 files +740/−104. Amend delta vs r1's `59fa0d4ec` is EXACTLY the two
  cures: README.md +37/−28 (was 36/28), docs/PERMISSIONS.md +13/−3 (was 11/3),
  tests/Permissions/AutoReviewScopeTest.php +298/−0 (was 293/0). src byte-identical
  between the two tips (no src entry in the amend diff).
- Author law: %ae and %ce separately greped = `detain@interserver.net`; commit
  body is subject-only, zero trailers.

## Phantom-fix check
Both cure tokens present in the tip blobs via `git show 75beffe3e:<file>` greps:
- `capped pending diff for the write tools` + `(WebFetch has no diff, so it rides as
  its url plus arguments)` — PERMISSIONS.md:561-563 and README.md:1559-561.
- `assertSame([], ...tools... ReflectionProperty...)` — AutoReviewScopeTest.php:172-177
  inside `testTheModelOverrideBuildsItsOwnToollessBackend`.

## MINOR-1 cure — truth (PASS)
Cross-checked against src at tip:
- `AskDiffPreview::for()` match arms cover ONLY `'Edit'`, `'Write'`, `ApplyPatch::NAME`
  (default → null) — WebFetch genuinely has no diff. ✓
- `ExecReviewer::renderCall()`: non-shell calls get a head (`file_path`→`path` else
  `url`→`url`), then `$diff === null ? head + "arguments: …" : head + "diff: …"` —
  WebFetch rides url head + raw arguments, exactly as the new sentence says. ✓
- The write-tool diff is capped: `AskDiffPreview::MAX_DIFF_BYTES = 32768`, applied in
  `for()` via `self::cap($preview)` — "capped pending diff for the write tools" true. ✓
- Wording mirrors `settings.autoReviewScope.help` (en.php:3308) qualifier. ✓
- No guard pinned the old sentence: greps of DocFigureProseDriftTest,
  ReadmeRosterDriftTest and the whole tests/ tree for "pending diff" / "capped
  pending diff" / "has no diff" → zero arms (only the unrelated ExecReviewerTest:35
  docblock prose). Confirmed empirically: the doc-guard superset runs 237T rc0 at tip
  (see gates). No in-step guard law broken by the reword.

## MINOR-2 cure — non-vacuity (PASS, by code derivation)
- The override backend is built at Bootstrap.php:7137
  (`$reviewBackend = new EngineBackend($factory->create($config), $overrideModel, hooksDisabled: true)`)
  — the third positional `$tools` parameter is NOT passed; the ctor
  (EngineBackend.php:421-424) declares `private readonly array $tools = []`.
- The new assert cannot pass vacuously: (a) the preceding `assertInstanceOf(EngineBackend, …)`
  proves the override path actually built the backend (not null/off); (b) `tools` is a
  real typed private-readonly property, so the ReflectionProperty read either returns
  the genuine array or throws (test error, never a silent pass); (c) the method-name
  leg (`assertSame('probe-model-7d', model)`) pins that THIS user-chosen-model instance
  is the one inspected — a future change that keeps the model but hands the reviewer a
  tool-carrying backend reddens exactly the new line 172-177. This is precisely the
  audit-15b-12 law r1 said was riding un-pinned.
- The fixer's mutation claim (carry `['ghost']` through :7137 → 1F, rest green) is the
  mechanically necessary outcome of the above. Execution note: my own reviewer
  permission map refused the /tmp cp-harness (`cp`, `python3`, heredocs all denied), so
  the discrimination proof here is structural code-derivation, not a re-run mutation —
  the derivation is exact for this single assertion.

## Gates (cwd worktree candy-code, plain pipe, env -u LINES -u COLUMNS)
- `--filter 'AutoReviewScopeTest|UnflaggedShellReviewTest|ExecReviewerTest'` →
  **OK 43T/136A** rc0 — exactly the brief's +1A-over-r1-by-design figure.
- `--filter 'AutoReviewScope|UnflaggedShell|ExecReviewer'` (loose form) →
  44T/142A rc0; the extra 1T is `BinCandyCodeWiringTest::testNoRootResolvingSite…`
  data set "Permissions/ExecReviewer.php" matching the substring — filter artifact,
  green, not lane dirt. (PtyRunToolTest never in any filter.)
- `StderrEmitterCensusTest` → **95T/21412A** rc0 EXACT — census roster still agrees
  with the amended tree (in-step 40→43/8→11/FORTY-THREE survives the cure amend).
- doc-guard superset `TrustKey|DocFigure|ReadmeRoster|EnvRoster|SettingsSchema|CliLang|LangParity`
  → **237T rc0** (48953A) — MINOR-1 reword broke no arm; the word-number/tier/schema
  guards all agree.
- `SettingsSchemaDocDriftTest` → **19T/183A** rc0 (phpunit-equivalent of the
  gen-settings-doc --check duty; reviewer map refuses bare `php`).
- "PHPUnit Deprecations: 2" appears on every run incl. untouched classes — the
  process-wide pre-existing pair r1 recorded; rc0 preserved.

## EXCLUDED foreign standing reds (recorded, NOT attributed to this lane)
- `ProjectTierRefusalInventoryTest` (run explicitly): 15T/2F at tip. Both arms are
  foreign src drift — failure 1 accuses Bundles/Marketplace.php,
  MarketplaceRegistry.php, Format/FormatAutoTable.php, LSP/LspInstaller.php,
  Memory/AgentMemory.php, Support/MacAltNotice.php, Tools/BuiltIn/ScheduleTool.php
  dot-paths (none in the lane's 14-file set); failure 2 is the hardcoded
  `assertCount(48, …distinct dot-DIRECTORY paths)` vs live 55. Proven lane-immune:
  `git diff e4a705244 75beffe3e -- src | grep '^+' | grep '"\.<dir>/'` → zero added
  dot-path literals, and the amend touched no src file, so the lane cannot move the
  count. Note for the record: the reviewer brief's "FIFTY-SEVEN at Bootstrap.php:2286"
  resolves to the guard's word-map (ProjectTierRefusalInventoryTest.php:690); no
  FIFTY-SEVEN exists in Bootstrap.php at tip — the Bootstrap :2283-2286 enumeration
  (TWENTY-FOUR/FORTY-EIGHT) is byte-untouched by the lane, consistent with r1.
- `ReadmeSuiteFigureDriftTest` staleness pair and
  `PermissionsIntroDriftTest::testThePatternToolRosterIsTheWholeCatalog` — on the
  brief's exclusion list; r1 independently proved the Schedule-row red inherited
  (zero-line diffs in ToolCatalog.php and the roster paragraph).

## Hygiene / duties
- No `suite-figure.json`, no `scripts/parallel-tests-durations.tsv`, no
  `candy-code-web/dist/`, no generated protocol files in the commit. ✓
- DURATIONS duty: `git diff --name-status` → exactly ONE `A` file,
  `candy-code/tests/Permissions/AutoReviewScopeTest.php` → weld adds one TSV row.
  ExecReviewerTest (+102/−0) and UnflaggedShellReviewTest (+23/−0) are appends to
  existing files, not new rows.
- E646: reviewer call keeps connect-timeout-only posture (doc sentence re-affirms;
  no timer introduced anywhere in the diff). §7.e boundary held: whole reviewer still
  gates on `autoReview === true`.

## Verdict
CLEAN. r1's two step-local MINORs are cured, cure tokens are on disk, gates reproduce
with the claimed figures, and the remaining reds are the pre-declared foreign
standing set. Recommend: weld W16 C-SEC-7.d at 75beffe3e (cherry-pick, then
durations row + SETTINGS/generated-region duties belong to the weld per §2).
