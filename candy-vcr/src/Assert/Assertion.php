<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Assert;

/**
 * Strategy for comparing replay output against the cassette's recorded
 * output. Implementations decide WHAT counts as "equal" — exact bytes
 * (`ByteAssertion`), cell-grid screen state (`ScreenAssertion` via
 * candy-vt, PR5), or anything else.
 */
interface Assertion
{
    /**
     * Compare the program's actual output to the cassette's expected
     * output. Returns a result envelope:
     *
     * ```php
     * ['ok' => bool, 'diff' => string]
     * ```
     *
     * `diff` is a human-readable description of the divergence (empty
     * string when `ok` is true).
     *
     * @return array{ok: bool, diff: string}
     */
    public function compare(string $expected, string $actual): array;
}
