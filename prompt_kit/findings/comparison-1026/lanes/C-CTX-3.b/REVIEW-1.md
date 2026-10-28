# REVIEW-1 — C-CTX-3.b ('#' quick-add, TUI) — lane cc/C-CTX-3.b @ aa8e4fdbf (base 9fc915cd9)

VERDICT: CLEAN (0C / 0M / 0 step-local MINOR; NITs + welder notes below)

## Gates re-derived at tip (cwd=candy-code, plain pipe, env -u LINES -u COLUMNS)
- InstructionQuickAddTest 11T/53A OK (matches report exactly).
- ReadPathCensusTest 19T/651A OK; DenialPrefixRosterTest 17T/155A OK; BangShellTest 11T/74A OK (exact); DocFigureProseDriftTest 92T/4056A OK (exact).
- Guard family DuplicatedTestHelperDrift|GlobDialectDifferential|StderrEmitterCensus|ReadmeRosterDrift|KeyBindingDrift: 356T/24051A OK (new test's Backend double is byte-identical to the BangShellRecordingBackend precedent; no glob-shaped or **Refused:**-shaped literals).
- Broad slice tests/Chat + ContainedPathInventoryTest: 1310T/7F. All 7 FAILS are base-inherited, NOT lane:
  · ContainedPathInventory routed (AgentMemory+1, SkillLoader 3→4) and handSpelled (ContextHostCommand+1, TranscriptProjector+1, PathJail 5→6) legs — all in files the lane never touches (diff name-check empty); Chat.php row NOT in either failure diff ⇒ lane's 1→2 flip is arithmetically correct (§1.8 in-step satisfied).
  · ChatHostLangCatalogue asks about 5 pre-existing keys, none of the 3 new.
  · ApplySettingsTest ×2 (autoReviewScope badge/arm roster), AutomaticCompactionModelSummaryTest /clear-batch count, ColdResumeFinishedAgentTest agent-label row: reproduced IDENTICALLY in sibling worktrees cc/C-EXT-3.b and cc/C-SES-7.e (neither carries this lane's edits) ⇒ global base reds; the REPORT's "3F" base-inherited list is not exhaustive — welder/§7 gate owes these to their owning lanes.
- "PHPUnit Deprecations: 2" in every run = AccessorDeprecationTest private data providers (:168/:202), base-era file, not lane; not failOn-gated.

## Core judgments
1. Entry doors: multi-line, ##, bare #, mid-turn each have a dedicated pin (4 pass-through tests assert pending==null, file absent, and model-visible prompt intact). Read-only refusal + bang arms ordered BEFORE the capture (code-verified in submit()); slash menu/palette/permission/keyHelp arms precede the owner arm in HandlesKeys, and '#' can never co-open the '/' popup ⇒ menu door structurally closed (no pin, unreachable). Single-line PASTE lands on the confirm by design; Esc re-routes — brief's accepted risk.
2. Write path: HOME via HomeDirectory::owned(), dir PrivateDir::ensure 0700 + realpath post-check against owned home (load-bearing, pinned by testADirectoryLinkOutOfHomeIsRefused), is_link refusal (pinned by testASymlinkWearingTheFileNameIsRefusedNeverFollowed), fresh file AtomicFileWriter 0600 (perm-pinned), existing file LOCK_EX append preserving bytes+mode (pinned 0644-kept). No new PathJail surface; reuses loader's PERSONAL_FILENAME so write window == loader read window.
3. Reload: no in-lane E2E pin, but the mechanism is C-CTX-1.a's dep, pinned tree-wide by InstructionDiffTurnContextTest::testAnEditArrivesAsAUnifiedDelta on the same personal AGENTS.md path; the quick-add writes exactly those bytes. Adequate per deps: [C-CTX-1.a].
4. en.php: 3 keys chat.instructions.quick_add.{appended,confirm,refused}, alphabetical slot correct, all consumed via Lang::t; catalogue guard's asked-for leg green. en-only per house.
5. Vacuity (mutation-reasoned, matches builder's M1 math): quickAddNote→null ⇒ exactly the 7 capture/confirm/pin tests red, 4 pass-through green; \n-guard removed ⇒ multiline test red; strlen/#2 guard ⇒ ## + bare-# reds; ltrim ⇒ appended-bytes assert red; owner-arm removed ⇒ confirm-answer tests red; is_link ⇒ symlink test red; realpath post-check ⇒ dirlink test red; append-mode/LOCK_EX ⇒ second-note red; withUiOnly strips ⇒ uiOnly asserts red. Every guard has a dying leg. Note: dedup is NOT implemented and not required by the brief (repeat note appends twice — consistent docs).

## Scope
6 files == BRIEF files.create/modify + serialized [lang,docs:key dispatch] regions + §1.8 roster flip. HandlesKeys.php deviation disclosed and correct (titleEditor precedent). No suite-figure.json / durations.tsv / dist touches; author/committer detain verified; no trailers. Report claim "498/−1" confirmed.

## NITs (no action required to weld)
- N1: cross-tab clear ('pendingQuickAdd'=>null on session change) carries titleEditor precedent but is itself unpinned; blast radius benign (target is the global personal file). Optional weld-time pin.
- N2: "draft byte-exact" is exact for the submitted (trimmed, chips-expanded) text; typed leading/trailing spaces are normalized on restore. Prose vs tested case — fine.
- N3: ctor-promoted docblock reuses the neighborhood's pre-existing off-column /** indent.

## Welder carries (confirmed owed)
- scripts/parallel-tests-durations.tsv row for tests/Chat/InstructionQuickAddTest.php; suite-figure re-pin +11T (+53A wobble) — ReadmeSuiteFigureDrift staleness pair expected at weld, not lane red.
