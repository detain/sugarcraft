<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Session;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * E680 — the entry said `src/Session.php` still blocks on an unbounded
 * flock. MEASURED FALSE at f0b4b0945: Session has no flock of its own;
 * persistence runs through candy-core AtomicJsonFile, whose flock() lands on
 * a PER-WRITER uniquely-named temp file (`.session.json.tmp.<16 hex>`) —
 * nothing else can contend with it, and the rename onto the destination is
 * not lock-guarded. These rows pin the MEASURED shape rather than the stale
 * claim: a foreign exclusive flock on the destination path must not delay
 * save() at all. If save() ever regresses to locking the destination itself
 * (the unbounded shape E137 hunted), the bound below reddens.
 */
final class SessionSaveLockTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = \sys_get_temp_dir() . '/sugar-crush-sessionlock-' . \uniqid((string) \getmypid(), true);
        // OneSidedHomeSandboxTest discipline: BOTH spellings of HOME move
        // together — the trait redirects getenv('HOME') AND $_SERVER['HOME']
        // and restores both, so no row is owed in its roster.
        $this->useHomeSandbox($this->tempDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restoreHomeSandbox();
        foreach (\iterator_to_array(new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ), false) as $file) {
            $file->isDir() ? @\rmdir($file->getPathname()) : @\unlink($file->getPathname());
        }
        @\rmdir($this->tempDir);
    }

    public function testSaveAndLoadRoundTripThroughTheAtomicStore(): void
    {
        (new Session(cwd: '/first'))->save();

        $this->assertFileExists($this->sessionPath());
        $this->assertSame('/first', Session::load()->cwd);
    }

    public function testAForeignFlockOnTheSessionFileNeverBlocksSave(): void
    {
        (new Session(cwd: '/first'))->save();

        // A contender holding the DESTINATION exclusively — the exact shape
        // the old unbounded save() used to park behind. Advisory locks do
        // not block AtomicJsonFile's tmp+rename; the wall bound says so.
        $hold = \fopen($this->sessionPath(), 'c');
        $this->assertIsResource($hold);
        $this->assertTrue(\flock($hold, \LOCK_EX));

        try {
            $start = \microtime(true);
            (new Session(cwd: '/second'))->save();
            $elapsed = \microtime(true) - $start;

            $this->assertLessThan(2.0, $elapsed, 'save() blocked behind a foreign flock — the E680 unbounded-lock shape is back');
        } finally {
            \flock($hold, \LOCK_UN);
            \fclose($hold);
        }

        $this->assertSame('/second', Session::load()->cwd, 'the bounded save did not land');
    }

    private function sessionPath(): string
    {
        return $this->tempDir . '/.config/sugarcraft-crush/session.json';
    }
}
