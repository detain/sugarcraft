# REVIEW-1 — lane C-CTX-4.c (cc/C-CTX-4.c @ 275819aed over base 9fbb73173)

VERDICT: FINDINGS 0 CRITICAL / 0 MAJOR / 1 MINOR (out-of-scope) / 3 NIT (step-local)

## 1. Phantom-first — CLEAN
- cat-file -t 275819aed = commit; git rev-list master..cc/C-CTX-4.c = 1; status --porcelain = 0 lines in worktree.
- git log -1 --format='%ae' → detain@interserver.net; separate grep on '%ce' → detain@interserver.net. No Co-Authored-By / trailer lines in body. Subject/body match brief.

## 2. Declared-set discipline
diff --stat = 14 files, +738/−138, matching BRIEF file set plus out-of-set edits judged individually:
- candy-code/README.md — forced by gen-settings-doc (generated marker blocks) + TrustKeyDrift-mandated never-project sentence. LEGIT guard law.
- src/Support/ContainedPath.php docblock SIXTY→SIXTY-ONE — forced by the routed-call-site census equality. LEGIT.
- DocFigureProseDriftTest arm (6→7 decisions, 'nine' word) — forced by MEMORY.md census-equality lock. LEGIT.
- LayeredSettingsTest userTierOnlyKeys literal — forced equality roster for the new key. LEGIT.
- tests InstructionFileLoaderTest: 2 refusal pins re-pointed to projectForcedInstructions() named-arg. In-step: they police the refusal spellings; project-tier refusal semantics byte-unchanged. LEGIT.
- lang/en.php (+2 rows: label/help strings for instructions.walkToRoot) — guard-forced (SettingDefinition withLabel(Lang::t()) + LangParity), but ABSENT from the builder's disclosed-deviations list → NIT m2 (step-local, report completeness only; the edit itself is correct and required).

## 3. Semantics — verified by code trace
- ancestorRoot() walk branch: gated on walkToRoot && realHome !== false && root !== realHome && ContainedPath::within($root,$realHome). The raw root!==home guard IS load-bearing: within() with exactCounts:true returns true when the path resolves ONTO home, so without the guard a checkout AT home computes top=dirname(home) and the climb could pass above home; only testARootThatIsHomeItself changes under removal → confirms builder's M2 'exactly 1 red'. Climb lands at topmost directory strictly below $HOME; top===home yields null.
- Default-off byte-identical: home resolution merely reordered ahead of the .git check; every early-return path returns the same values. Holds.
- loadForced(): absolute/~ honored ONLY when $userTier=true. Project tier keeps the EXACT original refusal literals ('an absolute forced-instruction pattern; forced patterns resolve under the checkout (...)' / '~-prefixed ...'). Boundary = realpath(home) for user tier, repoRoot otherwise; every match passes ContainedPath::within($path,$boundary) → a SYMLINKED ~ escape is refused BY PATH, not just by tier name. Pin exists and asserts the refusal reason: testASymlinkedUserTierMatchEscapingHomeIsRefusedByPath (reason 'outside the user home directory'). Unresolved home → refusal by name. expandImports receives the same boundary. User-list-first-then-project order preserved.
- Bootstrap::projectForcedInstructions() 'empty today — split provably agrees': merged = readUserConfig(); user = readUserConfigWithoutProjectTier(); residue = merged['instructions'] minus user via strict in_array. Project layer is filtered by LayeredSettings::only(..., PROJECT_TIER_KEYS) and neither 'instructions' nor 'instructions.walkToRoot' is in PROJECT_TIER_KEYS (verified list at LayeredSettings.php:856, generator-markered) → residue provably []. No divergence path found.
- Setting definition (MemoryRuleSettings): Bool, default false, RiskClass::Prompt, withLayered, NO withProjectSettable, withReaderSymbol Bootstrap::instructionLoader. Read site uses (readUserConfig()[KEY] ?? false) === true — same project-can't-set idiom as excludedInstructions. LayeredSettings LAYERED_KEYS +1 in-step.
- loadAncestorRoots unchanged: consumes ancestorRoot(), containment vs $ancestorRoot, readBounded 61440-byte budget intact on walk (testTheSizeBudgetRidesTheExtendedWalkUnchanged pins ceiling + pointer row).
- Red-before non-vacuity: confirmed by static construction — the new test file cannot pass at parent (11 named-arg/missing-method Errors + 5 behavior failures; default-off parity test is the only green one). Substance of builder's 'only default-off parity passes' holds (counts slightly off, see m3).

## 4. Gates reproduced (plain pipe, cwd lane candy-code, env -u LINES -u COLUMNS, PtyRunToolTest excluded)
- InstructionWalkPastRootTest: 17T/39A, OK (file has 17 test methods — matches expected 17T).
- --filter Instruction: 309T/1584A OK — exact builder figure.
- Settings/doc-guard set: 206T/39522A OK with my broader filter; 192T/39435A OK with exact class names. Builder's '93T/39050A' not reproducible as labeled but the whole family is GREEN → NIT m3 (report arithmetic), not a finding against the code.
- ReadPathCensus: 19T/636A OK — exact.
- BootstrapUserConfig|FeatWiring|StderrEmitterCensus|DuplicatedTestHelperDrift|LangParity: 176T/24465A OK — exact.
- GlobDialect: 14T/369A OK (chr(42) fixtures; the one 'docs/*.md'-shaped literal pre-exists in the corpus via the FeatWiring base — corpus figure unmoved).
- gen-settings-doc --check: NOT RUN — sandbox permission map denies arbitrary php-script execution (reviewer-tool limitation, disclosed). Covered by green SettingsSchemaDocDriftTest and generated-marker blocks in README/SETTINGS.md.
- Mutation re-reasoning: cp-based swaps are also denied by the permission map, so M1–M3 were re-derived statically. M2 (drop raw !==home guard) → exactly 1 red (rootIsHome) CONFIRMED. M3 (project treated as user) → exactly 4 reds (2 new refusal tests + 2 loaderTest pins) CONFIRMED. M1 (walk branch never taken) → static trace reddens 3 tests (honouredCapped, neverLeaves, sizeBudget), not builder's 4; defaultStops/markerTopmost/cappedStarts/rootIsHome stay green → NIT m4.

## 5. ContainedPathInventory base-red re-derivation
At tip: 49T with EXACTLY 2 failures (testTheRoutedCallSiteInventory, testTheHandSpelledInventoryIncludingItsStaleExclusion). The array-identity diffs name only FOREIGN files: Host/Commands/ContextHostCommand.php, Host/TranscriptProjector.php, Tools/PathJail.php, AgentMemory, SkillLoader — zero lane-touched files newly accused. The lane's own InstructionFileLoader roster row swap 8→9 matches the live walk (it is NOT in the failure diffs). cp-swap re-run at base is denied by the sandbox, but the lane's diff touches no foreign-file blob and no roster line for them, so the live walk at 9fbb73173 has identical inputs → identical 2 failures at base. Builder's claim ACCEPTED. (Note the test-name typo 'HandSpelled' is pre-existing, not lane-introduced.)

## 6. Laws
- E646: clean — zero timers; walk is bounded by depth cap + byte budget only.
- One type per file; declare(strict_types=1) first; final classes; no new proc_open/fork sites; weld-only files (suite-figure.json, durations.tsv, dist/) NOT in diff.
- en-only lang law honored (new keys land in lang/en.php only, LangParity green).
- TrustKey/ConfigWriteProducer census honesty: walkToRoot registered in never-project list via generator, TrustKeyDrift + ConfigWriteProducer filters green.

## 7. Findings
- MINOR (out-of-scope / closeout): candy-code tests FeatWiringReachabilityTest.php:1079 docblock still asserts user-tier absolute instructions "must not be honoured even when it arrives from the user's own config" — the law flipped this lane. Test stays green ONLY because its fixture places outside.md outside the temp-dir home (still refused by the new path boundary); on any layout where the target is inside home, the e2e would honor and the comment is falsified. File is outside brief ownership; builder disclosed it as a seam. Closeout should truth-flip the docblock sentence.
- NIT (step-local) m2: lang/en.php out-of-set edit is guard-forced and correct but omitted from the report's deviations paragraph.
- NIT (step-local) m3: report arithmetic — red-before stated 16T/10E (static count: 17T/11E); settings-guard figure 93T not reproducible as labeled (green at 206T/192T under either filter spelling).
- NIT (step-local) m4: M1 mutation claimed '4 red'; static trace gives 3.

## 8. Welder duties
- NEW durations.tsv row required for candy-code/tests/Context/InstructionWalkPastRootTest.php (else it runs zero shards).
- suite-figure.json + README staleness-pair re-pin (+17T expected).
- Hub-file collision check at pick time: DocFigureProseDriftTest arm, README.md, LayeredSettingsTest, lang/en.php all touched — verify no same-wave sibling lane edits the same regions.

## Standing foreign reds (report-only, not findings)
ReadmeSuiteFigureDrift staleness pair; the 2 ContainedPathInventory foreign-row reds; ambient 'PHPUnit Deprecations: 2' on every run.
