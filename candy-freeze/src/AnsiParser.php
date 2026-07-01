<?php

declare(strict_types=1);

namespace SugarCraft\Freeze;

use SugarCraft\Ansi\Parser\Handler;
use SugarCraft\Ansi\Parser\Parser;

/**
 * Splits a single line of ANSI-styled text into typed {@see Segment}s
 * for {@see SvgRenderer}.
 *
 * Handles SGR foreground and background colours (16-color / 256-color
 * / 24-bit RGB) and the standard attribute flags (bold, italic,
 * underline). Background colours are passed through to segments for
 * per-segment rendering.
 *
 * Other ANSI sequences (CSI cursor moves, OSC, etc.) pass through
 * silently — they have no visible effect in a static SVG.
 *
 * Internally delegates to candy-ansi's {@see Parser} state machine.
 */
final class AnsiParser
{
    /** xterm 16-color palette as hex strings, used for `\x1b[3{0-7}m`. */
    public const ANSI16 = [
        0  => '#000000', 1  => '#cd0000', 2  => '#00cd00', 3  => '#cdcd00',
        4  => '#0000ee', 5  => '#cd00cd', 6  => '#00cdcd', 7  => '#e5e5e5',
        8  => '#7f7f7f', 9  => '#ff0000', 10 => '#00ff00', 11 => '#ffff00',
        12 => '#5c5cff', 13 => '#ff00ff', 14 => '#00ffff', 15 => '#ffffff',
    ];

    /**
     * Parse one line of ANSI text into a list of styled segments.
     *
     * @return list<Segment>
     */
    public static function parse(string $line): array
    {
        $segments = [];
        $state = new SgrState();
        $textBuf = '';
        $flush = static function () use (&$segments, &$textBuf, &$state): void {
            if ($textBuf === '') {
                return;
            }
            $segments[] = new Segment(
                text:      $textBuf,
                fg:        $state->fg,
                bold:      $state->bold,
                italic:    $state->italic,
                underline: $state->underline,
                bg:        $state->bg,
            );
            $textBuf = '';
        };

        $handler = new class($state, $textBuf, $flush, $segments) implements Handler
        {
            private SgrState $state;
            private string $textBuf;
            /** @var callable */
            private $flush;
            /** @var list<Segment> */
            private array $segments;

            public function __construct(SgrState &$state, string &$textBuf, callable $flush, array &$segments)
            {
                $this->state = &$state;
                $this->textBuf = &$textBuf;
                $this->flush = $flush;
                $this->segments = &$segments;
            }

            public function printChar(string $rune): void
            {
                $this->textBuf .= $rune;
            }

            public function execute(int $byte): void
            {
            }

            public function csiDispatch(int $final, array $params, int $prefix, int $intermediate): void
            {
                if (chr($final) !== 'm') {
                    return;
                }

                ($this->flush)();
                $this->state = $this->applySgr($params, $this->state);
            }

            public function escDispatch(int $final, int $intermediate): void
            {
            }

            public function oscDispatch(string $data): void
            {
            }

            public function dcsDispatch(int $final, array $params, int $prefix, int $intermediate, string $data): void
            {
            }

            public function sosPmApcDispatch(string $kind, string $data): void
            {
            }

            private function applySgr(array $params, SgrState $cur): SgrState
            {
                $fg = $cur->fg;
                $bg = $cur->bg;
                $bold = $cur->bold;
                $italic = $cur->italic;
                $underline = $cur->underline;

                $count = count($params);
                for ($i = 0; $i < $count; $i++) {
                    $p = $params[$i];
                    $applied = match (true) {
                        $p === 0  => fn() => ['fg' => null, 'bg' => null, 'bold' => false, 'italic' => false, 'underline' => false],
                        $p === 1  => fn() => ['bold' => true],
                        $p === 3  => fn() => ['italic' => true],
                        $p === 4  => fn() => ['underline' => true],
                        $p === 22 => fn() => ['bold' => false],
                        $p === 23 => fn() => ['italic' => false],
                        $p === 24 => fn() => ['underline' => false],
                        $p === 39 => fn() => ['fg' => null],
                        $p === 49 => fn() => ['bg' => null],
                        default   => null,
                    };
                    if ($applied !== null) {
                        $changes = $applied();
                        foreach ($changes as $k => $v) {
                            $$k = $v;
                        }
                        continue;
                    }
                    if ($p >= 30 && $p <= 37) {
                        $fg = AnsiParser::ANSI16[$p - 30] ?? null;
                        continue;
                    }
                    if ($p >= 90 && $p <= 97) {
                        $fg = AnsiParser::ANSI16[$p - 90 + 8] ?? null;
                        continue;
                    }
                    if ($p >= 40 && $p <= 47) {
                        $bg = AnsiParser::ANSI16[$p - 40] ?? null;
                        continue;
                    }
                    if ($p >= 100 && $p <= 107) {
                        $bg = AnsiParser::ANSI16[$p - 100 + 8] ?? null;
                        continue;
                    }
                    if ($p === 38 && isset($params[$i + 1])) {
                        $mode = $params[$i + 1];
                        if ($mode === 5 && isset($params[$i + 2])) {
                            $fg = AnsiParser::xterm256ToHex($params[$i + 2]);
                            $i += 2;
                            continue;
                        }
                        if ($mode === 2 && isset($params[$i + 2], $params[$i + 3], $params[$i + 4])) {
                            $fg = sprintf('#%02x%02x%02x', $params[$i + 2], $params[$i + 3], $params[$i + 4]);
                            $i += 4;
                            continue;
                        }
                    }
                    if ($p === 48 && isset($params[$i + 1])) {
                        $mode = $params[$i + 1];
                        if ($mode === 5 && isset($params[$i + 2])) {
                            $bg = AnsiParser::xterm256ToHex($params[$i + 2]);
                            $i += 2;
                            continue;
                        }
                        if ($mode === 2 && isset($params[$i + 2], $params[$i + 3], $params[$i + 4])) {
                            $bg = sprintf('#%02x%02x%02x', $params[$i + 2], $params[$i + 3], $params[$i + 4]);
                            $i += 4;
                            continue;
                        }
                    }
                }
                return new SgrState($fg, $bg, $bold, $italic, $underline);
            }
        };

        $parser = new Parser($handler);
        $parser->feed($line);
        $parser->flush();
        $flush();

        return $segments;
    }

    public static function xterm256ToHex(int $i): string
    {
        if ($i < 16) {
            return self::ANSI16[$i] ?? '#ffffff';
        }
        if ($i >= 232) {
            $g = 8 + ($i - 232) * 10;
            return sprintf('#%02x%02x%02x', $g, $g, $g);
        }
        $idx = $i - 16;
        $levels = [0, 95, 135, 175, 215, 255];
        return sprintf(
            '#%02x%02x%02x',
            $levels[intdiv($idx, 36)],
            $levels[intdiv($idx, 6) % 6],
            $levels[$idx % 6],
        );
    }
}


