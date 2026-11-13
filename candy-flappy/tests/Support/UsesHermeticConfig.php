<?php

declare(strict_types=1);

namespace SugarCraft\Flappy\Tests\Support;

/**
 * M5: keeps every test game out of the real $HOME/.candy-flappy store.
 *
 * Game::start() without a configDir reads the high-score file from the
 * developer's HOME — the review found launch state (a polluted box's
 * leaderboard bleeding into a "best:" line) reaching golden renders and
 * behaviour tests. Every `Game::start()` call in the suite therefore
 * passes hermeticConfigDir(): a per-process, per-instance PID-tokened
 * scratch directory that is EMPTY by construction (readScores treats a
 * missing file as no scores), so nothing ever reads or writes HOME.
 */
trait UsesHermeticConfig
{
    private ?string $hermeticDir = null;

    private function hermeticConfigDir(): string
    {
        // PHPUnit builds a fresh TestCase per test, so the object-id
        // suffix gives each test its own scratch; the PID keeps parallel
        // lanes (and the suite vs. a manual run) from sharing files.
        return $this->hermeticDir ??= sprintf(
            '%s/candy-flappy-tests-%d-%d',
            sys_get_temp_dir(),
            getmypid(),
            spl_object_id($this),
        );
    }

    /**
     * flappy-48 (finding -048): the scratch lives under /tmp, so a written
     * save (or a quarantined corrupt-*) would outlive the run — and the
     * NEXT test in the same worker reuses PID-keyed names its siblings may
     * still see. Mirror the hand-rolled cleanups: sweep the store, tolerate
     * absence (most tests never write), then keep the parent chain intact.
     */
    protected function tearDown(): void
    {
        $dir = $this->hermeticDir;
        if ($dir !== null) {
            // Dotfiles included: AtomicJsonFile's .scores.json.lock starts
            // with a dot and glob() skips those by default — leaving it
            // behind would make both rmdir legs fail and leak the scratch.
            foreach (['/.candy-flappy/*', '/.candy-flappy/.*', '/*', '/.*'] as $pattern) {
                foreach (glob($dir . $pattern) ?: [] as $entry) {
                    if (is_file($entry)) {
                        @unlink($entry);
                    }
                }
            }
            @rmdir($dir . '/.candy-flappy');
            @rmdir($dir);
        }

        parent::tearDown();
    }
}
