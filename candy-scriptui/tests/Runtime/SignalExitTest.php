<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SugarCraft\ScriptUi\Runtime\SignalExit;

final class SignalExitTest extends TestCase
{
    public function testNoSignalYieldsNoExitCode(): void
    {
        $this->assertNull(SignalExit::code(null));
    }

    public function testSigIntMapsTo130(): void
    {
        // The documented shell convention scripts branch on.
        $this->assertSame(130, SignalExit::code(2));
    }

    public function testSigTermMapsTo143(): void
    {
        $this->assertSame(143, SignalExit::code(15));
    }
}
