<?php

declare(strict_types=1);

namespace SugarCraft\Ansi\Parser;

/**
 * Opt-in declaration that a {@see Handler} reads ECMA-48 colon sub-parameters
 * and wants the parser to PUSH them to it, instead of reaching back for them.
 *
 * The parser flattens every parameter string into one list (ECMA-48 §14.1.1
 * allows both `;` parameter separation and `:` sub-parameter separation inside
 * it), so `CSI 4 : 3 m` (curly underline) and `CSI 4 ; 3 m` (underline, then
 * italic) both arrive as `[4, 3]`. The continuation flags that distinguish them
 * live on the parser, and until this interface existed the only way a handler
 * could get them was to *pull*: the front-end that owns the Parser late-binds a
 * closure returning {@see Parser::subparams()} into the handler
 * (`attachSubparamsProvider()` in candy-vt, `bindParser()` in candy-freeze).
 *
 * That pull inverts the dependency the parser otherwise has — the handler ends
 * up holding a reference to the thing dispatching it — and the reference can
 * go stale in ways the type system cannot see: candy-vt's `Terminal::__clone()`
 * exists solely to re-attach a closure that would otherwise keep reading the
 * *original* terminal's parser flags, and a handler reused across two parsers
 * reads whichever one bound last. Pushing the flags at dispatch time removes
 * the back-reference entirely: the handler is told, in the same call chain that
 * shows it the parameters, how those parameters were grouped.
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
 * The pull-based late binding is NOT deleted by this interface's introduction,
 * because its call sites live in consuming libraries, not here:
 * `SugarCraft\Vt\Handler\ScreenHandler::attachSubparamsProvider()`,
 * `SugarCraft\Vt\Parser\CsiHandlerImpl::attachSubparamsProvider()` and
 * `SugarCraft\Freeze\SgrStateHandler::bindParser()`. {@see Parser::subparams()}
 * therefore remains public and byte-compatible for those handlers, and a
 * consuming class is free to implement this interface and ignore the pull
 * route. Once every consumer in-tree has migrated, the `attach…`/`bind…`
 * plumbing goes away and {@see Parser::subparams()} keeps only its
 * parser-owner meaning. Until then: implementors get the push, non-implementors
 * are dispatched with zero behaviour change.
 */
interface SubparamsAwareHandler extends Handler
{
    /**
     * Receive the sub-parameter continuation flags for the parameter list that
     * is about to be dispatched.
     *
     * The parser calls this immediately BEFORE {@see Handler::csiDispatch()}
     * and immediately BEFORE {@see Handler::dcsDispatch()}, and never before
     * {@see Handler::escDispatch()}, {@see Handler::oscDispatch()} or
     * {@see Handler::sosPmApcDispatch()}: those three carry no parameter string
     * at all. An ESC sequence is `ESC` + intermediate bytes (0x20-0x2F) + one
     * final byte (ECMA-48 §14.1, `escape-last` / `escape-intermediate` states),
     * and OSC/SOS/PM/APC carry a *string* payload whose internal separators are
     * defined by the registering application, not by the §14.1.1 parameter
     * grammar — so there would be nothing true to report.
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
     *
     * @param list<bool> $subparams Continuation flags parallel to the dispatched params.
     */
    public function setSubparams(array $subparams): void;
}
