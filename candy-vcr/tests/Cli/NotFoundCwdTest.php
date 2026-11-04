<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cli\Application;
use SugarCraft\Vcr\Format\CassetteLoader;
use SugarCraft\Vcr\Tests\Support\Stream;

/**
 * A not-found against a RELATIVE path names the directory that was searched;
 * absolute paths already carry their own context and stay untouched.
 * No chdir anywhere: relative names resolve against the real cwd, which is
 * exactly what the hint must print.
 */
final class NotFoundCwdTest extends TestCase
{
    private function missingRelative(): string
    {
        return 'definitely-missing-' . \bin2hex(\random_bytes(4)) . '.cas';
    }

    public function testLoaderNamesTheCwdForRelativePaths(): void
    {
        $name = $this->missingRelative();
        try {
            (new CassetteLoader())->load($name);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("cassette/tape not found: {$name}", $e->getMessage());
            $this->assertStringContainsString('(cwd: ' . (string) \getcwd() . ')', $e->getMessage());
        }
    }

    public function testLoaderStaysQuietForAbsolutePaths(): void
    {
        $path = '/definitely/not/here-' . \bin2hex(\random_bytes(4)) . '.cas';
        try {
            (new CassetteLoader())->load($path);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("cassette/tape not found: {$path}", $e->getMessage());
            $this->assertStringNotContainsString('(cwd:', $e->getMessage());
        }
    }

    public function testInspectReportsTheSearchedDirectory(): void
    {
        $stdout = Stream::memory('w+');
        $stderr = Stream::memory('w+');
        $this->assertNotFalse($stdout);
        $this->assertNotFalse($stderr);

        $exit = (new Application())->run(['candy-vcr', 'inspect', $this->missingRelative()], $stdout, $stderr);
        rewind($stderr);

        // Flag misuse exits 2; a failed load is a runtime refusal (1).
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('(cwd: ' . (string) \getcwd() . ')', (string) stream_get_contents($stderr));
    }

    public function testRenderTapeReportsTheSearchedDirectory(): void
    {
        $stdout = Stream::memory('w+');
        $stderr = Stream::memory('w+');
        $this->assertNotFalse($stdout);
        $this->assertNotFalse($stderr);

        $exit = (new Application())->run(
            ['candy-vcr', 'render-tape', 'definitely-missing-' . \bin2hex(\random_bytes(4)) . '.tape'],
            $stdout,
            $stderr,
        );
        rewind($stdout);
        rewind($stderr);
        $out = ((string) stream_get_contents($stdout)) . ((string) stream_get_contents($stderr));

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('tape file not found', $out);
        $this->assertStringContainsString('(cwd: ' . (string) \getcwd() . ')', $out);
    }
}
