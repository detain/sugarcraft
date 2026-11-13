<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Tests\Support\PtyRun;

/**
 * S-X14: on TERM=dumb candy-top must not write the alt-screen, mouse,
 * synchronized-update or grapheme-cluster modes. Live pty child, bytes
 * asserted; the capable-terminal control proves the regex can see them.
 */
final class DumbTerminalTest extends TestCase
{
    private const MODES = '/\x1b\[\?(?:[0-9;]*;)?(?:1049|1002|1006|2026|2027)[hl]/';

    protected function setUp(): void
    {
        $why = PtyRun::unavailable();
        if ($why !== null) {
            $this->markTestSkipped($why);
        }
    }

    private function bytes(string $term): string
    {
        $run = PtyRun::start(['--fake'], ['TERM' => $term]);
        $this->assertTrue($run->readUntil('cpu', 15.0), 'a frame was drawn under TERM=' . $term);
        $run->signal(SIGTERM);
        $this->assertSame(0, $run->reap(10.0));

        return $run->output();
    }

    public function testDumbTerminalGetsNoFullScreenModes(): void
    {
        $out = $this->bytes('dumb');
        $this->assertDoesNotMatchRegularExpression(self::MODES, $out);
        $this->assertStringContainsString('TERM=dumb', $out, 'the one-line stderr notice');
    }

    public function testCapableTerminalStillGetsThem(): void
    {
        $out = $this->bytes('xterm-256color');
        foreach (['1049', '1002', '1006', '2026', '2027'] as $mode) {
            $this->assertStringContainsString("\x1b[?{$mode}h", $out, "?{$mode}h on a capable terminal");
        }
        $this->assertStringNotContainsString('TERM=dumb', $out);
    }
}
