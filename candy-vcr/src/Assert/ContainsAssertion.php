<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Assert;

/**
 * Partial output match assertion.
 *
 * Asserts that the expected substring is found anywhere within the
 * actual output string. Unlike {@see ByteAssertion} which requires
 * exact byte equality, this assertion allows flexible partial matching
 * — useful for testing when you only care about specific content
 * appearing in the output without requiring exact formatting.
 *
 * The `$expected` parameter to {@see compare()} is treated as a literal
 * substring to search for within `$actual`. The comparison is
 * case-sensitive.
 *
 * Example:
 * ```php
 * $assertion = new ContainsAssertion();
 * $result = $assertion->compare('hello world', "say hello world here");
 * // $result['ok'] === true
 * ```
 */
final class ContainsAssertion implements Assertion
{
    /**
     * Compare the actual output to check it contains the expected substring.
     *
     * @param string $expected Substring to search for within the actual output
     * @param string $actual The full output string to search within
     * @return array{ok: bool, diff: string} `ok` is true if the expected substring
     *                                     is found in actual, `diff` describes the failure
     */
    public function compare(string $expected, string $actual): array
    {
        if ($expected === '') {
            // Empty substring is always "found" (vacuous truth)
            return ['ok' => true, 'diff' => ''];
        }

        if ($actual === '') {
            // Non-empty substring cannot be found in empty actual
            return [
                'ok' => false,
                'diff' => $this->buildDiffMessage($expected, $actual),
            ];
        }

        if (str_contains($actual, $expected)) {
            return ['ok' => true, 'diff' => ''];
        }

        return [
            'ok' => false,
            'diff' => $this->buildDiffMessage($expected, $actual),
        ];
    }

    /**
     * Build a human-readable failure message.
     *
     * @param string $expected The substring that was not found
     * @param string $actual The output string that was searched
     */
    private function buildDiffMessage(string $expected, string $actual): string
    {
        $escaped = preg_replace('/[^\x20-\x7e]/', '.', $actual);
        $truncated = strlen($escaped) > 100
            ? substr($escaped, 0, 100) . '…'
            : $escaped;

        return sprintf(
            "substring not found: '%s' was not found in actual output\n  substring: %s\n  output: %s (%d bytes)",
            $this->escapeString($expected),
            $this->escapeString($expected),
            $truncated,
            strlen($actual),
        );
    }

    /**
     * Escape non-printable characters in a string for display.
     */
    private function escapeString(string $str): string
    {
        $escaped = preg_replace('/[^\x20-\x7e]/', '.', $str);
        if (strlen($escaped) > 50) {
            return substr($escaped, 0, 50) . '…';
        }
        return $escaped;
    }
}