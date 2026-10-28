<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\AttachmentType;
use SugarCraft\Code\Message;
use SugarCraft\Code\Protocol\Methods\TurnMethods;
use SugarCraft\Code\Protocol\Params;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;

/**
 * `session.send`'s `documents` (plan_comparison C-WEB-4.e): base64 PDFs
 * uploaded from the web composer, decoded at the door and attached — like the
 * WEB-5b images — only to the prompt that STARTS a turn. What is pinned: the
 * PDF sniff admits real `%PDF-` bytes and refuses anything else, the budgets
 * bound (count at ingress, the SHARED images+documents decoded budget at the
 * handler), the busy law (`documents_while_busy`), the command law
 * (`documents_with_command`), and the reload path (the saved row replays the
 * upload, which matters because documents re-enter context every later turn —
 * the CTX-2-P1 blast radius the page now states).
 */
final class SessionSendDocumentsTest extends TestCase
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

    public function testAnUploadedPdfRidesThePromptThatStartsTheTurn(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $bytes = $this->pdf(64);

        $answer = $client->call('session.send', [
            'sessionId' => $sessionId,
            'text' => 'summarize this paper?',
            'documents' => [['name' => 'paper.pdf', 'mime' => 'application/pdf', 'data' => \base64_encode($bytes)]],
        ]);
        self::assertSame('started', $answer['admitted']);

        $attachments = $this->fixture->backend->userMessages[0]->attachments;
        self::assertCount(1, $attachments, 'the uploaded PDF reaches the turn as one attachment');
        self::assertSame(AttachmentType::Document, $attachments[0]->type, 'it rides as a Document, not an Image');
        self::assertSame($bytes, $attachments[0]->data, 'the bytes ride the wire untouched');
        self::assertSame('application/pdf', $attachments[0]->mimeType);
        self::assertSame('paper.pdf', $attachments[0]->path, 'the client name is a chip label, kept as given');

        $this->fixture->backend->settle(Message::assistant('a summary'));
    }

    public function testTheSniffedBytesAreTheAuthority(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);

        // The data-URL of a pasted document is TEXT: not strict base64.
        $notBase64 = $client->request('session.send', [
            'sessionId' => $sessionId, 'text' => 'x',
            'documents' => [['mime' => 'application/pdf', 'data' => 'data:application/pdf;base64,JVBERi0']],
        ]);
        self::assertSame('document_not_base64', $notBase64['error']['data']['kind']);

        // Real PNG bytes in the documents leg decode to no PDF.
        $notPdf = $client->request('session.send', [
            'sessionId' => $sessionId, 'text' => 'x',
            'documents' => [['mime' => 'application/pdf', 'data' => \base64_encode($this->png(32))]]],
        );
        self::assertSame('document_not_decodable', $notPdf['error']['data']['kind']);

        // The wire mimes enum declares application/pdf only, so a false
        // declaration never reaches the handler; the mismatch leg below it is
        // the same sniff-is-authority guard the images carry, kept live for
        // the day FileMentions::sniffDocument() recognizes a second kind.
        $declared = $client->request('session.send', [
            'sessionId' => $sessionId, 'text' => 'x',
            'documents' => [['mime' => 'application/postscript', 'data' => \base64_encode($this->pdf(8))]]],
        );
        self::assertSame(-32602, $declared['error']['code'] ?? null, 'a mime off the enum dies at the params schema');

        $guard = new \ReflectionMethod(TurnMethods::class, 'decodeDocuments');
        try {
            $guard->invoke(null, Params::of([
                'documents' => [['mime' => 'application/postscript', 'data' => \base64_encode($this->pdf(8))]],
            ]));
            self::fail('the guard leg must refuse the claim, not trust it');
        } catch (\SugarCraft\Code\Protocol\RpcError $refused) {
            self::assertSame('document_mime_mismatch', $refused->kind, 'when reached, the sniff is still the authority');
        }
    }

    public function testTheCountIsRefusedAtIngressAndTheSharedBudgetAtTheHandler(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $entry = ['mime' => 'application/pdf', 'data' => \base64_encode($this->pdf(8))];

        $tooMany = $client->request('session.send', [
            'sessionId' => $sessionId, 'text' => 'x',
            'documents' => \array_fill(0, TurnMethods::MAX_DOCUMENTS + 1, $entry),
        ]);
        self::assertSame(-32602, $tooMany['error']['code'] ?? null, 'a third document never passes the params schema');

        // Two PDFs and one PNG each under their own ceilings, together over
        // the SHARED 11 MiB decoded budget: the leg that did not exist before
        // documents could ride, so it is pinned across both kinds.
        $five = \base64_encode($this->pdf(5 * 1024 * 1024));
        $png = \base64_encode($this->png(3 * 1024 * 1024));
        $overBudget = $client->request('session.send', [
            'sessionId' => $sessionId, 'text' => 'x',
            'images' => [['mime' => 'image/png', 'data' => $png]],
            'documents' => [['mime' => 'application/pdf', 'data' => $five], ['mime' => 'application/pdf', 'data' => $five]],
        ]);
        self::assertSame('attachment_budget_exceeded', $overBudget['error']['data']['kind']);

        // One document at the wire cap decodes to at most budget + 1 byte
        // (the cap is the exact base64 length of the full budget), so the
        // largest lone upload always names the budget at the handler.
        $single = $client->request('session.send', [
            'sessionId' => $sessionId, 'text' => 'x',
            'documents' => [['mime' => 'application/pdf', 'data' => \base64_encode($this->pdf(TurnMethods::MAX_ATTACHMENT_TOTAL_BYTES + 1 - 8))]]],
        );
        self::assertSame('attachment_budget_exceeded', $single['error']['data']['kind']);
    }

    public function testDocumentsWhileATurnRunsWaitForTheTurnTheyStart(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $first = $client->call('session.send', ['sessionId' => $sessionId, 'text' => 'running']);
        self::assertSame('started', $first['admitted']);
        $document = [['mime' => 'application/pdf', 'data' => \base64_encode($this->pdf(8))]];

        foreach (['queue', 'steer'] as $delivery) {
            $refused = $client->request('session.send', ['sessionId' => $sessionId, 'text' => 'read this', 'documents' => $document, 'delivery' => $delivery]);
            self::assertSame('documents_while_busy', $refused['error']['data']['kind'], "the {$delivery} lane carries strings, not documents");
        }
        $interrupted = $client->request('session.send', ['sessionId' => $sessionId, 'text' => 'read this', 'documents' => $document, 'delivery' => 'interrupt']);
        self::assertArrayNotHasKey('error', $interrupted, 'interrupt starts a turn, and the document rides that one');
        self::assertSame($document[0]['data'], \base64_encode($this->fixture->backend->userMessages[1]->attachments[0]->data));
    }

    public function testACommandOrBangTakingNoDocumentsIsRefusedNotStripped(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $document = [['mime' => 'application/pdf', 'data' => \base64_encode($this->pdf(8))]];

        foreach (['/model sonnet', '!ls', '  /compact'] as $text) {
            $refused = $client->request('session.send', ['sessionId' => $sessionId, 'text' => $text, 'documents' => $document]);
            self::assertSame('documents_with_command', $refused['error']['data']['kind'], "{$text} routes ahead of the prose path; its documents would vanish");
        }
    }

    public function testImagesAndDocumentsRideTheSamePromptInOrder(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);

        $answer = $client->call('session.send', [
            'sessionId' => $sessionId, 'text' => 'screenshot and spec',
            'images' => [['mime' => 'image/png', 'data' => \base64_encode($this->png(8))]],
            'documents' => [['mime' => 'application/pdf', 'data' => \base64_encode($this->pdf(8))]],
        ]);
        self::assertSame('started', $answer['admitted']);

        $attachments = $this->fixture->backend->userMessages[0]->attachments;
        self::assertCount(2, $attachments);
        self::assertSame(AttachmentType::Image, $attachments[0]->type, 'images first, documents after — one ordered list');
        self::assertSame(AttachmentType::Document, $attachments[1]->type);
        self::assertSame('pasted-document.pdf', $attachments[1]->path, 'an unnamed upload takes the sniffed subtype');
    }

    public function testTheSavedRowReplaysTheDocumentForAReload(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $bytes = $this->pdf(48);

        $client->call('session.send', [
            'sessionId' => $sessionId, 'text' => 'read this',
            'documents' => [['name' => 'notes.pdf', 'mime' => 'application/pdf', 'data' => \base64_encode($bytes)]],
        ]);
        $this->fixture->backend->settle(Message::assistant('read'));
        $snapshot = $client->call('session.get', ['sessionId' => $sessionId]);

        $row = null;
        foreach ($snapshot['messages'] as $candidate) {
            if ($candidate['role'] === 'user') {
                $row = $candidate;
            }
        }
        self::assertNotNull($row);
        self::assertSame(\base64_encode($bytes), $row['attachments'][0]['dataBase64'], 'a reload shows the upload the live tab sent — and CTX-2-P1 makes it re-enter EVERY later turn from this row');
        self::assertSame('application/pdf', $row['attachments'][0]['mimeType']);
    }

    private function pdf(int $padding): string
    {
        return '%PDF-1.7' . \str_repeat('x', $padding);
    }

    private function png(int $padding): string
    {
        return "\x89PNG\r\n\x1a\n" . \str_repeat('x', $padding);
    }
}
