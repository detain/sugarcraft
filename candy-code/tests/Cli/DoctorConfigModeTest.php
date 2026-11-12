<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\DoctorChecks;

/**
 * The `config file` doctor row warns when the user config is readable by
 * group or other users. Plaintext stays the default; this is a warning only.
 */
final class DoctorConfigModeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/candy-code-config-mode-' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        Bootstrap::useConfigPath(null);
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function configRow(string $path, int $mode): array
    {
        file_put_contents($path, "{}\n");
        chmod($path, $mode);
        Bootstrap::useConfigPath($path);

        return (DoctorChecks::probes(null)['config file'])();
    }

    public function testAnOwnerOnlyConfigStaysOk(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX permission bits');
        }
        $row = $this->configRow($this->dir . '/config.json', 0600);

        self::assertSame('OK', $row['status'], $row['detail']);
    }

    public function testAGroupOrWorldReadableConfigWarns(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX permission bits');
        }
        $path = $this->dir . '/config.json';
        $row = $this->configRow($path, 0644);

        // The detail text is pending its lang key (cli.doctor.config_loose_mode);
        // until it lands the row carries the raw key, so only the verdict is pinned.
        self::assertSame('WARN', $row['status'], $row['detail']);
    }

    public function testTheModeHelperIgnoresOtherBitsOffWindows(): void
    {
        $method = new \ReflectionMethod(DoctorChecks::class, 'looseConfigMode');
        $method->setAccessible(true);
        $path = $this->dir . '/probe.json';
        file_put_contents($path, '{}');
        chmod($path, 0640);

        self::assertSame(0640, $method->invoke(null, $path, 'Linux'));
        self::assertNull($method->invoke(null, $path, 'Windows'));
        self::assertNull($method->invoke(null, $this->dir . '/missing.json', 'Linux'));
    }
}
