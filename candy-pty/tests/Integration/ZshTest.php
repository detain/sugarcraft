<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Integration;

/**
 * End-to-end smoke test for `zsh -i` under a real PTY.
 *
 * Mirrors creack/pty integration test for zsh — confirms candy-pty
 * handles zsh's distinct prompt + line-editor flow. Zsh is commonly
 * absent on slim CI images, so this test must skip cleanly when the
 * binary isn't installed rather than fail.
 *
 * @see plans/sugarcraft-is-a-mono-logical-twilight.md (P5.1)
 */
final class ZshTest extends InteractiveShellTestCase
{
    private const ZSH_PATH = '/usr/bin/zsh';

    public function testZshInteractiveEchoRoundTrip(): void
    {
        $this->requirePtySyscalls();
        $this->requireBinary(self::ZSH_PATH, 'zsh');

        $output = $this->runShellRoundTrip(
            [self::ZSH_PATH, '-i'],
            "echo hi\nexit\n",
        );

        $this->assertStringContainsString(
            'hi',
            $output,
            'zsh -i must echo "hi" within the 5s wallclock budget',
        );
    }
}
