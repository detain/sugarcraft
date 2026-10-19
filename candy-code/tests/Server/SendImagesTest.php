<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\AttachmentType;
use SugarCraft\Code\Message;
use SugarCraft\Code\Attachments\FileMentions;
use SugarCraft\Code\Protocol\Methods\TurnMethods;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;

/**
 * `session.send`'s `images` (plan_comparison WEB-5b): the base64 paste strip
 * of the web composer, decoded at the door and attached to the prompt that
 * STARTS a turn. What is pinned: the SNIFF is the authority (a declared mime
 * is a claim; non-raster bytes — a data-URL pasted as text, a PDF — never
 * ride), the budgets bound (count at ingress, decoded bytes at the handler),
 * the busy law (queue and steer lanes carry strings, so images arrive only
 * with a turn they start or an interrupt that starts one), and the reload
 * path (the saved row replays the snapshot exactly as the TUI's paste does).
 */
final class SendImagesTest extends TestCase
{
    private ProtocolFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ProtocolFixture::new();
    }

    protected function tearDown(): void
    {
        $this->fixture->tearDown();
    }

    public function testAPastedImageRidesThePromptThatStartsTheTurn(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $bytes = $this->png(64);

        $answer = $client->call('session.send', [
            'sessionId' => $sessionId,
            'text' => 'what is in this screenshot?',
            'images' => [['name' => 'shot.png', 'mime' => 'image/png', 'data' => \base64_encode($bytes)]],
        ]);
        self::assertSame('started', $answer['admitted']);

        $attachments = $this->fixture->backend->userMessages[0]->attachments;
        self::assertCount(1, $attachments, 'the pasted image reaches the turn as one attachment');
        self::assertSame(AttachmentType::Image, $attachments[0]->type);
        self::assertSame($bytes, $attachments[0]->data, 'the bytes ride the wire untouched');
        self::assertSame('image/png', $attachments[0]->mimeType);
        self::assertSame('shot.png', $attachments[0]->path, 'the client name is a chip label, kept as given');

        $this->fixture->backend->settle(Message::assistant('a dialog'));
    }

    public function testTheSniffedBytesAreTheAuthority(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);

        // A GIF claiming to be a PNG: the declared mime is refused rather
        // than a provider being handed a lie for a media_type.
        $gif = 'GIF89a' . \str_repeat('x', 32);
        $mismatch = $client->request('session.send', [
            'sessionId' => $sessionId, 'text' => 'x',
            'images' => [['mime' => 'image/png', 'data' => \base64_encode($gif)]],
        ]);
        self::assertSame('image_mime_mismatch', $mismatch['error']['data']['kind']);

        // The data-URL of a pasted image is TEXT: 'data:image/png;base64,…"
        // is not strict base64, and bytes that decode to a PDF decode to no
        // raster. Both are refused at the door, neither reaches a turn.
        $notBase64 = $client->request('session.send', [
            'sessionId' => $sessionId, 'text' => 'x',
            'images' => [['mime' => 'image/png', 'data' => 'data:image/png;base64,iVBOR']],
        ]);
        self::assertSame('image_not_base64', $notBase64['error']['data']['kind']);

        $pdf = '%PDF-1.7\n%\xc7\xec\x8f\x9d\n1 0 obj';
        $notImage = $client->request('session.send', [
            'sessionId' => $sessionId, 'text' => 'x',
            'images' => [['mime' => 'image/png', 'data' => \base64_encode($pdf)]],
        ]);
        self::assertSame('image_not_decodable', $notImage['error']['data']['kind']);

        // An unnamed PNG gets the sniffed subtype for a chip label.
        $named = $client->call('session.send', [
            'sessionId' => $sessionId, 'text' => 'and this?',
            'images' => [['mime' => 'image/webp', 'data' => \base64_encode('RIFF' . \chr(0x10) . \chr(0) . \chr(0) . \chr(0) . 'WEBPVP8 body')]],
        ]);
        self::assertSame('started', $named['admitted']);
        $this->fixture->backend->settle(Message::assistant('a photo'));
        self::assertSame('pasted-image.webp', $this->fixture->backend->userMessages[0]->attachments[0]->path);
    }

    public function testTheCountIsRefusedAtIngressAndTheBytesAtTheHandler(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $entry = ['mime' => 'image/png', 'data' => \base64_encode($this->png(8))];

        $tooMany = $client->request('session.send', [
            'sessionId' => $sessionId, 'text' => 'x',
            'images' => \array_fill(0, TurnMethods::MAX_IMAGES + 1, $entry),
        ]);
        self::assertSame(-32602, $tooMany['error']['code'] ?? null, 'a fifth image never passes the params schema');

        // Four images of 3 MiB each are individually under the per-image
        // ceiling and together over the 10 MiB total the send carries.
        $big = \base64_encode($this->png(3 * 1024 * 1024));
        $overBudget = $client->request('session.send', [
            'sessionId' => $sessionId, 'text' => 'x',
            'images' => \array_fill(0, TurnMethods::MAX_IMAGES, ['mime' => 'image/png', 'data' => $big]),
        ]);
        self::assertSame('image_budget_exceeded', $overBudget['error']['data']['kind']);

        // One image over the per-image ceiling names that ceiling: the
        // ingress string cap is the exact base64 length of a full 5 MiB
        // decode, so ONE byte more still fits the wire but not the handler.
        $single = $client->request('session.send', [
            'sessionId' => $sessionId, 'text' => 'x',
            'images' => [['mime' => 'image/png', 'data' => \base64_encode($this->png(FileMentions::IMAGE_MAX_BYTES + 1 - 8))]],
        ]);
        self::assertSame('image_too_large', $single['error']['data']['kind']);
    }

    public function testImagesWhileATurnRunsWaitForTheTurnTheyStart(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $first = $client->call('session.send', ['sessionId' => $sessionId, 'text' => 'running']);
        self::assertSame('started', $first['admitted']);
        $image = [['mime' => 'image/png', 'data' => \base64_encode($this->png(8))]];

        foreach (['queue', 'steer'] as $delivery) {
            $refused = $client->request('session.send', ['sessionId' => $sessionId, 'text' => 'look', 'images' => $image, 'delivery' => $delivery]);
            self::assertSame('images_while_busy', $refused['error']['data']['kind'], "the {$delivery} lane carries strings, not images");
        }
        $interrupted = $client->request('session.send', ['sessionId' => $sessionId, 'text' => 'look', 'images' => $image, 'delivery' => 'interrupt']);
        self::assertArrayNotHasKey('error', $interrupted, 'interrupt starts a turn, and the images ride that one');
        self::assertSame($image[0]['data'], \base64_encode($this->fixture->backend->userMessages[1]->attachments[0]->data));
    }

    public function testACommandOrBangTakingNoImagesIsRefusedNotStripped(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $image = [['mime' => 'image/png', 'data' => \base64_encode($this->png(8))]];

        foreach (['/model sonnet', '!ls', '  /compact'] as $text) {
            $refused = $client->request('session.send', ['sessionId' => $sessionId, 'text' => $text, 'images' => $image]);
            self::assertSame('images_with_command', $refused['error']['data']['kind'], "{$text} routes ahead of the prose path; its images would vanish");
        }
    }

    public function testTheSavedRowReplaysTheImageForAReload(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $bytes = $this->png(48);

        $client->call('session.send', [
            'sessionId' => $sessionId, 'text' => 'look',
            'images' => [['name' => 'shot.png', 'mime' => 'image/png', 'data' => \base64_encode($bytes)]],
        ]);
        $this->fixture->backend->settle(Message::assistant('seen'));
        $snapshot = $client->call('session.get', ['sessionId' => $sessionId]);

        $row = null;
        foreach ($snapshot['messages'] as $candidate) {
            if ($candidate['role'] === 'user') {
                $row = $candidate;
            }
        }
        self::assertNotNull($row);
        self::assertSame(\base64_encode($bytes), $row['attachments'][0]['dataBase64'], 'a reload shows the paste the live tab sent, from the same snapshot a resumed TUI session reads');
        self::assertSame('image/png', $row['attachments'][0]['mimeType']);
    }

    /** @return string real PNG magic over $padding filler bytes */
    private function png(int $padding): string
    {
        return "\x89PNG\r\n\x1a\n" . \str_repeat('x', $padding);
    }
}
