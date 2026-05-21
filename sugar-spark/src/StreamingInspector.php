<?php

declare(strict_types=1);

namespace SugarCraft\Spark;

/**
 * Streaming incremental parser for ANSI escape sequences.
 *
 * Unlike {@see Inspector::parse()} which requires complete input,
 * StreamingInspector can be fed input in chunks. It yields complete
 * segments as they are finished, and buffers incomplete sequences
 * and plain text between calls to {@see feed()}.
 *
 * Text segments are buffered and only yielded when:
 * - A sequence is encountered (text preceding the sequence is flushed)
 * - {@see finish()} is called (any remaining text is flushed)
 */
final class StreamingInspector
{
    /** Buffered incomplete sequence bytes pending the next chunk. */
    private string $buffer = '';

    /** Accumulated plain text awaiting a sequence or finish. */
    private string $textBuf = '';

    /** @return list<Segment> */
    public function feed(string $data): array
    {
        $this->buffer .= $data;
        return $this->parseAvailable();
    }

    /**
     * Flush any remaining buffered text as a final TextSegment.
     *
     * @return list<Segment>
     */
    public function finish(): array
    {
        $out = [];
        if ($this->textBuf !== '') {
            $out[] = new TextSegment($this->textBuf);
            $this->textBuf = '';
        }
        return $out;
    }

    /**
     * Process as much of the buffer as possible into complete segments.
     *
     * @return list<Segment>
     */
    private function parseAvailable(): array
    {
        $out = [];
        $prevBufferLen = -1;
        while (strlen($this->buffer) > 0) {
            $result = $this->tryParseOne();
            if ($result !== []) {
                foreach ($result as $seg) {
                    $out[] = $seg;
                }
            }
            // If buffer length hasn't changed and result is empty, we can't
            // make further progress — an incomplete sequence needs more data.
            if ($result === [] && strlen($this->buffer) === $prevBufferLen) {
                break;
            }
            $prevBufferLen = strlen($this->buffer);
        }
        return $out;
    }

    /**
     * Attempt to parse one or more complete segments from the buffer.
     * Returns an array (possibly empty) when a sequence is complete
     * (text flush + sequence), or an empty array if the buffer holds
     * an incomplete sequence.
     *
     * @return list<Segment>
     */
    private function tryParseOne(): array
    {
        if ($this->buffer === '') {
            return [];
        }

        $b = $this->buffer[0];

        // Accumulate plain text into textBuf (don't yield until a sequence or finish).
        if ($b !== "\x1b") {
            $len = strlen($this->buffer);
            for ($i = 0; $i < $len; $i++) {
                if ($this->buffer[$i] === "\x1b") {
                    break;
                }
            }
            $this->textBuf .= substr($this->buffer, 0, $i);
            $this->buffer = substr($this->buffer, $i);
            return []; // Text buffered; need a sequence or finish to yield it.
        }

        // Bare ESC at end of buffer — flush any pending text, keep ESC buffered.
        if (strlen($this->buffer) === 1) {
            $out = [];
            if ($this->textBuf !== '') {
                $out[] = new TextSegment($this->textBuf);
                $this->textBuf = '';
            }
            return $out;
        }

        $next = $this->buffer[1];

        if ($next === '[') {
            // CSI: ESC [ params final-byte — need final byte in 0x40-0x7E.
            $j = 2;
            $len = strlen($this->buffer);
            $foundFinal = false;
            while ($j < $len) {
                $c = ord($this->buffer[$j]);
                $j++;
                if ($c >= 0x40 && $c <= 0x7E) {
                    $foundFinal = true;
                    break;
                }
            }
            if (!$foundFinal) {
                return []; // Incomplete CSI.
            }
            $bytes = substr($this->buffer, 0, $j);
            $params = substr($this->buffer, 2, $j - 2 - 1);
            $final = substr($bytes, -1);
            $this->buffer = substr($this->buffer, $j);
            // Flush any pending text, then return the sequence.
            return $this->flushTextThenSequence($bytes, Inspector::describeCsi($params, $final));
        }

        if ($next === ']') {
            // OSC: ESC ] payload (BEL | ESC \).
            $j = 2;
            $len = strlen($this->buffer);
            $foundTerminator = false;
            while ($j < $len) {
                if ($this->buffer[$j] === "\x07") { $j++; $foundTerminator = true; break; }
                if ($this->buffer[$j] === "\x1b" && ($this->buffer[$j + 1] ?? '') === '\\') {
                    $j += 2; $foundTerminator = true; break;
                }
                $j++;
            }
            if (!$foundTerminator) {
                return []; // Incomplete OSC.
            }
            $bytes = substr($this->buffer, 0, $j);
            $payload = substr($bytes, 2, -1);
            $payload = rtrim($payload, "\x1b");
            $this->buffer = substr($this->buffer, $j);
            return $this->flushTextThenSequence($bytes, Inspector::describeOsc($payload));
        }

        if ($next === 'O') {
            // SS3: ESC O <byte> — 3 bytes total.
            if (strlen($this->buffer) < 3) {
                return [];
            }
            $bytes = substr($this->buffer, 0, 3);
            $this->buffer = substr($this->buffer, 3);
            return $this->flushTextThenSequence($bytes, Inspector::describeSs3($bytes[2] ?? ''));
        }

        if ($next === 'P') {
            // DCS: ESC P payload (BEL | ESC \).
            $j = 2;
            $len = strlen($this->buffer);
            $foundTerminator = false;
            while ($j < $len) {
                if ($this->buffer[$j] === "\x07") { $j++; $foundTerminator = true; break; }
                if ($this->buffer[$j] === "\x1b" && ($this->buffer[$j + 1] ?? '') === '\\') {
                    $j += 2; $foundTerminator = true; break;
                }
                $j++;
            }
            if (!$foundTerminator) {
                return []; // Incomplete DCS.
            }
            $bytes = substr($this->buffer, 0, $j);
            $payload = substr($bytes, 2, -2);
            $this->buffer = substr($this->buffer, $j);
            return $this->flushTextThenSequence($bytes, Inspector::describeDcs($payload));
        }

        if ($next === '_') {
            // APC: ESC _ payload (BEL | ESC \).
            $j = 2;
            $len = strlen($this->buffer);
            $foundTerminator = false;
            while ($j < $len) {
                if ($this->buffer[$j] === "\x07") { $j++; $foundTerminator = true; break; }
                if ($this->buffer[$j] === "\x1b" && ($this->buffer[$j + 1] ?? '') === '\\') {
                    $j += 2; $foundTerminator = true; break;
                }
                $j++;
            }
            if (!$foundTerminator) {
                return []; // Incomplete APC.
            }
            $bytes = substr($this->buffer, 0, $j);
            $payload = substr($bytes, 2, -2);
            $this->buffer = substr($this->buffer, $j);
            return $this->flushTextThenSequence($bytes, Inspector::describeApc($payload));
        }

        // Two-byte ESC <c> (e.g. ESC 7 = save cursor).
        if (strlen($this->buffer) < 2) {
            return [];
        }
        $bytes = substr($this->buffer, 0, 2);
        $this->buffer = substr($this->buffer, 2);
        return $this->flushTextThenSequence($bytes, Inspector::describeEsc($next));
    }

    /**
     * Flush any buffered text as a TextSegment, then return the sequence.
     *
     * @return list<Segment>
     */
    private function flushTextThenSequence(string $bytes, string $label): array
    {
        $out = [];
        if ($this->textBuf !== '') {
            $out[] = new TextSegment($this->textBuf);
            $this->textBuf = '';
        }
        $out[] = new SequenceSegment($bytes, $label);
        return $out;
    }
}
