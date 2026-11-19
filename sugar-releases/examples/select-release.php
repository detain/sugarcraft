<?php

declare(strict_types=1);

/**
 * Offline release selection: which GitHub release would `widget self-update`
 * pick, given a decoded release list? No network and no phar — the input is
 * the array shape the GitHub releases API returns, so this runs anywhere.
 *
 * Shows the two channels: the stable pick (newest non-draft, non-prerelease
 * `widget/v*` release carrying both the phar and its .sha256 sidecar) and the
 * rolling `widget/latest` release judged by commit against a dev build.
 *
 *   php examples/select-release.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Releases\ReleaseSelector;
use SugarCraft\Releases\ReleaseSource;

$source = ReleaseSource::new('acme/widget', 'widget');
$selector = ReleaseSelector::for($source);

/** Build one release entry the way the GitHub API spells it. */
$release = static function (string $tag, string $commitish, array $names, bool $draft = false, bool $prerelease = false): array {
    $assets = [];
    foreach ($names as $name) {
        $assets[] = [
            'name' => $name,
            'browser_download_url' => "https://github.com/acme/widget/releases/download/{$tag}/{$name}",
        ];
    }

    return [
        'tag_name' => $tag,
        'target_commitish' => $commitish,
        'draft' => $draft,
        'prerelease' => $prerelease,
        'assets' => $assets,
    ];
};

$both = ['widget.phar', 'widget.phar.sha256'];
$releases = [
    $release('widget/v1.1.0', 'aaaa1111', $both),
    $release('widget/v1.3.0', 'cccc3333', $both, draft: true),                 // drafts never ship
    $release('widget/v1.2.0', 'bbbb2222', $both),
    $release('widget/v1.4.0-rc.1', 'dddd4444', $both, prerelease: true),        // prereleases never ship
    $release('widget/v1.2.1', 'eeee5555', ['widget.phar']),                     // no sidecar: not selectable
    $release('widget/latest', 'ffff6666', $both),
    $release('other/v9.9.9', '0000000', $both),                                 // another app's stream
];

$stable = $selector->selectRelease($releases);
echo "stable pick: {$stable['version']}\n";
echo "phar:        {$stable['pharUrl']}\n";

$latest = $selector->selectLatestRelease($releases);
echo "latest tag:  {$source->latestTag()} names commit {$latest['commitish']}\n";

// A dev build embeds the commit it was built from as dev-<sha>; an abbreviated
// sha matches the release's full target commit as a prefix.
foreach (['dev-ffff66', 'dev-aaaa1111', 'v1.2.0'] as $current) {
    [$decision, $label] = $selector->latestDecision($current, $latest);
    echo "{$current} vs {$label}: " . match ($decision) {
        0 => "up to date\n",
        1 => "a newer commit is published\n",
        default => "cannot compare (refused, fail-closed)\n",
    };
}
