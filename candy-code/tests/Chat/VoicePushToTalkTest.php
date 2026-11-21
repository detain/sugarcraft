<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use ReflectionMethod;
use ReflectionProperty;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Code\App\VoiceFailedMsg;
use SugarCraft\Code\App\VoiceTakeStoppedMsg;
use SugarCraft\Code\App\VoiceTranscribedMsg;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Media\Voice\AudioRecorder;
use SugarCraft\Code\Media\Voice\AudioRecording;
use SugarCraft\Code\Media\Voice\TranscribeCmd;
use SugarCraft\Code\Media\Voice\VoiceSession;
use SugarCraft\Code\Renderer;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\ScriptedProvider;

/**
 * C-TUI-6.m: the `chat.voice` chord wired through the TUI. Terminals deliver
 * no key-up, so push-to-talk is a TOGGLE: Alt+V arms the take, the same
 * chord again stops it and hands the WAV to the transcriber, and Esc while
 * recording throws the take away BEFORE the two-beat stop arms can read the
 * press. The transcript lands at the caret — inserted, never sent — and the
 * status bar carries the static cue while either live state holds. These
 * drive the real handlers; the engine's own contract lives in
 * `tests/Media/Voice/`.
 */
final class VoicePushToTalkTest extends TestCase
{
    use HomeSandboxTrait;

    private string $home = '';
    /** @var list<string> */
    private array $takes = [];
    private ?string $pathRestore = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = sys_get_temp_dir() . '/ct6m_p2t_' . bin2hex(random_bytes(6));
        mkdir($this->home . '/.candy-code', 0o700, true);
        $this->useHomeSandbox($this->home);
        VoiceSession::resetForTesting();
        TranscribeCmd::resetForTesting();
    }

    protected function tearDown(): void
    {
        VoiceSession::resetForTesting();
        TranscribeCmd::resetForTesting();
        if ($this->pathRestore !== null) {
            putenv('PATH=' . $this->pathRestore);
            $this->pathRestore = null;
        }
        $this->restoreHomeSandbox();
        $this->vp2tRemoveTree($this->home);

        parent::tearDown();
    }

    public function testVoiceOffChordToastsTheBaseUrlRefusalAndStartsNothing(): void
    {
        [$next, $cmd] = $this->chat()->update(self::altV());

        self::assertInstanceOf(\Closure::class, $cmd, 'a refusal schedules only its toast expiry');
        self::assertFalse(VoiceSession::active(), 'and leaves no take behind');
        // The welded off-sentence is reused verbatim (design (4)): it is the
        // one string that already names `voice.baseUrl`.
        self::assertStringContainsString(
            'voice.baseUrl',
            self::peekToast($next),
            'voice-off says what to turn on, never a silent no-op',
        );
        self::assertStringContainsString(Lang::t('media.voice.off'), self::peekToast($next));
    }

    public function testAltVEscapesTheGenericCharArmsAndTypesNothing(): void
    {
        // The chord must be claimed BEFORE the delegate-to-input family:
        // a recording press that also typed a `v` into the draft would be
        // the design's named failure (arm ordering before generic Char).
        $chat = $this->chat(input: 'ab');
        [$next] = $chat->update(self::altV());

        self::assertSame('ab', $next->inputBuf, 'the press typed nothing');
        self::assertStringContainsString('voice.baseUrl', self::peekToast($next), 'it toasted instead');
    }

    public function testTheSecondPressStopsThePlantedTakeAndReturnsTheEngineCmd(): void
    {
        $take = $this->vp2tPlantTake(samples: true);

        [$next, $stop] = $this->chat()->update(self::altV());

        try {
            self::assertFalse(VoiceSession::active(), 'the take is closed');
            self::assertInstanceOf(\Closure::class, $stop, 'the press answers with the recorder stop Cmd');
            self::assertFalse(TranscribeCmd::inFlight(), 'nothing is uploaded before the recorder has let go');

            $stopped = self::vp2tSettle($stop);
            self::assertInstanceOf(VoiceTakeStoppedMsg::class, $stopped);
            self::assertSame($take, $stopped->recording);

            [, $cmd] = $next->update($stopped);
            self::assertNotNull($cmd, 'the stopped take goes to the transcriber');
            self::assertInstanceOf(\Closure::class, $cmd);
            self::assertTrue(TranscribeCmd::inFlight(), 'the engine latch took it');
            self::assertFileExists($take->path, 'the upload closure owns the file — it never ran here');
        } finally {
            TranscribeCmd::resetForTesting();
            @unlink($take->path);
        }
    }

    public function testASilentTakeIsAnsweredByTheNoAudioMsgWithoutAFork(): void
    {
        $take = $this->vp2tPlantTake(samples: false);
        self::assertFileExists($take->path);

        [$chat, $stop] = $this->chat()->update(self::altV());
        self::assertInstanceOf(\Closure::class, $stop);
        [, $cmd] = $chat->update(self::vp2tSettle($stop));

        self::assertInstanceOf(\Closure::class, $cmd, 'the empty take still answers through a cmd');
        self::assertFalse(TranscribeCmd::inFlight(), 'the empty answer never took the latch');
        $msg = $cmd();
        self::assertInstanceof(VoiceFailedMsg::class, $msg);
        self::assertStringContainsString(Lang::t('media.voice.no_audio'), $msg->line);
        self::assertFileDoesNotExist($take->path, 'and the engine deleted it — cmd() owns that path');
    }

    public function testAStopThatLandsWhileATranscriptionHoldsTheLatchDiscardsTheTakeLoudly(): void
    {
        $take = $this->vp2tPlantTake(samples: true);
        [$chat, $stop] = $this->chat()->update(self::altV());
        self::assertInstanceOf(\Closure::class, $stop);
        $stopped = self::vp2tSettle($stop);

        $latch = new ReflectionProperty(TranscribeCmd::class, 'inFlight');
        $latch->setValue(null, true);
        try {
            [$next] = $chat->update($stopped);
        } finally {
            $latch->setValue(null, false);
        }

        self::assertStringContainsString(Lang::t('chat.voice.busy'), self::peekToast($next));
        self::assertFileDoesNotExist($take->path, 'the stranded take is discarded through the recorder cancel');
    }

    public function testStoppingThroughTheUpdatePathEndsTheRecorder(): void
    {
        // The user tier stores flat dotted keys; this one turns voice on.
        file_put_contents(
            $this->home . '/.candy-code/config.json',
            (string) json_encode(['voice.baseUrl' => 'http://127.0.0.1:9/v1']),
        );
        $bin = $this->home . '/bin';
        mkdir($bin, 0o700);
        file_put_contents($bin . '/arecord', "#!/bin/sh\nsleep 30\n");
        chmod($bin . '/arecord', 0o755);
        $path = getenv('PATH');
        $this->pathRestore = is_string($path) ? $path : '';
        putenv('PATH=' . $bin . ':' . $this->pathRestore);

        [$recording, $start] = $this->chat()->update(self::altV());
        self::assertNull($start, 'a started take needs no toast');
        self::assertTrue(VoiceSession::active(), 'the shim recorder is rolling');

        $live = (new ReflectionProperty(AudioRecorder::class, 'live'))->getValue();
        self::assertCount(1, $live);
        $process = array_values($live)[0]['process'];
        self::assertIsResource($process);
        $pid = (int) (proc_get_status($process)['pid'] ?? 0);
        self::assertGreaterThan(0, $pid);

        [$stopping, $stop] = $recording->update(self::altV());
        self::assertInstanceOf(\Closure::class, $stop);
        self::assertFalse(VoiceSession::active());

        $stopped = self::vp2tSettle($stop);

        self::assertInstanceOf(VoiceTakeStoppedMsg::class, $stopped, 'the stop Cmd settled on the loop');
        self::assertFalse(is_resource($process), 'the recorder was reaped');
        self::assertFalse(AudioRecorder::stopping());
        self::assertDirectoryDoesNotExist('/proc/' . $pid, 'the recorder process is gone and reaped');

        // The sleeping shim captured nothing: the fold answers the visible
        // no-audio refusal without forking an upload.
        [, $cmd] = $stopping->update($stopped);
        self::assertInstanceOf(\Closure::class, $cmd);
        $msg = $cmd();
        self::assertInstanceOf(VoiceFailedMsg::class, $msg);
        self::assertStringContainsString(Lang::t('media.voice.no_audio'), $msg->line);
    }

    public function testEscWhileRecordingCancelsDeletesTheWavAndTouchesNothingElse(): void
    {
        $take = $this->vp2tPlantTake(samples: true);
        $chat = $this->chat();

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Escape, ''));

        self::assertSame($chat, $next, 'the cancel is a pure throw-away: no stamp, no state write');
        self::assertNull($cmd, 'and it schedules nothing');
        self::assertFalse(VoiceSession::active());
        self::assertFileDoesNotExist($take->path, 'Esc deletes the WAV through the engine cancel');

        // The two-beat idle pairing still works AFTER the take died — proof
        // the cancel did not leave a half-armed Escape behind.
        [$armed] = $next->update(new KeyMsg(KeyType::Escape, ''));
        [, $opened] = $armed->update(new KeyMsg(KeyType::Escape, ''));
        self::assertNotNull($opened, 'a later idle Esc Esc still reaches the picker');
    }

    public function testAltVIsInertWhileThePaletteOwnsTheKeyboard(): void
    {
        if (AudioRecorder::probe() !== '') {
            self::markTestSkipped('this host has a recorder binary; the inertness fixture needs the off-chord refusal to be the loud one');
        }

        // Voice ON (a user-tier baseUrl) so "reached the chord" and "stayed
        // inert" differ observably: reaching it would toast the no-backend
        // refusal; inertness leaves no toast and no take at all.
        $this->vp2tEnableVoice();
        [$palette] = $this->chat()->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        self::assertNotSame([], $palette->paletteMatches(), 'fixture: the palette is open');

        [$next, $cmd] = $palette->update(self::altV());

        self::assertNull($cmd);
        self::assertFalse(VoiceSession::active(), 'Alt+V did not start a take behind the palette');
        self::assertNull($next->settingsToast(), 'and the chord never fired at all');
    }

    public function testTheTranscriptLandsAtTheCaretAndNeverSends(): void
    {
        $withDraft = $this->chat(input: 'keep ');

        [$next, $cmd] = $withDraft->update(new VoiceTranscribedMsg('hello there'));

        self::assertStringContainsString('hello there', $next->inputBuf, 'the text was inserted at the caret');
        self::assertFalse($next->inFlight, 'and nothing was sent');
        self::assertNull($cmd, 'the insert schedules nothing');
        self::assertCount(\count($withDraft->history), $next->history, 'the transcript did not gain a turn');
    }

    public function testAFailedTakeRaisesANoticeRow(): void
    {
        $chat = $this->chat();

        [$next] = $chat->update(new VoiceFailedMsg('the endpoint said no'));

        $last = $next->history[\count($next->history) - 1] ?? null;
        self::assertNotNull($last);
        self::assertStringContainsString('the endpoint said no', $last->content);
    }

    public function testTheStatusBarCarriesTheStaticCueInEachLiveState(): void
    {
        $bar = new ReflectionMethod(Renderer::class, 'renderStatusBar');
        $chat = $this->chat();

        $idle = $bar->invoke(null, $chat, Renderer::publishedFrame());
        self::assertStringNotContainsString('● rec', $idle);
        self::assertStringNotContainsString('transcribing…', $idle);

        $this->vp2tPlantTake(samples: true);
        $recording = $bar->invoke(null, $chat, Renderer::publishedFrame());
        self::assertStringContainsString(Lang::t('chat.voice.recording'), $recording);
        self::assertStringContainsString('● rec · Alt+V stop · Esc cancel', $recording, 'the design string paints verbatim');

        VoiceSession::resetForTesting();
        $latch = new ReflectionProperty(TranscribeCmd::class, 'inFlight');
        $latch->setAccessible(true);
        $latch->setValue(null, true);
        try {
            $transcribing = $bar->invoke(null, $chat, Renderer::publishedFrame());
            self::assertStringContainsString('transcribing…', $transcribing);
            self::assertStringNotContainsString('● rec', $transcribing, 'one state at a time on the bar');
        } finally {
            $latch->setValue(null, false);
        }

        self::assertSame(
            $idle,
            $bar->invoke(null, $chat, Renderer::publishedFrame()),
            'with no take and no latch the bar is byte-identical to the idle one',
        );
    }

    private function chat(string $input = ''): Chat
    {
        return (new Chat(
            inputBuf: $input,
            backend: EngineBackend::new(new ScriptedProvider([]), 'm'),
        ))->withSize(100, 30);
    }

    private function vp2tEnableVoice(): void
    {
        file_put_contents(
            $this->home . '/.candy-code/config.json',
            (string) json_encode(['voice' => ['baseUrl' => 'http://127.0.0.1:9/v1']]),
        );
    }

    /**
     * Plant a take through the engine's own static (the VoiceSessionTest
     * idiom — this fake recorder never spawns a child), backed by a real
     * temp WAV whose size decides the hasAudio() answer downstream.
     */
    private function vp2tPlantTake(bool $samples): AudioRecording
    {
        $path = $this->home . '/take-' . bin2hex(random_bytes(4)) . '.wav';
        file_put_contents($path, str_repeat('x', $samples ? 2048 : 44));
        $this->takes[] = $path;

        $recording = new AudioRecording(path: $path, backend: 'fixture', maxSeconds: 120, startedAt: time());
        $property = new ReflectionProperty(VoiceSession::class, 'recording');
        $property->setAccessible(true);
        $property->setValue(null, $recording);

        return $recording;
    }

    /** Run a stop Cmd on the loop until it answers, with a hard leash. */
    private static function vp2tSettle(\Closure $cmd): ?object
    {
        $async = $cmd();
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

    private static function altV(): KeyMsg
    {
        // The registry label's own spelling, read back the way the drift
        // suite reads it: the Alt branch hands the rune capitalised.
        return new KeyMsg(KeyType::Char, 'V', alt: true);
    }

    private static function peekToast(?Chat $chat): string
    {
        return (string) preg_replace(
            '/[│╭╮╰╯─✔ℹ\s]+/u',
            ' ',
            Ansi::strip($chat?->settingsToast()?->view('', 400, 24) ?? ''),
        );
    }

    private function vp2tRemoveTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (is_dir($path)) {
                $this->vp2tRemoveTree($path);
                @rmdir($path);

                continue;
            }

            @unlink($path);
        }

        @rmdir($dir);
    }
}
