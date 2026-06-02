<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Validation;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Admin\Validation\ConnectionValidator;
use SugarCraft\Query\Admin\Validation\PsUsableValidator;
use SugarCraft\Query\Admin\Validation\PrivilegeValidator;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Db\Version;

/**
 * Tests for admin validators.
 */
final class ValidatorTest extends TestCase
{
    public function testConnectionValidatorPassesWhenPingSucceeds(): void
    {
        $db = $this->createMock(\SugarCraft\Query\Db\DatabaseInterface::class);
        $db->method('ping')->willReturn(true);
        $db->method('query')->willReturn([['1' => 1]]);

        $ctx = $this->createMock(ServerContextInterface::class);
        $ctx->method('connection')->willReturn($db);

        $validator = new ConnectionValidator($ctx);
        $this->assertTrue($validator->isValid());
    }

    public function testConnectionValidatorFailsWhenPingFails(): void
    {
        $db = $this->createMock(\SugarCraft\Query\Db\DatabaseInterface::class);
        $db->method('ping')->willReturn(false);

        $ctx = $this->createMock(ServerContextInterface::class);
        $ctx->method('connection')->willReturn($db);

        $validator = new ConnectionValidator($ctx);
        $this->assertFalse($validator->isValid());
        $this->assertNotEmpty($validator->error());
    }

    public function testPsUsableValidatorPassesForMySQL(): void
    {
        $ctx = $this->createMock(ServerContextInterface::class);
        $ctx->method('flavor')->willReturn(Flavor::MySQL);
        $ctx->method('statusVariables')->willReturn(['Uptime' => '3600']);

        $validator = new PsUsableValidator($ctx);
        $this->assertTrue($validator->isValid());
    }

    public function testPsUsableValidatorFailsForSqlite(): void
    {
        $ctx = $this->createMock(ServerContextInterface::class);
        $ctx->method('flavor')->willReturn(Flavor::Sqlite);

        $validator = new PsUsableValidator($ctx);
        $this->assertFalse($validator->isValid());
        $this->assertStringContainsString('SQLite', $validator->error());
    }

    public function testPsUsableValidatorFailsWhenStatusVariablesEmpty(): void
    {
        $ctx = $this->createMock(ServerContextInterface::class);
        $ctx->method('flavor')->willReturn(Flavor::MySQL);
        $ctx->method('statusVariables')->willReturn([]);

        $validator = new PsUsableValidator($ctx);
        $this->assertFalse($validator->isValid());
    }

    public function testPrivilegeValidatorHasPrivilege(): void
    {
        $db = $this->createMock(\SugarCraft\Query\Db\DatabaseInterface::class);
        $db->method('query')->willReturn([
            ['Privilege' => 'Process'],
            ['Privilege' => 'Select'],
        ]);

        $ctx = $this->createMock(ServerContextInterface::class);
        $ctx->method('flavor')->willReturn(Flavor::MySQL);
        $ctx->method('connection')->willReturn($db);

        $validator = new PrivilegeValidator($ctx);
        $this->assertTrue($validator->isValid());
        $this->assertTrue($validator->hasPrivilege('process'));
        $this->assertFalse($validator->hasPrivilege('super'));
    }

    public function testPrivilegeValidatorFailsWithoutProcessPrivilege(): void
    {
        $db = $this->createMock(\SugarCraft\Query\Db\DatabaseInterface::class);
        $db->method('query')->willReturn([
            ['Privilege' => 'Select'],
            ['Privilege' => 'Insert'],
        ]);

        $ctx = $this->createMock(ServerContextInterface::class);
        $ctx->method('flavor')->willReturn(Flavor::MySQL);
        $ctx->method('connection')->willReturn($db);

        $validator = new PrivilegeValidator($ctx);
        $this->assertFalse($validator->isValid());
    }

    public function testPrivilegeValidatorPassesForSqlite(): void
    {
        $ctx = $this->createMock(ServerContextInterface::class);
        $ctx->method('flavor')->willReturn(Flavor::Sqlite);

        $validator = new PrivilegeValidator($ctx);
        $this->assertTrue($validator->isValid());
    }
}
