<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Git\App;

/**
 * The commit collector's hint lane warns before pinentry can paint over
 * the raw-mode frame (candy-git-054): with GPG commit signing on, Enter
 * leads to gpg-agent prompting on /dev/tty — an input channel the app's
 * stdin isolation cannot fence. The warning rides the collector hint
 * itself (one amber row, one confirmation grammar), so the Enter/Esc/TAB
 * affordances never disappear behind a second notice.
 */
final class GpgSigningHintTest extends TestCase
{
    private static function plain(string $view): string
    {
        return preg_replace('/\x1b\[[0-9;]*m/', '', $view) ?? $view;
    }

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

    private function collectorOn(FixtureGit $git, string $rune): App
    {
        $app = App::start($git);
        [$app,] = $app->update(new KeyMsg(KeyType::Char, $rune));

        return $app;
    }

    public function test_signed_repo_opens_the_collector_with_the_pinentry_warning(): void
    {
        $git = self::fixture();
        $git->commitGpgSignsReturn = true;
        $view = self::plain($this->collectorOn($git, 'c')->view());

        $this->assertStringContainsString('enter commits (pinentry on the tty)', $view);
        $this->assertStringContainsString('tab newline', $view, 'the newline chord affordance must survive the warning');
        $this->assertStringContainsString('esc cancels', $view);
    }

    public function test_unsigned_repo_keeps_the_plain_commit_hint(): void
    {
        $git = self::fixture();
        $view = self::plain($this->collectorOn($git, 'c')->view());

        $this->assertStringContainsString('enter commits', $view);
        $this->assertStringNotContainsString('pinentry', $view);
    }

    public function test_amend_collector_warns_too(): void
    {
        $git = self::fixture();
        $git->commitGpgSignsReturn = true;
        $view = self::plain($this->collectorOn($git, 'A')->view());

        $this->assertStringContainsString('pinentry on the tty', $view);
    }

    public function test_the_warning_survives_typing_into_the_buffer(): void
    {
        $git = self::fixture();
        $git->commitGpgSignsReturn = true;
        $app = $this->collectorOn($git, 'c');
        [$app,] = $app->update(new KeyMsg(KeyType::Char, 'f'));
        [$app,] = $app->update(new KeyMsg(KeyType::Char, 'i'));
        $view = self::plain($app->view());

        $this->assertStringContainsString('pinentry on the tty', $view, 'every keystroke re-writes the state - the probe must persist with the buffer');
    }

    public function test_the_warning_does_not_leak_into_other_collectors(): void
    {
        $git = self::fixture();
        $git->commitGpgSignsReturn = true;
        $app = $this->collectorOn($git, 'c');
        [$app,] = $app->update(new KeyMsg(KeyType::Escape));
        $this->assertFalse($app->collectingCommit);
        $this->assertFalse($app->commitGpgSigns, 'closing the buffer drops the probe result with it');

        [$app,] = $app->update(new KeyMsg(KeyType::Char, 'n'));
        $view = self::plain($app->view());
        $this->assertStringNotContainsString('pinentry', $view);
        $this->assertStringContainsString('enter confirms', $view);
    }
}
