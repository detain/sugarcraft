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

    // Player status messages
    'player.loading' => 'loading...',
    'player.paused' => 'paused',
    'player.playing' => 'playing',
    'player.quit' => 'quit',

    // Controls help
    'controls.help' => 'space=play  q=quit  m=mode  ? for help',
];
