# W35 wave review — round 2 (certify) — FRESH reviewer, read-only

BRIEFED ANCHOR: master tip 761d1090e87b35ce59d873e896c2760a2c71e57a.
LIVE ANCHOR WHEN THIS ROUND READ GIT: master = 909fd2546bf8185bace01dac3fa888ffd0cf5919 (master advanced behind this review by three C-WEB-5.f commits — 7bf4691e9, da2694520, 909fd2546 — an in-flight wave's lane, NOT W35 work; disclosed. All gates below ran against the live tree at 909fd2546).

## Certify step 1 — rider commits: PASS
- 7267e6f99 exists (cat-file: commit), ancestor of 761d1090e == ancestor of tip (merge-base --is-ancestor R1-ANC). Subject "W35: cure HookWebhooks positional-ctor break from 6.g canvas insert (r1 MAJOR-1)". Files (numstat): candy-code/tests/Server/HookWebhooksBodyCapStackTest.php 4+/1-, candy-code/tests/Server/HookWebhooksTest.php 5+/1- — TESTS ONLY, zero src/ files. Cure shape read from the diff: `null,` canvas slot inserted + `webhooks:` named arg with a drift-proof comment, exactly the r1-prescribed fix. Author law: `%ae`=detain@interserver.net AND `%ce`=detain@interserver.net (separate greps); Co-Authored grep over the show output = 0.
- 761d1090e exists (commit), ancestor of tip (R2-ANC). Subject "W35/§7 rider: mint MediaMethods read sinks into ReadPathCensus roster (C-TUI-6.n weld left-off)". Files: candy-code/tests/Support/ReadPathCensusTest.php 7+/0- ONLY (roster mint). Author law: %ae and %ce separate = detain; Co-Authored = 0.

## Certify step 2 — merged-tree gates re-run personally (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe, no full suite, PtyRun testExpectMiss never in any filter): PASS
- `--filter 'HookWebhooksTest|HookWebhooksBodyCapStackTest'` → **OK 21T/238A 0F 0E** — r1 MAJOR-1 CURE CERTIFIED GREEN (r1 recorded 237A at 761d1090e; live 238A, +1 from post-anchor tree movement, green either way).
- Trio validators `--filter 'ProtocolSchemaDriftTest|ServerProtocolDocumentationDriftTest'` → **OK 13T/362A 0F** — 6.g trio regen holds; matches r1 and the weld mark exactly. Filter-hygiene note recorded live: the briefed substring 'ServerProtocolDocDrift' matches only 7T/303A (it silently misses the real class ServerProtocolDocumentationDriftTest) — the correct-name run above is the certified one.
- Merged canvas/serve families: `--filter 'CanvasPageTest|CanvasLinksTest|MediaMethodsTest|ServeCommandTest'` → **OK 31T/175A 0F** (canvas 15T + MediaMethods 16T; disclosed: ServeCommandTest matches NO existing class — phantom name; actual serve surfaces verified present: tests/Server/CanvasPageTest.php, CanvasLinksTest.php, tests/Protocol/MediaMethodsTest.php all exist, plus) `--filter 'ServeArgsTest'` → **OK 46T/234A 0F** — serve route surface (incl the post-anchor C-WEB-5.f tail work riding atop W35's canvas endpoints) all green.
- Guard families, BARE class names: `--filter 'TreeWideGuardRosterTest|DuplicatedTestHelperDriftTest|StderrEmitterCensusTest|ReadPathCensusTest'` → **OK 145T/29055A 0F** — TWGR green, DTH green, StderrEmitterCensus green (W36 cd3d1aecb cure holds), ReadPathCensus GREEN at tip (the 761d1090e mint verified effective; r1 saw 29029A/1F at the pre-mint tree).

### Standing FOREIGN reds (classified, NOT charged to W35):
- GlobDialectDifferentialTest → **1F** at 909fd2546 (PathGlob docblock pair-count vs derived corpus — the §7 moving item). Per run-law neither number asserted.
- vitest shellsInput/processesSection → **§7(1) idempotencyKey hand-off (banked 28P/4F)**; npx exec-denied in the reviewer map this round too (r1 confirmed the denial) — not chased, classified standing-foreign.
- No NEW red found touching any W35 member file at this tip: every W35 surface family above is green.

## Certify step 3 — verdict
r1 duties 1-4/6-7 all PASSed and are not re-litigated. r1's sole W35-chargeable finding (MAJOR-1, HookWebhooks positional-ctor break from 9d1cf26ca) is cured by 7267e6f99 — a test-only, author-law-clean, zero-src rider, ancestry-verified — and its filter reproduces green (21T/238A 0E) in this round's own run. The foreign ReadPathCensus 1F was minted away (761d1090e, tests-only roster) and the census re-derives green. Remaining reds are the two §7 standing-foreign items above. r1's NIT-1 (6.i §4 mark slice boundary :646-vs-:648) is cosmetic plan-mark wording; the lead's close action, does not gate the wave.

WAVE VERDICT: CLEAN
