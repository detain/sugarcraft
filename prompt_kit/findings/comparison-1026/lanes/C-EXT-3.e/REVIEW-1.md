# C-EXT-3.e REVIEW-1 (round r1) — reviewer verdict on tip f18b8cd86 (parent e6188662a)

VERDICT: CLEAN (0 CRITICAL / 0 MAJOR / 2 MINOR / 2 NIT). All claims re-derived on disk; phantom law satisfied.

## Verified builder claims
- Tool: `SkillWorkshop` #[BuiltInTool] Write-class position 43; attribute pinned in SkillWorkshopTest::… + ToolCatalogTest tail-3 [ScheduleTool, SkillsSearch, SkillWorkshop] flipped in-step. ✓
- Revision-before-live STRUCTURAL pin: `testTheRevisionIsWrittenBeforeTheLiveFileAndTheTrimLast` reflection-slices land() body, assertNotFalse on `AtomicFileWriter::write($revisionPath`, asserts revisionWrite < liveWrite < trim positions. Mutation-reason: delete/swap the revision write → assertNotFalse or assertLessThan REDS exactly there (plus functional assertFileExists(history/1.md) red). Pin is real, not vacuous. ✓ (execution blocked — edit tool denied by reviewer permission map + live builder writes on tree; by-construction reasoning from pin source.)
- Window-8 + whole-file @rename archive (testTheHistoryWindowIsBoundedAndFullRevisionsSurviveTheMove: 9 creates → active [2..9], archive/1.md byte-equal, undo→8). ✓
- Undo restore + sole-revision removal + residue legs: store + host pins (removed/restored/residue, user asset survives, dir stays named). Mutation-reason: drop `@unlink(head.md)` in restore branch → assertFileDoesNotExist(2.md)+revisionCount red; drop live-restore write → byte asserts red in both tiers. ✓
- Shadow refusal (refuseShadowed via SkillLoader(false)->loadAllManifests, own-path row skipped; proven through the loader with chdir fixture). ✓
- Sanitiser/caps/redaction/0600-0700: '../Hidden Traversal X'→'hidden-traversal-x'; ghp_ → [REDACTED] in landed bytes; caps refuse-without-truncating; chmod 0777 home → 'no home directory', root never created; is_link refused at root/dir/file; readRevision filesize-gate + MAX_REVISION_BYTES+1 bound. ✓
- Linked trees blind-refused (patch/undo/history pins target untouched tree). ✓
- Roster flips in-step, all on disk: DocFigure 'thirty-nine'=39, ReadmeRoster/SettingsTierClaim 34/'Thirty-four', ReadPathCensus ×2 rows (scandir NAMES_ONLY, file_get_contents OWNED_HOME — file has exactly those 2 sinks, verified against sink+static+ctor scanner lists; filesize/filemtime are not censused sinks), BuiltInToolCorpus row, WRITE_CAPABLE += SkillWorkshop in Runtime.php with why-comment (self-grant floor: Write class asks in default mode, plan/dont-ask refuse; Schedule precedent). ✓
- Docs riders (README/ARCHITECTURE/AGENTS_AUTHORING/PERMISSIONS/SETTINGS/COMMANDS/SKILLS) in-lane; doc-guards green. gen-tool/gen-command --check: bare `php tools/...` DENIED by my permission map (run-blocked, not failed); substitute: ToolDocDriftTest green inside 103T roster gate; COMMANDS.md/SKILLS.md rows present. ✓

## Gates (mine, clean committed tree, plain pipe, cwd=candy-code)
- trio: 32T/190A (17/84 + 8/56 + 7/50 per-file). ✓
- roster: 103T/1051A/1F — sole F ChatHostLangCatalogue:90; FOREIGN RE-PROVEN: base e6188662a test sources() lacks src/Permissions/SavedRules.php (fixed list + only Chat/Handlers + Host walks; SavedRules lives in src/Permissions/), keys present in base en.php, lane diff touches neither test nor SavedRules. Base-red by construction; welder routes (C-TUI-5.d tail).
- doc-guards: 102T/4164A green. post-restore set: 88T/1558A/1F (same foreign) — matches REPORT exactly.

## Judgments
1. R-EXT-2: live-with-announce-and-undo exactly. create/patch land straight to live; both success paths return announce rows embedding `/skills undo <name>` (sprintf-exact pins); refusals land nothing (no silent-live); no draft-gate creep — ProposedSkills untouched (file absent from diff), dream leg unchanged. PASS.
2. Undo integrity: revision→live order = never lose model bytes; undo walks head revision (restore N−1 or remove at 1); post-multiple-edits + window-overflow pins. PASS. (NIT-2 below.)
3. Security: sanitiser (no path-capable names), caps refuse-not-truncate, SecretRedactor on all three fields + patch fields, Skill::parse round-trip, link/0700/0600/owned-home floors, shadow floor (no cross-tier squat), doctrine text in description(), ToolResult/announcement escaped via SkillPromptLine::field. Persistence vector = R-EXT-2's accepted risk with announce+undo+Write-gate. PASS.
4. WRITE_CAPABLE: justified, precedent-consistent, why-comment present. PASS.
5. §1.8: every guard the lane moves moved in-commit; census rows truthful (see above). PASS.
6. /skills dispatch: region-only in SkillsHostCommand match arms (Chat.php not in diff); en.php append-only at tail; candy-code/lang holds only en.php → locale law moot. PASS.
7. en.php keys present VERBATIM on committed tip (git diff master...HEAD): cmd.skills.desc/hint, host.skills.usage, 5 history + 3 undo keys; all consumed rows green. PASS.
8. Deviations: per-call LearnedSkills::new() matches host command's per-call ProposedSkills precedent (stateless, no memo to drift); render() byte-shape restated (parent method private); patch()-headRevision read = new-file code fixed pre-commit, scope-honest. PASS.

## Findings
- MINOR (out-of-scope): KeyHelpTest 5F — RE-PROVED foreign: identical 63T/96533A/5F on main tree 302e99ac2 which does not contain the lane (incl. testSlashHelpNoLongerOpensTheKeyboardReference failing "/skills hint" at base, from the C-EXT-3.c hint-length lineage). Welder: route; note the lane further lengthens that hint, so the owning repair must re-measure the 49-col ellipsis roster post-lane.
- MINOR (step-local, disclosure): undo() head==1 uses @unlink/@rmdir unchecked — a failed head.md unlink still reports 'removed' leaving a stale revision that later undos mislabel. Low impact (0700 home); candidate: assert file gone or include residue note.
- NIT: crash between revision→live writes lets a later undo present never-live bytes as "previous" — deliberate order (protects model content), disclosed in land() comment; acceptable.
- NIT: 'PHPUnit Deprecations: 2' = tree-wide baseline (SkillsNewTest alone reports 2; doc-comment metadata shape), not lane.

## Builder-in-flight disclosure
At review close the lane worktree carried 2 UNCOMMITTED builder edits (SkillWorkshop.php docblock {@see}→plain prose; LearnedSkillsTest fail-in-try→capture-then-assert hygiene; 84A→93A, still 17T green at @39/@40). No reviewer probe landed (grep 'MUT:REVIEW-PROBE' = 0; src/Skills/LearnedSkills.php clean; HEAD unchanged f18b8cd86). All findings above are commit-level (git diff master...HEAD), so the verdict binds f18b8cd86; welder should pick the builder's FINAL tip if hygiene commits land (figures then re-measure from that tip) — trio shape unchanged (32T).

## Welder carries
- DUR rows ×3: candy-code/tests/Skills/LearnedSkillsTest.php, tests/Tools/BuiltIn/SkillWorkshopTest.php, tests/Host/Commands/SkillsHistoryUndoTest.php.
- suite-figure re-pin +32T (from final tip; commit-state trio = 32T/190A).
- §4 mark f18b8cd86 (plus any builder hygiene commit that lands after).
- Foreign routing: ChatHostLangCatalogue 1F (SavedRules sources() omission), KeyHelpTest 5F (/skills hint clip lineage), ContainedPathInventory 2F (AgentMemory/SkillLoader/ContextHostCommand/TranscriptProjector rows — identical on lane-less 302e99ac2, NOT the lane: LearnedSkills adds zero ContainedPath calls).
