<?php

declare(strict_types=1);

/**
 * Show how a build stamp becomes the version string. A phar carries its stamp
 * in its metadata, and Support\Version decides which stamps are printable.
 * A stamp that is not a release or a dev identity is refused, so it never
 * prints as CURRENT by mistake.
 *
 *   $ php examples/version-stamp.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\GifView\Cli;
use SugarCraft\GifView\Support\Version;

$stamps = [
    'candy-gifview/v1.4.2' => 'release tag with prefix',
    '1.4.2'                => 'bare release',
    'dev-1a2b3c4'          => 'development build from a commit',
    'dev-build'            => 'development build without git',
    'release-candidate'    => 'not a version at all',
];

foreach ($stamps as $stamp => $note) {
    $normal = Version::normalizeStamp($stamp);
    printf(
        "%-22s -> %-12s stampable=%s  (%s)\n",
        $stamp,
        $normal ?? 'null',
        Version::isStampable($stamp) ? 'yes' : 'no',
        $note,
    );
}

// Outside a phar there is no stamp, so the answer is the compiled-in version.
echo "running version: ", Cli::runningVersion(), "\n";
echo "fallback:        ", Version::resolve(''), "\n";
