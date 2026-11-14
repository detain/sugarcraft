<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Assert;

/**
 * Exact byte-equality check between expected and actual output.
 *
 * The strictest form of assertion — fails on any byte-level
 * difference, even semantically equivalent reorderings (e.g. a
 * redundant SGR re-emission, an alternative cursor-position sequence
 * that lands the cursor in the same spot). Use {@see ScreenAssertion} for replay-time tolerance to those reorderings.
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
     * side-by-side viewer use the cell-grid assertion (ScreenAssertion) or pipe
     * the cassette through `vendor/bin/candy-vcr diff`.
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

    /**
     * Slice size for the C-speed pre-scan in {@see self::firstDivergence()}.
     */
    private const DIVERGENCE_CHUNK = 65536;

    private function firstDivergence(string $a, string $b): int
    {
        $len = min(strlen($a), strlen($b));
        // Chunked scan (PERF-10): the common prefix — megabytes on a long
        // cassette's first, most informative failure — is skipped at C speed
        // one 64 KiB substr_compare at a time, and only the diverging slice
        // pays the userland byte walk. Returns the same offset the naive
        // loop would, exactly.
        for ($off = 0; $off < $len; $off += self::DIVERGENCE_CHUNK) {
            $size = min(self::DIVERGENCE_CHUNK, $len - $off);
            if (substr_compare($a, $b, $off, $size) === 0) {
                continue;
            }
            for ($i = $off; $i < $off + $size; $i++) {
                if ($a[$i] !== $b[$i]) {
                    return $i;
                }
            }
            // substr_compare just said this slice differs, so the walk must
            // find the byte; if it doesn't, the scan desynchronized.
            throw new \LogicException('divergence scan desynchronized within a chunk');
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
