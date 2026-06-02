<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\PerfSchema;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\PerfSchema\EasySetupDetector;
use SugarCraft\Query\Tests\Admin\FakeDatabase;

/**
 * Tests for EasySetupDetector.
 */
final class EasySetupDetectorTest extends TestCase
{
    public function testNewCreatesInstance(): void
    {
        $db = new FakeDatabase();
        $detector = EasySetupDetector::new($db);

        $this->assertInstanceOf(EasySetupDetector::class, $detector);
    }

    public function testIsDisabledReturnsFalseWhenQuerySucceeds(): void
    {
        $db = new FakeDatabase();
        $db->setQueryResult([['total' => 100, 'enabled' => 50]]);

        $detector = EasySetupDetector::new($db);
        $this->assertFalse($detector->isDisabled());
    }

    public function testIsDisabledReturnsTrueOnPrivilegeError(): void
    {
        $db = new FakeDatabase();
        $db->setQueryThrows(new \PDOException('SELECT command denied', 1142));

        $detector = EasySetupDetector::new($db);
        $this->assertTrue($detector->isDisabled());
    }

    public function testIsDisabledReturnsTrueOnTableNotFound(): void
    {
        $db = new FakeDatabase();
        $db->setQueryThrows(new \PDOException('Table not found', 1146));

        $detector = EasySetupDetector::new($db);
        $this->assertTrue($detector->isDisabled());
    }

    public function testIsDisabledReturnsTrueOnConnectionError(): void
    {
        $db = new FakeDatabase();
        $db->setQueryThrows(new \PDOException('Connection refused', 2003));

        $detector = EasySetupDetector::new($db);
        $this->assertTrue($detector->isDisabled());
    }

    public function testEnabledPercentageWithAllEnabled(): void
    {
        $db = new FakeDatabase();
        // Single query returns both total and enabled
        $db->setQueryResult([['total' => 100, 'enabled' => 100]]);

        $detector = EasySetupDetector::new($db);
        $this->assertSame(100, $detector->enabledPercentage());
    }

    public function testEnabledPercentageWithNoneEnabled(): void
    {
        $db = new FakeDatabase();
        $db->setQueryResult([['total' => 100, 'enabled' => 0]]);

        $detector = EasySetupDetector::new($db);
        $this->assertSame(0, $detector->enabledPercentage());
    }

    public function testEnabledPercentageWithHalfEnabled(): void
    {
        $db = new FakeDatabase();
        $db->setQueryResult([['total' => 100, 'enabled' => 50]]);

        $detector = EasySetupDetector::new($db);
        $this->assertSame(50, $detector->enabledPercentage());
    }

    public function testTotalCountReturnsZeroOnError(): void
    {
        $db = new FakeDatabase();
        $db->setQueryThrows(new \PDOException('Query failed', 500));

        $detector = EasySetupDetector::new($db);
        $this->assertSame(0, $detector->totalCount());
    }

    public function testEnabledCountReturnsZeroOnError(): void
    {
        $db = new FakeDatabase();
        $db->setQueryThrows(new \PDOException('Query failed', 500));

        $detector = EasySetupDetector::new($db);
        $this->assertSame(0, $detector->enabledCount());
    }

    public function testDetectReturnsDisabledWhenIsDisabled(): void
    {
        $db = new FakeDatabase();
        $db->setQueryThrows(new \PDOException('Access denied', 1142));

        $detector = EasySetupDetector::new($db);
        $this->assertSame('disabled', $detector->detect());
    }

    public function testDetectReturnsFullyWhenAllEnabled(): void
    {
        $db = new FakeDatabase();
        // isDisabled check returns success (empty result means query worked)
        $db->setQueryResult([['cnt' => 1]]);
        $detector = EasySetupDetector::new($db);

        // enabledPercentage query
        $db->setQueryResult([['total' => 100, 'enabled' => 100]]);
        $this->assertSame('fully', $detector->detect());
    }

    public function testDetectReturnsCustomWhenNotFullyEnabled(): void
    {
        $db = new FakeDatabase();
        // isDisabled check returns success
        $db->setQueryResult([['cnt' => 1]]);
        $detector = EasySetupDetector::new($db);

        // enabledPercentage query - 50% enabled, not default setup
        $db->setQueryResult([['total' => 100, 'enabled' => 50]]);
        $this->assertSame('custom', $detector->detect());
    }

    public function testDetectReturnsDisabledOnConnectionError(): void
    {
        $db = new FakeDatabase();
        $db->setQueryThrows(new \PDOException('Connection refused', 2003));

        $detector = EasySetupDetector::new($db);
        $this->assertSame('disabled', $detector->detect());
    }

    public function testDetectReturnsDisabledOnAccessError(): void
    {
        $db = new FakeDatabase();
        $db->setQueryThrows(new \PDOException('Access denied', 1142));

        $detector = EasySetupDetector::new($db);
        $this->assertSame('disabled', $detector->detect());
    }
}
