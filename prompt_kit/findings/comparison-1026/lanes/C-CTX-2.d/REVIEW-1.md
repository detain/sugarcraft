# REVIEW-1 — lane C-CTX-2.d (served prompt-suggestion surface: protocol + SessionHost + web wiring)

Reviewer: fresh read-only, 2026-10-28. Tree: /home/sites/cc-cmp-C-CTX-2.d @ 566456295 (base e4a705244; master 271ad0619 has since moved +10 via sibling welds — merge-base identity holds, normal rolling wave). Worktree left byte-clean (porcelain 0 after review; no regen was executed — see protocol-honesty note).

## Verdict

VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 3 MINOR (all step-local) / 3 NIT. Ship-ready; weld owes the trio regen + the one durations.tsv row.

## Duties ledger (all PASS)

- Phantom-first: branch cc/C-CTX-2.d; master..HEAD == 1; porcelain 0; %ae and %ce SEPARATE greps both detain@interserver.net; zero Co-Authored/other trailers. Diff == 12 files (836+/10−), = declared set + 1 disclosed undeclared (below). Generated trio (schema JSON / SERVER.md / generated.ts) NOT committed — §1.8 honored. No dist/, suite-figure, durations.
- §2 discipline: hot SessionHost.php/Composer.vue/contextSources.ts edits all inside this lane's feature regions; Chat.php and Bootstrap.php untouched (diff confirms). applyCommandResult default-'' $commandName param is internal, deviation-disclosed, both call sites pass the name; refused results never reach it.
- Protocol-surface honesty: map refused `gen-protocol-schema --write/--check`, so proven by inverse — ProtocolSchemaDriftTest on the restored tree fails at :39 with a diff containing EXACTLY the two new schema objects (prompt.suggested full row + context.changed required-reason leg) and nothing else → the committed source regenerates precisely the advertised schema. ServerProtocolDocumentationDriftTest passes unrestored (polices hand-written SERVER.md figures only; event tables are the generated marked pair — regen at weld adds the two rows, no lane file owed).
- PHP correctness (hand-traced): landPromptSuggestion bills FIRST (ledger()->account titleBackend — OBS-3 parity with landAutoCommit), released-guard, sanitizeSuggestion, staleness triple (generation|historyCount|sessionId) is verbatim Chat HandlesTurnCompletion parity, never persists; settle announce sits after the generation gate (cancelled turn: zero context.changed); typedCompact announce skipped by the safeguard-hold early return; roster ['rules','memory','init'] name-keyed per design. E646 clean (60s bound is test-side drain guard only). Env suppression lives in TitleService::suggestionsEnabled — correct single home.
- Tests real, non-vacuous: ServedPromptSuggestionTest 4T (announce shape + durable-log absence + stale-billed-not-announced + env-off + roster bidirectional); EventTypeTest arm fails-by-absence at base. M1-M5 re-reasoned from code (map denies cp): each mutation strips a leg a specific assertion scores (settle-announce 3F, roster-guard 1F, arm-removal hang→drain-guard, stale-check 1F, billing-order 2F). setUp follows lane-cf snapshot-first.
- Gates reproduced (plain-pipe, env -u LINES -u COLUMNS, cwd candy-code): guards filter 158T/26137A exit0 EXACT; ServedPromptSuggestionTest 4T/48A; protocol trio-on-restored-tree 16T/480A/1F (= builder 16/481 minus the red leg's short-circuit; drift-pair-alone 12T consistent). php -l 5 files clean.
- Web reproduced (--no-file-parallelism serial): new specs 9/9; adjacent 116/116 exact; protocol 31/31 (client 13 + generated 3 + protocol 15 — generated.spec green on the internally-consistent restored pair); contextPanel+dormantRoster 22/22.
- durations duty: ServedPromptSuggestionTest is the only new candy-code test file → exactly one tsv row at weld; web specs are vitest, correctly absent.

## Findings

MINOR-1 (step-local, report honesty) — REPORT.md:13 "PHP family 99T/679A" does not reproduce: the exact filter string runs 187T/1083A green at tip; it carries a phantom token 'ServedContextSources' (no such class tree-wide) and 'SessionHost*' over-matches. State is real; figure is wrong.
MINOR-2 (step-local, discipline) — tests/Host/SessionHostTurnSettleWorkTest.php 3-line docblock flip is outside the brief's declared set (disclosed deviation 7; comment-only; the sibling env-clear it describes pre-existed at base — safe, no concurrent claimant, accepted).
MINOR-3 (step-local, report honesty) — deviation 5 claims typecheck "zero in my files/regions": the committed tree actually reports 23 errors = the 19 pre-existing EXACTLY reproduced (5 unchanged specs + contextSources :176/:187 byte-identical shifted out of the lane hunks) + 4 NEW in src-web/stores/reducer.ts(430,50) TS2344 and (431,18/44/86) TS18046 — the Ev<'prompt.suggested'> reference against the intentionally-restored generated.ts. Self-heals at the weld regen (verified the mechanism; the builder's green-with-regen claim is credible), but the committed-state number should have been disclosed as 23 with those 4 lines named.
NIT-1 — design says web clears the offer "on typing"; implementation only hides the ghost (store clears on turn.started/snapshot) — deliberate TUI parity (HandlesKeys gates accept on empty inputBuf, keeps the offer until generation change); no spec pins the design sentence.
NIT-2 — context.changed{settle} + existing turn.completed arm = two refetches per settle (immediate + debounced); harmless (generation latch), a follow-up could fold the turn.completed arm into scheduleReload.
NIT-3 — /memory read-forms announce too (name-keyed per design; consumers are advisory reloads).

Weld checklist: regen trio (gen-protocol-schema --write + npm run gen:protocol) → drift pair + generated.spec + typecheck 19-green; durations.tsv row for tests/Host/ServedPromptSuggestionTest.php; SERVER.md event-table rows arrive via regen.
