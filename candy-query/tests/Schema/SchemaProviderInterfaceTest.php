<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Schema;

use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Schema\SchemaProviderInterface;
use PHPUnit\Framework\TestCase;

final class SchemaProviderInterfaceTest extends TestCase
{
    public function testInterfaceRequiresTablesMethod(): void
    {
        $this->assertTrue(
            method_exists(SchemaProviderInterface::class, 'tables'),
            'SchemaProviderInterface must have tables() method',
        );
    }

    public function testInterfaceRequiresColumnsMethod(): void
    {
        $this->assertTrue(
            method_exists(SchemaProviderInterface::class, 'columns'),
            'SchemaProviderInterface must have columns() method',
        );
    }

    public function testInterfaceRequiresIndexesMethod(): void
    {
        $this->assertTrue(
            method_exists(SchemaProviderInterface::class, 'indexes'),
            'SchemaProviderInterface must have indexes() method',
        );
    }

    public function testInterfaceRequiresForeignKeysMethod(): void
    {
        $this->assertTrue(
            method_exists(SchemaProviderInterface::class, 'foreignKeys'),
            'SchemaProviderInterface must have foreignKeys() method',
        );
    }

    public function testInterfaceRequiresDropTableMethod(): void
    {
        $this->assertTrue(
            method_exists(SchemaProviderInterface::class, 'dropTable'),
            'SchemaProviderInterface must have dropTable() method',
        );
    }

    public function testInterfaceRequiresWithFlavorMethod(): void
    {
        $this->assertTrue(
            method_exists(SchemaProviderInterface::class, 'withFlavor'),
            'SchemaProviderInterface must have withFlavor() method',
        );
    }

    public function testTablesReturnsArray(): void
    {
        $reflection = new \ReflectionMethod(SchemaProviderInterface::class, 'tables');
        $this->assertSame('array', $reflection->getReturnType()->getName());
    }

    public function testColumnsReturnsArray(): void
    {
        $reflection = new \ReflectionMethod(SchemaProviderInterface::class, 'columns');
        $this->assertSame('array', $reflection->getReturnType()->getName());
    }

    public function testIndexesReturnsArray(): void
    {
        $reflection = new \ReflectionMethod(SchemaProviderInterface::class, 'indexes');
        $this->assertSame('array', $reflection->getReturnType()->getName());
    }

    public function testForeignKeysReturnsArray(): void
    {
        $reflection = new \ReflectionMethod(SchemaProviderInterface::class, 'foreignKeys');
        $this->assertSame('array', $reflection->getReturnType()->getName());
    }

    public function testDropTableReturnsVoid(): void
    {
        $reflection = new \ReflectionMethod(SchemaProviderInterface::class, 'dropTable');
        $this->assertSame('void', $reflection->getReturnType()->getName());
    }

    public function testWithFlavorReturnsSelf(): void
    {
        $reflection = new \ReflectionMethod(SchemaProviderInterface::class, 'withFlavor');
        $returnType = $reflection->getReturnType();
        // Return type is 'self' which refers to the interface itself
        $this->assertSame('self', $returnType->getName());
    }

    public function testColumnsAcceptsStringParameter(): void
    {
        $reflection = new \ReflectionMethod(SchemaProviderInterface::class, 'columns');
        $params = $reflection->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('table', $params[0]->getName());
        $this->assertSame('string', $params[0]->getType()->getName());
    }

    public function testIndexesAcceptsStringParameter(): void
    {
        $reflection = new \ReflectionMethod(SchemaProviderInterface::class, 'indexes');
        $params = $reflection->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('table', $params[0]->getName());
        $this->assertSame('string', $params[0]->getType()->getName());
    }

    public function testForeignKeysAcceptsStringParameter(): void
    {
        $reflection = new \ReflectionMethod(SchemaProviderInterface::class, 'foreignKeys');
        $params = $reflection->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('table', $params[0]->getName());
        $this->assertSame('string', $params[0]->getType()->getName());
    }

    public function testDropTableAcceptsStringParameter(): void
    {
        $reflection = new \ReflectionMethod(SchemaProviderInterface::class, 'dropTable');
        $params = $reflection->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('table', $params[0]->getName());
        $this->assertSame('string', $params[0]->getType()->getName());
    }

    public function testWithFlavorAcceptsFlavorParameter(): void
    {
        $reflection = new \ReflectionMethod(SchemaProviderInterface::class, 'withFlavor');
        $params = $reflection->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('flavor', $params[0]->getName());
        $this->assertSame(Flavor::class, $params[0]->getType()->getName());
    }
}
