# SugarCraft Monorepo Audit — Missing/Improvement Findings

Compiled from a 59-agent parallel audit (58 libraries + docs/website) covering:
missing/incomplete functionality, performance concerns, test coverage gaps,
missing VHS demos, security concerns, cross-lib duplication, and documentation gaps.

## Table of Contents

- [candy-ansi](#candy-ansi)
- [candy-async](#candy-async)
- [candy-buffer](#candy-buffer)
- [candy-core](#candy-core)
- [candy-files](#candy-files)
- [candy-flip](#candy-flip)
- [candy-focus](#candy-focus)
- [candy-forms](#candy-forms)
- [candy-freeze](#candy-freeze)
- [candy-fuzzy](#candy-fuzzy)
- [candy-hermit](#candy-hermit)
- [candy-input](#candy-input)
- [candy-kit](#candy-kit)
- [candy-layout](#candy-layout)
- [candy-lister](#candy-lister)
- [candy-log](#candy-log)
- [candy-metrics](#candy-metrics)
- [candy-mines](#candy-mines)
- [candy-mold](#candy-mold)
- [candy-mosaic](#candy-mosaic)
- [candy-mouse](#candy-mouse)
- [candy-palette](#candy-palette)
- [candy-pty](#candy-pty)
- [candy-query](#candy-query)
- [candy-serve](#candy-serve)
- [candy-shell](#candy-shell)
- [candy-shine](#candy-shine)
- [candy-sprinkles](#candy-sprinkles)
- [candy-testing](#candy-testing)
- [candy-tetris](#candy-tetris)
- [candy-vcr](#candy-vcr)
- [candy-vt](#candy-vt)
- [candy-wish](#candy-wish)
- [candy-zone](#candy-zone)
- [docs-website](#docs-website)
- [honey-bounce](#honey-bounce)
- [honey-flap](#honey-flap)
- [sugar-bits](#sugar-bits)
- [sugar-boxer](#sugar-boxer)
- [sugar-calendar](#sugar-calendar)
- [sugar-charts](#sugar-charts)
- [sugar-crumbs](#sugar-crumbs)
- [sugar-crush](#sugar-crush)
- [sugar-dash](#sugar-dash)
- [sugar-gallery](#sugar-gallery)
- [sugar-glow](#sugar-glow)
- [sugar-post](#sugar-post)
- [sugar-prompt](#sugar-prompt)
- [sugar-readline](#sugar-readline)
- [sugar-reel](#sugar-reel)
- [sugar-skate](#sugar-skate)
- [sugar-spark](#sugar-spark)
- [sugar-stash](#sugar-stash)
- [sugar-stickers](#sugar-stickers)
- [sugar-table](#sugar-table)
- [sugar-tick](#sugar-tick)
- [sugar-toast](#sugar-toast)
- [sugar-veil](#sugar-veil)
- [sugar-wishlist](#sugar-wishlist)

---

## candy-ansi

Scope per README/MATCHUPS.md: `candy-ansi` is the decode-side ECMA-48/VT500 byte-stream
state machine extracted from `candy-vt` (upstream: `charmbracelet/x/ansi/parser`, mapped in
`docs/MATCHUPS.md:45` to the whole `charmbracelet/x/ansi` package). The encode-side helpers
(SGR/cursor/OSC string *builders*) already live in `candy-core/src/Util/Ansi.php`, so that
split is intentional, not a gap — findings below focus on the parser itself.

### 1. Missing or incomplete functionality vs upstream scope

- `src/Parser/HandlerAdapter.php:79-85` — `oscDispatch()` only regex-matches
  `^([0-2]);(.*)$` and forwards to `$this->osc->title()`. **OSC 8 (hyperlink) is never
  routed to `$this->osc->hyperlink()`**, even though `OscHandler::hyperlink()`
  (`src/Parser/OscHandler.php:20-28`) and `OscHandlerImpl::hyperlink()`
  (`src/Parser/OscHandlerImpl.php:23-25`) both exist. `hyperlink()` is effectively dead
  code from the adapter's perspective — nothing in the parser pipeline ever calls it.
  `OscHandlerImpl::hyperlink()` is also an explicit no-op ("hyperlink support deferred to
  v2", `OscHandlerImpl.php:10`), so even if wired up it stores nothing.
- `src/Parser/HandlerAdapter.php:50-72` — `csiDispatch()`'s `match($finalChar)` handles
  only cursor-move (`A/B/C/D/H/f`), `m` (SGR), `J/K` (erase), `r` (DECSTBM), `h/l`
  (DECSET/RST), `g` (TBC), `Z/I` (tab). It has no cases for scroll (`S`/`T`), insert/delete
  line (`L`/`M`), insert/delete char (`@`/`P`), repeat char (`b`), or SCO cursor save/restore
  (`s`/`u`) — several of which `CsiHandler` doesn't even declare as methods, so there's no
  seam to extend into without touching the interface.
- `src/Parser/CsiHandler.php` has no `cr()`/`lf()` methods. `candy-vt`'s own (forked) copy
  of this interface added both (see §6) because the C0 `execute()` path needs somewhere to
  send CR/LF — `candy-ansi`'s `HandlerAdapter::execute()` (`HandlerAdapter.php:33-41`)
  explicitly no-ops CR (`0x0D`) and doesn't handle LF/VT/FF at all.
- `CsiHandlerImpl` (`src/Parser/CsiHandlerImpl.php`) is a pure no-op stub by design (per its
  own doc-comment, terminal-state wiring is deferred to "step-12"). That step apparently
  never happened in `candy-ansi` itself — the real implementation was written directly in
  `candy-vt` instead (see §6), leaving `candy-ansi`'s own `CsiHandlerImpl`/`OscHandlerImpl`
  permanently inert.

### 2. Performance concerns

- `Transitions::build()` (`src/Parser/Transitions.php:50-239`) is lazily built once into a
  static 4096-byte string cached in `Transitions::$table` — good, no per-call regeneration.
- `Parser::feed()` (`Parser.php:53-59`) iterates byte-by-byte with `ord($bytes[$i])` in a
  PHP `for` loop; unavoidable for a byte-level state machine but worth flagging as the hot
  path for large paste/output streams (e.g. `sugar-spark`'s stream inspector). No batching
  or SIMD-ish shortcut for long runs of plain printable ASCII (Ground state, action=Print) —
  each printable byte still round-trips through `advance()` → `Transitions::get()` →
  `perform()` → `handler->printChar(chr($byte))` individually, one function call per byte.
  A fast-path that scans ahead for a run of `0x20-0x7E` bytes in Ground state and dispatches
  them as one `printChar()`-per-byte batch (or hands the whole run to the handler at once)
  would cut call overhead substantially for plain-text-heavy streams.
- No correctness issue, but `isValidUtf8Rune()` (`Parser.php:111-143`) is only invoked when
  `replaceMalformed` is true; when false (the default) invalid runes are just forwarded
  as-is (see §5).

### 3. Test coverage gaps

- OSC 8 hyperlink is fed through `ParserTest.php:323` (`$parser->feed("\x1b]8;;https://...")`)
  only at the `Parser`/`DebugHandler` level — there is no test that drives an OSC 8 sequence
  through `HandlerAdapter` and asserts `OscHandler::hyperlink()` gets called, because (per
  §1) the adapter never calls it. `HandlerAdapterTest.php` has no `testOscDispatchHyperlink*`
  case at all — the gap in coverage tracks the gap in behavior.
- `CsiHandlerImplTest.php` (17 tests) is exclusively `assertTrue(true)` "no-op doesn't throw"
  assertions (e.g. `testPrintableIsNoOp`, `testCuuIsNoOp`, lines 19-40+) — these test that
  the stub exists, not that it does anything meaningful (there's nothing meaningful to test,
  by design, but it does mean the coverage numbers overstate real assurance for this class).
- No test exercises `Parser::parseComplete()` end-of-stream flush combined with
  `replaceMalformed = true` for a *DCS* string left unterminated (only OSC/UTF-8 paths are
  covered around `flush()`); `flush()` (`Parser.php:79-95`) explicitly special-cases
  `DcsString`/`SosString`/`PmString`/`ApcString` alongside `OscString`, but
  `ParserOverflowTest.php`/`ParserTest.php` don't appear to assert flush behavior for the
  DCS/SOS/PM/APC branches specifically (only OSC is directly exercised for flush-on-EOF).
- `Transitions::action()`/`Transitions::nextState()` unpacking helpers (`Transitions.php:40-48`)
  aren't unit-tested in isolation (only indirectly via `Parser` behavior) — a direct
  bit-packing round-trip test (`pack` → `get` → `action`/`nextState`) would pin the encoding
  contract described in the class doc-comment ("action << 4 | nextState").

### 4. Missing .vhs/*.tape demo files

- No `.vhs/` directory exists in `candy-ansi/` at all, and `examples/debug.php` just
  `print_r()`s an action log (no ANSI rendering to a terminal, no visual output). This is
  consistent with AGENTS.md's carve-out for "non-visual libs" (FFI/codec-style parsers) —
  `candy-ansi` is a byte-stream parser with no `view()`/rendering surface, so a VHS tape
  would have nothing visual to capture. No action needed here, but worth confirming this
  lib should stay on the `vhs.yml` exemption list alongside `candy-pty`/`candy-testing`.

### 5. Security concerns

- `Parser::MAX_STRING_BUFFER = 65536` (`Parser.php:35`) caps OSC/DCS/SOS/PM/APC payload
  accumulation — good, bounds memory for a malicious/runaway string sequence. Note
  `candy-vt`'s forked copy uses a 1 MiB cap (`MAX_STRING_BYTES = 1_048_576`,
  `candy-vt/src/Parser/Parser.php`) — a 16x looser bound for the same attack surface, with
  no comment explaining why the consuming lib needs 16x more headroom. Worth reconciling
  once these are unified.
- `Parser::param()` (`Parser.php:236-264`) clamps each parameter to `MAX_PARAM_VALUE = 65535`
  and caps the parameter *count* at `MAX_PARAMS = 32` — both guard against
  integer-overflow / unbounded-array attacks from a crafted CSI sequence. Well covered by
  `ParserOverflowTest.php`.
- UTF-8 handling: when `replaceMalformed = false` (the constructor default,
  `Parser.php:45`), invalid/malformed multi-byte sequences are **silently dropped**
  (`Parser.php:170-176`, confirmed by `Utf8PolicyTest.php:14-26`) rather than replaced or
  rejected. This is a reasonable default for a permissive terminal emulator, but any
  caller feeding untrusted external byte streams into a `Handler` that eventually reaches
  real terminal output should consider defaulting `replaceMalformed = true` (U+FFFD
  substitution) — silently dropping bytes can, in the worst case, splice unrelated
  adjacent bytes together in a way that changes downstream interpretation. Not exploitable
  within this parser itself, but a footgun for consumers that don't know the default.
- No sanitization concerns found in the OSC/DCS payload path itself — `candy-ansi` treats
  `stringBuffer` as opaque bytes and hands it to the `Handler` unmodified, which is correct
  for a decoder (sanitization for re-emission is the encoder's job, already handled by
  `candy-core/src/Util/Ansi.php`'s `stripOscControlBytes()`).

### 6. Cross-lib duplication (functionality that belongs elsewhere or is duplicated)

This is the most actionable finding. `candy-vt/src/Parser/` still carries a **forked copy**
of `Parser.php`, `CsiHandler.php`, and `CsiHandlerImpl.php` (confirmed via `diff` — not
symlinks, not `use`s of the `candy-ansi` classes; `candy-vt`'s namespace is
`SugarCraft\Vt\Parser`, distinct from `SugarCraft\Ansi\Parser`). `candy-ansi`'s own
README (`candy-ansi/README.md:16-17`) and `CALIBER_LEARNINGS.md` entry
"2026-05-28 — step-01" both call this out as expected, pending a "step-12" migration that
apparently hasn't landed yet despite `candy-vt`'s `composer.json` already declaring a
path-repo on `../candy-ansi` (`candy-vt/composer.json:97`) — the dependency is wired but
unused.

The two copies have **materially diverged**, and the divergence includes a real
regression:

- **`candy-vt`'s `start()` still has the bug `candy-ansi` fixed.** `candy-ansi`'s
  `CALIBER_LEARNINGS.md` entry "2026-05-30 — step-20" documents removing
  `$this->stringBuffer = '';` from `start()` because it discarded the lead-through byte of
  DCS/OSC/SOS/PM sequences before the handler could see it, and credits the fix with
  fixing `sugar-spark`, `candy-hermit`, and `candy-freeze` consumers. `candy-vt`'s forked
  `start()` (`candy-vt/src/Parser/Parser.php:198-201`) **still contains
  `$this->stringBuffer = '';`** — the exact bug candy-ansi fixed is live in candy-vt today.
  Since `candy-vt` is the terminal emulator backing the whole rendering pipeline, this is a
  real correctness bug worth a regression test + fix in `candy-vt` directly, or (better)
  finally completing the "step-12" migration so `candy-vt` consumes `candy-ansi::Parser`
  instead of its own copy.
- Other divergences in the fork: `candy-vt`'s `Parser` has no `parseComplete()`, no
  `replaceMalformed`/`isValidUtf8Rune()` UTF-8 validation, a 1 MiB string-buffer cap vs
  candy-ansi's 64 KiB, and a differently-structured (but behaviorally similar) `param()`
  overflow-clamp implementation.
- `candy-vt`'s `CsiHandler` interface adds `cr()`/`lf()` (`candy-vt/src/Parser/CsiHandler.php`)
  that `candy-ansi`'s interface lacks, and its `CsiHandlerImpl` is a full ~280-line real
  implementation (cursor movement, SGR color/attr tracking, erase, scroll regions, DECSET
  cursor visibility) wired to `CellGrid`/`Cursor`/`Theme` — i.e. exactly the "step-12" work
  the candy-ansi README describes as pending, done instead as a local fork rather than as
  an upstream contribution back into `candy-ansi`. Any future bugfix to CSI/SGR handling
  logic now has to be applied twice (once here, once in candy-vt) unless someone reconciles
  them.
- Consumers already depending on `candy-ansi` directly (not the fork) — `sugar-spark`
  (`AnsiHandler.php`, `StreamingInspector.php`), `candy-freeze` (`AnsiParser.php`),
  `candy-hermit` (`Hermit.php`), `candy-pty` (`Output/AnsiOutputParser.php`,
  `Output/SgrHandler.php`) — got the `start()` fix for free when `candy-ansi` was patched.
  `candy-vt` is the one straggler still running the pre-fix logic.

### 7. Documentation gaps

- README status line says "🟡 Initial port" (`README.md:13`) but `docs/MATCHUPS.md:45`
  marks candy-ansi 🟢 — inconsistent status between the two source-of-truth files. Given
  the README's own text says "Consumer handlers (CsiHandler, OscHandler) remain in
  candy-vt until their cell-grid dependencies are refactored" (i.e. step-12 not done), 🟡
  in the README seems to be the more accurate one; MATCHUPS.md's 🟢 looks stale/optimistic.
- README's Quickstart and Handler-interface code blocks are accurate against the current
  `Parser`/`Handler` API (verified by reading `Parser.php`/`Handler.php` directly) — no
  drift found there.
- `CALIBER_LEARNINGS.md` has only two entries (extraction + the `start()` buffer-clear fix)
  and doesn't mention the still-open "step-12" migration status, nor the fact that
  `candy-vt`'s fork has since re-introduced the exact bug the second entry fixed. Given
  CALIBER_LEARNINGS is meant to be "patterns and anti-patterns learned," the fact that the
  documented fix didn't propagate to the sibling fork is exactly the kind of thing worth a
  new dated entry (e.g. "the candy-ansi fix needs a matching patch in candy-vt until
  step-12 completes").
- No README section on the `MAX_STRING_BUFFER` / `MAX_PARAMS` / `MAX_PARAM_VALUE` security
  bounds (§5) — these are meaningful behavioral limits for any caller feeding untrusted
  input and aren't mentioned outside a code comment.

---

## candy-async

Scope: `AsyncOps` (withTimeout/retry/debounce/throttle), `Cancellable`/`CancellationSource`/`CancellationToken`, `Subscription`/`Subscriptions`, `Suspended`, `Lang`. 43 tests pass locally (`vendor/bin/phpunit`, 78 assertions, ~0.6s).

### 1. Missing or incomplete functionality vs upstream scope

- **No dedicated cancellation exception class.** `candy-async/src/AsyncOps.php:112,125` rejects retry-cancellation with a bare `new \RuntimeException('Retry cancelled')`. Meanwhile `candy-lister/src/FuzzyMatch.php:63` and `candy-lister/src/Model.php:333,521` document (in doc-comments) that operations throw `SugarCraft\Async\OperationCancelledException` — **this class does not exist anywhere in the repo**. Either candy-lister's docs are aspirational/stale, or candy-async is missing a class its own consumers expect. Recommend adding `OperationCancelledException extends \RuntimeException` (mirroring `TimeoutException`) and using it consistently in `retryAttempt()` instead of the generic `\RuntimeException`.
- **`debounce()`/`throttle()` have no disposal/cancel handle.** Unlike `CancellationSource`/`Subscription`, the closures returned by `AsyncOps::debounce()` (`src/AsyncOps.php:162-178`) and `AsyncOps::throttle()` (`src/AsyncOps.php:189-207`) give the caller no way to cancel a pending timer or reset cooldown state early. If the owning widget is torn down while a debounce timer is still pending, the timer fires later and invokes `$fn` against possibly-stale/destroyed state — there's no `CancellationToken` integration point. Upstream-style debounce/throttle utilities (Lodash, RxJS) typically expose `.cancel()`/`.flush()`. Consider returning a small value object (`DebouncedCallable`) with `cancel()`/`flush()` methods, or accepting a `CancellationToken` to auto-dispose.
- **No `Promise::all`/`race` combinator wrapper.** Not necessarily a gap since `react/promise` already exports `all()`/`race()`/`any()` free functions, but the README's "AsyncOps" section implies this lib is the async vocabulary hub — worth an explicit doc note (see Documentation section) that combinators are delegated to `react/promise` rather than re-implemented, so future contributors don't duplicate them.
- **`retry()` has no jitter option.** Exponential backoff is deterministic (`backoff * 2` each attempt, `src/AsyncOps.php:138`) with no random jitter parameter — fine for now, but thundering-herd risk if many retry() calls with the same base backoff are triggered simultaneously (e.g. many failed requests in `candy-query`).

### 2. Performance concerns

- **Dead statement in `retryAttempt()`.** `src/AsyncOps.php:115` has a bare `$operationPromise;` statement before the try block — a no-op reference to an as-yet-undefined variable, left over from a refactor. It doesn't error under PHP 8.3 (bare `$var;` statements as expression-statements aren't evaluated as a "use" that triggers the undefined-variable warning) and PHPUnit's `failOnWarning="true"` still passes, but it's confusing dead code that should be removed for clarity — a future refactor could accidentally give it semantic weight.
- **Retry backoff scheduling uses `\React\EventLoop\Loop::get()` directly** (`src/AsyncOps.php:134`) instead of accepting a `LoopInterface` parameter like `withTimeout()`, `debounce()`, and `throttle()` do. This is inconsistent with the class's own `debounce`/`throttle` signatures (which accept `?LoopInterface $loop = null`) and with `CALIBER_LEARNINGS.md`'s explicit rule "do not construct multiple loops... pass `Loop::get()` or accept a `LoopInterface` parameter." `retry()` can't be tested against an injected/mock loop, forcing all retry tests to use the real global loop.
- No batching/coalescing performance issues found in `Subscriptions::compose()`/`disposeAll()` — straightforward O(n) iteration, no churn.

### 3. Test coverage gaps

- **`retry()`'s loop-injection inconsistency isn't tested** — no test asserts `retry()` accepts (or should accept) a custom `LoopInterface`, unlike `debounce`/`throttle` which do get exercised with an explicit `$loop` argument in `tests/AsyncOpsTest.php`.
- **No test exercises `retry()` with a `CancellationToken`.** `AsyncOps::retry()` accepts an optional `?CancellationToken $token` (`src/AsyncOps.php:87`) and checks `$token->isCancelled()` both before the first attempt and before scheduling a retry (`src/AsyncOps.php:111,124`), but `tests/AsyncOpsTest.php` never passes a token — the cancellation-during-retry code path is completely untested (both the "already cancelled at entry" and "cancelled mid-backoff" branches).
- **No test for `debounce`/`throttle` argument passing beyond a single primitive.** Existing tests (`testDebounceOnlyLastCallFires`, `testThrottleLimitsCallFrequency`) pass single scalar args; no test verifies multiple/variadic args or object args survive the closure capture, and no test verifies throttle's *trailing* call semantics (does a call made during cooldown get dropped entirely, or queued for after cooldown? Current impl drops it silently — untested edge case worth asserting explicitly since it's surprising behavior).
- **`CancellationToken::fireCallbacks()` error-aggregation behavior is under-tested.** `src/CancellationToken.php:77-88` collects thrown exceptions from callbacks into `$errors` and only re-throws `$errors[0]`, silently swallowing any additional callback exceptions and still marking all callbacks as fired. No test in `tests/CancellationTokenTest.php` covers a callback that throws, whether subsequent callbacks still run, or that only the first exception surfaces.
- **Test file references a nonexistent method name.** `tests/CancellationTokenTest.php` docblocks (e.g. above `testCancellationSourceCancelSetsTokenState`, lines ~163-176, and `testTokenIsReadOnlyViaSourceOnly` around line 108) repeatedly reference `markCancelled()` as the internal token method, but the actual method in `src/CancellationToken.php:46` is named `acceptCancellationSource()`. This is stale documentation drift from a rename that wasn't fully propagated into the tests — harmless for correctness but misleading for future readers.
- **`Suspended` has no test for a `resume()` callable that itself throws**, nor for generic `T` state carrying non-array values (only tested with array state, `tests/SuspendedTest.php:29`).
- **`Subscriptions` has no test for exception-safety during `disposeAll()`** — if one inner `Subscription::unsubscribe()` throws, does it stop disposing the rest? `src/Subscriptions.php:85-91` has no guard, so an exception from one subscription would abort the loop and leave later subscriptions un-disposed; untested.

### 4. Missing .vhs demo files

- Not applicable. `candy-async` is a pure async/cancellation utility library with no CLI-visual surface — no `.vhs/` directory exists and none is expected (consistent with AGENTS.md's note that non-visual/primitive libs are VHS-exempt).

### 5. Security concerns

- **Unbounded retry with attacker-controlled `$operation`.** `retry()` schedules successive attempts via nested closures/timers with no upper bound on total elapsed wall-clock time — only `$attempts` bounds attempt *count*, but with exponential backoff and a large `$attempts`, total wait time grows geometrically unbounded (2^n). No max-total-duration guard exists (only `withTimeout()` gives an absolute deadline, and it's not composed with `retry()` by default). Consumers must remember to wrap externally; a helper like `retry()` accepting a `maxTotalSeconds` would reduce risk of resource exhaustion from long retry chains against a flaky backend.
- **No caps on `debounce`/`throttle` closures created.** Each call to `AsyncOps::debounce()`/`throttle()` creates fresh mutable closure state; if called in a hot loop (e.g., once per keystroke instead of memoized once per field), each call creates an independent timer/cooldown island with no shared registry — not a vulnerability per se, but nothing in the API or docs warns against re-calling `debounce()`/`throttle()` per-event instead of once-and-reuse, which is an easy misuse mode when integrating with per-render TUI code.

### 6. Duplicated/missing functionality vs other libs

- **`candy-wish/src/CancellationException.php`** defines its own single-purpose cancellation exception (`context cancelled`) independent of `candy-async`'s `Cancellable`/`CancellationToken` machinery, even though `candy-wish` is not currently a `candy-async` consumer per its composer.json path-repos check in this audit — worth a follow-up to see if `candy-wish`'s PTY-session cancellation could be unified with `candy-async`'s `CancellationSource`/`CancellationToken` pattern rather than maintaining a parallel one-off exception.
- **`candy-lister`'s doc-comments reference `SugarCraft\Async\OperationCancelledException`** (see section 1) which doesn't exist — this is the clearest cross-lib drift: either candy-lister needs updating to reflect what candy-async actually throws, or candy-async needs the class added. Given `candy-lister` is the consumer describing an *expected* contract, the more likely intent was to add `OperationCancelledException` to `candy-async` and have `retryAttempt()`/cancellation paths throw it.
- Confirmed no duplicate `debounce`/`throttle` implementations elsewhere in the monorepo (`grep` for `function debounce|function throttle` outside `candy-async` returned nothing) — good, no functionality split needed there.

### 7. Documentation gaps

- **README's AsyncOps section doesn't mention `CancellationToken` support in `retry()`.** The Quickstart (`README.md:29-34`) shows `retry()` without a `$token` argument, and the Architecture section's bullet list for AsyncOps (`README.md:75-82`) never mentions the optional `CancellationToken` parameter or cancellation-during-backoff semantics — a reader would not discover this capability without reading source.
- **No documented example for `Suspended`.** `Suspended` is exported from `src/Suspended.php` and has real semantics (paused Cmd/resume pattern tied into TEA `subscriptions()`), but it's completely absent from `README.md` — no mention in the Overview bullet list, Quickstart, or Architecture section, despite being one of the library's five top-level classes.
- **CALIBER_LEARNINGS.md is stale re: exception hierarchy.** It states "TimeoutException extends RuntimeException — not a dedicated subclass of any standard exception hierarchy. Catch via `AsyncOps\TimeoutException`" — the namespace cited is wrong; the actual class lives at `SugarCraft\Async\TimeoutException` (declared inside `src/AsyncOps.php:213`, same file, same namespace `SugarCraft\Async`), not an `AsyncOps\` sub-namespace. Minor but could mislead a consumer's `use` statement.
- **README doesn't document `Subscriptions::count()`/`isEmpty()`**, both public methods (`src/Subscriptions.php:70-78`) with dedicated tests, but absent from the README's Architecture/Subscriptions section which only shows `compose()`/`unsubscribe()`.

---

## candy-buffer

Cell-grid value objects (`Buffer`, `Cell`, `Position`, `Region`, `Style`, `Hyperlink`) plus a diff/encode pipeline (`src/Diff/*`). Deliberately minimal per `CALIBER_LEARNINGS.md` (2026-05-28): "rich styling logic belongs in candy-sprinkles, not here."

### 1. Missing or incomplete functionality vs upstream scope

- **No `resize()` on `Buffer`.** `candy-vt/src/Buffer/Buffer.php` (its own, separate Buffer) has a `resize(int $cols, int $rows): self` that preserves content and pads new cells. `candy-buffer/src/Buffer.php` has no equivalent — callers who need to grow/shrink a grid must build a whole new buffer manually via `copy()`/`withRegion()`. Given `Buffer` is supposed to be the shared foundation type, this is a reasonable gap to close (or explicitly document as out of scope).
- **No atomic "place wide cell" helper.** `Buffer::withCellAt(int $col, int $row, Cell $cell)` (`src/Buffer.php:109-117`) writes a single cell only. When `$cell->width() === 2` the caller is responsible for separately writing a `Cell::continuation()` at `$col+1` (README.md:53-57, `src/Cell.php:14-16`). There is no `withWideCellAt()`/`withRune()` convenience that writes both cells together, and `withCellAt` does **not** validate/enforce the invariant — it's easy to call it with a width-2 cell and forget the continuation cell, silently producing an inconsistent grid that `diff()`/`toAnsi()` will render incorrectly (extra visible glyph misalignment) with no exception raised.
- **No whole-buffer clear/blank.** `fill(Region $region, Cell $cell)` (`src/Buffer.php:169-191`) requires the caller to pass `$this->region()` to clear everything; there's no bare `clear()`/`blank()` convenience, unlike most Buffer-analogues in this ecosystem (ratatui `Buffer::reset`, ansi Screen `clear()`).
- **`Region` has no validation** for non-positive `width`/`height` (`src/Region.php:19-23`), unlike `Buffer::new()`/`Buffer::fromGrid()` which both throw on non-positive dimensions (`src/Buffer.php:34-38`, `61-65`). A negative-height region silently no-ops in `fill()`/`copy()`/`withRegion()` rather than raising — inconsistent with the "no silent failures" convention in `CONTRIBUTING.md`.

### 2. Performance concerns

- `diff()` (`src/Buffer.php:245-381`) is a single row/col walk with an inner run-scan that advances `$col` past the run it just consumed — this is amortized O(width×height), not O(n²); no concern here.
- Every `with*()` mutator (`withCellAt`, `withRegion`, `fill`) does `$grid = $this->grid;` then mutates one or more indices. PHP's copy-on-write means the initial assignment is O(1), but the first write to `$grid[...]` triggers a full O(n) array duplication. Calling `withCellAt()` N times in a loop (e.g. building up a row cell-by-cell) is therefore O(n²) overall — the same anti-pattern the class's own docblock on `fromGrid()` (`src/Buffer.php:46-51`) already documents and provides an escape hatch for (`fromGrid`). Worth calling out in the README's Quickstart (currently the Quickstart at README.md:32-41 chains three `withCellAt()` calls, which is exactly the pattern to avoid at scale) — a short "building buffers in bulk" note pointing at `fromGrid()` would help callers avoid the trap.
- `Buffer::mutate()` (`src/Buffer.php:578-584`) rebuilds an associative array via `array_merge()` on every call including `width`/`height` keys that never change for `withCellAt`/`withRegion`/`fill` — trivial overhead, not worth optimizing.

### 3. Test coverage gaps

- **`Buffer::fill()` has zero tests.** Grep of `tests/BufferTest.php` finds no `testFill*` method and no call to `->fill(` anywhere in the test file — a public, non-trivial method (loops + bounds clipping + negative-origin handling) is completely unexercised.
- **`Buffer::copy()` has zero tests.** Same story — no `testCopy*`, and the only occurrence of `copy` in the test file is an unrelated `array_fill` call (`tests/BufferTest.php:94`). `copy()`'s out-of-bounds branch (negative or overflowing source coordinates → blank `Cell::new()`, `src/Buffer.php:210-220`) is untested.
- **`Region` has no non-positive-dimension test.** `tests/RegionTest.php` covers `right()`/`bottom()`/`contains()` at normal and boundary values but never constructs a zero- or negative-width/height `Region` to check the (currently unvalidated, see §1) behaviour.
- **`Style` fg/bg out-of-range values are untested.** `SgrEmitter::emit()` (`src/Diff/SgrEmitter.php:36-48`) masks `$rgb` with `& 0xFF` per channel with no upper-bound check on the input int; a value like `0x1FFFFFFF` or a negative int silently wraps to some other color with no test asserting the wrap behaviour is intentional.
- **`DiffOp::TYPE_*` deprecated constants are untested** (`src/Diff/DiffOp.php:28-43`) — reasonable to skip since they're dead/deprecated, but worth flagging as effectively untested + unused dead code that could be removed instead of kept "for API compatibility" (no external consumers reference `DiffOp::TYPE_*`, per grep across the monorepo).
- Good coverage otherwise: `Buffer` diff/apply round-trip tests (`testRoundTripDiffApplyDiffIsIdentity`, `testRoundTripRandomPairsTwentyIterations`), wide-char continuation handling, byte-count regression tests (`testByteCountOneCharChangeIn80x24StaysUnder30`) are solid and exceed the "≥1 test per public method" bar for most of the class.

### 4. Missing .vhs/*.tape demo files

- `candy-buffer` has no `.vhs/` directory and no `examples/` directory. This looks correct rather than a gap: it's a pure data-model/value-object library (like `candy-ansi`, `candy-layout`, both also without `.vhs/`), not a visually-demoable widget. No action needed unless the maintainers want a `diff()`→`DiffEncoder` demo GIF to illustrate the "Diffing & delta ANSI" README section — `candy-sprinkles` and `candy-core` (which do have `.vhs/`) are closer analogues of "foundation lib with a visual demo," so this is a judgment call, not a clear gap.

### 5. Security concerns

- **`Cell::rune` has no control-character validation**, unlike `Hyperlink` which explicitly rejects `\x00-\x1f\x7f` in `url`/`id` to prevent "ANSI escape injection into the OSC 8 wire format" (`src/Hyperlink.php:18-28`, and tested in `tests/HyperlinkTest.php`). `Buffer::toAnsi()` (`src/Buffer.php:550`) and `DiffEncoder::encodeSetCells()` (`src/Diff/DiffEncoder.php:142`) both write `$cell->rune()` directly into the output byte stream with no filtering. If a `Cell`'s rune is built from untrusted input (e.g. echoing remote/user-supplied text into a terminal buffer — a realistic scenario for libs like `candy-shell`/`sugar-readline`/`candy-vcr` that plausibly sit downstream), an attacker-controlled rune containing `\x1b[...` sequences would be emitted verbatim, allowing arbitrary SGR/cursor-position/OSC injection into the rendered terminal output. Given `Hyperlink` already treats this exact class of injection as a threat worth guarding against, the same reasoning arguably applies to `Cell::rune`, or at minimum the gap should be a documented, deliberate decision (e.g. "sanitization is the caller's responsibility") rather than an implicit omission.
- No unbounded-growth concerns: `Buffer::new()`/`fromGrid()` require explicit positive dimensions and there's no grow-on-write path, so there's no obvious untrusted-input buffer-growth vector in this lib.
- Integer overflow in coordinate math (`$row * $this->width + $col`) is theoretically possible for pathological huge `$width`/`$height` products exceeding PHP int range, but this requires the caller to have already allocated an equally enormous `$grid` array, so it's not independently exploitable — low priority.

### 6. Functionality duplicated/missing vs candy-core / candy-vt

- **`candy-vt` reimplements its own independent `Buffer`/`Cell` (`candy-vt/src/Buffer/Buffer.php`, `SugarCraft\Vt\Cell\Cell`) despite depending on `sugarcraft/candy-buffer` in its `composer.json` (`candy-vt/composer.json:29,55`).** A repo-wide grep for `SugarCraft\Buffer\` inside `candy-vt/src` returns zero hits — the dependency is declared but unused. `candy-vt`'s `Buffer` is mutable (candy-buffer's is immutable), stores a nested `array<row><col>` grid instead of a flat index, and has its own `Cell`/`Sgr`/`Hyperlink` types plus a `resize()` method candy-buffer's `Buffer` lacks (see §1). This is either: (a) a legitimate design split (VT emulator needs a mutable, resizable grid; candy-buffer's immutable model is wrong for that hot path) — in which case the unused `composer.json` dependency on `candy-buffer` should be removed to avoid confusion, or (b) unintentional duplication that should be consolidated (e.g. by adding `resize()` + a mutable "builder" mode to candy-buffer and having candy-vt adopt it). Worth a maintainer decision either way; currently it's ambiguous scope overlap between the two foundation libs.
- Several other libs (`candy-forms`, `candy-mines`, `candy-tetris`, `candy-lister`, `sugar-crush`, `sugar-table`, `sugar-charts`, `sugar-calendar`, `candy-shine`, `candy-testing`, `candy-vcr`, `sugar-boxer`, `sugar-dash`, `sugar-prompt`, `sugar-bits`, `sugar-reel`, `sugar-veil`, `sugar-toast`) all declare a `sugarcraft/candy-buffer` dependency — worth a follow-up grep (out of scope for this audit) to confirm each of those actually imports `SugarCraft\Buffer\*` types rather than repeating the `candy-vt` pattern of an unused path-repo dependency.

### 7. Documentation gaps

- **README `Buffer` API table omits `fill()` and `copy()`.** The "### Buffer" section (README.md:61-68) lists `new`, `cellAt`, `withCellAt`, `withRegion`, `width`/`height`/`region`, and `diff` — but not `fill(Region, Cell)` or `copy(Region): self`, both public methods present in `src/Buffer.php:169` and `199`. Also omits `fromGrid()` (the bulk-construction escape hatch called out in §2) and `applyDiff()`/`toAnsi()`, both of which are used in the "Diffing & delta ANSI" worked example further down but never listed as API surface.
- **README doesn't document the wide-char continuation-cell invariant as caller-enforced.** The "## Wide characters" section (README.md:51-57) shows constructing a width-2 `Cell` but doesn't state that `Buffer::withCellAt()` won't place the continuation cell for you (see §1) — a caller reading only the README could reasonably assume `withCellAt(col, row, Cell::new('中'))` handles the continuation automatically, since `applyDiff()`'s internal `SetCellOp` handling *does* auto-place continuation cells (`src/Buffer.php:454-459`), creating an inconsistent mental model between the two write paths.
- **`CALIBER_LEARNINGS.md` entry on `candy-core` dependency risk looks stale.** The 2026-07-01 entry (lines 5-8) warns about `dev-master`/`@dev` stability risk for `candy-core`, but `composer.json:31` still pins `sugarcraft/candy-core` as `dev-master` in `require-dev` with no stable version available yet — the learning describes a risk that hasn't actually been mitigated; either the entry should note it's still open, or (per repo convention) be updated once resolved.
- Lang wrapper (`src/Lang.php`) uses an `extends BaseLang` + `NAMESPACE`/`DIR` const pattern, which differs from the canonical `T::register()`/`T::translate()` wrapper pattern documented in the repo's `lang-files.md` rule and exemplified by `sugar-wishlist/src/Lang.php`. Not necessarily wrong (it may be an intentional/older variant `BaseLang` provides), but worth confirming it isn't drift from the documented convention — no test (`tests/LangTest.php`) exercises locale fallback beyond the base case.

---

## candy-core

Audit of `/home/sites/sugarcraft/candy-core` (foundation TEA runtime — Program/Model/Cmd, Renderer, Subscriptions, Util/*).

### 1. Missing or incomplete functionality vs upstream bubbletea

- **Signal handling is best-effort and PHP-idiosyncratic.** `installSignalHandlers()` (`src/Program.php:1053-1086`) only installs SIGINT/SIGWINCH/SIGTSTP/SIGCONT via `pcntl_signal`, and signal delivery depends on `pcntl_signal_dispatch()` being called from the periodic render timer (`src/Program.php:259-267`). Between render ticks (default 1/framerate, e.g. 60fps ≈16ms — fine) signals queue, but if a consumer sets a very low framerate via `ProgramOptions::framerate`, signal responsiveness (including Ctrl-C) degrades proportionally. Upstream Go delivers signals immediately via the runtime; there's no async-signal-safe path here (`pcntl_async_signals` is never enabled) — worth calling out or defaulting to `pcntl_async_signals(true)`.
- **`Kind::Custom` and `Kind::Key` subscriptions are polling-timer based**, not event-driven (`startSubscription()`, `src/Program.php:1151-1183`): `Kind::Key` subscriptions poll every 0.1s regardless of actual key-press timing, and `Kind::Custom` defaults to a 1.0s poll if no `interval` param is given. This is a shim, not a real "subscribe to keyboard stream" primitive — a model using `withKey()` gets an arbitrary 100ms-latency proxy for keyboard events rather than being driven by the same `InputReader` pipeline that regular `KeyMsg`s use.
- **`installSignalSubscription()` (`src/Program.php:1190-1207`) returns a no-op 86400s timer "to satisfy the return type"** — the returned timer handle is fictitious; `cancelAllSubscriptions()`/`reconcileSubscriptions()` will cancel that dummy timer on teardown but never actually detach the `pcntl_signal()` handler that was installed. Repeatedly starting/cancelling a `Kind::Signal` subscription for the same signo will silently leak/overwrite handlers rather than properly restoring the previous one.
- **No `Program::Send`-equivalent cross-goroutine safety story is documented** for calling `send()`/`quit()`/`kill()` from a separate PHP thread/process — reasonable for a single-process ReactPHP runtime, but worth an explicit doc note since bubbletea's Go docs stress this is safe across goroutines.

### 2. Performance concerns

- **`Util/Width::string()` has no memoization**, unlike `Renderer`'s `$tokenCache` (`src/Renderer.php:50-51`, `343-346`) which explicitly caches parsed tokens per string to avoid re-parsing unchanged lines. `Width::string()` (`src/Util/Width.php:19-52`) re-runs `Ansi::strip()`, grapheme segmentation, and per-cluster codepoint decoding on every call with zero caching, and it is called pervasively (padding, truncation, wrapping, table columns, style width calcs) across the entire monorepo — every downstream lib pays this cost per render frame with no opt-out. A small LRU/string-keyed cache analogous to the Renderer's token cache would materially help hot render loops (tables, forms) that re-measure the same short strings every frame.
- **`Program::runExec()` and `suspendProgram()` both fully discard renderer/image state** (`$this->renderer->reset(); $this->lastRenderedBody = null; ...`) on every exec/suspend cycle (`src/Program.php:673-679`, `724-729`) — correct for correctness, but means any `$EDITOR` shell-out or Ctrl-Z suspend always forces one full-screen repaint afterward even when nothing changed, which is unavoidable but worth noting as a repaint-cost cliff vs. the otherwise carefully diffed renderer.
- **Cell-diff renderer's `getTokens()` cache (`src/Renderer.php:343-346`) never evicts** — `$this->tokenCache` grows unboundedly keyed by full line string, only cleared on `reset()` (alt-screen re-entry, exec, suspend, PrintMsg). For a long-running program whose view lines are highly variable (e.g. streaming logs, timestamps baked into every line), this is an unbounded memory leak across a session; there's no cap or LRU eviction.

### 3. Test coverage gaps

- **`Util/Clamp`, `Util/ColorUtil`, `Util/Validation`, `Util/Sanitize`, `Util/NullLogger` have zero dedicated tests** in `candy-core/tests/` despite being real, actively-consumed foundation utilities (`Clamp`/`ColorUtil` underpin candy-mosaic color quantization; `Sanitize::controlChars` is used by sugar-bits' Progress/Tree/Table/Help/Tabs; `Validation` throws i18n'd `InvalidArgumentException`s). None of `tests/` references `Clamp::`, `ColorUtil::`, `Sanitize::`, `Validation::`, or `NullLogger`. Given these are the *foundation* lib other libs' own test suites implicitly rely on, edge cases (e.g. `Clamp::byte()` overflow, `Sanitize::controlChars()` regex correctness against `\x7f` DEL, `Validation::range()` boundary-inclusive checks) are untested at the source.
- **`installSignalSubscription()`'s no-op-timer behavior (see §1) is untested** — no test exercises repeated start/cancel of a `Kind::Signal` subscription to confirm the pcntl handler is actually replaced/removed correctly.
- **`subscriptions()` pump reconciliation is tested at the `Subscriptions`/`reconcileSubscriptions` unit level** (`tests/SubscriptionsReconcileTest.php`) but there's no test that exercises the *full* `Program::reconcileWantedSubscriptions()` short-circuit path (`src/Program.php:1112-1119`, "hot path... must stay allocation-free: skip entirely") to confirm it actually never calls `$this->model->subscriptions()` unnecessarily, nor a test proving `Kind::Key`/`Kind::Custom` polling intervals actually fire at their configured cadence end-to-end through `Program`.
- **`Renderer::diffCells()` fallback-to-full-repaint threshold is only lightly covered** per `CALIBER_LEARNINGS.md`'s own note (line 16) about needing SGR-heavy inputs to trigger it — worth confirming `RendererTest.php` actually includes such a case rather than only the documented gotcha being recorded without a corresponding regression test (verify `tests/RendererTest.php` has an SGR-prefix-heavy row-diff case; not confirmed either way in this pass).
- **`Component`/`Composite` reorder-triggers-remount behavior** (documented in README/CALIBER_LEARNINGS as "children reconciled by ordinal position — reordering triggers unmount/mount") should have an explicit test proving *reordering* two stable children fires unmount+mount rather than leaving them mounted; `ComponentLifecycleTest.php` exists but wasn't verified to include a reorder case specifically.

### 4. Missing .vhs/*.tape demo files

- `.vhs/` contains only `counter.tape` and `timer.tape`, but `examples/` has 19 runnable demos: `altscreen-toggle.php`, `focus-blur.php`, `mouse.php`, `panes.php`, `prevent-quit.php`, `print-key.php`, `realtime.php`, `screen-stack.php`, `send-msg.php`, `sequence.php`, `set-window-title.php`, `simple.php`, `splash.php`, `suspend.php`, `tabs.php`, `views.php`, `window-size.php` all lack a corresponding `.tape`/GIF. The README only embeds the counter and timer GIFs (`README.md:196-203`) — several of the more visually distinctive demos (`screen-stack.php`, `mouse.php`, `splash.php`, `tabs.php`) would benefit most from a VHS recording since they're referenced in prose (README.md:192, 325, 478) but have no visual.

### 5. Security concerns

- **Bracketed-paste content is never sanitized before reaching the model.** `InputReader` collects raw paste bytes into `PasteMsg` (`src/InputReader.php:47,60-72,100-106`) with no call to `Util/Sanitize::controlChars()` (confirmed: `grep -rn Sanitize src/` finds only the class's own definition — it is never invoked anywhere inside candy-core, including `InputReader`). A pasted blob containing embedded ANSI/OSC escape sequences (e.g. an OSC 52 clipboard-set, a cursor-report request, or a DEC private-mode toggle) is handed to `Model::update()` verbatim; if a consumer's `view()` echoes paste content back into the rendered frame — which is the common case for a text-input widget — the escape sequences ride straight to the terminal via the renderer's raw string output. `Sanitize::controlChars()` exists specifically "because terminal control sequence injection is a real TUI attack vector" (its own doc-comment, `src/Util/Sanitize.php:11-14`) but candy-core doesn't apply its own advice at the one place raw untrusted terminal bytes enter the system.
- **`Program::runExec()` and `Util/Editor::edit()`/`Util/Open::url()`/`Util/Open::file()` correctly avoid shell interpolation** (argv-form `proc_open`, scheme allowlist in `Open::url()`) — no injection concern found there. Noted as a positive, not a gap.
- **`WorkerPool` serializes arbitrary callables/strings to subprocess stdin** (`src/WorkerPool.php`, doc-comment lines 20-23) via `\Closure::serialize()`; this is documented as app-controlled (not attacker input) so not flagged as a vulnerability, but worth a doc callout that a `WorkerPool` must never be fed serialized data derived from untrusted input (classic PHP object-injection risk if `task` strings/callables originate from network/user data).

### 6. Duplicated / misplaced functionality vs other libs

- **Three independent "sanitize terminal-unsafe text" implementations exist across the monorepo with no shared base**: `candy-core/src/Util/Sanitize::controlChars()` (strips C0 controls, preserves ESC/SGR), `sugar-table/src/Sanitize::value()` (replaces C0/DEL/C1 with `·`, has a `$multiline` flag), and `sugar-dash/src/Output/Sanitize::untrusted()` (a third, differently-shaped API consumed by `WeatherModule`/`GenericModule`/`ExternalModule` specifically for *untrusted external* content like API responses). These three do overlapping but subtly different jobs (candy-core's preserves ANSI for internal use; sugar-table's neutralizes ANSI *and* control chars for display; sugar-dash's is explicitly for untrusted external data) and have diverged rather than being layered on one canonical primitive. Given candy-core is the foundation, consolidating the C0/C1-stripping primitive here (with sugar-table/sugar-dash calling through with their own display-glyph or untrusted-data policy layered on top) would remove real duplication and let one lib own the "is this sanitizer's regex correct" test burden instead of three.
- **`Kind::Key`/`Kind::Custom` subscriptions being generic 0.1s/1.0s polling timers** (see §1) rather than true event subscriptions feels like functionality that's really `candy-async`'s or `candy-input`'s domain (an actual event-stream subscription primitive) leaking into candy-core as a timer-polling shim. If `candy-input`/`candy-async` ever grow a real pub/sub event bus, this polling implementation in `Program::startSubscription()` should be replaced rather than extended.
- **`ImageLayer`/`ImageOverlay`/`ImagePlacement`** (sixel/kitty/iTerm2 image painting, `src/ImageLayer.php`, `src/ImageOverlay.php`, `src/ImagePlacement.php`) is fairly specialized terminal-graphics-protocol code living in the foundation lib. Given the monorepo already carves out `candy-mosaic` for image/color-quantization rendering, there's a reasonable argument this belongs there or in a dedicated `candy-*` graphics lib instead of candy-core's core Program/Renderer path — it adds meaningful surface area (three classes plus the `Program::renderFrame()`/`clearImageRows()` integration) to what's supposed to be the minimal TEA runtime.

### 7. Documentation gaps

- **README's own "Architecture" section is stale relative to the actual Renderer.** `README.md:66`: *"`Renderer` — minimal cursor-home + erase + write. Diff-based renderer is a follow-up."* This describes a renderer that no longer exists — `src/Renderer.php` implements both a full line-diff and an opt-in cell-diff ("cursed renderer") with SGR-state tracking, token caching, and DEC 2026 synchronized-update wrapping (documented accurately in the Renderer's own class doc-comment, `src/Renderer.php:12-44`, and in the Status section further down the same README, `README.md:208`). The one-line Architecture bullet contradicts the Status section three screens later in the same file.
- **README's Screen/ScreenStack example references a non-existent class.** `README.md:183`: `new \SugarCraft\Core\Model\Anonymous(fn() => "Push a screen with n\n")` — there is no `SugarCraft\Core\Model\Anonymous` class anywhere in `src/` (confirmed via `find`/`grep` — no `Anonymous` class exists in the codebase). A reader copy-pasting this example gets a fatal "class not found" error; the example needs either a real anonymous-class implementation of `Model` inline, or removal of that branch.
- **README badge line still shows `php-%E2%89%A58.2` (PHP ≥8.2)** (`README.md:10`) while `composer.json` requires `"php": "^8.3"` and both AGENTS.md/CLAUDE.md state PHP 8.3+ as the floor — minor but a real drift between the badge and the actual requirement.
- **README "Requirements" section says "PHP 8.1+"** (`README.md:52`) — same drift, now three different floors quoted across one file (8.1 in Requirements prose, 8.2 in the badge, 8.3 in composer.json).

---

## candy-files

Audit of `/home/sites/sugarcraft/candy-files` (dual-pane file manager, port of `yorukot/superfile`).

### 1. Missing or incomplete functionality vs upstream scope

- **Search is shallow, not recursive.** `Manager::search()` (`src/Manager.php:815-828`) calls `($this->lister)($cwd)` against the *current pane's cwd only* and filters by substring. Superfile-style file managers (and the README's "search" framing) imply a project-wide/recursive find; this only filters the entries already listed in the active directory. No fuzzy matching either (SugarCraft already has `candy-fuzzy` in the monorepo — see §6) — it's a plain `str_contains` substring match (`Manager.php:825`).
- **No globbing / extension filtering.** There's no way to filter a pane's listing by pattern (`*.php`, hide by extension, etc.) — `grep` for `glob` across `src/` returns nothing.
- **No permission/mode display.** `Entry` (`src/Entry.php:18-25`) only carries `name/isDir/size/mtime/isLink/isHidden` — no `mode`/permission bits, owner/group, or a rendered `rwxr-xr-x` column, despite `lstat()` already being called in `FsLister::lister()` (`src/FsLister.php:28-32`) and `$stat['mode']` already being read out for the dir/symlink bitmask. Adding permission display would be nearly free (the stat call already happened).
- **Symlinks are followed for `isDir`, not distinguished for navigation.** `FsLister` sets `isDir` from `lstat` mode bits directly, so a symlink to a directory is *not* marked `isDir` (lstat doesn't follow the link) — meaning symlinked directories can't be navigated into via `Pane::navigate()` (`src/Pane.php:71-81`, which requires `$current->isDir`). This is a functional gap: symlinked dirs show as `LINK` size and are dead-ends in the UI.
- **No rename/copy/move destination text-entry** for copy/move — the destination is always hard-coded to "the inactive pane's cwd" (`armCopy`/`armMove`, `Manager.php:472-511`). There's no way to type an arbitrary destination path, unlike rename which does get a text-entry sub-mode (`ConfirmState::RenameSelected`).
- **No mkdir / new-file creation.** `UndoAction::mkdir()` (`src/UndoAction.php:93-100`) and `UndoActionType::Insert` handling exist in `Manager::redoInsert`/`reverseDelete` (`Manager.php:1184-1197`), implying mkdir support was planned, but no `Manager` key binding or `armMkdir`/`performMkdir` method exists — the undo plumbing is present with no producer.
- **No file preview/view.** Files can't be opened, viewed, or edited from the manager — `navigate()` explicitly no-ops on files (`Pane.php:74-76`), and there's no bound key for "open externally" or preview pane.

### 2. Performance concerns

- **Fully synchronous directory scans.** `FsLister::lister()` (`src/FsLister.php:17-47`) does `scandir()` + one `lstat()` per entry, entirely synchronously, on every `Pane::open()` call (navigate, refresh, sort cycle re-list via `toggleHidden`, tab duplicate, etc.). For a huge directory (tens of thousands of entries) this blocks the ReactPHP event loop for the whole scan — there is no chunking, no `Loop::futureTick` batching, and no lazy/paginated loading of entries the way the async copy path (`AsyncOps`) at least defers to `futureTick`.
- **`performCopy()` (sync path) still exists and blocks.** `Manager::performCopy()` (`Manager.php:598-633`) does synchronous recursive `copyDir()` (`Manager.php:571-596`) even though `performCopyAsync()` exists and is the one wired into `resolveConfirm()` (`Manager.php:407-413`). The sync `copy()`/`move()`/`rename()`/`copyDir()` methods on `Manager` are dead code paths now that copy goes through `performCopyAsync()`, but `move`/`rename` remain fully synchronous (confirmed in README's own Architecture table: "move/rename available but sync", `README.md:82`) — a large recursive move/rename of a directory tree will block the loop.
- **No stat caching.** Every `refresh()`, sort cycle, or `toggleHidden()` re-lists from scratch via `Pane::open()` → new `lstat()` per entry; `setSort()` is the only op that avoids re-listing (comment at `Pane.php:145-146` confirms this was deliberate for sort). Directory size/mtime is never cached across re-renders in the same session, so pressing `r` or toggling hidden files always re-stats every entry.
- **`redoDelete`/`reverseDelete` do per-item `file_exists()` + `rename()` syscalls** in a loop with no batching (`Manager.php:1117-1149`, `1219-1242`) — fine for a handful of items but scales linearly with selection size with no async offload, unlike copy.

### 3. Test coverage gaps

- **No symlink test anywhere.** `grep -n "symlink\|readlink" tests/*.php` returns zero hits. `Manager::copy()`, `copyDir()`, and `AsyncOps::doCopy()`/`copyDir()` all have explicit `is_link()`/`symlink()`/`readlink()` branches (`Manager.php:537-543`, `582-584`; `AsyncOps.php:192-198`, `221-222`) that are completely untested — including the case of a broken/dangling symlink, a symlink to a directory, or a symlink loop (which would cause `copyDir`'s recursion to run away, see §5).
- **No permission-error tests.** No test simulates `@copy`/`@rename`/`@mkdir`/`@unlink` failing due to permissions (e.g. read-only source dir, unwritable destination) to verify the `$errors` counters (`Manager::performDelete/performCopy/performMove`) and the `status.*_with_errors` i18n messages actually fire and read sensibly.
- **No huge-directory / pagination test.** Nothing exercises `FsLister`/`Pane::open` with thousands of synthetic entries to confirm sort/render/cursor behavior scales or at least doesn't error.
- **`Manager::copy()/move()/rename()/copyDir()` (the synchronous, now-effectively-dead public methods)** aren't directly unit tested in isolation from the confirm-gate flow — coverage exists via `ManagerMoveTest`/`ManagerRenameTest`/`ManagerCopyTest` but only through the `armX()`→`resolveConfirm()` path, so there's no direct regression test pinning `Manager::copy()`'s symlink/dir/file branch selection.
- **`Renderer::renderTabBar`/`renderSearch`/`truncate`** have no dedicated tests — `tests/RendererTest.php` is 86 lines and per the README only covers basic pane rendering; grep confirms no test file references tab-bar or search rendering assertions specifically. Multi-tab rendering (`showTabBar`, label truncation at `Renderer.php:38-40`) and the search-mode banner (`Renderer.php:88-109`) look untested against real Manager state.
- **`AsyncOps::copyManyAsync` concurrency limiting** (`AsyncOps.php:99-150`) is nontrivial (manual pending/index bookkeeping with recursive `$startNext` closures) — worth double-checking `tests/AsyncOpsTest.php` covers the case where `$concurrency` > count and where a copy in the middle of the pipeline throws (the `function (\Throwable $e)` rejection branch, `AsyncOps.php:134-142`) with **multiple** concurrent failures, not just one.

### 4. Missing .vhs/*.tape demo files

- All 7 demos referenced in `README.md` (navigate, search, multi-select, tabs, sort-cycle, undo, hidden-files) have matching `.tape`+`.gif` pairs in `.vhs/` — no gaps here.
- However, there is **no tape/demo for copy, move, or rename** — three of the manager's core destructive/mutating operations (`armCopy`/`armMove`/`armRename`, `Manager.php:472-527`) have no VHS coverage despite being prominent in the keybinding table (`README.md:55-57`) and having their own dedicated test files (`ManagerCopyTest.php`, `ManagerMoveTest.php`, `ManagerRenameTest.php`). Given `.vhs/undo.tape` exists, a copy/move/rename demo is a natural gap to fill.

### 5. Security concerns

- **`copyDir()` has no symlink-loop / cycle guard.** Both `Manager::copyDir()` (`Manager.php:571-596`) and `AsyncOps::copyDir()` (`AsyncOps.php:208-234`) recurse into subdirectories found via `scandir()` with no depth limit and no tracking of visited real paths. A directory containing a symlink back to one of its own ancestors (or to itself) will cause unbounded recursion / stack exhaustion — classic symlink-loop DoS. Upstream superfile (Go) has the OS's own protections partially via `filepath.Walk` symlink semantics; this PHP port re-implements the walk manually with no equivalent guard.
- **Copy/move destination is not path-traversal-checked**, unlike rename. `performCopy`/`performMove`/`performCopyAsync` build `$target = Pane::join($dst, $name)` (`Manager.php:614-615`, `701-703`, `661-663`) where `$name` comes from `array_keys($pane->selected)` or `$pane->currentEntry()->name` — both sourced from real directory listings, so `$name` can't itself contain `../` under normal `scandir()` output. But note `Manager::rename()` (the *only* op with a `Phar::canonicalize` traversal guard, `Manager.php:747-756`) implies the team is aware of traversal risk for user-typed input; copy/move destination is always the *other pane's cwd* (not user-typed) so the risk surface is smaller today — but if the "type an arbitrary destination" feature from §1 is ever added, it would need the same guard and currently has no shared/reusable path-validation helper (the traversal check is inlined once in `performRename`, not factored out).
- **Trash-based delete uses `getmypid()` in a world-readable temp path**: `sys_get_temp_dir() . '/candyfiles-trash-' . getmypid()` (`Manager.php:90`, `463`). On a shared multi-user host, `sys_get_temp_dir()` (`/tmp`) is often world-writable; a predictable-ish trash dir name (PID is guessable/enumerable) could let another local user pre-create `/tmp/candyfiles-trash-<pid>` with permissive mode before the app does, or race to read "deleted" files before they're cleaned up by the shutdown handler. The random 16-byte hex prefix on individual trashed *item* names (`Manager.php:466`) mitigates guessing individual filenames, but the trash *directory* itself has no `mkdir(..., 0700)` — it inherits whatever `@rename()`'s implicit directory creation would need (actually `rename()` won't auto-create the dir; look at `performDelete` — if the trash dir doesn't exist yet, `@rename($full, $trashPath)` will fail and fall back to permanent `removePath()` deletion). That fallback path (`Manager.php:431-438`) means **first-ever delete in a session likely bypasses trash and undo silently** unless something else creates the trash dir first — worth verifying there's a `mkdir` for the trash dir somewhere; a grep shows no `mkdir` call for `$trashDir` itself, only for restore/redo paths.
- **No root-boundary confinement.** Nothing stops `goUp()`/navigation from walking above the directory the manager was started in, up to `/` and beyond into other filesystem areas the invoking user has read access to — this may be intentional (it's a general file manager, not a jailed one) but is worth flagging since some SugarCraft consumers might expect a `chroot`-like `rootDir` confinement option for embedding in restricted contexts; no such option exists on `Manager::start()`.

### 6. Duplicated / misplaced functionality across libs

- **Fuzzy matching duplication risk**: `candy-files`' search (`Manager::search()`) implements ad hoc `str_contains(strtolower(...))` matching instead of using `candy-fuzzy`, which already exists in the monorepo specifically for this purpose. `candy-files/composer.json` does not depend on `candy-fuzzy` at all. If `candy-fuzzy`'s public API supports simple filtering, this is a case where `candy-files` reinvented a subset of it instead of depending on it (mirrors the resolved `candy-query reinvents TUI primitives` pattern noted in project memory — worth the same upstream-extraction treatment here).
- **Undo/redo primitives**: `UndoAction`/`ConfirmState`/`UndoActionType` are partly local (`src/UndoAction.php`, `src/ConfirmState.php`) and partly imported from `SugarCraft\Core\Undo\UndoActionType` (`Manager.php:14`, `UndoAction.php:7`) — i.e. the enum lives in `candy-core` but the `UndoAction` DTO and the confirm-gate state machine are reimplemented locally in `candy-files` with no shared base. If any other lib needs "arm → confirm (y/n) → perform, with undo stack" (a generic and reusable TUI pattern), it currently has no home in `candy-core`/`candy-forms` to be shared from — `candy-files` is the only consumer today, so this may be fine, but it's the kind of state machine `candy-forms`/`candy-focus` conventions might want to formalize if a second lib needs it.

### 7. Documentation gaps

- **README's "Test plan" section is stale**: it claims "36 tests / 65 assertions" (`README.md:86`) listing only `Entry`/`Sort`/`Pane`/`Manager` coverage; the actual suite has grown to **143 test methods / ~375 assertions** across 10 test files including `ManagerCopyTest`, `ManagerMoveTest`, `ManagerRenameTest`, `UndoActionTest`, `AsyncOpsTest`, and `LangCoverageTest` — none of which are mentioned in the README.
- **README's "Architecture" table is incomplete**: it lists `Entry/Sort/Pane/ConfirmState/Manager/FsLister/Renderer/AsyncOps` (`README.md:73-82`) but omits `Manager\ManagerBuilder`, `UndoAction`, and `Msg\CopyCompletedMsg` — all real, non-trivial classes with their own responsibilities.
- **README's "Status" line is stale**: "Phase 10 entry — copy / move / rename / undo are wired... copy undo is informational" (`README.md:149`) — undo/redo (`Ctrl+Z`/`Ctrl+Y`) is now bidirectional (`Manager::undo()`/`redo()`, `Manager.php:1046-1099`) with a real redo stack, tabs (`t`/`Ctrl+w`/`Ctrl+Tab`) are implemented, and search (`/`) is implemented — none of which are reflected in this one-line status blurb, which reads as if the lib is mid-port rather than functionally complete for these features.
- **`docs/lib/candy-files.html` links to a non-existent `examples/` directory** (`docs/lib/candy-files.html:104`: "Runnable examples" → `https://github.com/sugarcraft/candy-files/tree/main/examples`) — there is no `examples/` directory in the repo; the actual entry point is `bin/candyfiles`. This link will 404.
- **i18n coverage is a single locale.** `candy-files/lang/` contains only `en.php` (44 keys), whereas sibling libs like `candy-shine/lang/` ship 16 locale files (ar, cs, de, en, es, fr, it, ja, ko, nl, pl, pt, pt-br, ru, tr, zh-cn) per `LOCALES.md`'s documented locale set. `CALIBER_LEARNINGS.md` doesn't flag this as a known/accepted gap, and `LangCoverageTest.php` presumably only tests the `en` fallback path, so this reads as an oversight rather than a deliberate scope decision.
- **`CALIBER_LEARNINGS.md` is thin/stale relative to the code's complexity**: it documents the confirm-gate pattern and the "god-class Manager needs a builder" lesson (2026-05-31) but says nothing about the async-copy-vs-sync-move/rename split, the trash-based delete/undo design, tabs, or search — all substantial features added since the builder was introduced, per `Manager.php`'s size (1294 lines) and the README's own feature list.

### Additional observation: convention deviation

`Manager` (`src/Manager.php`) does not follow the repo-wide Immutable+fluent `mutate()` helper pattern documented in `AGENTS.md`/`.claude/rules/model-pattern.md` (canonical example `candy-sprinkles/src/Style.php` / trait `candy-core/src/Concerns/Mutable.php`). Instead, every `with*()` method (`withActive`, `withActivePane`, `withConfirm`, `withStatus`, `withInputBuffer`, `withSearch`, `withUndoRedoStacks`, plus `openNewTab`/`closeTab`/`switchTab`/`duplicateTab`) manually re-lists all 16 constructor parameters positionally (e.g. `Manager.php:242-263`, `781-812`, `950-1043`). This is exactly the "impossible to memorize, mistake is silent" anti-pattern the lib's own `CALIBER_LEARNINGS.md` (2026-05-31 entry) warns about for the *constructor* — the `with*()` methods have the identical problem one level up, since adding a 17th field requires updating ~15 call sites by hand instead of one `mutate()` closure. `ManagerBuilder` solves this for *construction* but the runtime *transition* methods on `Manager` itself never adopted the same discipline.

---

## candy-flip

candy-flip is a terminal ASCII/ANSI GIF *viewer* — port of `namzug16/gifterm` — not a card-flip/easing-animation lib as its name might suggest. It has: `Decoder` (hand-rolled GIF89a parser + GD compositor), `Frame` (value object), `Renderer` (solid/density ANSI emitter), `Player` (candy-core `Model`), `TickMsg`. A `.vhs/play.tape` + `examples/play.php` demo already exists and the lib is already wired into `.github/workflows/vhs.yml` (line 151, 225) with `ext-gd` in the shared `extensions:` list (line 72) — so items normally flagged as "missing VHS demo" do NOT apply here.

### 1. Missing or incomplete functionality vs upstream scope

- **Only two render presets** (`solid`, `density`) — `src/Renderer.php:21-24`. No 256-color ("ANSI-256") degrade path; every emitted SGR is 24-bit truecolor (`38;2;…`/`48;2;…`, `Renderer.php:136,139`). Terminals without truecolor support (many CI logs, some legacy terminals) will render garbage. No capability probe/fallback exists anywhere in the lib.
- **No playback-speed control.** Keys are pause/resume, step ±1 frame, preset toggle, quit (`src/Player.php:49-71`; README.md:24-32). There is no fast-forward/slow-motion/loop-count control, even though `Frame::$delay` is already per-frame data that a speed multiplier could scale trivially in `Player::scheduleTick()` (`Player.php:121-124`).
- **`Frame` disposal-method docblock contradicts the implemented behavior.** `src/Frame.php:22` says: `3 = restore-prev (restore to previous — unsupported; treated as NONE)`. But `src/Decoder.php:101-104` *does* implement DISPOSAL_PREVIOUS by restoring a canvas snapshot taken before the prior frame was painted. Either the doc-comment is stale (most likely — the feature was added later and the comment wasn't updated) or the multi-frame restore path is not actually exercised (see Test coverage below) and the comment is aspirational-but-true. Either way this is a real inconsistency that should be resolved one way or the other.
- **CALIBER_LEARNINGS.md documents two patterns that do not exist in `src/` at all:**
  - `[pattern:weakmap-frame-cache]` (CALIBER_LEARNINGS.md:20) — describes memoizing per-frame rendered ANSI output via a `WeakMap`. `grep -rn WeakMap src/ tests/` returns nothing; no such cache exists in `Renderer` or `Player`.
  - `[pattern:floyd-steinberg-dithering]` (CALIBER_LEARNINGS.md:14) — describes an error-diffusion dithering pass with a palette-distance lookup. `grep -rln "Floyd\|dither\|Dither" src/ tests/` returns nothing; there is no dithering code path or third preset for it.
  Both learnings look like they were extracted from a session that planned or partially built this work and then reverted/abandoned it, or from a different lib's session bleeding into this file. They should either be implemented or removed so `CALIBER_LEARNINGS.md` doesn't mislead future sessions (see section 7).

### 2. Performance concerns

- **No frame-render memoization.** `Player::view()` calls `$renderer->renderFrame($frame, $this->preset)` unconditionally on every `view()` invocation (`src/Player.php:94`). Since `view()` can be invoked multiple times for the same `$index`/`$preset` pair (e.g. after a `WindowSizeMsg` that doesn't change the current frame, or a re-render triggered by candy-core's event loop without a new `TickMsg`), the full per-cell luminance/ramp computation and ANSI string concatenation (`Renderer.php:97-126`) is redone from scratch each time. This is exactly the gap the (unimplemented) `weakmap-frame-cache` CALIBER learning was meant to close — see section 1.
- **Decoder compositing is pixel-by-pixel via GD accessor calls.** `Decoder::decode()`'s per-frame compositing loop (`src/Decoder.php:128-137`) calls `imagecolorat()`/`imagecolorsforindex()`/`imagesetpixel()`/`imagecolorallocate()` once per source pixel, and `sampleCanvas()`/`sample()` do the same per destination-cell source pixel (`Decoder.php:230-251`, `512-529`). `imagecolorallocate()` inside the innermost loop (`Decoder.php:135`) is a known GD anti-pattern — repeated calls with a color GD hasn't seen before grow the image's color table and can degrade performance on large truecolor canvases; palette lookups on a big color table are O(n) in GD's copy of libgd. For the documented 256-frame cap and typical small GIFs this is bounded, but for larger/higher-res source GIFs (e.g. a 500×500 canvas) this compositing pass is O(frames × width × height) with per-pixel function-call overhead in both compositing and downsampling — no caching or vectorized (`imagecopyresampled`-style) shortcut is used despite GD providing one.
- **Renderer's "same color" run-coalescing only compares to the immediately preceding cell** (`Renderer.php:114-121`), not to a run buffer, so it still touches `Ansi::CSI` sprintf logic once per distinct-color transition — reasonable, but it's worth noting the density preset always computes the luminance ramp index per cell even when reusing the same glyph is impossible anyway (no memoized luminance-to-glyph table beyond the fixed 10-entry `RAMP` string) — minor, not a real hotspot.

### 3. Test coverage gaps

- **DISPOSAL_PREVIOUS's actual multi-frame restore semantics are not verified.** `tests/DecoderCompositingTest.php:143-215` has an explicit regression test whose own comments (line 153, 212) state it only verifies that `disposal=3` is *stored* correctly on the `Frame`, not that the canvas snapshot restore itself changes composited pixel output as the GIF spec (and `Decoder.php:101-104`) require. Given the Frame.php docblock also claims this is "unsupported," this is the most concrete coverage gap in the lib — there is no test that actually asserts pixel-level correctness of a DISPOSAL_PREVIOUS restore across 3+ frames.
- **`Renderer::withAdaptiveSize()` cannot be exercised in CI** — `tests/AdaptiveSizeTest.php:14` notes it "requires a real TTY," so the TTY-query path through `SizeIoctl::query()` (`Renderer.php:53-57`) and its `\RuntimeException` when STDOUT isn't a TTY are effectively untested in automated runs (only the constraint-clamping logic downstream is tested via `withConstraints()`).
- **No test drives `bin/candy-flip` end-to-end** (argv parsing, `density` CLI arg, non-TTY 60×24 fallback at `bin/candy-flip:36-43`, missing-path usage error). This is a thin script but its non-TTY fallback branch and `Lang::t('cli.usage')` message are unverified by any test in `tests/`.
- **No coercion/edge-case test for `Decoder::decode()` with `$cellsW`/`$cellsH` \<= 0** beyond the oversized-grid regression noted above (`tests/DecoderTest.php:161-170` covers the `> MAX_CELLS` branch, but the `$cellsW <= 0 || $cellsH <= 0` branch at `Decoder.php:48-50` has no dedicated test asserting the exception/message).
- All other public methods (`Frame::width/height`, `Renderer::render/renderFrame/withConstraints/new`, `Player::init/update/view/subscriptions`, `Decoder::decode` happy-path/transparency/local-color-table/timing) do have direct test coverage across `tests/*.php` (1,483 total test lines across 10 files) — coverage is generally strong except for the two gaps above.

### 4. Missing .vhs/*.tape demo files

None — `.vhs/play.tape` and `.vhs/play.gif` already exist, `examples/play.php` builds a self-contained synthetic GIF so the tape doesn't need a binary fixture, and `candy-flip` is already present in `.github/workflows/vhs.yml`'s hand-maintained `all=(...)` array (line 151) and matrix (line 225), with `gd` already in the shared `extensions:` list (line 72). No action needed here.

### 5. Security concerns

- Largely not applicable — this is a local file decoder, not a network-facing service. Two minor hardening notes:
  - `Decoder::decode()` does correctly guard against untrusted/malformed GIFs: header-length check, magic-byte check, `$cellsW * $cellsH > MAX_CELLS` cap (`Decoder.php:44-53`), and a 256-frame cap on `frameInfos` (`Decoder.php:392`) to bound memory — good practice already in place, no gap to flag.
  - The hand-rolled byte-stream walker (`parseHeader()`, `Decoder.php:282-394`) uses `ord($bytes[$i] ?? '')` defensively but a few array accesses inside the GCE branch (`Decoder.php:312,315`) use `ord($bytes[$i + 3] ?? '')`-style null-coalescing only for some offsets and bare `ord($bytes[$i + 4])`/`ord($bytes[$i + 5])` (no `?? ''`) for others (`Decoder.php:316`) — a truncated file that ends exactly inside a GCE block could throw a `TypeError`/warning from `ord(null)` rather than the intended `RuntimeException`. Low severity (local file, not attacker-controlled network input in the typical use case) but inconsistent with the defensive style used elsewhere in the same function.

### 6. Cross-lib duplication / misplaced functionality

- No overlap found with `honey-bounce`/`honey-flap` — those are spring/physics easing libs for animating UI values, unrelated to GIF frame-timing (which just replays literal per-frame GCE centisecond delays, not eased/interpolated motion). No functionality should move between them.
- No GIF/image-decoding functionality was found duplicated in other libs (checked for `Decoder`-shaped code elsewhere would require a repo-wide grep beyond this lib's scope; nothing in candy-flip's own tree suggests reuse of a shared decoder that should have lived in `candy-core` instead — the whole GIF-specific parser is appropriately self-contained here).

### 7. Documentation gaps

- **`composer.json` keywords array has a duplicate entry**: `"sugarcraft"` appears twice (`composer.json:11` and `:13`). Cosmetic but should be de-duped.
- **CALIBER_LEARNINGS.md documents unimplemented features as if they were established patterns** (see section 1: WeakMap frame cache, Floyd-Steinberg dithering). Since this file is described project-wide as "auto-managed... do not edit manually" and meant to reflect "patterns... learned from previous sessions" (AGENTS.md), having two learnings that describe code not present in `src/` is actively misleading for the next session/agent that trusts it as ground truth.
- **README.md's "Implementation notes" section (lines 34-44) accurately describes disposal methods 0-3 including DISPOSAL_PREVIOUS (3) restoring a canvas snapshot** — this text is *correct* and matches `Decoder.php`, which makes the contradicting docblock in `src/Frame.php:22` (saying disposal 3 is "unsupported") even more clearly a stale comment that should be fixed to match both the README and the actual Decoder implementation.
- README's Architecture table (lines 48-56) is otherwise accurate and matches the actual file responsibilities in `src/`.

---

## candy-focus

Audited: `candy-focus/src/FocusRing.php` (409 lines, single class), `candy-focus/tests/FocusRingTest.php` (435 lines, 40 tests), `candy-focus/README.md`, `candy-focus/CALIBER_LEARNINGS.md`, `candy-focus/composer.json`. No `.vhs/` directory exists. The lib is intentionally tiny and dependency-free — a single `FocusRing` value object with no rendering/key-decoding.

### 1. Missing or incomplete functionality vs upstream scope

- **No focus-trap / nested-scope support.** The doc-comment (`src/FocusRing.php:1-24`) explicitly scopes this as a single flat ring — there's no concept of a sub-ring or modal focus-trap (e.g. a dialog that should cycle only among its own regions and restore the parent ring's focus on close). Nothing wrong with the scope choice, but it means any TUI needing modal focus-trapping (dialogs, popups) must hand-roll it on top of `FocusRing`, and no helper (e.g. `push()`/`pop()` a sub-ring, or "capture" semantics) exists for that common case.
- **No mouse/click focus helper.** `candy-input/src/Event/FocusEvent.php` reports terminal-level focus gained/lost (CSI I/O), and mouse click events live in `candy-input`, but `FocusRing` has no `focusAt(x, y)` or region-hit-testing helper — every consumer must map a `MouseEvent` to a region id itself before calling `focus($id)`. This is reasonable given the "dependency-free" design goal (region geometry isn't its job), but worth flagging as a scope gap relative to typical focus-manager designs that do own hit-testing.
- **`disable()`/`enable()` exist and are skip-aware in `next()`/`previous()`** (`src/FocusRing.php:206-319`) — this satisfies the "disabled-widget skip" requirement well, including edge cases (disabling the focused region doesn't move focus immediately; only-one-enabled is a no-op; all-disabled is a no-op). No gap here.
- **Tab/Shift-Tab wrap-around cycling** is present and correct (`next()`/`previous()`, `src/FocusRing.php:207-293`). No gap here.
- **No `FocusRing` reference counting / weak registration** — if a caller forgets to `unregister()` a torn-down widget, the ring holds a stale id forever (not a bug, just a note that lifecycle management is entirely the caller's job and undocumented as a footgun in the README).

### 2. Performance concerns

- `next()` and `previous()` rebuild the full `$enabledPositions` array by scanning all `$ids` on every call (`src/FocusRing.php:213-219`, `258-264`) — O(n) per keystroke instead of O(1) or O(disabled-count). For the intended use case (a handful of top-level panels: sidebar/grid/filter-bar) this is a non-issue, but if a consumer builds a ring over many rows (e.g. a `sugar-table` with per-cell/per-row focus regions), every Tab press becomes an O(n) scan. Given `enabledCount()`/`disabledCount()` already avoid array allocation (`src/FocusRing.php:345-355`), the class is clearly performance-conscious elsewhere, so this asymmetry looks like an oversight rather than a deliberate tradeoff. Not blocking at ring sizes in the single digits/tens.
- `register()`/`unregister()` do `in_array`/`array_search` linear scans (`src/FocusRing.php:96`, `118`) — same O(n) profile, same caveat.
- No caching/memoization is needed at the intended scale; flagging only for future large-ring use cases.

### 3. Test coverage gaps

40 tests cover almost every behavioral edge case (empty ring, single-region no-op, dedup, unregister-before/at/after-focus, disable/enable interactions, empty-string id as a real region, immutability of mutators, reorder identity fast-path). Concrete gaps:

- **`jsonSerialize()` has zero tests** (`src/FocusRing.php:363-370`) — not covered at all; no test asserts the `{ids, index, disabled}` shape or that `disabled` serializes as a plain `list<string>` (via `array_keys`).
- **`getIterator()` has zero tests** (`src/FocusRing.php:357-361`) — `IteratorAggregate` contract untested; no test does `foreach ($ring as $id)` or `iterator_to_array($ring)`.
- **`enabledCount()` / `disabledCount()` have zero tests** (`src/FocusRing.php:345-355`) despite being called out in the doc-comment as the "zero-cost" alternative to `enabledIds()`/`disabledIds()` — no test verifies the counts, and no test verifies they stay consistent with `enabledIds()`/`disabledIds()` after disable/enable/unregister sequences.
- **`disabledIds()` has zero tests** (`src/FocusRing.php:336-343`) — only `enabledIds()` is tested (`testEnabledIdsReturnsOnlyEnabled`, line 429-434); the disabled-side mirror method is unverified.
- **No nested/multi-scope test** — consistent with there being no such feature (see §1), but also means the "one ring per modal" pattern that consumers will likely build isn't exercised even at the integration level.
- **No test with a large ring** (e.g. 100+ ids) to guard against the O(n) traversal cost noted in §2 regressing further, though this is a minor ask for a lib this size.
- Every other public method (`new`, `of`, `ofStrict`, `register`, `unregister`, `focus`, `reorder`, `next`, `previous`, `disable`, `enable`, `isEnabled`, `has`, `current`, `isFocused`, `index`, `ids`, `count`, `isEmpty`) has solid coverage including edge cases.

### 4. Missing .vhs/*.tape demo files

- No `.vhs/` directory exists, and `candy-focus` is **not** listed in `.github/workflows/vhs.yml`'s hand-maintained `all=(...)` matrix (confirmed via grep — zero matches). This is consistent with AGENTS.md's carve-out for "non-visual libs" (pure state machines like `candy-testing`), since `FocusRing` renders nothing itself. No action needed unless the project wants a demo showing it wired into a toy TUI model — but if so, it'd need `examples/` first, which also doesn't exist.

### 5. Security concerns

None apply. Pure in-memory value object over string ids; no I/O, no external input parsing, no serialization deserialization path that trusts untrusted data (`jsonSerialize()` is one-way, there's no `fromJson`/deserializer to unvalidated-input risk).

### 6. Duplicated / missing-elsewhere functionality

- **`candy-forms/src/Form.php` reimplements a private, non-reusable focus-ring instead of depending on `candy-focus`.** `Form` tracks `focusedIndex` + `groupIndex` and implements its own wrap/skip traversal in `advance()` (`candy-forms/src/Form.php:776-797`) and `advanceGroup()`, including a `firstNonSkippable()` helper that plays the same role as `FocusRing::next()`/`previous()`'s enabled-position skip logic. Root `composer.json` and `candy-focus/composer.json` show `candy-focus` has **no consumers anywhere in the monorepo** (`grep -rl "FocusRing" --include="*.php" .` outside `candy-focus/` itself returns nothing; `grep -rl "candy-focus" --include="composer.json" .` only matches the root manifest and candy-focus's own file — no other lib's `composer.json` requires it). Since AGENTS.md explicitly calls out "Foundation `candy-forms` is the form-primitives base that `sugar-bits` + `candy-shell` depend on," and `candy-focus`'s own doc-comment says it "Mirrors... sugar-dash's FocusManager," this looks like exactly the kind of focus-traversal duplication the task description asked to flag: `candy-focus` was built as the shared primitive but `candy-forms.Form` (and, per the doc-comment, `sugar-dash`'s `FocusManager`) each independently reimplement equivalent field-to-field traversal/skip logic rather than composing over `FocusRing`.
- **`candy-forms` Field-level `focus()`/`blur()`/`isFocused()` contract** (`candy-forms/src/Field.php:29-52`) is a *different* concern — each field owns its own boolean focus state and cursor-blink behavior (see `candy-forms/src/Cursor/Cursor.php`), which is legitimately per-field UI state, not ring membership. The overlap is specifically in `Form`'s *traversal* logic (which field is "current"), not in the individual `Field::isFocused()` accessors.
- **`candy-input`'s `FocusEvent`** (`candy-input/src/Event/FocusEvent.php`) is unrelated in scope — it reports terminal-window-level focus gained/lost (CSI I/O DECSET 1004), not in-app widget focus. No duplication there; it's a complementary signal a consumer could feed into `FocusRing`-aware code (e.g. "the terminal lost focus, so blur everything"), but nothing wires the two together today.
- **`candy-kit`** has no focus-related code at all (`grep -rniI "focus" candy-kit/src` — zero matches), despite being a foundation/kit lib; no duplication concern there, just an absence.

### 7. Documentation gaps

- README.md's API table (`candy-focus/README.md:66-81`) omits several public methods that exist in `FocusRing.php`: `ofStrict()`, `reorder()`, `disable()`/`enable()`, `isEnabled()`, `enabledIds()`/`disabledIds()`, `enabledCount()`/`disabledCount()`, `jsonSerialize()`, `getIterator()`. The README's "Behaviour" section and Quick Start example also never mention disabled-region skip semantics at all, even though roughly a third of `FocusRing.php`'s code (`disable`/`enable`/skip-aware `next`/`previous`) implements exactly that feature — a reader relying only on the README would not know disabled-region support exists.
- `CALIBER_LEARNINGS.md` (candy-focus) only documents the original `register`/`unregister`/one-focused-member invariant work; it predates (or at least doesn't mention) the later `ofStrict()`, `reorder()`, and disable/enable additions visible in the test file's "Step 2/3/4" section markers (`tests/FocusRingTest.php:245, 272, 316`), suggesting the learnings file wasn't updated after those steps landed — mild staleness.
- `docs/index.html` references `img/icons/candy-focus.png` (lines 139, 439) but no such file exists at `docs/img/icons/candy-focus.png`, nor does `media/icons/candy-focus.png` exist anywhere in the repo (`ls media/icons/candy-focus.png` → no such file). The `onerror="this.style.display='none'"` fallback hides the broken image, but the icon asset itself is genuinely missing — this is an "Adding a lib" checklist item (`media/icons/<slug>.png`) that appears to have been skipped for candy-focus.
- `docs/MATCHUPS.md:50` documents candy-focus correctly (🟢, dependency-free focus ring, no 1:1 upstream, inspired by bubbles + sugar-dash's FocusManager) — no gap there.

---

## candy-forms

Audit of `/home/sites/sugarcraft/candy-forms` (foundation lib for `sugar-bits`/`candy-shell`/`sugar-prompt`). 831 tests passing per most recent commit message; recent activity is active bug-fixing (`3171da23` refocus-on-blocked-submit, `56d3d6f9` vim text objects, `43201eee` ESC-stripping via `RenderSafe`).

### 1. Missing or incomplete functionality vs upstream scope

- **`Field\Select` has no validator hook at all.** `getError()` unconditionally returns `null` and `revalidate()` is a no-op (`src/Field/Select.php:376-377`). There is no `withValidator()` method on the class. Every other interactive field (`Input`, `Text`, `Confirm`, `MultiSelect`) supports at least some error surface. Upstream `huh.Select` supports `.Validate()`; this port dropped it.
- **`Field\FilePicker` has no validator hook either.** `getError()` returns `null`, `revalidate()` is a no-op (`src/Field/FilePicker.php:93-94`), and there is no `withValidator()`. A form author cannot express "a file must be chosen" (Required-style) or run a custom check on the selected path — only the picker's own `withAllowedExtensions()`/`withDirAllowed()`/`withFileAllowed()` filters exist.
- **`Field\Input`/`Field\Text` validators ignore `TextInput\ValidateOn` entirely.** `TextInput` has a full `ValidateOn::{None,Blur,Change,Submit}` enum with real gating logic (`src/TextInput/TextInput.php:456,689-690,872,926-929`), but that gating only applies to `TextInput`'s own internal `$validate` closure. `Field\Input::update()` unconditionally calls `$this->validate()` on every message (`src/Field/Input.php:393`), which runs the full `withValidator()` chain (Required/Email/Pattern/etc.) on every keystroke regardless of any `ValidateOn` setting — there is no `Field\Input::withValidateOn()` at all (confirmed absent via grep). So the field-level validator chain can never be deferred to blur/submit; only the lower-level `TextInput` validator (rarely used directly by form authors) respects the enum. This is a functionality gap and a naming trap: a developer setting `ValidateOn::Submit` and expecting `Input::withValidator(new Required())` to defer will be surprised.
- **`Confirm::withValidator()` only accepts a bare `\Closure`** (`src/Field/Confirm.php:59`), unlike `Field\Input::withValidator(Validator|\Closure $validator)` (`src/Field/Input.php:284`). API is inconsistent across field types — a `Validator` instance (e.g. `new Required()`) cannot be passed to `Confirm`.
- **`MultiSelect` only exposes `withMin()`/`withMax()`** (`src/Field/MultiSelect.php:72,75`) constraints, no general `withValidator(callable)` escape hatch the way `Input`/`Text` have via `withValidation()`.

### 2. Performance concerns

- Because `Field\Input`/`Field\Text` validators run unconditionally on every `update()` (see §1), any regex-based validator (`Validator\Pattern`, `Validator\Email`'s `filter_var` internally does its own regex-like scanning) re-executes on **every keystroke** with no debounce and no `ValidateOn::Submit`/`Blur` opt-out at the field level. Combined with no default `charLimit` (see §5), a pasted/typed large string re-runs the full validator chain, `mb_strlen`, and any user-supplied `Pattern` regex on every single character insert.
- `Form::view()` (and each `Field::view()`) rebuilds the full multi-line string from scratch on every render call — consistent with the rest of the ecosystem where diffing happens at the `candy-core` `Program`/renderer layer, so this alone isn't a candy-forms-specific defect, but it does mean expensive per-field `view()` work (e.g. `FuzzyMatcher` scoring in `Select`/`MultiSelect` filter mode) re-runs every render, not just on filter-text change.

### 3. Test coverage gaps

- **`Form::accessible()`/`accessibleView()` has zero dedicated tests.** `grep -rn "accessible" tests/` only turns up an unrelated hit in `ItemList/ItemListTest.php:135` (comment mentioning "accessible via filterValue()"). The `accessible` flag and `accessibleView()` method (`src/Form.php:42,96,128,227,404-405,438`) are completely unexercised.
- **No test exercises `Field\Input::withValidator()` with an actual `Validator` instance** (`Required`/`Email`/`Pattern`/`MaxLength`/`MinLength`). `tests/Field/InputValidationTest.php` and `tests/Field/TextTest.php` only pass raw closures; `tests/Field/InputTest.php:42` uses a closure too. The `Validator` implementations each have their own unit test (`tests/Validator/*Test.php`) testing `validate()` in isolation, but the `Validator|\Closure` union branch inside `Field\Input::withValidator()` (`src/Field/Input.php:284-296`, the `match` wrapping a `Validator` instance) is never hit by any test — an integration regression there (e.g. breaking the `$validator instanceof Validator` branch) would not be caught.
- **No test for `Field\Select::getError()`/`revalidate()` no-op behavior** — not a bug, but worth a regression test pinning the "Select never errors" contract given it stands out from every other field.
- **No test for `Field\FilePicker::getError()`/`revalidate()` no-op behavior**, same reasoning.
- **`Confirm::withValidator()`'s closure-only signature** has no test asserting it rejects (or silently mishandles) a `Validator` instance — the inconsistency noted in §1 is untested either way.

### 4. Missing .vhs/*.tape demo files

- `candy-forms/.vhs/` does not exist (`find candy-forms/.vhs` produced no output) and `candy-forms` does not appear anywhere in `.github/workflows/vhs.yml`'s hand-maintained `all=(...)` matrix (`grep -n candy-forms .github/workflows/vhs.yml` — no hits). Per `AGENTS.md`, non-visual libs are meant to be the exemption (`candy-pty`, `candy-testing`, FFI, codecs); candy-forms is a heavily visual, highly interactive TUI form library and its total absence from the VHS matrix looks like an oversight rather than an intentional exemption, especially now that `Spinner` was absorbed into this lib (README §"Shared foundations"). There is also no `examples/` directory (`find . -iname "*.tape"` and `ls examples` both empty), so there's nothing for a tape to drive even if one were added.

### 5. Security concerns

- **No default `charLimit`.** `TextInput::charLimit` and `TextArea::charLimit` both default to `0` (unlimited) (`src/TextInput/TextInput.php:91`, `src/TextArea/TextArea.php:82`), and `Field\Input`/`Field\Text` don't impose one either — it's purely opt-in via `withCharLimit()`/`maxlength()`. Given the memory note that candy-forms input feeds downstream systems like `candy-query`, an unbounded default means any form built without explicitly adding `MaxLength`/`charLimit` accepts arbitrarily long pasted/typed input, which then flows through the validator chain (see perf note above) and potentially into a DB layer with no length guard from this library's side.
- **Inconsistent `RenderSafe::clean()` (ESC-stripping) coverage.** Commit `43201eee` ("strip bare ESC in render paths via RenderSafe::clean()") only touched `src/FilePicker/FilePicker.php`, `src/Field/MultiSelect.php`, and `src/Field/Confirm.php` (confirmed via `grep -rln "RenderSafe::clean" src/`). `src/Field/Input.php`, `src/Field/Text.php`, `src/Field/Select.php`, `src/Field/Note.php`, `src/Field/FilePicker.php` (the field wrapper, as opposed to the widget), `src/TextInput/TextInput.php`, `src/TextArea/TextArea.php`, and `src/HasDynamicLabels.php` (`resolveTitle()`/`resolveDescription()`, `src/HasDynamicLabels.php:56,64`) do **not** run field titles/descriptions/option labels through `RenderSafe::clean()`. Since `HasDynamicLabels` explicitly supports computing titles/descriptions from live form values (`*Func` closures per `Field.php`'s doc-comment), and `Select`'s option strings can originate from user/DB data, a raw ESC byte embedded in a dynamically-computed title, a Select option, or a Note body is not sanitized before being written into the ANSI stream — this is exactly the terminal-injection class of bug the recent commit set out to close, but the fix wasn't applied uniformly across all render paths.
- `Validator\Pattern` accepts an arbitrary caller-supplied PCRE pattern with no length/complexity guard (`src/Validator/Pattern.php`). Combined with the always-on-every-keystroke execution noted in §1/§2, a form author who wires a pathological regex (accidentally or via a config-driven pattern) creates a ReDoS-shaped hang on typical typing, not just on submit.

### 6. Cross-lib duplication / placement

- README (`candy-forms/README.md:80-109`) documents that `Spinner` was absorbed from `sugar-bits` and that `sugar-bits`/`sugar-prompt` now re-export via `class_alias` shims — this pattern is consistent and well-documented; `CALIBER_LEARNINGS.md` §"class_alias shim pattern (canonical)" backs it up. No new duplication was found between candy-forms and sugar-bits/candy-shell/candy-focus during this pass — `VimKeyHandler` is correctly centralized here and delegated to by sugar-prompt/sugar-bits/sugar-readline per `CALIBER_LEARNINGS.md`. Worth flagging only as a watch-item: `candy-focus` (a separate foundation lib per `CLAUDE.md`'s layout) was not cross-checked against candy-forms' own focus-management logic inside `Form::update()`/`advance()` (`src/Form.php`) — if `candy-focus` provides a generic focus-ring primitive, `Form`'s bespoke `focusedIndex`/`advance()`/blur-then-focus logic (the subject of the `3171da23` fix) might be reinventing it. Not confirmed either way in this pass; recommend a follow-up diff against `candy-focus/src/`.

### 7. Documentation gaps

- README's "Role" section (`README.md:16-22`) lists `Validator\{Required,Email,MinLength,MaxLength,Pattern}` but does not mention that `Select`, `FilePicker`, and (with caveats) `Confirm` don't accept these `Validator` instances — a reader would reasonably assume `withValidator(new Required())` works uniformly across all field types per the Quickstart example (`README.md:33-47`, which only demonstrates `Input`).
- No README section documents `ValidateOn` at all — `TextInput\ValidateOn::{None,Blur,Change,Submit}` is a real, tested feature (`tests/TextInput/ValidateOnTest.php`) but isn't mentioned in the README's Quickstart or "Role" sections, and given §1's finding that it doesn't propagate to `Field\Input`'s validator chain, its absence from the docs means there's no place warning callers about that gap.
- No README mention of `Form::accessible()`/accessible rendering mode, despite it being a real public API surface (`src/Form.php:128,227`).
- `CALIBER_LEARNINGS.md` is not stale relative to major structural changes (Spinner absorption, class_alias shim pattern, VimKeyHandler) but has no entry at all for the `ValidateOn`/validator-chain-always-runs behavior or the FilePicker/Select validator gap — future sessions adding validators to these fields would benefit from a learning entry once fixed, to avoid re-discovering the inconsistency.

---

## candy-freeze

### 1. Missing or incomplete functionality vs upstream charmbracelet/freeze

- **No real syntax highlighting.** `LanguageDetector` (`src/LanguageDetector.php`) detects a language name, but nothing tokenizes source by grammar and applies per-token colors the way upstream's Chroma-based pipeline does. Highlighting only happens if the *caller* pre-colors the text with ANSI SGR codes (via `AnsiParser`). This is explicitly documented as the top gap in `docs/research/libraries/candy-freeze-research.md` ("Current Gap: candy-freeze parses ANSI SGR codes but has no language detection or grammar-based highlighting").
- **Detected language is dead code in the CLI.** `bin/candyfreeze` lines 144-148 call `LanguageDetector::detect($text)` and assign it to `$language`, but `$language` is never passed to `SvgRenderer`/`PngRenderer` or used for anything else — it's computed and discarded. The `-t`/`--type` flag (line 96-99) that lets a user force a language is equally unused downstream.
- **No PNG/WebP rasterization of SVG.** Upstream freeze's primary path is SVG→PNG via `rsvg-convert` or an embedded `resvg` WASM fallback, plus WebP output. candy-freeze's `PngRenderer` (`src/PngRenderer.php`) is a completely separate code path built on GD bitmap fonts (`imagestring()`), not an SVG rasterizer — it duplicates all the frame/window/shadow drawing logic instead of reusing `SvgRenderer`'s output, and it explicitly cannot render Unicode (`@warning` at PngRenderer.php:28-30). No WebP output exists at all.
- **No `--execute` / PTY-capture mode.** Upstream can run a shell command in a PTY and screenshot its ANSI output (`pty.go`). candy-freeze has no equivalent even though `candy-pty` exists in the monorepo and is already a `repositories[]` path-dep in `candy-freeze/composer.json` (unused for this purpose).
- **No config file support.** Upstream persists settings to `$XDG_CONFIG/freeze/user.json` and layers preset JSON (`base`/`full`) → user config → CLI flags. candy-freeze's CLI (`bin/candyfreeze`) only supports flags with no persistence/config file layer.
- **No margin support, and only single-value padding.** Upstream supports CSS-style 1/2/4-value padding *and* margin. `SvgRenderer`/`PngRenderer` `padding` is a single `int` (`withPadding(int $p)`); there is no margin concept distinct from the shadow margin.
- **No interactive TUI mode** (`freeze --interactive` via `huh` upstream). Not present here; `sugar-prompt` exists in the monorepo and would be the natural building block but isn't used.
- **No `--lines start,end` clipping of input**, only line-range *highlighting* (`withHighlight`). Upstream's `cut.go` slices the input to a line window before rendering; candy-freeze always renders the whole input.
- **Font embedding is SVG-only and TTF-only.** `SvgRenderer::withFont()` (SvgRenderer.php:82-88) embeds a raw TTF as base64 but hardcodes `$mime = 'font/ttf'` (line 152) even if a `.otf`/`.woff` path is passed — no format sniffing/validation of the font bytes themselves, and `PngRenderer` has no font-embedding equivalent (GD bitmap fonts only, per its class doc-comment).
- **`ChromaThemeLoader`/`VsCodeThemeLoader` only map a handful of token colors onto the 3 window-dot colors + line-number + fg/bg** (`TOKEN_MAP` in ChromaThemeLoader.php:23-38) — a very lossy compression of a full syntax theme (usually 15-30+ scopes) into ~8 `Theme` fields, since `Theme` itself has no concept of per-token-type colors. This is consistent with finding #1 (no real tokenization), but worth flagging separately since the loaders imply richer theme fidelity than the renderer can actually use.

### 2. Performance concerns

- `PngRenderer::render()` creates a full-size transparent image and a second same-size shadow image (`imagecreatetruecolor($totalW, $totalH)` twice, PngRenderer.php:129 and 146) purely to alpha-composite a solid drop-shadow rectangle; for large screenshots this doubles GD memory allocation for what could be a single `imagefilledrectangle` with alpha blending directly onto `$img`.
- `SvgRenderer::render()` builds the entire SVG document by repeated string concatenation (`$svg .= ...`) inside nested loops over lines × segments. For large inputs (the class's own doc-comment at SvgRenderer.php:112-114 flags ">1MB SVG" as a known concern) this is O(n²)-ish due to PHP string reallocation; the doc-comment defers a streaming `renderToStream()` to "a future major version" — but see finding in section 6: an `OutputWriter` abstraction for exactly this purpose already exists in the codebase and is unused.
- `PngRenderer` per-glyph draws via `imagestring()` one call per *segment* (not per character), which is fine, but color allocation is cached per-image via a `\WeakMap` (`PngRenderer.php:46, 240-262`) — reasonable, though GD's palette-based `imagecolorallocate()` silently returns a previously-allocated *approximate* color once the 256-color true-color-adjacent palette fills up in some GD builds; no test exercises many distinct colors in one render to catch this.
- No caching of parsed `AnsiParser::parse()` results across repeated renders of the same renderer with different themes (e.g. the CLI's own `examples/freeze_to_png.php` re-renders the same code 5×, re-parsing ANSI/segments from scratch each time) — minor, but noted since `AnsiParser` instantiates a new `SgrState`/anonymous `Handler` class on every call (AnsiParser.php:42-59), which is non-trivial closure/object setup per line, not per document.

### 3. Test coverage gaps

- `src/OutputWriter.php`, `src/FileOutputWriter.php`, `src/StringOutputWriter.php` have **zero tests** — no `OutputWriterTest.php`/`FileOutputWriterTest.php`/`StringOutputWriterTest.php` exists in `tests/`, and (per section 6) these classes aren't even called from production code, so there is no indirect coverage either.
- `src/LayoutCalculator.php` — `LayoutCalculator::calculate()` has no dedicated test file; it's only exercised indirectly through `SvgRendererTest`/`PngRendererTest` assertions on final SVG/PNG dimensions, so edge cases (e.g. `lineNumbers=true` with 100+ lines changing gutter width via `strlen(count($lines))`, or `shadow=false` + `window=false` zeroing both margins) are not directly asserted against the returned tuple.
- `src/WindowChromeGeometry.php` — no dedicated `WindowChromeGeometryTest.php`; geometry values (`macos()`, `iterm2()`, `hyper()`, `windowsTerminal()`) are only checked transitively through rendered SVG/PNG byte content in `WindowStyleTest.php`, not asserted directly against the geometry object's fields.
- `src/SgrState.php` — no dedicated test; only exercised as a side effect of `AnsiParserTest`.
- `src/Theme.php` — the base `Theme` constructor and its `dark()/light()/dracula()/tokyoNight()/nord()` factories have no dedicated `ThemeTest.php`; coverage is indirect via `SvgRendererTest::testThemePresetsAppearInOutput()` (SvgRendererTest.php:58) and `PngRendererTest::testThemePresetsProduceDifferentOutput()`, which check that *some* theme-specific string/pixel appears, not that every theme property (fontFamily/fontSize/lineHeight/windowStyle default) round-trips correctly.
- `bin/candyfreeze` — `CliTest.php` covers help/stdin/file-input/unknown-flag/unknown-theme/output-to-file/write-failure/window-style-none/ligatures, but does **not** cover: `--padding`, `--no-shadow`, `--no-border`, `--line-numbers`, `--border-radius`, `--highlight` (valid and malformed formats), `--font` (missing-file exit-2 path), `--format png` (including the `gd_required` exit-2 branch when `ext-gd` is unavailable), the symlink/path-outside-cwd guard (lines 127-134), or `-t`/`--type`.
- `AnsiParser::xterm256ToHex()` is public but only exercised indirectly through `AnsiParserTest` SGR-sequence tests (e.g. `testXterm256Greyscale`); there's no direct unit test calling it standalone across its three branches (`<16`, `>=232` greyscale ramp, and the 6×6×6 cube math), so a regression in the cube-index arithmetic (AnsiParser.php:205-212) wouldn't be caught with a minimal, targeted test.

### 4. Missing .vhs/*.tape demo files

- `.vhs/screenshot.tape` and `.vhs/ansi-input.tape` exist and are demonstrating `SvgRenderer` code-screenshot and ANSI-input flows respectively — good coverage for the primary SVG path.
- No tape demonstrates: `--format png` output, `WindowStyle` variants other than default macOS (windows-terminal/iterm/hyper/none), `--line-numbers`, `--highlight`, `--font` embedding, or `--border-radius`/`--no-shadow`/`--no-border` combinations — all of which are real CLI flags with no visual demo.

### 5. Security concerns

- **Path-traversal / arbitrary local file read via `--font` and positional input path.** `bin/candyfreeze` accepts an arbitrary file path for the input file (line 105-106) and for `--font` (line 69-75) with only an existence check (`file_exists`), no confinement to a working directory or allow-list. If this CLI is ever wrapped by a web service or run over untrusted input (as many "code screenshot" tools eventually are), a caller could read arbitrary files the process has permission to (e.g. `/etc/passwd`, other users' files) and embed their bytes into the output SVG (font path is base64-embedded raw — `SvgRenderer.php:149-156` — so arbitrary file contents readable by the process would be exfiltrated into the output artifact if `--font` accepted a non-font file, since there is no validation that the file is actually a font).
- **The existing symlink guard is incomplete.** `bin/candyfreeze` lines 127-134 only block a **symlink** input path that resolves outside CWD (`is_link($inputPath)` check) — a *non-symlink* absolute or `../../` relative path is not checked at all, so `candyfreeze /etc/passwd` or `candyfreeze ../../secret.txt` reads the file with no restriction. The guard's narrow trigger condition (symlink-only) gives a false sense of protection.
- **`--output`/`-o` has no path validation either** (line 88, used at line 196-200) — same arbitrary-write concern mirrored in the read path: a caller-controlled output path can overwrite any file the process can write, with no confinement.
- **`ChromaThemeLoader::load()` / `VsCodeThemeLoader` read arbitrary theme JSON files via `file_get_contents($path, false, null, 0, 1_000_000)`** (ChromaThemeLoader.php:51) — the 1MB cap bounds memory but the path itself is not validated/confined, same class of issue as above if theme paths become user-controlled in a service context.
- None of these are exploitable in the intended "developer runs this locally" use case, but the CLI's error/security posture assumes a trusted local user; nothing in the README or CALIBER_LEARNINGS documents this trust boundary, so a future contributor wiring this into e.g. a web-facing "paste code, get a screenshot" service could unknowingly reintroduce classic LFI/arbitrary-write bugs.

### 6. Functionality duplicated/missing vs other libs

- **`OutputWriter`/`FileOutputWriter`/`StringOutputWriter` (`src/OutputWriter.php`, `src/FileOutputWriter.php`, `src/StringOutputWriter.php`) are dead code.** They implement a streaming-output abstraction ("Enables streaming output for large screenshots without buffering the entire rendered result in memory" — OutputWriter.php:9-11) but are never referenced from `SvgRenderer`, `PngRenderer`, `bin/candyfreeze`, or any example — confirmed via `grep -rn "OutputWriter" --include=*.php .` outside `src/` and `vendor/composer/autoload*.php` (autoload metadata only). Both renderers' doc-comments (SvgRenderer.php:112-114, PngRenderer.php:97-98) say streaming is deferred to "a future major version" even though the streaming interface already exists, unused, in the same directory. Either wire these into the renderers (candidate: an SvgRenderer::renderToStream(OutputWriter $out) overload) or remove them as speculative/orphaned API surface — as-is they add untested public API with no callers.
- **PNG rasterization duplicates `SvgRenderer`'s frame/window/shadow layout logic wholesale** instead of rendering SVG then rasterizing (which is upstream's actual architecture, via `rsvg-convert`/`resvg`). Both `SvgRenderer` and `PngRenderer` independently implement `buildMacosWindow`/`buildWindowsTerminalWindow`/`buildITerm2Window`/`buildHyperWindow` (they do share `WindowChromeGeometry` for the numeric geometry, which is good), and independently reimplement highlight-range and line-number logic. A bug fixed in one frame-drawing path (e.g. the highlight-range feature, which `PngRenderer` doesn't even support — no `withHighlight()` on `PngRenderer` at all) silently doesn't propagate to the other.
- `PngRenderer` has no `withHighlight()`/line-highlighting, `withLigatures()`, or `withFont()` — feature parity with `SvgRenderer` is incomplete (confirmed by diffing the two classes' public `with*()` methods: PngRenderer.php:76-87 vs SvgRenderer.php:62-88 — missing `withLigatures`, `withFont`, `withHighlight`).
- `candy-shine` is repo-mapped in `docs/repo_map/charmbracelet_freeze.md` (line 159) as the natural home for "SVG manipulation" (shadow/clip/corner/positioning primitives) but candy-freeze reimplements all of that SVG DOM-building inline as string concatenation in `SvgRenderer::render()` rather than delegating to `candy-shine` — worth a cross-lib check on whether `candy-shine` actually offers reusable SVG primitives that would de-duplicate `SvgRenderer`'s ad-hoc `<rect>`/`<filter>`/`<circle>` string building.
- `candy-pty` is declared as a path-repo dependency in `candy-freeze/composer.json` `repositories[]` but is not `require`d and not referenced anywhere in `src/` or `bin/` — it appears to be scaffolding left over for the (currently unimplemented) `--execute`/PTY-capture feature. Either implement that feature or drop the unused repository entry.

### 7. Documentation gaps

- **README.md never documents `--format png` / PNG output at all** — the entire README (`candy-freeze/README.md`) markets the library as "No `ext-gd` / Imagick required" (line 17) and only shows SVG examples; `PngRenderer` (373 lines, fully tested) is completely unmentioned in the README despite being a first-class public class with its own theme presets and CLI `--format png` flag.
- **README doesn't document `--window-style`, `--highlight`, `--font`, or `-t`/`--type`** CLI flags, all of which exist in `bin/candyfreeze` (lines 59-99) — the "Flags" list (README.md:35-43) stops at `--border-radius` and `-o`/`--output`.
- **README doesn't document `WindowStyle` enum options** (`macos`/`windows-terminal`/`iterm`/`hyper`/`none`) at the library level — only the CLI usage example uses a theme flag; no `withWindowStyle()` code example exists despite `WindowStyleTest.php` covering all 5 variants.
- **README doesn't mention `ChromaThemeLoader`/`VsCodeThemeLoader`** at all, despite both having ~150-200 lines of tested implementation (`src/Theme/ChromaThemeLoader.php`, `src/Theme/VsCodeThemeLoader.php` + their Test files) — a user reading the README would have no idea custom theme-file loading exists.
- **README's language-detection example doesn't explain that detected language currently has no effect on rendering** (see section 1) — as written, a reader would reasonably assume `LanguageDetector::detect()` feeds into syntax highlighting in `SvgRenderer`, which it does not; this should be called out explicitly to avoid a misleading impression of feature completeness.
- **CALIBER_LEARNINGS.md is stale relative to current `src/`.** It documents only 4 narrow implementation patterns (per-segment-bg-rect, sgr-bg-48, language-detector-priority-chain, segment-immutable-withbg) plus one `Lang` note, all pertaining to features added early. It has no entries for: `WindowStyle`/window-chrome-variants (WindowChromeGeometry.php, ~4 window styles across 2 renderers), `ChromaThemeLoader`/`VsCodeThemeLoader` (2 whole files + a normalize-hex convention), font embedding (`withFont`), line highlighting (`withHighlight`), ligatures (`withLigatures`), or the CLI (`bin/candyfreeze`) itself — i.e., roughly the newer half of the library's surface area is undocumented in the learnings file that's supposed to capture exactly this kind of pattern.
- `examples/` directory (`screenshot.php`, `freeze_to_png.php`) is not referenced from the README at all — the README's own "Demos" section only shows VHS gifs, not a pointer to the runnable example scripts that would let a new contributor explore the API interactively.

---

## candy-fuzzy

Audit of `/home/sites/sugarcraft/candy-fuzzy` (97 tests / 4220 assertions, all green via `composer install && vendor/bin/phpunit`). Files reviewed: `src/FuzzyMatcher.php`, `src/Highlighter.php`, `src/MatchResult.php`, `src/MatchResultSorter.php`, `src/Matcher/{FuzzyMatcherFactory,SahilmMatcher,SmithWatermanMatcher}.php`, all of `tests/`, `README.md`, `CALIBER_LEARNINGS.md`, `composer.json`.

### 1. Missing/incomplete functionality vs upstream scope

- **No configurable scoring for either matcher exposed at the top level.** `CALIBER_LEARNINGS.md` (lines 25-32) already flags this as a known pre-1.0 gap for `SahilmMatcher` (hardcoded `MATCH_SCORE`/`CONSECUTIVE_BONUS`/etc, `src/Matcher/SahilmMatcher.php:27-32`). It is *also* true of `SmithWatermanMatcher` (`src/Matcher/SmithWatermanMatcher.php:27-31`, all five tuning constants are `private const`). Notably, `candy-lister/src/FuzzyMatch.php:13-51` already built exactly this feature (a public `ScoringProfile` value object with `default()`/`strict()`/`lenient()` factories) for its own independent Smith-Waterman port — that capability was never folded back into `candy-fuzzy`, so the canonical library is now behind a duplicate.
- **`SahilmMatcher` has no case-fold/normalization option beyond binary case-sensitivity** — no NFC/NFD Unicode normalization, no diacritic-insensitive matching (e.g. `é` vs `e`), which upstream `sahilm/fuzzy` also lacks but is a common ask for filter UIs with international candidate lists.
- **No fuzzy "score-only" fast path.** Every call to `match()`/`matchAll()` always computes and returns matched indices (needed for highlighting), even when a caller only wants a ranking (e.g. simple sort). For `SmithWatermanMatcher` this means the full O(n·m) traceback matrix is always built (see §2) — there's no cheaper `score()`-only entry point comparable to the old `candy-forms`/`candy-lister` shims.
- `matchAllGenerator()` is declared on the `FuzzyMatcher` interface (`src/FuzzyMatcher.php:57`) and implemented by both matchers, but as written it defeats its own stated purpose: both implementations (`SmithWatermanMatcher.php:92-109`, `SahilmMatcher.php:109-126`) build the *entire* `$results` array eagerly before yielding anything, so there is no actual streaming/early-exit benefit over `matchAll()` — the doc-comment's claim of "memory efficiency for large candidate lists" is not true for the accumulation phase (only the final `foreach`/yield is lazy). A caller that only wants the first result still pays the full corpus scan cost, though at least memory for `$results` is not saved either since it's built as one array upfront.

### 2. Performance concerns

- **`SmithWatermanMatcher::compute()` allocates *two* full `(queryLen+1) × (candidateLen+1)` matrices** (`$matrix` and `$traceback`, `src/Matcher/SmithWatermanMatcher.php:133-136`) via `array_fill` of `array_fill`, i.e. real O(n·m) *memory*, not just time — this is PHP arrays of arrays (much heavier per-cell than a flat typed buffer). The class doc-comment self-flags this ("For queries or candidates exceeding ~1,000 characters, consider using SahilmMatcher") but nothing in the code enforces or even warns at runtime — there is no length guard, so a caller can silently pay multi-hundred-MB costs.
- **No caps on query/candidate length anywhere.** Neither `match()` nor `matchAll()` in either matcher rejects or truncates oversized input. This is directly relevant to §5 (DoS) since fuzzy matchers are typically wired to live keystroke input over externally-sourced candidate lists (filenames, DB rows, etc).
- **No memoization/caching across repeated calls with the same haystack list.** Every `matchAll()` invocation rescans the *entire* candidate iterable from scratch (`src/Matcher/SmithWatermanMatcher.php:69-75`, `SahilmMatcher.php:86-92`) — there is no incremental-filter mode (e.g. re-narrowing an already-filtered subset as the user types more characters, the way `fzf`/`gum filter` and other real-time TUI fuzzy-finders do to keep p99 keystroke latency low on large lists). For a foundation lib meant to back live TUI filter widgets, this is a first-order scalability gap once candidate lists reach into the thousands+ and users type quickly.
- **`PerformanceTest.php` thresholds are loose tripwires only** (`< 2.0s` for ~2000-char strings / ~2000 candidates, `tests/PerformanceTest.php:50,74,102,129`) — good regression coverage for the *previous* mb_substr hot-loop bug, but they don't exercise the two-matrix-allocation cost at real "large list" scale (e.g. 50k+ candidates, which is realistic for file-tree or history-based fuzzy filters), nor do they assert anything about peak memory.
- `MatchResultSorter::sortAndSlice()` deliberately full-sorts before slicing (`src/MatchResultSorter.php:43-52`, comment acknowledges no heap/partial-sort) — reasonable per the comment for typical TUI list sizes, but combined with the lack of incremental filtering above, this compounds the per-keystroke cost on large corpora (full O(n log n) sort of *all* matches every keystroke, even when only top-K is ever displayed).

### 3. Test coverage gaps

- **`FuzzyMatcherFactory::create()` has zero tests.** No test file references `FuzzyMatcherFactory` anywhere in `tests/` — neither the two valid branches (`'smith-waterman'`, `'sahilm'`) nor the `\InvalidArgumentException` default-arm (`src/Matcher/FuzzyMatcherFactory.php:21-28`) are exercised.
- **`matchAllGenerator()` is untested on both matchers.** `grep` for `matchAllGenerator` across `tests/` returns nothing — this public interface method (declared in `FuzzyMatcher.php:57` and implemented twice) has no coverage at all, despite being part of the public contract.
- **`MatchResultSorter` has no dedicated test file.** Its two public static methods (`sort()`, `sortAndSlice()`) are only exercised indirectly through matcher-level `matchAll()` assertions (e.g. `SmithWatermanMatcherTest::testMatchAllTiebreakByCandidateAscending`) — there's no direct unit test asserting the tiebreak contract, negative/zero `$limit` handling, or empty-array input in isolation.
- **Highlighter has no adversarial/edge-case coverage for out-of-range or negative indices.** `Highlighter::highlight()` normalizes unsorted/duplicate indices (tested, `tests/HighlighterTest.php:103-115`) but there's no test for indices that exceed `mb_strlen($haystack)` or negative indices — since `MatchResult` is "publicly constructible" per the class's own doc-comment (`src/Highlighter.php:31`), a hostile/buggy caller could pass out-of-bounds indices and `mb_substr` would silently clamp/no-op rather than throw, which is untested behavior.
- **No very-long-string edge case in the "correctness" test suites** (only in `PerformanceTest.php`, which asserts timing/non-emptiness, not exact scores/indices) — e.g. no characterization test pinning exact score/indices for a several-hundred-character candidate.
- **No test for `matchAll()`/`matchAllGenerator()` with a `Traversable`/generator as the `iterable<string>` argument** — all tests pass plain arrays; the type-hint advertises generator/iterator support but nothing exercises it.
- `MatchResult`'s own constructor accepts non-ascending / duplicate `matchedIndices` (used deliberately by `HighlighterTest`), but `MatchResultTest.php` never tests that scenario at the `MatchResult` level itself (e.g. `indices()` simply echoing back whatever was passed, unsorted).

### 4. Missing .vhs/*.tape demo files

- `candy-fuzzy` has no `.vhs/` directory and is absent from `.github/workflows/vhs.yml`'s hand-maintained `all=(...)` matrix. Per `AGENTS.md`/`CLAUDE.md` this is expected and correct — candy-fuzzy is a pure algorithm library with no terminal rendering surface, matching the stated exemption for "non-visual libs." Not a gap.

### 5. Security concerns

- **Algorithmic-complexity DoS via `SmithWatermanMatcher`.** As noted in §2, `compute()` is O(n·m) in both time and memory with no length cap. If query and/or candidate strings are attacker-influenced (e.g. a search box value, or an externally-sourced candidate list such as filenames/DB content), an attacker can submit a long string (or many long strings via `matchAll()`) to force large CPU/memory consumption per request — classic ReDoS-style resource exhaustion, just via a hand-rolled DP matrix rather than a regex engine. No regex is used anywhere in this library (`grep` confirms), so there is no literal ReDoS risk, but the unbounded matrix allocation is the equivalent hazard for this codebase.
- **No input-size validation/truncation in either matcher** for `query` or `candidate` — same root cause, applies to `SahilmMatcher` too (its per-character loop is only O(n+m), much cheaper, but `matchAll()` still has no cap on iterable size or per-item length before doing the work).
- The `README.md`'s own "Security note" (lines 86-88) correctly assigns ANSI/control-byte sanitization responsibility to the TUI render layer rather than `Highlighter` — that boundary is honest and consistent with what the code does (Highlighter forwards raw substrings verbatim, confirmed in `src/Highlighter.php:94-98`). No issue there, just noting the documented boundary is accurate.

### 6. Duplication vs candy-forms / candy-lister

This is the most significant finding. `README.md:82-84` states:

> The existing `SugarCraft\Forms\Fuzzy\FuzzyMatcher` and `SugarCraft\Lister\FuzzyMatch` classes remain as deprecated shims that delegate to `SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher`. Consumers will migrate in subsequent steps.

This is **not accurate for either class**, and the extraction described in the README's "Role" section was never actually completed:

- **`candy-forms/src/Fuzzy/FuzzyMatcher.php`** is marked `@deprecated ... use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher` (line 10) but its `score()`/`match()` methods (lines 30-115) are a full independent re-implementation of the two-row Smith-Waterman DP — it does **not** call into `candy-fuzzy` at all. It's also byte-level (`strtolower`/`strlen`, not `mb_*`), so it's not even UTF-8 safe unlike the new `SmithWatermanMatcher`.
- **`candy-lister/src/FuzzyMatch.php`** doesn't even carry a `@deprecated` annotation, has zero reference to `candy-fuzzy`, and `candy-lister/composer.json` has no `sugarcraft/candy-fuzzy` requirement (confirmed absent from `composer.json`; candy-fuzzy is required by `sugar-stash`, `sugar-prompt`, `sugar-stickers`, `sugar-wishlist`, `sugar-readline`, `candy-query`, `sugar-skate`, `sugar-bits`, `candy-hermit`, `candy-shell`, `sugar-glow`, `candy-forms`, `sugar-crush`, and root — but **not** `candy-lister`). Worse, `candy-lister/src/FuzzyMatch.php` has *outgrown* candy-fuzzy: it ships a public `ScoringProfile` (lines 13-51 — `default()`/`strict()`/`lenient()` tunable presets) that is exactly the "Future Enhancement" `CALIBER_LEARNINGS.md` says candy-fuzzy still needs (lines 25-32). The duplication has drifted to the point where the "wrong" library has the more complete feature set.
- Net effect: there are now **three** independent Smith-Waterman fuzzy-match implementations in the monorepo (`candy-fuzzy`, `candy-forms` shim, `candy-lister`), only one of which (`candy-fuzzy`) exposes matched-indices for highlighting, and the README's claim of a completed shim/delegation migration is stale/incorrect and should either be fixed (finish the actual delegation) or the README corrected to describe the real state.
- Other consumers grep-confirmed as *not* duplicating logic, just consuming the interface as intended: `sugar-prompt/src/Fuzzy/FuzzyMatcher.php` (separate — worth a quick look to confirm it's not another independent port, not verified here), `sugar-skate/src/Store.php`, `sugar-wishlist/src/Picker.php`, `sugar-stash/src/StashManager.php`, `candy-hermit/src/Hermit.php`, `candy-shell/src/Model/FilterModel.php`, `candy-forms/src/Field/Select.php` — these were not deep-audited as part of this pass but showed up in the `SugarCraft\Fuzzy` namespace grep and may warrant their own check for whether they're using `candy-fuzzy`'s real matchers or hand-rolled logic. **`sugar-prompt/src/Fuzzy/FuzzyMatcher.php` in particular has its own `Fuzzy` subdirectory** (mirroring the `candy-forms` naming) and should be checked — likely a fourth duplicate implementation.

### 7. Documentation gaps

- **README.md's "Backward Compatibility" section (lines 82-88) is factually wrong** per §6 above — neither shim class delegates to `candy-fuzzy`. This should either be fixed in code (make the shims real delegating wrappers) or the README corrected to say the migration has not yet happened.
- **CALIBER_LEARNINGS.md's "Future Enhancements (Pre-1.0)" section (lines 25-32) is stale** in the sense that the described enhancement (configurable scoring via a `*Config`/constructor-params approach) has already been *independently implemented* in `candy-lister` (as `ScoringProfile`) — the learnings file doesn't mention this, so a future contributor picking up "configurable scoring" as a task would not know a design already exists to mirror/port back.
- README's `## Algorithm Differences` table (lines 70-80) is a nice comparison but doesn't mention the O(n·m) memory cost tradeoff that the `SmithWatermanMatcher` class doc-comment does — worth surfacing in the README too since it's the primary place a consumer decides which matcher to use for a large-list use case.
- No README section documents `matchAllGenerator()` at all — the Quickstart only shows `match()` and `matchAll()`; a public interface method with no usage example and no test coverage (§3) is a documentation+test double-gap.
- No README section on `FuzzyMatcherFactory` — it's mentioned nowhere in the README despite being a public, autoloaded class; a consumer would have to read source to discover `FuzzyMatcherFactory::create('smith-waterman'|'sahilm')` exists.

---

## candy-hermit

Port of `Genekkion/theHermit` — fuzzy-finder/quick-fix overlay compositor (renders a filterable list window on top of an already-rendered background string; no terminal I/O of its own). Status in `docs/MATCHUPS.md:61` is 🟢. 123 tests / 247 assertions pass locally (`vendor/bin/phpunit`). No `check-path-repos.php` violations.

### 1. Missing/incomplete functionality vs upstream scope

- **`withBorder()`/`withStyle()` are inert — never applied to rendered output.** `src/Hermit.php:230-245` store a `candy-sprinkles\Border`/`Style` and `border()`/`style()` (`src/Hermit.php:464-472`) return them, and the README (`README.md:113`, `171-176`) advertises "Border & Style composition ... for window decoration" as a feature. But `buildOverlayLines()` (`src/Hermit.php:530-586`) and `compositeOver()`/`replaceSegment()` (`src/Hermit.php:848-895`) never reference `$this->border` or `$this->style` — no border runes are drawn around the overlay, no style is applied to its background/foreground. The existing test (`tests/HermitTest.php:242-251`) only round-trips the accessor (`assertSame($border, $h->border())`), never asserts the border/style shows up in `View()` output — confirming there is no rendering path to test. This is either a half-finished feature or dead API surface; either way it doesn't match its own docs.
- No visible "preview pane" / multi-column layout support that upstream `theHermit` quick-fix overlays sometimes offer (not confirmed against actual upstream source, but the composited single-column list + header + separator is the full extent here) — flagged as lower confidence since upstream repo wasn't fetched.

### 2. Performance concerns

- **`Hermit::View()` computes `buildOverlayLines($winWidth)` twice per call**, unconditionally (`src/Hermit.php:512` and `src/Hermit.php:520` — identical arguments). Every item goes through `itemFormatter`, and when a filter + `matchStyle` is set, through `highlightMatches()`/`highlightFuzzy()` (which instantiate a fresh ANSI `Parser` + anonymous `Handler` object per item via `printableText()`, `src/Hermit.php:736-785`). In a TUI re-rendering every keystroke this doubles the per-frame cost for no reason — the first call at line 512 is only used to get `count()`.
- Related: `View()` builds `$backgroundView = \implode("\n", $bgLines)` at `src/Hermit.php:517` when padding is needed, but `compositeOver()` (`src/Hermit.php:848`) never reads its `string $background` parameter — it operates purely on the `$bgLines` array. The re-`implode()` and the string argument passed at `src/Hermit.php:522` are dead work.
- `highlightMatches()` (`src/Hermit.php:791-824`) does per-character `mb_substr()` calls in a loop to rebuild the string rune-by-rune; for typical short item labels this is fine, but it's O(n) `mb_substr` calls each of which re-scans the string, i.e. effectively O(n²) for long item values. Not a hotspot at expected list-item lengths, but worth noting if items can contain long free text.

### 3. Test coverage gaps

- Border/Style are only tested for accessor round-trip, never for actual rendered effect (see #1) — because there is no rendered effect to test. Once border/style rendering is implemented, `HermitTest.php` needs `View()`-level assertions.
- No test exercises `View()` with both `helpBar` and `statusBar` attached simultaneously with a border/style also set — the combined line-count math in `buildOverlayLines()` (header + separator + items + helpBar + statusBar, `src/Hermit.php:536-583`) is only spot-tested per-component.
- No test asserts the double-`buildOverlayLines()` call is idempotent/pure (it happens to be, since it's a pure function of state, but nothing pins that behavior or would catch a future stateful regression there).
- `attachSigwinch()`/`ttySize()` fallback-to-80x24 path (`src/Hermit.php:318-329`, catch branch) isn't directly exercised — tests only check the "callback set" / "no callback" true/false return, not the actual size values delivered on a forced `Tty::size()` failure.

### 4. Missing .vhs/*.tape demos

None — `.vhs/basic.tape` and `.vhs/interaction.tape` both exist and match `examples/basic.php` and `examples/interaction.php`, with rendered `.gif`s present. No gap here.

### 5. Security concerns

- `FileHistory::__construct(string $path)` (`src/History/FileHistory.php:20-23`) takes a raw path with no validation — if `$path` is ever built from user-controlled input by a consumer, this is a path-traversal/arbitrary-file-write vector (`append()` does `fopen($this->path, 'a')` unconditionally). Not a bug in isolation (it's an explicit low-level file-path API, similar to other `FileHistory`-style classes elsewhere in the monorepo), but the README doesn't warn callers to sanitize/confine the path, and there's no `realpath()`/base-dir confinement option.
- `FileHistory::all()` uses `json_decode` with `JSON_THROW_ON_ERROR` inside a per-line `try/catch (\JsonException)` (`src/History/FileHistory.php:71-75`) — malformed lines are silently skipped, which is reasonable for corrupted history but means a truncated/partial write (e.g., crash mid-`fwrite`) is silently dropped rather than surfaced; acceptable for a history file, just noting no logging hook exists.

### 6. Functionality that belongs elsewhere or duplicates other libs

- `HelpBar` (`src/HelpBar.php`) and `StatusBar` (`src/StatusBar.php`) reimplement the same "key → description" / "segments + message" single-line bar concept that already exists independently in `sugar-dash/src/Components/StatusBar/StatusBar.php` and `sugar-crush/src/Tui/Components/StatusBar.php`. Each lib's version is self-contained (no shared base), so there are now at least three near-duplicate `StatusBar` implementations across the monorepo. Given `candy-hermit` requires `candy-sprinkles` already, a shared `StatusBar`/`HelpBar` primitive in `candy-sprinkles` (or a new small foundation lib) that each of these composes would remove the duplication — but this is a repo-wide observation, not unique to candy-hermit, and out of scope to fix unilaterally here.
- The `Concerns\Visible` trait (`src/Concerns/Visible.php`) is a generic show/hide/isVisible mixin with no Hermit-specific logic; likely duplicated in spirit (if not byte-for-byte) by similar visibility toggles in other bar/overlay components across the monorepo — worth checking during any future de-duplication pass.

### 7. Documentation gaps

- README's "Border & Style composition" bullet (`README.md:113`) and the `withBorder()`/`withStyle()` API section (`README.md:171-176`) describe a working decoration feature; per #1 above this is misleading since no rendering is wired up. Either the README overclaims or the feature is genuinely unfinished — should be reconciled.
- `CALIBER_LEARNINGS.md:13` ("[pattern:border-style-composition-sprinkles]") describes the same composition pattern as if it were complete/rendering, reinforcing the same gap — the learning entry itself needs correcting once border/style rendering lands (or a caveat added now that it's storage-only).
- README doesn't document `MAX_FILTER_LENGTH` (`src/Hermit.php:32`) or that `type()` silently no-ops past 256 chars (`src/Hermit.php:355-357`) — a caller typing past the cap gets no error/signal, and the README's `type()` API section (`README.md:200`) doesn't mention this ceiling.

---

## candy-input

### 1. Missing/incomplete functionality vs upstream scope

- **UTF-8 multi-byte input is corrupted, not decoded.** `decodeClean()` in `candy-input/src/EscapeDecoder.php:93-140` treats every non-control, non-DEL byte as one printable `KeyEvent` (`EscapeDecoder.php:134-136`, `$byte = $stream[0]`). Any multi-byte UTF-8 character (accented letters, CJK, emoji) is split into N separate garbled single-byte `KeyEvent`s instead of one character event. Verified live:
  ```
  decode("é") → 2 events: key='\xc3' (mangled), key='\xa9' (mangled)
  ```
  This is a functional gap vs. any real terminal input layer — non-ASCII typing/pasting outside bracketed-paste is silently broken. No UTF-8 continuation-byte lookahead exists anywhere in the file.
- **`EscapeDecoderOptions` is dead code.** `candy-input/src/EscapeDecoderOptions.php` declares `enableMouse`/`enableKitty`/`enableFocus`/`enablePaste` flags and is documented with a usage example (`new EscapeDecoder($options)`), but `EscapeDecoder` (`candy-input/src/EscapeDecoder.php`) has no constructor at all — the options class is never consumed. It is not referenced anywhere in `src/` or `tests/` (grep confirms zero hits). Either wire it in or remove it; right now it's misleading, unused API surface.
- **No legacy X10/URXVT mouse mode.** Only SGR 1006 (`CSI < …`) mouse is handled (`EscapeDecoder.php:236-331`). Older terminals/multiplexers that only emit X10 (`CSI M Cb Cx Cy`, single-byte coords) or URXVT (`CSI Cb;Cx;CyM`) mouse reports produce no `MouseEvent` at all — they fall through to the generic "unknown CSI" skip path. README (`candy-input/README.md:12`) only advertises SGR 1006, so this may be an accepted scope limit, but it's worth calling out explicitly as a documented non-goal rather than a silent gap.
- **Kitty protocol coverage is partial.** `handleKitty()` (`EscapeDecoder.php:339-368`) only matches the bare `Pm;Psu` form via `preg_match('/^(\d+);(\d+)u/', …)` (line 342). It does not handle: the full CSI-u extended form with a 3rd/4th parameter (`event-type`, `text-as-codepoint`, e.g. `CSI code:shifted:base;mods:event-type;text u`), repeat/press-vs-repeat disambiguation beyond the single release bit, or associated-text reporting. Any Kitty terminal in full "report all event types" mode sends the extended form, which this decoder cannot parse (falls through as incomplete → dropped, `EscapeDecoder.php:343-344`).

### 2. Performance concerns

- **Quadratic-time decode of long non-escape runs.** `decodeClean()` (`EscapeDecoder.php:93-140`) advances through `$stream` one byte at a time via `$stream = substr($stream, 1)` (line 136, also 123/131). Each `substr` call copies the remaining tail, so decoding N printable bytes in one call is O(N²). Measured directly:
  | bytes | time |
  |---|---|
  | 10,000 | 0.0096s |
  | 40,000 (4x) | 0.074s (~7.7x) |
  | 160,000 (16x) | 0.80s (~83x) |
  Superlinear scaling confirmed (not the ~16x a linear algorithm would show). `StreamInputDriver` bounds each `fread()` to 8192 bytes (`candy-input/src/Driver/StreamInputDriver.php:94`), which caps the damage for that driver, but `ReactInputDriver` (`candy-input/src/Driver/ReactInputDriver.php:118-133`) hands whatever chunk size the underlying ReadableStreamInterface delivers straight to `decode()` with no chunking/slicing — a fast producer or a single large buffered write can trigger multi-hundred-ms stalls on the event loop thread. Recommend an offset-index walk instead of repeated `substr`.
- **Regex-per-CSI-sequence** in `handleCsiKey()` (`EscapeDecoder.php:384`, `437`) and `handleKitty()` (`EscapeDecoder.php:342`) run `preg_match` on every CSI escape, which is standard for this style of parser and not by itself alarming — flagging only because it compounds with the substr-copy behavior above under high input rates (e.g. mouse-drag flooding many `CSI <` sequences per second).

### 3. Test coverage gaps

- **No test file for `ReactInputDriver`** (`candy-input/src/Driver/ReactInputDriver.php`) — `tests/` has no `ReactInputDriverTest.php`, and grep for `ReactInputDriver` across `tests/` returns nothing.
- **No test file for `SignalResizeDriver`** (`candy-input/src/Driver/SignalResizeDriver.php`) — SIGWINCH handling, `tput` fallback, and the "no pcntl" degrade path are all untested.
- **`EscapeDecoderOptions` has zero test coverage** (consistent with it being unused/dead — see §1).
- **No UTF-8 / non-ASCII decode test exists**, which is how the multi-byte corruption in §1 went unnoticed — `EscapeDecoderTest.php` (1059 lines, ~90 test methods) covers ASCII, control chars, CSI, SS3, Kitty, SGR mouse, focus, and bracketed paste exhaustively, but never feeds a multi-byte UTF-8 character through `decode()`.
- **No fuzz/property test despite the CALIBER_LEARNINGS.md claim.** `candy-input/CALIBER_LEARNINGS.md:12` asserts "Fuzz-friendly — every decoder path handles random byte sequences without throwing," but no fuzz harness or randomized-input test exists in `tests/EscapeDecoderTest.php` — the closest is `testRunawayCSI` (single fixed case, line 699) and `testInvalidUtf8MidSequence` (single fixed case, line 715). The learning describes an aspiration/manual finding, not an automated regression guard.

### 4. Missing .vhs demos

- `candy-input` has **no `.vhs/` directory at all** (confirmed via `find`). Per `AGENTS.md`, non-visual/FFI/codec libs are the stated exemption; `candy-input` decodes raw bytes to typed events with no rendered output of its own, so this is plausibly an intentional exemption similar to `candy-pty`/`candy-testing`. Flagging for confirmation since it's not on record anywhere in this lib's own docs (README/CALIBER_LEARNINGS.md say nothing about VHS exemption), unlike libs that explicitly note "non-visual, VHS-exempt."

### 5. Security concerns

- **Unbounded oversized-CSI buffering outside bracketed paste.** Bracketed paste has an explicit 1 MiB cap (`PasteEvent::MAX_SIZE`, `candy-input/src/Event/PasteEvent.php:20`, enforced in `handlePasteStream`/`finishPaste`, `EscapeDecoder.php:548-554`/`577-590`). But a non-paste "incomplete sequence" — e.g. an attacker streaming `\x1b[` followed by megabytes of digit bytes that never terminate with a final byte — is buffered without limit into `$this->remainder` (`EscapeDecoder.php:116`, `477`, `494`). Confirmed: a 200,000-digit unterminated CSI param string was accepted and buffered whole with no truncation or rejection. A hostile PTY peer (or a misbehaving nested program) can grow `$remainder` arbitrarily across repeated `decode()` calls, unbounded memory growth — the paste-size cap does not apply to this path.
- **The performance finding in §2 is also a light DoS vector**: any code path that hands `decode()` a large single buffer of plain bytes (see `ReactInputDriver`) burns CPU quadratically; an adversarial fast writer to the input stream can degrade the consuming process's responsiveness without ever tripping the paste-size guard (this isn't bracketed paste, so `PasteEvent::MAX_SIZE` doesn't apply).
- Otherwise the decoder is well-hardened for the cases it does test: malformed CSI params, truncated/split multi-call sequences, invalid trailing bytes, and the `testInvalidUtf8MidSequence` case all degrade gracefully to skip/buffer rather than throwing, consistent with the "fuzz-friendly" design goal.

### 6. Functionality duplicated with candy-ansi/candy-vt/candy-mouse

- **Naming collision, not logic duplication, with `candy-mouse`.** `candy-mouse/src/MouseEvent.php` defines its own `SugarCraft\Mouse\MouseEvent` (immutable, `x`/`y`/`button`/`MouseAction` enum, used for zone hit-testing against `Scanner`/`Zone`) alongside `candy-input`'s `SugarCraft\Input\Event\MouseEvent` (SGR-decoded, string-constant `action`). They serve different purposes (raw terminal decode vs. rendered-zone hit-testing) and don't share parsing code, but the identically-named class in two different namespaces is a real source of import confusion (`use SugarCraft\Mouse\MouseEvent;` vs `use SugarCraft\Input\Event\MouseEvent;`) — worth a doc note pointing each at the other, or a consuming-side adapter, since these two libs are likely to be wired together in practice (input decode → zone hit-test).
- **No decode-logic overlap found with `candy-ansi` or `candy-vt`.** `candy-ansi` (`candy-ansi/src/Parser/*.php`) implements an ANSI/VT state-machine parser but for *output* rendering-side interpretation of escape sequences generated by the app itself, not for classifying raw *input* bytes into key/mouse events — no shared code paths. `candy-vt/src/Handler/SgrHandler.php` etc. likewise operate on the terminal-emulation (output) side. No conflict found.
- **Suspicious unused path-repo dependencies.** `candy-input/composer.json:34-62` declares path repositories for `candy-ansi`, `candy-async`, and `candy-pty` (in addition to the actually-`require`d `candy-core`), but grep across `candy-input/src` shows **zero usage** of any `SugarCraft\Ansi\*`, `SugarCraft\Async\*`, or `SugarCraft\Pty\*` symbol. These repositories appear to be either leftover scaffolding from `check-path-repos.php --fix` over-inserting entries, or a signal that some intended integration (e.g. PTY-backed input, async cancellation) was planned but never implemented. Worth confirming with `tools/check-path-repos.php` whether they're required transitively by `candy-core`, or pruning them.

### 7. Documentation gaps

- **`EscapeDecoderOptions` is entirely undocumented in README.md** despite having its own doc-comment usage example inside the source file — a user reading the README has no way to discover the class exists, and if they find it and try to use it (`new EscapeDecoder($options)`), it will fail because no such constructor parameter exists (`EscapeDecoder.php` has no `__construct` at all). This is the same dead-code issue as §1/§3 but specifically a docs/API-surface mismatch.
- **README's protocol list (`candy-input/README.md:9-14`) doesn't disclose the UTF-8 limitation** from §1 — a consumer would reasonably assume "Plain ASCII keys" scope also covers ordinary Unicode text input, since most modern terminals send UTF-8 by default; the README should either state the ASCII-only limitation explicitly or the decoder should be fixed to assemble UTF-8 codepoints into single `KeyEvent`s.
- **No VHS/demo section or explicit non-visual exemption note** (see §4) — other CLAUDE.md/AGENTS.md-exempt libs like `candy-pty` state their exemption inline; `candy-input`'s README is silent on this.
- **`CALIBER_LEARNINGS.md` overstates test coverage** ("Fuzz-friendly — every decoder path handles random byte sequences without throwing") when no fuzz harness exists (see §3) — this could mislead a future contributor into skipping fuzz-test work believing it's already covered.

---

## candy-kit

Scope correction first: candy-kit's own `composer.json` (`candy-kit/composer.json:1-4`) and `docs/MATCHUPS.md:38` declare the upstream as **charmbracelet/fang** (opinionated CLI presentation chrome for Cobra apps), not charmbracelet/bubbles. Bubbles-style stateful widgets (spinner, progress, viewport, paginator, list, table, textarea) are intentionally out of scope here — those already live in `sugar-bits`, `candy-forms`, and `sugar-table`. The audit below is framed against fang's actual surface plus the extra widgets candy-kit has grown beyond fang (`Frame`, `Stage`, `Logo` — the last explicitly documented as mirroring ratatui, not fang: `candy-kit/src/Logo.php:13`).

### 1. Missing/incomplete functionality vs upstream (fang)

- No styled **error page** equivalent to fang's boxed root-command error output (fang wraps `cmd.Execute()` errors in a bordered red box with usage hint). `StatusLine::error()` (`src/StatusLine.php:25-28`) only gives a one-line glyph+message; there's no `ErrorPage`/`ErrorBox` helper.
- No **version command** styling helper (fang styles `--version` output distinctly from help).
- No **manpage / completion** styling hooks — acceptable since candy-kit is deliberately Symfony-Console-free (`README.md:22-23`), but this means "port of fang" is only partial; worth calling out explicitly in the README's Components section rather than leaving it implicit.
- `Theme::byName()` doc-comment (`src/Theme.php:205-206`) lists presets `'ansi','plain','charm','dracula','nord','catppuccin'` but omits `'auto'`, which the `match` arm actually supports (`src/Theme.php:222`) — docblock is stale.
- `Logo` is upstream-mismatched: it's the only class in the lib citing ratatui instead of fang (`src/Logo.php:13`), and isn't part of fang's scope at all — flag as a scope note rather than a gap.

### 2. Performance concerns

- `Banner::title()` keeps a **mutable static cache** (`self::$titleStyleCache`, `src/Banner.php:18,32-38`) — this is the only stateful/non-immutable construct in an otherwise strictly immutable, `readonly`-DTO codebase (AGENTS.md "Immutable + fluent" convention). It's correctness-safe today (the cached `Style` never depends on `$theme`), but it's global mutable state in a library that could run inside a long-lived worker (ReactPHP event loop, PHP-FPM opcache-persistent process) — a future edit that makes the cached style theme-dependent would silently leak stale styling across requests/ticks. Consider dropping the cache (border+padding Style construction is cheap) or scoping it correctly.
- `Stage::subStepWithProgress()`'s indeterminate-spinner branch (`src/Stage.php:109-112`) calls `microtime(false)` (string form `"0.xxxx sec"`) and multiplies the **string** by 10 directly: `(int) (microtime(false) * 10) % 10`. Confirmed via `php -r` that this throws `PHP Warning: A non-numeric value encountered` on every call, because arithmetic on the space-containing string only coerces the leading numeric substring. Under this lib's own `phpunit.xml` (`failOnWarning="true"`), this would fail the test suite the moment a test exercises this branch — and no test currently does (see §3). Fix: use `microtime(true)`.

### 3. Test coverage gaps

- **`Stage::subStepWithProgress()` with `$total = 0`** (the indeterminate-spinner code path, `src/Stage.php:108-112`) is never exercised by any test — `tests/GoldenRenderTest.php:210` and `tests/StageTest.php` only call it with `$total = 10`. This is the exact path with the `microtime(false)` bug above; adding a test would immediately surface the `failOnWarning` failure.
- **`Frame::render()` with `$rows` below `OVERHEAD` (6)** is untested. Every call site in `tests/FrameTest.php` uses `rows=24` or `rows=10`. The class's own doc-comment promises "always exactly `$rows` lines … for `$rows >= the frame overhead`" (`src/Frame.php:95-96`), but for `$rows < 6` the method still unconditionally emits all 6 overhead lines (`src/Frame.php:117-125`) — i.e. output silently exceeds the requested row count instead of clamping/throwing. Given Frame's whole raison d'être is preventing exactly this kind of TEA-renderer desync (`src/Frame.php:24-31`), this boundary case deserves either a test proving graceful behavior or a fix + regression test.
- `Theme` builder (`ThemeBuilder`, `src/Theme.php:233-278`) — happy path and one missing-field exception are tested (`tests/ThemeTest.php:114,132`), but only `success` is tested for the missing-field throw; the other five required fields (`error/warn/info/prompt/accent/muted`) have no corresponding assertion.
- `StatusLine::prompt()` is tested for plain theme only (`tests/StatusLineTest.php:35`); no golden/ANSI snapshot exists in `GoldenRenderTest.php` (compare with `success`/`error`/`warn`, which all have goldens at `tests/GoldenRenderTest.php:187-215` — `prompt` is the one status level missing a golden fixture).
- `Section::header()`/`subHeader()` with `$width = null` (the "no trailing fill" branches, `src/Section.php:38-39,95-97`) are not covered by `tests/SectionTest.php` (spot check: no `null` width call found).

### 4. Missing .vhs/*.tape demos per widget

- Only `.vhs/cli-page.tape` (+ its rendered `.vhs/cli-page.gif`) exists. The README's "Demos" section (`README.md:61-77`) advertises three more: `.vhs/logo.gif`, `.vhs/section.gif`, `.vhs/stage.gif` — **none of these tapes or GIFs exist** in `candy-kit/.vhs/`. These are broken image links in the published README/docs site. `examples/logo.php` exists and could back a `logo.tape`; there is no equivalent standalone example for `Section`/`Stage` to back the other two advertised demos.
- `Frame`, `Banner`, `StatusLine`, `HelpText`, `Theme` have no demo tape at all (arguably covered indirectly by `cli-page.tape` if `examples/cli-page.php` exercises them — worth confirming `examples/cli-page.php` actually calls all of these, otherwise they're advertised only via composer.json description, not any demo).

### 5. Security concerns

- No sanitization of caller-supplied strings against terminal-escape injection in most widgets: `StatusLine::format()` (`src/StatusLine.php:46-49`), `Stage::step()/subStep()` (`src/Stage.php:30-49,59-69`), `Section::header()/subHeader()` (`src/Section.php:25-44,83-101`), and `HelpText::render()/renderRows()` (`src/HelpText.php:27-68`) all interpolate `$message`/`$label`/description text directly into the returned ANSI string with no `Ansi`/control-character stripping. Contrast with `Frame`, which explicitly cares about this class of problem and routes all body/title/status text through `Width::truncateAnsi()` + a trailing `Ansi::reset()` (`src/Frame.php:136-194`) specifically to avoid ANSI bleed/desync. If any of these helpers are ever fed untrusted input (e.g., a filename, a network error message, a user-controlled commit message) rather than only compile-time literals, a crafted string containing cursor-move/OSC sequences could corrupt terminal state. Low severity for a CLI-presentation library (typical callers pass static/developer-authored strings), but worth a short caveat in each class's docblock ("caller is responsible for sanitizing untrusted input") since it's inconsistent within the same lib.

### 6. Functionality duplicated with sugar-bits/candy-forms/sugar-table

- **Theme duplication across the monorepo, with actual drift.** `candy-sprinkles/src/Theme.php:9-13` explicitly bills itself as "the canonical theme palette — the single source of truth for terminal colour schemes across SugarCraft consumer libs" and already ships `dracula()` (`candy-sprinkles/src/Theme.php:92-108`). `candy-kit/src/Theme.php:162-174` independently hand-rolls its *own* `dracula()` preset with different values for `accent` (`#bd93f9` in candy-kit vs `#ff79c6` in candy-sprinkles) and `warn`/`warning` (`#f1fa8c` yellow in candy-kit vs `#ffb86c` orange in candy-sprinkles) — same palette name, already-diverged hex values, maintained twice. `candy-forms/src/Theme.php` is a third independent theme type (different field set: `title/description/focusedTitle/...`) with its own `dracula()`/`catppuccin()` presets. None of these three reference or derive from candy-sprinkles' canonical palette, defeating its stated purpose. This is worth flagging as a cross-cutting item (not candy-kit-specific to fix alone) — candy-kit's `Theme::ansi/charm/dracula/nord/catppuccin` (`src/Theme.php:91-202`) could derive its `Style` objects from `candy-sprinkles\Theme`'s `Color` fields instead of re-declaring hex literals.
- No overlap found with `sugar-table`/`candy-forms` widget-level code (checked for duplicate `Frame`/`Banner`/`StatusLine`/`Section`/`Stage`/`HelpText`/`Logo` class names across `sugar-bits`, `candy-forms`, `sugar-table`, `candy-sprinkles` — none exist outside candy-kit), so the widget surface itself is not duplicated, only the Theme/palette concept.

### 7. Documentation gaps

- `README.md:61-77` "Demos" section links to three GIFs that don't exist (see §4) — will 404 on the rendered docs site/GitHub.
- Composer description (`composer.json:3`) and README intro (`README.md:19-23`) correctly say "port of charmbracelet/fang," but `Logo`'s docblock (`src/Logo.php:13`) says "Mirrors ratatui v0.27+ `Logo` widget" — inconsistent upstream attribution within the same package; a reader skimming the README would not expect a ratatui-sourced widget bundled into a fang port.
- `Theme::byName()` docblock omits the `'auto'` preset it actually supports (§1).
- No CALIBER_LEARNINGS.md entry documents the Frame OVERHEAD invariant or the Banner static-cache tradeoff — both are non-obvious "why" decisions baked into the code comments only; per AGENTS.md ("comment WHY not WHAT") the in-code comments are good, but nothing surfaces them at the CALIBER_LEARNINGS.md summary level for future contributors skimming that file first.

---

## candy-layout

### Scope correction (read this first)

The audit brief describes candy-layout as mirroring `charmbracelet/lipgloss` layout primitives (`Join`, `Place`, alignment). **That is not what this library is.** Per `candy-layout/composer.json:2`, `candy-layout/README.md:11-63`, and `docs/MATCHUPS.md:53`, candy-layout is a from-scratch port of **ratatui's constraint-based layout solver** (Cassowary simplex + greedy fallback: `Length`/`Min`/`Max`/`Fill`/`Percentage`/`Ratio` constraints → `Rect` splits), not a lipgloss string-joining/placement API. There is no `Join`/`Place`/`JoinHorizontal` anywhere in this lib — those concepts don't apply here; lipgloss-style placement lives elsewhere in the monorepo (likely `candy-sprinkles`). Findings below are scoped to what candy-layout actually is.

One internal doc inconsistency worth fixing: `src/LayoutSolver.php:14` says "Mirrors ratatui's layout constraint solving (charmbracelet/bubbletea)" — ratatui is a Rust TUI crate, unrelated to bubbletea; the parenthetical is a copy-paste error and should be dropped.

### Missing/incomplete functionality vs upstream (ratatui layout scope)

- `CassowarySolver` does not implement a real Cassowary solver. Per its own comments (`src/CassowarySolver.php:306-319`), the simplex "never converges within 1000 iterations for ANY constraint type, including pure Length constraints," verified even with Bland's-rule anti-cycling. The correct fail-fast guard (throw on non-convergence) is written but commented out because "it would fail all 21 Cassowary tests." So the solver silently returns whatever state the tableau is in after exactly 1000 forced iterations, for every single call.
- Consuming lib `candy-sprinkles/src/Layout/SolverFactory.php:32-37` independently documents that `CassowarySolver` has a bug where **Ratio constraints return 0 instead of the expected value**, causing 14 test failures, and defaults to `GreedySolver` with a runtime `E_USER_WARNING` if callers opt into `cassowary`. This bug is not mentioned anywhere in `candy-layout/CALIBER_LEARNINGS.md` or `candy-layout/README.md` — the defect is documented only downstream, not at the source.
- `CassowarySolver::solve()` delegates `Min` constraints entirely to `GreedySolver` (`src/CassowarySolver.php:110-114`, `README.md:45,58`) — the simplex path only truly exercises Length/Percentage/Ratio/Max. Combined with the above, the "simplex" solver is a wrapper around Big-M machinery that doesn't converge, with Min silently rerouted and Ratio broken; in practice it has no correctness advantage over `GreedySolver` and should probably be marked experimental/unstable more loudly than a README table entry.
- No support for nested/2D layout composition (splitting a region into rows, then columns within each row) at the API level — callers must manually loop `solve()` per axis. Ratatui's `Layout` builder supports `margin`/`spacing`(gap) between segments; candy-layout has no `spacing`/`margin` constraint equivalent at all (checked `src/Constraint/*.php` — only Length/Min/Max/Fill/Percentage/Ratio exist, no Spacing).

### Performance concerns

- `CassowarySolver::solveCore()` (`src/CassowarySolver.php:290-320`) always runs up to `maxIterations = 1000` simplex pivots per `solve()` call, and per the code's own admission never breaks out early via convergence — meaning **every** Cassowary solve (even a trivial single-`Length` constraint split) does a fixed 1000-iteration Big-M simplex with `O(constraints × pivot cost)` tableau rewrites per iteration (`pivot()` at `src/CassowarySolver.php:429-463` rewrites every row/column). For a TUI re-rendering layout on every frame/keystroke, this is a substantial, unnecessary CPU cost for what GreedySolver does in one pass.
- `GreedySolver::solveVertical()` (`src/GreedySolver.php:327-343`) works by constructing a "fake" horizontal `Region` and reusing `solveHorizontal`, which is fine algorithmically but means every vertical solve does an extra `Region` reallocation per rect for the flip-back; not a hotspot but worth noting no caching/memoization exists for repeated identical `(Region, Direction, constraints)` calls — every `solve()` recomputes from scratch, no memoized result for static layouts that don't change between frames.

### Test coverage gaps

- No test drives `applyMaxClamp()`'s (`src/GreedySolver.php:224-321`) Fill-redistribution branch with **all Fill weights = 0** (`Fill::__construct` explicitly permits `weight = 0`, `src/Constraint/Fill.php:14-17`). In that case `$totalWeight = array_sum($recipientWeights)` at `src/GreedySolver.php:307` is 0, and the subsequent `$recipientWeights[$idx] / $totalWeight` divides by zero. Neither `tests/GreedySolverTest.php` nor `CassowarySolverTest.php` appears to cover a Max+Fill(weight:0) combination that reaches the clamp-redistribution path.
- No fuzz/property test asserting the core invariant "output rect sizes always sum to the input region's total dimension" across randomized constraint mixes — `testOutputSizesSumToTotal` in `CassowarySolverTest.php:230-247` exists but only for one fixed case, not a property-based sweep.
- No test exercises `CassowarySolver` with a `Ratio` constraint checked against a **non-zero, correct** expected value — given the independently-documented "Ratio returns 0" bug, this suggests the Cassowary Ratio path is essentially untested for correctness (only routed through Min-delegation or Greedy paths in tests, e.g. `testRatioConstraintViaGreedy` at `tests/CassowarySolverTest.php:127-138` explicitly avoids the pure Cassowary path).
- No test for extreme/degenerate `Ratio` denominators (e.g. very large denominator vs. small numerator causing `floor()` to zero out an allocation) or for negative-area/zero-width `Region` inputs to either solver.
- "Unicode/wide-char alignment" edge cases (the audit brief's suggested category) don't apply to this lib — it operates purely on integer cell counts, not on string content/rendering, so there is nothing to test here; this is not a gap, just an inapplicable category for this particular library.

### Missing .vhs/*.tape demos

- No `.vhs/` directory exists, and none is warranted: candy-layout is a pure algorithmic/data-structure library (constraint solver over `Region` structs) with no terminal rendering output, analogous to `candy-pty`/FFI libs that AGENTS.md marks exempt from the VHS demo requirement. Not a gap.

### Security concerns

- None significant. Inputs are validated at construction time (`Length`/`Min`/`Max`/`Percentage`/`Fill`/`Ratio` constructors all throw `InvalidArgumentException` on negative/out-of-range values, e.g. `src/Constraint/Percentage.php:16-19`). The one soft spot is the Fill-weight-0 division-by-zero path noted under Test coverage gaps — a robustness bug, not an exploitable security issue, since inputs are programmatic layout constraints, not untrusted user data in the libraries observed.
- `Region` (`src/Region.php:14-19`) performs no validation on `x`/`y`/`width`/`height` (negative values are accepted silently), unlike the `Constraint` subclasses. Not a security issue per se, but an inconsistency in the "fail fast on bad input" convention applied elsewhere in this lib.

### Functionality duplicated with candy-sprinkles/candy-buffer

- `candy-layout\GreedySolver` is explicitly a fork/extraction: README.md:14 states it was "ported from candy-sprinkles," and `candy-sprinkles/src/Layout/SolverFactory.php` + `candy-sprinkles/src/Layout/Solver.php` + `candy-sprinkles/src/Layout/Constraint.php` still exist as a parallel `SugarCraft\Sprinkles\Layout\*` namespace that wraps/re-exposes `SugarCraft\Layout\GreedySolver`/`CassowarySolver`. This is intentional (candy-layout is the extracted "foundation" package, per README.md:56-58), but it means there are now two `Constraint`-shaped APIs in the monorepo (`SugarCraft\Layout\Constraint\*` vs `SugarCraft\Sprinkles\Layout\Constraint`) — worth confirming `candy-sprinkles/src/Layout/Constraint.php` is a thin re-export and not a second maintained implementation that could drift from candy-layout's.
- **Naming collision**: `candy-layout\Region` (`src/Region.php`, flat `x/y/width/height` ints) and `candy-buffer\Region` (`src/Region.php` in candy-buffer, `Position`-object + `width/height`, implements `JsonSerializable`) are two unrelated classes with the same short name and overlapping purpose (rectangular sub-region of a grid) but different shapes/namespaces. candy-layout's own doc comment (`src/Region.php:7-8`) explicitly justifies this as "own type to keep candy-layout leaf (no dep on candy-buffer)" — a deliberate tradeoff, but it means any code that bridges the two libs must manually convert between two incompatible `Region` types, and the identical class name invites `use` collisions/confusion for consumers of both libs.

### Documentation gaps

- `candy-layout/CALIBER_LEARNINGS.md` (5 lines total) does not mention the CassowarySolver non-convergence bug or the Ratio-returns-0 bug at all, even though both are known, reproducible, and already documented by a *downstream* consumer (`candy-sprinkles/CALIBER_LEARNINGS.md:17`, tag `bug:cassowary-solver-ratio`). Per repo convention (CALIBER_LEARNINGS.md exists precisely to capture "patterns/anti-patterns learned"), this is the wrong place for the bug to be undocumented — a future contributor working only in candy-layout has no signal that `CassowarySolver` is known-broken beyond a buried inline code comment.
- `README.md`'s "Solvers" table (`README.md:40-45`) undersells the severity: it calls CassowarySolver merely "Experimental simplex prototype; Min/Fill delegate to GreedySolver," which reads as a scope limitation rather than "does not converge and has a Ratio bug that returns wrong answers." A reader would reasonably expect Percentage/Max/Length via Cassowary to work correctly, which per the sprinkles-level bug report is not reliably true for Ratio at least.
- No top-level doc anywhere states *why* GreedySolver is production-recommended and Cassowary should not be used outside experimentation — that reasoning currently exists only in `candy-sprinkles/src/Layout/SolverFactory.php`'s docblock, not in candy-layout itself where the defect lives.

---

## candy-lister

Port of `treilik/bubblelister` (marked 🟢 in `docs/MATCHUPS.md:56`). Pure rendering
component: `Model` (`candy-lister/src/Model.php`) stores items + cursor + viewport
and produces styled lines; no TEA `update()`/`view()`/`init()` contract, no
key-message handling.

### 1. Missing/incomplete functionality vs upstream scope

- **No `update(Msg)` / keybinding integration at all.** `candy-lister/src/Model.php`
  has no `init()`/`update()`/`subscriptions()` per the repo's own TUI-Model
  convention (`.claude/rules/model-pattern.md`); cursor movement is exposed only
  as fluent setters (`cursorUp()`/`cursorDown()`/`cursorPageUp()`/`cursorPageDown()`,
  `Model.php:389-413`). Every consumer must hand-wire `KeyMsg` → cursor calls
  themselves. `composer.json:76-88` lists `candy-input` and `candy-pty` as
  path-repos but neither is `require`d nor referenced anywhere in `src/` —
  dead/aspirational repo entries.
- **`FilterState` enum is missing the `filtered` case that the README documents.**
  `src/FilterState.php:16-20` defines only `unfiltered` and `filtering`, but
  `README.md:116,132,146-150` describes and tables a three-state machine
  (`unfiltered → filtering → filtered`) and says `filterState is now
  FilterState::filtering → FilterState::filtered`. `Model::withFilterFn()`
  (`Model.php:202-220`) only ever sets `FilterState::filtering`, never
  `filtered`, so the documented terminal state does not exist in code —
  README/CALIBER_LEARNINGS (`CALIBER_LEARNINGS.md:7`) and the enum have drifted
  apart. `FilterStateTest.php:30-34` locks in the 2-case enum, confirming this
  is not a test gap but a real README/behavior mismatch.
- **`CancellationToken` support is only a doc-comment promise, not implemented.**
  `Model.php:329-334` (`sort()`), `Model.php:517-522` (`lines()`), and
  `FuzzyMatch.php:59-63` all have "## CancellationToken support (intended API —
  requires candy-async)" blocks describing a parameter that does not exist on
  any of these methods today — `candy-async` is in `repositories[]`
  (`composer.json:69-74`) but not `require`d and no code references it.
  `FuzzyMatch.php:65-68` similarly documents an unimplemented `matchAsync()`.
- No wrap-around cursor navigation (top↔bottom) — `setCursor()`
  (`Model.php:384-387`) clamps with `max(0, min(...))` rather than modulo, unlike
  some upstream Bubble Tea list components that support wrapping.

### 2. Performance concerns

- **O(n²) list construction via single-item `addItem()`.** `addItem()`
  (`Model.php:260-264`) and `cursorUp/Down`/`setCursor` all go through
  `mutate()` → `clone $this` (`Model.php:117-122`). Because `$items` is a PHP
  array with copy-on-write semantics, appending to `$m->items[]` inside the
  clone forces a full copy of the existing items array on *every* `addItem()`
  call once the clone's refcount is shared with `$this`. Building a list of N
  items one-at-a-time (exactly the pattern shown in
  `examples/basic.php:20-22` and `README.md:41-43` quick start) is O(n²) in
  array copies. `addItems()`/`addItemsFromArray()` (`Model.php:271-295`) avoid
  this by batching into one clone, but neither the README nor the examples
  steer users toward the batch form — the documented idiom is the slow one.
- **No virtualization/windowing document or guard for very large lists in
  `sort()` and `find()`.** `sort()` (`Model.php:335-353`) is O(n log n) plus an
  O(n) `array_flip`/`array_map` per call and `find()` (`Model.php:489-505`) is
  O(n) — both fine at typical sizes but unbounded and un-time-sliced (no
  `CancellationToken` despite the doc-comment promising one, see §1), so a
  100k-item list blocks the ReactPHP loop for the full scan with no yield point.
  `lines()` itself is correctly windowed (only walks `cursorOffset` items above
  cursor and `height` below, `Model.php:523-591`), so render-time viewport cost
  is bounded — the concern is confined to `sort()`/`find()`/mass `addItem()`.
- **`FuzzyMatch::score()` is O(queryLen × candidateLen) per candidate** with
  `mb_substr` calls inside the innermost loop (`FuzzyMatch.php:124-145`) rather
  than pre-splitting the strings once (contrast with candy-fuzzy's
  `SmithWatermanMatcher::compute()`, which does `mb_str_split()` once before the
  loop — `candy-fuzzy/src/Matcher/SmithWatermanMatcher.php:128-129`). For large
  candidate lists this is measurably slower than the sibling implementation
  doing the same math.
- `Model::View()`'s diff path (`Model.php:618-648`) rebuilds a full `Buffer`
  cell-by-cell via `mb_substr` per cell every frame (`bufferFromOutput()`,
  `Model.php:805-825`) even when only computing a delta — O(width×height)
  `mb_substr` calls per render regardless of how small the change is.

### 3. Test coverage gaps

- No test exercises `addItem()` at scale (e.g., verifying `addItems()` batch
  path is actually cheaper, or any performance/regression guard against the
  O(n²) pattern in §2).
- No test for `resetPreviousFrame()` semantics beyond
  `IntegrationTest.php:271` single case interacting with resize; no test
  combining filter + resize + diff in one sequence.
- `ScoringProfile` (`FuzzyMatch.php:13-51`, with `default()`/`strict()`/`lenient()`
  factories and `FuzzyMatch::withProfile()`, `FuzzyMatch.php:82-87`) has **zero
  test coverage** — `FuzzyMatchTest.php` only exercises the default profile;
  `strict()`/`lenient()`/`withProfile()` are never invoked in any test.
- No test asserts `FilterState::filtered` because it doesn't exist (see §1) —
  but there's also no test that would have caught the README/enum drift (e.g.
  a doc-example test).
- No property-based/edge test for `hardWrap()`/`splitOverWidth()`
  (`Model.php:723-772`) with zero-width viewport, extremely long single words,
  or grapheme clusters that are themselves wider than `contentWidth`.
- `candy-input`/`candy-pty`/`candy-async` path-repos exist with no code
  reference and no test proving they're actually needed — dead weight in
  `composer.json` (§1) that a test/CI check wouldn't catch since
  `tools/check-path-repos.php` only checks for *missing* path-repos, not unused
  ones.

### 4. Missing .vhs/*.tape demos

- `examples/long-items.php` (word-wrap demo, `examples/long-items.php:1-33`) has
  **no corresponding `.vhs/long-items.tape`** — only `basic.tape` and
  `custom-styling.tape` exist (`candy-lister/.vhs/` listing). Per
  `AGENTS.md`'s VHS-demos convention every visual example should get a tape;
  this is a gap since candy-lister is a visual/non-exempt lib.
- No demo (example or tape) shows filtering (`withFilterFn`/`withoutFilter`) or
  fuzzy matching (`FuzzyMatch`) in action, despite both being flagship features
  called out in `README.md:25-26` and given dedicated README sections
  (`README.md:114-175`).

### 5. Security concerns

- Handled reasonably: `README.md:54-56` explicitly documents that item text is
  emitted verbatim via `(string) $value` with no escaping, and tells callers to
  sanitize untrusted input themselves — consistent with the "TUI render
  invariants" project convention (sanitize binary/DB data before feeding a
  renderer).
- `applyStyle()` (`Model.php:779-787`) does `trim($style, "\e\x1b[]m")` and
  splices raw `$codes` into an SGR escape (`Ansi::CSI . $codes . 'm'`) with no
  validation that `$codes` is digits/semicolons only. A caller-supplied
  `lineStyle`/`currentStyle` string containing further escape sequences (e.g.
  embedded `\x1b]8;;` OSC-8 hyperlink payloads or nested CSI sequences) would be
  concatenated into terminal output verbatim — low severity (style strings are
  developer-supplied, not typically untrusted user input) but no
  defense-in-depth if `lineStyle`/`currentStyle` were ever built from
  user-controlled data.
- No limits on `wrap`/`width`/`height` inputs — `setWidth()`/`setHeight()`
  (`Model.php:143-151`) accept any `int` including negative/huge values;
  `lines()` throws for `<= 0` (`Model.php:528-530`) but a maliciously huge
  `height` (e.g. `PHP_INT_MAX`) would attempt to allocate an unbounded
  `$allLines` array with no cap — minor DoS surface if viewport dims are ever
  derived from untrusted input (e.g. a terminal resize event forged by a
  compromised PTY peer).

### 6. Functionality duplicated with candy-fuzzy

**Confirmed and detailed.** `candy-lister/src/FuzzyMatch.php` independently
reimplements the exact same Smith-Waterman local-alignment algorithm that
`candy-fuzzy/src/Matcher/SmithWatermanMatcher.php` implements as the
ecosystem's canonical fuzzy matcher:

- **Identical scoring constants.** `FuzzyMatch.php`'s `ScoringProfile::default()`
  (`FuzzyMatch.php:23-26`, matchScore=3, mismatchPenalty=-3, gapOpen=-5,
  gapExtend=-1, adjacentBonus=5) are bit-for-bit the same as
  `SmithWatermanMatcher`'s class constants (`SmithWatermanMatcher.php:26-30`,
  `MATCH_SCORE=3`, `MISMATCH_PENALTY=-3`, `GAP_OPEN=-5`, `GAP_EXTEND=-1`,
  `ADJACENT_BONUS=5`) — candy-fuzzy's own doc-comment even calls itself "Bit-
  equivalent in score and ranking to the original
  SugarCraft\Forms\Fuzzy\FuzzyMatcher implementation" (`SmithWatermanMatcher.php:14-15`),
  confirming this exact algorithm was already extracted once from `candy-forms`
  into `candy-fuzzy` as the canonical shared implementation — candy-lister
  built a *third*, separate copy instead of depending on either.
  `docs/MATCHUPS.md` and `CALIBER_LEARNINGS.md:6` even independently label
  candy-lister's copy with the pattern tag `[pattern:smith-waterman-two-row]`,
  treating it as a reusable pattern rather than flagging the duplication.
  candy-lister's `composer.json` (`composer.json:26-30`) does not depend on
  `sugarcraft/candy-fuzzy` at all.
- **Algorithmic core is line-for-line equivalent.** Compare the DP recurrence in
  `FuzzyMatch.php:143-147` (`$scoreDiag`/`$scoreUp`/`$scoreLeft`, gap-open vs.
  gap-extend based on whether the neighbor cell is 0) against
  `SmithWatermanMatcher.php:158-162` — same formulas, same adjacency-bonus
  condition (`$match > 0 && $i > 1 && $j > 1` checking the diagonal-previous
  characters), same `mb_strtolower(..., 'UTF-8')` case folding.
- **Differences are only in matrix strategy and feature surface, not
  algorithm:** candy-lister's `FuzzyMatch` uses a two-row DP matrix (memory
  O(candidateLen), no traceback, no matched-character indices —
  `FuzzyMatch.php:117-119`), while candy-fuzzy's `SmithWatermanMatcher` uses a
  full O(queryLen×candidateLen) matrix specifically to support traceback and
  return `matchedIndices` for highlight rendering
  (`SmithWatermanMatcher.php:19-21`, `MatchResult`). candy-lister's version
  therefore *cannot* support the `Highlighter` (`candy-fuzzy/src/Highlighter.php`)
  highlighting feature that candy-fuzzy's `SmithWatermanMatcher` was built to
  enable — a real functional regression for filter-UI use cases, not just
  duplicated code.
- **Unique feature not present upstream:** `ScoringProfile` with
  `default()`/`strict()`/`lenient()` presets and `FuzzyMatch::withProfile()`
  (`FuzzyMatch.php:13-51,82-87`) lets callers tune match/mismatch/gap scores;
  candy-fuzzy's `SmithWatermanMatcher` hardcodes its constants with no
  equivalent tuning knob. This is the one piece of candy-lister's `FuzzyMatch`
  that isn't pure duplication — but it would be a small, easy addition to
  candy-fuzzy's matcher (e.g. a constructor-injected profile) rather than
  justifying a third full reimplementation of the DP algorithm.
- **Net assessment:** candy-lister should depend on `sugarcraft/candy-fuzzy`
  and either use `SmithWatermanMatcher` directly (gaining highlight support for
  free) or, if the two-row memory-efficiency win matters for very large
  candidate lists, that optimization should be contributed back to
  candy-fuzzy rather than forked here. As it stands the ecosystem has three
  historical copies of this exact algorithm: the original in `candy-forms`,
  the "canonical" extraction in `candy-fuzzy`, and this one in `candy-lister`.

### 7. Documentation gaps

- **README/enum mismatch on `FilterState`** — see §1; `README.md:132,146-150`
  describes a `filtered` terminal state and a `filtering → filtered`
  transition that do not exist in `src/FilterState.php`. This is the most
  concrete doc bug found.
- README's Fuzzy Matching section (`README.md:152-175`) does not mention that
  `FuzzyMatch` duplicates `candy-fuzzy`'s `SmithWatermanMatcher`, nor that it
  lacks matched-character-index/highlighting support that candy-fuzzy provides
  — a reader would not know a more capable, shared implementation exists
  elsewhere in the monorepo.
- No mention in README of the `CancellationToken`/`candy-async` aspirational
  API documented only in source doc-comments (§1) — either finish the feature
  or remove the forward-reference comments; as written they read as
  implemented but are not.
- No documentation of the `addItem()` vs `addItems()`/`addItemsFromArray()`
  performance trade-off (§2) — the Quick Start (`README.md:37-50`) uses the
  slower per-item loop pattern without a note steering large-list users to the
  batch constructors.
- No VHS demo/README callout for `examples/long-items.php` word-wrap behavior
  (§4) despite the example existing.
- `composer.json` keywords (`composer.json:6-13`) omit `"fuzzy"` even though
  `FuzzyMatch` is advertised as a flagship feature in the README — minor
  discoverability gap on Packagist.

---

## candy-log

### 1. Missing/incomplete functionality vs upstream charmbracelet/log

- **`Fatal` semantics diverge from upstream.** Upstream `log.Fatal` calls `os.Exit(1)` after printing. This port throws `\RuntimeException` (`src/Logger.php:144-146`, message via `Lang::t('logger.fatal', …)`). That's a reasonable PHP adaptation (can't call `exit()` in a library and stay testable) but it means callers who don't catch the exception get a PHP fatal-error stack trace rather than a clean process exit, and any code between the `fatal()` call and the eventual uncaught-exception handler still runs `finally`/destructors that upstream's immediate exit would skip. Not documented as a deviation in README beyond the one-line "`$log->fatal('fatal message'); // throws RuntimeException`" (README.md:61).
- **No `Helper()` / caller-skip depth control.** Upstream's `log` package (and slog) let you mark wrapper functions so caller reporting skips extra frames. `CallerFormatter::find()` (`src/CallerFormatter.php:22-39`) only skips frames whose file lives under `candy-log/src` — any project-level logging wrapper (e.g. a `MyApp\Log::info()` helper) will be reported as the caller instead of the true call site, with no way to configure additional skip frames.
- **No `SetCallerFormatter` / custom caller formatting.** Upstream allows overriding how caller is rendered (short vs long path). Here it's hard-coded to `basename($file):line` (`src/CallerFormatter.php:34-35`); no option for full path or package-relative path.
- **JSON/Logfmt formatters ignore `PartsOrder`.** `PartsOrder` (`src/PartsOrder.php`) is documented as controlling "which log-parts appear and in what sequence when formatting" but `JsonFormatter::format()` (`src/Formatter/JsonFormatter.php:23-63`) and `LogfmtFormatter::format()` (`src/Formatter/LogfmtFormatter.php:23-53`) hard-code their own field order and don't consult `$partsOrder` at all — only `TextFormatter` honors it. README's Parts Order section (README.md:203-226) doesn't call out this formatter-specific limitation.
- **`HookRegistry` only fires via `PsrBridge`**, never from plain `Logger` calls (explicitly documented at README.md:166 and `src/Hook/HookRegistry.php:70-71`). This is a significant functional gap vs. the natural expectation that hooks fire on every emitted log line — most callers use `Logger` directly, so hooks are effectively dead unless the app also wraps in `PsrBridge`.
- **No sampling / rate limiting** (upstream `log` doesn't have this either, so not a real gap, but worth noting there's no throttling for high-volume debug logging).
- **No structured "error" auto-extraction** — upstream charmbracelet/log has conventions for logging `error` values with stack info; this port treats all context values generically via `ValueCoercion` with no special-casing for `\Throwable` (an exception passed in `context` degrades to its class name via `stringifyObject()`, `src/Formatter/ValueCoercion.php:79-92`, losing the message/trace entirely for Text/Logfmt output, and to `get_class()` for JSON via `coerce()`, `ValueCoercion.php:130-135`).

### 2. Performance concerns

- **`debugf`/`infof`/`warnf`/`errorf`/`fatalf` eagerly `sprintf` before the level filter runs.** e.g. `debugf()` (`src/Logger.php:175-178`) calls `\sprintf($format, ...$args)` and passes the *already-formatted* string into `debug()` → `log()` → `emit()`, where the min-level check happens only inside `emit()` (`src/Logger.php:131-133`). So a `Debug`-level `sprintf` with expensive argument stringification still runs even when the logger's `minLevel` is `Info` or higher. Upstream Go avoids this by checking the level before doing any `fmt.Sprintf` work.
- **No lazy/deferred context values.** Context arrays passed to `log()`/`debug()`/etc. must be fully materialized by the caller (e.g. `['dump' => expensiveSerialize($obj)]`) even if the level is filtered out, since there's no closure/callable deferral support in `Formatter` or `Logger::emit()` (`src/Logger.php:129-147`). Any caller building rich diagnostic context for `debug()` calls pays that cost unconditionally in production where `minLevel` is `Info`.
- **`CallerFormatter::find()` always walks up to 20 stack frames** (`\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 20)`, `src/CallerFormatter.php:24`) whenever `reportCaller` is on — reasonable but note it captures backtrace on every single log call, not just when actually needed; acceptable given it's opt-in via `reportCaller`.
- **`ValueCoercion::stringify` recurses depth 4 over arbitrary context values on every formatted line** (`src/Formatter/ValueCoercion.php:15,25-77`) — fine for typical small context maps, but no fast-path/caching for repeated identical field shapes.

### 3. Test coverage gaps

- **No test exercises `PartsOrder` being ignored by `JsonFormatter`/`LogfmtFormatter`** (i.e. no regression test locking in or flagging the inconsistency noted above).
- **No test for `HookRegistry::remove()` firing behavior end-to-end through `PsrBridge`** beyond registration; `HookRegistryTest.php` (131 lines) should be checked for `remove()` coverage but CALIBER_LEARNINGS.md:9 states the original `remove(int $id)` was "broken" and removed from scope — yet `src/Hook/HookRegistry.php:58-61` still defines a `remove()` method, so this learning note appears **stale/contradicted by current source**: `remove()` exists and sets `$this->handlers[$id] = null`. Worth flagging as a docs/code mismatch — either the learning is outdated or a subsequent PR re-added `remove()` without updating `CALIBER_LEARNINGS.md`.
- **No test verifies `debugf`/`errorf`/etc. skip `sprintf` when filtered** — since they don't (see performance section), a regression test would actually catch this eagerness; none exists in `LoggerTest.php`.
- **No test for `CallerFormatter::find()` returning `null`** when all frames are inside the log package (edge case at `src/CallerFormatter.php:38`); `CallerFormatterTest.php` is only 37 lines.
- **No test for `StandardLogAdapter::panic()`/`fatal()` actually forwarding to `Logger::fatal()` and the resulting `RuntimeException` bubbling out** — `StandardLogAdapterTest.php` should be checked but risk of gap given `panic()`/`fatal()` (`src/StandardLogAdapter.php:45-55`) are near-duplicates.
- **No test for JSON-encode failure fallback path** in `JsonFormatter::format()` (`src/Formatter/JsonFormatter.php:53-60`) — the `_encode_error` fallback branch (triggered e.g. by invalid UTF-8 in a context value) appears untested.
- **No test demonstrates log-injection via embedded newlines** in message/context values for Text or Logfmt formatters (see Security section) — this is both a missing test and a missing feature.

### 4. Missing .vhs demos

- Only one tape exists: `.vhs/demo.tape` (+ committed `.vhs/demo.gif`). Given the lib exposes multiple formatters (Text/JSON/Logfmt), styling, parts-order presets, and the panic handler, there's a single demo covering (presumably) just the default text output — no dedicated tape for JSON/Logfmt formatter output or for `Log::installPanicHandler()`'s panic-report rendering (which is the most visually distinctive feature and has two example scripts, `examples/panic-handler.php` and `examples/panic-restore.php`, with no corresponding tape).

### 5. Security concerns

- **Log injection / log forging via unescaped newlines.** `TextFormatter::format()` interpolates `$message` and field values directly (`src/Formatter/TextFormatter.php:75-89`) with no newline stripping/escaping — a message or context value containing `\n` (e.g. from unsanitized user input) will inject what looks like an additional, independently-parseable log line, letting an attacker forge fake log entries (classic CRLF/log-injection). `LogfmtFormatter::escape()` (`src/Formatter/LogfmtFormatter.php:55-61`) only escapes literal `"` characters and quotes the value when it contains whitespace (which `\s` matches, including `\n`/`\r`), but it does **not** escape the newline character itself — the raw newline still lands in the output inside the quotes, still breaking single-line-per-entry invariants that most log shippers (and logfmt itself) assume. `JsonFormatter` is safe here since `json_encode` escapes control characters by default.
- **No redaction/scrubbing of sensitive field names.** There is no built-in mechanism to redact keys like `password`, `token`, `authorization`, `secret`, etc. from structured context before they hit the formatter/stream — every context value passed to `->info()`/`->error()`/etc. is logged verbatim (`src/Logger.php:110-147`, all three formatters). `PanicFormatter` has a `redactPaths` option (`src/PanicFormatter.php:16-17,100-104,140-142`) but that only redacts *file paths* in backtraces, not arbitrary sensitive values, and it doesn't apply to the normal `Logger`/formatter path at all.
- **`PanicFormatter`'s `showLocals` option can leak sensitive data.** When enabled, it dumps function-argument values from the backtrace (`src/PanicFormatter.php:147-155`), truncated to 40 chars but with no redaction — secrets/PII passed as arguments anywhere in the call stack at crash time would be printed to STDERR. This is opt-in (`showLocals` defaults to `false`, `Log.php:92`) but undocumented as a security-sensitive flag in README's Panic Handlers section (README.md:127-141).
- **No output sanitization for ANSI/control-sequence injection.** Because `useColors` wraps values in `Style::render()` (ANSI SGR codes) and raw message/context strings are otherwise passed through unmodified, a malicious context value containing raw ANSI escape sequences (e.g. `\x1b[2J` clear-screen, or OSC sequences) would be forwarded verbatim to the terminal/log stream when a human later `cat`s or `tail -f`s the log file in a terminal — no stripping of embedded control characters in message/field values before styling.

### 6. Functionality duplicated with candy-metrics

- None found. `candy-metrics` (`candy-metrics/src/Registry.php`, `Backend.php`) is scoped to counters/gauges/histograms/up-down-counters — no leveled-logging, formatting, or logger surface overlaps with `candy-log`. No consolidation concern.

### 7. Documentation gaps

- README's Hook System section documents the "hooks only fire via PsrBridge" limitation reasonably well (README.md:166), but doesn't explain *why* (no rationale for why `Logger` itself can't dispatch hooks), nor does it warn that this is easy to get wrong (a caller wiring up `HookRegistry` against a plain `Logger` will silently get no callbacks).
- No documentation of the log-injection risk (newline handling) or absence of redaction — the README's Features list advertises "Structured key/value pairs" and formatters without any caveat about sanitizing untrusted input before logging it.
- README doesn't document `StandardLogAdapter`'s `$forceLevel` constructor parameter or when to use it over the default `Level::Info` (`src/StandardLogAdapter.php:15-21`) — the PSR-3 Bridge and Panic Handler sections are documented but there's no "Standard Log Adapter" section at all in README.md despite the class being public API and mentioned in Features (line 24).
- `CallerFormatter` class doc says "This class is public API but is for framework integration use" (`src/CallerFormatter.php:12-13`) yet README's "Caller Information" section (README.md:228-238) presents it as a normal usage example without that caveat.
- `CALIBER_LEARNINGS.md:9` claims `HookRegistry::remove()` "was broken … and was removed" but the method still exists in `src/Hook/HookRegistry.php:58-61` — the learnings file is stale/inaccurate relative to current source and should be corrected or the method's history clarified.

---

## candy-metrics

Telemetry primitives (Counter/Gauge/Histogram/UpDownCounter/AsyncCounter/AsyncGauge) with pluggable
`Backend` (InMemory, JsonStream, Statsd, PrometheusFile, Multi) behind a `Registry` facade, plus a
CandyWish `SessionMetrics` middleware. This is a mature, well-tested port (Phase 9, 41+ tests per
README, 1326 lines of test code) — findings below are refinements, not gaps in a stub.

### 1. Missing/incomplete functionality

- **No quantile/summary support.** `Descriptor` accepts `type: 'summary'` but only ever emits a bare
  `# TYPE`/`# HELP` header — no `observe()` path renders quantile lines (`{quantile="0.5"}` etc.) or
  exemplars. Documented as a known limitation in `src/Backend/PrometheusFileBackend.php:29-33` and
  `README.md:22,146`, but there's no histogram-quantile *estimation* helper either (e.g. no
  t-digest/HDR client-side approximation) — callers only get Prometheus-side `histogram_quantile()`
  via bucket counts. This is consistent with upstream OTel API shape, so likely acceptable, but worth
  flagging as a real feature gap vs. Prometheus client libraries that support summaries client-side.
- **No OTLP / OpenTelemetry exporter backend.** Only StatsD, Prometheus textfile, JSON stream, and
  in-memory. Given the library explicitly mirrors OTel async-instrument semantics
  (`CALIBER_LEARNINGS.md:9`, `src/Instrument/AsyncCounter.php:18`), an OTLP HTTP/gRPC backend is a
  natural but absent extension point.
- **No HTTP `/metrics` scrape endpoint backend** (Prometheus pull model) — only the textfile-collector
  pattern (`PrometheusFileBackend`, push-via-file). Fine for the CandyWish SSH-server use case, but
  means any consumer wanting a live pull endpoint must roll their own on top of `InMemoryBackend`.
- **`StatsdBackend::upDownCounter()`** encodes signed deltas as StatsD gauge (`name:+1|g`) —
  `src/Backend/StatsdBackend.php:71-77`. This is correct DogStatsD usage but not documented in
  `README.md`'s backend section (only counter/gauge/histogram wire formats are shown, lines 135-141).

### 2. Performance concerns

- **`InMemoryBackend::histogram()`** (`src/Backend/InMemoryBackend.php:47-50`) appends every sample to
  an unbounded `list<float>` per `(name, tags)` key with no cap, retention window, or reservoir
  sampling. For a long-running process (e.g. an always-on CandyWish server) recording per-request
  latency, this array grows forever — pure memory growth proportional to total request count, not
  distinct label cardinality. `Registry`'s cardinality limiter does nothing to bound this (see §5).
- **`PrometheusFileBackend::flush()`** (`src/Backend/PrometheusFileBackend.php:172-340`) does a full
  `in_array($key, $dirtyXKeys, true)` linear scan per accumulator array to separate dirty vs. non-dirty
  keys (e.g. lines 267, 274, 281, 288, 295, 302) — O(n·m) where n = total keys, m = dirty keys, instead
  of an O(1) hash lookup (`isset($dirtySet[$key])`). Negligible at low cardinality, but scales poorly
  as the metric surface grows; an easy fix is flipping the dirty arrays to `array_flip()`'d sets before
  the scan or reusing the existing `$this->dirtyCounters` map (already keyed by `$key`) with
  `isset()` instead of building `array_keys()` + `in_array()`.
- **`PrometheusFileBackend`** takes a `flock(LOCK_EX)` on the `.tmp` file per `flush()`
  (`src/Backend/PrometheusFileBackend.php:328-336`) — fine for correctness, but every `flush()` does a
  full re-serialize of *all* metrics ever seen (not just dirty ones need the header, but non-dirty
  metrics are still walked and rendered in full — lines 266-313) — so per-flush cost is O(total
  distinct series), which combined with the unbounded-cardinality concern in §5 means flush cost grows
  without bound over the life of a long process.
- **`Registry::trackCardinality()`** (`src/Registry.php:258-274`) computes `$this->mergeTags($tags)`
  and a full sorted tag-key string on *every single emit* (counter/gauge/histogram/etc.), even when the
  combination was already tracked. This is an `isset()`-guarded cheap path but still allocates a merged
  array and a `ksort()`'d string per call — for very hot counters (e.g. per-keystroke telemetry, which
  `CALIBER_LEARNINGS.md` in other libs explicitly warns against) this is allocation overhead on every
  metric update, not just cardinality-changing ones.

### 3. Test coverage gaps

- No test exercises `MultiBackend::withContinueOnError()`'s aggregated-failure path returning partial
  success — `tests/Backend/MultiBackendTest.php` should be checked for whether `MultiBackendException`
  content (which children failed, in what order) is asserted; from the read source
  (`src/Backend/MultiBackend.php:107-127`) the exception aggregates a list of `\Throwable`s but nothing
  in the reviewed test file range confirms per-child error identification is verified.
- No test drives `InMemoryBackend::histogram()` at scale to document/guard the unbounded-growth
  behavior in §2 — i.e. no regression test asserting a cap, decay, or explicit warning exists for
  histogram sample retention.
- No test verifies `Registry`'s automatic FIFO eviction (`trackCardinality`, triggered when exceeding
  `cardinalityLimit`) actually reduces backend memory — and per `tests/CardinalityTest.php:73-75`
  (`testDeleteLabelValuesRemovesTrackingForSpecificCombo`) the tests *explicitly document* that
  backend data is untouched by `deleteLabelValues()`. This means cardinality "eviction" only trims the
  Registry's own bookkeeping array, not the actual per-backend storage that caused the memory pressure
  in the first place (see §5) — a real, tested-and-confirmed design limitation, not a hidden bug, but
  the README's cardinality section (`README.md:50-71`) reads as though eviction reclaims memory
  ("evicts the oldest combination... FIFO the oldest entry is removed") without clarifying that only
  the tracking cache shrinks — actual per-backend series data (Prometheus `.prom` file rows, in-memory
  histogram lists) is not reclaimed unless the app also calls `Registry::remove()` (not
  `deleteLabelValues()`) per combination.
- `PrometheusFileBackend`'s `__destruct()`-swallowed-flush-error path (`src/Backend/PrometheusFileBackend.php:93-100`)
  has no test forcing a destructor-time flush failure (e.g. unwritable dir) to confirm it truly never
  throws.
- No test for `StatsdBackend`'s `failSilently: false` mode actually throwing/propagating a `fwrite`
  failure (only the default silent-drop path is implied by the README).

### 4. VHS demos

Present — `.vhs/showcase.tape` + `.vhs/showcase.gif` exist and correspond to `examples/showcase.php`.
Not a gap.

### 5. Security concerns

- **Unbounded memory growth from unbounded label sets is only partially mitigated — likely exploitable
  as a DoS vector.** As detailed in §2/§3: `Registry`'s cardinality limiter
  (`src/Registry.php:258-274`, default 10 000 per metric) only bounds its *own* tracking cache. It does
  **not** call `Backend::remove()` on eviction — only `deleteLabelValues()`'s private bookkeeping is
  trimmed. Concretely:
  - `InMemoryBackend`'s `$counters`/`$gauges`/`$histograms` maps (`src/Backend/InMemoryBackend.php:23-34`)
    keep every distinct key forever; histogram lists grow per-sample forever regardless of cardinality
    limit.
  - `PrometheusFileBackend`'s `$counters`/`$gauges`/`$histograms`/etc. maps (`src/Backend/PrometheusFileBackend.php:49-60`)
    likewise retain every distinct `(name, tags)` key seen for the life of the process — there's no
    automatic pruning tied to the Registry's cardinality limit.
  - Net effect: an attacker (or buggy caller) that emits metrics with attacker-controlled label values
    (e.g. `user_id`, `request_id`, raw path segments) can grow backend memory unboundedly even with the
    default `cardinalityLimit=10000` in place, because that limit only throttles what `Registry::cardinality()`
    *reports*, not what the backend actually retains. True mitigation requires the app to call
    `Registry::remove()` (which does call `Backend::remove()`) — but nothing wires that up automatically
    from the cardinality limiter.
  - `SessionMetrics` middleware (`src/Middleware/SessionMetrics.php:66`) tags the `wish.session.error`
    counter with `'exception' => $e::class` — bounded (finite set of exception class names), fine. But
    `user`/`term` tags (lines 44) come directly from `Session->user`/`Session->term`, which for an SSH
    server are attacker-influenced (arbitrary username / `$TERM` string) — a malicious or buggy SSH
    client could grow cardinality/memory by connecting with many distinct usernames or terminal type
    strings. Given the limiter doesn't actually cap backend memory (per above), this is a plausible
    amplification path specific to the one first-party consumer (`candy-wish`) this library ships
    middleware for.
- `StatsdBackend`/`JsonStreamBackend` failure handling defaults (`failSilently: true`,
  `throwOnError: true` respectively — inconsistent defaults between the two backends,
  `src/Backend/StatsdBackend.php:42` vs `src/Backend/JsonStreamBackend.php:31`) are a minor
  inconsistency: StatsD silently drops on failure by default while JsonStream throws by default. Not a
  vulnerability, but an easy footgun — a JsonStreamBackend hooked into `SessionMetrics` without the
  middleware's try/catch guards (which the middleware does have, `src/Middleware/SessionMetrics.php:49-79`)
  would propagate write failures.

### 6. Duplication with candy-log

None found. `candy-log` (charmbracelet/log port: leveled logging, formatters, PSR-3 bridge) and
`candy-metrics` (counters/gauges/histograms, StatsD/Prometheus backends) are cleanly separated concerns
— no overlapping responsibility, no shared primitives that should be deduplicated.

### 7. Documentation gaps

- `README.md`'s cardinality section (lines 50-71) does not clarify that automatic FIFO eviction and
  `deleteLabelValues()` only shrink the Registry's tracking cache, not backend-stored series data (see
  §5) — a reader would reasonably expect "evicts the oldest combination" to mean the underlying metric
  is actually forgotten/reclaimed. Should explicitly document that `remove()` (not `deleteLabelValues()`)
  is required to free backend memory, and that the cardinality limiter is an accounting/reporting
  mechanism, not a memory-bounding one for `InMemoryBackend`/`PrometheusFileBackend`.
- StatsD wire-format section (`README.md:135-141`) omits the `upDownCounter` signed-gauge convention
  (`name:+1|g` / `name:-1|g`) implemented in `src/Backend/StatsdBackend.php:71-77` — worth a line since
  it's a non-obvious StatsD idiom.
- No documentation of the `failSilently`/`throwOnError` default asymmetry between `StatsdBackend` and
  `JsonStreamBackend` (see §5) — a consumer picking backends interchangeably could be surprised.
- `CALIBER_LEARNINGS.md` documents the cardinality-FIFO pattern (`pattern:cardinality-fifo-eviction`)
  with the same overstated claim ("prevents memory exhaustion... Backends that accumulate large label
  sets should call `deleteLabelValues()` to reclaim memory") — this is incorrect per the `CardinalityTest`
  comments and source: `deleteLabelValues()` does NOT reclaim backend memory, only `remove()` does. This
  learning entry should be corrected since it will mislead future sessions/agents porting similar
  patterns into other libs.

---

## candy-mines

Scope confirmed: this is a genuine minesweeper TUI game/app (port of `maxpaulus43/go-sweep`), not just a foundation primitive despite the `Candy-` prefix. It has a runnable `bin/candy-mines`, `examples/play.php`, and VHS demos — treated below as a visual/game lib.

### 1. Missing / incomplete functionality

- **Stats/DifficultyStats system is built but never wired up.** `Game::recordResult()` (`src/Game.php:286-302`) and `SugarCraft\Mines\Stats\DifficultyStats` (`src/Stats/DifficultyStats.php`) implement a full win/loss/best-time tracker with atomic JSON persistence, but nothing in `bin/candy-mines` or `examples/play.php` ever calls `recordResult()` or `DifficultyStats::load()/save()`. `Game::update()` (`src/Game.php:87-128`) has no branch that detects a fresh win/lose transition and persists it. As shipped, a player can win or lose every game and the stats file is never created or updated — this is dead code exercised only by unit tests (`tests/GameTest.php:194-330`, `tests/DifficultyStatsTest.php`).
- **`Ui\CustomDifficulty` is unreachable from the running program.** `src/Ui/CustomDifficulty.php` validates rows/cols/mines for a custom game, but there is no in-TUI menu/prompt that uses it — `bin/candy-mines` only accepts three raw positional CLI ints (`bin/candy-mines:24-27`), with no validation reuse of `CustomDifficulty::fromInput()` and no bounds enforcement (e.g. `./bin/candy-mines 0 0 -5` bypasses `CustomDifficulty`'s 2-50 row/col checks and instead throws a much less friendly `Board` exception, or worse, `mineCount` bounds letting `mineCount > width*height-1` produce an unsolvable/degenerate board with `mineCount <= width*height-9` not enforced since `Board`'s own check is looser, `src/Board.php:38-40`).
- **No in-game difficulty selector.** `Difficulty::EASY/MEDIUM/EXPERT` presets (`src/Difficulty.php`) and `Game::withDifficulty()` (`src/Game.php:53-56`) exist and are documented in README, but the CLI entrypoint never exposes them — a user must know exact width/height/mine counts to hit a "difficulty" that `Difficulty::fromDimensions()` can recognize for stats purposes. There's no `--easy/--medium/--expert` flag or startup menu.
- **`r` (restart) discards the in-progress game's result.** `Game::update()` restart branch (`src/Game.php:101-105`) creates a fresh `Game::withCustom(...)` without ever calling `recordResult()` on the game being replaced, so a restart after a loss (or even after a win, since restart isn't blocked in the post-game state — the post-game gate at `src/Game.php:106-109` only blocks other keys, `r`/`q` still work) silently loses that outcome even if the stats wiring above were fixed.
- Save/restore mid-game (`Board::serialize()/unserialize()`, `src/Board.php:224-305`) is fully implemented and tested but likewise has no caller in `bin/`/`examples/` — no keybinding to save or load a game session, despite README documenting it as a feature ("Save / restore mid-game").

### 2. Performance concerns

- `Board::flagCount()` (`src/Board.php:207-216`) does a full `width×height` scan and is called on every render frame via `Renderer::status()` (`src/Renderer.php:202,205`). Unlike `revealedCount`, which is deliberately tracked as an O(1) counter (per `CALIBER_LEARNINGS.md` pattern `o1-win-revealedCount`), flag count is not — inconsistent with the lib's own stated design goal, and scales linearly with board size on every single keystroke/frame (worst case 50×50 custom board = 2500-cell scan per render).
- `Renderer::resolveClick()` (`src/Renderer.php:175-190`) rebuilds the entire marked interior buffer (`self::interior($g, mark: true)`) and re-runs `Scanner::scan()` on every mouse click, i.e. a full `width×height` buffer construction + scan per click, duplicating work already done by the just-rendered unmarked frame. For large boards this doubles per-click render cost; could be memoized/cached alongside the last rendered frame instead of rebuilt fresh.
- `bin/candy-mines` and `examples/play.php` both enable `MouseMode::CellMotion` (`bin/candy-mines:29`, `examples/play.php:16`), which streams continuous mouse-motion `MouseMsg` events (not just clicks) to `update()`. `Game::onMouse()` correctly no-ops on non-`MouseClickMsg` (`src/Game.php:205-207`), so no board rebuild happens on motion — but every motion event still returns `[$this, null]` from `update()`, and if the outer `Program` loop repaints on every `update()` call regardless of model identity, this could cause needless full-frame re-renders on every pixel of mouse movement. Worth confirming candy-core's `Program` skips repaint when the returned model is `===` the same reference.

### 3. Test coverage gaps

- No test exercises `DifficultyStats::load()` against syntactically-invalid JSON (a truncated/corrupted file). `load()` (`src/Stats/DifficultyStats.php:39-76`) calls `json_decode(..., JSON_THROW_ON_ERROR)` **without** a try/catch, unlike `Board::unserialize()` which explicitly catches `\JsonException` and rethrows as `\InvalidArgumentException` (`src/Board.php:255-259`). A corrupted stats file will crash with an uncaught `\JsonException` instead of the documented `\RuntimeException`. `tests/DifficultyStatsTest.php:110-127` only covers a structurally-valid-but-wrong-type field, not malformed JSON syntax — this gap in tests mirrors a real gap in the code.
- No test drives `Game::recordResult()`/`DifficultyStats` through the actual `update()` loop end-to-end (i.e., proving stats get recorded automatically on a real win/loss transition) — because, per finding #1, no such wiring exists to test.
- No test for `CustomDifficulty::fromInput()` being invoked from the CLI/bin path — it's only unit-tested in isolation (`tests/CustomDifficultyTest.php`), consistent with it being unreachable from the actual program.
- No dedicated `RendererTest.php` for `status()` text (exploded/won/in-progress messages) or `formatTime()` boundary values (e.g., negative elapsed via clock skew, `>=3600s` games) — only exercised indirectly through golden-file snapshots (`tests/GoldenRenderTest.php`) which pin specific board states, not the status-line logic in isolation.
- `Board`'s own `mineCount` bound (`mineCount > width*height-1`, `src/Board.php:38`) is looser than `CustomDifficulty`'s (`rows*cols-9`, `src/Ui/CustomDifficulty.php:58`) — no test asserts which one the CLI path actually enforces, and per finding #1 the CLI bypasses `CustomDifficulty` entirely, so a board can be constructed with `mineCount == width*height-1` (leaving only 1 safe cell), defeating the "safe 3×3 first click" guarantee the README promises. Worth a regression test plus a fix to route CLI input through `CustomDifficulty` (or tighten `Board`'s own bound).

### 4. Missing .vhs demos

None — `.vhs/play.tape` and `.vhs/flagging.tape` (with matching `.gif`s) exist and cover core gameplay + flagging. `candy-mines` is present in `.github/workflows/vhs.yml`'s hand-maintained matrix (line 151, 225). No gap here; a `custom-difficulty` or `chord-click` tape could be added given those are documented features, but this is a nice-to-have, not a gap against the checklist.

### 5. Security concerns

None significant — no network I/O, no shell/process invocation, no user-supplied file paths from an untrusted source (stats file path is caller-controlled, not attacker-controlled input). The one defensive-coding gap is the uncaught `\JsonException` in `DifficultyStats::load()` noted in Test Coverage Gaps above — a robustness issue (crash on corrupt local file) rather than an exploitable security hole.

### 6. Functionality duplicated with other libs

- The "atomic tmp-file + rename" JSON persistence pattern in `src/Stats/DifficultyStats.php:118-159` (documented in `CALIBER_LEARNINGS.md` as `pattern:atomic-json-tmp-rename`, "the Homestead pattern") is independently reimplemented in at least six other libs: `candy-hermit/src/History/FileHistory.php`, `candy-metrics/src/Backend/PrometheusFileBackend.php`, `sugar-dash/src/Modules/Weather/WeatherModule.php`, `sugar-dash/src/State/Persistence.php`, `candy-wish/src/Middleware/RateLimit.php`, `sugar-crush/src/Hooks/BuiltIn/AuditHook.php`, `sugar-readline/src/History/FileHistory.php`. This is a repo-wide cross-cut, not specific to candy-mines, but candy-mines is one more copy of logic that arguably belongs in `candy-core` or `candy-freeze` as a shared `AtomicJsonFile` helper rather than being hand-rolled per lib.
- No overlap found with other game/app libs (`sugar-crush`, `sugar-dash`, `candy-mosaic`, `candy-flip`) at the domain-logic level — board/flood-fill/chord logic is minesweeper-specific and not duplicated elsewhere in the monorepo.

### 7. Documentation gaps

- README's "Architecture" table (`README.md:38-58`) documents `Stats`, `DifficultyStats`, and mid-game save/restore as if they're active, integrated features ("Serialises to versioned JSON for mid-game save/load", "Save / restore mid-game" section at `README.md:58`) — but per finding #1 none of these are reachable from the shipped `bin/candy-mines` or `examples/play.php`. This overstates the lib's actual runtime behavior; the README should either note these are library-level building blocks not yet wired into the CLI, or the CLI should be updated to use them.
- README documents `Game::withDifficulty(Difficulty::$LEVEL)` (`README.md:18`) but the actual API is `Game::withDifficulty(Difficulty $d, ?\Closure $rand = null)` (`src/Game.php:53`) — the `Difficulty::$LEVEL` static-property-style syntax in the README doesn't match the enum-case syntax (`Difficulty::EASY`) used everywhere else including the same README's own architecture table; likely a copy-paste artifact from the upstream Go docs (`go-sweep` uses a different idiom) that wasn't adapted to PHP enum syntax.
- No CLI usage documented for difficulty presets or custom-difficulty validation limits (2-50 rows/cols, 1 to rows×cols−9 mines) — a user reading the README has no way to discover `Difficulty`/`CustomDifficulty` exist unless they read `src/`.
- `CALIBER_LEARNINGS.md` and README are otherwise thorough and accurate for the parts of the system that *are* wired up (win detection, flood-fill, chord, mouse zones).

---

## candy-mold

**Scope note**: candy-mold is not a 1:1 upstream port. It's a `composer create-project` scaffold/skeleton — the closest upstream analog referenced in-code is `charmbracelet/bubbletea-app-template` (comment at `candy-mold/src/Counter.php:49`). Its "actual purpose" is to hand a new SugarCraft app author a minimal, runnable `Model` + `bin/start` + tests, not to implement a feature-complete library. Findings below are scoped accordingly — most "missing functionality" categories don't apply the way they would for a real port.

### Missing/incomplete functionality

- No functionality gaps vs. its stated purpose. `src/Counter.php` implements the full `Model` contract (`init`/`update`/`view`/`subscriptions`) and `bin/start` wires it through `Program::run()`. `README.md:78-96` documents an opt-in panic-handler integration point (commented out in `bin/start:35-36`) rather than shipping it live — intentional, to keep the default skeleton dependency-light (no `candy-log` requirement).
- `composer.json:32-39` requires only `candy-core` + `candy-sprinkles`, but `repositories[]` (`composer.json:52-102`) lists path-repos for `candy-layout`, `candy-async`, `candy-ansi`, `candy-input`, `candy-pty` too — those are presumably transitive deps of candy-core/candy-sprinkles pulled in for monorepo dev closure, not unused cruft, but worth a spot-check with `php tools/check-path-repos.php --strict-closure` since it wasn't run against this lib specifically in this audit.

### Test coverage gaps

- `tests/CounterTest.php` covers all 5 public `Model` methods (`init`, `update`, `view`, `subscriptions`, plus purity/immutability). Coverage is solid for the demo's scope — no gaps against the code that exists.
- Not covered (because it lives outside `src/`, per `phpunit.xml` `<source><include><directory>src</directory></include>`): `bin/start` itself has zero test coverage — the autoloader-discovery loop (`bin/start:17-27`) and the `Program` wiring (`bin/start:41`) are unexercised by any test or CI smoke check. A minimal "does `php bin/start --version`-style smoke invocation exit cleanly" check is absent; low priority for a template repo but it's the one file every `composer create-project` user runs first.

### Missing .vhs/*.tape demos

- None missing. `.vhs/start.tape` exists and matches the `bin/start` counter flow (Up x4, Down, quit) per `vhs-tape.md` conventions, though it uses `FontSize 16`/`Width 700`/`Height 240` (a third size variant beyond the two documented standards of 14/800/480 and 16/600/180 — minor convention drift, not a defect).
- Confirmed present in `.github/workflows/vhs.yml:148` (bash array) and `:225` (matrix `lib:` list), so CI renders it.

### Documentation gaps

- `candy-mold/CALIBER_LEARNINGS.md` is **missing entirely** — every other lib skeleton per `AGENTS.md`'s "Adding a lib" checklist and root `CLAUDE.md` layout section calls for one. No patterns/gotchas file exists in this directory to seed session learnings.
- `README.md` is otherwise thorough (anatomy of a Model, common-next-steps dependency table, panic-handler opt-in, testing instructions) — no other gaps found.
- Root wiring is complete and consistent: `docs/MATCHUPS.md:72`, `PROJECT_NAMES.md:108`, `docs/index.html:149,644-653,1047-1048,1180-1182`, `docs/lib/candy-mold.html` (exists), `media/icons/candy-mold.png` (exists), root `composer.json:60,213` (require + path-repo), `codecov.yml:96-97,332-337` (flag + component) — all present and correctly cross-referenced. No documentation drift found in these cross-cut files.

### Functionality duplicated with other libs

- None found. `candy-mold` is the only lib in the monorepo with a `bin/start` executable + `composer create-project` skeleton shape (`grep -rl "bin/start" */composer.json` returns only `candy-mold/composer.json`). The `Counter` Model itself is a thin, deliberately-minimal demo — its up/down/quit key handling duplicates no shared logic since `candy-core`'s `KeyMsg`/`KeyType`/`Cmd::quit()` are the primitives it composes, not something it reimplements.

### Performance concerns

- None applicable — the entire `src/` is a single ~40-line immutable Model with O(1) `update()`/`view()`. No loops, no I/O, no allocation patterns worth flagging.

### Security concerns

- None applicable — no templating/rendering-injection surface despite the "Mold" name suggesting scaffolding; it does not parse or interpolate untrusted template strings. `view()` (`src/Counter.php:65-72`) only formats an internal `int` via `sprintf` into a `Style::render()` call — no user-controlled input reaches string interpolation or shell/file operations. `bin/start`'s autoload-path resolution (`bin/start:18-24`) only checks fixed relative candidates, not user input.

---

## candy-mosaic

Scope confirmed: image-to-terminal-cell renderer, port of `charmbracelet/x/mosaic`. Decodes PNG/JPEG/static-GIF via ext-gd and encodes to Sixel, Kitty graphics protocol, iTerm2 OSC 1337, half-block/quarter-block Unicode, ASCII-ramp, or an external Chafa fallback. Also owns an `Animation`/`AnimationDriver` layer (SugarCraft addition, no upstream equivalent) and caching (`AdaptiveImage` in-memory LRU, `DiskCache` on-disk LRU). 30 renderer/support classes, 27 test files — this is a mature, well-hardened lib; most obvious gaps are already called out in its own `CALIBER_LEARNINGS.md` as known limitations.

### Missing/incomplete functionality

- `src/KittyOptions.php:22-24` (`cellWidth`/`cellHeight` props, used at `KittyOptions.php:143-144` to emit Kitty's `s=`/`v=` fields) have no public setter — no `withCellWidth()`/`withCellHeight()` method exists, so `s`/`v` are permanently `0`/`null` in `toArray()`. The constructor plumbing exists but is dead code from the public API's perspective; `s=`/`v=` (original image pixel dimensions, needed when transmitting raw pixel data rather than PNG) can never actually be set.
- `AnimationDriver::update()` (`src/AnimationDriver.php:64-79`) does not check `$this->paused` before advancing the frame index or scheduling the next tick — only `init()` (line 46-56) checks it. If a `FrameTickMsg` is already in flight when `withPaused(true)` is applied, the driver still advances past the frame the caller intended to pause on, and re-schedules another tick, silently un-pausing. `AnimationDriverTest.php` (10 tests) does not appear to cover pause after an in-flight tick (test-count too low to confirm exhaustively without reading the file, but no such scenario is implied by the AGENTS.md pattern of behaviour tests here).
- `CALIBER_LEARNINGS.md:33-37` and `:83-93` already document, as intentional/deferred: (a) no GIF→`Animation` bridge from `candy-flip` frames (see Duplication section), (b) no process-forking `AsyncRenderer` (parallelism), (c) no cancellation support for in-flight `AsyncRenderer::renderAsync()` promises.
- No max-resolution / max-frame-count guard for `Animation` — an attacker-controlled frame list of very large `ImageSource`s has no size cap (relates to decompression-bomb concern below).

### Performance concerns

- No decompression-bomb / dimension cap anywhere in the image-decode path: `ImageSource::fromFile()` (`src/ImageSource.php:38-88`), `::fromString()` (`:106-133`), `::fromGd()` (`:180-211`) all call GD decode functions (`imagecreatefrompng`/`imagecreatefromjpeg`/`imagecreatefromgif`/`imagecreatefromstring`) directly on attacker-controllable bytes with no check on declared pixel dimensions before allocation. A tiny compressed file (e.g. a PNG with a huge IHDR-declared width×height) will make GD allocate `width*height*4` bytes uncontrolled — classic memory-exhaustion DoS. `getimagesize()` is called first in `fromFile()` (`:53`) but its result is only used for MIME sniffing, not a size gate. Worth adding an explicit `width*height` ceiling check before the GD decode call, especially since `fromUrl()`/`fromUrlAsync()` feed remote, attacker-influenced bytes into the same path.
- Otherwise the hot loops are already well-optimized and self-documented in `CALIBER_LEARNINGS.md`: `SixelRenderer` samples ≤4096 pixels for median-cut (`src/Renderer/SixelRenderer.php:165-188`) rather than every pixel, and memoizes nearest-color lookups on a coarse 5-bit RGB cube key capped at 32768 entries (`:342-346`, `:405-408`) rather than O(pixels). `AdaptiveImage` (in-memory) and `DiskCache` (on-disk, atomic writes, mtime-LRU eviction) both already exist and are exactly the caching the audit prompt asks about — no gap here.
- `PixelGrid::fromGd`/`fromGdQuarter` (`src/PixelGrid.php:46-98`, `:129-177`) call `imagecolorat`/`imagecolorsforindex` per output cell (not per source pixel), so cost scales with terminal cell count, not source resolution — fine.
- `ChafaRenderer::render()` (`src/Renderer/ChafaRenderer.php:82-136`) writes the full image to a fresh `tempnam()` file per call with no reuse/caching; for animation frames driven through `AnimationDriver` this is a per-frame disk round-trip. Minor; `chafa --version` availability probe is already correctly memoized (`:31,50-80`).

### Test coverage gaps

- No dedicated test file for `Capability.php`, `CellSize.php`, `PrecomputedImage.php`, or `Concerns/RenderValidationTrait.php` — all are exercised only indirectly via other tests (`MosaicAutoTest`, `DetectTest`, `AdaptiveImageTest`). `PrecomputedImage` in particular is a trivial accessor DTO but has zero direct assertions on its three getters.
- `KittyOptions::toArray()`/`isPlace()`/`useVirtual()` are covered by `KittyOptionsTest.php` (7 tests), but since `cellWidth`/`cellHeight` have no public setter (see above), there is necessarily no test exercising non-null `s=`/`v=` output — the dead code path is untested because it's unreachable.
- `AnimationDriver`'s pause-during-in-flight-tick interaction (see Missing functionality above) has no apparent regression test.
- `Mosaic.php` itself (526 lines, the public facade) has no single `MosaicTest.php` — coverage is split across `MosaicAutoTest.php` (189 lines/13 tests, auto-detection paths), `MosaicBuilderTest.php` (102 lines/7 tests), `MosaicScaleTest.php` (116 lines/9 tests). Between them the `render()`/`applyScale()`/`adaptive()`/`precompute()` methods look covered, but there's no test file whose name signals "here's where Mosaic's core render() contract lives," making it easy for a future change to Mosaic.php to land without an obvious place to add a regression test.

### Security concerns

- **Decompression bomb / memory exhaustion**: see Performance section — `ImageSource::fromFile/fromString/fromGd` have no pixel-dimension ceiling before GD decode. This is the most actionable finding in the whole audit.
- **SSRF / local-file-read via `fromUrl()`/`fromUrlAsync()`**: already documented and deliberately scoped in `README.md:102-106` and doc-comments at `src/ImageSource.php:221-227` — default `$allowedSchemes = ['http','https']` blocks `file://`, but callers can pass `null` to disable validation entirely (`src/ImageSource.php:237,247-263`), and even with http/https allowed, `fetchUrlSync()` (`:326-355`) follows up to 5 redirects (`max_redirects: 5` at `:332`) with no post-redirect scheme/host re-validation — a same-scheme redirect to `http://169.254.169.254/...` (cloud metadata) is not blocked. This is called out as a caller-responsibility tradeoff in the docs, not a silent gap, but worth flagging as still-live risk since the mitigating advice ("only pass validated URLs") is easy to skip.
- Header-injection (CRLF) protection is present and tested (`formatHeaders()`, `src/ImageSource.php:368-380`).
- `DiskCache` key hashing (`src/DiskCache.php:270-273`) correctly prevents path traversal from attacker-supplied keys — no issue found.
- `ChafaRenderer` uses `tempnam(sys_get_temp_dir(), 'chafa')` (`src/Renderer/ChafaRenderer.php:93`) and `proc_open` with an argument array (not a shell string), so no command-injection risk from image bytes or CLI option strings passed through `$options`/`$format` — those are developer-supplied, not attacker-supplied, per the constructor's usage pattern.

### Duplication with candy-flip/candy-freeze

- No harmful duplication found. `candy-flip` (`Decoder.php`, `Player.php`, `Frame.php`, `Renderer.php`) owns animated-GIF *decoding* (multi-frame GIF parsing); `candy-mosaic`'s `ImageSource` only decodes *static* images (including single-frame GIF via `imagecreatefromgif`). `CALIBER_LEARNINGS.md:33-37` explicitly documents this boundary and that `candy-mosaic` does not depend on `candy-flip`.
- The gap this creates: there is no shipped bridge converting `candy-flip`'s decoded GIF frames into `list<ImageSource>` for `Animation::fixed()` — a user wanting to play an animated GIF through Mosaic's protocol-aware renderers must hand-roll that glue themselves. `CALIBER_LEARNINGS.md` flags this as "a future GIF→Mosaic bridge... if needed" — i.e. a known, accepted gap rather than an oversight.
- `candy-freeze` (`PngRenderer.php`, `SvgRenderer.php`) renders terminal *output* to PNG/SVG (the inverse direction — ANSI screenshot to image) and shares no code paths with candy-mosaic's image-to-terminal pipeline. No overlap.

### Documentation gaps

- Only one `.vhs/*.tape` demo (`inline-image.tape`) exists, driving the single `examples/inline-image.php` script through the iTerm2 path only (per the tape's own comment, "VHS emulates an iTerm2-compatible terminal"). Given the library exposes 6 distinct render protocols (kitty/sixel/iterm2/halfblock/quarterblock/chafa) plus dithering and the whole `Animation`/`AnimationDriver` subsystem, there is no visual demo of: Sixel dithering options, Kitty virtual-image placement/zlib compression, quarter-block density, ASCII-ramp mode, or an animation loop. `AGENTS.md`'s VHS convention treats one tape per notable demo scenario as the norm elsewhere in the repo; this lib under-demos its own most distinctive feature set (multi-protocol rendering).
- README.md is otherwise thorough (Quickstart, API, remote-image security callout, DiskCache, KittyOptions, protocol-delete table, Animation, Architecture diagram) — no gaps found there beyond the missing `s=`/`v=` (cellWidth/cellHeight) documentation, which is consistent with that feature being unreachable via the public API (see Missing functionality).
- `CALIBER_LEARNINGS.md` is unusually good — already documents most of the subtle gotchas found in this audit (GD alpha/interpolation, sixel quantization limits, animation architecture decisions, and known async limitations) before this audit even started.

---

## candy-mouse

### Scope correction (important)

candy-mouse is **not** an X10/URXVT/SGR mouse-protocol decoder. It is a self-contained
bubblezone (`lrstanley/bubblezone`) hit-testing port: `Mark` (wrap content with PUA
sentinels U+E000/U+E001), `Scan`/`Scanner` (find zone bounding boxes), `Zone`
(bounding-box + `inBounds()`/`pos()`), and `ZoneClickTracker` (press/release
dedup). It never touches raw terminal escape bytes — `candy-mouse/src/MouseEvent.php:13-52`
takes already-decoded `(x, y, button, action)` tuples. All actual protocol decoding
(SGR 1006, modifier keys, scroll direction) lives in `candy-input/src/Event/MouseEvent.php`.
So most of the "missing X10/URXVT/SGR/modifier" scope named in the audit brief belongs to
candy-input, not candy-mouse — see the collision section below for the exact overlap.

### Functionality duplicated with candy-input / candy-zone

- **`MouseEvent` name collision, confirmed, different purpose.**
  - `candy-mouse/src/MouseEvent.php:13-20` — `final readonly class MouseEvent` in
    `SugarCraft\Mouse`, fields `x, y, button, action` (action is the local
    `MouseAction` enum: Press/Release/Drag/Scroll). No modifiers, no raw-byte decoding.
    Factories: `press()`, `release()`, `drag()`, `scroll()` (`MouseEvent.php:25-52`).
  - `candy-input/src/Event/MouseEvent.php:16-47` — `final readonly class MouseEvent`
    in `SugarCraft\Input\Event`, implements `Event`, fields `x, y, button, action`
    (action is a bare `string`), **plus `public KeyModifier $modifiers`**
    (`MouseEvent.php:46`). Documents SGR 1006 origin, has button constants including
    `BUTTON_RELEASE = 3` and scroll-specific factories `scrollUp()`/`scrollDown()`
    encoding SGR button codes 96/97 (`MouseEvent.php:54-64`).
  - The two classes are unrelated (different namespaces, no shared interface, no
    conversion helper between them) but are conceptually the same domain object
    with diverging fields (modifiers vs. none; enum vs. string action). A consumer
    wiring `candy-input`'s raw decoder into `candy-mouse`'s zone tracker must
    hand-write a mapper; no such mapper exists in either lib.
  - `MouseAction` (`candy-mouse/src/MouseAction.php:28-30`) duplicates
    `candy-input\Event\MouseEvent::BUTTON_LEFT/MIDDLE/RIGHT` constants verbatim
    ("mirrors candy-input convention" per the doc comment at line 27), but as a
    second, separately-maintained copy — a drift risk if candy-input's button
    numbering ever changes (e.g. adding a 4th/5th button).

- **Whole-library duplication with candy-zone.** `candy-zone` (`candy-zone/src/Manager.php`,
  `Zone.php`, `Zones.php`, `ClickCounter.php`, `DragTracker.php`, `ZoneHoverTracker.php`)
  is *also* a full bubblezone port — Manager-based marker/scan/get, click counting
  (single/double/triple click), drag tracking, hover tracking, using APC (`ESC _ ... ESC \`)
  markers. candy-mouse re-implements the same Mark/Scan/Get pattern independently with
  PUA-codepoint markers and its own `ZoneClickTracker` for press/release dedup
  (`candy-mouse/README.md:9-11` explicitly frames candy-mouse as "replaces the model
  where consumers wire candy-zone's Manager externally"). Two parallel, non-interoperable
  zone-tracking implementations exist in the monorepo with different marker encodings
  (PUA vs APC) and different click-semantics feature sets (candy-zone has double/triple
  click + drag + hover; candy-mouse only has press/release dedup). No shared `Zone`
  interface/type — `SugarCraft\Mouse\Zone` and `SugarCraft\Zone\Zone` are two distinct
  classes.

- **Unused path-repo entries.** `candy-mouse/composer.json` declares path repositories
  for `candy-ansi`, `candy-async`, `candy-input`, `candy-pty` (lines 30-52) but only
  `candy-core` appears in `require` and only `SugarCraft\Core\...` is ever imported in
  `src/` (`Scan.php:7`, `Lang.php:7` — confirmed via grep, no `SugarCraft\Input`,
  `SugarCraft\Pty`, `SugarCraft\Async`, or `SugarCraft\Ansi` references anywhere in
  `src/` or `tests/`). These look like leftover scaffolding from an earlier design
  that planned to consume candy-input's decoded events directly.

### Missing/incomplete functionality

- No scroll direction. `MouseAction::Scroll` (`MouseAction.php:24`) is a single case;
  unlike candy-input's `scrollUp()`/`scrollDown()`, candy-mouse has no up/down
  distinction — the `button` field is left to the caller to overload with meaning,
  undocumented.
- No modifier-key support at all (no Shift/Ctrl/Alt on any `MouseAction`), consistent
  with candy-mouse being a post-decode hit-tester, but worth flagging since
  `ZoneClickTracker`'s click semantics (e.g. distinguishing a plain click from a
  Ctrl-click for multi-select) can never be built on top of this event shape without
  first bolting on modifiers.
- No double/triple-click detection (candy-zone's `ClickCounter` has this;
  candy-mouse's `ZoneClickTracker` only dedups a single press+release pair,
  per its own state-machine doc comment at `ZoneClickTracker.php:12-24`).
- No hover/enter/exit tracking (candy-zone has `ZoneHoverTracker`, `ZoneEnterMsg`,
  `ZoneExitMsg`); candy-mouse has no equivalent.
- No drag-move tracking beyond suppression — `ZoneClickTracker::track()` simply
  discards `Drag` events (`ZoneClickTracker.php:56-58`); candy-zone's `DragTracker`
  emits `ZoneDragStartMsg`/`ZoneDragMoveMsg`/`ZoneDragEndMsg`. A caller wanting
  drag-and-drop UI on candy-mouse zones has nothing to build on.

### Performance concerns

- `Scanner::hit()` is O(n) linear scan over all zones per call, self-documented at
  `candy-mouse/src/Scanner.php:85-93` with candidate mitigations (grid bucket, R-tree,
  area-sort) explicitly deferred — flagged as a known limitation, not fixed.
- `Scan::parse()`'s close-sentinel lookup, `strpos($rendered, Sentinel::CLOSE, $i + 3)`
  at `candy-mouse/src/Scan.php:74`, scans forward to the next close sentinel (or end
  of string if none exists) **every time an open sentinel is encountered**. Adversarial
  or buggy input with many unmatched open sentinels (no corresponding close) makes each
  occurrence's `strpos` scan to end-of-string, giving O(n²) worst case on `$rendered`
  length × unmatched-open count. Not covered by a test (`testParseUnterminatedSentinelIsSkipped`
  at `ScanTest.php:78` only checks one unterminated case, not many).
- CALIBER_LEARNINGS.md documents this class of gap explicitly under "O(n) Scanner::hit()"
  and "Memoization opportunity for repeated scans" — acknowledged, unresolved.
- Synchronous-only design confirmed (CALIBER_LEARNINGS.md, "Synchronous-only design"
  section) — no ReactPHP integration, consistent with this being a pure CPU-bound
  parser/hit-tester, not necessarily a defect.

### Security concerns (malformed mouse escape sequences)

- Scope mismatch: candy-mouse never parses actual terminal mouse escape sequences
  (ESC `[<...M`/`m` etc.) — that risk surface belongs entirely to candy-input's
  decoder, which is outside this audit's target directory.
- Within candy-mouse's own input surface (rendered strings containing sentinel
  markers), `Scan::parse()` handles unterminated/lone sentinels gracefully (tests at
  `ScanTest.php:78` `testParseUnterminatedSentinelIsSkipped`, `:149`
  `testParseLoneCloseSentinelNotPrecededByZoneOpen`) — no crash observed for those
  cases.
- Duplicate zone `id`s are not validated: `Scan::parse()`'s `$this->open[$id] = [...]`
  (`Scan.php:109`) silently overwrites any prior open entry with the same id, and a
  close sentinel resolves against whichever open entry currently holds that id. Two
  concurrently-open same-id zones (malformed markup, e.g. from untrusted
  dynamically-generated content) will produce one merged/incorrect bounding box
  rather than an error — not a crash but a silent-corruption path with no test
  covering it.
- The O(n²) worst case documented above (many unmatched opens) is itself a mild DoS
  vector if `Scan::parse()` is ever fed size-unbounded or attacker-influenced content
  (e.g. reflected user text wrapped in `Mark::wrap()` without sanitization upstream).
- CSI/OSC pass-through loops (`Scan.php:116-127`, `130-139`) are bounded by `$len` and
  terminate correctly on malformed/unterminated sequences (loop exits at end of
  string) — no infinite-loop risk found.

### Documentation gaps

- `README.md` never mentions the `MouseEvent` naming collision with candy-input, nor
  clarifies that candy-mouse consumes already-decoded events rather than raw escape
  bytes — a new consumer reading only this README could reasonably assume candy-mouse
  does protocol decoding.
- `README.md:11` mentions candy-zone only in passing ("Replaces the model where
  consumers wire candy-zone's Manager externally") without explaining that the two
  libraries are functionally overlapping alternatives with different feature sets
  (no double/triple-click, hover, or drag in candy-mouse) — a reader can't tell which
  to pick without reading both source trees.
- No migration/adapter guidance for converting a `candy-input\Event\MouseEvent` into a
  `candy-mouse\MouseEvent` (or vice versa) despite the README's quickstart implying
  candy-mouse is meant to consume mouse events from elsewhere in the pipeline.
- Unused composer path-repos (candy-ansi/candy-async/candy-input/candy-pty) are not
  explained or flagged as dead/planned-future wiring.
- `lang/en.php` is an empty translation stub with no keys (`candy-mouse/lang/en.php`) and
  `Lang.php` is unused anywhere in `src/` outside its own definition — the i18n
  scaffolding exists but nothing in candy-mouse actually calls `Lang::t()`; not
  documented as intentionally unused boilerplate.

### Missing .vhs demos

- No `.vhs/` directory and no `examples/` directory exist under `candy-mouse/`, and
  `candy-mouse` does not appear in `.github/workflows/vhs.yml`'s hand-maintained
  `all=(...)` matrix. This is plausibly correct exemption (candy-mouse is a
  non-rendering logic library, similar to candy-pty/candy-testing per AGENTS.md), but
  unlike candy-pty it is not explicitly called out anywhere as intentionally exempt —
  worth a one-line note in README or CALIBER_LEARNINGS.md confirming the exemption is
  deliberate rather than an oversight.

---

## candy-palette

Scope correction up front: candy-palette is a port of `charmbracelet/colorprofile` — a **color-profile detection / degradation** library (TrueColor→ANSI256→ANSI→ASCII→NoTTY), not a curated named-theme/palette library. It has no `dracula()`, `nord()`, `tokyoNight()`, etc. theme factories anywhere in `src/`. See "Duplication" below for how this bears on the candy-sprinkles/candy-kit `dracula()` drift.

### 1. Missing/incomplete functionality

- `StandardColors` (`candy-palette/src/StandardColors.php:17-99`) only covers the classic 16-color ANSI palette (black..brightWhite). There is no ANSI256 named-index table (e.g. "color 202 = orange"), no CSS/X11 color name table, and no curated palette collection (Dracula/Nord/Solarized/etc.) — despite the class/library being named "Palette", it never defines any named *theme* palettes. If the intent for `candy-palette` is ever to become the SSOT for named color palettes/themes (implied by the sibling candy-kit audit), that functionality plain doesn't exist here yet.
- `Color` (`src/Color.php`) has no HSL/HSV/Lab/OKLab conversions, no CSS `rgb()`/`hsl()` string parsing (only `#rrggbb`/`#rgb` hex via `Color::parse()`, `src/Color.php:54-67`), and no `mix()`/blend/interpolation helpers — common needs for gradient/theme work elsewhere in the monorepo (e.g. `honey-bounce`/`sugar-glow` consumers already reach into this lib per the cross-repo usage grep).
- `AsyncProbe::colorProfile()` (`src/AsyncProbe.php:35-79`) is a fully-implemented ReactPHP-based async probe, but it is **not documented anywhere in README.md** and has **zero test coverage** (see Test coverage section). It's a "hidden" public API.
- `composer.json` requires `sugarcraft/candy-sprinkles` (`composer.json:20`) but nothing in `src/` references `SugarCraft\Sprinkles` — confirmed via `grep -rn "Sprinkles" candy-palette/src/` returning no hits. This is either a stale/unused dependency or a signal that palette↔theme integration (e.g. delegating to candy-sprinkles' `Theme`) was planned but never wired up.

### 2. Performance concerns

- `Probe::infocmpUpgrade()` (`src/Probe.php:110-137`) and `AsyncProbe::colorProfile()`'s subprocess path both shell out to `infocmp` on every call — `Probe`'s version is a **blocking `shell_exec()`** (`src/Probe.php:126`) with no caching of the *result* (only the binary path is cached via `$infocmpPath`, `src/Probe.php:139-149`). Every call to `Probe::colorProfile()` when the chain resolves to `Ansi` re-execs `infocmp` synchronously. In a hot path (e.g. called per-render or per-frame by a consumer) this is a real stall; CALIBER_LEARNINGS.md already flags this as `[pattern:async-gap]` (`CALIBER_LEARNINGS.md:9`) but the sync path is still the default entry point most consumers will reach for.
- `Palette::rewriteAnsi()` (`src/Palette.php:258-290`) uses `preg_replace_callback` with a per-match `Color` object allocation + `convert()` call for every SGR sequence in a string; for large buffers with many color runs (e.g. full-screen redraws) this is O(n) regex scans plus per-match object churn — acceptable for typical TUI frame sizes but worth flagging if `candy-log`/`candy-mosaic` push large buffers through it repeatedly per frame.
- `StandardColors::all()` caches its indexed array (`src/StandardColors.php:41,51-74`) — good — but the 16 `Color` instances themselves are eagerly constructed at file-load time via top-level statements (`src/StandardColors.php:102-117`), which runs on every `require` of the file regardless of whether `StandardColors` is ever used by the consuming request.

### 3. Test coverage gaps

- **`AsyncProbe` has no test file at all.** `tests/` contains no `AsyncProbeTest.php`, and `grep -rn "AsyncProbe" tests/` (including `tests/Probe/`) returns zero matches. The full ReactPHP `Process`/`Deferred` flow (success path, non-zero exit fallback, `error` event fallback, no-loop fallback) in `src/AsyncProbe.php:35-119` is completely unexercised.
- `Probe::_reset()` (`src/Probe.php:156-159`) is a test-only internal reset hook, but `ProbeInfocmpTest.php` only has 2 test methods — thin coverage for the infocmp-upgrade branch (`Probe::infocmpUpgrade`, `src/Probe.php:110-137`) which has several distinct branches (no infocmp binary, infocmp present but no Tc/RGB, infocmp present with Tc, with RGB, empty/null TERM).
- Full suite run: 171 tests / 355 assertions, all green (`cd candy-palette && vendor/bin/phpunit`) — so existing coverage is solid everywhere *except* `AsyncProbe`.

### 4. Missing .vhs demos

- None — all four examples (`convert.php`, `degrade.php`, `detect.php`, `standard-colors.php`) have matching `.tape`/`.gif` pairs in `.vhs/`. No gap here.

### 5. Security concerns

- `Probe::infocmpUpgrade()` (`src/Probe.php:126`) and `AsyncProbe::colorProfile()` (`src/AsyncProbe.php:53,55`) both shell out with the `TERM` env var passed through `escapeshellarg()` — properly escaped, no injection vector found.
- `AsyncProbe` restricts the binary path to a hardcoded allowlist (`/usr/bin/infocmp` or `/bin/infocmp`, `src/AsyncProbe.php:115-116`, mirrored in `src/Probe.php:146-147`) rather than trusting `$PATH`, which is a good defensive choice — avoids PATH-hijacking of `infocmp`.
- No user-controlled input reaches `Palette::stripAnsi()`/`rewriteAnsi()` regexes in a way that looks exploitable (ReDoS risk is low given bounded quantifiers), but neither function has an input-length guard; a pathological string with millions of `\x1b[` fragments would be an unbounded regex-callback cost (self-inflicted, not obviously attacker-reachable in typical TUI usage).

### 6. Duplication with candy-sprinkles / candy-kit

- **candy-palette does NOT define a `dracula()` theme and is not a third source of truth.** Confirmed via `grep -rn "dracula" candy-palette/` — zero hits anywhere in the library (src, tests, README, examples). Only two `dracula()` factories exist in the monorepo:
  - `candy-sprinkles/src/Theme.php:92-109` — semantic `Theme` value object (foreground/background/primary/secondary/accent/muted/error/warning/success/info/border/separator/cursor), hex values e.g. `background: Color::hex('#282a36')`, `primary: Color::hex('#bd93f9')`.
  - `candy-kit/src/Theme.php:163-174` — a *different* `Theme` shape (Style-wrapped success/error/warn/info/prompt/accent/muted), reusing several of the same hex values (`#50fa7b`, `#ff5555`, `#8be9fd`, `#bd93f9`, `#6272a4`) but a materially different field set (no `background`/`foreground`/`border`/`cursor`; adds `warn`/`prompt` as bold `Style` objects instead of plain `Color`).
  - candy-palette's own `Color` class (`src/Color.php`) is structurally compatible with both (`r`,`g`,`b`,`a`, `parse()`/`fromHex()`), but nothing in either `candy-sprinkles/src/Theme.php` or `candy-kit/src/Theme.php` imports `SugarCraft\Palette\Color` — they use their own local `Color::hex()` (from `candy-sprinkles`/`candy-ansi`, not candy-palette's `Color`). So there are actually **three distinct `Color` value-object families** in play across sprinkles/kit/palette, not one SSOT — candy-palette's `Color` is orthogonal (profile-degradation-focused) rather than a shared base the other two extend.
  - Verdict for the sibling audit: the sprinkles-vs-kit `dracula()` drift is real and independent of candy-palette; candy-palette is neither the canonical source nor a third drifted copy — it simply doesn't participate in named-theme duplication at all. If a canonical `dracula()`/theme SSOT is desired, candy-palette's `Color` would need to become the shared RGBA primitive both `Theme` classes build on (it currently isn't), and a dedicated theme-catalog module would need to be added to *some* lib (candy-palette is a reasonable structural home given its name, but today it has no such catalog).
- Separately, `Palette`'s env-detection logic is intentionally layered/duplicated *within* candy-palette itself across three call sites (`Palette::detectProfile()` in `src/Palette.php:193-248`, `Probe::colorProfile()` in `src/Probe.php:34-56`, and `DetectionChain::detect()` in `src/DetectionChain.php:54-130`) — `DetectionChain` is documented as the shared SSOT that both `Palette` and `Probe` delegate to (`src/DetectionChain.php:8-13`), so this is intentional de-duplication already done, not a gap, but it means three subtly different precedence orderings (Palette's `TERM=dumb` before `COLORTERM`, vs. DetectionChain's `COLORTERM` before `TERM=dumb`, per the comment at `src/DetectionChain.php:81-83`) exist for historical/compat reasons — worth a doc note since it's easy to assume they're identical.

### 7. Documentation gaps

- README.md documents `Palette`, `Probe`, `ColorProfile`, `Color`, `ProfileWriter`, `StandardColors`, `Lang` in the Architecture diagram (`README.md:144-156`) but **omits `AsyncProbe`, `DetectionChain`, `Profile\Probe\Capability`, `Probe\ProbeReport`, and `Probe\TerminalProbe`** entirely — five public classes with no README mention.
- No mention of the `react/child-process` hard dependency (`composer.json:20`) anywhere in README — a consumer installing candy-palette gets ReactPHP pulled in transitively with no explanation of why (it's solely for `AsyncProbe`).
- The precedence-order docblocks are duplicated near-verbatim across `Palette.php:174-192`, `Probe.php:8-25`, and `DetectionChain.php:15-29`, each with slightly different step numbering/wording (e.g. `Palette` step 6 "TERM_PROGRAM=iTerm.app" doesn't appear at all in `Probe`'s or `DetectionChain`'s lists) — a reader diffing the three will reasonably wonder if they're out of sync rather than deliberately scoped differently.
- No architecture doc explains *why* three parallel detection entry points (`Palette`, `Probe`, `DetectionChain`) exist rather than one — `CALIBER_LEARNINGS.md` only documents the SSOT relationship for `Probe`/`ColorProfile` vs. external consumers, not the internal `Palette` vs `Probe` split.

---

## candy-pty

### 1. Missing / incomplete functionality

- **Windows ConPTY: not implemented, honestly flagged.** `Libc::lib()` throws immediately on `PHP_OS_FAMILY === 'Windows'` (`src/Libc.php:51-55`), and `ControllingTerminal::claim()` does the same (`src/ControllingTerminal.php:44-46`). README's own comparison table marks it `❌ planned (v2 sidecar; see plans/x-windows.md)` (`README.md:366`), and `PtySystemFactory` throws `UnsupportedPlatformException::forDeferredBackend()` for the `sidecar`/`pecl` backend values (`README.md:409-410`; confirmed via `docs/CONCEPTS.md:272`). This is consistent with AGENTS.md calling out PHP 8.4+ "for Windows FFI" as aspirational — candy-pty is Linux/macOS-only today despite the repo-wide Windows ambition. Not a bug, but worth flagging since the audit prompt specifically asked about it: there is currently zero Windows PTY code, only the exception scaffolding.
- **Window-size (winsize) ioctl support: present and platform-aware**, including a documented Darwin arm64 workaround. `SizeIoctl` (`src/SizeIoctl.php:31-233`) implements both `TIOCSWINSZ`/`TIOCGWINSZ` with correct platform-divergent request constants and packs/unpacks the kernel `winsize` struct. No gap here beyond the documented `stty` subprocess fallback (see Performance below).
- **Signal forwarding: SIGWINCH and SIGCHLD covered; SIGINT/SIGHUP forwarding is not a `SignalForwarder` responsibility** — it's delegated to the kernel via `ControllingTerminal::claim()` / TIOCSCTTY (`src/ControllingTerminal.php`), which is architecturally reasonable (matches creack/pty), but means `SignalForwarder` itself only exposes `attachSigwinch()`/`attachSigwinchToFd()`/`attachSigchld()` (`src/SignalForwarder.php:74,118,151`) — there's no `attachSigterm`/`attachSighup` passthrough helper for callers that want to propagate host signals into a `Child::kill()` call; callers must wire that themselves. Minor gap, not a bug.
- **Raw-mode termios: implemented via two backends** (`PosixTermios` FFI-based, `src/Posix/PosixTermios.php`; `SttyTermios` shell-out fallback), selected by `TermiosFactory` based on `SUGARCRAFT_TERMIOS` env / ext-ffi availability. `PosixTermios::restore()` is a no-op if `current()`/snapshot was never captured (`src/Posix/PosixTermios.php:90-97`) — silent no-op rather than throwing, which could surprise a caller that assumes `restore()` always undoes `makeRaw()`. Worth a doc note or a `RuntimeException` if `$original` is null and the caller explicitly expects a restore.
- **`Spawn::proc()` is `@deprecated`** (`src/Spawn.php:9-11`) yet is still the only implementation `PosixSlavePty::spawn()` delegates to (`src/Posix/PosixSlavePty.php:48`) — the "use PosixSlavePty::spawn() instead" deprecation notice is misleading since that very method calls the deprecated class internally. This is a documentation/API-hygiene gap, not a functional one.

### 2. Performance concerns

- **`PosixPump::flushMaster()` busy-waits with `usleep(20_000)` for up to `flushDeadlineSec`** even when there is nothing left to flush and the child has already exited by another race (`src/Posix/PosixPump.php:184-196`) — bounded (default deadline is short), but every pump teardown pays at least one 20ms tick when the tail happens to be empty on the first check.
- **`ChildPollTrait::wait()` fallback loop polls every `usleep(10_000)`** (10ms) when the FFI `waitpid()` fast path is unavailable (`src/Posix/ChildPollTrait.php:158-184`) — acceptable and documented as intentional, but every blocking `wait()` on a slow child costs a 0-10ms syscall-poll tail latency; the async `waitAsync()` companion (15ms periodic timer) exists for ReactPHP callers so this is not a project-wide bottleneck.
- **Darwin arm64 `TIOCSWINSZ` falls back to spawning a `stty` subprocess per resize** (`src/SizeIoctl.php:137-214`), rate-limited to one call per 20ms window per `(fd,rows,cols)` triple. A SIGWINCH burst on Darwin arm64 can still fork several `stty` processes per second — documented as an accepted trade-off in README ("Known limitations", `README.md:490-498`) pending macOS shipping POSIX 2024 `tcsetwinsize`.
- **`readPtsName()` allocates a 256-byte FFI buffer per `open()` call** (`src/Posix/PosixPtySystem.php:164-175`) — trivial cost, no pooling concern given `PtyPool` already amortizes the libc handle (documented at `src/PtyPool.php:29-31`, measured 0.05ms/open).
- No other hot-path concerns found — the pump's inner `stream_select` loop, chunked I/O, and FFI handle caching (`Libc::$ffi` static, `src/Libc.php:31,45-70`) are all reasonable for a syscall-wrapper library.

### 3. Test coverage gaps

- **`requirePtySyscalls()` is duplicated verbatim across at least 41 test files** rather than shared via a trait or base TestCase (confirmed via `grep -c` across `tests/**/*.php`; canonical copy at `tests/LibcTest.php:22-33`). Functionally correct (each copy checks Windows/ext-ffi/`/dev/ptmx` readability/writability and calls `markTestSkipped()`), but any future change to the skip conditions — e.g. adding a check for `SUGARCRAFT_LIBC` override reachability — requires touching 41 files instead of one shared helper/trait. This is the single most notable structural gap in the test suite.
- **FFI-gated tests are correctly wired to actually run in CI, not silently always-skipped.** The macOS job in `.github/workflows/ci.yml` is scoped specifically to `candy-pty` (`.github/workflows/ci.yml:311-316`, confirming the `[pattern:macos-ci-scope]` CALIBER_LEARNINGS entry) so the FFI/PTY syscalls in `requirePtySyscalls()`-gated tests exercise real hardware on both Linux (default `ubuntu` runners, where `/dev/ptmx` is normally present) and macOS. This is the intended behavior — no evidence found of the gate always tripping `markTestSkipped()` in CI (that would only happen on `/dev/ptmx`-less sandboxes, e.g. minimal containers, which the README's "Known limitations" section (`README.md:477-489`) explicitly anticipates for *consumer* environments, not CI runners).
- **`Spawn` (the deprecated class) still has its own dedicated test file** (`tests/SpawnTest.php`, `tests/SpawnProcTest.php`) separate from `PosixSlavePty`'s test coverage — reasonable during the deprecation window, but there's no test asserting the deprecation notice fires (e.g. no `E_USER_DEPRECATED` trigger in `src/Spawn.php`, so nothing to test) — the `@deprecated` tag is purely a doc-comment, unenforced at runtime.
- **Windows has zero test coverage** (necessarily, since there's no Windows implementation) — `UnsupportedPlatformException` paths for the `sidecar`/`pecl` backends are presumably tested via `PtySystemFactoryTest.php`, but I did not find dedicated CI coverage running `phpunit` under `PHP_OS_FAMILY === 'Windows'` conditions (the repo's `WINDOWS_LIBS` matrix in `ci.yml` would need to list `candy-pty` for that; not confirmed here — worth a follow-up check against `ci.yml`'s `WINDOWS_LIBS` array, which I did not have time to positively enumerate).

### 4. VHS demo

- **Correctly exempt.** No `.vhs/` directory exists under `candy-pty` (confirmed via `ls`), and `candy-pty` does not appear in the hand-maintained `all=(...)` array in `.github/workflows/vhs.yml` (grep for `candy-pty` in that file returned zero matches, vs. multiple matches in `ci.yml`'s dynamic matrix). This matches AGENTS.md's explicit callout that "non-visual libs (`candy-pty`, `candy-testing`, FFI, codecs) exempt" from VHS. No action needed.

### 5. Security concerns

- **No obvious buffer overruns.** All FFI buffers are fixed-size and bounds-checked at the point of use: `ptsname_r` buffer is 256 bytes with the return code checked before `FFI::string()` (`src/Posix/PosixPtySystem.php:164-175`); `SizeIoctl::pack()` validates all four winsize fields are non-negative before writing them, throwing `\InvalidArgumentException` otherwise (`src/SizeIoctl.php:72-86`); termios buffers are a fixed 80-byte opaque blob (`src/Posix/PosixTermios.php:34,45`) with `FFI::memcpy` calls always bounded to `BUFSIZE`, never to caller-supplied lengths.
- **Unchecked return codes: mostly checked, with a few explicit "best-effort" exceptions that are intentional, not oversights.** `grantpt`/`unlockpt`/`ptsname_r` rc's are checked and turned into `PtyException` (`src/Posix/PosixPtySystem.php:69-81`). `fcntl(F_SETFD, FD_CLOEXEC)` calls on the master and anchor-slave fd are NOT rc-checked (`src/Posix/PosixPtySystem.php:67,102`) — if `fcntl` silently fails, the child inherits the master fd across `proc_open`, resurrecting the exact SIGHUP-delivery bug the CALIBER_LEARNINGS `[pattern:fd-cloexec-on-master]` entry describes fixing. Worth an explicit rc check + at least a warning/exception here since a silent failure reintroduces a documented, previously-fixed bug class with no test able to catch a *silent* `fcntl` failure (as opposed to `FD_CLOEXEC` simply not being set, which existing tests presumably do check for behaviorally).
- **`Libc::errno()` / `errnoDetail()` correctness depends on reading errno "immediately after the failing FFI call"** (doc comment at `src/Libc.php:178-179`) — this is inherently fragile: any PHP-level statement between the failing libc call and the `errno()` read (even an implicit one, like an autoloader trigger on first exception construction) can clobber the thread-local errno on some platforms/architectures. No FFI-level guard enforces "read errno in the same call" — this is a general FFI errno-reporting risk more than a candy-pty-specific bug, but every error-path call site (`open.posix_openpt_failed`, etc.) needs its errno read to have zero intervening PHP calls, which isn't statically enforceable and isn't tested against opcode-boundary regressions.
- **TOCTOU on PTY slave path is narrow but present by design.** `PosixSlavePty::spawn()` wires the child's stdio via `proc_open`'s `['file', $slavePath, ...]` descriptor spec (`src/Spawn.php:62-66`), meaning the kernel opens `/dev/pts/N` by PATH three separate times rather than via an already-held fd/dup. Between `ptsname_r` returning the path and each of the three `open()` calls, nothing pins that specific PTY number to this session beyond the still-open master fd (which is exclusive per devpts number while held). Risk window is small (attacker would need local access, appropriate permissions on the slave device, and to win a race on a *reused* devpts slot number after a fast close/reopen cycle) but the codebase already has the fix pattern in hand for the Darwin anchor-slave-fd trick (`src/Posix/PosixPtySystem.php:87-104`) — using `/dev/fd/N`-style dup'd descriptors for all three stdio slots instead of re-opening by path would close this gap entirely and is a small, well-precedented change.
- **`bin/pty-shim.php` privilege posture is sound.** No setuid/setgid logic, no privilege escalation; `pcntl_exec()` replaces the process image with the caller-supplied `$cmd`/`$argv` inherited verbatim from `proc_open`'s env (`bin/pty-shim.php:89`) — no shell interpolation, no `escapeshellarg` needed since `pcntl_exec` takes argv directly (not a shell string), so this specific path is not vulnerable to the classic "flags via `escapeshellarg`" gotcha AGENTS.md calls out (that gotcha applies to `SttyTermios`'s shell-out, which does use `proc_open` with an array-form `$cmd` too — `src/SizeIoctl.php:197-203`, `Posix/SttyTermios.php` — also safe from injection since PHP's `proc_open` array form doesn't invoke a shell).
- **`SUGARCRAFT_LIBC` env override is unauthenticated and process-wide.** Any code able to set env vars before candy-pty's first `Libc::lib()` call can redirect libc loading to an arbitrary shared object (`src/Libc.php:78-88`). This is an intentional escape hatch for musl/Alpine (documented), but it is also a straightforward code-execution primitive for anyone who can influence the process environment — worth a one-line security note in the README's "Library lookup" section (`README.md:319-323`) alongside the existing musl/Alpine rationale, similar to how `LD_PRELOAD`-style overrides are usually called out as trusted-environment-only.

### 6. Functionality duplicated with candy-wish / candy-shell

- **No meaningful duplication found — extraction is complete on both sides.** Both `candy-wish` and `candy-shell` `require: sugarcraft/candy-pty` in composer.json (`candy-wish/composer.json:8`, `candy-shell/composer.json:6`) and delegate to candy-pty's real classes rather than carrying parallel implementations.
- `candy-wish`'s `InProcessTransport::runChild()` (`candy-wish/src/Transport/InProcessTransport.php:228-349`) allocates the PTY via an injected `PtySystem` (`:252`), spawns via `slave->spawn(...)` (`:269-275`), pumps via `(new PosixPump())->run(...)` with `PumpOptions::sshDefault()` (`:310-312`), and forwards SIGWINCH via candy-pty's `SignalForwarder::attachSigwinch()` (`:290-302`, `SizeIoctl`/`Libc` at `:375-377`). No local termios/pump/proc_open reimplementation exists in `candy-wish/src`; the class doc-comment (`:25-39`) frames itself as the extraction target for `PumpOptions::sshDefault()`, and what remains at the transport layer (PID tracking for `signalChild()` at `:410-419`, SIGHUP→SIGKILL teardown at `:319-343`) is legitimate orchestration, not duplicated primitive logic. `HostSshdTransport` is a no-PTY legacy path with no spawn/pump code at all.
- `candy-shell`'s `RealProcess` (`candy-shell/src/Process/RealProcess.php:9-19,43-93`) is an explicit thin adapter delegating every method to an injected `PosixProcess` from candy-pty — its own doc-comment states the proc_open polling lifecycle was moved into candy-pty's `ChildPollTrait`.
- Conclusion: this audit category is clean; no consolidation action needed.

### 7. Documentation gaps

- **README's "Compared to node-pty / creack/pty / portable-pty" table is excellent** and already documents almost every gap an auditor would ask about (Windows ConPTY, foreground job control caveats, async model) — this lib's documentation is well above the monorepo average.
- **`Spawn::proc()`'s deprecation message points to `PosixSlavePty::spawn()`**, but that method's implementation is just a thin wrapper calling the deprecated `Spawn::proc()` internally (`src/Posix/PosixSlavePty.php:48`) — the deprecation guidance doesn't reflect that there is, in fact, no non-deprecated *implementation*, only a non-deprecated *entry point*. Worth clarifying in the doc-comment that `Spawn` is being kept as the shared internal implementation, not actually slated for a separate rewrite before v2.0.
- **No mention in README of the `fcntl(F_SETFD, FD_CLOEXEC)` unchecked-return-code risk** (see Security above) even though the CALIBER_LEARNINGS file documents the underlying SIGHUP bug this flag fixes in detail (`[pattern:fd-cloexec-on-master]`) — a one-line "we don't check this rc; if your target OS's fcntl is exotic enough to fail here silently, resize/close semantics regress" caveat would close the gap between what the maintainers clearly know (learnings file) and what a consumer reading only the README would know.
- **`ControllingTerminal::claim()` is documented as "callable directly from FFI-heavy contexts"** (README.md:84) but there's no worked example showing a non-shim caller invoking it directly (only the shim's own usage is shown, `bin/pty-shim.php:71`) — a short code sample would help since the whole point of exposing it publicly is for FFI-heavy contexts that want to skip the shim's ~5-50ms startup cost.

---

## candy-query

### Missing/incomplete functionality

- **No transaction support anywhere.** `grep -rn "beginTransaction\|COMMIT\|ROLLBACK" src/` (excluding tests) returns nothing outside PerfSchema's fixed `setup_*` DDL statements. The query editor (`src/App.php` `runQuery()`/`beginRunQuery()`, `src/App.php:369-462`) executes each statement standalone; there is no way to wrap multiple statements in a user-driven transaction, and no rollback-on-error UX. `DatabaseInterface` (`src/Db/DatabaseInterface.php`) exposes no `beginTransaction()`/`commit()`/`rollback()` contract at all.
- **Query history/favorites are not persisted across sessions.** `src/App/QueryState.php:15-51` holds `history`/`favorites` purely as in-memory arrays; `src/App/AppBuilder.php:150-194` accepts `queryHistory`/`queryFavorites` constructor params but nothing ever loads or saves them to disk. The CLI entrypoint `bin/candy-query:40-53` calls `App::start($database, $flavor)` directly — it never wires up a history file. Note this is distinct from `src/Admin/History/SqliteHistoryStore.php` + `HistoryRecorder.php`, which persist **Dashboard StatusSnapshots** (metrics time-series for the Admin Dashboard graphs), not query-editor history/favorites. So "favoriting" a query (`Ctrl+F`, `App.php:846-865`) is lost the moment the process exits.
- **`sqlsrv` driver is documented but not implemented.** `README.md:27` advertises `bin/candy-query --dsn sqlsrv://localhost/dbname`, but `src/Db/ConnectionFactory.php:20` defines `SUPPORTED_DRIVERS = ['sqlite', 'mysql', 'pgsql']` — `fromDsn()` throws `InvalidArgumentException('Unsupported driver: sqlsrv')` for that exact example from the README. `composer.json` also only requires `ext-pdo_sqlite`/`ext-pdo_mysql`/`ext-pdo_pgsql`, no `ext-pdo_sqlsrv`.
- **Export/import identifier quoting is hardcoded to MySQL regardless of actual DB flavor.** `src/Db/Export/CsvExporter.php:61,79,206,217,228` and `src/Db/Export/SqlExporter.php:65,69,109` all call `Identifier::quote(Flavor::MySQL, ...)` unconditionally, even though both classes are typed against the driver-neutral `DatabaseInterface` and their doc-comments claim "Driver-agnostic" (`CsvExporter.php:14`, `SqlExporter.php:15`). `ReportsPage.php:568` constructs `new CsvExporter($this->db)` where `$this->db` can be a Postgres connection (Query Stats/Table Stats admin panes support both MySQL and Postgres per `App.php:756`) — a Postgres `exportCsv()`/`importCsv()` call would emit invalid backtick-quoted SQL. (Sqlite tolerates backticks as a MySQL-compat extension so the `Database.php` deprecated-SQLite path happens to still work; Postgres does not.) Currently only reachable via the deprecated `Database` class (SQLite-only) so the Postgres path is latent, not exercised — but the API is public and typed to accept any `DatabaseInterface`.
- Connection pooling is minimal by design: `AdminQueryCache` (`src/Admin/AdminQueryCache.php:29-51`) caches exactly one `AsyncConnection` keyed by `driver|dsn|user` (see `connKey` construction in `App.php:492,631,928`) — adequate for a single-user TUI, but there is no pool of multiple concurrent connections, no idle-connection eviction, and no reconnect-storm guard beyond `ReconnectManager`.

### Performance concerns

- **`ServerContext::plugins()`/`version()`/`flavor()`/`versionString()` bypass the async-cache protection and can block the ReactPHP loop.** `AsyncCachingServerContext` (`src/Admin/AsyncCachingServerContext.php`) deliberately routes `serverVariables()`/`statusVariables()` through `AdminQueryCache` with an explicit comment that a synchronous `SHOW GLOBAL VARIABLES` "can take seconds (it froze the whole UI)" (lines 38-43) — but `plugins()` (line 75), `version()` (line 81), `flavor()` (delegated transitively via `version()`), and `versionString()` (line 90) all delegate straight to `$this->inner` (the raw `ServerContext`), which performs a **synchronous PDO round-trip** in `fetchPlugins()`/`connection()->serverVersion()` (`src/Admin/ServerContext.php:95-137,227-239`). These are called directly from admin-page `view()`-path code: `src/Admin/ServerStatus/ServerStatusPage.php:148-576` (`versionString()`, `flavor()`, `plugins()` ×3, `version()` ×3), `src/Admin/Dashboard/DashboardPage.php:83-438`, `src/Admin/PerfSchema/PerfSchemaPage.php:614-711`, `src/Admin/ServerStatus/ServerInfoCard.php:52`. Results are cached after the first call (`versionCache`/`pluginsCache` in `ServerContext.php:25-34`), so this is a one-time blocking hit per admin-context lifetime (and again after `refresh()`, `ServerContext.php:170-180`) rather than a per-tick regression — but against a slow/remote MySQL/Postgres server it is exactly the same class of UI-freeze bug the surrounding code explicitly guards against for `serverVariables()`/`statusVariables()`.
- Query-execution and table-browse paths are correctly React-async-gated: `App::beginRunQuery()` (`App.php:420-462`) and `App::beginLoadTable()`/`createRowsPromise()` (`App.php:549-649`) only run synchronously for SQLite (with an explicit "a local file cannot freeze the event loop" rationale) and dispatch `Cmd::promise` through `ReactMysqlConnection`/`ReactPostgresConnection` for MySQL/Postgres. No sync-query-per-keystroke pattern was found; row previews go through the blob-safe `PreviewQuery` (never select BLOB/large-text columns).
- No visible pagination/streaming limit on the full-table `CsvExporter::exportCsv()`/`SqlExporter::exportSql()` paths — both call `$this->db->rows($table)` (default `$limit = 100` in `MysqlDatabase::rows()`, `src/Db/MysqlDatabase.php:94-107`) then `$this->db->tables()`/rows per table with no explicit chunking for large exports; for the SQLite `Database::exportCsv()` path this reads the whole result set into memory at once.

### Test coverage

- `tests/Admin/Reports/ReportsPageTest.php` (24 test methods) exercises the `sugar-table` `Table::fromColumns()->withRows()` construction end-to-end via `view()` (`ReportsPageTest.php:160-441`), including a CSV formula-injection regression test (`=CMD|'/C calc'!A0`, `+A1+A2`, `-SUM(...)`, `@HYPERLINK`, `=2+2` at lines 364-368) — the previously-reported "untested sugar-table API drift" in `ReportsPage` appears resolved; current `Table`/`Column`/`Row`/`RowData` calls (`ReportsPage.php:657-704`) match the live `sugar-table` API (`Table::fromColumns()`, `Column::new()`, `Row::new(RowData::from())` all verified present in `sugar-table/src/*.php`).
- No test found directly asserting that `ServerStatusPage`/`DashboardPage`/`PerfSchemaPage` avoid a blocking `plugins()`/`version()` call against a slow connection — the blocking-call concern above has no regression test guarding it (unlike `serverVariables()`/`statusVariables()`, which do have cache-miss tests per `AsyncCachedConnectionTest.php`/`AsyncCachingServerContextTest.php`).
- No test exercises `CsvExporter`/`SqlExporter` against a non-MySQL flavor (`tests/Db/Export/CsvExporterTest.php`, `SqlExporterTest.php` — worth double-checking flavor coverage) to catch the hardcoded `Flavor::MySQL` identifier-quoting issue above.

### Missing .vhs/*.tape demos

- Only two tapes exist: `.vhs/play.tape` and `.vhs/query-history.tape` (plus matching `.gif`s). The large Admin surface — Dashboard (`src/Admin/Dashboard/DashboardPage.php`), Connections/Processlist (`src/Admin/Connections/ConnectionsPage.php`), Variables editor (`src/Admin/Variables/VariablesPage.php`), Server Status (`src/Admin/ServerStatus/ServerStatusPage.php`), Performance Schema (`src/Admin/PerfSchema/PerfSchemaPage.php`), Reports/Query Stats (`src/Admin/Reports/ReportsPage.php`), and Debug (`src/Admin/Debug/DebugPage.php`) — has no VHS demo coverage at all despite being the majority of the lib's UI surface.

### Security

- **Identifier quoting is centralized and used consistently for user-facing table/column names** (`src/Db/Identifier.php`, used throughout `MysqlDatabase`, `PostgresDatabase`, `SqliteDatabase`, schema providers, `PreviewQuery`) — no raw string-interpolated table/column names into SQL were found outside the exporters noted above.
- **`VariableEditor` (`src/Admin/Variables/VariableEditor.php`) correctly parameterizes values** (`SET GLOBAL \`x\` = ?` with `execute([$newValue])`, lines 63-77, 104-119, 142-157, 181-196) — variable *names* are backtick-escaped rather than parameterized because MySQL doesn't support placeholders for identifiers in `SET`, and names originate from the static `Catalog`, not free-form user input (per the class doc-comment). This is a sound pattern, but note `getEditPreview()` (lines 245-260) builds a **display-only** string using manual `str_replace("'", "''", $newValue)` escaping — never sent to the DB (only shown as a UI preview), so no injection risk, but worth confirming no code path mistakenly executes the preview string instead of the parameterized `$sql`.
- `ConnectionActions::executeKill()` (`src/Admin/Connections/ConnectionActions.php:132-152`) int-casts the thread ID directly into `KILL {$id}` / `KILL QUERY {$id}` — correctly reasoned as injection-safe since MySQL's `KILL` doesn't accept placeholders and the value is `(int)`-cast first.
- `ConnectionActions::isInstrumentationEnabled()` (`ConnectionActions.php:107`) — `"SELECT ENABLED FROM performance_schema.setup_actors WHERE HOST = '%' AND USER = '%' LIMIT 1"` is not an injection risk (no interpolated variable) but is a likely **functional bug**: it uses `=` against the literal string `'%'` rather than a `LIKE '%'` wildcard match (or checking the actual configured host/user pattern), so this will only ever match a row whose `HOST`/`USER` column is literally the two-character string `%` — probably not the intended semantics for "is the default catch-all actor enabled."
- `QueryLogger` (`src/Admin/QueryLogger.php:26-40`) logs raw SQL text verbatim (in-memory, capped at 100 entries, surfaced on the Debug admin page). If a user runs a statement containing credentials (e.g. `CREATE USER ... IDENTIFIED BY 'secret'`, or a `SET GLOBAL` carrying a password-like value), that literal text is retained in the process and displayable via the Debug pane. Low severity (local-only, in-memory, not written to disk or transmitted), but worth a redaction pass if the Debug pane is ever exposed to less-trusted operators.
- CSV export has explicit formula-injection protection (`CsvExporter::guardFormula()`, lines 248-267) covering `=`, `+`, `-`, `@`, leading tab/CR — good defensive coverage, verified by regression tests in `ReportsPageTest.php:364-368`.
- Credentials: `ConnectionConfig` documented as "Pass never echoed" (README architecture table); `Database`/`MysqlDatabase`/`PostgresDatabase` keep the PDO handle private (`Database.php:49-54` explicitly documents why). No `var_dump`/`print_r`/log statements containing `->pass` or `$password` were found in `src/`.

### Duplication with sugar-table/candy-forms/candy-kit or raw-ANSI

- No raw ANSI escape sequences (`\x1b[`, `\033[`) found anywhere in `src/` (`grep -rn "\\x1b\[\|\\033\[" src/` empty) — the previously-reported raw-ANSI-elimination effort holds on current `master`.
- `ReportsPage` correctly uses `sugar-table`'s `Table`/`Column`/`Row`/`RowData` (`ReportsPage.php:657-704`) rather than hand-rolled grid rendering.
- `VariablesPage`/`ConnectionsPage`/`DashboardPage` widgets (`SidebarGauge`, `MeterCell`, `CounterCell`, `TimeSeriesCell`) are candy-query-specific admin widgets, not obvious duplicates of `sugar-table`/`candy-kit` primitives — they compose `Sprinkles\Style`/`Layout` directly, which is the expected layering (candy-query depends on `candy-sprinkles`/`candy-layout` per `composer.json`).

### Documentation gaps

- **README documents an unsupported `sqlsrv` DSN example** (`README.md:27`) that throws at runtime — see functionality finding above. Either implement SQL Server support or remove the example.
- README's architecture table describes `Database` as "⚠️ Deprecated thin alias to `SqliteDatabase`" but doesn't mention that `CsvExporter`/`SqlExporter` reached through it hardcode MySQL-style identifier quoting even for the SQLite/Postgres paths — worth a note given the "driver-agnostic" claim in the exporter class doc-comments themselves.
- No README section documents query-history/favorites persistence (or its absence) — a user reading the Keys table (`Ctrl+F` favorites, `README.md:44`) would reasonably assume favorites survive a restart; they currently do not.
- `CALIBER_LEARNINGS.md` (450 lines) was not fully cross-checked line-by-line against current source in this pass beyond spot checks; recommend a follow-up diff against the findings above (transaction support, sqlsrv, exporter flavor bug) to see if any are already tracked/contradicted there.

---

## candy-serve

PHP port of `charmbracelet/soft-serve` (self-hostable Git server: SSH+TUI, Git daemon, HTTP smart protocol, LFS). ~5,044 LOC across `src/`, 20 test files (`grep -c "public function test"` totals well over 250 test methods), no `.vhs/` directory.

### 1. Missing/incomplete functionality vs upstream scope

- **No actual SSH transport is implemented.** `src/SSH/SSHServer.php` only implements protocol-level logic (`handleConnection($stream, $username, $command, $publicKey)`) — it expects to be handed an already-established stream, username, and forced command by some external transport. Nothing in the codebase binds `ssh.listen_addr` and speaks the SSH wire protocol (`grep -n sshListenAddr src/ bin/` shows it's read into `Config` and printed in the CLI banner (`bin/soft-serve:74`), never passed to a listener). `ext-ssh2` is required in `composer.json:35` but is only used for local key-pair generation (`src/User.php:166-196`), not for accepting inbound connections. Upstream soft-serve's actual SSH daemon (via `gliderlabs/ssh`) has no PHP equivalent here.
- **No actual HTTP transport is implemented.** `src/HttpSmartProtocol/Server.php` is a pure `handleRequest(method, path, query, headers, body): array` logic layer; nothing binds `http.listen_addr` (`grep -n httpListenAddr` — same as SSH, read into `Config`/CLI banner only, never wired to a socket). Contrast with `src/Git/GitDaemon.php`, which has full socket-binding for both sync (`socket_select()`) and async (`stream_socket_server`) modes, and `src/StatsServer.php`, which binds its own listener. So of the three protocols the README documents (SSH, HTTP, Git daemon), only Git-daemon-over-9418 is actually runnable from `bin/soft-serve`.
- **`bin/soft-serve serve` only ever starts `GitDaemon`** (`bin/soft-serve:123-143`); non-daemon mode (default) just prints a banner/repo list and returns (`bin/soft-serve:79-104`) — it does not serve anything. There is no `cmdServe` path that starts an HTTP server, SSH server, or `StatsServer`, despite the README's "HTTP Smart Protocol" and "SSH Access" sections implying `composer serve` exposes them.
- **`git-daemon` native-protocol push does not unpack objects.** `GitDaemon::handleReceivePack()` (`src/Git/GitDaemon.php:765-838`) reads only ref-update commands from the client buffer and calls `git update-ref` directly (`src/Git/GitDaemon.php:806-822`) — it never runs `git receive-pack`/`git unpack-objects`/`git index-pack` on the packfile the client sent. A push over `git://host:9418/repo` (write access, as documented in README:178-180) will move refs to commit hashes whose objects were never stored, corrupting the repo with dangling refs. The HTTP smart-protocol path is correct by contrast — it delegates to `git receive-pack --stateless-rpc` (`src/HttpSmartProtocol/Server.php:326-338`), which does unpack objects.
- **TLS is configured but never wired up.** `Config::$tlsKeyPath`/`$tlsCertPath` are parsed from `http.tls_key_path`/`tls_cert_path` (`src/Config.php:40-41,163-164`) but nothing in `GitDaemon`, `HttpSmartProtocol\Server`, or `StatsServer` references them — no `stream_context_create` with `ssl` options, no HTTPS listener anywhere (`grep -n "tls\|ssl\|stream_context"` across `src/` returns only the `Config` declarations). Since there's also no HTTP daemon at all (see above), TLS has no consumer regardless.
- `cmdUserAdd`/`cmdUserKey`/`cmdUserList` in `bin/soft-serve` (lines 211-253) construct `User` objects and print messages but never persist them anywhere (no DB write despite `Config::$dbDriver`/`$dbDataSource` existing) — user management is not functional from the CLI.
- No admin/collaborator management commands (`repo collab add`, `user set-admin`, etc.) despite `AccessControl`/`Repo::addCollaborator()` existing as library APIs.

### 2. Performance concerns

- `GitDaemon::sendPack()` (`src/Git/GitDaemon.php:728-760`) and `HttpSmartProtocol\Server::handleUploadPack/handleReceivePack` (`src/HttpSmartProtocol/Server.php:245-358`) buffer the **entire** packfile in memory via `readCapped()`/`stream_get_contents()` before responding — capped at `http.max_pack_bytes` (default 256 MiB), but still a full in-memory buffer per request rather than true streaming, despite `Transfer-Encoding: chunked` being advertised in response headers (`Server.php:234,264,324`) — the body is never actually chunked/streamed to the client. A `TODO` comment even flags this (`Server.php:641`: "stream via chunked callback for true streaming; cap buffered size for now").
- `Repo::branches()/tags()/refs()/readFile()` (`src/Repo.php:215-280`) all shell out via `\exec()` synchronously — fine for the blocking daemon, but if invoked from `GitDaemon::serveAsync()`'s readiness callback (same synchronous protocol code per the dual-mode design, `README.md:206-212`) these block the ReactPHP loop for the duration of the `git` subprocess, defeating the purpose of the async mode for anything beyond accept/read multiplexing. This is a documented tradeoff (`CALIBER_LEARNINGS.md` notes similar for LFS: "the loop buys bounded SCHEDULING... not async file I/O") but worth flagging since it applies to every Git plumbing call in the request path, not just LFS.
- `GitDaemon::acceptConnection()`/`acceptAsyncConnection()` (`src/Git/GitDaemon.php:316-377`) enforce `gitMaxConnections` (default 32) by evicting the **oldest** connection unconditionally, even if it's mid-transfer — a burst of new connections can starve/kill legitimate in-flight clones.
- `HttpSmartProtocol\Server` and `StatsServer` have **no connection/request-rate limiting at all** — unlike `GitDaemon`, which caps connections via `gitMaxConnections`. Since neither actually binds a socket in this codebase (see §1), this is currently moot, but any host wiring `Server::handleRequest()` to a real listener would need to add its own caps.

### 3. Test coverage gaps

- No test exercises `bin/soft-serve` end-to-end (no `tests/BinTest.php` or similar) — the CLI entrypoint's `cmdServe`/`cmdUserAdd`/`cmdRepoCreate` argument parsing and control flow (`bin/soft-serve:48-144, 211-253`) are untested.
- No test covers `GitDaemon::handleReceivePack()`'s actual on-disk repo state after a push over the native git protocol — `tests/Git/GitDaemonTest.php`/`GitDaemonAsyncTest.php` should be checked for whether they assert the pushed objects are actually retrievable post-push (this would have caught the missing-unpack bug in §1) vs. only asserting the `ok <ref>`/`ng <ref>` wire response.
- No test for `HttpSmartProtocol\Server`'s `X-CandyServe-User` header trust path or its interaction with a real reverse proxy — see security note below; `tests/HttpSmartProtocol/ServerTest.php` should be checked for whether it exercises `getUserFromHeaders()` with attacker-controlled headers to confirm intended trust boundary.
- No fuzz/malformed-input tests for the git-daemon wire parser (`dispatchClientRequest()`, `readWantsFromFile()`, `readCommandsFromFile()` in `src/Git/GitDaemon.php:556-869`) — e.g. oversized buffers, missing `\n`, non-hex hashes beyond the `ctype_xdigit` check already present.
- TLS config fields (`tlsKeyPath`/`tlsCertPath`) have no test at all (consistent with them being unused — §1).

### 4. Missing .vhs demos

- `candy-serve/.vhs/` does not exist — no VHS tape files. Per `AGENTS.md`, VHS demos are for TUI-visual output; candy-serve is server/daemon code with no interactive TUI surface exposed in this repo (the README's "Browse repos... via a terminal TUI over SSH" is aspirational/not yet implemented — see §1, no SSH transport exists to drive such a TUI). Likely legitimately exempt like `candy-pty`/`candy-testing`, but should be confirmed explicit in `.github/workflows/vhs.yml`'s exemption list rather than silently absent.

### 5. Security concerns

- **Auth-bypass header: `X-CandyServe-User` grants full impersonation with zero verification.** `HttpSmartProtocol\Server::getUserFromHeaders()` (`src/HttpSmartProtocol/Server.php:769-799`) — for Basic auth it correctly checks `hash_equals()` against `$user->password` (line 784), but the `X-CandyServe-User` branch (lines 794-796) does `return $this->users[$headers['X-CandyServe-User']] ?? null;` with **no password/token/signature check whatsoever**. Any HTTP client that can set this header (which is just a normal request header — nothing in this codebase strips it or restricts it to a trusted proxy) can impersonate any registered user, including admins, and get full read/write/admin access via `AccessControl::canRead/canWrite/canAdmin`. `CALIBER_LEARNINGS.md` documents this as an intentional "custom CandyServe header" pattern but there is no enforcement that it only originates from a trusted internal proxy (no IP allowlist, no shared-secret check, no doc warning). This is a critical finding if the HTTP server is ever exposed directly to clients.
- **SSH public-key auth is bypassable when no key material is supplied.** `SSHServer::authenticate()` (`src/SSH/SSHServer.php:152-169`): if `$presentedKey` is null/empty, the method returns `true` unconditionally ("trust the transport... as before", line 166-168) — i.e., if whatever wires up `handleConnection()` fails to pass the peer's key (which is easy, since nothing in-repo actually extracts it — see §1, there is no real SSH transport here), any `$username` claim is accepted with no verification. Combined with §1 (no real SSH listener exists), this is latent risk for whoever eventually wires a transport in without carefully threading the presented key through.
- **Anonymous read defaults to allowed.** `AccessControl::allowAnonymousRead()` (`src/AccessControl.php:78-81`) hardcodes `return true;` — not configurable via `config.yaml` at all, despite the README's access-control section implying finer control. Combined with `SSHServer::authenticate()` (line 154-158): a null/unknown user always gets anonymous SSH access as long as `ext-ssh2` is loaded, regardless of `allowAnonymousRead` intent for private setups.
- **`AccessControl::canAdmin()`** (`src/AccessControl.php:59-64`) only checks `$user->isAdmin` — no per-repo admin delegation exists, consistent with upstream but worth noting there's no owner concept (repo creator isn't automatically its admin — `SSHServer::createRepo()` at `src/SSH/SSHServer.php:199-216` creates repos as `withPublic(true)` with the creating user only implicitly a collaborator via no explicit `addCollaborator()` call — the creator gets no special access to their own on-demand-created repo beyond being an admin already, since `canCreateRepos()` requires `isAdmin` anyway. Minor: on-demand repo creation is admin-only, contradicting the README's "Push to create repos on demand" framing as a general user capability).
- **`StatsServer` has zero authentication** (`src/StatsServer.php`) — anyone who can reach `stats.listen_addr` gets connection counts, pack upload/download counts, LFS activity (`Stats::toJson()`), which is a minor info-disclosure/reconnaissance surface if that port is reachable from untrusted networks. Not necessarily wrong for an internal metrics endpoint, but undocumented as "bind to localhost only."
- **No rate limiting or per-IP connection caps anywhere** — `GitDaemon` caps total connections (`gitMaxConnections`, oldest-evict) but has no per-source-IP limit, so a single client can consume the entire connection budget (a real DoS vector against the daemon's other users). `HttpSmartProtocol\Server` and `StatsServer` have no caps at all (moot currently since neither is wired to a real listener — §1 — but a real risk the moment one is added without additional hardening).
- Shell-out surfaces (`Repo::init/branches/tags/refs/readFile`, `GitDaemon::sendPack/handleReceivePack`, `MirrorPuller`) consistently use `\escapeshellarg()` for user-influenced values (repo path, ref names validated via `preg_match('/^refs\/[a-zA-Z0-9._\/.-]+$/')` at `GitDaemon.php:796`, mirror URLs) — this part looks solid; ref-name validation and repo-name validation (`SSHServer::handleConnection` line 111: `^[a-zA-Z0-9._-]+$`) both guard against path traversal / shell injection reasonably well.
- LFS upload path correctly verifies `hash('sha256', $body) === $oid` before storage (`Server.php:494-496`) and caps upload size (`Server.php:489-492`) — good practice, no gap found there.

### 6. Functionality duplicated with candy-wish

- `candy-wish` (`sugarcraft/candy-wish`) already implements a full SSH-middleware framework — it deliberately does **not** reimplement the SSH wire protocol, instead leaning on the host's OpenSSH via `ForceCommand` (per-connection PHP process), with pluggable `Transport`/`Middleware`/`Session`/`Context` abstractions (`candy-wish/src/{Transport,Middleware,Session,Context}.php`, `candy-wish/README.md:14-25`).
- `candy-serve/src/SSH/SSHServer.php` independently reinvents a slice of this same shape — a `handleConnection($stream, $username, $command, $publicKey)` entry point that expects an external transport to have already done SSH auth and handed over a forced-command string — but does **not** depend on or reuse `candy-wish` at all (`candy-serve/composer.json` requires `candy-async`, `candy-core`, `symfony/yaml`, `react/*`, but not `sugarcraft/candy-wish`; the path-repos list includes `candy-pty`, `candy-ansi`, `candy-input` — presumably for a not-yet-built SSH-TUI browsing feature — but not `candy-wish`).
- Given candy-wish already solves "run PHP over an SSH ForceCommand session with public-key auth," candy-serve's `SSHServer` is a parallel, less-complete reimplementation of the same integration point (no `Transport`/`Middleware` abstraction, no in-process vs. host-sshd dual-mode, no key-verification when `$presentedKey` is absent — see §5). This looks like the natural home for de-duplication: candy-serve's SSH surface could sit as a candy-wish middleware/handler instead of a hand-rolled parallel auth+dispatch class, closing the "no real SSH transport" gap in §1 for free.

### 7. Documentation gaps

- **README overstates what's runnable.** The "SSH Access", "HTTP Smart Protocol", and OSC-52/TUI-browsing sections (`README.md:112-145, 302-311`) present `ssh -p 23231 user@host`, `git clone http://...`, and `repo tree`/`repo blob` sub-commands as working features; none of the underlying transports exist in this codebase (§1). At minimum this needs an explicit "these protocol handlers are logic-only; wiring a listener (sshd ForceCommand, `stream_socket_server`, etc.) is left to the host application" caveat, mirroring the honesty notes CALIBER_LEARNINGS.md already uses for the LFS async scheduling caveat.
- The `X-CandyServe-User` header (§5) is undocumented in the README's "HTTP Smart Protocol" section beyond one line ("Authentication uses HTTP Basic auth or the `X-CandyServe-User` header," `README.md:145`) — there's no warning that this header is a **complete trust bypass** with no secondary verification, which any integrator needs to know before ever exposing the HTTP server behind anything but a strictly-controlled trusted proxy that strips/overwrites client-supplied headers of the same name.
- No documentation of `stats.listen_addr` exposure risk (bind to loopback recommended) — README presents it as a plain `curl localhost:23233/stats` example with no security guidance.
- TLS config keys (`tls_key_path`/`tls_cert_path`) are not mentioned anywhere in the README's Configuration example (`README.md:69-95` config.yaml sample omits them) despite existing in `Config` — should either be documented as "reserved for future use" or removed until wired up, since currently they silently do nothing (§1).
- No `CHANGELOG.md`/upgrade notes for the `Visibility` enum migration described in `CALIBER_LEARNINGS.md` (7.8) — the BC-compat rules (`withPublic(false)` on Private stays Private, `withPrivate(false)` resolves to CollaboratorOnly) are subtle and only documented in `CALIBER_LEARNINGS.md`/inline doc-comments (`Repo.php:86-113`), not in the public README's "Repo Permissions" section, which only shows the happy path.

---

## candy-shell

### 1. Missing/incomplete functionality vs upstream `charmbracelet/gum`

All 13 upstream subcommands are present and registered in `candy-shell/src/Application.php:39-54`: `style`, `choose`, `input`, `confirm`, `join`, `log`, `table`, `filter`, `write`, `file`, `pager`, `spin`, `format`, plus a CandyShell-only `completion` command. No subcommand is missing at the top level.

- **`--timeout` is accepted everywhere but enforced nowhere.** Every command declares a `timeout` option (`SpinCommand.php:48`, `FormatCommand.php:37`, `TableCommand.php:47`, `FileCommand.php:36`, `LogCommand.php:37`, `JoinCommand.php:38`, `ChooseCommand.php:41`, and the rest), but a repo-wide grep for non-`addOption` usages of `timeout` in `src/Command/*.php` returns **zero hits** — the value is parsed and then discarded. Upstream `gum spin --timeout` and `gum choose --timeout` actually abort the operation after N seconds; here the flag is cosmetic gum-compat only. This is the most concrete functional gap versus upstream, and it is most consequential on `spin` (a long child process should be killable on timeout) and the interactive Model-based commands (`choose`, `input`, `confirm`, `filter`, `write`, `file`, `pager`) which upstream auto-abort on idle timeout.
- `FormatCommand` `--type template` (`FormatCommand.php:92-104`) implements only `{{VAR}}` env-var substitution — no Go-template function support (conditionals, pipes, etc.). This is explicitly documented as a known gap in `README.md:213-215`, so it's disclosed rather than hidden.
- README (`README.md:135-137`) points to `../AUDIT_2026_05_06.md` for "the full delta" of upstream flags not yet wired — that audit file was not found at the expected path (`find /home/sites/sugarcraft -maxdepth 1 -iname "AUDIT_2026_05_06*"` returned nothing), so the referenced audit document is either stale, renamed, or was never committed — a documentation/audit-trail gap in itself (see §7).

### 2. Performance concerns

- `Application::applyEnvVarFallbackToInput()` (`src/Application.php:110-155`) uses `ReflectionProperty` to reach into `ArgvInput`'s private `tokens` property on every single invocation that has any `CANDYSHELL_*` env var set, in order to re-inject option tokens before Symfony's second `bind()` pass. This is a per-process, not hot-loop, cost, so raw perf impact is negligible, but it is a **fragility/stability** concern: it depends on Symfony Console's internal `ArgvInput::$tokens` property name and shape, which is not part of Symfony's public contract and could silently break (or silently no-op) on a future `symfony/console` minor/major bump. Consider a supported extension point or defensive check (e.g. verify the reflected property exists before use) — currently a Symfony refactor would fail loudly via a `ReflectionException` with no test coverage guarding against it besides the existing behavioral tests.
- `TableCommand::parseRows()` (`TableCommand.php:150-189`) is deliberately careful about memory (`php://temp/maxmemory:8388608` spill-to-disk for single-char separators), which is a positive, not a concern — noted for completeness since performance was in scope.
- No other blocking/O(n²) patterns found in the Command layer; the interactive Models were out of this audit's read depth but weren't flagged by size (`FilterModel.php` at 416 lines is the largest, consistent with fuzzy-match complexity via candy-fuzzy).

### 3. Test coverage gaps per subcommand

Every subcommand has both a `tests/Command/*CommandTest.php` and (where model-backed) a `tests/Model/*ModelTest.php`. Sizes (lines) suggest depth varies:

| Command | Command test | Model test |
|---|---|---|
| spin | 37 (thin) | 111 |
| style | 45 (thin) | — (no Model; StyleBuilder/SubStyleParser tested separately) |
| log | 68 | — (no Model) |
| table | 68 | — (no Model) |
| join | 77 | — (no Model) |
| filter | 79 | 255 |
| input | 81 | 54 |
| write | 84 | 49 |
| confirm | 88 | 62 |
| file | 92 | 116 |
| pager | 92 | 96 |
| format | 109 | — (no Model) |
| choose | 146 | 72 |

- `SpinCommandTest.php` at 37 lines is the thinnest command-level test in the suite. Given `SpinCommand` has the most security-relevant surface (spawns an arbitrary external process, `--show-output`/`--show-error` capture, SIGINT→130 handling, `--timeout` parsed-but-unused), 37 lines is unlikely to cover: the untested `--timeout` no-op behavior, `--align right`, both `--show-stdout`/`--show-stderr` aliases together, and the child-nonzero-exit-code forwarding path. Recommend expanding with `FakeProcess`-driven cases for each of these branches.
- `StyleCommandTest.php` at 45 lines — worth checking it covers `SubStyleParser`'s per-element `--style elem.prop=value` composition end-to-end (not just via `SubStyleParserTest.php` in isolation), since that's the integration point most likely to regress silently.
- No test appears to assert the `--timeout` flag's absence-of-effect is intentional (a "timeout flag exists but is a documented no-op" regression test would future-proof the flag once it's wired up, and would also make the current gap visible in CI rather than only in this audit).
- `LogCommand::formatLine()` with `--format` doing `sprintf($fmt, $text)` (`LogCommand.php:50-53`) — no test file content was inspected in depth, but this is a good candidate for a malformed-format-string regression test (see §5).

### 4. Missing `.vhs/*.tape` demos per subcommand

None missing. `.vhs/` contains a `.tape` + `.gif` pair for all 13 gum-mirroring subcommands (`choose`, `confirm`, `file`, `filter`, `format`, `input`, `join`, `log`, `pager`, `spin`, `style`, `table`, `write`). The CandyShell-only `completion` command has no VHS demo, which is reasonable since it's non-interactive text emission, not a visual TUI.

### 5. Security concerns

- **`SpinCommand` process spawn is safe from shell injection**: `RealProcess::spawn($argv, ...)` (`src/Process/RealProcess.php:30-41`) forwards the `argv` array (from Symfony's `IS_ARRAY` argument, i.e. individual tokens, not a joined string) straight to `PosixProcess::spawn(cmd: $command, ...)`, which calls `\proc_open($cmd, ...)` with `$cmd` as an **array** (`candy-pty/src/Posix/PosixProcess.php:90`). PHP's `proc_open()` with an array command does *not* go through `/bin/sh -c`, so shell metacharacters in the user's command arguments are not shell-interpreted — this correctly avoids the classic command-injection footgun and does not need `escapeshellarg()` per the repo's usual gotcha (that gotcha applies to string-built shell commands, which this code path avoids entirely). Good pattern; worth keeping as the canonical example for future process-spawning code in the repo.
- **`FormatCommand --type template` leaks arbitrary environment variables into stdout** (`FormatCommand.php:97-104`): `renderTemplate()` expands any `{{VAR_NAME}}` placeholder found in the *input* (file or stdin) via `getenv($m[1])`, with no allow-list. If a script does `candyshell format --type template untrusted.md` where `untrusted.md` is attacker-influenced (e.g. downloaded content, a shared template, CI artifact), the attacker can write `{{AWS_SECRET_ACCESS_KEY}}` / `{{GITHUB_TOKEN}}` / any other env var name and have its value printed to stdout — a straightforward secret-exfiltration primitive if the output is logged, captured, or piped somewhere the attacker can observe. Upstream `gum format --type template` has the same general shape (Go's `text/template` also has env access via sprig-style funcs in some configs), but since this is billed as a "lightweight" reimplementation, it would be reasonable to either document this explicitly as a risk in the README's template section (currently only documents functional limitations, not this data-exposure angle) or gate it behind an explicit `--allow-env` flag.
- **`LogCommand --format` performs unguarded `sprintf($fmt, $text)`** (`LogCommand.php:50-53`): a caller-supplied `--format` string with more/fewer conversion specifiers than the single `$text` argument will trigger a PHP `ValueError` (PHP 8 changed `sprintf()` mismatches from warnings to catchable-but-fatal-if-uncaught errors) that is not caught here — only `\JsonException` from the JSON formatter branch is caught (`LogCommand.php:61-66`). A hostile or merely careless `--format '%s %s'` crashes the command instead of degrading gracefully. Low severity (it's the invoking script's own flag, not attacker-controlled input in the typical shell-script use case), but worth a defensive `try/catch` alongside the existing `JsonException` handling for consistency.
- No use of `shell_exec`, `exec`, `system`, backticks, or `escapeshellcmd`/`escapeshellarg` was found anywhere under `candy-shell/src` — all subprocess interaction goes through the array-form `RealProcess`/`PosixProcess` path described above, which is the correct posture for this "shell-adjacent" library.
- `FileCommand`/`TableCommand`/`FormatCommand` all read arbitrary local files via `file_get_contents($file)` / `@file_get_contents` with no path canonicalization — this mirrors expected CLI-tool behavior (the user names the file), not a vulnerability in itself, but there's no symlink/traversal guard if this library is ever wrapped by something that passes untrusted paths.

### 6. Functionality duplicated with candy-forms / candy-kit

- CALIBER_LEARNINGS.md (`candy-shell/CALIBER_LEARNINGS.md:17`) documents that this was already resolved: candy-shell's Models (`ChooseModel`, `ConfirmModel`, `FileModel`, `FilterModel`, `InputModel`, `PagerModel`, `WriteModel`) now delegate to `SugarCraft\Forms\*` primitives (`TextInput`, `TextArea`, `ItemList`, `Viewport`, `FilePicker`, `Spinner`, `Confirm`) rather than duplicating them, having migrated off the leaf libs `sugar-bits`/`sugar-prompt`. No further widget-level duplication was found in `src/Model/*.php` at a glance — each Model file is a relatively thin composition (88–416 lines) consistent with delegating core interaction logic to candy-forms rather than reimplementing it.
- No overlap was found with `candy-kit` — candy-shell does not require `candy-kit` in `composer.json`, and no `SugarCraft\Kit\*` usage appears in `src/`.
- `SpinCommand`'s spinner styles (`pickStyle()`, `SpinCommand.php:103-120`) go through `SugarCraft\Forms\Spinner\Style`, consistent with the same foundation-reuse pattern — not a duplicate spinner implementation.

### 7. Documentation gaps

- `README.md:135-137` and `:211` both cite `../AUDIT_2026_05_06.md` as the source of truth for the upstream-flag delta, but that file could not be located at the repo root (`/home/sites/sugarcraft/AUDIT_2026_05_06.md` does not exist per the `find` check). Either the audit was moved/renamed/archived without updating the README's two references, or it was never committed — this leaves the "full delta of unwired upstream flags" undocumented in practice, only referenced.
- The `--timeout` no-op behavior (§1) is *not* documented per-command in README — the "Porting from gum" section (`README.md:207-223`) generically says timeout-and-friends "now accept their gum-equivalent values," which reads as if the values are honored; the per-command `addOption` help text is more accurate (each doc string is explicit that timeout is currently `0`/no-op for the non-interactive commands `format`, `table`, `join`, `log`), but the interactive commands' (`spin`, `choose`, `file`, etc.) option help text does *not* say "no-op," which is misleading since they don't enforce it either — a user reading `--timeout`'s help on `choose` or `spin` would reasonably expect it to work.
- `FormatCommand`'s template-mode env-var exposure (§5) is undocumented as a security consideration anywhere in the README or command help text.

---

## candy-shine

Scope confirmed: `candy-shine` is a PHP port of **charmbracelet/glamour** (Markdown → ANSI renderer), built on `league/commonmark` + `SugarCraft\Sprinkles` (Style/Border/Table). Not a "sparkle effect" lib. `candy-shine/composer.json:3`, `candy-shine/README.md:16`.

### 1. Missing/incomplete functionality vs upstream (glamour)

- **Syntax highlighting is a hand-rolled regex tokenizer covering only 8 languages** (php/js/ts/json/python/go/bash/sql) — `candy-shine/src/SyntaxHighlighter.php:21-71`. Upstream glamour delegates to `alecthomas/chroma`, which supports ~200 lexers. No language beyond the hardcoded list gets real highlighting; anything else silently falls back to the plain `codeBlock` style (`SyntaxHighlighter.php:96-102`).
- **No terminal-width auto-detection.** Upstream glamour's `NewTermRenderer` auto-sizes word-wrap from the real terminal width; here `withWordWrap()` is manual-only (`Renderer.php:159-162`, `README.md:184-193`) — callers must query terminal size themselves and pass it in.
- **`GLAMOUR_STYLE=auto` doesn't detect background luminance.** Upstream picks dark/light by querying the terminal's actual background color; `Theme::fromEnvironment()` here only checks `isatty` and picks `dark()` vs `notty()` — never `light()` (`src/Theme.php:316-332`).
- **No footnote support.** CommonMark footnote extension isn't registered in the `Environment` (`Renderer.php:108-114`); glamour/goldmark renders footnotes. No test or code path references footnotes.
- **No `WithChromaFormatter`/custom-highlighter injection point** — `SyntaxHighlighter::highlight()` is a `final class` with only static methods (`SyntaxHighlighter.php:18,89`), so users can't swap in a real Chroma-backed highlighter without forking the class (compare `candy-freeze`'s pluggable `ChromaThemeLoader`, `candy-freeze/src/Theme/ChromaThemeLoader.php`).

### 2. Performance concerns

- **`SyntaxHighlighter::buildPattern()`** compiles a monolithic regex per language with unbounded/nested constructs, notably the HTML-comment alternative `(?:[^-]|-(?!->))*` and the C-style block-comment `[^*]*(?:\*(?!\/)[^*]*)*` (`SyntaxHighlighter.php:142`). These character-by-character negative-lookahead alternations are known to backtrack badly on adversarial/malformed input (e.g. a huge fenced code block containing many `<!--` without a matching `-->`). No length guard or timeout is applied before running `preg_match_all` (`SyntaxHighlighter.php:162`) — a large hostile "code" fence could cause a slow/hanging render.
- **`Renderer::render()` reuses `$this->blockStack`/`$this->styleSheet` only if non-null** (`Renderer.php:308-313`), but instances built via `copy()` (every `with*()` call) construct a **brand-new `Renderer`**, so the `MarkdownParser`/`Environment` is rebuilt on every fluent option change — `withWordWrap()`, `withHyperlinks()`, etc. each pay the CommonMark environment-setup cost again (`Renderer.php:278-300`, constructor `Renderer.php:108-115`). Fine for one-shot use, but chaining several `with*()` calls before a single `render()` (a natural usage pattern shown in the README, `README.md:184-193`) rebuilds the parser N times for N chained calls.
- **`renderChildren()`** recursively `implode()`s per-node output with no reserve/streaming (`Renderer.php:617-624`) — for very large documents this is O(n) allocations of intermediate strings; not pathological but worth flagging if candy-shine is ever used for large log/doc dumps rather than short README snippets (its actual intended use per README).

### 3. Test coverage gaps

- No test exercises the ReDoS-risk regex paths in `SyntaxHighlighter` with pathological/large input (only `tests/SyntaxHighlighterTest.php`, 807 lines, appears functional-only — no size/perf assertions).
- No test for CommonMark **footnotes** (feature absent, so naturally untested) or for unregistered extensions silently degrading via the `default => $this->renderChildren($node)` fallback in `renderNode()` (`Renderer.php:463`) — e.g. no test asserting behavior for a raw/unknown Node subtype.
- `Theme::fromEnvironment()` / `GLAMOUR_STYLE=auto` tests (`tests/ThemeTest.php:146-166`) don't cover the light/dark-detection gap noted above — there's no test asserting `auto` ever returns `light()`, because the code path never produces it.
- No test on `Renderer::copy()` re-parsing cost/behavior — nothing asserts a `Renderer` created via `withX()` still round-trips theme/state correctly across multiple chained `with*()` calls in one expression (implicitly covered only when a single option changes).
- `tests/SanitizeTest.php` (96 lines) covers `stripControls`/URL sanitization but nothing under `tests/` explicitly asserts OSC 8 hyperlink envelope integrity when a URL contains `\` / semicolons / other bytes that are OSC-8-syntax-significant but not C0/ESC/BEL (e.g. does a URL containing a literal `\x07`-adjacent multi-byte UTF-8 sequence or embedded OSC terminator confuse the envelope?). `safeUrl()` only strips `[\x00-\x1f\x7f]` (`Renderer.php:561-565`) — no test targets boundary bytes like `0x9c` (8-bit ST) which some terminals also treat as a string terminator.

### 4. Missing .vhs/*.tape demos

- `.vhs/` only has `render.tape` and `themes.tape` (`candy-shine/.vhs/render.tape`, `candy-shine/.vhs/themes.tape`), each driving `examples/render.php` / `examples/themes.php`.
- `examples/custom-renderer.php` and `examples/stdin-stdout.php` exist (`candy-shine/examples/custom-renderer.php`, `candy-shine/examples/stdin-stdout.php`) but have **no corresponding `.tape`** — the custom-theme-building and pipe/stdin usage patterns highlighted in the README (`README.md:110-150`, "Authoring a custom theme") aren't demonstrated visually.

### 5. Security concerns (untrusted-markdown → terminal-escape injection)

- Core defenses are present and look sound: `stripControls()` strips C0 controls + ESC from all source-derived text/code/HTML literals when `sanitize` is on (default true) — `Renderer.php:474-478`, applied at `renderText` (`:480-486`), `renderCode` (`:488-495`), `renderIndent` (`:497-504`), `renderFencedCode` (`:626-640`), `renderHtmlBlock` (`:567-575`), `renderHtmlSpan` (`:577-585`). URLs are unconditionally stripped of C0/ESC/BEL via `safeUrl()` regardless of the `sanitize` flag (`Renderer.php:561-565`), which is good defense-in-depth for the OSC 8 envelope.
- **Gap: `sanitize` is a single global on/off flag** — `withSanitize(false)` (`Renderer.php:255-258`) disables control-byte stripping for *all* node kinds simultaneously, including HTML blocks/spans which are the highest-risk surface (raw markdown-embedded HTML passed straight through with only a theme `Style::render()` wrapper, no HTML-entity handling or tag filtering — `renderHtmlBlock`/`renderHtmlSpan`, `Renderer.php:567-585`). There's no way to keep HTML sanitized while relaxing it for trusted plain text, or vice versa.
- **Emoji shortcode expansion runs before sanitize** (`Renderer::render()`, `Renderer.php:304-306`) — low risk since the replacement map is a fixed literal-to-emoji table (`Renderer.php:363-384`), but worth noting the ordering: expansion happens on raw untrusted markdown prior to any control-byte stripping, so if the map were ever extended with attacker-influenced input this ordering assumption should be revisited.
- **`renderImage()`** emits `alt (url)` with the URL always passed through `resolveUrl()`/`safeUrl()` (`Renderer.php:518-532`), so no raw-URL injection vector there.
- No sandboxing/backpressure against the ReDoS-prone `SyntaxHighlighter` regexes noted in §2 — a hostile fenced code block is also a (mild) DoS surface, not just a performance nit, if candy-shine is ever fed fully untrusted markdown (e.g. rendering user-submitted READMEs in a TUI).

### 6. Functionality duplicated with other libs

- **Syntax highlighting duplication**: `candy-freeze` already ships a Chroma-theme-aware pipeline (`candy-freeze/src/Theme/ChromaThemeLoader.php`, `candy-freeze/src/Theme/VsCodeThemeLoader.php`) for its screenshot/rendering use case, while `candy-shine` maintains its own independent, much smaller regex-based `SyntaxHighlighter` (`candy-shine/src/SyntaxHighlighter.php`). These are two separate, non-shared syntax-highlighting implementations across the monorepo with different language coverage and no common abstraction — a candidate for extraction into a shared foundation lib (e.g. under `candy-core` or a new `candy-`-prefixed highlighting primitive) rather than reimplementing per-lib.
- **Style/Table/Border are correctly reused, not duplicated** — `candy-shine` depends on `SugarCraft\Sprinkles\{Style,Border,Table}` for theme styling and GFM table rendering (`Renderer.php:15-17`, `898-931`) rather than reinventing them. No duplication found against `candy-sprinkles` here.
- No functional overlap found with `candy-flip` (that lib is a TUI player/frame decoder for `.tape`/asciicast-style playback, unrelated domain — `candy-flip/src/{Player,Decoder,Frame}.php`).

### 7. Documentation gaps

- README's "What it renders" section (`README.md:97-108`) doesn't mention CommonMark extensions actually wired in (`TaskListExtension`, `AutolinkExtension`, `StrikethroughExtension`, `DescriptionListExtension`, `TableExtension` — `Renderer.php:109-114`) by name, nor does it disclose the **absence** of a footnote extension, which upstream glamour users might expect.
- No documentation of the `sanitize`/`withSanitize()` global-toggle security tradeoff described in §5 — the README's "Renderer options" list (`README.md:181-193`) omits `withSanitize()`/`sanitize()` entirely even though it's a real constructor/builder option (`Renderer.php:86-116`, `255-264`).
- `SyntaxHighlighter`'s language coverage (8 languages + aliases) isn't documented anywhere in the README's syntax-highlighting mention (`README.md:100-101` just says "regex syntax highlighting for PHP / JS / TS / JSON / Python / Go / Bash / SQL" — actually this *is* documented correctly, so no gap here beyond not cross-referencing the alias table in `SyntaxHighlighter::ALIASES` (`SyntaxHighlighter.php:74-83`), e.g. `golang`→`go`, `jsonc`→`json` aren't mentioned).
- `CALIBER_LEARNINGS.md` is a single-line stub (`candy-shine/CALIBER_LEARNINGS.md:6`) with no per-lib gotchas beyond the golden-snapshot pattern — no note about the ReDoS-prone regexes, the global-sanitize tradeoff, or the syntax-highlighter's limited language set for future contributors to avoid re-litigating.

---

## candy-sprinkles

Scope: `candy-sprinkles` (port of `charmbracelet/lipgloss`), read-only audit of `src/`, `tests/`, `README.md`, `CALIBER_LEARNINGS.md`, `composer.json`, `.vhs/`. All 548 tests pass (`vendor/bin/phpunit`, 2143 assertions, ~1.6s) after a local `composer install`. This is a mature, largely complete port — most gaps found are cosmetic/duplication rather than missing core functionality.

### 1. Missing/incomplete functionality vs upstream lipgloss

- Overall coverage is strong: borders (`src/Border.php` — `normal/rounded/thick/double/block/ascii/hidden/markdownBorder`, per-side toggles, titles via `src/Border/BorderTitle.php` + `TitleAnchor.php`), gradients (`src/Border/BorderGradientBlend.php`), adaptive color (`src/AdaptiveColor.php`, `Style::foregroundAdaptive()`/`resolveAdaptive()` at `src/Style.php:225-289`), profile-tiered color (`src/CompleteColor.php`, `src/CompleteAdaptiveColor.php`), and renderer/profile detection (`src/Renderer.php`, delegates to `candy-core`'s `ColorProfile::detect()`) are all present and mirror lipgloss v2.
- **No whole-style/text gradient.** Gradient support is scoped only to `BorderGradientBlend` (`src/Border/BorderGradientBlend.php:1-76`) and `Style::borderForegroundBlend()` (`src/Style.php:533-542`). Upstream lipgloss (and several charm demos) also gradient plain foreground text per-character; there's no `Style::foregroundBlend()`/text-gradient equivalent — a caller wanting a gradient heading has to hand-roll it via `Cell`/`Markup`.
- `Style::inherit()` doc (`src/Style.php:757-767`) and README (`README.md:308-314`) both state inheritable props exclude `fgAdaptive`/`bgAdaptive`/`fgComplete`/`bgComplete` — confirmed in the `inherit()` implementation those ARE actually carried over (`src/Style.php:774-777`), so the doc comment is stale/inaccurate (undersells what inherit() does — not a functional bug, just a doc gap, see §7).

### 2. Performance concerns

- `Style::render()` (`src/Style.php:937-1079`) is not memoized — every call to `render()` re-walks padding/margin/border/SGR codegen from scratch, even for a `Style` rendered in a tight redraw loop (e.g. a table cell re-rendered every frame). Given `Style` is immutable and cheap to compare, a `render()` result cache keyed on `(spl_object_id, content)` — or just caching `buildContentSgr()`'s output per-instance since it's pure and content-independent — would cut redundant SGR-codegen for high-frequency TUI redraws. Not currently a measured bottleneck (tests run in ~1.6s), but flagged since Style is "cited repo-wide as canonical" and gets called in tight loops by every consumer.
- `buildContentSgr()` (`src/Style.php:1404-1438`) and `buildBorderSgr()` (`src/Style.php:1440-1446`) are recomputed on every `render()` call rather than once at construction — could be memoized as a lazily-computed readonly-esque field, but `readonly` properties can't be back-filled post-construction without a wrapper object, so this is a deliberate trade-off, not an oversight.
- `render()`'s width-measurement pass calls `Width::string()` once per line to compute `$innerWidth` (`src/Style.php:976-980`) and then again inside `halign()` per line (`src/Style.php:1134`) — line widths are computed twice when `width` is unset. Minor; for very large content blocks (many lines) this doubles the cost of ANSI-aware width scanning.

### 3. Test coverage gaps

- Test suite is broad (26 test files, 548 tests) and most "no dedicated test file" hits resolve to solid *indirect* coverage: `AdaptiveColor`/`CompleteColor` via `StyleTest`/`RendererTest`; `CompleteAdaptiveColor` via `StyleTest.php:533-541`; `Layer` via `CanvasTest`; `LightDark`/`Align`/`VAlign` via `RendererTest`/`StyleTest`/`TableTest`.
- Real gaps with **no evident direct or indirect assertions**:
  - `src/Layout/Rect.php`, `src/Layout/Constraint.php`, `src/Layout/Direction.php`, `src/Layout/Fill.php`, `src/Layout/Length.php`, `src/Layout/Max.php`, `src/Layout/Min.php`, `src/Layout/Percentage.php`, `src/Layout/Ratio.php` — only exercised transitively through `tests/Layout/LayoutSolverTest.php` and `tests/Layout/SolverFactoryTest.php`; none of these value objects have a dedicated test asserting their own accessors/edge-case construction (e.g. negative `Constraint::length(-1)`, `Rect` zero-area edge cases).
  - `src/Listing/Enumerator.php` and `src/Tree/Enumerator.php` — no direct test file; only covered indirectly if `ItemListTest`/`TreeTest` happen to exercise every enumerator variant (bullet/dash/asterisk/arabic/alphabet/roman/romanUpper/decimal/none per README §Public API) — worth confirming each named enumerator is hit, not just the default.
  - `src/Table/Data.php` (the row-reader interface) and `src/Border/TitleAnchor.php` (6-case enum) have no dedicated tests; `TitleAnchor` is exercised via `BorderTitleTest` but only for whichever anchors that test happens to use.
  - `src/Layout/Solver.php` (interface) is untested directly, which is expected (interface, not logic) — no action needed.
- **Known, tracked, gated bug**: `CassowarySolver` Ratio-constraint bug (`CALIBER_LEARNINGS.md:17`) causes 14 test failures when `SUGARCRAFT_LAYOUT_SOLVER=cassowary` is forced — but this is opt-in (default solver is greedy) and gated with an `E_USER_WARNING` in `src/Layout/SolverFactory.php:36-45`, and `tests/Layout/SolverFactoryTest.php` covers the env-var switch itself. Not a live regression, just carried tech debt.

### 4. Missing `.vhs/*.tape` demos

Existing: `border`, `canvas`, `constraint-dashboard`, `dashboard`, `list`, `style`, `table`, `tree` (8 tapes, all with matching `.gif`s, all wired presumably into `.github/workflows/vhs.yml`).

Not demoed despite being README-documented features:
- `Theme` — no tape showcases the 10 named theme factories side-by-side (dark/light/dracula/tokyoNight/oneDark/githubDark/solarizedDark/solarizedLight/ansi/adaptive), despite `Theme` being called out as "SSOT for theming across consumer libs" — a natural, high-value visual demo.
- `Markup` (`[tag]text[/]` parser) and `StyleParser` (`[text](fg:red,bold)` parser) — both are public, README-documented, distinct parsers with no visual demo.
- `Hsl` factory — no demo of `Hsl::color()`/`Hsl::parse()` output.
- Hyperlink/underline-style feature (`Style::hyperlink()`, `UnderlineStyle::Curly` etc., README §291-300) — not visually demoed (admittedly hard to show a clickable OSC-8 link in a GIF, but the curly/dotted/dashed underline styles are visually demonstrable).

### 5. Security concerns

- None found. `Style::hyperlink()` (`src/Style.php:443-454`) delegates OSC-8 wrapping to `candy-core`'s `Ansi::hyperlink()`, which sanitizes both `$url` and `$id` via `stripOscControlBytes()` (`candy-core/src/Util/Ansi.php:269-303`) before embedding them in the escape sequence — no raw terminal-escape-injection vector via user-supplied link text/id.
- `Style::transform()` (`src/Style.php:481-484`) accepts an arbitrary `\Closure` applied to rendered output — this is by design (mirrors lipgloss `Transform`) and not a vulnerability in itself, but is worth noting as a place where a consumer-supplied closure runs against rendered content; no sanitization is expected or needed at this layer.
- `StyleParser::parseColor()` (`src/StyleParser.php:173-213`) treats any string starting with a hex digit as a hex color candidate (`ctype_xdigit(substr($value,0,1))`, line 207) even without a leading `#` — e.g. the literal word `"dead"` would be routed into `Color::hex('dead')` rather than falling through to "unknown color". Not a security issue, but a minor correctness footgun for arbitrary/untrusted input strings.

### 6. Duplication with candy-palette / candy-kit — `dracula()` theme drift

**Confirmed and detailed as requested.** There is no single canonical `dracula()`/theme source; at least 8 files across the monorepo independently define a `dracula()` factory, each with its own field shape and, in some cases, different color-to-slot mappings for the same official Dracula palette:

- `candy-sprinkles/src/Theme.php:91-109` (**canonical**, 13-slot `Theme`: foreground/background/primary/secondary/accent/muted/error/warning/success/info/border/separator/cursor):
  `primary=#bd93f9` (purple), `secondary=#50fa7b` (green), `accent=#ff79c6` (pink), `muted=#6272a4` (comment), `warning=#ffb86c` (orange). **No slot uses Dracula's official "Yellow" `#f1fa8c`.**
- `candy-kit/src/Theme.php:163-174` — a *structurally different* 7-slot `Theme` (`success/error/warn/info/prompt/accent/muted`, each a `Style` not a `Color`):
  `accent=Color::hex('#bd93f9')` — this is candy-sprinkles' **`primary`** color, but candy-kit calls it `accent`.
  `prompt=Color::hex('#ff79c6')` — this is candy-sprinkles' **`accent`** color, but candy-kit calls it `prompt`.
  `warn=Color::hex('#f1fa8c')` — Dracula's official Yellow, which candy-sprinkles' Theme has **no slot for at all** (candy-sprinkles instead uses Orange `#ffb86c` for its `warning` slot).
  → Net effect: the word "accent" refers to two different Dracula colors (purple vs. pink) depending on which lib you're in, and candy-kit's warn color has no counterpart in candy-sprinkles' theme.
- Also drifted (broader duplication, not just candy-kit): `candy-shine/src/Theme.php` (uses both Orange `#ffb86c` for heading5 and Yellow `#f1fa8c` for heading6 — captures both, unlike either candy-sprinkles or candy-kit), `candy-forms/src/Theme.php` (5-color subset, no orange/yellow at all), `candy-freeze/src/Theme.php` (windowYellow uses Yellow `#f1fa8c`), `candy-vt/src/Theme.php` (16-slot ANSI palette array, uses Yellow `#f1fa8c` at index 3), `sugar-dash/src/Foundation/Theme.php` (9-slot `Theme`, uses Orange `#ffb86c` for `warning` like candy-sprinkles, plus a `highlight` slot candy-sprinkles doesn't have, set to the purple `#bd93f9`).
  → 7 independently-hand-typed `dracula()` factories total, none importing from `candy-sprinkles\Theme` or from each other. Any correction to a hex value (e.g. fixing a wrong shade) has to be applied 7 times by hand.
- **`candy-palette` has no relationship/drift here** — its `src/Palette.php` (a port of `charmbracelet/colorprofile`) is scoped entirely to terminal color-*profile detection and degradation* (TrueColor→ANSI256→ANSI→NoTTY), not named color themes. It has no `Theme` class and no `dracula()` factory; grep confirms zero references to `dracula`/`Theme` in `candy-palette/src`. No duplication to report between candy-sprinkles and candy-palette specifically — the drift is entirely a candy-sprinkles vs. candy-kit (and, more broadly, vs. 5 other libs) problem.
- **CALIBER_LEARNINGS.md contradiction found while investigating this**: `candy-sprinkles/CALIBER_LEARNINGS.md:6` asserts *"Theme's `primary`/`secondary` and `accent`/`muted` slots are passed the **same** Color value in all 10 named constructors — they are true aliases"*. This is **false** for `dracula()` (and for `tokyoNight`/`oneDark`/`githubDark`/`solarizedDark`/`solarizedLight` — confirmed by reading `src/Theme.php:91-215`, e.g. dracula's `accent=#ff79c6` ≠ `primary=#bd93f9`). The README (`README.md:155`, `README.md:359-362`) correctly documents the *actual* current behavior (accent/muted are aliases only in the "basic" `dark()`/`light()`/`ansi()` themes, distinct in the richer named themes) — so the learnings file is stale relative to both the code and the README, and should be corrected or removed to avoid misleading future agents into "fixing" theme distinctness as if it were a bug.

### 7. Documentation gaps

- `Style::inherit()` doc comment (`src/Style.php:757-767`) doesn't mention that `fgAdaptive`/`bgAdaptive`/`fgComplete`/`bgComplete` are inherited (they are, per the constructor call at `src/Style.php:774-777`) — README's inheritance section (`README.md:308-314`) lists only the original lipgloss-v1-era inheritable set (`bold/italic/underline/strike/faint/blink/reverse/fg/bg/borderFg/borderBg`) and likewise omits the newer adaptive/complete-color and `hyperlink`/`underlineColor`/`underlineStyle`/`paddingChar`/`marginChar`/`rapidBlink` fields that `inherit()`'s actual `self(...)` construction (`src/Style.php:768-817`) also merges. Both the doc-comment and the README undersell what `inherit()` covers today.
- `CALIBER_LEARNINGS.md:6` is stale/incorrect per §6 above — should be corrected to match the README's accurate description rather than left as a standing (wrong) rule for future sessions.
- No documentation cross-references the `dracula()`/theme duplication across `candy-kit`/`candy-shine`/`candy-forms`/`candy-freeze`/`candy-vt`/`sugar-dash` — nothing in candy-sprinkles' README or CALIBER_LEARNINGS.md flags that it is the intended SSOT that those other libs should be consuming (`CALIBER_LEARNINGS.md:8` says Theme "is the SSOT for theming across consumer libs (sugar-dash, sugar-charts in Phase 03)" but `sugar-dash/src/Foundation/Theme.php` demonstrably still hand-rolls its own independent `Theme` class/factories rather than depending on `candy-sprinkles\Theme` — the SSOT claim doesn't hold in the code as it stands).

---

## candy-testing

### Missing/incomplete functionality & underused API surface

- **`ScriptedInput`/`ProgramSimulator` are almost entirely unadopted.** Repo-wide grep shows `ProgramSimulator`/`ScriptedInput` used in exactly one consumer (`sugar-reel/tests/PlayerTest.php`), while 80 other test files (`sugar-prompt/tests/Field/*.php`, `sugar-prompt/tests/FormTest.php`, `sugar-prompt/tests/KeyMapTest.php`, etc.) hand-roll `->update(new KeyMsg(...))` sequences directly instead of using the fluent builder the lib exists to provide. This is the single biggest gap: the harness's flagship API isn't reaching consumers, likely because behaviour tests need direct access to a concrete `Model` (not a `Program`), and `ProgramSimulator::for()` only accepts a `Program` (candy-testing/src/ProgramSimulator.php:64), forcing an extra wrapping step most tests skip in favor of calling `$model->update()` inline.
- **`TapeRecorder` (candy-testing/src/Tape/TapeRecorder.php) has zero consumers.** Grep for `TapeRecorder` outside `candy-testing/` itself returns nothing — no lib's VHS `.tape` files are generated through it; tapes are hand-written per `record-vhs-demo`/`vhs-tape-record` skill conventions instead. Either wire it into the vhs-tape-record workflow or consider it dead weight.
- **`Assertions::assertCellGrid()` (candy-testing/src/Snapshot/Assertions.php:69) has zero consumers** outside its own `AssertionsTest.php`. No lib uses cell-grid snapshotting despite `candy-vt`'s `Terminal`/`Screen` cell API being the documented pattern in `.claude/rules/test-conventions.md`. Libs that assert on `Buffer`/`Terminal` cells do it ad hoc.
- **`TestResult::assertCmdCount()/assertCmdContains()/assertNoCmds()`** (candy-testing/src/TestResult.php:37-77) are dead: not used by any consumer, and not even exercised by candy-testing's own test suite (`ProgramSimulatorTest.php` never calls them). They also throw plain `\RuntimeException` instead of going through `PHPUnit\Framework\Assert::fail()` like `Assertions` does — inconsistent with the rest of the lib's failure-reporting convention (no PHPUnit "Failed asserting…" formatting, doesn't count as a PHPUnit assertion).
- **`TemporaryDirectoryTrait` (candy-testing/tests/Concerns/TemporaryDirectoryTrait.php) is not reusable by consumers** — it lives under `tests/` (`SugarCraft\Testing\Tests\Concerns` namespace, wired only via `autoload-dev`), so no other lib's `composer.json` can pull it in. Meanwhile 162 test files repo-wide (`sugar-prompt`, `candy-wish`, `sugar-stash`, `sugar-wishlist`, `candy-flip`, `sugar-reel`, `tools/tests/CheckPathReposTest.php`, etc.) hand-roll `sys_get_temp_dir()`/`mkdir`/manual recursive cleanup. This is exactly the kind of hand-rolled pattern that should be consolidated into candy-testing's `src/` and published for reuse (see "Duplicated functionality" below).
- No helper for asserting on `Model::subscriptions()` behavior beyond the implicit pumping inside `ProgramSimulator::run()` — nothing lets a test assert "subscription X fired N times" or inspect which subscriptions are currently registered.

### Test coverage gaps (candy-testing's own tests)

- 80 tests / 134 assertions pass (`vendor/bin/phpunit`, verified locally), and most public methods are covered class-by-class.
- **`TestResult::assertCmdCount()`, `assertCmdContains()`, `assertNoCmds()` have no tests at all** — confirmed via `grep -rn "assertCmdCount|assertCmdContains|assertNoCmds"` returning only the `TestResult.php` definitions, nothing in `tests/`.
- `ProgramSimulator::withRealCmdRunner()`/subscription-pumping cycle-overflow guard (`applyMsg()`'s `$maxCycles = 10_000` in candy-testing/src/ProgramSimulator.php:216) has no test exercising the overflow path itself (no test drives 10,000+ cmd-produced messages to confirm the `RuntimeException` fires).
- `GoldenFile::resolve()` traversal/edge cases (see Security below) are untested — no test asserts behavior for `..`-containing relative paths.

### Security concerns

- **`GoldenFile::resolve()` (candy-testing/src/Snapshot/GoldenFile.php:67-70) does no path-traversal sanitization.** It does `$baseDir . '/fixtures/' . ltrim($relative, '/')` — a relative path containing `../../` will happily escape the `fixtures/` directory. Given `$baseDir`/`$relative` are today always literal strings authored by trusted test code, this is low real-world risk, but if any lib ever builds golden paths from a data-provider-supplied name (e.g. parametrized fixture names), `GoldenFile::save()` (candy-testing/src/Snapshot/GoldenFile.php:46, which calls `mkdir(..., recursive: true)` and `file_put_contents` unconditionally) could write files outside `tests/fixtures/`. Worth a `str_contains($relative, '..')` guard or `realpath()`-based containment check for defense-in-depth, since this becomes an even bigger footgun once golden paths are parametrized (e.g. matrix-driven snapshot tests).

### Documentation gaps

- **README.md documents `ProgramSimulator`, `Assertions`, and `ScriptedInput` but omits `TapeRecorder` and `TestResult`'s assertion helpers entirely** (candy-testing/README.md — no section for either). Given `TapeRecorder` has zero consumers (see above), lack of docs plus lack of workflow wiring likely explains the non-adoption.
- No worked example showing the "capture-only" (`withRealCmdRunner(false)`) vs "execute" mode trade-off beyond a one-line docblock (candy-testing/src/ProgramSimulator.php:97-113) — this is a subtle, easy-to-misuse toggle (defaults to executing side-effecting cmds) that deserves a README callout, since a test author using `ProgramSimulator::for($program)->run()` without opting into capture-only mode will silently execute real cmd side effects.
- No documented guidance for extracting `TemporaryDirectoryTrait` for reuse (it isn't public API today, so there is nothing to document, but its existence + non-reuse is itself worth flagging in `CALIBER_LEARNINGS.md`).

### VHS demo — confirmed exempt

- `candy-testing` does not appear in `.github/workflows/vhs.yml`'s hand-maintained `all=(...)` array (`grep -n "candy-testing" .github/workflows/vhs.yml` returns nothing), and there is no `.vhs/` directory under `candy-testing/`. Correctly exempt as a non-visual foundation lib, consistent with AGENTS.md's exemption note for `candy-pty`/`candy-testing`/FFI/codec libs.

### Duplicated functionality that should be consolidated here instead

- **Temp-directory setup/teardown** (`sys_get_temp_dir()` + manual `mkdir`/recursive `unlink`/`rmdir`) is duplicated in ~162 test files across the monorepo (`sugar-prompt`, `candy-wish`, `sugar-stash`, `sugar-wishlist`, `candy-flip`, `sugar-reel`, `tools/tests/CheckPathReposTest.php`, and more) — candy-testing already has a working, tested implementation (`TemporaryDirectoryTrait`) but it's stranded in `tests/Concerns/` where no consumer can `use` it. Promoting it to `src/Concerns/TemporaryDirectoryTrait.php` (public namespace, added to `require-dev` autoload for consumers) would let every one of those 162 files drop their hand-rolled setUp/tearDown boilerplate.
- **Behaviour-test scripted-KeyMsg sequences**: the 80 files that manually construct `new KeyMsg(type: KeyType::Char, rune: '+', alt: false, ctrl: false, shift: false)` inline (verbose 6-arg constructor calls, as also seen inside candy-testing's own `ProgramSimulatorTest.php`) are exactly what `ScriptedInput::key()` exists to replace. Worth auditing whether `ProgramSimulator::for()` could also accept a bare `Model` (not just `Program`) to lower the adoption barrier, since most consumers are testing a `Model` directly rather than a full `Program`.
- No evidence of a second, independently-invented golden-diff/ANSI-escape-pretty-printer elsewhere (`assertGoldenAnsi`/`assertAnsiEquals` are the one thing that IS well-adopted — 10 `GoldenRenderTest.php` files across `sugar-prompt`, `candy-flip`, `sugar-reel`, `sugar-table`, `sugar-charts`, `honey-flap`, `candy-tetris`, `candy-shine`, `sugar-bits`, `honey-bounce`, `sugar-glow`, `candy-vcr`, `sugar-toast`, `candy-forms`, `candy-vt`, `sugar-calendar`, `candy-kit`, `candy-mines`), so this half of the lib is doing its job well.

### Performance concerns

- No caching layer for golden-file loads (`GoldenFile::load()` re-reads from disk on every assertion, candy-testing/src/Snapshot/GoldenFile.php:26-35) — not a real bottleneck at current fixture sizes/counts, but flagging since the audit asked: if any lib starts asserting many large golden files per test method, per-assertion `file_get_contents` with no in-process memoization could add measurable I/O across a large suite. Low priority given current fixture sizes are all small ANSI snippets.
- `ProgramSimulator::applyMsg()`'s cmd-drain loop (candy-testing/src/ProgramSimulator.php:213-246) calls `$model->view()` after every single update cycle and concatenates into `$this->outputBytes` — for models with expensive `view()` renders and many cmd-chained messages, this could add up, but the 10,000-cycle safety cap bounds the worst case.

---

## candy-tetris

Overall: this is a mature, feature-complete port — SRS wall kicks, hold piece,
ghost piece, T-Spin (3-corner rule), B2B, combo, perfect clear, level/gravity
curve, and a VS-computer mode are all present and tested. No high-score
persistence exists anywhere in the lib (or in candy-mines, checked for
comparison), so this is a genuine gap rather than an oversight limited to
this port.

### 1. Missing/incomplete functionality

- **Hold piece** — present. `src/Game.php:416-451` (`tryHold()`), toggled with `c`, `canHold` sentinel resets on lock (`src/Game.php:398`). Not missing.
- **Ghost piece** — present. `src/Renderer.php:78-101` computes `$ghost = $game->board->dropPiece($piece)` and renders it as `▒` with a faint per-tetromino style.
- **Wall-kick rotation (SRS)** — present and fairly complete: `src/Rotation/SrsKickTable.php` (JLSTZ + I-piece kick tables, CW/CCW), consumed via `Piece::rotationsWithKicks()` (`src/Piece.php:58-70`) and tried in order by `Game::tryRotate()` (`src/Game.php:236-255`). One gap: `SrsKickTable::kicks()` unknown-transition fallback returns naive-only (`tests/Rotation/SrsKickTableTest.php:56` `testUnknownTransitionFallsBackToNaive`) — worth confirming all 8 CW/CCW transitions per piece type are actually enumerated rather than relying on the fallback silently degrading exotic rotation sequences (e.g. two rapid `z`+`x` presses that skip an intermediate state is not applicable since rotation is always ±1, so likely fine, but not explicitly tested for O-piece "does nothing" degenerate case beyond `testOPieceUsesJlstzTable`).
- **Level/speed curve** — present, NES-style: `src/Score.php:54-71` (`framesPerRow()` / `gravityIntervalUs()`), consumed by `Game::scheduleGravity()` (`src/Game.php:124-130`). Not missing.
- **High-score persistence — MISSING.** No file I/O anywhere in `src/` or `bin/tetris` (`grep` for `file_put_contents|file_get_contents|fopen|json_decode|json_encode` returned nothing under `src/`/`bin/`). `Score` is purely in-memory and discarded on quit/game-over. `README.md` never mentions a persisted high-score/leaderboard feature, so this may be intentional scope, but it's worth flagging since `Score::points` is the natural candidate and the game-over banner (`src/Renderer.php:61-67`) shows "final score" with no comparison to a best score.
- Minor: no "next queue" depth control beyond the hardcoded `peek(3)` in `src/Renderer.php:118` — not a bug, just an unconfigurable constant.

### 2. Performance concerns

- **Full board+sidebar re-render every tick.** `Renderer::render()` (`src/Renderer.php:55-72`) rebuilds a brand-new `Buffer` (`Board::COLS × Board::VISIBLE_ROWS` = 200 cells) and calls `toAnsi()` from scratch, plus rebuilds the sidebar (next-queue previews, score, help text) on every `view()` call. At high levels (`Score::framesPerRow()` bottoms out at 1 frame/row ≈ 16.7 ms — `src/Score.php:54-64`), this is called on essentially every render tick. Whether this matters depends on candy-core's `Program`/renderer doing frame diffing upstream of `view()`; `candy-tetris` itself does no incremental diffing — it's a plain pure-function full rebuild per `AGENTS.md`'s TEA model, which is the expected pattern, but worth noting as the single most CPU-heavy per-tick cost in the lib.
- **Tick scheduling drift.** `Game::gravityStep()` (`src/Game.php:199-218`) and `hardDrop()`/`tryHold()` all call `self::scheduleGravity($game->score)` to re-arm the next tick relative to *when the current tick is processed*, not to a fixed wall-clock origin. Under system load/GC pauses this will drift (each tick is `now + interval`, not `origin + n*interval`), so effective gravity speed can lag behind the nominal level-derived rate. Not unique to tetris (inherent to `Cmd::tick`'s API) but the fastest levels (16.7 ms nominal) are the most exposed to this drift.
- Garbage-row application (`Game::addGarbageRows()`, `src/Game.php:470-526`) does an O(ROWS×COLS) scan for overflow-check plus a full row-shift copy — cheap at 24×10, no concern in practice.

### 3. Test coverage gaps

- **Game-over is never exercised through the real code path.** `tests/GameTest.php:103-118` (`testGameOverOnlyHonorsQuit`) hand-constructs `new Game(..., over: true, ...)` rather than driving `lockAndSpawn()`'s top-out branch (`src/Game.php:376-388`, `!$cleared->fits($newPiece)`) via a scripted sequence of drops that fills the spawn zone. The top-out branch itself is untested in `GameTest`; the only top-out coverage is via `addGarbageRows()` (`tests/GameTest.php:291-311`, `testAddGarbageTopsOutWhenStackOverflows`), which exercises a *different* over-setting code path (`src/Game.php:483-495` / `516-523`) than the natural lock-and-spawn top-out.
- **Line-clear detection** is well covered at the `Board` level (`tests/BoardTest.php:61-90`, single + multiple rows) but `Game`-level integration of `clearLines()` → scoring (`lockAndSpawn`, `src/Game.php:326`) has no direct test driving an actual 1/2/3/4-line clear through `update()`/hard-drop to assert the resulting `Score` — the scoring math itself is unit-tested in isolation (`tests/ScoreTest.php`), and B2B/combo/T-Spin bonus *stacking* inside `lockAndSpawn()` (the CALIBER-flagged tricky multiplier-ordering logic, `src/Game.php:355-374`) has no test that drives a real board+piece through `update()` to confirm the end-to-end bonus math — existing tests assert only the constants (`testPerfectClearBonusConstant`, `testB2BMultiplierConstant`, `src/Game.php:131-138` region... i.e. `tests/GameTest.php:131-140`).
- **Rotation edge cases**: `PieceTest`/`SrsKickTableTest` cover per-piece kick tables well (data-provider driven across all 7 kinds, CW and CCW), but there's no test for rotating against a *populated* board that forces a wall-kick to actually save an otherwise-blocked rotation (i.e. an integration test through `Game::tryRotate()` where the naive rotation fails but a kick candidate succeeds) — `Game::tryRotate()`'s kick-selection loop (`src/Game.php:241-253`) is exercised only indirectly via `testUpKeyRotatesPiece` (`tests/GameTest.php:63`), which is almost certainly the naive (no-kick) case on an empty board.
- **VS mode**: `VsGameTest`/`VsRendererTest`/`ComputerTest` exist and look substantial (208/130/303 lines) — not audited line-by-line here but coverage breadth looks reasonable for the AI heuristics.

### 4. Missing .vhs/*.tape demos

None — `.vhs/` has `play.tape`, `hold-demo.tape`, `vs-computer.tape`, and `vs-computer-simple.tape`, each with a matching `.gif`, and `README.md` embeds `play.gif` and `vs-computer.gif`. Fully covered; no gap here.

### 5. Security concerns

Not applicable — no disk I/O, no score persistence, no JSON parsing anywhere in `candy-tetris/src` or `bin/tetris`. If high-score persistence is added later (see §1), standard concerns would apply: validate/clamp the save path (don't trust an env var blindly), and use `json_decode(..., true, 512, JSON_THROW_ON_ERROR)` with a try/catch rather than trusting the file's shape.

### 6. Duplication with candy-mines / candy-core patterns

- **No persistence duplication to flag** — checked `candy-mines/src` (`Board.php`, `Cell.php`, `Difficulty.php`, `Game.php`, `Lang.php`, `Renderer.php`, `Stats.php`, `Stats/`, `Ui/`) for a `highscore`/`leaderboard` pattern to compare against; none exists there either, so there is no existing persistence convention in the monorepo for candy-tetris to duplicate or align with. If persistence is added to either lib, it should probably be extracted as a shared `candy-*` helper rather than implemented twice.
- **Game-loop / tick scheduling** correctly reuses `candy-core`'s `Cmd::tick()` (`src/Game.php:126-129`) rather than reinventing a scheduler — consistent with the monorepo's TEA pattern, no duplication found.
- **Board/cell-grid rendering** correctly reuses `candy-buffer`'s `Buffer`/`Cell`/`Style` (`src/Renderer.php:7-11, 83-113`) per the `buffer-cell-grid-for-game-boards` CALIBER pattern rather than ad-hoc ANSI string concatenation (there are two now-dead legacy helpers, `Renderer::block()` / `Renderer::ghost()` at `src/Renderer.php:184-202`, that build raw `\x1b[48;2;...m` strings and appear unused by `renderBoard()`/`renderSidebar()` — only `renderMini()` calls `self::block()`; `ghost()` looks fully dead code, worth a follow-up removal since it duplicates what `ghostStyle()`+`Buffer` already do).

### 7. Documentation gaps

- `README.md` is thorough (scoring tables, T-Spin/B2B/combo/perfect-clear rules, controls, VS mode) and cross-links `candy-buffer`/`candy-testing`. No missing top-level doc.
- `CALIBER_LEARNINGS.md` documents the three trickiest invariants (SRS kick-table separation, T-Spin 3-corner rule, B2B/combo multiplier stacking order) — good, matches what the code actually does.
- Gap: `README.md` doesn't mention hold-piece key (`c`) despite `hold-demo.gif`/`hold-demo.tape` existing in `.vhs/` and not being linked/embedded in `README.md` at all (only `play.gif` and `vs-computer.gif` are embedded — `README.md:14, 31`). The controls table (`README.md:47-57`) also omits the `c` (hold) key entirely, even though `Game::handleKey()` (`src/Game.php:171-173`) implements it and the sidebar help text in `Renderer::renderSidebar()` (`src/Renderer.php:129`) does list `c    hold`.
- No mention in README of the lock-delay mechanic (`Game::startWithLockDelay()`, `src/Game.php:103-117`) or whether `bin/tetris`'s default `Game::start()` actually uses lock delay (it does not — `bin/tetris` calls `Game::start()`, not `startWithLockDelay()`, so lock delay is dead/unused in the shipped binary despite being fully implemented and tested — worth flagging as either an intentional default-off design or an integration gap).

---

## candy-vcr

### 1. Missing/incomplete functionality vs upstream `charmbracelet/vhs`

- **`Output <path>` directive is parsed but silently discarded at compile time.** `src/Tape/Compiler.php:141` has `$node instanceof OutputDirective => null,` — the directive produces no Cassette event and no header field. Neither `src/Encode/TapeToGif.php` nor `src/Cli/RenderTapeCommand.php` nor `src/Cli/RenderBatchCommand.php` ever inspect the parsed AST for an `OutputDirective`; the actual `.gif` path always comes from the CLI `--output`/`--output-dir` flag or a `<tape>.gif` default (`RenderTapeCommand.php:40-42`, `RenderBatchCommand.php:110-111`). In upstream VHS, `Output foo.gif` inside the tape *is* the authoritative output path. 280/291 `.tape` files in this monorepo (`grep -rl "^Output " --include="*.tape" .`) declare an `Output .vhs/<name>.gif` line whose value happens to always match the CLI-derived default, which is why this has gone unnoticed — but any tape with `Output` pointing somewhere else (a different filename, a nested subdir, `docs/…`) would silently render to the wrong place. `README.md:555` documents this as "stored on the OutputDirective AST node for the render step" — that claim is inaccurate; nothing in the render step ever reads it.
- **No WebM/MP4/frame-folder output** — only GIF (`src/Encode/GifEncoder.php`, `FfmpegGifEncoder`, `PhpGifEncoder`). Upstream `vhs` supports `.mp4`/`.webm`/`.gif`/raw PNG-frame output selected by the `Output` file extension. Not present here at all (compounds with the point above since `Output` is ignored anyway).
- **No asciicast (`.cast`) *recording/export*** — `src/Format/AsciinemaFormat.php` is import/read-only (per README: "Read-only interop with `asciinema cat`/`asciinema play`... Stdin events become raw-byte inputs (no Msg envelope round-trip)"). Upstream vhs / asciinema tooling can both read and write `.cast`.
- **`Wait <duration>`** is parsed but a documented no-op (`README.md:557`, `src/Tape/Ast/WaitDirective.php`, `Compiler.php` — `WaitDirective` only advances the virtual clock, same as `Sleep`, with no actual "wait for pattern/screen" semantics that upstream `vhs`'s `Wait[@<duration>] [Screen|Line] /regex/` provides).
- **Missing directives entirely** (not in the Lexer/Parser/Compiler at all, `README.md:540-559` directive table confirms the supported set): `Require`, `Copy`/`Paste`, mouse directives (`Right Click`, `Middle Click`), `Alt+<key>`, and the VHS `Set` keys `Shell`, `LoopOffset`, `CursorBlink`, `WindowBar`/`WindowBarSize`, `BorderRadius`, `LineHeight`, `MarginFill` — only `Theme`, `FontSize`, `Width`, `Height`, `TypingSpeed`, `FontFamily`, `PlaybackSpeed`, and no-op `Padding`/`Margin` are handled (`Compiler.php:162-175`).
- **Only 5 built-in themes** (`TapeToGif::resolveTheme()`, `src/Encode/TapeToGif.php:251-259`): TokyoNight/TokyoNightLight/TokyoNightStorm/Dracula/SolarizedDark. Upstream `vhs` ships ~30+ named themes.
- **Decompiler limitations** (self-documented, `README.md:622-632`): `Hide`/`Show` don't round-trip on decompile; `Screenshot`/`Output` "are render-side only and leave no Cassette trace."

### 2. Performance concerns (~6 min/tape render)

- **Full cell-grid snapshot taken at every fps tick, before dedup.** `src/Render/FrameStream.php:74-79` calls `$terminal->snapshot($nextSnapshotTime)` (an O(cols×rows) grid copy from candy-vt) for *every* 1/fps tick regardless of whether the terminal state actually changed, and only afterward does `FrameDedup` (consumed lazily in `TapeToGif::buildFramesWithHolds()`, `src/Encode/TapeToGif.php:204-211`) collapse duplicates. A `Sleep 2s` at 30fps produces 60 full-grid snapshot allocations that are immediately discarded by dedup — wasted allocation/copy work scales with idle time in the tape, not with visual change.
- **Per-cell `mb_strwidth()` call with no memoization.** `src/Raster/GdRasterizer.php:125` (`isWideChar()` at line 270-273) calls `mb_strwidth($cell->char)` for every single cell of every non-deduped frame (cols×rows calls/frame). mb_* functions are notably slower than array/byte lookups; for a typical 80×24 grid that's 1,920 calls per frame even though the character set per frame is tiny (the `Glyphs` cache already proves only ~6-50 unique chars appear).
- **CFR encode path double-writes every frame to disk.** `FfmpegGifEncoder::encode()` (`src/Encode/FfmpegGifEncoder.php:73-78`) `copy()`s every already-written PNG into a second temp dir with sequential names before invoking ffmpeg, purely so `-i frame%05d.png` glob-style input works — this doubles frame I/O for the common (non-VFR) case.
- **No parallelism anywhere in the pipeline.** Rasterization (`TapeToGif::render()` foreach loop, `src/Encode/TapeToGif.php:107-150`) is fully sequential PHP-process CPU work; ffmpeg is invoked once per whole tape (not per frame — the `CALIBER_LEARNINGS.md:337-339` note "`FfmpegGifEncoder` (2.7): Sequential `Process::run()` for 100+ frames is slow... Consider `encodeBatchAsync()`" appears to be **stale/inaccurate** relative to current code, since `encode()` only calls `Process::run()` once total per tape, not once per frame — worth reconciling that learning note). The actual parallelism opportunity is across *frames within one tape* (rasterize N frames concurrently) or across *tapes in `render-batch`* (`src/Cli/RenderBatchCommand.php:106-129` renders tapes strictly one after another in a single `foreach`).
- **`render-batch` provides no `--jobs`/parallel option** — 291 tapes repo-wide are rendered strictly sequentially by `RenderBatchCommand`, which is consistent with the reported ~6 min/tape × N-tapes CI wall time; there is no fork/subprocess fan-out despite each tape render being independent and CPU-bound.
- The project's own `Glyphs` cache benchmark (`CALIBER_LEARNINGS.md:228-243`) found the glyph-tile cache only buys ~7-8% on the rasterize-only slice and **the ffmpeg two-pass palette encode dominates end-to-end wall time** for smoke.tape (1.28s of 1.28s total) — i.e. the reported bottleneck is the GIF encode step, not rasterization, which points investigation toward the ffmpeg `palettegen`/`paletteuse` two-pass filter graph (`buildFilterComplex()`, `FfmpegGifEncoder.php:116-121`) and per-frame PNG I/O rather than font/glyph caching.
- `PhpGifEncoder::lzwEncode()` (`src/Encode/PhpGifEncoder.php:194-203`) builds output via repeated `chr()`/string concatenation per pixel — flagged already in `CALIBER_LEARNINGS.md:345-347` (#2.9) as inefficient; lower priority since ffmpeg is the default encoder and PhpGifEncoder is the fallback.
- `Recorder::writeLine` `fflush()`s on every event (crash-safety trade-off, already noted in `CALIBER_LEARNINGS.md:333-335` as intentional) — recording-side, not render-side, but worth keeping in mind if "6 min/tape" investigation broadens beyond rendering.

### 3. Test coverage gaps

- No CLI-level test exercises `RenderTapeCommand::execute()` doing a **real** (non-dry-run) render end-to-end — `tests/Cli/RenderTapeDryRunTest.php` only covers `--dry-run`. `TapeToGifTest.php` covers the class directly, but the Symfony Command wiring (`--output`, `--font`, `--theme`, `--fps`, `--backend`, `--encoder`, `--strict` option plumbing in `RenderTapeCommand.php:35-92`) has no dedicated command-level test.
- No test asserts that the `Output <path>` tape directive actually controls the rendered file's location — which would have caught the compile-time no-op documented in section 1. `tests/Tape/ParserTest.php:160-167` only checks that the *parser* produces an `OutputDirective` AST node with the right `->path`; nothing downstream (`Compiler`, `TapeToGif`) is exercised for this directive.
- No regression test for `mb_strwidth` per-cell cost or a benchmark asserting a perf budget for full-tape renders (only the ad-hoc `scripts/bench-glyph-cache.php`, not wired into CI/PHPUnit).
- `render-batch`'s sequential/no-parallelism behavior has coverage for recursion (`RenderBatchRecursiveTest.php`) and cache reuse (`RenderBatchReuseTest.php`) but nothing timing-related to guard against a future regression that makes batch rendering even slower.

### 4. VHS demo

N/A confirmed — `candy-vcr` is itself the renderer used by every other lib's `.vhs/*.tape`; it is not matrix-listed in `.github/workflows/vhs.yml` for its own demo GIF (consistent with AGENTS.md's "non-visual libs... exempt" convention, though candy-vcr is visual-adjacent it's infrastructure, not a widget with a demo to record).

### 5. Security concerns

- **Mitigated: `Source <path>` directive is confined to the tape's base directory.** `src/Tape/Compiler.php:281-304` resolves `realpath()` and rejects (silently skips, no error) any resolved path that doesn't start with the base directory — good path-traversal defense for inlined tape sources, plus depth/cycle guards (`MAX_SOURCE_DEPTH = 10`, `$sourceStack`, `Compiler.php:55-58`). Note it fails *silently* (`return;` with no warning) rather than raising a `ParseError` — a masked-mistake risk more than a security hole.
- **Mitigated: `Screenshot <path>` is confined to the output directory** at render time via `TapeToGif::confineScreenshotPath()` (`src/Encode/TapeToGif.php:169-199`) — rejects `..`, backslashes, and absolute paths outside `outputDir`.
- **No command injection risk in the GIF encode path** — `FfmpegGifEncoder` builds an argv array for Symfony `Process` (`src/Encode/FfmpegGifEncoder.php:62-88`, `$args = [...]`) rather than shelling out via a formatted string, so no shell metacharacter injection from theme names, font families, or paths. Concat-list entries are still shell-quoted defensively (`str_replace("'", "'\\''", $absPath)`, line 142/149) even though they're written to a file consumed by `-f concat`, not a shell — extra caution, not a bug.
- **Not exploitable in practice, but worth flagging: the ignored `Output` directive (section 1) means there is currently no code path where a hostile `.tape`'s `Output` value could write outside the output dir** — since it's a no-op. If a future fix wires `Output` into the render step, it MUST get the same traversal confinement `confineScreenshotPath()` already applies to `Screenshot`, since `.tape` files are hand-authored across 58 libs and (per repo norms) go through normal PR review but are still less trusted than first-party PHP source.
- `RecordCommand::filteredHostEnv('')` (empty regex disables all env filtering) is a known, documented footgun (`CALIBER_LEARNINGS.md:19`) for accidentally recording secrets into cassettes — recording-side, not rendering-side, but part of the same lib's attack surface.

### 6. Overlap with candy-flip / candy-mosaic

- **No functional duplication found.** `candy-flip` (`candy-flip/src/*.php`: `Decoder.php`, `Frame.php`, `Player.php`, `Renderer.php`) is a GIF *viewer* — it decodes an existing `.gif` on disk via `ext-gd` and renders it as ANSI block-glyphs live in a terminal TUI (per `candy-flip/README.md`, port of `namzug16/gifterm`). `candy-vcr` *produces* GIFs from `.tape`/cassette sources; it never reads/plays back an existing `.gif`. Directionally inverse, no code-level overlap.
- `candy-mosaic` (`AdaptiveImage.php`, `Sixel`/`Kitty`/`iTerm2`/half-block renderers) is a general still-image-to-terminal-cell renderer (PNG/JPEG/static GIF) for live terminal display, not a GIF *encoder* — again the inverse direction (terminal→pixels vs candy-vcr's pixels→GIF-file), and no shared source.
- The only shared concept across all three is "GIF" as a data format, but no code, class, or GIF-parsing/encoding logic is duplicated between them; each solves a distinct problem (record→GIF vs GIF→terminal-playback vs image→terminal-display). No consolidation action needed.

### 7. Documentation gaps

- `README.md:555`'s claim that `Output <path>` is "stored on the OutputDirective AST node for the render step" overstates actual behavior — the render step never consults it (section 1). Should be corrected to state plainly that `Output` in the tape is currently ignored and the CLI `--output`/`--output-dir` flag (or `<tape>.gif` default) is authoritative.
- No documentation of the render-batch performance characteristics or the ~6 min/tape figure anywhere in `README.md` or `CALIBER_LEARNINGS.md` — the only perf numbers on record are the tiny `smoke.tape` benchmark (`CALIBER_LEARNINGS.md:228-243`, 1.28s end-to-end for a 5-frame tape) and the Phase 0 Player-replay-only benchmark (`CALIBER_LEARNINGS.md:76-85`, 31ms for 6 events, pre-render-pipeline). Neither measures a realistic multi-second/many-keystroke tape end-to-end, so there is no documented baseline to validate or regress against for the reported ~6 min figure.
- `CALIBER_LEARNINGS.md:337-339` (#2.7, "Sequential `Process::run()` for 100+ frames is slow... encodeBatchAsync()") appears to describe a per-frame ffmpeg invocation model that does not match the current `FfmpegGifEncoder::encode()` implementation (one `Process::run()` per whole tape, not per frame) — likely stale from an earlier design iteration; should be reconciled or removed to avoid misleading future optimization work.
- Missing-directive / missing-theme / missing-output-format gaps (section 1) are not called out anywhere as known limitations — the README's "Supported directives" table (lines 540-559) reads as a complete feature list with no "not yet implemented" section for `Require`, `Copy`/`Paste`, mouse clicks, additional `Set` keys, or non-GIF output formats.

---

## candy-vt

### 1. Parser regression: `start()` clears `stringBuffer` before dispatch (CONFIRMED, high priority)

`candy-vt/src/Parser/Parser.php:198-205`:

```php
private function start(int $byte, State $from): void
{
    $this->stringBuffer = '';
    // For DCS, the byte that triggers Start IS the final command.
    if ($from === State::DcsEntry || $from === State::DcsParam || $from === State::DcsIntermediate) {
        $this->cmd = ($this->cmd & ~0xFF) | $byte;
    }
}
```

Compare the fixed sibling implementation, `candy-ansi/src/Parser/Parser.php:266-272`:

```php
private function start(int $byte, State $from): void
{
    // For DCS, the byte that triggers Start IS the final command.
    if ($from === State::DcsEntry || $from === State::DcsParam || $from === State::DcsIntermediate) {
        $this->cmd = ($this->cmd & ~0xFF) | $byte;
    }
}
```

candy-ansi's `start()` has no `$this->stringBuffer = ''` line — it was
explicitly removed per candy-ansi's `CALIBER_LEARNINGS.md` 2026-05-30 entry
("step-20 ansi-consumers: Parser state machine — don't clear buffers in
start() before dispatch"), which states the fix "also fixed candy-hermit and
candy-freeze consumers." candy-vt/src/Parser/Parser.php never received the
same fix — the line is still present, so candy-vt carries the identical
regressed bug class today. `candy-vt/CALIBER_LEARNINGS.md` has no entry
documenting or superseding this, i.e. candy-vt's own learnings file is
unaware of the fix that happened in its sibling.

Buffer clearing is already handled correctly elsewhere in candy-vt's own
Parser: `clear()` (`Parser.php:151-156`, fired by `Action::Clear` on ESC/CSI
entry) and the tail of `dispatch()` (`Parser.php:252`, fired *after* the
handler consumes the buffer) both reset `stringBuffer`. The extra clear in
`start()` is redundant in the common case (buffer is already empty when a
string state is freshly entered) but becomes destructive in the "Anywhere"
transition paths that the transition table wires directly into `Start`
while a *different* string type is already being collected — e.g.
`SosString`/`PmString`/`ApcString` (state values 11-13) only get a
per-state `Put` override for bytes `0x20-0x7F` (`Transitions.php:162`); the
"Anywhere" block (`Transitions.php:86-106`) still governs 8-bit C1 bytes
`0x80-0xFF` for those three states, including `0x98`/`0x9E`/`0x9F`
(SOS/PM/APC re-entry) which map to `Action::Start`. `OscString`/`DcsString`
close that gap for their own byte ranges (`Transitions.php:206,245`) but
SOS/PM/APC do not — so any in-flight SOS/PM/APC payload containing an
embedded 8-bit PM/APC/SOS introducer byte re-triggers `start()`, and the
mishandling of that path is exactly the class of bug the candy-ansi fix
targeted. Whatever the precise trigger, the direct code diff against the
already-fixed sibling is unambiguous: **this is the same bug, unfixed.**

`tests/Parser/ParserTest.php` has `testDcsStTerminated` (line 307),
`testTwoOscSequencesBackToBack` (line 296), `testSosDispatch`/`testPmDispatch`/
`testApcDispatch` (lines 336/345/354) but none of them interleave a second
string-introducer byte *while* a first SOS/PM/APC/DCS/OSC string is still
being collected — so the regression is untested and would not be caught by
the current suite. `tests/FuzzerTest.php` only asserts "no exception +
cursor stays in bounds," not payload correctness, so it also would not
catch silent data loss from this bug.

**Recommendation:** remove `$this->stringBuffer = '';` from
`Parser::start()` (mirror candy-ansi exactly), then add a regression test
feeding an SOS/PM/APC/DCS/OSC string that gets interrupted mid-collection
by another string-introducer byte, asserting the first dispatch still
receives its (possibly partial) payload rather than empty string / no call.

### 2. candy-ansi path-repo dependency declared but unused; CsiHandlerImpl forked and diverged (CONFIRMED)

`candy-vt/composer.json:97-101` declares a path-repo for `../candy-ansi`,
but `candy-vt` does not require `sugarcraft/candy-ansi` in its `require`
block at all (`composer.json:27-32` lists only `candy-buffer`, `candy-core`,
`candy-sprinkles`) — the path-repo entry is dead weight with no
corresponding require. Confirmed via search: `grep -rn "SugarCraft\\Ansi"
candy-vt/src candy-vt/tests` returns **no matches** — candy-vt never
references the candy-ansi namespace anywhere.

Instead, candy-vt has its own `src/Parser/CsiHandlerImpl.php` (387 lines)
that independently reimplements a CSI dispatcher, diverging completely from
`candy-ansi/src/Parser/CsiHandlerImpl.php` (98 lines). The candy-ansi
version's doc-comment is explicit that this was meant to be temporary:

`candy-ansi/src/Parser/CsiHandlerImpl.php:8-13`:
```
 * Minimal CSI handler stub for the ANSI parser.
 *
 * This is a self-contained implementation that satisfies the CsiHandler
 * interface without depending on terminal-specific state (Cell, CellGrid,
 * Cursor, Theme). Those dependencies will be wired in step-12 when
 * candy-vt migrates onto candy-ansi.
```

That migration ("step-12") never happened. candy-vt's `CsiHandlerImpl`
(`candy-vt/src/Parser/CsiHandlerImpl.php:21-107+`) is a full mutable
implementation with its own scroll-region/fg/bg/attrs state, `CellGrid`,
`Cursor`, `Theme` wiring — a complete fork with no shared code path, and no
mechanism to keep the two in sync. Any future ANSI-parsing bugfix (like
finding #1 above) has to be manually ported between the two, and evidently
isn't being — see finding #1.

**Recommendation:** either (a) drop the unused `candy-ansi` path-repo entry
from `composer.json` if the migration is not planned, or (b) if the
migration is still intended, track it as a real backlog item and note the
CsiHandlerImpl drift explicitly in `candy-vt/CALIBER_LEARNINGS.md` so future
bugfixes are ported both ways.

### 3. candy-buffer dependency declared but its types never used (CONFIRMED)

`candy-vt/composer.json:27-32` requires `sugarcraft/candy-buffer:dev-master`
and `composer.json:52-59` wires its path-repo, but candy-vt reimplements its
own `Buffer`/`Cell` types rather than consuming candy-buffer's:

- `candy-vt/src/Buffer/Buffer.php` (namespace `SugarCraft\Vt\Buffer`, 107
  lines) is a **mutable** grid (`private array $grid`, no `readonly`, no
  `with*()`) vs. `candy-buffer/src/Buffer.php` (namespace
  `SugarCraft\Buffer`) which is an **immutable**, flat-array,
  `\JsonSerializable`, fluent `with*()`-based value object
  (`private function __construct(... private readonly array $grid)`).
- `candy-vt/src/Cell.php` (namespace `SugarCraft\Vt`, root-level renderer
  path) and `candy-vt/src/Cell/Cell.php` (namespace `SugarCraft\Vt\Cell`,
  full-emulator path) are both distinct from
  `candy-buffer/src/Cell.php` (namespace `SugarCraft\Buffer`), which
  additionally models `Style`/`Hyperlink`/continuation-cell semantics
  candy-vt reinvents independently.
- `grep -rln "SugarCraft\\Buffer" candy-vt/src candy-vt/tests` returns **no
  matches** — confirmed zero usage of the candy-buffer namespace anywhere in
  candy-vt.

This mirrors the pattern already called out for candy-query in prior
project memory ("candy-query reinvents TUI primitives") — candy-vt
reinvents `Buffer`/`Cell` instead of consuming the foundation lib it
declares a dependency on. Given candy-vt actually has **three** parallel
Cell-like/Buffer-like hierarchies of its own (root renderer path `Cell.php`/
`Buffer.php` is absent — actually `Cell.php` root + `Cell/Cell.php` +
`Buffer/Buffer.php`), consolidating onto candy-buffer would be a
non-trivial refactor, but the current state means the composer dependency
is pure dead weight.

**Recommendation:** drop the unused `candy-buffer` require + path-repo if
no migration is planned, or scope a follow-up to adopt `candy-buffer\Cell`/
`Buffer` and delete the local forks, consistent with the "adopt foundation
libs" precedent set in candy-query's post-audit cleanup.

### Architecture note: three Cell/Buffer/Terminal hierarchies (documented, not a bug)

Unlike findings #2/#3, candy-vt's **internal** duplication between
`SugarCraft\Vt\Terminal` (root, immutable, renderer path used by candy-vcr)
and `SugarCraft\Vt\Terminal\Terminal` (full VT500 emulator, mutable) is
explicitly documented in `README.md:85-141` ("Two Terminal classes") and is
an intentional split, not drift. Flagging only because it doubles the
maintenance surface for any parser-level fix (see finding #1: the
regression only needed fixing in `Parser\Parser`, which both Terminal
classes share via `HandlerAdapter`/`ScreenHandler`, but the *handler-level*
logic — e.g. `CsiHandlerImpl` per finding #2 — is fully forked between the
renderer path and the full emulator path, and also forked yet again against
candy-ansi).

### 4. Missing/incomplete functionality vs real VT100/xterm scope

- **Sixel / Kitty / iTerm2 inline image protocols**: no support anywhere
  (`grep -rniE "sixel|kitty.*graphic|iterm2" src tests README.md` → no
  matches). Reasonable scope cut for a TUI-testing cell-grid emulator, but
  undocumented as an explicit non-goal.
- **Mouse *reporting* (generating escape codes back to the app)**: candy-vt
  tracks DEC mouse-mode *request* state (`Mode::$mouseAny/$mouseCellMotion/
  $mouseExtended/$mouseSgr`, `src/Mode/Mode.php:29-34`,
  `src/Handler/ModeHandler.php:16-19`) but there is no mouse-event encoder —
  this is architecturally correct (candy-vt emulates the *display* side;
  input encoding belongs to candy-input) but is not called out in the
  README's mode-mapping table as an intentional boundary.
- **`Mode::$mouseHighlights` (DEC 1001)** is reserved but never set by any
  handler — documented as a known gap in
  `candy-vt/CALIBER_LEARNINGS.md:136-138` ("don't read it as 'highlights
  mode is on'").
- **Resize while in alt-screen** only resizes the active buffer, not the
  saved main buffer — documented gap in `CALIBER_LEARNINGS.md:149-151`
  ("revisit if a downstream TUI exercises this").
- **OSC 52 (clipboard)** read requests are recorded as events but the parser
  doesn't synthesize a reply — by design per `CALIBER_LEARNINGS.md:167-170`,
  but a consumer expecting round-trip clipboard emulation would need to
  build that themselves.

### 5. Performance concerns

- `Parser::feed()` (`Parser.php:52-58`) iterates byte-by-byte via
  `ord($bytes[$i])`, and `Parser::put()`/hot-path `Print` action append one
  byte at a time (`$this->stringBuffer .= chr($byte)` at `Parser.php:215`;
  `$handler->printChar(chr($byte))` at `Parser.php:139`). For large OSC/DCS
  payloads (up to the `MAX_STRING_BYTES = 1_048_576` cap at `Parser.php:25`)
  this is O(n) string-append-by-1-byte in a hot loop — PHP string
  concatenation is amortized-friendly but still far slower than a bulk
  substring/scan approach (e.g. finding the terminator byte with `strcspn`/
  `strpos` and slicing). candy-ansi caps its equivalent buffer at 64 KiB
  (`MAX_STRING_BUFFER = 65536`, `candy-ansi/src/Parser/Parser.php:35`) —
  16x smaller than candy-vt's 1 MiB cap — meaning candy-vt is deliberately
  more permissive on the same byte-at-a-time code path, compounding the
  cost/DoS-window for any single malformed/oversized OSC/DCS string.
- `src/Buffer/Buffer.php` stores the grid as nested `array<int,
  array<int, Cell>>` (`Buffer.php:20`) rebuilt cell-by-cell in
  `makeGrid()` (`Buffer.php:29-38`) — every `resize()`/full-buffer
  operation is O(rows×cols) with per-cell object churn (`Cell::empty()`
  allocated fresh for every cell), no row-level fast paths (e.g.
  `array_fill`).
- No apparent caching/memoization issue found in `Transitions::build()` —
  it's correctly built once via a `static $initialized` guard
  (`Transitions.php:54-61`), so no repeated 4096-byte table rebuild.

### 6. Test coverage gaps

- No test exercises interleaved string-introducer bytes mid-collection
  (the scenario implicated in finding #1) — see detail above.
- `phpstan-baseline.neon` flags `Method
  SugarCraft\Vt\Tests\Mode\CursorShapeTest::feed() is unused` and `Method
  SugarCraft\Vt\Tests\Mode\OriginModeTest::feed() is unused` — dead helper
  methods in two test files, suggesting copy-pasted-then-abandoned test
  helpers (`phpstan-baseline.neon`, message list).
- `phpstan-baseline.neon` also flags `Property
  SugarCraft\Vt\Terminal\Terminal::$scrollbackSize is never read, only
  written` — a real dead-field candidate in the full-emulator `Terminal`
  class, not merely a lint nit; worth a quick look at whether
  `withScrollbackSize()` is actually threading the value through to
  `Scrollback` or silently dropping it.
- Several PHPStan-suppressed test assertions are tautological/dead:
  `assertSame('...Sgr', '...Sgr')` and `assertTrue(true)` always evaluate
  true (`phpstan-baseline.neon` messages) — these tests aren't actually
  asserting anything meaningful at those lines and should be tightened.
- No dedicated regression test file per the CALIBER_LEARNINGS pattern used
  elsewhere in the monorepo for "bug found via fuzzer → add byte-verbatim
  regression case" (`CALIBER_LEARNINGS.md:231-238` documents the *policy*
  but no such fixture currently exists under `tests/fixtures/` for a past
  crash).

### 7. VHS demo — present, not missing

`candy-vt/.vhs/snapshot-demo.tape` and `.gif` exist and drive
`examples/feed-and-screenshot.php`; candy-vt is correctly present in
`.github/workflows/vhs.yml`'s hand-maintained `all=(...)` matrix (line 149)
and the OS-specific `lib:` matrix (line 225). No gap here — contradicts the
task's "likely N/A" assumption; candy-vt is a visual-ish enough library
(renders cell grids) that it was given a demo.

### 8. Security concerns

- Finding #1 (stringBuffer clear in `start()`) is itself a
  correctness/potential-DoS-adjacent issue: silent data loss on malformed
  or adversarial escape sequences is exactly the kind of bug that breaks
  downstream consumers parsing untrusted PTY output (as it already did for
  sugar-spark/candy-hermit/candy-freeze via the shared bug class).
- `MAX_STRING_BYTES = 1_048_576` (`Parser.php:25`) — 1 MiB per in-flight
  OSC/DCS/SOS/PM/APC string before silent truncation (`put()`,
  `Parser.php:207-216`) — is a generous DoS ceiling per-sequence; there's no
  cap on the *number* of sequences fed in one `feed()` call, so a
  pathological caller could still drive sustained memory pressure via many
  back-to-back near-1MiB strings. Not a crash risk (fuzz-tested,
  `tests/FuzzerTest.php`), but worth noting the 16x-larger cap vs candy-ansi
  is unexplained/undocumented — no comment justifies why candy-vt needs 1
  MiB vs candy-ansi's 64 KiB.
- `MAX_PARAMS = 32` / `MAX_PARAM_VALUE = 65535` (`Parser.php:23-24`) bound
  CSI/DCS parameter growth — overflow is silently dropped
  (`param()`, `Parser.php:168-196`), matching upstream xterm behavior and
  preventing unbounded param-array growth from adversarial input. Good.
- `FuzzerTest` (`tests/FuzzerTest.php`) provides genuine crash-safety
  coverage up to 100 KiB random streams plus an "escape storm" case — this
  is a solid mitigant for the security concerns above, though (per finding
  #6) it doesn't catch silent-data-loss-class bugs like finding #1, only
  crashes/out-of-bounds cursor.

### 9. Documentation gaps

- `README.md` is otherwise thorough (640 lines, covers architecture, both
  Terminal classes, CSI/OSC coverage tables, BCE, scrollback, DECOM,
  DECSCUSR, focus events, combining/wide chars, sync output, themes) but:
  - Does not mention Sixel/Kitty/iTerm2 graphics as an explicit non-goal
    (see finding #4).
  - Does not document the unused `candy-ansi`/`candy-buffer` composer
    dependencies (findings #2/#3) — a reader inspecting `composer.json`
    would reasonably expect to find candy-ansi/candy-buffer types in use
    somewhere in `src/`.
  - The "Two Terminal classes" section (`README.md:85-107`) is good but
    doesn't cross-reference that `CsiHandlerImpl` itself is *also* forked
    against candy-ansi's version of the same class name — a maintainer
    searching for "CsiHandlerImpl" across the monorepo would find two
    same-named, differently-behaved classes with no note connecting them.
- `CALIBER_LEARNINGS.md` (candy-vt's own) has no entry acknowledging the
  candy-ansi sibling's 2026-05-30 parser fix — meaning candy-vt's own
  learnings file, which is supposed to be the source of "patterns and
  anti-patterns learned," missed recording a directly-applicable lesson
  from a sibling library using the near-identical parser implementation.

---

## candy-wish

Audit scope: `candy-wish/src`, `candy-wish/tests`, `candy-wish/README.md`, `candy-wish/CALIBER_LEARNINGS.md`, `candy-wish/composer.json`, `candy-wish/.vhs`. Read-only.

### 0. Architecture premise (context for everything below)

candy-wish does **not** implement the SSH wire protocol. It is a userland
supervisor invoked by host `sshd` via `ForceCommand`
(`candy-wish/src/Server.php:11-30`, `candy-wish/README.md:29-56`). sshd
performs the actual key exchange, host-key presentation, and the first-pass
user authentication (PAM / `authorized_keys` / password) *before* this code
ever runs. This is a deliberate, documented departure from upstream
`charmbracelet/wish` (which embeds `gliderlabs/ssh` and owns host keys,
`PublicKeyHandler`, `PasswordHandler` callbacks invoked *during* the SSH
handshake). Every "Auth" middleware in this port (`Auth`, `PasswordAuth`,
`CertificateAuth`, `KeyboardInteractive`, `AuthMethods`) is a **post-hoc
allowlist check that runs after sshd has already accepted the connection**,
reading credentials sshd already validated (or, for `PasswordAuth`/
`KeyboardInteractive`, out-of-band channels sshd was configured to expose).
This is fine as designed, but every consumer of this library needs to
understand the trust boundary already moved once before `Server::serve()`
starts — flagged fully under Security below since it's easy for an operator
to assume "I added `Auth::class` middleware" replaces proper sshd
`AuthorizedKeysCommand`/PAM configuration.

### 1. Missing/incomplete functionality vs upstream `wish`

- No host-key management surface at all (no equivalent of `wish.WithHostKeyPEM`/
  `WithHostKeyPath`) — by design, since sshd owns host keys. Worth an explicit
  README callout (currently only implied by "Architecture" section,
  `README.md:27-56`) that operators must secure sshd's host keys themselves;
  candy-wish has zero visibility into host-key rotation/fingerprint pinning.
- No real pubkey-auth handshake hook (upstream's `PublicKeyHandler` runs
  *during* negotiation and can reject before password fallback is offered).
  `Auth::keyFingerprints` (`src/Middleware/Auth.php:39-92`) is a same-process
  allowlist check evaluated after the fact, sourced from
  `SSH_USER_KEY_FINGERPRINT`/`KEY_FINGERPRINT`/`SSH_USER_AUTH` env vars that
  only exist if sshd's `ExposeAuthInfo` is turned on — not default sshd
  behaviour. If an operator forgets `ExposeAuthInfo yes`, `Auth`'s
  fingerprint check silently falls through to `'<missing>'` and always
  rejects (fail-closed, at least) — but this failure mode isn't tested or
  documented outside the doc-comment.
- No session-recording / tee middleware (upstream wish has
  `activeterm`/logging examples via `wish.WithMiddleware` chains that record
  raw PTY bytes for audit). candy-wish has `Logger` (connect/disconnect JSON
  events only, `src/Middleware/Logger.php`) but nothing that captures
  session transcript bytes for compliance/audit replay.
- `SftpStub` (`src/Middleware/Subsystem/SftpStub.php`) is explicitly a stub,
  not a working SFTP subsystem — matches README's own disclaimer
  (`README.md:385-387`), not a gap so much as a documented limitation.
- No pluggable "authorized keys" file-backed auth helper (upstream `wish`
  ships convenience helpers reading a keys file); every auth decision here
  requires the caller to supply their own callback/allowlist array.

### 2. Performance concerns

- Concurrency model is process-per-connection (one `sshd` fork → one PHP
  process), so there is no PHP-side "many sessions on one ReactPHP loop"
  concern — each session gets its own OS process and its own ReactPHP loop
  instance is only used for async middleware await
  (`src/Transport/PromiseAwait.php`) and the pump loop's `stream_select`.
  This avoids goroutine-style scheduling entirely but means every session
  pays full PHP process start-up (~50-200ms, documented in `README.md:55`)
  — acceptable for the intended "spawn a shell" use case, but a design
  ceiling if someone wants thousands of concurrent lightweight sessions.
- `AsyncMiddleware::handle()` (`src/Middleware/AsyncMiddleware.php:41-57`)
  synchronously blocks the process via `PromiseAwait::settle()`
  (`src/Transport/PromiseAwait.php:35-78`), which calls `Loop::run()` to
  drain the *global* ReactPHP loop until the promise settles. Since each
  connection is its own process this is safe in isolation, but it does mean
  any async middleware (LDAP/OAuth/DB auth) fully blocks that process for
  up to the 30s timeout — no other work can proceed on that same process,
  which is expected given the 1-process-per-connection model, but worth
  flagging if a caller ever tries to reuse one PHP process across sessions
  (e.g. a FastCGI-style multiplexed sshd wrapper) — `Loop::run()`/`Loop::get()`
  usage assumes a single global loop instance per process lifetime.
- `RateLimit::take()` (`src/Middleware/RateLimit.php:58-91`) does a
  read-modify-write of a single shared JSON file under `flock(LOCK_EX)` on
  *every single connection attempt*, holding the exclusive lock across a
  `json_decode`+arithmetic+`json_encode`+temp-file-write+`rename()`. Under
  high connection-attempt volume (e.g. a credential-stuffing burst) this
  serializes all concurrent connecting processes on this one file lock —
  a self-inflicted bottleneck exactly during the scenario the rate limiter
  exists to survive. The doc-comment acknowledges this
  ("intentionally dependency-light... for high-volume deployments swap in
  Redis", `RateLimit.php:24-26`) so it's a known trade-off, not an oversight.
- `InProcessTransport::runChild()`'s teardown grace period
  (`src/Transport/InProcessTransport.php:328-334`) busy-polls
  `usleep(20_000)` up to 200ms checking `$child->exited()` — a tight
  200ms-max stall per disconnecting session; bounded and small, not a
  real concern, noted for completeness.

### 3. Test coverage gaps

Overall coverage is good — `AuthMethodsTest`, `CertificateAuthTest`,
`KeyboardInteractiveTest`, `PasswordAuthTest`, `AuthTest`, `RateLimitTest`,
`KeepaliveTest`, `LoggerTest`, `SubsystemTest`, `BubbleTeaTest`,
`SpawnTest`/`SpawnPtySystemIntegrationTest`, `ContextTest`,
`SessionTest`/`SessionMetadataTest`, `ServerTest`, `StreamHelperTest`,
`AsyncMiddlewareTest`, `DefaultChannelHandlerTest`, and 6 `Transport/*Test`
files (`candy-wish/tests/`) cover essentially every public class. Gaps:

- No dedicated test file for the 7 `Channel/Msg/*.php` DTOs
  (`BreakMsg`, `EnvMsg`, `ExecMsg`, `PtyReqMsg`, `ShellMsg`, `SignalMsg`,
  `WindowChangeMsg`) — they're presumably exercised indirectly via
  `DefaultChannelHandlerTest.php`, but there's no standalone
  construction/property test, so a regression in one DTO's constructor
  signature wouldn't be caught until the handler test happens to touch it.
- `src/Middleware/Subsystem/SftpStub.php` has no test at all (only
  `SubsystemTest.php` for the dispatch middleware itself) — acceptable
  given it's explicitly a non-functional stub, but worth a one-line
  smoke test asserting it doesn't throw when invoked.
- No test exercises the `AuthMethods` + `Auth` + `PasswordAuth` +
  `KeyboardInteractive` middleware *chained together* in one stack the
  way the README's multi-method example implies is possible — each is
  tested standalone. A chaining/precedence test (e.g. does `Context`'s
  `auth.methods` key set by `AuthMethods` actually get consulted by
  anything downstream today?) would catch the fact that nothing currently
  reads `AuthMethods::fromContext()` except the class's own static helper
  — it's dead wiring today (grep confirms no other middleware calls
  `AuthMethods::fromContext()`).
- No integration test proves `RateLimit`'s file-lock behavior under actual
  concurrent processes (only single-process/sequential token-bucket math is
  tested per `RateLimitTest.php`) — the `flock()` serialization claim in
  the doc-comment (§2 above) is unverified by a real concurrent-writer test.

### 4. Missing `.vhs/*.tape` demos

`.vhs/` has `showcase.tape` (→ `showcase.gif`) and `spawn-bash.tape`
(→ `spawn-bash.gif`), covering `examples/showcase.php` and
`examples/spawn-bash.php`. Two example scripts have **no** corresponding
tape:

- `examples/hello-server.php` — no tape (also has the correctness bug
  below, which a VHS run would have caught immediately).
- `examples/spawn-program.php` — no tape.

### 5. Security concerns (SSH server code — high priority)

- **Auth middleware runs after sshd's own authentication, not instead of
  it.** As described in §0, none of `Auth`/`PasswordAuth`/`CertificateAuth`/
  `KeyboardInteractive` can *reject an SSH connection at the protocol
  level* — by the time any of this code executes, sshd has already
  completed key exchange and (for the common `ForceCommand` deployment)
  already authenticated the user via PAM/`authorized_keys`. These
  middleware only decide whether the *already-connected* session's inner
  chain proceeds; on rejection they `fwrite(stderr, "Unauthorized...")` and
  `return` without calling `$next` (e.g. `Auth.php:58-70`,
  `PasswordAuth.php:61-67`). The process then exits normally — the
  attacker already got a full SSH session (auth'd by sshd) and can see
  whatever candy-wish writes to stdout/stderr before rejecting (banners,
  error text). This is a legitimate defense-in-depth layer, not a bypass,
  *provided* the operator also locks down sshd itself — but nothing in the
  code enforces or verifies that sshd-level auth is actually restrictive
  (e.g. nothing checks that `PasswordAuthentication no` is set when
  `PasswordAuth` middleware is used as the "real" gate). A misconfigured
  sshd (e.g. default `PermitRootLogin`/open `AuthorizedKeysCommand`)
  combined with an operator's false assumption that `Auth::class` is
  suflicient could under-protect a deployment. Recommend README callout:
  "these middleware are secondary allowlists; the actual authentication
  boundary is sshd's own config."
- **`PasswordAuth` — password transits `SSH_PASSWORD` env var.**
  (`src/Middleware/Auth/PasswordAuth.php:20-26,51-59`). The class does
  proactively `unset()`/`putenv()` to scrub it immediately after reading,
  which is a good mitigation, but the exposure window (readable via
  `/proc/<pid>/environ` by anyone with ptrace/proc access to that uid, and
  inherited by any child process spawned *before* the unset) still exists
  by construction — this mechanism (passing a password via an env var set
  by sshd) is inherently the weakest of the four auth middleware and the
  doc-comment correctly flags it, but nothing prevents an operator from
  combining `PasswordAuth` with `Spawn`/`InProcessTransport`, where the
  spawned child process inherits the *current* environment at
  `slave->spawn()` time (`InProcessTransport.php:269-275`) — if `Spawn`'s
  factory runs and captures env before `PasswordAuth`'s `handle()` clears
  it (ordering depends entirely on middleware registration order, which is
  the caller's responsibility and unenforced), the plaintext password
  could leak into the spawned child's environment. Worth a defensive check
  or at least a stronger doc warning: **`PasswordAuth` must be registered
  before any middleware that spawns a child process**, and there's no
  runtime assertion of that ordering.
- **No auth-attempt rate limiting is auth-attempt-aware.** `RateLimit`
  (`src/Middleware/RateLimit.php`) throttles by `Session::$clientHost`
  (source IP) at *connection* granularity, not by *failed-auth* attempts —
  it can't distinguish "5 different valid users connecting from one IP"
  from "5 failed password brute-force attempts from one IP" since it fires
  before the auth middleware even runs (ordering again depends on stack
  registration order — nothing enforces `RateLimit` before `Auth`). A
  brute-forcer who successfully evades sshd-level `MaxAuthTries` (e.g. by
  reconnecting a fresh TCP session per guess, since sshd resets its own
  per-connection auth-attempt counter each time) would only be slowed by
  the token bucket's per-IP connection rate, not specifically penalized
  for failed candy-wish-level auth. There's also no lockout/backoff
  *per username* (only per source IP) — a distributed/botnet brute force
  against one account from many IPs is entirely unthrottled by this
  library.
- **`RateLimit`'s fail-open-on-I/O-error design.** `RateLimit::take()`
  (`RateLimit.php:60-65`) explicitly returns `true` (allow) if the state
  file can't be opened ("we err open rather than locking everyone out",
  comment at line 62-63). This is a defensible availability trade-off, but
  it means a disk-full/permissions/ENOSPC condition on the rate-limit
  state file silently *disables* rate limiting entirely rather than
  failing closed — worth flagging since it's the opposite of typical
  security-control fail-safe posture (most rate limiters/WAFs fail closed
  under storage errors). Should at least be logged (currently nothing
  writes to stderr/Logger when `fopen()` fails).
- **`Auth::fingerprint()` accepts unauthenticated env vars at face value.**
  (`Auth.php:72-91`). `SSH_USER_KEY_FINGERPRINT`/`KEY_FINGERPRINT`/
  `SSH_USER_AUTH` are trusted verbatim from `$_SERVER`/`getenv()` with no
  cross-check against anything cryptographic in this process — their
  trustworthiness depends entirely on sshd populating them correctly
  (which it does, if `ExposeAuthInfo` is on and the deployment is a true
  `ForceCommand` fork of sshd itself). If candy-wish were ever run behind
  a different front-end (a proxy, a non-OpenSSH SSH daemon, or a
  test/dev harness that fakes these env vars), the fingerprint check would
  trivially accept forged values — there is no guard rail or doc-comment
  warning that these env vars are trusted implicitly and must never be
  settable by the connecting client. (Contrast: `CertificateAuth` at least
  passes the raw PEM through a caller-supplied `validate` callback rather
  than doing its own binary allow/deny — `Auth`'s fingerprint path does the
  comparison itself with no validator hook.)
- **Sanitization for log/stderr injection is present and good.**
  `Auth::sanitize()` (`Auth.php:104-113`) strips C0/C1 controls and ANSI
  CSI sequences from username/fingerprint before writing to stderr —
  correctly mitigates terminal-injection/log-spoofing from attacker-
  controlled identity strings. `PasswordAuth`/`CertificateAuth`/
  `KeyboardInteractive` do **not** apply the same sanitization to any
  values they might echo (though today none of them echo user-controlled
  values to stderr other than the fixed "Permission denied."/"Certificate
  rejected." strings, so this is latent rather than exploitable now — flag
  for future middleware author).
- **`RateLimit::writeBack()` temp-file naming uses `random_bytes(8)`
  in the same directory as the state file** (`RateLimit.php:108`) — fine
  entropy-wise, but the temp file is created with default `file_put_contents`
  permissions (whatever the process umask yields, no explicit `chmod`), and
  the directory itself needs to be writable by whatever uid candy-wish
  processes run as; if the state directory is shared across multiple
  distinct services/tenants this could allow a symlink-race style issue on
  a permissive directory — low likelihood, but no explicit permission
  hardening (e.g. `0600`) is applied to the state file. Not urgent, noted
  for completeness.
- **Host-sshd (legacy) opt-in transport does not itself add privilege risk
  beyond what InProcessTransport already carries.** `HostSshdTransport`
  (`src/Transport/HostSshdTransport.php`) just runs the middleware chain
  inline against sshd's inherited stdio — no PTY allocation, no subprocess
  spawn, no new privileged syscalls (no `posix_kill`, no `ioctl`, no PTY
  master/slave). If anything it has a *smaller* attack surface than the
  default `InProcessTransport` (which does `posix_kill()` on the spawned
  child's PID and issues `SIGHUP`/`SIGKILL`, `src/Transport/
  InProcessTransport.php:325-338`). The opt-in "legacy" label refers to
  architecture vintage, not privilege level — there is no privilege
  escalation vector specific to choosing `HostSshdTransport` over
  `InProcessTransport`. Both run as whatever uid `sshd`'s `ForceCommand`
  invokes the PHP process as (typically the authenticated user, per normal
  sshd behavior) — candy-wish itself never does its own privilege
  transition (no `setuid`/`seteuid` calls anywhere in `src/`).

### 6. Duplication with candy-serve / candy-pty

- **candy-serve confirmed to duplicate SSH-session-handling concerns
  covered by candy-wish.** `candy-serve/src/SSH/SSHServer.php` implements
  its own `handleConnection()` entry point that re-derives username/command
  handling, does its own public-key verification
  (`SSHServer::authenticate()`, lines 152-169) trusting a `$presentedKey`
  parameter "when the transport hands us the peer's key," and falls back to
  "trust the transport (e.g. sshd ForceCommand where libssh2 already
  verified the key)" — i.e. it re-implements almost exactly the same
  trust-boundary logic candy-wish's `Auth`/`AuthMethods` middleware already
  provide as reusable, tested components, but candy-serve does not depend
  on or call into candy-wish anywhere (`candy-serve/composer.json` was not
  checked for a `sugarcraft/candy-wish` require — recommend the sibling
  audit's suggestion that candy-serve delegate session/auth plumbing to
  candy-wish's `Server`/`Auth`/`Session` primitives rather than
  re-implementing a parallel one-off SSHServer). This is candy-serve's gap
  to fix, not candy-wish's — candy-wish's implementation is not itself
  duplicative of candy-serve; candy-serve is duplicative of candy-wish.
- **candy-pty delegation confirmed clean — no duplication.**
  `InProcessTransport` (`src/Transport/InProcessTransport.php`) is a thin
  consumer of candy-pty's public contracts: `Contract\MasterPty`,
  `Contract\PtySystem`, `Libc`, `Posix\PosixPump`, `PtyException`,
  `PtySystemFactory`, `PumpOptions`, `SignalForwarder`, `SizeIoctl`
  (imports at `InProcessTransport.php:7-15`). It calls
  `SignalForwarder::pcntlReady()`/`attachSigwinch()`/`reset()` for SIGWINCH
  forwarding, `PosixPump::run()` for the byte-pump loop, and
  `PtySystemFactory::default()` to obtain the PTY backend — it does not
  reimplement any pump/signal-forwarding/PTY-allocation logic itself. The
  only signal-handling code that lives directly in candy-wish is the
  child-teardown `posix_kill(SIGHUP)`→grace→`posix_kill(SIGKILL)` sequence
  in `runChild()`'s `finally` block (`InProcessTransport.php:319-339`),
  which is session*lifecycle* logic (deciding *when* to kill the child on
  disconnect), not PTY/signal-plumbing — a legitimate, non-duplicative
  responsibility for the consumer of `candy-pty`'s primitives to own.

### 7. Documentation gaps

- **`examples/hello-server.php` is broken against the current `Middleware`
  interface — a real bug, not just a doc nit.** The `Middleware` interface
  (`src/Middleware.php:37`) requires
  `handle(Context $ctx, Session $session, callable $next)`. The `Banner`
  class in `examples/hello-server.php:26-42` declares
  `public function handle(Session $s, callable $next): void` — missing the
  `Context $ctx` parameter entirely, and its `$next($s)` call
  (`hello-server.php:41`) only passes one argument. This is a stale
  leftover from before the Context-propagation migration (see
  `CALIBER_LEARNINGS.md` `[pattern:context-propagation]` entry warning
  exactly about this: "update ALL anonymous middleware classes in tests AND
  all Transport::run() implementations simultaneously" — this example was
  evidently missed). Running this example as written today will fatal:
  PHP enforces `LSB`/interface-compatibility on method signatures, and a
  class declaring `handle(Session, callable)` does not satisfy
  `Middleware::handle(Context, Session, callable)`. This is the single
  concrete actionable bug found in this audit — recommend fixing
  `examples/hello-server.php` to match the current interface and adding
  the missing `.vhs/hello-server.tape` (see §4) so a VHS re-render would
  catch this class of regression in future.
- README's middleware table documents `AuthMethods` as writing an
  `SSH_AUTH_METHODS` banner and storing the method list in Context
  (`README.md:139`, `AuthMethods.php:66-74`), but nothing in the codebase
  actually *reads* `AuthMethods::fromContext()` downstream — it's
  documented as part of a multi-round-trip SSH auth negotiation pattern
  that, per §0, this library's architecture (sshd already completed auth
  before this code runs) cannot actually implement. The middleware's
  doc-comment (`AuthMethods.php:12-33`) describes RFC 4252 multi-round-trip
  semantics that don't apply post-ForceCommand — worth clarifying in the
  README that `AuthMethods` is informational/banner-only in this
  architecture, not a real SSH auth-method negotiation.
- The "ext-ssh2" section (`README.md:280-282`) says the extension is "used
  only if you want a middleware that opens outbound SSH connections... e.g.
  SFTP file pickers" but no such outbound-SSH middleware exists anywhere in
  `src/` — the composer.json `suggest` entry
  (`composer.json:40-42`: "Enables the Ssh2 client middleware...") likewise
  references a class that doesn't exist in this codebase. Either dead
  documentation for a removed/never-built feature, or a forward-reference
  to unbuilt work that should be labeled "planned" rather than described
  as available.

---

## candy-zone

### 6) candy-mouse duplication — CONFIRMED, and worse than "overlap": live cross-contamination in shared consumers

Both libraries are independent, complete ports of the same upstream `lrstanley/bubblezone`, and both are officially tracked as separate 🟢 (feature-complete) rows for the *same* upstream in `docs/MATCHUPS.md:32-33`:

```
32:| lrstanley/bubblezone | CandyMouse | candy-mouse/ | sugarcraft/candy-mouse | SugarCraft\Mouse | 🟢 | Self-contained Mark/Scan/Get mouse hit-testing + ZoneClickTracker (bubblezone issue #10 fix) |
33:| lrstanley/bubblezone | CandyZone  | candy-zone/  | sugarcraft/candy-zone  | SugarCraft\Zone  | 🟢 | Mouse-zone tracker |
```

**Non-interoperable schemes, confirmed file:line:**

- candy-zone marker format — APC escape sequences, terminal-safe but visible in raw byte streams: `candy-zone/src/Manager.php:17-30` (`ESC _ "candyzone:S:<id>" ESC \` / `candyzone:E:<id>`). Scan loop at `candy-zone/src/Manager.php:127-243`.
- candy-mouse marker format — private-use Unicode codepoints U+E000/U+E001: `candy-mouse/src/Mark.php:13-26` and `candy-mouse/src/Sentinel.php` (referenced), parsed by `candy-mouse/src/Scan.php:26-113` (raw byte pattern `\xEE\x80\x80` / `\xEE\x80\x81`).
- Both define their own `Zone` value object with the *same* method names (`inBounds`, `pos`, `width`, `height`, `isZero`) but as **distinct, non-substitutable classes**: `candy-zone/src/Zone.php:16-74` (`SugarCraft\Zone\Zone`, hit-tests against `SugarCraft\Core\Msg\MouseMsg`) vs `candy-mouse/src/Zone.php:22-85` (`SugarCraft\Mouse\Zone`, hit-tests against `SugarCraft\Mouse\MouseEvent`). A zone scanned by one manager cannot be passed to the other's API — passing a `Mouse\Zone` where `Zone\Zone` is expected (or vice versa) is a type error, not a silent bug, but the near-identical surface invites exactly that mistake.
- candy-zone requires an externally-shared `Manager` (`candy-zone/src/Manager.php:25-42`, `Zones` facade `candy-zone/src/Zones.php`); candy-mouse is explicitly designed to avoid that: `candy-mouse/README.md:9-11` — "Replaces the model where consumers wire candy-zone's Manager externally... no shared global state." The two libraries' READMEs describe themselves as alternative solutions to the same problem, one explicitly positioned against the other.
- candy-zone's own click/hover/drag trackers (`ClickCounter`, `ZoneHoverTracker`, `DragTracker` — all in `candy-zone/src/`) duplicate candy-mouse's `ZoneClickTracker` (`candy-mouse/src/ZoneClickTracker.php:26-106`), which itself cites "bubblezone issue #10" as its rationale — the same rationale candy-zone's README does not mention, suggesting the two were built independently without cross-referencing each other's click-dedup logic.

**Concrete evidence this is not just theoretical — real files import BOTH incompatible schemes side by side:**

- `sugar-crumbs/src/Breadcrumb.php:8-10` — `use SugarCraft\Mouse\Mark; use SugarCraft\Mouse\Scanner; use SugarCraft\Zone\Manager;` and line 146 returns `?\SugarCraft\Mouse\Zone` from a method called `hit()`. This file, in the same class, wires up a candy-mouse `Scanner` AND a candy-zone `Manager`.
- `sugar-veil/src/Veil.php:11-20` — identical pattern: `use SugarCraft\Mouse\Mark; use SugarCraft\Mouse\Scanner; use SugarCraft\Mouse\Zone; ... use SugarCraft\Zone\Manager;`.
- `sugar-bits/composer.json` and `sugar-crumbs/composer.json` and `sugar-veil/composer.json` all `require` both `sugarcraft/candy-zone` AND `sugarcraft/candy-mouse` simultaneously (confirmed via `grep -rl "sugarcraft/candy-zone" */composer.json` and the equivalent for candy-mouse — both greps return `sugar-veil`, `sugar-bits`, `sugar-crumbs`).
- Other consumers picked one or the other exclusively: `sugar-gallery/src/PosterGrid.php` (candy-zone only), `candy-mines/src/Renderer.php` and `candy-tetris` (candy-mouse only) — so the ecosystem has already split into two camps of downstream libs, each locked into a different, non-portable marker/hit-test scheme.

**Recommendation (concrete, not hand-wavy):** candy-mouse is the better long-term owner of the "mark + scan + hit-test" primitive — it has no global-state footprint, a documented O(n) linear-scan caveat with concrete alternatives (`candy-mouse/src/Scanner.php:81-97`), a private-use-codepoint marker format that is strictly safer around raw-byte log dumps / copy-paste than visible-looking `\x1b_candyzone:...` APC payloads, and it already fixes the documented upstream issue (#10) that candy-zone's own `ClickCounter` re-solves independently. candy-zone's differentiator is the TEA-idiomatic `Manager`/`Zones` façade wired to `SugarCraft\Core\Model`/`MouseMsg` and its hover/drag trackers (`ZoneHoverTracker`, `DragTracker`) which candy-mouse has no equivalent for. Two honest paths: (a) merge candy-zone's `Manager`/`Zones`/hover/drag layer to sit *on top of* candy-mouse's `Mark`/`Scan`/`Zone` primitives (deprecate candy-zone's own marker format and `ClickCounter`, delegate to candy-mouse under the hood, re-export `Zone` as a thin wrapper for BC), or (b) formally split scope in MATCHUPS.md/README so candy-mouse owns "local, component-scoped hit-testing with no shared registry" and candy-zone owns "TEA Program-wide zone registry with hover/drag/multi-click semantics," and audit `sugar-crumbs`/`sugar-veil`/`sugar-bits` to pick exactly one per logical hit-test target instead of wiring both into the same class. Doing nothing leaves 3 shared consumers silently maintaining two parallel, incompatible mouse-hit-test code paths.

### 1) Missing/incomplete functionality vs upstream bubblezone scope

- No `ScanIterator`/streaming scan — acknowledged as a gap in the *sibling* lib (`candy-mouse/src/Scan.php:20-24` design note), but candy-zone's `Manager::scan()` (`candy-zone/src/Manager.php:127-243`) has the identical single-shot, full-buffer-in-memory limitation with no equivalent doc callout.
- `Manager::setMotionTracking()` (`candy-zone/src/Manager.php:95-100`) only returns the CSI 1003 h/l string — there is no integration point wiring this into `candy-core`'s input/terminal-mode layer, so callers must remember to both call this and separately write it to the TTY (README does note this at `candy-zone/README.md:197-204`, but it's easy to forget in a real Program).
- No spatial index / grid-bucketing of zones (see performance section) — upstream bubblezone is also O(n) so this isn't a *regression*, but neither PHP port improved on it despite candy-mouse's own README explicitly discussing R-tree/grid-bucket alternatives without implementing them.

### 2) Performance concerns

- `Manager::anyInBounds()` (`candy-zone/src/Manager.php:294-311`) is an unconditional **O(n) linear scan over every recorded zone on every mouse event**, computing `width() * height()` per zone to find the smallest-area hit. For a Program with dozens-to-hundreds of interactive zones (tables, lists, grids) and 1003-mode motion reporting enabled (`setMotionTracking(true)` fires on every pixel of movement), this scan runs on every mouse-move tick, not just clicks — motion tracking is exactly the scenario where this scales worst, and it's the one feature this class explicitly exposes.
- Unlike the sibling lib, this cost is **not documented** anywhere in candy-zone (compare to `candy-mouse/src/Scanner.php:85-97`, which explicitly flags "adequate for n < 100 zones" and lists three mitigation strategies). `candy-zone/src/Manager.php` and `candy-zone/README.md` have no equivalent caveat, so a downstream author has no signal that motion-tracked, zone-heavy UIs (e.g. `sugar-gallery/src/PosterGrid.php`, a virtualized poster grid that explicitly integrates candy-zone per `docs/MATCHUPS.md:51`) could see per-frame cost grow linearly with zone count.
- `ZoneHoverTracker::update()` and `DragTracker::update()` (`candy-zone/src/ZoneHoverTracker.php:76-111`, `candy-zone/src/DragTracker.php:105-159`) each independently call `$manager->anyInBounds($msg)` — a Program composing both trackers over the same manager (a plausible real use case: hover highlight + drag) pays the O(n) scan twice per event with no shared/cached hit-test result for that tick.
- `scan()` itself (`candy-zone/src/Manager.php:127-243`) walks every byte of the rendered frame doing grapheme-cluster extraction (`grapheme_extract` when available, else manual UTF-8 decode) for every visible character — standard cost for this class of parser, on par with candy-mouse's `Scan::parse()`, not a regression, but combined with the O(n) hit-test above there's no total complexity budget documented anywhere for a "typical" Program frame size.

### 3) Test coverage gaps

Coverage is otherwise strong (69 test methods enumerated across `ZoneTest`, `ZonesTest`, `ManagerTest`, `ManagerMotionTrackingTest`, `ZoneHoverTrackerTest`, `DragTrackerTest`, `ClickCounterTest`) — the CALIBER_LEARNINGS entry on `ClickCounter`'s hybrid `mutate()` (see below) indicates this area was already scrutinized. Gaps found:

- No test exercises `Manager::close()` interacting with `ZoneHoverTracker`/`DragTracker`/`ClickCounter` — all three trackers call `$this->manager->get($id)`/`anyInBounds($msg)` and silently return "no transition" (`candy-zone/src/ZoneHoverTracker.php:80-88`, `candy-zone/src/DragTracker.php:109-121`) when the manager has been closed mid-drag/mid-hover, but there's no `*Test.php` covering "manager closes while a drag/hover/click-streak is in progress."
- No test for `Manager::scan()` receiving **overlapping but not nested** zones (partial rectangle overlap, neither containing the other) — `ManagerTest.php` has `testAnyInBoundsPrefersInnermostZone` (nested case) and `testAnyInBoundsTieBreaksToLastInserted` (equal area), but the union-rectangle bug class most likely to bite real layouts — two zones whose bounding boxes partially overlap without one containing the other — isn't exercised.
- No test covering `Manager::mark()`/`scan()` with an `$id` containing the marker delimiter substrings themselves (e.g. an id literally containing `"E:"` or the APC terminator `ESC \`) — the parser in `Manager.php:127-243` does substring search (`strpos($rendered, self::APC_ST, ...)`, `str_starts_with($payload, self::TAG_START)`) with no escaping of the id, so a caller-supplied id containing adversarial substrings is untested (see security note below).
- `DragTrackerTest.php` covers move-across-zones and release, but no test drives a Scroll action through `DragTracker::update()` — the method only branches on Release/Press/Motion (`candy-zone/src/DragTracker.php:105-159`) and falls through to `return [$this, null]` for anything else, which is probably correct but isn't asserted.
- `ClickCounterTest.php` doesn't test what happens when the underlying `Manager`'s zones are cleared/rescanned between the second and third click of a streak (a realistic re-render scenario) — only zone-change-by-different-hit is tested (`testZoneChangeResetsStreak`), not zone-disappearance.

### 4) Missing .vhs demos

None — `.vhs/buttons.tape`, `.vhs/list-default.tape`, `.vhs/nested.tape` all exist with matching rendered `.gif`s (`candy-zone/.vhs/*.tape` + `*.gif`, timestamps Jul 4 14:44), and three corresponding `examples/*.php` scripts (`buttons.php`, `list-default.php`, `nested-components.php`) back them. No gap here.

### 5) Security concerns

- **Unescaped, caller-controlled id embedded directly in the marker payload with substring-based parsing.** `Manager::mark()` (`candy-zone/src/Manager.php:109-118`) concatenates `$this->idPrefix . $id` directly between `TAG_START`/`TAG_END` constants and APC delimiters with no validation that `$id` doesn't itself contain `"\x1b\\"` (the APC terminator) or the tag prefixes `candyzone:S:`/`candyzone:E:`. An id containing an embedded terminator sequence could prematurely close the APC marker and inject attacker-controlled bytes into what `scan()` treats as terminal-passthrough content — low severity since ids are typically hardcoded by the app author, not untrusted input, but worth a validation guard or doc-comment caveat given `mark()` takes a raw `string $id` with no format constraint. candy-mouse's `Mark::wrap()` (`candy-mouse/src/Mark.php:66-80`) has the identical unescaped-id concatenation, so this is a shared, unaddressed risk in both libraries, not unique to candy-zone.
- `Manager::scan()`'s CSI/OSC passthrough branches (`candy-zone/src/Manager.php:190-216`) forward arbitrary escape sequences from `$rendered` into the "clean" output untouched and un-validated — this mirrors upstream terminal-passthrough behavior (necessary for styling to survive), but means any malicious OSC payload embedded in unsanitized upstream data (e.g. a DB-sourced string rendered without escaping — flagged as a general TUI-render invariant in project history) would flow straight through `scan()` into the terminal. This is an inherent property of the marker design, not a candy-zone-specific bug, but there's no doc-comment warning callers that `scan()` performs zero content sanitization.

### 7) Documentation gaps

- `candy-zone/README.md` documents `Manager`, `Zones`, `ZoneHoverTracker`, `DragTracker`, `ClickCounter`, and `setMotionTracking` thoroughly (`candy-zone/README.md:1-262`), but **never mentions candy-mouse** — a reader evaluating "which library do I use for mouse hit-testing in SugarCraft" has no signal that a second, incompatible implementation exists, despite three real consumers (`sugar-bits`, `sugar-crumbs`, `sugar-veil`) already depending on both. This is the single highest-value doc fix: a "See also / How this differs from candy-mouse" section.
- No performance/complexity caveat on `anyInBounds()` (see performance section) — candy-mouse's README/`Scanner.php` sets a precedent (`candy-mouse/src/Scanner.php:85-97`) that candy-zone should mirror.
- `CALIBER_LEARNINGS.md` (`candy-zone/CALIBER_LEARNINGS.md:1-11`) documents `ClickCounter`'s hybrid `mutate()` pattern but has no entry at all about the candy-mouse overlap — despite this being (per the prior sibling audit and this one) the single most consequential architectural fact about this library. Given CALIBER_LEARNINGS is described as "auto-extracted from real tool usage," the overlap has apparently never surfaced during a session that touched both libs together, which itself suggests the two were developed in isolation without cross-referencing.
- README's "Tips" section (`candy-zone/README.md:206-225`) documents several sharp edges (unterminated APC markers, organic-shape bounding boxes) well, but omits the id-collision/injection risk noted under Security above.

---

## docs/ website

Scope: `/home/sites/sugarcraft/docs/` (index.html, `lib/*.html`, `css/`, `js/`, `img/icons/`) cross-referenced against the 58 lib subdirs at repo root and `media/icons/`.

### 1. Missing per-lib doc pages

None. `ls -d */` at repo root (minus `tools/vendor/scripts/steps/media/docs/findings/home4`) yields exactly 58 lib dirs, and `docs/lib/*.html` has exactly 58 matching `<slug>.html` files with a 1:1 name match (`comm -23`/`comm -13` diff is empty both ways). Every lib subdir has a doc page and there are no orphan pages for nonexistent libs.

Note: `docs/lib/` also contains a stray `honey-bounce.md` alongside `honey-bounce.html` — leftover/duplicate source file, harmless but should probably be removed for tidiness.

### 2. Missing icons

`media/icons/` has 55 `*.png` files but 58 lib dirs exist. Five libs have **no icon file at all**:

- `candy-focus`
- `candy-forms`
- `candy-pty`
- `sugar-dash`
- `sugar-gallery`

`docs/index.html` already anticipates this: the `<img>` tags for these five (lines 115, 138, 139, 140, and the `candy-forms`/`candy-pty` demo cards around lines 413–450, 515–522) carry `onerror="this.style.display='none'"` — a defensive client-side fallback rather than a fix. So the site doesn't visibly break, but these five tiles render with no icon.

Two extra files live under `media/icons/` that aren't libs: `sugarcraft.png` and `sugarcraft.github.io.png` (site/org-level icons, not per-lib — expected, not a bug).

**Separate, more serious problem — `docs/img/icons/` is a stale, out-of-sync copy of `media/icons/`, not a symlink.** `docs/img/icons` is a real directory (`ls -la` shows regular dir, `readlink -f` returns itself). Diffing basenames between `docs/img/icons/*.png` and `media/icons/*.png`:
- `docs/img/icons/` is **missing** `candy-mosaic.png` (present in `media/icons/`, real 23KB artwork) — so `docs/index.html`'s references to `img/icons/candy-mosaic.png` (lines 130, 360) are pointing at a file that doesn't exist in the copy actually served.
- `docs/img/icons/` has an **extra** `sugar-dash.png` (827 bytes) that isn't in `media/icons/sugar-dash.png` at all (media/icons has no sugar-dash icon, per item above) — i.e. someone added an icon directly under `docs/img/icons/` without ever adding the canonical source file under `media/icons/`, so future syncs from `media/icons/` would silently delete it.

This means `media/icons/` and `docs/img/icons/` have drifted and there is no automated sync step evident in the repo (no build script found generating `docs/img/icons/` from `media/icons/`) — a real maintenance risk given the checklist in AGENTS.md only mentions `media/icons/<slug>.png`, not the docs copy.

**Placeholder/stub icons**: 8 of the 55 `media/icons/*.png` files are literal 1×1 pixel PNGs (67 bytes each), i.e. unfinished stand-ins rather than real artwork:
`candy-ansi.png`, `candy-async.png`, `candy-buffer.png`, `candy-fuzzy.png`, `candy-input.png`, `candy-layout.png`, `candy-mouse.png`, `candy-testing.png`.
Combined with the 5 fully-missing icons above, **13 of 58 libs (22%) have no real icon** on the public site.

### 3. Stale/broken content

- **Stale package count in meta tags**: `docs/index.html` line 7 (`<meta name="description">`), line 9 (`og:description`), line 24 (JSON-LD `description`), and the visible copy at line 69/181/635 ("Thirty-three libraries, one ecosystem" / "Twenty-three apps built on the stack") all say **"33 libraries and 23 apps (56 packages)"**. The actual repo root now has **58** lib subdirectories — the copy is stale by at least 2 packages and the 33/23 split may no longer match either (would need a MATCHUPS.md category re-count to split libs vs apps precisely, but 33+23=56 ≠ 58 regardless).
- **Branch-name inconsistency in lib pages**: every one of the 58 `docs/lib/*.html` pages links "Full README" to `https://github.com/sugarcraft/<slug>/blob/main/README.md` (e.g. `docs/lib/candy-core.html:104`) while other links on the very same page use `master` (e.g. line 139: `github.com/detain/sugarcraft/blob/master/CONTRIBUTING.md`). Per CONTRIBUTING.md's bootstrap flow, the per-lib `sugarcraft/<slug>` org repos are meant to have their default branch flipped from `main` to `master` (`scripts/set-org-default-master.sh`). If that step has run for a given repo, the `main` branch may no longer exist and every "Full README" link across all 58 pages 404s. This is a single find-and-replace bug (`blob/main/` → `blob/master/`) repeated identically 58 times.
- **Stale TODO comment**: `docs/index.html:367` — `<!-- TODO(candy-mouse): add .vhs/ GIF + img/icons/candy-mouse.png when examples are added -->`. `candy-mouse.png` already exists (as a 1×1 placeholder, see §2), so the TODO is partially stale — the icon file exists, it's just a stub; the comment should be reworded to say "replace placeholder icon," not "add" it.
- **Status badges (🔴🟡🟢🚀) are not reflected on the public site at all.** They only appear in `docs/MATCHUPS.md` (63 occurrences). `docs/index.html` and `docs/lib/*.html` carry no per-lib maturity indicator, so a visitor can't tell a 🟢 v1-ready lib from a 🔴 planning-only entry without leaving the site to read MATCHUPS.md — this is really a UX gap (see §4) as much as a staleness risk, but it also means the site can never go stale on this axis since it doesn't attempt to track it.

### 4. Site structure/UX gaps

- **Search exists and is reused consistently.** Both `docs/index.html` and every `docs/lib/*.html` page include the same `#search-modal` markup and `js/search.js` (deferred, self-hosted) — a real search/filter mechanism for the 58-library catalog, wired identically on both index and lib pages.
- **Nav is consistent.** `<nav class="nav" aria-label="Main navigation">` appears on both index and lib pages with the same structure; lib pages use relative `../` paths for shared assets (`../css/search.css`, `../js/search.js`), correctly rooted.
- **No breadcrumb** on lib pages back to the library grid section (only the top nav + a GitHub link/button) — a "back to index #libraries" trail is a minor gap for a page one level deep.
- **Responsive/mobile**: `viewport` meta tag is present; `css/style.css` has 3 `@media` blocks and `css/search.css` has 1 — real responsive breakpoints exist, not just a meta tag with no follow-through. Did not verify visually.
- **Hand-maintained HTML, no static-site generator.** No Jekyll/Hugo/Eleventy config, no generator `<meta>` tag, no build step evident for `docs/`. All 58 `lib/*.html` pages (11,085 total lines across `docs/lib/*.html`) are hand-written/hand-edited HTML with heavy duplication (identical nav, identical search modal markup, identical head boilerplate repeated in every file). This is the same maintenance burden already implied by the AGENTS.md checklist ("Adding a lib" touches `docs/index.html` + `docs/lib/<slug>.html` by hand) and is the direct cause of the `blob/main/` bug in §3 — a templated build would fix a branch-name typo in one place instead of 58.

### 5. SEO/accessibility basics

- Meta tags are solid: charset, viewport, description, canonical (`index.html:15`), full Open Graph + Twitter Card block, and a JSON-LD block (`index.html:19`). `<html lang="en">` present on both index and lib pages.
- Icons use `alt="" aria-hidden="true"` — correct pattern for purely decorative icons sitting next to visible text/titles, not a violation, but it does mean there's no descriptive text fallback if the icon is the *only* visual cue in a context (checked; icons are always paired with a `title=` attribute on the enclosing `<a>`, so this is acceptable).
- Semantic structure is good: `<main id="main">`, sectioned `<section>` blocks with `<h1>`/`<h2>` hierarchy, `role="dialog"`/`aria-modal`/`role="listbox"` correctly used on the search modal.
- One accessibility nit: several `<section>` tags carry inline `style="background: var(--card); border-block: 1px solid var(--line);"` (e.g. `index.html:179`, `876`, `1136`) — not an a11y bug, but inline styling duplicated across sections is a minor maintainability smell consistent with §4's hand-maintenance concern.

### 6. Missing cross-links

- **GitHub**: every lib page links to `https://github.com/sugarcraft/<slug>` (repo) and `.../issues` — present and correct (e.g. `docs/lib/candy-core.html:103,136,140`).
- **Packagist**: present per-lib, e.g. `docs/lib/candy-core.html:137` → `https://packagist.org/packages/sugarcraft/candy-core`.
- **Codecov: missing entirely from lib pages.** `grep -c codecov docs/lib/*.html` returns 0 across all 58 pages, even though every lib's own `README.md` carries a per-flag Codecov badge (e.g. `candy-core/README.md:7`: `codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-core`). The public doc site never surfaces test-coverage status even though the data/badge already exists per-lib — a straightforward addition (reuse the exact README badge URL) that's currently absent from every one of the 58 pages.
- **"Full README" links use the wrong branch** (`blob/main/` vs actual `master`) — see §3; functionally this breaks the cross-link even though the link is present.

### 7. Security concerns

No CDN script/stylesheet includes and no inline `<script>` blocks found in `index.html` or the sampled lib page — `grep` for `cdn.`, `googleapis`, `jsdelivr`, `unpkg` and `<script>` (inline) all returned zero hits. All JS/CSS is self-hosted (`css/style.css`, `css/search.css`, `js/main.js`, `js/search.js`, loaded with `defer`), so there's no SRI concern because there's no third-party script to begin with — that's actually the safer posture (nothing to add SRI to). The only external resources referenced are `<img>` tags pointing at `raw.githubusercontent.com/.../demo.gif` (e.g. `index.html:415,427,517`) for VHS demo GIFs — plain `https://` image loads with `onerror` fallback, not a security issue, just an availability dependency on GitHub's raw CDN staying up. No mixed-content risk observed (all external refs are `https://`).

---

## honey-bounce

### 1. Missing/incomplete functionality vs upstream harmonica

Upstream `charmbracelet/harmonica` scope is small: `Spring`/`NewSpring`, `Spring.Update`, `FPS`, `Projectile`/`NewProjectile`, `Vector`, `Point`, package-level `Gravity`/`TerminalGravity`. All of this is present and mirrored (`src/Spring.php`, `src/Projectile.php`, `src/Vector.php`, `src/Point.php`, `src/Gravity.php`). Upstream harmonica has **no** `SpringConfig` (tension/friction/mass), no named presets, no `SpringChain`/`SpringCollection`, and no angular-spring variant at all — those don't exist upstream. honey-bounce actually *exceeds* upstream scope with `src/SpringConfig.php`, `src/SpringPreset.php` (`SpringPreset.php:17-58`), `src/SpringChain.php`, `src/SpringCollection.php`, and a whole `Easing`/`CubicBezier` module not present upstream at all. No functional gap found against upstream; if anything the "angular spring variant" mentioned in the audit brief doesn't exist in harmonica either, so there's nothing to port.

### 2. Performance concerns

- `Spring::update()` (`src/Spring.php:120-130`) is allocation-light — precomputed coefficients in the constructor, per-call work is 2 multiplications + a 2-element array return. No issue.
- `SpringChain::tick()` (`src/SpringChain.php:68-94`) rebuilds the entire `$stages` list every tick (`foreach` copy at lines 84-91) even though only one element changes — O(n) allocation per tick where n = stage count. For long chains driven at 60fps this is avoidable churn; PHP array COW mitigates but the explicit foreach-copy is unnecessary — could just replace the one index.
- `SpringCollection` (`src/SpringCollection.php`) breaks the repo's own immutable-with pattern: `add()` (line 30), `remove()` (line 41), and `setTarget()` (line 135) mutate `$this->springs/positions/velocities/targets` directly (`void` returns), while `tick()` (line 59) returns a new instance via `withState()`. This is an inconsistent API in the same class — callers can't treat it as immutable, and it diverges from the `mutate()`-helper convention documented in AGENTS.md/`.claude/rules/model-pattern.md` (canonical `candy-sprinkles/src/Style.php`). Not a performance bug per se, but the mutable/immutable split invites accidental shared-state bugs when a `SpringCollection` is passed by reference and diverges from other Bounce types (`SpringChain`, `SpringConfig`, `Vector`, `Point`) which are all fully immutable.

### 3. Test coverage gaps

- `SpringConfig::__construct` throws `\InvalidArgumentException` when `$mass <= 0.0` (`src/SpringConfig.php:33-37`), but no test in `tests/SpringConfigTest.php` exercises this path (zero mass, negative mass) — the one `expectException` in the whole suite is `SpringTest.php:22` for `Spring::fps(0)`. This is the exact "zero mass" edge case called out in the audit and it is currently silently untested.
- The three-way branch selection in `Spring::__construct` (`src/Spring.php:59-110`) is tested at representative ζ values (0.2 under, 1.0 critical, 2.0 over — `tests/SpringTest.php`) but not at the `EPSILON` boundaries themselves (`ζ = 1.0 ± 1e-9`, the exact edge where the branch selection flips between the critically-damped closed form and the under/over-damped ones). A boundary-straddling test would catch discontinuities if the coefficient formulas ever drift apart at the seam.
- No test drives `Spring` with `angularFrequency` below `EPSILON` combined with nonzero `dampingRatio` other than the one identity case (`testZeroFrequencyIsIdentity`, ζ=1.0) — worth one more with ζ=0 and ζ>1 to confirm the identity branch is truly independent of damping ratio.
- `SpringChain`/`SpringCollection` tests don't cover a zero-mass or zero-frequency spring driving a stage/collection member (i.e., composing the untested edge cases above through the higher-level types).

### 4. Missing .vhs/*.tape demos

- `.vhs/spring.gif` exists but **has no corresponding `spring.tape`** (`ls .vhs/` shows only `projectile.gif`, `projectile.tape`, `spring.gif`) — the gif is an orphan that CI's `vhs.yml` cannot regenerate; it was presumably committed from a tape that was later deleted or never checked in.
- `examples/particle.php` exists (a full particle-swarm demo per its docblock) but has **no `.vhs/particle.tape`** and no corresponding gif — README's "Demos" section only shows `projectile` and `spring` (`README.md:316-324`), so the particle example is undocumented visually despite being a first-class example file.

### 5. Security concerns

None apply — pure numeric/math library (floats in, floats out), no I/O, no user input parsing, no filesystem/network/shell access. `Spring::update()`'s only external dependency is `SugarCraft\Palette\Probe::reducedMotion()` reading environment variables, which is read-only and non-executable.

### 6. Duplication with candy-flip / other animation-adjacent libs

`candy-flip/src` (`Decoder.php`, `Frame.php`, `Lang.php`, `Player.php`, `Renderer.php`, `TickMsg.php`) contains no references to `Spring`, `Easing`, or `CubicBezier` — it's a GIF/frame player, not a physics/easing engine, so no functional overlap or duplicated math was found. No other lib in the monorepo appears to reimplement spring/easing math (a grep for `Spring`/`Easing`/`CubicBezier` usage outside honey-bounce came back empty for candy-flip). No duplication found.

### 7. Documentation gaps

- README's "Public API" section (`README.md:269-303`) doesn't mention `SpringConfig`'s exception behavior for `mass <= 0`, nor `SpringChain`'s/`SpringCollection`'s settling threshold constants (`SpringChain::SETTLING_THRESHOLD = 0.0005` at `src/SpringChain.php:17` vs. `Spring::SETTLING_THRESHOLD = 1e-4` at `src/Spring.php:25` — two different "settled" thresholds exist in the same library and neither is cross-referenced in prose; a reader tuning one may not realize the other exists).
- `Spring::SETTLING_THRESHOLD` (`src/Spring.php:25`) is declared `public const` but nothing in `Spring` itself appears to consume it (only `SpringChain` has its own private, differently-valued threshold) — dead/unused constant, or at minimum undocumented as to which type should use it.
- The "Demos" section doesn't mention `examples/particle.php` at all (see §4), so a reader has no pointer to the particle-swarm demo despite it being feature-complete.
- CALIBER_LEARNINGS.md documents `SpringCollection` implicitly via the general patterns list but never calls out that it is the one non-immutable type in the library (see §2) — worth a note so future contributors don't assume the "every with*() returns new instance" project convention holds for it.

---

## honey-flap

Flappy-Bird-style TUI game, port of `kbrgl/flapioca`, built on a `SugarCraft\Core\Model` (TEA) loop. Bird vertical motion delegates to a `HoneyBounce\Projectile` (gravity + upward-kick flap). Pipes are single-column obstacles with a scaling gap. Includes high-score persistence to `$XDG_CONFIG_HOME|~/.config/.honey-flap/scores.json`.

### 1. Missing/incomplete functionality

- **Pipes are exactly one column wide** (`src/Pipe.php:12-45`, spawned by `PipeGenerator::makePipe` at `src/PipeGenerator.php:46-55`). Classic Flappy-Bird-style pipes (and, per the README's stated upstream `kbrgl/flapioca`) typically have a body width of several columns, not a single cell. This was not verified against upstream source (no network fetch performed) but is worth confirming — as implemented, a pipe occupies the field for exactly one tick's worth of column-width, which combined with the 1-cell-per-tick scroll rate at `Game::advance()` (`src/Game.php:247-299`) means collision can only ever be evaluated once per pipe per bird position — there is no "clipping the side of the pipe" possibility, only a single go/no-go cell check.
- **No horizontal difficulty scaling** — only gap *height* narrows with score (`src/PipeGenerator.php:33-38`); scroll speed and `PIPE_EVERY` spacing (`src/Game.php:33`) are constant for the whole game. Many flappy-bird variants also ramp horizontal speed.
- **No terminal velocity cap** on the bird's fall — `Bird::spawn`/`tick` (`src/Bird.php:37-53`) never clamps `velocity.y`, unlike `HoneyBounce\Projectile::TERMINAL_GRAVITY` which exists precisely for this purpose (`honey-bounce/src/Projectile.php:39-42`) but is unused here. In a long stretch without flapping the fall accelerates unbounded (bounded in practice only by the floor crash check).
- **High-score list is unbounded** — `Game::withHighScore()` (`src/Game.php:144-163`) appends every new record with no cap (e.g. top-10), so `scores.json` grows for the lifetime of play (slowly, since only strict new maxima are appended, but no ceiling exists).
- **Dead code**: `Game::persistHighScores()` (`src/Game.php:165-176`) is a private method that duplicates the persistence logic inlined as a closure in `update()` (`src/Game.php:216-228`) but is never called from anywhere in `src/` or `tests/` (confirmed via grep). Either the inline closure should call this method (DRY) or the method should be removed.

### 2. Performance concerns

- `Renderer::cellGlyph()` (`src/Renderer.php:62-82`) does an O(pipes) linear scan per cell, so a full frame is O(width × height × pipes) — at `WIDTH=60, HEIGHT=18` (`src/Game.php:30-31`) and typically ≤4 onscreen pipes this is trivial (~4k ops) at 30 fps, so not a real bottleneck today, but the pattern doesn't scale if `WIDTH`/`HEIGHT` were ever raised for a larger terminal.
- `Game::advance()` (`src/Game.php:247-299`) rebuilds the full `$pipes` array every tick via `foreach` + array append, and does two separate `foreach ($pipes as $p)` passes (one for scoring, one for collision) — fine at this scale, minor micro-inefficiency only.
- Each `Bird::tick()`/`flap()` allocates a new `Projectile` + `Vector`/`Point` (`src/Bird.php:50-69`), consistent with the repo-wide immutable pattern; no leak/perf risk given 30 Hz cadence.

### 3. Test coverage gaps

- **No test ever crashes the bird into a pipe.** `tests/GameTest.php` only exercises floor crashes (`tickN(80)` in `testRestartFromCrashedState` line 61, `testCrashStopsBirdFromMovingAfterFurtherTicks` line 71, `testUpdateWithUnwritableDirDoesNotThrow` line 214) — `Pipe::collides()` is unit-tested in isolation (`tests/PipeTest.php:19-37`) but the integration path `Game::advance()` → pipe-collision branch (`src/Game.php:281-288`) has no covering test.
- **No test crashes into the top wall** (`row() < 0` branch, `src/Game.php:280`) — only the floor (`row() >= HEIGHT`) is exercised.
- **No test asserts score actually increments** when a pipe crosses the bird's column (`src/Game.php:266-276`) — `testPipeSpawnsEveryNTicks` and `testDeterministicWithSeededRand` check pipe counts/positions but never assert `$g->score` grows past 0 during play.
- **`Game::persistHighScores()`** (`src/Game.php:165-176`) has zero test coverage (consistent with it being dead code — see §1).
- **`PipeGeneratorTest::testMakePipePositionsGapWithinBounds`** (`tests/PipeGeneratorTest.php:65-74`) only tests `rand=0` (the minY boundary); there's no case exercising `rand=max` (the maxY boundary) or a mid-range value.
- No test for `Game::update()` receiving an arbitrary `KeyMsg` (e.g. an unhandled letter) confirming it's a no-op passthrough — implicitly covered by other paths but not explicitly asserted.

### 4. .vhs demos

- Present and adequate: `.vhs/play.tape` + committed `.vhs/play.gif`, following the standard TokyoNight/FontSize/Width/Height convention (`.vhs/play.tape:1-22`). No gap here.

### 5. Security concerns

- `Game::getDefaultConfigDir()` (`src/Game.php:61-73`) trusts `$_SERVER['HOME']`/`getenv('HOME')`/`getenv('XDG_CONFIG_HOME')` without validation before using it to build a filesystem path for `mkdir()`/`file_put_contents()` (`src/Game.php:165-176`, `216-228`). This is standard for a local CLI game (the values come from the invoking user's own environment, not a remote/untrusted source) so risk is low, but there's no defensive check against e.g. a symlink-swap TOCTOU between `is_dir()` and `mkdir()`/`file_put_contents()` at `src/Game.php:168-170` and `219-221` — acceptable for a single-user local high-score file, flagging only for completeness.
- `Game::readScores()` (`src/Game.php:78-95`) uses `json_decode($contents, true)` with no depth/size limit and no try/catch around `sort()` — if `scores.json` were adversarially huge this could be a (very mild) local DoS vector, but again the trust boundary is the invoking user's own config directory, not attacker-controlled input.
- No use of `escapeshellarg` or shell exec anywhere in this lib — no shell-injection surface.

### 6. Duplication vs honey-bounce / other animation libs

- No physics duplication found — `Bird` (`src/Bird.php`) is a thin, appropriate adapter over `HoneyBounce\Projectile`/`Vector`/`Point`/`Spring` (constructor at `src/Bird.php:7-10`), not a reimplementation. This is the correct reuse pattern and matches `CALIBER_LEARNINGS.md`'s note that HoneyBounce is used "just expressed as projectile state instead of an ad-hoc accumulator" (`src/Bird.php:12-17`).
- Rendering/styling correctly reuses `candy-sprinkles` (`Style`, `Border`) and `candy-core\Util\Color` rather than hand-rolling ANSI codes (`src/Renderer.php:7-9`).
- The one real duplication is internal, not cross-lib: the two high-score-persistence code paths noted in §1 (`persistHighScores()` vs. the inline closure in `update()`).

### 7. Documentation gaps

- **README.md never mentions high-score persistence at all** — no mention of `.honey-flap/scores.json`, `XDG_CONFIG_HOME`/`HOME` resolution, the "new high score" banner, or the `configDir` constructor parameter, despite this being a substantial chunk of `Game`'s implementation (`src/Game.php:35, 61-73, 78-95, 144-176, 209-235`) and its own dedicated Renderer branch (`src/Renderer.php:43-51`). The Architecture table (README "## Architecture") describes `Game` only as "bird + pipes + score + crashed flag" — high score tracking is omitted.
- `CALIBER_LEARNINGS.md` has only two entries (variable pipe gap, golden-snapshot pattern) and does not capture the high-score-persistence pattern (config-dir resolution, `Cmd`-batched disk write, dead-code duplication risk) — worth adding for future sessions per the project's own "Session Learnings" convention.
- No documentation of the top-wall-is-a-wall behavior (`src/Game.php:26` docblock mentions it, README does not) — a returning player reading only the README would not know hitting the ceiling ends the run.
- `Bird::FLAP_KICK`/`GRAVITY`/`TICKS_PER_SEC` tuning constants (`src/Bird.php:28-30`) are explained in a code comment but not surfaced in README's "Architecture" or a "Tuning" section, unlike the difficulty-scaling table which does get its own README section.

---

## sugar-bits

### Scope ownership (widget-by-widget: sugar-bits vs candy-forms vs candy-kit vs sugar-table)

`sugar-bits/composer.json:2-3` self-describes as the `charmbracelet/bubbles` port. In practice it is **not** the sole owner of bubbles-scope widgets — it is a thin façade over `candy-forms`, which holds the canonical implementations for 8 of the ~14 upstream bubbles components:

- **Real implementations in sugar-bits** (first-party): `Help`, `Key\Binding`/`Key\KeyMap`, `Progress` + `AnimatedProgress`, `Timer`, `Stopwatch`, `Paginator`, `Table`, `Tabs` (not upstream bubbles — SugarCraft-original extra), `Tree` (not upstream bubbles — SugarCraft-original extra, mirrors bubbles#233 discussion).
- **Deprecated `class_alias()` re-exports** (real code lives in `candy-forms`): `Cursor` (`sugar-bits/src/Cursor/Cursor.php:7`), `TextInput` (`src/TextInput/TextInput.php:7`), `TextArea` (`src/TextArea/TextArea.php:7`), `Viewport` (`src/Viewport/Viewport.php:7`), `ItemList` (`src/ItemList/ItemList.php:7` — this is upstream bubbles' `list.Model`), `FilePicker` (`src/FilePicker/FilePicker.php:7`), `Scrollbar` (`src/Scrollbar/Scrollbar.php:7`), `Spinner` (`src/Spinner/Spinner.php:7`). Each file is 8 lines, pure `class_alias()`, confirmed via `Read`.
- **candy-kit** ports `charmbracelet/fang` (CLI presentation chrome: Banner/StatusLine/Theme/Frame/Section/Logo/Stage — `candy-kit/src/*.php`), which is **unrelated to bubbles**. The sibling audit's suspicion that "candy-kit isn't the bubbles owner, so sugar-bits must be" is correct, but the real bubbles owner for 8 of the widgets is `candy-forms`, not sugar-bits itself — sugar-bits only re-exports.
- **sugar-table** ports `Evertras/bubble-table` (a different upstream project, richer feature set: zebra striping, frozen columns, viewport virtualization, `Border` family, borderless mode — see `sugar-table/README.md`). `sugar-bits/src/Table/Table.php` is an independent, simpler port of upstream **bubbles'** own `table.Model`. These are two legitimately distinct upstream projects living side by side under different names — not accidental duplication, but worth flagging in docs since "Table" appears in both libs with no cross-reference either README.

Net: upstream `charmbracelet/bubbles` scope (cursor, filepicker, help, key, list, paginator, progress, spinner, stopwatch, table, textarea, textinput, timer, viewport) is **fully covered** across sugar-bits + candy-forms combined — no missing widget. But ownership is split and only documented in `CALIBER_LEARNINGS.md`, not in either lib's README in a way that cross-links.

### Functionality vs upstream bubbles — no gaps found

All 14 upstream bubbles components exist (7 first-party in sugar-bits, 7 aliased from candy-forms — `list.Model` maps to `ItemList`, confirmed no distinct "List" class expected). Two SugarCraft-original extras beyond upstream scope: `Tree` (`src/Tree/Tree.php`, 366 lines, mirrors bubbles #233 discussion per README) and `Tabs` (`src/Tabs/Tabs.php`, 495 lines — not present in upstream bubbles at all).

### Performance concerns

- `src/Table/Table.php:165-166` and `:412-413` and `:578-580` (`visibleRows()`) each independently call `sortedRows()` then `filteredRows($sortedRows)` with **no memoization**. `view()` (line 165), `selectedRow()` (line 246, via `visibleRows()`), and another render path (line 412) each re-sort and re-filter the **entire** row set from scratch. For a large table (thousands of rows) this means an O(n log n) sort plus an O(n) filter scan repeated multiple times per single `view()` call, and again on every keystroke/tick that triggers a re-render — no caching keyed on `(rows, sortState, filter)`.
- `filteredRows()` (`src/Table/Table.php:544-567`) default substring filter does `mb_strtolower` + per-cell `mb_stripos` over every row/column on every call — same non-memoized-recompute issue compounds this.
- Windowing itself (`array_slice` at `Table.php:175`) is correct and cheap; the waste is entirely in the un-cached sort/filter projection feeding it.

### Test coverage gaps

- Overall coverage is broad (8099 test LOC across 30 files) and every first-party widget (Table, Tabs, Tree, Progress/AnimatedProgress, Timer, Stopwatch, Paginator, Help, Key\Binding) has a dedicated test file.
- **Test-suite duplication**: `sugar-bits/tests/{TextInput,TextArea,ItemList,Viewport,FilePicker,Cursor,Scrollbar,Spinner}/*Test.php` are near-byte-identical copies of `candy-forms/tests/{...}/*Test.php` (verified via diff + head — files differ only in namespace: `SugarCraft\Bits\Tests\*` vs `SugarCraft\Forms\Tests\*`, importing `SugarCraft\Bits\*` classes that are themselves aliases back to `SugarCraft\Forms\*`). This means the exact same behavioral test suite runs twice in CI (once against the real class in candy-forms, once against the alias in sugar-bits) with zero incremental coverage — e.g. `tests/TextInput/TextInputTest.php` (901 lines) duplicates `candy-forms/tests/TextInput/TextInputTest.php` almost line-for-line. `CALIBER_LEARNINGS.md`'s `class-alias-transitional` entry documents the alias pattern but doesn't call out or justify the duplicated test cost — worth a follow-up to either delete the sugar-bits copies (keep one `ShortAliasesTest.php`-style smoke test that the alias resolves) or explicitly document why both suites are kept.

### Missing .vhs demos

- `examples/tabs.php` exists but has **no** `.vhs/tabs.tape` — the only first-party widget without a VHS demo (all other first-party widgets — table, tree, progress, timer, stopwatch, paginator, help — have tapes).
- `examples/spinner.php` has no `spinner.tape`, but `.vhs/spinners.tape` (plural) appears to be an intentional multi-style spinner demo rather than a true gap.

### Security concerns

No direct security issues in sugar-bits' own code: no `exec`/`shell_exec`/`passthru`/`eval`/`getenv`/`putenv` calls anywhere in `src/` (confirmed via grep). C0-control-char sanitization via `SugarCraft\Core\Util\Sanitize::controlChars()` is applied consistently on all render paths that embed user-supplied strings: `Help.php:252-253` (key/desc), `Progress.php:75-76,118-119` (fullChar/emptyChar), `Table.php:643` (cell content), `Tabs.php:193` (labels), `Tree.php:169` (node labels) — matches the pattern documented in `CALIBER_LEARNINGS.md`'s `security:sugar-bits:c0-sanitization` entry. `Paginator` doesn't sanitize, but it only renders dots/digits it generates itself, not user input, so that's not a gap. The 8 aliased widgets (TextInput, TextArea, FilePicker, etc.) inherit whatever security posture `candy-forms` has — out of scope for this lib since sugar-bits contributes zero logic there.

### Documentation gaps

- README (`sugar-bits/README.md:15-19`) clearly states the 9 first-party / 8 aliased split and links each aliased row to its `candy-forms` home — this part is good.
- No README cross-reference to `sugar-table` despite both libs shipping a class literally named `Table` for different upstream projects (bubbles' `table.Model` here vs `Evertras/bubble-table` there) — a newcomer skimming `docs/index.html` tiles could easily require the wrong one. Worth a one-line disambiguation note in both READMEs.
- No stated deprecation/removal timeline for the 8 `class_alias` re-exports (e.g. "removed in v2.0") — they're marked `@deprecated` in-code and in the README table but there's no migration guide or SunsetDate, so consumers have no signal for when direct `candy-forms` imports become mandatory.

---

## sugar-boxer

Port target: `treilik/bubbleboxer` (box-drawing layout engine). Status in `docs/MATCHUPS.md:57` is 🟢. Confirmed scope from README + code: a **pure, stateless renderer** — build a `Node` tree (`leaf`/`horizontal`/`vertical`/`noborder`), call `SugarBoxer::render($root, $w, $h)`, get an ANSI string back (with optional diff-encoded delta on repeat renders of the same size).

### 1. Missing/incomplete functionality vs upstream

- **No interactive sub-model wiring.** Upstream `bubbleboxer` is fundamentally a `tea.Model` itself: `Boxer.Update()` forwards `tea.Msg` to the focused leaf's embedded `tea.Model`, and `Boxer.View()` calls each leaf model's `View()`. `SugarCraft\Boxer\Node` (`src/Node.php:47`) only carries a static `string $content` — there is no way to embed a `candy-core` `Model`, so sugar-boxer cannot drive a focused/interactive child (e.g. a live `sugar-bits\TextInput` inside a panel) without the caller re-rendering the whole tree by hand every frame. This is the single biggest capability gap vs. upstream and vs. what `MATCHUPS.md` calls "🟢" (feature-complete).
- **No node addressing (`GetAddr`/`ModelMap`).** Upstream's `Boxer` maps string addresses to leaf nodes so callers can look up/replace/focus a specific leaf by path. `SugarCraft\Boxer\Node` has no id/address concept at all — no `withId()`, no `SugarBoxer::find(Node, string $addr)`. Composing large layouts requires holding onto `Node` references manually.
- **No focus/active-panel concept.** No `withFocused(bool)`/border-color-on-focus helper — common bubbleboxer usage pattern (highlight the active pane's border) has to be reimplemented by callers via `withBorderStyle()`/`withStyle()` each render.
- **Title anchoring is top-only and single-position.** `drawBorder()` (`src/SugarBoxer.php:759-802`) only ever writes the title into the top border row, left-anchored after the corner. `candy-sprinkles\Style` already models six title anchor positions via `Border\TitleAnchor` / `BorderTitle` (`candy-sprinkles/src/Border.php:8`, referenced from `Style::withTitle`-family methods) — sugar-boxer's own `Node::withTitle()` (`src/Node.php:216`) doesn't reuse that richer primitive, so titles can't be centered/right-anchored/put on the bottom border like Style's can.
- **`withMargin()`/`withAlignH()`/`withAlignV()` don't participate in `totalWidth()`/`totalHeight()`.** `Node::totalWidth()` (`src/Node.php:265`) accounts for border + padding but never adds `$this->margin[1] + $this->margin[3]` (or vertical margin for height) — so a flex/weight computation that uses `totalWidth()` as a child's "natural size" (`SugarBoxer::renderHorizontal`, `src/SugarBoxer.php:264`) will under-count any node that also sets a margin, and the margin then eats into space the sizing pass assumed was free.

### 2. Performance concerns

- **`totalWidth()`/`totalHeight()` are uncached recursive walks** (`src/Node.php:265-314`) called on every `renderHorizontal`/`renderVertical` invocation for every flex sibling (`src/SugarBoxer.php:264`, `:340`). For a deep/wide tree re-rendered every frame (the diff-buffer machinery in the same class implies exactly that use case — animated TUIs), this is an uncached O(nodes) traversal per render per level, i.e. effectively O(n·depth) work purely to recompute sizes that don't change between frames when the tree itself is immutable and unchanged. Nothing memoizes on the `Node` (which is otherwise fully immutable/readonly — an ideal cache candidate).
- **`render()` allocates a full `height`×`width` grid of one-character PHP strings every call** (`src/SugarBoxer.php:103`: `\array_fill(0, $height, \array_fill(0, $width, ' '))`), even when only the diff is ultimately emitted. For large viewports (e.g. a maximized 200×60 terminal) that's 12,000 individual string-cell allocations per frame regardless of how little changed — no cell-grid reuse/pooling across renders on the same `SugarBoxer` instance.
- **`sgrPrefixCache` keyed by `spl_object_id($style)`** (`src/SugarBoxer.php:31`, `:410`) never evicts. A caller that constructs a fresh `Style` object per render (easy to do accidentally, since `Style` is immutable/fluent and callers often build styles inline) will leak cache entries for the lifetime of the `SugarBoxer` instance — unbounded growth for long-lived renderer instances driving many distinct styles over a session. There's a `MAX_CARRY` cap for the unrelated combining-character carry buffer (`:29`) but no analogous cap/LRU here.

### 3. Test coverage gaps

Strong coverage overall (61 test methods across 4 files: `AnsiPlacementTest.php` 301 lines / `FlexLayoutTest.php` 171 / `LangCoverageTest.php` 68 / `SugarBoxerTest.php` 602) for word-wrap, ANSI-aware placement, flex distribution, alignment, margins, diff-buffer lifecycle. Gaps found:
- **No test for `maxWidth`/`maxHeight` interacting with `renderHorizontal`/`renderVertical`'s `min(..., $child->maxWidth)` clamp** (`src/SugarBoxer.php:289-291`, `:360-362`) — only leaf-level `testMaxWidthClampsContentWidth`/`testMaxHeightClampsContentHeight` (`tests/SugarBoxerTest.php:521`, `:538`) exercise the constraint on a bare leaf's own content region, not a child inside a horizontal/vertical parent hitting the per-child clamp in the distribution loop.
- **No test for `withMargin()` combined with a horizontal/vertical parent's flex sizing** — given the `totalWidth()`/margin gap noted above, there's no regression test that would have caught it.
- **No test for `Node::noBorder()` nested more than one level, or `noBorder` wrapping a horizontal/vertical node** (`renderNoBorder`, `src/SugarBoxer.php:372`) — existing `testRenderNoBorder` (`tests/SugarBoxerTest.php:208`) only wraps a single leaf.
- **No test for negative/invalid constructor inputs** — `withMinWidth(-5)`, `withPadding(-1)`, `withSpacing(-1)` are never exercised; `Node`'s constructor and `with()` (`src/Node.php:68`, `:329`) perform no clamping/validation on these (contrast `withFlex()` which explicitly does `\max(0, $weight)`, `src/Node.php:163-166`), so behavior under negative padding/spacing is untested and unspecified.
- **`resetPreviousFrame()` interaction with mid-stream style-cache** — `sgrPrefixCache` is never cleared by `resetPreviousFrame()` (`src/SugarBoxer.php:985-991`); no test asserts the cache's lifetime/growth behavior at all (ties to the performance concern above).
- No test drives `render()` with `$width` or `$height` of 0 or negative directly at the top-level `render()` entry (only inner `renderNode`'s `$w <= 0 || $h <= 0` guard is exercised transitively via nested layouts).

### 4. Missing .vhs/*.tape demos

`.vhs/` has three tapes (`basic.tape`, `borders.tape`, `nested.tape`) each driving the matching `examples/*.php`, all present and wired (`basic.gif`/`borders.gif`/`nested.gif` committed). Gaps:
- **No tape/example demonstrating `withFlex()`/`withGrow()`** — the flex-fill feature is the largest single addition called out in `CALIBER_LEARNINGS.md` ("flex-grow-children", 2026-06-23) and has its own dedicated test file (`tests/FlexLayoutTest.php`), but no `examples/flex.php` + `.vhs/flex.tape` shows it visually.
- **No tape/example demonstrating `withStyle()`/colour, `withTitle()`, or `withAlignH`/`withAlignV`** — all are documented in `README.md:82-107` with code snippets but none of the three existing example scripts (`examples/basic.php`, `borders.php`, `nested.php`) exercise style/title/alignment, so there's no visual regression coverage for them despite unit-level coverage.

### 5. Security concerns

No high-severity issues found — no `eval`/`exec`/`shell_exec`/`unserialize`/dynamic `include` in `src/`. Notes:
- **Unbounded recursion via arbitrarily deep/self-referential `Node` trees.** `renderNode()`/`totalWidth()`/`totalHeight()` recurse into `$node->children` with no depth limit (`src/SugarBoxer.php:148`, `src/Node.php:265`,`:292`). Since `Node` is immutable and constructed only through factories, true cycles aren't constructible by normal use, but a caller building a very deep tree from untrusted structured input (e.g. deserializing a layout spec) could still trigger a stack-exhaustion `Error` — a hardening nice-to-have, not an active vulnerability given the library's intended usage.
- ANSI/escape parsing (`escapeAt`, `src/SugarBoxer.php:571`) is defensive against malformed/unterminated sequences (bounded loops, no `preg_match` backtracking risk observed), and `MAX_CARRY` (`:29`) already guards against unbounded zero-width-grapheme accumulation from adversarial content strings — good.

### 6. Duplication with candy-sprinkles / candy-layout

- **`SugarBoxer::distribute()`/`distributeFlex()` (`src/SugarBoxer.php:846-938`) reimplement a constraint/weight-based space-splitting algorithm that `candy-layout` already provides as a first-class, tested abstraction.** `candy-layout/src/LayoutSolver.php` defines `GreedySolver`/`CassowarySolver` explicitly "mirrors ratatui's layout constraint solving" with `Constraint\{Length,Min,Max,Percentage,Ratio,Fill}` — i.e. exactly the fixed-vs-proportional-vs-flex-fill split sugar-boxer hand-rolls with its own weight/flex math. `sugar-boxer/composer.json` even lists `../candy-layout` as a path-repository entry but **does not `require` it**, and `src/` never references `SugarCraft\Layout\*` — the dependency is wired for path-resolution but unused, and the layout distribution logic was written from scratch instead of delegating to it.
- **Border-drawing overlap with `candy-sprinkles\Style`.** `candy-sprinkles/src/Style.php` already renders a full box: border (all 4 sides, per-side toggle, per-side fg/bg, gradient blends) plus multi-anchor titles (`Border/TitleAnchor.php`, `Border/BorderTitle.php`). `SugarBoxer::drawBorder()` (`src/SugarBoxer.php:759`) re-implements corner/edge drawing and single-position title placement directly against `Border` characters rather than delegating to `Style::render()`'s existing border+title pipeline — two independent border-rendering code paths in the monorepo that can drift (e.g. sugar-boxer's title is top-only/left-anchored while Style supports 6 anchors, as noted in section 1).
- **Word-wrap is sugar-boxer-only** (`wordWrap()`/`splitWord()`, `src/SugarBoxer.php:666-749`) — no `wordWrap`/`WordWrap` symbol exists in `candy-sprinkles` or `candy-layout` today, so this isn't duplicated *yet*, but it is exactly the kind of primitive (ANSI-aware, grapheme-width-aware wrapping) that belongs in a shared foundation lib (`candy-sprinkles` or `candy-core`) rather than being owned by a single leaf-level component — worth flagging before a second consumer reinvents it independently.

### 7. Documentation gaps

- **`README.md` badge block references the wrong Packagist org** — `README.md:6` links `packagist.org/packages/sugarcore/sugar-boxer` (`sugarcore`) while `composer.json:2` declares `"name": "sugarcraft/sugar-boxer"` — the badge points at a nonexistent/wrong package.
- **No documentation of the `Preserve`/sentinel mechanics for `with*()` chaining gotchas that CALIBER_LEARNINGS.md calls out** (`withBorder(false)` not clearing `borderStyle`, margin/style/align preservation across chained calls) — these are recorded in `CALIBER_LEARNINGS.md:49-56` but not surfaced in the public `README.md`, so a consumer following only the README's `## Styling with candy-sprinkles` examples has no warning about the "`withBorder(false)` doesn't clear style" footgun before hitting it.
- **No mention of the buffer-diff feature's *opt-in* per-instance requirement.** `README.md:145-158` documents the diffing behavior well, but doesn't state the precondition explicitly spelled out in code comments — that diffing only activates when the **same `SugarBoxer` instance** is reused across renders at unchanged dimensions (`src/SugarBoxer.php:112-121`); a fresh `SugarBoxer::new()` per render (an easy mistake, since `::new()` is the idiomatic factory call shown in every README example) silently always takes the full-repaint path with no diff benefit and no warning.
- **No `CHANGELOG.md`** in the lib directory (not required by `AGENTS.md` skeleton, but flex/grow and ANSI-placement were significant post-initial-release additions per `CALIBER_LEARNINGS.md` and aren't reflected in any version history the README exposes).

---

## sugar-calendar

### 1. Missing/incomplete functionality

- **No timezone handling anywhere.** `grep -rn "TimeZone\|DateTimeZone" sugar-calendar/src` returns nothing. `DatePicker::__construct` (`src/DatePicker.php:106-111`), `today()` (`src/DatePicker.php:191-195`), and every `\DateTimeImmutable::createFromFormat(...)` call (e.g. `src/DatePicker.php:444-447`, `751-753`, `849-852`, `861-863`) rely on the ambient PHP default timezone (`date_default_timezone_get()`). If a caller injects a `\DateTimeImmutable` in one zone and `today()` resolves `new \DateTimeImmutable()` in another (or vs. server default), "isToday" comparisons (`src/DatePicker.php:779`) and range containment can silently be off by a day around midnight. No `withTimezone()`/`withToday()`-style zone injection exists (only `withToday()` for the date itself, `src/DatePicker.php:183-189`).
- **No recurring-event support.** `EventStore`/`EventStoreInterface` (`src/EventStore.php`, `src/EventStoreInterface.php`) is a flat in-memory log of `{type, payload, time}` triples recorded by `Model::update()` (`src/Model.php:78-105`) — there is no concept of an "event" with a date/duration, no RRULE/recurrence, no persistence. This is an interaction-event log (analytics/telemetry), not a calendar-events model, despite the lib name "sugar-calendar". Upstream `bubble-datepicker` is itself just a date picker (no recurrence), so this may be in-scope-as-designed, but the README doesn't clarify that "events" here means UI interaction events, not calendar appointments — likely to confuse consumers expecting an events/appointments API.
- **Locale-aware month/day names exist and route through i18n correctly** (`DatePicker::dayName()`/`monthName()` at `src/DatePicker.php:89-100` call `Lang::t('day.'.$dow)` / `Lang::t('month.'.$month)`, backed by `src/Lang.php` extending `SugarCraft\Core\I18n\Lang`). However **only `lang/en.php` exists** — no other locale files under `sugar-calendar/lang/` (per `LOCALES.md`'s recommended set: fr, de, es, pt, pt-br, zh-cn, zh-tw, ja, ru, it, ko, pl, nl, tr, cs, ar). Non-English users get English month/day names via the `en` fallback in the lookup chain.
- **No locale-aware week-start.** Week rendering is hardcoded Sunday-first: `dayName()` loop `for ($dow = 0; $dow < 7; $dow++)` (`src/DatePicker.php:480-484`) and grid math uses `format('w')` (0=Sun) throughout (`dateAtCursor()` `src/DatePicker.php:428`, `buildCells()` `src/DatePicker.php:757`, `firstDayOffset()` `src/DatePicker.php:852`). Locales where the week starts on Monday (most of Europe) or Saturday cannot be represented; there's no `withWeekStart()` API.

### 2. Performance concerns

- **Two-tier caching is documented and implemented** (`CALIBER_LEARNINGS.md` "pattern:view-caching"), but the cache-validity check in `View()` (`src/DatePicker.php:469-471` and `523-526`) only special-cases the *default* `$width=21, $showWeekNumbers=false` call — every other width/week-number combination always recomputes both cells and buffer, which is fine functionally but means callers rendering with a non-default width every frame get zero caching benefit.
- **Mutable cache fields on an otherwise-immutable value object.** `$cachedCells`, `$cachedView`, `$cacheValid` (`src/DatePicker.php:64-70`) are written directly inside `View()` (`$this->cachedCells = $cells;` at line 498, `$this->cachedView = ...` at line 524-525) even though `View()` has no `self` return type change — i.e. `View()` mutates `$this` despite the class's `with*()`/clone-based immutability convention everywhere else. This is a subtle immutability violation: two "different" `DatePicker` clones that are `==`-equal in state can end up with divergent private cache fields, and concurrent/shared references to the same instance get mutated by a supposedly side-effect-free render call. Not thread-unsafe in single-request PHP, but breaks the "every method returns a new instance" invariant documented in `AGENTS.md`/`CLAUDE.md`.
- **`isoWeekNumber()` computed per row per render** (`src/DatePicker.php:539-552`) creates a fresh `\DateTimeImmutable` per week row via `modify()`; negligible in practice (max 6 calls) but not covered by the view cache when `showWeekNumbers=true` (never cached, see above).
- No pathological perf issues found otherwise — grid is fixed 6×7=42 cells, `View()` is O(42).

### 3. Test coverage gaps

- **No leap-year test.** No test constructs February in a leap year (e.g. 2028) vs. non-leap (2026/2027) to assert `daysInMonth` (`format('t')`) correctness for Feb 28 vs Feb 29. `buildCells()` (`src/DatePicker.php:749-808`) and `dateAtCursor()` (`src/DatePicker.php:423-438`) both depend on `format('t')`; untested at the Feb/leap boundary.
- **No DST-transition test.** `today()`/`withToday()` and all date arithmetic use `\DateTimeImmutable` with no explicit UTC/zone pinning; no test exercises a DST spring-forward/fall-back date (e.g. a March/November boundary in a zone with DST) to confirm `isToday`/range containment aren't off-by-one when the ambient timezone has a DST transition.
- **No year-boundary (Dec→Jan) `isoWeekNumber()` test** — ISO week 1 vs week 52/53 edge case at year boundaries (e.g. Dec 31 landing in week 1 of the next year, or Jan 1 landing in week 52 of the previous year) is unexercised; `isoWeekNumber()` (`src/DatePicker.php:539-552`) is only reachable via `View($width, true)` and `DatePickerTest`/`GoldenRenderTest` never pass `$showWeekNumbers = true`. **This method currently has zero direct or golden-fixture coverage at all.**
- **`firstDayOffset()` (`src/DatePicker.php:847-853`) is dead/duplicate code** — `clampedCursor()` already computes `$firstDow` itself via `$this->firstDayOffset()` at line 865, but `firstDayOffset()` duplicates the same `createFromFormat`+`format('w')` logic already present in `firstOfViewMonth()`/`buildCells()`. Not a test gap per se, but no test targets `firstDayOffset()` directly, and it's redundant with `firstOfViewMonth()->format('w')` used elsewhere.
- **`GoToPreviousMonth()`/`GoToNextMonth()` across a Dec 31→Jan 31 or "31st of a 30-day month" carry is untested** — e.g. going from March 31 to February (28/29 days) never explicitly asserted; only year-wrap (`testGoToPreviousMonthAtJanuary`, `testGoToNextMonthAtDecember`) and short-month clamp (`testClampCursorAfterNavigatingToShorterMonth`) are covered, and the latter tests cursor clamping, not that `viewMonth`/`viewYear` land correctly when there's no corresponding day-of-month (irrelevant here since navigation is month/year only, not day-preserving, but worth noting for future day-preserving nav additions).
- `DateRange::durationInDays()` (`src/DateRange.php:46-55`) is never tested with `end < start` (reversed/invalid range) — `diff()->days` is always non-negative (absolute), so a "reversed" range silently reports a positive duration instead of erroring or returning null, and no test documents/locks this behavior.
- Good coverage otherwise: golden-file snapshots for 3 months (`tests/GoldenRenderTest.php`), immutability checks (`testImmutability`), SGR-style unit tests (`tests/DatePickerStyleTest.php`), i18n key-existence audit (`tests/LangCoverageTest.php`), range-selection normalization (`testRangeSelectionNormalizesStartEnd`), and cross-check between `dateAtCursor()`/`Navigation::gridIndexToDate()` (`testCursorDateMappingsAgree`).

### 4. Missing .vhs/*.tape demos

None — `.vhs/basic.tape` and `.vhs/constraints.tape` (with corresponding `.gif`s) both exist, and `sugar-calendar` is registered in the hand-maintained `all=(...)` matrix in `.github/workflows/vhs.yml:154`. No gap here.

### 5. Security concerns

- **`Navigation::gridIndexToDate()` builds a date via unvalidated string interpolation**: `new \DateTimeImmutable("$year-$month-01")` (`src/Navigation.php:39`) — if `$month`/`$year` ever originate from untrusted input (e.g. deserialized state, URL params in a web-embedded TUI), no bounds/format validation occurs before interpolation into the date string. `\DateTimeImmutable`'s constructor is fairly permissive/relative-format-tolerant (e.g. a `$month` of `13` or negative values could parse as a relative date shift rather than throwing), so malformed `$month`/`$year` could silently produce a wildly wrong date instead of failing loudly. Contrast with `DatePicker`'s equivalent logic which uses `createFromFormat('Y-m-d', sprintf('%04d-%02d-01', ...))` (`src/DatePicker.php:751-753`, `849-851`) — stricter, but still no explicit range check on `$viewMonth`/`$viewYear` before formatting, and `createFromFormat` with `sprintf('%02d', $month)` for `$month > 99` or negative would still produce a parseable-but-wrong string rather than throwing.
- **No injection risk from SGR style strings** — `assertSgr()` (`src/DatePicker.php:614-621`) validates all `With*Style()` inputs against `/^[0-9;]*$/` before use, which correctly prevents raw ANSI escape injection through style setters (defensive, well done — has a regression test `testWithStyleRejectsNonSgrInput`, `tests/DatePickerStyleTest.php:247-252`).
- **No validation on `EventStore::record()` payload** (`src/EventStore.php:15-18`) — `$type` and `$payload` are stored as-is with no sanitization; this is by design for an in-memory event log, but if a consumer later serializes/renders this payload into a UI or log without escaping, it's a downstream XSS/log-injection risk inherited from whatever calls `record()` — not a bug in this lib, but worth flagging since nothing in the docstrings warns callers to sanitize `$payload` before display.
- No date-parsing from raw/untrusted strings anywhere in the public API surface (`DatePicker` and `Model` only ever accept `\DateTimeImmutable`, never raw strings), which is good — limits the untrusted-date-string attack surface primarily to `Navigation::gridIndexToDate()` above.

### 6. Functionality duplicated with other libs

- No duplication found. A repo-wide grep for `DatePicker`/calendar-ish date logic in `sugar-crumbs`, `sugar-stickers`, `candy-forms`, `sugar-post` turned up nothing — `sugar-calendar` appears to be the sole home for calendar-grid/date-picker logic in the monorepo. `Navigation` (`src/Navigation.php`) duplicates grid-index math that's *inline* in `DatePicker` itself (`MoveCursorLeft/Right/Up/Down` at `src/DatePicker.php:217-247` vs. `Navigation::move()` at `src/Navigation.php:18-29`, and `dateAtCursor()` at `src/DatePicker.php:423-438` vs. `Navigation::gridIndexToDate()` at `src/Navigation.php:37-48`) — this is intra-lib duplication, not cross-lib. `DatePicker` doesn't actually call `Navigation` at all (`MoveCursorLeft()` etc. reimplement the clamp logic directly rather than delegating to `Navigation::move()`), so `Navigation` is essentially a parallel, currently-unused-by-DatePicker implementation of the same grid math, only exercised directly by `tests/NavigationTest.php` and cross-checked against `dateAtCursor()` in `tests/DatePickerTest.php::testCursorDateMappingsAgree`. Worth consolidating: either have `DatePicker` delegate to `Navigation`, or drop `Navigation` as a separate public class if it's purely a test-parity artifact.

### 7. Documentation gaps

- **README omits range-selection and event-store features entirely.** `README.md` documents only single-date select/clear and month/year navigation (`README.md:42-71`); it never mentions `withRangeMode()`, `rangeStart()`/`rangeEnd()`, `handleKey()`'s range-Enter/Escape semantics, `DateRange`, `EventStore`/`EventStoreInterface`, or `Model` (the TEA wrapper) — all of which are implemented, tested, and part of the public API (`src/DatePicker.php:288-387`, `src/DateRange.php`, `src/EventStore.php`, `src/Model.php`). A new consumer reading only the README would not discover range selection or the TEA `Model` integration exists.
- **README doesn't mention `withToday()`** (`src/DatePicker.php:183-189`), the only supported hook for pinning "today" for testability/timezone-consistent rendering — likely to be missed by consumers who need deterministic "today" highlighting.
- **README doesn't document `showWeekNumbers`/custom `$width` params of `View()`** (`src/DatePicker.php:461-464`), nor the styling setters `WithHeaderStyle`/`WithTodayStyle`/`WithSelectedStyle`/`WithCursorStyle`/`WithRangeStyle` (`src/DatePicker.php:558-601`) or the SGR-string format/validation contract (`assertSgr()`, `src/DatePicker.php:614-621`).
- **No CONVERSION/upstream-parity note** about the documented architectural divergence from upstream (grid-index cursor vs. date-based cursor, per `CALIBER_LEARNINGS.md` "pattern:grid-index-cursor") appearing anywhere in the public README — this is a meaningful behavioral difference for anyone porting code from `EthanEFung/bubble-datepicker` and currently only recorded in the internal `CALIBER_LEARNINGS.md`, not user-facing docs.
- `EventStoreInterface`/`EventStore` lack any doc-comment cross-reference to `Model`'s recorded event *types* (`'date_selected'`, `'cursor_moved'` — see `src/Model.php:86-104`) — a consumer implementing a custom `EventStoreInterface` has to read `Model.php` source to discover which `$type` strings and `$payload` shapes to expect; no enum/const list of event types exists anywhere.

---

## sugar-charts

### Missing / incomplete functionality

- No pie/donut, histogram, box-plot, or gauge chart types. README's own "Status" table (`sugar-charts/README.md:203-216`) self-reports several gaps: `BarChart` — "multi-bar grouped datasets pending" (`README.md:209`); `TimeSeries` — "multi-dataset + update-handler variants pending" (`README.md:211`); `OHLC` — "volume sub-pane + multi-series pending" (`README.md:214`); `Scatter` — "single-dataset only — per-point rune/style sets pending" (`README.md:215`); `Picture` — "Sixel renderer only — Kitty graphics protocol + iTerm2 inline + half-block fallback pending" (`README.md:216`).
- `LineChart` (`src/LineChart/LineChart.php`) *does* already support multi-series via the `datasets` param (`LineChart.php:36-45`, plotted together at `LineChart.php:436-437`) and per-series rune overrides (`datasetPoints`) — the README's "dataset styles pending" note (`README.md:210`) is stale relative to the code; worth reconciling docs vs. implementation.
- `Scatter` (`src/Scatter/Scatter.php`) has one global `$rune` (`Scatter.php:49`) and no `datasets`/per-point color support — true single-series only, unlike `LineChart`.
- `BarChart` (`src/BarChart/BarChart.php`) has no grouped/stacked multi-series bars — one value per label only (`BarChart.php:41` `Bar[]`).
- Axis labeling: `Chart`/`ChartExtras` support a single `xLabel`/`yLabel` string appended as a caption (`src/Chart/Chart.php:213-231`), not per-tick axis labels with units; `LineChart` does have real tick labels (`xLabels`/`yLabels`, `xLabelFormatter`/`yLabelFormatter`, `resolveXLabels`/`resolveYLabels` — `LineChart.php:417-418`), but `BarChart`, `Scatter`, `Heatmap`, `OHLCChart` only get the single caption-style label, no per-bar/per-tick numeric axis.
- Legend exists (`src/Legend/Legend.php`, wired via `ChartExtras`/`Chart`) but only for `BarChart`, `LineChart`, `Scatter` (via `chartExtrasGetLegendItems()` — e.g. `BarChart.php:265-266`, `Scatter.php:169-170`). `Heatmap`, `OHLCChart`, `Sparkline`, `Streamline`, `Waveline`, `TimeSeries` have no legend support (`Heatmap` has its own separate `withLegend()` for a colour-scale gradient bar, not a series legend — `src/Heatmap/Heatmap.php:150-159`, `202-256`).

### Performance concerns

- No memoization: every chart's `view()`/`renderChart()` fully recomputes min/max scans and canvas painting on every call (e.g. `BarChart::renderChart()` at `src/BarChart/BarChart.php:300-381`, `LineChart::renderChart()` at `src/LineChart/LineChart.php:386-`, `Heatmap::view()` at `src/Heatmap/Heatmap.php:202-256`). Fine for typical TUI widths but means a hot render loop (e.g. `Streamline::push()` called per sample then re-`view()`'d — `src/LineChart/Streamline.php:97-104`) pays a fresh `LineChart::new(...)->view()` construction + full min/max scan every frame.
- No large-dataset downsampling before render: `LineChart::renderChart()` does slice to the last `$plotW` points when `count($values) > $plotW` (`LineChart.php:441`), which is a reasonable last-N truncation, but there is no *first-class* downsample-before-plot path (e.g. LTTB/min-max-per-bucket) for series much larger than the plot width — a caller must manually pipe through `Aggregation\Resample`/`BucketByTime` first. `Scatter::renderChart()` (`src/Scatter/Scatter.php:198-227`) and `Heatmap::view()` iterate every point/grid cell with no cap — a very large point/grid list means O(n) canvas writes with no sampling, though for a bounded terminal size this is rarely an issue except with pathologically large input arrays.
- `Streamline::push()` (`src/LineChart/Streamline.php:42-51`) clones the window array on every single-sample push (`array_slice` when over `$width`) — acceptable since window is bounded to `$width`, but a hot per-tick call path with a large `$width` (e.g. thousands) makes each push O(width) rather than O(1).

### Test coverage gaps

- `Scatter` supports only a single dataset/rune and its tests (`tests/Scatter/ScatterTest.php`) presumably don't (and can't) cover multi-series since the feature doesn't exist — flagged here as a coverage gap tied to the missing-functionality item above.
- No test exercises non-finite/NaN input values (`NAN`, `INF`, `-INF`) anywhere — confirmed by `grep -rn "is_nan|is_finite|is_infinite|NAN|INF" src/` returning zero hits repo-wide. Every chart's min/max/normalize path (`BarChart.php:325-329`, `LineChart.php:409-413`, `Heatmap.php:213-231`, `Scatter.php:204-215`) is therefore untested against malformed numeric input.
- `src/Chart/ChartExtras.php` (the shared legend/title/label composition trait used by `BarChart`, `Scatter`, and implicitly by `Chart`) has no dedicated unit test file — it's only exercised indirectly through `BarChartTest`/`ScatterTest`/`ChartTest`.
- `src/Chart/Position.php`, `src/Canvas/Cell.php`, `src/BarChart/Bar.php`, `src/Heatmap/HeatPoint.php`, `src/OHLC/Bar.php`, `src/Picture/Protocol.php` are plain DTOs/enums with no standalone test files (low risk, but zero direct coverage).
- `examples/animated_line.php` exists but has no corresponding test, VHS tape, or README table entry (see Demo gap below) — the animation-progress code path (`Chart::withAnimationProgress()`/`withAnimationDuration()`, `LineChart.php:447-472`) is covered by `tests/LineChart/LineChartAnimationTest.php`, so unit coverage exists even though the example itself is undocumented/unverified end-to-end.

### Missing demos (.vhs/*.tape)

- `examples/animated_line.php` has no matching `.vhs/animated_line.tape` (compare the full tape set: `bar`, `heatmap`, `line`, `ohlc`, `picture`, `scatter`, `sparkline`, `timeseries` — `sugar-charts/.vhs/*.tape`) and is also absent from `examples/README.md`'s table (`examples/README.md:9-17`), which only lists 8 of the 9 example scripts.
- No demo/tape at all for multi-series `LineChart` (`withData` + `datasets`), `withTheme()`, `withCanvas(BrailleCanvas)` (braille rendering), or `Streamline`/`Waveline` — all documented as features in `README.md` (lines 56-59, 95-131) but none has a `.vhs` tape or example script under `examples/`.

### Security concerns

- No `is_nan()`/`is_finite()` guards anywhere in `src/` (confirmed via repo-wide grep — zero matches). Untrusted numeric input containing `NAN`/`INF` flowing into any chart's min/max auto-range logic (`BarChart.php:325-329`, `LineChart.php:409-413`, `Scatter.php:200-215`, `Heatmap.php:213-231`) will silently produce undefined/garbage output rather than a clamped or rejected value:
  - `Heatmap::sample()` (`src/Heatmap/Heatmap.php:266-270`) computes `$t = ($v - $min) / ($max - $min)` then `max(0.0, min(1.0, $t))` — if `$v` is `NAN`, PHP's NAN comparison semantics make the clamp unreliable, which can propagate a NAN into `Color` construction / palette-index math (`$pos = $t * $segments; $idx = min((int) floor($pos), ...)` a few lines below) — `(int) floor(NAN)` is implementation-defined and could yield an out-of-range palette index.
  - `NiceScale::ceiling()` (`src/Chart/NiceScale.php:35-50`) does `(int) $max` on a caller-supplied float with no `is_finite()` check — passing `NAN`/`INF` produces platform-dependent, silently-wrong axis ceilings rather than an exception.
  - `BarChart`/`LineChart`/`Scatter`'s `$max == $min` fallback checks (e.g. `BarChart.php:327`, `LineChart.php:411`, `Scatter.php:214-215`) use loose `==`, which is false for `NAN == NAN`, so a NAN-only dataset skips the "avoid divide-by-zero" branch entirely and divides by `NAN - NAN` = `NAN` downstream.
  - None of the public `new()`/`withData()`/`push()` entry points validate that incoming values are finite floats (e.g. `BarChart::coerceBars()` at `src/BarChart/BarChart.php:437-456` casts blindly with `(float) $value`), so a caller piping untrusted/DB-sourced numeric strings (`"NAN"`, `"INF"`, overflow-large numeric strings) gets no validation before it reaches rendering math.
  - This is a real risk given `CALIBER_LEARNINGS.md`'s note that `candy-query` (a sibling lib) renders DB-sourced numeric data — any future dashboard wiring sugar-charts to live query results should sanitize/clamp values before charting.

### Duplication with candy-metrics / sugar-table

- Minimal overlap. `candy-metrics` (`candy-metrics/src/`) is a telemetry/instrumentation library (Counter/Gauge/Histogram/UpDownCounter/AsyncCounter/AsyncGauge + Registry + pluggable Backends, e.g. `PrometheusFileBackend`) — it collects and exports metrics, it does not render charts; no visual-rendering code found there (`grep` for `Chart|Sparkline|Graph|render` in `candy-metrics/src` only matches `PrometheusFileBackend.php`, which is a metrics exporter, not a renderer). A natural (currently unbuilt) integration point would be piping `candy-metrics` histogram/gauge samples into `sugar-charts`' `Sparkline`/`LineChart`, but no such glue exists today, so there's no current duplication.
- `sugar-table` (`sugar-table/src/`) is a data-grid renderer (`Table.php`, `Column.php`, `Row.php`, `Sanitize.php`) — no chart/graph primitives; likewise no functional overlap with sugar-charts beyond both producing terminal text output.

### Documentation gaps

- `examples/README.md` table (`examples/README.md:9-17`) omits `animated_line.php` entirely.
- `README.md`'s "Status" table (lines 203-216) is stale in at least one place: it says LineChart dataset styling is "pending" (line 210) while the code already implements `datasetPoints` per-series rune overrides (`LineChart.php:36-45`, `443-445`).
- No documented guidance on numeric-input validation/sanitization for untrusted data sources (ties to the Security section above) — other libs in the monorepo (e.g. `sugar-table`'s `Sanitize.php`) have an explicit sanitize step; sugar-charts has none and doesn't call it out as a caller responsibility anywhere in `README.md`.
- `README.md`'s Components table documents `Heatmap::withLegend()` and `BarChart`/`LineChart`/`Scatter` `withLegend()` as if uniform, but they are two different concepts (colour-scale gradient legend vs. series legend) — not called out, which could confuse consumers expecting a `Legend::new([...])`-style series legend on `Heatmap`.

---

## sugar-crumbs

### Dual candy-mouse / candy-zone import — confirmed and expanded

`sugar-crumbs/src/Breadcrumb.php:8-10` imports all three:
```
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\Scanner;
use SugarCraft\Zone\Manager;
```

- `SugarCraft\Zone\Manager` is used in exactly one place: the `@deprecated` method
  `withZoneManager(?Manager $manager)` (Breadcrumb.php:101-110). It only type-hints the
  parameter and, if non-null, allocates a `Scanner` (`$clone->scanner = $clone->scanner ?? Scanner::new()`) —
  it never actually calls any `Manager` method (`mark()`, `scan()`, `anyInBoundsAndUpdate()`, etc.). The
  `Manager` instance passed in is discarded/unused beyond the null-check.
- `Breadcrumb.php:146` also returns `\SugarCraft\Mouse\Zone` (fully-qualified, not imported) from `hit()`.
- All *real* hit-testing/zone-marking work is done through `candy-mouse`'s `Mark` (line 55, 259-266,
  `wrapAllCrumbs()`) and `Scanner` (lines 9, 52, 105, 120-138, 146-149).
- `NavStack.php:22` references `\SugarCraft\Zone\MsgZoneInBounds` only inside a docblock comment
  ("step 10.21" — described as future wiring), not in any executable code.
- `tests/BreadcrumbTest.php:9` imports `SugarCraft\Zone\Manager` solely to exercise the deprecated
  back-compat shim (`testWithZoneManagerBackCompatDoesNotThrow`, `testWithZoneManagerAcceptsNull`,
  `testWithZoneManagerReturnsNewInstance`, `testWithZoneManagerEnablesZoneMarkers` — lines 118-235).
- `CALIBER_LEARNINGS.md:7-32` documents the OLD `candy-zone`-based design as if it were current
  ("via `Breadcrumb::withZoneManager(?Manager)`... `Manager::mark()`... `Manager::scan()`") — this is
  now stale/misleading; the actual implementation self-contains via `candy-mouse`'s `Scanner`, and the
  learnings doc's own closing section ("Mouse hit-testing... self-contained via candy-mouse") contradicts
  its own opening section.
- `composer.json:28-39,78-80` requires BOTH `sugarcraft/candy-mouse` and `sugarcraft/candy-zone` as
  hard `require` deps with matching path-repos, purely to keep the deprecated `withZoneManager()`
  method's type-hint alive.

**Could candy-zone be dropped?** Yes. `withZoneManager()` never calls any `Manager` API — it just
null-checks. The dependency could be removed by either (a) deleting the deprecated method entirely
(README.md:91,95 already frames it as legacy/back-compat-only), or (b) loosening its signature to
accept `?object`/duck-typed input if back-compat with external callers matters. As written, dropping
`sugarcraft/candy-zone` from `composer.json` require + repositories, removing the `use
SugarCraft\Zone\Manager;` import, and deleting `withZoneManager()` (plus its 4 tests) would have zero
effect on any currently-exercised behavior — confirmed by grep: `Zone\` appears nowhere else in
`src/` or `tests/` except the docblock comment in NavStack.php:22.

### Missing/incomplete functionality

- No per-segment ellipsis/truncation. `Breadcrumb::truncate()` (Breadcrumb.php:226-244) always keeps
  the *last* title in full regardless of its own width (`$out = [\end($titles)]` unconditionally,
  line 229) — if a single crumb title alone exceeds `maxWidth`, the rendered output silently exceeds
  `maxWidth` with no truncator applied to that individual segment. Upstream bubbleo has the same
  segment-level limitation, but it's worth flagging since `setMaxWidth()` presents itself as a hard cap.
- No clickable-crumb *navigation* wiring: `hit()` (Breadcrumb.php:146-149) returns a `Zone` for the
  clicked crumb, but nothing in `sugar-crumbs` maps a hit zone back to `NavStack::popTo()`. The
  NavStack.php:19-25 docblock explicitly defers this ("the actual wiring is implemented in step 10.21")
  but no such wiring exists anywhere in `src/` — grep for `popTo` outside NavStack.php/tests shows no
  caller. This is the single most-advertised feature (clickable breadcrumbs) and it stops one step
  short of being usable without consumer code re-deriving the `crumb-N` → `popTo(N)` mapping manually.
- `Escape` (src/Escape.php) hardcodes `' > '` as the separator to escape (line 12), but `Breadcrumb`'s
  default separator is `' › '` (Breadcrumb.php:44) — confirmed as a known gap by the test comment in
  `tests/BreadcrumbTest.php:270-273` ("Escape::title() uses hardcoded separator ' > ', different from
  Breadcrumb's default ' › ', so this is NOT auto-applied"). `Escape` is never called from
  `Breadcrumb::render()`/`doRender()` at all — it's a standalone, disconnected utility that a caller
  must remember to invoke manually before pushing titles that might contain a literal `' > '`.
- `NavigationItem::data` is typed `mixed` with no validation; `NavStack::filter()` (NavStack.php:236-238)
  casts it with `(string) $item->data`, which throws `\Error` (uncatchable `TypeError`-adjacent) if
  `$data` is an array/object without `__toString()` — e.g. the very common pattern shown in
  `examples/basic.php:20-21` (`push('Settings', ['user_id' => 42])`) would crash `filter()` if called.
  No test exercises `filter()` against array-valued `data` (all filter tests in NavStackTest.php use
  string data, lines 216-224).

### Test coverage gaps

- The `filter()`-with-array-`data` crash path above is untested and unhandled.
- `tests/BreadcrumbTest.php:268-291` (`testTruncationWithScannerZoneCountMatchesVisibleCrumbs`) is a
  placeholder — it ends with `$this->assertTrue(true); // placeholder — actual zone counting done via
  integration` (line 290), i.e. the test asserts nothing about the regression it claims to guard
  ("zones should not exceed the number of items that fit"). This is dead-weight coverage for exactly
  the scanner/truncation interaction the comment says needs testing.
- No test for `Breadcrumb::setMaxWidth()` combined with a single overlong title exceeding `maxWidth`
  by itself (the truncate() gap noted above).
- No test wiring `hit()`'s returned `Zone` back to `NavStack::popTo()` — i.e. no end-to-end
  "click crumb 1 → navigate to that level" test, consistent with the missing wiring noted above.
- `Escape::title()`/`unescape()` are tested in isolation (tests/EscapeTest.php) but never in
  combination with `Breadcrumb::render()` — there's no test proving (or disproving) that a title
  containing the literal `Breadcrumb::separator` string round-trips correctly through
  `Escape::title()` + render with a *non-default* separator (only the default `' › '` is covered, via
  `testBreadcrumbTitleContainingSeparatorRendersCorrectly`, tests/BreadcrumbTest.php:293-304, which
  tests the render-level list-carry fix, not `Escape`).
- No security/sanitization test: no test feeds ANSI escape sequences or C0 control characters (e.g.
  `\x1b[2K`, `\r`, `\n`) into `NavigationItem`/`NavStack::push()`/`Breadcrumb::render()` to confirm (or
  refute) that terminal injection is possible.

### Security concerns

- **Unsanitized titles rendered to terminal.** `NavStack::view()` (NavStack.php:182-191) and
  `Breadcrumb::render()`/`doRender()` (Breadcrumb.php:156-215) both emit `$item->title` /
  caller-supplied title strings directly into the returned string with no control-character or ANSI
  stripping. `candy-core` ships exactly this kind of guard —
  `candy-core/src/Util/Sanitize::controlChars()` (candy-core/src/Util/Sanitize.php:24-28) strips C0
  control chars (`\x00-\x08\x0b\x0c\x0e-\x1f`) and normalizes `\n\r\t` to spaces "so they cannot inject
  newlines or corrupt the TUI render" — but `sugar-crumbs` never imports or calls it anywhere in
  `src/`. Only `Breadcrumb.php:7` imports `SugarCraft\Core\Util\Width` from candy-core, not `Sanitize`.
  Given `Shell::pushDirectory()` (src/Shell.php:74-84) and `Url::parse()` (src/Url.php:31-42) both feed
  filesystem-path segments / URL-decoded segments straight into `NavigationItem::title` — and
  filenames/URLs are attacker-influenced input in many real deployments — a segment containing a raw
  ESC (`\x1b`) sequence or CR (`\r`) would pass through untouched into `Breadcrumb::render()`'s output
  and could corrupt the terminal display or (depending on the terminal) trigger unintended escape-code
  behavior. `viewHtml()` (NavStack.php:202-225) is the only render path that escapes anything, and it
  only HTML-escapes (`htmlspecialchars`), which does not neutralize ANSI/C0 bytes either.
  Note this is consistent with the project-wide "TUI render invariants" learning (sanitize
  binary/untrusted data before rendering) that other libs already apply via `Sanitize`.

### Documentation gaps

- `README.md` documents `withZoneManager()` (lines 74-95) as a "deprecated back-compat wrapper" but
  never explains that it is functionally a no-op pass-through to `Scanner` and doesn't actually use
  the passed `Manager` — a reader could reasonably assume `Manager`-based zone tracking still works
  through it.
- `README.md` never documents the `Escape` class at all (no mention of `Escape::title()`/`unescape()`
  anywhere in README.md), despite it being public API in `src/Escape.php` — and, per the test-suite
  comment noted above, its separator hardcoding vs. `Breadcrumb`'s configurable separator is a subtle
  footgun that deserves an explicit caveat.
- `README.md` never documents `Url::derive()`/`Url::parse()` (src/Url.php) despite `tests/UrlDerivationTest.php`
  covering 14 cases for it — no Quick Start section demonstrates URL round-tripping.
- `README.md` never documents `Closable` (src/Closable.php) or `NavigationItem::onEnter()`/`onLeave()`
  lifecycle hooks, despite `Closable`/`NavigationItem` being covered by a dedicated test file
  (tests/ClosableTest.php) — no guidance on how/when a consumer would subclass `NavigationItem` to use
  these hooks.
- `README.md` never documents `NavStack::viewHtml()` despite it being a fully-implemented,
  well-tested (tests/NavStackHtmlTest.php, 12 tests) public method with accessibility semantics
  (`aria-current="page"`).
- `CALIBER_LEARNINGS.md` is stale/self-contradictory as detailed in the dual-import section above —
  its headline pattern describes the old `candy-zone`/`Manager::mark()` design as current when the
  code has since moved to a self-contained `candy-mouse` `Scanner`.
- No `.vhs` tape or example demonstrates the mouse-click / `Scanner`/`hit()` feature — both
  `.vhs/basic.tape` and `.vhs/navigation.tape` only run `examples/basic.php` and
  `examples/navigation.php`, neither of which touches `withScanner()`/`scan()`/`hit()` (confirmed:
  `examples/*.php` have no `Scanner`/`Mouse` references at all). The most novel/differentiating
  feature (clickable breadcrumb zones) has zero demo coverage.

### Performance concerns

- `Breadcrumb::truncate()` (Breadcrumb.php:226-244) is O(n²) in the number of titles: each loop
  iteration re-`implode()`s and re-measures (`effectiveWidth()`, which itself calls
  `Width::string()`, a grapheme-aware width scan) an ever-growing candidate string
  (Breadcrumb.php:231-232). For typical breadcrumb depths (a handful of segments) this is
  inconsequential, but nothing in `Breadcrumb`/`NavStack` bounds stack depth — `NavStack::push()`
  (NavStack.php:52-56) and `Shell::pushDirectory()` (Shell.php:74-84) accept unbounded segment counts
  (e.g. a deeply nested filesystem path), so a pathological deep stack combined with `setMaxWidth()`
  would degrade quadratically at render time.
- No caching: `Breadcrumb::render()` recomputes `effectiveWidth()` from scratch on every call even if
  neither the stack nor the separator/maxWidth changed — fine for typical low-frequency breadcrumb
  redraws, but worth noting if used in a hot per-frame TUI render loop.

---

## sugar-crush

### Premise correction (read this first)

`sugar-crush` is **not** a match-3/candy-crush-style puzzle game. It is a PHP port of
[`charmbracelet/crush`](https://github.com/charmbracelet/crush) — a full terminal AI coding-agent
chat shell (multi-provider LLM backends, tool calling, hooks, skills, sub-agents, MCP, SQLite
sessions). "Crush" here is the upstream Charm project name, not the confectionery game; "candy-crush"
was the lib's own working title during development, later renamed/merged into `sugar-crush`
(`sugar-crush/README.md:34`). Consequently the audit categories in the brief that assume match-3
mechanics — special candies/power-ups, cascading-match chain scoring, board-scan complexity, and a
level/goal system — **do not apply to this codebase** and are omitted below. The categories that do
carry over are addressed against what the library actually is.

### Merge-debris audit (candy-crush → sugar-crush)

The merge is clean in every functional/shipping artifact:
- `composer.json` (root): only `sugarcraft/sugar-crush` requires/repositories (`/home/sites/sugarcraft/composer.json:82,367`), no `candy-crush` entry.
- `docs/MATCHUPS.md:81`: single row, `SugarCrush` / `sugar-crush/` / `sugarcraft/sugar-crush` / `SugarCraft\Crush`, no leftover `CandyCrush` row.
- `docs/index.html:155,776-783,1062-1064`, `docs/lib/sugar-crush.html`, `media/icons/sugar-crush.png`, `.github/workflows/vhs.yml:148,225`, `codecov.yml:108-109,356-361`, root `README.md:84,167` — all reference `sugar-crush` only.
- No `candy-crush/` directory, no `.candy-crush-plan/` directory exist in the tree (both fully removed).
- `sugar-crush/README.md:34` explicitly documents the history: "SugarCrush absorbed the former experimental `candy-crush` port."
- `sugar-crush/src/Backend/EngineBackend.php:98` has one intentional historical comment referencing "candy-crush" while explaining a design gap it fixed — appropriate, not debris.

Two stale-but-non-blocking spots:
- `PROJECT_NAMES.md:126` still carries a **`CandyCrush`** row describing it as a live "TUI AI coding assistant" library, unreconciled with the `sugar-crush` rename/merge. This is the one source-of-truth doc (per AGENTS.md) that should have been updated but wasn't.
- Root-level planning docs `crush_ai.md`, `crush_ai_prompt.md`, `open_crush.md`, `video_prompt.md`, and `docs/repo_map_prompt.md` still refer throughout to "CandyCrush"/`candy-crush`/`bin/candy-crush`. Per project convention these are historical planning artifacts (never retroactively updated), so this is informational only, not a defect.

### Documentation gaps

- `PROJECT_NAMES.md:126` (see above) — should be updated to reflect the `SugarCrush` name/merge rather than describing a separate `CandyCrush` entity.
- `sugar-crush/CALIBER_LEARNINGS.md` reads as though the game/data-lister precursor project ("filter/sort/goto/select tools", "ToolCall/ToolResult wire format") is still the primary shape of the lib — it documents `ToolRegistry` built-ins (`filter`, `sort`, `goto`, `select`, `quit`) that look like leftovers from an earlier CLI-lister incarnation and aren't mentioned anywhere in the current README's capability list. Worth confirming whether that `ToolRegistry`/slash-command layer is still live user-facing surface or dead code inherited from the pre-merge codebase.
- No dedicated security-model doc: the README documents hooks/tools but nowhere calls out that `EngineBackend` without an explicit `withHooks()` call runs the `Bash`/`Edit` tools **completely unguarded** (see Security section below). This is a footgun worth one paragraph in README "The agent loop" section.

### Security concerns

- **Hooks are opt-in, not on-by-default.** `EngineBackend::run()` falls back to `new HookManager(new HookRegistry())` — an *empty* registry — when no `HookManager` was supplied (`sugar-crush/src/Backend/EngineBackend.php:84`). Only `bin/sugarcrush` remembers to call `$hooks->registerBuiltIns()` before use (`sugar-crush/bin/sugarcrush:63-64`). Any other embedder who constructs `EngineBackend` directly (as shown in the README's own "The agent loop" example, `sugar-crush/README.md:104-111`, which *does* remember `registerBuiltIns()`) must remember to wire hooks manually — the library has no safe-by-default posture, and a caller who omits `withHooks()` gets unrestricted `Bash`/`Edit`/`Write` execution with zero interception.
- **`ConfirmRemoveHook` regex is easily bypassed** (`sugar-crush/src/Hooks/BuiltIn/ConfirmRemoveHook.php:33`): `/\brm\b[^\n]*\s-[a-z]*[rf]/i` only catches short-form flags separated by whitespace from `rm` (e.g. `rm -rf`). It does not catch long-form flags (`rm --recursive --force`), flag bundling without a leading space quirk, indirection (`x=rf; rm -$x`), or non-`rm` destructive equivalents (`find … -delete`, `shred`, `dd`, `> file`, `truncate`). This is a heuristic safety net, not a sandbox — fine as defense-in-depth but should be documented as such rather than implied to be a real guard.
- **`ProtectFilesHook`** (`sugar-crush/src/Hooks/BuiltIn/ProtectFilesHook.php:14-20`) protects `.env`, `composer.json/.lock`, `.git/config`, `config/*.php` from `Bash`/`Edit`/`Write`/`Read`, but the pattern list is a fixed allowlist of "sensitive" filenames — it will not catch project-specific secrets files (`.env.production`, `secrets.yaml`, SSH keys, cloud credential files under `~/.aws`, etc.). Worth exposing as a configurable pattern list rather than a hardcoded const.
- **`Bash` tool has no `PathJail`.** Unlike `Read`/`Edit`/`Glob`/`Grep` (which all route through `PathJail::resolve`/`resolveDir`, `sugar-crush/src/Tools/BuiltIn/{Read,Edit,Glob,Grep}.php`), `Bash` only `cd`s into `$root` before running the command (`sugar-crush/src/Tools/BuiltIn/Bash.php:40-47`) — a `cd`-based jail is not a real boundary; `cd /; cat /etc/passwd` (or any absolute-path command) escapes it trivially. This is inherent to giving an LLM a Bash tool at all, but the asymmetry with the path-jailed file tools is worth calling out — the file tools imply a security boundary that `Bash` doesn't actually provide.
- **Session file has no permission hardening.** `Session::save()` (`sugar-crush/src/Session.php`, `~/.config/sugarcraft-crush/session.json`) creates its directory with `@mkdir($dir, 0755, true)` and writes the file with default `file_put_contents` permissions (subject to umask) — no explicit `chmod 0600`. Session state here (`cwd`/`selected`/`filter`/etc.) is low-sensitivity, so this is a minor hardening gap rather than an active vulnerability, but `SessionStore` (SQLite, `src/Session/SessionStore.php`) persisting actual chat transcripts/tool-call history should be checked for the same gap if it stores API keys or file contents from tool results.
- `PathJail::resolve()` (`sugar-crush/src/Tools/PathJail.php:9-36`) has an edge case: when `$path` is exactly `$rootReal` itself (no trailing content), the final check `!str_starts_with($resolved, $rootReal . '/')` rejects the root directory itself as out-of-jail (off-by-one on the trailing slash). Low severity (fails closed, not open) but could confuse a legitimate "operate on cwd root" call.

### Performance concerns

No board-scan/match-detection concerns apply (not a game). The closest analogues:
- `StreamingDirectoryLister` (`sugar-crush/src/StreamingDirectoryLister.php`) is generator-based and explicitly designed to avoid loading full directory listings into memory — no concern there (documented in `CALIBER_LEARNINGS.md`).
- `Runtime`'s agentic loop (`sugar-crush/src/Runtime.php`, `EngineBackend::run` in `src/Backend/EngineBackend.php`) is bounded by `maxSteps`, so no unbounded-loop risk was found.
- `McpClient`'s response-wait loop polls with `usleep(10000)` for up to 100 attempts (1s ceiling) per `CALIBER_LEARNINGS.md` — acceptable for a TUI but worth knowing it's polling, not event-driven, if MCP round-trips are chained.

### Test coverage gaps

Overall coverage is strong: 1255 tests / 2956 assertions, all passing (`vendor/bin/phpunit` run 2026-07-09). Most "no dedicated `*Test.php`" hits from a naive file-to-test mapping are false positives — covered by consolidated suites (`tests/Tools/BuiltInToolTest.php` for all 6 built-in tools, `tests/Tui/{ComponentTest,TuiComponentTest}.php` for Tui components, `tests/Providers/ProviderRequestResponseTest.php` for the DTO classes, `tests/Messages/MessageTest.php` for the typed message hierarchy). Genuine gaps:
- `src/Tools/PathJail.php` has no dedicated unit test file; its behavior is only exercised indirectly through `ToolSecurityTest.php`'s tool-level tests (`Read`/`Edit`/`Glob`/`Grep` jail checks). The root-equals-path edge case noted above and the `resolve()` vs `resolveDir()` non-existent-parent fallback logic aren't directly asserted.
- `ConfirmRemoveHook`/`ProtectFilesHook` tests (`tests/Hooks/ConfirmRemoveHookTest.php`, `tests/Hooks/ProtectFilesHookTest.php`) should be checked for whether they assert the *bypass* cases identified above (long-form `rm --force`, `find -delete`) — if those tests only assert the happy-path `rm -rf` denial, the bypass gap is untested by design rather than by oversight.
- `src/MCP/McpServer.php` (the abstract/shared server base, distinct from `StdioMcpServer`/`HttpMcpServer` which do have tests) has no direct test coverage.

### Missing .vhs demos

Only one tape exists: `.vhs/chat.tape` → `.vhs/chat.gif`, demonstrating the basic chat loop with a single provider turn (`sugar-crush/.vhs/chat.tape`). Given the breadth of user-facing surface (`AgentsPane`, `SkillsPane`, `ToolsPane`, `FilesPane`, the command palette, provider switching via `ProviderSelectCmd`), there's no demo covering: a tool-call/hook-deny interaction, the agents/skills/tools panes, or the command palette — all of which are called out as headline capabilities in the README. Not a blocker (one tape satisfies the "non-visual libs are exempt" bar is inapplicable since this lib *is* visual and already has one), but the demo surface undersells the multi-pane/agent/skill features.

---

## sugar-dash

Audited: `sugar-dash/src` (350 PHP files), `tests` (226 test files), `README.md` (567 lines), `CALIBER_LEARNINGS.md` (47 entries), `composer.json`, `.vhs/` (extensive tape+gif set).

### 6) FocusManager / Sanitize / dracula() duplication — confirmed with detail

**FocusManager vs candy-focus FocusRing**
- `sugar-dash/src/Layout/FocusManager.php:12-156` — mutable-map-backed (`array<string,bool> $focusMap` + `?string $focusedId`), immutable via clone, methods `focus()/blur()/isFocused()/focusNext()/focusPrevious()/register()/unregister()`, plus disk persistence (`persistState()`/`restoreState()` at lines 130-155, via `SugarCraft\Dash\State\Persistence`).
- `candy-focus/src/FocusRing.php:25-409` explicitly documents itself (docblock line 23) as mirroring "sugar-dash's FocusManager" and is the newer, dependency-free, ordered-list design (`list<string> $ids` + `int $index`) with `next()/previous()/disable()/enable()/register()/unregister()/reorder()` — richer traversal (enabled/disabled skip logic) but **no persistence**.
- Consumer check: `grep -rln FocusManager src/` inside sugar-dash returns only `src/Layout/FocusManager.php` itself — **no other `src/` class consumes it**. It is used only in `examples/boxer.php`, `examples/dashboard-live.php` (the flagship live-dashboard demo, lines 59/75/126/133-134/144/156/215/266), and `tests/Examples/DashboardLiveTest.php:18/200/204`. So it is exercised by example/demo code and demo-tests, but has zero production (`src/`) callers, and there is no dedicated `FocusManagerTest.php` unit test.
- Verdict: adopting `candy-focus\FocusRing` for the traversal/enable-disable logic would deduplicate ~80 lines of parallel logic, but FocusRing lacks the persistence hooks sugar-dash's dashboard-live demo relies on (`persistState`/`restoreState`). A migration would need to either add persistence helpers alongside FocusRing (composed, not baked in — matching FocusRing's "no dependencies" design goal) or keep FocusManager as a thin persistence wrapper around an internal FocusRing. Since FocusManager currently has no `src/` consumers, this is a low-risk, low-urgency swap.

**Sanitize — three genuinely divergent implementations, not just three files**
- `candy-core/src/Util/Sanitize.php:24-28` (`controlChars()`): replaces `\n\r\t` with a space, strips other C0, **preserves ESC/SGR** (keeps ANSI color codes intact) — designed for inline single-line text that still wants color.
- `sugar-table/src/Sanitize.php:29-60` (`value()`): repairs invalid UTF-8 via `iconv(...//IGNORE)`, replaces newlines with a visible `→` glyph (or preserves `\n` in multiline mode), replaces C0/C1 control bytes with a visible middle-dot (`·`) placeholder — **never strips ANSI/SGR** and deliberately keeps a visible marker rather than dropping bytes.
- `sugar-dash/src/Output/Sanitize.php:33-99` (`untrusted()`): strips ALL ANSI escape sequences via `Ansi::strip()` first, then **drops** (not replaces) C0 control bytes except tab/LF, then does a byte-scan to strip only *lone* C1 bytes while preserving valid UTF-8 continuation bytes (lines 64-96) — the most defensive/thorough of the three, but with different semantics (silent drop vs visible replacement, full ANSI strip vs ANSI-preserving).
- These are not accidental copies — each optimizes for a different consumer contract (color-preserving inline text vs table-cell placeholder vs untrusted-external-process output). `sugar-dash/src/Output/Sanitize.php` is actually used in production code: `src/Modules/Weather/WeatherModule.php`, `src/Modules/Generic/GenericModule.php`, `src/Plugin/ExternalModule.php` — i.e., specifically at the boundary where dashboard modules ingest external/untrusted data (weather API responses, generic external plugins). That "single authoritative sink for untrusted module output" framing (docblock lines 13-17) is a legitimate distinct role from candy-core's or sugar-table's use cases; consolidating would need candy-core's `Sanitize` to grow an `untrusted()`-equivalent mode (full ANSI strip + C1-aware stripping) without breaking its color-preserving `controlChars()` callers.
- Recommendation: do not force sugar-dash to drop its own `Sanitize::untrusted()` — it is the right sink for its use case — but consider adding an equivalent method to `candy-core/src/Util/Sanitize.php` (e.g. `untrusted()`) and having `sugar-dash`, and eventually `sugar-table`, delegate to it, to end the byte-scanning-logic triplication (the lone-C1 UTF-8-aware scan in `sugar-dash/src/Output/Sanitize.php:64-96` is intricate and worth having in exactly one place).

**dracula() theme factory**
- `sugar-dash/src/Foundation/Theme.php:59-73` — `Theme::dracula()`, one of many independent `dracula()` factories repo-wide: `candy-sprinkles/src/Theme.php`, `candy-shine/src/Theme.php`, `candy-forms/src/Theme.php`, `candy-freeze/src/Theme.php`, `candy-vt/src/Theme.php`, `candy-kit/src/Theme.php` (≥6 besides sugar-dash's own).
- `sugar-dash/CALIBER_LEARNINGS.md:40` (`[pattern:dual-theme-ssot]`) **already documents this as an intentional, accepted divergence**: sugar-dash's `Theme` has 10 colour slots + helper methods (`bar()/text()/fg()/bg()/color()/highlight()`), while `SugarCraft\Sprinkles\Theme` has 13 slots (adds muted/info/border/separator/cursor) and is readonly-properties-only with no helpers. The note states "Both are canonical for their lib" — i.e. a prior audit already reached the same conclusion and recorded it, rather than treating it as an oversight.
- Additional note not previously flagged: `sugar-dash/src/Foundation/Theme.php`'s `withName()/withForeground()/withBackground()/withPrimary()` (lines 273-344) hand-roll `new self(...)` reconstruction instead of the repo-standard `mutate()` helper (`candy-core/src/Concerns/Mutable.php`, cited in AGENTS.md as the canonical immutable-fluent pattern). This is a convention gap, not a duplication bug — every other field lacks a `withX()` at all (no `withSecondary/withAccent/withError/withWarning/withSuccess/withHighlight`), so the wither surface is also incomplete relative to its 9 color slots.
- Verdict: given the existing, deliberate `CALIBER_LEARNINGS.md` entry, sugar-dash should **not** adopt `candy-sprinkles\Theme` wholesale (different slot counts, different consumer contracts — `$theme->bar()/$theme->text()` return sugar-dash `Bar`/`Text` components). Recommend leaving as-is; if anything, standardize the `dracula()`/`oneDark()`/`githubDark()` hex palettes across the ≥7 copies via a shared constants file so the *colors* (not the API) stay in sync — currently each lib could drift its Dracula hex values independently with no shared source of truth.

### 1) Missing/incomplete functionality
- **Widget grid layout persistence**: NOT missing — `SugarCraft\Dash\State\Persistence` (`src/State/Persistence.php`, atomic tmp+rename save/load, tested in `tests/State/PersistenceTest.php`) is wired into `FocusManager::persistState/restoreState` (`src/Layout/FocusManager.php:130-155`), `Boxer::persistState/restoreState` (`src/Layout/Boxer/Boxer.php:258,273`), and `StackedGrid::persistState/restoreState` (`src/Layout/Grid/StackedGrid.php:296,308`) — collapsed-panel/focus state does survive restart.
- **Drag-to-resize panels**: confirmed missing. `grep -rn "drag\|resize"` across `src/Layout/` and `src/Components/` returns nothing panel-resize-related (only `src/Events/ResizeEvent.php`, which is a terminal-window resize event, not a mouse-driven panel resize). `src/Events/MouseEvent.php` exists but `grep -rn "MouseEvent" src/` outside its own definition file returns **zero hits** — it is defined but never consumed anywhere in `src/`, so there is no mouse-event wiring at all to build drag-resize on top of. README.md has no mention of drag-to-resize as a feature or a known gap.

### 2) Performance concerns
- **Full-dashboard re-render vs panel-level diffing**: `examples/dashboard-live.php:332-363` (the reference live-dashboard `Model::view()`) rebuilds the entire `Boxer` tree and re-renders every module's `view()` on every single `Msg`, even though `msgToAddress()` (lines 321-329) already knows which single module address a given Msg targets (e.g. a `ClockTickMsg` every second re-renders the Weather and System panels too, not just Clock). The code comment at line 346 states this is deliberate ("We recreate the boxer each frame to pick up module state changes"), i.e. no memoization of unchanged panel output.
- By contrast, `src/Plot/Chart/Chart.php` (lines 45, 63, 151-206) *does* do proper frame-diffing internally (`Buffer::diff($previousFrame)` against a stored `previousFrame`) for its own canvas redraws — so the diffing primitive exists in the codebase, it's just not applied at the dashboard/panel-composition layer. `CALIBER_LEARNINGS.md:47` documents the buffer-diff-boundary invariant (reset diff state on resize/cursor-loss) for this pattern, confirming it's a known, deliberately-scoped technique that could be extended to panel-level composition but hasn't been.

### 3) Test coverage gaps
- 226 test files vs 350 `src/` files. A scripted pairwise check (`<Class>Test.php` expected at the mirrored `tests/` path) turned up ~140 `src/` files with no direct test file — the large majority are enums, small value-object DTOs (`AreaPoint`, `TreemapLeaf`, `KeyMeta`, `SortDirection`, etc.) that are plausibly exercised indirectly via their owning component's test, so this is not 140 genuine gaps.
- Specific gaps worth flagging directly:
  - `src/Layout/FocusManager.php` — **no dedicated `FocusManagerTest.php`**; only indirectly exercised via `tests/Examples/DashboardLiveTest.php`. Its non-trivial `persistState()`/`restoreState()` round-trip (lines 130-155) and `focusNext()`/`focusPrevious()` wraparound logic (lines 63-99) have no isolated unit coverage.
  - `src/Events/MouseEvent.php`, `src/Events/KeyEvent.php`, `src/Events/PasteEvent.php`, `src/Events/FocusEvent.php`, `src/Events/EventDispatcher.php`, `src/Events/EventHandler.php` — the whole `Events/` namespace has no test files at all, consistent with the "defined but never consumed" finding on `MouseEvent`.
  - `src/Layout/Boxer/Boxer.php`, `src/Layout/Grid/StackedGrid.php` — the two other `persistState/restoreState` consumers likewise have no direct test files exercising their persistence round-trip (only `PersistenceTest.php` tests the underlying `Persistence` class in isolation, not the callers' save/restore data shape).

### 4) Missing .vhs/*.tape demos
- None found. `.vhs/` contains ~140+ tape/gif pairs covering essentially every `examples/*.php` file (kebab-case naming reconciles cleanly: `aSCIIBanner.php`→`ascii-banner.tape`, `hStack.php`→`hstack.tape`, `qRCode.php`→`qr-code.tape`, etc.). `generate-all.php`/`run-all.php` are batch utility scripts, not demos, and correctly have no tape.

### 5) Security concerns
- None beyond the Sanitize analysis above. `src/Output/Sanitize.php` is correctly used at the untrusted-data boundary (`WeatherModule`, `GenericModule`, `ExternalModule` — external HTTP/plugin data), which is the right place for the most defensive of the three sanitizers.
- `src/State/Persistence.php:22-52` uses atomic tmp+rename with `LOCK_EX`, `mkdir(...,0755,true)`, and cleans up the temp file on failure — reasonable for a local-file state store; no obvious injection/traversal issue since `$path` is caller-supplied (not derived from untrusted input in current call sites).

### 7) Documentation gaps
- `media/icons/sugar-dash.png` is **missing** (`ls` returns nothing at `/home/sites/sugarcraft/media/icons/sugar-dash.png`), confirming the docs-audit finding.
- `README.md` (567 lines) documents `ResizeEvent` (line 395) and `Persistence` (line 411) but has no mention of drag-to-resize as either present or a known limitation, and no mention of the FocusManager/FocusRing or Sanitize/Theme duplication — a future reader has no signal that these are intentional divergences without finding `CALIBER_LEARNINGS.md:38-43`.

---

## sugar-gallery

Scope examined: `sugar-gallery/src/{PosterCard,PosterGrid,Rail}.php`, `tests/*Test.php`, `README.md`, `CALIBER_LEARNINGS.md`, `composer.json`; cross-checked `MATCHUPS.md`, `docs/index.html`, `docs/lib/sugar-gallery.html`, `media/icons/`, `.github/workflows/vhs.yml`, `codecov.yml`.

### 1) Missing/incomplete functionality

- **Grid layout itself is present and solid** — `PosterGrid` (`sugar-gallery/src/PosterGrid.php:32-413`) implements sparse, absolute-indexed, virtualized 2-D layout with `columns()`/`visibleRows()`/`totalRows()` (lines 225-243) and full keyboard nav: `left/right/up/down/pageUp/pageDown/home/end/moveTo` (lines 135-194). No gap here.
- **No lazy-loading is implemented inside the lib — by design, but the "owner-driven paging" contract is documentation-only, not enforced.** `visibleRange()` (`PosterGrid.php:206-221`) hands back the window but nothing in the lib debounces or dedupes repeated fetch requests; every caller must independently track `$lastFetchedStart` (README.md:44-49 shows this as caller responsibility with a manual `!==` check). There's no helper (e.g. a `needsFetch(array $lastRange): bool`) so every consumer re-implements the same comparison — a duplication/foot-gun risk, not a hard bug.
- **No debounce/coalescing for rapid navigation.** Holding down `right()`/`down()` will make the owner re-derive `visibleRange()` on every keystroke; nothing in `PosterGrid` throttles or marks a range "in flight," so a naive caller can fire overlapping fetches for the same page. Not addressed in tests either.
- **Rail has no virtualization at all.** `Rail::render()` (`sugar-gallery/src/Rail.php:119-154`) always holds the full `$cards` array in memory (`array $cards` constructor param, `Rail.php:22`) — reasonable for a "row" carousel of modest size, but there is no documented upper bound, and nothing stops a caller from handing it thousands of cards (it would still only *render* `perRow()` of them, so display cost is fine, but the full card list — including any attached `poster`/`posterImage` ANSI/pixel bytes — sits resident).
- **No search/filter/jump-by-letter primitive**, despite the CALIBER_LEARNINGS.md (`sugar-gallery/CALIBER_LEARNINGS.md:16`) and README (`README.md:36`) both citing "an A–Z letter offset" as the intended use of `moveTo()`. The letter→index mapping itself is left entirely to the caller — arguably fine as a design choice, but worth flagging since it's called out twice as a first-class use case yet has zero test coverage of that flow (only literal `moveTo(2600)`/`moveTo(26)` in tests, `tests/PosterGridTest.php:146,151`).
- **No eviction/pruning of the sparse `$items` map.** `withItems()`/`withItem()` (`PosterGrid.php:97-119`) only ever add entries; there is no `withoutItem()`/eviction API, so a long session that pages through a 50,000-item library monotonically grows `$this->items` (and therefore memory, since cards may carry poster bytes) with no LRU/cap mechanism. For a truly large gallery this defeats part of the "virtualized" selling point in the class doc-comment (`PosterGrid.php:12-18`, which advertises "large collections" but only virtualizes rendering, not memory).

### 2) Performance concerns

- **Poster/image bytes accumulate unbounded in the sparse map** (see above) — `PosterGrid::$items` (`PosterGrid.php:35`) never shrinks. For sixel/kitty overlay bytes (`PosterCard::$posterImage`, `PosterCard.php:45`) this could be sizable per cell; thousands of loaded cells over a long session is a real leak vector for a gallery that markets itself as scaling to 50,000 items (`PosterGrid.php:18`, `README.md:21`).
- **`render()` reprocesses every visible poster's lines/width on each call** (`PosterGrid::box()`, `PosterGrid.php:338-361`; `PosterCard::posterRows()`/`fitWidth()`, `PosterCard.php:180-211`) — `Width::string()` is called per line per cell per render, with no caching. For a `view()`-per-frame TUI loop this is redone every tick even when nothing changed (no memoization keyed on card identity). Not necessarily a problem at typical grid sizes (a few dozen visible cells) but there's no test/benchmark asserting render cost stays flat as `posterHeight`/`cardWidth` grow.
- **No decode/decompression cost in this lib at all** (see security section — by design it never touches raw image bytes), so no CPU/memory bomb risk from decoding here; that risk is entirely deferred to whatever renderer (e.g. candy-mosaic) the caller uses to produce `poster`/`posterImage` bytes before handing them to `PosterCard`.

### 3) Test coverage gaps

- Overall coverage looks strong at the unit level (763 test lines across 3 files for ~3 source files) but some gaps:
  - **No test drives the full owner-driven paging loop end-to-end** — i.e. no test simulates "move cursor → read `visibleRange()` → `withItems()` the new window → repeat," which is the lib's headline pattern (README.md:39-50). Existing tests (`tests/PosterGridTest.php:155-181`) check `visibleRange()` and `withItems()` in isolation but never together in sequence.
  - **No test for `Rail::withCards()` when the new list is empty *and* old cursor was non-zero combined with scroll > 0** beyond `testWithCardsEmptyResetsCursor` (`tests/RailTest.php:143-151`), which is covered — but there's no test for `withCards()` growing the list (only shrinking, `testWithCardsClampsCursorAndScroll`, `tests/RailTest.php:52-59`).
  - **No memory/eviction test** — consistent with there being no eviction API to test.
  - **No test exercises `PosterGrid::render()` with a `ZoneManager` at the *last partial row*** to confirm blank trailing cells are correctly excluded from zone marking (CALIBER_LEARNINGS.md:33 explicitly states this invariant — "Mark only real cells... not the blank trailing fillers" — but `testRenderMarksZonesForMouseHitTesting` (`tests/PosterGridTest.php:244-252`) uses a grid of 8 items with 4 columns (an exact multiple, no partial row), so the "don't mark blank fillers" claim is asserted in prose but not actually verified by a test with a partial last row.
  - **No fuzz/property test for extreme `cardWidth`/`posterHeight` combinations** (e.g. `cardWidth=4` minimum with very long CJK titles) — `testWideCjkTitleIsMeasuredInCellsNotChars` (`tests/PosterCardTest.php:89-99`) only checks a mid-size width.
  - **No test for `PosterCard::stripC0()` against a title containing a full CSI sequence with parameters** (e.g. `\x1b[31;1m`) — only a bare `\e[2J` + bell is tested (`tests/PosterCardTest.php:253-262`); since `stripC0` only strips C0 bytes and not the printable `[31;1m` portion of a malformed/partial ANSI sequence, a title like `"\x1b[31mRed"` would have its ESC stripped but leave the literal text `[31mRed` visible — not a security bug, but a cosmetic gap worth a regression test.

### 4) Missing .vhs/*.tape demos

- **Confirmed: no `.vhs/` directory exists in `sugar-gallery/`** (`ls sugar-gallery/.vhs` → no such file or directory). There is also no `sugar-gallery` entry in `.github/workflows/vhs.yml`'s hand-maintained `all=(...)` matrix (`grep -n "sugar-gallery" .github/workflows/vhs.yml` → no match). Per AGENTS.md this is required for any visual lib; sugar-gallery renders poster grids/rails in-terminal so it is not one of the exempt non-visual libs (FFI/codec/candy-pty-style). This is a real gap, not a false positive.

### 5) Security concerns

- **No path traversal risk in this lib** — `sugar-gallery` never accepts a filesystem path or directory listing anywhere in its public API. `PosterCard` only accepts `posterUrl` (an opaque string never opened/read by this code, `PosterCard.php:41`) and pre-rendered `poster`/`posterImage` byte strings supplied by the caller. There is no `readdir`/`glob`/`fopen` call anywhere in `src/`. This category does not apply to sugar-gallery itself — any traversal risk would live in whatever component resolves `posterUrl` to a file (out of scope for this lib).
- **No decompression-bomb risk in this lib** for the same reason — it never decodes an image; `posterImage` bytes are opaque and only ever measured by byte length when building the marker block (`PosterCard::imageRows()`, `PosterCard.php:154-164`), never parsed/inflated. This is consistent with the explicit design tenet in CALIBER_LEARNINGS.md:5-8 ("Renderer-agnostic by design ... never decodes images ... don't add an image decoder dependency").
- **Plain-title sanitization is present and reasonably solid**: `PosterCard::stripC0()` (`PosterCard.php:240-246`) strips C0 controls including ESC/BEL from untrusted plain titles before they reach the terminal, with a test (`tests/PosterCardTest.php:253-262`). This is a good defensive measure against a DB-sourced title smuggling terminal-control sequences.
- **`styledTitle` intentionally bypasses sanitization** — `PosterCard.php:134-136` routes `styledTitle` through `Width::truncateAnsi()` unchanged, explicitly NOT stripping C0/ESC (doc comment `PosterCard.php:131-133` states this is "pre-styled ANSI... preserved"). This is a deliberate contract (styled titles are assumed to be built by the app, e.g. fuzzy-match highlighting, not raw untrusted user text) but it is a real trust boundary: if any caller ever threads unsanitized user input into `withStyledTitle()` instead of `withProgress`/plain `title`, arbitrary ANSI/terminal-control injection becomes possible (cursor moves, screen clears, OSC sequences, terminal title spoofing). Nothing in the code or docs enforces or checks that `styledTitle` inputs are actually pre-vetted — this is worth a doc-comment/README callout warning against passing raw user text there, since the plain-title path clearly signals it's the "safe" one.
- **`posterUrl` is stored and exposed via the public readonly property** (`PosterCard.php:41`) but never validated as a URL/scheme — again out of this lib's direct rendering path, but if any downstream code (not in this lib) blindly shells out to fetch `posterUrl` this could be an SSRF vector at the integration layer, not within sugar-gallery.

### 6) Duplication with candy-mosaic/candy-flip

- **No duplication found — proper reuse via explicit non-reuse (renderer-agnostic contract).** `sugar-gallery` deliberately does NOT depend on or reimplement `candy-mosaic`/`candy-flip` image-rendering logic. `composer.json` (`sugar-gallery/composer.json:29-34`) requires only `candy-core`, `candy-sprinkles`, `candy-zone` — no `candy-mosaic`/`candy-flip`/`ext-gd` dependency. `PosterCard` holds only *already-rendered* poster bytes (doc comment `PosterCard.php:13-17`: "this widget pulls in no image decoder"), confirmed again in README.md:5-8 and CALIBER_LEARNINGS.md:5-8 ("keeps sugar-gallery off candy-mosaic (and ext-gd)... don't add an image decoder dependency"). This is the correct layering — sugar-gallery is a layout/presentation widget, candy-mosaic/candy-flip would be the thing that *produces* the ANSI/pixel bytes fed into `withPoster()`/`withImage()`. No functional overlap or reimplementation detected.

### 7) Documentation gaps

- **Confirmed: `media/icons/sugar-gallery.png` is missing** (`ls media/icons/sugar-gallery.png` → no such file). `docs/img/icons/sugar-gallery.png` is also absent (`ls docs/img/icons/sugar-gallery.png` → no such file), even though `docs/index.html:140,448` both reference `img/icons/sugar-gallery.png` (with an `onerror` fallback that hides the broken image, masking the gap visually on the public site but the asset is genuinely absent from the repo).
- Everything else documentation-wise is in good shape: `MATCHUPS.md`/`PROJECT_NAMES.md:115` has the naming-rulebook entry, `docs/lib/sugar-gallery.html` exists, `codecov.yml:135-136,410-415` has both the flag and component wired up, README.md and CALIBER_LEARNINGS.md are thorough and accurate against the source.

---

## sugar-glow

### 1. Missing/incomplete functionality vs upstream glow scope

- **No directory/file browser.** Upstream `glow` run with no args (or a directory arg) opens a fuzzy-searchable file-picker listing markdown files in the cwd/repo. `sugar-glow/src/RenderCommand.php:44` only accepts a single optional `file` argument or stdin — there is no directory-walk/listing mode at all. `sugar-glow/src/Application.php:13-21` registers exactly one command with no browse mode.
- **No TOC/outline navigation.** Upstream glow's pager has a `Ctrl+T`/table-of-contents jump-to-heading view. `sugar-glow/src/GlowModel.php:41-55` only forwards keys to `Viewport`; there is no heading index or jump command anywhere in `src/`.
- **No in-document search.** Upstream glow pager supports `/` search with next/prev match. No search logic exists in `GlowModel.php` or `Pager.php` — `Pager.php` is unrelated (a streaming line-chunk reader, not a search feature — see naming/duplication note below).
- **No config file support.** Upstream `glow` reads `~/.config/glow/glow.yml` (default style, width, etc.). `RenderCommand.php:41-51` only exposes CLI flags; there is no `getenv('XDG_CONFIG_HOME')`/`$HOME/.config/glow` lookup anywhere (confirmed via grep — zero hits for `XDG`/`.config`/`glow.yml`).
- **No "stash" (local bookmarks) feature.** Upstream glow's `glow stash`/local marks for favoriting files has no analogue here — no persistence of bookmarked paths, no `stash` command registered in `Application.php`.
- **No remote/stashed-source support** (glow can pull from GitHub/GitLab/stashed news sources) — out of scope for a CLI pager port, but worth noting as upstream parity gap if that's ever expected.

### 2. Performance concerns

- `RenderCommand::execute()` (`src/RenderCommand.php:88-104`) renders the **entire** document to a single string via `$renderer->render($raw)` before ever entering the pager, and `GlowModel::fromContent()` (`src/GlowModel.php:22-26`) hands the whole rendered string to `Viewport::setContent()`. For very large markdown files this means full-document rendering + full-document buffering in the `Viewport` model on every keystroke-driven `update()` (each `update()` call at `GlowModel.php:53` reconstructs a new `Viewport` via `$this->viewport->update($msg)`, but the underlying content string itself is never re-sliced/windowed — it's carried whole through every immutable copy). There's no viewport-level windowing of the *rendering* step itself (i.e., no lazy per-page markdown rendering) — the full-document ANSI render happens once up front, which is correct for correctness but means a huge file pays full render cost even if the user only ever looks at the first screen.
- `FileWatcher::watch()` (`src/FileWatcher.php:50-74`) is a busy-poll loop (`usleep($intervalMs*1000)` every iteration, default 500ms) — fine for a CLI watch use case, but it's a blocking generator (documented at `FileWatcher.php:41-43`) with no integration point shown into the ReactPHP loop that the rest of the monorepo standardizes on (per AGENTS.md, this repo is ReactPHP-based) — no example of non-blocking consumption exists in `src/` or `tests/FileWatcherTest.php`.

### 3. Test coverage gaps

- `src/Highlighter/Highlighter.php`, `src/Highlighter/ChromaJsonHighlighter.php`, and `src/GlamourTheme.php` all have dedicated test files (`tests/Highlighter/HighlighterTest.php`, `tests/Highlighter/ChromaJsonHighlighterTest.php`, `tests/GlamourThemeTest.php`) — but grep confirms none of these three classes is referenced anywhere in `src/RenderCommand.php`, `src/Application.php`, or `src/GlowModel.php`. The tests exercise dead code that is never reached by the CLI (see §6) — coverage numbers look healthy but don't correspond to exercised production paths.
- No test drives `Application` + `RenderCommand` through the pager path with a real `Program`/TTY loop end-to-end (i.e. no scripted-KeyMsg integration test proving `q`/`Esc`/`Ctrl+C` actually exit via the full `Program::run()` — `tests/GlowModelTest.php` tests `GlowModel::update()` directly, and `tests/PagerTest.php` tests the unrelated `Pager` stream reader, but the wiring in `RenderCommand::buildPagerModel` + `Program` construction at `RenderCommand.php:96-103` has no covering test).
- No test for `--theme-config` pointing at a **malformed** JSON file exercising the `Theme::fromJson` failure path from candy-shine (only the unreachable `GlamourTheme::fromJson` failure path is tested, at `tests/GlamourThemeTest.php`).
- No test for large-file / streaming behavior interacting with the pager (`Pager.php`'s chunked generator is tested in isolation in `tests/PagerTest.php` but never proven to be used by `RenderCommand`/`GlowModel` — because it isn't, per grep).
- `FileWatcher::watch()`'s infinite generator is tested for a few iterations in `tests/FileWatcherTest.php`, but there's no test showing it wired into `RenderCommand` (it isn't — `RenderCommand.php` has no `--watch` flag at all, another upstream-parity gap: glow supports live-reload).

### 4. Missing .vhs/*.tape demos

- Two tapes exist: `.vhs/render.tape` (stdout render) and `.vhs/pager.tape` (fullscreen pager scroll). Given the flag surface in `RenderCommand.configure()` (`src/RenderCommand.php:41-51`), there's no tape demonstrating: `--theme-config` custom JSON theme loading, `--no-hyperlinks`, or the `--width` word-wrap flag distinctly (the README's `examples/render-readme.sh` covers width/theme combos in a shell script, not a recorded demo). Not necessarily required, but the two existing tapes don't showcase the theme-config/hyperlink toggle features that are otherwise front-and-center in the README's flag table.

### 5. Security concerns

- **Path traversal / arbitrary file read is by design and unguarded.** `RenderCommand::loadInput()` (`src/RenderCommand.php:159-172`) does `@file_get_contents($file)` on any path the user supplies with zero canonicalization or containment to a project root — appropriate for a CLI pager (glow is meant to read arbitrary user-specified files) but worth flagging explicitly since there is no directory-browse mode to scope it (see §1) — if a future browse/stash feature is added, it must not silently expand the readable surface via symlink-following glob without validation.
- **`--theme-config` reads and JSON-decodes an arbitrary attacker-supplied path** (`RenderCommand.php:72-76`) via `Theme::fromJson($configPath)` (candy-shine) — no size cap before `file_get_contents`/`json_decode`, so a maliciously large theme file could cause memory exhaustion. Same class of concern applies to the dead `GlamourTheme::fromFile()` (`src/GlamourTheme.php:63-71`) if it's ever wired in.
- **Untrusted markdown rendering**: `ChromaJsonHighlighter::highlight()` (`src/Highlighter/ChromaJsonHighlighter.php:110-113`) explicitly strips embedded ESC bytes (`str_replace("\x1b", '', $fullMatch)`) to prevent terminal control-code injection from code-block content — good defensive practice, but this sanitization lives entirely in the **dead/unused** highlighter (§6), so it provides no protection on the actual CLI path. The actual production path (candy-shine's `SyntaxHighlighter`/`Renderer`) needs to be verified independently for the same ESC-stripping guarantee — that verification is out of scope for this lib's audit but is the operative security boundary, not the code audited here.
- No length/size cap on stdin (`RenderCommand::loadInput()` line 170: `stream_get_contents($stream)` slurps the entire stream) — a hostile/huge piped input could exhaust memory before any rendering guard applies. Matches upstream glow's own lack of a cap, but worth noting given "render untrusted markdown" is explicitly in scope for this audit.

### 6. Functionality duplicated with candy-shine (composition audit — FAILED)

This is the most significant finding. `AGENTS.md`/task brief flagged this composition as the thing to verify, and it is **not clean**:

- `src/GlamourTheme.php` reimplements Glamour-style JSON theme loading (`blockPrefix`/`blockSuffix`/`indentToken`/`margin`/`chroma` token→SGR map, `fromJson`/`fromFile`/`resolve()`) — but `candy-shine` already has `Theme::fromJson()` (used directly at `src/RenderCommand.php:76,119-121`) which is the one actually wired into the CLI. Grep across `src/RenderCommand.php`, `src/Application.php`, `src/GlowModel.php` shows **zero references** to `GlamourTheme` — it is fully dead, unused, parallel-implementation code with its own 136-line test file (`tests/GlamourThemeTest.php`).
- `src/Highlighter/Highlighter.php` + `src/Highlighter/ChromaJsonHighlighter.php` + `src/Highlighter/HighlighterInterface.php` reimplement fenced-code-block syntax highlighting via a hand-rolled combined regex (keyword/string/comment/number/function/operator token classes at `ChromaJsonHighlighter.php:49-59`) — but `candy-shine/src/SyntaxHighlighter.php` (used via `candy-shine/src/Renderer.php:634-639`, itself invoked by `RenderCommand.php:85-88` through `new Renderer($theme)`) is the actual, wired-in syntax highlighter for code blocks in rendered markdown. Grep confirms **zero references** to `Highlighter`/`ChromaJsonHighlighter` from `RenderCommand.php`/`Application.php`/`GlowModel.php`.
- Net effect: ~300 lines of `src/` (GlamourTheme.php + all of `Highlighter/`) plus ~330 lines of tests (`tests/GlamourThemeTest.php` + `tests/Highlighter/*`) implement a second, unused markdown-theme/syntax-highlighting stack that duplicates candy-shine's `Theme`/`SyntaxHighlighter` and is never reached by the CLI entry point. This violates the "wraps candy-shine cleanly" expectation from the task brief — sugar-glow does compose candy-shine correctly for the actual render path, but it also ships a whole shadow reimplementation that should either be deleted or genuinely wired in (e.g., as a pluggable highlighter override) with clear justification in `README.md`/`CALIBER_LEARNINGS.md` for why two highlighting stacks coexist. Currently neither document explains this duplication.
- Separately, `src/Pager.php` (chunked-line stream reader, `IteratorAggregate` over `fgets()`) is also unused by `GlowModel`/`RenderCommand` (the pager UI is actually `GlowModel` wrapping `SugarCraft\Bits\Viewport\Viewport`, per `GlowModel.php:7,24`). `Pager.php`'s name collides confusingly with the actual pager (`GlowModel`) and the README's "Pager keys" section — a reader skimming `src/` would reasonably assume `Pager.php` implements the fullscreen pager UI; it does not, and it's dead code as far as CLI wiring goes (only exercised by `tests/PagerTest.php` in isolation).

### 7. Documentation gaps

- `README.md:75-131` documents `GlamourTheme` and `FileWatcher` as "library API" utility classes for integrating sugar-glow's behavior into other projects, but does **not** document `Pager.php` or the `Highlighter/` namespace at all — despite them existing in `src/` with their own test suites. Given §6's finding that these are unused shadow implementations, the omission is arguably correct (don't document dead code) but inconsistent: `GlamourTheme` *is* documented as if it's a supported integration point, reinforcing the confusion about whether it's meant to be used.
- No mention in `README.md` or `CALIBER_LEARNINGS.md` of the upstream-parity gaps in §1 (no directory browse, no TOC, no search, no config file, no stash, no `--watch` despite `FileWatcher` existing in `src/`) — a reader comparing to `charmbracelet/glow` has no signal that these are intentionally out of scope vs. simply not-yet-ported.
- `CALIBER_LEARNINGS.md:8` documents `GlamourTheme::fromJson()`/`::fromFile()` silent-failure behavior as if it's a live gotcha callers need to know about — but per §6 no caller in this repo actually uses it, so the learning entry itself is stale/misleading without a caveat noting the class is currently unwired.
- `FileWatcher` is documented in `README.md:96-114` and has a working generator, but there is no CLI flag (`--watch`) exposing it, and no doc note explaining that it's library-only / not yet CLI-integrated — a reader expecting `sugarglow -p --watch file.md` live-reload (a real glow feature) would be surprised to find no such flag in `RenderCommand::configure()`.

---

## sugar-post

**Scope correction:** sugar-post is NOT an HTTP-client/Postman-style REST TUI. It is a PHP port of `charmbracelet/pop` — an email-sending library/CLI (`bin/pop`) with two transports: `ResendTransport` (HTTPS REST API) and `SmtpTransport` (raw SMTP/TLS). There is no request-builder, no arbitrary-URL fetch, no collections concept, and no TUI model at all (no `Model`/`update()`/`view()` — it's a plain library + CLI script). The audit categories below are reinterpreted for an email sender rather than a generic HTTP client.

### 1. Missing/incomplete functionality

- **SMTP auth mechanisms**: only `AUTH LOGIN` is implemented (`src/SmtpTransport.php:156-164`). No `AUTH PLAIN`, `AUTH CRAM-MD5`, and critically no OAuth2/XOAUTH2 — Gmail and Microsoft 365 have deprecated basic username/password SMTP auth in favor of OAuth2 XOAUTH2 for most consumer/business accounts, so the README's Gmail SMTP example (`README.md:57`) will fail for most real Gmail accounts today.
- **No Bearer/API-key transport abstraction beyond Resend**: `ResendTransport` hardcodes `https://api.resend.com/emails` (`src/ResendTransport.php:39`) with no way to point at another REST-based ESP (SendGrid, Mailgun, Postmark, SES) short of writing a whole new `Transport` implementation — reasonable for scope but worth noting there's no generic "HTTP transport with configurable auth header" building block.
- **No environment/variable substitution in email content** (e.g., templating `{{name}}` placeholders) — out of scope for a `pop` port and not claimed in the README, so not a gap per se.
- **No attachment size/count limits** — `Email::withAttachment()`/`Attachment::fromPath()` (`src/Attachment.php:29-46`) will silently include arbitrarily large files; Resend's API and most SMTP relays enforce ~10-25MB limits, but sugar-post doesn't pre-check size, so failures surface only as an opaque relay error.
- **No send history / delivery status persistence** — `Mailer::send()` (`src/Mailer.php:27-40`) is fire-and-forget; there's no local log/queue of sent messages, no dead-letter retry queue, no bounce/webhook handling for Resend. Given this is a one-shot CLI/library rather than a running service, this may be intentional, but it means retries after a `SmtpTransport` failure are entirely the caller's responsibility (also called out in `CALIBER_LEARNINGS.md:10-14`).
- **STARTTLS is opportunistic-only when not on port 465**: `startTlsIfNeeded()` (`src/SmtpTransport.php:124-144`) only upgrades if the server advertises `STARTTLS` in its EHLO response; there's no "require TLS or fail" flag, so a downgrade attack (a MITM stripping the STARTTLS advertisement from the 250 response) would cause silent plaintext auth if a username/password is also configured. Upstream `pop` may have the same limitation but it's worth flagging as a hardening gap.

### 2. Performance concerns

- **`SmtpTransport::send()` is fully synchronous/blocking** (`src/SmtpTransport.php:63-85`): uses blocking `stream_socket_client`, `fwrite`, `fgets` for the entire SMTP dialogue (connect → EHLO → STARTTLS → AUTH → MAIL FROM → RCPT TO → DATA → QUIT). Despite the codebase being built around ReactPHP (`use React\EventLoop\LoopInterface; use React\EventLoop\Loop;` imported at `src/SmtpTransport.php:10-11` but never actually used anywhere in the file — dead imports), this transport will block the entire event loop for the duration of the TCP handshake + full SMTP conversation if used inside a ReactPHP-based host application (e.g., candy-query or any other SugarCraft app that queues emails). This is explicitly documented as an architectural limitation in the class doc-comment (`src/SmtpTransport.php:57-61`) and in `CALIBER_LEARNINGS.md:10-20`, but the unused `LoopInterface`/`Loop` imports suggest an abandoned async attempt that should either be finished or removed.
- **`ResendTransport::send()` also blocks via `curl_exec()`** (`src/ResendTransport.php:51`) with no async/promise-based option — same event-loop-blocking risk if used from inside a long-running ReactPHP app rather than the one-shot CLI.
- **No timeout on the Resend cURL total-execution boundary against retries**: `CURLOPT_TIMEOUT => 30` (`src/ResendTransport.php:48`) is reasonable, but there's no connect-timeout distinction (`CURLOPT_CONNECTTIMEOUT`), so a hanging DNS resolution or TCP handshake counts against the same 30s budget as the whole request.

### 3. Test coverage gaps

- **No end-to-end socket-level test of `SmtpTransport::send()`**: `tests/SmtpTransportTest.php` only exercises pure/isolated private methods via `ReflectionClass` (`dotStuff`, `buildMimeMessage`) plus constructor/name() smoke tests (lines 16-140). The actual `send()` flow — `connect()`, `helo()`, `startTlsIfNeeded()`, `authenticateIfNeeded()`, `sendMailFrom()`/`sendRcptTo()`/`sendData()`, error paths in `readResponse()` — is never driven against a real or fake socket (e.g., via `stream_socket_server` loopback or a small fake SMTP server). None of the `\RuntimeException` throw sites in `src/SmtpTransport.php` (unexpected response code, connect failure, STARTTLS failure, incomplete write, no response) have a regression test.
- **No test for `AUTH LOGIN` success/failure path** — `authenticateIfNeeded()` (`src/SmtpTransport.php:146-164`) is entirely untested.
- **No test for TLS certificate verification behavior** — `startTlsIfNeeded()`'s `verify_peer`/`verify_peer_name`/`peer_name` options (`src/SmtpTransport.php:131-135`) have no test confirming they're actually set or that a bad cert causes `stream_socket_enable_crypto` to fail cleanly.
- **`CancellationToken` mid-send behavior is untested** beyond the pre-check — the doc-comment itself admits true cancellation isn't possible (`src/SmtpTransport.php:57-61`), so there's nothing to test there, but the pre-check path (`token->isCancelled()` before `connect()`) also has no explicit test in `tests/SmtpTransportTest.php` (may be covered elsewhere — not found in a `grep` of the file).
- **`ResendTransport` network-error and non-2xx paths** (`src/ResendTransport.php:56-65`) — `tests/ResendTransportTest.php` is only 36 lines; worth confirming it mocks `curl_exec` failure and 4xx/5xx responses (not inspected in full, flagging as likely thin given the file size relative to `ResendPayloadTest.php`'s 179 lines, which appears to cover payload-building instead).
- **No fuzz/property test for `Email::sanitizeAddr()`** header-injection edge cases beyond the CRLF check — e.g., NUL bytes, other line-terminator variants (`\r`, `\n` alone are checked at `src/Email.php:101`, which looks correct, but there's no test file confirming e.g. a lone `\r` or Unicode line-separator ` ` is rejected).

### 4. Missing .vhs/*.tape demos

- Only one tape exists: `.vhs/showcase.tape` (rendering `.vhs/showcase.gif`). The `examples/` directory has 5 additional demo scripts — `examples/attachments.php`, `examples/basic.php`, `examples/html-email.php`, `examples/pipeline.php`, `examples/smtp.php` — none of which have a corresponding `.tape` file. Per `AGENTS.md`'s VHS section, non-visual libs are exempt, but sugar-post has a CLI (`bin/pop`) and multiple example scripts that are visual/terminal-output-worthy, so `attachments`, `html-email`, `pipeline`, and `smtp` demos are plausible gaps if the intent is one tape per example (matching the `record-vhs-demo` skill's per-demo pattern).

### 5. Security concerns

- **Header/CRLF injection — well mitigated**: `Email::sanitizeAddr()` and `sanitizeHeader()` (`src/Email.php:95-131`) reject any `\r`/`\n` in from/to/cc/bcc/reply-to/subject before they reach MIME header construction — good defense against classic email-header-injection via user-supplied recipient/subject strings. However, `Email::$body`/`$htmlBody` are NOT run through any sanitization (`src/Email.php:60,63`), and `SmtpTransport::buildBody()` (`src/SmtpTransport.php:279-315`) only normalizes CRLF, it doesn't validate that body content can't smuggle a fake MIME boundary line (`--{boundary}` or `--{boundary}--`) if a user-supplied body happens to contain the exact random boundary string — extremely low practical risk since the boundary is a fresh `random_bytes(16)` hex string per message (`src/SmtpTransport.php:233`), so not exploitable in practice, but worth noting there's no explicit boundary-collision guard.
- **Credentials stored/handled in plaintext, in-memory and via env vars**: `SmtpTransport` takes `$username`/`$password` as plain constructor strings held as private properties for the object's lifetime (`src/SmtpTransport.php:23-24,36-37`); `bin/pop`'s `buildTransport()` reads `POP_SMTP_PASSWORD`/`RESEND_API_KEY` straight from env (`bin/pop:169-177`, `src/ResendTransport.php:22-28`). This is standard practice for a CLI tool (no credential vault integration expected) but there is no secrets-redaction anywhere — if `SmtpTransport::send()`'s catch block ever logged `$e` (it currently doesn't, it just rethrows — `src/SmtpTransport.php:81-84`), or if a caller logs the constructed `Mailer`/`Transport` object, the password could leak. No `__debugInfo()`/redaction guard on `SmtpTransport` to prevent accidental `var_dump`/`print_r` exposure of `$password`.
- **TLS certificate validation is correctly enabled** for STARTTLS: `verify_peer`, `verify_peer_name`, and `peer_name` are explicitly set true/to-host before `stream_socket_enable_crypto()` (`src/SmtpTransport.php:131-135`) — good, this is often gotten wrong (left to PHP defaults or disabled for "convenience"). Implicit TLS (port 465, `STREAM_CLIENT_CONNECT`+`tcp://` then presumably relying on `stream_socket_client` — actually note: for port 465 the code does NOT wrap the socket in TLS at `connect()` time; it relies on `$this->tls` flag inside `startTlsIfNeeded()` (`src/SmtpTransport.php:126`) to call `STARTTLS` command over what should already be an implicit-TLS connection. This looks like a **protocol bug**: on port 465 the connection is supposed to be TLS-wrapped from the first byte (implicit TLS/SMTPS), but `connect()` (`src/SmtpTransport.php:96-122`) always does a plaintext `tcp://` connect and only calls `stream_socket_enable_crypic` at STARTTLS-time — sending `STARTTLS` on an already-implicit-TLS port 465 server is protocol-incorrect and will likely fail against real port-465 servers (Gmail, most ESPs) since they expect the TLS handshake immediately after TCP connect, not a plaintext `EHLO`/`STARTTLS` exchange. This is both a functional bug and a security concern (a port-465 "TLS" send may silently degrade to a broken handshake or, worse, some servers might tolerate a plaintext EHLO before erroring, exposing early plaintext SMTP banner/EHLO data).
- **cURL to Resend has no explicit `CURLOPT_SSL_VERIFYPEER`/`VERIFYHOST` override** (`src/ResendTransport.php:40-49`) — this defaults to verify-on in stock PHP/cURL, so it's fine, but it's implicit rather than explicit/defensive.
- **No SSRF surface**: neither transport fetches a caller-controlled/arbitrary URL — `ResendTransport` always targets the fixed `api.resend.com` host; `SmtpTransport`'s host/port are supplied by the *deploying operator* (env vars/constructor args), not by untrusted end-user input in the library's own code, so classic SSRF (attacker-controlled URL fetched by the server) doesn't apply here. If some other SugarCraft component were to expose `SmtpTransport`'s host/port to end-user input, that would become an SSRF-via-SMTP-relay vector, but that's outside sugar-post's own code.
- **Path traversal on attachments not validated**, by design: README explicitly documents this (`README.md:76`): "`--attach`/`withAttachment()` read arbitrary local paths. The caller is responsible for validating that passed paths are trusted. No path allow-list is enforced by design, matching upstream behavior." This is a reasonable, explicitly-documented trust boundary for a CLI tool, not a silent gap.
- **`Attachment::fromPath()`/`inline()` swallow file-read errors via `@` + `error_reporting` suppression** (`src/Attachment.php:34-36,82-84`) and fall back to `null` content, which then serializes as an empty/zero-byte attachment rather than failing the whole send — a caller could unknowingly ship an email with a missing attachment silently replaced by a 0-byte file (no exception until `Attachment::getContent()` is called for the *inline* case, and even then only if content is null AND path is set — actually `getContent()` at `src/Attachment.php:101-117` does throw when path+content are both unreadable, but `fromPath()`'s constructor bakes in `content: null` up front without deferring, so the failure surfaces later than expected, at MIME-build time rather than attach time).

### 6. Duplication with candy-query

- No meaningful overlap. `candy-query` (`/home/sites/sugarcraft/candy-query/src/`) is a React-based async MySQL/Postgres admin TUI (`Database.php`, `Explain/`, `Schema/`, `SchemaBrowser.php`, `ResultTable.php`, `Terminal/`) — a "hit a remote DB and show results" tool, not "hit a remote HTTP/SMTP service." sugar-post has no query/results-table/schema-browsing concept and candy-query has no email-sending concept. The "both hit a remote service and show results" framing in the task brief doesn't hold for these two specific libraries — there's no shared abstraction to de-duplicate (no shared `Transport`/connection-pool code between them, and none is warranted).

### 7. Documentation gaps

- **README doesn't mention the SMTP AUTH LOGIN-only limitation** — a user following the Gmail SMTP example (`README.md:52-66`) with a real Gmail account/app-password may hit `AUTH LOGIN`-specific failures or find modern Gmail requires OAuth2; worth a caveat note similar to the existing attachment-path caveat (`README.md:76`).
- **No mention of the port-465-implicit-TLS-vs-STARTTLS behavior** described in section 5 above — if the implicit-TLS handling is indeed as coded (STARTTLS command sent even on port 465), the README's claim "TLS is supported on port 465; STARTTLS on 587" (mirrored in the class doc-comment, `src/SmtpTransport.php:17`) is misleading/inaccurate about *how* TLS is established on 465.
- **`CALIBER_LEARNINGS.md`** already documents the sync-vs-async limitation and env-var fallback patterns well (lines 6-31) — no gap there.
- **No architecture note on where retries should live** — given `Mailer::send()` and both transports throw on any failure with no built-in retry, and `CALIBER_LEARNINGS.md:10-14` says a full async rewrite would be needed for `AsyncOps::retry()`, the README's "Architecture" section (`README.md:97-104`) could call out that retry/backoff is the caller's responsibility today.
- **No documented list of Resend-specific limitations** — e.g., Resend has its own attachment size caps and rate limits; sugar-post doesn't document or defend against these (ties to item 1's size-limit gap).

---

## sugar-prompt

### 6) Fuzzy-matching duplication — CONFIRMED RESOLVED (not a live duplicate)

The sibling audit's "fourth possible duplicate" claim is **outdated**. sugar-prompt
no longer contains an independent fuzzy-matching implementation. Every field/form
class and the fuzzy matcher are now thin `class_alias()` re-export shims over
`candy-forms` and `candy-fuzzy`:

- `/home/sites/sugarcraft/sugar-prompt/src/Fuzzy/FuzzyMatcher.php:10` —
  `class_alias(\SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher::class, FuzzyMatcher::class);`
  (candy-fuzzy is canonical; doc-comment at line 7-9 says `@deprecated Use
  SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher instead`).
- `src/Field/Select.php:10`, `src/Field/MultiSelect.php:10`, `src/Field/Confirm.php:10`,
  `src/Field/Input.php:10`, `src/Field/Note.php:10`, `src/Field/Text.php:10`,
  `src/Field/FilePicker.php:10`, `src/Field.php:10`, `src/Form.php:10`,
  `src/Group.php:10`, `src/KeyMap.php:10`, `src/Theme.php:10` — all
  `class_alias()` to the matching `SugarCraft\Forms\*` class. Every one is a
  single-statement 10-line file.
- `src/HasDynamicLabels.php:17-21` and `src/HasHideFunc.php:17-21` are empty stub
  classes (traits cannot be `class_alias`'d in PHP) that exist only so
  `class_exists()` checks pass; the doc-comment explicitly warns "This stub does
  NOT provide the trait's methods."

This convergence is deliberate and tested:
- `tests/AliasResolutionTest.php:23-42` asserts every alias resolves via
  `ReflectionClass::getName()` to its documented canonical target.
- `tests/AliasResolutionTest.php:90-184` (`testAllFormsClassesHavePromptAlias`)
  walks `vendor/sugarcraft/candy-forms/src` by reflection and fails the build if
  a new candy-forms class is added without a corresponding sugar-prompt alias —
  a regression guard against the fragmentation the audit worried about.
- `tests/Fuzzy/FuzzyMatcherTest.php:169-173` (`testAliasResolvesToCandyFuzzyClass`)
  pins the fuzzy alias to `SmithWatermanMatcher`.
- `CALIBER_LEARNINGS.md` line "`[pattern:fuzzy-alias-repoint-with-back-compat]`"
  documents the historical repoint from a since-removed `SugarCraft\Forms\Fuzzy\FuzzyMatcher`
  to `candy-fuzzy`'s `SmithWatermanMatcher`, i.e. the de-duplication already happened.
- `README.md:40-44` explicitly states: "`SugarCraft\Prompt\*` is a maintained
  back-compat facade over `SugarCraft\Forms\*`. New code may use either
  namespace; `SugarCraft\Forms\*` is canonical."

**Overlap with candy-shell**: also verified, and also not a duplicate.
`candy-shell/src/Model/ConfirmModel.php:12,29` wraps `SugarCraft\Forms\Field\Confirm`
directly, and `candy-shell/src/Model/ChooseModel.php:7-8` wraps
`SugarCraft\Forms\ItemList\ItemList` / `StringItem` directly — both consume
candy-forms rather than re-implementing prompt logic.

**Residual, intentional non-duplication**: `tests/AliasResolutionTest.php:130-144`
lists `preExistingDebt` namespaces deliberately NOT aliased because sugar-prompt
ships its **own** implementation — `Spinner\` (`src/Spinner.php`, 233 lines,
pcntl-fork blocking spinner driver, wraps `SugarCraft\Bits\Spinner\Style`, not
present in candy-forms) and `FilePicker\` is aliased (`src/Field/FilePicker.php:10`)
but the comment at line 138 is stale/contradictory — it says "sugar-prompt has
its own FilePicker implementation" while the actual file is a class_alias to
`SugarCraft\Forms\Field\FilePicker`. This is a minor doc/test-comment
inaccuracy, not a functional duplicate.

**Separately, a genuinely distinct fuzzy implementation exists elsewhere**:
`candy-lister/src/FuzzyMatch.php` is a third fuzzy-matching implementation in the
monorepo (candy-fuzzy, candy-lister/FuzzyMatch.php) but it is outside sugar-prompt's
scope — flagging for the cross-lib audit, not as a sugar-prompt defect.

**Verdict**: the "fourth duplicate" claim should be marked resolved/stale in the
audit tracker — sugar-prompt already converged to zero independent fuzzy logic.

### 7) Documentation gaps

- `README.md:207-224` ("For fine-grained control, use `FuzzyMatcher` directly")
  documents a **non-existent API**. It shows:
  ```php
  $score = $matcher->score('js', 'JavaScript'); // 9
  $matches = $matcher->match('py', ['Python', 'PHP', 'Ruby', 'JavaScript']);
  ```
  But the real aliased class `SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher`
  (confirmed via `candy-fuzzy/src/Matcher/SmithWatermanMatcher.php:40,63`) exposes:
  ```php
  public function match(string $query, string $candidate): ?MatchResult
  public function matchAll(string $query, iterable $candidates, ?int $limit = null, int $minScore = 1): array
  ```
  There is no `score()` method on the interface (`candy-fuzzy/src/FuzzyMatcher.php:20`)
  or the implementation. `match()` takes two strings and returns a `?MatchResult`
  object (with a `->score` property, per `tests/Fuzzy/FuzzyMatcherTest.php:27-28`),
  not `(string, array): list<[string,int]>`. A user following the README verbatim
  will get a fatal error / TypeError. This needs a doc fix — either update the
  README example to use `match()`/`matchAll()` correctly, or document that the
  recommended path is `Select::withFuzzySuggestions()` /
  `Input::withFuzzySuggestions()` (confirmed real at `candy-forms/src/Field/Select.php:106`
  and `candy-forms/src/Field/Input.php:216`) and drop the "direct" example
  entirely.
- `tests/AliasResolutionTest.php:138` inline comment ("`FilePicker\` — sugar-prompt
  has its own FilePicker implementation") is inaccurate/stale — `src/Field/FilePicker.php:10`
  is a `class_alias` to `candy-forms`'s FilePicker, not an independent
  implementation. Low-severity, but misleading for future maintainers reading
  the test as documentation.
- No top-level architecture note in `README.md` explaining that `Spinner` (unlike
  every other public class) is the one genuinely independent implementation in
  the package — a reader has to diff file line-counts to discover this.

### 3) Test coverage gaps

- `Spinner.php` (233 lines, the one non-shim class) has platform-dependent
  behavior (pcntl fork vs. Windows/cli-server inline fallback, TTY detection) per
  its own doc-comment (`src/Spinner.php:20-33`); `tests/SpinnerTest.php` exists
  but coverage of the Windows/no-pcntl inline-execution branch and the
  non-TTY-suppressed-glyph branch could not be confirmed without running the
  suite under those specific conditions (single-CI-runner testing constraint,
  not necessarily a real gap — flagging for verification).
- No dedicated test asserts that mutating a candy-forms class does NOT
  desync from the sugar-prompt CALIBER_LEARNINGS "vim keybinding" anti-pattern
  note (`CALIBER_LEARNINGS.md` "`[anti-pattern:vim-keybindings-per-lib]`") — this
  is enforced by convention only, no automated guard exists specific to
  sugar-prompt (candy-forms' own tests would need to cover it).

### 5) Security concerns

- `Spinner.php` forks child processes via `pcntl_fork` (per doc-comment,
  `src/Spinner.php:20-33`) to run an arbitrary user-supplied action callable.
  No sandboxing/timeout is documented at the class level beyond the general
  "communicate results out-of-band" caveat — if the action callable is built
  from untrusted input this is a code-execution surface, though this is
  inherent to the "run arbitrary PHP callable" design and equally true of
  upstream `huh.NewSpinner().Action(fn)`; not a new bug, just worth noting for
  the audit trail.
- `Field/FilePicker.php` is a pure re-export of `candy-forms`'s FilePicker;
  any path-traversal / symlink-following concerns belong to candy-forms, not
  sugar-prompt — recommend the audit track that finding under candy-forms
  instead of duplicating it here.

### 1) Missing/incomplete functionality

None found specific to sugar-prompt — multi-select (`Field/MultiSelect.php`
aliasing `candy-forms`'s `MultiSelect`, tests in `tests/Field/MultiSelectTest.php`
and `tests/Field/MultiSelectVimKeysTest.php`), validation (5 validators documented
and tested under `tests/Validator/`), and default-value display (`Confirm::withLabels`,
`Note`, etc., all via candy-forms) are all present through the alias layer. Any
gaps here are candy-forms' responsibility, already exercised by sugar-prompt's
own `FieldAliasTest.php` / `AliasResolutionTest.php` regression guards.

### 4) Missing .vhs/*.tape demos

`.vhs/` contains tapes+gifs for `select`, `form`, `text`, `confirm`,
`multi-select`, `input`, `themes` (7 tapes). The `examples/` directory has 10
scripts: `burger.php`, `confirm.php`, `hide.php`, `input.php`,
`multi-page-form.php`, `multi-select.php`, `select.php`, `spinner.php`,
`text.php`, `themes.php`. Three examples have **no corresponding VHS demo**:
`burger.php`, `hide.php`, `multi-page-form.php`, and — more notably —
`spinner.php` has no tape despite `Spinner` being the one non-shim, most
"visually distinct" class in the library (animated glyph, fork behavior). Given
`Spinner` is the sole original implementation, its absence from the VHS matrix
is the most worth fixing; `burger.php`/`hide.php`/`multi-page-form.php` are
lower priority since `form.tape` likely already exercises the same
`Form`/`Group` code path.

### 2) Performance concerns

None specific to sugar-prompt beyond what's inherited from candy-forms/candy-fuzzy
(already covered by CALIBER_LEARNINGS' O(c)-space Smith-Waterman note). No
sugar-prompt-specific hot loop exists outside `Spinner`'s sleep-loop animation
driver, which is bounded by design (single fork, single sleep cadence).

---

## sugar-readline

### 1. Missing/incomplete functionality vs GNU readline scope

- **No kill-ring / yank at all.** `Key::CtrlU`/`Key::CtrlK`/`Ctrl+W`/`Alt+D` (sugar-readline/src/TextPrompt.php:286-287,295; sugar-readline/src/Mode/EmacsMode.php:88-90,153-155) delete text but never stash it anywhere retrievable. There is no `Key::Yank`/`Ctrl+Y` constant in `sugar-readline/src/Key.php` and no kill-ring class. In real readline, kill (Ctrl+K/U/W) and yank (Ctrl+Y, then Alt+Y to cycle) are a matched pair — deleting text here is permanently destructive, which is a regression from upstream `erikgeiser/promptkit`-adjacent readline semantics and from GNU readline itself.
- **Vi yank (`yy`/`yiw`/`ya(`) is a documented no-op.** `sugar-readline/src/Mode/ViMode.php:335-337` (`elseif ($motion === 'y' && $key === 'y') { // yy — yank line (stored in internal buffer, not yet exposed); stay in normal mode }`) and `VimAction::YankTextObject` (ViMode.php:383-387) only move the cursor — nothing is captured for a subsequent `p`/`P` paste, and there is no paste binding at all. CALIBER_LEARNINGS.md documents this as an intentional simplification ("no yank register yet, matching `yy`") but it's a real gap vs vi/readline expectations.
- **Tab-completion is a static array only**, not a dynamic hook. `TextPrompt::withCompletions(array $completions)` (TextPrompt.php:143-148) and `suggestion()` (TextPrompt.php:631-642) do simple `str_starts_with()` prefix matching against a fixed list handed in up front. There is no `withCompletionCallback(callable $fn)` equivalent of GNU readline's `rl_completion_function` for computing completions dynamically from the live buffer/cursor context (e.g. filesystem paths, DB-backed lookups, multi-word completion). Anyone needing dynamic completion must recompute the whole array and call `withCompletions()` again on every keystroke.
- Vi mode has no `.`/repeat-last-change, no named registers, no `u`/redo inside vi mode (relies on the shared Ctrl+Z/Ctrl+Y-style `UndoManager`, which is emacs/readline-flavored, not vi's own undo tree).
- Multi-line editing (`TextareaPrompt`, sugar-readline/src/TextareaPrompt.php) exists and is reasonably complete (line merge on Backspace/Delete at boundaries, maxLines cap), but has no vi/emacs mode integration — `EmacsMode`/`ViMode` are wired only to `TextPrompt`, not `TextareaPrompt`, so multi-line editing gets none of the word-motion/kill/history-search bindings.
- Incremental history search (Ctrl+R/Ctrl+S) and vi text objects are implemented (confirmed via CALIBER_LEARNINGS.md and source) — these are NOT gaps, despite being called out as a common readline concern.

### 2. Performance concerns

- **`FileHistory::appendLinesAtomically()` rewrites the entire history file on every non-deferred `push()`.** sugar-readline/src/History/FileHistory.php:125-152: it reads the whole existing file into memory (`file_get_contents($this->filePath)`, line 135) and rewrites it plus the new line(s) to a temp file, then renames. For a large/long-lived history file (thousands of commands, as real shell histories accumulate), this makes every submitted line O(file size) instead of O(1) append — a `fopen(..., 'a')` append would be O(1) per push. `deferWrites` mode avoids this per-keystroke but still does the same O(file size) rewrite once at flush.
- **`FileHistory::load()` reads the entire history file into memory** on every construction (FileHistory.php:165-195), with no cap independent of `maxHistory` — `maxHistory` is only enforced after all lines are pushed into `InMemoryHistory`, so a 500MB history file is fully read and iterated (with an O(n) `in_array` dedup check per line, `inMemoryContains()`, InMemoryHistory.php-backed) before any trimming happens.
- **`InMemoryHistory::search()`** (InMemoryHistory.php:89-101) and thus `TextPrompt` incremental search (`refineSearch`/`stepSearch`, TextPrompt.php:481-508) are O(n) linear scans per keystroke with `str_contains()`. Fine for typical shell-history sizes (hundreds–low thousands of entries) but will visibly lag on very large histories (tens of thousands+) since every character typed during Ctrl+R rescans from the current position.
- **`FileHistory::inMemoryContains()`** is `\in_array($line, $this->history, true)` (FileHistory.php:228-231) — O(n) per push/load-line, so `load()` is effectively O(n²) in the number of history lines when there are many duplicates to dedup.

### 3. Test coverage gaps

- No dedicated `AutoSuggest`-in-`TextPrompt` integration tests beyond `computeAutoSuggest()`'s happy path exercised indirectly; `AutoSuggestTest.php` (38 lines) only tests the standalone value object (`AutoSuggest::none()`/`fromHistory()`), not `TextPrompt::view()`'s fish-style-suggestion rendering path (TextPrompt.php:676-704).
- No test file for `Key.php` (constants-only, low risk, but zero coverage recorded).
- No performance/large-history regression test for `FileHistory` (e.g. asserting push() stays sub-linear, or at least documenting expected cost) — given the O(file-size)-per-push behavior above, a regression here would go undetected.
- `Readline.php`'s `runLoop()` mouse/focus/paste dispatch paths (sugar-readline/src/Readline.php:192-225) and bracketed-paste enable/disable (`enableBracketedPaste`, Readline.php:241-248) are only lightly covered — `ReadlineTest.php` has `testReadlineOnMouseReturnsCloneForChaining` (registration only) but no test that actually feeds a `MouseEvent`/`FocusEvent`/`PasteEvent` through `runLoop()` via a scripted driver and asserts the handler fired or that pasted multi-char content is fed through `handleChar()` char-by-char (Readline.php:211-223).
- No test asserts `enableBracketedPaste()`'s TTY-guard branch (`stream_isatty`) — it's unreachable from a `php://memory` stream in tests, so that branch is structurally uncoverable by the current test harness; consider extracting it behind an injectable predicate so it can be tested.
- `ViMode` yank (`yy`/`yiw`) no-op behavior isn't explicitly asserted (i.e., there's no test proving the buffer is genuinely unaffected and no hidden register state leaks) — `ViTextObjectTest.php` covers delete/change text objects but yank coverage is thinner.
- FileHistory concurrent-write races (two processes calling `push()` at once) are not tested; the "another process appended since we loaded" guard (`peekLastOnDisk()`, FileHistory.php:201-223) has no multi-process/multi-instance regression test.

### 4. Missing .vhs/*.tape demos

- Only `sugar-readline/.vhs/basic.tape` and `sugar-readline/.vhs/multi-select.tape` exist, driving `examples/basic.php` and `examples/multi-select.php`. There is a third example, `sugar-readline/examples/interactive.php`, with **no corresponding `.tape`/`.gif`** — per AGENTS.md's VHS convention this demo is undocumented visually.
- No `.tape` demonstrates `SelectionPrompt`, `ConfirmationPrompt`, `TextareaPrompt`, vi-mode (`ViMode`), emacs-mode (`EmacsMode`), or incremental history search (Ctrl+R) — all shipped, non-trivial, user-facing features with zero VHS coverage. Given `.github/workflows/vhs.yml`'s hand-maintained `all=(...)` array, verify sugar-readline's entry only re-renders `basic`/`multi-select`.

### 5. Security concerns

- **TOCTOU window on history temp files.** `FileHistory::appendLinesAtomically()` (FileHistory.php:127-151) and `clear()` (FileHistory.php:243-246) create `$this->tempDir . '/.history.tmp.' . getmypid()` via `fopen($tempFile, 'w')` / `file_put_contents($tempFile, '')`, which is created with the process umask (commonly 0644/0664 — world- or group-readable) and only `chmod()`'d to 0600 *after* the sensitive history content has already been written to it (line 151/245, after the writes at 137-147). Between creation and chmod, another local user with access to `$tempDir` (which defaults to `dirname($filePath)`, e.g. a shared `/tmp` or a home directory with lax perms) can read shell/command history in the temp file. Fix: call `umask(0177)` around the `fopen`, or `fopen` + immediate `chmod` before writing any content, or use `tempnam()` + `chmod(0600)` before writing.
- **Predictable temp filename.** The temp filename is deterministic (`.history.tmp.<pid>`) with no random component, in the same directory as the real history file. On a multi-user host with a shared, writable `tempDir`, this is guessable and could be pre-created (e.g. as a symlink) by another local user before the legitimate process runs, redirecting the atomic `rename()` — classic temp-file symlink/race attack surface. `tempnam()` (which guarantees uniqueness and atomicity of creation) would close this.
- **History file path/location is entirely caller-supplied** (`FileHistory::__construct(string $filePath, ...)`, FileHistory.php:34-50) — sugar-readline does not itself default to a predictable dotfile path (no `~/.sugar_readline_history` default), so the "world-readable/predictable path" risk is pushed onto the integrator; worth a README callout that callers should route through `getenv('HOME')`/XDG dirs with 0700 parent dirs, since sugar-readline only secures the file itself (0600) and not the containing directory.
- **`Ansi::sanitize()` only strips C0 control bytes** (Ansi.php:31-35: `[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]`), explicitly preserving TAB/LF "for legitimate multi-line uses." History entries loaded from disk (`FileHistory::load()`, which trusts file content) and auto-suggestions/search matches derived from history are rendered through this sanitize before `Ansi::wrap()`. An attacker who can plant lines in the history file (e.g. via another compromised process sharing the file, or a race during the temp-file window above) cannot inject raw ESC sequences (stripped), but embedded LF is preserved — a crafted history entry containing `\n` will render as multiple visual lines in `view()`, distorting the single-line prompt's display (minor terminal-spoofing/UI-confusion vector, not code execution).
- Tab-completion input (`withCompletions(array $completions)`) is caller-supplied strings rendered via `Ansi::sanitize()` in the suggestion display (TextPrompt.php:693-697) — no injection risk found here since sanitize is applied consistently.

### 6. Functionality duplicated with candy-forms TextInput / candy-input key decoding

- **Word-boundary/word-motion logic is reimplemented independently four times** with near-identical bodies and *inconsistent* character classes:
  - `sugar-readline/src/TextPrompt.php:847-851` `isWordChar()` — regex `/[a-zA-Z0-9_\p{L}]/u` (Unicode-aware).
  - `sugar-readline/src/Mode/EmacsMode.php:209-213` `isWordChar()` — same Unicode regex, duplicated verbatim, plus its own `wordForward()`/`wordBack()` (EmacsMode.php:166-207) duplicating `TextPrompt::deleteWordBefore()`'s boundary-walk logic (TextPrompt.php:814-845).
  - `sugar-readline/src/Mode/ViMode.php:423-465,504-508` — yet another copy of `wordForward()`/`wordBack()`/`isWordChar()`, same Unicode regex.
  - `candy-forms/src/TextInput/TextInput.php:300-368` — `vimWordForward()`/`vimWordBackward()`/`isWordChar()`, but with an **ASCII-only** regex `/[a-zA-Z0-9_]/` (no `\p{L}`, no `/u` flag) — so candy-forms's `TextInput` treats a Unicode letter as a non-word character where sugar-readline's four copies treat it as a word character. Same concept, silently divergent behavior across libs that are supposed to share vim semantics per `sugar-readline/CALIBER_LEARNINGS.md`'s own rule ("Always add new bindings to `VimAction` enum + `VimKeyHandler`... so candy-forms, sugar-prompt, sugar-bits, and sugar-readline all benefit at once").
  - Recommendation: hoist `isWordChar()` + word-forward/back into a shared helper (candy-forms `VimKeyHandler`/`TextObject`-adjacent utility, since ViMode already depends on candy-forms for `VimKeyHandler`/`TextObject`), and pick one character class (Unicode `\p{L}` is the better readline-parity choice) so emacs-mode word motion, vi-mode word motion, and candy-forms `TextInput`'s vim word motion agree.
- `Readline::symbolicKey()`/`mapPlainKey()` (Readline.php:376-472) re-derives a symbolic-key naming scheme (`'ctrl_a'`, `'alt_b'`, `'up'`, `'f1'`, etc.) from candy-input's `KeyEvent`/`KeyModifier`, essentially a second key-naming layer on top of candy-input's own `EscapeDecoder` key names (`ArrowUp`, `Escape`, etc.). This isn't wrong (it's a legitimate mapping layer for sugar-readline's own `Key::*` constants), but the Ctrl+letter/Alt+letter derivation duplicates modifier-composition logic that candy-input's decoder likely already normalizes internally — worth confirming candy-input doesn't already expose an equivalent symbolic name to avoid two independently-maintained modifier-composition tables drifting apart (e.g. `symbolicKey()`'s `$ctrlMap = ['Escape' => 'ctrl_c', '[' => 'ctrl_c']` hardcodes a Ctrl+[ / Escape conflation that's easy to get subtly wrong in one layer and not the other).

### 7. Documentation gaps

- README.md documents Ctrl+R/Ctrl+S incremental search, vi text objects, and vi/emacs modes thoroughly, but **does not mention that Ctrl+U/Ctrl+K/Ctrl+W/Alt+D are destructive with no yank/undo-via-paste** — a user coming from bash/GNU readline would reasonably expect Ctrl+Y to restore killed text and will silently lose data. Worth an explicit "Known limitations" section given item #1 above.
- README's "Editing modes" section documents `ViMode`'s text-object simplifications (no trailing-whitespace absorption for `a"`, no `quoteescape`, no yank register) — good — but doesn't cross-reference that `TextareaPrompt` has *no* mode support at all (no vi/emacs bindings), which a reader could easily assume is symmetric with `TextPrompt`.
- No documentation of `FileHistory`'s `deferWrites` performance trade-off's *failure mode* beyond "lost on SIGKILL" — doesn't mention the O(file-size) rewrite cost per flush (see Performance §2), which matters for anyone choosing between deferred vs. immediate writes for a large history.
- `AutoSuggest.php` (fish-style suggestion value object) is fully documented in its own docblock but never mentioned in README.md's feature list at all — it's a real, wired feature (`TextPrompt::withAutoSuggest()`, `computeAutoSuggest()`) invisible to a README reader.
- No documented default/recommended history file location or permissions guidance for integrators (see Security §5's "caller-supplied path" gap) — the README's Quick Start never shows a `FileHistory` example at all, only leaves history usage implicit via `withHistory()`.

---

## sugar-reel

Terminal video player (mp4/gif/avi/webm → ASCII/ANSI/truecolor half-block/sixel/kitty/iTerm2).
No single upstream (draws on tplay/glyph/video-to-ascii); reuses candy-mosaic, candy-flip,
candy-palette, candy-core per README/CALIBER_LEARNINGS. Overall the lib is one of the more
mature/complete ports in the monorepo (1254-line `Player.php` matched by a 1795-line
`PlayerTest.php`), so most gaps below are secondary polish items rather than missing core
functionality.

### Performance concerns

- **`Player::frameToBuffer()` builds the per-frame `Buffer` cell-by-cell via `withCellAt()`
  in a nested loop** (`sugar-reel/src/Player.php:653-745`, loops at :683-701, :707-721,
  :724-741). `candy-buffer`'s `Buffer::withCellAt()` (`candy-buffer/src/Buffer.php:109-117`)
  does `$grid = $this->grid; $grid[...] = $cell; return $this->mutate([...])` — because
  `$this->grid` is still referenced by the old `$this`, PHP's copy-on-write duplicates the
  *entire* grid array on the very first mutation of each call. Calling this once per cell
  means an O(cells²) grid-copy cost per frame (e.g. 80×24 = 1920 cells → ~3.7M array-slot
  copies per frame, at framerate). `candy-buffer` already exposes `Buffer::fromGrid(int
  $width, int $height, array $grid)` (`candy-buffer/src/Buffer.php:59`) which takes a plain
  PHP array and builds the Buffer in one shot — `frameToBuffer()` should accumulate cells
  into a local array and call `fromGrid()` once instead of looping `withCellAt()`. This is
  the single highest-value perf fix in the lib since it runs every tick for Ascii/Ansi256/
  TrueColor/HalfBlock/QuarterBlock modes (everything except Sixel/Kitty/Iterm2's direct
  render path).
- **`RgbFrame::toGd()`** (`sugar-reel/src/Decode/RgbFrame.php:62-85`) does a PHP-level
  `imagesetpixel()` loop over every pixel when there is no pre-encoded PNG. This path is hit
  for GIF playback in graphics modes (`GraphicsRenderer::toImageSource()`,
  `sugar-reel/src/Render/GraphicsRenderer.php:76-88`, and `HalfBlockRenderer`/
  `QuarterBlockRenderer`'s bridge, `src/Render/HalfBlockRenderer.php:36-38`,
  `src/Render/QuarterBlockRenderer.php:32-34`) — i.e. every GIF frame in sixel/kitty/iterm2/
  half-block/quarter-block mode pays a per-pixel PHP loop plus a full PNG re-encode, whereas
  the ffmpeg path gets the PNG for free from ffmpeg's C encoder. Not wrong, just
  disproportionately slower for GIF sources in those modes; worth a comment/benchmark or a
  future GD-from-string fast path (`imagecreatefromstring` on raw truecolor isn't directly
  available, but a packed-string blit via `imagecreatefromstring('BMP...')` header trick, or
  just accepting the cost, are the realistic options).
- `Sync::shouldSkip()` limit is hardcoded at 2 frames (`sugar-reel/src/Sync.php:51-54`) with
  no way to tune it per-source; fine for a first cut but worth a constructor param if this
  becomes configurable later.

### Test coverage gaps

- No dedicated test for `SugarCraft\Reel\Subtitle\Cue` (`src/Subtitle/Cue.php`) — only
  exercised indirectly through `tests/Subtitle/WebVttTest.php`. `Cue::contains()`'s half-open
  interval (`$seconds >= start && $seconds < end`) boundary behavior isn't directly unit
  tested.
- No test for `SugarCraft\Reel\Lang` (`src/Lang.php`) — moot right now since it's dead code
  (see Documentation gaps below), but if it's wired up later it'll need a lookup test like
  other libs' `LangTest.php`.
- `RendererFactory::auto()` / `autoMode()` (`src/Render/RendererFactory.php:38-87`) capability
  precedence (sixel > kitty > iterm2 > truecolor > ansi256 > ascii) has no test asserting the
  exact fallback order across all six branches — `RendererFactoryTest.php` exists (270 lines)
  but worth double-checking it covers every rung of the ladder, not just the top and bottom.
- No test asserts the `HalfBlockRenderer`/`QuarterBlockRenderer` "Mosaic path" classes are
  actually *unreachable* from `Player`'s runtime rendering (i.e. that `RendererFactory::create()`
  for those modes is only used by direct callers/tests) — the parity test
  (`testHalfBlockInlineMatchesMosaicRenderer`, referenced in `src/Render/HalfBlockRenderer.php:44`)
  keeps the two paths in sync but doesn't document *why* two paths exist for future readers
  beyond the inline comment.
- `examples/play.php` has no smoke test (not unusual for this monorepo, but note it since the
  VHS tape only exercises `--help`/synthetic playback, see below).

### Missing .vhs/*.tape demos

- Only one tape, `.vhs/play.tape`, and per CALIBER_LEARNINGS
  (`pattern:vhs-tape-shows-help-or-static-output`) it deliberately shows the synthetic
  playback rather than `--help` (contradicts its own learning text, which says the opposite —
  minor learnings/behavior drift, not a functional bug). There is no tape demonstrating any of
  the graphics-protocol modes (sixel/kitty/iterm2), `halfblock`/`quarterblock`, or the `m` mode-
  cycle key — a second tape showing `m` cycling through modes would better showcase the lib's
  main differentiator (7 rendering backends) than a single synthetic-pattern loop.
- No tape exercises seeking (`←`/`→`/digit keys) or speed control (`[`/`]`), both documented
  keyboard features.

### Security concerns

- No injection issues found: `FfmpegDecoder::buildCommand()` (`src/Decode/FfmpegDecoder.php:205-256`),
  `AudioPlayer::buildCommand()` (`src/AudioPlayer.php:188-216`), and `VideoSource::probe()`
  (`src/Source/VideoSource.php:108-126`) all pass argv arrays straight to `proc_open()` with no
  shell, and `Probe::which()` (`src/Source/Probe.php:81-103`) escapes the one shell string it
  builds. Consistent with the project's `pattern:proc-open-array-form` learning.
- `Reel::openUrl()` (`src/Reel.php:90-97`) validates `^https?://` but does not prevent SSRF-style
  abuse (fetching arbitrary internal URLs via ffmpeg) if this lib is ever exposed to
  untrusted user input for a URL parameter — worth a note in README/CALIBER_LEARNINGS for
  downstream consumers (e.g. `candy-query`-style admin apps) that they must allowlist hosts
  before passing user-supplied URLs to `Reel::openUrl()`.
- `WebVtt::parse()` (`src/Subtitle/WebVtt.php:43-62`) has no size/cue-count cap — an
  attacker-supplied subtitle file with millions of tiny blocks could cause excessive memory/
  CPU in `usort()`. Low severity (subtitle files are typically trusted/local), but worth a
  defensive cap if subtitles are ever fetched from an untrusted source (e.g. a remote WebVTT
  URL companion to `openUrl()`, which doesn't exist yet — see Missing functionality).
- `FfmpegDecoder::MAX_PNG_BUFFER` (100MB, `src/Decode/FfmpegDecoder.php:48`) guards the PNG
  pipe path but there's no equivalent cap on the raw rgb24 path — a corrupt/malicious stream
  that never produces `EOF` on the fixed-length read loop (`next()`, :270-308) would keep
  reading indefinitely, though this is bounded per-call by `$this->frameBytes` so risk is low.

### Functionality duplication (honey-bounce / candy-flip)

- No overlap with **honey-bounce**: that lib is spring/easing physics for animation; sugar-reel's
  `Sync` (`src/Sync.php`) is pure wall-clock frame-index pacing (skip/hold/advance), a
  different concern. No shared code or duplicated logic.
- **candy-flip** overlap is intentional reuse, not duplication: `GifDecoder`
  (`src/Decode/GifDecoder.php:70`) calls `FlipDecoder::decode()` directly per
  `pattern:reuse-rendering-stack` — correctly delegated.
- **Internal duplication** (not with another lib, but worth flagging under this heading):
  `Player::frameToBuffer()`'s inline HalfBlock (`src/Player.php:672-701`) and QuarterBlock
  (`src/Player.php:702-721`) pixel-grouping/coloring logic duplicates — deliberately, per
  in-code comments — the logic in `src/Render/HalfBlockRenderer.php` and
  `src/Render/QuarterBlockRenderer.php`, which delegate to candy-mosaic's renderers instead.
  The comments explain the Mosaic-path classes are "NEVER reached by the Player runtime" and
  exist only for direct `RendererFactory::create()` callers/tests, kept in sync by a parity
  test. This is a real (self-acknowledged) maintenance smell: two independently-maintained
  implementations of the same half-block/quarter-block color-quantization algorithm in one
  lib. A future refactor could make `Player` call the Mosaic-path renderers directly (with an
  RgbFrame→ImageSource bridge) and delete the inline duplicate, provided the perf cost of the
  `toGd()`/PNG round-trip is acceptable — currently the inline path was presumably kept for
  speed (avoids one GD encode step per frame) at the cost of duplicated color logic.

### Documentation gaps

- **`Lang.php`/`lang/en.php` are dead code.** `lang/en.php` defines a full set of message keys
  (`decoder.ffmpeg_missing`, `audio.no_binary`, `player.loading`, `controls.help`, etc.,
  `lang/en.php:11-27`) and `src/Lang.php` wraps `SugarCraft\Core\I18n\Lang`, but a repo-wide
  grep found **zero calls to `Lang::t()` anywhere in `src/`** — every user-facing string is
  hardcoded English inline instead (e.g. `renderPlaceholder()`'s `"loading...  space play  q
  quit"` at `src/Player.php:881`, the `[ended]` hint at `src/Player.php:636-637`, and the
  `error_log()` messages in `FfmpegDecoder`/`AudioPlayer`). Either the i18n scaffolding should
  be wired into `Player::view()`/error paths, or `Lang.php`+`lang/en.php` should be removed/
  flagged as scaffolding-only until adopted — currently they're maintenance debt with no
  effect.
- Only `lang/en.php` exists — no other locale files, unlike some sibling libs that ship
  several `LOCALES.md`-recommended codes. Low priority since the strings aren't even wired up
  yet (see above).
- README's "Known limitations" section (`README.md:171-177`) is good and specific, but doesn't
  mention: (a) the `Sync::shouldSkip` 2-frame hardcoded skip threshold, (b) that GIF
  graphics-mode/half-block/quarter-block rendering pays an extra per-pixel PHP + PNG-encode
  cost vs. the ffmpeg path (see Performance above), or (c) that `Reel::openUrl()` performs no
  host allowlisting (SSRF consideration for downstream consumers).
- No `CONVERSION.md`/`MATCHUPS.md`-style "prior art" cross-reference beyond the README's
  bullet list — fine given there's no single upstream, but the three prior-art projects listed
  (tplay/glyph/video-to-ascii) aren't cited per-feature (e.g. which project inspired the
  skip/hold/advance algorithm specifically, vs. the letterbox math, vs. the luma ramp) — a
  minor traceability gap for future maintainers wanting to check divergence from prior art.
- `AudioPlayer`'s doc comment (`src/AudioPlayer.php:9-26`) explains the "audio is not a
  position-reporting master clock" design decision well, but this key architectural
  constraint (video paces off wall clock, not audio position) isn't repeated in the top-level
  README architecture diagram/prose (`README.md:114-155`), which only says "Audio: AudioPlayer
  shells out to ffplay or mpv as the audio master clock" (`README.md:155`) — this is actually
  a **contradiction**: the README calls audio "the audio master clock" while the source code
  and its own doc comment say the *opposite* (video paces off its own wall clock; audio has no
  reporting-back role). This should be corrected in the README.

---

## sugar-skate

**Scope correction:** the task brief assumed sugar-skate is a skateboarding/physics arcade game akin to honey-flap/candy-mines/candy-tetris. It is not. Per `sugar-skate/composer.json:3` and `sugar-skate/README.md`, sugar-skate is a PHP port of **charmbracelet/skate** — a personal SQLite-backed key/value store with multi-database (`@dbname`), binary blob, glob-pattern, TTL/expiry, and JSON/YAML import-export support. The audit below is retargeted to that actual scope; the requested categories (scoring, obstacles, difficulty progression, collision/game-over detection) do not apply and are omitted. "High-score persistence" is mapped to the analogous concern here: the JSON/YAML import path and SQLite-file persistence.

### 1) Correctness bugs — CLI entry point is broken (verified by running it)

- `bin/skate:87-92` calls `Store::sanitizeForTty(...)` as a static method on every `list` invocation. **No such method exists anywhere in `src/`** (confirmed via `grep -rn sanitizeForTty sugar-skate/`, only hit is `bin/skate` itself). Running `./bin/skate set foo bar && ./bin/skate list` throws:
  ```
  PHP Fatal error: Uncaught Error: Call to undefined method SugarCraft\Skate\Store::sanitizeForTty() in bin/skate:87
  ```
  This makes `skate list` (the CLI's primary read command) unconditionally fatal.
- `bin/skate:72` calls `$store->suggestSimilar($argv[2])` on a `get` miss, but `suggestSimilar` is declared `private` in `sugar-skate/src/Store.php:185`. Running `./bin/skate get nonexistent-key` throws:
  ```
  PHP Fatal error: Uncaught Error: Call to private method SugarCraft\Skate\Store::suggestSimilar() from global scope in bin/skate:72
  ```
  Note `Store::get()` (`src/Store.php:113-129`) already does the suggestion-on-miss internally and writes it to STDERR — the CLI's duplicate call at `bin/skate:72` is both redundant and broken (should be deleted, not fixed-in-place).
- Root cause: **zero test coverage of `bin/skate` itself** — `tests/CliArgParserTest.php` only unit-tests the static `ArgParser::*` argument-parsing helpers, never the CLI dispatcher/`bin/skate` script end-to-end, so these two fatals ship silently. `grep -rn "sanitizeForTty|suggestSimilar" sugar-skate/tests/` returns nothing.

### 2) Missing/incomplete functionality

- `Store::get()` at `src/Store.php:113` has its own inline suggestion logic; `bin/skate`'s separate/broken `get` handling (`bin/skate:64-76`) duplicates and diverges from it rather than just calling `$store->get()` and branching on the fallback sentinel — the divergence is what produced the private-method-call bug above.
- `ExportCommand::exportToString()` (`src/Cli/ExportCommand.php:48-81`) only exports from a single database at a time (matches `Store::list()`'s single-db scope) — there's no "export everything across all databases" command, even though `listDatabases()` exists. Not necessarily a bug, but a gap vs. a full backup/restore story.
- `YamlImporter`'s fallback parser (`src/Import/YamlImporter.php:166-212`) is explicitly documented as minimal (no nested structures, no lists, no anchors) — acceptable per its own doc-comment, but the export side (`ExportCommand::exportYaml`, `src/Cli/ExportCommand.php:99-127`) round-trips cleanly only for scalar maps; anything more complex silently degrades without a warning to the caller when `symfony/yaml` isn't installed.

### 3) Test coverage gaps

- **No end-to-end/process test for `bin/skate`.** All CLI-adjacent tests (`tests/CliArgParserTest.php`) test only `ArgParser::set/list/import/export` static parsing — they never invoke the actual `bin/skate` script (e.g. via `exec()`/`proc_open`), which is why the two fatal errors above (section 1) are invisible to `vendor/bin/phpunit`.
- `src/Import/JsonImporter.php:64-67` does `json_decode($json, true, 512, JSON_THROW_ON_ERROR)` with no `is_array($data)` guard before `isset($data['_ttl'])` / `foreach ($data as ...)`. Reproduced directly:
  ```
  $imp->importFromString('"just a string"');
  → PHP Warning: foreach() argument must be of type array|object, string given (JsonImporter.php:99 and :82)
  ```
  Valid JSON whose top level is a scalar/string/number (not an object) produces PHP warnings instead of a clean `\RuntimeException`, and — because `failOnWarning="true"` is the project-wide PHPUnit convention (`AGENTS.md`) — this is exactly the kind of input class that has **no regression test**, unlike `candy-mines/src/Board.php:260` which explicitly guards with `if (!is_array($p)) { ... }` after its `json_decode(..., JSON_THROW_ON_ERROR)`. `YamlImporter::importFromString` has the analogous gap when `symfony/yaml`'s `Yaml::parse()` returns a non-array (e.g. a bare YAML scalar document).
- No test exercises `Database::listDatabases()` filtering out a database literally named `settings` (`src/Database.php:320`) — that special-case exclusion is untested and undocumented in README/CALIBER_LEARNINGS.
- `Entry::fromRow()` (`src/Entry.php:58-72`) trusts `$row['created']`/`$row['modified']` to always be valid `DATE_ATOM` strings; there's no test for a corrupted/legacy row with an unparsable date string (would throw an uncaught `\Exception` from `new \DateTimeImmutable()`).
- `Database::init()`'s legacy-schema migration path (`src/Database.php:51-59`, `ALTER TABLE entries ADD COLUMN expires_at`) has no test that opens a pre-existing DB file lacking the column and confirms the migration runs and preserves existing rows.

### 4) Missing .vhs demos

Not a gap — `sugar-skate/.vhs/` has four tapes with committed GIFs (`basic.tape`, `binary.tape`, `glob.tape`, `multidb.tape`, plus `reverse-order.tape`), and the README embeds the `glob.gif` demo. No `import`/`export`/TTL demo exists, which would be a natural fifth tape given those are documented CLI features, but this is a minor completeness gap, not a defect.

### 5) Security concerns

- **Database name validation is solid**: `Store::dbPath()` (`src/Store.php:355-363`) is the single choke point for all DB access and rejects anything outside `^[A-Za-z0-9_-]+$` — confirmed by dedicated tests `testInvalidDbNameTraversalThrows`/`DotThrows`/`SlashThrows`/`BackslashThrows` in `tests/StoreTest.php:350-391`. This is good and directly analogous to (and better tested than) the kind of path-safety issue that would be flagged in a persistence layer.
- JSON/YAML import (section 3 above) lacks a top-level-type guard before iterating decoded data — the "JSON-parse-safety" gap analogous to candy-mines is present here in `JsonImporter`/`YamlImporter`, just manifesting as unhandled PHP warnings rather than silent data corruption, since the loop bodies are otherwise defensive (`!\is_string($key) || !\is_string($value)` continue-guards at `src/Import/JsonImporter.php:83` and `src/Import/YamlImporter.php:86`).
- `Store::setFile`/`getFile` (`src/Store.php:249-272`) take arbitrary file paths with no path validation — this is by design (it's a local file-import/export helper mirroring upstream `skate`, not client-facing), but worth flagging if this class is ever exposed through a network-facing wrapper.

### 6) Duplication with other libs

- No functional overlap with `honey-bounce` (spring/projectile physics — `Gravity.php`, `Spring.php`, `Vector.php`) or `candy-mines`/`candy-tetris` (TUI game boards) — those are unrelated domains; the task brief's premise that sugar-skate is a physics/arcade game was incorrect.
- `sugar-skate` correctly reuses `sugarcraft/candy-fuzzy`'s `SmithWatermanMatcher` for `Store::fuzzyFilter()` (`src/Store.php:153-178`) rather than reimplementing scored matching — this matches the documented pattern in `CALIBER_LEARNINGS.md` ("Use candy-fuzzy for scored filter matching") and is the one cross-lib dependency worth noting; README explicitly flags that the fuzzy matcher is wired but not yet the active CLI filter path (glob-to-LIKE remains primary), which is consistent with what the code does.

### 7) Documentation gaps

- `README.md` Quick Start example is wrong: `$skate->set('token', 'ghp_xxxx', 'passwords')` (README.md, "With a database" section) — `Store::set()`'s third positional parameter is `bool $binary` (`src/Store.php:84`), not a database name; passing the string `'passwords'` there is truthy-coerced to `$binary = true`, which is not the documented multi-database behavior. The correct call per the rest of the README/tests is `$skate->set('token@passwords', 'ghp_xxxx')`. This is a copy-paste-able bug for anyone following the README.
- README's CLI section doesn't mention that `skate list` is currently broken (section 1) — obviously unintentional, but worth noting the docs describe working behavior that the shipped `bin/skate` does not deliver.
- `CALIBER_LEARNINGS.md` documents the TTL/Levenshtein/transaction/STDIN patterns accurately for the `Store`/`Database` classes, but has no entry at all about `bin/skate`'s CLI dispatcher risks (the sanitizeForTty/suggestSimilar bugs) — future contributors have no signal that the CLI entry point lacks test coverage.

---

## sugar-spark

**Scope correction (important):** sugar-spark is NOT a sparkline/mini-graph library. Per its
`composer.json` description and `README.md`, it is a PHP port of
`charmbracelet/sequin` — an **ANSI escape-sequence inspector/decoder** (SGR/CSI/OSC/DCS/APC/SS3 →
human-readable labels), shipped as both a library (`Inspector`, `StreamingInspector`) and a CLI
(`bin/sugarspark`). The "sparkline" naming resemblance is coincidental; the actual sparkline/mini-graph
chart port lives in `sugar-charts/src/Sparkline/Sparkline.php` (see Duplication section). Findings below
are scoped to what sugar-spark actually is; the requested "multi-series sparklines" / "color-threshold
highlighting" checks don't apply to this lib (they're sugar-charts concerns, and sugar-charts'
`Sparkline.php` currently has neither multi-series nor threshold-color support either — worth flagging
separately if auditing sugar-charts).

### candy-vt Parser bug impact — DENIED, but two other real bugs found

- **No candy-vt dependency exists.** `sugar-spark/composer.json` requires only
  `sugarcraft/candy-ansi` and `sugarcraft/candy-core`; its `repositories[]` list is
  candy-ansi/candy-core/candy-async/candy-input/candy-pty — **candy-vt is absent**.
  `grep -rn "candy-vt\|CandyVt\|SugarCraft\\\\Vt"` across `src/`, `tests/`, `composer.json`, `bin/`
  returns zero hits. sugar-spark parses ANSI via `SugarCraft\Ansi\Parser\Parser` (candy-ansi), a
  fully separate parser from candy-vt's `Parser.php`. **The prior finding that candy-vt's
  stringBuffer bug (candy-vt/src/Parser/Parser.php:198-205) broke sugar-spark is incorrect** —
  sugar-spark doesn't consume candy-vt at all. It was likely misattributed from another lib (only
  candy-vcr, candy-vt, candy-pty, candy-ansi list candy-vt as a dependency in this monorepo).

- **However, two independent, unrelated fatal-error bugs exist in sugar-spark's own streaming path**,
  both in `src/StreamingInspector.php::finish()`:

  1. **Dead/incorrect state check (functional regression):** `finish()` calls
     `$this->parser->flush()` and only *afterward* checks
     `$this->parser->currentState() === State::Escape` (StreamingInspector.php:65-69/`69-74` per
     current file — see lines 63-74). But `Parser::flush()` always resets state to `Ground`
     (verified: `state after feed: Escape` → `state after flush: Ground`), so this branch is
     **permanently unreachable**. Compare to the correct pattern in
     `src/AnsiHandler.php::parse()` (lines 65-71), which captures `$stateBeforeFlush` *before*
     calling `flush()`. Net effect: a bare trailing ESC at end-of-stream is silently dropped by
     `StreamingInspector` (verified: feeding `"hello\x1b"` then `finish()` returns only the `"hello"`
     TextSegment, no ESC segment) — inconsistent with `Inspector::parse()`, which correctly emits it.

  2. **Reachable fatal `Error` for a dangling SS3 sequence.** If the dead-code branch above were
     fixed (or via the separate, live `isSs3Buffered()` branch at StreamingInspector.php:77-82),
     the code writes `$this->handler->segments[] = ...` directly — but `AnsiHandler::$segments` is
     `private` (AnsiHandler.php:24), and `getSs3Intermediate()` is `protected`
     (AnsiHandler.php:144) — both illegal from `StreamingInspector`, a different class. **Confirmed
     reproducible today:** feeding `"\x1bO"` (SS3 intermediate with no final byte — i.e. any input
     that ends mid a cursor-key/function-key escape, a very plausible split point when reading a
     TTY stream in chunks) into `StreamingInspector::feed()` then calling `finish()` throws:
     `Error: Call to protected method SugarCraft\Spark\AnsiHandler::getSs3Intermediate() from scope
     SugarCraft\Spark\StreamingInspector`. This is a real crash for any consumer streaming
     terminal output that happens to chunk-split on an SS3 boundary at end-of-stream.

### Test coverage gaps

- `tests/StreamingInspectorTest.php` (315 lines) calls `finish()` in several places but **never
  feeds a bare trailing ESC or a dangling SS3 sequence right before `finish()`** — the exact
  conditions that trigger the two bugs above. This is the direct reason both bugs shipped
  undetected (163/163 tests pass; `composer install && vendor/bin/phpunit` is green).
- No test exercises `StreamingInspector` across an SS3 chunk boundary landing at true end-of-stream
  (only mid-stream continuation is covered, per a scan of `finish(` call sites).

### Documentation gaps

- `README.md`'s "Library" section only documents `Inspector::parse()` / `Inspector::report()`; it
  never mentions `StreamingInspector` at all, despite it being a public, non-trivial class with its
  own `feed()`/`finish()` streaming contract and edge cases (the ones with the bugs above). A user
  reading only the README would not know incremental/streaming inspection exists.
- `sugar-spark/CALIBER_LEARNINGS.md` is **missing** — every other lib in the skeleton checklist
  (per `AGENTS.md` "Adding a lib" section) is expected to carry one; sugar-spark has none.

### Security concerns

- Low overall risk — this is a local CLI/library that decodes text, not a network-facing service.
- `bin/sugarspark` (lines 21-26) reads either a file path from `$argv[1]` via
  `@file_get_contents($argv[1])` (error-suppressed) or stdin. No special handling of `argv[1]`
  values that look like flags/URLs (e.g. `php://` wrappers) — since `file_get_contents` respects PHP
  stream wrappers, a malicious/careless caller could pass `php://stdin`, `http://...`, or `phar://...`
  as "a file path", enabling local/remote file or phar inclusion in scripts that build the CLI's
  argv from untrusted input. Low severity given intended interactive/pipe usage, but worth a
  `is_file()` guard or restricting to `fopen('file://...')` if this is ever invoked with
  attacker-influenced arguments.
- `Inspector::sanitizeLabelBytes()` (Inspector.php:461-479) correctly neutralizes embedded C0/DEL
  bytes (including ESC) in interpolated OSC/APC payload labels before they'd reach a live terminal —
  a good defensive measure against re-arming escape sequences via crafted OSC titles/APC payloads.
  This mitigation is applied in `describeOsc()`/`describeApc()` but should be double-checked that
  every payload-interpolating description path (e.g. `describeDcs()`, DECRPSS reply text) goes
  through the same sanitizer — `describeDcs()` (Inspector.php:358-395) interpolates `$payload`
  directly into returned strings (e.g. line 364, 369, 387) without calling
  `sanitizeLabelBytes()`, unlike `describeOsc`/`describeApc`. A crafted DCS/DECRPSS payload
  containing raw ESC/C0 bytes could reach a live terminal un-sanitized when `Inspector::report()`
  output is echoed directly (as the README's CLI/library examples do).

### Performance concerns

- No hard performance red flags for typical CLI usage (single-shot, moderate input sizes).
- `AnsiHandler::printChar()`/`execute()` accumulate `$textBuf .= $rune` per character
  (AnsiHandler.php:159) — fine for normal terminal output volumes; on very large captured sessions
  (e.g. inspecting a multi-MB terminal recording) this is O(n) string growth per segment plus one
  `Segment` object per escape sequence, so memory scales linearly with input size and escape-sequence
  density. Not a concern for the tool's stated use case (piping single command output through
  `sugarspark`), but worth noting if `StreamingInspector` is ever wired into a long-lived process
  inspecting continuous PTY traffic — no bound/eviction on segment accumulation exists (segments
  only leave via `drainSegments()`, so a caller who forgets to drain will leak memory unboundedly).

### Duplication with sugar-charts

- No functional duplication: `sugar-charts/src/Sparkline/Sparkline.php` is a genuine sparkline/
  chart-rendering component (glyph-based min/max bar sparkline, `Push`/`PushAll` sliding window);
  `sugar-spark` does unrelated ANSI-sequence decoding/labelling. The only overlap is naming
  similarity ("Spark" vs "Sparkline"), which is a documentation/discoverability risk for
  contributors/users (already reflected correctly in `docs/MATCHUPS.md`: sugar-spark ↔
  `charmbracelet/sequin`, sugar-charts ↔ `NimbleMarkets/ntcharts`), not a code-duplication issue.

---

## sugar-stash

Scope confirmed: `sugar-stash` is a three-pane git TUI, port of `jesseduffield/lazygit` (README.md:16). It has **no overlap** with `sugar-skate` (a `charmbracelet/skate` SQLite key-value store port) — different upstream, different domain (git porcelain client vs. generic KV store), no shared code paths. Section 6 (duplication) is therefore empty/N-A.

### 1. Missing/incomplete functionality vs. upstream (lazygit)

- **No remote operations at all** — no `push`, `pull`, `fetch`, no remote-tracking/ahead-behind sync actions beyond the read-only `branch_summary` line parsed in `src/Git.php:23-38`. `GitDriver` (`src/GitDriver.php`) has zero methods for `git push/pull/fetch/remote`. This is core lazygit functionality that's absent.
- **No tags support** — no list/create/delete/push-tag anywhere in `GitDriver`/`App.php`.
- **No submodules pane** — lazygit has one; not present here.
- **No custom commands / bisect / blame / reflog** — none of these lazygit features exist (confirmed via grep across `src/App.php`; no matches for `remote`, `push`, `pull`, `fetch`, `tag`, `submodule`, `bisect`, `blame`).
- **Rename entries mis-parsed in `git status`**: `Git::status()` (`src/Git.php:21-38`) does `substr($line, 3)` for the path on every non-`##` porcelain line. For a rename/copy, `git status --porcelain=v1` emits `R  old -> new`, so `path` becomes the literal string `"old -> new"`. Every consumer (`App.php:527` discard, `:547-551` stage/unstage, `:619` diff) passes this straight to `git add -- <path>` / `git restore --staged -- <path>` / `git diff -- <path>`, which will fail or silently no-op against a non-existent path. No test exercises a rename fixture (`tests/GitApplyIntegrationTest.php`, `tests/GitGuardTest.php` only cover non-rename cases).
- **Worktree entry point is broken for worktrees themselves**: `bin/sugar-stash:25` checks `is_dir("$cwd/.git")` to decide "is this a git repo". Inside a linked worktree, `.git` is a *file*, not a directory, so `sugar-stash` launched from inside one of its own managed worktrees refuses to start with `cli.not_a_repo`. Contrast with `CALIBER_LEARNINGS.md:126-139`, which explicitly documents using `git rev-parse --absolute-git-dir` "works even when `.git` is a file (worktree)" for the rebase-detection code path (`Git::rebaseInProgress()`, `src/Git.php:220-228`) — the same fix was never applied to the CLI entrypoint.
- **No WindowSizeMsg / resize handling**: `src/App.php` has no reference to `WindowSizeMsg` or any width/height state; `Renderer.php` hardcodes pane widths (e.g. `36` at `src/Renderer.php:142,163,184`, truncate widths `31`/`32`/`26`). The TUI never adapts to actual terminal size — violates the project's own "WindowSizeMsg is size truth" invariant.
- **No scrolling/viewport for status or branches panes**: `Renderer::statusPane()` (`src/Renderer.php:120-143`) and `branchesPane()` (`:145-164`) render every entry in `$a->status`/`$a->branches` unconditionally with no `array_slice`/scroll offset. A repo with many modified files or many local branches will overflow the fixed-height pane frame with no way to scroll to entries below the fold. `logPane()` is bounded only because `Git::log()` hardcodes `-n25` (`src/Git.php:57-61`), not because of any viewport logic.

### 2. Performance concerns

- Every mutating key handler ends by calling `->refresh()`, which re-spawns 3 synchronous `git` subprocesses (`status`, `for-each-ref`, `log`) via `proc_open` (`src/Git.php:243-263`) — acknowledged as "by design for v1" in README.md:78, but combine with the no-limit `branches()`/`status()` calls above: a repo with thousands of branches or a huge dirty tree re-parses/re-renders the full list on every keystroke that triggers a refresh, with no caching between refreshes.
- `run()`/`runPatch()` have no timeout on `proc_open`; a `git` invocation that blocks (e.g., hooks prompting, or a merge driver waiting on stdin) will hang the whole synchronous UI thread indefinitely (single-threaded, blocking event loop per README.md:78).

### 3. Test coverage gaps

- **`Git` class's real parsing logic is essentially untested.** `tests/AppTest.php:14` defines `FixtureGit implements GitDriver` and all `App` behavior tests run against that fixture, never against real `git` output. The only tests instantiating the real `Git` class are `tests/GitGuardTest.php` (dash-injection guard checks — most just assert an exception is thrown *before* `proc_open` runs) and `tests/GitApplyIntegrationTest.php` (hunk stage/unstage only). This means:
  - `Git::status()` porcelain-v1 parsing (branch-summary line, index/work status columns, path extraction, **rename format**) has no real-git test at all.
  - `Git::branches()` `for-each-ref` parsing has no real-git test.
  - `Git::log()` format-string parsing has no real-git test.
  - `commit()`, `amend()`, `stageAll()`, `unstageAll()`, `discard()`, `deleteBranch()`, `rebaseContinue/Abort/Skip()`, `reset()`, `stashList/Apply/Drop()`, `cherryPickContinue/Abort()`, `worktreeList/Remove()`, `rebaseInProgress()` are exercised nowhere against a real repo.
  - Given the rename-parsing bug above, this gap is not hypothetical — it hid a real defect.
- No test drives `bin/sugar-stash` itself (the `.git`-is-a-directory bug would have been caught by a worktree-based CLI smoke test).
- No `WindowSizeMsg`/resize test exists (consistent with the feature being entirely unimplemented).
- No test for status/branches panes with entry counts exceeding pane height (would have caught the missing-viewport gap).

### 4. Missing `.vhs`/demos

Only two tapes exist: `.vhs/play.tape` and `.vhs/stage.tape` (basic pane-cycling + stage/unstage). Given the README documents a large keymap (README.md:23-56), there are no demos for: diff viewer hunk-staging (`P`), commit/amend (`c`/`A`), branch create/delete (`n`/`D`), merge (`M`), rebase menu (`r`), stash manager (`S`), cherry-pick (`V`), worktrees manager (`w`), interactive rebase (`i`), undo/redo (`u`/`Ctrl+r`), or help overlay (`?`) — i.e., every "overlay" feature added since the initial two tapes were recorded has zero visual regression coverage.

### 5. Security concerns

- Positive: `Renderer::sanitize()` (`src/Renderer.php:20-30`) strips C0/C1 control bytes and bare ESC from all git-derived strings (paths, branch names, commit subjects) before rendering — correctly guards against terminal-escape injection from untrusted repo content (malicious commit messages/filenames).
- Positive: `guardRef()` (`src/Git.php:235-240`) rejects any ref/path/branch argument starting with `-`, preventing option injection into `git` subcommands across `checkout`, `createBranch`, `merge`, `stashApply`, `stashDrop`, `cherryPick`, `worktreeAdd`, `worktreeRemove` (`src/Git.php:81-218`).
- No secrets/credentials are stored by this lib (unlike a KV-store port) — no file-permission/encryption-at-rest surface applies.
- **Gap**: `worktreeAdd(path, branch)` (`src/Git.php:207-212`) only rejects a leading `-`; it does not validate `$path` against traversal (`../../etc` etc.) or symlink targets before calling `git worktree add`. Low real-world severity since the path is operator-supplied at their own terminal (not remote input), but worth a guard given the app already treats `-` specially for exactly this class of concern.
- `runPatch()`/`run()` build the `git` argv as an array passed to `proc_open` (not a shell string), so no shell-metacharacter injection risk from paths/messages containing spaces or quotes.

### 7. Documentation gaps

- README's "Architecture" table (README.md:58-74) omits `HistoryEntry`/`HistoryManager` undo/redo internals in the class list even though the Keys table documents `u`/`Ctrl+r` — only `HistoryEntry`/`HistoryManager` behavior is documented in `CALIBER_LEARNINGS.md:90-119`, not in the user-facing README architecture section (minor inconsistency, not blocking).
- README doesn't mention the `is_dir($cwd/.git)` worktree limitation from item 1 — a user might reasonably expect `sugar-stash` to work from a linked worktree given it has a whole worktree-management feature; the incompatibility is undocumented.
- No CHANGELOG or version-history doc; not required by AGENTS.md but noting for completeness since the lib clearly has undergone several feature phases (stash → cherry-pick → worktrees → interactive rebase per `CALIBER_LEARNINGS.md`).
- `GitDriver` interface docblock (`src/GitDriver.php:11-15`) marks the whole interface `@deprecated` pending a v2 async rewrite, but no migration/deprecation timeline or replacement interface exists yet — README doesn't surface this to consumers of the package.

---

## sugar-stickers

Scope confirmed: PHP port of `76creates/stickers` (Lipgloss-adjacent Go lib) — FlexBox layout + sortable/filterable Table, plus thin Viewport/Scrollbar wrappers that compose `sugar-bits`. Not related to emoji/ASCII-art "stickers" despite the name.

### 1. Missing/incomplete functionality vs upstream target

- **`Table::sortBy()` never updates the sort-arrow indicator (functional bug).** `Table::sortBy()`/`sortByNext()` (`src/Table/Table.php:82-104`) only mutate the Table's own private `$sortColIndex`/`$sortAscending`; they never call `Column::sorted()` on the affected column. But `buildLines()` (`src/Table/Table.php:211-214`) reads the arrow glyph from `$col->sortDir()`, which is only set by manually calling `Column::sorted()` directly on a column object — a completely separate, never-synced piece of state. Verified live:
  ```
  (new Table())->addColumn(Column::make('Name',10))->addRow(['Bob'])->addRow(['Alice'])->sortBy(0,true)->render()
  ```
  sorts the rows correctly but renders `Name` with no `▲`/`▼` glyph at all. The README advertises "Sortable columns — click to sort by any column, ascending/descending" implying visible sort-direction feedback; that feedback is currently unreachable through the public sort API. `Column::sorted()`/`unsorted()`/`sortPriority()` are consequently dead weight from the Table's perspective — well tested in isolation (`tests/StickersTest.php:418-448`) but never exercised end-to-end via `Table::sortBy()`.
- **`Column::sortPriority()` is stored but never consumed.** No code path in `Table` reads `sortPriority()` for multi-column sort — only single-column sort is implemented (`sortColIndex`/`sortAscending`), so the priority field is speculative API surface with no behavior behind it.
- **No border support on Table**, unlike sibling `candy-sprinkles/src/Table/Table.php` (which wraps `Border`) and `sugar-table` (frozen columns, zebra striping, pagination). Not necessarily a defect since 76creates/stickers' upstream Table is minimalist, but the README doesn't call out the limitation relative to its sibling ports.
- **`FlexBox`/`FlexItem` have no bulk accessor** — no `items()` getter, no `removeItem()`/`withItems()`, so callers can't introspect or bulk-replace the item list once constructed; only `addItem()` is exposed.
- FlexBox's `wrap`/`border` were deliberately removed per `CALIBER_LEARNINGS.md` (undocumented no-ops were stripped) — correctly resolved, not a gap.

### 2. Performance concerns

- `FlexBox::renderRow()`/`renderColumn()` (`src/Flex/FlexBox.php:114-120`, `195-201`) allocate a `$measured` array with a closure via `array_map()` on every single `render()` call — flagged already in `CALIBER_LEARNINGS.md` as GC-pressure-inducing for frequently-updated layouts; no caching even when items are unchanged between renders.
- `TableRenderer::bufferFromOutput()` (`src/Table/TableRenderer.php:101-192`) re-parses the entire ANSI stream and rebuilds a full grapheme-by-grapheme `$strippedPosToStyle` map every frame — O(width×height) per render even when only one cell changed, before the diff itself is computed. Documented in `CALIBER_LEARNINGS.md` as "~5,000 entries for a 100×50 buffer," acknowledged but unaddressed.
- `TableRenderer::sgrToStyle()` (`src/Table/TableRenderer.php:204-234`) only maps SGR *attribute* codes (bold/italic/underline/etc.) — it does **not** parse basic 8/16-color codes (30-37, 40-47, 90-97) or 256/RGB color codes (`38;5;n`, `38;2;r;g;b`), leaving `$fg`/`$bg` permanently `null`. This means color-only changes between frames may fail to register in the diff Buffer's `Style`, potentially causing the diff encoder to under-report changes (a correctness/perf tradeoff, not just missing color fidelity) — worth flagging for whoever consumes `TableRenderer::render()` expecting exact diff coverage of colored cells.

### 3. Test coverage gaps

- **No test exercises `Table::sortBy()` + rendered arrow glyph together** — the bug in section 1 slipped through because `testTableSortBy()`/`testTableSortToggle()` only assert `currentRow()` ordering, never `render()` output content for the sort indicator.
- **`Column::sanitize()` and `FlexBox::sanitize()` (security-relevant control-character/OSC/DCS stripping) have no dedicated tests** — nothing feeds ESC/OSC/DEL/C0-control bytes through `Table::render()` or `FlexBox::render()` to confirm they're stripped. Given both methods exist specifically to prevent terminal-injection from data-origin content, this is a real coverage gap for a security-motivated code path.
- **No test for `Column::withFormatter()`** actually transforming a value through `render()`/`padded()` — only indirectly touched.
- **No test for `Align::Center`/`Align::End`/`Align::Start` producing visually distinct padding** in `FlexBox` — `testFlexBoxWithAlign()` only asserts the enum round-trips through `withAlign()`, never checks rendered output differs by alignment.
- **No test for wide/CJK characters or combining marks** in `FlexBox` (`measureWidth`/`alignCell`) despite `TableRenderer` having extensive wide-char/zero-width handling — asymmetric coverage between the two renderers.
- **`TableRenderer::reset()` is untested** — no test asserts that calling `reset()` forces a subsequent full-frame emission.
- **`Table::computeTotalWidth()` is untested directly** (only used internally by `addColumn`).
- Overall: 66 test methods across 6 files for a ~1,700-line `src/` — reasonable breadth for FlexBox/Table/Viewport basics, but the two security-sanitize methods and the sort-arrow/priority dead-code path above are the notable holes.

### 4. Missing .vhs/*.tape demos

None — coverage looks complete: `flexbox.tape`, `sort-filter.tape`, `table.tape` each drive a matching `examples/*.php`, all wired with the standard `TokyoNight` theme + `Type "php examples/<demo>.php"` pattern. No missing demo for Viewport/Scrollbar specifically, but those are largely re-exports of `sugar-bits` (which has its own demos), so this is likely intentional.

### 5. Security concerns

- Both `FlexBox::sanitize()` (`src/Flex/FlexBox.php:291-307`) and `Column::sanitize()` (`src/Table/Column.php:112-128`) correctly strip OSC/DCS sequences, bare ESC introducers, C0 controls, and DEL from data-origin content before rendering — good defensive posture against terminal escape-sequence injection via untrusted cell/item content. However, as noted in section 3, this logic is entirely untested, so a future refactor could silently regress it without a failing test to catch it.
- `Table`'s `$cursorStyle`/`$headerStyle`/column `$ansiStyle` and `FlexItem::$style` are raw ANSI parameter strings inserted directly into `Ansi::CSI . $style . 'm'` (`src/Table/Table.php:339-343`, `src/Flex/FlexBox.php:278-282`) with no validation that `$style` itself doesn't contain injected escape bytes — if `$style` ever originates from untrusted input (unlikely given it's typically a caller-supplied constant, but not enforced), it bypasses `sanitize()` entirely since only cell *content* is sanitized, not the style string.

### 6. Duplication with candy-sprinkles / sibling Table ports

- The monorepo now has **four** independent `Table` implementations: `candy-sprinkles/src/Table/Table.php` (static styled renderer, port of lipgloss table), `sugar-bits/src/Table/Table.php` (selectable/scrollable `Model`, cursor-driven), `sugar-table/src/Table.php` (full `Evertras/bubble-table` port: pagination, frozen columns, zebra striping, sorting, filtering), and this lib's `src/Table/Table.php` (sortable/filterable, port of `76creates/stickers`). The first two explicitly cross-reference each other in doc comments (`sugar-bits/src/Table/Table.php:18-19`: "Distinct from `\SugarCraft\Sprinkles\Table\Table`, which is a static styled renderer"). `sugar-stickers/src/Table/Table.php`'s doc comment (lines 10-22) does **not** mention any of the other three Table ports, despite `sugar-table` already implementing overlapping sort+filter+cell-style functionality with a more complete feature set (pagination, frozen columns). A reader landing in `sugar-stickers` has no signal pointing to the more capable `sugar-table` alternative or explaining when to pick one over the other.
- `Viewport`/`Scrollbar` in this lib are intentionally thin wrappers over `sugar-bits` (documented SSOT pattern in `CALIBER_LEARNINGS.md`) — no duplication concern there.
- No overlap found with `candy-freeze` (image→PNG/SVG snapshotting) or `candy-mosaic` (terminal image rendering) — those operate in an entirely different domain (raster image output) from `sugar-stickers`' text/ANSI layout concerns; the audit prompt's suspected overlap does not materialize.

### 7. Documentation gaps

- README's Table section (lines 24-29) advertises "Sortable columns — click to sort by any column, ascending/descending" as if the header displays a live indicator, but per section 1 the arrow indicator is not wired to `sortBy()` at all — the README oversells current behavior.
- `src/Table/Table.php`'s class doc-comment doesn't disambiguate itself from `candy-sprinkles`/`sugar-bits`/`sugar-table`'s `Table` classes (see section 6) — every other Table port in the repo does this cross-referencing except this one.
- No mention in README of the `Column::sorted()`/`sortPriority()` API surface at all, even though it's public and tested — an undocumented, effectively dead API from the Table's perspective.
- No `docs/lib/sugar-stickers.html` / `MATCHUPS.md` entries were checked in this pass (out of scope for a single-lib README/CALIBER_LEARNINGS review) but should be cross-checked separately given the sort-arrow gap may affect the public matchup status claimed there.

---

## sugar-table

### Prior findings — verification

**(1) Own sanitize implementation (visible-glyph + UTF-8 repair, never strips ANSI) — CONFIRMED.**
`sugar-table/src/Sanitize.php:15-60`. `Sanitize::value()` does: (a) `iconv('UTF-8','UTF-8//IGNORE', $s)` to repair invalid UTF-8 (line 32), (b) newline handling — either preserve `\n` (multiline explode path) or collapse CRLF/CR/LF to the visible `→` glyph `\xE2\x86\x92` (lines 40-46), (c) replace C0 controls `\x00-\x08\x0B\x0C\x0E-\x1F\x7F` with the visible middle-dot `\xC2\xB7` (line 51), (d) replace C1 controls `U+0080-U+009F` with the same middle-dot (line 57). It never touches ESC/`\x1b` or strips ANSI/SGR sequences — cell values are trusted to be user-authored plain text, not raw ANSI, so there's no CSI/OSC stripping step at all (unlike sugar-dash).

This is a third, independently-written implementation alongside:
- `candy-core/src/Util/Sanitize.php:24-28` — `controlChars()`: strips only C0, replaces `\n\r\t` with spaces, explicitly **preserves ESC** for SGR passthrough. No UTF-8 repair, no C1 handling.
- `sugar-dash/src/Output/Sanitize.php:33-99` — `untrusted()`: strips ANSI via `Ansi::strip()` first, then C0 (preserving `\n`/`\t`), then a byte-scanning lone-C1-byte stripper that walks backward through UTF-8 continuation bytes. No visible-replacement-character approach — everything is simply removed, not replaced with a glyph.

The sugar-table doc-comment (line 13) says "Mirrors the pattern in `SugarCraft\Query\CellValue::sanitize()`" — and indeed `candy-query/src/CellValue.php:50-67` is nearly byte-identical to `sugar-table/src/Sanitize.php` (same iconv repair, same `↵` newline glyph, same C0/C1 middle-dot replacement), down to using `mb_check_encoding`+`mb_convert_encoding` there vs `iconv` here as the only real difference. So there are effectively **four** near-duplicate sanitizers in the monorepo (candy-core, sugar-dash, sugar-table, candy-query), not three, with sugar-table/candy-query forming one twin pair and candy-core/sugar-dash being unrelated approaches. None of the three libraries import a shared implementation.

**(2) candy-query ReportsPage sugar-table API drift — CSV formula-injection regression tests: CONFIRMED RESOLVED, but scoped entirely to candy-query, not sugar-table.**
sugar-table itself has **no CSV/export functionality whatsoever** — `grep -rniE "csv|export"` across `sugar-table/src`, `sugar-table/tests`, and `sugar-table/README.md` returns zero hits. The formula-injection protection lives in `candy-query/src/Db/Export/CsvExporter.php` (`guardFormula()` at line 248, called from `writeHeader`/`writeRows` at lines 178-188, doc-commented "RFC-4180 compliant CSV with formula-injection protection" at lines 15/142), tested in `candy-query/tests/Db/Export/CsvExporterTest.php`. `ReportsPage` (`candy-query/src/Admin/Reports/ReportsPage.php`) does use `SugarCraft\Table\{Column,Row,RowData,Table}` and is covered by `candy-query/tests/Admin/Reports/ReportsPageTest.php` (465 lines) plus `ReportRunnerTest.php`/`ReportDefinitionTest.php` — no signs of stale/untested API usage against the current sugar-table `Table`/`Column`/`Row`/`RowData` surface. **Conclusion: this finding is really "candy-query has its own formula-injection-safe CSV exporter, unrelated to sugar-table" — sugar-table has no export path to audit for this concern at all** (see item 5 below).

### 1. Missing/incomplete functionality vs upstream bubble-table

- Feature set is broad and mostly matches/exceeds upstream: sorting (`SortBy`/`ClearSort`, `Table.php:750-798`), per-column filtering (`Filter`/`ClearFilter`/`ClearAllFilters`, lines 800-855), global search (`search`/`ClearSearch`, lines 855-937), pagination (`withPageSize`/`withPage`/`NextPage`/`PreviousPage`/`SelectPage`, lines 251-658), row selection (`SelectNext`/`SelectPrevious`/`withSelectedIndex`, lines 595-644), frozen columns (`withFrozenCols`, line 273), viewport virtualization (`withViewportHeight`/`withScrollY`, lines 301-315), row expansion (`withExpandedRows`/`toggleExpanded`, lines 500-575), and multiline mode (`withMultilineMode`, line 389).
- **No interactive/programmatic column resize.** `grep -n "[Rr]esize"` across `src/*.php` and `README.md` returns nothing. Column widths are fixed at construction time via `ColumnWidth` (Fixed/Percent/Dynamic/Content/Flex); there's no `withColumnWidth(idx, delta)`-style runtime resize API, no keyboard-driven resize handler analogous to `handleKey()`'s scroll support.
- **No mouse support.** `grep -n "Mouse\|mouse"` across `src/*.php` returns nothing — no click-to-select-row, click-to-sort-column-header, or drag-to-resize, even though `candy-mouse` exists elsewhere in the monorepo as a foundation lib. Upstream bubble-table (bubbletea ecosystem) is keyboard-first too, so this may be acceptable, but it's worth noting no mouse integration exists at all.
- No "sticky header" concept distinct from the existing `showHeader` — the header is always rendered as part of `renderToBuffer()`'s single buffer (`Table.php:1286-1340`), not decoupled from scroll region, so there's no independent "header pinned while body scrolls in a larger outer viewport" primitive beyond the built-in `viewportHeight`/`scrollY` (which does keep header+footer outside the scrolled row window — effectively equivalent, just not named "sticky").

### 2. Performance concerns

- **`computeTotalWidth()` documented non-convergence** (CALIBER_LEARNINGS.md `[pattern:computeTotalWidth-single-pass]`, `Table.php:1304-1326` per that note — method exists near line ~1149 `computeColumnWidths` / total-width call sites): single-pass approximation "may not converge when mixing `ColumnWidth::Percent` with `ColumnWidth::Dynamic`/`Content`." This is a known, self-documented incompleteness rather than a fresh finding — flagged here because it's still unresolved in current `src/Table.php` and no test exercises the non-convergent case to characterize the actual error bound.
- **Full in-memory filter/sort/search over the entire row set on every cache miss.** `buildFilteredSortedRows()` (`Table.php:970-1042`) runs `array_filter` for column filters, another `array_filter` for global search, and `usort` for multi-column sort — all directly over `$this->rows`, the complete dataset held in a plain PHP array (`RowData::get()` per row per filter key, `strcasecmp`/numeric coercion per sort comparison). This is cached (`$filteredSortedCache`, line 136) and invalidated only on `with*()` mutations that change filter/sort/search state (9 call sites null it out, e.g. lines 198, 217, 432, 460, 784, 792, 821, 835, 860), so repeated `View()` calls without state changes are O(1). But there is **no virtualization at the data layer** — a 500k-row dataset must fully materialize as `Row` objects in PHP memory and pay one full O(n log n) sort + O(n) filter/search pass whenever any filter/sort/search state changes, even though only `viewportHeight` rows are ever rendered (`renderToBuffer()`, `Table.php:1310-1327`, slices via `array_slice($rows, $this->scrollY, $this->viewportHeight)` — rendering itself IS virtualized, just not the upstream filter/sort/search computation). For "large datasets" as called out in the audit brief, this is the actual bottleneck, not rendering.
- No pagination/streaming hook for lazy row loading (e.g., a `RowSource` callback) — `withRows()`/`addRow()`/`addRows()` (lines 192, 575, 582) all take/append fully-materialized `Row[]` arrays.

### 3. Test coverage gaps

- Test method counts per file (via `grep -c 'public function test'`): `TableTest.php` 94, `TableGlobalSearchTest.php` 30, `TableViewportTest.php` 29, `ColumnTest.php` 27, `TableExpansionTest.php` 24, `TableColumnWidthTest.php` 20, `TableWrappingTest.php` 21, `TableFrozenColsTest.php` 18, `StyledCellTest.php` 16, `RowDataTest.php` 12, `TableBorderStyleTest.php` 12, `TableSanitizeTest.php` 12, `BorderlessTableTest.php` 14, `TableMultilineModeTest.php` 8, `LangCoverageTest.php` 3, `ColorRoundTripTest.php` 5, `GoldenRenderTest.php` 2. Overall breadth is strong (~347 test methods) and matches the feature surface well.
- **No test exercises the documented `computeTotalWidth()` non-convergence case** (mixed `Percent` + `Dynamic`/`Content` columns) — `TableColumnWidthTest.php` should have a regression test pinning current (possibly wrong) behavior so a future fix doesn't silently change output without a golden to compare against.
- **No large-dataset / performance regression test** (e.g. asserting `filteredSortedRows()` cache is actually reused across repeated `View()` calls, or a row-count ceiling smoke test) — the caching behavior described above is untested for cache-hit vs cache-miss counts.
- `GoldenRenderTest.php` has only 2 tests backing 2 golden fixtures (`table-basic.golden`, `table-zebra.golden`) — thin relative to the feature surface; most correctness is asserted via ad hoc string assertions in `TableTest.php` etc. rather than golden-file snapshots, per the `[pattern:assert-golden-ansi]` CALIBER_LEARNINGS note that only new render tests are asked to use goldens (existing coverage predates the convention).

### 4. Missing .vhs demos

None — `.vhs/basic.tape` + `.vhs/basic.gif` and `.vhs/features.tape` + `.vhs/features.gif` both exist and are wired up (`.vhs/basic.tape` uses TokyoNight theme, standard FontSize/Width/Height/Type/Enter/Sleep sequence per convention).

### 5. Security concerns

- sugar-table has **no CSV/export code path of its own** (confirmed above) — so it inherits no formula-injection risk directly; that risk is fully owned and handled by `candy-query/src/Db/Export/CsvExporter.php`'s `guardFormula()`. This is fine as-is, but worth flagging: **if any consumer builds a CSV/TSV export directly off `Table::Rows()`/`RowData` values without routing through something like `CsvExporter::guardFormula()`, it would have zero formula-injection protection** — sugar-table's own `Sanitize::value()` (terminal-control-char focused) does nothing to escape leading `=`/`+`/`-`/`@` characters that trigger spreadsheet formula execution on CSV import. This is a latent gap for any *future* export feature added directly to sugar-table, not a bug in the current code.
- Terminal-injection sanitization itself looks sound: C0/C1/DEL neutralized to a visible glyph rather than silently dropped (avoids "invisible" corruption), and it's applied before values reach the render buffer — `TableSanitizeTest.php` (12 tests) covers this.

### 6. Sanitize-text duplication — consolidation recommendation

Confirmed 3-4 divergent implementations exist (`candy-core/src/Util/Sanitize.php`, `sugar-dash/src/Output/Sanitize.php`, `sugar-table/src/Sanitize.php`, `candy-query/src/CellValue.php::sanitize()`), each with genuinely different semantics (candy-core preserves ESC for SGR passthrough and drops C1 handling entirely; sugar-dash strips ANSI outright plus does lone-C1-byte UTF-8-aware stripping; sugar-table/candy-query share near-identical logic: iconv/mb UTF-8 repair + visible-glyph replacement + C1 handling, but sugar-table adds a `preserveNewlines` mode candy-query's twin lacks). Given sugar-table's version is a near-line-for-line duplicate of candy-query's, and the doc-comment in `sugar-table/src/Sanitize.php:13` already explicitly points at `CellValue::sanitize()` as the "mirrored" original, this is the strongest consolidation candidate in the monorepo: extracting a shared `candy-core` (or new small foundation) helper with a `preserveNewlines` flag would eliminate one of the two copies with no behavior change, and let candy-query's `CellValue::display()` wrapper (NULL/scalar/JSON handling) remain a thin caller. candy-core's own `Sanitize::controlChars()` and sugar-dash's `Sanitize::untrusted()` solve a different problem (untrusted external process/network output where ANSI itself must be stripped) and should likely stay separate rather than being folded into the same helper.

### 7. Documentation gaps

- `README.md` Packagist badge references `sugarcore/sugar-table` (line 6: `https://packagist.org/packages/sugarcore/sugar-table`) while `composer.json` declares the package name as `sugarcraft/sugar-table` — inconsistent org name in the badge URL (copy-paste artifact, likely wrong on the badge, not the composer.json).
- README's Features list (lines 15-35) doesn't mention lack of column resize or mouse support — not required, but a "Known limitations" section (present in some sibling libs) is absent here, so a consumer has to read source/tests to discover these gaps.
- CALIBER_LEARNINGS.md's `computeTotalWidth-single-pass` note is the only place the non-convergence bug is documented — it isn't surfaced in the public README, so downstream consumers combining `Percent` + `Dynamic`/`Content` widths have no warning short of reading internal dev notes.

---

## sugar-tick

Scope confirmed: sugar-tick is **not** a Timer/Stopwatch widget — it is a privacy-first, WakaTime/TakaTime-style coding-time tracker (port of `Rtarun3606k/TakaTime`), JSONL-on-disk, with a SugarCharts-driven TUI dashboard (`sugar-tick/README.md:17`). No naming/scope collision with sugar-bits' `Timer`/`Stopwatch` widgets — see §6.

### 1) Missing/incomplete functionality

- `src/Ignore/SugarTrackIgnore.php` (`.sugartrackignore` glob-pattern matcher, fully implemented + tested in `tests/SugarTrackIgnoreTest.php`) is **never wired into the CLI**. `grep -n "Ignore" bin/sugar-tick` returns nothing — the `push` subcommand (`bin/sugar-tick:41-60`) does not consult it, so the advertised "ignore files" capability has no way to actually suppress a heartbeat. It's a dead/orphan feature from the user's perspective.
- `src/Storage/SqliteBackend.php` (full SQLite3 schema: `heartbeats` + `milestones` tables, insert/query/insertMilestone/milestones) is likewise never referenced outside its own test (`tests/Storage/SqliteBackendTest.php`) — not used by `Store`, not selectable from `bin/sugar-tick`, no CLI flag or env var to opt into it. `composer.json` also does not declare `ext-sqlite3` as a requirement even though this class hard-depends on `\SQLite3` (`SqliteBackend.php:19`) — if a user isn't aware of the class it will simply not appear as broken, but if they instantiate it without the extension it fails uninformatively.
- `src/Milestone.php` value object is never constructed/read anywhere except being conceptually paired with `SqliteBackend::milestones()` — which itself returns raw arrays, not `Milestone` instances (`SqliteBackend.php:81-93`), so the two milestone-related pieces don't actually compose. No CLI subcommand to add/list milestones.
- `bin/sugar-tick` has no `milestone` subcommand despite `Milestone` + `SqliteBackend::insertMilestone`/`milestones()` existing — half-built feature.
- CSV/JSON/ICS export and `gaps`/`backup` subcommands work but have no dashboard-side surfacing (e.g., no way to view gaps or backups from the TUI, only from CLI flags) — acceptable for v1 scope but worth noting as a gap vs. a "full" time-tracker.

### 2) Performance concerns

- `Store::loadDay()` (`src/Store.php:39-60`) reads the entire day's JSONL file into memory via `file_get_contents` + `explode("\n", ...)`, then json_decodes line by line. Fine at typical heartbeat cadence, but there is no line-count/size cap — a very chatty editor plugin (heartbeat per keystroke rather than per-interval) would grow a single day's file unbounded and this call would scale linearly with no streaming/line-buffered alternative.
- `Store::append()` (`src/Store.php:96-114`) uses `file_put_contents($file, $line, FILE_APPEND)` with no `LOCK_EX`. This is a deliberate design choice per the class doc ("never holds a file lock") relying on POSIX atomic small writes, but it's only safe below `PIPE_BUF`; a heartbeat with an unusually long `file`/`tags` payload could in theory interleave under concurrent writers (multiple editor plugins). Not currently guarded against.
- `Dashboard::recompute()` / `Stats::compute()` re-folds the full multi-day beat list on every reload/shift (`src/Dashboard.php:84-96`, `src/Stats.php:29-97`) — pure and simple, consistent with the "no caching" doc comment, but for very long lookback windows (`export`/`gaps` accept arbitrary `$days` from the CLI with only a `max(1, ...)` floor and no ceiling — `bin/sugar-tick:64`, `:86`) a user could request e.g. `export csv 36500` and force loading/parsing 100 years of (mostly nonexistent) daily files; low risk since `loadDay` short-circuits on missing files, but there's no explicit upper bound the way `push`'s duration is clamped to a year (`bin/sugar-tick:52`).

### 3) Test coverage gaps

- No test exercises `bin/sugar-tick` itself (the CLI dispatch script) — argument parsing, `help`/unknown-command paths, `push` missing-args exit code, `export`/`gaps`/`backup` subcommand wiring are all untested; only the underlying classes (`Store`, `GapsReport`, `AutoBackup`, exporters) have unit tests.
- `SugarTrackIgnore` has a unit test but no integration/behaviour test proving it's actually consulted anywhere (consistent with it not being wired in — see §1).
- `SqliteBackend` has a dedicated test but again purely in isolation; no test proves the two storage backends (`Store` JSONL vs `SqliteBackend`) can be switched between or coexist.
- `Milestone` has a unit test (`tests/MilestoneTest.php`) covering `fromArray`/`toArray`/construction validation presumably, but no test ties it to `SqliteBackend::insertMilestone`/`milestones()` (which don't even return `Milestone` instances).
- No test for `Dashboard`'s `WindowSizeMsg` narrow-width branch in `Renderer::render` (stacked vs. side-by-side layout threshold at width 56, `src/Renderer.php:32-34`) beyond whatever `RendererTest.php`/`DashboardTest.php` may cover — worth confirming both branches are hit (not verified in this pass; recommend checking `tests/RendererTest.php` for a `width < 56` case).

### 4) Missing .vhs/*.tape demos

- README embeds **two** demo GIFs — `.vhs/dashboard.gif` and `.vhs/push.gif` (`README.md:14-15`) — but only `.vhs/dashboard.tape` (and its rendered `.vhs/dashboard.gif`) exists on disk. There is no `.vhs/push.tape`, so `push.gif` referenced in the README is either stale/broken or was manually added outside the normal VHS pipeline. This is a concrete broken-doc-asset finding.
- No demo at all for `export`, `gaps`, or `backup` CLI subcommands (understandable — they're one-shot, non-interactive output rather than TUI, so may be legitimately exempt, but nothing documents that exemption).

### 5) Security concerns

- `src/Export/CsvExporter.php:22-33` (`safeCell`) proactively neutralizes CSV-formula-injection prefixes (`=`, `+`, `-`, `@`, tab, leading `\r`) before writing user-controlled `project`/`language`/`file` fields into CSV — good defensive practice, worth calling out as a positive.
- `src/Ignore/SugarTrackIgnore::isIgnored()` (`src/Ignore/SugarTrackIgnore.php:50-61`) uses `fnmatch()` against both `basename($path)` and the raw `$path` — no path traversal risk since it's read-only pattern matching, not filesystem access, but moot anyway since it's unwired (§1).
- `AutoBackup::rotate()` (`src/Backup/AutoBackup.php:25-57`) does no permission/ownership check on `$backupDir`/`$dirs`; uses `@mkdir`/`@copy` with suppressed errors — silent partial failures (a full disk or permission-denied backup dir just returns a lower count with no diagnostic), acceptable for a best-effort utility but worth a log line.
- No sensitive-data scrubbing on `file` paths written into heartbeats — a heartbeat's `file` field is the raw path from the editor plugin; if a user works in a directory with credentials in the filename this leaks into local JSONL. Given the "privacy-first" pitch (`README.md:17`) — data never leaves the machine, so this is low severity, but there's no path redaction/allowlist analogous to `.sugartrackignore` actually filtering *write* time (it can't, since it's unwired).

### 6) Duplication with sugar-bits Timer/Stopwatch or candy-async

- **No functional overlap found.** `sugar-bits/src/Timer/Timer.php` and `sugar-bits/src/Stopwatch/Stopwatch.php` are generic TUI countdown/elapsed-time widgets (Bubble Tea `Model`s for interactive timing), whereas sugar-tick is a persistent activity-log/analytics tool (JSONL store + stats aggregation + TUI report). Different problem domains; no shared code, no redundant re-implementation.
- `candy-async` usage is real and narrow: `Store::append()` accepts an optional `CancellationToken` (`src/Store.php:7,89-111`) purely for best-effort I/O cancellation, matching the documented pattern in `CALIBER_LEARNINGS.md:6-8` ("true preemption requires async rewrite"). No duplication of candy-async's own functionality — sugar-tick only consumes `CancellationToken`, doesn't reimplement it.

### 7) Documentation gaps

- README documents only `push` and the bare dashboard invocation (`README.md:19-27`) — it omits `export`, `gaps`, and `backup` subcommands entirely, even though all three are fully implemented in `bin/sugar-tick:62-124` and translated (`lang/en.php:16-23`). A new user reading the README would not discover these exist.
- README's "Architecture" table (`README.md:41-51`) lists only `Heartbeat`/`Store`/`Stats`/`Dashboard`/`Renderer` — omits `Milestone`, `Backup\AutoBackup`, `Storage\SqliteBackend`, `Ignore\SugarTrackIgnore`, `Report\GapsReport`, and the `Export\*` classes entirely, understating the actual surface area of `src/`.
- `push.gif` is referenced but not backed by a tape/asset in the repo (see §4) — a doc-vs-repo inconsistency.
- `composer.json` `keywords` array has a literal duplicate: `"sugarcraft"` appears twice (`composer.json:6-13`, lines 12 and 13) — cosmetic but should be deduped.
- No mention anywhere (README or CALIBER_LEARNINGS) of the SQLite backend, ignore-file feature, or milestones — since none are wired up, this is at least internally consistent, but if these are intended future work it isn't flagged as such (no TODO/roadmap note), so they read as either abandoned or accidentally-shipped scaffolding.

---

## sugar-toast

### 1. Missing/incomplete functionality

- Stacking/queueing of multiple toasts is **implemented**: `Toast::$queue` (array), `withMaxConcurrent()`/`withOverflow()` (`sugar-toast/src/Toast.php:47-51,134-145`), two-pass stacking in `View()` (`sugar-toast/src/Toast.php:456-491`). Not a gap.
- Auto-dismiss timing config is **implemented**: `withDuration()` (`src/Toast.php:99-105`), per-alert `$expiresAt` override, `nextExpiry()`/`secondsUntilNextExpiry()` loop-integration helpers (`src/Toast.php:381-408`). Not a gap.
- Severity levels/icons are **implemented**: `ToastType` (Error/Warning/Info/Success) with `nerdIcon()`/`unicodeIcon()`/`asciiPrefix()`/`color()` (`src/ToastType.php:22-69`). Not a gap.
- **Fade/animation is a functional stub only.** `withAnimationDuration()` (`src/Toast.php:153-156`) stores a float but nothing in `View()`/`renderAlert()` reads `$this->animationDuration` to alter output — grep confirms no other reference to the field outside the setter and the constructor default. README (`README.md:238-247`) is honest that "CubicBezier deferred", but the field is currently 100% dead weight (no observable effect at any value, not even a documented partial behavior like time-based alpha/reveal). Consider either wiring a minimal reveal effect or removing the public setter until it does something, since right now calling it silently no-ops.
- No **mouse/click wiring** for `Action` buttons — `Action::callback()` (`src/Action.php`) must be invoked manually by host key/mouse handlers; there's no dispatch helper (e.g., "given a click at (x,y), which action fired") even though the layout is computed internally in `renderAlert()`. Every consumer must reimplement action-button hit-testing.
- `ToastType` is a fixed 4-case enum — no path for a host app to register a 5th custom severity (e.g., "debug"/"critical") short of using the raw `alert(string $type, …)` escape hatch, which then throws `InvalidArgumentException` for unknown strings (`src/Toast.php:190-193`). `tests/ToastCustomTypeTest.php` exists but (per its name) likely just re-tests the existing 4 types via string lookup rather than truly custom types — worth confirming intent isn't "custom types," since the enum can't be extended.

### 2. Performance concerns

- **Double rendering per alert per `View()` call.** The sizing pass (`src/Toast.php:460-467`) calls `renderAlert($alert)` to compute `$alertHeight`, then the placement pass calls `renderAlertToBuffer($alert, $contentWidth)` (`src/Toast.php:481`) which calls `renderAlert($alert)` again internally (`src/Toast.php:575`). Every `alert()`/`progressToast()`/tick-driven re-render therefore re-runs `wordWrap()`, `Width::string()`, and string concatenation twice per active alert, per frame. For a fast-refresh event loop (candy-core `Program` re-renders `view()` on every `Msg`), this doubles the formatting cost for no functional reason — the height could be read directly off the already-built `Buffer` (`Buffer::height()`, used at `src/Toast.php:482`) instead of a separate first pass.
- `placeAnsiStringAt()` re-parses freshly generated SGR strings from scratch every frame (byte-by-byte scan with per-cluster `grapheme_extract()` calls when the intl extension is present) — no memoization keyed on `(alert, width)`, so an unchanged toast (e.g., a persistent alert with no progress bar) pays full ANSI-parse + grapheme-cluster cost on every re-render even though its content is identical. Not a functional bug, just a hot-path allocation cost worth profiling if `sugar-toast` is composited inside a tight `candy-core` loop with many concurrent toasts.
- `wordWrap()` (`src/Toast.php:772-808`) allocates a new `$result` array and calls `Width::string()` per candidate substring inside a nested loop — O(n²)-ish for long messages with many words, though toast messages are typically short so this is low risk in practice.

### 3. Test coverage gaps

- 143 tests pass locally (`vendor/bin/phpunit` — OK, 143 tests, 263 assertions). Coverage is broad (golden-render, animation, border, custom-type, esc-close, history, max-concurrent, next-expiry, persistent, progress, rendering, overflow, action, lang) but:
  - **No test exercises `withAnimationDuration()` actually changing rendered output** — `tests/ToastAnimationTest.php` (72 lines) should be checked for whether it merely asserts the field round-trips via `mutate()`/fluent chain rather than asserting any visual difference, since (per finding above) there currently is no visual difference to assert. If the test only checks the setter is fluent/immutable, that's a coverage gap masquerading as a passing suite — it can't catch a regression in "animation does nothing" because nothing is expected to happen.
  - **No dedicated `SymbolSet` unit test.** `src/SymbolSet.php` is a bare 3-case enum with no methods, so this is low priority, but `ToastType::icon(SymbolSet)` dispatch (`src/ToastType.php:74-81`) is only exercised indirectly through render tests — there's no test asserting all 3×4 = 12 (type × symbol-set) icon combinations resolve to the expected glyph/prefix.
  - **No standalone `PositionTest`** covering the non-Middle 6 cases' `xOffset()`/`yOffset()` math directly (only `tests/PositionMiddleTest.php` exists, 121 lines, focused on Middle-stacking per `CALIBER_LEARNINGS.md`'s documented `top-stacking-overlap` and `middle-position-stacking` gotchas). Top/Bottom offset math is presumably covered incidentally via `GoldenRenderTest`/`ToastRenderingTest`, but a fixture regression there wouldn't pinpoint which `Position` case broke.
  - **No test for malformed/unterminated ANSI input** in `placeAnsiStringAt()` — e.g., a message containing a bare `"\x1b["` with no terminating byte in range `0x40-0x7e` before end-of-string. The scan loop (`src/Toast.php:602-609`) would run `$j` to `$len` and then compute `\substr($s, $i + 2, $j - $i - 3)`, which for a short trailing fragment can produce a negative length argument — worth a regression test to confirm current PHP silently returns `''`/no crash rather than a TypeError under strict_types (PHP's `substr()` accepts negative length as "stop N chars from the end," which for tiny strings can yield unexpected but non-fatal results; still untested).
  - **`cancelAlert()`/`extendAlert()` out-of-bounds paths**: both no-op silently when `$index` is out of range (`src/Toast.php:325-343`), but grep shows no test file specifically titled around bounds-checking for these two methods (as opposed to `ToastNextExpiryTest`/`ToastPersistentTest` which cover adjacent expiry behavior) — worth confirming existing tests hit the early-return branch, since AGENTS.md's "coercion: clamp edge cases" convention calls this out explicitly.

### 4. Missing `.vhs/*.tape` demos

- `.vhs/basic.tape` and `.vhs/types.tape` exist and are wired to `examples/basic.php` / `examples/types.php` respectively — no gap for the two documented demo scripts.
- However, sugar-toast's headline features per the README — **stacking/overflow** (`Overflow::DropOldest/DropNewest/Enqueue`), **9-position placement**, **progress toasts**, and **action buttons** — have no dedicated `.tape` demonstrating them visually; both existing tapes just run `examples/basic.php`/`examples/types.php` (need to check those example scripts' content to confirm whether they already cover positions/overflow in one combined demo, or whether a `stacking.tape`/`actions.tape` would be additive). Given `.vhs/` only has 2 tapes but the feature surface is large (9 positions, 3 overflow strategies, progress bars, actions, history log), a `stacking-overflow.tape` and `actions.tape` would give better demo coverage per `AGENTS.md`'s VHS conventions.

### 5. Security concerns

- **Message content is not passed through to the terminal raw** — `renderAlert()` embeds `$alert->message` directly into an ANSI-bearing string (`src/Toast.php:512-560`), but that string is never written straight to stdout. It is re-parsed by `placeAnsiStringAt()` cell-by-cell and only re-emitted through `Buffer::toAnsi()` (`src/Toast.php:592-637`, `View()` return at `:493`). Because the CSI-sequence scanner at `src/Toast.php:601-614` treats *any* `\x1b[...<final-byte 0x40-0x7e>` sequence as an SGR/style attempt (it does not check that the final byte is specifically `'m'`), a message containing an embedded escape like a cursor-move (`\x1b[10;20H`) or screen-clear (`\x1b[2J`) is swallowed into `sgrToBufferStyle()`, which only recognizes `0`/`1`/`30-37`/`90-97` codes and otherwise silently ignores the rest — so the sequence is consumed and not re-emitted verbatim. This is accidental/incidental sanitization (a side effect of the SGR-parsing logic) rather than an explicit, tested security boundary. **No test asserts this behavior intentionally** (e.g., "a message containing `\x1b[2J` renders literally as visible/escaped text or is stripped, and never reaches the composed output as a live control sequence"). Recommend adding an explicit regression test plus a doc comment at `placeAnsiStringAt()` clarifying that non-`m` CSI sequences are intentionally discarded (not just an artifact of not checking the final byte), since a future refactor that "fixes" the scanner to only match `m`-terminated sequences could reopen a real injection path (arbitrary CSI bytes from `$alert->message` would then flow through unparsed into the final `\x1b[`-laden output written to the buffer's raw cell content).
- Lone `\x1b` not followed by `[` is zero-width per `graphemeWidth()` (`src/Toast.php:714`, range 0x0E-0x1F includes 0x1b) so it's dropped rather than rendered — good, but again this is a side effect of the general C0-control-filtering table, not a change specifically motivated/tested as an ANSI-injection defense.
- No length cap on `$alert->message` — a very long single "word" (no whitespace) is wrapped by cell-width via `wordWrap()`'s oversized-word branch (`src/Toast.php:787-799`), so it won't overflow the box, but an attacker-controlled message with millions of characters would still cost O(n) `Width::string()`/wrapping work per render call with no truncation guard — minor DoS-via-render-cost concern if message content is ever attacker-controlled (e.g., piped from a network service) rather than developer-authored.

### 6. Duplication with candy-log / sugar-dash

- **sugar-dash has its own, separate `Toast` implementation** at `sugar-dash/src/Components/Toast/` (`Toast.php` 449 lines, `Notification.php`, `NotificationQueue.php` 206 lines, `NoticePosition.php`, `Level.php`). It is a *different* design: a static, immutable `SugarCraft\Dash\Components\Toast\Toast` value object built via `fromNotification()`/`fromQueue()` bridging a `Notification` DTO (message/title/level) into a styled single-box render (`sugar-dash/src/Components/Toast/Toast.php:1-60`), with its own `Level` enum (Info/Warning/…) and its own `NotificationQueue` for stacking — i.e., sugar-dash reimplements queueing, severity levels, and position enums (`NoticePosition.php`) independently of `sugar-toast`'s `Toast`/`ToastType`/`Position`/`Overflow`. This is a real duplication risk: two libraries in the same monorepo both model "queued severity-leveled toast notifications with position placement," with no shared base and no cross-reference in either README. Given `sugar-toast` is the more complete, purpose-built, dedicated port (BubbleUp) with auto-dismiss timers, overflow strategies, action buttons, and a history log, sugar-dash's `Components/Toast/*` looks like it should either (a) delegate to `sugar-toast`'s `Toast`/`Alert` as its rendering engine (mirroring how `sugar-crush` absorbed `candy-crush` per project memory), or (b) at minimum cross-link in both READMEs so consumers don't have to guess which one to use for a dashboard app that wants toast notifications. Not verified whether sugar-dash already depends on `sugar-toast` in its composer.json — worth checking before scoping a consolidation PR.
- **No overlap with `candy-log`.** `candy-log` (`Logger`, `PsrBridge`, `StandardLogAdapter`, `PanicFormatter`, `Styles`, `PartsOrder`) is a structured/leveled logging library (PSR-3 bridge, syslog-style key=value output, panic/backtrace formatting) — it targets persistent log streams, not floating/transient TUI overlay widgets. No functional or API overlap with `sugar-toast`'s ephemeral alert-queue rendering; the two solve different problems (log records vs. transient UI notifications) despite both being "message surfacing" in the loosest sense.

### 7. Documentation gaps

- README's "Animations" section (`README.md:238-247`) correctly flags the stub nature but doesn't explicitly warn "no visible effect at any value yet" — a reader could reasonably expect *some* incremental fade even without full CubicBezier easing; recommend an explicit "currently a no-op; reserved for a future phase" callout to prevent bug reports.
- No documented guidance on **thread/re-entrancy safety of `Action::$callback`** — since `Toast` is otherwise pure/immutable, but `Action` wraps a `\Closure` that presumably captures host state (e.g., "reconnect logic") and is invoked outside the library's control; README shows the pattern (`README.md:196-216`) but doesn't mention that invoking the callback does not, by itself, dismiss or remove the alert — a consumer must separately call `dismiss()`/`clear()` after acting on the button, which isn't spelled out anywhere in README or `CALIBER_LEARNINGS.md`.
- No mention in README of the **max-message-length / long-message performance note** (see Security section) — not a blocker, just an omission for anyone piping untrusted/large message content into `alert()`.
- `CALIBER_LEARNINGS.md` documents past defect fixes (`byte-slice-overlay`, `top-stacking-overlap`, etc.) thoroughly, but has no entry for the double-render-per-frame performance characteristic noted above (finding 2) — future maintainers optimizing `View()` won't have a breadcrumb explaining why `renderAlert()` is called twice per alert per frame (it looks unintentional rather than deliberate).

---

## sugar-veil

### 6) candy-mouse / candy-zone dual dependency (confirmed + expanded)

Confirmed the prior finding, and it's worse than a mere "both imported" — the `candy-zone` half is **fully dead weight**:

- `sugar-veil/src/Veil.php:11-13` imports `SugarCraft\Mouse\Mark`, `SugarCraft\Mouse\Scanner`, `SugarCraft\Mouse\Zone` (candy-mouse) — these are the classes that actually do the work: `Mark::wrap()` (line 373-376), `Scanner::scan()`/`hit()` (lines 350-365).
- `sugar-veil/src/Veil.php:20` additionally imports `SugarCraft\Zone\Manager` (candy-zone) — used **only** as a passively-stored, never-invoked property:
  - `Veil.php:62-63` — `private readonly ?Manager $manager` field, doc'd "Stored manager for back-compat only (deprecated)".
  - `Veil.php:284-287` — `withManager(Manager $manager)`, `@deprecated`, just calls `mutate(manager: $manager)`.
  - `Veil.php:295-298` — `manager()` accessor, `@deprecated`, just returns the stored value.
  - `Veil.php:327-338` — `isClickOutside()`, the one place a Manager *could* matter, does **not** reference `$this->manager` at all; it delegates entirely to `$this->hit($mouse->x, $mouse->y)` (the candy-mouse `Scanner`).
  - Grep confirms: outside of storage/accessor/mutate plumbing, `$this->manager` is never read anywhere in `src/`.
- README.md:230-239 documents this explicitly: "`withManager(Manager $manager)` is retained for backward compatibility only... does **not** drive `isClickOutside()`."
- `composer.json:33` (`sugarcraft/candy-zone`) and its matching path-repo (`composer.json:122-128`) exist solely to support this inert back-compat shim. Same pattern as `sugar-crumbs`: **candy-zone could be dropped entirely** by removing `withManager()`/`manager()` (or keeping them as a no-op `mixed $manager` param with no type import), with zero functional change — `isClickOutside()`/`hit()`/`scan()`/`mark()` already work purely through candy-mouse.
- Bonus: `tests/VeilTest.php:335-342` (`testIsClickOutsideReturnsFalseWhenManagerNotSet`) is a byte-for-byte duplicate of `testIsClickOutsideReturnsFalseWhenDismissDisabled` (lines 326-333) — same body, same assertion, but the name references "Manager" even though the test never sets or omits a Manager and the code path it exercises doesn't consult one. This is a leftover from the Manager→Scanner migration and should either be deleted or rewritten to actually test "no manager set" semantics (which no longer exist).

### 1) Missing/incomplete functionality

- **No focus trap while a veil is open.** `grep -rin "focus" src/ tests/ README.md` returns nothing. `sugar-veil` is a pure string-compositing library with no `Model`/`update()` contract (unlike TEA components), so it has no concept of routing key events to the modal vs. the background. Any consumer wiring a veil into a `candy-core` `Program` must manually intercept `KeyMsg`s themselves; there's no helper (e.g. an `isKeyForVeil()` or focus-scope abstraction) and the README doesn't call out this responsibility, so it's easy for an integrator to forget and let keystrokes leak to the background model while a modal is showing.
- **Backdrop click-to-dismiss exists but is opt-in and manual.** `withClickOutsideDismiss()` + `scan()` + `isClickOutside()` (Veil.php:202-205, 350-365, 327-338) implement the mechanics, but there's no `update()`-level integration — the consumer must call `scan($rendered)` after every render and manually call `isClickOutside()` against incoming `MouseMsg`s and decide to remove the veil. Given `VeilStack` has no `Model`, there's no single call the app can make to get "click outside → auto-dismiss" for free.
- **Stacked/nested modals: composited but not click-testable as a stack.** `VeilStack::compositeAll()` (VeilStack.php:105-122) composites multiple veils, but each `Veil`'s own `scan()`/`hit()`/`isClickOutside()` only knows about its own zone from its own last `scan()` call — there's no `VeilStack`-level `hit()`/`isClickOutside()` that accounts for z-order (e.g., a click that lands inside veil A's rectangle but veil B, with higher z-index, occludes that exact cell). A consumer naively calling `isClickOutside()` on a lower-z veil while a higher-z veil visually covers that area would get a false "outside" result routed to the wrong layer.
- **No keyboard-only nested-modal semantics (e.g., Escape-to-close-topmost).** `VeilStack` has `maxZIndex()`/`minZIndex()`/`sorted()` but no helper to identify "the topmost veil" as an object (only its z-index), which a consumer would need for standard modal-stack behavior (Esc closes only the top layer).

### 2) Performance concerns

- **`bufferFromOutput()` multibyte extraction is O(n²).** `src/Veil.php:671-714`, specifically line 700: `$char = \mb_substr($line, $pos, 1);` inside a `while` loop that advances `$pos` one byte/char at a time. `mb_substr` re-scans the string from the start (or does an internal multibyte re-walk) on every call; for a wide line this turns per-frame buffer construction into effectively quadratic work. This runs on **every** `composite()` call once dimensions are stable (it's the buffer source for `RenderSession::diff()`, `RenderSession.php:70-80`), i.e. once per rendered frame, potentially many times per second in a live TUI. A single pre-split via `mb_str_split($line)` (or iterating with `Width`'s existing ANSI-aware helpers used elsewhere in the file, e.g. `Width::truncateAnsi`) would make this linear.
- **Full O(width×height) buffer rebuild every frame regardless of diff size.** `composite()` (Veil.php:454-539) always rebuilds a full row-by-row string via `dimLine()`/`Width::truncateAnsi()`/`Width::padRight()`/`Width::dropAnsi()` for the *entire* background, even when only the foreground moved by one animation frame (i.e., during `animate()` progress sweeps in a for-loop as shown in the README's own Animations example, README.md:112-116). Diffing only kicks in at the buffer/ANSI-op emission stage, not the string-composition stage, so animation loops re-render (not just re-diff) the whole background every tick. For large terminals + high FPS animation this is real CPU-per-frame that could be mitigated by only recomputing changed rows (rows within `[y, y+fgHeight)` plus rows whose dim state changed).
- **`VeilStack::composite()`/`compositeAll()` do work per veil per full background** (VeilStack.php:77-93, 105-122): each veil in the stack triggers another full `composite()` pass (see above) over the *entire* background dimensions, so an N-veil stack is O(N × width × height) even if most veils are small. No early-exit/clipping to each veil's own bounding box.

### 3) Test coverage gaps

- **Duplicate/mislabeled test**: `tests/VeilTest.php:335-342` `testIsClickOutsideReturnsFalseWhenManagerNotSet()` is identical to `testIsClickOutsideReturnsFalseWhenDismissDisabled()` (lines 326-333) and doesn't actually exercise any Manager-related code path (see finding 6). No test actually confirms `manager()`/`withManager()` have zero effect on `isClickOutside()`'s outcome when a Manager IS set with conflicting zone data — i.e., there's no regression test proving the deprecated Manager path can't accidentally leak back into the hit-testing logic.
- **No `VeilStack`-level click-outside / hit-testing tests.** Given `VeilStack` composites multiple veils, there's no test verifying hit-testing behavior across a multi-veil stack (see functionality gap above) — every `scan()`/`hit()`/`isClickOutside()` test in `VeilTest.php` operates on a single `Veil` in isolation.
- **No animation-progress + backdrop combination tests beyond a couple of cases.** `VeilAnimationTest.php` covers animation kinds and progress boundaries reasonably well, but there's no test combining `withAnimation()` + `withBackdrop()` + `withClickOutsideDismiss()` all together (a realistic "animated dismissible modal" configuration) to confirm no interaction bugs (e.g., does `scan()` see the animated/offset position correctly for hit-testing mid-animation?).
- **No large-input / performance-regression test** for `bufferFromOutput()`'s multibyte path (e.g., a wide multibyte line asserting the function completes within a time bound), so the O(n²) issue above has no regression guard if someone "fixes" it and reintroduces an equivalent slowdown, or if a much-larger fixture is added later without anyone noticing the cost.
- **`resetPreviousFrame()` and `withoutSession()` interaction with `VeilStack`** — `VeilStackTest.php` exercises `compositeAll`/`composite` correctness but doesn't test what happens if a caller forgets to call `withoutSession()` semantics themselves (i.e., stacks built manually via repeated `Veil::composite()` calls outside `VeilStack`, chaining full/diff state incorrectly) — this is a foot-gun the CALIBER_LEARNINGS.md itself flags (lines 87-93) but there's no test demonstrating the corruption that `withoutSession()` prevents, only tests of the happy path.

### 4) Missing .vhs demos

None — `.vhs/basic.tape` and `.vhs/multiple.tape` (with corresponding `.gif`s) exist and pair with `examples/basic.php` and `examples/multiple-overlays.php`. However:
- Neither tape appears to demo `withBackdrop()`, `withAnimation()` (SLIDE/FADE/SCALE), or `withClickOutsideDismiss()` — the three most visually distinctive features per the README's own feature list. Only static positioning (`basic.php`) and multi-overlay stacking (`multiple-overlays.php`) are demoed. Worth adding a third tape/example showing an animated + dimmed modal to visually validate the animation code path, since animation is otherwise only unit-tested against string output, never visually confirmed.

### 5) Security concerns

- **No injection-relevant issues found.** `dimLine()` (Veil.php:558-581) only interpolates internally-computed integers (`$r`/`$g`/`$b`, each `\round(255 * factor)`, always 0-255) into the ANSI escape — no user-controlled string reaches the SGR sequence unescaped.
- `Mark::wrap()` (candy-mouse `src/Mark.php`) embeds the caller-supplied `$id` verbatim between U+E000/U+E001 sentinels with no validation that `$id` doesn't itself contain a sentinel codepoint. If a consumer ever derives zone ids from untrusted input (unlikely but not guarded against), a crafted id containing U+E000/U+E001 could desynchronize the Scanner's zone parsing. This is a shared candy-mouse concern more than sugar-veil-specific, but sugar-veil's `mark(string $id, string $content)` (Veil.php:373-376) passes `$id` straight through with no sanitization or documented constraint (e.g., "id must not contain U+E000/U+E001" isn't stated anywhere in sugar-veil's docs).
- No other security-relevant surface — this is a pure string-transform library with no I/O, no file/network access, no eval/deserialization.

### 7) Documentation gaps

- **README doesn't mention the focus-trap / key-routing responsibility** at all (see functionality gap #1) — a consumer reading the Quick Start could reasonably assume `Veil` handles input routing since it's presented alongside `candy-core`/TEA-adjacent examples elsewhere in the ecosystem; it should explicitly say "sugar-veil does not intercept KeyMsg — route background updates yourself while a veil is displayed."
- **`withManager()`/`manager()` deprecation is documented (README.md:230-239) but the composer.json dependency it requires (`candy-zone`) is not mentioned anywhere** — a reader has no way to know from the README that keeping this one deprecated method around is the *entire* reason `candy-zone` is a required dependency (vs. an optional/dev one).
- **No documented complexity/perf characteristics** for `composite()` per-frame cost or the `bufferFromOutput()` diff-buffer construction, despite the "Buffer diffing" section (README.md:241-255) making specific byte-count performance claims ("~8 bytes... instead of ~1940 bytes") — those numbers describe the *diff encoding* savings but say nothing about the *string-composition* cost still paid every frame (see Performance section above), which could mislead readers into believing the whole render pipeline is O(changed cells) when only the transport-encoding stage is.
- **CALIBER_LEARNINGS.md line 59 is stale**: "`isClickOutside(MouseMsg $mouse): bool` returns `false` when either `clickOutsideDismiss` is `false` or `manager` is `null`... delegates to `Manager::anyInBounds($mouse)`" — this describes the *old* Manager-based implementation, not the current Scanner-based one (Veil.php:327-338, which never references `$this->manager`). The doc was not updated when the code migrated to candy-mouse's `Scanner`/`Mark`, even though a "Mouse hit-testing" section further down (CALIBER_LEARNINGS.md:72-75) does correctly describe the new self-contained approach — the file now contradicts itself between lines 57-61 and 72-75.

---

## sugar-wishlist

**Scope correction (important):** sugar-wishlist is NOT a to-do/task/wishlist-tracking app. It is the PHP port of `charmbracelet/wishlist` — a TUI *SSH endpoint directory/launcher*: it loads a list of SSH hosts from YAML/JSON/`~/.ssh/config`, renders a filterable picker (`src/Picker.php`), and on Enter replaces the current process with `ssh` via `pcntl_exec` (`src/Launcher.php`). There are no categories/tags/due-dates/reminders/persistence-of-completed-items concepts to audit here — that framing belongs to a different kind of app and does not apply to this lib. Confirmed via README.md:16 ("PHP port of charmbracelet/wishlist — a TUI directory of SSH endpoints") and composer.json:3.

### 6) Relationship to candy-wish — confirmed unrelated (naming coincidence only)

- `candy-wish` (composer.json:3, candy-wish/README.md) is a port of `charmbracelet/wish` — an **SSH server** middleware framework (build TUIs anyone can `ssh user@host` into, server-side).
- `sugar-wishlist` is a port of `charmbracelet/wishlist` — an **SSH client-side** picker that launches `ssh` as a client.
- One is server middleware, the other is a client launcher/directory. No shared code, no dependency between them (sugar-wishlist/composer.json:32-159 requires candy-core, sugar-bits, candy-fuzzy + transitive path-repos; candy-wish is not among them). The similar names are upstream-inherited (Charm's `wish` vs `wishlist`), not a SugarCraft duplication. No functional overlap to reconcile.
- No other list-management lib duplication found — sugar-wishlist's "list" is a static SSH-host directory, distinct in purpose from candy-lister (generic list widget) or sugar-stash (key/value store); no shared responsibility.

### 1) Missing/incomplete functionality

- No support for SSH config `Match` directives, `Include`, or multiple `IdentityFile` entries being passed to `ssh` — `Endpoint::toSshArgv()` (src/Endpoint.php:49-52) only ever emits the *first* `identityFiles[0]`, silently dropping any additional identity files collected from YAML/JSON `identityFiles: [...]` or repeated `IdentityFile` lines in `~/.ssh/config` (src/SshConfigParser.php:146-151 collects a full list, but only index 0 ever reaches the ssh argv).
- No config file caching/hot-reload and no way to merge multiple config sources (e.g. `wishlist.yml` + `~/.ssh/config` in one session) — `bin/wishlist` (bin/wishlist:44-72) picks exactly one candidate path; `Config::importFromSshConfig()` is only reachable programmatically, not wired into the `bin/wishlist` CLI flag surface at all (no `--ssh-config` flag).
- No persistence of "last connected" / "favorite" / usage-frequency ordering — the picker always presents endpoints in file order (aside from fuzzy-filter scoring), so no MRU/frecency to speed up habitual connections.

### 2) Performance concerns

- `Picker::filterMatches()` (src/Picker.php:117-165) reruns `SmithWatermanMatcher::matchAll()` over the *entire* endpoint list on every single keystroke, with no debouncing and no incremental/cached scoring against the previous filter state. Smith-Waterman is O(n*m) per candidate; for very large SSH-config imports (hundreds of hosts) this means a full O(hosts × pattern²)-ish rescan per keystroke. Likely fine at realistic list sizes (tens of hosts) but does not scale gracefully if used with a large imported `~/.ssh/config`.
- `Picker::draw()` (src/Picker.php:170-189) writes to the output stream with multiple small `fwrite()` calls per frame (one per line) rather than batching into a single write — minor overhead, only matters for very large lists redrawn every keystroke.

### 3) Test coverage gaps

- No test drives `Config::load()`/`Config::parse()`/`SshConfigParser` with a row/field whose value is a *non-scalar* (e.g. `name`, `host`, or `port` being a nested array/object) — see Security section below; `grep` across tests/*.php found no "malformed field type" or non-scalar-value coverage, only malformed *syntax* cases (tests/ConfigTest.php:188, :201).
- No test exercises `Endpoint::toSshArgv()` with more than one `identityFiles[]` entry to document/confirm the "only first identity file is used" behavior — a reader could reasonably expect all configured identity files to be passed to ssh.
- No test wires `Config::importFromSshConfig()` through `bin/wishlist`'s actual CLI flag parsing, since there is no such flag today (see functionality gap above) — so the disconnect between the documented programmatic API and the CLI surface is untested/undocumented in code, only in README prose.

### 4) Missing .vhs/*.tape demos

- None missing — `.vhs/picker.tape` + rendered `.vhs/picker.gif` already exist and exercise the documented filter/select/quit flow (navigate, type-to-filter "stag", backspace back to full list, select, Escape). No gap here.

### 5) Security concerns

- **Same class of gap as candy-mines/sugar-skate, present here in a milder form.** candy-mines (Board.php:260,272,278,284) was hardened to `is_array()`-guard *every nesting level* of untrusted persisted JSON before indexing, precisely because `json_decode($x, true)` can yield any shape at any depth for a tampered/malformed file. `Config::parseJson()`/`buildEndpoint()` (src/Config.php:70-84, 196-227) guards the **outer two levels** correctly (`is_array($decoded)` at src/Config.php:73, `is_array($row)` per-entry at src/Config.php:78, `is_array($row['options'])`/`is_array($row['identityFiles'])` at :202/:208) but does **not** guard that the *scalar* fields (`name`, `host`, `port`, `user`, `description`, `proxyJump`, and each element inside `options`/`identityFiles`) are actually scalars before `(string)`/`(int)` casting them (src/Config.php:217-226, and the per-item casts at :204/:210/:213/:215). If an attacker-controlled or corrupted YAML/JSON config supplies e.g. `name: {a: b}` or `host: [1,2]`, PHP's `(string)` cast on an array emits an "Array to string conversion" warning and silently coerces to the literal string `"Array"` rather than throwing — producing a corrupted-but-not-crashing `Endpoint` (e.g. `ssh` destination becomes `user@Array`) instead of failing loudly like candy-mines now does. Not remotely exploitable for injection (the existing `assertNotOption()` leading-dash guard in src/Endpoint.php:81-88 still blocks flag-injection on the final string), but it is a silent data-integrity gap of the exact same root cause the candy-mines fix addressed, and it is untested (see #3).
- `SshConfigParser` does not have this gap in the same way since it parses line-oriented text (every captured value is already a string from the regex), so no analogous fix is needed there.
- Positive findings: `Picker::stripControls()` (src/Picker.php:196-203) correctly strips ANSI/C0 control sequences from `name`/`description`/`displayLine` before writing to the terminal, preventing terminal escape-sequence injection from a malicious config file — this is exactly the defense-in-depth pattern that would be worth backporting to any lib missing it. `Endpoint::assertNotOption()` (src/Endpoint.php:81-88) guards against option-injection (leading-dash values) in `proxyJump`, `options[]`, and the final host destination before they reach `pcntl_exec`'s argv — solid.

### 7) Documentation gaps

- README.md has a duplicated "## Programmatic Use" section (README.md:96-108 and again verbatim at README.md:133-148, the second differing only in also mentioning `importFromSshConfig`) — looks like a copy-paste leftover from when SSH-config-import docs were added; should be merged into one section.
- README.md never documents the `--ssh <path>` CLI flag that `bin/wishlist` actually implements (bin/wishlist:41-42), despite the `.vhs/picker.tape` demo relying on it (`--ssh /bin/true`) — a reader following the README's "Install"/"Configure" sections alone wouldn't discover this flag.
- README.md does not document `--config`'s interaction with `importFromSshConfig()` — the SSH-config-import feature is presented as purely programmatic API, with no CLI flag to invoke it from `bin/wishlist`, which is itself the functionality gap noted in #1.
- CALIBER_LEARNINGS.md is solid on SSH-config-parsing/pcntl_exec/testing gotchas but has no note on the identity-files "only first is used" limitation, so a future contributor extending `toSshArgv()` has no signpost warning them this was a deliberate (or possibly incomplete) simplification.

---

