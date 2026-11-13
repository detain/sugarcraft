<?php

/**
 * English (default) translations for sugar-video.
 *
 * BRAND-PREFIX RULE (SV-DOC-4/060 sweep): every diagnostic value — text that
 * reaches error_log, an exception, or a CLI refusal — begins with
 * "sugar-video: ". The five on-screen status/hint keys (loading/ended/error/
 * restart/quit/too-small) are exempt: they render inside the player's own status row,
 * where the brand would eat columns a 10-cell seat does not have (the
 * clipToCells law) — chrome carries the section, not the prefix.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Decoder errors
    'subtitle.unreadable' => 'sugar-video: subtitle file could not be read ({path}) — playback continues without captions',
    'audio.binary_missing' => 'sugar-video: neither ffplay nor mpv was found on this host — audio playback is unavailable, video continues silent',
    'subtitle.too_large' => 'sugar-video: subtitle file ({path}) exceeds the {max}-byte ceiling — ignored',
    'subtitle.cue_cap' => 'sugar-video: subtitle track truncated at {max} cues',
    'subtitle.parse_too_large' => 'sugar-video: subtitle text is {len} bytes, above the {max}-byte ceiling — refused',
    'render.mode_unsupported' => 'sugar-video: the {mode} render mode is not supported on this terminal (its graphics escape bytes would be invisible garbage there) — this seat picks {best}; omit the mode argument (the CLI takes it positionally), drop withMode(), or choose one of RendererFactory::supportedModes()',
    'decoder.gif_budget' => 'sugar-video: GIF decode budget exceeded at frame {frame} of a {grid}-pixel grid (limit {max} resident pixels) — use a smaller terminal geometry or a shorter clip',
    'decoder.gd_missing' => 'sugar-video: this PHP has no image extension (ext-gd), so graphics render modes are unavailable',
    'decoder.gif_grid' => 'sugar-video: the GIF asks for a cell grid beyond what this terminal decodes — {detail}; a smaller window or shorter clip will play',
    'decoder.text_sampled' => 'sugar-video: this PHP has no image extension (ext-gd), so GIF frames are sampled as text',
    'source.probe_missing' => 'sugar-video: ffprobe was not found on this host — duration, fps, and audio detection are unavailable',
    'decoder.ffmpeg_missing' => 'sugar-video: ffmpeg not found on this host; mp4/avi/webm playback requires ffmpeg',
    'decoder.windows_refused' => 'sugar-video: ffmpeg video decode is not available on Windows: the frame pump reads the child pipes with stream_select(), which is documented-unsupported on Windows — a failed select reads as "no data", so playback would black-screen silently while ffmpeg keeps producing frames nobody drains (SV-M44). GIF playback remains available there; play mp4/avi/webm/stream sources on a Linux or macOS host until the pump is reworked — see the README "Windows" notes.',
    'decoder.ffmpeg_exit' => 'sugar-video: ffmpeg exited with code {code} before the stream ended ({source})',
    'source.unparseable' => 'sugar-video: ffprobe could not decode any video stream from {source} (exit code {code}) — the file is corrupt or not a video',
    'decoder.webp_gd_missing' => 'sugar-video: this PHP cannot decode WebP (GD lacks WebP support); animated WebP playback requires it — install GD with WebP support, or serve the file over http(s) so the ffmpeg route decodes it',

    // GIF source ingress (the bounded one-time capture in GifDecoder::readBoundedBytes)
    'gif.source_unreadable' => 'sugar-video: GIF source "{path}" cannot be decoded — {reason}',
    'gif.source_too_large' => 'sugar-video: GIF source is {size} bytes, above the {max}-byte ceiling — the whole file must fit in memory before playback starts',

    // Remote-source request headers (see Source/HttpHeaders — rejected before anything
    // reaches ffmpeg, because a CR/LF in a header value is request smuggling)
    'header.invalid_name' => 'sugar-video: Invalid HTTP header name {name}: must be a non-empty token with no CR, LF, NUL or ":" in it',
    'header.invalid_value' => 'sugar-video: Invalid HTTP header value for {name}: CR, LF, NUL and other C0 controls are rejected so a header value cannot start a second request line or truncate the argv passed to the decoder',
    'header.malformed_field' => 'sugar-video: Malformed HTTP header field {field}: expected "Name: value"',
    'header.too_many' => 'sugar-video: Refused {count} HTTP header fields: the ceiling is {max}',
    'header.value_too_long' => 'sugar-video: Invalid HTTP header value for {name}: {len} bytes exceeds the per-value ceiling of {max} bytes',
    'header.aggregate_too_large' => 'sugar-video: Invalid HTTP header set: {len} bytes total exceeds the aggregate ceiling of {max} bytes',
    'header.openurl_positional' => 'sugar-video: Reel::openUrl() second argument is HTTP headers as a name => value map, not a positional list — a list looks like the old allowedHosts position; pass headers as name => value entries and the host allowlist as the named allowedHosts: argument',
    'header.ignored_local_source' => 'sugar-video: ignoring {count} HTTP request header(s) — the decoder reads "{source}" without an HTTP request of its own, so there is nothing for them to ride on',
    'header.ignored_local_source.audio' => 'sugar-video: ignoring {count} HTTP request header(s) — the audio companion plays "{source}" without an HTTP request of its own, so there is nothing for them to ride on',
    'ssrf.no_allowlist' => 'sugar-video: openUrl() to remote host "{host}" without a host allowlist — the URL is handed to ffmpeg, which resolves DNS and follows redirects and can reach internal/link-local hosts (SSRF surface). Restrict it via openUrl($url, allowedHosts: [...]) or withAllowedHosts([...]).',

    // Player status messages
    'player.no_source_for_embedding' => 'sugar-video: Reel::toPlayer() needs a bound source; call Reel::open(path) or Reel::openUrl(url, headers) first',
    'player.cellpx_non_positive' => 'sugar-video: Cell pixel size must be positive, got {w}x{h}',

    // SV-M18: literals promoted from exception sites and the on-screen status
    // rows. Values keep the exact historical wording (placeholders swapped in)
    // so the existing substring pins stay green as an equivalence proof.
    // SV-LANG later prepended the brand to these diagnostics too — substring
    // integrity held, which is exactly what the pins proved.
    'decoder.source_missing' => 'sugar-video: video source not found: {path}',
    'decoder.frame_truncated' => 'sugar-video: RGB24 frame arrived truncated — {len} bytes for a {w}x{h} grid (needs {want}); refusing to paint the remainder black',
    'player.fps_positive' => 'sugar-video: fps must be greater than zero',
    'url.not_http' => 'sugar-video: Not an http(s) URL: {url}',
    'ramp.unknown' => 'sugar-video: Unknown ramp name: {name}',
    'allowlist.malformed_authority' => 'sugar-video: Remote host not in allowlist: (malformed authority)',
    'allowlist.host_not_allowed' => 'sugar-video: Remote host not in allowlist: {host}',
    'player.loading_status' => '[{mode}] loading...  space play  q quit',
    'player.ended_status' => '[ended]  {hint}',
    'player.error_status' => '[error] {failure}',
    'player.restart_hint' => '0 restart  q quit',
    'player.quit_hint' => 'q quit',
    'player.frame_budget_negative' => 'sugar-video: frame budget must not be negative, got {ms} ms',
    'player.terminal_too_small' => 'terminal too small ({cols} cols, {min} min)',
];
