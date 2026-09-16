<?php

declare(strict_types=1);

namespace SugarCraft\Ansi\Parser;

/**
 * Opt-in declaration that a {@see Handler} reads ECMA-48 colon sub-parameters
 * and wants the parser to PUSH them to it, instead of reaching back for them.
 *
 * The parser flattens every parameter string into one list (ECMA-48 lets a
 * control sequence separate parameters with `;` and sub-parameters with `:`
 * inside that string), so `CSI 4 : 3 m` (curly underline) and
 * `CSI 4 ; 3 m` (underline, then italic) both arrive as `[4, 3]`. The continuation flags that distinguish them
 * live on the parser, and until this interface existed the only way a handler
 * could get them was to *pull*: the front-end that owns the Parser hands the
 * handler a way back to it, and the handler calls {@see Parser::subparams()}
 * from inside its own dispatch. Two shapes do this today — candy-vt injects a
 * closure (`attachSubparamsProvider()`), candy-freeze injects the Parser object
 * itself (`bindParser()`) — which is itself an argument for one pushed contract.
 *
 * That pull inverts the dependency the parser otherwise has — the handler ends
 * up holding a reference to the thing dispatching it — and the reference can go
 * stale in ways the type system cannot see: candy-vt's `Terminal::__clone()` has
 * to re-attach the closure after cloning precisely because the inherited one
 * still late-binds to the *original* terminal's parser, and a handler reused
 * across two parsers reads whichever one bound last. Pushing the flags at
 * dispatch time removes the back-reference entirely: the handler is told, in the
 * same call chain that shows it the parameters, how those parameters were
 * grouped.
 *
 * Deliberately a separate capability rather than a fifth parameter on
 * {@see Handler::csiDispatch()}, for two reasons:
 *
 *  1. `Handler` is documented as the extension point for "the real terminal
 *     emulator, a debug logger, or a unit-test mock". Adding a required method
 *     to it — or a required argument to its dispatch signatures — is a
 *     load-time fatal for every implementation outside this repository, not a
 *     graceful degradation.
 *
 *  2. Almost no handler needs the grouping. `DebugHandler`, the test doubles
 *     and candy-ansi's own {@see HandlerAdapter} all consume the flat list and
 *     would carry an unused parameter for ever. Not implementing this interface
 *     must remain a complete answer.
 *
 * This interface *extends* {@see Handler} rather than standing alone: a type
 * named `…Handler` that could not be passed to {@see Parser::__construct()}
 * would be an illegal state the signature fails to express, and the parser's
 * `instanceof` check on a `Handler` is identical either way.
 *
 * Mirrors the shape of charmbracelet/x/ansi `Params.HasMore(i)` consumers, where
 * the parameter list carries its own continuation flags instead of requiring
 * callers to consult the parser.
 *
 * ## Retirement sequencing (both routes live for one wave)
 *
 * The pull-based late binding is NOT deleted by this interface's introduction:
 * its call sites live in consuming libraries, and removing the pull route from
 * here would fatally break their CI. The follow-up (wave-6 child "vt-B") must
 * migrate the consumers, then tag the three plumbing symbols `@deprecated` —
 * they are defined OUTSIDE this repository, which is why no `@deprecated` tag
 * can accompany this interface:
 *
 *  - `SugarCraft\Vt\Handler\ScreenHandler::attachSubparamsProvider()`
 *  - `SugarCraft\Vt\Parser\CsiHandlerImpl::attachSubparamsProvider()`
 *  - `SugarCraft\Freeze\SgrStateHandler::bindParser()`
 *
 * ### Which of those the push can actually reach
 *
 * The parser consults exactly one object: the {@see Handler} passed to
 * {@see Parser::__construct()}. A capable handler *nested behind* a plain
 * wrapper therefore receives nothing, so the migration is not uniformly
 * mechanical, and vt-B must check the wiring rather than assume it:
 *
 *  - **push-reachable** — `ScreenHandler` (given straight to `new Parser(...)`
 *    by `candy-vt/src/Terminal/Terminal.php`, both the constructor and
 *    `__clone()`) and `SgrStateHandler` (`candy-freeze/src/AnsiParser.php`).
 *    Implementing this interface on those two classes is sufficient.
 *  - **NOT push-reachable** — `CsiHandlerImpl` in the candy-vt *renderer* path,
 *    where `candy-vt/src/Terminal.php::new()` (not the same class as the
 *    `Terminal/Terminal.php` emulator above) hands the parser a plain
 *    {@see HandlerAdapter} and
 *    keeps `CsiHandlerImpl` inside it as that adapter's constructor argument.
 *    Either the adapter grows the capability and forwards `setSubparams()` down
 *    to the wrapped handler, or this path keeps the pull route. Deleting
 *    `CsiHandlerImpl::attachSubparamsProvider()` without doing one of those two
 *    would silently regress colon SGRs there — no fatal, no red test.
 *
 * Re-derive this map from the wiring at migration time: the only object that
 * matters is the one passed to `new Parser(...)`, and wrappers come and go.
 *
 * Until every consumer has migrated, {@see Parser::subparams()} stays public and
 * byte-compatible, and a class is free to implement this interface while
 * ignoring the pull route. Non-implementors are dispatched with zero behaviour
 * change.
 */
interface SubparamsAwareHandler extends Handler
{
    /**
     * Receive the sub-parameter continuation flags for the parameter list that
     * is about to be dispatched.
     *
     * The parser calls this immediately BEFORE {@see Handler::csiDispatch()} and
     * immediately BEFORE {@see Handler::dcsDispatch()}, and never before
     * {@see Handler::escDispatch()}, {@see Handler::oscDispatch()} or
     * {@see Handler::sosPmApcDispatch()}.
     *
     * CSI and DCS are wired because both carry a *parameter string* before their
     * final byte. DCS's is a property of the DEC VT500 grammar this parser
     * implements rather than of ECMA-48, which defines DCS as introducing a
     * string: the state machine runs DCS bytes 0x30-0x3F through the same
     * `Action::Param` path as CSI (see {@see Transitions}, states `DcsEntry` and
     * `DcsParam`), so `DCS 1 ; 2 : 3 q` reaches {@see Handler::dcsDispatch()} as
     * the flat `[1, 2, 3]` with the grouping otherwise recoverable only by
     * pulling — the same defect this interface closes for CSI. No in-tree handler
     * needs that today (candy-vt's `dcsDispatch` is a documented no-op); sixel
     * and ReGIS consumers are the ones that would.
     *
     * The other three are deliberately excluded because none has a parameter
     * string to describe. In the DEC VT500 grammar (the diagram linked from
     * {@see Parser}) the `escape` and `escape intermediate` states accept only
     * bytes in 0x20-0x2F before the final byte — no digits, no `;`, no `:` — and
     * OSC/SOS/PM/APC buffer an opaque *string* whose internal separators belong
     * to the registering application, not to the parameter grammar. Pushing
     * there would mean
     * inventing a value.
     *
     * The contract on the list:
     *
     *  - `true` at index `i` means `params[i]` was followed by `:`, i.e.
     *    `params[i + 1]` is a sub-parameter of the same group rather than an
     *    independent parameter (exactly {@see Parser::subparams()}).
     *  - It is always parallel to the `$params` list handed to the very next
     *    dispatch call — same length, including the `-1` slots that mark a
     *    default/missing parameter — so an empty list accompanies an empty
     *    parameter list (`CSI m`) and an all-`false` list accompanies a plain
     *    `;`-only sequence.
     *  - It describes only that one sequence. The parser pushes a fresh list on
     *    every dispatch, so a handler may keep the array across the call but
     *    must never assume it still describes the following sequence.
     *  - Parallelism holds at the {@see Parser} parameter cap (32), but the
     *    *grouping* stops being faithful past it: once 32 slots are full the
     *    parser drops further separators, so a genuine `:` arriving there reads
     *    `false` and the following digits merge into slot 32 (`…;7:8` becomes a
     *    single `78`). A handler that must distinguish that case cannot rely on
     *    the flags alone; it is a lossy input, not a mis-parse of one.
     *
     * @param list<bool> $subparams Continuation flags parallel to the dispatched params.
     */
    public function setSubparams(array $subparams): void;
}
