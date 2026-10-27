# C-CTX-6.d — REVIEW-1

VERDICT: CLEAN (0 CRITICAL / 0 MAJOR / 0 MINOR / 1 NIT-inherent)

Lane: branch `cc/C-CTX-6.d`, single commit `6fcfd0db8` on base `c36cc1982` (log confirmed).
Worktree `/home/sites/cc-cmp-C-CTX-6.d`.

## Gates (reviewer-run, cwd worktree candy-code, plain pipe, -c phpunit.xml, env -u LINES -u COLUMNS)
- `--filter 'MemoryOpenJournal|EditorCommand'` → **OK: 15 tests, 98 assertions** (3 journal + 11 EditorCommandTest + 1 derived provider row; 2 deprecations = pre-existing AccessorDeprecationTest baseline).
- `--filter 'Memory|Editor'` → **737T/6402A/2F** — exactly the two named foreign base-reds. Proven disjoint, not merely claimed: the lane diff is 3 files; neither test nor its production inputs (DreamPass settle-scheduling leg at DreamPassTest:288 `assertNull($none)` for a non-engine backend; SettingsEditor category roster — production now carries the foreign `git` category) appear in it, and `memoryOpenArm` is reachable only from the `/memory open` slash dispatch (:15816) which neither test drives. Red-before-fix on the journal surface itself: builder base pass (tests 1+3 red at base src, test 2 green both worlds) is analytically confirmed below.
- five-guard (`ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift`) → **133T/15720A, failures = staleness pair ONLY**, live 26,124 == REPORT (base 26,121 foreign drift + lane +3T).

## Axes
1. **Behavior contract** — `openFile(string $path, ?\Closure $onSaved = null)` reads the hash at arm time through the `is_file()` door (`sha1_file` on absent returns false sentinel, no PHP warning under failOnWarning — both read sites gated). `openCollect` runs the hook only after the `$error !== null` and `$exit !== 0` doors have returned → exit-0-AND-hash-changed confirmed in code. Chat.php diff = exactly the two `memoryOpenArm` hunks (:15826 docblock, :15860 return). ✓
2. **Journal semantics** — edited open commits `memory: /memory open {$id}` (same `memory: /memory <sub>` shape as the router's `MemoryCommand.php:83`); unchanged open records nothing. Non-vacuity per leg: hook-removal → test 1 red (edit swept into log()'s OUTSIDE commit, both legs flip); hash-compare removal → tests 2 and 3 red; exit/error-gate removal → test 3 legs 3/4 red. The hook's `record()` warning string rides appended to the opened StatusMsg, never swallowed. ✓
3. **No leak to other callers** — production `openFile` call site is ONLY `Chat.php:15867`; the 4-arg `openCollect` test calls are unaffected (params optional); `$onSaved === null` short-circuits the whole hook branch. ✓
4. **Scope** — only new file `tests/Chat/MemoryOpenJournalTest.php` (3T, DUR row listed in REPORT for closeout); suite-figure.json / durations.tsv / dist untouched; one type per file. ✓
5. **E646** — zero timers; commit goes through the pre-existing bounded `GitRunner` spawn (no new proc_open site, roster unaffected). ✓
6. **Laws** — `declare(strict_types=1)` first; final class; §1.9 clean (only docblock `*` hits, no glob-shaped or Refused-shaped string literals); author law `%ae`=detain@interserver.net and `%ce`=detain@interserver.net verified on SEPARATE greps, commit body has zero trailers. ✓

## NIT (inherent, no fix demanded)
- `src/Chat.php:15877` — a concurrent outside-/memory note write landing while the editor is open is committed under the open's subject (single-commit hook shape). The router's commit-OUTSIDE-first pattern cannot be copied here because at hook time the user's edit is ALREADY on disk — an outside-sweep would file the user's own edit under `OUTSIDE_SUBJECT` instead. Bounded window, alternative strictly worse; record as design consequence, not a repair target.
