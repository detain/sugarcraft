# C-TUI-3.f review r1 — Recap core (RecapHostCommand)

VERDICT: FINDINGS — 0C / 0M / 1 step-local MINOR / 2 NIT. One-sentence docblock cure → weld-ready.

## Scope and tree
- `git show --stat 033ba8947`: exactly 4 files — create `src/Host/Commands/RecapHostCommand.php` + `tests/Host/Commands/RecapHostCommandTest.php`, modify `src/Host/Commands/BtwHostCommand.php` + `lang/en.php`. Matches BRIEF files.create/modify/tests exactly. No weld-only files (no suite-figure/durations/dist). Author/committer detain (`%ae|%ce` verified), zero trailers, ancestry real (parent 878047472 = C-CTX-5.c), tree clean.

## BtwHostCommand widening — IN-BRIEF, not a deviation
- Brief's files.modify names BtwHostCommand.php ("`call()`/`request()` take the framing prompt"). The extra trailing params ($failureKey/$headingKey/$noAnswerKey) are the disclosed minimum for the recap voice on the same seam. All defaults byte-preserve /btw (trailing-positional PHP BC; no caller re-keyed: only BtwHostCommand::run and RecapHostCommand call request/call; Chat fold answerRow stays default-voiced). /btw pins green: `tests/Chat/BtwCommandTest` 10T/48A OK.

## Gates re-derived at tip (plain pipe, cwd candy-code)
- RecapHostCommandTest 4T/44A OK ✅ (claim exact). Combined Recap|Btw 14T OK ✅.
- TreeWideGuardRoster+StderrEmitterCensus 112T/25,061A green ✅; NoRawAnsiInTranscript+GlobDialect within combined 135T/25,452A green ✅.
- HostErrorRowFlagTest 31T/1177A green — REPORT claims "33 incl provider": figure imprecision, NIT.
- Disclosed base reds all reproduce at tip and are proven NOT-lane: ChatHostLangCatalogue 5 stranded `chat.*` keys each exist at parent `git show 878047472:candy-code/lang/en.php`; DuplicatedTestHelperDrift red names CacheTtlIdleFoldTest vs ModelMetadataTest (minted by base C-CTX-5.c); ProjectTierRefusalInventory 2F names Marketplace/LspInstaller dot-path census — no lane file in any message.

## Vacuity / correctness
- No-backend door pins value-reads, not shapes: `assertTrue($result->holdsTurn)` + `assertSame([], $result->effects)` + refusal `error` flag true → strip-holdingTurn / add-effect / reply-not-errorReply mutations each red deterministically. Row voice pins `assertSame(RecapHostCommand::FRAMING, asked[0][0]->content)` ("recap not btw") and `assertSame("**recap** — …")` content → framing-strip and default-mapper mutations red; builder's M1 claim consistent. Sync-door double throws LogicException (no silent sync fallback). Ceiling test ties to shared BtwHostCommand::ANSWER_MAX_CHARS (no recap copy).
- Mutations not physically re-run: reviewer permission map denies cp/sed/edit into the worktree (5 denials); discriminating-by-construction inference above substitutes; builder's cp/md5-clean M1 attested.
- E646: zero timers in the diff; the ask carries [system FRAMING, user envelope] (transcript + QUESTION + `<IMPORTANT>` "do not call any tool" block — pinned at test :88) over completeAsync, same as /btw. No new Msg class, no Chat arm, no TitleService touch, /recap NOT registered anywhere (grep: only docblock cross-references) — 3.g owns the row. §1.9: fixture literals space-bearing (not glob-shaped), no Refused-shaped tokens. Lang keys used, alphabetical (host.permissions < recap < recompress; answer_heading, failed, needs_title_model, no_answer). PSR-12/strict_types/final/one-type-per-file ✅.

## Findings
- MINOR (step-local) — `src/Host/Commands/RecapHostCommand.php:16-19` docblock says the existing fold "already lands" the answer — true, but `Chat.php:12010` applies Async effects as `Cmd::promise($effect->run())`, DROPPING the describe mapper, so once 3.g registers /recap the TUI lands recap rows at `HandlesTurnCompletion.php:377` with the DEFAULT **btw** heading (host path correct via rowMessage). Exact fix: qualify the sentence — e.g. "on the headless host the row rides this class' mapper; the Chat fold lands it in the seam's default btw voice — a recap-voiced TUI row is a C-TUI-3.g seam" — and carry that seam note into 3.g's brief.
- NIT — REPORT HostErrorRowFlag figure 33→measured 31.
- NIT — `host.recap.failed` failureKey wiring has no behavior pin (disclosed; mirrors BtwCommandTest's own coverage gap).

## Welder carries
- durations.tsv row for `candy-code/tests/Host/Commands/RecapHostCommandTest.php` + figure +4T carry (disclosed by lane).
- Optional roster add `host.recap.needs_title_model` to HostErrorRowFlagTest::failureKeyRoster (one-directional, green either way).
- C-TUI-3.g: register `/recap` (registry + docs row) AND carry the TUI-fold-voice seam above.
- Base-red ledger for the gate: ChatHostLangCatalogue 5 stranded chat.* keys; CacheTtlIdleFoldTest/ModelMetadataTest const pair; ProjectTierRefusalInventory 2F — foreign-origin at 878047472, not this lane.
