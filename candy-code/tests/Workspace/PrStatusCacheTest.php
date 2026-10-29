<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workspace;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use RuntimeException;
use SugarCraft\Code\Workspace\PrStatusCache;

/**
 * C-AGT-6.e (R13): the PR/CI cache behind the web's session chips.
 *
 * The laws under test, in order of the pins: a summary build only ever
 * registers the watch and answers from storage — the gh run happens on the
 * sweep, one entry per beat; a first answer lands only after the quiet window
 * AND only for an entry that keeps being observed, so a single observation
 * (every one-shot test loop) dies before it could ever spawn; answers stand
 * for the TTL and nothing sooner; failures — throw, refusal, garbled output —
 * all answer null, never throw, and still rate-limit to the TTL. The verdict
 * fold is pinned shape by shape, and the real fetch path rides a `gh` shim on
 * PATH so nothing here talks to GitHub.
 *
 * Fake clock on every case: registration, watching and TTL math run on
 * $this->watchNow seconds, never on wall time.
 */
final class PrStatusCacheTest extends TestCase
{
    private float $watchNow = 1000.0;

    /** @var list<array{0: string, 1: string}> */
    private array $fetchCalls = [];

    /** @var list<string> */
    private array $prShimPaths = [];

    private string|false $originalPath;

    /** Real watchable directory: the cache only keeps entries whose root exists. */
    private string $watchDir;

    protected function setUp(): void
    {
        PrStatusCache::clearForTesting();
        $this->watchNow = 1000.0;
        $this->fetchCalls = [];
        PrStatusCache::useClockForTesting(fn (): float => $this->watchNow);
        $this->originalPath = getenv('PATH');
        $this->watchDir = sys_get_temp_dir() . '/crush_pr_watch_' . uniqid((string) getmypid(), true);
        mkdir($this->watchDir, 0777, true);
    }

    protected function tearDown(): void
    {
        PrStatusCache::clearForTesting();
        if (is_dir($this->watchDir)) {
            @rmdir($this->watchDir);
        }
        if ($this->originalPath === false) {
            putenv('PATH');
        } else {
            putenv('PATH=' . $this->originalPath);
        }
        foreach ($this->prShimPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            } else {
                @rmdir($path);
            }
        }
        $this->prShimPaths = [];
    }

    public function testAColdSummaryBuildAnswersNullAndFetchesNothing(): void
    {
        $loop = $this->watchTimerLoop();
        $this->recordFetcher(static fn (): ?array => ['number' => 1, 'state' => 'OPEN', 'url' => 'u', 'checks' => 'pass']);

        $this->assertNull(PrStatusCache::observe('feature/x', $this->watchDir, $loop));
        $this->assertSame([], $this->fetchCalls, 'observe() registers, it never fetches');
        $this->assertCount(1, $loop->armed, 'one shared watch timer, armed once');
        $this->assertSame(
            PrStatusCache::FIRST_FETCH_DELAY_SECONDS,
            $loop->armed[0],
            'the first beat wakes only after the quiet window',
        );
    }

    public function testAKeptWatchedEntryFetchesWhenItsFirstBeatIsDue(): void
    {
        $answer = ['number' => 7, 'state' => 'OPEN', 'url' => 'https://example.invalid/pull/7', 'checks' => 'pass'];
        $loop = $this->watchTimerLoop();
        $this->recordFetcher(static fn (): array => $answer);

        $this->assertNull(PrStatusCache::observe('feature/pr', $this->watchDir, $loop));
        $this->watchNow = 1007.0;
        $this->assertNull(PrStatusCache::observe('feature/pr', $this->watchDir, $loop), 'the beat has not landed yet');

        $this->watchNow = 1015.001;
        PrStatusCache::sweep();
        $this->assertSame([['feature/pr', $this->watchDir]], $this->fetchCalls);
        $this->assertSame($answer, PrStatusCache::observe('feature/pr', $this->watchDir, $loop));
    }

    public function testASingleObservationDiesBeforeItEverFetches(): void
    {
        $loop = $this->watchTimerLoop();
        $this->recordFetcher(static fn (): ?array => null);

        $this->assertNull(PrStatusCache::observe('feature/lonely', $this->watchDir, $loop));
        $this->watchNow = 1100.0;
        PrStatusCache::sweep();

        $this->assertSame([], $this->fetchCalls, 'nobody re-observed the key within the watch window — dropped unrefreshed');
        $this->assertNull(PrStatusCache::observe('feature/lonely', $this->watchDir, $loop), 're-registering is still cold');
    }

    public function testAFetchedAnswerStandsForTheWholeTtl(): void
    {
        $loop = $this->watchTimerLoop();
        $answer = ['number' => 9, 'state' => 'OPEN', 'url' => 'https://example.invalid/pull/9', 'checks' => 'fail'];
        $this->recordFetcher(static fn (): array => $answer);

        PrStatusCache::observe('feature/ttl', $this->watchDir, $loop);
        $this->watchNow = 1007.0;
        PrStatusCache::observe('feature/ttl', $this->watchDir, $loop);
        $this->watchNow = 1015.001;
        PrStatusCache::sweep();
        $this->assertCount(1, $this->fetchCalls);

        $this->watchNow = 1070.0;
        $this->assertSame($answer, PrStatusCache::observe('feature/ttl', $this->watchDir, $loop));
        $this->watchNow = 1074.9;
        PrStatusCache::observe('feature/ttl', $this->watchDir, $loop);
        PrStatusCache::sweep();
        $this->assertCount(1, $this->fetchCalls, '15 + 60 is not due until 75.001');
    }

    public function testTheAnswerRefreshesOnlyAfterTheTtlElapses(): void
    {
        $loop = $this->watchTimerLoop();
        $answers = [
            ['number' => 1, 'state' => 'OPEN', 'url' => 'https://example.invalid/one', 'checks' => 'pending'],
            ['number' => 1, 'state' => 'MERGED', 'url' => 'https://example.invalid/one', 'checks' => 'pass'],
        ];
        $this->recordFetcher(static function () use (&$answers): ?array {
            $next = array_shift($answers);

            return $next ?? $answers[0] ?? null;
        });

        PrStatusCache::observe('feature/turn', $this->watchDir, $loop);
        $this->watchNow = 1007.0;
        PrStatusCache::observe('feature/turn', $this->watchDir, $loop);
        $this->watchNow = 1015.001;
        PrStatusCache::sweep();
        $this->watchNow = 1016.0;
        $this->assertSame('OPEN', PrStatusCache::observe('feature/turn', $this->watchDir, $loop)['state']);

        $this->watchNow = 1070.0;
        PrStatusCache::observe('feature/turn', $this->watchDir, $loop);
        $this->watchNow = 1075.05;
        PrStatusCache::sweep();
        $this->assertCount(2, $this->fetchCalls);
        $this->assertSame('MERGED', PrStatusCache::observe('feature/turn', $this->watchDir, $loop)['state']);
    }

    public function testAThrowingFetcherIsSwallowedAndRateLimitedToTheTtl(): void
    {
        $loop = $this->watchTimerLoop();
        $this->recordFetcher(static function (): array {
            throw new RuntimeException('gh exploded');
        });

        PrStatusCache::observe('feature/boom', $this->watchDir, $loop);
        $this->watchNow = 1007.0;
        PrStatusCache::observe('feature/boom', $this->watchDir, $loop);
        $this->watchNow = 1015.001;
        PrStatusCache::sweep();
        $this->assertCount(1, $this->fetchCalls);

        $this->assertNull(PrStatusCache::observe('feature/boom', $this->watchDir, $loop), 'the failure answers null, never a chip');
        $this->watchNow = 1016.1;
        PrStatusCache::sweep();
        $this->assertCount(1, $this->fetchCalls, 'a failure still stamps the beat — retries ride the TTL');
    }

    public function testANullFetcherResultKeepsTheChipUnregistered(): void
    {
        $loop = $this->watchTimerLoop();
        $this->recordFetcher(static fn (): ?array => null);

        PrStatusCache::observe('feature/none', $this->watchDir, $loop);
        $this->watchNow = 1007.0;
        PrStatusCache::observe('feature/none', $this->watchDir, $loop);
        $this->watchNow = 1015.001;
        PrStatusCache::sweep();

        $this->assertNull(PrStatusCache::observe('feature/none', $this->watchDir, $loop));
    }

    public function testADeadWorkspaceIsDroppedWithoutSpawningAnything(): void
    {
        $loop = $this->watchTimerLoop();
        $this->recordFetcher(static fn (): ?array => null);
        $gone = sys_get_temp_dir() . '/crush_pr_cache_gone_' . uniqid((string) getmypid(), true);

        PrStatusCache::observe('feature/dead', $gone, $loop);
        $this->watchNow = 1014.0;
        PrStatusCache::observe('feature/dead', $gone, $loop);
        $this->watchNow = 1015.001;
        PrStatusCache::sweep();

        $this->assertSame([], $this->fetchCalls, 'is_dir fails — the entry is pruned, not fetched');
    }

    public function testBranchlessAndRootlessSessionsRegisterNothing(): void
    {
        $loop = $this->watchTimerLoop();
        $this->recordFetcher(static fn (): ?array => null);

        $this->assertNull(PrStatusCache::observe(null, '/root/inert', $loop));
        $this->assertNull(PrStatusCache::observe('', '/root/inert', $loop));
        $this->assertNull(PrStatusCache::observe('feature/x', null, $loop));
        $this->assertNull(PrStatusCache::observe('feature/x', '', $loop));

        $this->assertSame([], $this->fetchCalls);
        $this->assertSame([], $loop->armed, 'no watch, no timer');
    }

    public function testTheChainSharesOneTimerAndMovesWholeWithTheLoop(): void
    {
        $first = $this->watchTimerLoop();
        $this->recordFetcher(static fn (): ?array => null);

        PrStatusCache::observe('feature/a', $this->watchDir, $first);
        PrStatusCache::observe('feature/b', $this->watchDir, $first);
        $this->assertCount(1, $first->armed, 'the second key joins the armed beat');
        $this->assertSame(0, $first->cancels);

        $second = $this->watchTimerLoop();
        PrStatusCache::observe('feature/a', $this->watchDir, $second);
        $this->assertSame(1, $first->cancels, 'a different loop retires the pending beat');
        $this->assertCount(1, $second->armed);
    }

    public function testTheSummaryPathOnlyRegistersTheWatch(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Protocol/ServerContext.php');
        $start = strpos($source, 'public function summary(');
        $this->assertIsInt($start);
        $end = strpos($source, 'public function summaryOf(', $start);
        $this->assertIsInt($end);
        $slice = substr($source, $start, $end - $start);

        $this->assertStringContainsString('PrStatusCache::observe(', $slice, 'summary() consults the cache');
        $this->assertStringNotContainsString('GhRunner', $slice, 'and never reaches the runner itself');
        $this->assertStringNotContainsString('sweep', $slice, 'nor drives a refresh beat inline');
    }

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function rollupShapes(): array
    {
        return [
            'empty' => [[], 'pending'],
            'missing' => [null, 'pending'],
            'not-a-list' => ['nope', 'pending'],
            'green run' => [[['conclusion' => 'SUCCESS', 'status' => 'COMPLETED']], 'pass'],
            'neutral and skipped count green' => [[['conclusion' => 'NEUTRAL'], ['conclusion' => 'SKIPPED']], 'pass'],
            'one failure poisons the rollup' => [[['conclusion' => 'SUCCESS'], ['conclusion' => 'TIMED_OUT']], 'fail'],
            'commit status failure' => [[['state' => 'FAILURE']], 'fail'],
            'commit status pending' => [[['state' => 'PENDING']], 'pending'],
            'expected is still rolling' => [[['state' => 'EXPECTED']], 'pending'],
            'mixed green shapes stay green' => [[['state' => 'SUCCESS'], ['conclusion' => 'SKIPPED']], 'pass'],
            'queued check reports no verdict' => [[['status' => 'IN_PROGRESS']], 'pending'],
            'completed without conclusion' => [[['status' => 'COMPLETED']], 'pending'],
            'unreadable item shape' => [['not-an-array'], 'pending'],
        ];
    }

    #[DataProvider('rollupShapes')]
    public function testTheRollupFoldsIntoTheChipWord(mixed $rollup, string $expected): void
    {
        $this->assertSame($expected, PrStatusCache::verdict($rollup));
    }

    public function testTheRealFetchPathReadsTheShimAnswer(): void
    {
        PrStatusCache::useFetcherForTesting(null);
        $root = $this->plantPrShim('printf \'{"number":123,"state":"OPEN","url":"https://github.example/o/r/pull/123","statusCheckRollup":[{"conclusion":"SUCCESS","status":"COMPLETED"}]}\'');
        $loop = $this->watchTimerLoop();

        $this->assertNull(PrStatusCache::observe('feature/real', $root, $loop));
        $this->watchNow = 1007.0;
        PrStatusCache::observe('feature/real', $root, $loop);
        $this->watchNow = 1015.001;
        PrStatusCache::sweep();

        $this->assertSame(
            ['number' => 123, 'state' => 'OPEN', 'url' => 'https://github.example/o/r/pull/123', 'checks' => 'pass'],
            PrStatusCache::observe('feature/real', $root, $loop),
        );
    }

    public function testTheRealFetchPathRefusesANonZeroExit(): void
    {
        PrStatusCache::useFetcherForTesting(null);
        // Valid JSON first: only the exit leg can refuse this one, so the
        // refusal pin stays load-bearing against a dropped exitCode check.
        $root = $this->plantPrShim('printf \'{"number":4,"state":"OPEN","url":"https://github.example/o/r/pull/4","statusCheckRollup":[]}\'; exit 3');
        $loop = $this->watchTimerLoop();

        PrStatusCache::observe('feature/refused', $root, $loop);
        $this->watchNow = 1007.0;
        PrStatusCache::observe('feature/refused', $root, $loop);
        $this->watchNow = 1015.001;
        PrStatusCache::sweep();

        $this->assertNull(PrStatusCache::observe('feature/refused', $root, $loop));
    }

    public function testTheRealFetchPathRefusesGarbledOutput(): void
    {
        PrStatusCache::useFetcherForTesting(null);
        $root = $this->plantPrShim('printf \'not json at all\'');
        $loop = $this->watchTimerLoop();

        PrStatusCache::observe('feature/garbled', $root, $loop);
        $this->watchNow = 1007.0;
        PrStatusCache::observe('feature/garbled', $root, $loop);
        $this->watchNow = 1015.001;
        PrStatusCache::sweep();

        $this->assertNull(PrStatusCache::observe('feature/garbled', $root, $loop));
    }

    /** @param callable(string, string): (array<string, mixed>|null) $fetcher */
    private function recordFetcher(callable $fetcher): void
    {
        $calls = &$this->fetchCalls;
        PrStatusCache::useFetcherForTesting(static function (string $branch, string $root) use (&$calls, $fetcher): ?array {
            $calls[] = [$branch, $root];

            return $fetcher($branch, $root);
        });
    }

    /**
     * A loop that records every beat the chain arms and never fires one; the
     * tests drive {@see PrStatusCache::sweep()} directly against the fake clock.
     */
    private function watchTimerLoop()
    {
        return new class implements LoopInterface {
            /** @var list<float> */
            public array $armed = [];

            public int $cancels = 0;

            public function addReadStream($stream, $listener)
            {
            }

            public function addWriteStream($stream, $listener)
            {
            }

            public function removeReadStream($stream)
            {
            }

            public function removeWriteStream($stream)
            {
            }

            public function addTimer($interval, $callback)
            {
                $this->armed[] = (float) $interval;

                return new class($interval, $callback) implements TimerInterface {
                    public function __construct(private mixed $interval, private $callback)
                    {
                    }

                    public function getInterval()
                    {
                        return $this->interval;
                    }

                    public function getCallback()
                    {
                        return $this->callback;
                    }

                    public function isPeriodic()
                    {
                        return false;
                    }
                };
            }

            public function addPeriodicTimer($interval, $callback)
            {
                throw new RuntimeException('the watch chain is one-shot; a periodic beat would outlive the test');
            }

            public function cancelTimer(TimerInterface $timer)
            {
                $this->cancels++;
            }

            public function futureTick($listener)
            {
            }

            public function addSignal($signal, $listener)
            {
            }

            public function removeSignal($signal, $listener)
            {
            }

            public function run()
            {
            }

            public function stop()
            {
            }
        };
    }

    /**
     * A repo dir plus a PATH-first `gh` printing $body; returns the repo dir.
     * The shim idiom follows {@see GhRunnerTest}: a directory first on PATH
     * holding a `#!/bin/sh` script — but every answer here is fixed prose,
     * so no argument recording exists to share a helper with that suite.
     */
    private function plantPrShim(string $body): string
    {
        $base = sys_get_temp_dir() . '/crush_pr_status_' . uniqid((string) getmypid(), true);
        $bin = $base . '/bin';
        $repo = $base . '/repo';
        mkdir($bin, 0777, true);
        mkdir($repo, 0777, true);
        $this->prShimPaths = [$bin . '/gh', $repo, $bin, $base, ...$this->prShimPaths];

        file_put_contents($bin . '/gh', "#!/bin/sh\n" . $body . "\n");
        chmod($bin . '/gh', 0755);
        putenv('PATH=' . $bin . ':' . ((string) (getenv('PATH') ?: '/usr/bin:/bin')));

        return $repo;
    }
}
