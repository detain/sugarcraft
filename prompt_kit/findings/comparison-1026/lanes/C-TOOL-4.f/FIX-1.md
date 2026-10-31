# C-TOOL-4.f — Egress proxy core: FIX-1 (round 1 fixer)

Commit `fd274f225` — `C-TOOL-4.f: review fixes (round 1)` on `cc/C-TOOL-4.f` (follow-up atop picks 79e786867 + 4bbf922b2; no pick amended; tip cat-file'd same shell, %ae/%ce detain 1/1, zero trailers, porcelain 0).

## MINOR-1 — FIXED

Review wording: "src/Tools/Sandbox/EgressProxyProcess.php:152 calls $this->discardDirectory() — undefined method (only discardScaffold() exists; sites :131/:152/:221/:233). Reached only when proc_open returns false; an Error('Call to undefined method') replaces the intended RuntimeException('Failed to spawn egress proxy process') ... Fix: rename :152 to discardScaffold()."

Cure: renamed the :152 call site to `discardScaffold()` (one-line, the reviewer's exact cure).

Pin: `EgressProxyProcessTest::testEveryInstanceCallInTheSpawnClassResolvesToADeclaredMethod` (new method, existing file — no new test file, no durations row). The proc_open-false branch is **not behaviorally driveable from a unit test**: the spawn command is always the running `PHP_BINARY` (plus the probed setsid wrapper — `ProcessContainment::detachedSpawnBinary()` is a per-process memo a test cannot point elsewhere, and the `!is_executable(PHP_BINARY)` pre-check already owns the only controllable refusal shape), and `proc_open()` answers false only when the fork itself fails. So the pin is static, exactly the shape the rename is judged by: it lexes every `$this->method()` call site out of the class source via `PhpToken` and asserts each resolves via `method_exists`, with a >=4-site blindness floor.

RED-before/GREEN-after proof (both run on this tree, not inferred):
- Before the rename (pin added against the broken source): `FAILURES! Tests: 1, Assertions: 4, Failures: 1` — failure text `'discardDirectory at line 152'`.
- After the rename, full family: `Tests: 36, Assertions: 117, OK` (reviewer's 35T/113A + 1T/4A pin).

## NIT-1 — DECLINED (with reason)

Unbounded pre-dial `$pending` buffering (EgressProxy.php:137 + bridge relay). Per the reviewer's own note this is jail-local DoS-of-one bounded by the 10.0s/5.0s connect windows and is a "consider a cap in 4.g polish" item. Any cap introduces a NEW drop-close leg with its own flush-discrimination burden (the same structure the §7 carry list already owes mutation proof for) — reshaping dormant security-core semantics for an optional NIT risks exactly the guard-cascade churn my brief forbids. 4.g scope.

## NIT-3 — TAKEN (comment cure, as the reviewer suggested)

The stdout descriptor leg comment in `EgressProxyProcess::start()` now states WHY the undrained pipe is legal (STUB never writes there) and what a future STDOUT-printing stub MUST do (paired reader or route to the STDERR log — an undrained pipe buffer fills and blocks the child's boot). Comment tokens only: census surfaces untouched (proved below).

## NIT-2 — NOT TAKEN (deferred to 4.g by the review) — proven untouched

No lang keys added or moved: `git diff` touched exactly two files (`src/Tools/Sandbox/EgressProxyProcess.php`, `tests/Tools/Sandbox/EgressProxyProcessTest.php`); `candy-code/lang/en.php` byte-untouched.

## §1.8 — non-owed, proven

- ReadPathCensusTest + DescriptorInheritanceGuardTest grep: `discardScaffold`/`discardDirectory` appear ZERO times in either roster (rows key `file|expression` and `file::start`; neither the fopen/require licensure expressions nor the proc_open spawn moved).
- Grepping the added src lines for every census sink spelling (`file_get_contents|readfile|scandir|opendir|GlobIterator|SplFileObject|DirectoryIterator|glob|fopen`): zero hits (comments are T_COMMENT prose; the rename line is a `$this->` call).

## Gate ledger (cwd=candy-code, linked vendor, env -u LINES -u COLUMNS plain pipe; PtyRunToolTest::testExpectMiss never in scope)

- Egress family: `36T/117A OK` (Policy 17/39 + Proxy 8/24 + Bridge 5/19 + Process 6/41, all in one anchored run) — reviewer's 35T/113A + the new pin.
- ReadPathCensusTest + DescriptorInheritanceGuardTest anchored pair: `34T/5892A OK` (= reviewer's 19T/734A + 15T/5158A exactly).
- TreeWideGuardRosterTest BARE: `17T/3873A OK` (exact).
- DuplicatedTestHelperDriftTest BARE: `14T/204A OK` (exact).
- StderrEmitterCensusTest + ForkedChildExitConventionTest + DuplicatedDocBlockLineTest: `103T/23218A OK` (95T/23135A + 4T/60A + docblock family 4T/23A — all green, new docblock lines carry no duplicated prose).
- The `PHPUnit Deprecations: 2` under every run = the accepted global AccessorDeprecationTest :168/:202 provider pair, unchanged.
- GlobDialectDifferentialTest: NOT run, no figures asserted from it (accepted foreign PathGlob red); my added literals contain no wildcard characters.

## Files touched (2)

- candy-code/src/Tools/Sandbox/EgressProxyProcess.php (rename :152; stdout-leg contract comment)
- candy-code/tests/Tools/Sandbox/EgressProxyProcessTest.php (+1 test method + 2 imports)

Forbidden set untouched (suite-figure.json / durations.tsv / web+protocol trio / root files / .opencode); no `php scripts/refresh-deps.php`, composer, npm, or git run against the main repo (this file written directly); no existing pick amended; nothing pushed.
