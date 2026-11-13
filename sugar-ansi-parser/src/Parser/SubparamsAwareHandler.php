<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Parser;

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
 * from inside its own dispatch. The bridging injection helpers a couple of
 * consumers used for that are retired — those paths take the push now. The
 * pull itself is NOT a dead route: a handler that owns its Parser
 * (candy-ansi-inspect's `AnsiHandler` does `new Parser($this)` and reads
 * {@see Parser::subparams()} mid-dispatch) still pulls, and remains free to.
 *
 * That pull inverted the dependency the parser otherwise has — the handler ended
 * up holding a reference to the thing dispatching it — and the reference could go
 * stale in ways the type system cannot see: a handler reused across two parsers
 * reads whichever one bound last, and sugar-vterm's `Terminal::__clone()` once had
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
 *     and sugar-ansi-parser's own {@see HandlerAdapter} all consume the flat list and
 *     would carry an unused parameter for ever. Not implementing this interface
 *     must remain a complete answer.
 *
 * This interface *extends* {@see Handler} rather than standing alone: a type
 * named `…Handler` that could not be passed to {@see Parser::__construct()}
 * would be an illegal state the signature fails to express, and the parser's
 * `instanceof` check on a `Handler` is identical either way.
 *
 * Conceptually mirrors charmbracelet/x/ansi's continuation-flag consumers —
 * `Param.HasMore()` on a single param and the free `parser.HasMore` helper
 * in ansi/parser/seq.go (finding 026: the old cite's `Params.HasMore(i)`
 * spelled a list-level method that does not exist upstream) — where the
 * parameter carries its own continuation flags instead of requiring callers
 * to consult the parser.
 *
 * ## Two live routes: push (opt-in) and pull (parser owners)
 *
 * Push is this interface: the parser calls {@see SubparamsAwareHandler::setSubparams()}
 * on the handler it was given, immediately before the dispatch. Pull is the raw
 * {@see Parser::subparams()} accessor: a front-end that owns its Parser —
 * `candy-ansi-inspect`'s `AnsiHandler` does `new Parser($this)` — reads the flags
 * back mid-`csiDispatch()` without implementing this interface. Both routes stay
 * live by design: push where a handler opts in, pull where the handler owns the
 * parser it was passed to.
 *
 * ### Which object the push actually reaches
 *
 * Exactly one: the {@see Handler} instance passed to {@see Parser::__construct()}.
 * A capable handler nested *behind* a plain wrapper receives nothing, so the SINK
 * must be the capable one — a decorator that needs the flags forwarded to an
 * inner handler implements this interface itself and calls through (sugar-vterm's
 * renderer decorator does exactly this). Which classes in consuming libraries
 * sit at the sink is that wiring's business and moves as wrappers are added or
 * removed; re-derive it from the `new Parser(...)` call site before trusting any
 * roster. Sinks that do not opt in need nothing pushed and are dispatched with
 * zero behaviour change — sugar-pty's ANSI reader and candy-vcr's mouse tracker
 * are plain-Handler examples.
 *
 * {@see Parser::subparams()} stays public and byte-compatible — for pull handlers
 * like candy-ansi-inspect's and for any driver reading the last sequence after `feed()`.
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
     * needs that today (sugar-vterm's `dcsDispatch` is a documented no-op); sixel
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
