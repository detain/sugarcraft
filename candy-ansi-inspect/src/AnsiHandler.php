<?php

declare(strict_types=1);

namespace SugarCraft\AnsiInspect;

use SugarCraft\AnsiParser\Parser\Handler;
use SugarCraft\AnsiParser\Parser\Parser;
use SugarCraft\AnsiParser\Parser\State;

/**
 * Collects ANSI parse events into a flat list of Segments.
 *
 * Feeds the input string through {@see Parser}, intercepts every handler
 * call, and accumulates TextSegments for printChar output and
 * SequenceSegments for every recognized escape sequence.
 *
 * Every sequence is re-emitted with the bytes the input actually carried:
 * parameter separators keep their own `;`/`:` spelling, an unterminated
 * tail survives end-of-stream, and a string terminator is accounted for
 * exactly once. That fidelity is what makes the inspector trustworthy as a
 * byte-level debugging tool. Reported bytes equal input bytes apart from a
 * closed list of deviations that `README.md` enumerates and `ByteFidelityTest`
 * pins one by one: the BEL/ST spelling, the lossy DCS prelude rebuild,
 * sugar-ansi-parser's parameter-count, parameter-value and string-size caps, the C0
 * bytes it ignores inside an OSC payload, the sequence it cancels on an illegal
 * parameter byte or on CAN/SUB while the parser is in a control-sequence state
 * (nothing of the sequence reaches this handler then; the cancel byte itself
 * arrives as an Execute echo — the abandoned prelude is still over-reported by
 * {@see recoverCancelledResidue()} whenever a later dispatch can observe it,
 * which also covers the undocumented fourth member of that family, the
 * anywhere-ESC cancel; a C1 control cancel reports the same prelude
 * immediately, ahead of its own segment), CAN/SUB arriving inside a string
 * payload state —
 * OSC, DCS after its final byte, SOS, PM, APC — where the sequence now reaches
 * this handler truncated at the cancel byte and the cancel byte is consumed
 * un-echoed as the terminator (sugar-ansi-parser 35dda61c0; README deviation 11), a
 * truncated UTF-8 rune at end of stream, which is not an escape-sequence state
 * for `Parser::flush()` to report, and an invalid UTF-8 byte mid-stream, which
 * the parser's UTF-8 window rejects without ever calling back into a handler
 * (surfacing it needs a sugar-ansi-parser callback — deferred, README deviation 10).
 *
 * Mirrors charmbracelet/x/ansi Handler — accumulates segments rather than
 * rendering to a terminal.
 */
final class AnsiHandler implements Handler
{
    /** @var list<Segment> */
    private array $segments = [];

    private string $textBuf = '';

    /**
     * The state machine driving this handler.
     *
     * Owned here (rather than by each front-end) so the handler can consult
     * {@see Parser::subparams()} while a CSI dispatch is in flight — the
     * flattened `list<int>` the handler receives cannot tell `CSI 4 : 3 m`
     * (one curly-underline parameter) from `CSI 4 ; 3 m` (underline plus
     * italic), and rebuilding the wrong spelling would rewrite the caller's
     * bytes.
     */
    private ?Parser $parser = null;

    /**
     * Raw bytes fed since the last emitted segment.
     *
     * The parser only reports completed sequences, so a stream cut mid-CSI
     * would otherwise lose its bytes with nobody the wiser. Keeping the
     * window lets {@see finishPending()} hand back whatever is still in
     * flight once the stream ends. A dispatch consumes the whole window;
     * an Execute echo consumes only the text it just flushed plus its own
     * byte, so a C0 that leaves the parser in its control-sequence state
     * does not blindside the sequence already in flight (finding 009).
     */
    private string $inFlightBytes = '';

    /**
     * True immediately after a string-sequence dispatch, while the parser may
     * still deliver that sequence's ST (`ESC \`) terminator to
     * {@see escDispatch()}. sugar-ansi-parser ends DCS/OSC/SOS/PM/APC on the ESC and
     * then dispatches the `\` as a two-byte escape; the terminator already
     * lives in the emitted segment, so it must not surface a second time.
     *
     * `feed()` is what keeps the window exactly one byte wide (any byte other
     * than `\` closes it before the parser runs). Each string dispatch also
     * opens by clearing the flag so a sequence can only ever inherit its *own*
     * terminator — the guarantee does not depend on who drove the parser.
     */
    private bool $awaitingStringTerminator = false;

    /**
     * True while an `ESC O` waits for its single final byte (ECMA-48 §5.6 Fb).
     *
     * `final` on the class, so this is private state — the visibility a
     * subclass would need is unreachable by construction.
     */
    private bool $ss3Buffered = false;

    private int $ss3Intermediate = 0;

    /**
     * True between a bare `ESC[M` dispatch and the arrival of its three raw
     * payload bytes (X10 mouse event, finding 006 / AIP-07). The parser is
     * right that those bytes are ordinary characters — ECMA-48 has no CSI
     * continuation — but a mode-unaware inspector has to pick a reading, and
     * the mouse report is the one captures actually contain. Any dispatch,
     * execute byte or stream end before the third payload byte aborts the
     * arm and reports `ESC[M` + what arrived as truncated.
     */
    private bool $x10Armed = false;

    private string $x10Payload = '';

    /** Introducer bytes of the live X10 arm, verbatim from the feed window (AIP-15). */
    private string $x10ArmRaw = "\x1b[M";

    /**
     * Parse a complete input in one shot: feed every byte, then finalise.
     *
     * @return list<Segment>
     */
    public function parse(string $input): array
    {
        $this->reset();
        $this->parser = new Parser($this);
        $this->feed($input);
        $this->finish();

        return $this->segments;
    }

    /**
     * Feed bytes through the state machine, accumulating segments.
     *
     * Bytes travel one at a time so the {@see $inFlightBytes} window always
     * covers exactly the sequence in progress: a chunk that completes one
     * sequence and starts another must not let the first dispatch blind the
     * second. The end result is byte-identical to a single `Parser::feed()`
     * of the whole chunk — same handler callbacks, same order.
     */
    public function feed(string $input): void
    {
        $parser = $this->parser ??= new Parser($this);
        $length = strlen($input);

        for ($offset = 0; $offset < $length; $offset++) {
            $byte = $input[$offset];
            // The terminator to swallow must be the *immediate* follower of the
            // string dispatch: sugar-ansi-parser dispatches the sequence on the ESC of
            // `ESC \` and delivers the backslash next. Any other byte (a BEL
            // that ended an OSC, a C1 ST that ended a DCS, plain text) closes
            // the window, so a later genuine `ESC \` is still reported.
            if ($this->awaitingStringTerminator === true && $byte !== '\\') {
                $this->awaitingStringTerminator = false;
            }
            $this->inFlightBytes .= $byte;
            $parser->feed($byte);
        }
    }

    /**
     * End of stream: force any in-flight string sequence to dispatch and
     * finalise the dangling tail.
     */
    public function finish(): void
    {
        $parser = $this->parser ??= new Parser($this);
        // Capture the state BEFORE flush(): flush() resets it to Ground, so a
        // post-flush check would never observe an unterminated escape state.
        $stateBeforeFlush = $parser->currentState();
        $parser->flush();
        $this->finishPending($stateBeforeFlush);
    }

    /**
     * Reset all accumulated state (segments, text buffer, flags).
     * Used by StreamingInspector between sessions.
     *
     * The parser binding survives on purpose: a streaming front-end resets the
     * segment bookkeeping between sessions without discarding the state machine
     * that carries a half-delivered sequence across chunk boundaries.
     */
    public function reset(): void
    {
        $this->segments = [];
        $this->textBuf = '';
        $this->inFlightBytes = '';
        $this->awaitingStringTerminator = false;
        $this->ss3Buffered = false;
        $this->ss3Intermediate = 0;
        $this->x10Armed = false;
        $this->x10Payload = '';
        $this->x10ArmRaw = "\x1b[M";
    }

    /**
     * Return all segments produced since the last drain (or since reset).
     * Does NOT call flushText() — pending text is only flushed when a
     * sequence dispatch auto-flushes (csiDispatch / oscDispatch / etc.) or
     * when finish() is called.  This preserves the streaming invariant that
     * text is returned only when a sequence is completed or the stream ends.
     *
     * Does NOT emit pending bare ESC — that is handled by finish() so that
     * the next feed() can correctly continue a sequence (e.g. ESC O P SS3)
     * across chunk boundaries.
     *
     * @return list<Segment>
     */
    public function drainSegments(): array
    {
        $out = $this->segments;
        $this->segments = [];
        return $out;
    }

    public function flushText(): void
    {
        if ($this->textBuf === '') {
            return;
        }
        $this->segments[] = new TextSegment($this->textBuf);
        $this->textBuf = '';
    }

    /**
     * Finalise the end-of-stream tail after the parser has been flushed.
     *
     * Flushes any buffered text, then emits a trailing bare ESC (when the
     * stream ended mid-escape) together with the bytes it cancelled and any
     * the parser swallowed after it, a dangling SS3 intermediate (ESC O with
     * no final byte) and — for any other escape state the stream never left —
     * the exact bytes of the unterminated sequence. Shared by the one-shot
     * {@see parse()} and the streaming {@see StreamingInspector::finish()} so
     * both handle the dangling tail identically — and so the streaming path
     * never needs to reach into this handler's private/protected members.
     *
     * The caller MUST capture the parser state BEFORE {@see Parser::flush()}
     * (which resets it to Ground) and pass it here; a post-flush check would
     * never observe {@see State::Escape}, silently dropping a trailing bare ESC.
     *
     * @param State $stateBeforeFlush parser state captured before flush()
     */
    private function finishPending(State $stateBeforeFlush): void
    {
        $pendingText = $this->textBuf;
        $this->flushText();
        $this->abortX10Arm();

        // Text bytes ride in the in-flight window until a dispatch consumes
        // them; drop that prefix so what remains is only the sequence tail.
        $tailBytes = $this->inFlightBytes;
        if ($pendingText !== '' && str_starts_with($tailBytes, $pendingText)) {
            $tailBytes = substr($tailBytes, strlen($pendingText));
        }
        $this->inFlightBytes = '';

        // A pending SS3 is always the earliest unemitted thing in the stream:
        // any later dispatch would already have flushed it. Report it before
        // the tail that follows it.
        $this->flushPendingSs3();

        if ($stateBeforeFlush === State::Escape) {
            // The dangling introducer is the LAST ESC in the window: whatever
            // sits ahead of it belongs to a sequence this ESC cancelled, and
            // anything behind it is a byte the parser swallowed without
            // executing (0x7F stays Action::None in Escape). Report all three
            // pieces in stream order so no byte vanishes (finding 010).
            $escPos = strrpos($tailBytes, "\x1b");
            if ($escPos !== false) {
                $cancelled = $escPos > 0 ? substr($tailBytes, 0, $escPos) : '';
                $swallowed = substr($tailBytes, $escPos + 1);
                if ($cancelled !== '') {
                    $this->segments[] = new SequenceSegment(
                        $cancelled,
                        Inspector::describeTruncated($cancelled) . ' — cancelled',
                    );
                }
                $this->segments[] = new SequenceSegment("\x1b", Inspector::describeEsc(''));
                if ($swallowed !== '') {
                    $this->segments[] = new SequenceSegment(
                        $swallowed,
                        Inspector::describeTruncated($swallowed),
                    );
                }
            } else {
                // feed() appends every byte before the parser advances, so an
                // Escape state without an ESC in the window cannot normally
                // happen — emit the introducer anyway rather than forget it,
                // and keep the window bytes as the unterminated tail.
                $this->segments[] = new SequenceSegment("\x1b", Inspector::describeEsc(''));
                if ($tailBytes !== '') {
                    $this->segments[] = new SequenceSegment($tailBytes, Inspector::describeTruncated($tailBytes));
                }
            }
            $tailBytes = '';
        }

        if ($tailBytes !== '' && self::isUnterminatedEscapeState($stateBeforeFlush)) {
            $this->segments[] = new SequenceSegment($tailBytes, Inspector::describeTruncated($tailBytes));
        }
    }

    public function printChar(string $rune): void
    {
        $this->awaitingStringTerminator = false;

        // X10 payload bytes (AIP-07): swallowed whole while the arm is open,
        // emitted as one mouse report when the third byte lands. A rune that
        // overflows (multi-byte) keeps its tail as fresh text — the mouse
        // report itself never absorbs more than its three bytes.
        if ($this->x10Armed === true) {
            $this->x10Payload .= $rune;
            if (strlen($this->x10Payload) < 3) {
                return;
            }
            $payload = substr($this->x10Payload, 0, 3);
            $overflow = substr($this->x10Payload, 3);
            $this->x10Armed = false;
            $this->x10Payload = '';
            $this->segments[] = new SequenceSegment($this->x10ArmRaw . $payload, Inspector::describeMouseX10($payload));
            $this->inFlightBytes = $overflow;
            if ($overflow !== '') {
                $this->textBuf .= $overflow;
            }
            return;
        }

        if ($this->ss3Buffered === true) {
            if (self::isSs3Final($rune)) {
                $this->segments[] = new SequenceSegment(
                    "\x1b" . chr($this->ss3Intermediate) . $rune,
                    Inspector::describeSs3($rune),
                );
                $this->ss3Buffered = false;
                $this->inFlightBytes = '';
                return;
            }
            // The byte after `ESC O` is outside the SS3 final range 0x40–0x7E
            // (ECMA-48 §5.6 Fb), so the sender abandoned the SS3. Report the
            // partial sequence and re-process the rune as ordinary text — the
            // alternative is stealing legitimate text into a bogus SS3 segment.
            $this->flushPendingSs3();
        }
        $this->textBuf .= $rune;
    }

    public function execute(int $byte): void
    {
        $this->awaitingStringTerminator = false;
        $this->abortX10Arm();

        // The flushed pending text and the echoed byte are the only bytes an
        // Execute legitimately consumes from the window (finding 009). A C0
        // byte that is not CAN/SUB leaves the parser in its control-sequence
        // state (Transitions: CsiParam stays on 0x00-0x17/0x19/0x1C-0x1F), so
        // whatever else the window holds is still the live sequence's prelude
        // and must survive to a later dispatch or the end-of-stream tail
        // report; a C1 control cancels the sequence here and now, so that
        // prelude is reported immediately, ahead of the C1 segment.
        $pending = $this->textBuf;
        $this->flushPendingSs3();
        $this->flushText();
        $residue = $this->residueAfterExecute($pending, $byte);

        if ($byte >= 0x00 && $byte <= 0x1F && $byte !== 0x1B) {
            $this->segments[] = new SequenceSegment(
                chr($byte),
                'C0 ' . C0C1::c0Name($byte),
            );
            // CAN/SUB keep README deviation 7's documented floor: the parser
            // cancels without handing this handler the abandoned prelude, and
            // the byte window is flushed with it (pinned in ByteFidelityTest).
            $this->inFlightBytes = ($byte === 0x18 || $byte === 0x1A) ? '' : $residue;
        } elseif ($byte === 0x7F) {
            // DEL (0x7F) belongs to the C0 set of ISO 6429 even though it sits
            // outside the 0x00–0x1F range the branch above covers, and the VT500
            // anywhere table routes it through Action::Execute while in Ground.
            // Before step 10.16 it matched neither branch and vanished — exactly
            // the kind of unlisted byte loss the README fidelity contract forbids.
            // Mid-sequence the table gives it None (no callback), so the residue
            // this branch can ever see is empty unless a future table change
            // routes it Execute-in-place; retaining is the faithful default.
            $this->segments[] = new SequenceSegment(chr($byte), 'DEL (delete)');
            $this->inFlightBytes = $residue;
        } elseif ($byte >= 0x80 && $byte <= 0x9F) {
            // C1 bytes 0x80–0x9F: the VT500 "anywhere" transition table routes
            // 0x80–0x8F, 0x91–0x97, 0x99, 0x9A and 0x9C through Action::Execute
            // (→ Ground). The remaining C1 bytes (0x90, 0x98, 0x9A, 0x9B, 0x9D,
            // 0x9E, 0x9F) go to Entry states and are handled via their respective
            // dispatch callbacks (DCS/CSI/OSC/SOS/PM/APC), never reaching execute()
            // — except 0x99/0x9A listed in the Execute set above.
            // A C1 from a control-sequence state cancels it: report the
            // abandoned prelude first (wire order), then the C1 byte itself.
            if ($residue !== '') {
                $this->segments[] = new SequenceSegment(
                    $residue,
                    Inspector::describeTruncated($residue) . ' — cancelled',
                );
            }
            $this->segments[] = new SequenceSegment(
                chr($byte),
                'C1 ' . C0C1::c1Name($byte),
            );
            $this->inFlightBytes = '';
        }
    }

    /**
     * Window contents left after an Execute consumed its flushed text
     * prefix and its own echoed byte.
     *
     * Purely positional: the flushed text can only ever sit at the head of
     * the window and the executed byte at its tail (feed() appends before
     * the parser runs), so peeling both is byte-exact.
     */
    private function residueAfterExecute(string $pendingText, int $byte): string
    {
        $tail = $this->inFlightBytes;
        if ($pendingText !== '' && str_starts_with($tail, $pendingText)) {
            $tail = substr($tail, strlen($pendingText));
        }
        if ($tail !== '' && substr($tail, -1) === chr($byte)) {
            $tail = substr($tail, 0, -1);
        }
        return $tail;
    }

    /**
     * Verbatim window bytes of the sequence currently dispatching.
     *
     * The feed window holds every byte since the last dispatch, so the
     * tail starting at the LAST appearance of one of $introducers is the
     * sequence's own prelude — the sender's real bytes, including the
     * marker, intermediate and DEL bytes sugar-ansi-parser's single slots
     * collapse away, and the 8-bit introducer the dispatch callback drops.
     * Returns the tail with its introducer length, or null when the window
     * carries no introducer (a residue-only window, defensive).
     *
     * @param list<string> $introducers possible introducer spellings, 7-bit and 8-bit
     * @return ?array{0:string,1:int} verbatim tail + introducer length
     */
    private function windowTail(array $introducers): ?array
    {
        $bestPos = null;
        $bestLen = null;
        foreach ($introducers as $needle) {
            $pos = strrpos($this->inFlightBytes, $needle);
            if ($pos !== false && ($bestPos === null || $pos > $bestPos)) {
                $bestPos = $pos;
                $bestLen = strlen($needle);
            }
        }

        return $bestPos === null ? null : [substr($this->inFlightBytes, $bestPos), $bestLen];
    }

    public function csiDispatch(int $final, array $params, int $prefix, int $intermediate): void
    {
        $this->awaitingStringTerminator = false;

        $prefixStr = $prefix !== 0 ? chr($prefix) : '';
        $intermediateStr = $intermediate !== 0 ? chr($intermediate) : '';
        $finalChar = chr($final);

        $paramsStr = $this->joinParams($params);
        if ($prefixStr !== '') {
            $paramsStr = $prefixStr . $paramsStr;
        }

        // Verbatim rebuild (finding 016): the window tail from its introducer
        // IS the sequence the sender wrote — markers arriving after the first,
        // extra intermediates, dropped DEL bytes and the 8-bit spelling all
        // survive in the window even though sugar-ansi-parser's single slots
        // collapse them out of the callback fields. The `ESC [` template only
        // re-emits what the parser could not carry, and its use here would
        // report bytes the sender never sent.
        $windowHit = $this->windowTail(["\x1b[", "\x9b"]);
        $verbatim = $windowHit !== null && str_ends_with($windowHit[0], $finalChar);
        $rawBytes = $verbatim ? $windowHit[0] : "\x1b[{$paramsStr}{$intermediateStr}" . $finalChar;
        // With a verbatim tail the label reads the sequence's own interior
        // bytes (marker + params + intermediates in wire order); the collapsed
        // fields would label a sequence that was never on the wire.
        $describeInput = $verbatim ? substr($windowHit[0], $windowHit[1], -1) : $paramsStr . $intermediateStr;
        $this->abortX10Arm();
        if ($finalChar === 'M' && $prefixStr === '' && $intermediateStr === '' && $paramsStr === '') {
            // X10 mouse introducer (finding 006 / AIP-07): do not emit "delete
            // lines 1" — arm for the three raw payload bytes that trail the
            // dispatch and complete the report inside printChar().
            $this->recoverCancelledResidue($rawBytes);
            $this->flushPendingSs3();
            $this->flushText();
            $this->x10Armed = true;
            $this->x10Payload = '';
            $this->x10ArmRaw = $rawBytes;
            $this->inFlightBytes = '';
            return;
        }
        $this->recoverCancelledResidue($rawBytes);
        $this->flushPendingSs3();
        $this->flushText();
        // One `describeCsi` input: params (marker-prefixed) plus any
        // intermediate byte — the SGR guard (finding 007) must see the
        // intermediate to refuse `ESC[!5m`-style shapes.
        $label = Inspector::describeCsi($describeInput, $finalChar);

        $this->segments[] = new SequenceSegment($rawBytes, $label);
        $this->inFlightBytes = '';
    }

    /**
     * Rebuild the parameter string carrying the input's own separator bytes:
     * `;` stays `;`, `:` stays `:`, and a parameter omitted between separators
     * stays empty (ECMA-48 §14.1.1 sub-parameters, xterm ctlseqs SGR).
     *
     * Deliberately NOT canonicalised: `4;3` means underline then italic while
     * `4:3` is one curly-underline parameter, so rewriting one spelling as the
     * other would both mislabel the sequence and report bytes the sender never
     * sent.
     *
     * @param list<int> $params Flattened params as dispatched; -1 marks an omitted one.
     */
    private function joinParams(array $params): string
    {
        $subparams = $this->parser?->subparams() ?? [];
        $last = array_key_last($params);
        $rebuilt = '';

        foreach ($params as $index => $value) {
            $rebuilt .= $value === -1 ? '' : (string) $value;
            if ($index === $last) {
                break;
            }
            $rebuilt .= ($subparams[$index] ?? false) === true ? ':' : ';';
        }

        return $rebuilt;
    }

    public function escDispatch(int $final, int $intermediate): void
    {
        $this->abortX10Arm();
        if ($final === ord('O') && $intermediate === 0) {
            $this->recoverCancelledResidue("\x1bO");
            $this->flushPendingSs3();
            $this->flushText();
            $this->ss3Buffered = true;
            $this->ss3Intermediate = ord('O');
            $this->inFlightBytes = '';
            return;
        }

        // The ST that closed the string sequence just dispatched is already
        // part of that sequence's raw bytes; consuming it here keeps it from
        // leaking out a second time as a phantom `ESC \` segment.
        $terminatesOpenString = $final === ord('\\') && $this->awaitingStringTerminator;
        if ($terminatesOpenString === true) {
            $this->flushPendingSs3();
            $this->flushText();
            $this->awaitingStringTerminator = false;
            $this->inFlightBytes = '';
            return;
        }
        $this->awaitingStringTerminator = false;

        $intermediateStr = $intermediate !== 0 ? chr($intermediate) : '';
        // `ESC <intermediate> O` is not an SS3 (ECMA-48 §15.3): buffering it
        // as one lost the `O` and asserted an SS3 shape never sent (finding
        // 018). It falls through to a plain 2-byte-ESC segment reporting the
        // window's verbatim bytes with its real name.
        $windowHit = $this->windowTail(["\x1b"]);
        $verbatim = $windowHit !== null && str_ends_with($windowHit[0], chr($final));
        $rawBytes = $verbatim ? $windowHit[0] : "\x1b{$intermediateStr}" . chr($final);
        // The residue check runs BEFORE the flushes on purpose: it has to see
        // the still-pending text buffer to know which window bytes are already
        // accounted for by the segments the flushes are about to create.
        $this->recoverCancelledResidue($rawBytes);
        $this->flushPendingSs3();
        $this->flushText();
        $this->segments[] = new SequenceSegment($rawBytes, Inspector::describeEsc(chr($final), $intermediateStr));
        $this->inFlightBytes = '';
    }

    public function oscDispatch(string $data): void
    {
        $this->awaitingStringTerminator = false;
        $this->abortX10Arm();
        // Residue check before the flushes: it must see the still-pending
        // text/SS3 buffers to attribute window bytes. The ST-pending spelling
        // is a second candidate because sugar-ansi-parser dispatches the OSC on
        // the ESC of `ESC \` and delivers the backslash next. The 0x9D twins
        // anchor an OSC opened by the 8-bit introducer: a cross-type jump
        // abandons the previous string payload (finding 017) and the residue
        // seam must still recognise THIS sequence's own bytes.
        $this->recoverCancelledResidue(
            "\x1b]{$data}\x07",
            "\x1b]{$data}\x1b",
            "\x9d{$data}\x07",
            "\x9d{$data}\x1b",
        );
        $this->flushPendingSs3();
        $this->flushText();
        // OSC terminator normalized to BEL; original ST (\x1b\\) is not
        // preserved because sugar-ansi-parser's Parser strips it before dispatch.
        $this->segments[] = new SequenceSegment(
            "\x1b]{$data}\x07",
            Inspector::describeOsc($data),
        );
        $this->awaitingStringTerminator = true;
        $this->inFlightBytes = '';
    }

    public function dcsDispatch(int $final, array $params, int $prefix, int $intermediate, string $data): void
    {
        $this->awaitingStringTerminator = false;
        $this->abortX10Arm();

        $prefixStr = $prefix !== 0 ? chr($prefix) : '';
        $intermediateStr = $intermediate !== 0 ? chr($intermediate) : '';
        // Same replay rule as a CSI: `Action::Param` feeds DCS sub-parameters
        // through the identical slot/flag arrays, so `ESC P 1:2 q` must come
        // back with its colon and an omitted parameter with an empty slot —
        // never as a canonical `;` or a literal `-1`.
        $paramsStr = $this->joinParams($params);

        $fullPayload = $intermediateStr . $prefixStr . $paramsStr . $data;
        // Verbatim for a BEL-terminated body (finding 016's DCS leg): the
        // window tail from the introducer carries the sender's real prelude
        // and terminator. An ST-terminated DCS dispatches on the ESC of
        // `ESC \` — the backslash has not been fed yet — so that spelling
        // keeps the disclosed template re-emission (README deviation 2); the
        // window tail is still offered to the residue seam so bytes abandoned
        // BEFORE this DCS surface instead of vanishing with the clear.
        $windowHit = $this->windowTail(["\x1bP", "\x90"]);
        $template = "\x1bP{$fullPayload}\x1b\\";
        $rawBytes = $template;
        if ($windowHit !== null && str_ends_with($windowHit[0], "\x07")) {
            $rawBytes = $windowHit[0];
        }
        // The DCS dispatches on the ESC of its ST, so the window tail carries
        // only `…ESC` when the backslash has not been fed yet — the second
        // candidate.
        $this->recoverCancelledResidue(
            $rawBytes,
            $windowHit[0] ?? '',
            $template,
            substr($template, 0, -1),
        );
        $this->flushPendingSs3();
        $this->flushText();
        // 2026-10-23 (r1024/inspect): classify from the parser's true dispatch
        // fields via the shared sugar-ansi-parser Codec\Dcs decoder instead of
        // re-peeling the payload string — a sixel body that begins with '$'
        // is sixel, not a DECRQSS request, and only the fields can say so.
        $this->segments[] = new SequenceSegment(
            $rawBytes,
            Inspector::describeDcsParts($final, $params, $prefix, $intermediate, $data, $fullPayload),
        );

        $this->awaitingStringTerminator = true;
        $this->inFlightBytes = '';
    }

    public function sosPmApcDispatch(string $kind, string $data): void
    {
        $this->awaitingStringTerminator = false;
        $this->abortX10Arm();

        $isSosPm = $kind === 'sos' || $kind === 'pm';
        $introducer = match ($kind) {
            'sos' => "\x1bX",
            'pm'  => "\x1b^",
            'apc' => "\x1b_",
            default => '',
        };
        // 8-bit twins of the introducers: candidates for the residue seam so a
        // string opened by 0x98/0x9E/0x9F still anchors its own window tail
        // (finding 017's cross-type jump — the abandoned payload must surface
        // as a cancelled segment instead of vanishing with the window clear).
        $c1 = match ($kind) {
            'sos' => "\x98",
            'pm'  => "\x9e",
            'apc' => "\x9f",
            default => '',
        };
        if ($introducer === '') {
            $this->recoverCancelledResidue();
        } elseif ($data === '') {
            $this->recoverCancelledResidue($introducer, $introducer, $c1);
        } else {
            $full = "{$introducer}{$data}\x1b\\\\";
            $fullC1 = "{$c1}{$data}\x1b\\\\";
            $this->recoverCancelledResidue(
                $full,
                substr($full, 0, -1),
                $fullC1,
                substr($fullC1, 0, -1),
            );
        }
        $this->flushPendingSs3();

        if ($isSosPm === true && $data === '') {
            // An empty SOS/PM string with no payload byte to carry: report the
            // introducer alone and leave its ST to be dispatched on its own.
            $this->flushText();
            $label = $kind === 'sos' ? Inspector::describeEsc('X') : Inspector::describeEsc('^');
            $this->segments[] = new SequenceSegment("\x1b" . ($kind === 'sos' ? 'X' : '^'), $label);
            $this->inFlightBytes = '';
            return;
        }

        $this->flushText();

        $label = match ($kind) {
            'sos' => self::describeSosPm('SOS', $data),
            'pm'  => self::describeSosPm('PM', $data),
            'apc' => Inspector::describeApc($data),
            default => "{$kind} {$data}",
        };

        $rawBytes = match ($kind) {
            'sos' => "\x1bX{$data}\x1b\\",
            'pm'  => "\x1b^{$data}\x1b\\",
            'apc' => "\x1b_{$data}\x1b\\",
            default => "{$kind} {$data}",
        };

        $this->segments[] = new SequenceSegment($rawBytes, $label);
        $this->awaitingStringTerminator = true;
        $this->inFlightBytes = '';
    }

    /**
     * Report the bytes a parser-side cancel abandoned, before the sequence
     * that ends up dispatching consumes the shared byte window.
     *
     * ECMA-48 anywhere-ESC (and the CAN/SUB and illegal-parameter cancels
     * that leave no callback at all) discards the sequence in flight without
     * a handler dispatch — but the handler's {@see $inFlightBytes} window
     * kept every fed byte. When the next real dispatch fires, the window
     * therefore holds `[maybe-text][abandoned prelude][this dispatch's own
     * bytes]`. Attributing the dispatch's true bytes from the tail (each
     * `$candidates` spelling is one way the rebuilt raw can appear — the
     * ST-pending forms carry only the ESC because sugar-ansi-parser delivers
     * the backslash one byte later) and subtracting the buffers a following
     * flush will account for leaves exactly the abandoned prelude.
     *
     * Over-report, never under-report (the house philosophy of README
     * deviation 7): the prelude surfaces as a `truncated … — cancelled`
     * segment ordered ahead of the dispatch that revealed it. Since the
     * window-tail rebuild (finding 016) a CSI/DCS dispatch's own bytes ARE
     * the window tail, so the anchor holds even when the parser slots
     * collapsed markers, intermediates or DEL bytes out of the callback
     * fields; the remaining no-anchor shapes are the string families'
     * disclosed template re-emissions, where no residue is invented and the
     * pinned deviation stands untouched.
     *
     * @param string ...$candidates possible window-tail spellings of the dispatch's own bytes
     */
    private function recoverCancelledResidue(string ...$candidates): void
    {
        $window = $this->inFlightBytes;
        $tail = '';
        foreach ($candidates as $candidate) {
            if ($candidate !== '' && str_ends_with($window, $candidate)) {
                $tail = $candidate;
                break;
            }
        }
        if ($tail === '') {
            return;
        }

        $residue = substr($window, 0, strlen($window) - strlen($tail));
        if ($residue === '') {
            return;
        }

        // Bytes the pending (not yet flushed) segments will account for —
        // strip one occurrence of each so no byte is reported twice.
        if ($this->ss3Buffered === true) {
            $residue = self::stripOnce($residue, "\x1b" . chr($this->ss3Intermediate));
        }
        $textAhead = $this->textBuf !== '' && str_starts_with($residue, $this->textBuf);
        if ($this->textBuf !== '') {
            $residue = self::stripOnce($residue, $this->textBuf);
        }
        if ($residue === '') {
            return;
        }

        if ($textAhead === true) {
            // The pending text fed in ahead of the abandoned sequence: report
            // it in stream position, before the cancelled segment.
            $this->flushPendingSs3();
            $this->flushText();
        }
        $this->segments[] = new SequenceSegment($residue, Inspector::describeTruncated($residue) . ' — cancelled');
    }

    /**
     * Remove the first occurrence of $needle from $haystack (binary-safe).
     */
    private static function stripOnce(string $haystack, string $needle): string
    {
        $pos = strpos($haystack, $needle);
        return $pos === false ? $haystack : substr_replace($haystack, '', $pos, strlen($needle));
    }

    /**
     * Emit an SS3 introduced by `ESC O` that never received a final byte.
     *
     * Called before any other sequence starts — and by `finishPending()`
     * before it reports the dangling tail — so the abandoned SS3 keeps its
     * place in the stream instead of gluing itself onto whatever comes next.
     */
    private function flushPendingSs3(): void
    {
        if ($this->ss3Buffered === false) {
            return;
        }
        $this->segments[] = new SequenceSegment(
            "\x1b" . chr($this->ss3Intermediate),
            'SS3 ' . chr($this->ss3Intermediate),
        );
        $this->ss3Buffered = false;
    }

    /**
     * Close an open X10 arm whose payload never completed: the introducer and
     * whatever payload bytes did arrive are reported as truncated (finding
     * 006's conservation floor — the bytes must surface, even when the sender
     * broke the protocol).
     */
    private function abortX10Arm(): void
    {
        if ($this->x10Armed === false) {
            return;
        }
        $this->x10Armed = false;
        $raw = $this->x10ArmRaw . $this->x10Payload;
        // The payload bytes live in the shared byte window too (they arrived
        // after the arm cleared it); peel them out so no later residue check
        // reports the same byte twice.
        $this->inFlightBytes = substr($this->inFlightBytes, strlen($this->x10Payload));
        $this->x10Payload = '';
        $this->segments[] = new SequenceSegment($raw, 'X10 mouse report (incomplete payload)');
    }

    /**
     * SOS and PM carry opaque strings with no internal structure to decode,
     * so the honest label is the family plus the payload length. The two
     * shared one "SOS/PM" label until step 10.16 split them the way
     * ECMA-48 §5.6 does — a report should not make the reader guess which
     * privacy flavor the stream used.
     */
    private static function describeSosPm(string $family, string $data): string
    {
        if ($data === '') {
            return $family . ' string';
        }
        return $family . ' string (' . strlen($data) . ' bytes)';
    }

    /**
     * Is the byte a legal SS3 final? ECMA-48 §5.6 defines the final byte of a
     * single-shift sequence in C6 (0x40–0x7E); anything else means the sender
     * abandoned the sequence.
     */
    private static function isSs3Final(string $rune): bool
    {
        return strlen($rune) === 1 && ord($rune) >= 0x40 && ord($rune) <= 0x7E;
    }

    /**
     * Escape states whose bytes belong to a sequence that never completed.
     *
     * String states (OSC/DCS-passthrough/SOS/PM/APC) are absent on purpose:
     * {@see Parser::flush()} already dispatches those, so their payload reached
     * the stream as a normal segment.
     */
    private static function isUnterminatedEscapeState(State $state): bool
    {
        return match ($state) {
            State::CsiEntry, State::CsiParam, State::CsiIntermediate,
            State::EscapeIntermediate,
            State::DcsEntry, State::DcsParam, State::DcsIntermediate => true,
            default => false,
        };
    }
}
