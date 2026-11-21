<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Media\Voice;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use ReflectionMethod;
use ReflectionProperty;
use SugarCraft\Code\App\VoiceTakeStoppedMsg;
use SugarCraft\Code\Media\Voice\AudioRecording;
use SugarCraft\Code\Media\Voice\AudioRecorder;
use SugarCraft\Code\Media\Voice\VoiceConfig;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\Util\Proc\BoundedShutdown;

/**
 * C-TUI-6.l recorder laws: the design's argv ladder is pinned shape for
 * shape, every refusal answers with a token and a reason rather than a
 * silent empty take, and the take's file is 0600 before any audio exists.
 * The lifecycle tests run against a shim binary on PATH, so no host needs
 * a microphone for these to execute — and none can fake their way green.
 */
final class AudioRecorderTest extends TestCase
{
    use HomeSandboxTrait;

    private ?string $recorderPathRestore = null;

    private ?string $recorderBinDir = null;

    /** @var list<string> */
    private array $recorderScratch = [];

    protected function tearDown(): void
    {
        AudioRecorder::resetForTesting();

        $this->restoreHomeSandbox();

        if ($this->recorderPathRestore !== null) {
            \putenv('PATH=' . $this->recorderPathRestore);
            $this->recorderPathRestore = null;
            $this->recorderBinDir = null;
        }

        foreach ($this->recorderScratch as $dir) {
            self::recorderPruneTree($dir);
        }

        $this->recorderScratch = [];
    }

    public function testTheAlsaArgvCarriesTheDesignShapeWithItsSelfStopCap(): void
    {
        $argv = AudioRecorder::argv(AudioRecorder::BACKEND_ALSA, 120, '/tmp/ct6l-take.wav');

        self::assertSame([
            'arecord',
            '-q',
            '-f',
            'S16_LE',
            '-r',
            '16000',
            '-c',
            '1',
            '-d',
            '120',
            '/tmp/ct6l-take.wav',
        ], $argv, 'an orphaned arecord must stop itself at the cap, not be waited on forever');
    }

    public function testTheSoxArgvCarriesTheDesignShapeWithItsTrimCap(): void
    {
        $argv = AudioRecorder::argv(AudioRecorder::BACKEND_SOX, 45, '/tmp/ct6l-take.wav');

        self::assertSame([
            'sox',
            '-q',
            '-d',
            '-r',
            '16000',
            '-c',
            '1',
            '-b',
            '16',
            '/tmp/ct6l-take.wav',
            'trim',
            '0',
            '45',
        ], $argv, 'the sox arm caps through trim, and the cap number rides verbatim');
    }

    public function testAnUnknownBackendArguesNoArgv(): void
    {
        self::assertSame([], AudioRecorder::argv('ffmpeg', 120, '/tmp/ct6l-take.wav'),
            'ffmpeg is a named follow-up, not a silently-adopted third backend');
    }

    public function testProbeAnswersArecordThenSoxThenNothing(): void
    {
        $both = static fn (string $binary): string => '/usr/bin/' . $binary;
        $onlySox = static fn (string $binary): string => $binary === 'sox' ? '/usr/bin/sox' : '';
        $none = static fn (string $binary): string => '';

        self::assertSame(AudioRecorder::BACKEND_ALSA, AudioRecorder::probe($both),
            'arecord is the design-first backend and wins on a host carrying both');
        self::assertSame(AudioRecorder::BACKEND_SOX, AudioRecorder::probe($onlySox));
        self::assertSame('', AudioRecorder::probe($none));
    }

    public function testHasAudioReadsMoreThanABareHeader(): void
    {
        $dir = $this->recorderTempDir();

        $bare = $dir . '/bare.wav';
        \file_put_contents($bare, \str_repeat('z', AudioRecorder::WAV_HEADER_BYTES));
        $samples = $dir . '/samples.wav';
        \file_put_contents($samples, \str_repeat('z', AudioRecorder::WAV_HEADER_BYTES + 1));

        self::assertFalse(AudioRecorder::hasAudio($bare), 'a bare header is not a take');
        self::assertTrue(AudioRecorder::hasAudio($samples));
        self::assertFalse(AudioRecorder::hasAudio($dir . '/absent.wav'));
    }

    public function testStartRefusesNoBackendWithoutDemandingAHome(): void
    {
        $result = AudioRecorder::start(VoiceConfig::off(), static fn (string $binary): string => '');

        self::assertNull($result['recording']);
        self::assertSame(AudioRecorder::REFUSAL_NO_BACKEND, $result['token'],
            'no recorder is reported unavailable, never faked into an empty take');
    }

    public function testStartRefusesNoHomeWhenTheHomeCannotBeOwned(): void
    {
        // '/' is a home this process cannot own — the refusal under
   // test. create:false: the spelling is asserted about, never created.
        $this->useHomeSandbox('/', false);

        $result = AudioRecorder::start(
            VoiceConfig::off(),
            static fn (string $binary): string => '/bin/' . $binary,
        );

        self::assertNull($result['recording']);
        self::assertSame(AudioRecorder::REFUSAL_NO_HOME, $result['token']);
    }

    public function testStartRefusesAPlantedSymlinkOverTheVoiceDir(): void
    {
        $home = $this->recorderAdoptFakeHome();
        $elsewhere = $this->recorderTempDir();

        $cache = $home . '/.candy-code/cache';
        self::assertTrue(mkdir($cache, 0o700, true));
        self::assertTrue(symlink($elsewhere, $cache . '/voice'));

        $result = AudioRecorder::start(
            VoiceConfig::off(),
            static fn (string $binary): string => '/bin/' . $binary,
        );

        self::assertNull($result['recording'], 'a planted symlink is refused, never followed');
        self::assertSame(AudioRecorder::REFUSAL_CACHE_REFUSED, $result['token']);
        self::assertStringContainsString('symbolic link', $result['reason']);
    }

    public function testAReservedTakePathRefusesWhateverAlreadySitsThere(): void
    {
        $dir = $this->recorderTempDir();
        $planted = $dir . '/take-planted.wav';
        \file_put_contents($planted, 'pre-existing');

        $reserve = new ReflectionMethod(AudioRecorder::class, 'reserveTake');

        self::assertStringContainsString('already sits', (string) $reserve->invoke(null, $planted),
            'a take is never adopted off an existing inode — truncation is not adoption');
    }

    public function testTheFullTakeLandsZeroSixZeroDiesOnCancelAndCarriesTheCapThroughSpawn(): void
    {
        $home = $this->recorderAdoptFakeHome();
        $shimDir = $this->recorderPlantShim('arecord', 'sleep 30');

        $config = VoiceConfig::fromSettings([
            'voice.baseUrl' => 'https://example.invalid/v1',
            'voice.maxSeconds' => '7',
        ]);
        $result = AudioRecorder::start($config);

        self::assertNotNull($result['recording'], 'the shim on PATH is a real recorder for this test');
        $recording = $result['recording'];
        self::assertSame(AudioRecorder::BACKEND_ALSA, $recording->backend);
        self::assertSame(7, $recording->maxSeconds, 'the configured cap rides the take');
        self::assertFileExists($recording->path);

        $perms = @\fileperms($recording->path);
        self::assertIsInt($perms);
        self::assertSame(0o600, $perms & 0o777, 'the mode is settled before any audio exists');

        $voiceDir = $home . '/.candy-code/cache/voice';
        $dirPerms = @\fileperms($voiceDir);
        self::assertIsInt($dirPerms);
        self::assertSame(0o700, $dirPerms & 0o777);

        $log = $shimDir . '/arecord.log';
        $spelled = $this->recorderWaitFor(static fn (): string => (string) (@\file_get_contents($log) ?: ''), 2.0);
        self::assertNotSame('', $spelled, 'the spawn carried argv through containment');
        self::assertStringContainsString('-d', $spelled);
        self::assertStringContainsString('7', $spelled, 'the cap that stopped the take is the configured cap');
        self::assertStringContainsString('S16_LE', $spelled);
        self::assertStringContainsString($recording->path, $spelled, 'spawn reached the argv the design spelled');

        AudioRecorder::cancel($recording);

        self::assertFileDoesNotExist($recording->path, 'cancel deletes the take either way');
    }

    public function testStopFinalizesAndReportsAnEmptySampleHonestly(): void
    {
        $this->recorderAdoptFakeHome();
        // POSIX sh: the for-loop leaves $last at the final argument, which is
        // the take path in both argv shapes.
        // Builtin-only body: PHP's stat cache used to make the parent read the
        // take's pre-sample zero size (fixed in hasAudio with clearstatcache);
        // the filler stays shell builtins so the take's shape never depends on
        // PATH resolution inside the contained child.
        $this->recorderPlantShim('arecord', 'for x in "$@"; do last="$x"; done; i=0; while [ $i -lt 40 ]; do printf 0123456789 >> "$last"; i=$((i+1)); done; echo ok > "$last.done"');

        $result = AudioRecorder::start(VoiceConfig::fromSettings([
            'voice.baseUrl' => 'https://example.invalid/v1',
        ]));
        self::assertNotNull($result['recording']);

        // The child writes samples asynchronously; a real user speaks into
        // the take before stop lands. Bound the wait so a regression that
        // never fills the file fails rather than hangs.
        $path = $result['recording']->path;
        $filled = $this->recorderWaitFor(
            static fn (): string => AudioRecorder::hasAudio($path) ? $path : '',
            2.0,
        );
        $logSpelled = (string) @\file_get_contents((string) $this->recorderBinDir . '/arecord.log');
        $logLines = array_values(array_filter(explode("\n", $logSpelled), static fn (string $l): bool => $l !== ''));
        self::assertNotSame('', $filled, 'the take never collected samples; log lines=' . json_encode($logLines)
            . ' size=' . var_export(@\filesize($path), true)
            . ' marker=' . var_export(is_file($path . '.done'), true));

        $stopped = $this->recorderSettleStop($result['recording']);
        self::assertSame($result['recording'], $stopped->recording, 'the stop answers with the take it ended');
        self::assertTrue($stopped->hasAudio, 'the shim wrote samples; stop must read them back');
        self::assertFileExists($result['recording']->path, 'stop keeps the finalized file for the caller');

        $idle = new AudioRecording(
            $this->recorderTempDir() . '/take-dead.wav',
            AudioRecorder::BACKEND_SOX,
            120,
            \microtime(true),
        );
        self::assertFalse($this->recorderSettleStop($idle)->hasAudio,
            'a take with nothing behind it answers false, not an exception');
    }

    public function testStopReturnsACmdAndLeavesTheTakeAtOnce(): void
    {
        $this->recorderAdoptFakeHome();
        $this->recorderPlantShim('arecord', 'sleep 30');

        $result = AudioRecorder::start(VoiceConfig::fromSettings([
            'voice.baseUrl' => 'https://example.invalid/v1',
        ]));
        self::assertNotNull($result['recording']);

        $cmd = AudioRecorder::stop($result['recording']);

        self::assertInstanceOf(\Closure::class, $cmd, 'stop hands back a Cmd, never a verdict');
        self::assertTrue(AudioRecorder::stopping(), 'the child stays tracked until the Cmd sees it gone');

        $stopped = $this->recorderSettleStop($result['recording'], $cmd);

        self::assertFalse(AudioRecorder::stopping(), 'a settled stop forgets the child');
        self::assertFalse($stopped->hasAudio, 'the sleeping shim captured nothing, and the answer says so');
    }

    public function testATermTrappingRecorderIsKilledWithinTheGraceBoundWithoutBlockingTheLoop(): void
    {
        $this->recorderAdoptFakeHome();
        // SIG_IGN survives exec, so the sleep the shim waits on ignores INT
        // and TERM too: only the KILL rung can end this take.
        $this->recorderPlantShim('arecord', "trap '' INT TERM; sleep 30");

        $result = AudioRecorder::start(VoiceConfig::fromSettings([
            'voice.baseUrl' => 'https://example.invalid/v1',
        ]));
        self::assertNotNull($result['recording']);
        $recording = $result['recording'];

        $live = new ReflectionProperty(AudioRecorder::class, 'live');
        $process = $live->getValue()[$recording->path]['process'] ?? null;
        self::assertIsResource($process);
        $pid = (int) (\proc_get_status($process)['pid'] ?? 0);
        $group = BoundedShutdown::groupId($process);
        self::assertGreaterThan(0, $pid);

        // Let the shim install its traps before the first signal lands.
        self::assertNotSame('', $this->recorderWaitFor(
            fn (): string => (string) (@\file_get_contents((string) $this->recorderBinDir . '/arecord.log') ?: ''),
            2.0,
        ));
        \usleep(100_000);

        $loop = Loop::get();
        $ticks = 0;
        $heartbeat = $loop->addPeriodicTimer(0.05, static function () use (&$ticks): void {
            $ticks++;
        });

        $began = \hrtime(true);
        $async = AudioRecorder::stop($recording)();
        $issueSeconds = (\hrtime(true) - $began) / 1e9;

        try {
            $stopped = $this->recorderRunUntilSettled($async);
        } finally {
            $loop->cancelTimer($heartbeat);
        }

        $elapsed = (\hrtime(true) - $began) / 1e9;

        self::assertLessThan(0.5, $issueSeconds, 'issuing the stop must not wait on the recorder');
        self::assertInstanceOf(VoiceTakeStoppedMsg::class, $stopped, 'the stop Cmd never settled');
        // SIGINT grace 1.5 s, TERM grace 0.5 s, KILL grace 0.5 s: the trapped
        // child outlives the first two rungs and dies on the third.
        self::assertGreaterThanOrEqual(1.9, $elapsed, 'the INT and TERM graces were honored before KILL');
        self::assertLessThan(2.5 + 1.0, $elapsed, 'the child died within the grace bound');
        self::assertGreaterThan(10, $ticks, 'the loop kept ticking while the ladder ran');
        self::assertFalse(\is_resource($process), 'the handle was reaped');
        self::assertFalse(\file_exists('/proc/' . $pid) && self::recorderIsLiveProc($pid), 'the recorder process is gone');
        if ($group !== null && \function_exists('posix_kill')) {
            $deadline = \microtime(true) + 1.0;
            while (@\posix_kill(-$group, 0) && \microtime(true) < $deadline) {
                \usleep(10_000);
            }
            self::assertFalse(@\posix_kill(-$group, 0), 'the KILL rung reached the trapped sleep in the group too');
        }
    }

    public function testCancelOfATermTrappingRecorderReturnsAtOnceAndKillsItOnTheLoop(): void
    {
        $this->recorderAdoptFakeHome();
        $this->recorderPlantShim('arecord', "trap '' INT TERM; sleep 30");

        $result = AudioRecorder::start(VoiceConfig::fromSettings([
            'voice.baseUrl' => 'https://example.invalid/v1',
        ]));
        self::assertNotNull($result['recording']);
        $recording = $result['recording'];

        $live = new ReflectionProperty(AudioRecorder::class, 'live');
        $process = $live->getValue()[$recording->path]['process'] ?? null;
        self::assertIsResource($process);
        $pid = (int) (\proc_get_status($process)['pid'] ?? 0);
        self::assertGreaterThan(0, $pid);

        self::assertNotSame('', $this->recorderWaitFor(
            fn (): string => (string) (@\file_get_contents((string) $this->recorderBinDir . '/arecord.log') ?: ''),
            2.0,
        ));
        \usleep(100_000);

        // The Esc press runs inside update() with the loop live: the old
        // synchronous ladder held it for the whole TERM grace.
        $began = \hrtime(true);
        AudioRecorder::cancel($recording);
        $issueSeconds = (\hrtime(true) - $began) / 1e9;

        self::assertLessThan(0.2, $issueSeconds, 'cancel must not wait on the TERM grace');
        self::assertFileDoesNotExist($recording->path, 'cancel deletes the take at once');
        self::assertTrue(AudioRecorder::stopping(), 'the dying child is still tracked, so a new take waits for the microphone');
        $late = AudioRecorder::stop($recording)();
        self::assertInstanceOf(AsyncCmd::class, $late);
        $lateAnswer = 'unsettled';
        $late->promise->then(static function (mixed $value) use (&$lateAnswer): void {
            $lateAnswer = $value;
        });
        self::assertNull($lateAnswer, 'a stop of a cancelled take answers no Msg and never transcribes the deleted file');

        $loop = Loop::get();
        $ticks = 0;
        $heartbeat = $loop->addPeriodicTimer(0.05, static function () use (&$ticks, $loop): void {
            $ticks++;
            if (!AudioRecorder::stopping()) {
                $loop->stop();
            }
        });
        $leash = $loop->addTimer(5.0, static fn () => $loop->stop());
        $loop->run();
        $loop->cancelTimer($heartbeat);
        $loop->cancelTimer($leash);

        self::assertFalse(AudioRecorder::stopping(), 'the cancel ladder settled on the loop');
        self::assertGreaterThan(5, $ticks, 'the loop kept ticking while the ladder ran');
        self::assertFalse(\is_resource($process), 'the handle was reaped');
        self::assertFalse(\file_exists('/proc/' . $pid) && self::recorderIsLiveProc($pid), 'the recorder process is gone');
    }

    public function testASecondStopOfAPendingTakeAnswersNoMsgAndLeavesTheFirstToSettle(): void
    {
        $this->recorderAdoptFakeHome();
        $this->recorderPlantShim('arecord', 'sleep 30');

        $result = AudioRecorder::start(VoiceConfig::fromSettings([
            'voice.baseUrl' => 'https://example.invalid/v1',
        ]));
        self::assertNotNull($result['recording']);
        $recording = $result['recording'];

        $first = AudioRecorder::stop($recording);
        $second = AudioRecorder::stop($recording)();
        self::assertInstanceOf(AsyncCmd::class, $second);

        $answered = 'unsettled';
        $second->promise->then(static function (mixed $value) use (&$answered): void {
            $answered = $value;
        });

        self::assertNull($answered, 'the second Cmd settles at once with no Msg: it never signals the child');
        self::assertTrue(AudioRecorder::stopping(), 'the second stop leaves the first stop in charge of the child');

        $stopped = $this->recorderSettleStop($recording, $first);
        self::assertSame($recording, $stopped->recording, 'the first stop still answers with the take');
        self::assertFalse(AudioRecorder::stopping());
    }

    public function testTeardownAllKillsTheChildOfAStopStillPendingAtExit(): void
    {
        $this->recorderAdoptFakeHome();
        // INT and TERM ignored, so the stop Cmd's ladder cannot finish before
        // the exit path runs: the child sits only in the pending-stop set.
        $this->recorderPlantShim('arecord', "trap '' INT TERM; sleep 30");

        $result = AudioRecorder::start(VoiceConfig::fromSettings([
            'voice.baseUrl' => 'https://example.invalid/v1',
        ]));
        self::assertNotNull($result['recording']);
        $recording = $result['recording'];

        $live = new ReflectionProperty(AudioRecorder::class, 'live');
        $process = $live->getValue()[$recording->path]['process'] ?? null;
        self::assertIsResource($process);
        $pid = (int) (\proc_get_status($process)['pid'] ?? 0);
        self::assertGreaterThan(0, $pid);

        self::assertNotSame('', $this->recorderWaitFor(
            fn (): string => (string) (@\file_get_contents((string) $this->recorderBinDir . '/arecord.log') ?: ''),
            2.0,
        ));
        \usleep(100_000);

        // The Cmd runs (SIGINT sent, poll timer armed) but the loop never
        // ticks again, as at process exit.
        $async = AudioRecorder::stop($recording)();
        self::assertInstanceOf(AsyncCmd::class, $async);
        self::assertSame([], $live->getValue(), 'the take left the live set, so only the stopping set holds it');
        self::assertTrue(AudioRecorder::stopping());
        self::assertTrue(self::recorderIsLiveProc($pid), 'the INT-ignoring child outlived the stop signal');

        AudioRecorder::teardownAll();

        self::assertFalse(AudioRecorder::stopping(), 'the exit path forgets the pending stop');
        self::assertFalse(\is_resource($process), 'the exit path closed the pending child handle');
        self::assertFalse(\file_exists('/proc/' . $pid) && self::recorderIsLiveProc($pid), 'the pending recorder is gone');
        self::assertFileExists($recording->path, 'a take whose stop was issued keeps its file');

        // The stop Cmd's armed poll sees the closed handle as gone and settles.
        self::assertInstanceOf(VoiceTakeStoppedMsg::class, $this->recorderRunUntilSettled($async));
    }

    public function testAVanishedBinaryIsRefusedAtProbeNotSpawnedBlind(): void
    {
        $this->recorderAdoptFakeHome();
        $binDir = $this->recorderPlantShim('arecord', 'sleep 30');
        self::assertTrue(unlink($binDir . '/arecord'));

        $result = AudioRecorder::start(VoiceConfig::fromSettings([
            'voice.baseUrl' => 'https://example.invalid/v1',
        ]));

        self::assertNull($result['recording']);
        self::assertSame(AudioRecorder::REFUSAL_NO_BACKEND, $result['token'],
            'probe and exec read the same PATH at call time, so a vanished binary is caught before spawn');
    }

    /** @return string the fake home path this test now writes under */
    private function recorderAdoptFakeHome(): string
    {
        // §7 closeout: two-sided HOME via HomeSandboxTrait (the old arm
        // moved putenv only — the one-sided shape OneSidedHomeSandbox
        // refuses).
        return $this->useHomeSandbox($this->recorderTempDir());
    }

    /** Make a shim binary answer locateOnPath AND the child PATH, and log its argv. */
    private function recorderPlantShim(string $name, string $body): string
    {
        if ($this->recorderPathRestore === null) {
            $path = \getenv('PATH');
            $this->recorderPathRestore = \is_string($path) ? $path : '';
        }

        $dir = $this->recorderBinDir ?? $this->recorderTempDir();
        $this->recorderBinDir = $dir;

        \putenv('PATH=' . $dir . ':' . $this->recorderPathRestore);

        $script = "#!/bin/sh\nprintf '%s\\n' \"$@\" > " . \escapeshellarg($dir . '/' . $name . '.log') . "\n" . $body . "\n";
        self::assertIsInt(\file_put_contents($dir . '/' . $name, $script));
        self::assertTrue(\chmod($dir . '/' . $name, 0o755));

        return $dir;
    }

    /** Run a stop Cmd on the loop until it answers, with a hard leash. */
    private function recorderSettleStop(AudioRecording $recording, ?\Closure $cmd = null): VoiceTakeStoppedMsg
    {
        $async = ($cmd ?? AudioRecorder::stop($recording))();
        $stopped = $this->recorderRunUntilSettled($async);
        self::assertInstanceOf(VoiceTakeStoppedMsg::class, $stopped, 'the stop Cmd never settled');

        return $stopped;
    }

    private function recorderRunUntilSettled(mixed $async): ?object
    {
        self::assertInstanceOf(AsyncCmd::class, $async);

        $loop = Loop::get();
        $settled = null;
        $async->promise->then(static function (object $value) use (&$settled, $loop): void {
            $settled = $value;
            $loop->stop();
        });

        if ($settled === null) {
            $leash = $loop->addTimer(10.0, static fn () => $loop->stop());
            $loop->run();
            $loop->cancelTimer($leash);
        }

        return $settled;
    }

    /** A zombie still has a /proc entry; only a running or sleeping one counts as live. */
    private static function recorderIsLiveProc(int $pid): bool
    {
        $stat = @\file_get_contents('/proc/' . $pid . '/stat');
        if (!\is_string($stat)) {
            return false;
        }

        $close = \strrpos($stat, ')');

        return $close !== false && ($stat[$close + 2] ?? 'Z') !== 'Z';
    }

    /** Poll a shim-side effect with a hard bound: a regression fails here, it never hangs. */
    private function recorderWaitFor(callable $peek, float $boundSeconds): string
    {
        $deadline = \microtime(true) + $boundSeconds;

        do {
            $answer = (string) $peek();

            if ($answer !== '') {
                return $answer;
            }

            \usleep(20_000);
        } while (\microtime(true) < $deadline);

        return '';
    }

    private function recorderTempDir(): string
    {
        $dir = \sys_get_temp_dir() . '/ct6l_' . \uniqid((string) \getmypid(), true);
        self::assertTrue(mkdir($dir, 0o700, true));
        $this->recorderScratch[] = $dir;

        return $dir;
    }

    private static function recorderPruneTree(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isLink() || $item->isFile()) {
                @\unlink($item->getPathname());
            } else {
                @\rmdir($item->getPathname());
            }
        }

        @\rmdir($dir);
    }
}
