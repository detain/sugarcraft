<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

/**
 * Tokenising parser for terminal byte streams.
 *
 * `Parser` turns a raw input buffer (bytes from STDIN, a script,
 * or another process) into a list of {@see Token} records the rest
 * of the stack can dispatch on. It implements the classic
 * VT100/xterm escape-sequence state machine with extensions for
 * APC (used by CandyZone), DCS (used by the XTVERSION reply), SOS
 * and PM. A single instance can be reused across calls — incomplete
 * sequences split across read boundaries are buffered internally
 * until the next call brings the rest.
 *
 * Usage from a streaming context:
 * ```php
 * $parser = new Parser();
 * while (($chunk = fread($stdin, 4096)) !== false && $chunk !== '') {
 *     foreach ($parser->parse($chunk) as $token) {
 *         match ($token->type) {
 *             Token::CSI => $this->handleCsi($token),
 *             Token::OSC => $this->handleOsc($token),
 *             default    => $this->handleText($token),
 *         };
 *     }
 * }
 * ```
 *
 * The parser does NOT decode CSI parameters into bubbletea-style
 * Msg objects — that's `InputReader`'s job. Parser stays neutral
 * so the same tokens can power InputReader (input side), output
 * stripping (CandySprinkles), and OSC-reply handlers
 * (CandyShine).
 */
final class Parser
{
    /** Bytes carried over from the previous parse() call. */
    private string $pending = '';

    /**
     * Tokenise `$chunk` into a list of {@see Token}s.
     *
     * Incomplete sequences (e.g. an unterminated `ESC [` at the very
     * end of the chunk) are buffered and prepended to the next call.
     * This means callers can pump arbitrary read sizes without
     * worrying about cutting an escape in half.
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
                    // Incomplete — buffer and bail.
                    $this->pending = substr($buf, $i);
                    return $tokens;
                }
                $i += $consumed;
                continue;
            }

            // C0 control bytes — emit individually.
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

            // Printable / 8-bit — accumulate.
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
     * Drain any buffered partial sequence as a best-effort token list,
     * then reset internal state. Use on stream EOF if you want stuck
     * partial bytes to come back as plain text rather than disappear.
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
     * Consume an `ESC …` sequence starting at offset `$i` in `$buf`.
     * Returns the number of bytes consumed, or -1 if the sequence is
     * incomplete (caller should buffer the rest).
     *
     * @param array $tokens output appended to
     */
    private function consumeEsc(string $buf, int $i, int $len, array &$tokens): int
    {
        if ($i + 1 >= $len) {
            return -1;
        }
        $second = $buf[$i + 1];

        // CSI: ESC [
        if ($second === '[') {
            return $this->consumeCsi($buf, $i, $len, $tokens);
        }
        // OSC: ESC ]
        if ($second === ']') {
            return $this->consumeStTerminated($buf, $i, $len, $tokens, Token::OSC);
        }
        // DCS: ESC P
        if ($second === 'P') {
            return $this->consumeStTerminated($buf, $i, $len, $tokens, Token::DCS);
        }
        // APC: ESC _
        if ($second === '_') {
            return $this->consumeStTerminated($buf, $i, $len, $tokens, Token::APC);
        }
        // SOS: ESC X
        if ($second === 'X') {
            return $this->consumeStTerminated($buf, $i, $len, $tokens, Token::SOS);
        }
        // PM: ESC ^
        if ($second === '^') {
            return $this->consumeStTerminated($buf, $i, $len, $tokens, Token::PM);
        }

        // Two-byte ESC sequence (ESC 7 / ESC 8 / ESC c / ESC D / ESC M / ESC <printable>).
        $tokens[] = new Token(Token::ESC, $second);
        return 2;
    }

    /**
     * CSI grammar: `ESC [ <0x30-0x3f>* <0x20-0x2f>* <0x40-0x7e>`.
     *
     * Bytes 0x30-0x3f are parameter bytes (digits, `:`, `;`, `<`, `=`,
     * `>`, `?`). The first parameter byte may be a "private marker"
     * (`<`, `=`, `>`, `?`) which we hoist into `intermediate` so
     * callers can dispatch on it.
     *
     * Bytes 0x20-0x2f are intermediate bytes (`!"#$%&'()*+,-./` etc.).
     * Bytes 0x40-0x7e are final bytes — exactly one ends the sequence.
     */
    private function consumeCsi(string $buf, int $i, int $len, array &$tokens): int
    {
        $j = $i + 2;
        $intermediate = '';
        $params = '';

        // Optional private marker as the very first byte.
        if ($j < $len) {
            $first = $buf[$j];
            if ($first === '?' || $first === '<' || $first === '=' || $first === '>') {
                $intermediate .= $first;
                $j++;
            }
        }

        // Parameter bytes.
        while ($j < $len) {
            $b = ord($buf[$j]);
            if ($b >= 0x30 && $b <= 0x3f) {
                $params .= $buf[$j];
                $j++;
                continue;
            }
            break;
        }

        // Intermediate bytes (after parameters).
        while ($j < $len) {
            $b = ord($buf[$j]);
            if ($b >= 0x20 && $b <= 0x2f) {
                $intermediate .= $buf[$j];
                $j++;
                continue;
            }
            break;
        }

        // Final byte.
        if ($j >= $len) {
            return -1;
        }
        $finalByte = $buf[$j];
        $finalOrd = ord($finalByte);
        if ($finalOrd < 0x40 || $finalOrd > 0x7e) {
            // Invalid — bail by emitting a raw ESC token and resyncing on next byte.
            $tokens[] = new Token(Token::ESC, '[');
            return 2;
        }
        $tokens[] = new Token(Token::CSI, '', $intermediate, $params, $finalByte);
        return $j + 1 - $i;
    }

    /**
     * OSC / DCS / APC / SOS / PM all share the same envelope:
     * `ESC <opener> <body> <ST | BEL>`. ST is `ESC \`; BEL is `\x07`.
     */
    private function consumeStTerminated(string $buf, int $i, int $len, array &$tokens, string $type): int
    {
        $j = $i + 2;
        $bodyStart = $j;
        while ($j < $len) {
            $b = $buf[$j];
            if ($b === "\x07") {
                $tokens[] = new Token($type, substr($buf, $bodyStart, $j - $bodyStart));
                return $j + 1 - $i;
            }
            if ($b === "\x1b" && ($buf[$j + 1] ?? '') === '\\') {
                $tokens[] = new Token($type, substr($buf, $bodyStart, $j - $bodyStart));
                return $j + 2 - $i;
            }
            $j++;
        }
        return -1;
    }
}
