<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Skills;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Host\TurnController;
use SugarCraft\Crush\Host\WorkspaceContext;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\Skills\SkillMentions;
use SugarCraft\Crush\Skills\SkillRegistry;

/**
 * Roadmap 5.14l: `$name` in a prompt attaches that skill's body for one turn
 * ({@see SkillMentions}), beside the session-scoped Ctrl+S picker and the
 * standing `enabledSkills` key.
 */
final class SkillMentionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/skm-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*/SKILL.md') ?: [] as $file) {
            unlink($file);
            rmdir(\dirname($file));
        }
        @rmdir($this->dir);
    }

    // ── the syntax ─────────────────────────────────────────────────────

    public function testNamesAreDollarTokensOutsideCode(): void
    {
        self::assertSame(
            ['review', 'audit', 'HOME'],
            SkillMentions::names('$review this, then $audit. Also `$inline` and $HOME and $review again'
                . "\n```sh\necho \$fenced\n```"),
        );
        self::assertSame([], SkillMentions::names('costs US$5 and price$x'), 'a $ inside a word is not a mention');
        self::assertSame([], SkillMentions::names('no dollar at all'));
    }

    // ── the registry is the gate ───────────────────────────────────────

    public function testAUserInvocableSkillAttachesItsBodyForTheTurn(): void
    {
        $registry = $this->registry(['review' => "Check every changed line.\n"]);

        $result = SkillMentions::new($registry)->resolve('please $review the diff');

        self::assertSame([], $result['notices']);
        self::assertCount(1, $result['attachments']);
        self::assertSame($this->dir . '/review/SKILL.md', $result['attachments'][0]->path);
        self::assertSame('Check every changed line.', $result['attachments'][0]->data, 'the body, frontmatter stripped');
    }

    public function testShellVariablesAndUnknownNamesAreJustText(): void
    {
        $result = SkillMentions::new($this->registry(['review' => 'x']))->resolve('echo $HOME and $1 and $nope');

        self::assertSame(['attachments' => [], 'notices' => []], $result);
    }

    public function testANamedSkillThatMayNotBeInvokedSaysWhy(): void
    {
        $registry = $this->registry(['hidden' => 'x', 'off' => 'y'], userInvocable: ['hidden' => false]);
        $registry->disable('off');

        $result = SkillMentions::new($registry)->resolve('$hidden and $off');

        self::assertSame([], $result['attachments']);
        self::assertSame([
            '$hidden was not attached: that skill is not user-invocable (user-invocable: false).',
            '$off was not attached: that skill is disabled (disabledSkills).',
        ], $result['notices']);
    }

    public function testOnePromptAttachesAtMostThreeSkills(): void
    {
        $registry = $this->registry(['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D']);

        $result = SkillMentions::new($registry)->resolve('$a $b $c $d');

        self::assertSame(['A', 'B', 'C'], array_map(static fn ($a) => $a->data, $result['attachments']));
        self::assertSame(['$d was not attached: one prompt attaches at most 3 skills.'], $result['notices']);
    }

    public function testArgumentsArriveWhereTheBodyAsksForThem(): void
    {
        $registry = $this->registry(['fix' => 'Fix this: $ARGUMENTS', 'plain' => 'No placeholder.']);

        $result = SkillMentions::new($registry)->resolve('$fix the login timeout, using $plain.');

        self::assertSame('Fix this: the login timeout, using', $result['attachments'][0]->data);
        self::assertSame('No placeholder.', $result['attachments'][1]->data, 'nothing appended: the prompt already says it');
    }

    public function testASkillWhoseFileVanishedIsANoticeNotACrash(): void
    {
        $registry = $this->registry(['gone' => 'x']);
        unlink($this->dir . '/gone/SKILL.md');
        rmdir($this->dir . '/gone');

        $result = SkillMentions::new($registry)->resolve('$gone');

        self::assertSame([], $result['attachments']);
        self::assertStringStartsWith('$gone was not attached: ', $result['notices'][0]);
    }

    public function testTheRegistryLookupHonoursBothFilters(): void
    {
        $registry = $this->registry(['on' => 'x', 'hidden' => 'y', 'off' => 'z'], userInvocable: ['hidden' => false]);
        $registry->disable('off');

        self::assertSame('on', $registry->userInvocable('on')?->name);
        self::assertNull($registry->userInvocable('hidden'));
        self::assertNull($registry->userInvocable('off'));
        self::assertNull($registry->userInvocable('missing'));
    }

    // ── the user's row ─────────────────────────────────────────────────

    public function testTheUserRowCarriesTheSkillOnTheWire(): void
    {
        $registry = $this->registry(['review' => 'Check every changed line.']);
        $turns = TurnController::new();

        [$typed] = $turns->userTurnMessage('$review it', true, $this->dir, null, null, $registry);
        [$expanded] = $turns->userTurnMessage('$review it', false, $this->dir, null, null, $registry);
        [$unwired] = $turns->userTurnMessage('$review it', true, $this->dir, null, null);

        self::assertSame(
            "\$review it\n\n<file path=\"{$this->dir}/review/SKILL.md\">\nCheck every changed line.\n</file>",
            (new UserMessage($typed->content, $typed->attachments))->wireText(),
        );
        self::assertSame([], $expanded->attachments, 'text the user did not type resolves no mention');
        self::assertSame([], $unwired->attachments, 'no registry, no skill mentions');
    }

    public function testTheChatResolvesAgainstItsWorkspacesRegistry(): void
    {
        $chat = new Chat(
            history: [Message::user('hi')],
            projectRoot: $this->dir,
            workspace: WorkspaceContext::new(root: $this->dir, skills: $this->registry(['review' => 'Body.'])),
        );

        [$message, $notices] = (new \ReflectionMethod($chat, 'userTurnMessage'))->invoke($chat, 'run $review now');

        self::assertSame([], $notices);
        self::assertSame(['Body.'], array_map(static fn ($a) => $a->data, $message->attachments));
    }

    // ── helpers ────────────────────────────────────────────────────────

    /**
     * @param array<string, string> $bodies
     * @param array<string, bool> $userInvocable
     */
    // ── lane B (skills-qa F1/F2): the skill names itself ───────────────

    public function testAMentionedSkillCarriesItsNameOnTheAttachment(): void
    {
        $registry = $this->registry(['review' => 'Check every changed line.', 'audit' => 'x']);

        $attachments = SkillMentions::new($registry)->resolve('$review then $audit')['attachments'];

        self::assertSame('review', $attachments[0]->skill, 'the chip says WHICH SKILL.md this is');
        self::assertSame('audit', $attachments[1]->skill);
    }

    public function testTheInvokedSkillNameReadsOnlyUsableSkillResults(): void
    {
        self::assertSame('review', SkillMentions::invokedSkillName(
            new ToolResult('Skill', 'body', arguments: ['name' => 'review']),
        ));
        self::assertNull(SkillMentions::invokedSkillName(
            new ToolResult('Read', 'body', arguments: ['name' => 'review']),
        ), 'only the Skill tool names a skill');
        self::assertNull(SkillMentions::invokedSkillName(
            new ToolResult('Skill', 'body'),
        ), 'arguments without a name say nothing');
        self::assertNull(SkillMentions::invokedSkillName(
            new ToolResult('Skill', 'body', arguments: ['name' => '   ']),
        ), 'blank is not a name');
        self::assertNull(SkillMentions::invokedSkillName(
            new ToolResult('Skill', 'body', arguments: ['name' => 7]),
        ), 'a non-string name is not a name');
    }

    public function testTheInvokedSkillNameIsFoldedToOneInertLine(): void
    {
        $name = SkillMentions::invokedSkillName(
            new ToolResult('Skill', 'body', arguments: ['name' => "re\x1bv\ti\new"]),
        );

        self::assertSame('re v i ew', $name, 'control characters collapse to spaces');
        self::assertNull(SkillMentions::invokedSkillName(
            new ToolResult('Skill', 'body', arguments: ['name' => str_repeat('x', 121)]),
        ), 'a 121-char blob is not a skill name worth printing');
    }

    /**
     * @return array<string, mixed>
     */
    private function registry(array $bodies, array $userInvocable = []): SkillRegistry
    {
        $skills = [];
        foreach ($bodies as $name => $body) {
            mkdir($this->dir . '/' . $name, 0o700, true);
            $path = $this->dir . '/' . $name . '/SKILL.md';
            $front = "---\nname: {$name}\ndescription: the {$name} skill\n"
                . (($userInvocable[$name] ?? true) ? '' : "user-invocable: false\n")
                . "---\n";
            file_put_contents($path, $front . $body);
            $skills[$name] = Skill::fromFile($path);
        }

        $registry = new SkillRegistry();
        $registry->register($skills);

        return $registry;
    }
}
