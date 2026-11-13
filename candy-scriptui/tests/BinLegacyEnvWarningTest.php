<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests;

use PHPUnit\Framework\TestCase;

/**
 * scriptui-58 (finding 134): the LegacyEnv deprecation warning fires AFTER
 * the launcher's T::setLocale() wiring, so it is the one boot message that
 * can answer in the user's language — proven through the real bin so the
 * whole locale → catalog → interpolation chain is exercised. The
 * autoload-failure writes stay English by design (the machinery is not
 * loaded when they run), so they are exempt from this contract.
 */
final class BinLegacyEnvWarningTest extends TestCase
{
    private const BIN = __DIR__ . '/../bin/candy-scriptui';

    /** @param array<string,string> $env @return array{0:int,1:string,2:string} */
    private function runChild(array $env): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX bin launcher only.');
        }
        $fullEnv = ['PATH' => '/usr/bin:/bin', 'HOME' => sys_get_temp_dir()] + $env;
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            ['timeout', '-s', 'KILL', '20', PHP_BINARY, self::BIN, 'filter'],
            $descriptors,
            $pipes,
            dirname(self::BIN),
            $fullEnv,
        );
        self::assertIsResource($process);
        fclose($pipes[0]); // empty stdin -> the localized refusal sentence, after the warning
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $status = proc_close($process);

        return [$status, (string) $stdout, (string) $stderr];
    }

    /**
     * The expected warning sentence, interpolated straight from the catalog
     * file (not through Lang) so a broken lookup cannot self-certify.
     */
    private function expectedWarning(string $locale): string
    {
        $values = require dirname(__DIR__) . '/lang/' . $locale . '.php';
        self::assertIsArray($values);
        self::assertArrayHasKey('runtime.legacy_env', $values);

        $line = strtr($values['runtime.legacy_env'], [
            '{var}' => 'CANDYSHELL_FOO',
            '{replacement}' => 'CANDY_SCRIPTUI_FOO',
        ]);

        return 'candy-scriptui: ' . $line . "\n";
    }

    public function testGermanBootAnswersWarningInGerman(): void
    {
        [$status, , $stderr] = $this->runChild([
            'LANG' => 'de_DE.UTF-8',
            'CANDYSHELL_FOO' => 'bar',
        ]);

        self::assertSame(1, $status);
        self::assertStringContainsString($this->expectedWarning('de'), $stderr);
        self::assertStringNotContainsString('is deprecated; set', $stderr);
    }

    public function testPlainDefaultEnvironmentStillAnswersEnglish(): void
    {
        [$status, , $stderr] = $this->runChild(['CANDYSHELL_FOO' => 'bar']);

        self::assertSame(1, $status);
        self::assertStringContainsString($this->expectedWarning('en'), $stderr);
    }

    public function testWarningPrecedesTheLocalizedRefusal(): void
    {
        // Wiring-order proof: the warning must be emitted before the filter
        // command's localized empty-input sentence — i.e. the locale was
        // already resolved when the warning was translated.
        [$status, , $stderr] = $this->runChild([
            'LANG' => 'fr_FR.UTF-8',
            'CANDYSHELL_FOO' => 'bar',
        ]);

        self::assertSame(1, $status);
        $values = require dirname(__DIR__) . '/lang/fr.php';
        self::assertIsArray($values);
        $warningAt = strpos($stderr, $this->expectedWarning('fr'));
        $refusalAt = strpos($stderr, $values['runtime.empty_input_filter']);
        self::assertNotFalse($warningAt, 'French warning missing: ' . $stderr);
        self::assertNotFalse($refusalAt, 'French refusal missing: ' . $stderr);
        self::assertLessThan($refusalAt, $warningAt);
    }

    public function testNoLegacyExportsMeansNoWarning(): void
    {
        [, , $stderr] = $this->runChild(['LANG' => 'de_DE.UTF-8']);

        self::assertStringNotContainsString('CANDYSHELL_FOO', $stderr);
        self::assertStringNotContainsString('veraltet', $stderr);
    }
}
