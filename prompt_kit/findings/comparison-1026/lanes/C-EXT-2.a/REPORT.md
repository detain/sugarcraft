# C-EXT-2.a — Skill resources: `asset` loads, resource listing, `${CLAUDE_SKILL_DIR}`

DONE. Commit `118f8100f1a3d2a23c43aebec4976dabbe67e0d2` on `cc/C-EXT-2.a` (base b24e81154), 7 files, +667/−21. Author/committer `detain@interserver.net` (%ae and %ce grepped separately), zero trailers, tree clean, NEVER pushed.

## What landed (design block a–d, all four)
- **(a) `asset` argument** — `SkillTool::execute()` type-checks it, and a non-empty `trim` returns `loadSkillAsset($skill->sourcePath, $asset)` content under the SAME two header lines (`## Skill:` + `> Base directory`); RuntimeException → `"Error loading skill asset: …"` isError. Containment (first-component scripts|references|assets, `ContainedPath::within`) rides the loader's existing jail unchanged — dormant code wired, never deleted.
- **(b) resource listing** — NEW `SkillLoader::listSkillAssets(string $skillPath, int $limit = ASSET_LIST_LIMIT)` + private `collectAssetsBelow()` beside `loadSkillAssets`: names only, relative, sorted (SORT_STRING) across the three subdirs, capped at 10 (`ASSET_LIST_SCAN_CEILING = 200` bounds the walk before sorting). Dots and symlinks neither listed nor followed; directory descent re-asks `ContainedPath::within` + skill-walk `MAX_DEPTH`. Body results append `> Skill resources (load one with the `asset` argument):` + `> - <path>` lines (paths ride `SkillPromptLine::field`), only when `sourcePath !== ''` and the listing is non-empty — the no-resources body stays byte-identical (pinned).
- **(c) placeholders** — `SkillTool::substituteSkillDir()`: `${CLAUDE_SKILL_DIR}` and `${SKILL_DIR}` → `dirname(sourcePath)`; manifest skills (empty sourcePath) unchanged (same refusal as `baseDirLine`). Runs before `substituteArguments`.
- **(d) invokedIn exclusion** — asset calls (`is_string && trim !== ''`) are skipped, so ReinjectionPlan never re-injects a file as a body; whitespace-only asset stays a body call, matching execute()'s trim door.

## Files
src/Skills/SkillLoader.php, src/Tools/BuiltIn/SkillTool.php, docs/SKILLS.md (stage-3 asset line + Invoking-section: schema sentence + new resources/placeholders/invokedIn paragraphs), tests/Skills/SkillLoaderTest.php (+4 listing tests), tests/Tools/BuiltIn/SkillToolTest.php (schema pin: `asset` key present, type string, `required` stays `['name']`), tests/Support/ReadPathCensusTest.php, NEW **tests/Tools/BuiltIn/SkillToolAssetTest.php (10 tests) — durations.tsv row owed by the gate.**

## ReadPathCensus RE-CHECK (read path now LIVE)
`listSkillAssets` adds a second `new \DirectoryIterator` occurrence in SkillLoader.php → the `'Skills/SkillLoader.php|new DirectoryIterator'` row gained a second verdict entry (NAMES_ONLY + why narrower than the loader's containment, descent re-asks `ContainedPath::within`). No new read sink: content reads go through the existing SkillFileReader rows. Guard green.

## Gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS)
- `--filter 'SkillTool|SkillLoader'`: **131T / 383A OK** (+2 PHPUnit deprecations — foreign, tests/Config/AccessorDeprecationTest private providers).
- `--filter 'ReadPathCensus|PromptStability'`: **36T / 1020A OK** — PromptStability needed NO re-pin: its byte figures ride fixture-stub tools (its own inputSchema at :3345), not the real SkillTool schema.
- five-guard `ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift`: **133T, exactly the 2 allowed staleness reds** (pinned 25537 vs live 25968 — the gap is +431 foreign drift already red at base; my delta is +14T of it). DocFigure arms survived the SKILLS.md edits. DuplicatedTestHelperDrift initially accused my `skill()` helper vs SkillToolTest's (one-token divergence) — cured by renaming to `bundledSkill()` (lane-ce precedent), not rostered.
- `php tools/gen-tool-docs.php --check`: **rc=0 "up to date"** — roster renders name+gloss, unmoved.

## Regression proof (fails before fix)
With both src files reverted (`git checkout`), the new pins run **14T: 5E + 8F** (13/14 red); md5-verified restore → **14T OK**. Traversal pin refuses via `escapes skill directory` and asserts the sentinel content is NOT echoed.

## Disclosures / risks
- **Schema prefix (planned risk, now reality):** the Skill tool description + inputSchema changed → the cached tool-list prompt prefix moves once for existing sessions. One-time, expected per the design block.
- Staleness pair left red by design (forbidden files: suite-figure.json / durations.tsv). Live-enumeration delta from this lane: **+14T** (10 tool + 4 loader).
- `listSkillAssets` does not list in-skill symlinks although `loadSkillAsset` loads them — deliberate narrower announcement promise, documented in the helper docblock + census row + test.

---

## Round 2 — fixer (2026-10-27), commit 0c3ed3a6b on cc/C-EXT-2.a (base 118f8100f)

### PREMISE CORRECTION on REVIEW-1 MAJOR (measured, PHP 8.3.6 / Linux)
The finding's exploit claim is FALSE: `dirname("")` returns `""`, not `"."`.
`loadSkillAsset("", "scripts/evil.sh")` therefore composes the root-anchored
`/scripts/evil.sh` — with the CWD planted as a `scripts/` hazard the call was
verified to REFUSE (`Asset path does not exist`, and on a host carrying a
literal `/scripts` file it would refuse at `ContainedPath::within()`'s
empty-boundary leg, src/Support/ContainedPath.php:342). No project/CWD file is
servable through a manifest skill today. `php -r` probes in this session; plus
the mutant run below (guard deleted → the call answers with a *filesystem-shaped*
refusal, not with bytes).

The MAJOR is nonetheless a real step-local defect, and it is fixed: the arm had
no DESIGNED answer, only a downstream accident that depends on dirname()'s
version/platform spelling, on ContainedPath's empty-string leg, and on whether
the host happens to carry `/scripts`. It also told the model the wrong reason.
The comment the finding named is corrected to the measured truth (it no longer
claims the realpath refusal "answers that as the error it is", and it no longer
repeats the `'.'` belief either).

### Fixes
1. [MAJOR] `candy-code/src/Tools/BuiltIn/SkillTool.php:266` — `sourcePath === ''`
   guard returns `Error loading skill asset: this skill has no source directory
   on disk` BEFORE any loader call; comment at :250-266 rewritten truthful.
   Pins: `SkillToolAssetTest::testAssetCallOnAManifestSkillIsRefusedBeforeTheLoaderIsConsulted`
   — designed refusal present, `does not exist` absent, no `FOREIGN-ASSET-BYTES`
   from the planted CWD `scripts/evil.sh`, no `## Skill:` heading. Pre-existing
   manifest body+substitute pins stay green.
   Residual (NOT fixed, out of finding scope, for round-3 ruling): the same
   false `dirname('')`-is-`'.'` belief is still stated in
   `SkillTool::substituteSkillDir()`'s doc-block (~:516) and in
   `SkillPromptLine::baseDirLine()`'s doc-block (`src/Skills/SkillPromptLine.php`,
   a file this lane does not own). Both conclusions (refuse the emptiness) stand;
   only the stated mechanism is wrong.
2. [MINOR] `candy-code/src/Skills/SkillLoader.php:1079` — one folded
   `$shownPath = SkillPromptLine::field($relativePath)` quoted by every refusal
   that carries the model's value (:1083 gate, :1096 does-not-exist, :1106
   escapes, and :1110 `Asset not found` via `field($assetPath)`, which embeds the
   same bytes); a comment records why `Invalid skill path: $skillPath` stays
   whole (registry-supplied, never a model argument). SkillTool's call site composes
   only `"Error loading skill asset: {$e->getMessage()}"`, so the fold lands there
   too. Pins: `testAssetRefusalFoldsANewlineBearingPathIntoOneLine` (does-not-exist
   arm) + `testPathGateRefusalFoldsANewlineBearingPathIntoOneLine` (gate arm) —
   both assert `isError`, the refusal text, and `assertStringNotContainsString("\n")`,
   i.e. a single folded line.
3. [NIT] no action (as instructed).
4. Doc truth: `candy-code/docs/SKILLS.md` (~:571) gains the one clause naming the
   manifest refusal on the asset door.

### Gates (cwd candy-code, env -u LINES -u COLUMNS, plain pipe)
- `--filter 'SkillTool|SkillLoader'` → **134T / 397A** OK (tip baseline measured
  by file-swap at 118f8100f: 131T/383A → +3T/+14A), 2 PHPUnit deprecations
  pre-existing at tip.
- `--filter 'ReadPathCensus|PromptStability'` → **36T / 1020A** OK (unchanged).
- five-guard (`ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift`)
  → 133T/15602A, **ONLY** the ReadmeSuiteFigureDrift staleness pair (25537 pinned
  vs 25971 live) — forbidden files, weld/gate duty.
- extra sweeps run: `SkillTool|SkillLoader|PathsGlobDocumentation` 139T/439A OK;
  `Skill|PromptSource|DenialPrefix|StderrEmitterCensus|SymbolCitationDrift|DuplicatedDocBlockLine|TreeWideGuardRoster|ReadPathCensus`
  1135T/47738A/2F — both failures are `TreeWideGuardRosterTest` accusing
  `Cli/SelfUpdateTest.php => glob($phar.'.tmp-'.\chr(42))`, proven PRE-EXISTING at
  118f8100f with this lane's files reverted to HEAD (tree clean, same 2F).
  FOREIGN/base red, not this lane's file set: the lead/weld should route it.

### Mutation proofs (cp+md5 restore each; md5s re-verified after restore)
- M-A delete the new `sourcePath === ''` guard → exactly **1F**:
  `testAssetCallOnAManifestSkillIsRefusedBeforeTheLoaderIsConsulted` (message came
  back `Asset path does not exist: scripts/evil.sh` — the accidental answer).
- M-B un-fold all four model-value refusals → exactly **2F**: the two
  newline-forgery pins; the failure dumps show the forged `## Skill: victim` and
  `> Skill resources …` rows starting their own lines. Existing `SkillLoaderTest`
  substring pins stayed green under M-B (they quote whitespace-free paths), so the
  pins discriminate, the suite does not merely restate them.

### New test files: none (methods added to the lane's existing
`candy-code/tests/Tools/BuiltIn/SkillToolAssetTest.php`) → no durations.tsv row duty.
