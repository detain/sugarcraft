<?php

declare(strict_types=1);

namespace SugarCraft\Code\Media\Voice;

use SugarCraft\Code\Lang;

/**
 * The one microphone take this process is currently holding (C-TUI-6.l).
 *
 * Process-global static state, the {@see \SugarCraft\Code\Config\StatusLineCommand}
 * pattern: there is exactly one terminal, one microphone, and one take, and
 * the TUI key handler that starts it (C-TUI-6.m) reaches it by class name
 * rather than threading an object through every fold. `resetForTesting()` is
 * part of the contract for the same reason StatusLineCommand has it — the
 * suite must be able to start from a clean slate without process recycling.
 *
 * NOTHING HERE IS SILENT. Every way a start can fail returns a translated
 * refusal sentence (the `media.voice.*` family) that the caller puts on
 * screen; the recorder never leaves a half-take, and the shutdown hook
 * cancels an abandoned one so a dying process cannot strand a mic open or
 * a WAV behind. Voice being OFF is itself a refusal sentence, not a no-op:
 * the ruling's posture is that the user always learns which case they are in.
 */
final class VoiceSession
{
    private static ?AudioRecording $recording = null;
    private static bool $shutdownRegistered = false;

    /**
     * Begin a take. Returns null once audio is rolling, or the human
     * sentence explaining why it is not — never both, never neither.
     */
    public static function start(): ?string
    {
        self::registerShutdownCancel();

        // A take whose stop Cmd is still waiting on its recorder counts as in
        // progress too: the old child may still hold the microphone.
        if (self::$recording !== null || AudioRecorder::stopping()) {
            return Lang::t('media.voice.failed', ['reason' => 'a take is already in progress']);
        }

        $config = VoiceConfig::fromSettings();

        if (!$config->enabled()) {
            return Lang::t('media.voice.off');
        }

        $result = AudioRecorder::start($config);

        if ($result['recording'] === null) {
            return self::refusalFor($result['token'], $result['reason']);
        }

        self::$recording = $result['recording'];

        return null;
    }

    public static function active(): bool
    {
        return self::$recording !== null;
    }

    /**
     * End the take, as the Cmd {@see AudioRecorder::stop()} returns. Null
     * when nothing was recording. The session is closed at once; the Cmd
     * resolves to a {@see \SugarCraft\Code\App\VoiceTakeStoppedMsg} once
     * the recorder child is gone, and that message hands the take over for
     * transcription. A take with no samples inside still arrives — the
     * transcribe path turns it into the visible `media.voice.no_audio`
     * refusal rather than swallowing it.
     *
     * @return (\Closure(): \SugarCraft\Core\AsyncCmd)|null
     */
    public static function stop(): ?\Closure
    {
        $recording = self::$recording;

        if ($recording === null) {
            return null;
        }

        self::$recording = null;

        return AudioRecorder::stop($recording);
    }

    /** Throw an in-progress take away (Esc): killed recorder, deleted WAV. */
    public static function cancel(): void
    {
        $recording = self::$recording;

        if ($recording === null) {
            return;
        }

        self::$recording = null;
        AudioRecorder::cancel($recording);
    }

    /** Test seam: forget everything a previous test left latched. */
    public static function resetForTesting(): void
    {
        self::cancel();
        AudioRecorder::resetForTesting();
    }

    /**
     * The recorder's tokens, translated. `spawn_failed` rides the generic
     * failed sentence because its cause text is already one; the others have
     * sentences of their own in en.php.
     */
    private static function refusalFor(string $token, string $reason): string
    {
        return match ($token) {
            AudioRecorder::REFUSAL_NO_BACKEND => Lang::t('media.voice.no_backend'),
            AudioRecorder::REFUSAL_NO_HOME => Lang::t('media.voice.no_home'),
            AudioRecorder::REFUSAL_CACHE_REFUSED => Lang::t('media.voice.cache_refused', ['reason' => $reason]),
            default => Lang::t('media.voice.failed', ['reason' => $reason === '' ? $token : $reason]),
        };
    }

    /**
     * Once per process: an exit with a live take cancels it — recorder torn
     * down, WAV deleted — and a recorder whose stop Cmd has not settled is
     * torn down too, since no loop timer runs after exit.
     */
    private static function registerShutdownCancel(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }

        self::$shutdownRegistered = true;
        \register_shutdown_function(static function (): void {
            self::cancel();
            AudioRecorder::teardownAll();
        });
    }
}
