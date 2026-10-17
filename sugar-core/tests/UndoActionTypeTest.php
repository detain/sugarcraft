<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use SugarCraft\Core\Undo\UndoActionType;
use SugarCraft\Core\Undo\UndoAction;
use PHPUnit\Framework\TestCase;

final class UndoActionTypeTest extends TestCase
{
    public function testUndoActionTypeCases(): void
    {
        $cases = [
            UndoActionType::Delete,
            UndoActionType::Move,
            UndoActionType::Rename,
            UndoActionType::Copy,
            UndoActionType::Insert,
            UndoActionType::Modify,
            UndoActionType::Custom,
        ];

        $this->assertCount(7, $cases);
    }

    public function testUndoActionTypeValues(): void
    {
        $this->assertSame('delete', UndoActionType::Delete->value);
        $this->assertSame('move', UndoActionType::Move->value);
        $this->assertSame('rename', UndoActionType::Rename->value);
        $this->assertSame('copy', UndoActionType::Copy->value);
        $this->assertSame('insert', UndoActionType::Insert->value);
        $this->assertSame('modify', UndoActionType::Modify->value);
        $this->assertSame('custom', UndoActionType::Custom->value);
    }

    public function testUndoActionProperties(): void
    {
        $action = new UndoAction(
            type: UndoActionType::Delete,
            payload: ['path' => '/tmp/test.txt', 'originalContent' => 'hello'],
            label: 'Delete file',
        );

        $this->assertSame(UndoActionType::Delete, $action->type);
        $this->assertSame(['path' => '/tmp/test.txt', 'originalContent' => 'hello'], $action->payload);
        $this->assertSame('Delete file', $action->label);
    }

    public function testUndoActionReadonly(): void
    {
        $action = new UndoAction(
            type: UndoActionType::Move,
            payload: ['from' => '/a', 'to' => '/b'],
            label: 'Move file',
        );

        $reflection = new \ReflectionClass($action);
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testUndoActionWithEmptyPayload(): void
    {
        $action = new UndoAction(
            type: UndoActionType::Custom,
            payload: [],
            label: 'Custom action',
        );

        $this->assertSame([], $action->payload);
        $this->assertSame(UndoActionType::Custom, $action->type);
    }
}
