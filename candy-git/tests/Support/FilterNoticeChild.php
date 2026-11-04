<?php

declare(strict_types=1);

/**
 * Test fixture child script (NOT a PHPUnit test — no Test.php suffix, so the
 * suite never collects it). Runs filter-capable candy-git commands inside a
 * fresh process so the parent can capture the one-shot STDERR notice and the
 * strict-mode refusal that fwrite(STDERR)/throw produce in the wild.
 *
 * Usage: php FilterNoticeChild.php <repo-dir> <stage-diff|zero>
 * Prints EXIT-OK on success, REFUSED:<message> and exits 3 on refusal.
 */

namespace SugarCraft\Git\Tests\Support;

use SugarCraft\Git\Git;

require __DIR__ . '/../../vendor/autoload.php';

$repo = $argv[1] ?? '';
$mode = $argv[2] ?? 'stage-diff';

if (!is_dir($repo)) {
    fwrite(STDERR, "child: no repo at {$repo}\n");
    exit(4);
}

$git = new Git($repo);

try {
    if ($mode === 'checkout') {
        // Two branch switches on ONE instance: both are filter-capable, the
        // second one writes file.txt back (smudge fires again) and must NOT
        // re-announce — unless the lane SET changed underneath (m1 latch).
        $git->checkout($argv[3] ?? 'other-r3');
        $git->checkout($argv[4] ?? 'master');
    } elseif ($mode === 'checkout-mutate') {
        // Announce, repo grows a SECOND lane (clean), announce again; a
        // third command in the unchanged set must stay silent again (the
        // m1 latch is per lane SET, not per command). The clean value must
        // be CONTENT-NEUTRAL (a mangling filter would make the worktree
        // look dirty and git would refuse the checkout outright) yet byte-
        // DISTINCT from the sandbox global's `cat` (an identical value is
        // the disclosed notice-silent shadow and would never re-announce).
        $git->checkout($argv[3] ?? 'other-r3');
        exec('git -C ' . escapeshellarg($repo) . ' config filter.lfs.clean "cat -" 2>/dev/null');
        $git->checkout($argv[4] ?? 'master');
        $git->checkout($argv[3] ?? 'other-r3');
        $git->checkout($argv[4] ?? 'master');
    } else {
        // Both verbs sit in FILTER_CAPABLE_DISPATCH ('add', 'diff'). Two calls
        // on ONE instance pin the per-instance one-shot latch.
        $git->stageAll();
        $git->diff('file.txt');
        if ($mode === 'zero') {
            // A non-filter-capable verb must stay silent in every mode.
            $git->status();
        }
    }
} catch (\Throwable $e) {
    fwrite(STDOUT, 'REFUSED:' . $e->getMessage() . "\n");
    exit(3);
}

fwrite(STDOUT, "EXIT-OK\n");
exit(0);
