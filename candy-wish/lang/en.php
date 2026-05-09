<?php

/**
 * English (default) translations for candy-wish.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'middleware.cannot_open_stderr' => 'cannot open php://stderr',
    'middleware.stderr_not_resource' => 'stderr must be a resource',
    'logger.cannot_open_target'      => 'cannot open log target: {target}',
    'logger.invalid_target'          => 'Logger target must be a path, resource, or null',
    'bubbletea.bad_factory'          => 'BubbleTea factory must return an object with a run() method; got {got}',
    'transport.bad_stdin'            => 'InProcessTransport runChild() requires a valid stdin resource',
    'transport.bad_stdout'           => 'InProcessTransport runChild() requires a valid stdout resource',
];
