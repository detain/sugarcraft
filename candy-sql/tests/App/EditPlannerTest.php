<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\App\EditPlanner;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Schema\SchemaColumn;

/**
 * SQL-O03: the refusal ladder in front of every grid write.
 */
final class EditPlannerTest extends TestCase
{
    private static function col(string $name, string $type, bool $pk = false): SchemaColumn
    {
        return new SchemaColumn(0, $name, $type, false, null, $pk);
    }

    public function testPkColumnsKeepColumnOrder(): void
    {
        $schema = [self::col('b', 'int', true), self::col('x', 'text'), self::col('a', 'int', true)];

        $this->assertSame(['b', 'a'], EditPlanner::pkColumns($schema));
        $this->assertSame([], EditPlanner::pkColumns([self::col('x', 'text')]));
    }

    public function testKeyIsTheFullPrimaryKeyOfTheRow(): void
    {
        $schema = [self::col('org', 'int', true), self::col('user', 'int', true), self::col('note', 'text')];

        $this->assertSame(
            ['key' => ['org' => 1, 'user' => 7]],
            EditPlanner::key(Flavor::Sqlite, $schema, ['org' => 1, 'user' => 7, 'note' => 'n']),
        );
    }

    public function testNoPrimaryKeyRefuses(): void
    {
        $this->assertSame(
            ['refusal' => 'edit.refuse_no_pk'],
            EditPlanner::key(Flavor::Sqlite, [self::col('x', 'text')], ['x' => 'a']),
        );
    }

    public function testNullMissingOrBinaryKeyValueRefuses(): void
    {
        $schema = [self::col('id', 'integer', true)];
        $hidden = ['refusal' => 'edit.refuse_pk_hidden'];

        $this->assertSame($hidden, EditPlanner::key(Flavor::Sqlite, $schema, ['id' => null]));
        $this->assertSame($hidden, EditPlanner::key(Flavor::Sqlite, $schema, ['other' => 1]));

        $blobKey = [self::col('k', 'BLOB', true)];
        $this->assertSame($hidden, EditPlanner::key(Flavor::Sqlite, $blobKey, ['k' => '[blob 4 bytes]']), 'a placeholder is not the key');
    }

    public function testCellRefusalCoversBinaryAndUnknownColumns(): void
    {
        $schema = [self::col('id', 'integer', true), self::col('img', 'BLOB'), self::col('doc', 'jsonb')];

        $this->assertNull(EditPlanner::cellRefusal(Flavor::Sqlite, $schema, 'id'));
        $this->assertSame('edit.refuse_binary', EditPlanner::cellRefusal(Flavor::Sqlite, $schema, 'img'));
        $this->assertSame('edit.refuse_binary', EditPlanner::cellRefusal(Flavor::Postgres, $schema, 'doc'));
        $this->assertNull(EditPlanner::cellRefusal(Flavor::Sqlite, $schema, 'doc'), 'jsonb is a postgres-only elision');
        $this->assertSame('edit.refuse_schema', EditPlanner::cellRefusal(Flavor::Sqlite, $schema, 'ghost'));
    }
}
