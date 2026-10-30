# C-TUI-4.c — statusLine session JSON on stdin (GAP-UI-23)

**RESULT: DONE.** Branch `cc/C-TUI-4.c`, base `88118dfce`, tip committed this lane (SHA
cat-file-verified below). Targeted gate window: **254 tests / 49,193 assertions / 1 failure
— the declared standing red `CliLangCatalogueTest::testTheCliBlockIsOneSortedRunBetweenItsMarkers`
(serve-tls keys, not this lane's region)**. Mutation battery: **11 applied, 11 discriminated,
11 restored-clean** (cp + cmp, never git-checkout on a dirty file).

## Landed shape

`StatusLineCommand::run(?string $cwd = null, ?string $stdin = null): string` now writes the
session document to the child's stdin, then EOFs, then arms the deadline — exactly as the
design prescribed. `cmd(?string $stdin = null)` records the snapshot in
`private static ?string $lastStdin` (before its gates, so even a gated call records);
`refresh()` (the synchronous no-pcntl path) replays `run(self::$cwd, self::$lastStdin)`;
`configure()` clears the memo. A `null`/empty payload keeps the legacy immediate-EOF
contract verbatim — the pre-existing `cat; echo done → done` pin is untouched and stayed
green throughout.

The StatusLineTickMsg arm in `HandlesRunControl.php` builds the snapshot per tick from the
accessors the status bar already reads: `currentSessionId()`, `workingDirectory() ?:
getcwd()`, `servedModel() ?? model()` (EngineBackend-gated), `projectRoot()`,
`spentUsd() + liveUsage()?->turnUsage?->costUsd` (mirror of Renderer:3083-3089),
`provider()->name()`, `currentPermissionMode()?->value`, `contextTokens()`,
`(int) round(contextUsagePercent() * 100)` (mirror of Renderer:3317), and
`SessionStore::gitBranchAt(projectRoot())`.

### Exact stdin JSON (StatusLineSession::toJson)

```json
{"session_id":"ses_…","cwd":"…","model":{"id":"claude-…","display_name":"claude-…"},"workspace":{"current_dir":"…","project_dir":"…"},"version":"…","cost":{"total_cost_usd":1.25},"candy_code":{"provider":"anthropic","permission_mode":"default","context_tokens":12345,"context_percent":42,"branch":"main"}}
```

Claude-Code-compatible core keys first; candy-code extras namespaced under `candy_code`
(a named superset — a stock statusline script ignores it and works). Compact
(json_encode default spacing), flags
`JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE`
(house style, ScriptHook.php:1300). Unknown values serialize as JSON `null` — every key
is always present; fixed literal keys mean no nested map can collapse to `[]`
(the sibling-lane stdClass bug class, pre-empted; pinned). `display_name` equals `id`
(disclosed deviation: no separate display label exists in the backend accessors).

## The declared risk: write to a child that exits early

Two one-step calls, both `@`-suppressed:

```php
if ($stdin !== null && $stdin !== '') {
    @fwrite($pipes[0], $stdin);
    @fclose($pipes[0]);
} else {
    fclose($pipes[0]);
}
$deadline = microtime(true) + self::TIMEOUT_SECONDS;   // UNMOVED — still armed after the close
```

- **Cannot block:** the payload is far under the ~64 KiB kernel pipe buffer (pinned
  structurally: absurd-field snapshot < 16384 bytes), so one `fwrite` either lands whole
  or EPIPE-fails; no partial-write retry loop exists or is attempted.
- **Cannot raise or warn:** PHP ignores SIGPIPE, so a dead reader answers EPIPE as
  `fwrite` returning false/short and a flush-failing `fclose` — an offer declined.
  Un-suppressed it would warn mid-TUI-paint and trip `failOnWarning`; the `@` contract is
  borrowed from ScriptHook's stdin pump ("a diagnostic this pump EXPECTS").
- **Pinned both directions:** behavioral — `cat` echoes the payload byte-identically;
  `cat; echo done` yields payload-then-done (EOF after payload); `true` and
  `printf hi` (never-read / dead-by-the-write) still finish inside `budgetCeiling()`,
  with an `error_handler` sweep asserting **zero diagnostics raised**; empty payload
  keeps legacy EOF. Structural (deterministic) — source-slice pin asserts
  `write-offset < close-offset < deadline-offset` and the exact `@`-counts
  (`'@fwrite($pipes[0], $stdin);'===1`, `'@fclose($pipes[0]);'===1`,
  bare `'fclose($pipes[0]);'===2`). The structural `@`-count legs are the
  race-independent guard for suppression.

### Deadline preservation (E646)

`$deadline = microtime(true) + self::TIMEOUT_SECONDS;` — byte-identical, same constant,
still armed immediately AFTER stdin closes. **No wall-clock timer added, lengthened, or
moved earlier anywhere in the lane.** Proof: `git diff 88118dfce -- StatusLineCommand.php`
contains zero +/- lines mentioning the deadline assignment (grep-verified), and the
structural pin fails if it is ever armed before the stdin shutdown.

## Mutation battery (all restored-clean)

| # | mutation | reddened pins |
|---|---|---|
| M1 | drop `@fwrite` | payload+EOF, byte-identical, structural, cmd-records/refresh (4F) |
| M2 | fclose before fwrite | + hang-class: never-reads + no-diagnostic + 2 Segment ticks (6E/1F…) |
| M3 | strip `@` from both | structural `@`-count legs (1F) — behavioral no-warning pin stayed green (race-dependent, disclosed) |
| M4 | arm deadline before stdin block | structural order leg + wholesale budget collapse (42-44E, decisive) |
| M5 | drop payload-branch `@fclose` | cat/EOF tests (writer end held open → 1.0s budget hang) (4F) |
| M6 | `cmd()` stops recording | refresh pin (1F) |
| M7 | `refresh()` drops `$lastStdin` arg | refresh pin (1F) |
| M8 | `configure()` keeps stale | refresh pin (1F) |
| M9 | drop SUBSTITUTE flag | invalid-UTF8 pin (1F) |
| M10 | drop UNESCAPED flags | literal + slashes/unicode + invalid-UTF8 (3F) |
| M11 | `display_name ?? ''` decorates nulls | complete-document null leg (1F) |

## Doc/lang chain

- `settings.statusLine.help` (lang/en.php:3838): key **already existed** in sorted
  position — value flipped only (appends the stdin-JSON sentence). No new key ⇒ zero
  sort/family risk; CliLangCatalogue green (its one failure is the declared serve-tls
  standing red). The help string appears nowhere in SETTINGS.md/README (verified), so no
  doc pin broke. Source: InterfaceSettings.php:76 `withHelp`.
- `docs/SETTINGS.md`: statusLine prose is **hand-written** (~:779, design said ~L661);
  a factual paragraph added inside it. Generated `<!-- settings… -->` regions untouched;
  `php tools/gen-settings-doc.php --check` → "up to date", rc0, before and after.
  **Collision note for welder:** my touch is outside the generated markers; C-TOOL-2.c /
  C-SEC-7.a regen the generated regions — cherry-pick should text-merge cleanly; if not,
  hand-written region wins per design, generated wins for theirs.

## Spawn/roster position

No new `proc_open` site: the payload rides the existing `run()` spawn. Branch source is
`SessionStore::gitBranchAt` — a pure `@file_get_contents` of `.git/HEAD` (audit-B1
"reads HEAD and runs nothing"). `php tools/check-child-lifetimes.php` at tip:
**61 libs, 33 proc_open sites, 9 findings/9 accounted, 0 problems, rc=0** (identical
site-count to base — no roster row owed). `DescriptorInheritanceGuard` untouched.

## Files

- create `candy-code/src/Config/StatusLineSession.php` (pure VO + toJson)
- create `candy-code/tests/Config/StatusLineSessionTest.php` (8 tests) — **NEW durations
  row owed by welder: `candy-code/tests/Config/StatusLineSessionTest.php`**
- modify `candy-code/src/Config/StatusLineCommand.php` (`$lastStdin`, `workingDirectory()`,
  `cmd($stdin)`, `run($cwd,$stdin)` write-then-EOF, `refresh()` replay, docblock truth-flip)
- modify `candy-code/src/Chat/Handlers/HandlesRunControl.php` (tick-arm gather + memoized
  `statusLineStdin()`)
- modify `candy-code/lang/en.php` (:3838 value)
- modify `candy-code/docs/SETTINGS.md` (hand-written statusLine paragraph)
- modify `candy-code/tests/Config/StatusLineCommandTest.php` (6 new tests + legacy docblock)

## Lane-caught pitfall (disclosed)

First arm version called `Help::version()` per tick — on a dev checkout `versionString()`
shells `git rev-parse` **per call** (ISS-082, no memo), and the per-tick cost crossed the
2.0 s refresh TTL, reddening `StatusLineSegmentTest` tick pin at #4. Proved mine by
base-blob swap (red deterministic on tip / green on base), fixed with a process-wide
`static $version` memo in `statusLineStdin()` (self-update restarts the process, so the
memo can never serve a stale post-update version). Net effect: the feature **removes**
per-tick child spawns it would otherwise have added.

## Remaining disclosures

1. Design drift: SETTINGS.md schema prose lives ~:779 (not ~L661); guard is
   `tests/Config/Settings/SettingsSchemaDocDriftTest.php` (green, no flip needed since the
   generated regions don't embed the help text).
2. `refresh()` has zero production callers today (install path is `cmd()`); the snapshot
   replay still matters because it is the no-pcntl fallback shape and first-paint sync.
3. Path surface: `cwd`/`project_dir` are absolute local paths — visible only to the
   user's own configured statusLine command (which already runs with inherited env in
   that cwd; pre-existing trust boundary, user-tier setting; NOT a network surface).
4. No new env var, no protocol-trio movement, `suite-figure.json` / durations.tsv / dist
   untouched (per law); the 2 pre-existing AccessorDeprecationTest deprecations are base
   tree, green-equivalent.
5. `cost.total_cost_usd` for an unbilled session serializes `0.0` as bare `0` (PHP
   shortest-roundtrip); the VALUE round-trip is pinned, not the spelling.
