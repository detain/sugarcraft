<?php

declare(strict_types=1);

namespace SugarCraft\Jwt\Tests;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;

/**
 * Skeleton guard: the package's PSR-4 prefix must be registered with the
 * Composer autoloader and point at this package's src/ directory. Content
 * classes arrive in later steps; this keeps the wiring honest until then.
 */
final class PsrAutoloadTest extends TestCase
{
    public function testSugarCraftJwtPrefixIsRegisteredToSrc(): void
    {
        $loader = null;
        foreach (spl_autoload_functions() as $callable) {
            if (is_array($callable) && $callable[0] instanceof ClassLoader) {
                $loader = $callable[0];
                break;
            }
        }
        self::assertInstanceOf(ClassLoader::class, $loader, 'Composer ClassLoader is not registered');

        $prefixes = $loader->getPrefixesPsr4();
        self::assertArrayHasKey('SugarCraft\\Jwt\\', $prefixes);

        $srcDir = realpath(dirname(__DIR__) . '/src');
        self::assertNotFalse($srcDir, 'src/ directory is missing');
        $registered = array_map(
            static fn (string $dir): string|false => realpath($dir),
            $prefixes['SugarCraft\\Jwt\\'],
        );
        self::assertContains($srcDir, $registered);
    }
}
