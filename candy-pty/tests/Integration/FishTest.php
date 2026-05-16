<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Integration;

/**
 * End-to-end smoke test for `fish -i` under a real PTY.
 *
 * Mirrors creack/pty integration test for fish — fish renders prompts
 * with a different escape pattern and its own line editor, so we only
 * assert that the user-visible marker round-trips. The prompt
 * structure itself is out of scope.
 *
 * @see plans/sugarcraft-is-a-mono-logical-twilight.md (P5.1)
 */
final class FishTest extends InteractiveShellTestCase
{
    private const FISH_PATH = '/usr/bin/fish';

    public function testFishInteractiveEchoRoundTrip(): void
    {
        $this->requirePtySyscalls();
        $this->requireBinary(self::FISH_PATH, 'fish');

        $output = $this->runShellRoundTrip(
            [self::FISH_PATH, '-i'],
            "echo hi\nexit\n",
        );

        $this->assertStringContainsString(
            'hi',
            $output,
            'fish -i must echo "hi" within the 5s wallclock budget',
        );
    }
}
