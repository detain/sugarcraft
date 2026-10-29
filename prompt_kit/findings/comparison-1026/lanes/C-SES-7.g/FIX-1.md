# C-SES-7.g — FIX-1 (round 1), 2026-10-29

Branch cc/C-SES-7.g, new tip b0fb53309766b41042601fd16ee9856c26a12cff (cat-file-verified), base feda45350. 2 files, +175/−8. Author+committer detain@interserver.net (separate greps), zero trailers. NEVER pushed.

## Cure map

1. MAJOR (loopback DNS bypass) — `RemoteSessionPush.php:isLoopbackHost` — 127. leg now numeric dotted-quad only: `'/^127\.(?:[0-9]{1,3}\.){2}[0-9]{1,3}$/'` (was `'/^127\./'`); docblock states the OCTETS-only law + why (`127.attacker.example` is a resolver, not an address). `localhost`/`::1` name legs unchanged (pinned). Test: `dialAllowedProvider` +4 rows — `ws://127.attacker.example/ws`→false, `ws://127.0.0.1.attacker.example/ws`→false, non-127 numeric `ws://128.0.0.1/ws`→false, numeric-positive `ws://127.1.2.3/ws`→true. Mutation M1 (regex neuter) → exactly those 2 DNS rows red. PASS.
2. MINOR-1 (unbounded answer phase) — `push()` now: resolves `$loop ??= Loop::get()`, arms a ONE-SHOT reject timer (default `Attach::CONNECT_TIMEOUT_SECONDS` — same discipline `Attach::run` applies to this chain; E646 wording in docblock: connection-phase bound on a serve round-trip, not an LLM timeout) over connect→hello→session.import; on fire it closes the opened host (finding named "socket held" as the harm) and rejects. BOTH settle arms `$loop->cancelTimer($timer)` before resolve/reject, `$settled` guard keeps late legs inert. New `?float $answerBound = null` tail param — test seam ONLY, production never passes it (disclosed widening of the fix shape; pushCall at HandoffHostCommand:320 rides the 4-default form unchanged). Pins: `testASilentTargetIsRejectedWithinTheAnswerWindow` (real accept-then-silent WS stub, genuine 101 handshake with RFC6455 accept-sign + subprotocol echo, bound 0.25s, asserts the window's own message — await's 5 s generic message discriminates) and `testTheAnswerWindowIsCancelledByBothSettleArms` (structural body-slice: 2 cancels, success arm cancels before resolve — late uncancelled fire is promise-invisible by construction, loop-hygiene is what the cancel is FOR). Mutations: M2 (timer callback neuted) → silent-target pin exactly 1 red (hangs to await bound); M3 (reject-arm cancel stripped) → structural pin exactly 1 red. PASS.
3. MINOR-2 — NOT touched: Chat.php:19287/:19244 landHandoff error-paint is the hub, out of lane scope; weld-carry stands (expose HandoffHostCommand::isFailure(), flip both rows same commit).
4. NIT — REPORT.md :12 citation fixed §1.5→§1.11 (this dir, on disk).

## Gates (all plain-pipe, cwd=candy-code, -u LINES -u COLUMNS)

- `--filter 'RemoteSessionPush|Handoff'`: 58T/255A green (52T/244A at tip +4 provider rows +2 methods; the 2 PHPUnit Deprecations are the foreign AccessorDeprecationTest collection artifact REVIEW-1 already attributed, unchanged).
- `--filter 'GlobDialectDifferential|DuplicatedTestHelperDrift|ChildWallClockBudget|OneSidedHomeSandbox'`: 43T/12,972A green (no glob-shaped literals added — URLs carry no wildcards, regex literals carry `(`/`$` exemptions).
- `ReadmeSuiteFigureDrift`: staleness pair 2F — expected, +6 live-enum cases, welder re-pin (do-not-chase per brief).
- php -l both files clean. Mutation round-trips cp-verified: md5sum -c OK both files after restores (bbf3e125… / 0e5f7f4d…, backups /tmp/opencode/csess7g-mut/).

## Carries for weld

- DUR row: tests/Server/HandoffRemotePushTest.php already owed to welder (no NEW file added this round — rows for the same file unaffected).
- Suite-figure re-pin: +6T vs lane tip (52→58 in this filter; full live-enum at weld).
- Chat.php:19287 + :19244 hub carry (finding 3) unchanged.
- Lang: the new answer-window message is raw English in src, matching the file's existing 'session.import answered without a session id' precedent — en-only if ever i18n'd (no lang/en.php surface added).
- Timer API note for reviewers: react/event-loop 1.x Timer objects expose no cancel() — the canonical shape `$loop->cancelTimer($timer)` is what the structural pin spells.
