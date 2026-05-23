<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tape;

use SugarCraft\Vcr\Tape\Ast\ParseError;

/**
 * Line-oriented tape tokenizer.
 *
 * Each non-empty line that doesn't start with # is a directive.
 * Token types: TYPE, ENTER, TAB, BACKSPACE, SLEEP, SET, ENV, OUTPUT,
 * ARROW, CTRL, SPACE, ESCAPE, HIDE, SHOW, WAIT, SCREEN, SCREENSHOT, UNKNOWN.
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

            $token = $this->classifyLine($trimmed, $lineNum);
            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * Classify a non-empty, non-comment line into a token.
     */
    private function classifyLine(string $line, int $lineNum): Token
    {
        if (preg_match('/^Type\s+([\'"])(.*?)\1$/s', $line, $m)) {
            return new Token(self::TOKEN_TYPE, $m[2], $lineNum);
        }

        if ($line === 'Enter') {
            return new Token(self::TOKEN_ENTER, 'Enter', $lineNum);
        }

        if ($line === 'Tab') {
            return new Token(self::TOKEN_TAB, 'Tab', $lineNum);
        }

        if ($line === 'Backspace') {
            return new Token(self::TOKEN_BACKSPACE, 'Backspace', $lineNum);
        }

        if ($line === 'Space') {
            return new Token(self::TOKEN_SPACE, 'Space', $lineNum);
        }

        if ($line === 'Escape') {
            return new Token(self::TOKEN_ESCAPE, 'Escape', $lineNum);
        }

        if ($line === 'Up' || $line === 'Down' || $line === 'Left' || $line === 'Right') {
            return new Token(self::TOKEN_ARROW, $line, $lineNum);
        }

        if (preg_match('/^Sleep\s+(\d+(?:\.\d+)?)\s*(s|ms|m)$/i', $line, $m)) {
            $duration = (float) $m[1];
            $unit = strtolower($m[2]);
            $seconds = match ($unit) {
                's' => $duration,
                'ms' => $duration / 1000.0,
                'm' => $duration * 60.0,
                default => $duration,
            };
            return new Token(self::TOKEN_SLEEP, (string) $seconds, $lineNum);
        }

        if (preg_match('/^Set\s+(\S+)\s+(.*)$/', $line, $m)) {
            $key = $m[1];
            $value = $m[2];
            return new Token(self::TOKEN_SET, $key . "\x00" . $value, $lineNum);
        }

        if (preg_match('/^Env\s+(\S+)\s+["\'](.*?)["\']\s*$/', $line, $m)) {
            return new Token(self::TOKEN_ENV, $m[1] . "\x00" . $m[2], $lineNum);
        }

        if (preg_match('/^Output\s+(.+)$/', $line, $m)) {
            return new Token(self::TOKEN_OUTPUT, trim($m[1]), $lineNum);
        }

        if (preg_match('/^Ctrl\+([A-Za-z@\[\]\\\\^_])$/', $line, $m)) {
            return new Token(self::TOKEN_CTRL, $m[1], $lineNum);
        }

        if ($line === 'Hide') {
            return new Token(self::TOKEN_HIDE, 'Hide', $lineNum);
        }

        if ($line === 'Show') {
            return new Token(self::TOKEN_SHOW, 'Show', $lineNum);
        }

        if (preg_match('/^Wait\s+(\d+(?:\.\d+)?)\s*(s|ms|m)?$/i', $line, $m)) {
            $duration = (float) $m[1];
            $unit = isset($m[2]) ? strtolower($m[2]) : 's';
            $seconds = match ($unit) {
                's', '' => $duration,
                'ms' => $duration / 1000.0,
                'm' => $duration * 60.0,
                default => $duration,
            };
            return new Token(self::TOKEN_WAIT, (string) $seconds, $lineNum);
        }

        if (preg_match('/^Screen\s+(.+)$/i', $line, $m)) {
            return new Token(self::TOKEN_SCREEN, $m[1], $lineNum);
        }

        if (preg_match('/^Screenshot\s+(.+)$/i', $line, $m)) {
            return new Token(self::TOKEN_SCREENSHOT, $m[1], $lineNum);
        }

        return new Token(self::TOKEN_UNKNOWN, $line, $lineNum);
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
