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
}
