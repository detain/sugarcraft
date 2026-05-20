<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests;

use SugarCraft\Query\CellEditor;
use PHPUnit\Framework\TestCase;

final class CellEditorTest extends TestCase
{
    private function memoryPdo(): \PDO
    {
        return new \PDO('sqlite::memory:');
    }

    private function setupTable(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT)');
        $pdo->exec("INSERT INTO users VALUES (1, 'alice', 'alice@example.com')");
        $pdo->exec("INSERT INTO users VALUES (2, 'bob', 'bob@example.com')");
    }

    public function testUpdateCellModifiesValue(): void
    {
        $pdo = $this->memoryPdo();
        $this->setupTable($pdo);

        $editor = new CellEditor($pdo, 'users', 'id');
        $affected = $editor->updateCell(1, 'name', 'alice_updated');

        $this->assertSame(1, $affected);
        $val = $editor->readCell(1, 'name');
        $this->assertSame('alice_updated', $val);
    }

    public function testUpdateCellReturnsZeroWhenNoMatch(): void
    {
        $pdo = $this->memoryPdo();
        $this->setupTable($pdo);

        $editor = new CellEditor($pdo, 'users', 'id');
        $affected = $editor->updateCell(999, 'name', 'ghost');

        $this->assertSame(0, $affected);
    }

    public function testReadCellReturnsCorrectValue(): void
    {
        $pdo = $this->memoryPdo();
        $this->setupTable($pdo);

        $editor = new CellEditor($pdo, 'users', 'id');
        $name = $editor->readCell(2, 'name');
        $this->assertSame('bob', $name);
    }

    public function testReadCellReturnsNullForMissingRow(): void
    {
        $pdo = $this->memoryPdo();
        $this->setupTable($pdo);

        $editor = new CellEditor($pdo, 'users', 'id');
        $val = $editor->readCell(999, 'name');
        $this->assertNull($val);
    }

    public function testUpdateRowModifiesMultipleColumns(): void
    {
        $pdo = $this->memoryPdo();
        $this->setupTable($pdo);

        $editor = new CellEditor($pdo, 'users', 'id');
        $affected = $editor->updateRow(1, ['name' => 'alice_v2', 'email' => 'alice_v2@example.com']);

        $this->assertSame(1, $affected);
        $this->assertSame('alice_v2', $editor->readCell(1, 'name'));
        $this->assertSame('alice_v2@example.com', $editor->readCell(1, 'email'));
    }

    public function testUpdateRowWithEmptyArrayReturnsZero(): void
    {
        $pdo = $this->memoryPdo();
        $this->setupTable($pdo);

        $editor = new CellEditor($pdo, 'users', 'id');
        $affected = $editor->updateRow(1, []);
        $this->assertSame(0, $affected);
    }

    public function testUpdateRowReturnsZeroWhenNoMatch(): void
    {
        $pdo = $this->memoryPdo();
        $this->setupTable($pdo);

        $editor = new CellEditor($pdo, 'users', 'id');
        $affected = $editor->updateRow(999, ['name' => 'nobody']);
        $this->assertSame(0, $affected);
    }

    public function testUpdateCellWithNullValue(): void
    {
        $pdo = $this->memoryPdo();
        $this->setupTable($pdo);

        $editor = new CellEditor($pdo, 'users', 'id');
        $affected = $editor->updateCell(1, 'email', null);

        $this->assertSame(1, $affected);
        $this->assertNull($editor->readCell(1, 'email'));
    }
}
