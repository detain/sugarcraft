<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Decode;

use SugarCraft\Reel\Render\Mode;

/**
 * Interface for video decoders that produce RgbFrame objects.
 *
 * Implementations wrap external tools (ffmpeg) or pure-PHP libraries
 * (candy-flip for GIF) to decode video into a cell-grid of RGB pixels.
 */
interface Decoder
{
    /**
     * Open the decoder for a source file, targeting a grid of cellsW x cellsH
     * at approximately the given fps.
     *
     * @param string $source Path to the video source (mp4, avi, gif, etc.)
     * @param int $cellsW Target width in terminal cells
     * @param int $cellsH Target height in terminal cells
     * @param float $fps Target frames per second
     * @param Mode|null $mode Rendering mode (null = HalfBlock for backward compatibility)
     * @param float $startSec Seconds to seek into the source before the first frame
     *        (0 = from the start). Enables fast time-based seeking; a decoder without
     *        true seek support treats it as best-effort.
     * @param array<array-key, string> $headers HTTP request headers for a remote
     *        http(s) source — name => value, or "Name: value" field lines (see
     *        {@see \SugarCraft\Reel\Source\HttpHeaders}). A decoder that does not
     *        speak HTTP over the source ignores them; the built-in decoders reject
     *        CR/LF in a name or value outright.
     */
    public function open(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0, array $headers = []): void;

    /**
     * Yield the next RgbFrame, or null if there are no more frames.
     *
     * On a network-backed decoder a null can also be TRANSIENT — a bounded
     * pipe read timed out on a stalled stream (see FfmpegDecoder::READ_TIMEOUT_SEC)
     * and the partial frame stays buffered for the next call. A foreach over
     * getIterator() treats the first null as terminal, so prefer driving
     * playback through {@see next()} per tick.
     *
     * @return \Generator<int, RgbFrame, mixed, void>
     */
    public function getIterator(): \Generator;

    /**
     * Return the next RgbFrame, or null if no more frames are available.
     *
     * A null is not necessarily EOF: the bundled ffmpeg decoder reads the pipe
     * with a bounded, resumable wait, so mid-stream it may report "no frame
     * right now" and yield the same frame successfully on a later call.
     */
    public function next(): ?RgbFrame;

    /**
     * Close resources (pipes, processes) held by the decoder.
     */
    public function close(): void;

    /**
     * Re-open the decoder for a source file at the given grid size and fps.
     *
     * Unlike close() + open(), reopen() may reuse existing resources when
     * appropriate (e.g., FakeDecoder resets its frame index without
     * reallocating frames). Used as a test seam in rebuildDecoderAt().
     *
     * @param string $source Path to the video source (mp4, avi, gif, etc.)
     * @param int $cellsW Target width in terminal cells
     * @param int $cellsH Target height in terminal cells
     * @param float $fps Target frames per second
     * @param Mode|null $mode Rendering mode (null = HalfBlock for backward compatibility)
     * @param float $startSec Seconds to seek into the source before the first frame
     * @param array<array-key, string> $headers HTTP request headers for a remote
     *        http(s) source — see {@see open()}. A rebuild carries the same
     *        credentials the original open() did, or a signed stream stops
     *        mid-playback the first time the viewer resizes their terminal.
     */
    public function reopen(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0, array $headers = []): void;

    /**
     * Whether {@see reopen()} is a faithful in-place rebuild of this decoder.
     *
     * WHY THE PLAYER ASKS THIS. On a seek, a resize and a mode change the
     * Player must point its decoder at a new geometry. For the decoders
     * {@see DecoderFactory} owns, that means closing the subprocess and
     * building a fresh one from the source path. But a decoder handed to
     * `Player::fromDecoder()` — a socket feeding server-transcoded RGB24, a
     * test double holding canned frames — has no source path behind it to
     * rebuild from, so taking the factory branch there would silently
     * substitute a different frame source (or spawn ffmpeg against a
     * sentinel string). Answering true is the decoder saying "reopen me, I
     * can retarget myself"; answering false is it saying "I am a plain
     * ffmpeg/GIF stream — rebuild me from the source".
     *
     * This is the capability that replaces the old `instanceof FakeDecoder`
     * and `videoPath === '/fake'` special cases: the decision belongs to the
     * decoder, not to a string comparison in the playback loop.
     *
     * @return bool True when reopen() fully re-establishes the stream at the
     *         new geometry/mode, so the caller must not re-create the decoder.
     */
    public function reopensInPlace(): bool;
}
