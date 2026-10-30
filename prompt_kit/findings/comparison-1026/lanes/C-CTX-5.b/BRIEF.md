# C-CTX-5.b — Ahead-of-need skip rules (W24, effort S)

Design block (verbatim from `design-prv-ctx.md:1082`):

- item: C-CTX-5 (sources: OC-new#7 (skip-rule parity))
- kind: competitor-feature · effort S
- approach:
  - The 70% tier prefetches summaries on both hosts: Chat at `Chat.php:10917-10935`, SessionHost at `SessionHost.php:1731-1749`.
  - Move the request decision into `CompactionService::aheadOfNeedAllowed()` with OpenCode's skip rules:
    - skip when the history is under a token floor (a named constant, 64k);
    - drop a held summary when the session's model changed since it was asked for, by adding the model id to the fingerprint.
  - Both call sites use it.
- files.create: `candy-code/tests/Host/AheadOfNeedSkipRulesTest.php`
- files.modify: `candy-code/src/Host/CompactionService.php`, `candy-code/src/Chat.php` (`dispatchTurn()` ahead-of-need block), `candy-code/src/Host/SessionHost.php` (`dispatch()` ahead-of-need block)
- tests: AheadOfNeedSkipRulesTest (under the floor → no request on either host; a model switch discards the held summary); modify `candy-code/tests/Host/SessionHostAheadOfNeedTest.php`
- docs/guards: `candy-code/docs/CONTEXT.md` (one sentence)
- serialized: [chat:dispatchTurn (ahead-of-need block)] [docs:CONTEXT.md] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Host/CompactionService.php]
- deps: none
- phase: normal
- ruling: none
- risks: a small-window model where 70% < 64k never prefetches, by design. It still folds at 85%, just not pre-fetched.

## Base

Cut off master `a6c45be43`. Branch `cc/C-CTX-5.b`. Worktree `/home/sites/cc-cmp-C-CTX-5.b`.
`candy-code/vendor` is cp-seeded linked (composer is broken host-wide — do NOT run composer/`refresh-deps`; autoload verified OK).

## Lead notes

- Line cites drift; re-derive both call sites live before editing.
- Keep every edit inside the declared serialized regions. `Chat.php` is shared with C-SES-7.d, but that lane's region is `resumeCostHold`/ctor tail — the `dispatchTurn()` ahead-of-need block is disjoint. Do not wander.
- The 64k floor is intentionally a named constant, and the small-window-model consequence (never prefetches, still folds at 85%) is by design — pin it in a test rather than "fixing" it.
- Fingerprint change: the model id joins the held-summary fingerprint so a model switch invalidates a held prefetch. Confirm what the fingerprint currently covers and keep its existing semantics.
- No new docs tables; `CONTEXT.md` takes one sentence.
