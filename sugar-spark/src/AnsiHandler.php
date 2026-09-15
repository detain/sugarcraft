<?php

declare(strict_types=1);

namespace SugarCraft\Spark;

use SugarCraft\Ansi\Parser\Handler;
use SugarCraft\Ansi\Parser\Parser;
use SugarCraft\Ansi\Parser\State;

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
 * byte-level debugging tool — reported bytes must equal input bytes.
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
     * flight once the stream ends.
     */
    private string $inFlightBytes = '';

    /**
     * True immediately after a string-sequence dispatch, while the parser may
     * still deliver that sequence's ST (`ESC \`) terminator to
     * {@see escDispatch()}. candy-ansi ends DCS/OSC/SOS/PM/APC on the ESC and
     * then dispatches the `\` as a two-byte escape; the terminator already
     * lives in the emitted segment, so it must not surface a second time.
     */
    private bool $awaitingStringTerminator = false;

    protected bool $ss3Buffered = false;

    private int $ss3Intermediate = 0;

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
     * Bytes travel one at a time so the {@see $inFlightBytes()} window always
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
     * stream ended mid-escape), a dangling SS3 intermediate (ESC O with no
     * final byte) and — for any other escape state the stream never left —
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
    public function finishPending(State $stateBeforeFlush): void
    {
        $pendingText = $this->textBuf;
        $this->flushText();

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
        if ($this->ss3Buffered === true) {
            $this->segments[] = new SequenceSegment(
                "\x1b" . chr($this->ss3Intermediate),
                'SS3 ' . chr($this->ss3Intermediate),
            );
            $this->ss3Buffered = false;
        }

        if ($stateBeforeFlush === State::Escape) {
            $this->segments[] = new SequenceSegment("\x1b", Inspector::describeEsc(''));
            $tailBytes = '';
        }

        if ($tailBytes !== '' && self::isUnterminatedEscapeState($stateBeforeFlush)) {
            $this->segments[] = new SequenceSegment($tailBytes, Inspector::describeTruncated($tailBytes));
        }
    }

    public function printChar(string $rune): void
    {
        $this->awaitingStringTerminator = false;

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

        if ($byte >= 0x00 && $byte <= 0x1F && $byte !== 0x1B) {
            $this->flushPendingSs3();
            $this->flushText();
            $this->segments[] = new SequenceSegment(
                chr($byte),
                'C0 ' . C0C1::c0Name($byte),
            );
            $this->inFlightBytes = '';
        }
        // C1 bytes 0x80–0x9F: the VT500 "anywhere" transition table routes
        // 0x80–0x8F, 0x91–0x97, and 0x9C through Action::Execute (→ Ground).
        // The remaining C1 bytes (0x90, 0x98, 0x9A, 0x9B, 0x9D, 0x9E,
        // 0x9F) go to Entry states and are handled via their respective
        // dispatch callbacks (DCS/CSI/OSC/SOS/PM/APC), never reaching execute().
        if ($byte >= 0x80 && $byte <= 0x9F) {
            $this->flushPendingSs3();
            $this->flushText();
            $this->segments[] = new SequenceSegment(
                chr($byte),
                'C1 ' . C0C1::c1Name($byte),
            );
            $this->inFlightBytes = '';
        }
    }

    public function csiDispatch(int $final, array $params, int $prefix, int $intermediate): void
    {
        $this->awaitingStringTerminator = false;
        $this->flushPendingSs3();
        $this->flushText();

        $prefixStr = $prefix !== 0 ? chr($prefix) : '';
        $intermediateStr = $intermediate !== 0 ? chr($intermediate) : '';
        $finalChar = chr($final);

        $paramsStr = $this->joinParams($params);
        if ($prefixStr !== '') {
            $paramsStr = $prefixStr . $paramsStr;
        }

        $rawBytes = "\x1b[{$paramsStr}{$intermediateStr}" . $finalChar;
        $isSgr = $finalChar === 'm';
        $paramsForDescribe = $isSgr ? $paramsStr : $paramsStr . $intermediateStr;
        $label = Inspector::describeCsi($paramsForDescribe, $finalChar);

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
        $this->flushPendingSs3();
        $this->flushText();

        if ($final === ord('O')) {
            $this->ss3Buffered = true;
            $this->ss3Intermediate = $intermediate !== 0 ? $intermediate : ord('O');
            $this->inFlightBytes = '';
            return;
        }

        // The ST that closed the string sequence just dispatched is already
        // part of that sequence's raw bytes; consuming it here keeps it from
        // leaking out a second time as a phantom `ESC \` segment.
        $terminatesOpenString = $final === ord('\\') && $this->awaitingStringTerminator;
        $this->awaitingStringTerminator = false;
        if ($terminatesOpenString === true) {
            $this->inFlightBytes = '';
            return;
        }

        $intermediateStr = $intermediate !== 0 ? chr($intermediate) : '';
        $rawBytes = "\x1b{$intermediateStr}" . chr($final);
        $this->segments[] = new SequenceSegment($rawBytes, Inspector::describeEsc(chr($final)));
        $this->inFlightBytes = '';
    }

    public function oscDispatch(string $data): void
    {
        $this->flushPendingSs3();
        $this->flushText();
        // OSC terminator normalized to BEL; original ST (\x1b\\) is not
        // preserved because candy-ansi's Parser strips it before dispatch.
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
        $this->flushPendingSs3();
        $this->flushText();

        $prefixStr = $prefix !== 0 ? chr($prefix) : '';
        $intermediateStr = $intermediate !== 0 ? chr($intermediate) : '';
        $paramsStr = implode(';', array_map(
            static fn(int $p): string => (string) $p,
            $params,
        ));

        $fullPayload = $intermediateStr . $prefixStr . $paramsStr . $data;
        $rawBytes = "\x1bP{$fullPayload}\x1b\\";
        $this->segments[] = new SequenceSegment($rawBytes, Inspector::describeDcs($fullPayload, $final));

        $this->awaitingStringTerminator = true;
        $this->inFlightBytes = '';
    }

    public function sosPmApcDispatch(string $kind, string $data): void
    {
        $this->awaitingStringTerminator = false;
        $this->flushPendingSs3();

        $isSosPm = $kind === 'sos' || $kind === 'pm';
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
            'sos' => self::describeSosPm($data),
            'pm'  => self::describeSosPm($data),
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
     * Emit an SS3 introduced by `ESC O` that never received a final byte.
     *
     * Called before any other sequence starts so the abandoned SS3 keeps its
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

    private static function describeSosPm(string $data): string
    {
        if ($data === '') {
            return 'SOS string';
        }
        return 'SOS/PM ' . strlen($data) . ' bytes';
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
