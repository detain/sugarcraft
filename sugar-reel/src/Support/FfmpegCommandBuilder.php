<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Support;

use SugarCraft\Reel\Source\HttpHeaders;

/**
 * Shared argv assembly for every ffmpeg-family child sugar-reel spawns.
 *
 * No single upstream — extracted from the ffmpeg/ffplay/mpv argv assembly in
 * `Decode/FfmpegDecoder::buildCommand()` and `AudioPlayer::buildCommand()`
 * (findings #45); the flags themselves mirror those binaries' own CLI docs.
 *
 * WHY THIS CLASS EXISTS (findings #45). The library drives three separate
 * binaries — `ffmpeg` (decode), `ffplay` and `mpv` (audio companion) — and each
 * one used to build its own argument list inline. The pieces that are genuinely
 * the same knowledge (which sources are network sources, which reconnect options
 * a dropped Wi-Fi needs, how validated HTTP headers reach a demuxer, how a
 * millisecond offset becomes the `S.mmm` seconds string both players accept) were
 * therefore written two and three times, where any fix had to be remembered in
 * every copy. That is exactly how the audio path ended up silently missing the
 * header passthrough the video path had (the *Authenticated video, unauthenticated
 * audio* limitation): the option knowledge existed only inside `FfmpegDecoder`.
 *
 * WHAT THIS CLASS OWNS, AND WHAT IT DOES NOT. It owns the *shared* vocabulary:
 * the network predicate, the reconnect block, the header emitters, the seek flag,
 * the seconds format, and the two audio command shapes. It does not own the
 * decode output flags (`-f rawvideo`/`image2pipe`, the scale/pad filtergraph) —
 * that is ffmpeg-only knowledge with a single call site, and pulling it in here
 * would just move a long method rather than remove a duplication.
 *
 * ARGUMENT VECTOR BYTE-IDENTITY. Every method here returns a `proc_open()` array
 * argv (no shell is involved, so no value is quoted or escaped — quoting an
 * argument handed to `exec`-style spawn would send literal quotes to the binary).
 * The extraction was pinned before it was made: `tests/Support/ArgvGoldenTest`
 * compares each command against the argv captured from the pre-refactor source
 * in `tests/Support/argv-golden-pre-refactor.json`.
 */
final class FfmpegCommandBuilder
{
    /**
     * Whether the source is an http(s) URL (vs a local file path).
     *
     * One predicate for every ffmpeg-family call site: the reconnect options and
     * the header passthrough are valid only on the network protocols (ffmpeg
     * rejects `-reconnect` on a file input), so the routing decision in
     * {@see \SugarCraft\Reel\Decode\DecoderFactory}, the header gating in
     * {@see \SugarCraft\Reel\Decode\FfmpegDecoder} and the audio command builders
     * below must not be able to disagree about what "network" means.
     */
    public static function isNetworkSource(string $source): bool
    {
        return preg_match('#^https?://#i', $source) === 1;
    }

    /**
     * The http/https protocol reconnect block, as input options (so BEFORE `-i`).
     *
     * A momentary drop or a slow signed-URL response would otherwise end the
     * stream; `delay_max 4` bounds the total retry window so a dead host still
     * fails rather than hanging playback behind an invisible reconnect loop.
     *
     * @return list<string>
     */
    public static function networkReconnectFlags(): array
    {
        return [
            '-reconnect', '1',
            '-reconnect_streamed', '1',
            '-reconnect_on_network_error', '1',
            '-reconnect_delay_max', '4',
        ];
    }

    /**
     * The header input options for the `ffmpeg`/`ffplay` dialect: one
     * `-headers` blob (every field except User-Agent, CRLF-terminated exactly as
     * it hits the wire) plus a dedicated `-user_agent`.
     *
     * `HttpHeaders` already rejected CR/LF/NUL at the boundary, so these values
     * are transport-safe; an empty set contributes nothing.
     *
     * @return list<string>
     */
    public static function headerInputFlags(HttpHeaders $headers): array
    {
        $flags = [];
        $headerString = $headers->toFfmpegHeaderString();
        if ($headerString !== null) {
            array_push($flags, '-headers', $headerString);
        }
        $userAgent = $headers->userAgent();
        if ($userAgent !== null) {
            array_push($flags, '-user_agent', $userAgent);
        }

        return $flags;
    }

    /**
     * The header options for the `mpv` dialect: one repeatable
     * `--http-header-fields=Name: value` per field.
     *
     * mpv receives User-Agent as an ordinary field (see
     * {@see HttpHeaders::toMpvHeaderFields()}), so there is deliberately no
     * companion `--user-agent` here — pairing the two would put two User-Agent
     * lines on the wire, which strict servers reject.
     *
     * @return list<string>
     */
    public static function mpvHeaderFieldFlags(HttpHeaders $headers): array
    {
        $flags = [];
        foreach ($headers->toMpvHeaderFields() as $field) {
            $flags[] = '--http-header-fields=' . $field;
        }

        return $flags;
    }

    /**
     * Fast input seek (`-ss` BEFORE `-i`) as ffmpeg-family argv: decodes from the
     * keyframe at/just before $startSec without walking the whole file, which is
     * what makes scrubbing a multi-GB network stream instant.
     *
     * @return list<string>
     */
    public static function inputSeekFlag(float $startSec): array
    {
        return $startSec > 0.0 ? ['-ss', sprintf('%.3f', $startSec)] : [];
    }

    /**
     * Milliseconds as a fixed `S.mmm` seconds string built from integer math.
     *
     * Deliberately not a float cast/sprintf %f: the explicit split is immune to
     * float→string precision ini changes and would stay correct even in a
     * hypothetical locale-comma formatter, and ffplay/mpv accept it verbatim.
     */
    public static function secondsFromMillis(int $ms): string
    {
        return sprintf('%d.%03d', intdiv($ms, 1000), $ms % 1000);
    }

    /**
     * The `ffplay` audio-only command: `-nodisp -autoexit`, an optional input
     * seek, the signed-stream headers, then the source as the bare input.
     *
     * @param int           $seekMs Milliseconds to start from; 0 omits `-ss`.
     * @param HttpHeaders   $headers Validated headers for a network source — an
     *                            empty set (the local-file case) adds nothing,
     *                            so the argv stays what it was before headers
     *                            were ever threaded here.
     *
     * @return list<string>
     */
    public static function ffplayAudioCommand(string $binary, string $source, int $seekMs, HttpHeaders $headers): array
    {
        $cmd = [$binary, '-nodisp', '-autoexit'];
        if ($seekMs > 0) {
            array_push($cmd, '-ss', self::secondsFromMillis($seekMs));
        }
        // Input options must precede the file they apply to; ffplay has exactly
        // one input, so everything lands before it.
        array_push($cmd, ...self::headerInputFlags($headers));
        $cmd[] = $source;

        return $cmd;
    }

    /**
     * The `mpv` audio-only command: `--no-video --really-quiet`, an optional
     * `--start=`, then the header fields and the source.
     *
     * @param int          $seekMs Milliseconds to start from; 0 omits `--start=`.
     *
     * @return list<string>
     */
    public static function mpvAudioCommand(string $binary, string $source, int $seekMs, HttpHeaders $headers): array
    {
        $cmd = [$binary, '--no-video', '--really-quiet'];
        if ($seekMs > 0) {
            $cmd[] = '--start=' . self::secondsFromMillis($seekMs) . 's';
        }
        array_push($cmd, ...self::mpvHeaderFieldFlags($headers));
        $cmd[] = $source;

        return $cmd;
    }
}
