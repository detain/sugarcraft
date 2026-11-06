<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use SugarCraft\Core\Util\RawMode;
use PHPUnit\Framework\TestCase;

/**
 * RawMode exercised against non-tty streams. CI has no controlling
 * terminal, so every assertion here verifies the guard: enable()/
 * disable() must be safe no-ops (never shell out, never throw) when
 * the stream is not a TTY.
 */
final class RawModeTest extends TestCase
{
    public function testEnableIsNoOpOnNonTty(): void
    {
        $r = fopen('php://memory', 'r+');
        $this->assertNotFalse($r);
        try {
            $this->assertNull(RawMode::enable($r));
        } finally {
            fclose($r);
        }
    }

    public function testDisableIsNoOpOnNonTty(): void
    {
        $r = fopen('php://memory', 'r+');
        $this->assertNotFalse($r);
        try {
            $this->assertNull(RawMode::disable($r));
        } finally {
            fclose($r);
        }
    }

    public function testToggleIsIdempotentOnNonTty(): void
    {
        $r = fopen('php://memory', 'r+');
        $this->assertNotFalse($r);
        try {
            RawMode::enable($r);
            RawMode::enable($r);
            RawMode::disable($r);
            RawMode::disable($r);
            // No exception thrown and the stream is untouched.
            $this->assertIsResource($r);
        } finally {
            fclose($r);
        }
    }

    public function testEnableDoesNotShellOutOnNonTty(): void
    {
        // If RawMode shelled out for a non-tty stream it would clobber
        // the controlling terminal of the test runner. We can't observe
        // the absence of a shell_exec directly, but we can confirm the
        // guard short-circuits: a closed/invalid stream is treated as
        // "not a tty" by TtyDetect and the call is a no-op.
        $r = fopen('php://temp', 'r+');
        $this->assertNotFalse($r);
        RawMode::enable($r);
        RawMode::disable($r);
        $this->assertIsResource($r);
        fclose($r);
    }
    public function testEnableRecipeCarriesIxonAndHoldsIsigBack(): void
    {
        // The guard tests above can never observe the recipe string (CI has
        // no controlling terminal), so pin the source window of enable().
        // -ixon MUST be switched off: prompt libraries bind Ctrl+S (XOFF)
        // for forward search, and a discipline that swallows it freezes
        // output instead. -isig MUST stay enabled: consumers arm SIGINT as
        // their cancel channel by design; clearing ISIG would turn their
        // Ctrl+C into a byte. Abort-chord prompts run the full -isig
        // recipe themselves.
        $method = new \ReflectionMethod(RawMode::class, 'enable');
        $lines = file($method->getFileName());
        $this->assertIsArray($lines);
        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));
        $this->assertStringContainsString('-icanon -echo -ixon', $body);
        $this->assertStringNotContainsString('-isig', $body);
    }
}
