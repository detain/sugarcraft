<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Skills;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillFrontmatter;

/**
 * @see SkillFrontmatter — the typed SKILL.md frontmatter boundary (audit 15d-01).
 */
final class SkillFrontmatterTest extends TestCase
{
    public function testANullBlockIsAllDefaults(): void
    {
        $meta = SkillFrontmatter::fromParsed(null, 'demo');

        $this->assertSame('Skill: demo', $meta->description);
        $this->assertTrue($meta->userInvocable);
        $this->assertFalse($meta->disableModelInvocation);
        $this->assertNull($meta->allowedTools);
        $this->assertNull($meta->disallowedTools);
        $this->assertNull($meta->model);
        $this->assertSame('medium', $meta->effort);
        $this->assertSame('thread', $meta->context);
        $this->assertSame([], $meta->paths);
    }

    public function testAScalarPathsIsAOneElementList(): void
    {
        $this->assertSame(['src/**/*.php'], SkillFrontmatter::fromParsed(['paths' => 'src/**/*.php'], 'x')->paths);
    }

    public function testAToolListIsJoinedIntoTheCommaSeparatedForm(): void
    {
        $meta = SkillFrontmatter::fromParsed(['allowed-tools' => ['Read', 'Grep'], 'disallowed-tools' => 'Bash'], 'x');

        $this->assertSame('Read, Grep', $meta->allowedTools);
        $this->assertSame('Bash', $meta->disallowedTools);
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function booleanProvider(): iterable
    {
        yield 'true' => [true, true];
        yield 'false' => [false, false];
        yield 'yes' => ['yes', true];
        yield 'no' => ['no', false];
        yield 'On' => ['On', true];
        yield 'OFF' => ['OFF', false];
        yield '"false" string' => ['false', false];
    }

    #[DataProvider('booleanProvider')]
    public function testBooleansAcceptYaml11Words(mixed $value, bool $expected): void
    {
        $meta = SkillFrontmatter::fromParsed(['user-invocable' => $value, 'disable-model-invocation' => $value], 'x');

        $this->assertSame($expected, $meta->userInvocable);
        $this->assertSame($expected, $meta->disableModelInvocation);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function refusedProvider(): iterable
    {
        yield 'int description' => [['description' => 42], '"description"'];
        yield 'list context' => [['context' => ['fork']], '"context"'];
        yield 'int model' => [['model' => 4], '"model"'];
        yield 'float effort' => [['effort' => 1.5], '"effort"'];
        yield 'int boolean' => [['user-invocable' => 1], '"user-invocable"'];
        yield 'unknown boolean word' => [['disable-model-invocation' => 'maybe'], '"disable-model-invocation"'];
        yield 'map paths' => [['paths' => ['a' => 'b']], '"paths"'];
        yield 'int paths entry' => [['paths' => ['src/**', 2024]], '"paths[1]"'];
        yield 'nested paths entry' => [['paths' => [['src']]], '"paths[0]"'];
        yield 'int tool entry' => [['allowed-tools' => ['Read', 3]], '"allowed-tools[1]"'];
        yield 'scalar block' => ['just words', 'mapping'];
        yield 'list block' => [['a', 'b'], 'mapping'];
    }

    #[DataProvider('refusedProvider')]
    public function testAWrongTypeIsRefusedNamingTheField(mixed $parsed, string $named): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($named);

        SkillFrontmatter::fromParsed($parsed, 'x');
    }

    public function testSkillParseGoesThroughTheSameReader(): void
    {
        $skill = Skill::parse("---\ndescription: Hidden\nuser-invocable: no\npaths: src/**\n---\nBody.", 'hidden');

        $this->assertFalse($skill->userInvocable);
        $this->assertSame(['src/**'], $skill->paths);
        $this->assertSame('Body.', $skill->content);
    }

    public function testSkillParseRefusesAnUnquotedDateDescription(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"description"');

        Skill::parse("---\ndescription: 2024-01-01\n---\nBody.", 'dated');
    }
}
