# C-SEC-8.d — Strip invisible Unicode at submit (TUI and serve) — LANDED

## What shipped
- `src/Chat.php`: the old `submit()` body became `private submitDraft(string $text)`; the
  `submit()` wrapper now expands chips + trims, runs `Sanitize::stripInvisibleFormatting()`
  (the C-SEC-8.c API, sugar-core `src/Util/Sanitize.php:406`, used as-is — not forked),
  and when anything was removed posts ONE `Message::notice(Lang::t('chat.notice.invisible_stripped'))`
  into history, then re-enters the funnel with the STRIPPED bytes so every route
  (dispatch / queue / steer / command classification) sees the same text the model gets.
  An all-invisible draft is consumed with the notice as the whole answer (no user row, no turn).
  Zero invisible characters => byte-identical fast path (`removed === 0` returns before any mutate).
- `src/Host/SessionHost.php`: same choke inside `submit()`, mirroring the idle-advisory block
  pattern: strip, append the same notice row, `save()`, `announceTranscriptRow($notice)` so served
  subscribers see it, continue with stripped text; all-invisible => `TurnTicket::refused` with the
  empty-prompt notice + idempotency key. Attachments never touched.
- `lang/en.php`: one key `chat.notice.invisible_stripped`, inserted SORTED between
  `chat.model.usage` and `chat.notify.awaiting_approval`. candy-code ships en.php only (no locale
  siblings); LangParityTest + LangLaunchLocaleTest green.
- In-step doc cure: `docs/HOOKS.md:346` "reached from `submit()`" -> `submitDraft()` (the hook
  dispatch site moved into the renamed funnel; the DocFigure arm derives the method name live,
  so guard + doc row moved in THIS commit per §1.8). Stale test comment in
  `tests/Config/DocFigureProseDriftTest.php` truth-fixed in the same commit.

## Semantics (pinned by tests, from the 8.c API — disclosed: design text matched the API)
- Always tier (U+200B/202A-202E/2060/2066-2069/FEFF) removed everywhere.
- Joiner tier (U+061C/200C/200D/200E/200F) removed only after ASCII/start — ZWJ emoji families
  (👩‍💻) and Persian ZWNJ survive; `pay\u{200D}pal` spoof goes; trailing RLO goes.
- No opt-out key (design ruling honored: off settings/README rosters).

## Tests (NEW files — closeout owes durations.tsv rows)
- `tests/Chat/SubmitInvisibleUnicodeTest.php` — 7 tests (~2.1s): bidi+ZWS strip w/ notice count 2;
  plain prompt byte-identical no notice; ZWJ family kept; ZWNJ kept; paypal spoof -> 'paypal' +1;
  all-invisible consumed (no user row, inFlight false); mid-turn queue carries stripped bytes
  (notice needle filtered — the enqueue arm already emits its own queue notice).
- `tests/Host/SubmitInvisibleUnicodeTest.php` — 5 tests (~1.9s): served strip + announced notice;
  byte-identical plain; family survives; all-invisible -> ticket refused, no user row;
  attachments byte-dirty-untouched while prompt stripped.
- Both-file run: OK 12 tests / 39 assertions / 3.99s. `withDraft` helper byte-IDENTICAL to
  InFlightInputQueueTest's twin (guard accuses one-token divergence, not exact copies).

## Gates (cwd=candy-code, -c phpunit.xml, plain pipe)
- My 2 files + DocFigure + DuplicatedDocBlockLine + ServedRuntimeNoticeDrain: OK 114T/4243A/14.8s.
- tests/Chat + tests/Host + tests/Protocol dirs: 2144T/16719A/7F — ALL 7 proven foreign-inherited:
  ApplySettings x2 ('autoReviewScope'), CompactionBreaker, ColdResume, PaletteSuperset (disclosed
  flake family), RouteTraitParity (RuntimeNoticePumpMsg roster) all RED identically at BASE via
  file-copy harness (68T/834A/6F WITHOUT my files, restored cmp-identical); LangCatalogue 7 unused
  keys are foreign askers outside its source set (97113b210, 36cc39ad1 et al.), none mine.
- Lang/census batch: LangParity + LangLaunchLocale + StderrEmitterCensus green (118T/25896A, 1F =
  the foreign catalogue red above).
- ReadmeSuiteFigureDrift: standing staleness pair (25537 pinned vs 28699 live — widened by my +12T
  + foreign lanes; closeout re-pins).
- DuplicatedTestHelperDrift: standing 1F (CopySlashCommand/CopyCommand OPEN/CLOSE pair); grep of
  output: 0 occurrences of my files — added nothing to its failure set.
- GlobDialect + ChildWallClock + SwallowingCatch: green (in 122T/17224A batch).

## Census/roster flips
- None needed: zero sprintf/error_log sites added (notice rides history/announceTranscriptRow);
  ChatHostLangCatalogue literal+params satisfied in-step; no new test-file glob shapes.

## Disclosures
- Design's `Chat.php:9389` cite had drifted — current wrapper at :9876 (positions re-derived).
- Mid-turn queued prompts DO carry the notice (one per submission that strips); test pins it.
- Two new test files will widen the standing ReadmeSuiteFigureDrift pair until closeout re-pin.
- Foreign untracked `prompt_kit/findings/comparison-1026/plan_comparison.md` left unstaged.
