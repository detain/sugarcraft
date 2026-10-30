# C-SEC-8.d — fix round 1 (minor-only)

r1 verdict CLEAN 0C/0M/2m/2n. This round lands the two step-local MINORs, both
test-only, plus the optional NIT-1 (comment-only). NIT-2 (the plan cite inside
the src comment matching local precedent) was left untouched as instructed.
Zero src semantics changed: `src/Chat.php` edit is inside an existing comment
block only.

## MINOR-1 — refused polarity pinned explicitly
`candy-code/tests/Host/SubmitInvisibleUnicodeTest.php`, in
`testAWhitespaceOnlyInvisiblePromptIsRefusedAfterSayingSo`:
`assertNotSame(TurnTicket::STARTED, $ticket->admitted)` →
`assertSame(TurnTicket::REFUSED, $ticket->admitted)`, matching the house
refused-shape idiom (`tests/Host/SessionHostTest.php:179`,
`tests/Host/SessionHostCommandsTest.php:162`, `tests/Host/ServedSkillSlashTest.php:216`).

**Mutation-reason (reasoned, not run).** The old negation accepted ANY other
admission shape: an implementation whose all-invisible arm returned
`TurnTicket::queued(1)`, `pending()`, `steered(...)` or `handled()` — i.e. the
host taking the submission and parking it instead of refusing it — passed the
pin while still appending no user row. `TurnTicket::refused()` is the only
value the new `assertSame` accepts, so every one of those flips now reddens,
and so does a flip that dispatches (`started()`), which the old pin also
caught. `isAdmitted()` is NOT used anywhere in this file, so no other leg
softens it.

## MINOR-2 — post-refusal usability pinned
Same test, a follow-up block after the refusal assertions:
`$host->submit('back to normal')` on the SAME host instance, pinning

- `assertSame(TurnTicket::STARTED, $after->admitted)` — the refusal is about
  that draft, not the session; an empty-only send that left the host busy,
  wedged or otherwise deaf would come back QUEUED/STEERED/PENDING and redden;
- one user row across both sends, `assertSame('back to normal', ...)` — a leak
  of the refused draft into the queue would append a second user row (or the
  empty one) and redden;
- `assertCount(1, $this->noticesOf($later))` — the refusal's notice stays the
  whole account; a notice-per-send regression on a clean prompt reddens.

The TUI-side twin has no such leg to mirror (the lane's
`tests/Chat/SubmitInvisibleUnicodeTest.php` stops at "consumed with the
notice"), so this block is new rather than copied; no helper was duplicated
(`appended()`/`noticesOf()` are the file's own).

## NIT-1 (taken) — the wrapper names the Tab-queue deferral
`candy-code/src/Chat.php`, comment inside the `submit()` wrapper, after the
"no opt-out key" sentences. The mid-turn Tab door is the one prompt route that
does not pass through this choke: `HandlesKeys.php:693` Tab arm →
`Chat::queueDraft()` (`src/Chat.php:10775`) enqueues
`trim(expandPasteChips($inputBuf))` unstripped, and the strip happens at the
drain, when `releaseQueuedPrompts()` (`src/Chat.php:11361-11367`) re-enters
`submit()` with that entry as the draft. The comment now says exactly that —
the queue is the only place unstripped prompt bytes rest, and what the model
is sent is still cleaned. Prose only; no behaviour touched, no guard cites
this block (verified: no test matches "every route the draft" outside the
lane's own docblock).

## Gates (cwd=candy-code, `-c phpunit.xml`, plain pipe, `env -u LINES -u COLUMNS`)
- `--filter SubmitInvisibleUnicodeTest` (both files): 12T/39A at branch tip →
  **12T/43A**, OK. +4 assertions = the 4 new legs, +0 tests (one test widened,
  deliberately — a new method would need a `durations.tsv` row, which closeout owns).
- `--filter 'SessionHost|InFlightInputQueue|EnterWhileBusySteers|ServedRuntimeNoticeDrain'`:
  **122T/1180A OK** (the 2 PHPUnit deprecations are pre-existing at tip, present
  in the before-run too, and never emitted in per-file runs).
- Guards `--filter 'DocFigureProseDrift|DuplicatedDocBlockLine|DuplicatedTestHelperDrift|GlobDialect|ChildWallClock|SwallowingCatch|ServedRuntimeNoticeDrain'`:
  **153T/22878A/2F** — byte-identical failure set WITH and WITHOUT my edits
  (measured by file-copy restore at the same tip, md5-verified both ways).
  Both reds foreign-inherited: `SwallowingCatchSrcCensus` roster row, and
  `DuplicatedTestHelperDrift::testNoDuplicatedConstIsUnrecordedInEitherPolity`
  naming `Chat/CopySlashCommandTest`/`Commands/CopyCommandTest` OPEN/CLOSE,
  `Chat/MouseTextSelectionTest` STUB_CANDIDATE and `ResumeCostGuardTest`/
  `ResumeCostEstimateTest` HEAVY_CHARS — none of them this lane's files.
- `php -l` clean on both edited files.
- Extra safety nets run because NIT-1 edits a src comment:
  `--filter 'DenialPrefixRoster|StderrEmitterCensus|SymbolCitationDrift'` →
  **121T/40967A/2F**, and the byte-identical same 2F/121T/40967A with my two
  files reverted at the same tip (md5-verified restore both ways) — neither
  red is mine. See the disclosure below.

## Disclosure — this step shipped a guard red that r1's CLEAN verdict missed
`SymbolCitationDriftTest::testEveryCitationOfATestSymbolIsParseable` is RED,
with exactly one row: `tests/Chat/SubmitInvisibleUnicodeTest.php: InFlightInput
QueueTest::withDraft`. Landed-blob proof, not a dirty-tree read:
`git cat-file -e aa5ddf638:candy-code/tests/Chat/SubmitInvisibleUnicodeTest.php`
says the path does not exist on master, and
`git show f1d679d3d:candy-code/tests/Chat/SubmitInvisibleUnicodeTest.php | sed
-n '54p'` shows this step's own helper docblock opening
``/** Copy of `InFlightInputQueueTest::withDraft` byte-for-byte: …`` — a bare
backticked TEST-symbol cite the census cannot parse into class + member.
Root cause is the step, the guard was outside the lane's gate set (REPORT lists
DocFigure/DuplicatedDocBlockLine/GlobDialect/ChildWallClock/SwallowingCatch, never
SymbolCitationDrift), and r1 did not run it either.

**NOT fixed here**, because it is not one of this round's listed findings and
the cure is a wording decision in a file this round does not own: either re-spell
the cite into the parseable qualified shape, or drop the backticks (the census's
own message says "teach this census the shape rather than letting it drop the
occurrence"). One line either way — flagged for the lead's ruling.

The second red, `DenialPrefixRosterTest::testTheOnlyDenialShapedLiteralsInSrcAre
TheLeafsAndOneEarnedException`, names `src/Tools/BuiltIn/SkillWorkshop.php`
('SkillWorkshop refused: ') — a file this step never touches: foreign-inherited.
