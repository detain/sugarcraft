<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Files\Entry;
use SugarCraft\Files\FsLister;
use SugarCraft\Files\Tests\Support\FsTestCase;

/**
 * CF-52: the metadata surface - an mtime column on wide terminals, the
 * `I` stat overlay, and the perms/owner fields the lister now carries.
 */
final class ManagerInfoTest extends FsTestCase
{
    private function sized(int $cols): \SugarCraft\Files\Manager
    {
        [$m] = $this->manager()->update(new WindowSizeMsg($cols, 30));
        return $m;
    }

    public function testWideTerminalShowsTheMtimeColumn(): void
    {
        file_put_contents($this->tmp . '/dated.txt', 'x');
        touch($this->tmp . '/dated.txt', 1700000000);
        $view = $this->sized(120)->view();
        $this->assertStringContainsString(date('Y-m-d H:i', 1700000000), $view);
    }

    public function testNarrowTerminalHasNoDateColumn(): void
    {
        file_put_contents($this->tmp . '/dated.txt', 'x');
        touch($this->tmp . '/dated.txt', 1700000000);
        $this->assertStringNotContainsString(date('Y-m-d', 1700000000), $this->sized(80)->view());
    }

    public function testUnknownWidthKeepsTheLegacyRow(): void
    {
        file_put_contents($this->tmp . '/dated.txt', 'x');
        $this->assertStringNotContainsString(date('Y-'), $this->manager()->view());
    }

    public function testListerCarriesModeAndOwnership(): void
    {
        file_put_contents($this->tmp . '/f.sh', 'x');
        chmod($this->tmp . '/f.sh', 0750);
        $entry = null;
        foreach (FsLister::lister()($this->tmp) ?? [] as $e) {
            $entry = $e;
        }
        $this->assertNotNull($entry);
        $this->assertSame(0100750, $entry->mode);
        $this->assertSame(fileowner($this->tmp . '/f.sh'), $entry->uid);
        $this->assertSame(filegroup($this->tmp . '/f.sh'), $entry->gid);
    }

    public function testSymbolicModeRendersTypeAndSpecialBits(): void
    {
        $this->assertSame('-rw-r--r--', Entry::symbolicMode(0100644));
        $this->assertSame('drwxr-xr-x', Entry::symbolicMode(0040755));
        $this->assertSame('-rwsr-xr-x', Entry::symbolicMode(0104755));
        $this->assertSame('drwxrwxrwt', Entry::symbolicMode(0041777));
        $this->assertSame('lrwxrwxrwx', Entry::symbolicMode(0120777));
        $this->assertSame('-rwSr--r--', Entry::symbolicMode(0104644));
    }

    public function testHumanSizeMatchesTheSizeColumn(): void
    {
        $this->assertSame('512B', Entry::humanSize(512));
        $this->assertSame('1.5KB', Entry::humanSize(1536));
        $this->assertSame('1.5KB', (new Entry('a', false, 1536, 0))->displaySize());
    }

    public function testIOpensOverlayWithFreshStatLines(): void
    {
        file_put_contents($this->tmp . '/doc.txt', str_repeat('a', 2048));
        chmod($this->tmp . '/doc.txt', 0640);
        $m = $this->press($this->cursorOn($this->manager(), 'doc.txt'), 'I');
        $this->assertNotNull($m->infoLines);
        $text = implode("\n", $m->infoLines);
        $this->assertStringContainsString($this->tmp . '/doc.txt', $text);
        $this->assertStringContainsString('-rw-r-----', $text);
        $this->assertStringContainsString('(0640)', $text);
        $this->assertStringContainsString('2.0KB (2048 B)', $text);
        $this->assertStringContainsString('Free space', $text);
        $view = $m->view();
        $this->assertStringContainsString('File information', $view);
        $this->assertStringContainsString('I or Esc closes', $view);
    }

    public function testOverlaySwallowsKeysAndDismissesWithEscOrI(): void
    {
        file_put_contents($this->tmp . '/a.txt', 'x');
        $open = $this->press($this->cursorOn($this->manager(), 'a.txt'), 'I');
        $same = $this->press($open, 'd');
        $this->assertNotNull($same->infoLines, 'foreign keys must not leak through the overlay');
        $this->assertSame(\SugarCraft\Files\ConfirmState::None, $same->confirm);
        $this->assertNull($this->key($open, KeyType::Escape)->infoLines);
        $this->assertNull($this->press($open, 'I')->infoLines);
    }

    public function testSymlinkOverlayNamesTheTarget(): void
    {
        file_put_contents($this->tmp . '/real.txt', 'x');
        symlink($this->tmp . '/real.txt', $this->tmp . '/ln');
        $m = $this->press($this->cursorOn($this->manager(), 'ln'), 'I');
        $text = implode("\n", $m->infoLines ?? []);
        $this->assertStringContainsString('symbolic link', $text);
        $this->assertStringContainsString('Link target: ' . $this->tmp . '/real.txt', $text);
    }

    public function testParentSentinelDescribesTheDirectoryItself(): void
    {
        $m = $this->press($this->manager(), 'I');
        $this->assertStringContainsString('directory', implode("\n", $m->infoLines ?? []));
    }

    public function testOverlayWindowsToTheLiveHeight(): void
    {
        file_put_contents($this->tmp . '/a.txt', 'x');
        $m = $this->press($this->cursorOn($this->manager(), 'a.txt'), 'I');
        [$m] = $m->update(new WindowSizeMsg(100, 8));
        $this->assertLessThanOrEqual(8, count(explode("\n", $m->view())));
    }

    public function testVanishedEntryReportsAStatusNotAnOverlay(): void
    {
        file_put_contents($this->tmp . '/gone.txt', 'x');
        $m = $this->cursorOn($this->manager(), 'gone.txt');
        unlink($this->tmp . '/gone.txt');
        $m = $this->press($m, 'I');
        $this->assertNull($m->infoLines);
        $this->assertStringContainsString('cannot read info', $m->status);
    }
}
