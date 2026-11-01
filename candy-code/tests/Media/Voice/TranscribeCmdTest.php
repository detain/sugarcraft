<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Media\Voice;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Code\App\VoiceFailedMsg;
use SugarCraft\Code\App\VoiceTranscribedMsg;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Media\Voice\AudioRecorder;
use SugarCraft\Code\Media\Voice\AudioRecording;
use SugarCraft\Code\Media\Voice\TranscribeCmd;
use SugarCraft\Code\Media\Voice\VoiceConfig;
use SugarCraft\Code\Media\Voice\VoiceSession;
use SugarCraft\Code\Support\ToolIpcFiles;

/**
 * C-TUI-6.l cmd laws: the off-the-update-path transcription round-trips
 * through a forked child and a loop poll (mirroring StatusLineCommand), the
 * in-flight latch admits exactly one take at a time and releases on EVERY
 * settle path, a silent take refuses in place without spending a fork, and
 * the collect arm refuses absent and malformed child answers loudly.
 *
 * The no-pcntl fallback arm is pinned STRUCTURALLY (source gate) rather than
 * executed: pcntl cannot be undeclared mid-process, and the StatusLineCommand
 * precedent likewise drives only the fork arm live. The fork arm here IS
 * executed end-to-end below.
 */
final class TranscribeCmdTest extends TestCase
{
    /** @var list<string> */
    private array $tcdScratch = [];

    protected function tearDown(): void
    {
        TranscribeCmd::resetForTesting();
        VoiceSession::resetForTesting();
        AudioRecorder::resetForTesting();

        foreach ($this->tcdScratch as $file) {
            @\unlink($file);
        }

        $this->tcdScratch = [];
    }

    public function testSettleMapsASuccessfulRunToTheTranscribedMsg(): void
    {
        $msg = TranscribeCmd::settle(['ok' => true, 'text' => 'hello world', 'error' => '']);

        self::assertInstanceOf(VoiceTranscribedMsg::class, $msg);
        self::assertSame('hello world', $msg->text);
    }

    public function testSettleMapsAFailedRunToTheTranslatedFailureMsgAndReleasesTheLatch(): void
    {
        $latched = new \ReflectionProperty(TranscribeCmd::class, 'inFlight');
        $latched->setAccessible(true);
        $latched->setValue(null, true);
        self::assertTrue(TranscribeCmd::inFlight());

        $msg = TranscribeCmd::settle(['ok' => false, 'text' => '', 'error' => 'the endpoint said no']);

        self::assertInstanceOf(VoiceFailedMsg::class, $msg);
        self::assertStringContainsString('the endpoint said no', $msg->line);
        self::assertFalse(TranscribeCmd::inFlight(), 'a failed settle must free the next take, not weld the session shut');
    }

    public function testASilentTakeRefusesInPlaceWithoutForkingAndDeletesTheFile(): void
    {
        $take = $this->tcdTakeFile(AudioRecorder::WAV_HEADER_BYTES);
        $recording = new AudioRecording($take, AudioRecorder::BACKEND_ALSA, 120, \microtime(true));

        $cmd = TranscribeCmd::cmd($recording, VoiceConfig::off());

        self::assertIsCallable($cmd, 'the refusal still arms a closure — the loop needs its Msg');
        self::assertFalse(TranscribeCmd::inFlight(), 'the empty-take refusal never latches');

        $msg = $cmd();

        self::assertInstanceOf(VoiceFailedMsg::class, $msg);
        self::assertSame(Lang::t('media.voice.no_audio'), $msg->line);
        self::assertFileDoesNotExist($take, 'the refused empty take is deleted, not kept');
    }

    public function testTheForkedRoundTripSettlesThroughTheLoopAndCarriesTheChildsRefusal(): void
    {
        // 60 bytes beats the bare header, so the hasAudio gate passes and
        // the child actually forks; VoiceConfig::off() makes the child's own
        // Transcriber refuse before dialing, which must surface as the
        // translated failure line — and the child must delete the take.
        $take = $this->tcdTakeFile(60);
        $recording = new AudioRecording($take, AudioRecorder::BACKEND_ALSA, 120, \microtime(true));

        $cmd = TranscribeCmd::cmd($recording, VoiceConfig::off());
        self::assertIsCallable($cmd);
        self::assertTrue(TranscribeCmd::inFlight());
        self::assertNull(
            TranscribeCmd::cmd($recording, VoiceConfig::off()),
            'a second take while one is in flight is refused, not queued',
        );

        $async = $cmd();
        self::assertInstanceOf(AsyncCmd::class, $async);

        $loop = Loop::get();
        $settled = null;
        $async->promise->then(static function (object $value) use (&$settled, $loop): void {
            $settled = $value;
            $loop->stop();
        });

        if ($settled === null) {
            $leash = $loop->addTimer(20.0, static fn () => $loop->stop());
            $loop->run();
            $loop->cancelTimer($leash);
        }

        self::assertInstanceOf(VoiceFailedMsg::class, $settled, 'the forked transcription never settled');
        self::assertStringContainsString('voice input is off', $settled->line);
        self::assertFalse(TranscribeCmd::inFlight(), 'settle released the latch via the loop arm');
        self::assertFileDoesNotExist($take, 'the child deletes the take on every exit path');
    }

    public function testCollectRefusesAnAbsentChildAnswer(): void
    {
        $file = ToolIpcFiles::reserve(ToolIpcFiles::CHAT_PREFIX, 'json');
        ToolIpcFiles::discard($file);

        $result = $this->tcdCollect($file);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('returned nothing', $result['error']);
    }

    public function testCollectRefusesAMalformedChildAnswer(): void
    {
        $file = ToolIpcFiles::reserve(ToolIpcFiles::CHAT_PREFIX, 'json');
        ToolIpcFiles::write($file, '{');

        $result = $this->tcdCollect($file);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('malformed', $result['error']);
    }

    public function testCollectReadsAWrittenChildAnswer(): void
    {
        $file = ToolIpcFiles::reserve(ToolIpcFiles::CHAT_PREFIX, 'json');
        ToolIpcFiles::write($file, '{"ok":true,"text":"from the child","error":""}');

        $result = $this->tcdCollect($file);

        self::assertTrue($result['ok']);
        self::assertSame('from the child', $result['text']);
    }

    public function testTheNoPcntlArmIsGatedAtTheForkSite(): void
    {
        // Structural (UNCONFIRMED-BY-RUN for the fallback itself — pcntl
        // cannot be undeclared in-process): BOTH gates are consulted and the
        // fallback resolves synchronously through the same settle arm.
        $src = (string) \file_get_contents(\dirname(__DIR__, 3) . '/src/Media/Voice/TranscribeCmd.php');
        self::assertStringContainsString("function_exists('pcntl_fork')", $src);
        self::assertStringContainsString("function_exists('pcntl_waitpid')", $src);
        self::assertSame(
            2,
            \substr_count($src, 'self::settle(self::run($recording, $config))'),
            'both synchronous arms (no-pcntl, fork-failed) settle the same in-process run',
        );
    }

    /** @return array{ok: bool, text: string, error: string} */
    private function tcdCollect(string $file): array
    {
        $method = new \ReflectionMethod(TranscribeCmd::class, 'collect');
        $method->setAccessible(true);

        /** @var array{ok: bool, text: string, error: string} $result */
        $result = $method->invoke(null, $file);

        return $result;
    }

    private function tcdTakeFile(int $bytes): string
    {
        $take = \sys_get_temp_dir() . '/ct6l_tcd_' . \uniqid((string) \getmypid(), true) . '.wav';
        \file_put_contents($take, \str_repeat('a', $bytes));
        $this->tcdScratch[] = $take;

        return $take;
    }
}
