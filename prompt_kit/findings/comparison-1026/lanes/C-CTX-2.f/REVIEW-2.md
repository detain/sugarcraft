# C-CTX-2.f REVIEW-2 (round 2) — VERDICT: FINDINGS (0 CRITICAL, 0 MAJOR, 2 MINOR, 2 NIT) — lane weldable

Chain (disk): 9fc915cd9 ← 4a36a153a ← **645e4ca2f** "review fixes (round 1)" — exactly 2 commits. Author/committer detain 2/2, zero trailers, no weld-only files.
FIXER SHA CLAIM WRONG: 6f3a9f38a is NOT a git object (cat-file fails). Real fix commit is 645e4ca2f (landed 17:38 mid-review; my first probe read HEAD=4a36a153a). WELDER MUST PICK 645e4ca2f.

## MAJOR cure — CONFIRMED COMPLETE
Ledger is SHARED in-process (tracker holds readonly object ref to loader; capture seeds local copy, announce/revert write-through, guard reads loader LIVE) and COPIES across the fork only as the 5th envelope key — exactly the ISS-018 sessionState design (EngineBackend.php:5280 child-exit export post-announce, :5448 parent merge before ok-check; Runtime :3856/:3947 per-tool legs generic-key transport). Pair-string encoding is load-bearing: :5527 union flattens values to list<string> (a raw map would arrive fingerprint-only); in_array dedup + json_decode round-trip verified. Repeat dead end-to-end.
Pin name: testTheAnnouncedUpdateRowCrossesTheForkOnTheFifthEnvelopeKey (:220) — simulates the second fork: parent tracker captures BEFORE the child's merge-back, recheck fresh-detects, asserts section byte-identical + drain EMPTY (passes only via live loader read; local copy provably stale at capture).
Envelope: 5 keys byte-identical at all 3 exports (Read.php:215-220 +own ledger, Grep.php:145-151, Glob.php:148-154) + 5th consumed at all 3 merges; fail-soft rows; JSON_INVALID_UTF8_SUBSTITUTE + strval belt.

## Gates re-run (plain pipe, cwd=candy-code worktree) — green
Instruction 320T/1640A EXACT (= fixer msg); Visibility 9T/48A (= fixer msg, ≠ brief); fork-carrier family 115T/6712A; DocFigure|Glob|LangParity|TuiLang|DuplicatedTestHelper 135T/9036A; ReadPathCensus 19T/651A green (no new read sites — §1.8 legit in-step). Brief labels HandlesRunControl/HandlesCommands/Chat-330T/PathRead-14T are not reproducible filter shapes (1T/6A, 0T); 2 deprecations = standing AccessorDeprecation providers.

## FINDINGS
- MINOR (step-local, welder): the "+14T" re-pin and "14T/61A" carry figure matches nothing on disk; real step total is +9 Visibility tests over 5T pre-step base; welder re-pins from green run, not the figure.
- MINOR (out-of-scope, welder note): wide `--filter Chat` shows 10 base-standing reds beyond the disclosed trio (suggestion AsyncCmd ×5, ApplySettings autoReviewScope ×2, DreamPass, ColdResume, CompactionModelSummary) — all proven FOREIGN-inherited: step diff vs 4a36a153a^ empty for src/Chat*, all these test files (git diff --stat empty). Fixer's non-disclosure of this family is a report gap, not a defect here.
- NIT: commit message cites "dated ruling 2026-10-29" (tomorrow) — cosmetic, unamendable pre-weld.
- NIT: guard's local-map leg is redundant behind the live loader leg (loader always written-through) — harmless belt; leave.
