# Lane C-SES-3.d — Round-2 Review (fresh, disk-derived)

VERDICT: CLEAN

Base cc14518d5; single commit 7b47daa31 (amended f899d0551). All claims below re-derived from current code/git; prior verdict not trusted.

## (1) Amend diff = wording erratum only — CONFIRMED
`git diff f899d0551..7b47daa31`: exactly 3 files (Serve.php, ShareCommand.php, SharePageController.php),
every hunk entirely inside `//` comments or `*` docblocks. Zero executable lines. Author law: %ae and %ce
grepped SEPARATELY, both count 1 detain@interserver.net; zero Co-Authored/Signed-off trailers.
`cc14518d5..HEAD` is one commit; `git status --porcelain` empty (before and after).

## (2) Erratum is TRUE against code — CONFIRMED
ShareSession constructor filter is a pure predicate (`instanceof Message && pendingToolCallId===null &&
userVisible && (!uiOnly || toolResults!==[])`); no slice/take/cap constant exists in src/Share/**.
The three corrected passages now state "not a size bound / no row cap exists / full filtered transcript
re-serialized every request" — matches the code. Serve.php closure: `new ShareSession(...)->serialize()`
over all revived rows — no bounding. Tree-wide grep for leftover share-cap claims: only the erratum's own
"named a cap that was never there" sentence + unrelated families (ModelCatalog MAX_ROWS, Renderer
*_MAX_ROWS, BackgroundShellRegistry, Read OUTLINE_MAX_ROWS, PtyRun MAX_ROWS — none share-family).
COMMANDS.md/lang/en.php additions carry no cap/bound wording.

## (3) Gates reproduced EXACT (cwd candy-code, plain pipe, env -u LINES -u COLUMNS)
- `--filter 'SharePage|ShareStore|ShareCommand|CommandDocs|CommandRegistry|LangParity'`:
  "Tests: 114, Assertions: 3468" — matches FIX-1 claim exactly.
- Broad `--filter 'hare'`: "Tests: 365, Assertions: 4731" — matches (365T/0F, +4731A figure), 25s wall.
- PtyRunToolTest: zero "hare" substrings in its file (grep -c = 0) → never ran. (`--list-tests` is a
  listing quirk: it enumerates all 26,512 despite --filter; the run figures are the truth.)
- 2 PHPUnit deprecations ride the "OK" shape — informational, non-failing, era-consistent.

## (4) Hygiene — CONFIRMED
Base→tip diff = the declared 12 files; zero hits for suite-figure/durations/dist/coverage. Worktree clean.

## (5) Non-vacuity spot-checks (re-reasoned from code)
- Revoke-before-render: `SharePageTest::testRevokedAndExpiredLiveRowsNeverReachTheRenderer` carries a
  $renders counter pinned at 1 across 200→revoke→404 and expiry→404; lookup SQL filters
  `revoked_at IS NULL AND expires_at > :now`, so a reorder that renders first moves the counter — pin fails.
- Null-renderer 404 byte-identity: `testALiveLinkWhoseSessionIsGoneAnswersTheSharedDeath` assertSames
  body against a never-existing token route — any divergent death sentence reddens it.
- Idempotent ALTER: ensureSchema runs at atPath/publish/revoke; without the PRAGMA probe the SECOND
  call on a fresh table would fatal duplicate-column — nearly every store test trips it. Migration pin
  plants a real 6-column pre-live table + row, then proves lookup, snapshot semantics, and flag recording.

## Carried (unchanged from round 1, not claimed fixed)
- NIT pre-existing pattern: Serve.php liveRenderer wiring closure (loadTranscript→reviveRow→serialize)
  exercised only via injected renderers in SharePageTest; the production wiring site is unpinned.
  Weld-cure candidate, step-scope none.

No new findings. The erratum took truthfully; behavior, guards and figures are untouched by the amend.
