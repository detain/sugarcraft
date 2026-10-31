# C-TUI-6.j — round-1 fix report

Lane: /home/sites/cc-cmp-C-TUI-6.j (branch cc/C-TUI-6.j). Base pick pair (never amended):
c68ac29ae (src wiring) + b8b92b490 (tests). Findings authority: REVIEW-1.md
(11,262 B; VERDICT: FINDINGS — C=0 M=1 MINOR=2 NIT=2).

## Duties status (this file updated per step; disk is truth)

- [x] NIT-1 fromConfig docblock truth — COMMITTED `afad9c717` (comment-only, +5/−3).
- [x] LEAD RULING (c) body-only overlay replacement — COMMITTED `703a45998`
  (BEHAVIOR CHANGE disclosed in subject; status bar + Agent View header
  survive, tab strip is body; chrome-survive leg added to
  testAnOpenOverlayReplacesTheBody; red-before PROVEN by run: whole-frame
  mutation → exactly 1 failure at the new leg, restore md5-verified).
- [x] MAJOR-1 leg (i) permission-options menuRow positions — COMMITTED `12680b111`;
  SR pin `['1/4','2/4','3/4','4/4']` order-exact, off pin `[]`; red-before by
  RUN (wrapper deleted → 1F at test :203, restore md5-clean).
- [x] MAJOR-1 leg (ii) split-diff gutter glyph — COMMITTED `12680b111`;
  SR asserts no `'= 1;│'` fusion + `'= 1; 2│'` gutter shape, off asserts
  `'= 1;│2│'`; red-before by RUN (glyph() dropped at Renderer.php :5849 →
  1F at :216, restore md5-clean). Side row-number │ separators stay unwired
  = design scope, documented, not silently widened.
- [x] MAJOR-1 leg (iii) Tui/Renderer.php renderDiffReview rule glyph —
  COMMITTED `12680b111`; rule line beneath 'Files changed': SR trim === ''
  (blank paper), off `/^\x{2500}+$/u`; red-before by RUN (glyph() removed at
  :1396 → 1F at :228, restore md5-clean). Tui chrome keeps │/┐/┘ under SR —
  legs assert rule-local, never whole-frame.
- [x] MAJOR-1 leg (iv) Tui/Renderer.php dropdownOverflowLine glyph —
  COMMITTED `12680b111`; Session menu open (MenuBar::openMenu(1), statics
  closed in setUp+tearDown): off has `'/│ …+│/'` >=1 and no shed row; SR has
  exactly one `'/^\s+…\s{2,}/'` row and no boxed overflow; red-before by RUN
  (glyph() removed at :2933 → 1F at :247, restore md5-clean).
- [x] LEAD RULING (b) SessionPicker.php — NOT touched (out of declared set; lead carries §7 rider).
- [x] NIT-2 — ACCEPTED, zero tui.sr.* keys added.

# C-TUI-6.j — round-2 fix report (appended 2026-10-31; round-1 text above intact)

Fixer tip at dispatch: `12680b111` (five picks). New tip: `c43c8e554`
(`git cat-file -t` = commit), one file, tests-only:
candy-code/tests/Renderer/ScreenReaderRenderTest.php +65.

## Record correction (round-1 checklist above is stale)

The four MAJOR-1 legs (i)-(iv) listed as IN PROGRESS LANDED in `12680b111`
and r2's APPENDIX A verified their teeth; none were re-touched here.

## Brief-issued MAJOR-1: REFUTED by execution

On-disk REVIEW-2.md (14,987 B) final verdict is `C=0 M=0 MINOR=1 NIT=3` —
the initial `M=1` header is struck/superseded by `12680b111`; the briefed
`:6592` cite is slash-menu badge code, not a menuRow site (real sites:
src/Renderer.php:7100 palette, :7684 permission options). Reproduced with
cp+md5 restore on this worktree (map allows it):
- M1 delete permission menuRow wrapper → EXACTLY 1 failure,
  testPermissionOptionRowsCarryTheirPositionsOnlyWithTheModeOn.
- M2 palette SR arm → glyph path → 2 failures,
  testPaletteRowsCarryTheirPositionAndATextMarker +
  testAnOpenOverlayReplacesTheBody.
Both legs discriminate. Nothing to cure; no assertion weakened.

## MINOR-1 (real): TAKEN — Agent View header splice beside the SR overlay

`src/Renderer.php:2117` splices `$agentHeader` into the SR frame; r2 proved
no committed fixture opened an overlay over an agent view (my M3 run agrees:
before the fix, deleting the splice stayed 9/9 GREEN). Design basis:
design-tui.md `### C-TUI-6.j` — the overlay "REPLACES the body", chrome
survives above the clip; the header row is chrome (P-C2 / E48 doctrine,
shipped docblock :2108-2115).
New pin: `ScreenReaderRenderTest::testTheAgentViewHeaderSurvivesBesideTheScreenReaderOverlay`
(SR arm: full unclipped `main ▸ <name>` header row beside overlay, body band
replaced, rule line intact; off arm: clipped-veil counterpart `main ▸ ` +
`user> chi`, off-mode byte-identity preserved). Red-before/post: M3
(SR splice deleted) → EXACTLY 1 failure naming the new method; restore
md5 2c13499e54b643815a30dd498b792a41 verified. tearDown gains
`Renderer::setAgentView(null)` leak guard.

## Gates (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)

ScreenReaderRenderTest 10T/57A (was 9T/47A, +1T/+10A in-step);
ScreenReaderBellCueTest 2T/4A; TurnEndNotificationTest 10T/22A;
ScreenReaderModeTest 11T/54A; off-mode capture
PaletteCaretHonoursHandoffTest 3T/18A; BARE guards TreeWideGuardRosterTest
17T/3869A + DuplicatedTestHelperDriftTest 14T/204A. All GREEN (PHPUnit
Deprecations: 2 is this tree's pre-existing shape). src/Renderer.php
untouched by this pick → off-mode output structurally byte-identical.
§7: NO new test file (existing file extended in-step → no durations row;
file live count 10T). NIT-1/NIT-2/NIT-3 untouched (weld-ledger items).
Zero forbidden-path edits; porcelain 0; no git ever run against
/home/sites/sugarcraft.

# C-TUI-6.j — ROUND-1 CLOSEOUT (final; appended 2026-10-31 after the round-2
# section because the concurrent r2 pick landed inside round-1's gate window)

Round-1 picks (this agent, all cat-file'd = commit, author/committer
detain@interserver.net, zero Co-Authored): afad9c717 (NIT-1, comment-only),
703a45998 ((c) BEHAVIOR CHANGE, subject-disclosed), 12680b111 (MAJOR-1 legs
i-iv, tests-only, ScreenReaderRenderTest.php +148/−0 pure insertions).
Builder pair c68ac29ae+b8b92b490 never amended. SessionPicker.php never
touched (lead ruling b). NIT-2 accepted: zero tui.sr.* keys added.

Red-before/green-after — ALL FIVE BY RUN, no UNVERIFIED-BY-RUN needed
(each mutation applied to the real file, targeted --filter, cp+md5 restore):
- (c) chrome leg: whole-frame mutation → 1F at :162 naming only the new leg;
  6 existing overlay legs stayed green; restore md5 2c13499e… verified.
- (i) menuRow wrapper deleted → 1F at :203. (ii) gutter glyph dropped → 1F
  at :216. (iii) rule glyph dropped → 1F at :228. (iv) overflow glyph
  dropped → 1F at :247. Green-after in every case, md5sum -c OK.

Assertion inventory: +1 chrome leg (703a45998), +18 legs across 4 new methods
(12680b111), ZERO assertions removed or weakened; class 5T/28A → 9T/47A at
my tip. SR-off byte-identity: the (c) splice lives inside the
`if (ScreenReaderMode::enabled())` branch and the statusBar hoist is the same
call in the same slot — off output structurally unchanged (KeyHelpTest goldens
byte-identical: same 5 accepted dataset reds + 1 risky, same names as r1).

Gates (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe,
--no-coverage; Deprecations:2 = tree-wide accepted shape):
lane family (Render|BellCue|TurnEnd|Mode|DiffReviewWiring|AbandonedPalette)
46T/230A GREEN (post-c43c8e554 count, r2's +1 included); quartet
33T/137A GREEN; TreeWideGuardRosterTest BARE 17T/3869A GREEN;
DuplicatedTestHelperDriftTest BARE 14T/204A GREEN; guard set
(DocFigure|EnvRoster|KeyBinding|ReadmeRoster|StderrEmitter|ReadPathCensus|
SuiteSkipRoster|GlobDialect) 538T/71549A with EXACTLY the 1 accepted foreign
GlobDialect staleness red (310245-vs-311360 at :336 — asserted NEITHER).
KeyHelpTest:: 63T/96506A, 5 accepted reds + 1 risky; −83A vs r1's 96589
adjudicated: file has zero screenReader refs; failures are live-roster-driven
loops ('Slash commands (67)'), my literals are GlobDialect-corpus-exempt.
PtyRunToolTest::testExpectMissIsLoudAndTheScriptStillContinues EXCLUDED; no
full suite ever run. §1.8 drift guards NOT-OWED (held).

§7 riders (round-1, for the welder): (a) split-diff side row-number │
separators and the Tui docked/menubar chrome walls remain unwired SR seams —
out of the step's declared scope, deliberately not widened; (b) SessionPicker
row painting is the LEAD's dated rider, not this lane's; (c) concurrent
r2 pick c43c8e554 (MINOR-1 agent-header pin) landed on this worktree during
round-1's gates — round-1 verified it is tests-only on a distinct method, no
collision, and both loops' commits are intact in rev-list 9a0c569eb..HEAD (6
picks). (d) REVIEW-2.md supersession (M=1 struck by 12680b111) noted in the
round-2 section above.

Tree state at round-1 close: porcelain 0, no residue greps clean, tip =
c43c8e554 (round-2's, left in place), no git ever run against the main tree.
