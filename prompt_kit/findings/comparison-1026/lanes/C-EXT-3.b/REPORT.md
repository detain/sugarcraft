# C-EXT-3.b — CLOSED: skills_search landed, overflow line now names a tool that can list

COMMIT 3de7475dd on cc/C-EXT-3.b (base 86f95e191), author+committer detain@interserver.net verified (1/1), zero trailers, tree clean, NEVER pushed.

NEW src/Tools/BuiltIn/SkillsSearch.php (position 42, Read, ParallelSafe, BuildsFromCatalog over ToolBuildContext->skills, TakesToolCallId+TruncatesOutput): lexical ranking over name/description/paths/bounded 4 KiB body head; model-invocable rows only; top-K (default 5, clamp 1..20, never widened); tier badges; coverage line 'searched N of M skills; K matched.'; repo text via SkillPromptLine::field (hostile-input pin proves fence escape); miss answers isError false.
SkillMatcher overflow flipped in-step: MORE_LINE_FORMAT '- %d more skills; call skills_search to find them' + RuntimeNoticeSink text names skills_search.
Docs: gen-tool-docs --write owns README/ARCHITECTURE/AGENTS_AUTHORING/PERMISSIONS/SETTINGS (38 built-ins numeral + word-numerals all generator-carried); --check 'up to date'. SKILLS.md listing-budget prose flipped.
In-step guard set (same commit): SkillMatcherListingTest literals, BuiltInToolCorpus instances row, ToolCatalogTest tail-3, DocFigureProseDrift thirty-eight map, ReadmeRosterDrift Thirty-three, ReadmeSettingsTierClaimTest 33, RuntimeTest readOnlyBuiltInToolNames += 'skills_search' (mcp_list doctrine, adjudication comment), TruncatesOutput trait doc-block += SkillsSearch bystander (§1.8 — both descriptive flips, zero behavior change).
NEW tests/Tools/BuiltIn/SkillsSearchTest.php 16T/41A. **Welder owes durations.tsv row for it (suite-figure.json carry: +16T).**

GATES: final filter 'SkillsSearch|SkillMatcher|BuiltInTool|ToolDescription|ReadmeRoster|DocFigure|ToolCatalog|SettingsTier|TrustKey|RuntimeTest|TruncatesOutput' = OK 664 tests / 6947 assertions; 'PHPUnit Deprecations: 2' pre-existing at base shape. No prefix-stability pin moved (no static tool-schema digest exists; PromptPrefix/Snapshot neighborhood byte-derived).
MUTATIONS 5/5 one-test-discriminating, md5 restores: M1 16→8 weight → exact-vs-substring test; M2 drop isAutoInvocable gate → coverage-split; M3 drop max(1,) clamp → page test; M4 swap coverage searched/total → coverage-split (symmetric 1/1 & 8/8 pins intentionally blind, split leg catches); M5 revert MORE_LINE wording → budget-collapse test.

FOREIGN-INHERITED base reds (proven at 86f95e191, not touched, not in my src diff = SkillMatcher+SkillsSearch+TruncatesOutput doc only): ForeignAgentPresetWiringTest permission_mode reader (SchedulesCommand.php), MemoryPromptWiringTest withAgentInstructions reflection census, MouseWiringTest ctxmeter zone ×3.

CARRIES (report-only): docs/PROMPT_ENGINEERING.md:100 still quotes the old '- N more skills; call Skill to list them' — outside brief file list, no guard pins it; closeout may flip. lang/en.php untouched (schemas are model-facing constants per SkillTool convention); zero new stderr emitters/sprintfs in Bootstrap.

## FIX ROUND 1 (review MINOR: MAX_LIMIT=20 upper clamp unpinned)

Review-1 MINOR (step-local): the limit=999 leg ran on an 8-hit fixture, so a
min(30, ...) clamp mutation survived — only 'never pads' was proven. Fixed:
the clamp test now also builds a 21-hit crowd and asserts exactly 20 rows at
limit 999 (literal 20, not the constant) plus the truthful 'searched 21 of 21
skills; 21 matched.' coverage line. Folded into the existing method — test
count unchanged, +2 assertions.

Commit: this fix commit on cc/C-EXT-3.b (parent 3de7475dd; the section rides
the commit so its own SHA is read via `git rev-parse HEAD`; per campaign
precedent a commit cannot carry its own hash). Files:
candy-code/tests/Tools/BuiltIn/SkillsSearchTest.php + this REPORT. NEVER pushed.

GATES: filter 'SkillsSearch' 20T/61A green (was 20T/59A); 'SkillsSearch|SkillMatcher'
44T/156A green; 2 pre-existing deprecations unchanged.
MUTATIONS 2/2 one-red-discriminating, cp+md5 restore (src hash
17381375c2fcee5772cab7d22da3f20b re-verified): M1 min(self::MAX_LIMIT→30) at
the clamp site → exactly the new pin red ('21 matches expected size 20');
M2 const MAX_LIMIT=20→30 → exactly the new pin red (no schema-text pin exists
in this filter; the literal-20 assertion is what catches cap widening).
Welder carries unchanged (durations.tsv row was already owed at 16T — still
16T here; suite-figure +16T stands; assertions delta rides the tolerated wobble).
