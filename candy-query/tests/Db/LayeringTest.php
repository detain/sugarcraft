<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Db;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\Resilience\ReconnectManager;
use SugarCraft\Query\Admin\Sampler;
use SugarCraft\Query\Db\MysqlDatabase;
use SugarCraft\Query\Db\ReconnectException;
use SugarCraft\Query\Db\ReconnectManagerInterface;
use SugarCraft\Query\Db\SamplerInterface;

/**
 * Guards the Db → Admin layering direction.
 *
 * The Db layer must not depend on the Admin layer: MysqlDatabase drives
 * reconnect/sampler-reset through Db-level interfaces, and the concrete
 * Admin implementations depend down onto them. Regression cover for the
 * audit's formally-unmet "MysqlDatabase has no Admin\* imports" criterion.
 */
final class LayeringTest extends TestCase
{
    public function testMysqlDatabaseDoesNotImportTheAdminLayer(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Db/MysqlDatabase.php',
        );

        $this->assertStringNotContainsString(
            'use SugarCraft\\Query\\Admin\\',
            $source,
            'Db\\MysqlDatabase must not import the Admin layer (layering inversion).',
        );
    }

    public function testSamplerImplementsTheDbSeam(): void
    {
        $this->assertTrue(
            (new \ReflectionClass(Sampler::class))->implementsInterface(SamplerInterface::class),
        );
    }

    public function testReconnectManagerImplementsTheDbSeam(): void
    {
        $this->assertTrue(
            (new \ReflectionClass(ReconnectManager::class))->implementsInterface(ReconnectManagerInterface::class),
        );
    }

    public function testReconnectExceptionLivesInTheDbLayer(): void
    {
        $this->assertTrue(class_exists(ReconnectException::class));
        $this->assertTrue(
            is_a(ReconnectException::class, \RuntimeException::class, true),
        );
    }

    /**
     * MysqlDatabase once called $this->sampler?->registerUptime() — a method
     * that never existed on Sampler. The null-safe operator only guards null,
     * so a real injected sampler would have fatalled. Lock it out: the call is
     * gone and the seam interface does not promise a method Sampler lacks.
     */
    public function testReconnectPathNeverCallsAnUndefinedSamplerMethod(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Db/MysqlDatabase.php',
        );

        $this->assertStringNotContainsString('registerUptime', $source);
        $this->assertFalse(method_exists(Sampler::class, 'registerUptime'));
        $this->assertFalse(method_exists(SamplerInterface::class, 'registerUptime'));
    }
}
