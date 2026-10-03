<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Attachments;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\AttachmentType;
use SugarCraft\Crush\Attachments\FileMentions;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Audit 15b-15: what an `@` in a prompt attaches, what it leaves as prose,
 * and what it tells the user about the difference.
 */
final class FileMentionsTest extends TestCase
{
    use HomeSandboxTrait;

    private const GIF_BYTES = 'GIF89a' . "\x01\x00\x01\x00";

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/sc_mentions_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/src/deep', 0777, true);
        file_put_contents($this->root . '/src/Chat.php', "<?php\n// chat\n");
        file_put_contents($this->root . '/src/Chatter.php', "<?php\n");
        file_put_contents($this->root . '/notes with space.md', '# notes');
        file_put_contents($this->root . '/anim.gif', self::GIF_BYTES);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir((string) $file) : unlink((string) $file);
        }
        rmdir($this->root);
    }

    public function testABareAQuotedAndAnImageMentionAreAttachedWithTheirSnapshots(): void
    {
        $r = FileMentions::resolve('explain @src/Chat.php, @"notes with space.md" and @anim.gif', $this->root);

        $this->assertSame([], $r['notices']);
        $this->assertCount(3, $r['attachments']);
        [$chat, $notes, $gif] = $r['attachments'];
        $this->assertSame('src/Chat.php', $chat->path, 'the trailing comma is sentence punctuation');
        $this->assertSame(AttachmentType::File, $chat->type);
        $this->assertSame("<?php\n// chat\n", $chat->data);
        $this->assertSame('notes with space.md', $notes->path);
        $this->assertSame(AttachmentType::Image, $gif->type);
        $this->assertSame('image/gif', $gif->mimeType);
        $this->assertSame(self::GIF_BYTES, $gif->data);
    }

    public function testProseStaysProse(): void
    {
        $r = FileMentions::resolve('ask @team, or mail me@example.com', $this->root);

        $this->assertSame([], $r['attachments']);
        $this->assertSame([], $r['notices'], 'an @name that is not path-shaped is just a word');
    }

    public function testAPathShapedMentionThatMatchesNothingIsReported(): void
    {
        $r = FileMentions::resolve('see @src/Chta.php', $this->root);

        $this->assertSame([], $r['attachments']);
        $this->assertSame(['@src/Chta.php matched no file, so it was sent as plain text.'], $r['notices']);
    }

    public function testADirectoryAndADuplicateAreNotAttached(): void
    {
        $r = FileMentions::resolve('@src and @src/Chat.php and @./src/Chat.php', $this->root);

        $this->assertCount(1, $r['attachments'], 'the second spelling of the same file is not a second copy');
        $this->assertStringContainsString('@src is a directory', $r['notices'][0]);
    }

    public function testAbsoluteAndHomePathsResolve(): void
    {
        $this->useHomeSandbox($this->root);
        try {
            $r = FileMentions::resolve("@{$this->root}/anim.gif @~/src/Chat.php", '/nonexistent-root');
        } finally {
            $this->restoreHomeSandbox();
        }

        $this->assertCount(2, $r['attachments']);
        $this->assertSame('~/src/Chat.php', $r['attachments'][1]->path, 'shown as the user wrote it');
    }

    public function testABinaryFileIsRefusedAndALongOneTruncatedVisibly(): void
    {
        file_put_contents($this->root . '/blob.bin', "\x00\x01\x02");
        file_put_contents($this->root . '/big.txt', str_repeat('a', FileMentions::TEXT_MAX_BYTES + 10));

        $r = FileMentions::resolve('@blob.bin @big.txt', $this->root);

        $this->assertCount(1, $r['attachments']);
        $this->assertStringContainsString('@blob.bin was not attached: it is neither text nor', $r['notices'][0]);
        $this->assertStringContainsString('@big.txt is 256 KiB; only its first 256 KiB were attached.', $r['notices'][1]);
        $this->assertStringEndsWith('the first 256 KiB are included]', (string) $r['attachments'][0]->data);
    }

    public function testAnOversizedImageIsRefusedNotHalved(): void
    {
        $fh = fopen($this->root . '/huge.png', 'wb');
        fwrite($fh, "\x89PNG\r\n\x1a\n");
        ftruncate($fh, FileMentions::IMAGE_MAX_BYTES + 1);
        fclose($fh);

        $r = FileMentions::resolve('@huge.png', $this->root);

        $this->assertSame([], $r['attachments']);
        $this->assertStringContainsString('over the 5 MiB limit', $r['notices'][0]);
    }

    public function testOnePromptAttachesAtMostTheCap(): void
    {
        $words = [];
        for ($i = 0; $i < FileMentions::MAX_MENTIONS + 1; $i++) {
            file_put_contents($this->root . "/f{$i}.txt", (string) $i);
            $words[] = "@f{$i}.txt";
        }

        $r = FileMentions::resolve(implode(' ', $words), $this->root);

        $this->assertCount(FileMentions::MAX_MENTIONS, $r['attachments']);
        $this->assertSame(['@f20.txt was not attached: one prompt attaches at most 20 files.'], $r['notices']);
    }

    public function testTheTokenUnderTheCaret(): void
    {
        $this->assertSame(['start' => 5, 'partial' => 'src/C'], FileMentions::tokenAt('read @src/C', 11));
        $this->assertSame(['start' => 0, 'partial' => ''], FileMentions::tokenAt('@', 1));
        $this->assertNull(FileMentions::tokenAt('mail@x', 6), 'not at a word start');
        $this->assertNull(FileMentions::tokenAt('read @src/Chat.php', 9), 'the caret must END the token');
        $this->assertNull(FileMentions::tokenAt('read @src x', 11));
    }

    public function testCompletionBehavesLikeAShell(): void
    {
        $this->assertSame(['path' => 'src/', 'unique' => false], FileMentions::complete('sr', $this->root));
        $this->assertSame(['path' => 'src/Chat', 'unique' => false], FileMentions::complete('src/C', $this->root));
        $this->assertSame(['path' => 'src/Chatter.php', 'unique' => true], FileMentions::complete('src/Chatt', $this->root));
        $this->assertNull(FileMentions::complete('src/Chat', $this->root), 'the common prefix adds nothing');
        $this->assertNull(FileMentions::complete('nope', $this->root));
        $this->assertNull(FileMentions::complete('missing/dir/x', $this->root));
    }

    public function testImagesAreSniffedByContentNotName(): void
    {
        $this->assertSame('image/png', FileMentions::sniffImage("\x89PNG\r\n\x1a\nrest"));
        $this->assertSame('image/jpeg', FileMentions::sniffImage("\xFF\xD8\xFF\xE0"));
        $this->assertSame('image/webp', FileMentions::sniffImage('RIFF' . "\x00\x00\x00\x00" . 'WEBPVP8 '));
        $this->assertNull(FileMentions::sniffImage('not an image'));

        file_put_contents($this->root . '/fake.png', 'plain text pretending');
        $r = FileMentions::resolve('@fake.png', $this->root);
        $this->assertSame(AttachmentType::File, $r['attachments'][0]->type, 'a .png that is text is attached as text');
    }
}
