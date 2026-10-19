<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Code\AttachmentType;
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
 * Audit 15b-15: attachments reach the wire. `EngineBackend::toTypedMessages()`
 * used to build `new UserMessage($msg->content)` and drop every attachment on
 * the floor; it now carries them with `UserMessage::withAttachment()`, and a
 * provider without vision gets each image as a named text placeholder plus a
 * notice that rides home on the reply - in-process and across the fork's
 * result frame.
 */
final class EngineBackendAttachmentsTest extends TestCase
{
    use HomeSandboxTrait;

    private const IMAGE_BYTES = "\x89PNG\r\n\x1a\n" . 'pixel-bytes';

    private const PDF_BYTES = '%PDF-1.4' . "\n" . "\x00\x01\x00\x02" . 'xref body';

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

    public function testAVisionProviderReceivesEveryAttachmentOfTheUserTurn(): void
    {
        $provider = self::recordingProvider(vision: true);
        $prompt = Message::user('look at @a.php and @shot.png')
            ->attachFile('a.php', "<?php echo 1;\n")
            ->attachImage('shot.png', self::IMAGE_BYTES, 'image/png');

        $reply = EngineBackend::new($provider, 'm')->complete([$prompt]);

        $user = $provider->lastUser();
        $this->assertNotNull($user);
        $this->assertCount(2, $user->attachments(), 'both attachments must reach the provider');
        $this->assertSame(AttachmentType::File, $user->attachments()[0]->type);
        $this->assertSame("<?php echo 1;\n", $user->attachments()[0]->data, 'the snapshot rides along');
        $this->assertCount(1, $user->images());
        $this->assertSame(self::IMAGE_BYTES, $user->images()[0]->data);
        $this->assertStringContainsString('<file path="a.php">', $user->wireText(), 'the file is inlined on the wire');
        $this->assertNull($reply->attachmentNotice, 'nothing was withheld, so nothing is reported');
    }

    public function testAProviderWithoutVisionGetsAPlaceholderAndTheUserANotice(): void
    {
        $provider = self::recordingProvider(vision: false);
        $prompt = Message::user('what is in this?')
            ->attachFile('notes.txt', 'remember the milk')
            ->attachImage('/tmp/shot.png', self::IMAGE_BYTES, 'image/png');

        $reply = EngineBackend::new($provider, 'text-model')->complete([$prompt]);

        $user = $provider->lastUser();
        $this->assertNotNull($user);
        $this->assertSame([], $user->images(), 'no image part may reach a provider without vision');
        $this->assertCount(1, $user->attachments(), 'the file still goes, inlined as text');
        $this->assertStringContainsString(
            '[Image attachment /tmp/shot.png was not sent: the active model does not accept images.]',
            $user->content(),
            'the model is told something was attached rather than the image vanishing',
        );
        $this->assertNotNull($reply->attachmentNotice);
        $this->assertStringContainsString('shot.png was not sent', $reply->attachmentNotice);
        $this->assertStringContainsString('recording-double (text-model)', $reply->attachmentNotice);
    }

    public function testOnlyTheTurnsOwnPromptIsReportedNotAnOlderOne(): void
    {
        $provider = self::recordingProvider(vision: false);
        $old = Message::user('earlier')->attachImage('old.png', self::IMAGE_BYTES, 'image/png');

        $reply = EngineBackend::new($provider, 'm')->complete([$old, Message::assistant('seen'), Message::user('and now?')]);

        $this->assertNull($reply->attachmentNotice, 'the older image was reported when it was sent');
        $this->assertStringContainsString('old.png was not sent', $provider->users()[0]->content(), 'but it is still named on the wire');
    }

    public function testATurnWithoutAnImageNeverAsksTheProviderAboutVision(): void
    {
        $provider = self::recordingProvider(vision: false);

        EngineBackend::new($provider, 'm')->complete([Message::user('hi')->attachFile('a.txt', 'x')]);

        $this->assertSame(0, $provider->visionQueries(), 'SGLang answers this from the network; a text turn must not wait on it');
    }

    public function testADocumentCapableProviderReceivesThePdfAndNobodyAsksAboutVision(): void
    {
        $provider = self::recordingProvider(vision: false, documents: true);
        $prompt = Message::user('read the spec')->attachDocument('/tmp/spec.pdf', self::PDF_BYTES, 'application/pdf');

        $reply = EngineBackend::new($provider, 'claude-sonnet-4-6')->complete([$prompt]);

        $user = $provider->lastUser();
        $this->assertNotNull($user);
        $this->assertCount(1, $user->documents(), 'the PDF reaches a document-capable wire whole');
        $this->assertSame(self::PDF_BYTES, $user->documents()[0]->data);
        $this->assertStringNotContainsString('was not sent', $user->content(), 'a carried document is not a withheld one');
        $this->assertStringNotContainsString('%PDF', $user->content(), 'the bytes never ride the prompt text');
        $this->assertSame(0, $provider->visionQueries(), 'a document-only turn must not pay for the vision probe');
        $this->assertSame(1, $provider->documentQueries(), 'memoised once per request batch');
        $this->assertNull($reply->attachmentNotice, 'nothing was withheld, so nothing is reported');
    }

    public function testAProviderWithoutDocumentsGetsAPlaceholderAndTheUserANotice(): void
    {
        $provider = self::recordingProvider(vision: true, documents: false);
        $prompt = Message::user('what is this?')->attachDocument('/tmp/spec.pdf', self::PDF_BYTES, 'application/pdf');

        $reply = EngineBackend::new($provider, 'gpt-4o')->complete([$prompt]);

        $user = $provider->lastUser();
        $this->assertNotNull($user);
        $this->assertSame([], $user->documents(), 'no document part may reach a provider without the capability');
        $this->assertStringContainsString(
            '[Document attachment /tmp/spec.pdf was not sent: the active model does not accept documents.]',
            $user->content(),
        );
        $this->assertStringNotContainsString('%PDF', $user->content(), 'withheld means named, not inlined');
        $this->assertNotNull($reply->attachmentNotice);
        $this->assertStringContainsString('spec.pdf was not sent', $reply->attachmentNotice);
        $this->assertStringContainsString('does not accept documents', $reply->attachmentNotice);
        $this->assertStringContainsString('recording-double (gpt-4o)', $reply->attachmentNotice);
    }

    public function testWithheldImagesAndDocumentsGetTheirOwnSentences(): void
    {
        $provider = self::recordingProvider(vision: false, documents: false);
        $prompt = Message::user('two files')
            ->attachImage('shot.png', self::IMAGE_BYTES, 'image/png')
            ->attachDocument('spec.pdf', self::PDF_BYTES, 'application/pdf');

        $reply = EngineBackend::new($provider, 'm')->complete([$prompt]);

        $this->assertNotNull($reply->attachmentNotice);
        $this->assertStringContainsString('does not accept images', $reply->attachmentNotice);
        $this->assertStringContainsString('does not accept documents', $reply->attachmentNotice);
        // Two sentences, one per capability: fused grammar would hide which
        // model switch fixes which attachment.
        $this->assertSame(1, substr_count($reply->attachmentNotice, 'Switch to a vision-capable model'));
        $this->assertSame(1, substr_count($reply->attachmentNotice, 'Switch to a document-capable model'));
    }

    public function testATurnWithoutADocumentNeverAsksTheProviderAboutDocuments(): void
    {
        $provider = self::recordingProvider(vision: true);

        EngineBackend::new($provider, 'm')->complete([Message::user('hi')->attachImage('a.png', self::IMAGE_BYTES, 'image/png')]);

        $this->assertSame(0, $provider->documentQueries(), 'the same memoised-never rule the vision probe answers to');
    }

    public function testTheNoticeCrossesTheResultFrame(): void
    {
        $backend = EngineBackend::new(self::recordingProvider(vision: true), 'm');

        $with = $this->settle($backend, ['kind' => 'result', 'ok' => true, 'content' => 'x', 'attachmentNotice' => 'Image a.png was not sent']);
        $without = $this->settle($backend, ['kind' => 'result', 'ok' => true, 'content' => 'x']);
        $garbage = $this->settle($backend, ['kind' => 'result', 'ok' => true, 'content' => 'x', 'attachmentNotice' => ['no']]);

        $this->assertSame('Image a.png was not sent', $with->attachmentNotice);
        $this->assertNull($without->attachmentNotice, 'an older child that never wrote the key reports nothing');
        $this->assertNull($garbage->attachmentNotice);
    }

    public function testTheForkedTurnCarriesTheNoticeHome(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            self::markTestSkipped('completeAsync() takes the blocking fallback without pcntl');
        }

        $backend = EngineBackend::new(self::recordingProvider(vision: false), 'm');
        $prompt = Message::user('see')->attachImage('pic.png', self::IMAGE_BYTES, 'image/png');

        $reply = $this->awaitForkedTurn($backend->completeAsync([$prompt]));

        $this->assertNotNull($reply->attachmentNotice, 'decided in the child, so it must ride the result frame');
        $this->assertStringContainsString('pic.png was not sent', $reply->attachmentNotice);
    }

    /**
     * @param array<string, mixed> $frame
     */
    private function settle(EngineBackend $backend, array $frame): Message
    {
        $deferred = new Deferred();
        (new \ReflectionMethod($backend, 'settleFromResultFrame'))->invoke($backend, $frame, $deferred, null);

        $outcome = null;
        $deferred->promise()->then(static function (Message $m) use (&$outcome): void {
            $outcome = $m;
        });
        $this->assertInstanceOf(Message::class, $outcome);

        return $outcome;
    }

    private function awaitForkedTurn(PromiseInterface $promise): Message
    {
        $loop = Loop::get();
        $outcome = null;
        $settle = static function (mixed $v) use (&$outcome, $loop): void {
            $outcome = $v;
            $loop->stop();
        };
        $promise->then($settle, $settle);

        if ($outcome === null) {
            $guard = $loop->addTimer(30.0, static function () use (&$outcome, $loop): void {
                $outcome = new \RuntimeException('the forked turn never settled');
                $loop->stop();
            });
            $loop->run();
            $loop->cancelTimer($guard);
        }

        if ($outcome instanceof \Throwable) {
            $this->fail('forked turn failed: ' . $outcome->getMessage());
        }
        $this->assertInstanceOf(Message::class, $outcome);

        return $outcome;
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
