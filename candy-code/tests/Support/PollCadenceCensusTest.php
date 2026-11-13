<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Support\ProcessReaper;

/**
 * The bounded-poll cadence has one home, {@see ProcessReaper::POLL_INTERVAL_US}.
 * The backends and the worker pool alias it instead of restating the literal.
 */
final class PollCadenceCensusTest extends TestCase
{
    public function testCadenceLiteralAppearsOnlyInProcessReaper(): void
    {
        $files = [
            'Agents/AgentWorkerPool.php',
            'Backend/EngineBackend.php',
            'Backend/CommandBackend.php',
            'Backend/StreamingCommandBackend.php',
            'Runtime.php',
            'Chat.php',
            'Support/Keychain.php',
        ];
        foreach ($files as $f) {
            $src = (string) file_get_contents(__DIR__ . '/../../src/' . $f);
            $this->assertDoesNotMatchRegularExpression('/\b5_?000\b|=\s*0\.005\b/', $src, $f);
        }
        $this->assertSame(5000, ProcessReaper::POLL_INTERVAL_US);
    }
}
