<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Host\WorkspaceContext;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillMentions;
use SugarCraft\Crush\Skills\SkillRegistry;

/**
 * Roadmap 5.14l tail: `Tab` completes a `$skill` mention the caret ends, the
 * way it completes an `@file` path — from the skills a user may invoke, read
 * off the workspace's registry. A `$` word no such name starts with leaves Tab
 * to whatever else wants it.
 */
final class SkillMentionTabCompletionTest extends TestCase
{
    public function testTheTokenEndsAtTheCaretAfterWhitespaceAndOutsideCode(): void
    {
        $this->assertSame(['start' => 7, 'partial' => 'sec'], SkillMentions::tokenAt('review $sec', 11));
        $this->assertSame(['start' => 0, 'partial' => ''], SkillMentions::tokenAt('$', 1));
        $this->assertNull(SkillMentions::tokenAt('review $sec more', 9), 'mid-token');
        $this->assertNull(SkillMentions::tokenAt('cost$sec', 8), 'a $ glued to a word is not a mention');
        $this->assertNull(SkillMentions::tokenAt('run `echo $sec', 14), 'inside a code span');
        $this->assertSame(['start' => 13, 'partial' => 'a'], SkillMentions::tokenAt('run `echo x` $a', 15));
    }

    public function testCompletionIsUniqueWholeOrTheCommonPrefixOfUserInvocableNames(): void
    {
        $registry = self::registry(['security-audit', 'security-review', 'deploy', 'hidden' => false]);

        $this->assertSame(['name' => 'deploy', 'unique' => true], SkillMentions::complete('de', $registry));
        $this->assertSame(['name' => 'security-', 'unique' => false], SkillMentions::complete('s', $registry));
        $this->assertNull(SkillMentions::complete('security-', $registry), 'the common prefix adds nothing');
        $this->assertNull(SkillMentions::complete('hid', $registry), 'not user-invocable');
        $this->assertNull(SkillMentions::complete('HOME', $registry));
        $this->assertSame(['deploy', 'security-audit', 'security-review'], SkillMentions::completions('', $registry));
    }

    public function testTabCompletesTheMentionInTheDraft(): void
    {
        $chat = $this->chat('review $security-a', self::registry(['security-audit', 'security-review']));
        $this->assertTrue($chat->mentionOwnsTab());

        [$next] = $chat->update(new KeyMsg(KeyType::Tab));

        $this->assertSame('review $security-audit ', $next->inputBuf);
    }

    public function testSeveralMatchesCompleteToTheirCommonPrefix(): void
    {
        [$next] = $this->chat('$s', self::registry(['security-audit', 'security-review']))->update(new KeyMsg(KeyType::Tab));

        $this->assertSame('$security-', $next->inputBuf);
    }

    public function testADollarWordNoSkillStartsWithLeavesTabAlone(): void
    {
        $registry = self::registry(['security-audit']);

        $this->assertFalse($this->chat('echo $HOME', $registry)->mentionOwnsTab());
        $this->assertFalse((new Chat(inputBuf: 'review $sec', backend: new EchoBackend()))->mentionOwnsTab(), 'no registry, no completion');
    }

    private function chat(string $draft, SkillRegistry $registry): Chat
    {
        return (new Chat(inputBuf: $draft, backend: new EchoBackend(), workspace: WorkspaceContext::new(skills: $registry)))
            ->withSize(100, 30);
    }

    /** @param array<int|string, string|bool> $names name, or name => user-invocable */
    private static function registry(array $names): SkillRegistry
    {
        $skills = [];
        foreach ($names as $key => $value) {
            [$name, $invocable] = \is_string($key) ? [$key, (bool) $value] : [(string) $value, true];
            $skills[$name] = Skill::parse(
                "---\nname: {$name}\ndescription: the {$name} skill\n" . ($invocable ? '' : "user-invocable: false\n") . "---\nbody",
                $name,
            );
        }
        $registry = new SkillRegistry();
        $registry->register($skills);

        return $registry;
    }
}
