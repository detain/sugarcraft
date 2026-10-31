# C-WEB-5.c — FIX-1 (round 1)

Lane /home/sites/cc-cmp-C-WEB-5.c, branch cc/C-WEB-5.c, base 97201c6af.
Review authority read from disk: REVIEW-1.md 12,057 B @2026-10-31 11:29:29 —
VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 1 MINOR / 2 NIT; all three picks
(f28c26790 / e0d095eed / 6248dd790) covered.

New tip b3a41cfd8e22311da64c9845f381d5bd5d8a67c4 ("git cat-file -t" = commit,
same shell). picks=4, porcelain 0. 4 files, +83/-2. No amend of the three
reviewed picks.

## MINOR-1 — requestTarget() query leg — FIXED, EXEC-PROVEN

Cure: candy-code/src/Server/Push/PushSubscription.php:84-93 —
endpointRefusal() now validates the query with the same pchar law as the
path leg, widened by '?' per the reviewer's regex
(`#^[A-Za-z0-9\-._~%!$&'()*+,;=:@/?]*$#`); refusal sentence "the push
endpoint query carries characters a push service never issues".
requestTarget() docblock (:120) names the construction-time gate.

Probe (r1 could not exec; THIS lane's map allows php):
- parse_url on this build defangs raw CR/LF/NUL in the query to '_' (hex
  measured: 713d5f5f…) — the reviewer's exposure bound is TRUE for control
  octets, and '_' is pchar-legal, so the surviving hostile shapes are the
  printable request-line breakers: '|' '[' ']' '{' '}' '^' backtick space.
- RED-BEFORE (exec, pre-cure tree, tests written first):
  WebPushSenderTest::testQueryShapesAPushServiceNeverIssuesCannotBeConstructed
  FAILED — "construction must gate a hostile query out:
  https://updates.push.services.mozilla.com/wpush/v2/tokenabc?q=a[1]&x=1"
  (WebPushSenderTest.php:92).
- GREEN-AFTER: same filter OK; companion
  testLegitimateEndpointQueriesRideTheRequestTargetVerbatim pins FCM-style
  `key=…&v=2` and the design's `/?;` boundary octets riding requestTarget()
  byte-exact (green pre- AND post-cure — it guards the over-cure direction).

## NIT-1 — isP256 curve_name — TAKEN, EXEC-PROVEN

Candy-code/src/Server/Push/VapidKeys.php:202-211: added
`($details['ec']['curve_name'] ?? '') === 'prime256v1'` leg (one condition).
Vector: VapidKeysTest::
testAStoredEcKeyAtTwoHundredFiftySixBitsOnTheWrongCurveIsReplacedNotUsed —
stores a real secp256k1 (256-bit EC) PEM at 0600, reloads, asserts the state
file was REPLACED with prime256v1. RED-BEFORE exec-proven (failed 'secp256k1'
vs 'prime256v1' at VapidKeysTest.php:227 — old door adopted the foreign
curve), GREEN-AFTER.

## NIT-2 — SearchBackends.php:152 — NOT TOUCHED

Pre-existing foreign guardedDialAddress name-twin; zero edits (name-diff
proof below). Weld/closeout ledger note only.

## Corrected ledger (r1 Q4 ruling stands)

Doc battery 395T/48058A GREEN at tip ⇒ builder's doc-movement REPORT claim
REJECTED-BY-DISK; this fix adds ZERO doc movement — README/DocFigure/
lang/en.php untouched (serve.push.* run already sorted; no new key needed).

## Figures

Trio re-measure vs r1 exact baselines: PushPayloadCipherTest 9T/21A (no
drift), VapidKeysTest 15T/41A → 16T/45A (+1T/+4A NIT-1 pin),
WebPushSenderTest 12T/64A → 14T/70A (+2T/+6A MINOR-1 pins); trio combined
39T/136A (r1 36T/126A +3T/+10A, all in-step, NO new test files — durations
rows for the three Push files remain gate duty with the same live counts:
9T/21A, 16T/45A, 14T/70A after this commit — §7 LIST item 1 takes THESE).
Server adjacency: 502T/6821A/2F = r1's 499T/6811A + this lane's +3T/+10A;
the 2F are exactly the accepted AttachPagingTest pair (both legs named).
TreeWideGuardRosterTest BARE: OK 17T/3889A. DuplicatedTestHelperDriftTest
BARE: OK 14T/204A. ReadPathCensusTest: OK 19T/738A. GlobDialect:
still the foreign 1F at 560×556=311,360 vs doc 310,245 — figure byte-
identical to r1's ⇒ my literals add ZERO corpus shapes (all exempt via
'$'/'('/')'/';' predicate exclusions and >64-char lengths); accept + assert
NEITHER per roster. php -l clean ×4. No composer/package.json/protocol/
SERVER.md/generated.ts/dist/lang edits (git show --name-only: exactly the
four Push files). %ae detain count 1, %ce detain count 1 (separate greps),
Co-Authored-By 0. No amend: the three reviewed picks are untouched parents.
