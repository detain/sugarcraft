# C-CTX-2.d — REPORT (wave 16, provider/context, web-touching)

VERDICT: DONE. Tip 566456295b67df444c182f268facdec76c6e0eb8 on cc/C-CTX-2.d (base e4a705244), one commit, 12 files, tree clean, NEVER pushed.

Shipped: prompt.suggested + context.changed minted ephemeral (SessionEvent consts, EventType CATALOGUE [false, SCOPE_SESSION], EventSchemas rows); SessionHost suggestion arm → landPromptSuggestion (bill-first OBS-3, released guard, sanitize, stale generation|historyCount|sessionId, turn!==null drop, announce, never persist); settle/typedCompact/applyCommandResult(['rules','memory','init']) announce context.changed; web reducer holds suggestedPrompt (cleared on turn.started/snapshot), Composer grey placeholder + ArrowRight/Tab accept-once (route-derived session, SessionView.vue untouched), contextSources debounced-≥500ms refetch.

NEW test files (welder durations duty):
- candy-code/tests/Host/ServedPromptSuggestionTest.php (4T/48A)
- candy-code-web/src-web/components/__tests__/composerSuggestion.spec.ts (5)
- candy-code-web/src-web/stores/__tests__/contextChanged.spec.ts (4)

Gates (plain pipe, env -u LINES -u COLUMNS):
- PHP family 'SessionHost*|SessionHub|TurnRunner|TitleService|EventLog|Conformance|HelloMediaFlags|TranscriptProjector|EventType|ServedPromptSuggestion|SessionHostTurnSettleWork': Tests: 187, Assertions: 1083. (Corrected at weld per r1: the original run's figure 99T/679A was wrong — phantom token ServedContextSources (no such class) + over-matching SessionHost* were the cause; the 187/1083 figure above is the same roster with the phantom token removed, re-measured green on the lane tree.)
- Guards 'DuplicatedTestHelperDrift|StderrEmitterCensus|ReadPathCensus|GlobDialectDifferential|TreeWideGuardRoster': Tests: 158, Assertions: 26137.
- Protocol trio + new EventType arm (WITH regen, pre-restore): 'EventTypeTest|ProtocolSchemaDrift|ServerProtocolDocDrift' 16/481; drift pair alone 12/340.
- Vitest: composerSuggestion+contextChanged 9/9; adjacent 116/116 (reducer/contextSources/session/trustRouting/composerHistory/Dictation/DropAndChips/FilesImages/components specs); protocol specs 31/31; contextPanel+dormantRoster 22/22 — all --no-file-parallelism.

Red-before: PHP mutations M1 settle-announce 3F, M2 command-guard 1F, M3 suggestion-arm hang-timeout (drain 60s guard), M4 stale-check 1F, M5 billing 2F. Web: W1 reducer-case 3F, W2 turn.started-clear 1F, W3 context.changed-arm 2F, W4 accept-arm 2F, W5 placeholder 1F; all cp-restored cmp-clean. ServedPromptSuggestionTest is new → fails-by-absence on base (announce sites absent there).

Deviations / disclosures:
1. Regen trio (schema JSON, SERVER.md, generated.ts) restored pre-commit per §1.8; ProtocolSchemaDriftTest/ServerProtocolDocumentationDriftTest/generated.spec/dormantRoster judged on welded tree (verified green locally WITH regen).
2. applyCommandResult gained default-'' $commandName param (internal, no wire change); call sites runCommand/command() pass the name.
3. ContextMethods::instructionWrite {reason: instructions} arm = C-CTX-3.c by design (method not yet present); event+schema minted here.
4. typedCompact {reason:'compaction'} announce lands on the real-fold path only; pinned by EventTypeTest presence + code, not behavior-pinned (compactor heuristic fold too heavy for the fixture) — disclosed.
5. npm run typecheck: 19 pre-existing errors at base (5 unchanged spec files + contextSources.ts lines byte-identical to base, shifted); zero in my files/regions — verified by git diff vs e4a705244. (Weld disclosure per r1: on the committed pre-restore tree typecheck prints 23 lines = the 19 pre-existing EXACT + 4 new TS2344/TS18046 from referencing Ev<'prompt.suggested'> against the §1.8-restored generated.ts; the regen rider self-heals all 4 — post-regen vue-tsc is back to the 19-error standing baseline, proven at weld.)
6. Vue-reactivity bug caught by own specs: clear-then-read emitted null; offer captured before retiring.
7. Sibling tests/Host/SessionHostTurnSettleWorkTest.php docblock truth-flipped in-step (suggestion now answered via DISABLE_ENV, lane moved to ServedPromptSuggestionTest).
</content>
