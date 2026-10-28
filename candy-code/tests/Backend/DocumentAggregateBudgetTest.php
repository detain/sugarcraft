<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Message;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Providers\EmbeddingsRequest;
use SugarCraft\Code\Providers\EmbeddingsResponse;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-CTX-6.b: document bytes are a request-wide law. AttachmentEncoding
 * re-encodes every still-visible document on every later request, so two large
 * PDFs attached on different turns used to push every subsequent request over
 * the wire's per-request ceiling — a refusal each turn from then on. The
 * budget in {@see EngineBackend::DOCUMENT_BASE64_BUDGET} keeps the newest
 * documents and withholds older ones that no longer fit: the row survives with
 * a one-line note for the model, and one notice per turn names what to
 * re-attach.
 */
final class DocumentAggregateBudgetTest extends TestCase
{
    use HomeSandboxTrait;

    private const TINY_PNG = "\x89PNG\r\n\x1a\n" . 'pixel-bytes';

    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir() . '/sc_eb_attach_' . bin2hex(random_bytes(4));
        $this->useHomeSandbox($this->home);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        @rmdir($this->home);
        parent::tearDown();
    }

    /**
     * Raw bytes whose base64 encoding costs just over half the budget: any
     * two of them together are over the line, either one alone rides.
     */
    private static function overHalfBytes(): string
    {
        $budget = EngineBackend::DOCUMENT_BASE64_BUDGET;

        return self::pdfWith(intdiv($budget, 8) * 3 + 4);
    }

    private static function smallBytes(): string
    {
        return self::pdfWith(4096);
    }

    /** A PDF-prefixed blob of exactly $raw bytes. */
    private static function pdfWith(int $raw): string
    {
        return substr(self::PDF_HEAD . str_repeat('A', $raw), 0, $raw);
    }

    private const PDF_HEAD = '%PDF-1.4' . "\n";

    public function testTheBudgetIsThirtyMebibytesOfBase64(): void
    {
        // The 32 MiB per-request ceiling with room for one worst-case 20 MiB
        // PDF (26.7 MiB encoded) plus the rest of the turn.
        $this->assertSame(30 * 1024 * 1024, EngineBackend::DOCUMENT_BASE64_BUDGET);
    }

    public function testTwoDocumentsOverTheBudgetWithholdTheOlderOne(): void
    {
        $provider = self::recordingProvider(vision: true, documents: true);
        $older = Message::user('the first spec')->attachDocument('old.pdf', self::overHalfBytes(), 'application/pdf');
        $newer = Message::user('the second spec')->attachDocument('new.pdf', self::overHalfBytes(), 'application/pdf');

        $reply = EngineBackend::new($provider, 'claude-sonnet-4-6')
            ->complete([$older, Message::assistant('noted'), $newer]);

        $this->assertSame([], $provider->users()[0]->documents(), 'the older document no longer fits the request');
        $this->assertCount(1, $provider->users()[1]->documents(), 'the newest document always rides');
        $this->assertStringContainsString(
            '[Document attachment old.pdf was withheld; re-attach it to resend it.]',
            $provider->users()[0]->content(),
            'the model is told the document is withheld, not that it vanished',
        );
        $this->assertStringNotContainsString('AAAA', $provider->users()[0]->content(), 'withheld means named, not inlined');
        $this->assertNotNull($reply->attachmentNotice);
        $this->assertStringContainsString('old.pdf', $reply->attachmentNotice);
        $this->assertStringContainsString('re-attach', $reply->attachmentNotice);
        $this->assertSame(
            1,
            substr_count((string) $reply->attachmentNotice, 'Documents withheld'),
            'the notice is one sentence per turn — not one sentence per withheld document or row',
        );
    }

    public function testTwoDocumentsUnderTheBudgetBothRide(): void
    {
        $provider = self::recordingProvider(vision: true, documents: true);
        $older = Message::user('one')->attachDocument('a.pdf', self::smallBytes(), 'application/pdf');
        $newer = Message::user('two')->attachDocument('b.pdf', self::smallBytes(), 'application/pdf');

        $reply = EngineBackend::new($provider, 'm')
            ->complete([$older, Message::assistant('ok'), $newer]);

        $this->assertCount(1, $provider->users()[0]->documents(), 'an in-budget session sends every document');
        $this->assertCount(1, $provider->users()[1]->documents());
        $this->assertNull($reply->attachmentNotice, 'nothing was withheld, so nothing is reported');
    }

    public function testTheBudgetSpendsNewestFirstAcrossRows(): void
    {
        $provider = self::recordingProvider(vision: true, documents: true);
        $first = Message::user('first')->attachDocument('one.pdf', self::overHalfBytes(), 'application/pdf');
        $second = Message::user('second')->attachDocument('two.pdf', self::smallBytes(), 'application/pdf');
        $third = Message::user('third')->attachDocument('three.pdf', self::overHalfBytes(), 'application/pdf');

        EngineBackend::new($provider, 'm')->complete([
            $first, Message::assistant('a'), $second, Message::assistant('b'), $third,
        ]);

        // Newest (three) fits; second (small) still fits inside what remains;
        // oldest (one) is over — two just-over-half documents plus anything
        // else aboard exceed the budget, and the oldest pays for it.
        $this->assertSame([], $provider->users()[0]->documents(), 'the oldest document is the one withheld');
        $this->assertCount(1, $provider->users()[1]->documents());
        $this->assertCount(1, $provider->users()[2]->documents());
    }

    public function testTheNoticeCoversRowsOlderThanTheCurrentTurn(): void
    {
        $provider = self::recordingProvider(vision: true, documents: true);
        $older = Message::user('the big one')->attachDocument('manual.pdf', self::overHalfBytes(), 'application/pdf');
        $current = Message::user('what did the manual say?')->attachDocument('brief.pdf', self::overHalfBytes(), 'application/pdf');

        $reply = EngineBackend::new($provider, 'm')->complete([$older, Message::assistant('read'), $current]);

        $this->assertNotNull($reply->attachmentNotice, 'the withholding is this request\'s problem even when the document is old');
        $this->assertStringContainsString('manual.pdf', $reply->attachmentNotice);
        $this->assertStringNotContainsString('brief.pdf', $reply->attachmentNotice, 'only withheld documents are named');
    }

    /**
     * C-CTX-6.b review pin: the notice composes exactly once per turn. Two
     * withheld rows must produce ONE sentence naming both — a per-notice or
     * per-row emission refactor would compose 'Documents withheld' twice and
     * read like two refusals stacked, which is not the design.
     */
    public function testTheWithholdNoticeIsComposedOncePerTurnAcrossRows(): void
    {
        $provider = self::recordingProvider(vision: true, documents: true);
        $first = Message::user('the architecture doc')->attachDocument('arch.pdf', self::overHalfBytes(), 'application/pdf');
        $second = Message::user('the operations doc')->attachDocument('ops.pdf', self::overHalfBytes(), 'application/pdf');
        $third = Message::user('the latest doc')->attachDocument('latest.pdf', self::overHalfBytes(), 'application/pdf');

        $reply = EngineBackend::new($provider, 'm')->complete([
            $first, Message::assistant('a'), $second, Message::assistant('b'), $third,
        ]);

        $this->assertSame([], $provider->users()[0]->documents(), 'the two older documents no longer fit');
        $this->assertSame([], $provider->users()[1]->documents());
        $this->assertCount(1, $provider->users()[2]->documents(), 'the newest document rides');

        $notice = (string) $reply->attachmentNotice;
        $this->assertStringContainsString('arch.pdf', $notice);
        $this->assertStringContainsString('ops.pdf', $notice);
        $this->assertStringNotContainsString('latest.pdf', $notice, 'a document that rode is never named as withheld');
        $this->assertSame(1, substr_count($notice, 'Documents withheld'), 'two withheld rows, one notice — a row-scoped emission would compose twice');
        $this->assertSame(1, substr_count($notice, 're-attach a withheld'), 'one re-attach advice per turn, not one per withheld document');
    }

    /**
     * MULTI-withhold inside one row: with three just-over-half documents on
     * the same row the two oldest are withheld and the newest rides, and both
     * withheld names share the ONE sentence (implode), not two notices.
     */
    public function testTwoWithheldDocumentsOnOneRowShareOneSentence(): void
    {
        $provider = self::recordingProvider(vision: true, documents: true);
        $row = Message::user('three big specs')
            ->attachDocument('a.pdf', self::overHalfBytes(), 'application/pdf')
            ->attachDocument('b.pdf', self::overHalfBytes(), 'application/pdf')
            ->attachDocument('c.pdf', self::overHalfBytes(), 'application/pdf');

        $reply = EngineBackend::new($provider, 'm')->complete([$row]);

        $this->assertCount(1, $provider->users()[0]->documents(), 'only the newest document on the row rides');
        $notice = (string) $reply->attachmentNotice;
        $this->assertStringContainsString('a.pdf', $notice);
        $this->assertStringContainsString('b.pdf', $notice, 'the row withholds its two older documents');
        $this->assertStringNotContainsString('c.pdf', $notice);
        $this->assertSame(1, substr_count($notice, 'Documents withheld'), 'both withheld names ride one sentence, not two notices');
    }

    public function testOnlyDocumentsSpendTheDocumentBudget(): void
    {
        $provider = self::recordingProvider(vision: true, documents: true);
        $withImage = Message::user('look')->attachImage('shot.png', self::TINY_PNG, 'image/png');
        $doc = Message::user('and read')->attachDocument('spec.pdf', self::smallBytes(), 'application/pdf');

        $reply = EngineBackend::new($provider, 'm')->complete([$withImage, Message::assistant('seen'), $doc]);

        $this->assertCount(1, $provider->users()[1]->documents(), 'the image did not eat the document budget');
        $this->assertCount(1, $provider->users()[0]->images());
        $this->assertNull($reply->attachmentNotice);
    }

    public function testASingleDocumentOverTheWholeBudgetIsWithheld(): void
    {
        $provider = self::recordingProvider(vision: true, documents: true);
        $huge = Message::user('the monster')
            ->attachDocument('huge.pdf', self::pdfWith(EngineBackend::DOCUMENT_BASE64_BUDGET), 'application/pdf');

        $reply = EngineBackend::new($provider, 'm')->complete([$huge]);

        $this->assertSame([], $provider->users()[0]->documents(), 'a document no request could ever carry is withheld, not shipped into a refusal');
        $this->assertStringContainsString('[Document attachment huge.pdf was withheld', $provider->users()[0]->content());
        $this->assertNotNull($reply->attachmentNotice);
        $this->assertStringContainsString('huge.pdf', $reply->attachmentNotice);
    }

    public function testTheCapabilityGateIsUntouchedByTheBudget(): void
    {
        $provider = self::recordingProvider(vision: true, documents: false);
        $older = Message::user('one')->attachDocument('old.pdf', self::overHalfBytes(), 'application/pdf');
        $newer = Message::user('two')->attachDocument('new.pdf', self::overHalfBytes(), 'application/pdf');

        $reply = EngineBackend::new($provider, 'gpt-4o')->complete([$older, Message::assistant('ok'), $newer]);

        $this->assertSame([], $provider->users()[0]->documents());
        $this->assertSame([], $provider->users()[1]->documents());
        $this->assertNotNull($reply->attachmentNotice);
        $this->assertStringContainsString('does not accept documents', $reply->attachmentNotice);
        $this->assertStringNotContainsString('re-attach', $reply->attachmentNotice, 'an incapable model gets capability advice, not budget advice');
    }

    public function testADocumentOnlyRequestAsksTheProviderExactlyOnce(): void
    {
        $provider = self::recordingProvider(vision: true, documents: true);
        $a = Message::user('one')->attachDocument('a.pdf', self::smallBytes(), 'application/pdf');
        $b = Message::user('two')->attachDocument('b.pdf', self::smallBytes(), 'application/pdf');

        EngineBackend::new($provider, 'm')->complete([$a, Message::assistant('ok'), $b]);

        $this->assertSame(1, $provider->documentQueries(), 'the budget pre-pass seeds the same memo the row walk reads');
    }

    private static function recordingProvider(bool $vision, bool $documents = false): ProviderInterface
    {
        return new class ($vision, $documents) implements ProviderInterface {
            /** @var list<UserMessage> */
            private array $users = [];

            private int $visionQueries = 0;

            private int $documentQueries = 0;

            public function __construct(private readonly bool $vision, private readonly bool $documents = false) {}

            /** @return list<UserMessage> */
            public function users(): array
            {
                return $this->users;
            }

            public function lastUser(): ?UserMessage
            {
                return $this->users === [] ? null : $this->users[array_key_last($this->users)];
            }

            public function visionQueries(): int
            {
                return $this->visionQueries;
            }

            public function documentQueries(): int
            {
                return $this->documentQueries;
            }

            public function name(): string
            {
                return 'recording-double';
            }

            public function supportsStreaming(): bool
            {
                return false;
            }

            public function supportsFunctionCalling(): bool
            {
                return false;
            }

            public function supportsDocuments(): bool
            {
                $this->documentQueries++;

                return $this->documents;
            }

            public function supportsVision(): bool
            {
                $this->visionQueries++;

                return $this->vision;
            }

            public function supportsJsonSchema(): bool
            {
                return false;
            }

            public function contextWindow(): int
            {
                return 100_000;
            }

            public function costPer1kTokens(string $model, string $direction): ?float
            {
                return 0.0;
            }

            public function complete(CompleteRequest $request): CompleteResponse
            {
                $this->users = array_values(array_filter(
                    $request->messages,
                    // The `<turn-context>` row (step 1.A-1) is harness metadata, not a user turn.
                    static fn (mixed $m): bool => $m instanceof UserMessage && !\SugarCraft\Code\Context\TurnContextBlock::isTurnContext($m),
                ));

                return new CompleteResponse(content: 'ok');
            }

            public function completeStream(CompleteRequest $request): \Generator
            {
                yield $this->complete($request);
            }

            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                throw new \LogicException('unused');
            }
        };
    }
}
