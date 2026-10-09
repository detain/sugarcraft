<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Media;

use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Media\MediaJob;
use SugarCraft\Crush\Media\MediaJobStatus;
use SugarCraft\Crush\Media\MediaKind;

/**
 * Plan W1.2 state-machine gates: every legal edge walks, every illegal edge
 * (including same-status re-entry and terminal revivals) throws, the progress
 * ring bounds itself at PROGRESS_RING, and the dual task-id fields keep their
 * independent sentinel semantics.
 */
final class MediaJobTest extends TestCase
{
    private static function running(): MediaJob
    {
        return MediaJob::new(MediaKind::Image)->withStatus(MediaJobStatus::Running);
    }

    public function testFreshJobIsQueuedWithAMintedJobId(): void
    {
        $job = MediaJob::new(MediaKind::Image);

        $this->assertSame(MediaJobStatus::Queued, $job->status());
        $this->assertSame(MediaKind::Image, $job->kind());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $job->jobId());
        $this->assertSame([], $job->artifactPaths());
        $this->assertSame([], $job->progressFrames());
        $this->assertFalse($job->taskIdIsSet());
        $this->assertFalse($job->serverTaskIdIsSet());
        $this->assertFalse($job->errorIsSet());
    }

    public function testExplicitJobIdIsPreservedVerbatim(): void
    {
        // Checkpoint resume (W4.5) re-supplies the stored uuid — minting must stay opt-out.
        $this->assertSame('sess-7-0', MediaJob::new(MediaKind::Video, 'sess-7-0')->jobId());
    }

    public function testMintedJobIdsAreUnique(): void
    {
        $ids = [];
        for ($i = 0; $i < 50; $i++) {
            $ids[] = MediaJob::new(MediaKind::Image)->jobId();
        }

        $this->assertCount(50, array_unique($ids));
    }

    public function testHappyPathWalksQueuedRunningDone(): void
    {
        $job = MediaJob::new(MediaKind::Image)
            ->withStatus(MediaJobStatus::Running)
            ->withServerTaskId('task(image-3f2a1b)')
            ->withProgressFrame(['ratio' => 0.5])
            ->withArtifactPath('/out/img-001.png')
            ->withStatus(MediaJobStatus::Done);

        $this->assertSame(MediaJobStatus::Done, $job->status());
        $this->assertSame(['task(image-3f2a1b)'], [$job->serverTaskId()]);
        $this->assertSame(['/out/img-001.png'], $job->artifactPaths());
    }

    /**
     * @return array<string, array{MediaJob, MediaJobStatus}>
     */
    public static function illegalTransitionProvider(): array
    {
        $cancelled = MediaJob::new(MediaKind::Image)->withStatus(MediaJobStatus::Cancelled);
        $failed = MediaJob::new(MediaKind::Image)->withStatus(MediaJobStatus::Failed);
        $done = self::running()->withStatus(MediaJobStatus::Done);

        return [
            'skip the running rung' => [MediaJob::new(MediaKind::Image), MediaJobStatus::Done],
            'revive a done job' => [$done, MediaJobStatus::Running],
            'revive a failed job' => [$failed, MediaJobStatus::Running],
            'revive a cancelled job' => [$cancelled, MediaJobStatus::Queued],
            'queued back to queued' => [MediaJob::new(MediaKind::Image), MediaJobStatus::Queued],
            'running re-stamp' => [self::running(), MediaJobStatus::Running],
            'done re-stamp' => [$done, MediaJobStatus::Done],
            'running goes back to queued' => [self::running(), MediaJobStatus::Queued],
        ];
    }

    #[DataProvider('illegalTransitionProvider')]
    public function testIllegalTransitionsThrowNamingBothEnds(MediaJob $job, MediaJobStatus $to): void
    {
        $from = $job->status();
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches("/from '{$from->value}' to '{$to->value}'/");

        $job->withStatus($to);
    }

    public function testLegalFailureEdges(): void
    {
        $this->assertSame(MediaJobStatus::Failed, MediaJob::new(MediaKind::Image)->withStatus(MediaJobStatus::Failed)->status());
        $this->assertSame(MediaJobStatus::Cancelled, MediaJob::new(MediaKind::Image)->withStatus(MediaJobStatus::Cancelled)->status());
        $this->assertSame(MediaJobStatus::Failed, self::running()->withStatus(MediaJobStatus::Failed)->status());
        $this->assertSame(MediaJobStatus::Cancelled, self::running()->withStatus(MediaJobStatus::Cancelled)->status());
    }

    public function testTerminalRosterMatchesThePlanStateMachine(): void
    {
        $this->assertSame(
            ['queued', 'running', 'done', 'failed', 'cancelled'],
            array_map(static fn (MediaJobStatus $s): string => $s->value, MediaJobStatus::cases())
        );
        foreach (MediaJobStatus::cases() as $status) {
            $this->assertSame(
                in_array($status, [MediaJobStatus::Done, MediaJobStatus::Failed, MediaJobStatus::Cancelled], true),
                $status->terminal()
            );
            // The transition table's domain is exactly the five statuses.
            $this->assertArrayHasKey($status->value, MediaJob::TRANSITIONS);
        }
    }

    public function testProgressRingKeepsOnlyTheNewestFramesInOrder(): void
    {
        $job = self::running();
        for ($i = 0; $i < 25; $i++) {
            $job = $job->withProgressFrame(['seq' => $i]);
        }

        $frames = $job->progressFrames();
        $this->assertCount(MediaJob::PROGRESS_RING, $frames);
        $this->assertSame(5, $frames[0]['seq'], 'oldest five were evicted');
        $this->assertSame(24, $frames[19]['seq'], 'newest rides the tail');
    }

    public function testTaskIdAndServerTaskIdAreIndependentSentinelFields(): void
    {
        $job = self::running()->withServerTaskId('task(image-9)');
        $this->assertTrue($job->serverTaskIdIsSet());
        $this->assertFalse($job->taskIdIsSet(), 'the server id must not arm the client pin');

        $pinned = $job->withTaskId('crush-pin-1');
        $this->assertSame('crush-pin-1', $pinned->taskId());
        $this->assertSame('task(image-9)', $pinned->serverTaskId(), 'setting one never touches the other');

        // Set-to-null is meaningful and distinct from never-set (§4.2 force_task_id opt-out).
        $nulled = $pinned->withTaskId(null);
        $this->assertTrue($nulled->taskIdIsSet());
        $this->assertNull($nulled->taskId());
    }

    public function testErrorCarriesItsOwnSentinel(): void
    {
        $failed = self::running()->withError('CUDA out of memory')->withStatus(MediaJobStatus::Failed);
        $this->assertTrue($failed->errorIsSet());
        $this->assertSame('CUDA out of memory', $failed->error());

        $cleared = $failed->withError(null);
        $this->assertTrue($cleared->errorIsSet());
        $this->assertNull($cleared->error());
    }

    public function testArtifactPathsAccumulateInArrivalOrder(): void
    {
        $job = self::running()
            ->withArtifactPath('/out/a.png')
            ->withArtifactPath('/out/b.png');

        $this->assertSame(['/out/a.png', '/out/b.png'], $job->artifactPaths());
    }

    /** DoD: a video job needs zero image assumptions anywhere in the model. */
    public function testVideoJobsFlowThroughTheSameSkeleton(): void
    {
        $job = MediaJob::new(MediaKind::Video)
            ->withStatus(MediaJobStatus::Running)
            ->withServerTaskId('video-abc123')
            ->withProgressFrame(['ratio' => 0.1, 'eta_seconds' => 240])
            ->withArtifactPath('/out/clip.mp4')
            ->withStatus(MediaJobStatus::Done);

        $this->assertSame(MediaKind::Video, $job->kind());
        $this->assertSame(MediaJobStatus::Done, $job->status());
        $this->assertSame(['/out/clip.mp4'], $job->artifactPaths());
    }

    public function testWithStatusIsImmutable(): void
    {
        $queued = MediaJob::new(MediaKind::Image);
        $running = $queued->withStatus(MediaJobStatus::Running);

        $this->assertNotSame($queued, $running);
        $this->assertSame(MediaJobStatus::Queued, $queued->status(), 'transitions never mutate the receiver');
    }

    public function testArrayRoundTripPreservesTheFullRecord(): void
    {
        $job = self::running()
            ->withTaskId('pin-1')
            ->withServerTaskId('task(image-xyz)')
            ->withArtifactPath('/out/1.png')
            ->withProgressFrame(['ratio' => 0.25])
            ->withError(null);

        $data = $job->toArray();
        $restored = MediaJob::fromArray($data);

        $this->assertSame($data, $restored->toArray());
        $this->assertSame($job->jobId(), $restored->jobId());
        $this->assertSame($job->status(), $restored->status());
        $this->assertTrue($restored->errorIsSet());
        $this->assertNull($restored->error());
        // Persistence shape is snake_case wire-style like every other DTO here.
        $this->assertSame(
            ['job_id', 'kind', 'status', 'artifact_paths', 'progress_frames', 'task_id', 'server_task_id', 'error'],
            array_keys($data)
        );
        $this->assertSame('running', $data['status']);
    }

    public function testUnsetSentinelsAreOmittedFromPersistenceShape(): void
    {
        $data = MediaJob::new(MediaKind::Image)->toArray();

        $this->assertArrayNotHasKey('task_id', $data);
        $this->assertArrayNotHasKey('server_task_id', $data);
        $this->assertArrayNotHasKey('error', $data);
        $this->assertFalse(array_key_exists('cost_usd', $data), 'Q-3 honesty: no cost fields on the job record');
    }

    public function testFromArrayKeepsUnsetSentinelsUnset(): void
    {
        $restored = MediaJob::fromArray(MediaJob::new(MediaKind::Image)->toArray());

        $this->assertFalse($restored->taskIdIsSet());
        $this->assertFalse($restored->serverTaskIdIsSet());
        $this->assertFalse($restored->errorIsSet());
    }

    /**
     * @return array<string, array{array<string,mixed>, string}>
     */
    public static function malformedRecordProvider(): array
    {
        $base = ['job_id' => 'j1', 'kind' => 'image', 'status' => 'queued'];

        return [
            'missing job_id' => [['kind' => 'image', 'status' => 'queued'], 'job_id'],
            'empty job_id' => [['job_id' => '', 'kind' => 'image', 'status' => 'queued'], 'job_id'],
            'unknown kind' => [array_merge($base, ['kind' => 'audio']), 'kind'],
            'unknown status' => [array_merge($base, ['status' => 'paused']), 'status'],
            'paths not a list' => [array_merge($base, ['artifact_paths' => 'x']), 'artifact_paths'],
            'path entry not string' => [array_merge($base, ['artifact_paths' => [42]]), 'strings'],
            'frames not a list' => [array_merge($base, ['progress_frames' => 'x']), 'progress_frames'],
            'task_id wrong type' => [array_merge($base, ['task_id' => 42]), 'task_id'],
        ];
    }

    #[DataProvider('malformedRecordProvider')]
    public function testFromArrayFailsFastNamingTheField(array $data, string $needle): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($needle, '/') . '/');

        MediaJob::fromArray($data);
    }
}
