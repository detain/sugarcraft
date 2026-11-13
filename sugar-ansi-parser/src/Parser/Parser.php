<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Parser;

/**
 * VT500 ANSI parser state machine.
 *
 * Feeds raw bytes through the Paul-Williams state machine, dispatching
 * actions to a {@see Handler}. Handles partial input naturally — a
 * sequence split across two `feed()` calls resumes at the correct state
 * on the second call.
 *
 * Mirrors charmbracelet/x/ansi/parser. Adds a Utf8 state so multi-byte
 * runes arrive at the handler as a single `printChar(string $rune)` call.
 *
 * UTF-8 policy: by default the parser does NOT validate UTF-8 — every
 * structurally-completed rune byte sequence is forwarded verbatim (overlong,
 * surrogate and >U+10FFFF encodings included). Pass `$replaceMalformed =
 * true` to the constructor for validation with U+FFFD replacement (needs
 * ext-mbstring); that flag substitutes U+FFFD both for completed-invalid
 * encodings and for incomplete runes — a sequence interrupted mid-rune by
 * the next discriminator, and a partial rune abandoned at end-of-stream
 * (flush()/reset()), become U+FFFD. Under the default those incomplete runes
 * are dropped silently, producing no character at all.
 * The raw byte space is decided by the state table first, in both modes:
 * 0x80-0x9F are live C1 controls (executed, or introducers of DCS/SOS/OSC/
 * PM/APC — never dropped and never replaced, even though UTF-8 would read
 * them as lone continuation bytes), while the bytes that truly vanish on the
 * default drop row are lone continuations 0xA0-0xBF and invalid leads
 * 0xC0-0xC1 and 0xF5-0xFF — those are lost silently, with no U+FFFD, even
 * under `$replaceMalformed`. See README "Limits & hostile input".
 *
 * @see https://github.com/charmbracelet/x/tree/main/ansi/parser
 * @see https://vt100.net/emu/dec_ansi_parser
 */
final class Parser
{
    /**
     * The machine's current state as the raw int backing of {@see State}
     * (finding sugar-ansi-parser-001): `advance()` runs per byte, and the
     * transition table is indexed by int, so the hot loop no longer hydrates
     * enum cases per byte. {@see State} remains the public surface —
     * {@see currentState()} converts exactly once at that boundary.
     */
    private int $state = self::S_GROUND;

    /** @var list<int> Numeric CSI/DCS params; -1 marks a default/missing slot. */
    private array $params = [];

    /**
     * ECMA-48 sub-parameter continuation flags parallel to {@see $params}:
     * true at index i means `params[i]` was followed by a `:` separator, so
     * `params[i + 1]` is a SUB-parameter of the same parameter group rather
     * than an independent parameter. Without this, `CSI 4 : 3 m` (curly
     * underline) and `CSI 4 ; 3 m` (single underline + italic) arrive at the
     * handler as the identical flat list `[4, 3]` — the mis-split the VT500
     * grammar forbids. The flattened list is still dispatched unchanged for
     * handler compatibility; consumers that need the grouping read
     * {@see subparams()}, reconstitute it with {@see groupSubparameters()}, or
     * opt into {@see SubparamsAwareHandler} and have the parser push them in
     * immediately before each parameterised dispatch.
     *
     * Mirrors charmbracelet/x/ansi `Param.HasMore` / `Params.HasMore(i)`.
     *
     * @var list<bool>
     */
    private array $subparams = [];

    /** Packed command: intermediate << 16 | prefix << 8 | final. */
    private int $cmd = 0;

    /** OSC / DCS / SOS / PM / APC payload accumulator. */
    private string $stringBuffer = '';

    /** Default string-buffer cap (64 KiB) — prevents memory exhaustion from malicious sequences. */
    private const MAX_STRING_BUFFER = 65536;

    /** CSI/DCS parameter slots collected at most (ECMA-48 slack; caps hostile `;` floods). */
    private const MAX_PARAMS = 32;

    /** Parameter value saturates here so unbounded digit runs cannot overflow int math. */
    private const MAX_PARAM_VALUE = 65535;

    /**
     * Raw int state backing (see {@see $state}). These are constant
     * expressions of the enum's backing values, so the int and the enum can
     * never drift apart; the enum remains everything a consumer sees.
     */
    private const S_GROUND = State::Ground->value;
    private const S_DCS_ENTRY = State::DcsEntry->value;
    private const S_DCS_PARAM = State::DcsParam->value;
    private const S_DCS_INTERMEDIATE = State::DcsIntermediate->value;
    private const S_DCS_STRING = State::DcsString->value;
    private const S_ESCAPE = State::Escape->value;
    private const S_ESCAPE_INTERMEDIATE = State::EscapeIntermediate->value;
    private const S_OSC_STRING = State::OscString->value;
    private const S_SOS_STRING = State::SosString->value;
    private const S_PM_STRING = State::PmString->value;
    private const S_APC_STRING = State::ApcString->value;
    private const S_UTF8 = State::Utf8->value;

    /** Raw int action backing for the `perform()` routing (see {@see Action}). */
    private const A_NONE = Action::None->value;
    private const A_CLEAR = Action::Clear->value;
    private const A_COLLECT = Action::Collect->value;
    private const A_PREFIX = Action::Prefix->value;
    private const A_DISPATCH = Action::Dispatch->value;
    private const A_EXECUTE = Action::Execute->value;
    private const A_START = Action::Start->value;
    private const A_PUT = Action::Put->value;
    private const A_PARAM = Action::Param->value;
    private const A_PRINT = Action::Print->value;

    /**
     * Bitset of the five state ints accumulating a string payload (DcsString,
     * OscString, SosString, PmString, ApcString — not contiguous: Escape and
     * EscapeIntermediate sit between, so no range test). An abort out of one
     * of these drops the payload; membership is one `&` instead of the old
     * per-byte STRING_STATES list scan (finding sugar-ansi-parser-032).
     */
    private const STRING_STATE_MASK = (1 << State::DcsString->value)
        | (1 << State::OscString->value)
        | (1 << State::SosString->value)
        | (1 << State::PmString->value)
        | (1 << State::ApcString->value);

    /** Bytes of the current UTF-8 rune in flight. */
    private string $utf8Buffer = '';

    /** Total length of the rune we're currently collecting. */
    private int $utf8Need = 0;

    /**
     * Number of Ground-state printable-ASCII runs dispatched via the
     * {@see feed()} fast path (one per contiguous run, not per byte).
     * Diagnostic only — lets tests assert the fast path is exercised.
     */
    private int $fastPathRuns = 0;

    /**
     * The handler viewed through the optional {@see SubparamsAwareHandler}
     * capability, resolved once here rather than on every dispatch: the parser
     * is fed byte-by-byte in a hot loop, and a handler's class cannot change
     * under it. Null means the handler never opted in and receives no push.
     */
    private readonly ?SubparamsAwareHandler $subparamsSink;

    /**
     * The handler viewed through the optional {@see BulkPrintHandler}
     * capability, resolved once here for the same reason as
     * {@see $subparamsSink}: when present, the Ground printable sweep hands
     * over each contiguous run in one `printRun()` call instead of one
     * `printChar()` per byte. Null keeps the classic per-char contract —
     * byte-for-byte what every pre-capability consumer already sees.
     */
    private readonly ?BulkPrintHandler $bulkSink;

    /**
     * @param int $maxStringBuffer Cap (in bytes) on the DCS/OSC/SOS/PM/APC
     *                             payload accumulator. Defaults to 64 KiB;
     *                             emulator front-ends (e.g. sugar-vterm) may raise
     *                             it for large passthrough payloads.
     * @param bool $replaceMalformed Validate UTF-8 runes and substitute U+FFFD
     *                               for invalid ones. DEFAULT FALSE means no
     *                               validation at all: completed rune bytes ride
     *                               through verbatim to printChar(). When true,
     *                               validation calls mb_check_encoding(), so
     *                               ext-mbstring (declared in composer.json) is
     *                               required by this mode.
     * @throws \InvalidArgumentException if $maxStringBuffer is less than 1 (a
     *                                  zero/negative cap cannot accumulate any
     *                                  payload and is always a mistake) or if
     *                                  $replaceMalformed is requested without
     *                                  ext-mbstring present to serve it.
     */
    public function __construct(
        private readonly Handler $handler,
        private readonly bool $replaceMalformed = false,
        private readonly int $maxStringBuffer = self::MAX_STRING_BUFFER,
    ) {
        if ($maxStringBuffer < 1) {
            throw new \InvalidArgumentException(
                'String buffer cap must be at least 1 byte; got ' . $maxStringBuffer . '.',
            );
        }
        if ($replaceMalformed && !function_exists('mb_check_encoding')) {
            throw new \InvalidArgumentException(
                'replaceMalformed mode validates runes with mb_check_encoding();'
                . ' install ext-mbstring or construct the parser without the flag.',
            );
        }
        $this->subparamsSink = $handler instanceof SubparamsAwareHandler ? $handler : null;
        $this->bulkSink = $handler instanceof BulkPrintHandler ? $handler : null;
    }

    /**
     * Feed a chunk of bytes through the state machine. Safe to call
     * repeatedly; in-flight sequences carry across calls.
     */
    public function feed(string $bytes): void
    {
        $len = strlen($bytes);
        $i = 0;
        while ($i < $len) {
            $b = ord($bytes[$i]);

            if ($this->state === self::S_GROUND) {
                // Ground-state fast path: in Ground, every printable-ASCII byte
                // (0x20-0x7E) maps to Action::Print and stays in Ground (see the
                // transition table). Sweep the maximal run and emit its per-byte
                // printChar() calls directly, skipping the transition-table
                // round-trip for each byte (two enum ::from()s per byte were the
                // old cost; sap-01 removed them — the table round-trip remains).
                //
                // This is byte-for-byte identical to advancing one byte at a
                // time: exactly one printChar() with a single-byte rune per byte,
                // in order. 0x7F (DEL, Execute), C0 controls, ESC, C1 and UTF-8
                // lead bytes (>=0x80) all fall outside the range and route through
                // the normal state machine, preserving every boundary behaviour.
                if ($b >= 0x20 && $b <= 0x7E) {
                    $this->fastPathRuns++;
                    if ($this->bulkSink !== null) {
                        // Bulk dispatch (finding sugar-ansi-parser-002): one
                        // printRun() for the whole run. It still counts as
                        // one fast-path run — the diagnostic counts runs, not
                        // bytes, and its meaning is unchanged for bulk and
                        // classic consumers alike.
                        $runStart = $i;
                        do {
                            $i++;
                        } while ($i < $len && ($b = ord($bytes[$i])) >= 0x20 && $b <= 0x7E);
                        $this->bulkSink->printRun(substr($bytes, $runStart, $i - $runStart));
                        continue;
                    }
                    do {
                        $this->handler->printChar($bytes[$i]);
                        $i++;
                    } while ($i < $len && ($b = ord($bytes[$i])) >= 0x20 && $b <= 0x7E);
                    continue;
                }

                // Inline UTF-8 lead scan (finding sugar-ansi-parser-010): the
                // table takes every lead 0xC2-0xF4 from Ground to the Utf8
                // collection state (the anywhere rows — Transitions.php — are
                // unbranching for leads), so a complete, well-formed run of
                // `lead + (len-1)` continuations is provably one printChar()
                // of the whole slice. Take it without buffering per byte.
                // Anything the scan cannot prove complete — end-of-chunk
                // truncation or a scan break at a non-continuation — falls
                // through to advance(), whose Utf8 machinery stays the single
                // source of rune truth (mid-rune chunk carry, malformed
                // handling, drop-vs-U+FFFD policy all unchanged).
                if ($b >= 0xC2 && $b <= 0xF4) {
                    $need = $b <= 0xDF ? 2 : ($b <= 0xEF ? 3 : 4);
                    if ($i + $need <= $len) {
                        $complete = true;
                        for ($k = 1; $k < $need; $k++) {
                            $c = ord($bytes[$i + $k]);
                            if ($c < 0x80 || $c > 0xBF) {
                                $complete = false;
                                break;
                            }
                        }
                        if ($complete) {
                            $rune = substr($bytes, $i, $need);
                            if ($this->replaceMalformed && !$this->isValidUtf8Rune($rune)) {
                                $this->handler->printChar("\xEF\xBF\xBD");
                            } else {
                                $this->handler->printChar($rune);
                            }
                            $i += $need;
                            continue;
                        }
                    }
                }
            } elseif (((1 << $this->state) & self::STRING_STATE_MASK) !== 0) {
                // Bulk string-payload sweep (finding sugar-ansi-parser-011):
                // in a string state the table appends all-but-a-handful of the
                // byte space (Put → stay). strcspn against the build-time
                // derived terminator set finds the maximal safely-appendable
                // run in one pass; the run is appended whole instead of one
                // put() per byte (each of which re-evaluated strlen()). The
                // byte that stopped the sweep — a dispatch edge, a non-Put
                // hold like OSC's C0 ignore band, or a same-state
                // re-introducer Start — is advanced one byte at a time, so
                // every sequence boundary keeps its exact per-byte semantics.
                // The cap ride stays: at maxStringBuffer the put() route drops
                // further payload bytes silently, so a clipped run skips its
                // uncollectable remainder to the same landing point (the
                // truncation-signal gap is a separate row, not fixed here).
                $terminators = Transitions::terminators($this->state);
                $run = strcspn($bytes, $terminators, $i);
                if ($run > 0) {
                    $free = $this->maxStringBuffer - strlen($this->stringBuffer);
                    if ($free > 0) {
                        $this->stringBuffer .= substr($bytes, $i, min($run, $free));
                    }
                    $i += $run;
                    continue;
                }
            }

            $this->advance($b);
            $i++;
        }
    }

    /**
     * Feed a complete byte sequence and guarantee end-of-stream flush.
     *
     * This is the one-shot entry point: it calls feed() then flush(), so a
     * trailing unterminated OSC/DCS/SOS/PM/APC string still dispatches with
     * the payload collected so far, and — only when the parser was built
     * with `$replaceMalformed` — a trailing partial rune surfaces as
     * U+FFFD. In default mode that rune IS dropped, and an in-flight CSI or
     * bare ESC always is (there is no final byte to dispatch). Use
     * feed()+flush() separately for streaming (incremental) use.
     */
    public function parseComplete(string $bytes): void
    {
        $this->feed($bytes);
        $this->flush();
    }

    /**
     * Force any in-flight string sequence (OSC/DCS/SOS/PM/APC) to
     * dispatch with whatever payload has been collected, and return
     * to ground. Useful at end-of-stream.
     *
     * What flush does NOT recover:
     * - a DCS still in its PRELUDE (DcsEntry/DcsParam/DcsIntermediate — no
     *   final byte ever seen): an aborted sequence, dispatches nothing; its
     *   raw bytes are the caller's tail (candy-ansi-inspect recovers them
     *   itself). Fabricating a dispatch here would change that library's
     *   byte-fidelity goldens.
     * - an in-flight CSI or bare ESC: no final byte exists yet, so the
     *   partial sequence is dropped.
     * - an in-flight partial rune: dropped in default mode; U+FFFD only when
     *   built with `$replaceMalformed`.
     */
    public function flush(): void
    {
        $from = $this->state;
        if ((1 << $from) & self::STRING_STATE_MASK) {
            $this->dispatch(0, $from);
        }
        if ($this->replaceMalformed && $this->utf8Buffer !== '') {
            $this->handler->printChar("\xEF\xBF\xBD");
        }
        $this->state = self::S_GROUND;
        $this->utf8Buffer = '';
        $this->utf8Need = 0;
        $this->clear();
    }

    /**
     * Reset the parser to ground state.
     *
     * NOT a silent clear: this FLUSHES first, so an in-flight
     * OSC/DCS/SOS/PM/APC sequence **dispatches** to the handler with whatever
     * payload arrived (end-of-stream semantics), then all accumulated state
     * clears. A consumer that wants to abandon a mid-stream string without
     * receiving its dispatch has no API for that today — the dispatch is the
     * documented behaviour of {@see flush()}, which reset() delegates to.
     */
    public function reset(): void
    {
        $this->flush();
        $this->clear();
    }

    /**
     * Returns true if the given byte sequence is a well-formed UTF-8 rune.
     * Checks for: valid encoding, no overlong sequences, no surrogates,
     * and code point <= U+10FFFF.
     */
    private function isValidUtf8Rune(string $rune): bool
    {
        $len = strlen($rune);
        if ($len < 1 || $len > 4) {
            return false;
        }
        $cp = match (true) {
            $len === 1 => ord($rune[0]),
            $len === 2 => ((ord($rune[0]) & 0x1F) << 6) | (ord($rune[1]) & 0x3F),
            $len === 3 => ((ord($rune[0]) & 0x0F) << 12) | ((ord($rune[1]) & 0x3F) << 6) | (ord($rune[2]) & 0x3F),
            default => ((ord($rune[0]) & 0x07) << 18) | ((ord($rune[1]) & 0x3F) << 12) | ((ord($rune[2]) & 0x3F) << 6) | (ord($rune[3]) & 0x3F),
        };
        // Overlong checks
        if ($len === 2 && $cp < 0x80) {
            return false; // overlong
        }
        if ($len === 3 && $cp < 0x800) {
            return false; // overlong
        }
        if ($len === 4 && $cp < 0x10000) {
            return false; // overlong
        }
        // Surrogate check (U+D800-U+DFFF)
        if ($cp >= 0xD800 && $cp <= 0xDFFF) {
            return false;
        }
        // Max code point
        if ($cp > 0x10FFFF) {
            return false;
        }
        // Also verify with mb_check_encoding as additional safety
        return mb_check_encoding($rune, 'UTF-8');
    }

    /** @internal Exposed for tests asserting partial-input progress. */
    public function currentState(): State
    {
        // The single int→enum conversion boundary of the machine (sap-01):
        // internals store the backing value; consumers only ever see the enum.
        return State::from($this->state);
    }

    /** @internal Exposed for tests asserting the Ground-state fast path is taken. */
    public function fastPathRuns(): int
    {
        return $this->fastPathRuns;
    }

    /**
     * ECMA-48 sub-parameter continuation flags for the current (in-flight) or
     * most recently dispatched CSI/DCS parameter list: true at index i means
     * params[i] was followed by `:`, i.e. params[i + 1] belongs to the same
     * parameter group. Indices beyond the last flag default to false — an
     * empty result means the list held no `:` separators.
     *
     * A handler that owns its Parser reads them back mid-dispatch this way —
     * `candy-ansi-inspect`'s `AnsiHandler` does exactly that — and a driver reads them
     * after `feed()` for the last completed sequence; they reset when the next
     * sequence's params start accumulating. This is the PULL route.
     *
     * The pull window ends at {@see flush()}: flush clears the flags, so a
     * driver that used {@see parseComplete()} must read them from INSIDE the
     * dispatch — after the call returns they are gone.
     *
     * The alternative — the one the sugar-vterm and candy-codeshot sinks now use — is
     * the PUSH: implement {@see SubparamsAwareHandler} and be handed the same
     * flags in the dispatch call chain, so the handler needs no back-reference to
     * the parser dispatching it. {@see SubparamsAwareHandler::setSubparams()}
     * removes that back-reference where taken; it does not forbid pulling. Both
     * routes stay live and byte-identical (see that interface).
     *
     * @return list<bool>
     */
    public function subparams(): array
    {
        return $this->subparams;
    }

    /**
     * Reconstitute ECMA-48 parameter groups from a flattened params list plus
     * the {@see subparams()} continuation flags: each group is one parameter
     * followed by its `:`-separated sub-parameters. `CSI 4 : 3 m` yields
     * `[[4, 3]]` (one underline-style parameter) while `CSI 4 ; 3 m` yields
     * `[[4], [3]]` (underline, then a separate SGR 3) — the distinction the
     * flat list alone cannot express.
     *
     * @param list<int>  $params  Params as dispatched by the parser.
     * @param list<bool> $flags   Flags from {@see subparams()}.
     * @return list<list<int>>
     */
    public static function groupSubparameters(array $params, array $flags): array
    {
        $groups = [];
        foreach ($params as $i => $value) {
            $open = count($groups) - 1;
            if ($i === 0 || $open < 0 || ($flags[$i - 1] ?? false) !== true) {
                $groups[] = [$value];
                continue;
            }
            $groups[$open][] = $value;
        }
        return $groups;
    }

    private function advance(int $byte): void
    {
        if ($this->state === self::S_UTF8) {
            if ($byte >= 0x80 && $byte <= 0xBF) {
                $this->utf8Buffer .= chr($byte);
                if (strlen($this->utf8Buffer) >= $this->utf8Need) {
                    if ($this->replaceMalformed && !$this->isValidUtf8Rune($this->utf8Buffer)) {
                        $this->handler->printChar("\xEF\xBF\xBD");
                    } else {
                        $this->handler->printChar($this->utf8Buffer);
                    }
                    $this->utf8Buffer = '';
                    $this->utf8Need = 0;
                    $this->state = self::S_GROUND;
                }
                return;
            }
            // Non-continuation byte in Utf8 — drop the incomplete rune
            // and fall through with the byte processed from Ground.
            if ($this->replaceMalformed) {
                $this->handler->printChar("\xEF\xBF\xBD");
            }
            $this->utf8Buffer = '';
            $this->utf8Need = 0;
            $this->state = self::S_GROUND;
        }

        // Everything below routes on raw ints (finding sugar-ansi-parser-001):
        // the table already answers in ints, and hydrating Action/State enum
        // cases per byte paid a constant-load plus a linear case scan on the
        // hottest line of the library. The enum stays the public API; the
        // machine works in its backing values.
        $entry = Transitions::lookup($this->state, $byte);
        $action = Transitions::action($entry);
        $next = Transitions::nextState($entry);

        // UTF-8 lead bytes start a rune; bypass the regular Collect.
        if ($next === self::S_UTF8) {
            $this->utf8Buffer = chr($byte);
            $this->utf8Need = match (true) {
                $byte <= 0xDF => 2,
                $byte <= 0xEF => 3,
                default => 4,
            };
            $this->state = self::S_UTF8;
            return;
        }

        $from = $this->state;
        $this->perform($action, $byte, $from);
        // An ESC that aborts an in-flight string sequence lands in Escape with
        // only the table's own action (Dispatch for OSC/SOS/PM/APC/DCS — the
        // anywhere-loop Clear covers every other origin). Force the clear the
        // table omits, mirroring charmbracelet/x/ansi Advance(), so a
        // cancelled-but-dispatched sequence's params and intermediates cannot
        // bleed into the escape sequence that interrupted it: without this,
        // `ESC P 1 SP / q X ESC \` dispatches the final ESC \ with the stale
        // intermediate '/' (47) where upstream reports 0.
        if ($next === self::S_ESCAPE && $action !== self::A_CLEAR) {
            $this->clear();
        }
        // Defense-in-depth: a string sequence that reaches Ground on a table
        // edge carrying NEITHER Dispatch NOR Clear would leave its payload in
        // the buffer to bleed into whatever sequence starts next. No such edge
        // exists today — CAN/SUB carry Dispatch since the fidelity fix (they
        // emit the truncated payload instead of vanishing), every C1 from a
        // string state is Put-covered or dispatches, and only 0x9C/0x07/ESC
        // leave a string (each dispatching) — but a future table row must not
        // be able to reopen the bleed silently, so the guard stays. (The
        // start()-clears-buffer shape of the original bleed fix was rejected
        // because a mid-string C1 re-introducer MUST keep the bytes collected
        // before it — pinned downstream by sugar-vterm and here by
        // ParserTest::testC1ReintroducerPreservesStringPayload.)
        //
        // Evaluation order is the fix for finding sugar-ansi-parser-032: the
        // two int comparisons against the action (the discriminating tests —
        // almost every edge carries Dispatch or Clear) run BEFORE the rarer
        // Ground landing and the bitmask membership test, and the old
        // per-byte STRING_STATES list scan is gone: one `&` on
        // STRING_STATE_MASK answers it. Same truth, no allocation, no scan.
        if ($action !== self::A_DISPATCH
            && $action !== self::A_CLEAR
            && $next === self::S_GROUND
            && ((1 << $from) & self::STRING_STATE_MASK) !== 0
        ) {
            $this->stringBuffer = '';
        }
        $this->state = $next;
    }

    private function perform(int $action, int $byte, int $from): void
    {
        match ($action) {
            self::A_NONE => null,
            self::A_PRINT => $this->handler->printChar(chr($byte)),
            self::A_EXECUTE => $this->handler->execute($byte),
            self::A_CLEAR => $this->clear(),
            self::A_COLLECT => $this->collect($byte),
            self::A_PREFIX => $this->prefix($byte),
            self::A_PARAM => $this->param($byte),
            self::A_START => $this->start($byte, $from),
            self::A_PUT => $this->put($byte),
            self::A_DISPATCH => $this->dispatch($byte, $from),
        };
    }

    private function clear(): void
    {
        $this->params = [];
        $this->subparams = [];
        $this->cmd = 0;
        $this->stringBuffer = '';
    }

    private function collect(int $byte): void
    {
        $this->cmd = ($this->cmd & ~(0xFF << 16)) | ($byte << 16);
    }

    private function prefix(int $byte): void
    {
        $this->cmd = ($this->cmd & ~(0xFF << 8)) | ($byte << 8);
    }

    private function param(int $byte): void
    {
        // ';' (0x3B) and ':' (0x3A) both start a new param slot; ':' additionally
        // marks the PRECEDING slot as a sub-parameter group continuation (the
        // ECMA-48 `:` sub-parameter separator), recorded in $subparams and
        // surfaced via subparams().
        $n = count($this->params);
        if ($byte === 0x3B || $byte === 0x3A) {
            if ($n >= self::MAX_PARAMS) {
                return; // At cap, ignore separator
            }
            if ($n === 0) {
                $this->params[] = -1; // implicit default before the separator
                $this->subparams[] = false;
                $n = 1;
            }
            if ($byte === 0x3A) {
                $this->subparams[$n - 1] = true;
            }
            $this->params[] = -1;
            $this->subparams[] = false;
            return;
        }

        $digit = $byte - 0x30;
        if ($n === 0) {
            $this->params[] = $digit;
            $this->subparams[] = false;
            return;
        }
        $last = $n - 1;
        if ($this->params[$last] === -1) {
            $this->params[$last] = $digit;
            return;
        }
        // Even at cap, allow accumulation into the current (32nd) slot
        $this->params[$last] = min($this->params[$last] * 10 + $digit, self::MAX_PARAM_VALUE);
    }

    private function start(int $byte, int $from): void
    {
        // A DIFFERENT-type C1 string introducer mid-sequence is
        // an implicit cancel of the pending one — its payload must not ride
        // into the new sequence's dispatch (an APC fragment arriving as OSC
        // title data is a title-injection vector from untrusted peers).
        // Same-type re-introduction deliberately KEEPS the buffer
        // (testC1ReintroducerPreservesStringPayload).
        $target = $this->introducerTarget($byte);
        if ($target !== null
            && $target !== $from
            && ((1 << $from) & self::STRING_STATE_MASK) !== 0
        ) {
            $this->stringBuffer = '';
        }

        // For DCS, the byte that triggers Start IS the final command.
        if ($from === self::S_DCS_ENTRY || $from === self::S_DCS_PARAM || $from === self::S_DCS_INTERMEDIATE) {
            $this->cmd = ($this->cmd & ~0xFF) | $byte;
        }
    }

    /**
     * The string state a C1 introducer byte opens (raw int backing), or null
     * for any other byte. Used by the cross-type discard in {@see start()}.
     */
    private function introducerTarget(int $byte): ?int
    {
        return match ($byte) {
            0x98 => self::S_SOS_STRING,
            0x9D => self::S_OSC_STRING,
            0x9E => self::S_PM_STRING,
            0x9F => self::S_APC_STRING,
            default => null,
        };
    }

    private function put(int $byte): void
    {
        if (strlen($this->stringBuffer) >= $this->maxStringBuffer) {
            return;
        }
        $this->stringBuffer .= chr($byte);
    }

    private function dispatch(int $byte, int $from): void
    {
        $prefix = ($this->cmd >> 8) & 0xFF;
        $intermediate = ($this->cmd >> 16) & 0xFF;

        switch ($from) {
            case self::S_OSC_STRING:
                $this->handler->oscDispatch($this->stringBuffer);
                break;
            case self::S_DCS_STRING:
                $this->pushSubparams();
                $this->handler->dcsDispatch(
                    $this->cmd & 0xFF,
                    $this->params,
                    $prefix,
                    $intermediate,
                    $this->stringBuffer,
                );
                break;
            case self::S_SOS_STRING:
                $this->handler->sosPmApcDispatch('sos', $this->stringBuffer);
                break;
            case self::S_PM_STRING:
                $this->handler->sosPmApcDispatch('pm', $this->stringBuffer);
                break;
            case self::S_APC_STRING:
                $this->handler->sosPmApcDispatch('apc', $this->stringBuffer);
                break;
            case self::S_ESCAPE:
            case self::S_ESCAPE_INTERMEDIATE:
                $this->handler->escDispatch($byte, $intermediate);
                break;
            default:
                $this->pushSubparams();
                $this->handler->csiDispatch($byte, $this->params, $prefix, $intermediate);
        }
        $this->stringBuffer = '';
    }

    /**
     * Push the in-flight continuation flags to the handler when — and only when
     * — it opted into {@see SubparamsAwareHandler}.
     *
     * A handler that did not opt in is dispatched exactly as it was before this
     * capability existed: no extra call, no changed argument. The in-tree sinks
     * DO opt in and read the push — sugar-vterm's `ScreenHandler` (emulator) and
     * `RendererHandler` (renderer, forwarding to `CsiHandlerImpl`), and
     * candy-codeshot's `SgrStateHandler` — so none of them needs the late-bound
     * back-reference any more. {@see subparams()} remains public for a program
     * that owns a Parser directly; see {@see SubparamsAwareHandler}.
     */
    private function pushSubparams(): void
    {
        $this->subparamsSink?->setSubparams($this->subparams);
    }
}
