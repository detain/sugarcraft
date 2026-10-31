# C-TUI-6.f — WELD RECORD

Verdict: WELDED. Four picks landed on master zero-conflict; every weld gate GREEN at the merged tree; the protocol trio + dist regen carried by the welder per the lane's weld-only law; §4 mark flipped; lane worktree+branch pruned after `git cherry` proof.

## Chain (no `-x`, t646 ruling — landed shas are the authority)

| lane sha | landed sha | subject | diff |
|---|---|---|---|
| 0247fe160 | ebf93e2a8 | web i18n infrastructure — server.strings + chrome catalogue (PHP) | 8 files +589 |
| 9592ea7a1 | f9fb4e8a8 | web chrome i18n — i18n/locale stores, Vue migration, generators + guards | 13 files +614/−46 |
| 3a2db4ea7 | 26877e585 | TWGR row for WebStringsTest sandbox sweep | 1 file +3/−0 |
| 915f030d1 | 570531559 | pin the strings() en-fallback catch (junk-locale) | 1 file +20 |

Lane base `7e042da7b`, branch tip `915f030d1` (explicit pick list read from `git log --format='%h %s' 7e042da7b..HEAD`, never a range). Master pre-weld `a4f619f6d` (C-TOOL-4.h era). Law greps on the four picks: %ae 4× detain, %ce 4× detain, 0 Co-Authored (re-verified in welder shell).

## Review loop (quoted from the FILES, not the lead)

- REPORT.md 5,854 B @10:28 — lane build record.
- REVIEW-1.md 14,096 B @10:53 — `FINDINGS: C=0 M=1 MINOR=1 NIT=2`. MAJOR: the lane's own directory-walking test unlicensed under TreeWideGuardRosterTest — RED at lane tip 17T/3884A/2F, both legs naming `Protocol/WebStringsTest.php => scandir($this->dir)` (offender rows :1190 + :2047). MINOR: `strings()` en-fallback catch unpinned. NITs: .ts line-parse quote caveat; per-key translate deviation. r1 Q1 PASS — ruling compliance via 3 chokes (below).
- FIX-1.md 4,339 B @11:07 — both actionable items cured; TWGR row array-alphabetical after `Protocol/ContextSourcesTest.php` with 2-line WHY; green-after 17T/3887A/0F. Junk-locale pin folded into the EXISTING WebStringsTest.php (no new file → no new durations row beyond the two §7 carries): `testTheWireAnswersJunkLocaleTagsInEnglishRatherThanFaulting`; mutation (catch stripped) → EXACTLY 1E at WebStringsTest.php:161 via WireClient.php:108, cp+md5 restore 13e4edf0….
- REVIEW-2.md 6,659 B @11:16:54 — `VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 MINOR / 0 NIT. WELD-ELIGIBLE: YES.`

## Owner ruling (design-tui.md §C-TUI-6.f, verbatim)

`RULING ANSWERED 2026-10-28: chrome/UI strings only (server.strings); server/tool/model text stay untranslated` — enforced at three chokes, r1-verified: `WebStrings.php:93` prefix gate in `for()`; the i18n store's `setWebStrings` client-side filter; `gen-web-strings.mjs` exit-2 on non-chrome keys. Deleting the :93 guard reddens two named tests (mutation evidence in r1).

## TreeWideGuardRosterTest — merged blob row-state (read by TEXT)

All sibling WALKS rows alive at the landed tree AND the lane row present, tight form:
`:587 Agents/Live/AgentLiveRegistryTest.php · :645 Cli/LoginSubcommandTest.php · :678 Cli/SubcommandsMcpServeTest.php · :742 Protocol/ContextSourcesTest.php · :745 Protocol/WebStringsTest.php => ['scandir($this->dir)'] (THIS LANE, 26877e585) · :809 Tools/BashSandboxTest.php`.
History: red-before (2F at lane tip) → FIX-1 row → green-after. Gate run by BARE class name (the `::` form silently matches zero tests): TWGR 17T/3897A GREEN at merged tree (lane-tip 3887A → +10A merged wobble — assert neither r2's 3887A nor the lead's 3888A observation; test count identical). DTH BARE 14T/204A EXACT.

## Junk-locale catch pin (915f030d1→570531559)

Fixer's needle rationale banked: a grammar-valid `zz` reaches `en` WITHOUT the catch (locale lookup miss is legal), so the pin's tag must be grammar-REFUSING — `not a locale!` fails TAG_PATTERN and exercises the reject path; the mutation-stripped catch turns it into 1E, restore green.

## Merged-tree gates (welder runs; cwd=candy-code `-c phpunit.xml`, `env -u LINES -u COLUMNS` plain pipe; anchored `|Class::`; PtyRunToolTest::testExpectMiss + RegistryTest shrink-stdout flake excluded)

- `WebStringsTest::|WebStringsDriftTest::` = **9T/87A** EXACT r2 figure.
- `ProtocolSchemaDriftTest::|ServerProtocolDocumentationDriftTest::` = **13T/362A GREEN post-regen** (the lane-era 1F expected-stale drift resolved by the welder's regeneration).
- `ServerProtocolDocumentationDriftTest::|CommandDocsDriftTest::|McpStartupOrderingTest::` = **12T/70A** (brief reference `108T/4289A` is the wider family era — reporting mine per weld law).
- `CliLangCatalogueTest::|LangParityTest::` = **15T/3706A** GREEN (r1 lane-tree 3699A; +7A wobble).
- PAIRING GREEN PROOF: `BashSandboxTest::|BubblewrapHardeningTest::` co-run = **39T/190A 0F** at the merged tip (master carries cure db1441a61).
- `ReadmeSuiteFigureDrift` = 4T/26A/2F staleness pair AS ACCEPTED — live 29,434 vs pinned 25,537; this weld carries its +1T-class share; battery re-pin is §7, suite-figure.json NEVER touched.

## en.php append union + python dupscan

Picks auto-merged with zero conflict. Python census at merged tree: **3,467 keys, 0 duplicates**; `web.chrome` block lands as ONE contiguous tail block (idx 3415–3466, 52 keys). `grep -c` not used (exits 1 on zero — chain-killer law).

## Regen + dist — carried by THIS welder (the lane regen-verified then restored per weld-only law)

- `php scripts/gen-protocol-schema.php --write` → `candy-code/docs/protocol/candy-code.v1.schema.json` +36/−0, `candy-code/docs/SERVER.md` +1/0 (numstat-proven).
- `npm run gen:protocol` (cwd candy-code-web) → `src-web/protocol/generated.ts` +14/0.
- `npm run build` rc0 on PRE-SEEDED node_modules (NEVER npm ci) → dist: NEW `assets/index-C6T8duAb.js` + `assets/index-BEwIkY_l.css`, DELETED `index-CtqEFhtc.js` + `index-BQRHrys5.css`, `index.html` + `manifest.json` modified (dist is weld-only, C-WEB-5.g db6cadab1 precedent).
- `vue-tsc --noEmit` rc0 AFTER regen — resolves r1's stale-in-lane typing deviation.
- `php tools/gen-web-strings.php --check` rc0 AND `node scripts/gen-web-strings.mjs --check` rc0 at merged tree (both executed, not map-denied).

## vitest spot-confirm + the cache incident (honest record)

First serial spot-run went RED on `dormantRoster.spec.ts` (`derivedDormant()` listed `server.strings` as unclaimed). Diagnosis: NOT a lane defect — a stale `node_modules/.vitest-cache` transform of the spec carried an eager `import.meta.glob('?raw')` map PREDATING `stores/locale.ts`; every fresh-path copy of the identical logic was green. `rm -rf node_modules/.vitest-cache .vite .vite-temp` resolved it with ZERO spec/source changes (pristine spec md5 a97cec2b239a22563b6285ba659b1382 stayed git-clean through all probes). Post-wipe merged-tree spot-confirm, serial `--no-file-parallelism` ONLY: `generated.spec.ts` + `dormantRoster.spec.ts` + `i18nCoverage.spec.ts` + `i18n.spec.ts` + `locale.spec.ts` = **5 files, 18 tests, ALL PASSED**. (The lane's full 9-file/65-test vitest set is r1-era evidence; not re-run at weld.)

## §7 HAND-OFFS — listed, NEVER fixed here

1. durations.tsv rows: `WebStringsTest.php` must read **7T/82A** (FIX-1 WELD NOTE: junk-locale pin rode the existing file) + `WebStringsDriftTest.php` **2T/5A** — welder live counts.
2. vitest specs note above (5 spot specs green at weld; full set owed at battery).
3. suite-figure/README pair re-pin at battery (staleness pair live 29,434 vs pinned 25,537).
4. COMPARISON web-i18n cell candidates (e.g. the :4067 "only lang/en.php ships" prose) at battery — closeout flips, weld edits NOTHING but its §4 line.
5. `web.yml` gen:strings CI twin-step stays a LEAD rider.
6. PathGlob corpus figure re-derive at battery (GlobDialect 1F is in the accepted foreign set).

WARN NEXT WELDER: your base predates the 6.f/4.h-era welds — re-derive TWGR row positions, en.php key counts (3,467 now), and the WebStrings pair figure from the CURRENT blob, never from banked numbers.

## Absorption + cleanup

`git cherry master cc/C-TUI-6.f` = 4× `-`, zero `+` (no conflict resolutions → content-verify vacuous). Then worktree `../cc-cmp-C-TUI-6.f` + branch `cc/C-TUI-6.f` removed; surviving in-flight lanes verified via `git worktree list`: C-TOOL-4.d, C-TOOL-6.c, C-TOOL-6.g, C-TUI-6.j, C-WEB-5.c.
