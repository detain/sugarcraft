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

    /** Run $code in a child PHP with no ini files, stdin closed; returns its trimmed stdout. */
    private function runPhp(string $code): string
    {
        $command = escapeshellarg(PHP_BINARY) . ' -n -r ' . escapeshellarg($code) . ' < /dev/null 2>&1';
        exec($command, $output, $status);
        $this->assertSame(0, $status, 'child PHP failed: ' . implode("\n", $output));

        return trim(implode("\n", $output));
    }
}
