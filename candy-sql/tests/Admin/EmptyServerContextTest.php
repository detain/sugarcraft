<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Admin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Admin\EmptyServerContext;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\ServerVersion;

/**
 * Tests for EmptyServerContext — placeholder context for unsupported
 * database flavors (e.g., SQLite). Returns safe defaults so pages like
 * DashboardPage can initialize without throwing.
 */
final class EmptyServerContextTest extends TestCase
{
    private EmptyServerContext $ctx;

    protected function setUp(): void
    {
        $this->ctx = new EmptyServerContext();
    }

    public function testFlavorReturnsSqlite(): void
    {
        // STEP 1.1: flavor() now returns Sqlite instead of throwing, so that
        // DashboardPage and other pages can initialize with SQLite without
        // crashing. Pages that need real DB data should check flavor() first.
        $this->assertSame(Flavor::Sqlite, $this->ctx->flavor());
    }

    public function testVersionReturnsZeroVersion(): void
    {
        // STEP 1.1: version() now returns ServerVersion::parse('') which produces
        // a zero version (0.0.0, raw='') instead of throwing. This enables
        // DashboardPage initialization with SQLite. Pages that need real
        // version data should check flavor() first.
        $v = $this->ctx->version();
        $this->assertSame(0, $v->major);
        $this->assertSame(0, $v->minor);
        $this->assertSame(0, $v->release);
        $this->assertSame('', $v->raw);
    }

    public function testVersionIsAtLeastReturnsFalseForAnyRealVersion(): void
    {
        // Zero version (0.0.0) must not satisfy isAtLeast for any real version.
        $v = $this->ctx->version();
        $this->assertFalse($v->isAtLeast(8, 0, 0));
        $this->assertFalse($v->isAtLeast(0, 0, 1));
    }

    public function testServerVariablesReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->ctx->serverVariables());
    }

    public function testStatusVariablesReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->ctx->statusVariables());
    }

    public function testStatusVariablesTsReturnsZero(): void
    {
        $this->assertSame(0.0, $this->ctx->statusVariablesTs());
    }

    public function testPluginsReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->ctx->plugins());
    }

    public function testVersionStringReturnsEmptyString(): void
    {
        $this->assertSame('', $this->ctx->versionString());
    }

    public function testWasResetReturnsFalse(): void
    {
        $this->assertFalse($this->ctx->wasReset());
    }

    public function testConnectionAnswersEmptySentinel(): void
    {
        // SQL-C01: connection() used to throw RuntimeException('Not supported')
        // — which killed the TUI wherever a page dereferenced it during build
        // (ReportsPage via adminPage()). The sentinel answers empty data.
        $db = $this->ctx->connection();
        $this->assertSame([], $db->tables());
        $this->assertSame([], $db->query('anything'));
        $this->assertSame([], $db->rows('t'));
        $this->assertSame(0, $db->exec('UPDATE t SET x = 1'));
        $this->assertFalse($db->ping());
    }

    public function testInjectedHandleIsHonoured(): void
    {
        $fake = new FakeDatabase();
        $this->assertSame($fake, (new EmptyServerContext($fake))->connection());
    }

    public function testRefreshIsNoOp(): void
    {
        // Must not throw — refresh is a no-op for EmptyServerContext.
        $this->ctx->refresh();
        $this->assertSame([], $this->ctx->serverVariables());
    }
}
