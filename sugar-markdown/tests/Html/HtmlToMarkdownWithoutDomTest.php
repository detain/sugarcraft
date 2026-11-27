<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests\Html;

use PHPUnit\Framework\TestCase;

/**
 * The optional-dependency promise of {@see \SugarCraft\Markdown\Html\HtmlToMarkdown}:
 * without ext-dom, convert() returns null and the caller keeps the raw body.
 *
 * The suite itself always runs with ext-dom, so the check runs in a child
 * PHP started with `-n`, which skips the ini files that load dom here. A
 * build that compiles dom in cannot be started without it, and the test then
 * skips rather than pretending to cover the path.
 */
final class HtmlToMarkdownWithoutDomTest extends TestCase
{
    public function testConvertReturnsNullWhenDomIsNotLoaded(): void
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $probe = 'echo class_exists("DOMDocument") ? "dom" : "nodom";';
        $domState = $this->runPhp($probe);
        if ($domState !== 'nodom') {
            $this->markTestSkipped('This PHP build cannot start without ext-dom (-n still loads it).');
        }

        $script = 'require ' . var_export($autoload, true) . ';'
            . 'echo \SugarCraft\Markdown\Html\HtmlToMarkdown::convert("<p>x</p>", "https://e.com/") === null ? "null" : "converted";';

        $this->assertSame('null', $this->runPhp($script));
    }

    /**
     * Run $code in a child PHP with no ini files, stdin closed; returns its
     * trimmed stdout and stderr.
     *
     * The child starts from an argument list, not a shell string: on Windows
     * a shell string runs under cmd.exe, which has no /dev/null to redirect
     * from (the child never starts, exit 1), and escapeshellarg() there turns
     * every double quote inside the code into a space. The argument list
     * reaches the child intact on every platform, and its stdin is a pipe
     * closed before it reads.
     */
    private function runPhp(string $code): string
    {
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]];
        $process = proc_open([PHP_BINARY, '-n', '-r', $code], $spec, $pipes);
        $this->assertIsResource($process, 'could not start the child PHP');

        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $status = proc_close($process);
        $this->assertSame(0, $status, 'child PHP failed: ' . $output);

        return trim($output);
    }
}
