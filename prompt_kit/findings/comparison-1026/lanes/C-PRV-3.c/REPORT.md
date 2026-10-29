# C-PRV-3.c — ultrathink keyword (lane report)

VERDICT: DONE, ready to weld. Real gate runs green; two pre-existing base reds disclosed (not caused by this step).

SHIPPED (7 paths, one commit):
- NEW src/Providers/EffortKeyword.php — pure detector: whole-word `ultrathink`, case-insensitive, outside fenced blocks (``` / ~~~, fence-state walk; unclosed opener swallows the rest — conservative edge) and outside inline backtick spans (run-matched; unpaired opener stays literal).
- src/Host/TurnRunner.php start() region ONLY: latest typed User row (role User, !uiOnly, userVisible, stepId null) → if EffortGate::verdict() → $backend->withReasoningEffort(top of EffortBudget::levels()) on the clone the thunk captures (mirror of C-PRV-1.a/5.a derivations; no settings write; continuations keep it because EngineBackend's step loop reads $this->reasoningEffort per request and re-dispatch re-finds the same typed row). Non-shaper → $notices?->record(Lang chat.effort.ultrathink_none) ONLY when the keyword row is last in history (once per turn; E199: null sink keeps dropping). 6 new `use` lines.
- lang/en.php: +1 key chat.effort.ultrathink_none, alphabetical insert (candy-code ships en only).
- docs/SETTINGS.md: hand-prose paragraph appended beside the reasoningEffort paragraph (top-tier one dispatch, continuations, non-shaper notice, quoted-word caveat).
- src/Util/PathGlob.php: doc-block census flip 309,132 (558×554) → 310,245 (559×555). §1.8 in-step guard flip: GlobDialectDifferentialTest was RED at base (proven with lane files removed); the guard's own message names this exact cure; my files add zero corpus literals.
- NEW tests/Host/UltrathinkTurnTest.php 17T/30A: top-tier + no-persistence + next-turn-reset + continuation-keeps-tier + non-shaper ONE notice ("ultrathink: scripted/m ...") + quoted-word moves nothing + 12-row detector table (backticked word ignored, unpaired-fence edge). Mutations: M1 start-block neuter → 3F; M2 inline-span neuter → 3F; M3 once-guard removal → exactly 1F; all cp+md5 restored.

GATES (plain pipe, cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS):
- own file 17T/30A OK; TurnRunner|EffortGate|EffortBudget|ThinkingCeiling 70T green; DocFigure/TrustKey/EnvRoster/ConfigWriteProducer/StderrEmitterCensus/LangParity/RuntimeNotice families 313T/75,921A green; GlobDialect 13T green post-flip.

WELDER CARRIES: DUR row tests/Host/UltrathinkTurnTest.php (+17T); suite-figure/README re-pin (ReadmeSuiteFigureDrift 2F = foreign-inherited staleness pair, red identically at base with lane files removed). PRE-EXISTING BASE RED left alone: ChatHostLangCatalogueTest orphan keys (chat.startup.personal, chat.attach.pdf_pages, chat.effort.launch_unshaped, chat.permission.not_persisted/persisted, chat.serviceTier.launch_unshaped) — none mine (mine is asked-for by TurnRunner; verified red-list identical with lane files hidden); needs the owning lanes' decision, reported per law.
