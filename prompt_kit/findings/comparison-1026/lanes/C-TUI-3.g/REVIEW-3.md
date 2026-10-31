# REVIEW-3 — C-TUI-3.g adjudication of the r2 CLEAN — lane cc/C-TUI-3.g @ 78b3b97b7

VERDICT: CLEAN — weld-ready
C=0 M=0 MINOR=0 NIT=1 (r2's NIT-1 confirmed; recommend as one-line welder rider, not a new round)

Round-3 adjudicator (fresh session, owner-authorized restart after r2's terminal
notification). Object = REVIEW-2.md (11199 B, `VERDICT: CLEAN / C=0 M=0 MINOR=0
NIT=1`). Every file:line below was read in this session's shell against the LANE
tree /home/sites/cc-cmp-C-TUI-3.g @ 78b3b97b7. READ-ONLY: no edits, no staging,
no commits, no scratch worktree created.

## Ruling 1 — reachability — CONCUR (r2 right; lead's refutation of the old "no FocusGainedMsg" claim stands)
- sugar-core/src/InputReader.php:510 `private function decodeCsi(string $params, string $final)`, :512 comment "Focus reporting (CSI ?1004h): ESC[I → focus in, ESC[O → focus out", :515 `return new FocusGainedMsg();` (params==='' && final==='I'), :518 `return new BlurMsg();` (final==='O').
- decodeCsi is called ONLY at InputReader.php:165 inside the public parse() — live path.
- Program.php:1196 `foreach ($this->reader->parse($bytes) as $msg) { $this->dispatch($msg);` — real read loop.
- Focus reporting armed: Chat.php:7775 `reportFocus: true` in programOptions; Program writes `?1004h` under that option (Cmd::focusReporting → Ansi::focusReportingOn, verified earlier same tree).
- Route: Chat.php:2632-2633 `handleTurnCompletionMessages($msg) ?? $this->handleRunControlMessages($msg)` — run-control is second in the chain.
- Arms: HandlesRunControl.php:186 FocusLostMsg → focusLost(); :192 BlurMsg → focusLost(); :198 FocusGainedMsg → awayRecapOnFocusBack().
- awayRecapOnFocusBack :623-660 → :653-660 `Cmd::promise(BtwHostCommand::call($titleBackend, RecapHostCommand::PROMPT, $this->history, $this->currentSessionId, framing: RecapHostCommand::FRAMING, failureKey: 'host.recap.failed'))` — a real title-backend call, not a stub. Chain complete: terminal ESC[I → FocusGainedMsg → arm → paid recap. NO MAJOR. (The r1-era "FocusGainedMsg is never constructed" claim was false: grep of `new FocusGainedMsg` over both srcs = exactly :515.)

## Ruling 2 — m2 pin-over-routing — CONCUR
- RecapHostCommand.php: run() :43-63; :50 `CommandResult::new(Message::user($text)->withUiOnly())` = the echo row the design forbids on the automatic path; class file is outside the lane's declared modify set.
- The auto arm shares the request BY CONSTANT: arm :655 `RecapHostCommand::PROMPT` (class :36), :658 `framing: RecapHostCommand::FRAMING` (class :39). The arg list at the two call sites is quote-identical (backend, PROMPT, history, sessionId, framing, failureKey) → same request bytes; there is NO second hand-rolled prompt.
- The one duplicated literal is `failureKey: 'host.recap.failed'` (arm :659 vs class :58) and it is now policed by testAFailedAutoRecapLandsTheRecapFailureVoice (:160-187): :178/:185 assert 'The recap failed', :186 asserts NOT 'side question'. lang/en.php:2758 `_The recap failed: {reason}_` vs :2542 `_The side question failed: {reason}_` — a key swap provably reddens :178 first. Residual drift surface = one string, pinned. ACCEPTED.

## Ruling 3 — one-per-absence bound / spend safety — CONCUR
- AwayTracker.php (read full): focusLost(?float $at=null) `self::$lostAt = $at ?? microtime(true)` — unconditional re-stamp, so double-Blur or Blur+FocusLost leaves exactly ONE pending absence.
- focusGained() reads `$lostAt`, then `self::$lostAt = null` BEFORE the elapsed test, and returns elapsed only at `>= AWAY_SECONDS` (180.0) — consumption is structural even on the below-threshold and no-stamp paths, so rapid flap pairs each silently spend their gap.
- awayRecapOnFocusBack's FIRST statement is `AwayTracker::focusGained() === null` (:625); every remaining gate — inFlight/draft/setting (:629-635), null-title || spendCapReached (:637-640), MIN_USER_TURNS floor (:642-651) — runs only after consumption, so a blocked regain still spends its absence and cannot pay twice. Pinned by testOneRecapPerAbsence (:144-158: second regain assertNull) and testMidTurnTheReturnIsSilentAndTheStampIsConsumed (:189-202: second-regain assertNull :201-202). No unconsumed-reusable path exists. E646 clean (no timers added).

## Ruling 4 — two-arm shape — CONCUR
- RouteTraitParityTest.php harvest (topLevelArms) pattern: `/(?:if|elseif) \((!)?\$msg instanceof \\?(?:[A-Za-z_\\]*\\\\)?([A-Z][A-Za-z0-9_]*)/` — requires the literal `if (`/`elseif (` immediately before the optional bang and `$msg instanceof`. The second operand of a `||` union inside ONE `if (` never follows `if (`, so it is unharvestable, while FROZEN_ARM_ROSTER carries its own 'BlurMsg' row (:55, dated C-TUI-3.g M1 comment) and testTheArmFamiliesArePairwiseDisjointAndCoverTheFrozenRoster (:225-246) does assertSame on the sorted union. A union rewrite would drift the roster red. The duplication is census-mandated; the arm-site comment at HandlesRunControl.php:184-185 says exactly this. r2's claim VERIFIED.

## Ruling 5 — r2's mutation evidence — self-consistent (spot-verified)
- Pre-fix src + HEAD tests red-at-AwayRecapTest.php:93: without the BlurMsg arm, `update(new BlurMsg())` is swallowed by a later handler and the reflection read of AwayTracker::$lostAt stays NULL → assertIsFloat(null) = "null is of type float". Consistent with the code as written (:192 arm is the only production writer of the stamp).
- Deleting `|| $this->spendCapReached()` at :638 (null-leg kept): the cap pin testAtTheSpendCapTheAutoReturnIsSilent reaches :653 and returns the promise → assertNull at :337 ("the refusal voice belongs to the typed door alone") goes red. r2's :337 matches disk exactly (FIX-1's ":308" was stale). The typed door keeps its OWN separate cap check (Chat.php handleRecapCommand :2858-2864, refusal string), so testATypedRecapAtTheSpendCapRefuses (:309-320) correctly survives that mutation — as r2 reported.
- Swapping the arm's failureKey to 'host.btw.failed': the resolved DTO's answer carries '_The side question failed: …_' → :178 fails first, message shape as r2 quoted. Exactly-one-failure claims are coherent: each mutation kills one assertion leg before its sibling legs in the same method.
- No pin examined survives removal of the fix it pins.

## Lead's pre-stop Claim C — FALSE (mis-sourced, no finding)
The briefed "AwayRecapTest.php:231 asserts is_int($seconds)" does not exist: :231 is a blank line (grep :229-233 shows :229 'under the floor…' assertNull, :230 '}', :231 blank); `grep -n 'is_int|assertIsInt'` over AwayRecapTest.php and AwayTrackerTest.php = zero hits. Nothing to rule; the only is_int matches under tests/Tui belong to MediaFormFieldsTest (unrelated).

## Lead's pre-stop Claim B (thinking-panel routing) — not raised by r2, closed here anyway
The auto row lands via the shared SideQuestionAnsweredMsg fold as a uiOnly ASSISTANT row (AwayRecapTest :100-105 pins Role::Assistant + uiOnly + content in history) — transcript-visible, not a system/thinking-panel row. Its heading rides the default btw voice because the fold applies Async effects without the command's describe — declared VERBATIM in RecapHostCommand.php's class docblock ("a recap-voiced TUI row is a C-TUI-3.g seam") and carried as r1 rider m3; the fix site is outside this lane's declared set and the mismatch is identical for manual /recap → §7 rider, NOT step-local, NOT a MAJOR.

## NIT-1 (r2's lone finding) — CONFIRMED; rider handling CORRECT
AwayTracker.php:13-15 docblock still reads "every `FocusLostMsg`/`FocusGainedMsg` then lands in `Chat::handleRunControlMessages()`" — post-M1 the production loss shape is the decoder's BlurMsg (:518); the correction landed in the arm comment, not here. Zero behavior/pin impact. A one-line welder RIDER commit with in-mark disclosure is the right call — a whole new round for a stale sentence would be wasted cycle.

## Gates re-run this session (lane tip 78b3b97b7, cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS plain pipe)
- (AwayRecapTest|AwayTrackerTest|RouteTraitParityTest|CommandRegistryTest): OK, Tests 46, Assertions 303, Deprecations 2 (standing).
- AwayRecapTest alone: OK, Tests 16, Assertions 77. RouteTraitParityTest alone: OK, Tests 4, Assertions 44. Splits match r2's figures exactly.

## WELD DECISION
r2's CLEAN stands on independent re-derivation: all five rulings CONCUR, no CRITICAL/MAJOR/MINOR added by this round. The lead's HOLD is UNJUSTIFIED — release it. C-TUI-3.g is weld-ready as-is: §4 mark + record commit + prune remain, carrying r2's NIT-1 (one-line docblock truth-flip) and r1's m3 voice-seam as disclosed riders.
