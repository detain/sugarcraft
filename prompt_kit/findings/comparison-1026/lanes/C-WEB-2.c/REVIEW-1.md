VERDICT: CLEAN (0 CRITICAL / 0 MAJOR / 0 MINOR / 1 NIT) — weld-ready.

Lane: cc/C-WEB-2.c @ e8923c331, diff master...HEAD = exactly 4 files +323/-19
(NonInteractive.php +69, EngineBackend.php +13, SKILLS.md ±8, NEW
tests/Cli/NonInteractiveSkillSlashTest.php 252L/9T). No web/dist/trio touches
(green-equivalent by design).

Priority checks, all PASS:
1. ORDER law: draft at NonInteractive.php:1705 precedes SkillMentions resolve
   :1716-1717 on $text; line-reasoned (read-only, mutations NOT executed):
   resolve-on-raw-prompt ⇒ testATypedSlashNameRewrites… attachments count and
   the live-chain body assert both RED (slash prompt has no `$`). Both swap
   directions bite real pins.
2. stdin never scanned — SECURE and genuinely pinned: argv prompt is the ONLY
   draft/scan input; readStdinIfPiped() feeds $stdinContext only (call site
   :494), content prepends it AFTER (:1706-1707). testPipedStdinIsNeverRewrittenOrScanned
   feeds "/shipit…\n$shipit too" AS $stdinContext and pins byte-identical
   content + zero attachments — the exact production stdin argument. NIT (below).
3. $HOME/$1/$PATH literal: testADollarThatIsNotASkillStaysPlainText (registry
   armed, nothing attaches/notices) + expand-direction pin testATypedDollarMention…. Both directions.
4. Null-registry byte-identity: legacy 4-arg call asserts OLD SHAPE (content
   === input, attachments [], notices []) for both / and $ spellings, plus
   run()-level null-registry live chain.
5. Wiring: EngineBackend::skillRegistry() returns $this->skillRegistry (the
   withSkillRegistry-carried instance); call site reads it off the backend
   (:521 instanceof arm); grep proves zero Bootstrap::skillRegistry in
   NonInteractive.php. Live-chain fixture skill lives ONLY in the backend's
   sandbox registry, so any fresh-registry call-site slide goes red. -p only is
   the design scope (TUI via Chat::submit, serve via SessionHost — C-WEB-2.b).
6. Parity with TurnController::userTurnMessage (:1196+): same gate
   `!== null && str_contains($text,'$')`, same skilled→resolved→context attach
   order, same [...skilled,...resolved,...context] notice order; -p drains via
   existing noticeAttachment. HistoryFrom now mirrors it line-for-line.
7. Refusals verbatim from SkillMentions (:212-225 disabled/non-invocable/
   max-3 wording; unknown = silent, pinned). Zero lang keys added (diff has no
   lang file). SKILLS.md paragraph matches shipped semantics exactly.
8. Scope: 4 files only; slashCommands() untouched so the ARCHITECTURE 64
   numeral stays truthful (DocFigure roster green as run); no suite-figure/
   durations/dist touches; ReadmeRosterDrift green ⇒ README -p bullet owed nothing.

Gates reproduced EXACT (lane worktree, cwd=candy-code, plain pipe, PtyRun
never matched): own 9T/47A; NonInteractive 157T/716A; skill family 32T/157A;
EngineBackend 299T/2062A; SessionHost|TurnController|ContextMentions|FileMentions
176T/892A; doc guards 119T/4375A; extra sweep GlobDialectDifferential 13T/366A
GREEN (new file carries no glob-shaped literals); DuplicatedTestHelperDrift
failing ONLY on the standing foreign HEAVY_CHARS pair (diskRegistry twin
claims hold under the guard).

NIT (closeout): the stdin trust boundary is pinned at historyFrom's $stdinContext
argument (where it is implemented); no run()-level pin pipes a real stream.
Acceptable — argv/stdin routing at the call site is one line and visibly
untouched.

§4 WELD CARRIES:
- NEW durations.tsv row (welder-minted): candy-code/tests/Cli/NonInteractiveSkillSlashTest.php — 9T.
- Figure delta: live enum +9T / +47A own-file (staleness pair is standing; full
  re-pin is closeout duty, not this weld).
- No doc/roster rows owed beyond the landed SKILLS.md paragraph.
- Weld note: cut base 5f8717874, master moved during the lane — cherry-pick,
  expect SKILLS.md shared-doc drift with concurrent welds.
