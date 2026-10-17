# Step 2.5 — Create candy-gifview remotely (2026-10-15)

Base verified: master @ cc04715d0, tree clean. Local was 34 ahead / 0 behind origin before push.

1. **Repo created:** `sugarcraft/candy-gifview` (public) via `gh repo create` with
   `--description "$(bash scripts/bootstrap-org-repos.sh --print-description candy-gifview)"`
   (printed: "Terminal GIF + .ansi-art viewer — port of gifterm.") and the plan's homepage.
   The batch script has no single-repo mode (its create loop iterates the whole DESCRIPTIONS
   map, which would create other slugs — forbidden), so the explicit gh command was used,
   plus the script's exact topics + repo-feature API calls replicated for sibling uniformity.
2. **Push:** `git pull --rebase origin master` (clean, up to date) → `git push origin master`
   40a4919fc..cc04715d0. No conflicts.
3. **Sync run 38762956857** (sync-sugarcraft.yml, push-triggered) — watched with
   `gh run watch --exit-status`: conclusion `success`. Log:
   `✓ synced candy-gifview (014f80291f4122fb81bb043b69906601d5ac4219)` and
   `↳ packagist update candy-gifview → HTTP 404` (expected: package not yet registered;
   the workflow treats it non-fatal by design). `git ls-remote
   https://github.com/sugarcraft/candy-gifview refs/heads/master` = 014f80291... (non-empty,
   matches the split SHA). Continuity N/A (new repo, new root — 2.1 findings).
4. **Default branch:** already `master` — pushing master to an empty repo sets the default;
   verified via `gh repo view` (`master https://github.com/sugarcraft/candy-gifview`).
   `set-org-default-master.sh` is a batch script whose LIBS list excludes candy-gifview,
   so no edit was needed or run.
5. **Packagist:** registered `sugarcraft/candy-gifview`; `scripts/packagist.sh check
   candy-gifview` → dev-master present, source.reference
   `014f80291f4122fb81bb043b69906601d5ac4219` (== synced SHA) on first attempt.

   **DEVIATION (important):** `scripts/packagist.sh create` failed — Packagist has REMOVED
   the `api/packages/_update.json` maintainer route upstream (HTML 404, route gone; step
   2.4 never exercised `create` live, only `check`). Registration was completed with the
   currently documented endpoint instead:
   `POST https://packagist.org/api/create-package` body `{"repository":"https://github.com/sugarcraft/candy-gifview"}`
   auth `Authorization: Bearer detain:<MAIN token>` → HTTP 202 `{"status":"success"}`.
   The plan's "known /api/create-package 401 quirk" is resolved by the Bearer header form
   (token never rides the URL). **Follow-up owed: patch scripts/packagist.sh cmd_create to
   the create-package + Bearer shape** before Batch steps rely on it. Not done here because
   this step's commit scope is tools/renames.json + plan_rename.md only.
   PACKAGIST_USERNAME: the credentials file has no packagist username line (only the token
   after `packagist.org:`); account `detain` taken from the CI-proven hardcode in
   sync-sugarcraft.yml (`PACKAGIST_USERNAME: detain`). No token written anywhere.
6. **Abandon checklist:** `prompt_kit/briefs/rename-packagist-abandon-checklist.md` does not
   exist → skipped per instruction (create-kind entries never abandon).
7. **Commits:** 26edcb18c `rename: candy-gifview published` (renames.json status →
   packagist-done, JSON validated), 92ee5eaf5 tracker row 2.5 → ✅ 26edcb18c. Both author
   and committer Joe Huss <detain@interserver.net> (verified %ae/%ce grep -c detain = 1
   each), no Co-Authored-By, no caliber run, no pre-commit hook present. Both pushed.
8. **CI:** push kicked CI run 38762956851 (+ VHS/site runs); watched — see verdict below.

## CORRECTION + CI INVESTIGATION (appended same session)
Line 47 above ("watched — see verdict") was WRONG: CI run 38762956851 (push of
cc04715d0) concluded FAILURE — the watch-green claim had actually been the SYNC run
38762956857. CI run 38764012635 (push of 92ee5eaf5) failed identically. Diff between
the two pushes = tools/renames.json status line + plan tracker line only, so EVERY red
reproduces at cc04715d0, i.e. pre-exists this step's own commits — they are latent
defects in the 34-commit chain being CI'd for the first time, plus one infra family:

1. Windows x10 jobs — FAIL AT CHECKOUT (step "Run actions/checkout"): NTFS rejects
   candy-top/tests/fixtures/gpu/npu/sys/bus/pci/drivers/intel_vpu/0000:00:0b.0/device
   ("invalid path", exit 128 — colon in PCI address). Known family (docs-rework
   pre-existing dirt note). Fix = rename fixture dirs (chr-based) or core.checkout branch.
2. candy-core Test 8.3/8.4/Coverage — DescriptorSinkArgumentCensusTest:: <!-- @rename-keep historical provenance -->
   testEverySiteThatConsumesADescriptorIsJudgedInTheRoster red: 'candy-gifview/src/
   Cli.php::SizeIoctl::query(1)' [LITERAL_INT] missing from ROSTER — introduced by step
   1.3 split (commit 4d6f81799), REPRODUCED LOCALLY deterministic (1F). candy-flip/ <!-- @rename-keep -->
   Renderer.php keeps its own 2 query(1) sites; its roster rows stay. Fix = add one
   judged roster row in candy-core/tests. NOT committed here (outside 2.5 path scope). <!-- @rename-keep historical provenance -->
3. candy-core macOS — same census red + ProgramRuntimeTeardownTest:: <!-- @rename-keep historical provenance -->
   testPendingEscapeFlushDoesNotFireAfterRun (ESC flush timing; macOS-only => flake-ish,
   watch next run).
4. PHPStan 8.3/8.4 candy-core — pre-existing src findings surfaced: '<= always true' <!-- @rename-keep historical provenance -->
   (int<1,3> vs 1MiB), 'Cannot unset offset null on array of TimerInterface',
   'ClosedResource invalid type' (AtomicJsonFile::$lockHandle), 4x unused closure `use`
   in sugar-crush-side? (listed in log). Chain never PHPStan'd before.
5. candy-mines — DifficultyStatsTest::testSaveLeavesNoTempArtifacts (AtomicJsonFile <!-- @rename-keep -->
   lock/temp residue — known documented family).
6. candy-top — BorderBatteryTest::testAppShrinksTheClockForThePresentBattery
   (asserts 140==52; known "candy-top red on master" family).
7. sugar-dash — WeatherModuleTest::testCacheRoundTripThroughAtomicFileLeavesNoTemp + <!-- @rename-keep -->
   PersistenceTest::testSaveLeavesNoLeftoverTempFiles (same AtomicJsonFile residue
   family; proc_open posix_spawn warning = absent external cmds on runner, noise).
8. sugar-crush (8.3+8.4 sharded) — DoctorExtensionProbesTest::
   testTheManifestDeclaresBothExtensionsAndCarriesNoRepositoriesBlock +
   ReelDependencyTest::testTheManifestCarriesTheRequireWithoutAPathRepo — GREEN LOCALLY
   (linked mode, 2/2 OK); red in CI because check-path-repos --fix injects
   repositories[] into lib manifests before install and the tests read the working-tree
   manifest (injection-blind). Fix = read git-committed blob or skip injected block.

Step 2.5 deliverables themselves all verified good: repo+split sync (014f8029 == ls-
remote master), default branch master, Packagist dev-master == 014f8029, sync ping 202.
Recommend a remediation lane for items 1-8 (none is caused by 2.5's commits).
