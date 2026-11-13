<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use SugarCraft\Core\ExecRequest;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Files\Manager;
use SugarCraft\Files\Tests\Support\FsTestCase;

/**
 * CF-03 residual: the in-app read-only viewer (the `l` fallback when no
 * pager resolves) and `o` open-with-default (argv-list spawn).
 */
final class ManagerViewerTest extends FsTestCase
{
    /** @var array<string, string|false> */
    private array $env = [];
    private string $bin;

    protected function setUp(): void
    {
        parent::setUp();
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX PATH probing');
        }
        foreach (['PAGER', 'PATH'] as $v) {
            $this->env[$v] = getenv($v);
        }
        $this->bin = $this->tmp . '/bin';
        mkdir($this->bin);
        // A PATH holding only our fixture bin: no less, no more, no xdg-open.
        putenv('PATH=' . $this->bin);
        putenv('PAGER=');
    }

    protected function tearDown(): void
    {
        foreach ($this->env as $k => $v) {
            $v === false ? putenv($k) : putenv("$k=$v");
        }
        parent::tearDown();
    }

    private function viewing(string $name, string $body, int $rows = 0): Manager
    {
        file_put_contents($this->tmp . '/' . $name, $body);
        $m = $this->manager();
        if ($rows > 0) {
            [$m] = $m->update(new WindowSizeMsg(100, $rows));
        }
        return $this->press($this->cursorOn($m, $name), 'l');
    }

    public function testNoPagerOpensTheInAppViewer(): void
    {
        $m = $this->viewing('log.txt', "alpha\nbeta\ngamma\n");
        $this->assertNotNull($m->viewer);
        $this->assertSame(['alpha', 'beta', 'gamma'], $m->viewer['lines']);
        $view = $m->view();
        $this->assertStringContainsString('log.txt — line 1/3', $view);
        $this->assertStringContainsString('beta', $view);
        $this->assertStringContainsString('q or Esc closes', $view);
    }

    public function testPagerStillWinsWhenOneResolves(): void
    {
        file_put_contents($this->bin . '/less', "#!/bin/sh\n");
        chmod($this->bin . '/less', 0755);
        $m = $this->viewing('log.txt', "x\n");
        $this->assertNull($m->viewer);
        $this->assertStringContainsString('viewing', $m->status);
    }

    public function testBinaryStillRefusesInsteadOfViewing(): void
    {
        $m = $this->viewing('blob.bin', "\x7fELF\0\0\0");
        $this->assertNull($m->viewer);
        $this->assertStringContainsString('no viewer', $m->status);
    }

    public function testControlBytesAndBadUtf8AreNeutralised(): void
    {
        $m = $this->viewing('evil.txt', "ok\n\x1b]0;pwned\x07tail\ntab\there\n\xff\xfebad\n");
        $text = implode("\n", $m->viewer['lines'] ?? []);
        $this->assertStringNotContainsString("\x1b", $text);
        $this->assertStringNotContainsString("\x07", $text);
        $this->assertStringContainsString('tab    here', $text);
        $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
        // The frame's own bold title carries SGR; the FILE's escapes must not.
        $this->assertStringNotContainsString('pwned' . "\x07", $m->view());
        $this->assertStringNotContainsString("\x1b]0;", $m->view());
    }

    public function testScrollKeysClampAndPage(): void
    {
        $body = implode("\n", array_map(static fn($i) => "row$i", range(1, 50))) . "\n";
        $m = $this->viewing('big.txt', $body, 14);              // page = 10
        $this->assertSame(0, $m->viewer['top']);
        $m = $this->press($m, 'j');
        $this->assertSame(1, $m->viewer['top']);
        $m = $this->key($m, KeyType::PageDown);
        $this->assertSame(11, $m->viewer['top']);
        $m = $this->press($m, 'G');
        $this->assertSame(40, $m->viewer['top'], 'last page, never past the end');
        $m = $this->press($m, 'j');
        $this->assertSame(40, $m->viewer['top']);
        $m = $this->press($m, 'g');
        $this->assertSame(0, $m->viewer['top']);
        $m = $this->press($m, 'k');
        $this->assertSame(0, $m->viewer['top']);
        $this->assertLessThanOrEqual(14, count(explode("\n", $m->view())));
    }

    public function testViewerSwallowsKeysAndQClosesItWithoutQuitting(): void
    {
        $m = $this->viewing('a.txt', "x\n");
        $same = $this->press($m, 'd');
        $this->assertNotNull($same->viewer);
        $this->assertSame(\SugarCraft\Files\ConfirmState::None, $same->confirm);
        [$closed, $cmd] = $m->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertNull($closed->viewer);
        $this->assertNull($cmd, 'q closes the viewer, it does not quit the app');
        $this->assertNull($this->key($m, KeyType::Escape)->viewer);
    }

    public function testEmptyFileShowsTheEmptyNote(): void
    {
        $m = $this->viewing('empty.txt', '');
        $this->assertStringContainsString('(empty file)', $m->view());
    }

    public function testOnlyTheHeadOfALargeFileIsRead(): void
    {
        $m = $this->viewing('long.txt', str_repeat("0123456789\n", 40000));   // ~440 KB
        $this->assertTrue($m->viewer['truncated']);
        $this->assertLessThanOrEqual(Manager::VIEWER_MAX_BYTES / 11 + 1, count($m->viewer['lines']));
        $this->assertStringContainsString('first 256 KiB shown', $m->view());
    }

    public function testWheelScrollsTheViewer(): void
    {
        $body = implode("\n", range(1, 60)) . "\n";
        $m = $this->viewing('w.txt', $body, 14);
        [$m] = $m->update(new \SugarCraft\Core\Msg\MouseWheelMsg(5, 5, \SugarCraft\Core\MouseButton::WheelDown, \SugarCraft\Core\MouseAction::Press));
        $this->assertSame(3, $m->viewer['top']);
    }

    // --- open with default ----------------------------------------------

    private function fakeOpener(): void
    {
        $name = PHP_OS_FAMILY === 'Darwin' ? 'open' : 'xdg-open';
        file_put_contents($this->bin . '/' . $name, "#!/bin/sh\n");
        chmod($this->bin . '/' . $name, 0755);
    }

    public function testOSpawnsTheDefaultOpenerWithAnArgvList(): void
    {
        $this->fakeOpener();
        file_put_contents($this->tmp . '/it\'s; rm -rf x.txt', 'x');
        $m = $this->cursorOn($this->manager(), 'it\'s; rm -rf x.txt');
        [$m, $cmd] = $m->update(new KeyMsg(KeyType::Char, 'o'));
        $this->assertInstanceOf(\Closure::class, $cmd);
        $req = $cmd();
        $this->assertInstanceOf(ExecRequest::class, $req);
        $this->assertIsArray($req->command, 'argv list - never a shell string');
        $this->assertSame([$this->bin . '/' . (PHP_OS_FAMILY === 'Darwin' ? 'open' : 'xdg-open'), $this->tmp . '/it\'s; rm -rf x.txt'], $req->command);
        $this->assertStringContainsString('default application', $m->status);
    }

    public function testOWithoutAnOpenerSaysSo(): void
    {
        file_put_contents($this->tmp . '/a.txt', 'x');
        [$m, $cmd] = $this->cursorOn($this->manager(), 'a.txt')->update(new KeyMsg(KeyType::Char, 'o'));
        $this->assertNull($cmd);
        $this->assertStringContainsString('no default-open helper', $m->status);
    }

    public function testOOnTheSentinelOrWithCtrlIsInert(): void
    {
        $this->fakeOpener();
        [$m, $cmd] = $this->manager()->update(new KeyMsg(KeyType::Char, 'o'));
        $this->assertNull($cmd);
        $this->assertSame('nothing to open', $m->status);
        file_put_contents($this->tmp . '/a.txt', 'x');
        [, $cmd2] = $this->cursorOn($this->manager(), 'a.txt')->update(new KeyMsg(KeyType::Char, 'o', ctrl: true));
        $this->assertNull($cmd2, 'Ctrl+O stays the typed-path prompt');
    }

    public function testODirectoriesOpenToo(): void
    {
        $this->fakeOpener();
        mkdir($this->tmp . '/folder');
        [, $cmd] = $this->cursorOn($this->manager(), 'folder')->update(new KeyMsg(KeyType::Char, 'o'));
        $this->assertInstanceOf(\Closure::class, $cmd);
    }
}
