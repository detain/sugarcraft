<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\MockHandler as AwsMockHandler;
use Aws\Result;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Attachment;
use SugarCraft\Crush\AttachmentType;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\AttachmentEncoding;
use SugarCraft\Crush\Providers\BedrockProvider;
use SugarCraft\Crush\Providers\ClaudeCodeProvider;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CustomProvider;
use SugarCraft\Crush\Providers\ProviderFactory;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Providers\SglangServerInfo;
use SugarCraft\Crush\Providers\VertexProvider;

/**
 * Audit 15b-15: an attachment on a user turn reaches every wire this app
 * speaks, in that wire's own shape - and a turn without one keeps the exact
 * pre-attachment shape, so the ordinary prompt costs nothing and moves no
 * cached prefix.
 */
final class AttachmentWireTest extends TestCase
{
    private const PNG = "\x89PNG\r\n\x1a\n" . "\x00\xffbinary";

    /** @var list<array<string, mixed>> */
    private array $history = [];

    private static function attached(): UserMessage
    {
        return (new UserMessage('what is this?'))
            ->withAttachment(new Attachment('src/a.php', AttachmentType::File, "<?php echo 1;\n"))
            ->withAttachment(new Attachment('shot.png', AttachmentType::Image, self::PNG, 'image/png'));
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
            ->invoke(new ClaudeCodeProvider(new \SugarCraft\Crush\Providers\ClaudeCodeInvocation()), [self::attached()]);

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
