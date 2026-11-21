<?php

declare(strict_types=1);

/**
 * Run the CLI entry point on a few argument vectors and print the exit code
 * and the stderr line each one produces. The `play` seam stands in for the
 * blocking player, so no terminal is needed and nothing is ever drawn.
 *
 *   $ php examples/refusals.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\GifView\Cli;

$cases = [
    'help'         => ['candy-gifview', '--help'],
    'version'      => ['candy-gifview', '--version'],
    'bad preset'   => ['candy-gifview', 'clip.gif', 'densiy'],
    'missing file' => ['candy-gifview', '/nonexistent/clip.gif'],
];

foreach ($cases as $label => $argv) {
    $stdout = fopen('php://memory', 'w+');
    $stderr = fopen('php://memory', 'w+');
    if ($stdout === false || $stderr === false) {
        fwrite(STDERR, "refusals example: cannot open memory streams\n");
        exit(1);
    }

    $code = Cli::main($argv, $stdout, $stderr, static fn (): int => 0);

    rewind($stdout);
    rewind($stderr);
    $out = trim((string) stream_get_contents($stdout));
    $err = trim((string) stream_get_contents($stderr));

    printf("%-13s exit %d\n", $label, $code);
    if ($out !== '') {
        printf("  stdout: %s\n", strtok($out, "\n"));
    }
    if ($err !== '') {
        printf("  stderr: %s\n", $err);
    }
}
