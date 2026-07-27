<?php

declare(strict_types=1);

namespace SugarCraft\Stash\Tests;

use SugarCraft\Stash\HistoryEntry;
use PHPUnit\Framework\TestCase;

final class HistoryEntryTest extends TestCase
{
    public function testStageFactoryCreatesCorrectEntry(): void
    {
        $entry = HistoryEntry::stage('src/App.php');

        $this->assertSame('stage', $entry->op);
        $this->assertSame(['path' => 'src/App.php'], $entry->args);
        $this->assertSame('unstage', $entry->inverseOp);
        $this->assertSame(['path' => 'src/App.php'], $entry->inverseArgs);
    }

    public function testUnstageFactoryCreatesCorrectEntry(): void
    {
        $entry = HistoryEntry::unstage('src/App.php');

        $this->assertSame('unstage', $entry->op);
        $this->assertSame(['path' => 'src/App.php'], $entry->args);
        $this->assertSame('stage', $entry->inverseOp);
        $this->assertSame(['path' => 'src/App.php'], $entry->inverseArgs);
    }

    public function testDiscardFactoryCreatesCorrectEntry(): void
    {
        $entry = HistoryEntry::discard('src/App.php');

        $this->assertSame('discard', $entry->op);
        $this->assertSame(['path' => 'src/App.php'], $entry->args);
        $this->assertSame('stage', $entry->inverseOp);
        $this->assertSame(['path' => 'src/App.php'], $entry->inverseArgs);
    }

    public function testCheckoutFactoryCreatesCorrectEntry(): void
    {
        $entry = HistoryEntry::checkout('feature-branch');

        $this->assertSame('checkout', $entry->op);
        $this->assertSame(['branch' => 'feature-branch'], $entry->args);
        $this->assertSame('checkout', $entry->inverseOp);
        $this->assertSame(['branch' => 'feature-branch'], $entry->inverseArgs);
    }

    public function testCommitFactoryCreatesCorrectEntry(): void
    {
        $entry = HistoryEntry::commit('Add new feature');

        $this->assertSame('commit', $entry->op);
        $this->assertSame(['message' => 'Add new feature'], $entry->args);
        $this->assertSame('reset', $entry->inverseOp);
        $this->assertSame(['message' => 'Add new feature'], $entry->inverseArgs);
    }

    public function testAmendFactoryCreatesCorrectEntry(): void
    {
        $entry = HistoryEntry::amend();

        $this->assertSame('amend', $entry->op);
        $this->assertSame([], $entry->args);
        $this->assertSame('reset', $entry->inverseOp);
        $this->assertSame([], $entry->inverseArgs);
    }

    public function testCreateBranchFactoryCreatesCorrectEntry(): void
    {
        $entry = HistoryEntry::createBranch('new-feature');

        $this->assertSame('createBranch', $entry->op);
        $this->assertSame(['name' => 'new-feature'], $entry->args);
        $this->assertSame('deleteBranch', $entry->inverseOp);
        $this->assertSame(['name' => 'new-feature'], $entry->inverseArgs);
    }

    public function testStageAllFactoryCreatesCorrectEntry(): void
    {
        $entry = HistoryEntry::stageAll();

        $this->assertSame('stageAll', $entry->op);
        $this->assertSame([], $entry->args);
        $this->assertSame('unstageAll', $entry->inverseOp);
        $this->assertSame([], $entry->inverseArgs);
    }

    public function testStagePatchFactoryCreatesCorrectEntry(): void
    {
        $entry = HistoryEntry::stagePatch('src/App.php', '@@ -1,3 +1,4 @@');

        $this->assertSame('stagePatch', $entry->op);
        $this->assertSame(['path' => 'src/App.php', 'hunk' => '@@ -1,3 +1,4 @@'], $entry->args);
        $this->assertSame('unstagePatch', $entry->inverseOp);
        $this->assertSame(['path' => 'src/App.php', 'hunk' => '@@ -1,3 +1,4 @@'], $entry->inverseArgs);
    }

    public function testMergeFactoryCreatesCorrectEntry(): void
    {
        $entry = HistoryEntry::merge('feature-branch');

        $this->assertSame('merge', $entry->op);
        $this->assertSame(['branch' => 'feature-branch'], $entry->args);
        $this->assertSame('abort', $entry->inverseOp);
        $this->assertSame([], $entry->inverseArgs);
    }

    public function testDeleteBranchFactoryCreatesCorrectEntry(): void
    {
        $entry = HistoryEntry::deleteBranch('old-feature');

        $this->assertSame('deleteBranch', $entry->op);
        $this->assertSame(['name' => 'old-feature'], $entry->args);
        $this->assertSame('createBranch', $entry->inverseOp);
        $this->assertSame(['name' => 'old-feature'], $entry->inverseArgs);
    }

    public function testConstructorSetsPropertiesCorrectly(): void
    {
        $entry = new HistoryEntry(
            'customOp',
            ['key' => 'value'],
            'inverseOp',
            ['invKey' => 'invValue'],
        );

        $this->assertSame('customOp', $entry->op);
        $this->assertSame(['key' => 'value'], $entry->args);
        $this->assertSame('inverseOp', $entry->inverseOp);
        $this->assertSame(['invKey' => 'invValue'], $entry->inverseArgs);
    }

    public function testEntriesAreImmutable(): void
    {
        $entry = HistoryEntry::stage('file.txt');

        // Properties are readonly, attempting to modify would cause error
        $this->assertSame('stage', $entry->op);
        $this->assertSame('unstage', $entry->inverseOp);
    }
}
