<?php

declare(strict_types=1);

namespace SugarCraft\Mail\Tests;

use PHPUnit\Framework\SkippedWithMessageException;
use PHPUnit\Framework\TestCase;
use SugarCraft\Mail\Tests\Support\DialogueGate;

/**
 * CM-60: the dialogue gate's two arms, pinned through the availability seam
 * (ext-pcntl cannot be unloaded at runtime, so the real-world missing arm is
 * simulated rather than faked around).
 */
final class DialogueGateTest extends TestCase
{
    protected function tearDown(): void
    {
        DialogueGate::$assumeAvailable = null;
        unset($_ENV['CANDY_MAIL_REQUIRE_DIALOGUE_TESTS']);
        \putenv('CANDY_MAIL_REQUIRE_DIALOGUE_TESTS');
    }

    public function testMissingPcntlSkipsWhenTheGateIsOff(): void
    {
        DialogueGate::$assumeAvailable = false;

        $this->expectException(SkippedWithMessageException::class);
        $this->expectExceptionMessage('ext-pcntl unavailable');

        DialogueGate::requireFork();
    }

    public function testMissingPcntlFailsWhenTheGateIsOn(): void
    {
        DialogueGate::$assumeAvailable = false;
        $_ENV['CANDY_MAIL_REQUIRE_DIALOGUE_TESTS'] = '1';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CANDY_MAIL_REQUIRE_DIALOGUE_TESTS is on');

        DialogueGate::requireFork();
    }

    public function testTheCompoundCurlArmNamesBothExtensions(): void
    {
        DialogueGate::$assumeAvailable = false;

        $this->expectException(SkippedWithMessageException::class);
        $this->expectExceptionMessage('ext-curl or ext-pcntl unavailable');

        DialogueGate::requireCurlAndFork();
    }

    public function testAvailableToolchainPassesThrough(): void
    {
        DialogueGate::$assumeAvailable = true;
        DialogueGate::requireFork();
        DialogueGate::requireCurlAndFork();

        // Reaching the line at all is the claim: the gate let the toolchain
        // through instead of skipping. Counted, not asserted, because
        // assertTrue(true) is exactly the tautology this lane's phpstan gate
        // exists to refuse.
        $this->addToAssertionCount(1);
    }
}
