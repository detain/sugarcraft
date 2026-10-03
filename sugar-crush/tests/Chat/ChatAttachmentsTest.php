<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\AttachmentType;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\ClipboardImagePastedMsg;
use SugarCraft\Crush\Commands\CommandLoader;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Support\ClipboardImage;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Audit 15b-15, the input half: `@file` mentions become attachments on the
 * submitted user row, Tab completes them, an image dropped or pasted becomes
 * one, Ctrl+V reads the clipboard's image, and the settle arm reports an
 * attachment the provider could not carry.
 */
final class ChatAttachmentsTest extends TestCase
{
    use HomeSandboxTrait;

    private const SCREENSHOT = "\x89PNG\r\n\x1a\n" . 'screenshot';

    private string $sandbox;

    private string $project;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/sc_chat_attach_' . bin2hex(random_bytes(4));
        $this->project = $this->sandbox . '/project';
        mkdir($this->project . '/src', 0700, true);
        file_put_contents($this->project . '/src/a.php', "<?php echo 'a';\n");
        file_put_contents($this->project . '/shot.png', self::SCREENSHOT);
        file_put_contents($this->project . '/my shot.png', self::SCREENSHOT);
        $this->useHomeSandbox($this->sandbox . '/home');
    }

    protected function tearDown(): void
    {
        ClipboardImage::useRunnerForTesting(null);
        ClipboardImage::useCandidatesForTesting(null);
        ClipboardImage::useDirectoryForTesting(null);
        $this->restoreHomeSandbox();
        self::removeTree($this->sandbox);
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() && !$file->isLink() ? rmdir((string) $file) : unlink((string) $file);
        }
        rmdir($dir);
    }

    public function testASubmittedMentionRidesTheUserRowAsASnapshot(): void
    {
        $user = $this->submittedUserRow('explain @src/a.php and @shot.png please');

        $this->assertSame('explain @src/a.php and @shot.png please', $user->content, 'the prompt text is never rewritten');
        $this->assertCount(2, $user->attachments);
        $this->assertSame(AttachmentType::File, $user->attachments[0]->type);
        $this->assertSame("<?php echo 'a';\n", $user->attachments[0]->data);
        $this->assertSame(AttachmentType::Image, $user->attachments[1]->type);
        $this->assertSame(self::SCREENSHOT, $user->attachments[1]->data);
        $this->assertFalse($user->uiOnly, 'it is the agent-visible turn');
    }

    public function testAMentionThatAttachesNothingIsReportedAheadOfThePrompt(): void
    {
        [$chat] = $this->chat('read @src/b.php')->update(new KeyMsg(KeyType::Enter));

        $rows = array_values(array_filter($chat->history, static fn (Message $m): bool => $m->role !== Role::Assistant));
        $userAt = array_key_last(array_filter($rows, static fn (Message $m): bool => $m->role === Role::User));
        $notice = $rows[$userAt - 1];

        $this->assertTrue($notice->uiOnly, 'a report on the user\'s own input never reaches the model');
        $this->assertSame('@src/b.php matched no file, so it was sent as plain text.', $notice->content);
        $this->assertSame([], $rows[$userAt]->attachments);
    }

    public function testAFileBasedCommandsExpansionIsNeverReadForMentions(): void
    {
        $secret = $this->sandbox . '/secret.txt';
        file_put_contents($secret, 'do not attach');
        $dir = $this->sandbox . '/home/.sugar-crush/commands';
        mkdir($dir, 0700, true);
        file_put_contents($dir . '/peek.md', "Please read @{$secret} now.");

        $chat = new Chat(
            history: [],
            backend: new EchoBackend(),
            projectRoot: $this->project,
            commandLoader: new CommandLoader(),
        );
        [$next] = (new \ReflectionMethod(Chat::class, 'mutate'))
            ->invoke($chat, ['inputBuf' => '/peek'])
            ->update(new KeyMsg(KeyType::Enter));

        $users = array_values(array_filter($next->history, static fn (Message $m): bool => $m->role === Role::User && !$m->uiOnly));
        $this->assertCount(1, $users);
        $this->assertStringContainsString("@{$secret}", $users[0]->content, 'fixture: the path stands in the expansion');
        $this->assertSame([], $users[0]->attachments, 'repository-authored text cannot attach a file');
    }

    public function testTheSettleArmReportsAnAttachmentTheProviderCouldNotCarry(): void
    {
        $chat = new Chat(history: [Message::user('look')], backend: new EchoBackend());

        [$next] = $chat->update(new AssistantMsg(Message::assistant('I see text only')->withAttachmentNotice('Image a.png was not sent')));

        $last = $next->history[array_key_last($next->history)];
        $this->assertTrue($last->uiOnly);
        $this->assertSame('Image a.png was not sent', $last->content);
        $this->assertSame('I see text only', $next->history[array_key_last($next->history) - 1]->content);

        [$clean] = $chat->update(new AssistantMsg(Message::assistant('fine')));
        $this->assertSame('fine', $clean->history[array_key_last($clean->history)]->content, 'no notice without the field');
    }

    public function testTabCompletesTheMentionAndYieldsOtherwise(): void
    {
        $chat = $this->chat('look at @src/');
        $this->assertTrue($chat->mentionOwnsTab());

        [$next] = $chat->update(new KeyMsg(KeyType::Tab));

        $this->assertSame('look at @src/a.php ', $next->inputBuf);
        $this->assertSame(mb_strlen('look at @src/a.php '), $next->inputCursorOffset());
        $this->assertFalse($this->chat('no mention here')->mentionOwnsTab(), 'a plain draft leaves Tab to the shell');
        $this->assertFalse($next->mentionOwnsTab(), 'the closing space ends the mention');
    }

    public function testADroppedImagePathBecomesAMention(): void
    {
        foreach ([
            $this->project . '/shot.png' => '@shot.png ',
            "'" . $this->project . "/my shot.png'" => '@"my shot.png" ',
            'file://' . str_replace(' ', '%20', $this->project . '/my shot.png') => '@"my shot.png" ',
            str_replace(' ', '\\ ', $this->project . '/my shot.png') => '@"my shot.png" ',
        ] as $pasted => $expected) {
            [$next] = $this->chat('')->update(new PasteMsg($pasted));

            $this->assertSame($expected, $next->inputBuf, "pasting {$pasted}");
        }
    }

    public function testAPasteThatIsNotAnImagePathStaysText(): void
    {
        $textFile = $this->project . '/src/a.php';

        [$a] = $this->chat('')->update(new PasteMsg($textFile));
        [$b] = $this->chat('')->update(new PasteMsg("two\nlines"));

        $this->assertSame($textFile, $a->inputBuf, 'a text file\'s path is pasted as the path');
        $this->assertSame("two\nlines", $b->inputBuf);
    }

    public function testCtrlVWithNoImageSaysSoAndLeavesTheDraft(): void
    {
        ClipboardImage::useCandidatesForTesting([]);
        ClipboardImage::useDirectoryForTesting($this->sandbox . '/pastes');

        [$next, $cmd] = $this->chat('draft')->update(new KeyMsg(KeyType::Char, 'v', ctrl: true));
        $this->assertNotNull($cmd);
        $answer = $cmd();
        $this->assertInstanceOf(ClipboardImagePastedMsg::class, $answer);
        $this->assertNull($answer->path);

        [$after] = $next->update($answer);

        $this->assertSame('draft', $after->inputBuf);
        $last = $after->history[array_key_last($after->history)];
        $this->assertTrue($last->uiOnly);
        $this->assertStringStartsWith('No image on the clipboard to attach.', $last->content);
    }

    public function testAPastedClipboardImageIsMentionedAtTheCaret(): void
    {
        [$next] = $this->chat('see ')->update(new ClipboardImagePastedMsg($this->project . '/shot.png'));

        $this->assertSame('see @shot.png ', $next->inputBuf);
    }

    public function testTheContextEstimateCountsWhatIsAttached(): void
    {
        $estimate = new \ReflectionMethod(Chat::class, 'estimateTokenCount');
        $chat = $this->chat('');
        $plain = [Message::user('hi')];
        $withFile = [Message::user('hi')->attachFile('a.txt', str_repeat('word ', 4000))];
        $withImage = [Message::user('hi')->attachImage('a.png', self::SCREENSHOT, 'image/png')];

        $base = $estimate->invoke($chat, $plain);
        $this->assertGreaterThan($base + 4000, $estimate->invoke($chat, $withFile), 'an inlined file occupies the window');
        $this->assertSame($base + 1600, $estimate->invoke($chat, $withImage), 'an image counts at the flat estimate');
    }

    private function chat(string $draft): Chat
    {
        return (new Chat(history: [], inputBuf: $draft, backend: new EchoBackend(), projectRoot: $this->project))
            ->withSize(100, 30);
    }

    private function submittedUserRow(string $draft): Message
    {
        [$chat] = $this->chat($draft)->update(new KeyMsg(KeyType::Enter));
        $users = array_values(array_filter($chat->history, static fn (Message $m): bool => $m->role === Role::User && !$m->uiOnly));
        $this->assertCount(1, $users);

        return $users[0];
    }
}
