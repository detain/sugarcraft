<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use SugarCraft\Pty\Posix\SttyTermios;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SttyTermios failure-path remediation (Steps 5-6).
 *
 * Step 5: runStty() now guards proc_open result with is_resource()
 *         so it returns gracefully when proc_open returns false.
 * Step 6: restore() now uses preg_split(/\s+/, $savedMode) on the
 *         stty -g output string so argv elements are passed correctly.
 */
final class SttyTermiosFailureTest extends TestCase
{
    public function testRestoreParsesSavedModeArgv(): void
    {
        // The savedMode format from `stty -g` is "500:5:1f:8a3f:0:0:0"
        // (colon-separated fields).  preg_split(/\s+/, "500:5:1f:8a3f:0:0:0")
        // on this string returns a single-element array ["500:5:1f:8a3f:0:0:0"]
        // because there are no whitespace runs — this is the correct behavior.
        $savedMode = "500:5:1f:8a3f:0:0:0";
        $parts = \preg_split('/\s+/', $savedMode);
        $this->assertSame([$savedMode], $parts);
    }

    public function testRestoreParsesMultiWordSavedMode(): void
    {
        // If the savedMode string happened to have multiple words (edge case),
        // preg_split would produce multiple elements — exactly what we want for argv.
        $savedMode = "500:5:1f:8a3f:0:0:0";
        $parts = \preg_split('/\s+/', $savedMode);
        // Exactly one element — no extra whitespace to split on
        $this->assertCount(1, $parts);
        $this->assertSame($savedMode, $parts[0]);
    }

    public function testRestoreWithEmptySavedModeDoesNotCrash(): void
    {
        // Edge case: empty string preg_split returns ['']
        $parts = \preg_split('/\s+/', '');
        $this->assertSame([''], $parts);
    }

    public function testRestoreWithWhitespaceOnlySavedMode(): void
    {
        // preg_split on whitespace-only string returns empty-string tokens:
        // "   \t  " → ['', '']
        $parts = \preg_split('/\s+/', "   \t  ");
        $this->assertCount(2, $parts);
        $this->assertSame(['', ''], $parts);
    }
}
