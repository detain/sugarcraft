# C-SEC-6.e REVIEW-1 — tip ee4789c04 (parent de0c54646)

VERDICT: CLEAN

## Scope/traceability
- Diff = exactly the 3 declared files: OtlpEncoder.php [hot], ENVIRONMENT.md [docs], NEW tests/Observability/OtlpSafetyEventTest.php. Zero Chat/Runtime/fork edits — STOP clause correctly untriggered. Author+committer detain@interserver.net, zero Co-Authored. Forbidden files (PathGlob, suite-figure.json, durations.tsv) untouched.

## Gates (re-derived, cwd=candy-code, plain pipe)
- tests/Observability + SessionHostOtelFoldTest + NonInteractiveOtelExportTest: OK 87T/353A exit0 (EXACT).
- OtlpEncoderTest + OtlpSafetyEventTest: OK 25T/121A (EXACT).
- OtlpSafetyEventTest alone: OK 11T/54A (EXACT).
- Guard filter (five-guard+EnvRoster): 171T, exactly 3 failures — staleness pair (pin 25,537 vs live ~27,900, base-inherited W22-era) + GlobDialect 309132-vs-310245 (cured on master by ed5fbffed, later than base; lane's string tokens carry zero wildcards — corpus not shifted). Matches disclosure 1:1.

## Privacy core — PASS
- reason_token is `explode(' ', $kind->value, 2)[0]` — a slice of the ENUM value ('Permission'/'Hook'), structurally incapable of carrying a content word; predicate `str_starts_with($content, $kind->value)` only READS content. Forge 'permission-denied: /home/alice/secrets/key.pem…': lowercase/hyphen form fails starts_with → attribute absent; prefix-matching form exports only 'Permission'. Test 3 (unprefixed carrier) pins the degrade with a sentinel body scan.
- deny: kind token + toolName only; ask: tool/source/mode — source is 'gate'|'hook:<names>'|'tool:<name>' (PendingAsk::source), mode is the permission-mode string; no arguments/reason fields touched. reject/cancel: fixed decision words + kind token + askId — ask_id is a 16-hex hash ALREADY exported at baseline as permission.ask_id/permission.resolved_ask_id (same span), no new exposure.
- Docblock privacy law extended in-class; every positive pin carries a DO-NOT-EXPORT sentinel + whole-body json scan.

## Reachability — PASS
- Single choke absorb():163 feeds addSafetyEvent beside addEventAttributes; both event classes are in attributesKnownEvent, so the same single-open-span targeting law applies (multi-span drop pinned by test 8). Three feed doors carry every observed event unconditionally: Chat.php:3814 (settled queue) / :4183 (live pump, PermissionResolved generation-exempt) / :4206 (coalesced), NonInteractive.php:617 (-p), SessionHost.php:2842 (serve fold-first). Baseline permission.ask_id attribute already proves asks cross the encoder on the fed paths.
- Approvals emit nothing (permits() early-return; denial()==null early-return) — pinned (tests 5, 7). renderSpan omits 'events' when empty → no-verdict spans byte-identical; unchanged OtlpEncoderTest byte pins stay green. Dark OTel never builds an encoder (EventSinkFactory::dark) — untouched seam.

## Weld carries
- DUR: durations.tsv row for candy-code/tests/Observability/OtlpSafetyEventTest.php (11T); suite-figure + README +11T re-pin rides the already-red staleness pair; §4 cite ee4789c04.
