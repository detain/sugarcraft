# C-EXT-6.a — REVIEW-1 (fresh, r1)

VERDICT: CLEAN — 0C/0M/0 MINOR (1 NIT + welder carries below).

Tip 5f35a8957 (parent e6188662a), A/C %ae|%ce = detain@interserver.net both, zero trailers.
Scope: exactly the 5 brief files (ScriptHook.php, lang/en.php, HOOKS.md, new
ScriptHookSystemMessageNoticeTest.php, ScriptHookTest.php). No out-of-scope edits;
weld-only files untouched.

## Gates re-derived at tip (cwd candy-code, env -u LINES -u COLUMNS, plain pipe)
- `--filter ScriptHookSystemMessageNoticeTest` → OK **12T/66A** (spawn-relayed "120A"
  does not appear in the on-disk REPORT; REPORT says only "12 tests" — accurate).
- `--filter 'ScriptHook|RuntimeNoticeSink|Hooks'` → OK **1276T/10520A**, 0F
  (EXACT vs REPORT; 2 deprecations also present in the unrelated 367T guard band
  → tree-global, not lane). ScriptHookTest net +0T (2 renames), tree +12T.
- `--filter 'GlobFigureDrift|ReadmeRosterDrift|EnvRosterDrift|TrustKey|ConfigWriteProducer|KeyBindingDrift|HookEnvironmentDocumentationDrift'`
  → OK **367T/44351A** (EXACT vs REPORT).
- The spawn-relayed "1289T/11769A/6F" band is not the builder's claim and did not
  reproduce as such; my union 'Hooks|RuntimeNoticeSink|ChatHostLangCatalogue|ReadmeSuiteFigure'
  → 1283T/10555A/**3F**, all explained:
  1. ChatHostLangCatalogueTest — 5 offenders are ALL `chat.*` keys (StartupSummary/
     SavedRules family); the new `hooks.notice.system_message` is out of that guard's
     key-space; builder's revert-proof method sound, red is content-invariant.
  2/3. ReadmeSuiteFigureDrift staleness pair — the disclosed +12T lane-forbidden-file
     carry; expected red until batch re-pin, NOT a lane defect.
  +4. BootstrapLaunchFormatConstantsTest::testEveryDocPageQuotingAPromotedFormatIsOnTheReadersRoster
    (PAGE_QUOTES vs PROJECT_TIER_TOOL_REMOVAL_*/SESSION_RETENTION roster drift, other
    lanes' territory): BASE-PROVEN by content invariance — every input of its derivation
    (Bootstrap.php, the test file, README/SETTINGS/TROUBLESHOOTING/ENVIRONMENT/CI/DOCKER)
    is byte-identical between e6188662a and 5f35a8957; the only overlapping file,
    HOOKS.md, adds zero lines quoting any promoted format (grep of the added lines vs
    the constant texts: 0 matches).
- Mutation proofs are STATIC here: this reviewer's shell map refused worktree-add and
  /tmp copies, so no re-run; the discrimination analysis below stands in for the run,
  and the builder's claimed "removal reddens 10" matches my count exactly.

## Core judgments
1. **Reachability (REAL).** HookDispatcher.php:246 `$hook->execute($context)` →
   ScriptHook parse → fromEnvelope:1843 → recordSystemMessageNotice → RuntimeNoticeSink::record
   (public, :427). Fork leg rides C-EXT-1.a's transport backend armed in
   Bootstrap::workspace(); TUI drain owner = Chat::pumpRuntimeNotices gated
   `drainsRuntimeNotices` (Bootstrap::chat():1501 launch appointment, P-TABS
   switchTab). Same production shape the welded C-EXT-1.a dialect notices ride.
2. **Clipping/escaping NON-VACUOUS.** record-time `Sanitize::untrusted()` + `/\s+/`→' ' +
   trim + clip(NOTICE_REASON_BYTES=400), byte-identical to the house one-line idiom at
   PostEditDiagnosticsHook.php:167. Pins: full-row pin proves the WHOLE SGR sequence is
   removed (rejoined "beware", not "be[31mware"/"bewware"); ESC/NUL/BEL absence pins;
   \n/\r\n/\t collapse pins; oversized (900B) pins `clipSuffix()` tail + `mb_strlen ==
   MAX_CHARS` (sink contract), lead survives. PUA sentinels survive record-time untrusted
   BY DESIGN and are swept at paint time by Renderer::untrusted() =
   stripZoneSentinels+untrustedForDisplay — the System/uiOnly branch Renderer.php:4388
   applies it to notice rows (verified). Neutering each leg reddens a named pin.
3. **Human-channel-only.** Model surface pinned empty per verdict (`additionalContext
   === ''` in every test incl. suppress); verdict `message` carries only reason. Drain
   lands as `Message::notice` = Role::System + **uiOnly:true** (Message.php:331 — off the
   model wire by the 15b-03 mechanism). serve/JSON: no drain owner exists there (grep:
   `withNoticeDrain`/`drainsRuntimeNotices` appear in Chat/Bootstrap/App only; zero
   Server/ hits), no pin needed since -p/serve never read the inbox; HOOKS.md carries the
   serve-visibility caveat per the brief's risk line.
4. **suppressOutput-doesn't-hide REAL.** record sits at :1843, before the suppress block
   (:1874 nulls $note only). Pins: new test assertCount(1) with suppress=true, and
   ScriptHookTest's flipped pair. Gating record on $suppress reddens exactly those 2;
   deleting the call-site reddens 8 + 2 = 10 (matches builder).
5. **E199 budget.** Row rides the shared sink → TURN_NOTICE_LIMIT per-turn truncation at
   the parent's drain side + MAX_CHARS per-row clip; no separate budget minted. Correct.
6. **HOOKS.md flip in-step.** "suppressOutput and systemMessage are not read" → two
   honest rules + table rows; :628→~:674/:705 line cite drifted (brief-era cite), content
   matches. Guard band green in-step.
7. **Malformed ordering.** record sits after type-leg (:1811 loop denies `systemMessage:42`),
   after unknown-decision leg; pinned by the two negatives.

## §1.8/§1.9
No census flips owed or landed wrong (no emitter/sprintf/proc_open sites; Lang catalogue
guard's key-space excludes hooks.*). GlobFigureDrift green → no glob-literal corpus drift;
no denial-vocabulary or `**Refused:**`-shaped test literals.

## NIT (welder may carry)
- ScriptHook.php:1841 call-site comment: "nothing here reaches the transcript" — read
  literally false: the drain DOES put rows in the transcript as UI-only Message::notice
  rows. Intended claim is "never model context / no direct transcript write". Suggest
  "…nothing here writes the transcript directly; the drain lands it as a UI-only row".

## Welder carries
- scripts/parallel-tests-durations.tsv: row for ScriptHookSystemMessageNoticeTest.php —
  measured 12T, file wall ~5.1s (per-testcase sum from the junit at batch gate recommended).
- suite-figure.json + README pair re-pin: live enum +12T (1276T hooks-band reproduces).
- Report to owner: the "12T/120A / 1289T/11769A/6F" figures in the orchestrator's own
  relay match neither REPORT nor disk — transcription noise upstream of this lane.
