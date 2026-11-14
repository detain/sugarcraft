<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests\History;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\History\FileHistory;

/**
 * S-45 (finding 068): a FAILED LOCK_SH means the filesystem cannot do
 * advisory locks at all (an active writer makes the blocking call wait, not
 * fail), so the read paths must degrade to an unlocked read with a
 * once-per-store disclosure instead of hard-failing the constructor —
 * rename-atomic content is safe to read unlocked. Writers keep fail-closed.
 *
 * A lock-unsupported filesystem cannot be portably injected (probe: /proc,
 * /sys and tmpfs all succeed at flock on CI and dev hosts), so the
 * degrade branch is pinned structurally, and its two moving parts — the
 * once-per-store disclosure and the fail-closed write asymmetry — are
 * pinned behaviorally. Injection gap disclosed, never faked green.
 */
final class FileHistoryReadDegradeTest extends TestCase
{
    private string $dir;
    private string $path;
    private string|false $prevErrorLog;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/srl_rdegrade_' . uniqid('', true);
        mkdir($this->dir, 0700);
        $this->path = $this->dir . '/history.txt';
        $this->prevErrorLog = ini_get('error_log');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->prevErrorLog === false ? '' : $this->prevErrorLog);
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testReadPathsDegradeNotDeny_Structurally(): void
    {
        $load = $this->methodBody('load');
        $peek = $this->methodBody('peekLastOnDisk');
        $src = (string) file_get_contents(__DIR__ . '/../../src/History/FileHistory.php');

        foreach (['load' => $load, 'peek' => $peek] as $label => $body) {
            $this->assertStringContainsString('$locked = flock($fp, LOCK_SH);', $body, "$label must capture the lock verdict");
            $this->assertStringContainsString('$this->noteReadLockDegraded();', $body, "$label must disclose, not deny, on failure");
            $this->assertStringContainsString('if ($locked)', $body, "$label must unlock only what it locked");
            $this->assertStringNotContainsString('throw', $body, "$label must not throw on a lock refusal");
        }

        $this->assertSame(0, substr_count($src, 'cannot acquire history read lock'), 'the read-side throw is gone from the class');
    }

    public function testDisclosureIsEmittedOncePerStore(): void
    {
        $logFile = $this->dir . '/error.log';
        ini_set('error_log', $logFile);

        $history = new FileHistory($this->path, 0);
        $note = new \ReflectionMethod(FileHistory::class, 'noteReadLockDegraded');
        $note->setAccessible(true);
        $note->invoke($history);
        $note->invoke($history);
        $note->invoke($history);

        $log = file_exists($logFile) ? (string) file_get_contents($logFile) : '';
        $this->assertSame(1, substr_count($log, 'history read lock unavailable'), 'exactly one disclosure per store');
        $this->assertStringContainsString('disclosed once per store', $log);

        // A SECOND store is a second store: its own single disclosure.
        $history2 = new FileHistory($this->path . '.other', 0);
        $note->invoke($history2);
        $log = (string) file_get_contents($logFile);
        $this->assertSame(2, substr_count($log, 'history read lock unavailable'));
    }

    public function testWritersStayFailClosedUnderTheSameLockingRegime(): void
    {
        // The asymmetry of finding 068: reads degrade, writes refuse. The
        // S-15 sidecar-symlink seam reproduces a lock the class cannot hold;
        // push must still throw rather than write unserialized.
        $history = new FileHistory($this->path, 0);
        $history->push('a');
        @unlink($this->path . '.lock');
        if (!symlink($this->dir . '/victim', $this->path . '.lock')) {
            $this->markTestSkipped('unable to plant the lock symlink on this host');
        }
        clearstatcache();

        $threw = false;
        try {
            $history->push('b');
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), 'history lock');
        }
        $this->assertTrue($threw, 'the write side must remain fail-closed');
    }

    public function testLockCapableHostsNeverTakeTheDegradedPath(): void
    {
        $this->plant("one\ntwo\n");
        $history = new FileHistory($this->path, 0);
        $history->push('three'); // rides the peek fast path too

        $flag = new \ReflectionProperty(FileHistory::class, 'readLockDegraded');
        $flag->setAccessible(true);
        $this->assertFalse($flag->getValue($history), 'healthy locking must not silently trip the degradation');
        $this->assertSame(['three', 'two', 'one'], $history->entries());
    }

    private function plant(string $content): void
    {
        file_put_contents($this->path, $content);
        clearstatcache();
    }

    private function methodBody(string $name): string
    {
        $method = new \ReflectionMethod(FileHistory::class, $name);
        $lines = file((string) $method->getFileName());
        $this->assertIsArray($lines);

        return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }
}
