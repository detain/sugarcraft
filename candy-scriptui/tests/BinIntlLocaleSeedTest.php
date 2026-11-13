<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests;

use PHPUnit\Framework\TestCase;

/**
 * scriptui-79 (finding 112): with NO language env at all, the launcher seeds
 * LANG from intl's default locale — the only signal native Windows
 * cmd/PowerShell users have. The effect is proven through the real bin so
 * the whole seed → T::detect → catalog chain is exercised, and an explicit
 * LC_ALL/LANG still outranks the seed.
 */
final class BinIntlLocaleSeedTest extends TestCase
{
    private const BIN = __DIR__ . '/../bin/candy-scriptui';

    /** @param array<string,string> $env @return array{0:int,1:string,2:string} */
    private function runChild(array $phpOptions, array $env): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX bin launcher only.');
        }
        $fullEnv = ['PATH' => '/usr/bin:/bin', 'HOME' => sys_get_temp_dir()] + $env;
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            ['timeout', '-s', 'KILL', '20', PHP_BINARY, ...$phpOptions, self::BIN, 'filter'],
            $descriptors,
            $pipes,
            dirname(self::BIN),
            $fullEnv,
        );
        self::assertIsResource($process);
        fclose($pipes[0]); // empty stdin -> the localized refusal sentence
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $status = proc_close($process);

        return [$status, (string) $stdout, (string) $stderr];
    }

    private function catalogSentence(string $locale): string
    {
        $values = require dirname(__DIR__) . '/lang/' . $locale . '.php';
        self::assertIsArray($values);

        return $values['runtime.empty_input_filter'];
    }

    public function testSeedFromIntlDefaultWhenNoLanguageEnvExists(): void
    {
        [$status, , $stderr] = $this->runChild(['-d', 'intl.default_locale=de_DE'], []);

        self::assertSame(1, $status);
        self::assertStringContainsString($this->catalogSentence('de'), $stderr);
    }

    public function testExplicitLanguageEnvOutranksTheSeed(): void
    {
        [$status, , $stderr] = $this->runChild(
            ['-d', 'intl.default_locale=de_DE'],
            ['LANG' => 'fr_FR.UTF-8'],
        );

        self::assertSame(1, $status);
        self::assertStringContainsString($this->catalogSentence('fr'), $stderr);
        self::assertStringNotContainsString($this->catalogSentence('de'), $stderr);
    }

    public function testExplicitNeutralLocaleIsRespectedNotOverseeded(): void
    {
        // LANG/LC_ALL are SET (to the neutral C) — the user opted out of a
        // language; the seed must stay out of it.
        [$status, , $stderr] = $this->runChild(
            ['-d', 'intl.default_locale=de_DE'],
            ['LC_ALL' => 'C'],
        );

        self::assertSame(1, $status);
        self::assertStringContainsString($this->catalogSentence('en'), $stderr);
    }

    public function testPlainDefaultEnvironmentStillAnswersEnglish(): void
    {
        // No ini override, no env: intl's stock CLI default normalises to
        // 'en' — non-Windows users see exactly what they saw before.
        [$status, , $stderr] = $this->runChild([], []);

        self::assertSame(1, $status);
        self::assertStringContainsString($this->catalogSentence('en'), $stderr);
    }
}
