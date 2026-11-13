<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Files\Manager;
use SugarCraft\Files\Support\Bookmarks;
use SugarCraft\Files\Tests\Support\FsTestCase;

/**
 * CF-30: bookmarks - `a` pins the cwd, `B` opens the overlay, Enter jumps
 * through the CF-29 path-open door, `d` unpins; the list persists as JSON
 * under $XDG_DATA_HOME/candy-files/ via an atomic tmp+rename write.
 */
final class ManagerBookmarksTest extends FsTestCase
{
    private string $data;
    /** @var string|false */
    private $oldXdg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->data = $this->tmp . '/xdg';
        $this->oldXdg = getenv('XDG_DATA_HOME');
        putenv('XDG_DATA_HOME=' . $this->data);
        mkdir($this->tmp . '/alpha');
        mkdir($this->tmp . '/beta');
    }

    protected function tearDown(): void
    {
        $this->oldXdg === false ? putenv('XDG_DATA_HOME') : putenv('XDG_DATA_HOME=' . $this->oldXdg);
        parent::tearDown();
    }

    private function file(): string
    {
        return $this->data . '/candy-files/bookmarks.json';
    }

    private function at(string $dir): Manager
    {
        return Manager::start($dir, $this->tmp, \SugarCraft\Files\FsLister::lister());
    }

    public function testPathHonoursXdgThenHomeFallback(): void
    {
        $this->assertSame('/x/candy-files/bookmarks.json', Bookmarks::path(['XDG_DATA_HOME' => '/x']));
        $this->assertSame('/h/.local/share/candy-files/bookmarks.json', Bookmarks::path(['HOME' => '/h']));
    }

    public function testPinPersistsAtomicallyWithPrivateModes(): void
    {
        $m = $this->press($this->at($this->tmp . '/alpha'), 'a');
        $this->assertSame([$this->tmp . '/alpha'], $m->bookmarks);
        $this->assertStringContainsString('pinned ' . $this->tmp . '/alpha', $m->status);
        $this->assertSame([$this->tmp . '/alpha'], Bookmarks::load($this->file()));
        $this->assertSame(0700, fileperms(dirname($this->file())) & 0777);
        $this->assertSame(0600, fileperms($this->file()) & 0777);
        $this->assertSame([], glob(dirname($this->file()) . '/*.tmp'), 'no torn temp file survives');
    }

    public function testPinningTwiceIsIdempotent(): void
    {
        $m = $this->press($this->press($this->at($this->tmp . '/alpha'), 'a'), 'a');
        $this->assertCount(1, $m->bookmarks);
        $this->assertStringContainsString('already pinned', $m->status);
    }

    public function testNextSessionLoadsThePins(): void
    {
        $this->press($this->at($this->tmp . '/alpha'), 'a');
        $fresh = $this->at($this->tmp);
        $this->assertSame([$this->tmp . '/alpha'], $fresh->bookmarks);
    }

    public function testCorruptOrMissingStoreIsAnEmptyList(): void
    {
        $this->assertSame([], Bookmarks::load($this->file()));
        mkdir(dirname($this->file()), 0700, true);
        file_put_contents($this->file(), '{not json');
        $this->assertSame([], Bookmarks::load($this->file()));
        file_put_contents($this->file(), json_encode(['bookmarks' => ['/a', '/a', 7, '', '/b']]));
        $this->assertSame(['/a', '/b'], Bookmarks::load($this->file()));
    }

    public function testSaveFailsHonestlyWhenTheDirectoryCannotBeMade(): void
    {
        file_put_contents($this->tmp . '/blocker', 'x');
        $this->assertFalse(Bookmarks::save(['/a'], $this->tmp . '/blocker/sub/bm.json'));
    }

    public function testOverlayOpensListsAndClosesWithEscOrB(): void
    {
        $m = $this->press($this->at($this->tmp . '/alpha'), 'a');
        $open = $this->press($m, 'B');
        $this->assertSame(0, $open->bookmarkCursor);
        $view = $open->view();
        $this->assertStringContainsString('1 bookmarks', $view);
        $this->assertStringContainsString($this->tmp . '/alpha', $view);
        $this->assertNull($this->key($open, KeyType::Escape)->bookmarkCursor);
        $this->assertNull($this->press($open, 'B')->bookmarkCursor);
    }

    public function testEmptyOverlayTeachesThePinChord(): void
    {
        $this->assertStringContainsString('a pins the current directory', $this->press($this->at($this->tmp), 'B')->view());
    }

    public function testEnterJumpsTheActivePaneThere(): void
    {
        $m = $this->press($this->at($this->tmp . '/alpha'), 'a');
        $m = $this->press($this->at($this->tmp . '/beta'), 'a');   // second pin (reloads from store)
        $this->assertSame([$this->tmp . '/alpha', $this->tmp . '/beta'], $m->bookmarks);
        $m = $this->press($this->at($this->tmp), 'B');
        $m = $this->press($m, 'j');
        $this->assertSame(1, $m->bookmarkCursor);
        $m = $this->key($m, KeyType::Enter);
        $this->assertNull($m->bookmarkCursor);
        $this->assertSame($this->tmp . '/beta', $m->activePane()->cwd);
    }

    public function testVanishedBookmarkIsRefusedAndThePaneStays(): void
    {
        $m = $this->press($this->at($this->tmp . '/alpha'), 'a');
        rmdir($this->tmp . '/alpha');
        $m = $this->press($this->at($this->tmp), 'B');
        $m = $this->key($m, KeyType::Enter);
        $this->assertSame($this->tmp, $m->activePane()->cwd);
        $this->assertStringContainsString('Cannot read', $m->status);
    }

    public function testDRemovesAndPersists(): void
    {
        $this->press($this->at($this->tmp . '/alpha'), 'a');
        $this->press($this->at($this->tmp . '/beta'), 'a');
        $m = $this->press($this->press($this->at($this->tmp), 'B'), 'd');
        $this->assertSame([$this->tmp . '/beta'], $m->bookmarks);
        $this->assertSame([$this->tmp . '/beta'], Bookmarks::load($this->file()));
        $this->assertStringContainsString('unpinned', $m->status);
        $m = $this->press($m, 'd');
        $this->assertSame([], $m->bookmarks);
        $this->assertSame(0, $m->bookmarkCursor);
    }

    public function testOverlaySwallowsForeignKeys(): void
    {
        $m = $this->press($this->at($this->tmp . '/alpha'), 'a');
        $open = $this->press($m, 'B');
        $same = $this->press($open, 'q');                 // would quit outside the overlay
        $this->assertSame(0, $same->bookmarkCursor);
        $this->assertSame(\SugarCraft\Files\ConfirmState::None, $this->press($same, 'x')->confirm);
    }

    public function testLongListWindowsToTheLiveHeight(): void
    {
        $paths = [];
        for ($i = 0; $i < 40; $i++) {
            $paths[] = $this->tmp . '/dir' . $i;
        }
        Bookmarks::save($paths, $this->file());
        $m = $this->press($this->at($this->tmp), 'B');
        [$m] = $m->update(new WindowSizeMsg(100, 14));
        for ($i = 0; $i < 25; $i++) {
            $m = $this->press($m, 'j');
        }
        $lines = explode("\n", $m->view());
        $this->assertLessThanOrEqual(14, count($lines));
        $this->assertStringContainsString('dir25', $m->view());
    }

    public function testBookmarkOverlayIgnoresTheMouse(): void
    {
        $m = $this->press($this->at($this->tmp . '/alpha'), 'a');
        $open = $this->press($m, 'B');
        [$after] = $open->update(new \SugarCraft\Core\Msg\MouseClickMsg(5, 5, \SugarCraft\Core\MouseButton::Left, \SugarCraft\Core\MouseAction::Press));
        $this->assertSame($open, $after);
    }
}
