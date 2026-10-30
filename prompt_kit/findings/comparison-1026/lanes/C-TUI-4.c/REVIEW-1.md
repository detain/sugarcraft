# C-TUI-4.c REVIEW-1 (round 1, fresh reviewer) — written in MAIN TREE (worktree edit map refused); welder copies to the lane dir

VERDICT: FINDINGS — 2 MAJOR, 2 NIT. Not weld-ready until MAJOR-1 and MAJOR-2 are cured.

Reviewed worktree `/home/sites/cc-cmp-C-TUI-4.c`, branch `cc/C-TUI-4.c`, tip `de0f3a81b`, parent
`88118dfce` (matches REPORT), one commit, `%ae`/`%ce` = detain@interserver.net, 8 files +832/−15 =
exactly the design's files.create/modify list + REPORT.md. `[hot:]` region respected; protocol trio /
dist / README / suite-figure / durations untouched.

## MAJOR-1 — unpriced spend serializes as an exact `0` (repo ≥/unpriced law)
- `src/Config/StatusLineSession.php:70` `public readonly float $totalCostUsd` (non-nullable) +
  `src/Chat/Handlers/HandlesRunControl.php:360` `totalCostUsd: $this->spentUsd() + ($turnUsage?->costUsd ?? 0.0)`.
- The bar's own honesty gate is dropped by the gather: `src/Renderer.php:3085-3098` computes the same
  number but shows it only when `hasReportedSpend() || $live !== null`, printing `$?`/nothing
  otherwise ("a backend that reports tokens but has no pricing would otherwise print `$0.0000` for the
  life of the session"). `Usage::$unpricedModel` carries the disclosure everywhere else; the payload
  hands an external script the documented *lower bound* with no signal = fake-free.
- Self-contradiction in-lane: `StatusLineSession.php:42-47` lists "no pricing" among fields that "stay
  `null`", and `docs/SETTINGS.md:803-805` promises "a value this build cannot measure stays `null`" —
  impossible for a non-nullable float. `StatusLineSessionTest::testTheCostSurvivesTheRoundTripInBothExtremes`
  pins the `0` spelling instead of refusing the lie.
- Cure: `?float $totalCostUsd`; gather `(($this->hasReportedSpend() || $turnUsage !== null) ? $spent : null)`;
  flip the pinned literal + add a null-cost leg + flip the SETTINGS.md sentence, same commit.

## MAJOR-2 — the production wiring has zero tests (regression to the original GAP goes undetected)
- `statusLineStdin()` (`HandlesRunControl.php:345-368`) and the arm's `StatusLineCommand::cmd($this->statusLineStdin())`
  (`:281`) are reached by nothing: `grep -rn "statusLineStdin" candy-code/tests/` → empty;
  `grep -rln "StatusLineSession" candy-code/tests/` → only the two unit files, neither touching the trait.
- Mutation-reason: delete the argument (i.e. restore the pre-lane `cmd()`) and all 58 lane tests stay
  green. §1.5 demands a regression test that fails before the fix. The report's M2 "2 Segment ticks"
  reddened on fclose *ordering* (a hang), not on payload presence, so it does not cover this.
- Cure: drive a real Chat through `StatusLineTickMsg` with a `cat`-shaped configured command (fixtures
  exist in `tests/Renderer/StatusLineSegmentTest.php` / `tests/Cli/BootstrapStatusLineWiringTest.php`)
  and assert the settled line decodes to the pinned key list with `session_id` = the chat's id; plus a
  structural leg that the arm passes an argument to `cmd(` (this lane's own source-slice idiom).

## NIT-1 (step-local) — `""` can reach `cwd`/`project_dir`
`Chat::projectRoot()` (`src/Chat.php:8295`) returns `''` when both the stored root and `getcwd()`
fail, while `StatusLineSession.php:44-47` claims unknowns are "never the empty string". Cure: `?: null`
at the gather, or de-numeral the claim.

## NIT-2 (out-of-scope) — `context_percent` denominator can be the 100k fallback
Same as the bar (`src/Renderer.php:3300-3306`, `ContextWindow::FALLBACK_TOKENS`), but the payload omits
the limit so a script can't tell a real window from the fallback. A `context_limit` key or one
SETTINGS.md sentence closes it.

## Priority checks that PASSED (verified, not trusted)
1. Declared risk pinned non-vacuously: `testACommandThatNeverReadsStdinStillFinishesInsideItsBudget`
   uses `true` / `printf hi` (never-read, gone before the bytes) and bounds elapsed by the derived
   `budgetCeiling()` (TIMEOUT + both graces + slack) — a real hang discriminator, not the happy path;
   `testThePayloadOfferAgainstADeadReaderRaisesNoDiagnostic` sweeps an `error_handler` over three
   dead/reading shapes and pins `[]`. The `@`-legs are pinned structurally because EPIPE timing is a
   race the lane honestly declines to command. Cannot block: one `fwrite` of a document pinned `< 16384`
   B against the ~64 KiB buffer (`StatusLineSessionTest::testTheDocumentStaysFarInsideTheKernelPipeBuffer`).
   No unbounded wait in any new test (48 spawn-heavy tests = 6.7 s).
2. E646: `$deadline = microtime(true) + self::TIMEOUT_SECONDS;` byte-identical, still armed AFTER the
   stdin shutdown; added src lines contain zero `sleep|usleep|stream_select|Timer|Loop::|TIMEOUT`
   (only test-side elapsed measurements).
3. Hygiene/roster: no new `proc_open` site (diff adds zero `proc_open` lines — payload rides existing
   `run()`); pipes[0] closed on both branches before any fallible step; drain/wait/kill/`proc_close`
   unchanged; `SessionStore::gitBranchAt` (`src/Session/SessionStore.php:720`) is a pure HEAD read, no
   shell. Lane src adds ZERO read sinks ⇒ no ReadPathCensus / DescriptorInheritanceGuard /
   check-child-lifetimes row owed.
4. Purity/shape: `StatusLineSession` does no I/O and reads no clock (version is a param); `toJson`
   pinned as one literal, key order pinned, `[]`-collapse leg pinned, determinism pinned, flags =
   ScriptHook's set with a real SUBSTITUTE pin. Nothing secret in the payload (provider name only).
5. Ordering: `@fwrite` before `@fclose`; the legacy unwritten-EOF branch is byte-preserved and pinned.
6. `settings.statusLine.help` pre-existed in sorted position (value-only flip ⇒ no new key, no
   Help-roster/EnvRosterDrift row); SETTINGS.md touch is outside the generated markers.

## Gates run (cwd `candy-code`, `env -u LINES -u COLUMNS`, plain pipe; no full/serial suite; PtyRun excluded by filter scope)
- `--filter 'StatusLineSession|StatusLineCommand'` → **Tests: 58, Assertions: 175, OK** (2 base-tree
  PHPUnit deprecations also appear in unrelated windows ⇒ not lane-introduced).
- `tests/Config/StatusLineSessionTest.php` alone → 8T/29A OK; `StatusLineCommandTest.php` alone → 48T/134A OK.
- `--filter 'SettingsSchemaDocDrift|EnvRosterDrift|GlobDialect|ReadmeRosterDrift'` → **80T/40,914A OK**.
- `--filter 'DescriptorInheritanceGuard|ChildLifetime|ReadPathCensus|StderrEmitterCensus'` →
  206T/28,583A/**1F = only ReadPathCensus**, extras foreign (`Server/ServerConfig.php|file_get_contents`,
  `Session/Import/RemoteSessionPush.php|file_get_contents`) — declared standing red + foreign sibling.
- `--filter 'CliLangCatalogue|LangParity|SettingsSchema'` → 81T/6,244A/1F = the declared serve-tls
  sort red (`cli.serve.tls.mismatch`/`missing`), not the settings block.
- NOT run (my map denies bare `php …`): `tools/gen-settings-doc.php --check`,
  `tools/check-child-lifetimes.php`. Green `SettingsSchemaDocDrift` + `ReadmeRosterDrift` is strong
  indirect evidence; welder runs both generators + the tool at the pick regardless (§2 step 2).

## §4 weld carries
- NEW durations row owed: `candy-code/tests/Config/StatusLineSessionTest.php` (8 tests).
- `scripts/parallel-tests-durations.tsv:43` (`StatusLineCommandTest.php 6.432 41`) → **48 tests**,
  re-time it (7 new methods, all process-spawning).
- Figure delta from the current diff: **+15 tests** (7 CommandTest + 8 SessionTest), +85 assertions
  measured across the two files. REPORT.md states no figure delta and says "6 new tests" where the
  file has **7** — count from the tree.
- No child-lifetime / descriptor / ReadPathCensus row owed; no new env var.
- SETTINGS.md = hand-written region only; C-SEC-7.a / C-CTX-4.e regen the generated regions, so
  cherry-pick should text-merge.
