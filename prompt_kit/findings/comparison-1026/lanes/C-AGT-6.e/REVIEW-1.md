# C-AGT-6.e — REVIEW-1 (round 1, fresh reviewer)

VERDICT: FINDINGS (1 MAJOR, 1 NIT)

Verified on disk: branch cc/C-AGT-6.e tip 15be076bc, single commit off e91eb1d47 (merge-base confirmed), author/committer Joe Huss <detain@interserver.net>, no trailers, tree clean. Diff = exactly the 8 files the brief declares; no bleed, no dist/, no suite-figure.json, no durations.tsv, no generated trio committed (regen-consistency confirmed independently: ProtocolSchemaDrift diff is ONLY the pr block; ServerProtocolDocumentationDriftTest green). php -l 4/4 clean. Targeted gates (plain pipe, worktree): PrStatusCache 28T/60A OK (report figure exact); GlobDialect+DuplicatedTestHelperDrift+TreeWideGuardRoster green; vitest --no-file-parallelism statusBar+sidebar 28/28 passed. Summary path non-blocking proven (observe registers+answers cache only; structural pin testA... slices summary()); failure shapes all null; self-retiring watch pinned; one shared one-shot timer, no periodic; E646 clean (per-call gh bound rides GhRunner, no blanket budget).

## Findings

1. MAJOR (step-local) — candy-code/src/Workspace/PrStatusCache.php:281. The new
   `if (!\function_exists('proc_open') || !GhRunner::available())` capability
   probe adds a 'proc_open' STRING reference to the tree and
   candy-code/tests/Support/DescriptorInheritanceGuardTest::
   testEveryAppearanceThatIsNotACallIsAccountedFor reddens deterministically:
   `+ 'Workspace/PrStatusCache.php::fetchViaGh: string reference'` (measured:
   15T/1F in the worktree; the merged-tree weld run will hit it too). §1.8
   in-step guard law: the census roster moves in the same commit. The lane's
   gate filter list simply omitted this guard. Fix: add one row to NOT_A_SPAWN
   keyed `Workspace/PrStatusCache.php::fetchViaGh` with `count => 1` and the
   house reason wording (mirror the GhRunner row at :689: "function_exists()
   capability probe: with proc_open disabled the gh pr view watch is skipped,
   the chip stays null"), then re-run `--filter DescriptorInheritanceGuard`.
2. NIT — candy-code/src/Workspace/PrStatusCache.php:196-199. The arm() docblock
   claims the one-shot is armed "at the earliest live due time", but the
   keep-path (same loop already armed) never compares delays — an entry
   registering just after a beat can wait up to one TTL for its first fetch.
   Behavior is benign (chip is decoration, next beat re-earliest); truth-flip
   the sentence or re-arm when nextDelay() falls behind the live timer.

Non-findings checked: sidebar chip is a span not an anchor (row is itself a
link; deviation disclosed in REPORT, R13 silent on anchor shape — acceptable);
web chrome strings English-only matches sibling chips (web i18n is C-TUI-6.f,
LATE); no new protocol method so dormantRoster unaffected; no proc_open/fork
site (rides GhRunner); ReadmeSuiteFigureDrift staleness pair is welder-owned
expected interim red, disclosed; new test file listed in REPORT for the
durations TSV. Fix #1 and it welds.
