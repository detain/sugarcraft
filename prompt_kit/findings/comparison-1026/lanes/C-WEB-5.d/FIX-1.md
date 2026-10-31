# C-WEB-5.d FIX-1 — round-1 fixer

Lane: cc/C-WEB-5.d, base 895b9d6fd, reviewed picks 9a60cdaf4 + 84a9674cd (untouched, never amended).
Fix commit: **4b7174c1d040227311e19226d40e7cb40ea3a80a** `C-WEB-5.d: review fixes (round 1)` — tip, picks=3, porcelain 0. Authority: REVIEW-1.md 10,572 B @14:11:53 (re-statted by fixer).

## MINOR (Q1) — shape gate misses 'name' — FIXED
- PushSubscriptionStore.php:192: added `&& isset($row['name'])` to the isset group of the load() shape gate. A row without 'name' is now refused by the gate (dropped + persisted clean like every other malformed row) BEFORE the read sites at :212 (and :150 via entries()) touch `$row['name']`. Fail-closed posture; put() always writes the bool, so such a row is by definition foreign/tampered.
- Re-derivation of unguarded legs at :207-212: endpoint/p256dh/auth/principal are all isset+is_string-gated; **only 'name' was unguarded**. Non-bool name values (e.g. "yes") never warn — `=== true` coerces — so no is_bool widening was taken (assignment: name the leg if another warning-class existed; none does).
- Pin: PushSubscriptionStoreTest::testARowMissingItsNameFlagIsRefusedAtLoadWithoutAWarning — plants a digest-valid, VO-valid row with 'name' absent; asserts entries()===[] and the rewritten file holds 0 rows.
- Warning-absence leg: **EXEC**. candy-code/phpunit.xml carries failOnWarning="true" and the :212 read is not @-suppressed. Pre-fix run (fix withheld): `Tests: 8, Assertions: 33, Failures: 1, Warnings: 1` — the Warnings:1 line is the undefined-array-key E_WARNING surfacing to PHPUnit (PHPUnit Deprecations counted separately). Post-fix run: `Tests: 8, Assertions: 34` green, zero warnings. Red-before/green-after both quoted below.

## NIT (Q5) — vacuous assert at PushMethodsTest:239 — DELETED WITH REASON (EXEC)
- The assertStringNotContainsString('Edit', $question.$finished) leg scored over test-built strings; the real no-leak pin is the two byte-equalities (:237-238) comparing the dialed bodies to re-encrypted generic plaintext under frozen entropy. Deleted the vacuous leg, left a comment naming the carry.
- Deletion discrimination **EXEC**: mutation appended ' Edit' to the trigger body at ServerContext.php:617 (cp+md5 backup 08006c43afda46b84bfea219cf03f88b, restore verified identical). Mutated run after deletion: `Tests: 11, Assertions: 46, Failures: 2` — failures at PushMethodsTest.php:237 (testAQuestionAndACompletionEachDialOnceWithRedactedText) and :297 (testAnOptedInRowCarriesTheSessionNameAndOthersDoNot): the byte-equalities alone catch a tool-name leak in BOTH payload-comparing tests. Restored, re-ran green: `Tests: 11, Assertions: 48`.
- Count: 49A → 48A is the one deleted vacuous assertion; 11T unchanged.

## NIT ledger items (no code churn)
- parse_url-vs-VO cosmetic (Q2): recorded, not taken — would touch the dial-chain reuse story for zero behavior.
- REPORT vitest-staleness disclosure (Q3): r1's own note stands — the 2 post-restore vitest reds in dormantRoster.spec.ts are trio-staleness, welder-carry class; the fixer adds no PHP-side cure and committed nothing under candy-code-web/ (generated.ts/dist untouched — porcelain proves).

## Gates (fixer runs, cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS plain pipe, anchored stems)
- PushMethodsTest:: → `Tests: 11, Assertions: 48` OK (brief-era 49A minus the deleted vacuous leg).
- PushSubscriptionStoreTest:: → `Tests: 8, Assertions: 34` OK (was 7T/32A; +1T/+2A pin).
- Combined pair → `Tests: 19, Assertions: 82` OK.
- Bare TreeWideGuardRosterTest → `Tests: 17, Assertions: 3924` OK (lane adds no roster row).
- Bare DuplicatedTestHelperDriftTest → `Tests: 14, Assertions: 204` OK.
- (CliLangCatalogueTest::|LangParityTest::) → `Tests: 15, Assertions: 4205` OK. en.php byte-untouched this pick — proof: `git diff --name-only` lists ONLY the 3 lane files; zero new lang keys, dupscan N/A.
- Baseline shape preserved: `PHPUnit Deprecations: 2` on every run (r1's baseline), zero Warnings/Failures post-fix.
- Exclusions honored: PtyRunToolTest::testExpectMiss not in any filter; no full suite; no composer/refresh-deps; no regen tooling run.

## Law/anti-phantom
- Author split-var + GIT_* env + --author; `%ae` grep -c detain = 1, `%ce` grep -c detain = 1 (separate greps), Co-Authored count 0; no -x; explicit-path `git commit -o`; residue greps clean (NO-RESIDUE); forbidden set (trio/dist/suite-figure/durations/plan/run-state) absent from porcelain and diff name-only.
- `git cat-file -t 4b7174c1d…` → commit (same shell as citation). Reviewed picks 9a60cdaf4/84a9674cd byte-intact below the fix.
