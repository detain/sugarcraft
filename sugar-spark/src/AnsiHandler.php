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
 * Mirrors charmbracelet/x/ansi Handler — accumulates segments rather than
 * rendering to a terminal.
 */
final class AnsiHandler implements Handler
{
    /** @var list<Segment> */
    private array $segments = [];

    private string $textBuf = '';

    private bool $inCsi = false;

    private string $csiFinal = '';

    private string $csiPrefix = '';

    private string $csiIntermediate = '';

    protected bool $ss3Buffered = false;

    private int $ss3Intermediate = 0;

    private bool $dcsInProgress = false;

    private bool $oscInProgress = false;

    private bool $sosPmInProgress = false;

    /** Tracks whether a sequence dispatch (CSI/OSC/DCS/APC/ESC) auto-flushed text since last drain. */
    private bool $sequenceProcessedSinceLastDrain = false;

    public function parse(string $input): array
    {
        $this->segments = [];
        $this->textBuf = '';
        $this->inCsi = false;
        $this->csiFinal = '';
        $this->csiPrefix = '';
        $this->csiIntermediate = '';
        $this->ss3Buffered = false;
        $this->ss3Intermediate = 0;
        $this->dcsInProgress = false;
        $this->oscInProgress = false;
        $this->sosPmInProgress = false;

        $parser = new Parser($this);
        $parser->feed($input);
        $stateBeforeFlush = $parser->currentState();
        $parser->flush();
        $this->flushText();

        if ($stateBeforeFlush === State::Escape) {
            $this->segments[] = new SequenceSegment("\x1b", Inspector::describeEsc(''));
        }

        if ($this->ss3Buffered) {
            $this->segments[] = new SequenceSegment(
                "\x1b" . chr($this->ss3Intermediate),
                'SS3 ' . chr($this->ss3Intermediate),
            );
            $this->ss3Buffered = false;
        }

        return $this->segments;
    }

    /**
     * Reset all accumulated state (segments, text buffer, flags).
     * Used by StreamingInspector between sessions.
     */
    public function reset(): void
    {
        $this->segments = [];
        $this->textBuf = '';
        $this->inCsi = false;
        $this->csiFinal = '';
        $this->csiPrefix = '';
        $this->csiIntermediate = '';
        $this->ss3Buffered = false;
        $this->ss3Intermediate = 0;
        $this->dcsInProgress = false;
        $this->oscInProgress = false;
        $this->sosPmInProgress = false;
        $this->sequenceProcessedSinceLastDrain = false;
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
     * Returns whether an SS3 intermediate byte is buffered awaiting a final byte.
     */
    public function isSs3Buffered(): bool
    {
        return $this->ss3Buffered;
    }

    /**
     * Returns the buffered SS3 intermediate byte value.
     */
    protected function getSs3Intermediate(): int
    {
        return $this->ss3Intermediate;
    }

    public function printChar(string $rune): void
    {
        if ($this->ss3Buffered) {
            $this->segments[] = new SequenceSegment(
                "\x1b" . chr($this->ss3Intermediate) . $rune,
                Inspector::describeSs3($rune),
            );
            $this->ss3Buffered = false;
            return;
        }
        $this->textBuf .= $rune;
    }

    public function execute(int $byte): void
    {
        if ($byte >= 0x00 && $byte <= 0x1F && $byte !== 0x1B) {
            $this->flushText();
            $this->sequenceProcessedSinceLastDrain = true;
            $this->segments[] = new SequenceSegment(
                chr($byte),
                'C0 ' . C0C1::c0Name($byte),
            );
        }
        // C1 bytes 0x80–0x9F: the VT500 "anywhere" transition table routes
        // 0x80–0x8F, 0x91–0x97, and 0x9C through Action::Execute (→ Ground).
        // The remaining C1 bytes (0x90, 0x98, 0x9A, 0x9B, 0x9D, 0x9E,
        // 0x9F) go to Entry states and are handled via their respective
        // dispatch callbacks (DCS/CSI/OSC/SOS/PM/APC), never reaching execute().
        if ($byte >= 0x80 && $byte <= 0x9F) {
            $this->flushText();
            $this->sequenceProcessedSinceLastDrain = true;
            $this->segments[] = new SequenceSegment(
                chr($byte),
                'C1 ' . C0C1::c1Name($byte),
            );
        }
    }

    public function csiDispatch(int $final, array $params, int $prefix, int $intermediate): void
    {
        $this->flushText();
        $this->sequenceProcessedSinceLastDrain = true;

        $prefixStr = $prefix !== 0 ? chr($prefix) : '';
        $intermediateStr = $intermediate !== 0 ? chr($intermediate) : '';
        $finalChar = chr($final);

        $paramsStr = $this->joinSgrParams($params, $finalChar);
        if ($prefixStr !== '') {
            $paramsStr = $prefixStr . $paramsStr;
        }

        $rawBytes = "\x1b[{$paramsStr}{$intermediateStr}" . $finalChar;
        $isSgr = $finalChar === 'm';
        $paramsForDescribe = $isSgr ? $paramsStr : $paramsStr . $intermediateStr;
        $label = Inspector::describeCsi($paramsForDescribe, $finalChar);

        $this->segments[] = new SequenceSegment($rawBytes, $label);
    }

    private function joinSgrParams(array $params, string $final): string
    {
        if ($final === 'm') {
            $parts = [];
            for ($i = 0, $n = count($params); $i < $n; $i++) {
                $p = $params[$i];
                if ($p === 4 && isset($params[$i + 1]) && $params[$i + 1] >= 1 && $params[$i + 1] <= 9) {
                    $parts[] = '4:' . $params[$i + 1];
                    $i++;
                    continue;
                }
                $parts[] = (string) $p;
            }
            return implode(';', $parts);
        }
        return implode(';', array_map('strval', $params));
    }

    public function escDispatch(int $final, int $intermediate): void
    {
        $this->flushText();
        $this->sequenceProcessedSinceLastDrain = true;

        if ($final === ord('O')) {
            $this->ss3Buffered = true;
            $this->ss3Intermediate = $intermediate !== 0 ? $intermediate : ord('O');
            return;
        }

        if ($final === ord('\\') && ($this->dcsInProgress || $this->oscInProgress || $this->sosPmInProgress)) {
            $this->dcsInProgress = false;
            $this->oscInProgress = false;
            $this->sosPmInProgress = false;
            return;
        }

        $intermediateStr = $intermediate !== 0 ? chr($intermediate) : '';
        $rawBytes = "\x1b{$intermediateStr}" . chr($final);
        $this->segments[] = new SequenceSegment($rawBytes, Inspector::describeEsc(chr($final)));
    }

    public function oscDispatch(string $data): void
    {
        $this->flushText();
        $this->sequenceProcessedSinceLastDrain = true;
        $this->oscInProgress = true;
        // OSC terminator normalized to BEL; original ST (\x1b\\) is not
        // preserved because candy-ansi's Parser strips it before dispatch.
        $this->segments[] = new SequenceSegment(
            "\x1b]{$data}\x07",
            Inspector::describeOsc($data),
        );
    }

    public function dcsDispatch(int $final, array $params, int $prefix, int $intermediate, string $data): void
    {
        $this->flushText();
        $this->sequenceProcessedSinceLastDrain = true;

        $this->dcsInProgress = true;

        $prefixStr = $prefix !== 0 ? chr($prefix) : '';
        $intermediateStr = $intermediate !== 0 ? chr($intermediate) : '';
        $paramsStr = implode(';', array_map(
            static fn(int $p): string => (string) $p,
            $params,
        ));

        $fullPayload = $intermediateStr . $prefixStr . $paramsStr . $data;
        $rawBytes = "\x1bP{$fullPayload}\x1b\\";
        $this->segments[] = new SequenceSegment($rawBytes, Inspector::describeDcs($fullPayload, $final));

        $this->dcsInProgress = false;
    }

    public function sosPmApcDispatch(string $kind, string $data): void
    {
        $isSosPm = $kind === 'sos' || $kind === 'pm';
        if ($isSosPm) {
            $this->sosPmInProgress = true;
            if ($data === '') {
                $this->sosPmInProgress = false;
                $label = $kind === 'sos' ? Inspector::describeEsc('X') : Inspector::describeEsc('^');
                $this->segments[] = new SequenceSegment("\x1b" . ($kind === 'sos' ? 'X' : '^'), $label);
                return;
            }
        }

        $this->flushText();
        $this->sequenceProcessedSinceLastDrain = true;

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

        if ($isSosPm) {
            $this->sosPmInProgress = false;
        }
    }

    private static function describeSosPm(string $data): string
    {
        if ($data === '') {
            return 'SOS string';
        }
        return 'SOS/PM ' . strlen($data) . ' bytes';
    }
}
