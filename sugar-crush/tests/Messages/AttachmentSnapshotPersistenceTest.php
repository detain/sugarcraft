<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Messages;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Attachment;
use SugarCraft\Crush\AttachmentType;
use SugarCraft\Crush\Message;

/**
 * Audit 15b-15: an attachment's snapshot is what the wire is built from, so a
 * resumed session must get it back byte for byte - including an image's binary
 * bytes - while a path-only row from before snapshots persists exactly as it
 * always did.
 */
final class AttachmentSnapshotPersistenceTest extends TestCase
{
    private const PIXELS = "\x89PNG\r\n\x1a\n\x00\xff\xfe";

    public function testASnapshotSurvivesTheTranscriptRoundTrip(): void
    {
        $original = Message::user('look', 1_700_000_000)
            ->attachFile('src/a.php', "<?php // caf\xc3\xa9\n")
            ->attachImage('shot.png', self::PIXELS, 'image/png');

        $json = json_encode($original, JSON_THROW_ON_ERROR);
        $revived = Message::fromArray(json_decode($json, true));

        $this->assertEquals($original->attachments, $revived->attachments);
        $this->assertSame(self::PIXELS, $revived->attachments[1]->data, 'binary bytes survive via base64');
        $this->assertSame('image/png', $revived->attachments[1]->mimeType);
    }

    public function testAPathOnlyRowKeepsItsOldShape(): void
    {
        $row = Message::user('x')->attachFile('/tmp/a.txt')->jsonSerialize();

        $this->assertSame([['path' => '/tmp/a.txt', 'type' => 'File']], $row['attachments']);
    }

    public function testACorruptSnapshotDegradesToPathOnly(): void
    {
        $revived = Message::fromArray([
            'role' => 'user',
            'content' => 'x',
            'attachments' => [['path' => 'a.png', 'type' => 'Image', 'dataBase64' => '!!not base64!!', 'mimeType' => 7]],
        ]);

        $this->assertEquals([new Attachment('a.png', AttachmentType::Image)], $revived->attachments);
    }

    public function testTheNoticeIsTransportNotTranscript(): void
    {
        $row = Message::assistant('x')->withAttachmentNotice('Image a.png was not sent')->jsonSerialize();

        $this->assertArrayNotHasKey('attachmentNotice', $row, 'the notice row it becomes is what persists');
        $this->assertNull(Message::assistant('x')->withAttachmentNotice('')->attachmentNotice, 'empty means none');
    }
}
