<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cli\Application;
use SugarCraft\Vcr\Tests\Support\Stream;

/**
 * VCR-S66 (VCR-COPY-02/09): one CLI, one voice. Every failure line from any
 * of the eight verbs answers with `candy-vcr <verb>: ` — the two Symfony
 * verbs used to wrap bare `Failed: <message>` (no attribution), and the
 * record success line said `candy-vcr:` with no verb, splitting scripted
 * callers across two scraping dialects.
 */
final class VerbAttributionTest extends TestCase
{
    public function testRenderTapeFailureCarriesItsVerb(): void
    {
        $tape = $this->writeFile('attrib-tape.tape', "Type \"hi\"\n");

        [$exit, $out] = $this->runCli(['candy-vcr', 'render-tape', $tape, '--dry-run', '--fps=nonsense']);

        $this->assertSame(1, $exit, $out);
        $this->assertStringContainsString('candy-vcr render-tape:', $out);
        @unlink($tape);
    }

    public function testRenderBatchFailureCarriesItsVerb(): void
    {
        [$exit, $out] = $this->runCli(['candy-vcr', 'render-batch', '/definitely/not/a/directory']);

        $this->assertSame(1, $exit, $out);
        $this->assertStringContainsString('candy-vcr render-batch: Not a directory:', $out);
    }

    public function testRenderBatchRejectsJobsWithItsVerb(): void
    {
        $dir = sys_get_temp_dir() . '/candy-vcr-attrib-' . bin2hex(random_bytes(4));
        mkdir($dir);

        [$exit, $out] = $this->runCli(['candy-vcr', 'render-batch', $dir, '--jobs=0']);

        $this->assertSame(1, $exit, $out);
        $this->assertStringContainsString('candy-vcr render-batch: --jobs must be a positive integer', $out);
        @rmdir($dir);
    }

    /**
     * @param list<string> $argv
     * @return array{0:int,1:string}
     */
    private function runCli(array $argv): array
    {
        $stdout = Stream::memory('w+');
        $stderr = Stream::memory('w+');
        $this->assertNotFalse($stdout);
        $this->assertNotFalse($stderr);

        $exit = (new Application())->run($argv, $stdout, $stderr);

        rewind($stdout);
        rewind($stderr);
        return [$exit, (string) stream_get_contents($stdout) . (string) stream_get_contents($stderr)];
    }

    private function writeFile(string $name, string $contents): string
    {
        $path = sys_get_temp_dir() . '/candy-vcr-' . $name;
        file_put_contents($path, $contents);
        return $path;
    }
}
