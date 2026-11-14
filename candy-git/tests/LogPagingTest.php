<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Git\App;
use SugarCraft\Git\Pane;

/**
 * The Log pane deepens instead of lying (candy-git-100): the old hard cap
 * made 'j' past commit twenty a permanent "(no more)" even though the repo
 * had history below it. The window starts at one 20-commit page and grows
 * one page per bottom-press until a short answer marks the true end.
 */
final class LogPagingTest extends TestCase
{
    /** @param list<array{sha:string,subject:string}> $extra */
    private static function pool(int $n): array
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['sha' => sprintf('c%06d', $i), 'subject' => "commit {$i}", 'author' => 'Joe', 'ago' => "{$i}m ago"];
        }

        return $rows;
    }

    private static function appWith(int $poolSize): array
    {
        $git = new FixtureGit(
            statusRows: [
                ['branch_summary' => 'main...origin/main'],
                ['index_status' => 'M', 'work_status' => ' ', 'path' => 'src/A.php'],
            ],
            branchRows: [['name' => 'main', 'sha' => 'abc1', 'current' => true]],
            logRows: self::pool($poolSize),
        );
        $app = App::start($git);
        [$app,] = $app->update(new KeyMsg(KeyType::Tab, ''));
        [$app,] = $app->update(new KeyMsg(KeyType::Tab, ''));
        self::assertSame(Pane::Log, $app->pane);

        return [$app, $git];
    }

    /** Walk to the last LOADED row without triggering the deepening press. */
    private function toBottom(App $app): App
    {
        $guard = 0;
        while ($app->logCursor < count($app->log) - 1 && $guard++ < 1000) {
            [$app,] = $app->update(new KeyMsg(KeyType::Char, 'j'));
        }

        return $app;
    }

    public function test_boot_requests_exactly_one_page(): void
    {
        [$app, $git] = self::appWith(30);

        $this->assertSame([20], $git->logLimits);
        $this->assertCount(20, $app->log);
        $this->assertSame(20, $app->logDepth);
    }

    public function test_pressing_past_the_bottom_of_a_full_window_deepens_it(): void
    {
        [$app, $git] = self::appWith(30);
        $app = $this->toBottom($app);

        $this->assertSame([20], $git->logLimits, 'walking within the window fetches nothing');
        [$app,] = $app->update(new KeyMsg(KeyType::Char, 'j'));

        $this->assertSame([20, 40], $git->logLimits);
        $this->assertCount(30, $app->log, 'the short answer carries every commit there is');
        $this->assertSame(40, $app->logDepth);
    }

    public function test_the_true_end_stays_pinned_and_never_pages_again(): void
    {
        [$app, $git] = self::appWith(30);
        $app = $this->toBottom($app);
        [$app,] = $app->update(new KeyMsg(KeyType::Char, 'j'));   // deepens to 40

        $guard = 0;
        while ($app->logCursor < count($app->log) - 1 && $guard++ < 100) {
            [$app,] = $app->update(new KeyMsg(KeyType::Char, 'j'));
        }
        $this->assertSame(29, $app->logCursor);
        [$app,] = $app->update(new KeyMsg(KeyType::Char, 'j'));   // the old "(no more)" row
        [$app,] = $app->update(new KeyMsg(KeyType::Char, 'j'));

        $this->assertSame([20, 40], $git->logLimits, 'a window shorter than its cap is the end of history');
        $this->assertSame(29, $app->logCursor);
    }

    public function test_grown_depth_survives_a_refresh(): void
    {
        [$app, $git] = self::appWith(30);
        $app = $this->toBottom($app);
        [$app,] = $app->update(new KeyMsg(KeyType::Char, 'j'));   // depth 40
        [$app,] = $app->update(new KeyMsg(KeyType::Char, 'R'));   // explicit refresh

        $this->assertSame([20, 40, 40], $git->logLimits);
        $this->assertSame(40, $app->logDepth);
        $this->assertCount(30, $app->log);
    }

    public function test_end_key_deepens_before_it_lands_short(): void
    {
        [$app, $git] = self::appWith(30);
        $first = $app->logCursor;
        [$app,] = $app->update(new KeyMsg(KeyType::End, ''));

        $this->assertSame([20], $git->logLimits, 'from row zero End just lands on the last loaded row');
        $this->assertGreaterThan($first, $app->logCursor);

        [$app,] = $app->update(new KeyMsg(KeyType::End, ''));
        $this->assertSame([20, 40], $git->logLimits, 'End at a full bottom pages, like j');
    }
}
