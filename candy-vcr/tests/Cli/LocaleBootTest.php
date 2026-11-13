<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;

/**
 * bin/candy-vcr selects its locale from LC_ALL / LC_MESSAGES / LANG before the
 * first message. Spawns the real front door (the only place T::detect() is
 * wired) and proves a translated locale reaches the help text, while an
 * unset/C locale keeps the English bytes every other test pins.
 */
final class LocaleBootTest extends TestCase
{
    private const BIN = __DIR__ . '/../../bin/candy-vcr';

    /**
     * @param array<string, string> $env
     * @return array{0: int, 1: string, 2: string}
     */
    private static function spawn(array $env, string ...$args): array
    {
        $cmd = \array_merge([\PHP_BINARY, self::BIN], $args);
        // No inherited LC_*/LANG: the child's locale is exactly what the test names.
        $base = ['PATH' => (string) \getenv('PATH'), 'HOME' => (string) \getenv('HOME')];
        $proc = \proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $base + $env);
        self::assertIsResource($proc);
        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return [\proc_close($proc), $out, $err];
    }

    public function testGermanLocaleTranslatesTheSubcommandListing(): void
    {
        [$code, $out] = self::spawn(['LC_ALL' => 'de_DE.UTF-8'], '--help');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Verwendung: candy-vcr <Unterbefehl>', $out);
        $this->assertStringContainsString('Zeichnet die PTY-Sitzung', $out);
    }

    public function testGermanLocaleTranslatesADiagnosticButKeepsTheFlagSpelling(): void
    {
        [$code, , $err] = self::spawn(['LANG' => 'de_DE.UTF-8'], 'stats', '--bogus');

        $this->assertSame(2, $code);
        $this->assertSame("candy-vcr stats: unbekannte Option --bogus\n", $err);
    }

    public function testNoLocaleKeepsEnglish(): void
    {
        [$code, $out] = self::spawn([], '--help');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('usage: candy-vcr <subcommand> [options...]', $out);
    }

    public function testUnshippedLocaleFallsBackToEnglish(): void
    {
        [, $out] = self::spawn(['LC_ALL' => 'sv_SE.UTF-8'], '--help');

        $this->assertStringContainsString('usage: candy-vcr <subcommand>', $out);
    }
}
