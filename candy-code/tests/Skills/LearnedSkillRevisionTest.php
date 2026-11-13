<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Skills;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Skills\LearnedSkillRevision;

final class LearnedSkillRevisionTest extends TestCase
{
    public function testNewCarriesEveryField(): void
    {
        $rev = LearnedSkillRevision::new('deploy', 3, '/tmp/skills/deploy.r3.md', 1024);

        self::assertSame('deploy', $rev->name);
        self::assertSame(3, $rev->number);
        self::assertSame('/tmp/skills/deploy.r3.md', $rev->path);
        self::assertSame(1024, $rev->bytes);
    }

    public function testNewEqualsConstructor(): void
    {
        self::assertEquals(new LearnedSkillRevision('a', 1, '/p', 0), LearnedSkillRevision::new('a', 1, '/p', 0));
    }

    public function testIsReadonly(): void
    {
        $rev = LearnedSkillRevision::new('a', 1, '/p', 0);

        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line */
        $rev->number = 2;
    }
}
