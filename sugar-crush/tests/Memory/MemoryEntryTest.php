<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Memory;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Memory\MemoryEntry;

final class MemoryEntryTest extends TestCase
{
    public function testNewWithAllFields(): void
    {
        $now = new \DateTimeImmutable();
        $entry = new MemoryEntry(
            id: 'test-id-123',
            type: 'pattern',
            tags: ['php', 'testing'],
            scope: 'project',
            content: 'Always write tests for new features.',
            createdAt: $now,
            modifiedAt: $now,
        );

        $this->assertSame('test-id-123', $entry->id());
        $this->assertSame('pattern', $entry->type());
        $this->assertSame(['php', 'testing'], $entry->tags());
        $this->assertSame('project', $entry->scope());
        $this->assertSame('Always write tests for new features.', $entry->content());
        $this->assertSame($now, $entry->createdAt());
        $this->assertSame($now, $entry->modifiedAt());
    }

    public function testDefaultTagsIsEmptyArray(): void
    {
        $entry = MemoryEntry::new(
            type: 'convention',
            content: 'Follow PSR-12.',
            scope: 'user',
        );

        $this->assertSame([], $entry->tags());
    }

    public function testAccessorsReturnCorrectValues(): void
    {
        $now = new \DateTimeImmutable();
        $entry = new MemoryEntry(
            id: 'abc-456',
            type: 'decision',
            tags: ['architecture'],
            scope: 'agent',
            content: 'Use repository pattern for data access.',
            createdAt: $now,
            modifiedAt: $now,
        );

        $this->assertSame('abc-456', $entry->id());
        $this->assertSame('decision', $entry->type());
        $this->assertSame(['architecture'], $entry->tags());
        $this->assertSame('agent', $entry->scope());
        $this->assertSame('Use repository pattern for data access.', $entry->content());
        $this->assertSame($now, $entry->createdAt());
        $this->assertSame($now, $entry->modifiedAt());
    }

    public function testWithMethodsReturnNewInstances(): void
    {
        $original = MemoryEntry::new(
            type: 'pattern',
            content: 'Original content',
            scope: 'user',
            tags: ['original'],
        );

        $byType = $original->withType('convention');
        $byTags = $original->withTags(['new', 'tags']);
        $byScope = $original->withScope('project');
        $byContent = $original->withContent('New content');
        $byCreatedAt = $original->withCreatedAt(new \DateTimeImmutable('2024-01-01'));
        $byModifiedAt = $original->withModifiedAt(new \DateTimeImmutable('2025-06-15'));

        $this->assertNotSame($original, $byType);
        $this->assertNotSame($original, $byTags);
        $this->assertNotSame($original, $byScope);
        $this->assertNotSame($original, $byContent);
        $this->assertNotSame($original, $byCreatedAt);
        $this->assertNotSame($original, $byModifiedAt);
    }

    public function testImmutabilityOriginalUnchanged(): void
    {
        $original = MemoryEntry::new(
            type: 'preference',
            content: 'Immutable content',
            scope: 'user',
            tags: ['keep'],
        );

        // Each with*() creates a new instance; verify the original is untouched
        $byType = $original->withType('decision');
        $byTags = $original->withTags(['changed']);
        $byScope = $original->withScope('project');
        $byContent = $original->withContent('Modified content');

        // Original values must remain unchanged
        $this->assertSame('preference', $original->type());
        $this->assertSame(['keep'], $original->tags());
        $this->assertSame('user', $original->scope());
        $this->assertSame('Immutable content', $original->content());

        // Each modified instance reflects only its own change
        $this->assertSame('decision', $byType->type());
        $this->assertSame('user', $byType->scope()); // other fields unchanged
        $this->assertSame('Immutable content', $byType->content());
        $this->assertSame(['keep'], $byType->tags());

        $this->assertSame(['changed'], $byTags->tags());
        $this->assertSame('preference', $byTags->type());
        $this->assertSame('user', $byTags->scope());

        $this->assertSame('project', $byScope->scope());
        $this->assertSame('preference', $byScope->type());
        $this->assertSame('Immutable content', $byScope->content());

        $this->assertSame('Modified content', $byContent->content());
        $this->assertSame('preference', $byContent->type());
        $this->assertSame('user', $byContent->scope());
        $this->assertSame(['keep'], $byContent->tags());
    }

    public function testCreatedAtAndModifiedAtAreSet(): void
    {
        $before = new \DateTimeImmutable();
        $entry = MemoryEntry::new(
            type: 'pattern',
            content: 'Test content',
            scope: 'user',
        );
        $after = new \DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before, $entry->createdAt());
        $this->assertLessThanOrEqual($after, $entry->createdAt());
        $this->assertGreaterThanOrEqual($before, $entry->modifiedAt());
        $this->assertLessThanOrEqual($after, $entry->modifiedAt());
        $this->assertEquals($entry->createdAt(), $entry->modifiedAt());
    }

    public function testIdGeneration(): void
    {
        $entry1 = MemoryEntry::new(
            type: 'pattern',
            content: 'Entry one',
            scope: 'user',
        );

        $entry2 = MemoryEntry::new(
            type: 'pattern',
            content: 'Entry two',
            scope: 'user',
        );

        // Each entry gets a unique ID
        $this->assertNotEmpty($entry1->id());
        $this->assertNotEmpty($entry2->id());
        $this->assertNotSame($entry1->id(), $entry2->id());

        // Explicit ID is respected
        $entry3 = MemoryEntry::new(
            type: 'convention',
            content: 'Entry three',
            scope: 'user',
            id: 'my-custom-id',
        );

        $this->assertSame('my-custom-id', $entry3->id());

        // UUID format validation (32 hex characters grouped as 8-4-4-4-12)
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}[0-9a-f]{4}[0-9a-f]{4}[0-9a-f]{4}[0-9a-f]{12}$/',
            $entry1->id(),
            'Auto-generated ID should be a valid UUID v4 format',
        );
    }

    public function testToArrayReturnsAllFields(): void
    {
        $now = new \DateTimeImmutable('2024-03-15T10:00:00Z');
        $entry = new MemoryEntry(
            id: 'test-array-id',
            type: 'convention',
            tags: ['psr-12', 'strict-types'],
            scope: 'project',
            content: 'Always enable strict types.',
            createdAt: $now,
            modifiedAt: $now,
        );

        $arr = $entry->toArray();

        $this->assertIsArray($arr);
        $this->assertSame('test-array-id', $arr['id']);
        $this->assertSame('convention', $arr['type']);
        $this->assertSame(['psr-12', 'strict-types'], $arr['tags']);
        $this->assertSame('project', $arr['scope']);
        $this->assertSame('Always enable strict types.', $arr['content']);
        $this->assertSame('2024-03-15T10:00:00+00:00', $arr['createdAt']);
        $this->assertSame('2024-03-15T10:00:00+00:00', $arr['modifiedAt']);
    }

    public function testWithGenericUpdatesCorrectField(): void
    {
        $original = MemoryEntry::new(
            type: 'pattern',
            content: 'Original content',
            scope: 'user',
            tags: ['original'],
        );

        $byType = $original->with('type', 'decision');
        $byTags = $original->with('tags', ['new', 'tag']);
        $byScope = $original->with('scope', 'project');
        $byContent = $original->with('content', 'Updated content');
        $byCreatedAt = $original->with('createdAt', new \DateTimeImmutable('2023-01-01'));
        $byModifiedAt = $original->with('modifiedAt', new \DateTimeImmutable('2025-12-31'));

        $this->assertSame('decision', $byType->type());
        $this->assertSame('user', $byType->scope());
        $this->assertNotSame($original, $byType);

        $this->assertSame(['new', 'tag'], $byTags->tags());
        $this->assertSame('pattern', $byTags->type());

        $this->assertSame('project', $byScope->scope());

        $this->assertSame('Updated content', $byContent->content());

        $this->assertSame('2023-01-01', $byCreatedAt->createdAt()->format('Y-m-d'));

        $this->assertSame('2025-12-31', $byModifiedAt->modifiedAt()->format('Y-m-d'));
    }

    public function testWithGenericThrowsOnUnknownField(): void
    {
        $entry = MemoryEntry::new(
            type: 'pattern',
            content: 'Test',
            scope: 'user',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown field: nonexistent');

        $entry->with('nonexistent', 'value');
    }

    public function testWithGenericIdIsIdempotent(): void
    {
        // 'id' is not a writable field via with() since there is no withId()
        // Trying 'id' should throw
        $entry = MemoryEntry::new(
            type: 'pattern',
            content: 'Test',
            scope: 'user',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown field: id');

        $entry->with('id', 'new-id');
    }
}
