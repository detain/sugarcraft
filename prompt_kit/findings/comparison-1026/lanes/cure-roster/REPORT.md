
## CURE — ReadPathCensus launch-sweep scandir roster (2026-10-27)

Finding: lanes/C-TOOL-7.a/FINDING-readpath-census.md. Branch cc/cure-roster off
master tip a6beaf573; single commit 3eae0176a (author/committer both verified
detain@interserver.net on separate greps, zero trailers, NOT pushed).

Change (tests-only, candy-code/tests/Support/ReadPathCensusTest.php, +12):
the key 'Background/BackgroundShellRegistry.php|scandir' gains two verdict
entries mirroring the existing :504/:1026 row shape, so roster count 2→4
matches the live sinks at :548 and :568:
* :548 NAMES_ONLY — sweepDeadLaunchDirs() lists the registry root's sibling
  `launch-<pid>` names; feed only the signal-0 liveness gate + retention check.
* :568 NAMES_ONLY — the descend into one already-matched `launch-<digits>`
  directory lists the `sh-*` capture names for an mtime check and an unlink.
Dated adjudication comment states the containment leg: the sweep only ever
descends directories matching `launch-<digits>` under its own 0700 shells root.

Gates (cwd candy-code, linked, plain pipe, env -u LINES -u COLUMNS):
* pre-edit: ReadPathCensus filter RED exactly the predicted 2-vs-4 diff.
* post-edit: --filter 'ReadPathCensus|PromptStability' → 36T/1024A/0F.
  (Finding predicted 1020A; +4A is load-bearing and explained: the vocabulary
  arm runs 2 asserts per entry, and this cure adds 2 entries.)
* --filter 'ReadPathCensus' alone 19T green; 'PromptStability' alone 17T green.
* five-guard ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|
  ChildWallClockBudget|DuplicatedTestHelperDrift → 133T, ONLY the staleness pair
  red (25,537 pinned vs 26,041 live — pre-existing era gap, closeout re-pin
  duty; this cure adds zero test methods, only data-array entries).
* php -l clean on the edited file.
