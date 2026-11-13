<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Db;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Db\DmlBuilder;
use SugarCraft\Sql\Db\DmlStatement;
use SugarCraft\Sql\Db\Flavor;

/**
 * SQL-O03: the single-row write builder. The laws pinned here: identifiers
 * always quoted per flavor, values ALWAYS bound (never in the SQL text), the
 * display twin is honest, and a statement that could touch more than the row
 * on screen cannot be built.
 */
final class DmlBuilderTest extends TestCase
{
    public function testUpdateQuotesIdentifiersAndBindsValues(): void
    {
        $s = DmlBuilder::new(Flavor::Sqlite)->update('users', ['id' => 5], 'name', "O'Brien");

        $this->assertSame('UPDATE "users" SET "name" = ? WHERE "id" = ?', $s->sql);
        $this->assertSame(["O'Brien", 5], $s->params);
        $this->assertSame('UPDATE "users" SET "name" = \'O\'\'Brien\' WHERE "id" = 5', $s->display);
        $this->assertSame(DmlStatement::UPDATE, $s->kind);
        $this->assertTrue($s->allowZero);
        $this->assertStringNotContainsString("O'Brien", $s->sql, 'values never ride the SQL text');
    }

    public function testMysqlFlavorUsesBackticks(): void
    {
        $s = DmlBuilder::new(Flavor::MySQL)->update('order items', ['id' => 1], 'qty', '3');

        $this->assertSame('UPDATE `order items` SET `qty` = ? WHERE `id` = ?', $s->sql);
    }

    public function testHostileIdentifiersAreQuotedNotSpliced(): void
    {
        $s = DmlBuilder::new(Flavor::Postgres)->update('t"; DROP TABLE x; --', ['i"d' => 1], 'c"ol', 'v');

        $this->assertSame('UPDATE "t""; DROP TABLE x; --" SET "c""ol" = ? WHERE "i""d" = ?', $s->sql);

        $m = DmlBuilder::new(Flavor::MySQL)->delete('t`x\\', ['id' => 1]);
        $this->assertSame('DELETE FROM `t``x\\\\` WHERE `id` = ?', $m->sql);
    }

    public function testHostileValuesStayInParamsAndDisplayEscapes(): void
    {
        $evil = "x'; DELETE FROM users; --";
        $s = DmlBuilder::new(Flavor::Sqlite)->update('users', ['id' => 1], 'name', $evil);

        $this->assertSame([$evil, 1], $s->params);
        $this->assertSame(1, substr_count($s->sql, '?') - 1, 'one value marker plus one key marker');
        $this->assertStringContainsString("'x''; DELETE FROM users; --'", $s->display, 'the display literal doubles the quote');
    }

    public function testNullValueIsBoundAsNullAndDisplayedAsNull(): void
    {
        $s = DmlBuilder::new(Flavor::Sqlite)->update('users', ['id' => 1], 'name', null);

        $this->assertSame([null, 1], $s->params);
        $this->assertStringContainsString('SET "name" = NULL', $s->display);
    }

    public function testCompositeKeyAddressesEveryKeyColumn(): void
    {
        $s = DmlBuilder::new(Flavor::Sqlite)->delete('memberships', ['org' => 2, 'user' => 'u9']);

        $this->assertSame('DELETE FROM "memberships" WHERE "org" = ? AND "user" = ?', $s->sql);
        $this->assertSame([2, 'u9'], $s->params);
        $this->assertSame('DELETE FROM "memberships" WHERE "org" = 2 AND "user" = \'u9\'', $s->display);
        $this->assertFalse($s->allowZero, 'a delete must hit exactly one row');
    }

    public function testEmptyOrNullKeyIsUnrepresentable(): void
    {
        $b = DmlBuilder::new(Flavor::Sqlite);

        foreach ([
            fn () => $b->delete('t', []),
            fn () => $b->update('t', [], 'c', 'v'),
            fn () => $b->delete('t', ['id' => null]),
        ] as $build) {
            try {
                $build();
                $this->fail('a statement that could touch more than one row must not build');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testEmptyNamesAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DmlBuilder::new(Flavor::Sqlite)->update('', ['id' => 1], 'c', 'v');
    }

    public function testInsertListsOnlyGivenColumns(): void
    {
        $s = DmlBuilder::new(Flavor::Postgres)->insert('users', ['name' => 'ann', 'note' => null]);

        $this->assertSame('INSERT INTO "users" ("name", "note") VALUES (?, ?)', $s->sql);
        $this->assertSame(['ann', null], $s->params);
        $this->assertSame('INSERT INTO "users" ("name", "note") VALUES (\'ann\', NULL)', $s->display);
        $this->assertFalse($s->allowZero);
    }

    public function testInsertWithNoValuesIsDefaultValuesPerFlavor(): void
    {
        $this->assertSame('INSERT INTO "t" DEFAULT VALUES', DmlBuilder::new(Flavor::Sqlite)->insert('t', [])->sql);
        $this->assertSame('INSERT INTO "t" DEFAULT VALUES', DmlBuilder::new(Flavor::Postgres)->insert('t', [])->sql);
        $this->assertSame('INSERT INTO `t` () VALUES ()', DmlBuilder::new(Flavor::MariaDB)->insert('t', [])->sql);
    }

    public function testFloatAndBoolKeyValuesNormalise(): void
    {
        $s = DmlBuilder::new(Flavor::Sqlite)->delete('t', ['a' => 1.5, 'b' => true]);

        $this->assertSame([1.5, 1], $s->params);
        $this->assertSame('DELETE FROM "t" WHERE "a" = 1.5 AND "b" = 1', $s->display);
    }
}
