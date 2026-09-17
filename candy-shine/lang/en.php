<?php

/**
 * English (default) translations for candy-shine.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'theme.read_failed'   => 'could not read theme file: {path}',
    'theme.json_invalid'  => 'invalid JSON in theme file',
    'theme.json_object'   => 'theme JSON must decode to an object',
    'theme.bad_color'     => 'invalid colour spec: {spec}',
    'renderer.unknown_style' => 'unknown standard style: {name}',
    'renderer.stream_invalid'       => 'write() requires a stream resource',
    'renderer.stream_not_writable'  => 'write() requires a writable stream, got mode "{mode}"',
    'renderer.write_failed'         => 'failed to write {bytes} bytes to the output stream',
    'renderer.chunk_invalid'      => 'stream() chunks must be strings',
    'sink.closed'                 => 'the sink is closed',
    'sink.parent_missing'         => 'output directory does not exist: {dir}',
    'sink.path_unwritable'        => 'output path is not writable: {path}',
    'sink.open_failed'            => 'failed to open output path: {path}',
    'sink.flush_failed'           => 'flushing the output stream failed',
    'writer.feed_after_close'     => 'feed() called after close() — the writer is finished',
];
