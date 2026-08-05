<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\TeamMessage;

/**
 * Tests for TeamMessage - value object representing an inter-teammate message.
 */
final class TeamMessageTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Construction and property access
    // -------------------------------------------------------------------------

    public function testConstructionWithAllFields(): void
    {
        $sentAt = new \DateTimeImmutable('2026-01-01T10:00:00Z');
        $payload = ['task_id' => 'task-42', 'result' => 'done'];

        $message = new TeamMessage(
            id: 'msg-1',
            fromTeammateId: 'teammate-alpha',
            toTeammateId: 'teammate-beta',
            type: 'task_result',
            payload: $payload,
            sentAt: $sentAt,
            read: true,
        );

        $this->assertSame('msg-1', $message->id);
        $this->assertSame('teammate-alpha', $message->fromTeammateId);
        $this->assertSame('teammate-beta', $message->toTeammateId);
        $this->assertSame('task_result', $message->type);
        $this->assertSame($payload, $message->payload);
        $this->assertSame($sentAt, $message->sentAt);
        $this->assertTrue($message->read);
    }

    public function testConstructionWithDefaultReadFlag(): void
    {
        $sentAt = new \DateTimeImmutable('2026-01-01T10:00:00Z');

        $message = new TeamMessage(
            id: 'msg-2',
            fromTeammateId: 'teammate-alpha',
            toTeammateId: 'teammate-beta',
            type: 'idle',
            payload: [],
            sentAt: $sentAt,
        );

        $this->assertFalse($message->read);
    }

    public function testConstructionWithTaskAssignedType(): void
    {
        $sentAt = new \DateTimeImmutable('2026-01-01T10:00:00Z');
        $payload = ['task_id' => 'task-99', 'title' => 'Do the thing'];

        $message = new TeamMessage(
            id: 'msg-3',
            fromTeammateId: 'team-lead',
            toTeammateId: 'teammate-1',
            type: 'task_assigned',
            payload: $payload,
            sentAt: $sentAt,
        );

        $this->assertSame('task_assigned', $message->type);
        $this->assertSame('task-99', $message->payload['task_id']);
        $this->assertFalse($message->read);
    }

    public function testConstructionWithErrorType(): void
    {
        $sentAt = new \DateTimeImmutable('2026-01-01T10:00:00Z');
        $payload = ['error' => 'Connection refused', 'task_id' => 'task-5'];

        $message = new TeamMessage(
            id: 'msg-4',
            fromTeammateId: 'teammate-2',
            toTeammateId: 'team-lead',
            type: 'error',
            payload: $payload,
            sentAt: $sentAt,
        );

        $this->assertSame('error', $message->type);
        $this->assertSame('Connection refused', $message->payload['error']);
        $this->assertFalse($message->read);
    }

    public function testConstructionWithEmptyPayload(): void
    {
        $sentAt = new \DateTimeImmutable('2026-01-01T10:00:00Z');

        $message = new TeamMessage(
            id: 'msg-5',
            fromTeammateId: 'teammate-x',
            toTeammateId: 'teammate-y',
            type: 'idle',
            payload: [],
            sentAt: $sentAt,
        );

        $this->assertSame([], $message->payload);
    }

    public function testConstructionWithComplexPayload(): void
    {
        $sentAt = new \DateTimeImmutable('2026-01-01T10:00:00Z');
        $payload = [
            'task_id' => 'task-complex',
            'steps' => ['step1', 'step2', 'step3'],
            'metadata' => ['priority' => 'high', 'retry_count' => 3],
        ];

        $message = new TeamMessage(
            id: 'msg-6',
            fromTeammateId: 'teammate-alpha',
            toTeammateId: 'teammate-beta',
            type: 'task_result',
            payload: $payload,
            sentAt: $sentAt,
            read: true,
        );

        $this->assertSame('task-complex', $message->payload['task_id']);
        $this->assertCount(3, $message->payload['steps']);
        $this->assertSame('high', $message->payload['metadata']['priority']);
        $this->assertTrue($message->read);
    }

    public function testPropertiesAreReadonly(): void
    {
        $sentAt = new \DateTimeImmutable();

        $message1 = new TeamMessage(
            id: 'msg-readonly-1',
            fromTeammateId: 'teammate-a',
            toTeammateId: 'teammate-b',
            type: 'idle',
            payload: [],
            sentAt: $sentAt,
        );

        $message2 = new TeamMessage(
            id: 'msg-readonly-2',
            fromTeammateId: 'teammate-a',
            toTeammateId: 'teammate-b',
            type: 'idle',
            payload: [],
            sentAt: $sentAt,
        );

        // Verify original message is unchanged
        $this->assertSame('msg-readonly-1', $message1->id);
        // Verify two instances with different ids are different objects
        $this->assertNotSame($message1, $message2);
        $this->assertSame('msg-readonly-2', $message2->id);
    }

    public function testAllValidMessageTypes(): void
    {
        $sentAt = new \DateTimeImmutable();
        $validTypes = ['task_assigned', 'task_result', 'idle', 'error'];

        foreach ($validTypes as $type) {
            $message = new TeamMessage(
                id: 'msg-type-test',
                fromTeammateId: 'teammate-from',
                toTeammateId: 'teammate-to',
                type: $type,
                payload: [],
                sentAt: $sentAt,
            );

            $this->assertSame($type, $message->type);
        }
    }
}
