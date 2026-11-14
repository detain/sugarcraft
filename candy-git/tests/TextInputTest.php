<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Git\App;
use SugarCraft\Git\GitDriver;
use SugarCraft\Git\Pane;
use PHPUnit\Framework\TestCase;

/**
 * K1: text collection must actually work — the letter q is typeable while a
 * buffer owns the keyboard, Space (delivered as KeyType::Space, never as
 * Char ' ') appends, Backspace removes the last codepoint, and bracketed
 * pastes land in the active buffer flattened to one line.
 */
final class TextInputTest extends TestCase
{
    private function git(): GitDriver
    {
        return $this->createMock(GitDriver::class);
    }

    private function app(Pane $pane = Pane::Status): App
    {
        return new App($this->git(), pane: $pane);
    }

    /** @param App $a */
    private function type(App $a, string $key): App
    {
        return $a->update(new KeyMsg(KeyType::Char, $key))[0];
    }

    private function commitCollector(): App
    {
        return $this->type($this->app(), 'c');
    }

    public function testCollectingCommitIsArmedByC(): void
    {
        $a = $this->commitCollector();
        self::assertTrue($a->collectingCommit);
    }

    public function testLetterQIsTypeableInsideCommitBuffer(): void
    {
        $a = $this->type($this->commitCollector(), 'a');
        [$a, $cmd] = $a->update(new KeyMsg(KeyType::Char, 'q'));
        self::assertNull($cmd, 'q inside a text buffer must not quit');
        self::assertSame('aq', $a->commitMessage);
        self::assertTrue($a->collectingCommit);
    }

    public function testQStillQuitsWhenNoBufferIsCollecting(): void
    {
        [, $cmd] = $this->app()->update(new KeyMsg(KeyType::Char, 'q'));
        self::assertNotNull($cmd);
    }

    public function testQWhileHelpOverlayIsOpenClosesHelpInsteadOfQuitting(): void
    {
        $a = $this->type($this->app(), '?');
        self::assertTrue($a->showHelp);
        [$a, $cmd] = $a->update(new KeyMsg(KeyType::Char, 'q'));
        self::assertNull($cmd);
        self::assertFalse($a->showHelp);
    }

    public function testSpaceAppendsToCommitBuffer(): void
    {
        $a = $this->type($this->commitCollector(), 'hello');
        $a = $a->update(new KeyMsg(KeyType::Space))[0];
        $a = $this->type($a, 'world');
        self::assertSame('hello world', $a->commitMessage);
    }

    public function testBackspaceRemovesLastCodepointIncludingMultibyte(): void
    {
        $a = $this->commitCollector();
        $a = $this->type($a, 'a');
        $a = $this->type($a, 'é'); // é
        $a = $a->update(new KeyMsg(KeyType::Backspace))[0];
        self::assertSame('a', $a->commitMessage);
    }

    public function testBackspaceOnEmptyBufferKeepsItEmpty(): void
    {
        $a = $this->commitCollector()->update(new KeyMsg(KeyType::Backspace))[0];
        self::assertSame('', $a->commitMessage);
        self::assertTrue($a->collectingCommit);
    }

    public function testModifierChordsNeverInsertText(): void
    {
        $a = $this->commitCollector()->update(new KeyMsg(KeyType::Char, 'r', ctrl: true))[0];
        self::assertSame('', $a->commitMessage);
        self::assertTrue($a->collectingCommit);
    }

    public function testPasteLandsInCommitBufferFlattenedToOneLine(): void
    {
        $a = $this->commitCollector()->update(new PasteMsg("fix: qux\nsecond\ttabbed"))[0];
        self::assertSame('fix: qux second tabbed', $a->commitMessage);
        self::assertTrue($a->collectingCommit);
    }

    public function testPasteOutsideAnyBufferIsDropped(): void
    {
        $a = $this->app();
        [$a2, $cmd] = $a->update(new PasteMsg('nope'));
        self::assertNull($cmd);
        self::assertSame($a, $a2);
    }

    public function testEscapeCancelsCollectionButDoesNotQuitWhileCollecting(): void
    {
        [$a, $cmd] = $this->commitCollector()->update(new KeyMsg(KeyType::Escape));
        self::assertNull($cmd);
        self::assertFalse($a->collectingCommit);
    }

    public function testCtrlCCancelsCollectionAndQuitsWhenIdle(): void
    {
        [$collecting, $cmd] = $this->commitCollector()->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));
        self::assertNull($cmd, 'Ctrl+C cancels the innermost state first');
        self::assertFalse($collecting->collectingCommit);

        [, $quitCmd] = $this->app()->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));
        self::assertNotNull($quitCmd);
    }

    public function testLetterQIsTypeableInBranchAndMergeBuffers(): void
    {
        $branch = $this->type($this->app(), 'n');
        self::assertTrue($branch->collectingBranchName);
        $branch = $this->type($branch, 'eq');
        self::assertSame('eq', $branch->branchName);

        $merge = $this->type($this->app(), 'M');
        self::assertTrue($merge->collectingMergeTarget);
        $merge = $this->type($merge, 'q');
        self::assertSame('q', $merge->mergeTarget);
    }

    public function testCherryPickBufferAcceptsSpaceBackspaceAndQ(): void
    {
        $a = $this->type($this->app(), 'V');
        self::assertNotNull($a->cherryPick);
        self::assertTrue($a->cherryPick->collecting);
        $a = $this->type($a, 'abc');
        $a = $a->update(new KeyMsg(KeyType::Space))[0];
        $a = $this->type($a, 'q');
        $a = $a->update(new KeyMsg(KeyType::Backspace))[0];
        self::assertSame('abc ', $a->cherryPick->commitRef);
        [$a, $cmd] = $a->update(new KeyMsg(KeyType::Escape));
        self::assertNull($cmd);
    }

    public function testWorktreePathBufferAcceptsEditsAndEnterUsesThem(): void
    {
        $adds = [];
        $git = $this->createMock(GitDriver::class);
        $git->method('worktreeAdd')->willReturnCallback(
            static function (string $path, string $branch) use (&$adds): void {
                $adds[] = [$path, $branch];
            }
        );
        $a = (new App($git, pane: Pane::Branches))->update(new KeyMsg(KeyType::Char, 'w'))[0];
        self::assertNotNull($a->worktrees);
        $a = $this->type($a, 'a'); // enter add mode
        self::assertTrue($a->worktrees->adding);
        $a = $this->type($a, 'feature');
        $a = $a->update(new KeyMsg(KeyType::Space))[0]; // space in a path is a literal edit…
        $a = $a->update(new KeyMsg(KeyType::Backspace))[0]; // …removed again, exactly as typed
        $a = $this->type($a, 'q');
        $a = $a->update(new KeyMsg(KeyType::Enter))[0];
        self::assertSame([['featureq', 'HEAD']], $adds);
    }

    public function testWorktreePasteInsertsIntoAddBuffer(): void
    {
        $a = (new App($this->git(), pane: Pane::Branches))->update(new KeyMsg(KeyType::Char, 'w'))[0];
        $a = $this->type($a, 'a');
        $a = $a->update(new PasteMsg("/tmp/wt\n"))[0];
        self::assertSame('/tmp/wt ', $a->worktrees->newPath);
    }

    /**
     * candy-git-009: sugar-core's decoder hands 0x00 and 0x1c-0x1f through as
     * Char runes with ctrl=false, so the modifier guard alone let raw C0 bytes
     * into every collecting buffer (and from there into `git commit -m`).
     */
    public function testRawControlBytesCannotEnterCommitBuffer(): void
    {
        $a = $this->commitCollector();
        foreach (["\x00", "\x1c", "\x1d", "\x1e", "\x1f", "\x07", "\x7f"] as $rune) {
            $a = $a->update(new KeyMsg(KeyType::Char, $rune))[0];
            self::assertSame('', $a->commitMessage, 'control byte ' . dechex(ord($rune)) . ' must not append');
            self::assertTrue($a->collectingCommit, 'the collector stays open; the key is simply not an edit');
        }
    }

    public function testControlGateAppliesToEveryCollectBuffer(): void
    {
        $branch = $this->app()->update(new KeyMsg(KeyType::Char, 'n'))[0];
        $branch = $this->type($branch, 'ma');
        $branch = $branch->update(new KeyMsg(KeyType::Char, "\x1c"))[0];
        self::assertSame('ma', $branch->branchName);

        $merge = $this->app()->update(new KeyMsg(KeyType::Char, 'M'))[0];
        $merge = $merge->update(new KeyMsg(KeyType::Char, "\x00"))[0];
        self::assertSame('', $merge->mergeTarget);

        $cherry = $this->app()->update(new KeyMsg(KeyType::Char, 'V'))[0];
        $cherry = $cherry->update(new KeyMsg(KeyType::Char, "\x1f"))[0];
        self::assertSame('', $cherry->cherryPick->commitRef);

        $wt = (new App($this->git(), pane: Pane::Branches))->update(new KeyMsg(KeyType::Char, 'w'))[0];
        $wt = $this->type($wt, 'a');
        $wt = $wt->update(new KeyMsg(KeyType::Char, "\x1e"))[0];
        self::assertSame('', $wt->worktrees->newPath);
    }

    /**
     * The gate is the Unicode category-C rule, so invisibles are refused too
     * (they corrupt cells and travel into argv); printable non-ASCII — the
     * regular subject case for every non-Latin user — keeps flowing.
     */
    public function testInvisibleFormatRunesRefusedPrintableUnicodeAccepted(): void
    {
        $a = $this->commitCollector();
        $a = $this->type($a, 'fix ✨');
        $a = $a->update(new KeyMsg(KeyType::Char, "\u{200b}"))[0]; // zero-width space (Cf)
        $a = $a->update(new KeyMsg(KeyType::Char, "\u{feff}"))[0];  // BOM (Cf)
        self::assertSame('fix ✨', $a->commitMessage);
    }

    public function testMalformedUtf8RuneIsRefusedNotPassedThrough(): void
    {
        // preg with /u returns FALSE on a lone continuation byte; the gate must
        // fail CLOSED (Renderer::sanitize audit #7 precedent), not accept.
        $a = $this->commitCollector()->update(new KeyMsg(KeyType::Char, "\xff"))[0];
        self::assertSame('', $a->commitMessage);
    }
}
