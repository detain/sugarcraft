<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Git\App;

/**
 * The one-shot local-hooks announce (candy-git-144): a repository whose
 * .git/hooks carries executable files runs them with every commit verb,
 * and a stderr warning would be invisible under the full-screen UI. The
 * commit/amend collectors therefore say so ONCE per session, on the
 * amber notice lane — the flag persists across collector closes, so a
 * quiet repo pays one probe and a live one never repeats the warning.
 */
final class LocalHooksAnnounceTest extends TestCase
{
    private static function fixture(): FixtureGit
    {
        return new FixtureGit(
            statusRows: [
                ['branch_summary' => 'main...origin/main'],
                ['index_status' => 'M', 'work_status' => ' ', 'path' => 'src/A.php'],
            ],
            branchRows: [['name' => 'main', 'sha' => 'abc1', 'current' => true]],
            logRows: [['sha' => 'abc1', 'subject' => 'first commit', 'author' => 'Joe', 'ago' => '5m ago']],
        );
    }

    public function test_opening_the_collector_announces_live_hooks_once(): void
    {
        $g = self::fixture();
        $g->localHooksReturn = true;
        $app = App::start($g);
        [$app,] = $app->update(new KeyMsg(KeyType::Char, 'c'));

        $this->assertTrue($app->collectingCommit);
        $this->assertNotNull($app->notice);
        $this->assertStringContainsString('active git hooks', (string) $app->notice);
        $this->assertTrue($app->hooksAnnounced);
        $this->assertSame(1, $g->localHooksCalls);
    }

    public function test_the_announce_survives_close_and_reopen(): void
    {
        $g = self::fixture();
        $g->localHooksReturn = true;
        $app = App::start($g);
        [$app,] = $app->update(new KeyMsg(KeyType::Char, 'c'));
        [$app,] = $app->update(new KeyMsg(KeyType::Escape));
        $this->assertFalse($app->collectingCommit);

        [$app,] = $app->update(new KeyMsg(KeyType::Char, 'c'));
        $this->assertTrue($app->collectingCommit);
        $this->assertSame(1, $g->localHooksCalls, 'the one-shot flag short-circuits the probe on reopen');
        $this->assertTrue($app->hooksAnnounced);
    }

    public function test_quiet_repo_never_announces(): void
    {
        $g = self::fixture();
        $app = App::start($g);
        [$app,] = $app->update(new KeyMsg(KeyType::Char, 'c'));

        $this->assertNull($app->notice);
        $this->assertFalse($app->hooksAnnounced);
        $this->assertSame(1, $g->localHooksCalls, 'the probe itself is still paid once per collector open');
    }
}
