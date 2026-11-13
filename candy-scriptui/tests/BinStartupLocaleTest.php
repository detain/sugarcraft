<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The launcher owns two startup promises: every shipped catalog is
 * reachable (locale is env-driven), and a missing autoloader fails LOUD
 * instead of surfacing as a "Class ... not found" fatal three statements
 * later. The locale effect is proven through the real bin so the whole
 * LC_ALL → LC_MESSAGES → LANG → base-language chain is exercised, not a
 * re-typed unit of it.
 */
final class BinStartupLocaleTest extends TestCase
{
    private const BIN = __DIR__ . '/../bin/candy-scriptui';

    /** @param array<string,string> $env @return array{0:int,1:string,2:string} */
    private function runLocaleChild(array $args, array $env = []): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX bin launcher only.');
        }
        $fullEnv = ['PATH' => '/usr/bin:/bin', 'HOME' => sys_get_temp_dir()] + $env;
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            ['timeout', '-s', 'KILL', '20', PHP_BINARY, self::BIN, ...$args],
            $descriptors,
            $pipes,
            dirname(self::BIN),
            $fullEnv,
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $status = proc_close($process);
        return [$status, (string) $stdout, (string) $stderr];
    }

    public function testLauncherWiresLocaleAndAnnouncesAMissingAutoloader(): void
    {
        $bin = (string) file_get_contents(self::BIN);
        self::assertStringContainsString('Core\\I18n\\T::setLocale', $bin);
        self::assertStringContainsString('T::detect()', $bin);
        self::assertStringContainsString('no vendor/autoload.php found', $bin);
    }

    public function testTerritorySpellingLandsOnTheBaseLanguageCatalog(): void
    {
        [$status, $stdout, $stderr] = $this->runLocaleChild(
            ['confirm', '--default=maybe', 'x'],
            ['LANG' => 'de_DE.UTF-8'],
        );
        self::assertSame(1, $status);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Erlaubt: yes|no', $stderr);
        self::assertStringNotContainsString('Invalid value', $stderr);
    }

    public function testPosixLocaleStaysOnEnglish(): void
    {
        [$status, , $stderr] = $this->runLocaleChild(
            ['confirm', '--default=maybe', 'x'],
            ['LC_ALL' => 'C'],
        );
        self::assertSame(1, $status);
        self::assertStringContainsString('Allowed: yes|no', $stderr);
        self::assertStringNotContainsString('Erlaubt', $stderr);
    }

    public function testTerritoryFileWinsOverTheBaseCatalog(): void
    {
        // pt-br.php ships as its own catalog — pt_BR must not degrade to
        // the plain pt file if both exist; the launcher path proves it.
        [, , $stderr] = $this->runLocaleChild(
            ['confirm', '--default=maybe', 'x'],
            ['LANG' => 'pt_BR.UTF-8'],
        );
        self::assertStringNotContainsString('Invalid value', $stderr);
        self::assertStringNotContainsString('Erlaubt', $stderr);
        $pt = (string) file_get_contents(__DIR__ . '/../lang/pt.php');
        $ptBr = (string) file_get_contents(__DIR__ . '/../lang/pt-br.php');
        // Values may be single- or double-quoted PHP literals (scriptui-56
        // re-quotes values containing apostrophes as doubles); either form
        // satisfies the contract, so the pin accepts both.
        $pick = static function (string $contents): string {
            if (preg_match("/'runtime\\.unknown_choice'\\s*=>\\s*'([^']*)'/", $contents, $m) === 1) {
                return $m[1];
            }
            if (preg_match("/'runtime\\.unknown_choice'\\s*=>\\s*\"([^\"]*)\"/", $contents, $n) === 1) {
                return str_replace('\\"', '"', $n[1]);
            }
            return '';
        };
        $ptValue = $pick($pt);
        $ptBrValue = $pick($ptBr);
        self::assertNotSame('', $ptValue);
        self::assertNotSame('', $ptBrValue);
        if ($ptValue !== $ptBrValue) {
            // Distinct catalogs: the child must carry the pt-br wording.
            $needle = substr($ptBrValue, 0, strpos($ptBrValue, '{') === false ? strlen($ptBrValue) : strpos($ptBrValue, '{'));
            self::assertStringContainsString(trim($needle), $stderr);
        }
    }
}
