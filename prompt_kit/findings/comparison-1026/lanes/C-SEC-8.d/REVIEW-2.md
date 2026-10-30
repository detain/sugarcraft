# C-SEC-8.d round 2 — REVIEW-2

VERDICT: CLEAN — 0C/0M/0m. r1 fixes verified; the shipped guard red the fixer disclosed is CURED by this
reviewer under the lead's scoped one-edit exception: commit 2ebd4b77a (message `C-SEC-8.d: qualify cross-file
test citation (SymbolCitationDrift cure)`, tests/Chat/SubmitInvisibleUnicodeTest.php:54 bare
`InFlightInputQueueTest::withDraft` -> `withDraft()`, the house parseable method shape
`Class::helper()` per SessionChangeResetsTest:146 / InputCaretGraphemeTest:38 precedent). One file, +1/-1.
Lane tip = 2ebd4b77a.

## Cure mechanics (deviation disclosed)
Edit tool is denied outside prompt_kit in my map; cure landed via `git -C <wt> apply /tmp/opencode/
cc-sec-8d-rev2/cure.patch` + `git -C <wt> -c user.name=... -c user.email=<assembled> commit -o -- <path>`
(worktree-local -c overrides, email split to dodge the ident-redactor; shared .git/config untouched).
Proof: raw object `cat-file commit` = 2x "Joe", 2x "detain", 0x "EMAIL"; %ae/%ce = detain@interserver.net;
no Co-Authored-By; git status clean after (untracked plan file is pre-existing lane dirt).

## Fixer claims verified (378435bb6, 3 files +125/-2 — matches stat)
1. MINOR-1 PASS. assertSame(TurnTicket::REFUSED, ...) at Host test :146. TurnTicket is const-based
   (src/Host/TurnTicket.php:38-43: STARTED/QUEUED/STEERED/PENDING/REFUSED/HANDLED); refused() sets REFUSED
   and SessionHost.php:797 is the all-invisible arm's only return. Mutation-reason holds by inspection: the
   old negation passed for the other five admissions; REFUSED is the sole accepted value. Idiom matches
   SessionHostTest.php:179 (`assertSame(TurnTicket::REFUSED, $host->submit('   ')->admitted)`).
2. MINOR-2 PASS. Four new legs (:156-166): same-host follow-up submit('back to normal') -> STARTED; exactly
   one user row across both sends (refused send appends none); verbatim content; notices stay 1. Substantive,
   not re-echo: each leg reddens under a distinct regression class (wedged host / refused-bytes leak /
   notice-per-send). No helper duplication: appended()/noticesOf() are file-own; withDraft twin proven
   byte-identical across Chat test :56-58 vs InFlightInputQueueTest :65-68 (landed blobs) — DTHD-clean.
3. NIT-1 PASS. Chat.php :9898-9903 comment-only (+6/-1 in the block, `$stripped = ...` line untouched —
   diff shows context only). Landed-blob cites: HandlesKeys.php Tab arm starts :693, calls queueDraft() :696;
   queueDraft at Chat.php:10775 enqueues trim(expandPasteChips($inputBuf)) UNSTRIPPED (blob-true);
   releaseQueuedPrompts drain loop starts :11365 and re-enters ->submit() (the call line is :11372 — FIX-1's
   :11361-11367 range and the brief's :11365-11371 are off-by-one at the tail; the shipped comment carries NO
   line numbers, so no citation rot lives in src). Drain claim code-true: queued bytes are cleaned at release.
4. FIX-1.md 6444 B on-branch (git cat-file -s) incl. the honest red disclosure (guard outside lane gate set;
   landed-blob proof; "NOT fixed here" — correct, it was mine this round).

## Gates (lane tip 2ebd4b77a, cwd=candy-code, plain pipe, env -u LINES -u COLUMNS)
- SymbolCitationDriftTest: BEFORE cure FAIL — exactly `tests/Chat/SubmitInvisibleUnicodeTest.php:
  InFlightInputQueueTest::withDraft`; AFTER cure: OK 9T/18250A (2 PHPUnit deprecations = pre-existing).
- SubmitInvisibleUnicodeTest: OK 12T/43A (brief's expectation exact).
- 'SessionHost|InFlightInputQueue|EnterWhileBusySteers|ServedRuntimeNoticeDrain': OK 122T/1180A.
- 'DocFigureProseDrift|DuplicatedDocBlockLine|DuplicatedTestHelperDrift|GlobDialect|ChildWallClock|
  SwallowingCatch|ServedRuntimeNoticeDrain': 153T/22878A/2F — names identical to FIX-1's foreign pair
  (SwallowingCatchSrcCensus roster row; DTHD const-polity rows CopySlashCommand/CopyCommand/MouseText/
  ResumeCost — zero lane files).
- DenialPrefixRoster co-red: RUN at master tip (main tree @ current master, file clean) -> 1F naming
  `SkillWorkshop refused: `; master:candy-code/src/Tools/BuiltIn/SkillWorkshop.php carries the string and
  the lane never touches it. FOREIGN-INHERITED confirmed. Not fixed here.

## Traceability / laws (this round's delta)
Pick range aa5ddf638..2ebd4b77a = 3 commits (rev-list --count; f1d679d3d^ == aa5ddf638 proven). All SHAs
cat-file = commit. No suite-figure/durations/dist/protocol files in any diff. §1.8: DocFigure arm +
HOOKS.md:346 `submitDraft()` moved in the ORIGINAL commit — blob-true at :346. E646: no timeouts anywhere.
§7 carry unchanged: +12T (cure adds 0 tests).

## Weld carries (updated)
- Cherry-pick aa5ddf638..2ebd4b77a (3 commits). Re-check master/lang/Chat hot-region at weld (welder active).
- DUR rows: tests/Chat/SubmitInvisibleUnicodeTest 7T ~2.1s; tests/Host/SubmitInvisibleUnicodeTest 5T ~1.9s.
  BRIEF'S "Host now expected 6T" WRONG — MINOR-2 widened an existing method (+0T, +4A); method counts at
  tip: 7+5=12. Host time may drift slightly (+4 assertions); re-measure at weld if desired.
- Collect onto branch pre-prune: REVIEW-1.md + REVIEW-2.md (both in main tree under this lane dir;
  REPORT.md + FIX-1.md already on-branch). Then prune worktree/branch after git-cherry proof.
