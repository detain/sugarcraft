<?php

/**
 * English (default) translations for sugar-reel.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Decoder errors
    'decoder.ffmpeg_missing' => 'ffmpeg not found on this host; mp4/avi/webm playback requires ffmpeg',
    'decoder.ffmpeg_failed' => 'ffmpeg process failed (exit code {code})',
    'decoder.ffprobe_missing' => 'ffprobe not found; video metadata unavailable',
    'decoder.gif_only' => 'ffmpeg not available; only GIF sources are supported',

    // Audio errors
    'audio.no_binary' => 'no audio player available (install ffplay or mpv)',
    'audio.spawn_failed' => 'audio subprocess failed to start',

    // Remote-source request headers (see Source/HttpHeaders — rejected before anything
    // reaches ffmpeg, because a CR/LF in a header value is request smuggling)
    'header.invalid_name' => 'Invalid HTTP header name {name}: must be a non-empty token with no CR, LF, NUL or ":" in it',
    'header.invalid_value' => 'Invalid HTTP header value for {name}: CR, LF and NUL are rejected so a header value cannot start a second request line or truncate the argv passed to the decoder',
    'header.malformed_field' => 'Malformed HTTP header field {field}: expected "Name: value"',
    'header.openurl_positional' => 'sugar-reel: Reel::openUrl() second argument is HTTP headers as a name => value map, not a positional list — a list looks like the old allowedHosts position; pass headers as name => value entries and the host allowlist as the named allowedHosts: argument',
    'header.ignored_local_source' => 'sugar-reel: ignoring {count} HTTP request header(s) — the decoder reads "{source}" without an HTTP request of its own, so there is nothing for them to ride on',
    'ssrf.no_allowlist' => 'sugar-reel: openUrl() to remote host "{host}" without a host allowlist — the URL is handed to ffmpeg, which resolves DNS and follows redirects and can reach internal/link-local hosts (SSRF surface). Restrict it via openUrl($url, allowedHosts: [...]) or withAllowedHosts([...]).',

    // Player status messages
    'player.loading' => 'loading...',
    'player.paused' => 'paused',
    'player.playing' => 'playing',
    'player.quit' => 'quit',
    'player.no_source_for_embedding' => 'Reel::toPlayer() needs a bound source; call Reel::open(path) or Reel::openUrl(url, headers) first',

    // Controls help
    'controls.help' => 'space=play  q=quit  m=mode  ? for help',
];
