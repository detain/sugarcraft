# C-WEB-7.c (W13) — paged session.export + memoized non-git walk — DONE

Branch cc/C-WEB-7.c (cut by lane from 5ed26cf92; dispatch's "pre-cut" never existed). 13 files, 1 commit. Never pushed.

**New test files (gate owes durations rows + suite-figure re-pin):**
- candy-code/tests/Server/SessionExportPagingTest.php (10T/61A)
- candy-code/tests/Attachments/WorkspaceWalkTest.php (7T)
- candy-code-web/src-web/stores/__tests__/sessionsExportPaging.spec.ts (6T)

**Gates (plain pipe, env -u LINES -u COLUMNS, cwd candy-code; vitest serial):**
- `--filter 'SessionExportPagingTest|WorkspaceWalkTest|FsSearchTest|SnapshotPagingTest|ToolMethodsTest|ProtocolSchemaDriftTest|ServerProtocolDocumentationDriftTest|ReadPathCensusTest'` → 63T/1098A, 1F = ProtocolSchemaDriftTest only: by-design §1.8 regen-then-restore drift (regenerated schema.json+generated.ts+SERVER.md ran ALL guards + generated.spec.ts 3/3 green, then restored; weld re-derives).
- vitest `sessionsExportPaging sessionsRowActions sidebarActions` → 16/16 pass, 0 errors.
- `--filter 'DocFigureProseDriftTest|GlobDialect|DuplicatedTestHelperDrift|ChildWallClock'` → 129T/16091A, 1F = **inherited base red** testAgentsAuthoringToolClaims…: docs spell `thirty-seven`, word map tops `thirty-six`, BuiltIn has 37 classes — all three paths untouched by this lane.
- `--filter 'ContainedPathInventoryTest'` → 2F **inherited base reds**: derived roster grew (AgentMemory+1, SkillLoader 3→4, ContextHostCommand+1, TranscriptProjector+1, PathJail 5→6) — foreign lanes added ContainedPath sites without roster flips; my WorkspaceWalk-in/FsMethods-out swap rows agree exactly (absent from diffs).
- ReadmeSuiteFigureDriftTest staleness pair red = expected (merge-owned figures).
- Red-before: base SessionMethods+Fusion swaps in → SessionExportPagingTest 8 errors; restored cmp-verified. No stash used.

**In-step guard flips (same commit):** ReadPathCensusTest opendir row FsMethods→WorkspaceWalk (CONTAINED verdict travels); ContainedPathInventoryTest::ROUTED_CALL_SITES FsMethods 1→removed, WorkspaceWalk 1 added (count/sum-neutral → ContainedPath docblock word restatement needs no flip). SERVER.md :378 limits-row prose tail sentence appended (manual 1-hunk; generated method region restored HEAD-pristine). No new Lang keys (RpcError protocol messages are raw-English precedent); no suite-figure/durations/dist touches.

**Dormant report:** none — WorkspaceWalk::listing has a live caller (FsMethods::walk); export paging live via sessions.ts.

Deviations: unhandled-rejection pattern in the second-conflict spec (catch attached at promise creation); WorkspaceWalkTest depth-cap chain asserts verified green; pre-existing AccessorDeprecationTest 2-deprecation noise tolerated in all runs.
