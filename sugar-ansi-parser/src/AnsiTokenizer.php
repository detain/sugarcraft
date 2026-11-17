<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser;

/**
 * Stream tokenizer for terminal byte input: raw bytes in, a list of
 * {@see Token} records out.
 *
 * This is the flat, dispatch-free view of the stream, for consumers that want
 * the classified slices rather than the VT500 handler callbacks of
 * {@see Parser\Parser}. It recognizes C0 controls, two-byte ESC sequences,
 * CSI, and the string envelopes OSC, DCS, APC, SOS and PM. A C1 byte (0x80-0x9F)
 * is ordinary text here: only the 7-bit spellings open a sequence.
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
     * Past the cap, the held bytes are recovered as a TEXT token instead of
     * being rescanned on every later feed.
     */
    public const MAX_PENDING_BYTES = 65536;

    /** Bytes carried over from the previous parse() call. */
    private string $pending = '';

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
        $this->pending = '';
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
                $consumed = $this->consumeEsc($buf, $i, $len, $tokens);
                if ($consumed < 0) {
                    // Incomplete. Hold the tail unless it has outgrown the cap:
                    // then no terminator this tokenizer accepts is coming, so
                    // return those bytes as text and resynchronize. The check
                    // uses the whole buffer, because the backlog ahead of the
                    // partial is what grew.
                    $rest = substr($buf, $i);
                    if ($len > self::MAX_PENDING_BYTES) {
                        $tokens[] = new Token(Token::TEXT, $rest);
                        return $tokens;
                    }
                    $this->pending = $rest;
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
        return [new Token(Token::TEXT, $rest)];
    }

    /**
     * Consume an `ESC ...` sequence starting at offset `$i`. Returns the number
     * of bytes consumed, or -1 when the sequence is incomplete.
     *
     * @param list<Token> $tokens output appended to
     */
    private function consumeEsc(string $buf, int $i, int $len, array &$tokens): int
    {
        if ($i + 1 >= $len) {
            return -1;
        }
        $second = $buf[$i + 1];

        if ($second === '[') {
            return $this->consumeCsi($buf, $i, $len, $tokens);
        }
        if ($second === ']') {
            return $this->consumeStTerminated($buf, $i, $len, $tokens, Token::OSC);
        }
        if ($second === 'P') {
            return $this->consumeStTerminated($buf, $i, $len, $tokens, Token::DCS);
        }
        if ($second === '_') {
            return $this->consumeStTerminated($buf, $i, $len, $tokens, Token::APC);
        }
        if ($second === 'X') {
            return $this->consumeStTerminated($buf, $i, $len, $tokens, Token::SOS);
        }
        if ($second === '^') {
            return $this->consumeStTerminated($buf, $i, $len, $tokens, Token::PM);
        }

        // Two-byte sequence: ESC 7, ESC 8, ESC c, ESC D, ESC M, and any other printable.
        $tokens[] = new Token(Token::ESC, $second);
        return 2;
    }

    /**
     * CSI grammar: `ESC [ <0x30-0x3f>* <0x20-0x2f>* <0x40-0x7e>`.
     *
     * The first parameter byte may be a private marker (`<`, `=`, `>`, `?`),
     * which is hoisted into `intermediate` so callers can dispatch on it.
     */
    private function consumeCsi(string $buf, int $i, int $len, array &$tokens): int
    {
        $j = $i + 2;
        $intermediate = '';
        $params = '';

        if ($j < $len) {
            $first = $buf[$j];
            if ($first === '?' || $first === '<' || $first === '=' || $first === '>') {
                $intermediate .= $first;
                $j++;
            }
        }

        while ($j < $len) {
            $b = ord($buf[$j]);
            if ($b >= 0x30 && $b <= 0x3f) {
                $params .= $buf[$j];
                $j++;
                continue;
            }
            break;
        }

        while ($j < $len) {
            $b = ord($buf[$j]);
            if ($b >= 0x20 && $b <= 0x2f) {
                $intermediate .= $buf[$j];
                $j++;
                continue;
            }
            break;
        }

        if ($j >= $len) {
            return -1;
        }
        $finalByte = $buf[$j];
        $finalOrd = ord($finalByte);
        if ($finalOrd < 0x40 || $finalOrd > 0x7e) {
            // Not a valid final: emit a bare ESC [ and resynchronize on the next byte.
            $tokens[] = new Token(Token::ESC, '[');
            return 2;
        }
        $tokens[] = new Token(Token::CSI, '', $intermediate, $params, $finalByte);
        return $j + 1 - $i;
    }

    /**
     * OSC, DCS, APC, SOS and PM share one envelope: `ESC <opener> <body> <ST | BEL>`,
     * where ST is `ESC \` and BEL is `\x07`.
     */
    private function consumeStTerminated(string $buf, int $i, int $len, array &$tokens, string $type): int
    {
        $j = $i + 2;
        $bodyStart = $j;
        while ($j < $len) {
            $b = $buf[$j];
            if ($b === "\x07") {
                $tokens[] = new Token($type, substr($buf, $bodyStart, $j - $bodyStart), terminator: "\x07");
                return $j + 1 - $i;
            }
            if ($b === "\x1b" && ($buf[$j + 1] ?? '') === '\\') {
                $tokens[] = new Token($type, substr($buf, $bodyStart, $j - $bodyStart), terminator: "\x1b\\");
                return $j + 2 - $i;
            }
            $j++;
        }
        return -1;
    }
}
