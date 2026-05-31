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

    private bool $ss3Buffered = false;

    private int $ss3Intermediate = 0;

    private bool $dcsInProgress = false;

    private bool $oscInProgress = false;

    private bool $sosPmInProgress = false;

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

    private function flushText(): void
    {
        if ($this->textBuf !== '') {
            $this->segments[] = new TextSegment($this->textBuf);
            $this->textBuf = '';
        }
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
            $this->segments[] = new SequenceSegment(
                chr($byte),
                'C0 ' . C0C1::c0Name($byte),
            );
        }
    }

    public function csiDispatch(int $final, array $params, int $prefix, int $intermediate): void
    {
        $this->flushText();

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
            for ($i = 0; $i < count($params); $i++) {
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
        $this->oscInProgress = true;
        $this->segments[] = new SequenceSegment(
            "\x1b]{$data}\x07",
            Inspector::describeOsc($data),
        );
    }

    public function dcsDispatch(int $final, array $params, int $prefix, int $intermediate, string $data): void
    {
        $this->flushText();

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
