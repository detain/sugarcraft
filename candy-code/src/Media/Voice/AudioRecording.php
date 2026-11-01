<?php

declare(strict_types=1);

namespace SugarCraft\Code\Media\Voice;

/**
 * One finished microphone take (C-TUI-6.l). A value object: the WAV path on
 * disk, which binary made it, the cap that bound it, and when the take began.
 *
 * The handle to the recorder child deliberately lives OUTSIDE this object —
 * {@see VoiceSession} owns the process for the lifetime of the recording, and
 * by the time an `AudioRecording` is handed to a transcriber the child is
 * reaped. A take is therefore a dead fact on disk, never a live fd.
 *
 * Bytes are never held in memory by this class, and nothing in the voice
 * family logs audio: the only reads of `$path` are the transcribe request
 * body ({@see Transcriber::transcribe()}, which deletes the file after use)
 * and the recorder binary itself.
 */
final class AudioRecording
{
    public function __construct(
        public readonly string $path,
        public readonly string $backend,
        public readonly int $maxSeconds,
        public readonly float $startedAt,
    ) {
    }
}
