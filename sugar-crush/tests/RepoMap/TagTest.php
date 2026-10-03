<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\RepoMap;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\RepoMap\Tag;

final class TagTest extends TestCase
{
    public function testDefinitionAndReferenceFactories(): void
    {
        $def = Tag::definition('src/A.php', 4, 'run', Tag::TYPE_METHOD, 'A');
        $ref = Tag::reference('src/B.php', 9, 'run');

        self::assertTrue($def->isDefinition());
        self::assertSame(['src/A.php', 4, 'run', 'def', 'method', 'A'], [$def->relPath, $def->line, $def->name, $def->kind, $def->type, $def->scope]);
        self::assertFalse($ref->isDefinition());
        self::assertSame([Tag::KIND_REFERENCE, Tag::TYPE_REFERENCE, ''], [$ref->kind, $ref->type, $ref->scope]);
    }

    public function testArrayRoundTrip(): void
    {
        $tag = Tag::new('x.php', 2, 'X', Tag::KIND_DEFINITION, Tag::TYPE_ENUM);

        self::assertEquals($tag, Tag::fromArray($tag->toArray()));
        self::assertEquals($tag, Tag::fromArray(['path' => 'x.php', 'line' => '2', 'name' => 'X', 'kind' => 'def', 'type' => 'enum']));
    }

    /** @return iterable<string, array{string,int,string,string,string}> */
    public static function invalid(): iterable
    {
        yield 'empty name' => ['a.php', 1, '', Tag::KIND_REFERENCE, Tag::TYPE_REFERENCE];
        yield 'line zero' => ['a.php', 0, 'A', Tag::KIND_REFERENCE, Tag::TYPE_REFERENCE];
        yield 'unknown kind' => ['a.php', 1, 'A', 'decl', Tag::TYPE_CLASS];
        yield 'unknown type' => ['a.php', 1, 'A', Tag::KIND_DEFINITION, 'macro'];
        yield 'definition typed ref' => ['a.php', 1, 'A', Tag::KIND_DEFINITION, Tag::TYPE_REFERENCE];
        yield 'reference typed class' => ['a.php', 1, 'A', Tag::KIND_REFERENCE, Tag::TYPE_CLASS];
    }

    #[DataProvider('invalid')]
    public function testInvalidTagsAreRefused(string $path, int $line, string $name, string $kind, string $type): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Tag::new($path, $line, $name, $kind, $type);
    }
}
