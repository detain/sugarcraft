<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;

/**
 * S-H11: the entrypoint's post-run Kitty keyboard pop is best-effort. A torn
 * stdout must not surface a PHP Notice on every TUI exit.
 */
final class KittyPopTeardownTest extends TestCase
{
    public function testEntrypointPopIsSilenced(): void
    {
        $bin = (string) file_get_contents(__DIR__ . '/../../bin/candy-code');
        $this->assertStringContainsString('@fwrite(STDOUT, Ansi::popKittyKeyboard());', $bin);
        $this->assertSame(0, preg_match('/^fwrite\(STDOUT, Ansi::popKittyKeyboard/m', $bin));
    }

    public function testTornStdoutExitEmitsNoNotice(): void
    {
        $code = 'error_reporting(E_ALL); ini_set("display_errors","stderr"); '
            . '@fwrite(STDOUT, "\x1b[<1u"); echo "done";';
        // stdout is a pipe whose read end is closed before the child writes.
        $p = proc_open([PHP_BINARY, '-r', 'fclose(STDIN); usleep(300000);' . $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($p);
        fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        proc_close($p);
        $this->assertStringNotContainsString('Notice', (string) $err);
        $this->assertStringNotContainsString('Warning', (string) $err);
    }
}
