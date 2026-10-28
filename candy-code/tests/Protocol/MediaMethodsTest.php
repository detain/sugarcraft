<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Events\ToolFinished;
use SugarCraft\Code\Host\TranscriptProjector;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Media\MediaRequest;
use SugarCraft\Code\Permissions\PermissionDecision;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\Methods\MediaMethods;
use SugarCraft\Code\Protocol\Schema\MethodSchemas;
use SugarCraft\Code\Protocol\Schema\ProtocolSchema;
use SugarCraft\Code\Support\MediaStore;
use SugarCraft\Code\ToolCall;
use SugarCraft\Code\Tools\BuiltIn\GenerateImage;
use SugarCraft\Code\Tools\BuiltIn\GenerateVideo;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolResult;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Server\Support\WireClient;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * Plan W6.2: the serve-mode media door. `generate.submit` consents through
 * the same PermissionGate the Ask-class tool answers to in a turn, opens a
 * 0600 job ledger, and hands the render to the fork channel so the loop
 * thread is never held; `generate.status` polls that ledger; `media.fetch`
 * seek-reads stored artifacts in base64 slices that reassemble byte-exact,
 * resolving ids only through the store listing and paths only inside the
 * session's media folder. Together they satisfy the step's DoD: a stateless
 * client drives a generation and its bytes purely over RPC.
 */
final class MediaMethodsTest extends TestCase
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
        $this->homeRoot = sys_get_temp_dir() . '/w6p2-' . \uniqid('', true);
        $this->useHomeSandbox($this->homeRoot);
        // The production factory dials a real server when the env names one;
        // this suite injects stubs, and the unset keeps any accidental real
        // path honest (GenerateImageTest idiom).
        putenv(\SugarCraft\Code\Media\Sd\Client::BASE_URL_ENV);

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
        $this->purgeTree($this->homeRoot);
    }

    // ── the DoD walk ───────────────────────────────────────────────────

    public function testAStatelessClientDrivesGenerationAndBytesPurelyOverRpc(): void
    {
        $source = str_repeat('PNG-like bytes ', 200);
        $this->armStub($source);

        $submitted = $this->client->call('generate.submit', [
            'sessionId' => $this->sessionId,
            'prompt' => 'a red moon over sugar glass',
        ]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $submitted['jobId']);
        self::assertSame([], ProtocolSchema::errors($submitted, MethodSchemas::result('generate.submit')));

        $status = $this->client->call('generate.status', [
            'sessionId' => $this->sessionId,
            'jobId' => $submitted['jobId'],
        ]);
        self::assertSame('done', $status['state']);
        self::assertSame(1.0, $status['progress01']);
        self::assertArrayNotHasKey('etaSeconds', $status, 'nothing writes an estimate in v1');
        self::assertCount(1, $status['artifacts']);
        self::assertSame('image/png', $status['artifacts'][0]['mime']);
        self::assertSame(0, $status['artifacts'][0]['bytes'], 'path-only refs carry zero bytes (W6.1 law); the true size rides media.fetch');
        self::assertStringNotContainsString('jobs', $status['artifacts'][0]['path'], 'the ledger dir never poses as an artifact');
        self::assertSame([], ProtocolSchema::errors($status, MethodSchemas::result('generate.status')));

        $fetched = $this->client->call('media.fetch', [
            'sessionId' => $this->sessionId,
            'artifactId' => $status['artifacts'][0]['artifactId'],
        ]);
        self::assertSame($source, base64_decode($fetched['bytes'], true));
        self::assertSame(strlen($source), $fetched['totalBytes']);
        self::assertTrue($fetched['eof']);
        self::assertSame('image/png', $fetched['mime']);
        self::assertSame($status['artifacts'][0]['artifactId'], $fetched['artifactId']);
        self::assertSame([], ProtocolSchema::errors($fetched, MethodSchemas::result('media.fetch')));
    }

    /**
     * C-WEB-1.d: the ref a `tool.finished` frame projects for a Read of a
     * workspace image names the ADOPTED copy inside the session store, so the
     * card's very first `media.fetch` answers it byte-exact — before this step
     * the same call failed `media_artifact_not_found` (or, on a basename
     * collision, served the wrong image). The workspace path itself stays
     * unservable: adoption copied the bytes, it did not open the directory.
     */
    public function testAReadImageRefFromToolFinishedFetchesThroughMediaFetch(): void
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );
        self::assertIsString($png);

        $workspace = $this->homeRoot . '/workspace/photo.png';
        mkdir(dirname($workspace), 0o700, true);
        file_put_contents($workspace, $png);

        $event = TranscriptProjector::new()->toolFinishedEvent(
            new ToolFinished('read-9', 'Read', new ToolResult('read-9', 'photo.png is an image.')),
            TranscriptProjector::resultRow(new \SugarCraft\Code\ToolResult(
                name: 'Read',
                result: 'photo.png is an image.',
                id: 'read-9',
                imageBytes: $png,
                imagePath: $workspace,
                durationMs: 4,
            )),
            null,
            null,
            $this->sessionId,
            'turn',
        );

        self::assertArrayHasKey('media', $event->data);
        $artifactId = (string) $event->data['media'][0]['artifactId'];
        self::assertStringStartsWith('read-', $artifactId);

        $fetched = $this->client->call('media.fetch', [
            'sessionId' => $this->sessionId,
            'artifactId' => $artifactId,
        ]);
        self::assertSame($png, base64_decode($fetched['bytes'], true));
        self::assertSame($artifactId, $fetched['artifactId']);
        self::assertTrue($fetched['eof']);

        $escaped = $this->client->request('media.fetch', [
            'sessionId' => $this->sessionId,
            'path' => $workspace,
        ]);
        self::assertSame(
            'media_artifact_not_found',
            $escaped['error']['data']['kind'],
            'the workspace path itself stays unservable; only the adopted copy is fetchable',
        );
    }

    public function testSlicesReassembleByteExactAndOnlyTheFirstRowCarriesMime(): void
    {
        $source = random_bytes(3000);
        $this->armStub($source);
        $artifactId = $this->submitAndFetchId(['prompt' => 'chunk me']);

        $first = $this->client->call('media.fetch', [
            'sessionId' => $this->sessionId,
            'artifactId' => $artifactId,
            'limit' => 2048,
        ]);
        self::assertSame(0, $first['offset']);
        self::assertSame(2048, strlen(base64_decode($first['bytes'], true)));
        self::assertFalse($first['eof']);
        self::assertArrayHasKey('mime', $first);

        $second = $this->client->call('media.fetch', [
            'sessionId' => $this->sessionId,
            'artifactId' => $artifactId,
            'offset' => 2048,
            'limit' => 2048,
        ]);
        self::assertArrayNotHasKey('mime', $second, 'mime rides the first slice row only (plan W6.2)');
        self::assertTrue($second['eof']);
        self::assertSame($source, base64_decode($first['bytes'], true) . base64_decode($second['bytes'], true));
    }

    public function testAnOffsetPastTheEndAnswersEmptyAndEof(): void
    {
        $this->armStub(str_repeat('x', 64));
        $artifactId = $this->submitAndFetchId(['prompt' => 'tiny']);

        $row = $this->client->call('media.fetch', [
            'sessionId' => $this->sessionId,
            'artifactId' => $artifactId,
            'offset' => 999_999,
        ]);
        self::assertSame('', $row['bytes']);
        self::assertSame(64, $row['totalBytes']);
        self::assertTrue($row['eof']);
    }

    // ── ledger + job state ─────────────────────────────────────────────

    public function testAFailedRenderSettlesTheLedgerWithItsError(): void
    {
        $this->armStub('', fail: true, failure: 'no base url for the sd family');

        $submitted = $this->client->call('generate.submit', [
            'sessionId' => $this->sessionId,
            'prompt' => 'never rendered',
        ]);
        $status = $this->client->call('generate.status', [
            'sessionId' => $this->sessionId,
            'jobId' => $submitted['jobId'],
        ]);

        self::assertSame('failed', $status['state']);
        self::assertSame(0.0, $status['progress01']);
        self::assertStringContainsString('no base url', $status['error']);
        self::assertArrayNotHasKey('artifacts', $status);
    }

    public function testAnUnknownJobIsNotFoundByNameOfNothingElse(): void
    {
        $frame = $this->client->request('generate.status', [
            'sessionId' => $this->sessionId,
            'jobId' => '0123456789abcdef',
        ]);
        self::assertSame(ErrorCode::NotFound->value, $frame['error']['code']);
        self::assertSame('job_not_found', $frame['error']['data']['kind']);

        $shape = $this->client->request('generate.status', [
            'sessionId' => $this->sessionId,
            'jobId' => 'not-a-job-id!',
        ]);
        self::assertSame(ErrorCode::InvalidParams->value, $shape['error']['code']);
    }

    public function testReplayingAnIdempotencyKeyRendersOnceAndAnswersTheSameJob(): void
    {
        $this->armStub('bytes');

        $key = 'client-key-' . substr(md5((string) __LINE__), 0, 8);
        $first = $this->client->call('generate.submit', [
            'sessionId' => $this->sessionId,
            'prompt' => 'once',
            'idempotencyKey' => $key,
        ]);
        $second = $this->client->call('generate.submit', [
            'sessionId' => $this->sessionId,
            'prompt' => 'once',
            'idempotencyKey' => $key,
        ]);

        self::assertSame($first['jobId'], $second['jobId']);
        self::assertSame(1, $this->counter->renders, 'the replay answered from the cache, not the diffuser');
        self::assertCount(1, $this->ledgers(), 'one ledger per job');
    }

    public function testAnEmptyPromptIsRefusedBeforeAnyRenderOrLedger(): void
    {
        $this->armStub('bytes');

        $frame = $this->client->request('generate.submit', [
            'sessionId' => $this->sessionId,
            'prompt' => '   ',
        ]);
        self::assertSame(ErrorCode::InvalidParams->value, $frame['error']['code']);
        self::assertStringContainsString('prompt is empty', $frame['error']['message']);
        self::assertSame(0, $this->counter->renders);
        self::assertSame([], $this->ledgers());
    }

    // ── consent mirror ─────────────────────────────────────────────────

    public function testTheToolIsAskClassAndAnUnansweredAskRefusesWithoutRendering(): void
    {
        // The plan's pin: an ask IS raised for this call under the default
        // mode — the same gate object the in-turn tool path consults.
        $gate = new PermissionGate(PermissionMode::Default);
        self::assertSame(
            PermissionDecision::Ask,
            $gate->evaluate(new ToolCall(GenerateImage::NAME, ['prompt' => 'x'])),
        );

        $this->armStub('bytes');
        MediaMethods::setGateForTesting($gate);

        $frame = $this->client->request('generate.submit', [
            'sessionId' => $this->sessionId,
            'prompt' => 'who will answer me',
        ]);
        self::assertSame(ErrorCode::Forbidden->value, $frame['error']['code']);
        self::assertSame('permission_ask_unanswered', $frame['error']['data']['kind']);
        self::assertSame(0, $this->counter->renders, 'an unanswered ask never reaches the render');
        self::assertSame([], $this->ledgers());
    }

    public function testDontAskDeniesAndAGatelessWorkspaceFailsClosed(): void
    {
        $this->armStub('bytes');

        MediaMethods::setGateForTesting(new PermissionGate(PermissionMode::DontAsk));
        $denied = $this->client->request('generate.submit', [
            'sessionId' => $this->sessionId,
            'prompt' => 'denied outright',
        ]);
        self::assertSame(ErrorCode::Forbidden->value, $denied['error']['code']);
        self::assertSame('permission_denied', $denied['error']['data']['kind']);

        // Null override + the fixture's gate-less workspace: silence is not
        // consent.
        MediaMethods::setGateForTesting(null);
        $silent = $this->client->request('generate.submit', [
            'sessionId' => $this->sessionId,
            'prompt' => 'nobody to ask',
        ]);
        self::assertSame('permission_ask_unanswered', $silent['error']['data']['kind']);
        self::assertSame(0, $this->counter->renders);
    }

    public function testConsentFollowsTheSessionEffectiveGateNotTheWorkspaceOne(): void
    {
        // R15 MAJOR-1: submit must see the gate the session's turns run
        // under, not merely the workspace's — a bypass-admitted session runs
        // GenerateImage freely in-turn, so the RPC door must open for it too.
        $this->armStub('bytes');
        MediaMethods::setGateForTesting(null);
        $host = $this->fixture->hub->get($this->sessionId);
        self::assertNotNull($host, 'the open session must be addressable to flip its consent');

        $host->setPermissionMode(PermissionMode::BypassPermissions);
        $bypassed = $this->client->call('generate.submit', [
            'sessionId' => $this->sessionId,
            'prompt' => 'a bypass-admitted session runs',
        ]);
        self::assertMatchesRegularExpression(MediaMethods::JOB_ID_PATTERN, $bypassed['jobId']);
        self::assertSame(1, $this->counter->renders);

        // Fail-closed is symmetric: back to an Ask mode with no memo, the
        // same session's submit is refused again and renders nothing.
        $host->setPermissionMode(PermissionMode::Default);
        $asked = $this->client->request('generate.submit', [
            'sessionId' => $this->sessionId,
            'prompt' => 'unanswered after switching back',
        ]);
        self::assertSame('permission_ask_unanswered', $asked['error']['data']['kind']);
        self::assertSame(1, $this->counter->renders, 'an unanswered session-mode ask never reaches the render');
    }

    // ── fetch containment ──────────────────────────────────────────────

    public function testFetchRefusesForeignPathsParentEscapesAndSymlinkExits(): void
    {
        $this->armStub(str_repeat('s', 128));
        $artifactId = $this->submitAndFetchId(['prompt' => 'contained']);

        $foreign = $this->client->request('media.fetch', [
            'sessionId' => $this->sessionId,
            'path' => '/etc/passwd',
        ]);
        self::assertSame(ErrorCode::NotFound->value, $foreign['error']['code']);
        self::assertSame('media_artifact_not_found', $foreign['error']['data']['kind']);

        $escape = $this->client->request('media.fetch', [
            'sessionId' => $this->sessionId,
            'path' => $this->sessionMediaDir() . '/../' . basename($this->sessionMediaDir()) . '/x.png',
        ]);
        self::assertSame(ErrorCode::InvalidParams->value, $escape['error']['code']);
        self::assertStringContainsString('parent-directory', $escape['error']['message']);

        $outside = $this->homeRoot . '/outside-secret.png';
        file_put_contents($outside, 'leak target bytes');
        symlink($outside, $this->sessionMediaDir() . '/host.png');
        $viaLink = $this->client->request('media.fetch', [
            'sessionId' => $this->sessionId,
            'path' => $this->sessionMediaDir() . '/host.png',
        ]);
        self::assertSame('media_artifact_not_found', $viaLink['error']['data']['kind'], 'realpath containment: a link out is not a file in');

        $bothKeys = $this->client->request('media.fetch', [
            'sessionId' => $this->sessionId,
            'artifactId' => $artifactId,
            'path' => $this->sessionMediaDir() . '/ignored.png',
        ]);
        self::assertSame(ErrorCode::InvalidParams->value, $bothKeys['error']['code']);

        $neitherKey = $this->client->request('media.fetch', ['sessionId' => $this->sessionId]);
        self::assertSame(ErrorCode::InvalidParams->value, $neitherKey['error']['code']);
    }

    public function testTheSliceCapIsTheSchemaCapAndTheHandlerCap(): void
    {
        $this->armStub('bytes');
        $artifactId = $this->submitAndFetchId(['prompt' => 'capped']);

        $over = $this->client->request('media.fetch', [
            'sessionId' => $this->sessionId,
            'artifactId' => $artifactId,
            'limit' => MediaMethods::FETCH_MAX_BYTES + 1,
        ]);
        self::assertSame(ErrorCode::InvalidParams->value, $over['error']['code']);
        self::assertContains('params.limit: above ' . MediaMethods::FETCH_MAX_BYTES, $over['error']['data']['errors']);

        $defaulted = $this->client->call('media.fetch', [
            'sessionId' => $this->sessionId,
            'artifactId' => $artifactId,
        ]);
        self::assertSame(base64_encode('bytes'), $defaulted['bytes'], 'the 512 KiB default answers a small artifact whole');
    }

    // ── one vocabulary, three doors ────────────────────────────────────

    public function testTheToolSchemasAndTheRpcParamsShareOneVocabulary(): void
    {
        $mapped = array_map(static fn ($schema): array => $schema->toArray(), MediaRequest::paramSchema());
        self::assertSame($mapped, GenerateImage::new()->inputSchema()['properties'], 'the image model door renders the very map the RPC door embeds');

        $submitProps = MethodSchemas::params('generate.submit')->toArray()['properties'];
        unset($submitProps['sessionId'], $submitProps['idempotencyKey'], $submitProps['kind']);
        self::assertSame(array_keys($mapped), array_keys(array_intersect_key($submitProps, $mapped)), 'the image leg of the RPC door keeps the shared order verbatim');

        // C-WEB-3.f: the video leg. Every key the GenerateVideo model door
        // names must exist on the RPC door, and the seven video-exclusive
        // names are the whole delta over the shared image vocabulary.
        $videoProps = GenerateVideo::new()->inputSchema()['properties'];
        self::assertSame([], array_diff_key($videoProps, $submitProps), 'the video model door names nothing the RPC door lacks');
        self::assertSame(
            ['num_frames', 'duration_seconds', 'fps', 'cfg', 'cfg2', 'motion', 'first_frame_image'],
            array_keys(array_diff_key($submitProps, $mapped)),
            'exactly seven names ride the video door alone',
        );
    }

    // ── leaving the loop thread ────────────────────────────────────────

    public function testSubmitPublishesTheRunningLedgerBeforeLaunchingAndLeavesViaTheForkChannel(): void
    {
        $file = (string) (new \ReflectionClass(MediaMethods::class))->getFileName();
        $all = (string) file_get_contents($file);
        $from = (int) strpos($all, 'private static function submit');
        $body = substr($all, $from, 4000);
        $running = strpos($body, 'writeLedger($ledgerPath, self::record($job, $args, $startedAt, null))');
        $launch = strpos($body, 'TurnController::forkPayload(');
        self::assertIsInt($running);
        self::assertIsInt($launch);
        self::assertLessThan($launch, $running, 'a client that only ever polls status must find the job already running');
        self::assertStringContainsString('!self::$directRun && TurnController::canFork()', $body);

        // And live through the real channel: with forking available the
        // render settles asynchronously and status polls it to `done`.
        $this->armStub('forked bytes');
        MediaMethods::setDirectRunForTesting(false);
        if (!\SugarCraft\Code\Host\TurnController::canFork()) {
            // No pcntl: the fork door's inline fallback runs it in-handler;
            // the same terminal assertions still hold.
            self::markTestSkipped('the fork channel needs pcntl_fork and pcntl_waitpid');
        }

        $submitted = $this->client->call('generate.submit', [
            'sessionId' => $this->sessionId,
            'prompt' => 'render me off-thread',
        ]);
        $deadline = microtime(true) + 10.0;
        do {
            $this->fixture->run(0.05);
            $status = $this->client->call('generate.status', [
                'sessionId' => $this->sessionId,
                'jobId' => $submitted['jobId'],
            ]);
        } while ($status['state'] === 'running' && microtime(true) < $deadline);
        $this->fixture->run(0.2);

        self::assertSame('done', $status['state'], 'the forked child settled the ledger the poller read');
        self::assertArrayHasKey('artifacts', $status);
    }

    // ── harness ────────────────────────────────────────────────────────

    /** Stubs the render; tallies calls in this process (a fork child's copies do not count). */
    private function armStub(string $bytes, bool $fail = false, string $failure = 'render failed'): void
    {
        $tally = $this->counter;
        MediaMethods::setToolFactoryForTesting(static function (string $sessionId, ?WorkspaceContext $workspace) use ($bytes, $fail, $failure, $tally): Tool {
            return new class($sessionId, $bytes, $fail, $failure, $tally) implements Tool {
                public function __construct(
                    private readonly string $sessionId,
                    private readonly string $bytes,
                    private readonly bool $fail,
                    private readonly string $failure,
                    private readonly \stdClass $tally,
                ) {
                }

                public function name(): string
                {
                    return GenerateImage::NAME;
                }

                public function description(): string
                {
                    return 'stub render for W6.2 pins';
                }

                public function inputSchema(): array
                {
                    return GenerateImage::new()->inputSchema();
                }

                public function execute(array $args): ToolResult
                {
                    $this->tally->renders++;
                    if ($this->fail) {
                        return new ToolResult('w6p2-stub', $this->failure, true);
                    }
                    $artifact = MediaStore::forSession($this->sessionId)->put($this->bytes, 'png');

                    return new ToolResult('w6p2-stub', 'image 1: ' . (string) $artifact->path(), false);
                }
            };
        });
    }

    /** @param array<string, mixed> $extra */
    private function submitAndFetchId(array $params): string
    {
        $submitted = $this->client->call('generate.submit', ['sessionId' => $this->sessionId] + $params);
        $status = $this->client->call('generate.status', [
            'sessionId' => $this->sessionId,
            'jobId' => $submitted['jobId'],
        ]);
        self::assertSame('done', $status['state']);

        return (string) $status['artifacts'][0]['artifactId'];
    }

    private function sessionMediaDir(): string
    {
        return MediaStore::forSession($this->sessionId)->directoryPath();
    }

    private function jobsDir(): string
    {
        return $this->sessionMediaDir() . '/' . MediaMethods::LEDGER_DIR;
    }

    /**
     * Ledger filenames in the jobs dir — spelled without a glob-shaped
     * literal on purpose: the corpus GlobDialectDifferentialTest derives
     * from token-scanned wildcard strings must not gain entries here.
     *
     * @return list<string>
     */
    private function ledgers(): array
    {
        if (!is_dir($this->jobsDir())) {
            return [];
        }
        $found = [];
        foreach (scandir($this->jobsDir()) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_file($this->jobsDir() . '/' . $entry) && str_ends_with($entry, '.json')) {
                $found[] = $entry;
            }
        }
        sort($found);

        return $found;
    }

    private function purgeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_link($path) || is_file($path) ? unlink($path) : $this->purgeTree($path);
        }
        @rmdir($dir);
    }
}
