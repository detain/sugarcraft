<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Media\Video;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Media\Capability\EndpointFamily;
use SugarCraft\Crush\Media\MediaJobStatus;
use SugarCraft\Crush\Media\Sd\CallableMultipartSdTransport;
use SugarCraft\Crush\Media\Sd\CallableSdTransport;
use SugarCraft\Crush\Media\Sd\SdException;
use SugarCraft\Crush\Media\Video\JobClient;
use SugarCraft\Crush\Media\Video\JobPollLoop;
use SugarCraft\Crush\Media\Video\JobPollSummary;
use SugarCraft\Crush\Media\Video\JobSnapshot;
use SugarCraft\Crush\Support\MediaStore;

/**
 * W5.2 video-job pins (plan_crush_media.md): the closed status vocabulary,
 * the three ingestion shapes ({b64} / {url} / {file_path}) with fact (f)
 * enforced — bytes land in MediaStore and only the PATH comes back — the
 * same-origin law on server-returned URLs, the strict-base64 refusal
 * (whitespace is REFUSED, not skipped — the PHP-strict-decode premise is
 * pinned here because base64_decode($s, true) silently tolerates it), the
 * 1 s cadence with heartbeat-per-poll, the bounded 3-failure give-up, and
 * cancel-between-polls plus the sdapi global-interrupt fallback.
 *
 * Every dial runs through the CallableMultipartSdTransport fake (the W7
 * adapter made submit post form fields, so the fake must speak multipart):
 * zero network, zero
 * timers, zero ReactPHP — the Media-layer law.
 */
final class JobClientTest extends TestCase
{
    private const W5V_BASE = 'http://sd.test:7860';

    private const W5V_MP4_STUB_B64 = 'AAAAIGZ0eXBpc29tAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

    private string $mediaRoot;

    protected function setUp(): void
    {
        $this->mediaRoot = sys_get_temp_dir() . '/w5v-media-' . uniqid('r', true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->mediaRoot);
    }

    public function testSubmitForwardsParamsUnchangedAndReturnsTheServerTaskId(): void
    {
        $calls = [];
        $client = $this->w5vClient(['POST /v1/videos' => [$this->w5vFixtureRow('jobs-submit.json')]], calls: $calls);

        $taskId = $client->submit(['prompt' => 'a cat riding a rocket', 'num_frames' => 121, 'weird' => ['nested' => 1]]);

        self::assertSame('job-77', $taskId);
        self::assertCount(1, $calls);
        self::assertSame('POST', $calls[0]['method']);
        self::assertSame('/v1/videos', $calls[0]['path']);
        // Opaque-params law: the array rides unchanged, nested unknowns
        // included; only the explicit nulls of the W5.0 egress law drop out
        // (pinned by their own test below).
        self::assertSame(['prompt' => 'a cat riding a rocket', 'num_frames' => 121, 'weird' => ['nested' => 1]], $calls[0]['json']);
    }

    /**
     * R12-m1: the W5.0 stripExplicitNulls egress law now honestly covers the
     * video submit too (the W5.2 commit message always claimed the reuse).
     * A null posted to a pydantic-shaped server binds Python None onto the
     * Optional field instead of leaving its default — omitting the key IS
     * the default — while zero-valued arms (fps 0, false flags) must ride.
     */
    public function testSubmitStripsExplicitNullsAtTheWireAndKeepsEveryOtherValue(): void
    {
        $calls = [];
        $client = $this->w5vClient(['POST /v1/videos' => [$this->w5vFixtureRow('jobs-submit.json')]], calls: $calls);

        $client->submit([
            'prompt' => 'a cat',
            'seed' => null,
            'num_frames' => 0,
            'fps' => 0.0,
            'loop' => false,
            'negative_prompt' => null,
        ]);

        self::assertSame(
            ['prompt' => 'a cat', 'num_frames' => 0, 'fps' => 0.0, 'loop' => false],
            $calls[0]['json'],
            'nulls drop at the boundary; zeros, empty floats and false stay',
        );
    }

    public function testSubmitOnTheImageOnlySdApiFamilyRefusesWithoutDialling(): void
    {
        $calls = [];
        $client = $this->w5vClient([], EndpointFamily::SdApi, $calls);

        try {
            $client->submit(['prompt' => 'x']);
            self::fail('expected the image-only dialect to refuse a video submit');
        } catch (SdException $e) {
            self::assertStringContainsString('image-only', $e->getMessage());
        }

        self::assertSame([], $calls);
    }

    public function testAcceptedSubmitWithoutAUsableTaskIdRefuses(): void
    {
        $client = $this->w5vClient([
            'POST /v1/videos' => [['status' => 200, 'body' => '{"status":"queued"}']],
        ]);

        $this->expectException(SdException::class);
        $this->expectExceptionMessage('no usable task id string');

        $client->submit(['prompt' => 'x']);
    }

    /**
     * W7 adapter: the submit body is multipart form fields in the contract's
     * own vocabulary — steps/cfg/cfg2/first_frame_image rename, and a
     * width/height pair composes the size string (W0 openapi, Q-10).
     */
    public function testTheW7SubmitRenamesFieldsIntoTheContractVocabulary(): void
    {
        $calls = [];
        $client = $this->w5vClient(['POST /v1/videos' => [$this->w5vFixtureRow('jobs-submit.json')]], calls: $calls);

        $client->submit([
            'prompt' => 'a cat',
            'steps' => 30,
            'cfg' => 7.5,
            'cfg2' => 2.0,
            'first_frame_image' => '/tmp/seed.png',
            'width' => 512,
            'height' => 512,
            'seed' => 7,
        ]);

        // Renames RE-APPEND at the map tail (unset + set), so the field order
        // below is the honest wire order, pinned by assertSame.
        self::assertSame([
            'prompt' => 'a cat',
            'seed' => 7,
            'num_inference_steps' => 30,
            'guidance_scale' => 7.5,
            'guidance_scale_2' => 2.0,
            'input_reference' => '/tmp/seed.png',
            'size' => '512x512',
        ], $calls[0]['json'], 'renames land, the pair composes size, unmapped keys ride');
        self::assertArrayNotHasKey('cfg_scale', $calls[0]['json'], 'this dialect knows no cfg_scale');
        self::assertArrayNotHasKey('width', $calls[0]['json']);
        self::assertArrayNotHasKey('height', $calls[0]['json']);
    }

    public function testALoneDimensionRidesUnrenamedInsteadOfGuessingItsPartner(): void
    {
        $calls = [];
        $client = $this->w5vClient(['POST /v1/videos' => [$this->w5vFixtureRow('jobs-submit.json')]], calls: $calls);

        $client->submit(['prompt' => 'a cat', 'width' => 512]);

        self::assertSame(['prompt' => 'a cat', 'width' => 512], $calls[0]['json']);
        self::assertArrayNotHasKey('size', $calls[0]['json']);
    }

    public function testSubmitOnANonMultipartTransportRefusesWithoutDialling(): void
    {
        $calls = [];
        $transport = new CallableSdTransport(
            function (string $method, string $path, array $json) use (&$calls): array {
                $calls[] = ['method' => $method, 'path' => $path];

                return ['status' => 200, 'body' => '{}'];
            },
        );
        $client = JobClient::withBase(self::W5V_BASE, $transport, null, EndpointFamily::SglangDiffusion);

        try {
            $client->submit(['prompt' => 'a cat']);
            self::fail('expected the multipart-needy contract to refuse a JSON-only transport');
        } catch (SdException $e) {
            self::assertStringContainsString('posts multipart', $e->getMessage());
            self::assertStringContainsString(CallableSdTransport::class, $e->getMessage());
        }

        self::assertSame([], $calls, 'the refusal happens before the wire, not on it');
    }

    /**
     * W7: VideoResponse.progress is an INTEGER percent on the live schema;
     * the progress01 law wants 0..1. Values above 1 divide by 100 at this
     * ingress; ratios (and the ambiguous exactly-1) ride verbatim.
     */
    public function testIntegerPercentProgressNormalisesToTheZeroOneRatioAtIngress(): void
    {
        $rows = [37, 0.37, 100, 1, 0];
        $expected = [0.37, 0.37, 1.0, 1.0, 0.0];
        foreach ($rows as $i => $wire) {
            $client = $this->w5vClient([
                'GET /v1/videos/job-77' => [[
                    'status' => 200,
                    'body' => json_encode(['id' => 'job-77', 'status' => 'processing', 'progress' => $wire], JSON_THROW_ON_ERROR),
                ]],
            ]);

            self::assertSame($expected[$i], $client->status('job-77')->progress, 'wire progress ' . var_export($wire, true));
        }
    }

    #[DataProvider('w5vStatusWordRows')]
    public function testTheClosedStatusVocabularyDecodesEveryRecordedWord(string $fixture, MediaJobStatus $expected): void
    {
        $client = $this->w5vClient(['GET /v1/videos/job-77' => [$this->w5vFixtureRow($fixture)]]);

        $snapshot = $client->status('job-77');

        self::assertSame($expected, $snapshot->state);
        self::assertSame($expected->terminal(), $snapshot->terminal());

        if ($expected === MediaJobStatus::Running) {
            self::assertSame(0.5, $snapshot->progress);
            self::assertSame(12.5, $snapshot->etaSeconds);
        }
    }

    /**
     * @return array<string, array{0: string, 1: MediaJobStatus}>
     */
    public static function w5vStatusWordRows(): array
    {
        return [
            'queued' => ['jobs-queued.json', MediaJobStatus::Queued],
            'processing' => ['jobs-running.json', MediaJobStatus::Running],
            'completed' => ['jobs-done-b64.json', MediaJobStatus::Done],
            'failed' => ['jobs-failed.json', MediaJobStatus::Failed],
            'cancelled' => ['jobs-cancelled.json', MediaJobStatus::Cancelled],
        ];
    }

    public function testAnUnknownStatusWordRefusesInsteadOfGuessingATransition(): void
    {
        $client = $this->w5vClient(['GET /v1/videos/job-77' => [$this->w5vFixtureRow('jobs-unknown.json')]]);

        try {
            $client->status('job-77');
            self::fail('expected the closed vocabulary to refuse an unknown state word');
        } catch (SdException $e) {
            self::assertStringContainsString('vocabulary is closed', $e->getMessage());
            self::assertStringContainsString('teleporting', $e->getMessage());
        }
    }

    public function testAFailedJobCarriesTheServerMessageThrough(): void
    {
        $client = $this->w5vClient(['GET /v1/videos/job-77' => [$this->w5vFixtureRow('jobs-failed.json')]]);

        $snapshot = $client->status('job-77');

        self::assertSame(MediaJobStatus::Failed, $snapshot->state);
        self::assertSame('CUDA out of memory on the worker', $snapshot->serverMessage);
        self::assertTrue($snapshot->terminal());
    }

    public function testAListPayloadIsRefusedWhereAnObjectIsExpected(): void
    {
        $client = $this->w5vClient(['GET /v1/videos/job-77' => [['status' => 200, 'body' => '[1,2]']]]);

        $this->expectException(SdException::class);
        $this->expectExceptionMessage('expected a JSON object payload');

        $client->status('job-77');
    }

    public function testTheLifecycleQueuedRunningDoneLandsAnMp4FileAndBeatsEveryPoll(): void
    {
        $calls = [];
        $sleeps = [];
        $frames = [];
        $client = $this->w5vClient([
            'POST /v1/videos' => [$this->w5vFixtureRow('jobs-submit.json')],
            'GET /v1/videos/job-77' => [
                $this->w5vFixtureRow('jobs-queued.json'),
                $this->w5vFixtureRow('jobs-running.json'),
                $this->w5vFixtureRow('jobs-done-b64.json'),
            ],
        ], calls: $calls);

        $taskId = $client->submit(['prompt' => 'x']);
        self::assertSame('job-77', $taskId);

        $beats = 0;
        $summary = JobPollLoop::run(
            $client,
            $taskId,
            static function () use (&$beats): void {
                $beats++;
            },
            static fn (): bool => false,
            sleeper: static function (float $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
            onFrame: static function (JobSnapshot $snapshot) use (&$frames): void {
                $frames[] = $snapshot->state;
            },
        );

        self::assertSame(3, $summary->polls);
        self::assertSame(3, $beats);
        self::assertTrue($summary->beatPerPoll());
        self::assertSame(JobPollSummary::TERMINATION_SERVER_FINISHED, $summary->termination);
        // 1 s video cadence (NOT the 0.5 s image default) and no sleep after the terminal frame.
        self::assertSame([1.0, 1.0], $sleeps);
        self::assertSame(
            [MediaJobStatus::Queued, MediaJobStatus::Running, MediaJobStatus::Done],
            $frames,
        );

        $artifact = $client->fetch($taskId, $summary->last, $this->w5vStore());
        self::assertNotNull($artifact->path());
        self::assertFileExists($artifact->path());
        self::assertStringStartsWith("\x00\x00\x00\x20ftypisom", (string) file_get_contents($artifact->path()));
    }

    public function testTheUrlShapeIsFetchedThroughTheGuardAndOnlyTheServerRelativePathDials(): void
    {
        $calls = [];
        $client = $this->w5vClient([
            'GET /downloads/job-77.mp4' => [['status' => 200, 'body' => 'BINARY', 'contentType' => 'video/mp4']],
        ], calls: $calls);

        $artifact = $client->fetch('job-77', JobSnapshot::fromArray($this->w5vFixture('jobs-done-url.json')), $this->w5vStore());

        self::assertSame('BINARY', file_get_contents($artifact->path()));
        // url wins over the co-present file_path; the guard recomposed path stays server-relative.
        self::assertSame([['method' => 'GET', 'path' => '/downloads/job-77.mp4', 'json' => []]], $calls);
    }

    public function testAnOffOriginResultUrlIsRefusedWithoutEverDialling(): void
    {
        $calls = [];
        $client = $this->w5vClient([], calls: $calls);

        $snapshot = JobSnapshot::fromArray(['status' => 'completed', 'url' => 'http://evil.test/x.mp4']);

        try {
            $client->fetch('job-77', $snapshot, $this->w5vStore());
            self::fail('expected an off-origin result URL to be refused');
        } catch (SdException $e) {
            self::assertStringContainsString('outside the configured origin', $e->getMessage());
        }

        self::assertSame([], $calls);
    }

    public function testAServerLocalPathIsFetchedViaTheContentRouteAndNeverTrustedAsAPath(): void
    {
        $calls = [];
        $client = $this->w5vClient([
            'GET /v1/videos/job-77/content' => [['status' => 200, 'body' => 'MP4BYTES', 'contentType' => 'video/mp4']],
        ], calls: $calls);

        $artifact = $client->fetch('job-77', JobSnapshot::fromArray($this->w5vFixture('jobs-done-path-only.json')), $this->w5vStore());

        self::assertSame('MP4BYTES', file_get_contents($artifact->path()));
        self::assertSame('GET', $calls[0]['method']);
        self::assertSame('/v1/videos/job-77/content', $calls[0]['path']);
        // The literal path string was never opened as a filesystem path.
        self::assertFileDoesNotExist('/nonexistent-local/job-77.mp4');
    }

    public function testAFinishedJobWithoutAnyResultReferenceRefusesHonestly(): void
    {
        $client = $this->w5vClient([]);

        $this->expectException(SdException::class);
        $this->expectExceptionMessage('no fetchable result reference');

        $client->fetch('job-77', JobSnapshot::fromArray($this->w5vFixture('jobs-done-empty.json')), $this->w5vStore());
    }

    public function testWhitespaceLadenBase64IsRefusedNotLenientlySkipped(): void
    {
        // Premise pin: PHP's STRICT decode SKIPS trailing whitespace, so the
        // refusal must be an explicit gate — without it the plan's coercion
        // would silently pass. This assert is the gate's reason to exist.
        self::assertNotFalse(base64_decode(self::W5V_MP4_STUB_B64 . "\n", true));

        $client = $this->w5vClient([]);
        $snapshot = JobSnapshot::fromArray(['status' => 'completed', 'b64' => self::W5V_MP4_STUB_B64 . "\n"]);

        $this->expectException(SdException::class);
        $this->expectExceptionMessage('not strict base64');

        $client->fetch('job-77', $snapshot, $this->w5vStore());
    }

    public function testAnOutOfAlphabetBase64PayloadIsRefused(): void
    {
        $client = $this->w5vClient([]);
        $snapshot = JobSnapshot::fromArray(['status' => 'completed', 'b64' => 'AAAA$%']);

        $this->expectException(SdException::class);
        $this->expectExceptionMessage('not strict base64');

        $client->fetch('job-77', $snapshot, $this->w5vStore());
    }

    public function testAnInAlphabetPayloadThatFailsToDecodeRefuses(): void
    {
        $client = $this->w5vClient([]);
        $snapshot = JobSnapshot::fromArray(['status' => 'completed', 'b64' => '=']);

        $this->expectException(SdException::class);
        $this->expectExceptionMessage('decode of the result payload failed');

        $client->fetch('job-77', $snapshot, $this->w5vStore());
    }

    public function testAVideosListEnvelopeIsUnwrappedIntoTheArtifact(): void
    {
        $client = $this->w5vClient([]);

        $artifact = $client->fetch('job-77', JobSnapshot::fromArray($this->w5vFixture('jobs-done-videos-list.json')), $this->w5vStore());

        self::assertStringStartsWith("\x00\x00\x00\x20ftypisom", (string) file_get_contents($artifact->path()));
    }

    public function testCancelDeletesTheJobRouteOnTheVideoFamily(): void
    {
        $calls = [];
        $client = $this->w5vClient([
            'DELETE /v1/videos/job-77' => [['status' => 200, 'body' => '{}']],
        ], calls: $calls);

        $result = $client->cancel('job-77');

        self::assertTrue($result->is2xx());
        self::assertSame('DELETE', $calls[0]['method']);
        self::assertSame('/v1/videos/job-77', $calls[0]['path']);
    }

    public function testCancelOnTheSdApiFamilyFallsBackToTheGlobalInterrupt(): void
    {
        $calls = [];
        $client = $this->w5vClient([
            'POST /sdapi/v1/interrupt' => [['status' => 200, 'body' => '{}']],
        ], EndpointFamily::SdApi, $calls);

        $result = $client->cancel('job-77');

        self::assertTrue($result->is2xx());
        self::assertSame('POST', $calls[0]['method']);
        self::assertSame('/sdapi/v1/interrupt', $calls[0]['path']);
        // No job route exists on this family — nothing may dial /v1/videos.
        foreach ($calls as $call) {
            self::assertStringNotContainsString('/v1/videos', $call['path']);
        }
    }

    public function testThePollLoopGivesUpBoundedAfterThreeConsecutiveStatusFailures(): void
    {
        $calls = [];
        $sleeps = [];
        $client = $this->w5vClient([
            'GET /v1/videos/job-77' => [['status' => 500, 'body' => '{"error":"boom"}']],
        ], calls: $calls);

        $beats = 0;

        try {
            JobPollLoop::run(
                $client,
                'job-77',
                static function () use (&$beats): void {
                    $beats++;
                },
                static fn (): bool => false,
                sleeper: static function (float $seconds) use (&$sleeps): void {
                    $sleeps[] = $seconds;
                },
            );
            self::fail('expected the bounded failure cap to fire');
        } catch (SdException $e) {
            self::assertStringContainsString('giving up after 3 consecutive video job status poll failures', $e->getMessage());
        }

        // Watchdog stays fed while the route flaps: beats on the two tolerated
        // failures, then the throw — three dials, two sleeps, never unbounded.
        self::assertSame(2, $beats);
        self::assertCount(3, $calls);
        self::assertSame([1.0, 1.0], $sleeps);
    }

    public function testCancellingBetweenPollsStopsTheLoopWithTheDoneSummary(): void
    {
        $calls = [];
        $client = $this->w5vClient([
            'GET /v1/videos/job-77' => [$this->w5vFixtureRow('jobs-queued.json')],
        ], calls: $calls);

        $cancelsAsked = 0;
        $sleeps = [];
        $summary = JobPollLoop::run(
            $client,
            'job-77',
            static function (): void {
            },
            static function () use (&$cancelsAsked): bool {
                return ++$cancelsAsked > 1;
            },
            sleeper: static function (float $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
        );
        self::assertSame([1.0], $sleeps);

        self::assertSame(JobPollSummary::TERMINATION_DONE, $summary->termination);
        self::assertSame(2, $summary->polls);
        self::assertTrue($summary->beatPerPoll());
        $statusDials = array_filter($calls, static fn (array $call): bool => $call['method'] === 'GET');
        self::assertCount(2, $statusDials);
        self::assertNotNull($summary->last);
        self::assertSame(MediaJobStatus::Queued, $summary->last->state);
    }

    public function testANonPositivePollIntervalIsRefusedBeforeAnyDial(): void
    {
        $calls = [];
        $client = $this->w5vClient([], calls: $calls);

        $this->expectException(SdException::class);
        $this->expectExceptionMessage('poll interval must be positive');

        JobPollLoop::run($client, 'job-77', static function (): void {}, static fn (): bool => false, 0.0);
    }

    public function testAnEmptyTaskIdCannotAddressAJobRoute(): void
    {
        $calls = [];
        $client = $this->w5vClient([], calls: $calls);

        try {
            $client->cancel('   ');
            self::fail('expected a blank task id to be refused');
        } catch (SdException $e) {
            self::assertStringContainsString('empty task id cannot address a job route', $e->getMessage());
        }

        self::assertSame([], $calls);
    }

    /**
     * Build the client over a scripted fake transport. Rows are FIFO per
     * "METHOD path" key; a queue of exactly one REPEATS (the cancel-between-
     * polls shape needs a stable answer), and an unscripted dial answers 404
     * naming itself so a wiring mistake fails loud instead of hanging.
     * Multipart submits ride the fake's body position, so the captured
     * 'json' row IS the form-fields map for those calls (W7 seam law).
     *
     * @param  array<string, list<array{status: int, body: string, contentType?: string}>>  $script
     * @param  list<array{method: string, path: string, json: array<string, mixed>}>|null  $calls
     */
    private function w5vClient(
        array $script,
        EndpointFamily $family = EndpointFamily::SglangDiffusion,
        ?array &$calls = null,
    ): JobClient {
        $calls = [];

        $transport = new CallableMultipartSdTransport(
            function (string $method, string $path, array $json) use (&$script, &$calls): array {
                $calls[] = ['method' => $method, 'path' => $path, 'json' => $json];
                $key = $method . ' ' . $path;

                if (($script[$key] ?? []) === []) {
                    return ['status' => 404, 'body' => '{"error":"unscripted dial ' . $key . '"}'];
                }

                if (\count($script[$key]) === 1) {
                    return $script[$key][0];
                }

                return array_shift($script[$key]);
            },
        );

        return JobClient::withBase(self::W5V_BASE, $transport, null, $family);
    }

    /**
     * @return array<string, mixed>
     */
    private function w5vFixture(string $name): array
    {
        $decoded = json_decode($this->w5vFixtureBody($name), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array{status: int, body: string}
     */
    private function w5vFixtureRow(string $name): array
    {
        return ['status' => 200, 'body' => $this->w5vFixtureBody($name)];
    }

    private function w5vFixtureBody(string $name): string
    {
        $body = file_get_contents(\dirname(__DIR__, 2) . '/fixtures/video/' . $name);
        self::assertIsString($body);

        return $body;
    }

    private function w5vStore(): MediaStore
    {
        return MediaStore::forSession('video', $this->mediaRoot);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $path = $dir . '/' . $entry;
                is_dir($path) ? $this->rrmdir($path) : @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
