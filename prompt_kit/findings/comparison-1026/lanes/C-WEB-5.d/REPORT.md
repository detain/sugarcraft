# C-WEB-5.d — Web Push wiring (lane report, growing)

STEP=C-WEB-5.d LANE=/home/sites/cc-cmp-C-WEB-5.d (echo verified).
Base = master 895b9d6fd at cut; branch cc/C-WEB-5.d.

## Landed state (this section grows with each commit)

### Slice 1 (committing now): subscription store
- src/Server/StateDir.php: PUSH_STORE_FILE const + doc row (sanctioned: C-WEB-5.c weld §7 names StateDir "a hub the 5.d/5.e wiring lanes consume" — flagged as scope note).
- src/Server/Push/PushSubscriptionStore.php: rows 0600 via PrivateRetainedDir::write, keyed hash('sha256', endpoint) (endpoint is a bearer secret; never a filename), principal-gated remove(), prunePrincipal() (store side of token revocation), load re-validates every row against PushSubscription::new and silently re-persists clean.
- tests/Server/Push/PushSubscriptionStoreTest.php: 7T/32A GREEN (0600, digest key, cross-principal remove refused, prune counts, tampered-row purge, unusable-dir courtesy, resubscribe replace).
- tests/Support/ReadPathCensusTest.php: SELF_LOCATED row for the store's file_get_contents, same commit (§1.8).
- Gates: filter 'ReadPathCensusTest|PushSubscriptionStoreTest|PushMethodsTest' 37T/833A OK (2 PHPUnit Deprecations = baseline house noise); TreeWideGuardRosterTest 17T/3924A OK; DuplicatedTestHelperDriftTest 14T/204A OK (fake-browser tail class diverged from PushCmpFakeBrowser by name+body).

### Substrate reuse proof
Zero diff to src/Server/Push/{PushSubscription,VapidKeys,WebPushCipher/…,WebPushSender}.php — only consumers added: store reuses PrivateRetainedDir/StateDir; PushMethods calls WebPushSender::endpointAllowed (allowlist-first-words, no second validator); ServerContext triggers dial through the sender's single guard chain (allowlist:167 -> TTL -> PublicUrlGuard::guardedDialAddress:178). (git status proves: none of the four core files listed modified.)

## Lang fix note
My initial import `SugarCraft\Code\Support\Lang` was wrong (real: SugarCraft\Code\Lang, per 5.c's WebPushSender); fixed in src before first commit.

### Trio regen-verify-RESTORE (welder carry — picks never carry the trio)
Regenerated IN-LANE with my src, verified green, then `git checkout` restored (proven absent from both picks by git show --name-only):
- candy-code/docs/protocol/candy-code.v1.schema.json: +121/−0 (three push.* method entries + $defs additions).
- candy-code/docs/SERVER.md: +33 total = 3 GENERATED method-table rows (verbatim in SERVER-GEN-ROWS below) + 30 lines of MANUAL prose saved whole-file at SERVER-MANUAL-SECTION.md next to this report (regen never derives hand prose — the welder appends it under the generated methods-table region, before "## More than one project root (workspace hosts)").
- candy-code-web/src-web/protocol/generated.ts: +49/−0.
- candy-code-web/dist: rebuilt via npm run build (vite, 986ms; index.html+manifest churn + asset rename), restored+cleaned — welder re-builds.
- VERIFY lines taken in the REGENERATED state (before restore): phpunit --filter 'ServerProtocolDocumentationDriftTest|ProtocolSchemaDriftTest|CliLangCatalogueTest' → Tests: 21, Assertions: 582, OK (baseline 2 PHPUnit Deprecations). vitest --no-file-parallelism dormantRoster.spec.ts → 1 file 3 tests passed; FULL web suite serial → Test Files 98 passed, Tests 830 passed. npm run build rc0.
- POST-RESTORE expected staleness (welder resolves by carrying regen): ServerProtocolDocumentationDriftTest + ProtocolSchemaDriftTest will redden until the trio lands — this mirrors the C-WEB-5.c/6.f/6.g precedent.

SERVER-GEN-ROWS (the 3 rows gen derives from my MethodSchemas, for welder diff-check):
| `push.subscribe` | write | yes | `endpoint`, `keys` | Store this browser's push endpoint … |
| `push.unsubscribe` | write | yes | `endpoint` | Drop the stored row … answers false. |
| `push.vapidKey` | read |  | — | The VAPID public key (RFC 8292, base64url of the 65-octet P-256 point) … |
(full text regenerates from src/Protocol/Schema/MethodSchemas.php — rows above are the derived-table summary; the file is the source of truth)

### Slice 2 (this commit): RPCs + triggers + wire catalogue + web dormancy roster
- src/Protocol/Methods/PushMethods.php (new), Dispatcher registration, MethodSchemas rows, ServerContext trigger wiring (ctor/new() trailing optional seams; production callers WorkspaceHostProcess/Serve pass positionally — untouched), lang/en.php two keys after the 5.c serve.push.* run (dupscan python: 0 dupes; SORT_STRING law applies to the cli marker block only — no obligation on serve.*, matching 5.c's run), candy-code-web/src-web/__tests__/dormantRoster.spec.ts three rows (guard rule: roster == derived, reasons mandatory).
- tests/Protocol/PushMethodsTest.php: 11T/49A GREEN — spec-scope pins (no live read-scope connection; house ContextInstructionWriteTest stance stated in-file), endpoint refusals (off-allowlist kind endpoint_denied + store untouched; malformed kind endpoint_refused; schema-typed key parts), subscribe→keyHash+principal 'bearer'+0600, unsubscribe true→false, vapidKey 65-byte 0x04 mint-once, triggers: question+completion each dial exactly once with byte-exact re-encrypted generic payloads, followed session dials nothing, opted-in row alone carries the renamed title, delivery never awaited (Deferred answers; 410 prunes the row after the loop runs).
- §7 NEW TEST FILES for the battery/durations owner (live counts, rows NOT minted here): candy-code/tests/Server/Push/PushSubscriptionStoreTest.php 7T/32A; candy-code/tests/Protocol/PushMethodsTest.php 11T/49A. (Web spec gained methods inside existing dormantRoster.spec.ts: 3 tests total in-file.)
- RPC→sender trace (guard order preserved): PushMethods::subscribe → PushSubscription::endpointRefusal + WebPushSender::endpointAllowed BEFORE storing (words only); ServerContext::heard → pushNotify → WebPushSender::send → allowlist :167 → TTL → PublicUrlGuard::guardedDialAddress :178 → cipher+VAPID → pinned Browser POST :200. No second validator anywhere (git proves src/Server/Push/* four files zero-diff).

## Deviations / seams
1. StateDir.php gained PUSH_STORE_FILE const + doc row — sanctioned by the C-WEB-5.c weld §7 note ("a hub the 5.d/5.e wiring lanes consume").
2. REFUSED SEAM (outside files list): token revocation is Serve.php:966 `$token = $store->rotate()` and TokenStore is unreachable from ServerContext. The store-side half shipped (prunePrincipal + pin). Proposed one-line carry by the welder after that expression when a rotate actually happened:
   `if ($rotate) { \SugarCraft\Code\Server\Push\PushSubscriptionStore::forStateDir($config->stateDir)->prunePrincipal('bearer'); }`
   (principal 'bearer' is the owner-token credential class per AuthMiddleware::ATTR_PRINCIPAL.)
3. Lang import correction mid-lane: real facade is SugarCraft\Code\Lang (Support\Lang does not exist); fixed before any commit — no pick ever carried the wrong spelling.

## Final lane state (both picks cat-file'd, porcelain 0, master unmoved at 895b9d6fd)
- Picks: 9a60cdaf4 (store slice, 4 files) + 84a9674cd (wiring slice, 7 files). %ae/%ce detain counts 1/1 each, zero Co-Authored trailers, trio+dist+suite-figure.json+durations.tsv proven ABSENT from both (git show --name-only).
- Post-restore gates (tree as committed): filter 'PushMethodsTest|PushSubscriptionStoreTest|ReadPathCensusTest|CliLangCatalogueTest' → Tests: 45, Assertions: 1053, OK (baseline 2 Deprecations). TreeWideGuardRoster 17T/3924A OK; DuplicatedTestHelperDrift 14T/204A OK.
- filter 'ServerProtocolDocumentationDriftTest|ProtocolSchemaDriftTest' → Tests: 13, Assertions: 361, Failures: 1 — the single red is ProtocolSchemaDriftTest.php:39 comparing live src against the RESTORED schema.json: the sanctioned by-design staleness the welder resolves by carrying the regenerated trio (verified GREEN in the regenerated state earlier: 21T/582A OK incl. this family).
- FOREIGN GlobDialectDifferentialTest 1F adjudicated: 310,245 pinned vs 311,360 derived; my new files contribute ZERO glob-shaped literals (grep of quoted wildcards empty) — base-carried, uncured per roster law.
- Web: dormantRoster.spec.ts committed; full vitest serial in regenerated state 98 files / 830 tests passed; npm run build rc0; dist restored+cleaned.
