# C-WEB-5.d — REVIEW-2 (closure)

VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 MINOR / 1 NIT (observation). WELD-ELIGIBLE: YES.

Fresh r2 reviewer (no r1 overlap). Lane cc/C-WEB-5.d @ 4b7174c1d, chain master(895b9d6fd)→9a60cdaf4→84a9674cd→4b7174c1d re-derived from refs; parents exact, reviewed picks content-addressed byte-intact. porcelain 0; law: %ae and %ce both detain@interserver.net on all 3 picks (separate greps), Co-Authored-By 0.

## Q1 Cure coverage — PASS
Fix blob: gate :192 gains exactly `&& isset($row['name'])` — ONLY the name leg, no silent widening (no is_bool added; justified: `=== true` at :212 is warning-safe for any type). Full read-chain re-derived from blob: endpoint/p256dh/auth/principal isset+is_string-gated + digest equality at :195; PushSubscription::new VO gate :202; normalized output :208-212. entries() (:143-150) and prunePrincipal (:123) consume only load()-normalized rows. NO other unguarded raw read survives.
New pin testARowMissingItsNameFlagIsRefusedAtLoadWithoutAWarning (:153): plants digest-valid VO-valid name-absent row → assertSame([], entries()) refusal leg + assertCount(0) persisted-refusal leg (+2A explains 32→34). Warning-absence leg is structural: candy-code/phpunit.xml failOnWarning="true", :212 read not @-suppressed — pre-fix the E_WARNING fails the run. Both polarities: legit-row-loads lives in same green class (testPutAndReloadRoundTrip + tamper test). Class = 8 test methods, matches my run.
MY RUN: PushSubscriptionStoreTest:: → `Tests: 8, Assertions: 34` OK (+2 Deprecations baseline). Fixer-quoted red-before `Tests: 8, Failures: 1, Warnings: 1` consistent with the shape-gate analysis.

## Q2 Vacuous deletion honesty — PASS
`assertStringNotContainsString('Edit',...)` absent from tip blob (deleted, count 49A→48A = exactly −1). Carry comment at :236-239 names the replacement: the two byte-equalities at :237-238; the fixer EXEC-recorded mutation (ServerContext:617 ' Edit' leak, cp+md5 08006c43…) reddened :237 (testAQuestionAndACompletionEachDialOnceWithRedactedText) AND :297 (testAnOptedInRowCarriesTheSessionNameAndOthersDoNot) — the opted-in test's body comparison covers the same law, so no coverage lost. MY RUN: PushMethodsTest:: → `Tests: 11, Assertions: 48` OK (11 methods counted).

## Q3 Zero-collateral — PASS
`git show --name-only 4b7174c1d` = exactly the 3 files (+24/−3, matches lead stat). Range master...HEAD = 11 files; NO schema.json/SERVER.md/generated.ts/suite-figure/durations/dist — targeted full-range diff of those paths = 0 lines (trio regen correctly welder-carry). en.php + dormantRoster.spec.ts touched by BUILDER picks (r1-reviewed scope), not the fix. Only '-' content base..tip is builder-intentional (one ctor line + two rewritten comments) — zero weakened legs. No `**Refused:**`-shaped/glob-shaped literals added; setUp random_bytes + tearDown chr(42) are pre-existing compliant idioms.

## Q4 Gates (MY runs, cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS plain pipe, anchored stems, all NON-ZERO)
- PushMethodsTest:: `Tests: 11, Assertions: 48` OK
- PushSubscriptionStoreTest:: `Tests: 8, Assertions: 34` OK
- push family `--filter 'Push'` `Tests: 126, Assertions: 659` OK (filter-shape differs from r1-era 45T/1053A; all push classes green either way)
- TreeWideGuardRosterTest bare `Tests: 17, Assertions: 3924` OK
- DuplicatedTestHelperDriftTest bare `Tests: 14, Assertions: 204` OK
- (CliLangCatalogueTest::|LangParityTest::) `Tests: 15, Assertions: 4205` OK
- `PHPUnit Deprecations: 2` on every run = the banked baseline shape; zero Warnings/Failures.
- Deletion-discrimination re-EXEC: **UNCONFIRMED-BY-RUN** — reviewer map denies mkdir/cp (three rejection classes logged); relied on fixer's EXEC record + my static red-shape analysis above. LayeredSettingsTest skipped (fix touched no settings; name-only proves). PtyRunToolTest::testExpectMiss excluded; vitest NOT owed (zero candy-code-web files in fix).

## Q5 Weld-readiness + §7 (LIST, not fix)
Residual reds roster (accepted-foreign, unchanged by this pick): keepWarm 1F, KeyHelp 5+risky, HelpTest[CANDY_CODE_SERVICE_TIER], BinCandyCodeDispatch --worktree :1893, AttachPaging 2F (base-proven 364a52876), OneSided :435, ReadmeSuiteFigureDrift staleness pair, GlobDialect 1F assert-NEITHER, SettingsEditor :189, ChildStderrCapture 2F, AccessorDeprecation :168/:202, BootstrapLaunchFormat doc-leg, Cli/HelpTest env-var leg, RegistryTest O_TRUNC + ForkedChildReaper report-only. Pairing: base carries db1441a61 ⇒ co-run expected GREEN; red at weld = STOP note, report-never-cure.
§7 durations (MY live counts rule): PushMethodsTest 11T/48A · PushSubscriptionStoreTest 8T/34A; battery carry +18T-class, suite-figure re-pin by welder.
Welder-carry restated: picks are the 3 explicit SHAs read-from-lane (9a60cdaf4, 84a9674cd, 4b7174c1d), NEVER a range. Trio regen: schema +121/−0 · generated.ts +49/−0 — re-derive at regen; fix pick added no method/schema change so drift unchanged from builder list (spot-proved: full-range diff carries zero schema/generated.ts paths). SERVER-MANUAL-SECTION.md 1,759 B verbatim + dist rebuild + §4 cites r1 REVIEW-1 10,572 B (exact wording 0C/0M/1 MINOR/3 NIT) + FIX-1 4,640 B + this r2 verdict verbatim. Two ledger NITs (parse_url-vs-VO cosmetic; vitest-staleness disclosure) ride docs, no code.

NIT (observation, non-blocking, step-local): fixer's fixture does `'name' => false` immediately before `unset($row['name'])` — the explicit false is dead (the unset removes it either way); harmless intent-documentation, no action.

Quiescence: live siblings C-TOOL-4.e/4.g/6.g/6.j noted; master unmoved at 895b9d6fd during this review (re-read at close). background=true spawn law confirmed.
