# C-SES-7.c — REVIEW-2 (round-2 reviewer, fresh)

Lane: cc/C-SES-7.c @ 034b30363 (fix 230b07bc0 on 00a07c290) vs master 94432cfd1
Verdict: **CLEAN** — 0 CRITICAL / 0 MAJOR / 0 MINOR step-local. Round-1 MAJOR-1 cured; both NITs landed.

## 1. MAJOR-1 cure: complete (tab path was the last ungated mint route)

- Bootstrap.php:1397 — `sessionMint: self::incognito() ? null : ($opened['mint'] ?? null)` at the buildChat seam. Present.
- Bootstrap.php:1818 — prune sweep gated: `if ($store instanceof EnhancedSessionStore && !self::incognito())`. Present.

Independent mint-route sweep (every createSession / store-write reachable while incognito):

| route | verdict |
| --- | --- |
| Bootstrap:4426 launch mint | unreachable — openSession() early-returns before the mint key exists under LaunchContext::incognito() |
| Bootstrap:1816 tab mint closure | closure still built but the :1397 seam never hands it to Chat → inert; closure itself side-effect-free |
| Chat:19354 handlePaletteNewSession | guarded at :19335 `sessionStore === null` → refuses with chat.sessions.no_store_new; tabHosted arm re-routes through tabChat → gated buildChat |
| Chat:11493 dispatch trigger / :8388 ensureSessionRow | both null-inert under incognito (sessionMint null; store null) |
| Chat:20810 usageRecordSink | returns null unless store instanceof EnhancedSessionStore → no usage-ledger append |
| AgentManager::recordChildSessionsIn (bound at Bootstrap:2796 to the REAL store) | fail-closed: createChildSession (SessionStore.php:333) throws `Session not found: parentId` because the incognito parent row never exists → caught by catch(\Throwable) at :645 → warn only, stores NOTHING. Residual is a stderr notice, not a write. Acceptable. |
| BackgroundSessionRunner.php:846 forked transcript | unreachable — needs a forkedSessionId from /fork; null-store Chat refuses fork/branch (:16515/:16560/:18845 family); daemon child re-execs without the flag anyway |
| EnhancedSessionStore backfills :2006/:2407/:2528 | reachable only through a store handle; incognito Chats hold null; the tab's real-handle sole write (prune) is the gated line |
| SessionHub.php:132 serve | observation, not a finding: `--incognito serve` registers the static but SessionHub ignores it — same inert-flag family as other launch flags under subcommands; serve's keeps-nothing law is C-SES-7.e's declared scope |

Conclusion: tab path was the last ungated route; the fix closes it on both arms (mint + prune). Memory writes were already covered round-1 (HandlesTurnCompletion consolidation+dream drop, clone-carry pin).

## 2. New pin is non-vacuous; positives asserted

`IncognitoLaunchTest::testAnIncognitoTabOpensWriteFreeMintsNoRowAndTheKeepingTabDoesBoth` — five legs, each mutation-reasoned:
1. reflection assertNull(sessionMint) on the incognito tab → catches M1 (seam-strip) directly;
2. behavioral: real tabChat + update(InitialPromptMsg) → submitInitialPrompt → dispatchTurn fires the minter SYNCHRONOUSLY at Chat:11493 (HandlesRunControl.php:263 path; Cmd never runs so no backend dial) → assertSame(['aged-empty'], listSessions()) proves no row minted; a seam-stripped minter also reddens here;
3. aged nameless transcript-less seed row (updated_at 2000-01-01) proven VISIBLE through the tab's own store handle before the arms (anti-vacuity prologue — the spare is about a row that prune would remove);
4. second incognito tabChat leaves the seed alive → catches M2 (prune-neuter);
5. BOTH POSITIVES on a keeping tab (useIncognito(false)): minter non-null (kills an "always null" fake cure), aged row IS pruned, and its dispatch mints → listSessions() === [kept id]. The gate cannot pass as never-mint/never-prune.

Mutation status: M1/M2 not re-executed by the reviewer (worktree is edit-locked to this file); the discrimination above is reviewer re-reasoning from the live code, and it matches the fixer's claimed 1-red-each outcomes structurally (M1 → legs 1+2; M2 → leg 4).

## 3. Lane-diff re-scan — remaining categories

- **Lang key (NIT-2):** `chat.incognito.status` now at lang/en.php:2183 in the chat.help.*/chat.instructions.* adjacency slot; one definition, one reader (`Renderer.php` incognitoBadge `Lang::t('chat.incognito.status')`) — writer and reader on the same key, no orphan. candy-code ships only lang/en.php, so no 16-locale obligation applies.
- **NIT-1 (indentation):** useIncognito()/incognito() statics are 4-space PSR-12 at Bootstrap.php:4241-4256. Fixed.
- **Scope:** fix commit 230b07bc0 touches ONLY Bootstrap.php (+18), IncognitoLaunchTest.php (+94), lang/en.php (2-line relocation) + FIX-1.md evidence. All inside the lane's declared regions; zero hunks outside. The +18 Bootstrap lines add NO sprintf / no glob-shaped literals / no new emitters (grep-verified) → cannot worsen BootstrapLaunchFormatConstants, GlobDialect, or StderrEmitterCensus.
- **Author law:** all 6 lane commits AE=CE=detain@interserver.net, zero Co-Authored trailers (git log --format re-check).
- **Conventions:** declare(strict_types=1) intact; strict-typed ternary gates; comments say WHY (seam docblock explains the tab-hosted store handle is the same on-disk store); with*/mutate immutability untouched.

## 4. Disclosed "pre-existing" claims — upheld, not disproved

- **ChatHostLangCatalogueTest 7 rows:** verified the scan set (test docblock :13-20, failure msg :90) covers src/Chat.php + src/Host/** + a fixed file list that EXCLUDES src/Renderer.php. 6 foreign rows have consumers in unscanned, lane-untouched files (chat.startup.personal → src/Support/StartupSummary.php:240; chat.attach.pdf_pages → src/Attachments/FileMentions.php:340; keys present at master) → the red exists at master independently. The lane's row (chat.incognito.status, Renderer-only) is the disclosed 7th; the guard cannot go green from this lane without re-baselining 6 foreign rows = weld domain. Treated green-equivalent per brief. WELD LEDGER MUST carry: widen the scan set to Renderer.php (or roster the key) at closeout.
- **BootstrapLaunchFormatConstantsTest PROJECT_TIER ordering + GlobDialectDifferentialTest PathGlob figure (310,245 vs 560x556, forbidden file):** fix adds no sprintf and no glob-shaped literal (section 3) → base claim stands.
- **Standing fleet items** (ReadmeSuiteFigureDrift staleness pair, CliLangCatalogue serve-tls sort, TreeWideGuardRoster ServeArgs scandir): fleet-wide, weld-closeout duty, not lane findings.

## 5. Gate reproductions (cwd=candy-code, env -u LINES -u COLUMNS, plain pipe, -c phpunit.xml)

- `--filter IncognitoLaunchTest` → `Tests: 5, Assertions: 35` OK (+2 pre-existing PHPUnit deprecations) — reproduces FIX-1.md EXACT.
- `--filter 'IncognitoLaunchTest|ArgvParserTest|NonInteractiveSessionPersistenceTest|StderrEmitterCensusTest|LaunchFlagsTest'` → `Tests: 359, Assertions: 23512` OK — reproduces FIX-1.md EXACT (30.4s).
- PtyRunToolTest excluded per brief.

## Verdict

CLEAN. The step's DoD ("--incognito launches keep nothing — no row, no transcript, no memory writes") now holds on both the one-shot and the tab-hosted production paths, with a real-code behavioral pin that discriminates in both directions. No further fix round needed.

## §4 weld carries (verified)

- durations.tsv: NEW row for `tests/Chat/IncognitoLaunchTest.php` (new test file runs in zero shards without it).
- suite-figure/README re-pin: lane diff adds EXACTLY 9 test methods, removes 0 (grep count of `+…public function test` on master...HEAD = 9: IncognitoLaunchTest 5, ArgvParserTest 3, NonInteractiveSessionPersistenceTest 1) → **+9T** carry claim VERIFIED EXACT (was +8T pre-fix; LaunchFlagsTest/StderrEmitterCensus changes are in-method → 0T).
- ChatHostLangCatalogue scan-set widening (or key roster) for chat.incognito.status + the 6 foreign rows, per section 4.
