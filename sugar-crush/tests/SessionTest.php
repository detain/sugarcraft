<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Session;
use Throwable;

final class SessionTest extends TestCase
{
    private string $tempDir;
    private string $originalHome;

    protected function setUp(): void
    {
        parent::setUp();
        // Create a temp directory to isolate session file I/O
        $this->tempDir = sys_get_temp_dir() . '/sugar-crush-session-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
        // Override HOME to point to our temp directory
        $this->originalHome = getenv('HOME') ?: '';
        putenv('HOME=' . $this->tempDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Restore HOME
        if ($this->originalHome !== '') {
            putenv('HOME=' . $this->originalHome);
        } else {
            putenv('HOME');
        }
        // Clean up temp directory
        $this->removeDirectory($this->tempDir);
    }

    public function testDefaultSessionHasEmptyValues(): void
    {
        $session = new Session();
        $this->assertSame('', $session->cwd);
        $this->assertSame([], $session->selected);
        $this->assertSame('', $session->filter);
        $this->assertSame('name', $session->sortColumn);
        $this->assertSame('asc', $session->sortDir);
        $this->assertSame('files', $session->activePane);
    }

    public function testWithCwdReturnsNewInstance(): void
    {
        $session = new Session();
        $next = $session->withCwd('/home/user/projects');
        $this->assertNotSame($session, $next);
        $this->assertSame('/home/user/projects', $next->cwd);
        $this->assertSame('', $session->cwd);
    }

    public function testWithSelectedReturnsNewInstance(): void
    {
        $session = new Session();
        $files = ['/home/user/file1.txt', '/home/user/file2.txt'];
        $next = $session->withSelected($files);
        $this->assertNotSame($session, $next);
        $this->assertSame($files, $next->selected);
        $this->assertSame([], $session->selected);
    }

    public function testWithFilterReturnsNewInstance(): void
    {
        $session = new Session();
        $next = $session->withFilter('*.php');
        $this->assertNotSame($session, $next);
        $this->assertSame('*.php', $next->filter);
        $this->assertSame('', $session->filter);
    }

    public function testWithSortReturnsNewInstance(): void
    {
        $session = new Session();
        $next = $session->withSort('size', 'desc');
        $this->assertNotSame($session, $next);
        $this->assertSame('size', $next->sortColumn);
        $this->assertSame('desc', $next->sortDir);
        $this->assertSame('name', $session->sortColumn);
        $this->assertSame('asc', $session->sortDir);
    }

    public function testWithActivePaneReturnsNewInstance(): void
    {
        $session = new Session();
        $next = $session->withActivePane('details');
        $this->assertNotSame($session, $next);
        $this->assertSame('details', $next->activePane);
        $this->assertSame('files', $session->activePane);
    }

    public function testSaveLoadRoundtrip(): void
    {
        $session = (new Session())
            ->withCwd('/home/user')
            ->withSelected(['/home/user/file1.txt', '/home/user/file2.txt'])
            ->withFilter('*.php')
            ->withSort('size', 'desc')
            ->withActivePane('details');

        $session->save();

        $loaded = Session::load();

        $this->assertSame('/home/user', $loaded->cwd);
        $this->assertSame(['/home/user/file1.txt', '/home/user/file2.txt'], $loaded->selected);
        $this->assertSame('*.php', $loaded->filter);
        $this->assertSame('size', $loaded->sortColumn);
        $this->assertSame('desc', $loaded->sortDir);
        $this->assertSame('details', $loaded->activePane);
    }

    public function testLoadMissingFileReturnsFreshSession(): void
    {
        // Ensure no session file exists
        $path = $this->tempDir . '/.config/sugarcraft-crush/session.json';
        $this->assertFileDoesNotExist($path);

        $session = Session::load();

        // Should return fresh empty session
        $this->assertSame('', $session->cwd);
        $this->assertSame([], $session->selected);
        $this->assertSame('', $session->filter);
        $this->assertSame('name', $session->sortColumn);
        $this->assertSame('asc', $session->sortDir);
        $this->assertSame('files', $session->activePane);
    }

    public function testLoadCorruptedJsonReturnsFreshSession(): void
    {
        $configDir = $this->tempDir . '/.config/sugarcraft-crush';
        mkdir($configDir, 0755, true);
        $path = $configDir . '/session.json';

        // Write corrupted JSON
        file_put_contents($path, "{ this is not valid json }");

        $session = Session::load();

        // Should return fresh empty session rather than throwing
        $this->assertSame('', $session->cwd);
        $this->assertSame([], $session->selected);
    }

    public function testLoadPartialJsonReturnsDefaults(): void
    {
        $configDir = $this->tempDir . '/.config/sugarcraft-crush';
        mkdir($configDir, 0755, true);
        $path = $configDir . '/session.json';

        // Write partial JSON (only some fields)
        file_put_contents($path, json_encode([
            'cwd' => '/custom/path',
            'filter' => '*.md',
        ]));

        $session = Session::load();

        // Known fields preserved
        $this->assertSame('/custom/path', $session->cwd);
        $this->assertSame('*.md', $session->filter);
        // Unknown/missing fields use defaults
        $this->assertSame([], $session->selected);
        $this->assertSame('name', $session->sortColumn);
        $this->assertSame('asc', $session->sortDir);
        $this->assertSame('files', $session->activePane);
    }

    public function testSaveCreatesConfigDirectory(): void
    {
        $path = $this->tempDir . '/.config/sugarcraft-crush/session.json';
        $this->assertFileDoesNotExist($path);

        $session = (new Session())->withCwd('/test');
        $session->save();

        $this->assertFileExists($path);
        $this->assertDirectoryExists($this->tempDir . '/.config/sugarcraft-crush');
    }

    public function testSaveWritesOwnerOnlyFileAndDirectory(): void
    {
        $configDir = $this->tempDir . '/.config/sugarcraft-crush';
        $path = $configDir . '/session.json';

        (new Session())->withCwd('/secret/workspace')->save();

        $this->assertFileExists($path);
        clearstatcache();
        // Transcript must never be world- or group-readable.
        $this->assertSame(0600, fileperms($path) & 0777);
        // The directory the lib created must be owner-only too.
        $this->assertSame(0700, fileperms($configDir) & 0777);
    }

    public function testSaveTightensPermissionsOnPreexistingLooseFile(): void
    {
        $configDir = $this->tempDir . '/.config/sugarcraft-crush';
        mkdir($configDir, 0755, true);
        $path = $configDir . '/session.json';
        // Simulate a file left world-readable by an older version.
        file_put_contents($path, '{}');
        chmod($path, 0644);

        (new Session())->withCwd('/x')->save();

        // The setup chmod above pollutes PHP's stat cache; clear it so
        // fileperms() reflects the save()'s on-disk 0600.
        clearstatcache(true, $path);
        $this->assertSame(0600, fileperms($path) & 0777);
    }

    public function testLoadNonArrayJsonReturnsFreshSession(): void
    {
        $configDir = $this->tempDir . '/.config/sugarcraft-crush';
        mkdir($configDir, 0755, true);
        $path = $configDir . '/session.json';

        // Syntactically valid JSON whose top level is a scalar, not an array.
        // AtomicJsonFile::read() rejects this with a \RuntimeException (a bare
        // (array) cast would have silently produced a surprising shape); load()
        // must still fall back to a fresh session rather than propagating.
        file_put_contents($path, '42');

        $session = Session::load();

        $this->assertSame('', $session->cwd);
        $this->assertSame([], $session->selected);
        $this->assertSame('name', $session->sortColumn);
        $this->assertSame('files', $session->activePane);
    }

    public function testSaveIsAtomicAndLeavesNoTemporaryFiles(): void
    {
        $configDir = $this->tempDir . '/.config/sugarcraft-crush';
        $path = $configDir . '/session.json';

        (new Session())->withCwd('/home/user/projects')->save();

        $this->assertFileExists($path);

        // AtomicJsonFile writes through a sibling `.session.json.tmp.<hex>`
        // file and renames it into place; no temp file may survive the save.
        $this->assertSame([], glob($configDir . '/.session.json.tmp.*') ?: []);
        $entries = array_values(array_diff(scandir($configDir) ?: [], ['.', '..']));
        $this->assertSame(['session.json'], $entries);

        // AtomicJsonFile encodes with JSON_UNESCAPED_SLASHES and no trailing
        // newline — distinct from the old non-atomic file_put_contents path,
        // which escaped slashes and appended "\n". Pinning the on-disk bytes
        // anchors save() to the atomic helper.
        $raw = file_get_contents($path);
        $this->assertIsString($raw);
        $this->assertStringContainsString('"/home/user/projects"', $raw);
        $this->assertStringNotContainsString('\/home\/user', $raw);
        $this->assertStringEndsWith('}', $raw);
    }

    public function testImmutabilityWithChainedWithers(): void
    {
        $original = new Session();
        $v1 = $original->withCwd('/a');
        $v2 = $v1->withCwd('/b');
        $v3 = $v2->withFilter('*.php');

        $this->assertNotSame($original, $v1);
        $this->assertNotSame($v1, $v2);
        $this->assertNotSame($v2, $v3);
        $this->assertSame('', $original->cwd);
        $this->assertSame('/a', $v1->cwd);
        $this->assertSame('/b', $v2->cwd);
        $this->assertSame('/b', $v3->cwd);
        $this->assertSame('*.php', $v3->filter);
    }

    public function testFluentInterfaceReadsLikeEnglish(): void
    {
        $session = (new Session())
            ->withCwd('/home/user')
            ->withSelected(['file1', 'file2'])
            ->withFilter('*.log')
            ->withSort('modified', 'desc')
            ->withActivePane('preview');

        $this->assertStringContainsString('home', $session->cwd);
        $this->assertCount(2, $session->selected);
        $this->assertStringContainsString('*.log', $session->filter);
        $this->assertSame('modified', $session->sortColumn);
        $this->assertSame('desc', $session->sortDir);
        $this->assertSame('preview', $session->activePane);
    }

    /**
     * Recursively remove a directory.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
