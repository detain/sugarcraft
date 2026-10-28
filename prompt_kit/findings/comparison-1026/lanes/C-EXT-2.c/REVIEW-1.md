# C-EXT-2.c REVIEW-1 (round 1) — worktree /home/sites/cc-cmp-C-EXT-2.c @ 3897f5aa4

VERDICT: FINDINGS — 1 MAJOR, 1 step-local MINOR; everything else claimed is verified on disk.

## MAJOR
- M-1 In-step guard law (plan §1.8) broken: `tests/Cli/BootstrapLaunchNoticeRoutingTest.php:964-967` (`testIgnoredFrontmatterReachesBothChannelsAsOneRowPerFormat`) is a THIRD live pin of the old inert truth — it launches real `Bootstrap::chat()` on a fixture skill declaring `context: fork` and asserts the aggregated row still says `` `context: fork` is not acted on (fork-me) ``. Red at tip, green at base (proved: INERT[SKILL] emptied by this diff; test untouched by it). Builder's battery clearly never covered this Cli launch-routing family — I reproduced the failure directly (`Tests: 1, Failures: 1`, assert-contains miss). Its docblock (:933-934, "while `context: fork` and a misspelt `user_invocable` are named") teaches the same stale claim. Fix: expected skill row → '1 skill declares frontmatter candy-code ignores: `user_invocable` is a skill field (did you mean `user-invocable`?) (fork-me)'; rows-count 4 survives (unknown-key row still lands); amend the docblock sentence.

## MINOR (step-local, weld-cure disposable)
- m-1 `docs/SKILLS.md`+README moved to live truth in-step, but `docs/TROUBLESHOOTING.md:163-166` still says "`allowed-tools` / `effort` / `context: fork` do nothing … parsed and carried but not acted on by the live CLI path". The `context: fork` half is falsified by THIS lane (one-line prose flip, unpinned by any guard — DocFigure arm run green). The allowed-tools/effort half was already false at base (C-EXT-2.b seam — note for closeout too).

## Base-inherited reds — confirmed, not attributed to lane
- StderrEmitterCensus ×3 + TreeWideGuardRoster ×2 reproduce at tip; diff adds zero fwrite/error_log/walker lines and never names their files (grep of `^+` lines clean).
- NEW standing-red discovered (add to closeout roster): `tests/Chat/ChatHostLangCatalogueTest::testEveryCatalogueKeyIsAskedForByChatOrHost` red for `chat.effort.launch_unshaped` — asked ONLY by `src/Cli/LaunchContext.php:2109`, which the test's `sources()` list excludes; key+test byte-identical at base → C-AGT-7.b seam, not this lane's.
- FrontmatterKeyAuditTest:152-163 uses the retired "`context: fork` is not acted on" row as synthetic input to the notice() machine — green by design; NIT only, welder's discretion.
- Bootstrap.php:5950-5958 stale inert-context comment — builder-disclosed out-of-ownership seam; accept, closeout collects.

## Verified clean
- Focus 1 reachability: EngineBackend::turnTools() binds every SkillTool copy to the SAME turn-bound TaskTool copy (identity + per-turn-copy immutability pinned by reflection test); SkillTool ships via tools() catalog; degraded inline + FORK_INLINE_NOTE when unbound. Live.
- Focus 3 E646: ExemptFromParallelDeadline is a deadline-exemption annotation (WorkflowTool/TaskTool precedent); zero new timers/kill paths; note SkillTool is not ParallelSafe so the exemption is protective-forward (brief-mandated).
- Focus 4 deviations: ActiveSkillEffects comment-only; DocFigureProseDriftTest (consulters 3→5, exact ordered roster) + SkillTest/SkillFrontmatterTest obligatory flips — all legitimate, same commit.
- Focus 5: no git stash used by me; 5 claimed guard reds re-verified above.
- Focus 6 non-vacuity: neutering the fork branch reddens ≥6 SkillToolForkTest legs (fence-shape, refusal, note); neutering the bind reddens the identity pin; effort/model ladders pinned both polarities (skill-vs-agent wording); refusals never reach the provider (assertCount requests []); asset-read passthrough pinned. New files 16T/82A exact (7+9), TaskToolEngineTest 17T (+2 refusal arms).
- Gates re-run (cwd worktree candy-code, plain pipe, -u LINES -u COLUMNS): 'skills' family 762T OK (builder's 275T is a narrower filter — superset green); SkillForkTest|SkillToolForkTest 16T/82A OK; TaskToolEngineTest 17T OK; DocFigureProseDriftTest+InertFrontmatterDocumentationDriftTest 98T OK; GlobDialect|DuplicatedTestHelperDrift|SuiteSkipRoster 55T OK. lang/en.php is the sole locale (no 16-locale duty); key unpinned elsewhere.
- Focus 7 laws: ONE commit; %ae=%ce=detain@interserver.net (separate format fields grepped); zero Co-Authored/Signed-off trailers; suite-figure.json/durations.tsv/candy-code-web/dist absent from the 23-file stat; new test literals glob-shape-clean.
- Weld owes (NOT lane duty): durations.tsv rows for tests/Skills/SkillForkTest.php + tests/Tools/BuiltIn/SkillToolForkTest.php; suite-figure+README re-pin; closeout note for Bootstrap.php:5955 comment, ChatHostLangCatalogueTest standing red, TROUBLESHOOTING allowed-tools/effort half.
