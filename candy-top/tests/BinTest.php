<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Input\KeyTable;
use SugarCraft\Top\Lang;

/**
 * bin/candy-top argument and terminal guards, run as a real child process
 * with stdin/stdout that are NOT terminals (a file and a pipe). `timeout`
 * bounds every run, so a regressed guard that starts the TUI fails the
 * test instead of hanging it.
 */
final class BinTest extends TestCase
{
    public function testRefusesToStartWithoutATty(): void
    {
        [$code, $out, $err] = self::invoke(['--fake']);
        $this->assertSame(1, $code);
        $this->assertSame('', $out, 'no frame bytes written into the pipe');
        $this->assertStringStartsWith('No tty detected!', $err);
    }

    public function testHelpNeedsNoTty(): void
    {
        [$code, $out] = self::invoke(['--help']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('--config <path>', $out);
        foreach (['cli.help.title', 'cli.help.opt.fake'] as $key) {
            $this->assertStringContainsString(Lang::t($key), $out, "{$key} comes from the lang table");
        }
        foreach (KeyTable::keyRows() as $row) {
            $this->assertMatchesRegularExpression('/^  ' . preg_quote($row->keyLabel(), '/') . ' +' . preg_quote($row->description(), '/') . '$/m', $out, 'KEYS is the KeyTable');
        }
    }

    public function testComposerPublishesTheBinary(): void
    {
        $manifest = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['bin/candy-top'], $manifest['bin'] ?? null);
        $this->assertTrue(is_executable(__DIR__ . '/../bin/candy-top'), 'bin/candy-top is executable');
    }

    /** @return iterable<string, array{list<string>}> */
    public static function missingConfigValues(): iterable
    {
        yield 'last argument' => [['--config']];
        yield 'followed by a flag' => [['--config', '--fake']];
        yield 'empty equals form' => [['--config=']];
    }

    /** @param list<string> $args */
    #[DataProvider('missingConfigValues')]
    public function testConfigWithoutAValueReportsTheMissingValue(array $args): void
    {
        [$code, , $err] = self::invoke($args);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('missing value for --config', $err);
        $this->assertStringNotContainsString('unknown argument', $err);
    }

    public function testUnknownArgument(): void
    {
        [$code, , $err] = self::invoke(['--bogus']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('unknown argument --bogus', $err);
    }

    /**
     * @param list<string> $args
     * @return array{int, string, string}
     */
    private static function invoke(array $args): array
    {
        $proc = proc_open(
            ['timeout', '20', PHP_BINARY, __DIR__ . '/../bin/candy-top', ...$args],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out, $err];
    }
}
