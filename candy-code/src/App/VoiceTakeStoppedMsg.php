<?php

declare(strict_types=1);

namespace SugarCraft\Code\App;

use SugarCraft\Code\Media\Voice\AudioRecording;
use SugarCraft\Core\Msg;

/**
 * The recorder child behind a take has ended (P4-12 CC-3, AUD §7 rank 6).
 *
 * WHY ITS OWN MSG. {@see \SugarCraft\Code\Media\Voice\AudioRecorder::stop()}
 * is a Cmd: the SIGINT finalize grace and the TERM/KILL ladder run on loop
 * timers, so a recorder that ignores SIGINT no longer freezes the TUI for up
 * to about 2.5 s. The take can only be handed to the transcriber once the
 * child has let go of the file, so the hand-off re-enters the model through
 * this message rather than running inside the key press.
 *
 * The fold passes `recording` to {@see \SugarCraft\Code\Media\Voice\TranscribeCmd::cmd()}.
 * `hasAudio` records what the stopped take held when the child ended; the
 * transcribe path re-reads the file itself and refuses an empty take with
 * the visible `media.voice.no_audio` sentence.
 */
final class VoiceTakeStoppedMsg implements Msg
{
    public function __construct(
        public readonly AudioRecording $recording,
        public readonly bool $hasAudio,
    ) {
    }
}
