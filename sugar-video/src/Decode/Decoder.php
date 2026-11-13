<?php

declare(strict_types=1);

namespace SugarCraft\Video\Decode;

use SugarCraft\Video\Render\Mode;

/**
 * Interface for video decoders that produce RgbFrame objects.
 *
 * Implementations wrap external tools (ffmpeg) or pure-PHP libraries
 * (sugar-animation for GIF) to decode video into a cell-grid of RGB pixels.
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
     *        {@see \SugarCraft\Video\Source\HttpHeaders}). A decoder that does not
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
     * (This supersedes the earlier "null means END OF STREAM" wording,
     * which was true of the pre-timeout blocking reads the pump contract
     * documented; w4-reel replaced them with {@see FfmpegDecoder::READ_TIMEOUT_SEC}
     * fill deadlines.)
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
     * SV-H11/011 SHARPENS WHAT FALSE BUYS: the Player routes an index seek
     * ({@see Player::withSeek()}) through the native start-second rebuild
     * when you answer false — `-ss` before `-i` for ffmpeg, a cursor jump
     * for the byte formats — so a deep jump lands with a single pull instead
     * of walking every intervening frame in one keypress. Answering true
     * says you have no seconds notion behind reopen() (you replay from your
     * own head), so withSeek() keeps the budget-bounded index walk for you.
     *
     * @return bool True when reopen() fully re-establishes the stream at the
     *         new geometry/mode, so the caller must not re-create the decoder.
     */
    public function reopensInPlace(): bool;

    /**
     * Whether a start-SECOND argument actually lands the playhead.
     *
     * SV-M25/025: the Player's time routes — {@see \SugarCraft\Video\Player::seekToSeconds()}
     * and the throwaway thumbnail read behind {@see \SugarCraft\Video\Player::frameAt()} —
     * hand a `$startSec` down and trust it to mean "resume here". This is the
     * decoder saying whether that trust is answered: true for the sources
     * {@see DecoderFactory} owns (ffmpeg `-ss` before `-i`, a cursor jump over
     * the captured bytes for GIF/WebP), false for anything that replays from
     * its own head and reads `$startSec` as decoration.
     *
     * The answer pairs with {@see reopensInPlace()}: a host (candy-top's frame
     * RPC is the motivating caller) asks once, before a runtime throw, instead
     * of probing the behaviour by exception.
     */
    public function seeksBySeconds(): bool;
}
