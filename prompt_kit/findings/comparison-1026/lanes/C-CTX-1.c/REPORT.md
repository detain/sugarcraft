# C-CTX-1.c — @import children join the instruction-diff census

COMMIT: 2119422aa91236d6ff9b3ec1d257d89dba811ec5 on cc/C-CTX-1.c (base ebf05989e, one commit, never pushed).
Author/committer detain@interserver.net verified by separate %ae/%ce greps; zero Co-Authored trailers; tree clean.

## Files (5, +255/-40)
- src/Context/ImportResolver.php — expand()/expandOutsideFences()/expandReferences() gain `?array &$inlined = null`; the realpath is appended ONLY at the inline-success splice point (blocked/deferred/skipped not collected), threaded through the recursion so grandchildren are collected at every depth.
- src/Context/InstructionFileLoader.php — expandImports() gains `?array &$children = null` threaded to the collector; the four expanded-row sites (loadRoot, loadPersonal, loadAncestorRoots, loadForced) carry `'imports' => $children`. Body-null/'' rows keep the 3-key shape (InstructionBudgetTest:164 exact-key pin preserved). Row-shape docblocks `imports?: list<string>` (9 sites). loadForPath left 3-arg.
- src/Context/InstructionDiffTracker.php — public const MAX_TRACKED_CHILDREN = 32; capture() freezes roots first, then children stat-only per root (budget counts considered; realpath dedup can never steal a root's body/sha entry); recheck() statOnly branch sits after the moved-check, before the size gate: moved stat → 'no-baseline' pending → existing degradation line renders "the file has no diffable frozen baseline. Read <path>" — one pointer line, no read, no re-splice; unchanged child replays nothing pre-change and is byte-stable after. DECLARED LIMITS bullet rewritten honestly (old blindness claim removed); entry shapes +`, statOnly?: bool}` (5 docblock sites).
- tests/Context/InstructionDiffTurnContextTest.php — 3 new pins: (a) child-only edit → exactly one child pointer line, no '@@', no CLAUDE.md row; (b) same-size byte-different child with restored mtime → '' (stat-gate, never hashed), forward touch → pointer appears; (c) 33 children all changed → substr_count('no diffable frozen baseline. Read ') === MAX_TRACKED_CHILDREN and child-32 (last in order) absent. Pre-fix red by construction: (a)/(c) could not pass on the old tracker (children were not tracked at all — the docblock declared it).
- docs/MEMORY.md — new '### Instruction diff' subsection appended inside '## Instruction files', immediately before '## On-disk layout summary' (distinct section, append-only — C-CTX-2.c seam note below).

## Gates (cwd candy-code, linked vendor [copied from main tree — sibling symlinks are relative, re-homed automatically], env -u LINES -u COLUMNS vendor/bin/phpunit -c phpunit.xml, plain pipe)
- InstructionDiffTurnContextTest alone: Tests: 13, Assertions: 54, OK.
- Context family exact classes (InstructionDiffTurnContext|InstructionFileLoader|InstructionFileLoaderContainment|InstructionFileLoaderMidSessionNotice|ImportResolver|InstructionBudget|PersonalInstructionFile|GlobDialectDifferential|ReadPathCensus): Tests: 196, Assertions: 1407, 0F.
- ContainedPathInventoryTest EXCLUDED from that filter: it is RED AT BASE in this worktree (2 failures naming Memory/AgentMemory.php, Skills/SkillLoader.php 3→4, Host/Commands/ContextHostCommand.php, Host/TranscriptProjector.php — files this lane never touched; proven by checking out pristine files and re-running: identical 2F). Foreign-inherited census drift from the weld base; belongs to the closeout/gate owner, not this step.
- DocFigureProseDriftTest|MemoryDocumentationDriftTest: Tests: 99, Assertions: 3988, OK (MEMORY.md paragraph lands with them).
- Five-guard (ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift): Tests: 133, Assertions: 15759, Failures: 2 = EXACTLY the staleness pair (headline + artifact vs live enumeration; +3 methods this lane, plus foreign-era drift). Closeout re-pin carries; suite-figure.json/README/durations.tsv untouched per law.
- "PHPUnit Deprecations: 2" present in every run including base — pre-existing provider-visibility noise (matches sibling lanes).

## Seams / notes
- MEMORY.md collision: landed in a distinct `### Instruction diff` subsection between '### Containment' and '## On-disk layout summary'. If C-CTX-2.c appends the same anchor region, weld must union (both are append-only; no edited shared lines).
- Cap semantics: MAX_TRACKED_CHILDREN counts children CONSIDERED per root (dedup-skips consume budget — one stat each is the paid cost); documented in the const docblock.
- ImportResolver other callers unaffected (new param optional); no 9th ContainedPath read call site added (collector reuses the resolver's existing realpath $shown); no new file_get_contents anywhere (children never read, by design).
- DUR: none — modify-only (no new test files).
- Grandchildren (imported files that themselves import) ARE collected transitively at all depths, same cap window per root document.

## Round 2 (review fixes)

- FIXED [MINOR step-local] REVIEW-1 item 4: MAX_TRACKED_CHILDREN docblock now states the budget counts children CONSIDERED per root in expansion order — realpath-dedup or stat-failed children consume budget without freezing, so a later root may track fewer new children. One docblock sentence (src/Context/InstructionDiffTracker.php, const docblock); behaviour untouched.
- Gates at fix commit: `--filter InstructionDiff` 14T/60A OK (the filter string also matches the sibling InstructionDiffTrackerTest — the builder/reviewer's exact-class run is 13T/54A; both green); five-guard 133T/15759A with ONLY the staleness pair red (25,537 pinned vs live enumeration), per expectation.
