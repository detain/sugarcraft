<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Minesweeper\Support\Requirements;

/**
 * msw-23 (finding candy-minesweeper-018): the two extensions the app rides
 * are self-described in the manifest, enforced by a named boot preflight,
 * and documented for BOTH install paths. The missing-extension arm is
 * simulated honestly — `php -n` boots the real binary without php.ini,
 * which on shared-module builds strips mbstring/intl entirely — so the
 * refusal is proven on the actual boot path, not a mocked stand-in.
 */
final class RequirementsTest extends TestCase
{
    public function testManifestRequiresBothExtensions(): void
    {
        $manifest = json_decode((string) file_get_contents(\dirname(__DIR__) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        $require = $manifest['require'] ?? null;
        self::assertIsArray($require);
        self::assertSame('*', $require['ext-intl'] ?? null, 'the phar-free story starts at the manifest');
        self::assertSame('*', $require['ext-mbstring'] ?? null);
    }

    public function testTheHostItselfSatisfiesTheRequirement(): void
    {
        // Sanity pin under every other test here: the suite runs with both loaded.
        self::assertNull(Requirements::firstMissing(\get_loaded_extensions()));
    }

    public function testFirstMissingBlamesMbstringBeforeIntl(): void
    {
        self::assertSame('mbstring', Requirements::firstMissing([]));
        self::assertSame('mbstring', Requirements::firstMissing(['Core', 'date', 'intl']));
        self::assertSame('intl', Requirements::firstMissing(['Core', 'date', 'mbstring']));
        self::assertNull(Requirements::firstMissing(['Core', 'date', 'mbstring', 'intl']));
    }

    public function testRefusalNamesTheMissingExtension(): void
    {
        self::assertSame('candy-minesweeper requires ext-mbstring', Requirements::refuseMessage('mbstring'));
        self::assertSame('candy-minesweeper requires ext-intl', Requirements::refuseMessage('intl'));
    }

    public function testBinRunsThePreflightBeforeAnyVendorCallable(): void
    {
        $bin = (string) file_get_contents(\dirname(__DIR__) . '/bin/candy-minesweeper');
        $autoload = strpos($bin, 'require $path');
        $preflight = strpos($bin, 'Requirements::firstMissing');
        $locale = strpos($bin, '::setLocale(');
        self::assertIsInt($autoload, 'sanity: the autoload cascade is in the binary');
        self::assertIsInt($preflight, 'the binary must run the named preflight');
        self::assertIsInt($locale);
        self::assertGreaterThan($autoload, $preflight, 'the guard class arrives through autoload');
        self::assertLessThan($locale, $preflight, 'the preflight must precede the first vendor locale call');
    }

    public function testTheRealBinaryRefusesABareHostNamingTheMissingExtension(): void
    {
        $probe = (string) tempnam(sys_get_temp_dir(), 'msw23probe');
        $bin = \dirname(__DIR__) . '/bin/candy-minesweeper';
        // -n strips every shared-module extension on Debian/Ubuntu-style
        // builds — phar included, and the autoload guard above needs it.
        // Re-arm ONLY phar, so mbstring/intl stay bare exactly as a stray-
        // phar host would meet them, and the refusal must come from us.
        $bare = ['-n', '-d', 'extension=phar'];
        try {
            file_put_contents($probe, "<?php echo (extension_loaded('phar') && !extension_loaded('mbstring') && !extension_loaded('intl')) ? 'bare' : 'armed';");
            [$probeCode, $probeOut] = self::runPhp([...$bare, $probe]);
            if ($probeCode !== 0 || trim($probeOut) !== 'bare') {
                // Not a defeat — a host that compiles the extensions statically
                // cannot be stripped by -n; the unit arms above still hold.
                self::markTestSkipped('php -n -d extension=phar cannot produce a mbstring/intl-bare host on this build');
            }

            [$code, $out, $err] = self::runPhp([...$bare, $bin]);
            self::assertSame(1, $code, 'a bare host must be refused, not semi-die. stderr: ' . $err . ' stdout: ' . $out);
            self::assertSame('', $out, 'the refusal is stderr-only and paints nothing');
            self::assertSame(
                1,
                preg_match('/^candy-minesweeper requires ext-(mbstring|intl)$/m', $err),
                'stderr must name the missing extension. got: ' . $err,
            );
        } finally {
            @unlink($probe);
        }
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private static function runPhp(array $args): array
    {
        $proc = proc_open(
            [PHP_BINARY, ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($proc);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out, $err];
    }
}
