<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\MockHandler as AwsMockHandler;
use Aws\Result;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OpenAI\Contracts\ClientContract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Attachment;
use SugarCraft\Code\AttachmentType;
use SugarCraft\Code\Messages\AssistantMessage;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Providers\AttachmentEncoding;
use SugarCraft\Code\Providers\BedrockProvider;
use SugarCraft\Code\Providers\ClaudeCodeProvider;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CustomProvider;
use SugarCraft\Code\Providers\OpenAIProvider;
use SugarCraft\Code\Providers\ProviderFactory;
use SugarCraft\Code\Providers\SglangProvider;
use SugarCraft\Code\Providers\SglangServerInfo;
use SugarCraft\Code\Providers\VertexProvider;

/**
 * Audit 15b-15: an attachment on a user turn reaches every wire this app
 * speaks, in that wire's own shape - and a turn without one keeps the exact
 * pre-attachment shape, so the ordinary prompt costs nothing and moves no
 * cached prefix.
 */
final class AttachmentWireTest extends TestCase
{
    private const PNG = "\x89PNG\r\n\x1a\n" . "\x00\xffbinary";

    private const PDF = '%PDF-1.7' . "\n" . "\x00\x00\x00" . 'body' . "\n%%EOF\n";

    /** @var list<array<string, mixed>> */
    private array $history = [];

    private static function attached(): UserMessage
    {
        return (new UserMessage('what is this?'))
            ->withAttachment(new Attachment('src/a.php', AttachmentType::File, "<?php echo 1;\n"))
            ->withAttachment(new Attachment('shot.png', AttachmentType::Image, self::PNG, 'image/png'));
    }

    private static function attachedWithPdf(): UserMessage
    {
        return self::attached()
            ->withAttachment(new Attachment('handouts/spec.pdf', AttachmentType::Document, self::PDF, 'application/pdf'));
    }

    // ── UserMessage's own text forms ────────────────────────────────────

    public function testFilesAreInlinedAfterThePromptAndImagesAreNotText(): void
    {
        $this->assertSame(
            "what is this?\n\n<file path=\"src/a.php\">\n<?php echo 1;\n</file>",
            self::attached()->wireText(),
        );
        $this->assertCount(1, self::attached()->images());
    }

    public function testATextOnlyWireNamesTheImageItCannotSend(): void
    {
        $this->assertStringEndsWith(
            '[Image attachment shot.png was not sent: this request carries text only.]',
            self::attached()->textOnly(),
        );
    }

    public function testAnAttachmentWithoutASnapshotIsNamedNeverDropped(): void
    {
        $bare = (new UserMessage('hi'))->withFile('/tmp/gone.txt')->withImage('/tmp/gone.png');

        $this->assertStringContainsString('[Attached file /tmp/gone.txt could not be included', $bare->wireText());
        $this->assertStringContainsString('[Attached image /tmp/gone.png could not be included', $bare->wireText());
        $this->assertSame([], $bare->images());
    }

    public function testAQuoteInAPathCannotBreakOutOfTheFileAttribute(): void
    {
        $message = (new UserMessage(''))->withAttachment(new Attachment("a\"b\nc.txt", AttachmentType::File, 'x'));

        $this->assertStringStartsWith("<file path=\"a'b c.txt\">", $message->wireText());
    }

    // ── The four wire families ──────────────────────────────────────────

    public function testTheOpenAiShapeIsAStringWithoutImagesAndPartsWithThem(): void
    {
        $this->assertSame('plain', AttachmentEncoding::openAiContent(new UserMessage('plain')));

        $parts = AttachmentEncoding::openAiContent(self::attached());
        $this->assertIsArray($parts);
        $this->assertSame('text', $parts[0]['type']);
        $this->assertStringContainsString('<file path="src/a.php">', $parts[0]['text']);
        $this->assertSame(
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . base64_encode(self::PNG)]],
            $parts[1],
        );
    }

    public function testTheAnthropicGeminiAndBedrockShapes(): void
    {
        $anthropic = AttachmentEncoding::anthropicBlocks(self::attached());
        $this->assertSame(
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => base64_encode(self::PNG)]],
            $anthropic[1],
        );

        $gemini = AttachmentEncoding::geminiParts(self::attached());
        $this->assertSame(['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode(self::PNG)]], $gemini[1]);

        $bedrock = AttachmentEncoding::bedrockBlocks(self::attached());
        $this->assertSame(['image' => ['format' => 'png', 'source' => ['bytes' => self::PNG]]], $bedrock[1], 'raw: the SDK encodes blobs');
    }

    // ── CTX-2: the PDF document family ──────────────────────────────────

    public function testTheAnthropicDocumentBlockCarriesThePdfTitled(): void
    {
        $blocks = AttachmentEncoding::anthropicBlocks(self::attachedWithPdf());

        // The image keeps its slot; the document block joins after it.
        $this->assertSame('text', $blocks[0]['type']);
        $this->assertSame('image', $blocks[1]['type']);
        $this->assertSame(
            [
                'type' => 'document',
                'title' => 'spec.pdf',
                'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode(self::PDF)],
            ],
            $blocks[2],
        );
    }

    public function testADatalessDocumentIsNamedInTheTextAndCarriesNoBlock(): void
    {
        $message = (new UserMessage('see @spec.pdf'))->withDocument('/tmp/gone.pdf');

        $this->assertStringContainsString('[Attached document /tmp/gone.pdf could not be included', $message->wireText());
        $this->assertSame([], $message->documents());
        $this->assertCount(1, AttachmentEncoding::anthropicBlocks($message));

        $withBytes = $message->withAttachment(new Attachment('/tmp/raw.pdf', AttachmentType::Document, self::PDF, 'application/pdf'));
        $this->assertStringEndsWith(
            '[Document attachment /tmp/raw.pdf was not sent: this request carries text only.]',
            $withBytes->textOnly(),
        );
    }

    public function testDocumentsBecomeBlocksOnlyWhereTheWireCarriesThem(): void
    {
        $message = self::attachedWithPdf();

        // The no-flag callers — the Responses arm, SGLang and Custom — keep
        // the CTX-2 decline: the never-silent rule names what they cannot carry.
        $openAi = json_encode(AttachmentEncoding::openAiContent($message), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $this->assertStringContainsString(
            '[Document attachment spec.pdf was not sent: this wire does not accept PDF document blocks.]',
            $openAi,
            'openai without the opt-in: the decline line is byte-identical to CTX-2',
        );
        $this->assertStringNotContainsString(base64_encode(self::PDF), $openAi, 'openai without the opt-in: no document part is built');
        $this->assertStringNotContainsString('%PDF-1.7', $openAi, 'openai without the opt-in: the bytes are never inlined as text');

        // C-TOOL-2.e: the chat arm's opt-in carries the same PDF as a `file`
        // part with a `data:application/pdf` URL.
        $chat = json_encode(AttachmentEncoding::openAiContent($message, documentsAsBlocks: true), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $this->assertStringContainsString('"type":"file"', $chat, 'openai chat: the file part is built');
        $this->assertStringContainsString('spec.pdf', $chat, 'openai chat: the filename rides, dots and spaces legal');
        $this->assertStringContainsString('data:application\/pdf;base64,' . base64_encode(self::PDF), $chat, 'openai chat: base64 inside the data URL');
        $this->assertStringNotContainsString('does not accept PDF document blocks', $chat, 'openai chat: nothing declined');

        // C-TOOL-2.e: Gemini carries it as `application/pdf` inlineData.
        $gemini = json_encode(AttachmentEncoding::geminiParts($message), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $this->assertStringContainsString('"mimeType":"application\/pdf"', $gemini, 'gemini: the inlineData part is built');
        $this->assertStringContainsString(base64_encode(self::PDF), $gemini, 'gemini: base64 like the image leg');
        $this->assertStringNotContainsString('does not accept PDF document blocks', $gemini, 'gemini: nothing declined');
        $this->assertStringNotContainsString('%PDF-1.7', $gemini, 'gemini: the bytes are never inlined as text');

        // C-TOOL-2.e: Converse carries the same PDF as a `document` block -
        // with RAW bytes, like the image leg, not a base64 of base64.
        $bedrock = json_encode(AttachmentEncoding::bedrockBlocks($message), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $this->assertStringContainsString('"format":"pdf"', $bedrock, 'bedrock: the document block is built');
        $this->assertStringContainsString('spec_pdf', $bedrock, 'bedrock: the name is shaved to the Converse charset');
        $this->assertStringContainsString('%PDF-1.7', $bedrock, 'bedrock: blob members take raw bytes');
        $this->assertStringNotContainsString(base64_encode(self::PDF), $bedrock, 'bedrock: no double-encoding');

        // The no-flag string path (no images at all) names it too.
        $textOnly = AttachmentEncoding::openAiContent(
            (new UserMessage('just the pdf'))->withAttachment(new Attachment('a.pdf', AttachmentType::Document, self::PDF, 'application/pdf')),
        );
        $this->assertIsString($textOnly);
        $this->assertStringContainsString('[Document attachment a.pdf was not sent', $textOnly);
    }

    public function testTheDocumentWiresAreAnthropicMessagesAndTheNativeArms(): void
    {
        $factory = new ProviderFactory();

        $this->assertTrue($factory->create(['type' => 'anthropic', 'apiKey' => 'k'])->supportsDocuments());
        // C-TOOL-2.e: the chat arm builds `file` parts for official vision models…
        $this->assertTrue($factory->create(['type' => 'openai', 'apiKey' => 'k', 'model' => 'gpt-4o'])->supportsDocuments());
        // …and the Responses arm does not — its file construct is a file ID,
        // so documents keep the named decline there.
        $this->assertFalse(
            $factory->create(['type' => 'openai', 'apiKey' => 'k', 'model' => 'gpt-4o', 'api' => 'responses'])->supportsDocuments(),
            'the Responses arm has no `file` part this encoder builds',
        );
        $this->assertTrue($factory->create(['type' => 'gemini', 'apiKey' => 'k'])->supportsDocuments(), 'PDFs ride inlineData (C-TOOL-2.e)');
        $this->assertFalse(
            $factory->create(['type' => 'custom', 'name' => 'c', 'baseUrl' => 'https://x', 'model' => 'm', 'supportsVision' => true])->supportsDocuments(),
            'a vision override does not buy a document wire',
        );
        $this->assertFalse(
            $factory->create(['type' => 'sglang', 'baseUrl' => 'https://x', 'model' => 'm', 'discoverServerInfo' => false, 'supportsVision' => true])->supportsDocuments(),
        );

        $claude = VertexProvider::create(
            projectId: 'p',
            model: 'claude-3-sonnet@20240229',
            predictor: static fn (string $e, string $m, array $b): array => ['content' => [['type' => 'text', 'text' => 'ok']]],
        );
        $gemini = VertexProvider::create(
            projectId: 'p',
            model: 'gemini-1.5-pro-002',
            predictor: static fn (string $e, string $m, array $b): array => ['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]],
        );
        $this->assertTrue($claude->supportsDocuments(), 'the Claude arm speaks the Messages document block');
        $this->assertTrue($gemini->supportsDocuments(), 'the Gemini arm speaks inlineData (C-TOOL-2.e)');
    }

    public function testVertexClaudeCarriesTheDocumentBlockOnTheWire(): void
    {
        $captured = [];
        $provider = VertexProvider::create(
            projectId: 'p',
            model: 'claude-3-sonnet@20240229',
            predictor: function (string $endpoint, string $method, array $body) use (&$captured): array {
                $captured = $body;

                return ['content' => [['type' => 'text', 'text' => 'ok']]];
            },
            promptCache: false,
        );

        $provider->complete(new CompleteRequest(model: 'claude-3-sonnet@20240229', messages: [self::attachedWithPdf()]));

        $blocks = $captured['messages'][0]['content'];
        $this->assertSame('document', $blocks[2]['type']);
        $this->assertSame(base64_encode(self::PDF), $blocks[2]['source']['data']);
    }

    public function testBedrockNamesAnImageFormatConverseDoesNotKnow(): void
    {
        $odd = (new UserMessage('x'))->withAttachment(new Attachment('a.bmp', AttachmentType::Image, 'BM..', 'image/bmp'));

        $blocks = AttachmentEncoding::bedrockBlocks($odd);

        $this->assertCount(1, $blocks);
        $this->assertStringContainsString('a.bmp was not sent: Bedrock does not accept image/bmp', $blocks[0]['text']);
    }

    // ── Through each provider's real request builder ────────────────────

    public function testSglangSendsImageUrlPartsOnTheWire(): void
    {
        $this->sglang()->complete(new CompleteRequest(model: 'm', messages: [self::attached()]));

        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        $user = $body['messages'][array_key_last($body['messages'])];
        $this->assertSame('user', $user['role']);
        $this->assertSame('image_url', $user['content'][1]['type']);
        $this->assertSame('data:image/png;base64,' . base64_encode(self::PNG), $user['content'][1]['image_url']['url']);
    }

    public function testAPlainSglangTurnKeepsItsStringContent(): void
    {
        $this->sglang()->complete(new CompleteRequest(model: 'm', messages: [new UserMessage('hi'), new AssistantMessage('yo'), new UserMessage('again')]));

        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame('again', $body['messages'][array_key_last($body['messages'])]['content']);
    }

    public function testCustomSendsImageUrlPartsOnTheWire(): void
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"choices":[{"message":{"content":"ok"}}]}')]));
        $stack->push(Middleware::history($this->history));
        $provider = new CustomProvider('c', 'https://x.test', 'm', null, new Client(['base_uri' => 'https://x.test/', 'handler' => $stack]), false, false, supportsVision: true);

        $provider->complete(new CompleteRequest(model: 'm', messages: [self::attached()]));

        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame('image_url', $body['messages'][0]['content'][1]['type']);
    }

    public function testVertexClaudeSendsAnImageBlock(): void
    {
        $captured = [];
        $provider = VertexProvider::create(
            projectId: 'p',
            model: 'claude-3-sonnet@20240229',
            predictor: function (string $endpoint, string $method, array $body) use (&$captured): array {
                $captured = $body;

                return ['content' => [['type' => 'text', 'text' => 'ok']]];
            },
            promptCache: false,
        );

        $provider->complete(new CompleteRequest(model: 'claude-3-sonnet@20240229', messages: [self::attached()]));

        $blocks = $captured['messages'][0]['content'];
        $this->assertSame('text', $blocks[0]['type']);
        $this->assertSame('image', $blocks[1]['type']);
        $this->assertSame(base64_encode(self::PNG), $blocks[1]['source']['data']);
    }

    public function testVertexGeminiSendsInlineData(): void
    {
        $captured = [];
        $provider = VertexProvider::create(
            projectId: 'p',
            model: 'gemini-1.5-pro-002',
            predictor: function (string $endpoint, string $method, array $body) use (&$captured): array {
                $captured = $body;

                return ['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]];
            },
        );

        $provider->complete(new CompleteRequest(model: 'gemini-1.5-pro-002', messages: [self::attached()]));

        $this->assertSame(['mimeType' => 'image/png', 'data' => base64_encode(self::PNG)], $captured['contents'][0]['parts'][1]['inlineData']);
    }

    public function testBedrockSendsTheImageBytesBase64EncodedOnceOnTheHttpWire(): void
    {
        $mock = new AwsMockHandler();
        $mock->append(new Result(['output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'ok']]]]]));
        $client = new BedrockRuntimeClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'k', 'secret' => 's'],
            'handler' => $mock,
        ]);
        $provider = new BedrockProvider($client, 'us-east-1', 'anthropic.claude-sonnet-4-6', promptCache: false);

        $provider->complete(new CompleteRequest(model: 'anthropic.claude-sonnet-4-6', messages: [self::attached()]));

        $body = json_decode((string) $mock->getLastRequest()->getBody(), true);
        $image = $body['messages'][0]['content'][1]['image'];
        $this->assertSame('png', $image['format']);
        $this->assertSame(base64_encode(self::PNG), $image['source']['bytes'], 'base64 once - the SDK encodes the raw blob');
    }

    public function testTheClaudeCodePromptNamesTheImage(): void
    {
        $prompt = (new \ReflectionMethod(ClaudeCodeProvider::class, 'buildPrompt'))
            ->invoke(new ClaudeCodeProvider(new \SugarCraft\Code\Providers\ClaudeCodeInvocation()), [self::attached()]);

        $this->assertStringContainsString('<file path="src/a.php">', $prompt);
        $this->assertStringContainsString('[Image attachment shot.png was not sent', $prompt);
    }

    // ── Who answers yes to vision ───────────────────────────────────────

    public function testSglangAsksTheServerAndTheBlockKeyOverridesIt(): void
    {
        $info = SglangServerInfo::fromResponses(['has_image_understanding' => true, 'served_model_name' => 'vl'], null);
        $this->assertNotNull($info);
        $this->assertTrue($info->imageUnderstanding);

        $client = new Client();
        $seeing = new SglangProvider('https://x', 'm', null, $client, serverInfoLoader: static fn () => $info);
        $blind = new SglangProvider('https://x', 'm', null, $client, serverInfoLoader: static fn () => SglangServerInfo::new(servedModelName: 'm'));
        $undiscovered = new SglangProvider('https://x', 'm', null, $client);
        $forced = new SglangProvider('https://x', 'm', null, $client, supportsVision: true);
        $refused = new SglangProvider('https://x', 'm', null, $client, serverInfoLoader: static fn () => $info, supportsVision: false);

        $this->assertTrue($seeing->supportsVision());
        $this->assertFalse($blind->supportsVision(), 'a server that did not say counts as no vision');
        $this->assertFalse($undiscovered->supportsVision());
        $this->assertTrue($forced->supportsVision());
        $this->assertFalse($refused->supportsVision());
    }

    public function testTheLiveQwenFixtureSaysNoVision(): void
    {
        $modelInfo = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/sglang-model-info-qwen3.8.json'), true);

        $this->assertFalse(SglangServerInfo::fromResponses($modelInfo, null)?->imageUnderstanding);
    }

    public function testTheFactoryReadsSupportsVisionPerBlock(): void
    {
        $factory = new ProviderFactory();

        $this->assertFalse($factory->create(['type' => 'custom', 'name' => 'c', 'baseUrl' => 'https://x', 'model' => 'm'])->supportsVision());
        $this->assertTrue($factory->create(['type' => 'custom', 'name' => 'c', 'baseUrl' => 'https://x', 'model' => 'm', 'supportsVision' => 'true'])->supportsVision());
        $this->assertTrue($factory->create(['type' => 'anthropic', 'apiKey' => 'k'])->supportsVision());
        $this->assertTrue($factory->create([
            'type' => 'sglang', 'baseUrl' => 'https://x', 'model' => 'm', 'discoverServerInfo' => false, 'supportsVision' => true,
        ])->supportsVision());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('supportsVision must be true or false');
        $factory->create(['type' => 'custom', 'name' => 'c', 'baseUrl' => 'https://x', 'model' => 'm', 'supportsVision' => 'sometimes']);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function openAiModels(): array
    {
        return [
            'gpt-4o' => ['gpt-4o', true],
            'gpt-4o snapshot' => ['gpt-4o-2024-08-06', true],
            'gpt-4o-mini' => ['gpt-4o-mini', true],
            'gpt-4.1-nano' => ['gpt-4.1-nano', true],
            'gpt-4-turbo' => ['gpt-4-turbo', true],
            'gpt-5-mini' => ['gpt-5-mini', true],
            'o1' => ['o1', true],
            'o4-mini' => ['o4-mini', true],
            'gpt-4 (text only)' => ['gpt-4', false],
            'gpt-4-0613 (text only)' => ['gpt-4-0613', false],
            'gpt-3.5-turbo' => ['gpt-3.5-turbo', false],
            'gpt-4-turbo-preview' => ['gpt-4-turbo-preview', false],
            'o1-mini' => ['o1-mini', false],
            'o3-mini snapshot' => ['o3-mini-2025-01-31', false],
            'gpt-4o-audio-preview' => ['gpt-4o-audio-preview', false],
            'unknown future model' => ['some-future-model', false],
            'family prefix without dash' => ['gpt-4oo', false],
        ];
    }

    /**
     * Audit 15b-15 residual: OpenAI used to answer "vision" for every model,
     * so an image sent to a text-only one failed the turn on the API's 400.
     * Only the known vision families say yes now; the rest get the named
     * placeholder upstream in EngineBackend.
     */
    #[DataProvider('openAiModels')]
    public function testOpenAiVisionFollowsTheModel(string $model, bool $vision): void
    {
        $provider = new OpenAIProvider($this->createMock(ClientContract::class), $model);

        $this->assertSame($vision, $provider->supportsVision());
    }

    public function testTheOpenAiBlockOverridesTheModelTable(): void
    {
        $client = $this->createMock(ClientContract::class);
        $this->assertTrue((new OpenAIProvider($client, 'my-finetune', supportsVision: true))->supportsVision());
        $this->assertFalse((new OpenAIProvider($client, 'gpt-4o', supportsVision: false))->supportsVision());

        $factory = new ProviderFactory();
        $this->assertFalse($factory->create(['type' => 'openai', 'apiKey' => 'k', 'model' => 'gpt-3.5-turbo'])->supportsVision());
        $this->assertTrue($factory->create(['type' => 'openai', 'apiKey' => 'k', 'model' => 'gpt-3.5-turbo', 'supportsVision' => 'yes'])->supportsVision());
        $this->assertFalse($factory->create(['type' => 'openai', 'apiKey' => 'k', 'model' => 'gpt-4o', 'supportsVision' => false])->supportsVision());
        $this->assertTrue($factory->create(['type' => 'openai', 'apiKey' => 'k'])->supportsVision(), 'the default gpt-4o sees');
    }

    private function sglang(): SglangProvider
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"choices":[{"message":{"content":"ok"}}],"usage":{"total_tokens":1}}')]));
        $stack->push(Middleware::history($this->history));

        return new SglangProvider(
            'https://api.example.com',
            'MiniMax-M2.7',
            null,
            new Client(['base_uri' => 'https://api.example.com/', 'handler' => $stack]),
            supportsVision: true,
        );
    }
}
