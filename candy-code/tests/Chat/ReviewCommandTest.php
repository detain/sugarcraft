<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Attachments\ContextMentions;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\ReviewCommand;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * Roadmap GIT-1: `/review [ref]` and `/security-review [ref]` send canned
 * review prompts as turns, closing on the `@diff` mention so the scanner —
 * not the command — attaches the change set.
 */
final class ReviewCommandTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-review-' . bin2hex(random_bytes(6));
        $this->useHomeSandbox($this->sandbox . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        self::removeTree($this->sandbox);
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            self::removeTree($path . '/' . $entry);
        }
        rmdir($path);
    }

    private function submit(string $draft): Chat
    {
        $chat = (new Chat(inputBuf: $draft, backend: new EchoBackend()))->withSize(100, 30);
        [$next] = $chat->update(new KeyMsg(KeyType::Enter));

        return $next;
    }

    /** @return list<string> */
    private static function userPrompts(Chat $chat): array
    {
        return array_values(array_map(
            static fn(Message $m): string => $m->content,
            array_filter($chat->history, static fn(Message $m): bool => $m->role === Role::User),
        ));
    }

    public function testReviewStartsATurnClosingOnTheDiffMention(): void
    {
        $next = $this->submit('/review');

        self::assertTrue($next->inFlight, '/review starts a turn like /init');
        $prompts = self::userPrompts($next);
        self::assertSame([ReviewCommand::prompt()], $prompts);
        self::assertStringEndsWith(ReviewCommand::DIFF_MENTION, $prompts[0]);
        self::assertSame('', $next->inputBuf);
    }

    public function testSecurityReviewSendsTheThreatBody(): void
    {
        $next = $this->submit('/security-review');

        self::assertTrue($next->inFlight);
        $prompts = self::userPrompts($next);
        self::assertSame([ReviewCommand::prompt('', true)], $prompts);
        self::assertStringContainsString('security review', $prompts[0]);
        self::assertStringEndsWith(ReviewCommand::DIFF_MENTION, $prompts[0]);
    }

    public function testTheRefScopesTheClosingMention(): void
    {
        $next = $this->submit('/review main..HEAD');

        $prompts = self::userPrompts($next);
        self::assertCount(1, $prompts);
        self::assertStringEndsWith('@diff:main..HEAD', $prompts[0]);
    }

    public function testARefTheScannerWouldRefuseStartsNoTurn(): void
    {
        // Leading dash: the scanner's pattern refuses it so a ref can never
        // read as an option; the command refuses with the same pattern, one
        // layer earlier, and answers loudly instead of reviewing nothing.
        $next = $this->submit('/review --uploadpack');

        self::assertFalse($next->inFlight, 'a bad ref must not begin a turn');
        $last = $next->history[count($next->history) - 1];
        self::assertSame(Role::Assistant, $last->role);
        self::assertStringContainsString('is not a git revision', $last->content);
    }

    public function testBothPromptsMandateReportNotEdit(): void
    {
        // The mandate is the product law of the command, not decoration:
        // it rides in the constants so every prompt carries it.
        self::assertStringContainsString('Report, do not edit', ReviewCommand::REVIEW_PROMPT);
        self::assertStringContainsString('Report, do not edit', ReviewCommand::SECURITY_PROMPT);
        self::assertStringEndsWith('%s', rtrim(ReviewCommand::REVIEW_PROMPT));
        self::assertStringEndsWith('%s', rtrim(ReviewCommand::SECURITY_PROMPT));
    }

    public function testIsDiffRefAgreesWithTheScannerPattern(): void
    {
        self::assertTrue(ContextMentions::isDiffRef('HEAD'));
        self::assertTrue(ContextMentions::isDiffRef('main..HEAD'));
        self::assertTrue(ContextMentions::isDiffRef('0a9b8c7'));
        self::assertFalse(ContextMentions::isDiffRef(''), 'an empty ref never reaches this call, but the pattern rejects it anyway');
        self::assertFalse(ContextMentions::isDiffRef('-x'), 'a leading dash could read as an option');
        self::assertFalse(ContextMentions::isDiffRef('two words'), 'a spaced ref is not one revision');
    }
}
