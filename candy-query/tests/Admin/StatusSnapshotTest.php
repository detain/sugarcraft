<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\StatusSnapshot;

/**
 * Tests for StatusSnapshot immutable value object.
 */
final class StatusSnapshotTest extends TestCase
{
    public function testConstructionWithVariablesAndTimestamp(): void
    {
        $vars = ['Com_select' => '100', 'Com_insert' => '50'];
        $snapshot = new StatusSnapshot($vars, 1000.5);

        $this->assertSame($vars, $snapshot->variables);
        $this->assertSame(1000.5, $snapshot->ts);
    }

    public function testGetReturnsValueWhenPresent(): void
    {
        $vars = ['Com_select' => '100', 'Com_insert' => '50'];
        $snapshot = new StatusSnapshot($vars, 1000.0);

        $this->assertSame('100', $snapshot->get('Com_select'));
        $this->assertSame('50', $snapshot->get('Com_insert'));
    }

    public function testGetReturnsNullWhenAbsent(): void
    {
        $vars = ['Com_select' => '100'];
        $snapshot = new StatusSnapshot($vars, 1000.0);

        $this->assertNull($snapshot->get('Com_nonexistent'));
    }

    public function testGetIntReturnsInteger(): void
    {
        $vars = ['Com_select' => '100', 'Com_insert' => '50'];
        $snapshot = new StatusSnapshot($vars, 1000.0);

        $this->assertSame(100, $snapshot->getInt('Com_select'));
        $this->assertSame(50, $snapshot->getInt('Com_insert'));
    }

    public function testGetIntReturnsNullForNonNumeric(): void
    {
        $vars = ['Some_var' => 'not_numeric'];
        $snapshot = new StatusSnapshot($vars, 1000.0);

        $this->assertNull($snapshot->getInt('Some_var'));
    }

    public function testGetIntReturnsNullForAbsent(): void
    {
        $snapshot = new StatusSnapshot([], 1000.0);

        $this->assertNull($snapshot->getInt('Com_select'));
    }

    public function testGetFloatReturnsFloat(): void
    {
        $vars = ['Bytes_received' => '1048576'];
        $snapshot = new StatusSnapshot($vars, 1000.0);

        $this->assertSame(1048576.0, $snapshot->getFloat('Bytes_received'));
    }

    public function testGetFloatReturnsNullForNonNumeric(): void
    {
        $vars = ['Some_var' => 'text'];
        $snapshot = new StatusSnapshot($vars, 1000.0);

        $this->assertNull($snapshot->getFloat('Some_var'));
    }

    public function testGetFloatReturnsNullForAbsent(): void
    {
        $snapshot = new StatusSnapshot([], 1000.0);

        $this->assertNull($snapshot->getFloat('Bytes_received'));
    }

    public function testHasReturnsTrueWhenPresent(): void
    {
        $vars = ['Com_select' => '100'];
        $snapshot = new StatusSnapshot($vars, 1000.0);

        $this->assertTrue($snapshot->has('Com_select'));
    }

    public function testHasReturnsFalseWhenAbsent(): void
    {
        $snapshot = new StatusSnapshot([], 1000.0);

        $this->assertFalse($snapshot->has('Com_select'));
    }

    public function testElapsedSinceReturnsPositiveDelta(): void
    {
        $older = new StatusSnapshot([], 1000.0);
        $newer = new StatusSnapshot([], 1050.5);

        $this->assertSame(50.5, $newer->elapsedSince($older));
    }

    public function testElapsedSinceReturnsZeroForSameTimestamp(): void
    {
        $snap1 = new StatusSnapshot([], 1000.0);
        $snap2 = new StatusSnapshot([], 1000.0);

        $this->assertSame(0.0, $snap2->elapsedSince($snap1));
    }

    public function testDeltaReturnsEmptyArrayWhenNoCommonKeys(): void
    {
        $prev = new StatusSnapshot(['A' => '10'], 1000.0);
        $curr = new StatusSnapshot(['B' => '20'], 1010.0);

        $this->assertSame([], $curr->delta($prev));
    }

    public function testDeltaReturnsDeltasForCommonNumericKeys(): void
    {
        $prev = new StatusSnapshot(['Com_select' => '100', 'Com_insert' => '50'], 1000.0);
        $curr = new StatusSnapshot(['Com_select' => '150', 'Com_insert' => '70'], 1010.0);

        $delta = $curr->delta($prev);

        $this->assertSame(50.0, $delta['Com_select']);
        $this->assertSame(20.0, $delta['Com_insert']);
    }

    public function testDeltaSkipsNonNumericValues(): void
    {
        $prev = new StatusSnapshot(['Com_select' => '100', 'Status' => 'active'], 1000.0);
        $curr = new StatusSnapshot(['Com_select' => '150', 'Status' => 'active'], 1010.0);

        $delta = $curr->delta($prev);

        $this->assertArrayNotHasKey('Status', $delta);
        $this->assertSame(50.0, $delta['Com_select']);
    }

    public function testDeltaSkipsKeysOnlyInPrevious(): void
    {
        $prev = new StatusSnapshot(['Com_select' => '100', 'Com_delete' => '10'], 1000.0);
        $curr = new StatusSnapshot(['Com_select' => '150'], 1010.0);

        $delta = $curr->delta($prev);

        $this->assertArrayNotHasKey('Com_delete', $delta);
    }

    public function testDeltaWithFloatValues(): void
    {
        $prev = new StatusSnapshot(['Bytes_received' => '1048576'], 1000.0);
        $curr = new StatusSnapshot(['Bytes_received' => '2097152'], 1010.0);

        $delta = $curr->delta($prev);

        $this->assertSame(1048576.0, $delta['Bytes_received']);
    }

    public function testEmptySnapshotHasNoVariables(): void
    {
        $snapshot = new StatusSnapshot([], 0.0);

        $this->assertNull($snapshot->get('any'));
        $this->assertFalse($snapshot->has('any'));
        $this->assertSame([], $snapshot->delta(new StatusSnapshot(['X' => '1'], 0.0)));
    }
}
