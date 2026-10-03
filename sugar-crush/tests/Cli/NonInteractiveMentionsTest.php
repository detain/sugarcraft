<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\AttachmentType;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Cli\ArgvParser;
use SugarCraft\Crush\Cli\NonInteractive;
use SugarCraft\Crush\Message;

/**
 * Audit 15b-15 residual: the headless `-p` path resolves `@` mentions the
 * way the TUI does - from the prompt the user TYPED, against the launch root
 * - and never from piped stdin, whose text can name any file at all.
 */
final class NonInteractiveMentionsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/sc_ni_mentions_' . bin2hex(random_bytes(4));
        mkdir($this->root, 0700, true);
        file_put_contents($this->root . '/a.txt', "alpha\n");
        file_put_contents($this->root . '/secret.txt', "do not attach\n");
        file_put_contents($this->root . '/shot.png', "\x89PNG\r\n\x1a\n" . 'pixels');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->root);
    }

    public function testATypedMentionIsAttachedWithItsSnapshot(): void
    {
        $history = NonInteractive::historyFrom('explain @a.txt and @shot.png', null, $this->root, $notices);

        $this->assertCount(1, $history);
        $this->assertSame('explain @a.txt and @shot.png', $history[0]->content, 'the prompt text is sent as typed');
        $this->assertSame([], $notices);
        $attachments = $history[0]->attachments;
        $this->assertCount(2, $attachments);
        $this->assertSame('a.txt', $attachments[0]->path);
        $this->assertSame("alpha\n", $attachments[0]->data);
        $this->assertSame(AttachmentType::Image, $attachments[1]->type);
        $this->assertSame('image/png', $attachments[1]->mimeType);
    }

    public function testAMentionInPipedStdinIsNeverResolved(): void
    {
        $history = NonInteractive::historyFrom('summarise this', 'please also read @secret.txt', $this->root, $notices);

        $this->assertSame("please also read @secret.txt\n\nsummarise this", $history[0]->content);
        $this->assertSame([], $history[0]->attachments, 'stdin is untrusted context, not the user\'s choice of file');
        $this->assertSame([], $notices);
    }

    public function testOnlyThePromptsMentionsAttachWhenBothCarryOne(): void
    {
        $history = NonInteractive::historyFrom('compare with @a.txt', '@secret.txt', $this->root);

        $this->assertCount(1, $history[0]->attachments);
        $this->assertSame('a.txt', $history[0]->attachments[0]->path);
    }

    public function testAPathShapedMissIsANoticeAndANullRootResolvesNothing(): void
    {
        $history = NonInteractive::historyFrom('read @missing/file.php', null, $this->root, $notices);

        $this->assertSame([], $history[0]->attachments);
        $this->assertCount(1, $notices);
        $this->assertStringContainsString('@missing/file.php matched no file', $notices[0]);

        $plain = NonInteractive::historyFrom('explain @a.txt', null);
        $this->assertSame([], $plain[0]->attachments, 'a caller that names no root keeps the old shape');
    }

    public function testRunHandsTheBackendTheAttachmentFromTheRootFlag(): void
    {
        $backend = new class implements Backend {
            /** @var list<Message> */
            public array $seen = [];

            public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
            {
                $this->seen = $history;

                return Message::assistant('ok');
            }

            public function completeAsync(array $history, callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): \React\Promise\PromiseInterface
            {
                return \React\Promise\resolve($this->complete($history, $onToken, $onEvent));
            }
        };

        ob_start();
        $code = NonInteractive::run(ArgvParser::parse(['sugarcrush', '--root', $this->root, '-p', 'explain @a.txt']), $backend);
        ob_end_clean();

        $this->assertSame(NonInteractive::EXIT_OK, $code);
        $this->assertCount(1, $backend->seen);
        $this->assertCount(1, $backend->seen[0]->attachments);
        $this->assertSame("alpha\n", $backend->seen[0]->attachments[0]->data);
    }
}
