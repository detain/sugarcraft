<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use SugarCraft\Git\HistoryEntry;
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
        // Undoing a discard would recreate destroyed uncommitted bytes — the
        // entry must be marked unrecoverable, not given a fake 'stage' inverse.
        $this->assertSame(HistoryEntry::UNRECOVERABLE_INVERSE, $entry->inverseOp);
        $this->assertSame([], $entry->inverseArgs);
    }

    public function testCheckoutFactoryCreatesCorrectEntry(): void
    {
        $entry = HistoryEntry::checkout('main', 'feature-branch');

        $this->assertSame('checkout', $entry->op);
        $this->assertSame(['branch' => 'feature-branch', 'from' => 'main'], $entry->args);
        $this->assertSame('checkout', $entry->inverseOp);
        $this->assertSame(['branch' => 'main', 'from' => 'feature-branch'], $entry->inverseArgs);
    }

    /**
     * Audit A2: with no recorded origin (detached HEAD / unknown current)
     * the entry must REFUSE undo — the old shape "undone" a switch that
     * only ever re-checked-out the destination.
     */
    public function testCheckoutWithoutOriginRefusesUndo(): void
    {
        $entry = HistoryEntry::checkout('', 'feature-branch');

        $this->assertSame('checkout', $entry->op);
        $this->assertSame(HistoryEntry::UNRECOVERABLE_INVERSE, $entry->inverseOp);
        $this->assertSame([], $entry->inverseArgs);
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
        // 'reset' as inverse destroyed the ORIGINAL commit; refusing is honest.
        $this->assertSame(HistoryEntry::UNRECOVERABLE_INVERSE, $entry->inverseOp);
        $this->assertSame([], $entry->inverseArgs);
    }

    public function testCreateBranchFactoryCreatesCorrectEntry(): void
    {
        $entry = HistoryEntry::createBranch('new-feature', 'main');

        $this->assertSame('createBranch', $entry->op);
        $this->assertSame(['name' => 'new-feature', 'from' => 'main', 'base' => ''], $entry->args);
        // candy-git-016: the inverse is the PAIRED walk-home-then-delete,
        // not a bare deleteBranch (git refuses that while the fresh branch
        // is current — every undo of `n` used to fail).
        $this->assertSame('removeBranch', $entry->inverseOp);
        $this->assertSame(['name' => 'new-feature', 'from' => 'main'], $entry->inverseArgs);
    }

    public function testCreateBranchWithoutOriginRefusesUndo(): void
    {
        // Detached HEAD has no home to return to: refuse, never guess
        // (the checkout() lesson, mirrored per candy-git-016).
        $entry = HistoryEntry::createBranch('new-feature', '');

        $this->assertSame('createBranch', $entry->op);
        $this->assertSame(HistoryEntry::UNRECOVERABLE_INVERSE, $entry->inverseOp);
    }

    public function testCreateBranchStoresTheBaseForTheRedoPath(): void
    {
        // candy-git-018: a branch cut from a log commit must RE-CUT from
        // that same base on redo, not silently drift to the current HEAD.
        $entry = HistoryEntry::createBranch('nb', 'main', 'abc123');

        $this->assertSame(['name' => 'nb', 'from' => 'main', 'base' => 'abc123'], $entry->args);
        $this->assertSame('removeBranch', $entry->inverseOp);
        $this->assertSame(['name' => 'nb', 'from' => 'main'], $entry->inverseArgs, 'the inverse is the paired home-then-delete walk');
    }

    public function testRevertFactoryRefusesUndo(): void
    {
        // Undoing a revert means rewriting published history; the model
        // says so instead of pretending it can.
        $entry = HistoryEntry::revert('abc1234');

        $this->assertSame('revert', $entry->op);
        $this->assertSame(['sha' => 'abc1234'], $entry->args);
        $this->assertSame(HistoryEntry::UNRECOVERABLE_INVERSE, $entry->inverseOp);
        $this->assertSame([], $entry->inverseArgs);
    }

    public function testResetHardFactoryRefusesUndo(): void
    {
        // The pre-reset HEAD was never recorded and --hard already ate the
        // uncommitted bytes: this is the amend reflog lesson again.
        $entry = HistoryEntry::resetHard('def5678');

        $this->assertSame('resetHard', $entry->op);
        $this->assertSame(['sha' => 'def5678'], $entry->args);
        $this->assertSame(HistoryEntry::UNRECOVERABLE_INVERSE, $entry->inverseOp);
    }

    public function testDeleteUntrackedFactoryRefusesUndo(): void
    {
        // Untracked bytes were never in a git object - undo would lie
        // (candy-git-035), the same refusal lane as discard and amend.
        $entry = HistoryEntry::deleteUntracked('junk.tmp');

        $this->assertSame('deleteUntracked', $entry->op);
        $this->assertSame(['path' => 'junk.tmp'], $entry->args);
        $this->assertSame(HistoryEntry::UNRECOVERABLE_INVERSE, $entry->inverseOp);
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

    public function testUnstagePatchFactoryInvertsToStagePatch(): void
    {
        $entry = HistoryEntry::unstagePatch('src/App.php', '@@ -1,3 +1,4 @@');

        $this->assertSame('unstagePatch', $entry->op);
        $this->assertSame(['path' => 'src/App.php', 'hunk' => '@@ -1,3 +1,4 @@'], $entry->args);
        $this->assertSame('stagePatch', $entry->inverseOp, 'undoing an unstage must re-stage the same bytes');
        $this->assertSame($entry->args, $entry->inverseArgs);
    }

    public function testMergeFactoryCreatesCorrectEntry(): void
    {
        $entry = HistoryEntry::merge('feature-branch');

        $this->assertSame('merge', $entry->op);
        $this->assertSame(['branch' => 'feature-branch'], $entry->args);
        // 'abort' misrouted to rebase --abort — now an explicit refusal.
        $this->assertSame(HistoryEntry::UNRECOVERABLE_INVERSE, $entry->inverseOp);
        $this->assertSame([], $entry->inverseArgs);
    }

    public function testDeleteBranchFactoryCreatesCorrectEntry(): void
    {
        $entry = HistoryEntry::deleteBranch('old-feature', 'deadbeef');

        $this->assertSame('deleteBranch', $entry->op);
        $this->assertSame(['name' => 'old-feature', 'sha' => 'deadbeef'], $entry->args);
        // Audit A2: the inverse restores AT the recorded sha ('createBranch'
        // would recreate at HEAD and yank the working tree onto it).
        $this->assertSame('restoreBranch', $entry->inverseOp);
        $this->assertSame(['name' => 'old-feature', 'sha' => 'deadbeef'], $entry->inverseArgs);
    }

    public function testDeleteBranchWithoutShaRefusesUndo(): void
    {
        $entry = HistoryEntry::deleteBranch('old-feature', '');

        $this->assertSame('deleteBranch', $entry->op);
        $this->assertSame(HistoryEntry::UNRECOVERABLE_INVERSE, $entry->inverseOp);
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

    // ------------------------------------------- CG-34 (candy-git-042)

    public function testStageAllCarriesTheSelectionForItsUndo(): void
    {
        $entry = HistoryEntry::stageAll(['a.php', 'b.php'], false);

        $this->assertSame('stageAll', $entry->op);
        $this->assertSame('restoreSelection', $entry->inverseOp);
        $this->assertSame(['paths' => ['a.php', 'b.php']], $entry->inverseArgs);
    }

    public function testStageAllWithAPartialPickRefusesItsUndo(): void
    {
        $entry = HistoryEntry::stageAll(['a.php'], true);

        $this->assertSame(HistoryEntry::UNRECOVERABLE_INVERSE, $entry->inverseOp,
            'a staged version that exists only in the index cannot be re-derived');
    }

    public function testStageAllOnAnEmptyIndexKeepsThePlainInverse(): void
    {
        $entry = HistoryEntry::stageAll([], false);

        $this->assertSame('unstageAll', $entry->inverseOp);
        $this->assertSame([], $entry->inverseArgs);
    }

    /** CG-35: a captured sha makes the drop recoverable... */
    public function testStashDropWithAShaInvertsToRestash(): void
    {
        $entry = HistoryEntry::stashDrop('stash@{2}', '9f8e7d6c5b4a39281706f5e4d3c2b1a098765432');
        $this->assertSame('stashDrop', $entry->op);
        $this->assertSame(['ref' => 'stash@{2}', 'sha' => '9f8e7d6c5b4a39281706f5e4d3c2b1a098765432', 'subject' => ''], $entry->args);
        $this->assertSame('restash', $entry->inverseOp);
        $this->assertSame(['sha' => '9f8e7d6c5b4a39281706f5e4d3c2b1a098765432', 'subject' => ''], $entry->inverseArgs);
    }

    /** ...and a missing capture refuses undo instead of lying about it. */
    public function testStashDropWithoutAShaRefusesUndo(): void
    {
        $entry = HistoryEntry::stashDrop('stash@{0}', '');
        $this->assertSame(HistoryEntry::UNRECOVERABLE_INVERSE, $entry->inverseOp);
        $this->assertSame('stash@{0}', $entry->args['ref']);
    }

    public function testStashDropRedoKeepsThePositionalRefForVerification(): void
    {
        $entry = HistoryEntry::stashDrop('stash@{1}', 'abc123');
        $this->assertSame('stash@{1}', $entry->args['ref'], 'redo needs the ref to re-resolve');
    }

}
