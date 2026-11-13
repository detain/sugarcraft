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

    public function testAMissingAutoloaderIsAnnouncedLoudlyNotAsAClassFatal(): void
    {
        // scriptui-53: the locale wiring that this test used to grep out of
        // the launcher is proven by the three child probes below — the full
        // LC_ALL -> LC_MESSAGES -> LANG -> base-language chain runs through
        // the real bin, so the call-text greps retired. What replaces them
        // is the one launcher promise the probes cannot show from the happy
        // path: booting from a tree with NO vendor/ must fail loud.
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX bin launcher only.');
        }
        $launcher = (string) file_get_contents(self::BIN);
        $root = sys_get_temp_dir() . '/sc53-noautoloader-' . getmypid() . '-' . bin2hex(random_bytes(4));
        // Deep enough that EVERY candidate the launcher walks up to lands
        // inside $root (which we own and never populate).
        $deep = $root . '/one/two/three/bin';
        self::assertTrue(mkdir($deep, 0777, true));
        $tmpBin = $deep . '/candy-scriptui';
        self::assertTrue((bool) file_put_contents($tmpBin, $launcher));

        try {
            $proc = proc_open(
                ['timeout', '-s', 'KILL', '20', PHP_BINARY, $tmpBin, 'version'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $deep,
                ['PATH' => '/usr/bin:/bin', 'HOME' => sys_get_temp_dir()],
            );
            self::assertIsResource($proc, 'the isolated launcher must still start');
            fclose($pipes[0]);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($proc);

            self::assertSame(1, $status, 'a missing autoloader must exit 1, not fatal 255');
            self::assertSame('', $stdout);
            self::assertStringContainsString('no vendor/autoload.php found', $stderr);
            self::assertStringNotContainsString('Class "', $stderr,
                'the silent fall-through used to surface as a Class-not-found fatal');
        } finally {
            @unlink($tmpBin);
            @rmdir($deep);
            @rmdir($root . '/one/two/three');
            @rmdir($root . '/one/two');
            @rmdir($root . '/one');
            @rmdir($root);
        }
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
        // scriptui-53 KEEP (data-pin): the expected wording is read from the
        // catalogs themselves (the only ground truth for it) and asserted
        // against the executed child's STDERR — the behaviour leg is the
        // test, this only sources the needle.
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
