<?php

declare(strict_types=1);

/**
 * Published-channel freshness probe — candy-starter-001 (fleet CRIT #87).
 *
 * The split repo, Packagist and the docs site are fed ONLY by the
 * sync-sugarcraft workflow, so a stalled channel strands newcomers on
 * bytes the monorepo already fixed (measured 2026-11-12: the published
 * install block still lacked `--stability=dev` and the live Packagist
 * description carried the pre-fix text while HEAD had both corrected).
 * This probe compares the live public surfaces against the repo's own
 * bytes — the repo is source of truth, the channel must echo it:
 *
 *   1. the live Packagist dev-master `description` for
 *      sugarcraft/candy-starter equals the description of
 *      `git show HEAD:candy-starter/composer.json`;
 *   2. the rendered page https://sugarcraft.github.io/lib/candy-starter.html
 *      contains `--stability=dev` (the fix that never reached the copy).
 *
 * Exit shapes: 0 = channel fresh; 1 = drift, or the probe could not
 * measure either surface (fail loud — an unmeasured channel is not a
 * fresh one). `--advisory` prints every FAIL but exits 0: the mode CI
 * runs while owner gate OG-1 (republish the current green bytes) is
 * open, because the known-stalled channel must not red every push.
 * The flip is one edit: drop the flag from the sync-sugarcraft probe
 * step in the same commit that clears OG-1.
 *
 * Usage: php tools/check-published-channel.php [--advisory]
 */

$advisory = in_array('--advisory', $argv, true);
if (in_array('--help', $argv, true)) {
    echo "usage: php tools/check-published-channel.php [--advisory]\n";
    echo "  compares live Packagist dev-master + docs-site bytes against\n";
    echo "  the repo's own composer.json; --advisory prints FAIL but exits 0\n";
    exit(0);
}

$root = dirname(__DIR__);
$failures = 0;

$report = static function (string $status, string $message) use (&$failures): void {
    if ($status === 'FAIL') {
        $failures++;
    }
    echo $status . ': ' . $message . "\n";
};

// ---------------------------------------------------------------------------
// Expected side — the repo's own bytes at HEAD, never the working tree
// (the working tree may hold an uncommitted half-edit of the very file
// whose publication we are measuring).
// ---------------------------------------------------------------------------
exec(
    'git -C ' . escapeshellarg($root) . ' show HEAD:candy-starter/composer.json 2>/dev/null',
    $showLines,
    $showStatus
);
$repoComposerJson = implode("\n", $showLines ?: []);
if ($showStatus !== 0 || $repoComposerJson === '') {
    $report('FAIL', 'cannot read HEAD:candy-starter/composer.json via git (status ' . $showStatus . ')');
    $repoDescription = null;
} else {
    $repoData = json_decode($repoComposerJson, true);
    $repoDescription = is_array($repoData) ? ($repoData['description'] ?? null) : null;
    if (!is_string($repoDescription) || $repoDescription === '') {
        $report('FAIL', 'HEAD:candy-starter/composer.json carries no description string');
        $repoDescription = null;
    }
}

/**
 * @return array{0:?string, 1:string} [body, error] — body null on failure.
 */
$fetch = static function (string $url): array {
    exec(
        'curl -fsS --max-time 20 -- ' . escapeshellarg($url) . ' 2>/dev/null',
        $lines,
        $status
    );
    $body = implode("\n", $lines ?: []);
    if ($status !== 0 || $body === '') {
        return [null, 'curl exit ' . $status];
    }

    return [$body, ''];
};

// ---------------------------------------------------------------------------
// Check 1 — live Packagist dev-master description must equal the repo's
// (the packagist.org package API is the authority the finding measured;
// the repo.packagist.org p2 minified feed can serve an empty version list
// for branch-only packages, so it cannot carry this comparison)
// ---------------------------------------------------------------------------
if ($repoDescription !== null) {
    [$body, $error] = $fetch('https://packagist.org/packages/sugarcraft/candy-starter.json');
    if ($body === null) {
        $report('FAIL', 'could not fetch Packagist metadata for sugarcraft/candy-starter (' . $error . ')');
    } else {
        $packagist = json_decode($body, true);
        $liveDescription = is_array($packagist)
            ? ($packagist['package']['versions']['dev-master']['description'] ?? null)
            : null;
        if ($liveDescription === null) {
            $report('FAIL', 'Packagist metadata lists no dev-master version for sugarcraft/candy-starter');
        } elseif ($liveDescription === $repoDescription) {
            $report('ok', 'Packagist dev-master description matches HEAD bytes');
        } else {
            $report('FAIL', 'Packagist dev-master description is stale — published copy advertises a '
                . 'channel the repo has since corrected (OG-1 pending). Published: ' . $liveDescription);
        }
    }

    // -----------------------------------------------------------------------
    // Check 2 — rendered site page must carry the working install flag
    // -----------------------------------------------------------------------
    [$page, $error] = $fetch('https://sugarcraft.github.io/lib/candy-starter.html');
    if ($page === null) {
        $report('FAIL', 'could not fetch the rendered docs page (' . $error . ')');
    } elseif (str_contains($page, '--stability=dev')) {
        $report('ok', 'docs site teaches --stability=dev');
    } else {
        $report('FAIL', 'docs site page lacks `--stability=dev` — newcomers copy an install command '
            . 'that cannot work (OG-1 pending)');
    }
}

if ($failures === 0) {
    echo 'channel fresh: published surfaces echo the repo bytes.' . "\n";
    exit(0);
}

echo $failures . ' drift finding(s) against the published channel.' . "\n";
if ($advisory) {
    echo '(advisory mode: exit 0 while owner gate OG-1 keeps the channel republish pending —'
        . ' drop --advisory from the sync-sugarcraft probe step when OG-1 lands)' . "\n";
    exit(0);
}
exit(1);
