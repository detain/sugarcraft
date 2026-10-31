# C-WEB-5.c — REVIEW-2 (round-2 closure reviewer; fresh session, no inherited r1 context)

VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 MINOR / 0 NIT

Lane /home/sites/cc-cmp-C-WEB-5.c, branch cc/C-WEB-5.c, base 97201c6af, tip b3a41cfd8.
Re-derived from disk: `git log --oneline master..HEAD` = FOUR picks f28c26790/e0d095eed/6248dd790/b3a41cfd8; porcelain 0.
Evidence read on disk: REVIEW-1.md 12,057 B @11:29:29; FIX-1.md 4,097 B @11:45:22; design block
design-web.md:610-634 from the LANE'S OWN copy. MUST-COVER: fix pick + both questions below derived
from the b3a41cfd8 BLOB, not the fixer's prose.

## Q1 — MINOR-1 closed with teeth — YES

- Blob PushSubscription.php:88-91: query leg `$query !== '' && !preg_match('#^[A-Za-z0-9\-._~%!$&\'()*+,;=:@\/?]*$#', $query)`
  — character class EXACTLY r1's prescribed shape (PCRE `\/` escapes are spelling-neutral); refusal sentence at :90;
  requestTarget() docblock :118 names the construction-time gate; `new()` :42-44 throws on any endpointRefusal.
- Both polarity pins exist (WebPushSenderTest.php :77 hostile loop of 4 request-line breaker shapes `[ ] | { } space`
  with fail()-if-constructed + message assert at :92; :99 legit pin asserts BYTE-EXACT ride of
  `?key=AIzaSy…&v=2` and the `/?;` boundary octets `/probe;x&a=b`). Load-bearing verified: '[' '|' '{' ' ' are outside
  the class; '=' '&' '/' ';' '?' are inside.
- EXEC at tip (anchored per-class, cwd=candy-code, plain pipe): WebPushSenderTest:: → **Tests: 14, Assertions: 70, OK** (non-zero sanity).
- RED-BEFORE: my map refuses scratch seeding (`>` redirect + `git archive|tar` both DENIED; worktree add denied) —
  UNVERIFIED-BY-EXEC for the red run; STATICALLY CONCLUSIVE: base 6248dd790 pre-image of endpointRefusal shows the
  path leg followed directly by `return null` (zero `query` tokens) → the hostile endpoints construct fine → the pin's
  `self::fail(...)` arm fires deterministically. Fixer's red-before claim is consistent with the blob pair.
  Over-cure guard (legit pin) is green pre- AND post-cure by construction (query was unvalidated pre-cure).

## Q2 — NIT-1 taken — YES

- Blob VapidKeys.php:202-211: isP256 gained one condition — verbatim at :211:
  `&& ($details['ec']['curve_name'] ?? '') === 'prime256v1';` — why-comment (:209-210) names the 256-bit impostors.
- Pin testAStoredEcKeyAtTwoHundredFiftySixBitsOnTheWrongCurveIsReplacedNotUsed (:206): stores REAL secp256k1 PEM at
  0600, reloads, asserts state file was REPLACED with curve_name prime256v1 (:226-228 — the fixer's ":227" cite
  lands inside that assert). Red-before statically conclusive: base isP256 was EC+bits===256 only → adoption →
  no re-mint → file stays secp256k1 → assertSame red. Same UNVERIFIED-BY-EXEC label as Q1 for the actual red run.
- Hygiene spot-checks intact at tip blob: mint-once test :43-53 (identical pubkey on reload, 65B 0x04), 0600
  fileperms pins :60/:169, PEM-never-leaves :277-280 (random-run substr(8..24) absent + 'PRIVATE KEY' absent),
  raw-sig openssl_verify :85-99. VapidKeysTest:: EXEC → **Tests: 16, Assertions: 45, OK**.

## Q3 — NIT-2 discipline — CONFIRMED

`git show --name-only` ×4 picks: SearchBackends.php appears in NONE. Out-of-scope name-twin untouched.

## Q4 — Zero-collateral + inventory — CONFIRMED

- Fix pick name-status: 4 files, ALL `M` (PushSubscription.php, VapidKeys.php, VapidKeysTest.php,
  WebPushSenderTest.php) — NO new files, NO src/Server/Push violation, durations duty unchanged (live trio counts).
- Fix diff deletions = 2 lines, both benign rewrites (docblock gains gate-name; `bits===256;` leg gains the curve
  conjunct). Base..tip machine-diff: 1878 insertions(+), 0 deletions — nothing removed/weakened anywhere in range.
- Residue greps in fix blobs: TODO/FIXME/var_dump/error_log/print_r → zero. Range diff markTestSkipped → 0.
- Forbidden surfaces per-pick name-only: zero hits on suite-figure/durations/dist/protocol trio/SERVER.md/
  generated.ts/composer.* — sole en.php touch is builder 6248dd790 (serve.push.* sorted run :3609-3612; not relitigating).
- MY EXEC (composed anchored form): `(PushPayloadCipherTest|VapidKeysTest|WebPushSenderTest)::` → **Tests: 39, Assertions: 136, OK**;
  per-class 9T/21A + 16T/45A + 14T/70A reproduce the fixer's §7-feeding figures exactly. PtyRunToolTest needle in no filter.
- vitest N/A: full-range name list is 11 files, all candy-code/*; zero candy-code-web paths.

## Q5 — §1.8 + guards + SSRF law — CONFIRMED

- BARE-class at tip: TreeWideGuardRosterTest → **17T/3889A OK**; DuplicatedTestHelperDriftTest → **14T/204A OK** (both match fixer).
- ReadPathCensusTest:: → **19T/738A OK** (row for the key read lives in pick e0d095eed).
- GlobDialectDifferentialTest:: → **13T/364A/1F** — the sole accepted-foreign docblock-pair-count failure, signature
  numerically identical to r1's record ⇒ fix's literals moved zero corpus shapes. Asserting NEITHER number per roster law.
- R13: `git diff master...HEAD --name-only | grep -c composer` → 0. Design ruling "in-tree ext-openssl only" honored.
- SSRF: WebPushSender.php diff 6248dd790..b3a41cfd8 EMPTY — fix touched the construction gate only, which STRICTENS:
  an illegal query now refuses before a PushSubscription object (hence any sender call) can exist. Chain order at tip:
  ctor endpointRefusal → send() endpointAllowed :167 → PublicUrlGuard::guardedDialAddress :178 → dial vetted literal.
  No second validator, no new path around the guard, single-sourced rule intact (r1 Q1 PASS carries).

## Q6 — Weld-readiness — WELD-ELIGIBLE: YES

- Server adjacency RE-RUN at tip: `--filter 'Tests\\Server'` → **Tests: 502, Assertions: 6821, Failures: 2** — exact
  fixer match; the 2F are exactly the accepted AttachPagingTest pair, both legs named
  (testAnAttachmentStitchesOlderPagesIntoItsHistory :75, testAnAttachmentWithoutPagingTakesTheWholeSnapshotDirectly :94).
  r1's baseline 499T/6811A +3T/+10A = this lane's in-step delta. Reconciles.
- Residual set matches the accepted-foreign roster EXACTLY (GlobDialect 1F + AttachPaging 2F observed first-hand;
  keepWarm/KeyHelp×5/HelpTest[BIG_TIER]/BinCandyCodeDispatch :1893/OneSided :435/ReadmeSuiteFigureDrift pair/
  SettingsEditor :189/ChildStderrCapture 2F/AccessorDeprecation/BootstrapLaunchFormat doc-leg/Cli-Help env leg/
  RegistryTest O_TRUNC flake — all roster-banked, none re-litigated; push files appear in no surplus red I saw).
- PAIRING FOR WELDER (state it): lane base 97201c6af PREDATES db1441a61 — BashSandbox/BubblewrapHardening co-run 1F
  is cured on master; the fixer kept the two filters SEPARATE (correct). The WELD onto cured master must read the
  co-run GREEN — welder: run the pair together once post-weld, expect 0F.
- Observation (not a finding): every run reports "PHPUnit Deprecations: 2" with constant count across ALL filters
  including base-adjacent guards ⇒ pre-existing suite-wide shape, lane-attributable zero.

## §7 LIST (weld duties — never fixed here)

1. durations.tsv rows for the THREE Push test files with live counts (MY exec): PushPayloadCipherTest 9T/21A,
   VapidKeysTest 16T/45A, WebPushSenderTest 14T/70A.
2. suite-figure.json + README pair re-pin carrying +39T/+136A class.
3. COMPARISON.md :3470/:3475 push cells — flip candidates (Web Push core now in-tree).
4. NIT-2 SearchBackends.php:152 name-twin + r1 MINOR-1/NIT-1 closeout ledger wordings (docs only).
5. BashSandbox/Bubblewrap co-run GREEN read on cured master (see Q6).

No scratch was created (rev-5c-r2-base seeding map-blocked ⇒ nothing to PRUNE). REVIEW-1.md/FIX-1.md untouched.
All targeted runs only; no full suite; master untouched.
