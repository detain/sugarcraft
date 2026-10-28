<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Media\MediaKind;
use SugarCraft\Code\Media\Sd\Client as SdClient;
use SugarCraft\Code\Permissions\PermissionAction;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\PermissionRule;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\Methods\MediaMethods;
use SugarCraft\Code\Support\MediaStore;
use SugarCraft\Code\Tools\BuiltIn\GenerateImage;
use SugarCraft\Code\Tools\BuiltIn\GenerateVideo;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolResult;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Server\Support\WireClient;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * Plan C-WEB-3.f: `generate.submit` grew a `kind` dial. `kind: video` rides
 * the video tool door — its own key set forwarded whole, its own name
 * carried through consent, its kind stamped on the job ledger — while an
 * omitted kind keeps the pre-step image wire shape byte-for-byte. A launch
 * configured for kinds without video refuses inside the forked render with
 * zero dials, and a value outside the enum never reaches the handler at all.
 *
 * The pins mirror {@see MediaMethodsTest}: same sandboxed-home harness, the
 * same direct-run door, stubs armed through the one test factory seam.
 */
final class GenerateVideoSubmitTest extends TestCase
{
    use HomeSandboxTrait;

    private ProtocolFixture $fixture;

    private WireClient $client;

    private string $sessionId = '';

    private string $homeRoot = '';

    private \stdClass $counter;

    protected function setUp(): void
    {
        $this->counter = new \stdClass();
        $this->counter->renders = 0;
        $this->homeRoot = sys_get_temp_dir() . '/cwb3f-' . \uniqid('', true);
        $this->useHomeSandbox($this->homeRoot);
        // The production video door dials a real server when the env names
        // one; this suite injects stubs, and the unset keeps any accidental
        // real path honest (MediaMethodsTest idiom).
        putenv(SdClient::BASE_URL_ENV);

        $this->fixture = ProtocolFixture::new();
        $this->client = $this->fixture->client();
        $this->sessionId = $this->fixture->session($this->client);

        MediaMethods::setGateForTesting(new PermissionGate(PermissionMode::BypassPermissions));
        MediaMethods::setDirectRunForTesting(true);
    }

    protected function tearDown(): void
    {
        MediaMethods::resetForTesting();
        $this->fixture->tearDown();
        $this->restoreHomeSandbox();
        ProtocolFixture::removeTree($this->homeRoot);
    }

    public function testAVideoSubmitRidesTheVideoDoorAndSettlesAVideoLedger(): void
    {
        $this->armKindStub('MP4-like bytes', 'mp4', GenerateVideo::NAME, MediaKind::Video);

        $submitted = $this->client->call('generate.submit', [
            'sessionId' => $this->sessionId,
            'kind' => 'video',
            'prompt' => 'a fox running through neon snow',
            'num_frames' => 81,
            'fps' => 16,
            'motion' => 0.5,
            'width' => 512,
        ]);
        self::assertMatchesRegularExpression(MediaMethods::JOB_ID_PATTERN, $submitted['jobId']);

        $status = $this->client->call('generate.status', [
            'sessionId' => $this->sessionId,
            'jobId' => $submitted['jobId'],
        ]);
        self::assertSame('done', $status['state']);
        self::assertCount(1, $status['artifacts']);
        self::assertSame('video', $status['artifacts'][0]['kind']);
        self::assertSame('video/mp4', $status['artifacts'][0]['mime']);

        $record = $this->ledgerRecord((string) $submitted['jobId']);
        self::assertSame('video', $record['job']['kind'], 'the ledger names the kind the job rode');
        self::assertSame('done', $record['job']['status']);
        // The forwarded set is exactly the video tool's own keys the caller
        // dialled — no `kind`, no `sessionId`, no `idempotencyKey`.
        self::assertSame(
            ['prompt', 'num_frames', 'fps', 'motion', 'width'],
            array_keys($record['request']),
        );
        self::assertSame(81, $record['request']['num_frames']);

        self::assertTrue($this->storeHoldsAnMp4(), 'the stub wrote through the session store');
    }

    public function testVideoConsentConsultsTheVideoToolNameNotTheImageOne(): void
    {
        $this->armKindStub('MP4-like bytes', 'mp4', GenerateVideo::NAME, MediaKind::Video);
        // An Allow rule for GenerateImage alone: the pre-step door evaluated
        // every submit under the image name, so video would sneak through
        // this gate. Under C-WEB-3.f it must raise its own unanswered ask.
        MediaMethods::setGateForTesting(new PermissionGate(PermissionMode::Default, [
            new PermissionRule(GenerateImage::NAME, PermissionAction::Allow),
        ]));

        $video = $this->client->request('generate.submit', [
            'sessionId' => $this->sessionId,
            'kind' => 'video',
            'prompt' => 'who answers a video ask',
        ]);
        self::assertSame(ErrorCode::Forbidden->value, $video['error']['code']);
        self::assertSame('permission_ask_unanswered', $video['error']['data']['kind']);
        self::assertSame(0, $this->counter->renders, 'an ask raised for the video tool never reaches the render');
        self::assertSame([], $this->jobFiles(), 'a refused video submit opens no ledger');

        // The same gate admits the image door: the rule matched by name.
        $image = $this->client->call('generate.submit', [
            'sessionId' => $this->sessionId,
            'prompt' => 'the image half of the same gate',
        ]);
        self::assertMatchesRegularExpression(MediaMethods::JOB_ID_PATTERN, $image['jobId']);
        self::assertSame(1, $this->counter->renders);
    }

    public function testAVideoSubmitOnAVideolessLaunchRefusesWithZeroDials(): void
    {
        // Production door (no stub): the config roster names kinds without
        // video, so the capability refusal lands before any client exists.
        mkdir($this->homeRoot . '/.candy-code', 0o700, true);
        file_put_contents(
            $this->homeRoot . '/.candy-code/config.json',
            (string) json_encode(['mediaKinds' => ['image']]),
        );

        $submitted = $this->client->call('generate.submit', [
            'sessionId' => $this->sessionId,
            'kind' => 'video',
            'prompt' => 'refused at the capability door',
        ]);
        $status = $this->client->call('generate.status', [
            'sessionId' => $this->sessionId,
            'jobId' => $submitted['jobId'],
        ]);

        self::assertSame('failed', $status['state']);
        self::assertStringContainsString('do not include video', $status['error']);
        self::assertArrayNotHasKey('artifacts', $status);
        self::assertFalse($this->storeHoldsAnMp4(), 'the refusal sent no request and stored nothing');
    }

    public function testAnImageSubmitKeepsThePreStepWireShapeUntouched(): void
    {
        $this->armKindStub('PNG-like bytes', 'png', GenerateImage::NAME, MediaKind::Image);

        // A stray video key without `kind` must not leak through the image
        // door: the image intersection is still MediaRequest's vocabulary.
        $submitted = $this->client->call('generate.submit', [
            'sessionId' => $this->sessionId,
            'prompt' => 'a red moon over sugar glass',
            'num_frames' => 81,
        ]);
        $status = $this->client->call('generate.status', [
            'sessionId' => $this->sessionId,
            'jobId' => $submitted['jobId'],
        ]);
        self::assertSame('done', $status['state']);
        self::assertSame('image/png', $status['artifacts'][0]['mime']);

        $record = $this->ledgerRecord((string) $submitted['jobId']);
        self::assertSame('image', $record['job']['kind'], 'an omitted kind defaults to image');
        self::assertSame(['prompt'], array_keys($record['request']), 'the video key was dropped at the door');
        self::assertSame(1, $this->counter->renders);
    }

    public function testAKindOutsideTheEnumIsRefusedAtTheSchemaDoor(): void
    {
        $this->armKindStub('bytes', 'png', GenerateImage::NAME, MediaKind::Image);

        $frame = $this->client->request('generate.submit', [
            'sessionId' => $this->sessionId,
            'kind' => 'image2',
            'prompt' => 'not a kind this protocol knows',
        ]);
        self::assertSame(ErrorCode::InvalidParams->value, $frame['error']['code']);
        self::assertStringContainsString('kind', (string) json_encode($frame['error']['data'] ?? []));
        self::assertSame(0, $this->counter->renders, 'the handler never saw the value');
        self::assertSame([], $this->jobFiles(), 'a schema-door refusal opens no ledger');
    }

    // ── harness ────────────────────────────────────────────────────────

    /**
     * Stubs the render for either kind; tallies calls in this process.
     * Named apart from MediaMethodsTest's armStub on purpose: it is the
     * kind-parameterised twin, not a byte-copy.
     */
    private function armKindStub(string $bytes, string $extension, string $toolName, MediaKind $storeKind): void
    {
        $tally = $this->counter;
        MediaMethods::setToolFactoryForTesting(static function (string $sessionId, ?WorkspaceContext $workspace) use ($bytes, $extension, $toolName, $storeKind, $tally): Tool {
            return new class($sessionId, $bytes, $extension, $toolName, $storeKind, $tally) implements Tool {
                public function __construct(
                    private readonly string $sessionId,
                    private readonly string $bytes,
                    private readonly string $extension,
                    private readonly string $toolName,
                    private readonly MediaKind $storeKind,
                    private readonly \stdClass $tally,
                ) {
                }

                public function name(): string
                {
                    return $this->toolName;
                }

                public function description(): string
                {
                    return 'stub render for C-WEB-3.f pins';
                }

                public function inputSchema(): array
                {
                    return ($this->toolName === GenerateVideo::NAME ? GenerateVideo::new() : GenerateImage::new())->inputSchema();
                }

                public function execute(array $args): ToolResult
                {
                    $this->tally->renders++;
                    $artifact = MediaStore::forSession($this->sessionId)->put($this->bytes, $this->extension, [], $this->storeKind);

                    return new ToolResult('cwb3f-stub', 'artifact 1: ' . (string) $artifact->path(), false);
                }
            };
        });
    }

    /**
     * @return array{job: array<array-key, mixed>, request: array<array-key, mixed>, started_at: int, finished_at: ?int}
     */
    private function ledgerRecord(string $jobId): array
    {
        $raw = @file_get_contents($this->sessionMediaDir() . '/' . MediaMethods::LEDGER_DIR . '/' . $jobId . '.json');
        self::assertIsString($raw, 'the job ledger exists at the announced id');
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded);
        /** @var array{job: array<array-key, mixed>, request: array<array-key, mixed>, started_at: int, finished_at: ?int} $decoded */
        return $decoded;
    }

    /**
     * Ledger filenames under the jobs dir — scandir spelled without a
     * glob-shaped literal, same GlobDialect discipline as the sibling suite.
     *
     * @return list<string>
     */
    private function jobFiles(): array
    {
        $dir = $this->sessionMediaDir() . '/' . MediaMethods::LEDGER_DIR;
        if (!is_dir($dir)) {
            return [];
        }
        $found = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_file($dir . '/' . $entry) && str_ends_with($entry, '.json')) {
                $found[] = $entry;
            }
        }
        sort($found);

        return $found;
    }

    private function sessionMediaDir(): string
    {
        return MediaStore::forSession($this->sessionId)->directoryPath();
    }

    private function storeHoldsAnMp4(): bool
    {
        foreach (MediaStore::forSession($this->sessionId)->list() as $entry) {
            if (str_ends_with((string) ($entry['path'] ?? ''), '.mp4')) {
                return true;
            }
        }

        return false;
    }
}
