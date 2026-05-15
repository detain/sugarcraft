<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Assert;

/**
 * Pattern-match assertion on output.
 *
 * Checks whether the actual output matches a given regular expression.
 * Unlike {@see ByteAssertion} which requires exact byte equality, this
 * assertion allows flexible pattern matching — useful for validating
 * output structure without exact formatting requirements.
 *
 * The `$expected` parameter to {@see compare()} is treated as a PCRE
 * regular expression pattern, NOT a literal string. The `$actual`
 * parameter is the string to test against the pattern.
 *
 * Example:
 * ```php
 * $assertion = new RegexAssertion('/^hello world!\n$/');
 * $result = $assertion->compare('/^hello world!\n$/', "hello world!\n");
 * // $result['ok'] === true
 * ```
 */
final class RegexAssertion implements Assertion
{
    /**
     * @param string $pattern PCRE regular expression pattern (with delimiters)
     * @param bool $multiline If true, `^` and `$` match at line breaks as well as string boundaries
     * @param bool $caseInsensitive If true, pattern matching is case-insensitive
     * @param bool $dotAll If true, `.` matches any character including newline
     *
     * @throws \InvalidArgumentException If the pattern is invalid PCRE
     */
    public function __construct(
        private readonly string $pattern,
        private readonly bool $multiline = false,
        private readonly bool $caseInsensitive = false,
        private readonly bool $dotAll = false,
    ) {
        // Validate the pattern by attempting a preliminary match
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \InvalidArgumentException("Invalid PCRE pattern: {$errstr}");
        });
        try {
            $result = @preg_match($this->buildPattern(), 'test', $m);
            if ($result === false) {
                throw new \InvalidArgumentException('Invalid PCRE pattern');
            }
        } catch (\InvalidArgumentException $e) {
            restore_error_handler();
            throw $e;
        }
        restore_error_handler();
    }

    /**
     * Compare the actual output against the regex pattern.
     *
     * @param string $expected Regex pattern (treated as PCRE, must match $this->pattern)
     * @param string $actual Output string to test against the pattern
     * @return array{ok: bool, diff: string} `ok` is true if the pattern matches, `diff` describes failure reason
     */
    public function compare(string $expected, string $actual): array
    {
        $result = @preg_match($this->buildPattern(), $actual, $matches);

        if ($result === 1 || $result === 0) {
            // preg_match returns 1 if pattern matches, 0 if it doesn't
            if ($result === 1) {
                return ['ok' => true, 'diff' => ''];
            }
            return [
                'ok' => false,
                'diff' => $this->buildDiffMessage($actual),
            ];
        }

        // Negative result means error
        return [
            'ok' => false,
            'diff' => 'PCRE error: ' . preg_last_error_msg(),
        ];
    }

    /**
     * Build the full pattern string with embedded flags.
     */
    private function buildPattern(): string
    {
        // Extract the pattern and delimiter from the input pattern
        // Pattern format: /pattern/flags or /pattern
        if (preg_match('#^/(.+)/([imsxADSQJ]*)$#', $this->pattern, $m)) {
            $patternPart = $m[1];
            $existingFlags = $m[2];
        } elseif (preg_match('#^/(.+)/$#', $this->pattern, $m)) {
            $patternPart = $m[1];
            $existingFlags = '';
        } else {
            // Pattern without standard delimiters - use as-is
            return $this->pattern . $this->buildFlags();
        }

        return '/' . $patternPart . '/' . $existingFlags . $this->buildFlags();
    }

    /**
     * Build flag suffix string from constructor options.
     */
    private function buildFlags(): string
    {
        $flags = '';
        if ($this->multiline) {
            $flags .= 'm';
        }
        if ($this->caseInsensitive) {
            $flags .= 'i';
        }
        if ($this->dotAll) {
            $flags .= 's';
        }
        return $flags;
    }

    /**
     * Build a human-readable failure message.
     *
     * @param string $actual The actual output string that didn't match
     */
    private function buildDiffMessage(string $actual): string
    {
        $escaped = preg_replace('/[^\x20-\x7e]/', '.', $actual);
        $truncated = strlen($escaped) > 100
            ? substr($escaped, 0, 100) . '…'
            : $escaped;

        return sprintf(
            "regex mismatch: pattern '%s' did not match actual output\n  pattern: %s\n  output: %s (%d bytes)",
            $this->pattern,
            $this->buildPattern(),
            $truncated,
            strlen($actual),
        );
    }
}
