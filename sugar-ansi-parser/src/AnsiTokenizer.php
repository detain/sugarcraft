<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser;

/**
 * Stream tokenizer for terminal output bytes: raw bytes in, a list of
 * {@see Token} records out.
 *
 * This is the flat, dispatch-free view of the stream, for consumers that want
 * the classified slices rather than the VT500 handler callbacks of
 * {@see Parser\Parser}. It recognizes C0 controls, two-byte ESC sequences,
 * CSI, and the string envelopes OSC, DCS, APC, SOS and PM. A C1 byte (0x80-0x9F)
 * is ordinary text here: only the 7-bit spellings open a sequence.
 *
 * It is an output-stream tokenizer, not a keyboard decoder. A string opener
 * holds every byte after it until a terminator or the cap, so a bare `ESC P`
 * typed as Alt+Shift+P would swallow the keys that follow it. Keyboard input
 * belongs to sugar-core's InputReader, which narrows the DCS arm to the
 * XTVERSION reply.
 *
 * An incomplete sequence at the end of a chunk is held and joined to the next
 * {@see parse()} call. {@see flush()} drains whatever is still held, as text,
 * at end of stream. Held bytes are capped at {@see MAX_PENDING_BYTES}; past
 * that the bytes are returned as text and the tokenizer resynchronizes, so a
 * hostile stream cannot grow the buffer without bound.
 *
 * Usage:
 * ```php
 * $tokenizer = AnsiTokenizer::new();
 * foreach ($tokenizer->parse($chunk) as $token) {
 *     match ($token->type) {
 *         Token::CSI => $this->handleCsi($token),
 *         Token::OSC => $this->handleOsc($token),
 *         default    => $this->handleText($token),
 *     };
 * }
 * // at end of stream:
 * $rest = $tokenizer->flush();
 * ```
 *
 * One instance is one stream. Use a fresh instance per stream; sharing one
 * across streams joins their partial sequences.
 */
final class AnsiTokenizer
{
    /**
     * Upper bound on a single incomplete sequence held across parse() calls.
     * Real traffic never approaches it (OSC and DCS replies are tens of bytes).
     * Past the cap, the held bytes are recovered as a TEXT token and the
     * tokenizer resynchronizes.
     *
     * The cap bounds the buffer, not the total work per feed. An unterminated
     * OSC/DCS/APC/SOS/PM body keeps its scan position across feeds, so each body
     * byte is examined once however many feeds it arrives in. A held CSI is still
     * rescanned from its ESC on every feed, so a stream of 1-byte feeds that keeps
     * a CSI open costs up to roughly MAX_PENDING_BYTES^2 / 2 steps in total.
     */
    public const MAX_PENDING_BYTES = 65536;

    /** Bytes carried over from the previous parse() call. */
    private string $pending = '';

    /**
     * Offset into {@see $pending} where an unterminated string body stopped
     * being scanned, or 0 when no string envelope is held. Only ever non-zero
     * when `$pending` starts with the envelope's ESC, so the next feed resumes
     * the body scan there instead of rescanning it from the opener.
     */
    private int $pendingScan = 0;

    /**
     * Set by {@see consumeStTerminated()} when it runs out of input: the offset
     * in the buffer where the next feed must resume the body scan. Reset before
     * every escape-sequence consumption, so a stale value never leaks into a
     * later hold decision.
     */
    private int $envelopeScanStop = 0;

    private function __construct()
    {
    }

    public static function new(): self
    {
        return new self();
    }

    /**
     * Tokenize `$chunk`. Returns the tokens that are complete now; an
     * unfinished trailing sequence is held for the next call.
     *
     * @return list<Token>
     */
    public function parse(string $chunk): array
    {
        $buf = $this->pending . $chunk;
        $resume = $this->pendingScan;
        $this->pending = '';
        $this->pendingScan = 0;
        $len = strlen($buf);
        $i = 0;
        $tokens = [];
        $textStart = -1;

        while ($i < $len) {
            $b = $buf[$i];

            // ESC starts an escape sequence. Flush any pending text first.
            if ($b === "\x1b") {
                if ($textStart >= 0 && $textStart < $i) {
                    $tokens[] = new Token(Token::TEXT, substr($buf, $textStart, $i - $textStart));
                }
                $textStart = -1;
                // A held envelope always begins at offset 0, so only the first
                // escape of this buffer can resume a body scan.
                $this->envelopeScanStop = 0;
                $consumed = $this->consumeEsc($buf, $i, $len, $tokens, $i === 0 ? $resume : 0);
                if ($consumed < 0) {
                    // Incomplete. Hold the tail unless the tail itself has
                    // outgrown the cap: then no terminator this tokenizer
                    // accepts is coming, so return those bytes as text and
                    // resynchronize. The check is on the held bytes, not the
                    // whole buffer, so a large chunk ending in a short partial
                    // still keeps that partial for the next call.
                    $rest = substr($buf, $i);
                    if (strlen($rest) > self::MAX_PENDING_BYTES) {
                        $tokens[] = new Token(Token::TEXT, $rest);
                        return $tokens;
                    }
                    $this->pending = $rest;
                    if ($this->envelopeScanStop > 0) {
                        $this->pendingScan = $this->envelopeScanStop - $i;
                    }
                    return $tokens;
                }
                $i += $consumed;
                continue;
            }

            // C0 controls and DEL are emitted one byte per token.
            $ord = ord($b);
            if ($ord < 0x20 || $ord === 0x7f) {
                if ($textStart >= 0 && $textStart < $i) {
                    $tokens[] = new Token(Token::TEXT, substr($buf, $textStart, $i - $textStart));
                }
                $textStart = -1;
                $tokens[] = new Token(Token::CONTROL, $b);
                $i++;
                continue;
            }

            // Printable and 8-bit bytes accumulate into one text run.
            if ($textStart < 0) {
                $textStart = $i;
            }
            $i++;
        }
        if ($textStart >= 0 && $textStart < $i) {
            $tokens[] = new Token(Token::TEXT, substr($buf, $textStart, $i - $textStart));
        }
        return $tokens;
    }

    /**
     * Return any held partial sequence as one TEXT token and clear the buffer.
     * Call at end of stream so a stuck partial comes back as text rather than
     * disappearing.
     *
     * @return list<Token>
     */
    public function flush(): array
    {
        if ($this->pending === '') {
            return [];
        }
        $rest = $this->pending;
        $this->pending = '';
        $this->pendingScan = 0;
        return [new Token(Token::TEXT, $rest)];
    }

    /**
     * Consume an `ESC ...` sequence starting at offset `$i`. Returns the number
     * of bytes consumed, or -1 when the sequence is incomplete.
     *
     * `$scanFrom` is the resume offset for a string body held from an earlier
     * feed; 0 means scan from the start of the body.
     *
     * @param list<Token> $tokens output appended to
     */
    /**
     * True for a C0 control the VT500 machine executes without leaving the
     * state it is in (Escape, EscapeIntermediate, CSI). CAN and SUB are not
     * among them: they are "anywhere" transitions that end the sequence.
     */
    private static function executesInPlace(string $byte): bool
    {
        $ord = ord($byte);
        return $ord <= 0x17 || $ord === 0x19 || ($ord >= 0x1c && $ord <= 0x1f);
    }

    /**
     * Records the in-place controls from `$from` on, and returns the offset of
     * the first byte that is not one.
     *
     * @param list<int> $controls offsets of the controls seen so far
     */
    private static function skipInPlace(string $buf, int $from, int $len, array &$controls): int
    {
        $j = $from;
        while ($j < $len && self::executesInPlace($buf[$j])) {
            $controls[] = $j;
            $j++;
        }
        return $j;
    }

    /**
     * Emits the recorded in-place controls as CONTROL tokens, in order. Called
     * only once the sequence that contains them commits, so a held partial
     * never emits its controls and then re-emits them on the next feed.
     *
     * @param list<int> $controls
     * @param list<Token> $tokens
     */
    private static function emitControls(string $buf, array $controls, array &$tokens): void
    {
        foreach ($controls as $offset) {
            $tokens[] = new Token(Token::CONTROL, $buf[$offset]);
        }
    }

    /**
     * @param list<int> $controls in-place controls between ESC and the introducer
     */
    private function consumeEsc(string $buf, int $i, int $len, array &$tokens, int $scanFrom = 0): int
    {
        // C0 controls right after the ESC execute, and the sequence goes on.
        $controls = [];
        $k = self::skipInPlace($buf, $i + 1, $len, $controls);
        if ($k >= $len) {
            return -1;
        }
        $second = $buf[$k];

        // ESC ESC: the VT500 Escape state clears on ESC and restarts, so the
        // first ESC is abandoned without a token and the second opens the
        // sequence. Emitting it as a two-byte ESC token would strand the
        // sequence's own bytes as text. CAN and SUB abandon the ESC the same
        // way, and a high byte is not a two-byte sequence: it opens a UTF-8
        // rune (or is a stray 8-bit byte), and an ESC token would take its
        // lead byte and strand the continuation bytes as an invalid TEXT
        // token. In each case the byte is re-read as the next token.
        if ($second === "\x1b" || $second === "\x18" || $second === "\x1a" || ord($second) >= 0x80) {
            self::emitControls($buf, $controls, $tokens);
            return $k - $i;
        }
        if ($second === '[') {
            return $this->consumeCsi($buf, $i, $k, $len, $tokens, $controls);
        }
        if ($second === ']') {
            return $this->consumeStTerminated($buf, $i, $k, $len, $tokens, Token::OSC, $scanFrom, $controls);
        }
        if ($second === 'P') {
            return $this->consumeStTerminated($buf, $i, $k, $len, $tokens, Token::DCS, $scanFrom, $controls);
        }
        if ($second === '_') {
            return $this->consumeStTerminated($buf, $i, $k, $len, $tokens, Token::APC, $scanFrom, $controls);
        }
        if ($second === 'X') {
            return $this->consumeStTerminated($buf, $i, $k, $len, $tokens, Token::SOS, $scanFrom, $controls);
        }
        if ($second === '^') {
            return $this->consumeStTerminated($buf, $i, $k, $len, $tokens, Token::PM, $scanFrom, $controls);
        }

        // ESC intermediates: `ESC <0x20-0x2F>+ <0x30-0x7E>`, as the VT500
        // EscapeIntermediate state takes them. Charset designation (`ESC ( 0`),
        // DECALN (`ESC # 8`) and S7C1T (`ESC SP F`) are all of this shape, and
        // splitting them after the first byte would leak the final as TEXT.
        if (ord($second) >= 0x20 && ord($second) <= 0x2f) {
            $inter = '';
            $j = $k;
            while (true) {
                $j = self::skipInPlace($buf, $j, $len, $controls);
                if ($j >= $len || ord($buf[$j]) < 0x20 || ord($buf[$j]) > 0x2f) {
                    break;
                }
                $inter .= $buf[$j];
                $j++;
            }
            if ($j >= $len) {
                return -1;
            }
            $finalOrd = ord($buf[$j]);
            // An ESC or a non-final byte abandons the sequence without a token,
            // as the VT500 machine clears on ESC; the byte then opens (or is)
            // the next token, as in consumeCsi().
            if ($finalOrd < 0x30 || $finalOrd > 0x7e) {
                self::emitControls($buf, $controls, $tokens);
                return $j - $i;
            }
            self::emitControls($buf, $controls, $tokens);
            $tokens[] = new Token(Token::ESC, $buf[$j], $inter);
            return $j + 1 - $i;
        }

        // Two-byte sequence: ESC 7, ESC 8, ESC c, ESC D, ESC M, and any other printable.
        self::emitControls($buf, $controls, $tokens);
        $tokens[] = new Token(Token::ESC, $second);
        return $k + 1 - $i;
    }

    /**
     * CSI grammar: `ESC [ <0x30-0x3f>* <0x20-0x2f>* <0x40-0x7e>`.
     *
     * The first parameter byte may be a private marker (`<`, `=`, `>`, `?`),
     * which is hoisted into `intermediate` so callers can dispatch on it.
     */
    /**
     * @param list<int> $controls in-place controls between ESC and `[`
     */
    private function consumeCsi(string $buf, int $i, int $k, int $len, array &$tokens, array $controls): int
    {
        // C0 controls may sit anywhere in the CSI and execute in place, so each
        // byte read below is followed by a skip over any controls after it.
        $inside = [];
        $intermediate = '';
        $params = '';

        $j = self::skipInPlace($buf, $k + 1, $len, $inside);
        if ($j < $len) {
            $first = $buf[$j];
            if ($first === '?' || $first === '<' || $first === '=' || $first === '>') {
                $intermediate .= $first;
                $j = self::skipInPlace($buf, $j + 1, $len, $inside);
            }
        }

        while ($j < $len) {
            $b = ord($buf[$j]);
            if ($b < 0x30 || $b > 0x3f) {
                break;
            }
            $params .= $buf[$j];
            $j = self::skipInPlace($buf, $j + 1, $len, $inside);
        }

        while ($j < $len) {
            $b = ord($buf[$j]);
            if ($b < 0x20 || $b > 0x2f) {
                break;
            }
            $intermediate .= $buf[$j];
            $j = self::skipInPlace($buf, $j + 1, $len, $inside);
        }

        if ($j >= $len) {
            return -1;
        }
        // An ESC inside a CSI abandons the partial sequence without a token,
        // as the VT500 machine clears on ESC from any CSI state; the ESC
        // itself starts the next sequence. CAN and SUB end it the same way,
        // and the byte is then read again as a control.
        if ($buf[$j] === "\x1b" || $buf[$j] === "\x18" || $buf[$j] === "\x1a") {
            self::emitControls($buf, $controls, $tokens);
            self::emitControls($buf, $inside, $tokens);
            return $j - $i;
        }
        $finalByte = $buf[$j];
        $finalOrd = ord($finalByte);
        if ($finalOrd < 0x40 || $finalOrd > 0x7e) {
            // Not a valid final: emit a bare ESC [ and resynchronize on the next
            // byte. The controls after `[` are left to that rescan, so they are
            // emitted once, by the caller's loop, and not here.
            self::emitControls($buf, $controls, $tokens);
            $tokens[] = new Token(Token::ESC, '[');
            return $k + 1 - $i;
        }
        self::emitControls($buf, $controls, $tokens);
        self::emitControls($buf, $inside, $tokens);
        $tokens[] = new Token(Token::CSI, '', $intermediate, $params, $finalByte);
        return $j + 1 - $i;
    }

    /**
     * OSC, DCS, APC, SOS and PM share one envelope: `ESC <opener> <body> <ST | BEL>`,
     * where ST is `ESC \` and BEL is `\x07`.
     *
     * When the envelope is unterminated, the offset where the body scan stopped
     * is left in {@see $envelopeScanStop} for the next feed to resume from.
     */
    /**
     * @param list<int> $controls in-place controls between ESC and the opener
     */
    private function consumeStTerminated(string $buf, int $i, int $k, int $len, array &$tokens, string $type, int $scanFrom, array $controls): int
    {
        $bodyStart = $k + 1;
        $j = $scanFrom > 0 ? $scanFrom : $bodyStart;
        while ($j < $len) {
            $b = $buf[$j];
            if ($b === "\x07") {
                self::emitControls($buf, $controls, $tokens);
                $tokens[] = new Token($type, substr($buf, $bodyStart, $j - $bodyStart), terminator: "\x07");
                return $j + 1 - $i;
            }
            if ($b === "\x1b") {
                // The verdict on this ESC waits for the byte after it.
                if ($j + 1 >= $len) {
                    break;
                }
                if ($buf[$j + 1] === '\\') {
                    self::emitControls($buf, $controls, $tokens);
                    $tokens[] = new Token($type, substr($buf, $bodyStart, $j - $bodyStart), terminator: "\x1b\\");
                    return $j + 2 - $i;
                }
                // Any other ESC aborts the string as the VT500 machine does:
                // the body so far is dispatched with no terminator, and the
                // ESC opens the next sequence. Swallowing it into the body
                // would lose the key or SGR that follows.
                self::emitControls($buf, $controls, $tokens);
                $tokens[] = new Token($type, substr($buf, $bodyStart, $j - $bodyStart));
                return $j - $i;
            }
            $j++;
        }
        // Every body byte before len-1 has already been checked together with
        // its successor, which is in the buffer. A trailing ESC is the one
        // byte whose verdict waits on a byte not yet seen, so resume there.
        $this->envelopeScanStop = max($bodyStart, $len - 1);
        return -1;
    }
}
