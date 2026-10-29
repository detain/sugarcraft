# C-SES-7.g — /handoff --remote <url> teleport — CLOSED 2026-10-29

Branch `cc/C-SES-7.g` @ /home/sites/cc-cmp-C-SES-7.g, base 0c1ad6c64.
Commits (all cat-file-verified, author+committer detain@interserver.net, zero trailers):

- `a2fcf5639ae3fe7323a8290b8d99288bc5e9be67` — step 0 skeleton (RemoteSessionPush.php + HandoffRemotePushTest.php class-resolve pin, +49)
- `feda45350ec72316d0576269f4b48eddb4704c0b` — full implementation, 8 files +599/−20

## What landed
- `src/Session/Import/RemoteSessionPush.php`: websocketUrl (delegates Attach::websocketUrl), isLoopbackHost, dialAllowed (wss always; ws only loopback — C-WEB-5 TLS law), fitsWithinImportBound (SessionMethods::IMPORT_MAX_CONTENT_BYTES public const), resolveToken (env CANDY_CODE_SERVER_TOKEN as-is; else state-dir token file @lstat regular + /^[0-9a-f]{64}$/ — read-only, NEVER mints: TokenStore::token() rotates), push() = Attach::connect → hello() → session.import {format:candy-code, content} → resolves new session id, closes host on every path, rejects on any failure (command thunk converts rejections; nothing hangs).
- `HandoffHostCommand`: run() routes `--remote` first-word to private remote(): usage/bad_url/insecure/too_large/no_token ALL refused before a socket opens; success = reply cmd.handoff.remote.dialling + async effect (pushCall thunk → HandoffSeededMsg remoteUrl set, sessionId null so no local switch); describe()/row() branch remote first (isFailure: remote→error!==null, local→sessionId===null — host-path row color honest).
- `HandoffSeededMsg`: +1 tail param `?string $remoteUrl = null` (§1.11 root-cause necessity — the Tests rule §1.5 was a mis-cite, fixed per REVIEW-1 NIT: the TUI needs the arm marker; disclosed).
- `lang/en.php`: cmd.handoff.hint → `[--remote <url>|focus]`; 8 keys cmd.handoff.remote.{attached,bad_url,dialling,failed,insecure,no_token,too_large,usage}. builtin-commands/3600-handoff.php untouched (reads the hint lang key — design law honored).
- `docs/COMMANDS.md`: generated table hint cell via `php tools/gen-command-docs.php --write` (escaped `\|`) + hand prose teleport paragraph. `docs/SERVER.md`: teleport paragraph at end of attach section, LINE-DISJOINT from C-AGT-4.c's StatusBar/agent regions.
- Tests: `tests/Server/HandoffRemotePushTest.php` (DUR-carry, new file): live loopback serve round-trip (session lands remotely, attach line exact, export on the serve carries the transcript), refused-token → failure row + zero list change, missing token / non-loopback ws:// / bad url / usage → refuse with no effect, wss:// passes validation without dialing, resolveToken direct table, dialAllowed provider (6), import-bound edges. `tests/Chat/HandoffCommandTest.php:270` hint pin flipped in-step (guard law).

## Gates (worktree, linked vendor 23/23, cwd=candy-code -c phpunit.xml, plain pipe)
- `--filter 'Handoff|RemoteSessionPush|HandoffRemotePushTest'` → **OK 52T/244A** (0F/0E; 2 PHPUnit Deprecations pre-existing base family — same 2 on untouched HandoffCommandTest).
- `--filter 'CommandDocsDrift|CommandsTableTakesColumnDrift|EnvRosterDrift|GlobFigureDrift|ReadmeRosterDrift|ServerProtocolDocumentationDrift|DocFigureProseDrift'` → **OK 216T/47,202A**.
- php -l clean ×6. Full suites never run (law). ReadmeSuiteFigureDrift staleness pair owed to weld (test files added: +~15 live-enum cases).

## Disclosures
1. Token/stateDir resolution duplicates Attach's PRIVATE idiom (second reader; same two CANDY_CODE_SERVER_* env names, no new env surface — EnvRosterDrift green).
2. TUI landHandoff (Chat.php hub, forbidden) hardcodes withError(sessionId===null) → a remote SUCCESS row prints correct text but error-colored on the TUI path; host/serve path (row()) is correct. Chat-owned law, not fabricated around.
3. Chat.php:19244 docblock `/handoff [focus]` now stale — hub file seam for closeout.
4. No new proc_open/sockets beyond Attach::connect; URL validated before any dial; non-loopback ws:// refused per risk law until C-WEB-5.
5. protocol trio untouched (no MethodSchemas change → no regen needed). suite-figure.json/durations.tsv/dist/ untouched (weld duty; DUR row for HandoffRemotePushTest.php owed by gate).

---

## Weld erratum — batch-18g (2026-10-29, welder-18g; disclosure-only, history above stands)

- **Superseded figures.** The body's gate figures (52T/244A etc.) were the pre-r1 branch state; post-r1 lane truth = 58T/255A on the `Handoff|RemoteSessionPush` filter with the file at 15T, and at weld the merged tree answers **60T/288A 0F** (filter is class-wide — neighbor Handoff-named rows landed on master since the lane cut; +2T/+33A attributed, nothing red). The new file standalone measures **21T/73A / 3.466s** (median of 3 single-file green runs) — that is the minted `scripts/parallel-tests-durations.tsv` row.
- **Lead-flagged '13/13→15/15'.** No such literal exists in any evidence file of this lane (grep-verified at weld time). The stale-figure the ruling pointed at is the REPORT gate figure superseded post-r1 (corrected above); the only REVIEW-2 residual besides MINOR-1 was the cosmetic one-tick wording NIT ("window rejects before the connector's own 15s line prints") — disclosure-only, no action, per the ruling.
- **Weld-minor rider landed.** REVIEW-2 MINOR-1 (late-dial success arm unguarded vs an already-expired answer window) fixed in-weld: `RemoteSessionPush::push()` connect-success arm now captures `&$settled` and closes the late host, staying rejected ('the teleport answer window expired before the dial completed'); `testTheAnswerWindowIsCancelledByBothSettleArms` structural pin extended to the inner arm — mutation-proven (guard stripped → exactly 1F; restored md5-clean). No r3 round per the MCP-2.c precedent ruling.
- **Open seams unchanged:** Chat.php:19287 `withError(sessionId===null)` hub carry + Chat.php:19244 docblock stale — separate future weld-carry commit (brief 2/3), still not this weld (Chat.php untouched).
