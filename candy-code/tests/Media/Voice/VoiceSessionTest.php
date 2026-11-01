<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Media\Voice;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Media\Voice\AudioRecorder;
use SugarCraft\Code\Media\Voice\AudioRecording;
use SugarCraft\Code\Media\Voice\TranscribeCmd;
use SugarCraft\Code\Media\Voice\VoiceSession;

/**
 * C-TUI-6.l session laws: the gate answers OFF before any hardware or
 * filesystem touch, a second start is refused while a take rolls, and stop
 * hands the recording back even when it is silent (the transcribe path is
 * what turns silence into the visible no-audio refusal — the session must
 * not swallow the take here).
 *
 * The success arm (recorder actually rolling) needs a mic and a recorder
 * binary; this box has neither by design, which is exactly the honest
 * refusal class these tests pin. The rolling path's parts are covered by
 * AudioRecorderTest (spawn lifecycle) and TranscribeCmdTest (settle).
 */
final class VoiceSessionTest extends TestCase
{
    /** @var list<string> */
    private array $vcsScratch = [];

    /** True only while this test replaced HOME with a temp root. */
    private bool $vcsHomeAdopted = false;

    protected function tearDown(): void
    {
        VoiceSession::resetForTesting();
        AudioRecorder::resetForTesting();
        TranscribeCmd::resetForTesting();

        if ($this->vcsHomeAdopted) {
            // Removal, not reassignment: what the next process reads is then
            // passwd-derived again, exactly as before this test ran.
            \putenv('HOME');
            $this->vcsHomeAdopted = false;
        }

        foreach ($this->vcsScratch as $dir) {
            $this->vcsPrune($dir);
        }

        $this->vcsScratch = [];
    }

    public function testStartRefusesTheOffSentenceWithoutTouchingTheCache(): void
    {
        // A home with no settings at all: voice.baseUrl cannot be set, so the
        // refusal is the translated OFF sentence — and it must arrive BEFORE
        // the recorder probes PATH or reserves a directory (fail quiet-loud:
        // no silent half-start, no litter).
        $home = $this->vcsFakeHome();
        \putenv('HOME=' . $home);
        $this->vcsHomeAdopted = true;

        self::assertSame(Lang::t('media.voice.off'), VoiceSession::start());
        self::assertFalse(VoiceSession::active(), 'a refused start never rolls a take');
        self::assertDirectoryDoesNotExist(
            $home . '/.candy-code/cache',
            'the off refusal must not create the voice cache root',
        );
    }

    public function testASecondStartIsRefusedWhileATakeRolls(): void
    {
        $sentinel = new AudioRecording(
            '/definitely/not/a/real/take-' . \uniqid((string) \getmypid(), true) . '.wav',
            AudioRecorder::BACKEND_ALSA,
            120,
            \microtime(true),
        );
        $this->vcsPlantRecording($sentinel);

        $refusal = VoiceSession::start();

        self::assertIsString($refusal);
        self::assertStringContainsString('already in progress', $refusal);
        self::assertTrue(VoiceSession::active(), 'the refusal must not drop the rolling take');
    }

    public function testStopHandsBackTheRecordingAndEndsTheSession(): void
    {
        $dir = $this->vcsTempDir();
        $take = $dir . '/take-silent.wav';
        \file_put_contents($take, '');

        $recording = new AudioRecording($take, AudioRecorder::BACKEND_SOX, 60, \microtime(true));
        $this->vcsPlantRecording($recording);
        self::assertTrue(VoiceSession::active());

        $handed = VoiceSession::stop();

        self::assertSame($recording, $handed, 'the take rides back to the caller even when silent');
        self::assertFalse(VoiceSession::active());
        self::assertFileExists($take, 'stop() finalizes the file; deleting it is the transcribe path');
    }

    public function testCancelDeletesTheTakeAndEndsTheSession(): void
    {
        $dir = $this->vcsTempDir();
        $take = $dir . '/take-discarded.wav';
        \file_put_contents($take, \str_repeat('a', 64));

        $this->vcsPlantRecording(new AudioRecording($take, AudioRecorder::BACKEND_SOX, 60, \microtime(true)));

        VoiceSession::cancel();

        self::assertFalse(VoiceSession::active());
        self::assertFileDoesNotExist($take, 'a discarded take must not linger on disk');
    }

    public function testIdleSessionStopsToNullAndCancelsQuietly(): void
    {
        self::assertFalse(VoiceSession::active());
        self::assertNull(VoiceSession::stop());
        VoiceSession::cancel();
        self::assertFalse(VoiceSession::active());
    }

    public function testTheShutdownCancelIsRegisteredExactlyOnce(): void
    {
        // Static-state contract: every process that ever starts a voice
        // session must leave a shutdown hook that tears the child down, and
        // registering it per start() would stack handlers. Read the source:
        // exactly one register_shutdown_function call behind a once-guard.
        $src = \file_get_contents(\dirname(__DIR__, 3) . '/src/Media/Voice/VoiceSession.php');
        self::assertIsString($src);
        self::assertSame(1, \substr_count($src, 'register_shutdown_function'));
        self::assertGreaterThanOrEqual(2, \substr_count($src, 'shutdownRegistered'), 'the guard reads the flag it writes — a missing once-check drops this leg');
    }

    private function vcsFakeHome(): string
    {
        $dir = $this->vcsTempDir();
        \mkdir($dir . '/.candy-code', 0o700);

        return $dir;
    }

    private function vcsTempDir(): string
    {
        $dir = \sys_get_temp_dir() . '/ct6l_vcs_' . \uniqid((string) \getmypid(), true);
        \mkdir($dir, 0o700, true);
        $this->vcsScratch[] = $dir;

        return $dir;
    }

    private function vcsPlantRecording(AudioRecording $recording): void
    {
        $property = new \ReflectionProperty(VoiceSession::class, 'recording');
        $property->setAccessible(true);
        $property->setValue(null, $recording);
    }

    private function vcsPrune(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        foreach ((array) \scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (\is_dir($path)) {
                $this->vcsPrune($path);
                @\rmdir($path);

                continue;
            }

            @\unlink($path);
        }

        @\rmdir($dir);
    }
}
