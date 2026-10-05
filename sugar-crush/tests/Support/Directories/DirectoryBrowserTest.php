<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support\Directories;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Support\Directories\DirectoryBrowser;
use SugarCraft\Crush\Support\Directories\DirectoryBrowserException;
use SugarCraft\Crush\Support\Directories\DirectoryEntry;
use SugarCraft\Crush\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * The one directory listing behind `fs.listDirs` and the TUI's `/new` picker:
 * directories only, sorted, capped, hidden ones on request, project roots
 * marked — and, rooted, nothing outside the root however it is spelled.
 */
final class DirectoryBrowserTest extends TestCase
{
    use HomeSandboxTrait;

    private string $dir = '';

    private string $root = '';

    protected function setUp(): void
    {
        $this->dir = (string) \realpath(\sys_get_temp_dir()) . '/crush-dirs-' . \bin2hex(\random_bytes(6));
        $this->root = $this->dir . '/root';
        foreach (['alpha', 'Beta', 'gamma/inner', 'item10', 'item9', '.hidden', 'app/.git', 'lib'] as $sub) {
            \mkdir($this->root . '/' . $sub, 0o700, true);
        }
        \file_put_contents($this->root . '/lib/composer.json', '{}');
        \file_put_contents($this->root . '/README.md', 'a file, never listed');
        \mkdir($this->dir . '/outside', 0o700, true);
        \symlink($this->dir . '/outside', $this->root . '/escape');
        \symlink($this->root . '/alpha', $this->root . '/alias');
        \symlink($this->root . '/nowhere', $this->root . '/dangling');
    }

    protected function tearDown(): void
    {
        \chmod($this->root . '/alpha', 0o700);
        ProtocolFixture::removeTree($this->dir);
    }

    /** @param list<DirectoryEntry> $entries @return list<string> */
    private static function names(array $entries): array
    {
        return \array_map(static fn (DirectoryEntry $e): string => $e->name, $entries);
    }

    public function testItListsChildDirectoriesOnlySortedNaturally(): void
    {
        $listing = DirectoryBrowser::new($this->root)->list('');

        self::assertSame($this->root, $listing->path);
        self::assertNull($listing->parent, 'the browse root has no parent to go up to');
        self::assertSame(['alias', 'alpha', 'app', 'Beta', 'gamma', 'item9', 'item10', 'lib'], self::names($listing->entries), 'no file, no hidden dir, no link out of the root, no dangling link');
        self::assertFalse($listing->truncated);
        self::assertTrue($listing->readable);
        self::assertSame($this->root . '/alpha', $listing->entries[0]->path, 'a link inside the root is followed');
    }

    public function testProjectRootsAreMarked(): void
    {
        $byName = [];
        foreach (DirectoryBrowser::new($this->root)->list('')->entries as $entry) {
            $byName[$entry->name] = $entry->project;
        }

        self::assertTrue($byName['app'], '.git marks a project');
        self::assertTrue($byName['lib'], 'composer.json marks a project');
        self::assertFalse($byName['alpha']);
        self::assertTrue(DirectoryBrowser::isProjectRoot($this->root . '/app'));
    }

    public function testHiddenDirectoriesOnRequest(): void
    {
        self::assertContains('.hidden', self::names(DirectoryBrowser::new($this->root)->list('', true)->entries));
        self::assertContains('.git', self::names(DirectoryBrowser::new($this->root)->list('app', true)->entries));
    }

    public function testDescendingAndGoingUp(): void
    {
        $browser = DirectoryBrowser::new($this->root);
        $gamma = $browser->list('gamma');

        self::assertSame($this->root . '/gamma', $gamma->path);
        self::assertSame($this->root, $gamma->parent);
        self::assertSame(['inner'], self::names($gamma->entries));
        self::assertSame($this->root . '/gamma/inner', $browser->list('inner', false, $gamma->path)->path, 'relative to a base');
        self::assertSame($this->root, $browser->list($this->root . '/gamma/..')->path);
    }

    public function testNothingOutsideTheRootResolves(): void
    {
        $browser = DirectoryBrowser::new($this->root);
        foreach (['..', '../outside', '/etc', $this->root . '/../outside', 'gamma/../../outside', 'escape', $this->dir . '/does-not-exist'] as $path) {
            try {
                $browser->list($path);
                self::fail('listed ' . $path);
            } catch (DirectoryBrowserException $e) {
                self::assertSame(DirectoryBrowserException::REASON_OUTSIDE_ROOT, $e->reason, $path);
            }
        }
    }

    public function testAMissingPathOrAFileIsReported(): void
    {
        $browser = DirectoryBrowser::new($this->root);
        try {
            $browser->list('nope');
            self::fail('listed a missing directory');
        } catch (DirectoryBrowserException $e) {
            self::assertSame(DirectoryBrowserException::REASON_NOT_FOUND, $e->reason);
        }
        try {
            $browser->list('README.md');
            self::fail('listed a file');
        } catch (DirectoryBrowserException $e) {
            self::assertSame(DirectoryBrowserException::REASON_NOT_DIRECTORY, $e->reason);
        }
    }

    public function testAnUnreadableDirectoryIsReportedNotFatal(): void
    {
        if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            self::markTestSkipped('root reads every directory');
        }
        \chmod($this->root . '/alpha', 0o000);

        $listing = DirectoryBrowser::new($this->root)->list('alpha');
        self::assertFalse($listing->readable);
        self::assertSame([], $listing->entries);

        $entry = \array_values(\array_filter(DirectoryBrowser::new($this->root)->list('')->entries, static fn (DirectoryEntry $e): bool => $e->name === 'alpha'))[0];
        self::assertFalse($entry->readable);
    }

    public function testTheCapCutsTheListAndSaysSo(): void
    {
        $listing = DirectoryBrowser::new($this->root, 3)->list('');

        self::assertSame(['alias', 'alpha', 'app'], self::names($listing->entries));
        self::assertTrue($listing->truncated);
    }

    public function testUnrootedItGoesAnywhereAndStopsAtSlash(): void
    {
        $browser = DirectoryBrowser::new();

        self::assertNull($browser->root());
        self::assertSame($this->dir . '/outside', $browser->list($this->root . '/escape')->path);
        self::assertSame($this->dir, $browser->list($this->root)->parent);
        self::assertNull($browser->list('/')->parent);
    }

    public function testTildeIsTheHomeDirectory(): void
    {
        $this->useHomeSandbox($this->root);
        try {
            self::assertSame($this->root . '/gamma', DirectoryBrowser::new('~')->list('~/gamma')->path);
        } finally {
            $this->restoreHomeSandbox();
        }
    }

    public function testABrowseRootThatIsNotADirectoryIsRefused(): void
    {
        $this->expectException(DirectoryBrowserException::class);
        DirectoryBrowser::new($this->root . '/README.md');
    }
}
