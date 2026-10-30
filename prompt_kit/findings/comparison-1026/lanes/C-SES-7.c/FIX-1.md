# C-SES-7.c FIX-1 (round 1 — MAJOR-1 cured, both NITs folded)

Verdict: MAJOR-1 fixed with a behavioral tab-path pin, both arms mutation-proven
discriminating; NIT-1 and NIT-2 both landed (in-region, zero cost). Gate green.

## MAJOR-1 — incognito tab path minted a real row + ran the sweep (Bootstrap.php)

Cure as REVIEW-1 prescribes, BOTH arms:

- `candy-code/src/Cli/Bootstrap.php:1391-1398` (buildChat seam):
  `sessionMint: self::incognito() ? null : ($opened['mint'] ?? null)` — the
  verbatim cure line, with the ISS-102 comment truth-updated to name the tab
  shape. An incognito tab now carries no minter at all, as the launch arm of
  `openSession()` already carried none.
- `candy-code/src/Cli/Bootstrap.php:1812` (openTabSession):
  `if ($store instanceof EnhancedSessionStore && !self::incognito())` — the
  "full honesty" arm: the empty-session sweep is a store WRITE and incognito
  turns writes off, so an incognito tab open is read-only.

Regression pin (REVIEW-1: "the current IncognitoLaunchTest covers only the
launch openSession path"):
`candy-code/tests/Chat/IncognitoLaunchTest::testAnIncognitoTabOpensWriteFreeMintsNoRowAndTheKeepingTabDoesBoth`
drives the REAL `Bootstrap::tabChat()` factory (workspace cold-described and
memoized, ambient CANDY_CODE_* provider env neutralised around it, same
neutralisation the other launch-paying tests apply) and pins, on the live
workspace store:

1. the flagged tab's `sessionMint` is null (the seam gate, directly);
2. `update(InitialPromptMsg)` on it leaves `listSessions()` with no new row
   (the dispatch-mints-a-real-row defect shape; the turn Cmd is never run, so
   the backend is not dialed — the minter fired synchronously in update());
3. a second incognito tab open does NOT prune an aged, nameless,
   transcript-less seeded row (the sweep gate; the seed is proven to sit on the
   tab's own store handle before any arm reads it);
4. BOTH positives: one flag bit away, the keeping tab's open DOES prune the
   aged row and its first dispatch DOES mint its row — the null/survives arms
   above are the gates, not a dead path.

### Mutation proofs (cp backup + md5-verified restore, 1c2a26de… tip)

- M1 seam gate stripped (`sessionMint: $opened['mint'] ?? null`): exactly 1
  failure — the new test, "an incognito tab is handed no minter to fire".
  RED before the fix, green after. (28/35 assertions, execution stops there.)
- M2 prune gate neutered (`instanceof EnhancedSessionStore` without the flag
  arm): exactly 1 failure — "an incognito tab open ran the empty-session
  sweep". Discriminating.
- Both restores md5-clean against the backup; post-restore full run green.

## NITs — both folded (cheap + in-region)

- NIT-1: `useIncognito()`/`incognito()` (`Bootstrap.php:4238,4244`) indented to
  the 4-space method column. The two column-0 lines the lane's own commit added;
  the pre-existing column-0 family (useSessionLaunch :4228, pinPromptHistoryPath
  :4254, …) left alone — not this lane's to widen.
- NIT-2: `chat.incognito.status` relocated out of the `chat.mode.*` cluster to
  its alphabetical adjacency slot (`chat.help.keys_hint` → here →
  `chat.instructions.quick_add.appended`) in `candy-code/lang/en.php`. The block
  is unguarded; verified no guard owns chat.* ordering.

## Gates (cwd=candy-code, plain pipe, -c phpunit.xml, env -u LINES -u COLUMNS)

- `--filter IncognitoLaunchTest` → `Tests: 5, Assertions: 35` OK
  (2 deprecations = pre-existing AccessorDeprecationTest providers, reviewer
  saw the same pair at 4T/25A; my +1T/+10A).
- `--filter IncognitoLaunchTest|ArgvParserTest|NonInteractiveSessionPersistenceTest|StderrEmitterCensusTest|LaunchFlagsTest`
  → `Tests: 359, Assertions: 23512` OK (reviewer's 248+106+4 set = 358, +1 is
  this fix's new method).
- `--filter SettingsWriterCensusTest|CliLangCatalogueTest|LangParityTest|DuplicatedTestHelperDriftTest`
  → green. (`ChatHostLangCatalogueTest` + `GlobDialectDifferentialTest` join
  the set with the pre-existing reds disclosed below — unchanged by this
  round, proven pristine-tip identical.)

## Disclosure — pre-existing reds at the lane tip, NOT introduced by this round

Proven by backup → `git checkout` of the three edited files to pristine
00a07c290 → run → restore (md5-verified): each is red with and without this
round's changes. None is in REVIEW-1's finding list and none is this round's
file ownership; left for the weld/round-2 ledger:

- `BootstrapLaunchFormatConstantsTest::testEveryDocPageQuotingAPromotedFormat…`
  — PAGE_QUOTES derivation disagrees about the PROJECT_TIER_TOOL_REMOVAL_*
  ordering; the constants ride the merged master ledger (a98c29207) and the
  co-tenant C-SES-7.b tree (main tree @ a6c45be43) reproduces it too.
- `GlobDialectDifferentialTest::testTheCorpusFigureInTheDocBlockIsTheDerivedOne`
  — src/Util/PathGlob.php census pair stale (quotes 310,245; corpus derives
  560×556). Forbidden file for this lane; closeout re-derive duty.
- `ChatHostLangCatalogueTest::testEveryCatalogueKeyIsAskedForByChatOrHost` —
  7 unused-key entries, red at the pristine tip: 6 from foreign eras
  (chat.startup.personal, chat.attach.pdf_pages, chat.effort.launch_unshaped,
  chat.permission.not_persisted/persisted, chat.serviceTier.launch_unshaped)
  + chat.incognito.status, which the lane's own Renderer.php:2650 asks for
  outside the test's scanned source list. The test cannot go green from this
  lane without re-baselining six foreign rows — weld ledger item.

## Weld carries (unchanged from REVIEW-1, adjusted)

durations.tsv row for IncognitoLaunchTest.php (file is new to the branch);
suite-figure/README re-pin is now +9T (was +8T: this round adds one test
method). Foreign standing reds as REVIEW-1 recorded.

SHA: (fix commit recorded in the follow-up stamp below)
