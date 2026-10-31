# C-WEB-5.c — REVIEW-1 (fresh r1; predecessor session proven dead, zero inherited artifact)

VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 1 MINOR / 2 NIT

Lane /home/sites/cc-cmp-C-WEB-5.c, branch cc/C-WEB-5.c, base 97201c6af, tip 6248dd790
(re-derived: `git log --oneline master..HEAD` lists THREE picks f28c26790 / e0d095eed /
6248dd790; porcelain clean; 11 files +1797). Builder REPORT re-statted: 3,902 B
@2026-10-31 10:18:17 = brief claim; read fully, all claims verified vs disk.
MUST-COVER clause: all three picks reviewed and named below.
Terminology note: the resume brief said "cURL handle" + "sodium_crypto_scalarmul";
the DESIGN block (design-web.md:610-636, lane copy) says react/http Browser +
openssl_pkey_derive + ext-openssl only, and the implementation follows DESIGN.
Paraphrase artifact, not a finding.

## Q1 — SSRF (the core of this step) — PASS

- Dedicated transport, never WebFetch's client: WebPushSender.php:241-252 builds its
  own `new Browser(new Connector(['timeout'=>2.0,'tls'=>['peer_name'=>...]]))` —
  CONNECT_TIMEOUT_SECONDS=2.0 at :52; E646-clean (connect ceiling, no blanket
  request timeout).
- SINGLE-SOURCED guard: only call is PublicUrlGuard::guardedDialAddress at :178-182
  (sole definition src/Support/PublicUrlGuard.php:131; also used by HttpHook.php:314,
  WebFetch.php:717, PublicUrlGuard::fetch per-hop :225). Sender carries ZERO own
  filter_var/block-rule logic — its :100 property is a null-default resolver seam
  forwarded to the guard. No second validator invented.
- Allowlist BEFORE dial: endpointAllowed() :132-145 consulted at :167, strictly before
  the guard call :178. Exact-host then strict-suffix match (strlen>suffix so bare
  ".notify.microsoft.com" suffix alone refused); host lowercased at PushSubscription
  host() ⇒ case bypass closed. Test matrix 9 admit / 5 refuse incl
  `fcm.googleapis.com.attacker.example`, `attacker.fcm.googleapis.com`,
  `notnotify.microsoft.com` — matched-default = refuse.
- Resolve-then-dial-the-vetted-literal: URL authority is the returned IP literal
  (:201, v6 bracketed), Host header carries the name (:203), TLS peer_name pinned to
  the name (:246). No DNS-rebinding TOCTOU window.
- Fail-closed: refusal or null dial ⇒ rejected promise (:184-189); ANY blocked
  address among ALL resolver answers refuses (deny-wins inside the guard); empty
  resolver refuses. Redirects: withFollowRedirects(false) + withRejectErrorResponse
  (false) at :250-251, 307-unfollowed single-call pin + production-source token scan
  (substr_count('->withFollowRedirects(false)')==1). Nothing throws synchronously
  (doc :36; sync-throw→refusal sentence pinned).
- MINOR-1 (step-local): PushSubscription.php requestTarget() (~:110-120) copies the
  raw parse_url query into the request target with NO shape validation, unlike the
  path leg's pchar regex (:116). Raw CR/LF cannot be exec-proven accepted here
  (php-exec denied by shell map ⇒ UNVERIFIED-BY-EXEC for that probe); PHP parse_url
  refuses most control-char-bearing URLs, and encoded %0D%0A rides inert ASCII, and
  the dial is pinned to a vetted allowlisted push-service IP + peer_name — so worst
  case is a malformed request target toward a legitimate service, not SSRF. Fix:
  validate query with the same pchar law extended to allow `/` and `?`
  (`#^[A-Za-z0-9\-._~%!$&'()*+,;=:@/?]*$#`) before composing requestTarget().
- NIT-2 (out-of-scope): SearchBackends.php:152 carries a pre-existing
  guardedDialAddress-name twin; sender does not use it. Weld/closeout note only.

## Q2 — VAPID key discipline — PASS

- Mint-once: $pem memo + reload from VAPID_KEY_FILE (StateDir const + doc table row
  `vapid.key ... 0600` landed same commit e0d095eed — same-commit locality OK);
  corrupt/RSA/P-384 stored keys re-mint (three pins), reload yields identical 65B
  0x04 public key.
- 0600 via house pattern: PrivateRetainedDir::write — lstat .partial plant-refusal,
  umask(0o077) around file_put_contents, rename carries mode; fileperms pin in
  VapidKeysTest. dir re-verified via PrivateRetainedDir::verified on every use.
- Private PEM never leaves: privateKey() is private, NO PEM accessor exists;
  authorizationFor emits only 'vapid t=<jwt>, k=<b64url-public>' grammar (regex pin,
  k==public pin); PEM random-run substr(8..24) asserted ABSENT from authorization and
  'PRIVATE KEY' absent; sender test asserts the run absent from url+headers+body.
- Subject carries contact URL: withSubject regex mailto-addr|https only (ftp refused).
- `last-` cache clause: NOTHING in src/Server/Push contains 'last-' — clause is
  5.d/5.e (wiring) territory; inapplicable here, reported honestly.
- Log trace: zero error_log/echo/print/warn in all three push classes (grep proven)
  ⇒ no path can log the private key.
- DER→raw (design's flagged risk) reviewer-checked: strict 0x30/total==strlen, 0x02
  ints, long-form only 0x81, canonical single-0x00 pad, >32 refuse, trailing-byte
  check, leftpad 32; 3 forgery tests red + the 64B raw signature VERIFIES via rebuilt
  SPKI openssl_verify — load-bearing, not vacuous.
- NIT-1: isP256 checks EC && bits===256, not curve_name — a 256-bit brainpool curve
  would pass. Reachable only by hand-replacing the self-owned 0600 state file. Fix:
  compare curve_name 'prime256v1'.

## Q3 — Crypto correctness — PASS (mutations UNVERIFIED-BY-EXEC; static-derive below)

- RFC 8291 aes128gcm record: salt(16)||pack('N',4096)||chr(65)||UA-pub(65)||ct||tag(16);
  ikm=hash_hkdf(sha256, ECDH, 32, "WebPush: info\0"||ua_pub||as_pub, auth_secret) §3.4;
  CEK 16B / NONCE 12B with salt; aes-128-gcm raw, delimiter 0x02, AAD '', tag 16.
  Byte-exact RFC §5 vector test (fixed-entropy withEntropy seam, documented
  test-only, zero production callers; production path draws fresh salt+pair per
  message — 3 assertNotSame legs).
- ECDH via openssl_pkey_derive (design's choice; NOT sodium — see terminology note).
  Peer point pinned via fixed SPKI prefix + 65B; OpenSSL validates the curve point on
  load; truncated 64B point refused by test.
- 'aes-256-gcm' and friends: REFUSED-BY-CONSTRUCTION — cipher string is a single
  hardcoded literal, no selection API exists for a caller to pass a bad name.
  Mutation-reason (static): neutering the literal changes record bytes ⇒ RFC vector
  test reddens; salt len!=16 ⇒ LogicException pin (15B leg exists).
- Padding boundaries: empty payload ⇒ 86+1+16 frame decrypting to "\x02"; exact
  ceiling MAX_PLAINTEXT=3993 ⇒ 4096B record; 3994 throws — both pinned.
- Receiver reconstitution/truncation: production class has NO decrypt() (receiver
  half is C-WEB-5.e by design ⇒ duty UNOWED here); test-side legs DO exist — the
  record decrypts with RFC CEK/NONCE back to plaintext||0x02 (GCM tag verify =
  truncation-refusal leg in PushPayloadCipherTest).
- UNVERIFIED-BY-EXEC: php binary execution denied by my shell map; builder's 4
  mutation claims accepted as builder-side, independently reasoned statically above.

## Q4 — §1.8 cascade — PASS, all per-class

- R13 name-diff PROOF: zero composer.json/package.json in any pick (git show
  --name-only per pick); ext-openssl-only design ⇒ correct. Zero candy-code-web
  files ⇒ vitest UNOWED (5.d owns protocol/dist).
- ReadPathCensusTest.php:990 row 'Server/Push/VapidKeys.php|file_get_contents' =>
  SELF_LOCATED — live+TIGHT by the census's bidirectional fail-closed law (removal
  of a row whose site still exists ⇒ stale-red; new site unlisted ⇒ unrecorded-red;
  guard green at tip proves both legs agree). Builder's disclosed deviation from the
  design OWNED_HOME hint is sound: class takes a caller-supplied dir, contains no
  HomeDirectory::owned() text.
- DescriptorInheritanceGuard: NON-OWED — zero proc_open in src/Server/Push
  (name-grep; design text concurs "no proc_open, so no child-lifetime row").
- StderrEmitterCensus: NOT-OWED honestly — zero emitters added (no `**Push:**`-shaped
  stderr site); census green in battery.
- en.php serve.push.{endpoint_denied,endpoint_blocked,ttl_invalid,transport_failed}
  contiguous sorted run :3609-3612; CliLangCatalogueTest + LangParityTest GREEN in
  battery (201T run — only GlobDialect red, see below).
- README :531/:533 / DocFigure :6448/:6478 / CommandDocs: brief said "builders claim
  moved in-step" — REJECTED-BY-DISK: no README.md/DocFigureProseDriftTest in any
  pick's name-only list AND the full doc-roster battery ran 395T/48058A GREEN at tip
  ⇒ nothing drifted, nothing owed.
- SuiteSkipRoster: no 'Push' case anywhere (grep zero hits); no roster edit in diff;
  truthful — push tests never skip (R1b-inert confirmed: zero markTestSkipped in the
  three new test files).
- TreeWideGuardRosterTest + DuplicatedTestHelperDriftTest by BARE class name:
  GREEN at tip (battery 201T, sole failure GlobDialect). TWGR row :744 covers
  WebPushSenderTest chr(42) respell tightly. VapidKeysTest:37 plain '/*' glob needs
  no row (guard green). The `::` form of these names matches ZERO tests — avoided.
- GlobDialectDifferentialTest 1F (560x556=311,360 vs PathGlob 559x555=310,245):
  ATTRIBUTION PROVEN FOREIGN — diff-added wildcard literals are only '/*' and '?',
  both already present 100+ times in base (grepped at /home/sites/rev-5c-r1-base),
  and the corpus dedupes by value ⇒ lane adds zero unique entries; builder REPORT
  independently proved the figure persists with all lane files removed. Roster says
  accept + assert NEITHER number. Done so.

## Q5 — Inventory + vacuity — PASS

- MY anchored re-measures (match builder exactly): PushPayloadCipherTest 9T/21A;
  VapidKeysTest 15T/41A; WebPushSenderTest 12T/64A; trio combined 36T/126A OK
  (individual filters reproduced each figure — no false greens).
- No ungated skips; uniqid/random_bytes per-process dirs in every temp-using test.
- Refusal literals: `*.push.sh`-shaped strings live in TEST refusal messages? —
  confirmed NOT test-file glob-polluters: GlobDialect green-except-foreign above
  proves zero corpus movement; sender refusals are Lang keys + sentences, and the
  disclosed literals are not `**Refused:**`-shaped DenialPrefixRoster vocabulary
  (roster guard green).
- Load-bearing spot-checks: RFC vector byte-equality, openssl_verify of raw sig,
  dial-literal URL pin 'https://93.184.216.34:443/wpush/v2/tokenabc' + Host= name +
  TTL/CE/CT/auth headers + body==cipher output, off-allowlist "no request" count==0.

## Q6 — Weld-readiness

Residual reds EXACTLY the accepted-foreign roster: ReadmeSuiteFigureDrift pair (2F),
KeyHelp 5F+1 risky, Cli/HelpTest CANDY_CODE_SERVICE_TIER 1F, BinCandyCodeDispatch
--worktree :1893 1F, AttachPaging 2F, OneSidedHomeSandboxTest.php:435 1F (no Push
file implicated by grep), SettingsEditorTest:189 1F, ChildStderrCapture 2F,
GlobDialect 1F (foreign, above). Surplus greens today: KeepWarm 0F,
AccessorDeprecation 0F (roster-conformant drift, report-never-cure).
BootstrapLaunchFormatConstants doc-leg 1F. RegistryTest shrink-alone EXCLUDED
(base-proven flake). Pairing caveat honored: BashSandboxTest (23T) and
BubblewrapHardeningTest (16T) run SEPARATE filters — both green; the known co-run
1F belongs to master cure db1441a61 (predates lane base) — weld reads green onto
cured master. E646: connect-only timeout, honored.

## §7 LIST (weld duties — never fixed by reviewer)

1. durations.tsv: add rows for the THREE new Push test files with live counts
   PushPayloadCipherTest 9T/21A, VapidKeysTest 15T/41A, WebPushSenderTest 12T/64A.
2. suite-figure.json + README pair re-pin carrying +36T/+126A class (staleness pair
   currently red at tip exactly because figures not yet re-pinned — weld-only files).
3. COMPARISON.md :3470/:3475 push cells = flip candidates (Web Push core now in-tree).
4. StateDir shared-seam note: VAPID_KEY_FILE const + doc row (e0d095eed) is a hub
   edit other push lanes will consume; PrivateRetainedDir honored.
5. Closeout doc rows: MINOR-1 query-shape, NIT-1 curve_name, NIT-2 SearchBackends
   twin (out-of-scope ledger wordings only).
