<?php

declare(strict_types=1);

namespace SugarCraft\Video\Support;

use SugarCraft\Video\Source\HttpHeaders;

/**
 * Shared argv assembly for every ffmpeg-family child sugar-video spawns.
 *
 * No single upstream — extracted from the ffmpeg/ffplay/mpv argv assembly in
 * `Decode/FfmpegDecoder::buildCommand()` and `AudioPlayer::buildCommand()`
 * the flags themselves mirror those binaries' own CLI docs.
 *
 * WHY THIS CLASS EXISTS. The library drives three separate
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
     * Environment for every media child: the parent env MINUS anything a
     * spawned ffmpeg/ffplay/mpv/ffprobe must not honor.
     *
     * WHY a scrub (M8): ffmpeg reads `http_proxy`/`https_proxy`/`all_proxy`
     * from its environment, so a parent shell configured to route through a
     * proxy would silently send a "restricted host allowlist" playback through
     * that third party — the allowlist would police the URL while the bytes
     * ride someone else's hop. Secret/token/credential variables are swept for
     * the same reasoning: a media child has no business reading them, and it
     * echoes its environment into logs far more readily than our own process.
     *
     * Passing a full copy (minus the swept names) as proc_open()'s explicit
     * `$env` keeps everything else — PATH, HOME, locale — exactly as
     * inherited: an explicit env REPLACES the parent environment for the
     * child, so anything omitted would vanish from ffmpeg's view.
     *
     * @return array<string,string>
     */
    public static function scrubbedEnv(): array
    {
        $inherited = getenv();
        $env = is_array($inherited) ? $inherited : [];

        foreach (array_keys($env) as $name) {
            if (preg_match('/(?:PROXY|SECRET|TOKEN|KEY|CREDENTIAL|PASSWORD)/i', (string) $name) === 1) {
                unset($env[(string) $name]);
            }
        }

        if (!isset($env['PATH']) || $env['PATH'] === '') {
            $env['PATH'] = '/usr/local/bin:/usr/bin:/bin';
        }

        return $env;
    }

    /**
     * Whether the source is an http(s) URL (vs a local file path).
     *
     * One predicate for every ffmpeg-family call site: the reconnect options and
     * the header passthrough are valid only on the network protocols (ffmpeg
     * rejects `-reconnect` on a file input), so the routing decision in
     * {@see \SugarCraft\Video\Decode\DecoderFactory}, the header gating in
     * {@see \SugarCraft\Video\Decode\FfmpegDecoder} and the audio command builders
     * below must not be able to disagree about what "network" means.
     */
    public static function isNetworkSource(string $source): bool
    {
        return preg_match('#^https?://#i', $source) === 1;
    }

    /**
     * Redirect budget for the network transports when a host allowlist is
     * active: zero — ffmpeg/ffplay/ffprobe must not follow ANY hop.
     *
     * WHY (SSRF): the allowlist validates the URL STRING Reel was handed —
     * the first hop only. Left on defaults, a listed host answering
     * `302 → http://169.254.169.254/…` reaches exactly the internal targets
     * the allowlist exists to keep away, and the policy never sees it. With
     * an allowlist bound, a legitimate CDN redirect fails loudly at
     * open instead: the operator adds the redirect TARGET to the allowlist.
     * Without an allowlist the transport default is kept (no behavior
     * change for unrestricted callers).
     */
    public const ALLOWED_MAX_REDIRECTS = 0;

    /**
     * The http/https protocol reconnect block, as input options (so BEFORE `-i`).
     *
     * A momentary drop or a slow signed-URL response would otherwise end the
     * stream; `delay_max 4` bounds the total retry window so a dead host still
     * fails rather than hanging playback behind an invisible reconnect loop.
     *
     * OPTION VINTAGE (sugar-video-003): every flag here exists since ffmpeg
     * 4.0 (`reconnect`/`reconnect_streamed` since 3.3) — deliberately NO
     * 5.1-only options: `-reconnect_on_network_error` (≥5.1) made ffmpeg abort
     * the INPUT OPEN with "Option not found" on 4.x/5.0 hosts, killing ALL
     * remote playback rather than merely skipping the extra retry trigger
     * (whose behavior -reconnect_streamed already covers for streamed reads).
     * `-max_redirects` (the allowlist pin below) is ffmpeg ≥4.4 — binding an
     * allowlist therefore raises the effective floor to 4.4; see README.
     *
     * @param bool $hostRestricted when a Reel host allowlist is bound, the
     *               block also pins {@see ALLOWED_MAX_REDIRECTS} so the child
     *               cannot ride a redirect out of the allowlist.
     * @return list<string>
     */
    public static function networkReconnectFlags(bool $hostRestricted = false): array
    {
        $flags = [
            '-reconnect', '1',
            '-reconnect_streamed', '1',
            '-reconnect_delay_max', '4',
        ];

        if ($hostRestricted) {
            array_push($flags, '-max_redirects', (string) self::ALLOWED_MAX_REDIRECTS);
        }

        return $flags;
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
        return $startSec > 0.0 ? ['-ss', sprintf('%.3F', $startSec)] : [];
    }

    /**
     * A float formatted for ffmpeg filter argv, locale-immune.
     *
     * `(string) $fps` honours LC_NUMERIC on hosts with the locale data
     * installed — a comma-decimal seat would emit `fps=23,976` and ffmpeg
     * exits on the unparsable filter. `%.14F` is PHP's documented
     * locale-INdependent float form; trimming the trailing zeros (and a bare
     * trailing point) keeps the bytes identical to the historical cast on a
     * dot host ("24", "23.976") — same shape as {@see secondsFromMillis()}'s
     * deliberate integer split.
     */
    public static function formatFloat(float $value): string
    {
        return rtrim(rtrim(sprintf('%.14F', $value), '0'), '.');
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
     * @param bool          $hostRestricted a bound Reel allowlist also pins
     *                            `-max_redirects` (see {@see ALLOWED_MAX_REDIRECTS})
     *                            for a network source, so the audio child cannot
     *                            ride a redirect out of the policy the video
     *                            path was checked against.
     *
     * @return list<string>
     */
    public static function ffplayAudioCommand(string $binary, string $source, int $seekMs, HttpHeaders $headers, bool $hostRestricted = false): array
    {
        $cmd = [$binary, '-nodisp', '-autoexit'];
        if ($seekMs > 0) {
            array_push($cmd, '-ss', self::secondsFromMillis($seekMs));
        }
        // Input options must precede the file they apply to; ffplay has exactly
        // one input, so everything lands before it.
        array_push($cmd, ...self::headerInputFlags($headers));
        if ($hostRestricted && self::isNetworkSource($source)) {
            array_push($cmd, '-max_redirects', (string) self::ALLOWED_MAX_REDIRECTS);
        }
        $cmd[] = $source;

        return $cmd;
    }

    /**
     * The `mpv` audio-only command: `--no-video --really-quiet`, an optional
     * `--start=`, then the header fields and the source.
     *
     * @param int          $seekMs Milliseconds to start from; 0 omits `--start=`.
     * @param bool         $hostRestricted a bound Reel allowlist pins
     *                            `max_redirects=0` on mpv's libavformat http
     *                            stream (same AVOption as the ffmpeg family
     *                            uses), closing the identical redirect escape.
     *
     * @return list<string>
     */
    public static function mpvAudioCommand(string $binary, string $source, int $seekMs, HttpHeaders $headers, bool $hostRestricted = false): array
    {
        $cmd = [$binary, '--no-video', '--really-quiet'];
        if ($seekMs > 0) {
            $cmd[] = '--start=' . self::secondsFromMillis($seekMs) . 's';
        }
        array_push($cmd, ...self::mpvHeaderFieldFlags($headers));
        if ($hostRestricted && self::isNetworkSource($source)) {
            $cmd[] = '--stream-lavf-o=max_redirects=' . self::ALLOWED_MAX_REDIRECTS;
        }
        $cmd[] = $source;

        return $cmd;
    }
}
