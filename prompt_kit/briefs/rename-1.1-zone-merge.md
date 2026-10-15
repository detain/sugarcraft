# Step 1.1 — Merge candy-zone into candy-mouse (evidence brief)

Base HEAD: 70712b220. Plan: plan_rename.md Step 1.1 (authoritative). NEVER push; direct commits to master.

## Spec digest (plan wins over orchestrator brief where they differ)
- ns: `SugarCraft\Zone` → `SugarCraft\Mouse\Zones` (subdir `candy-mouse/src/Zones/`, Msg/ rides along).
- Tests → `candy-mouse/tests/Zones/`, ns `SugarCraft\Mouse\Tests\Zones` (plan step 3 — YES, plan says so).
- Autoload: candy-mouse psr-4 `SugarCraft\Mouse\`→src/ + `SugarCraft\Mouse\Tests\`→tests/ ALREADY resolve
  Zones/ and Tests/Zones/ — NO autoload entries needed (brief guessed "additional entry"; plan shape needs none).
- renames.json entry kind=merge, status→`renamed` at the end (step 17); residue check `--old=candy-zone` exit 0 (step 16).
- Commit msg (step DoD): `candy-mouse: absorb candy-zone under SugarCraft\Mouse\Zones`.
- Archive of sugarcraft/candy-zone remote = Phase 10 — NOT here. No push (Part A rule 6 deferred to operator per task law).

## Tool-interaction finding (ordering deviation, disclosed)
Plan lists step 3 (manual ns edits) BEFORE step 4 (tool). Empirically the tool's preflight
namespace-collision scan (runs under `--no-move` because movedState=false) aborts (exit 2) once any tracked
src/tests file literally contains `SugarCraft\Mouse\Zones\` — which step-3 edits create. Resolution: run the
tool FIRST, immediately after the git mv steps (only the moved FILES exist, namespaces still old → preflight
clean). R1 then performs the mechanical half of step 3 repo-wide (incl. moved files). Manual residue of step 3:
`SugarCraft\Mouse\Zones\Tests` → `SugarCraft\Mouse\Tests\Zones` in the 9 moved test files (+ MouseZone alias
prose check — candy-zone/src/Zone.php already imports `SugarCraft\Mouse\Zone as MouseZone`, safe under R1).

## Baselines (prompt_kit/briefs/rename-baseline.md)
- candy-mouse 204T/500A/0F; candy-zone 139T/416A/0F → merged expectation 343T/916A.
- (fill in consumer baselines below)

## Execution log
- [ ] git mv src/tests
- [ ] rename-lib --no-move (dry-run inspected)
- [ ] manual test-ns + prose fixes
- [ ] composers union/dedupe, root lock
- [ ] examples/.vhs/README/CALIBER_LEARNINGS
- [ ] candy-zone dir rm, vhs.yml, codecov.yml, bootstrap-org-repos.sh
- [ ] docs _data/lib/icons/MATCHUPS/PROJECT_NAMES/README/index + gen-docs check
- [ ] findings/research/repo_map git mv
- [ ] suites green vs baseline
- [ ] residue + guards
- [ ] renames.json status=renamed
- [ ] commit + tracker row
