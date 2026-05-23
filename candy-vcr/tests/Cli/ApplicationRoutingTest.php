<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cli\Application;

/**
 * Regression: positional args reach Symfony commands through
 * `Application::runSymfonyCommand`.
 *
 * Bug fixed in d070e742: the old wiring used `ArrayInput`, which
 * silently dropped the positional `<tape>` argument so any
 * `render-tape /tmp/foo.tape -o /tmp/foo.gif` invocation was rejected
 * with a "tape argument required" message even though the user did
 * pass one. The fix switches to `StringInput` with proper shell-quoting.
 *
 * This test passes a path to a non-existent tape; if the path arg
 * reaches the command, it'll fail with a "tape file not found"-style
 * message (NOT a generic argv-parser complaint).
 */
final class ApplicationRoutingTest extends TestCase
{
    public function testPositionalTapeArgReachesRenderTapeCommand(): void
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');

        $tape = '/tmp/candy-vcr-routing-' . bin2hex(random_bytes(4)) . '.tape';
        $gif = '/tmp/candy-vcr-routing-' . bin2hex(random_bytes(4)) . '.gif';

        $app = new Application();
        $exit = $app->run(
            ['candy-vcr', 'render-tape', $tape, '-o', $gif],
            $stdout,
            $stderr,
        );

        rewind($stdout);
        rewind($stderr);
        $stdoutText = (string) stream_get_contents($stdout);
        $stderrText = (string) stream_get_contents($stderr);

        $combined = $stdoutText . "\n" . $stderrText;

        // If the positional argument were lost, Symfony would emit
        // "Not enough arguments" or "argument required" — we'd never see
        // a "tape" / "not found" / "cannot read" message naming the file.
        $this->assertNotSame(0, $exit, 'render-tape on a missing file should fail');

        $regex = '/(tape|file|not found|cannot read|does not exist)/i';
        $this->assertMatchesRegularExpression(
            $regex,
            $combined,
            sprintf(
                "Output should reference the missing tape file; positional arg likely lost. " .
                "stdout=%s\nstderr=%s",
                $stdoutText,
                $stderrText,
            ),
        );

        // Strong signal that the tape path made it through.
        $this->assertStringNotContainsString(
            'Not enough arguments',
            $combined,
            'positional arg should not be reported missing — `StringInput` regression',
        );
    }
}
