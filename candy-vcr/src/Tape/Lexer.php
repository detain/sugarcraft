<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tape;

use SugarCraft\Vcr\Tape\Ast\ParseError;

/**
 * Line-oriented tape tokenizer.
 *
 * Each non-empty line that doesn't start with # is a directive.
 * Token types: TYPE, ENTER, TAB, BACKSPACE, SLEEP, SET, ENV, OUTPUT,
 * ARROW, CTRL, SPACE, ESCAPE, HIDE, SHOW, WAIT, SCREEN, SCREENSHOT, SOURCE, UNKNOWN.
 * Comments are preserved for round-trip.
 */
final readonly class Lexer
{
    public const TOKEN_TYPE = 'TYPE';
    public const TOKEN_ENTER = 'ENTER';
    public const TOKEN_TAB = 'TAB';
    public const TOKEN_BACKSPACE = 'BACKSPACE';
    public const TOKEN_SLEEP = 'SLEEP';
    public const TOKEN_SET = 'SET';
    public const TOKEN_ENV = 'ENV';
    public const TOKEN_OUTPUT = 'OUTPUT';
    public const TOKEN_ARROW = 'ARROW';
    public const TOKEN_CTRL = 'CTRL';
    public const TOKEN_SPACE = 'SPACE';
    public const TOKEN_ESCAPE = 'ESCAPE';
    public const TOKEN_HIDE = 'HIDE';
    public const TOKEN_SHOW = 'SHOW';
    public const TOKEN_WAIT = 'WAIT';
    public const TOKEN_SCREEN = 'SCREEN';
    public const TOKEN_SCREENSHOT = 'SCREENSHOT';
    public const TOKEN_SOURCE = 'SOURCE';
    public const TOKEN_UNKNOWN = 'UNKNOWN';
    public const TOKEN_COMMENT = 'COMMENT';

    /**
     * @return list<Token>
     */
    public function tokenize(string $source): array
    {
        $tokens = [];
        $lines = explode("\n", $source);
        $lineCount = count($lines);

        for ($i = 0; $i < $lineCount; $i++) {
            $lineNum = $i + 1;
            $raw = $lines[$i];
            $trimmed = trim($raw);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                $tokens[] = new Token(self::TOKEN_COMMENT, $raw, $lineNum);
                continue;
            }

            // A single line may carry several directives, e.g.
            //   Down  Sleep 200ms        # move, then pause
            //   Type "f"  Sleep 300ms    # press a key, then pause
            // Upstream VHS treats the tape as a whitespace-delimited token
            // stream where newlines aren't significant, so we peel
            // directives off the front of the line until it's consumed.
            foreach ($this->lexLine($trimmed, $lineNum) as $token) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * Tokenize one non-empty, non-comment line into one or more tokens.
     *
     * @return list<Token>
     */
    private function lexLine(string $line, int $lineNum): array
    {
        $tokens = [];
        $rest = $line;

        while (true) {
            $rest = ltrim($rest);
            if ($rest === '') {
                break;
            }
            // An inline comment runs to the end of the line.
            if ($rest[0] === '#') {
                $tokens[] = new Token(self::TOKEN_COMMENT, $rest, $lineNum);
                break;
            }

            [$token, $consumed] = $this->matchDirective($rest, $lineNum);
            if ($token === null || $consumed <= 0) {
                // Unclassifiable remainder — emit it whole as UNKNOWN and
                // stop so we never spin on a token we can't advance past.
                $tokens[] = new Token(self::TOKEN_UNKNOWN, $rest, $lineNum);
                break;
            }
            $tokens[] = $token;
            $rest = substr($rest, $consumed);
        }

        return $tokens;
    }

    /**
     * Match a single directive at the front of $s.
     *
     * @return array{0: ?Token, 1: int} the token (null when unmatched) and
     *         the number of bytes consumed off the front of $s.
     */
    private function matchDirective(string $s, int $lineNum): array
    {
        // Type takes one quoted argument; only the quoted run is consumed
        // so a trailing Sleep / comment stays on the line for the next pass.
        if (preg_match('/^Type\s+([\'"])(.*?)\1/s', $s, $m)) {
            return [new Token(self::TOKEN_TYPE, $m[2], $lineNum), strlen($m[0])];
        }

        if (preg_match('/^Sleep\s+(\d+(?:\.\d+)?)\s*(s|ms|m)(?=\s|$)/i', $s, $m)) {
            $seconds = $this->durationSeconds((float) $m[1], strtolower($m[2]));
            return [new Token(self::TOKEN_SLEEP, (string) $seconds, $lineNum), strlen($m[0])];
        }

        if (preg_match('/^Wait\s+(\d+(?:\.\d+)?)\s*(s|ms|m)?(?=\s|$)/i', $s, $m)) {
            $seconds = $this->durationSeconds((float) $m[1], isset($m[2]) ? strtolower($m[2]) : 's');
            return [new Token(self::TOKEN_WAIT, (string) $seconds, $lineNum), strlen($m[0])];
        }

        if (preg_match('/^Ctrl\+([A-Za-z@\[\]\\\\^_])(?=\s|$)/', $s, $m)) {
            return [new Token(self::TOKEN_CTRL, $m[1], $lineNum), strlen($m[0])];
        }

        // Bare keyword directives — anchored with a trailing boundary so
        // "Down" never swallows the start of a longer word.
        if (preg_match('/^(Enter|Tab|Backspace|Space|Escape|Hide|Show|Up|Down|Left|Right)(?=\s|$)/', $s, $m)) {
            $keyword = $m[1];
            $type = match ($keyword) {
                'Enter'     => self::TOKEN_ENTER,
                'Tab'       => self::TOKEN_TAB,
                'Backspace' => self::TOKEN_BACKSPACE,
                'Space'     => self::TOKEN_SPACE,
                'Escape'    => self::TOKEN_ESCAPE,
                'Hide'      => self::TOKEN_HIDE,
                'Show'      => self::TOKEN_SHOW,
                default     => self::TOKEN_ARROW, // Up / Down / Left / Right
            };
            return [new Token($type, $keyword, $lineNum), strlen($keyword)];
        }

        // Argument directives take free-form values and run to end of line;
        // they always stand alone, so consuming the remainder is correct.
        if (preg_match('/^Set\s+(\S+)\s+(.*)$/', $s, $m)) {
            return [new Token(self::TOKEN_SET, $m[1] . "\x00" . $m[2], $lineNum), strlen($m[0])];
        }

        if (preg_match('/^Env\s+(\S+)\s+["\'](.*?)["\']\s*$/', $s, $m)) {
            return [new Token(self::TOKEN_ENV, $m[1] . "\x00" . $m[2], $lineNum), strlen($m[0])];
        }

        if (preg_match('/^Output\s+(.+)$/', $s, $m)) {
            return [new Token(self::TOKEN_OUTPUT, trim($m[1]), $lineNum), strlen($m[0])];
        }

        // Screenshot must be tried before Screen (shared prefix).
        if (preg_match('/^Screenshot\s+(.+)$/i', $s, $m)) {
            return [new Token(self::TOKEN_SCREENSHOT, $m[1], $lineNum), strlen($m[0])];
        }

        if (preg_match('/^Screen\s+(.+)$/i', $s, $m)) {
            return [new Token(self::TOKEN_SCREEN, $m[1], $lineNum), strlen($m[0])];
        }

        if (preg_match('/^Source\s+(.+)$/i', $s, $m)) {
            return [new Token(self::TOKEN_SOURCE, trim($m[1]), $lineNum), strlen($m[0])];
        }

        return [null, 0];
    }

    /**
     * Convert a tape duration + unit to seconds.
     */
    private function durationSeconds(float $duration, string $unit): float
    {
        return match ($unit) {
            'ms'    => $duration / 1000.0,
            'm'     => $duration * 60.0,
            default => $duration, // 's' or empty
        };
    }
}

/**
 * A single token from the lexer.
 */
final readonly class Token
{
    public function __construct(
        public string $type,
        public string $value,
        public int $line,
    ) {
    }
}
