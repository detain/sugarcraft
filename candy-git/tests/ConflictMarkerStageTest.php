<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Git\App;

/**
 * The marker-aware stage-all confirm (candy-git-090): `git add -A` is
 * marker-blind - it happily stages a UU file whose bytes still carry
 * <<<<<<< - so the sweep arms a named confirm listing the files the
 * markers were found in, and the second press is the plain git-parity
 * answer. Fakes prove the arm grammar; the integration twin proves it
 * against a genuine conflicted merge.
 */
final class ConflictMarkerStageTest extends TestCase
{
    private static function fixture(array $markers): FixtureGit
    {
        $g = new FixtureGit(
            statusRows: [
                ['branch_summary' => 'main...origin/main'],
                ['index_status' => 'U', 'work_status' => 'U', 'path' => 'f.txt'],
            ],
            branchRows: [['name' => 'main', 'sha' => 'abc1', 'current' => true]],
            logRows: [['sha' => 'abc1', 'subject' => 'first commit', 'author' => 'Joe', 'ago' => '5m ago']],
        );
        $g->conflictedMarkersReturn = $markers;

        return $g;
    }

    public function test_first_sweep_press_arms_a_named_confirm_instead_of_staging(): void
    {
        $g = self::fixture(['f.txt']);
        $app = App::start($g);
        [$app,] = $app->update(new KeyMsg(KeyType::Char, 'a'));

        $this->assertSame('stageall-markers:f.txt', $app->pendingConfirm, 'the arm key embeds the marker list');
        $this->assertNotNull($app->notice);
        $this->assertStringContainsString('f.txt', (string) $app->notice, 'the warning names the file whose bytes carry markers');
        $this->assertFalse($g->stageAllCalled, 'nothing is staged while the question is open');
    }

    public function test_second_press_answers_the_arm_and_stages(): void
    {
        $g = self::fixture(['f.txt']);
        $app = App::start($g);
        [$app,] = $app->update(new KeyMsg(KeyType::Char, 'a'));
        [$app,] = $app->update(new KeyMsg(KeyType::Char, 'a'));

        $this->assertTrue($g->stageAllCalled, 'the repeat press is the yes');
        $this->assertNull($app->pendingConfirm, 'answering consumes the arm');
    }

    public function test_clean_sweep_stages_immediately_without_arming(): void
    {
        $g = self::fixture([]);
        $app = App::start($g);
        [$app,] = $app->update(new KeyMsg(KeyType::Char, 'a'));

        $this->assertTrue($g->stageAllCalled);
        $this->assertNull($app->pendingConfirm);
        $this->assertSame(1, $g->conflictedMarkersCalls, 'the probe runs once per sweep - exactly one extra read, no re-probing');
    }
}
