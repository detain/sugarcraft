# C-WEB-5.c — Web Push core: VAPID keys, RFC 8291 cipher, allowlisted sender

STATUS: DONE (all three slices committed on cc/C-WEB-5.c)

## Picks (cat-file'd same-shell; author+committer detain counts 1/1, zero trailers each)
- f28c26790 C-WEB-5.c: push subscription value object + RFC 8291 aes128gcm cipher with byte-exact RFC vector suite
- e0d095eed C-WEB-5.c: VapidKeys P-256 pair minted once at 0600 + ES256 assertion per RFC 8292
- 6248dd790 C-WEB-5.c: allowlisted async Web Push sender through the single SSRF rule  (TIP)

## Files
created src/Server/Push/{PushSubscription,PushPayloadCipher,VapidKeys,WebPushSender}.php;
created tests/Server/Push/{PushPayloadCipherTest,VapidKeysTest,WebPushSenderTest}.php;
modified src/Server/StateDir.php (VAPID_KEY_FILE const + doc table row),
lang/en.php (contiguous serve.push.{endpoint_denied,endpoint_blocked,ttl_invalid,transport_failed} run),
tests/Support/ReadPathCensusTest.php (SELF_LOCATED row for the VapidKeys key read),
tests/TreeWideGuardRosterTest.php (WALKS_A_DIRECTORY row, chr(42)-respelled sweep expression).

## §7 NEW test files, LIVE counts (cwd=candy-code -c phpunit.xml, anchored filters)
- tests/Server/Push/PushPayloadCipherTest.php — 9T/21A (RFC 8291 §5 vector byte-exact incl. all five named intermediates; framing/rs/idlen/tag legs; cap edge 3993/3994; per-message salt+pair freshness)
- tests/Server/Push/VapidKeysTest.php — 15T/41A (mint-once, 0600, reload, P-384 remint, strict DER→raw ES256 incl. hand-built leftpad + three forgery refusals, grammar pin, RFC 8292 §2.4 header-bytes pin, 24h exp bound, subject clone)
- tests/Server/Push/WebPushSenderTest.php — 12T/64A (no-dial refusals both doors, allowlist matrix incl. bare-suffix traps, dial-literal/Host/peer_name pin, TTL/CE/content-type headers, 404/410-gone, 307 unfollowed, factory contract token-scan, PEM secret never on wire)

## Gates
Targeted battery (3 Push classes + ReadPathCensus + LangParity + GlobDialect + TreeWideGuardRoster + DuplicatedTestHelperDrift, bare names): 106T/8752A, the ONLY failure is GlobDialectDifferentialTest::testTheCorpusFigureInTheDocBlockIsTheDerivedOne — PROVEN FOREIGN: with every C-WEB-5.c file removed from the tree it still derives 560x556 vs the doc-block's 310,245 (matches the accepted-reds "GlobDialect 1F" entry; not folded, closeout/battery duty). DTHD and TWGR required-green: green. Server\ adjacency run (background, full Server-namespace filter): 499T/6811A, both failures are the accepted-foreign AttachPagingTest pair (base-proven 364a52876); zero Push-class failures. Final tip 6248dd790, porcelain 0.

## Mutation proofs (each restored md5-clean)
- cipher delimiter 0x02→0x01: 3 named reds; pack N→V: 2 reds; key_info ua/as swap: 3 reds
- VapidKeys curve-leg `=== 256` neuter: exactly the P-384 remint test; str_pad neuter: exactly the leftpad test
- sender allowlist `if (false)`: off-family test red; guard `if (false)`: both no-dial tests ERROR (dial attempted); authority→hostname: URL pin red

## Deviations (disclosed)
- No PushSubscriptionTest file: the VO's shape/https/point/16B-auth refusals ride as named legs of the cipher and sender suites (design listed only three test files; this matches it).
- ReadPathCensus word is SELF_LOCATED, not the design-hinted OWNED_HOME: the class takes the caller's state dir (TokenStore house pattern) and the census's own mechanism leg demands HomeDirectory::owned() text an honest row here would not have.
- StateDir carries the VAPID_KEY_FILE const only (no vapidKeyPath accessor — would be dead code; VapidKeys composes dir+const).
- PushPayloadCipher::withEntropy is a test-only fixed-entropy seam for the RFC vector, documented as having no production caller; production ::new() draws a fresh salt + fresh P-256 pair per message.
- E646 honored: connect-phase timeout only (2.0s Connector 'timeout'), no total-request ceiling anywhere.
