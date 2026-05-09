<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Assert;

/**
 * Exact byte-equality check between expected and actual output.
 *
 * The strictest form of assertion — fails on any byte-level
 * difference, even semantically equivalent reorderings (e.g. a
 * redundant SGR re-emission, an alternative cursor-position sequence
 * that lands the cursor in the same spot). Use {@see ScreenAssertion}
 * (PR5) for replay-time tolerance to those reorderings.
 *
 * Mirrors charmbracelet/x/vcr Assert/Bytes.
 */
final class ByteAssertion implements Assertion
{
    public function compare(string $expected, string $actual): array
    {
        if ($expected === $actual) {
            return ['ok' => true, 'diff' => ''];
        }
        return ['ok' => false, 'diff' => $this->summarize($expected, $actual)];
    }

    /**
     * Compact human-readable diff: byte length of each side, the byte
     * offset of the first divergence, and a short hex window around
     * that offset. Long-form diffs are out of scope here — for a true
     * side-by-side viewer use the cell-grid assertion in PR5 or pipe
     * the cassette through `vendor/bin/candy-vcr diff` (PR7).
     */
    private function summarize(string $expected, string $actual): string
    {
        $expectedLen = strlen($expected);
        $actualLen = strlen($actual);
        $first = $this->firstDivergence($expected, $actual);

        $expectedWindow = $this->hexWindow($expected, $first, 16);
        $actualWindow = $this->hexWindow($actual, $first, 16);

        return sprintf(
            "byte mismatch: expected %d bytes, got %d bytes; first divergence at offset %d\n"
                . "  expected: %s\n"
                . "  actual:   %s",
            $expectedLen,
            $actualLen,
            $first,
            $expectedWindow,
            $actualWindow,
        );
    }

    private function firstDivergence(string $a, string $b): int
    {
        $len = min(strlen($a), strlen($b));
        for ($i = 0; $i < $len; $i++) {
            if ($a[$i] !== $b[$i]) {
                return $i;
            }
        }
        return $len;
    }

    private function hexWindow(string $bytes, int $offset, int $width): string
    {
        $start = max(0, $offset);
        $window = substr($bytes, $start, $width);
        if ($window === '') {
            return '<EOF>';
        }
        $hex = bin2hex($window);
        $printable = preg_replace('/[^\x20-\x7e]/', '.', $window) ?? '?';
        return $hex . ' (' . $printable . ')';
    }
}
