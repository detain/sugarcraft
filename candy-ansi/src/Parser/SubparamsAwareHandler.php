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
 * live on the parser, and before this interface existed the only way a handler
 * could get them was to *pull*: the front-end that owns the Parser handed the
 * handler a way back to it, and the handler read {@see Parser::subparams()}
 * from inside its own dispatch. The two injection helpers that bridged this on
 * the candy-vt/candy-freeze paths (a closure via `attachSubparamsProvider()`, a
 * Parser object via `bindParser()`) are gone now — those paths take the push
 * instead. The pull itself is NOT a dead route: a handler that owns its Parser
 * (sugar-spark's `AnsiHandler` does `new Parser($this)` and reads
 * {@see Parser::subparams()} mid-dispatch) still pulls, and remains free to.
 *
 * That pull inverted the dependency the parser otherwise has — the handler ended
 * up holding a reference to the thing dispatching it — and the reference could go
 * stale in ways the type system cannot see: a handler reused across two parsers
 * reads whichever one bound last, and candy-vt's `Terminal::__clone()` once had
 * to re-attach the closure after cloning precisely because the inherited one
 * still late-binds to the *original* terminal's parser. Pushing the flags at
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
 * ## Two live routes: push (new) and pull (still used)
 *
 * This interface shipped one wave ahead of its consumers so neither side needed a
 * big-bang cutover, then wave-6 "vt-B" (#1447) migrated the candy-vt and
 * candy-freeze paths to the push and DELETED the pull-bridging injection
 * helpers those paths used (a closure-injection helper on two classes, a
 * Parser-injection helper on a third) —
 * `SugarCraft\Vt\Handler\ScreenHandler::attachSubparamsProvider()`,
 * `SugarCraft\Vt\Parser\CsiHandlerImpl::attachSubparamsProvider()`, and
 * `SugarCraft\Freeze\SgrStateHandler::bindParser()`. Those helpers live in
 * consuming libraries (never in candy-ansi), which is why no `@deprecated` tag
 * ever accompanied this interface.
 *
 * The raw pull via {@see Parser::subparams()} is a separate matter and is NOT
 * retired: `sugar-spark`'s `AnsiHandler` owns its Parser (`new Parser($this)`)
 * and reads the flags back mid-`csiDispatch()` without implementing this
 * interface. So both routes remain live — push where a handler opts in, pull
 * where a handler owns the parser it was passed to.
 *
 * ### Which objects the push actually reaches (re-derived from current wiring)
 *
 * The parser consults exactly one object: the {@see Handler} passed to
 * {@see Parser::__construct()}. A capable handler *nested behind* a plain wrapper
 * receives nothing, so the *sink* must be the capable one. The sinks that opt in:
 *
 *  - `SugarCraft\Vt\Handler\ScreenHandler` — the emulator's sink, handed straight
 *    to `new Parser(...)` by `candy-vt/src/Terminal/Terminal.php`; implements this
 *    interface.
 *  - `SugarCraft\Vt\Parser\RendererHandler` — the renderer's sink, handed to
 *    `new Parser(...)` by `candy-vt/src/Terminal.php` (a different class from the
 *    `Terminal/Terminal.php` emulator above); implements this interface and
 *    FORWARDS the flags down to the wrapped `CsiHandlerImpl` whose `sgr()` needs
 *    them. The plain {@see HandlerAdapter} sits *inside* that decorator and is no
 *    longer passed to the parser directly, which is why its own `escDispatch()`
 *    can stay a no-op: `RendererHandler` takes over just that one event.
 *  - `SugarCraft\Freeze\SgrStateHandler` — candy-freeze's sink
 *    (`candy-freeze/src/AnsiParser.php`); implements this interface.
 *
 * Sinks that do NOT opt in need nothing pushed and are dispatched unchanged —
 * e.g. candy-vcr's `MouseModeTracker` and candy-pty's `AnsiOutputParser` pass a
 * plain {@see Handler}, and sugar-spark's `AnsiHandler` pulls instead (above).
 *
 * Re-derive this map from the wiring before trusting it: the only object that
 * matters is the one passed to `new Parser(...)`, and wrappers move between waves.
 *
 * {@see Parser::subparams()} stays public and byte-compatible — for pull handlers
 * like sugar-spark's and for any driver reading the last sequence after `feed()`.
 * A class is free to implement this interface while ignoring the pull route.
 * Non-implementors are dispatched with zero behaviour change.
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
