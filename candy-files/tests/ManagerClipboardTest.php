<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use SugarCraft\Core\RawMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Files\Tests\Support\FsTestCase;

/**
 * CF-50: `w` / `W` copy the path / name over OSC 52. The capture seam is
 * the Cmd itself - Cmd::setClipboard resolves to a RawMsg carrying the
 * exact bytes the runtime will write, so the sequence is asserted without
 * a terminal.
 */
final class ManagerClipboardTest extends FsTestCase
{
    /** @var string|false */
    private $oldTerm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oldTerm = getenv('TERM');
        putenv('TERM=xterm-256color');
        file_put_contents($this->tmp . '/one.txt', '1');
        file_put_contents($this->tmp . '/two.txt', '2');
    }

    protected function tearDown(): void
    {
        $this->oldTerm === false ? putenv('TERM') : putenv('TERM=' . $this->oldTerm);
        parent::tearDown();
    }

    /** @return array{0:\SugarCraft\Files\Manager,1:?\Closure} */
    private function send(\SugarCraft\Files\Manager $m, string $rune): array
    {
        return $m->update(new \SugarCraft\Core\Msg\KeyMsg(KeyType::Char, $rune));
    }

    private function bytes(?\Closure $cmd): string
    {
        $this->assertNotNull($cmd, 'the chord must return a Cmd');
        $msg = $cmd();
        $this->assertInstanceOf(RawMsg::class, $msg);
        return $msg->bytes;
    }

    public function testWEmitsTheFullPathAsOsc52(): void
    {
        [$m, $cmd] = $this->send($this->cursorOn($this->manager(), 'one.txt'), 'w');
        $this->assertSame(Ansi::setClipboard($this->tmp . '/one.txt'), $this->bytes($cmd));
        $this->assertStringContainsString("\x1b]52;c;" . base64_encode($this->tmp . '/one.txt'), $this->bytes($cmd));
        $this->assertStringContainsString('may ignore it', $m->status);
        $this->assertStringContainsString('one.txt', $m->status);
    }

    public function testCapitalWEmitsTheBareName(): void
    {
        [, $cmd] = $this->send($this->cursorOn($this->manager(), 'two.txt'), 'W');
        $this->assertSame(Ansi::setClipboard('two.txt'), $this->bytes($cmd));
    }

    public function testSelectionIsNewlineJoined(): void
    {
        $m = $this->cursorOn($this->manager(), 'one.txt');
        [$m] = $m->update(new \SugarCraft\Core\Msg\KeyMsg(KeyType::Space, ' '));
        [$m] = $m->update(new \SugarCraft\Core\Msg\KeyMsg(KeyType::Space, ' '));
        [$m, $cmd] = $this->send($m, 'w');
        $this->assertSame(Ansi::setClipboard($this->tmp . "/one.txt\n" . $this->tmp . '/two.txt'), $this->bytes($cmd));
        $this->assertStringContainsString('copied 2 entries', $m->status);
    }

    public function testParentSentinelCopiesTheDirectoryItself(): void
    {
        [, $cmd] = $this->send($this->manager(), 'w');
        $this->assertSame(Ansi::setClipboard($this->tmp), $this->bytes($cmd));
        [, $cmd2] = $this->send($this->manager(), 'W');
        $this->assertSame(Ansi::setClipboard(basename($this->tmp)), $this->bytes($cmd2));
    }

    public function testIncapableTermIsRefusedWithNoBytes(): void
    {
        foreach (['dumb', 'linux', ''] as $term) {
            putenv('TERM=' . $term);
            [$m, $cmd] = $this->send($this->cursorOn($this->manager(), 'one.txt'), 'w');
            $this->assertNull($cmd, "TERM='{$term}' must not emit");
            $this->assertStringContainsString('cannot receive clipboard writes', $m->status);
        }
    }

    public function testOversizedPayloadIsRefusedNotTruncated(): void
    {
        $long = str_repeat('n', 200);
        for ($i = 0; $i < 600; $i++) {
            touch(sprintf('%s/%s%03d', $this->tmp, $long, $i));
        }
        $m = $this->manager();
        [$m] = $m->update(new \SugarCraft\Core\Msg\KeyMsg(KeyType::Char, 'a', ctrl: true));
        [$m, $cmd] = $this->send($m, 'w');
        $this->assertNull($cmd);
        $this->assertStringContainsString('too large', $m->status);
    }

    public function testModifiedChordsAreNotCaptured(): void
    {
        [, $cmd] = $this->cursorOn($this->manager(), 'one.txt')
            ->update(new \SugarCraft\Core\Msg\KeyMsg(KeyType::Char, 'w', ctrl: true));
        $this->assertNull($cmd, 'Ctrl+w stays the tab-close chord');
    }

    public function testTheChordsAreInTheHelpLegend(): void
    {
        $this->assertStringContainsString('w/W copy path/name', \SugarCraft\Files\Lang::t('keyhelp.default'));
    }
}
