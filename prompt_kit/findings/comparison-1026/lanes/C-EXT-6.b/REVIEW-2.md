# C-EXT-6.b REVIEW-2 (round 2 closure re-review, fresh session) — /hooks log + hook log file

VERDICT: CLEAN — C=0 M=0 MINOR=0 NIT=1

State anchors re-verified by this reviewer: branch cc/C-EXT-6.b; base 288b81f11; picks 2bf5d2f18 + 14f326305 + a2a483550 (tip); `git status --porcelain` = 0 at open and at close.
Round-1 findings re-derived from REVIEW-1.md (19,480 B): MAJOR-1 = session filter unpinned end-to-end (command-level absence needle `session-beta` unrenderable by composeLog); MAJOR-2 = redact-before-cut straddle arm vacuous (3-byte `Zk9` fragment below every needle; all four shipped assertions hold under the order swap).
NOTE: the prior round-2 session's claimed REVIEW-2.md did not exist on disk; this file supersedes that phantom.

METHOD CAVEAT (disclosed up front, per brief): the reviewer shell map blocks `cp`/`mkdir`/compound-with-`tee`, so the in-tree cp→mutate→run→restore→md5 protocol was not runnable by this session and NO repo file was ever mutated. The brief pre-authorizes the alternative: both MAJORs were proven discriminating by CONSTRUCTION against the real production classes — the neuter is shown to be an input-equivalent substitution (dropping the filter argument ≡ tail(20,null) output ≡ dropping the wiring), and the forbidden order is computed with the real SecretRedactor and real MESSAGE_CAP_BYTES. FIX-1.md corroborates with its own cp+md5 mutation logs from a session that had the capability.

## Q1 — Closure of round-1 MAJORs, with a proof this reviewer ran

Baselines (plain pipe, cwd=candy-code, `-c phpunit.xml`, anchored filters):
- HookLogTest: OK, 23 tests / 98 assertions
- HooksLogCommandTest: OK, 11 tests / 39 assertions
- HookLogConcurrentAppendTest: OK, 6 tests / 31 assertions
- HooksCommandTest: OK, 12 tests / 42 assertions
- Family total: 52T / 210A — matches FIX-1.md's claimed 52T/210A (+7A over the 203A builder round) EXACTLY.

MAJOR-1 CLOSED. Proof run: /tmp/opencode/rev6b-r2/NeuterProofTest.php via `php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php` → **OK (2 tests, 18 assertions)**. Construction: 4 rows seeded through the REAL `HookLog(path)->record(REAL HookLogEntry::forRun(...))` (al1/al2 on session-alpha, be1/be2 on session-beta). Under the neuter (tail(20, null) — the output identity of "filter removed" and of "wiring dropped at HooksHostCommand.php:95"), the shipped command-level legs `assertStringNotContainsString('be1')` / `('be2')` on the session-alpha render would FAIL (both substrings present in the neutered render), while the old `session-beta` needle is confirmed absent in both polarities (vacuity re-proven, demotion to labeled anti-echo is honest). The mirrored positive control (session-beta render contains be1/be2 and not al1) holds in production, so the legs cannot be satisfied by an always-empty filter. The fix enters through `HooksHostCommand->run` with a sessionId-bearing CommandContext — same path as production.

MAJOR-2 CLOSED. Same proof file, test 2: the FORBIDDEN order (mb_strcut first, then redact) was computed with the real SecretRedactor and the real 200-byte cap on the shipped fixture — result: exactly 200 bytes, passes ALL FOUR pre-fix legs (whole-token needle, head-8 needle, 'Bearer ' presence, length bound), and CONTAINS `Zk9`. The shipped round-2 leg `assertStringNotContainsString(substr(LEAK_CANARY_TOKEN,0,3))` therefore reddens under the swap. The CORRECT order (production `forRun`) contains no `Zk9` and its last 3 bytes are exactly `[RE` — the builder's mid-truncation of the redaction marker is itself asserted (assertSame passed).

No other round-1 MAJOR exists (REVIEW-1.md: C=0 M=2 MINOR=0 NIT=0).

## Q2 — Does the production code do what the pins claim?

Redaction-before-cut: the ONLY producer of log messages is `HookLogEntry::forRun` — `src/Hooks/HookLogEntry.php:57` redacts (`SecretRedactor::redact`), `:58` collapses to first line, `:67-69` applies `mb_strcut(..., 0, 200)` only when `strlen` exceeds cap. Order is redact → first-line → cut, exactly as the pin claims. `toLine` json_encodes exactly the 7 fields (ts/session/event/hook/verdict/ms/message) with JSON_*_SUBSTITUTE — no second message path exists. No leg is doc-only: the straddle physics are pinned by Q1's construction.

Reachability (REACHABLE-GAP hunt): `/hooks log` reaches the filter through real entry points: `src/Cli/Chat.php:12953` (handleHooksCommand) → `Chat.php:12293` runHostCommand → `commandContext()` supplies `sessionId: $this->currentSessionId` → `src/Host/Commands/HooksHostCommand.php:47-51` routes word `log` → `runLog` → `:95` `HookLog::tail($limit, $context->sessionId)` → filter leg `src/Hooks/HookLog.php:186`. The pinned test drives `HooksHostCommand->run` directly with a CommandContext carrying the session id — the command entry point, not an object-constructing unit test. Writer side: `src/Hooks/HookRegistry.php:1010-1011` records at the spend point gated on `!self::isBuiltInHook($hook)`; `isBuiltInHook` (`:183`) is the same predicate the panel uses (`:381`) — one source, no divergent copy. VERDICT: pins match production; no unreachable leg.

## Q3 — Assertion-inventory regression across a2a483550

`git show --stat a2a483550`: 2 files, +37/−7. Every minus-line is comment/doc prose — zero assertion lines removed.
- HookLogTest::testRedactionPrecedesTheByteCut: 4 legs → 5 (+ `notContains(head-3)`); file assertions 97 → 98 (+1, matches the run).
- HooksLogCommandTest session test: 2 legs → 8 (+al2 presence, +be1/be2 absence, +3 positive-control legs); `session-beta` KEPT (demoted, labeled anti-echo); file assertions 33 → 39 (+6, matches the run).
Arithmetic: +1 +6 = +7A; family 203 → 210. expectException / assertFileDoesNotExist / negative-control removals across the pick: ZERO. No weakening anywhere.

## Q4 — Secret/PII discipline of the persistent log

- Directory: `mkdir 0700` at `HookLog.php:348` under the owned home. Leaf: `umask(0o077)` held strictly around `file_put_contents` (`:302-309`) → 0600 at inode creation. NO `chmod` call exists in any lane src file (grep zero) → structurally no chmod-after-publish window.
- Foreign-directory refusal: `directoryIsUsable` (`:336-387`) refuses (never repairs) on symlink, wrong-type, foreign owner, foreign WRITE bits (0o022, HOME arm) or foreign ACCESS bits (0o077, fallback arm). Caller-supplied paths skip the liveness guards by design (`ownsPath = ctor arg === null`, `:109`) — rotation and pruning also ownspath-gated (`:295`, `:312-322`, single `.1` generation at 1 MiB).
- Symlink leaf refusal at `:288-293` is a NON-static function — the round-1 fix (old static died after latch spend, before the notice) is intact and pinned.
- Reads: window walk ≤ 64 KiB ×4 growth with lstat+regular-file gate before `fopen` (`:253-264`); torn tail dropped (`:235`).
- Secret routes audited: exceptions and notices carry only crafted/Lang strings or `$e->getMessage()` from filesystem context at pre-redacted paths — a raw hook payload cannot reach a notice line (entry is redacted in `forRun` before `record`). Render path runs cells through `Chat::reportField` (control chars cleaned; off-catalog verdict echoed verbatim per its anti-fabrication pin). My proof fixtures used per-process `uniqid()` sentinel dirs and asserted ABSENCE of the canary fragment; the `Zk9` needle is one the code can actually render (proven present in the constructed forbidden-order output).
- No `getenv`/`$_ENV`/`$_SERVER`/`proc_open` in new src → no env/flag surface, no spawn roster duty.

## Q5 — §1.8 guard law across ALL THREE picks

Battery at tip (my runs): `(StderrEmitterCensusTest|ReadPathCensusTest)::` OK 114T/23630A · `(CommandDocsDriftTest|ReadmeRosterDriftTest|EnvRosterDriftTest|DocFigureProseDriftTest|LangParityTest|ChatHostLangCatalogueTest)::` OK 155T/48506A · `(GlobDialectDifferentialTest|PathGlobCorpusTest)::` OK 13T/366A · `DuplicatedTestHelperDriftTest::` 14T/202A/2F (foreign, see Q6) · `TreeWideGuardRosterTest` BARE 17T/3850A/2F (foreign, Q6).

Row-by-row: StderrEmitterCensus MESSAGE_SHAPES `src/Hooks/HookLog.php => 3` + prose FIFTY/TWENTY-ONE + NUMBER_WORDS + anchor widening `[A-Z-]+` — MOVED in pick1 (IN-COMMIT ✓). ReadPathCensus `Hooks/HookLog.php|fopen => SELF_LOCATED` — MOVED in pick1 ✓. COMMANDS.md generated region vs argumentHint — MOVED (pick2 regen; **NIT-1**: the hint shipped in pick1, regen in pick2 → a 1-commit CommandDocsDrift window inside the pick list; tip green, weld takes all three atomically — step-local, non-blocking). HOOKS.md +31 and lang/en.php +16 + Help roster — MOVED in pick1 ✓. DocFigureProseDrift word-numbers — green (guard run above), no figure moved without prose. EnvRosterDrift — NOT-OWED (no env surface added; grep proof in Q4). SuiteSkipRoster — NOT-OWED (no new skips; pcntl arm is fail-polarity assertTrue). check-child-lifetimes — NOT-OWED for src (no spawn); test forks already in `ForkedChildReaperAdoptionTest::SCOPE` (Hooks/) per round-1 ✓. check-one-type-per-file ✓ (single final class per file). DuplicatedTestHelperDrift ACROSS THE THREE NEW FILES — MOVED: builder renamed freshLog→reserveRacePath, WRITERS→RACE_*, LEAK_PROBE_TOKEN→LEAK_CANARY_TOKEN in pick1; both residual 2Fs name zero lane files (Q6). MISSING: none.

## Q6 — Foreign reds adjudication

The two DTHD reds + two TWGR reds above are exactly the pair cured on master at `e78d5dc14` ("cure the two pre-existing master reds"). `git show --name-only e78d5dc14` = AwayRecapTest.php, DuplicatedTestHelperDriftTest.php, TreeWideGuardRosterTest.php — ZERO overlap with the lane's 16 files; lane base 288b81f11 predates the cure, so these reds are expected on the lane tree and BOTH families must be GREEN at the weld (weld applies e78d5dc14's content first; the lane never touches those three files, so the cure cannot conflict). The TWGR failing row names only `Cli/LoginSubcommandTest.php => glob(...)` alignment; the DTHD failures carry the generic polity texts with zero HookLog-named symbols. Other standing reds from the brief's list did not appear in any of my targeted runs (I ran no filter containing them). No NEW family; nothing moved.

## Q7 — Scope + §7 hand-offs

Diff `288b81f11..HEAD` = 16 files; all inside or guard-adjacent to the design block (design-ext.md:483-500, read verbatim from the lane copy): the two census tests are the in-step guard law, tests/bootstrap.php pin was disclosed and judged legitimate in round 1. Never-touch set confirmed: `git show` grep for suite-figure.json|durations.tsv|dist|protocol|SERVER.md|generated.ts|plan_comparison|composer|tsv|run-state across all three picks = ZERO. Vitest N/A (no web files).

Durations rows for the gate to mint (LIVE counts from my runs):
- candy-code/tests/Hooks/HookLogTest.php — 23T / 98A
- candy-code/tests/Hooks/HookLogConcurrentAppendTest.php — 6T / 31A
- candy-code/tests/Host/Commands/HooksLogCommandTest.php — 11T / 39A
(HooksCommandTest pre-existing, now 12T/42A.) Suite-figure/README re-pin derives from the gate's full run; this step adds net 40T across the three new files.

COMPARISON.md cells the §7 gate must refresh: candy-code/COMPARISON.md:320 (HK-08 — "No dedicated hook log view" is now false for the TUI: `/hooks log [n]` + hooks-log.jsonl shipped) and candy-code/COMPARISON.md:910 (roadmap bullet "a hook log view (HK-08)" — delivered). Not edited by this reviewer.

## FINDINGS

- NIT-1 (step-local): pick1 moves the `/hooks log` argumentHint while pick2 regenerates COMMANDS.md — a one-commit CommandDocsDrift window inside the lane's own pick sequence; tip is green and the weld applies all three atomically. No action required beyond the note.

VERDICT: CLEAN — C=0 M=0 MINOR=0 NIT=1. Both round-1 MAJORs closed with pins this reviewer proved discriminating.
